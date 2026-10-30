<?php

namespace Zittme\Modules\Menu\Models;

use Context;
use FileHandler;
use LayoutAdminModel;
use LayoutModel;
use MemberModel;
use MenuAdminModel;
use Mobile;
use ModuleHandler;
use ModuleModel;
use Zittme\Framework\Router;

/**
 * Read-only snapshot of a site's sitemaps for the site editor.
 *
 * Everything here only reads existing data (menu, menu_item, modules,
 * layouts, domains, files/site_design). Nothing is written, so opening the
 * editor leaves the installation exactly as it was.
 */
class EditorState
{
	/**
	 * Page types of the page module, as used by the existing sitemap.
	 */
	const PAGE_TYPES = ['WIDGET', 'ARTICLE', 'OUTSIDE'];

	/**
	 * Get the list of sites (domains).
	 *
	 * @return array
	 */
	public static function getSites(): array
	{
		$output = executeQueryArray('module.getDomains', (object)['list_count' => 1000, 'page' => 1]);
		$sites = [];
		foreach (array_values($output->data ?: []) as $domain)
		{
			$settings = $domain->settings ? json_decode($domain->settings) : null;
			$index_mid = '';
			if ($domain->index_module_srl)
			{
				$index_module = ModuleModel::getModuleInfoByModuleSrl($domain->index_module_srl);
				$index_mid = $index_module->mid ?? '';
			}
			$title = trim(strval($settings->title ?? ''));
			$sites[] = (object)[
				'domain_srl' => intval($domain->domain_srl),
				'domain' => $domain->domain,
				'title' => $title !== '' ? html_entity_decode(Context::replaceUserLang($title), ENT_QUOTES, 'UTF-8') : $domain->domain,
				'is_default' => $domain->is_default_domain === 'Y',
				'index_mid' => $index_mid,
				'base_url' => self::_getBaseUrl($domain),
				'security' => strval($domain->security ?? ''),
			];
		}

		usort($sites, function($a, $b) {
			if ($a->is_default !== $b->is_default)
			{
				return $a->is_default ? -1 : 1;
			}
			return $a->domain_srl - $b->domain_srl;
		});
		return $sites;
	}

	/**
	 * Build the editor state for one site.
	 *
	 * @param int $domain_srl
	 * @return object
	 */
	public static function build(int $domain_srl): object
	{
		$sites = self::getSites();
		$site = null;
		foreach ($sites as $item)
		{
			if ($item->domain_srl === $domain_srl)
			{
				$site = $item;
			}
		}
		if (!$site)
		{
			$site = $sites[0] ?? (object)['domain_srl' => 0, 'domain' => '', 'title' => '', 'is_default' => true, 'index_mid' => '', 'base_url' => \RX_BASEURL];
		}

		$mobile_enabled = Mobile::isMobileEnabled() ? true : false;

		// All modules, read directly so that no cached or substituted values are shown.
		$modules = [];
		$module_output = executeQueryArray('module.getMidList', new \stdClass);
		foreach ($module_output->data ?: [] as $module_info)
		{
			$modules[$module_info->mid] = $module_info;
		}
		$module_srls = array_map(function($module_info) { return intval($module_info->module_srl); }, array_values($modules));
		self::$_extra_vars = count($module_srls) ? ModuleModel::getModuleExtraVars($module_srls) : [];

		// All sitemaps except the admin menu.
		$menu_config = ModuleModel::getModuleConfig('menu');
		$unlinked_menu_srl = intval($menu_config->unlinked_menu_srl ?? 0);
		$menus = executeQueryArray('menu.getMenus', new \stdClass)->data ?: [];
		$linked_urls = [];
		$trees = [];
		foreach ($menus as $menu)
		{
			$rows = executeQueryArray('menu.getMenuItems', (object)['menu_srl' => $menu->menu_srl])->data ?: [];
			foreach ($rows as $row)
			{
				if ($row->is_shortcut !== 'Y' && $row->url !== '')
				{
					$linked_urls[$row->url] = true;
				}
			}
			$trees[$menu->menu_srl] = $rows;
		}

		// Layout instances and the menu slots they define.
		$layouts = self::_getLayouts('P');
		$mlayouts = $mobile_enabled ? self::_getLayouts('M') : [];
		$slot_map = self::_resolveSlots($layouts, $trees);

		// Which sitemaps belong to this site.
		$site_menu_srls = self::_getSiteMenuSrls($site, $sites, $menus, $trees, $modules, $layouts, $slot_map);
		$owners = self::_getSitemapOwners($menus, $sites);
		$sitemaps_off = Exposure::getSitemapsOff();
		$design = SiteDesign::getEffective($site->domain_srl, $site->is_default);

		$sitemaps = [];
		$unlinked_items = [];
		foreach ($menus as $menu)
		{
			$rows = $trees[$menu->menu_srl];
			$tree = self::_buildTree($rows, $modules, $site);
			if (intval($menu->menu_srl) === $unlinked_menu_srl)
			{
				foreach ($tree as $node)
				{
					$unlinked_items[] = $node;
				}
				continue;
			}
			if (!in_array(intval($menu->menu_srl), $site_menu_srls, true))
			{
				continue;
			}
			$sitemaps[] = (object)[
				'menu_srl' => intval($menu->menu_srl),
				'title' => html_entity_decode(Context::replaceUserLang($menu->title), ENT_QUOTES, 'UTF-8'),
				'title_key' => $menu->title,
				'slots' => $slot_map[$menu->menu_srl] ?? [],
				'domain_srl' => intval($owners[intval($menu->menu_srl)] ?? 0),
				'seo_off' => isset($sitemaps_off[intval($menu->menu_srl)]),
				'items' => $tree,
			];
		}

		// Modules that are not reachable from any menu item at all.
		$orphans = [];
		foreach ($modules as $mid => $module_info)
		{
			if (isset($linked_urls[$mid]))
			{
				continue;
			}
			if (!$site->is_default && intval($module_info->domain_srl) !== $site->domain_srl)
			{
				continue;
			}
			$node = self::_describeModule($module_info, $site);
			$node->id = 0;
			$node->name = html_entity_decode(Context::replaceUserLang($module_info->browser_title ?: $mid), ENT_QUOTES, 'UTF-8');
			$node->name_key = $module_info->browser_title ?: $mid;
			$node->is_lang = false;
			$node->url = $mid;
			$node->is_shortcut = 'N';
			$node->children = [];
			$orphans[] = $node;
		}
		if (!$site->is_default)
		{
			$unlinked_items = array_values(array_filter($unlinked_items, function($node) use ($site) {
				return !empty($node->module_srl) && intval($node->domain_srl) === $site->domain_srl;
			}));
		}
		self::_findRestorable($sitemaps, array_merge($unlinked_items, $orphans));

		// Module types that appear in this state, for skin lists and labels.
		$kinds = self::_getAddableKinds();
		$module_names = [];
		foreach ($kinds as $kind)
		{
			if ($kind->module)
			{
				$module_names[$kind->module] = true;
			}
		}
		$collect = function($nodes) use (&$collect, &$module_names) {
			foreach ($nodes as $node)
			{
				if (!empty($node->module))
				{
					$module_names[$node->module] = true;
				}
				$collect($node->children ?? []);
			}
		};
		foreach ($sitemaps as $sitemap)
		{
			$collect($sitemap->items);
		}
		$collect($unlinked_items);
		$collect($orphans);

		$module_titles = [];
		$skins = [];
		foreach (array_keys($module_names) as $module_name)
		{
			$xml_info = ModuleModel::getModuleInfoXml($module_name);
			$module_titles[$module_name] = $xml_info->title ?? $module_name;
			$skins[$module_name] = self::_getSkins($module_name, $mobile_enabled, $design);
		}

		$groups = [];
		foreach (MemberModel::getGroups() ?: [] as $group)
		{
			$groups[] = (object)[
				'group_srl' => intval($group->group_srl),
				'title' => html_entity_decode(Context::replaceUserLang($group->title), ENT_QUOTES, 'UTF-8'),
				'is_admin' => ($group->is_admin ?? 'N') === 'Y',
			];
		}

		$oLayoutAdminModel = LayoutAdminModel::getInstance();
		$state = new \stdClass;
		$state->sites = $sites;
		$state->site = $site;
		$state->mobile_enabled = $mobile_enabled;
		$state->sitemaps = $sitemaps;
		$state->unlinked = (object)[
			'menu_srl' => $unlinked_menu_srl,
			'items' => $unlinked_items,
			'orphans' => $orphans,
		];
		$state->layouts = (object)['P' => array_values($layouts), 'M' => array_values($mlayouts)];
		if ($design->own)
		{
			$state->site_default_layout = (object)[
				'P' => self::_existingLayout(intval($design->info->layout_srl ?? 0)),
				'M' => $mobile_enabled ? self::_existingLayout(intval($design->info->mlayout_srl ?? 0)) : 0,
			];
		}
		else
		{
			$state->site_default_layout = (object)[
				'P' => intval($oLayoutAdminModel->getSiteDefaultLayout('P')),
				'M' => $mobile_enabled ? intval($oLayoutAdminModel->getSiteDefaultLayout('M')) : 0,
			];
		}
		$state->site_layouts = self::_getSiteLayoutSrls($sitemaps, $state->site_default_layout->P);
		$state->site_theme = (object)[
			'own' => $design->own,
			'theme' => SiteDesign::getThemeOfLayout($state->site_default_layout->P),
		];
		$state->skins = $skins;
		$state->module_titles = $module_titles;
		$state->kinds = $kinds;
		$state->groups = $groups;
		$state->mids = array_keys($modules);
		$state->rewrite = Router::getRewriteLevel() > 0;
		$state->hidden_ready = self::isHiddenReady();
		$state->options = self::getSiteOptions($site->domain_srl);
		$state->options->sitemap_per_domain = ($menu_config->sitemap_per_domain ?? 'N') === 'Y';
		return $state;
	}

	/**
	 * Extra variables of all modules, kept while a state is built.
	 *
	 * @var array
	 */
	protected static $_extra_vars = [];

	/**
	 * Check whether menu items can be hidden (the column has been added by the module update).
	 *
	 * @return bool
	 */
	public static function isHiddenReady(): bool
	{
		static $ready = null;
		if ($ready === null)
		{
			$ready = \DB::getInstance()->isColumnExists('menu_item', 'is_hidden');
		}
		return $ready;
	}

	/**
	 * Get the options of a site made in the editor (address prefix and where new menus open).
	 *
	 * A site without stored options behaves as before: no prefix, and new
	 * menus open on every site.
	 *
	 * @param int $domain_srl
	 * @return object
	 */
	public static function getSiteOptions(int $domain_srl): object
	{
		$config = ModuleModel::getModuleConfig('menu');
		$stored = $config->site_options[$domain_srl] ?? ($config->site_options[strval($domain_srl)] ?? null);
		$stored = is_array($stored) ? (object)$stored : $stored;
		return (object)[
			'prefix' => strval($stored->prefix ?? ''),
			'scope' => ($stored->scope ?? '') === 'site' ? 'site' : 'all',
		];
	}

	/**
	 * Return the layout number when the layout instance exists, otherwise 0.
	 *
	 * @param int $layout_srl
	 * @return int
	 */
	protected static function _existingLayout(int $layout_srl): int
	{
		return ($layout_srl > 0 && LayoutModel::getLayout($layout_srl)) ? $layout_srl : 0;
	}

	/**
	 * Resolve the sitemap shown in each layout menu slot, and list the slots of each sitemap.
	 *
	 * @param array $layouts
	 * @param array $trees
	 * @return array {menu_srl: [slot, ...]}
	 */
	protected static function _resolveSlots(array $layouts, array $trees): array
	{
		$home_menu_srl = self::_getHomeMenuSrl();
		$slot_map = [];
		foreach ($layouts as $layout)
		{
			foreach ($layout->slots as $slot)
			{
				$target = intval($slot->menu_srl) === -1 ? $home_menu_srl : intval($slot->menu_srl);
				if ($target > 0 && !isset($trees[$target]))
				{
					$target = 0;
				}
				$slot->resolved_menu_srl = $target;
				if ($target > 0)
				{
					$slot_map[$target][] = (object)[
						'layout_srl' => $layout->layout_srl,
						'layout_title' => $layout->title,
						'slot' => $slot->id,
						'slot_title' => $slot->title,
						'via_home' => intval($slot->menu_srl) === -1,
					];
				}
			}
		}
		return $slot_map;
	}

	/**
	 * Get the sitemaps that belong to a site, by the same rules as the editor.
	 *
	 * Used by sitemap.xml when it is split by site. Unlike the editor, a site
	 * that has no sitemap of its own gets an empty list.
	 *
	 * @param int $domain_srl
	 * @return array
	 */
	public static function getSiteMenuSrlsFor(int $domain_srl): array
	{
		$sites = self::getSites();
		$site = null;
		foreach ($sites as $item)
		{
			if ($item->domain_srl === $domain_srl)
			{
				$site = $item;
			}
		}
		if (!$site)
		{
			return [];
		}
		$modules = [];
		foreach (executeQueryArray('module.getMidList', new \stdClass)->data ?: [] as $module_info)
		{
			$modules[$module_info->mid] = $module_info;
		}
		$menus = executeQueryArray('menu.getMenus', new \stdClass)->data ?: [];
		$trees = [];
		foreach ($menus as $menu)
		{
			$trees[$menu->menu_srl] = executeQueryArray('menu.getMenuItems', (object)['menu_srl' => $menu->menu_srl])->data ?: [];
		}
		$layouts = self::_getLayouts('P');
		$slot_map = self::_resolveSlots($layouts, $trees);
		return self::_getSiteMenuSrls($site, $sites, $menus, $trees, $modules, $layouts, $slot_map, false);
	}

	/**
	 * Build a nested tree from menu_item rows (listorder desc, same as the menu cache).
	 *
	 * @param array $rows
	 * @param array $modules
	 * @param object $site
	 * @return array
	 */
	protected static function _buildTree(array $rows, array $modules, object $site): array
	{
		$by_parent = [];
		foreach ($rows as $row)
		{
			$by_parent[intval($row->parent_srl)][] = $row;
		}

		$make = function($parent_srl) use (&$make, &$by_parent, $modules, $site) {
			$nodes = [];
			foreach ($by_parent[$parent_srl] ?? [] as $row)
			{
				$nodes[] = self::_describeItem($row, $modules, $site, $make(intval($row->menu_item_srl)));
			}
			return $nodes;
		};
		return $make(0);
	}

	/**
	 * Describe a menu item for the editor.
	 *
	 * @param object $row
	 * @param array $modules
	 * @param object $site
	 * @param array $children
	 * @return object
	 */
	protected static function _describeItem(object $row, array $modules, object $site, array $children): object
	{
		$url = strval($row->url ?? '');
		$is_shortcut = $row->is_shortcut === 'Y';
		$module_info = (!$is_shortcut && $url !== '') ? ($modules[$url] ?? null) : null;

		if ($module_info)
		{
			$node = self::_describeModule($module_info, $site);
		}
		else
		{
			$node = new \stdClass;
			$node->module = '';
			$node->module_srl = 0;
			$node->mid = '';
			if ($is_shortcut)
			{
				if ($url === '' || $url === '#')
				{
					$node->kind = 'folder';
				}
				elseif (isset($modules[$url]))
				{
					$node->kind = 'shortcut';
					$node->target_mid = $url;
				}
				else
				{
					$node->kind = 'link';
				}
			}
			else
			{
				$node->kind = 'link';
			}
		}

		$name_key = strval($row->name ?? '');
		$node->id = intval($row->menu_item_srl);
		$node->name = html_entity_decode(Context::replaceUserLang($name_key), ENT_QUOTES, 'UTF-8');
		$node->name_key = $name_key;
		$node->is_lang = (bool)preg_match('/^\$user_lang->[a-zA-Z0-9_]+$/', $name_key);
		$node->url = $url;
		$node->is_shortcut = $is_shortcut ? 'Y' : 'N';
		$node->open_window = $row->open_window === 'Y' ? 'Y' : 'N';
		$node->expand = $row->expand === 'Y' ? 'Y' : 'N';
		$node->group_srls = strval($row->group_srls ?? '');
		$node->icon = strval($row->icon ?? '');
		$node->class = strval($row->class ?? '');
		$node->desc = strval($row->desc ?? '');
		$node->is_hidden = ($row->is_hidden ?? 'N') === 'Y' ? 'Y' : 'N';
		$node->is_home = !$is_shortcut && $url !== '' && $url === $site->index_mid;
		$node->children = $children;
		return $node;
	}

	/**
	 * Describe the module behind a menu item.
	 *
	 * @param object $module_info
	 * @param object $site
	 * @return object
	 */
	protected static function _describeModule(object $module_info, object $site): object
	{
		$node = new \stdClass;
		$node->module = $module_info->module;
		$node->module_srl = intval($module_info->module_srl);
		$node->mid = $module_info->mid;
		$node->domain_srl = intval($module_info->domain_srl);
		if ($module_info->module === 'page')
		{
			$page_type = strtoupper(strval($module_info->page_type ?? ''));
			if (!in_array($page_type, self::PAGE_TYPES, true))
			{
				$extra = ModuleModel::getModuleExtraVars($module_info->module_srl);
				$page_type = strtoupper(strval($extra[$module_info->module_srl]->page_type ?? 'WIDGET'));
			}
			$node->kind = strtolower(in_array($page_type, self::PAGE_TYPES, true) ? $page_type : 'WIDGET');
		}
		else
		{
			$node->kind = $module_info->module;
		}
		$node->layout_srl = intval($module_info->layout_srl);
		$node->mlayout_srl = intval($module_info->mlayout_srl);
		$node->use_mobile = strval($module_info->use_mobile ?? '');
		$node->is_skin_fix = ($module_info->is_skin_fix ?? 'N') === 'Y' ? 'Y' : 'N';
		$node->skin = strval($module_info->skin ?? '');
		$node->is_mskin_fix = ($module_info->is_mskin_fix ?? 'N') === 'Y' ? 'Y' : 'N';
		$node->mskin = strval($module_info->mskin ?? '');
		$extra = self::$_extra_vars[intval($module_info->module_srl)] ?? null;
		$node->seo = (object)[];
		foreach (Exposure::KEYS as $key)
		{
			$node->seo->{$key} = strval($extra->{$key} ?? '');
		}

		$xml_info = ModuleModel::getModuleInfoXml($module_info->module);
		$setup_act = $xml_info->setup_index_act ?? '';
		$node->setup_url = $setup_act ? getNotEncodedUrl('', 'module', 'admin', 'act', $setup_act, 'module_srl', $module_info->module_srl) : '';
		$node->view_url = self::_getViewUrl($site, $module_info->mid);
		return $node;
	}

	/**
	 * Mark modules that were moved out of a menu when its kind changed.
	 *
	 * A module whose address is "A_old" plus a number, while a menu item of
	 * this site uses the address "A" with a module of another kind, can be put
	 * back into that menu item.
	 *
	 * @param array $sitemaps
	 * @param array $nodes
	 * @return void
	 */
	protected static function _findRestorable(array $sitemaps, array $nodes): void
	{
		$by_url = [];
		$collect = function($list) use (&$collect, &$by_url) {
			foreach ($list as $node)
			{
				if (!empty($node->module_srl) && $node->is_shortcut !== 'Y')
				{
					$by_url[strtolower($node->url)] = $node;
				}
				$collect($node->children ?? []);
			}
		};
		foreach ($sitemaps as $sitemap)
		{
			$collect($sitemap->items);
		}

		foreach ($nodes as $node)
		{
			if (empty($node->module_srl) || !preg_match('/^(.+)_old[0-9]+$/i', strval($node->mid), $matches))
			{
				continue;
			}
			$target = $by_url[strtolower($matches[1])] ?? null;
			if (!$target && strlen($node->mid) === 40)
			{
				foreach ($by_url as $url => $candidate)
				{
					if (strncmp($url, strtolower($matches[1]), strlen($matches[1])) === 0)
					{
						$target = $candidate;
						break;
					}
				}
			}
			if ($target && $target->kind !== $node->kind)
			{
				$node->restore = (object)[
					'item_id' => intval($target->id),
					'mid' => $target->url,
				];
			}
		}
	}

	/**
	 * Get layout instances with their menu slots.
	 *
	 * @param string $layout_type
	 * @return array
	 */
	protected static function _getLayouts(string $layout_type): array
	{
		$result = [];
		$output = executeQueryArray('layout.getLayoutList', (object)['layout_type' => $layout_type]);
		foreach ($output->data ?: [] as $row)
		{
			if (!LayoutModel::isExistsLayoutFile($row->layout, $layout_type))
			{
				continue;
			}
			$layout_info = LayoutModel::getLayout($row->layout_srl);
			if (!$layout_info)
			{
				continue;
			}
			$slots = [];
			foreach ((array)($layout_info->menu ?? []) as $menu_id => $menu)
			{
				$slots[] = (object)[
					'id' => $menu_id,
					'title' => $menu->title ?: $menu_id,
					'menu_srl' => intval($menu->menu_srl ?? 0),
				];
			}
			$path = strval($layout_info->path ?? '');
			$thumbnail = ($path !== '' && file_exists(self::_toRealPath($path) . 'thumbnail.png')) ? self::_toUrl($path . 'thumbnail.png') : '';
			$result[intval($row->layout_srl)] = (object)[
				'layout_srl' => intval($row->layout_srl),
				'title' => html_entity_decode(strval($row->title ?: ($layout_info->title ?? $row->layout)), ENT_QUOTES, 'UTF-8'),
				'layout_title' => html_entity_decode(strval($layout_info->title ?? $row->layout), ENT_QUOTES, 'UTF-8'),
				'thumbnail' => $thumbnail,
				'slots' => $slots,
			];
		}
		return $result;
	}

	/**
	 * Get skins of a module.
	 *
	 * @param string $module_name
	 * @param bool $mobile_enabled
	 * @return object
	 */
	protected static function _getSkins(string $module_name, bool $mobile_enabled, ?object $design = null): object
	{
		$path = ModuleHandler::getModulePath($module_name);
		$result = (object)['P' => [], 'M' => [], 'default_P' => '', 'default_M' => ''];
		if (!$path || !is_dir(self::_toRealPath($path) . 'skins'))
		{
			return $result;
		}
		foreach (self::_listSkins($module_name, $path, 'skins') as $skin_name => $skin_info)
		{
			$result->P[] = self::_describeSkin($skin_name, $skin_info);
		}
		$result->default_P = $design ? SiteDesign::getDefaultSkin($design, $module_name, 'P') : strval(ModuleModel::getModuleDefaultSkin($module_name, 'P', 0, false));
		if ($mobile_enabled && is_dir(self::_toRealPath($path) . 'm.skins'))
		{
			foreach (self::_listSkins($module_name, $path, 'm.skins') as $skin_name => $skin_info)
			{
				$result->M[] = self::_describeSkin($skin_name, $skin_info);
			}
			$result->default_M = $design ? SiteDesign::getDefaultSkin($design, $module_name, 'M') : strval(ModuleModel::getModuleDefaultSkin($module_name, 'M', 0, false));
		}
		return $result;
	}

	/**
	 * List the skins of a module, including skins provided by themes.
	 *
	 * ModuleModel::getSkins() is not used here because it may store the
	 * default skin into files/site_design while listing.
	 *
	 * @param string $module_name
	 * @param string $path
	 * @param string $dir
	 * @return array
	 */
	protected static function _listSkins(string $module_name, string $path, string $dir): array
	{
		$result = [];
		$real = self::_toRealPath($path) . $dir . '/';
		$names = FileHandler::readDir($real) ?: [];
		natcasesort($names);
		foreach ($names as $skin_name)
		{
			if (!is_dir($real . $skin_name))
			{
				continue;
			}
			$skin_info = ModuleModel::loadSkinInfo($path, $skin_name, $dir);
			if (!$skin_info)
			{
				$skin_info = (object)['title' => $skin_name];
			}
			$result[$skin_name] = $skin_info;
		}
		foreach (\Zittme\Framework\Theme::getModuleSkins($module_name, $dir) as $skin_name => $skin_info)
		{
			$result[$skin_name] = $skin_info;
		}
		return $result;
	}

	/**
	 * Describe a skin for a design card.
	 *
	 * @param string $skin_name
	 * @param object $skin_info
	 * @return object
	 */
	protected static function _describeSkin(string $skin_name, $skin_info): object
	{
		$thumbnail = strval($skin_info->thumbnail ?? '');
		return (object)[
			'name' => $skin_name,
			'title' => html_entity_decode(strval($skin_info->title ?? $skin_name), ENT_QUOTES, 'UTF-8'),
			'thumbnail' => $thumbnail !== '' ? self::_toUrl($thumbnail) : '',
		];
	}

	/**
	 * Get the kinds that can be added, based on installed modules.
	 *
	 * @return array
	 */
	protected static function _getAddableKinds(): array
	{
		Context::loadLang(\RX_BASEDIR . 'modules/page/lang');
		$list = MenuAdminModel::getInstance()->getModuleListInSitemap(0);
		$kinds = [];
		foreach ($list ?: [] as $type => $info)
		{
			$is_page = in_array($type, self::PAGE_TYPES, true);
			$kinds[] = (object)[
				'type' => $type,
				'kind' => $is_page ? strtolower($type) : $type,
				'module' => $is_page ? 'page' : $type,
				'title' => html_entity_decode(strval($info->title ?? $type), ENT_QUOTES, 'UTF-8'),
			];
		}
		return $kinds;
	}

	/**
	 * Get the stored site of each sitemap, as {menu_srl: domain_srl}.
	 *
	 * Sitemaps that are not listed here belong to the default site.
	 *
	 * @return array
	 */
	public static function getSitemapDomains(): array
	{
		$config = ModuleModel::getModuleConfig('menu');
		$result = [];
		foreach ((array)($config->sitemap_domains ?? []) as $menu_srl => $domain_srl)
		{
			if (intval($menu_srl) > 0 && intval($domain_srl) > 0)
			{
				$result[intval($menu_srl)] = intval($domain_srl);
			}
		}
		return $result;
	}

	/**
	 * Get the site each sitemap belongs to.
	 *
	 * @param array $menus
	 * @param array $sites
	 * @return array {menu_srl: domain_srl}
	 */
	protected static function _getSitemapOwners(array $menus, array $sites): array
	{
		$default_srl = 0;
		$known = [];
		foreach ($sites as $item)
		{
			$known[$item->domain_srl] = true;
			if ($item->is_default)
			{
				$default_srl = $item->domain_srl;
			}
		}
		$stored = self::getSitemapDomains();
		$owners = [];
		foreach ($menus as $menu)
		{
			$menu_srl = intval($menu->menu_srl);
			$owner = $stored[$menu_srl] ?? 0;
			$owners[$menu_srl] = ($owner && isset($known[$owner])) ? $owner : $default_srl;
		}
		return $owners;
	}

	/**
	 * Get the sitemaps shown for a site.
	 *
	 * A site shows the sitemaps that belong to it (sitemaps without a stored
	 * site belong to the default site, so the default site shows every
	 * existing sitemap like the sitemap screen), plus the sitemaps that its
	 * modules use and that are connected to menu slots of its layouts.
	 */
	protected static function _getSiteMenuSrls(object $site, array $sites, array $menus, array $trees, array $modules, array $layouts, array $slot_map, bool $fallback_all = true): array
	{
		$all = array_map(function($menu) { return intval($menu->menu_srl); }, $menus);
		if (count($sites) < 2)
		{
			return $all;
		}

		$owners = self::_getSitemapOwners($menus, $sites);
		$found = [];
		$layout_srls = [];
		foreach ($owners as $menu_srl => $owner)
		{
			if ($owner === $site->domain_srl)
			{
				$found[$menu_srl] = true;
			}
		}
		if ($site->is_default)
		{
			$default_layout = intval(LayoutAdminModel::getInstance()->getSiteDefaultLayout('P'));
			if ($default_layout > 0)
			{
				$layout_srls[$default_layout] = true;
			}
		}
		elseif (SiteDesign::hasOwn($site->domain_srl))
		{
			$own_layout = intval(SiteDesign::read($site->domain_srl)->layout_srl ?? 0);
			if ($own_layout > 0)
			{
				$layout_srls[$own_layout] = true;
			}
		}
		foreach ($trees as $menu_srl => $rows)
		{
			foreach ($rows as $row)
			{
				$module_info = ($row->is_shortcut !== 'Y') ? ($modules[$row->url] ?? null) : null;
				if ($module_info && intval($module_info->domain_srl) === $site->domain_srl)
				{
					$found[intval($menu_srl)] = true;
					if ($module_info->layout_srl > 0)
					{
						$layout_srls[intval($module_info->layout_srl)] = true;
					}
				}
			}
		}
		if ($site->index_mid && isset($modules[$site->index_mid]) && $modules[$site->index_mid]->layout_srl > 0)
		{
			$layout_srls[intval($modules[$site->index_mid]->layout_srl)] = true;
		}
		foreach ($owners as $menu_srl => $owner)
		{
			if ($owner !== $site->domain_srl)
			{
				continue;
			}
			foreach ($trees[$menu_srl] ?? [] as $row)
			{
				$module_info = ($row->is_shortcut !== 'Y') ? ($modules[$row->url] ?? null) : null;
				if ($module_info && $module_info->layout_srl > 0)
				{
					$layout_srls[intval($module_info->layout_srl)] = true;
				}
			}
		}
		foreach (array_keys($layout_srls) as $layout_srl)
		{
			foreach ($layouts[$layout_srl]->slots ?? [] as $slot)
			{
				if (!empty($slot->resolved_menu_srl))
				{
					$found[intval($slot->resolved_menu_srl)] = true;
				}
			}
		}

		$result = array_values(array_filter($all, function($menu_srl) use ($found) {
			return isset($found[$menu_srl]);
		}));
		return (count($result) || $site->is_default || !$fallback_all) ? $result : $all;
	}

	/**
	 * Get the layouts that the modules of this site use.
	 *
	 * @param array $sitemaps
	 * @param int $site_default_layout
	 * @return array
	 */
	protected static function _getSiteLayoutSrls(array $sitemaps, int $site_default_layout): array
	{
		$found = [];
		if ($site_default_layout > 0)
		{
			$found[$site_default_layout] = true;
		}
		$collect = function($nodes) use (&$collect, &$found, $site_default_layout) {
			foreach ($nodes as $node)
			{
				if (!empty($node->module_srl))
				{
					$layout_srl = intval($node->layout_srl);
					if ($layout_srl === -1)
					{
						$layout_srl = $site_default_layout;
					}
					if ($layout_srl > 0)
					{
						$found[$layout_srl] = true;
					}
				}
				$collect($node->children ?? []);
			}
		};
		foreach ($sitemaps as $sitemap)
		{
			$collect($sitemap->items);
		}
		return array_keys($found);
	}

	/**
	 * Read the sitemap that holds the start module, without writing any cache.
	 *
	 * @return int
	 */
	protected static function _getHomeMenuSrl(): int
	{
		$homeMenuSrl = 0;
		$cache_file = \RX_BASEDIR . 'files/cache/menu/homeSitemap.php';
		if (FileHandler::exists($cache_file))
		{
			include $cache_file;
		}
		if ($homeMenuSrl)
		{
			return intval($homeMenuSrl);
		}
		$start_module = ModuleModel::getSiteInfo(0);
		if ($start_module && !empty($start_module->mid))
		{
			$output = executeQuery('menu.getMenuItemByUrl', (object)['url' => $start_module->mid, 'site_srl' => 0]);
			if ($output->toBool() && !empty($output->data->menu_srl))
			{
				return intval($output->data->menu_srl);
			}
		}
		return 0;
	}

	/**
	 * Get the base URL of a site.
	 *
	 * @param object $domain
	 * @return string
	 */
	protected static function _getBaseUrl(object $domain): string
	{
		return Context::getDefaultUrl($domain) ?: \RX_BASEURL;
	}

	/**
	 * Get the visitor URL of a module on a site.
	 *
	 * @param object $site
	 * @param string $mid
	 * @return string
	 */
	protected static function _getViewUrl(object $site, string $mid): string
	{
		$base = rtrim($site->base_url, '/') . '/';
		if ($mid === $site->index_mid)
		{
			return $base;
		}
		return Router::getRewriteLevel() > 0 ? ($base . $mid) : ($base . '?mid=' . rawurlencode($mid));
	}

	/**
	 * Convert a relative path to a URL.
	 *
	 * @param string $path
	 * @return string
	 */
	protected static function _toUrl(string $path): string
	{
		if (strncmp($path, \RX_BASEDIR, strlen(\RX_BASEDIR)) === 0)
		{
			$path = substr($path, strlen(\RX_BASEDIR));
		}
		return \RX_BASEURL . preg_replace('!^\./!', '', $path);
	}

	/**
	 * Convert a relative path to a real path.
	 *
	 * @param string $path
	 * @return string
	 */
	protected static function _toRealPath(string $path): string
	{
		return rtrim(FileHandler::getRealPath($path), '/') . '/';
	}
}
