<?php

namespace Zittme\Modules\Menu\Controllers;

use BaseObject;
use Context;
use LayoutModel;
use ModuleController;
use ModuleModel;
use Zittme\Framework\Cache;
use Zittme\Framework\DB;
use Zittme\Framework\Exceptions\InvalidRequest;
use Zittme\Framework\Security;
use Zittme\Framework\Storage;
use Zittme\Framework\Theme;
use Zittme\Framework\Themes\Applier;
use Zittme\Framework\URL;
use Zittme\Modules\Menu\Models\EditorState;
use Zittme\Modules\Menu\Models\Exposure;
use Zittme\Modules\Menu\Models\SiteDesign;
use Zittme\Modules\Menu\Models\SiteTheme;

/**
 * Site editor: search exposure, site themes, site options, copying between
 * sites, the new site wizard and the overview of all sites.
 */
class SiteEditorSites extends SiteEditor
{
	/**
	 * Where the data needed to undo a theme change is kept.
	 */
	const UNDO_DIR = 'files/site_editor/undo/';

	/**
	 * Menu sets of the new site wizard: [name key, type, address, depth].
	 */
	const STARTERS = [
		'company' => [
			'main' => [
				['home', 'WIDGET', 'home', 0],
				['about', 'folder', '', 0],
				['greeting', 'ARTICLE', 'greeting', 1],
				['location', 'ARTICLE', 'location', 1],
				['business', 'ARTICLE', 'business', 0],
				['pr', 'folder', '', 0],
				['notice', 'board', 'notice', 1],
				['press', 'board', 'press', 1],
				['contact', 'ARTICLE', 'contact', 0],
			],
			'footer' => [
				['privacy', 'ARTICLE', 'privacy', 0],
			],
		],
		'community' => [
			'main' => [
				['home', 'WIDGET', 'home', 0],
				['notice', 'board', 'notice', 0],
				['free', 'board', 'free', 0],
				['qna', 'board', 'qna', 0],
				['gallery', 'board', 'gallery', 0],
			],
			'footer' => [
				['terms', 'ARTICLE', 'terms', 0],
			],
		],
		'blank' => [
			'main' => [
				['home', 'WIDGET', 'home', 0],
			],
			'footer' => [],
		],
	];

	/**
	 * Save the search exposure values of a module.
	 *
	 * Only the given keys are written. An empty value removes the key, so
	 * that the module follows the default again. The previous values are
	 * returned for undo.
	 */
	public function procMenuAdminEditorSaveSeo()
	{
		$module_info = ModuleModel::getModuleInfoByModuleSrl(intval(Context::get('module_srl')));
		$values = json_decode(strval(Context::get('values')), true);
		if (!$module_info || !$module_info->module_srl || !is_array($values))
		{
			throw new InvalidRequest;
		}
		$allowed = [
			'robots_tag' => ['', 'noindex', 'all'],
			'seo_sitemap' => ['', 'Y', 'N'],
			'seo_structured' => ['', 'Y', 'N'],
			'seo_own' => ['', 'Y'],
		];
		foreach ($values as $key => $value)
		{
			if (!isset($allowed[$key]) || !in_array(strval($value), $allowed[$key], true))
			{
				throw new InvalidRequest;
			}
		}

		$module_srl = intval($module_info->module_srl);
		$extra = ModuleModel::getModuleExtraVars($module_srl)[$module_srl] ?? new \stdClass;
		$previous = [];
		$oDB = DB::getInstance();
		$oDB->begin();
		foreach ($values as $key => $value)
		{
			$previous[$key] = strval($extra->{$key} ?? '');
			$oDB->query('DELETE FROM module_extra_vars WHERE module_srl = ? AND name = ?', [$module_srl, $key]);
			if (strval($value) !== '')
			{
				$output = executeQuery('module.insertModuleExtraVars', (object)['module_srl' => $module_srl, 'name' => $key, 'value' => strval($value)]);
				if (!$output->toBool())
				{
					$oDB->rollback();
					return $output;
				}
			}
		}
		$oDB->commit();
		Cache::delete("site_and_module:module_extra_vars:$module_srl");
		Cache::clearGroup('site_and_module');
		self::_clearSitemapCache();

		$this->add('module_srl', $module_srl);
		$this->add('previous', $previous);
	}

	/**
	 * Turn the search exposure of a whole sitemap on or off.
	 *
	 * Turned off sitemaps are stored in the menu module settings (seo_off).
	 * When nothing is turned off, the key is removed.
	 */
	public function procMenuAdminEditorSaveSitemapSeo()
	{
		$menu = $this->_getEditableMenu(intval(Context::get('menu_srl')));
		$off = Context::get('off') === 'Y';
		$menu_srl = intval($menu->menu_srl);

		$config = ModuleModel::getModuleConfig('menu');
		$config = is_object($config) ? clone $config : new \stdClass;
		$map = Exposure::getSitemapsOff();
		$was_off = isset($map[$menu_srl]);
		if ($off)
		{
			$map[$menu_srl] = true;
		}
		else
		{
			unset($map[$menu_srl]);
		}
		if (count($map))
		{
			$config->seo_off = $map;
		}
		else
		{
			unset($config->seo_off);
		}
		$output = ModuleController::getInstance()->insertModuleConfig('menu', $config);
		if (!$output->toBool())
		{
			return $output;
		}
		self::_clearSitemapCache();
		$this->add('menu_srl', $menu_srl);
		$this->add('was_off', $was_off ? 'Y' : 'N');
	}

	/**
	 * Save site options: splitting sitemap.xml by site, and the address
	 * prefix and opening scope of new menus of a site.
	 */
	public function procMenuAdminEditorSaveOptions()
	{
		$config = ModuleModel::getModuleConfig('menu');
		$config = is_object($config) ? clone $config : new \stdClass;
		$previous = [];

		$per_domain = Context::get('sitemap_per_domain');
		if ($per_domain !== null)
		{
			$previous['sitemap_per_domain'] = ($config->sitemap_per_domain ?? 'N') === 'Y' ? 'Y' : 'N';
			if ($per_domain === 'Y')
			{
				$config->sitemap_per_domain = 'Y';
			}
			else
			{
				unset($config->sitemap_per_domain);
			}
		}

		$domain_srl = Context::get('domain_srl');
		if ($domain_srl !== null && (Context::get('prefix_set') === 'Y' || Context::get('scope') !== null))
		{
			$domain_srl = intval($domain_srl);
			$site = $this->_getSite($domain_srl);
			$current = EditorState::getSiteOptions($site->domain_srl);
			$previous['prefix'] = $current->prefix;
			$previous['scope'] = $current->scope;
			$prefix = Context::get('prefix_set') === 'Y' ? strtolower(trim(strval(Context::get('prefix')))) : $current->prefix;
			$scope = Context::get('scope') !== null ? (Context::get('scope') === 'site' ? 'site' : 'all') : $current->scope;
			if ($prefix !== '' && !preg_match('/^[a-z][a-z0-9_]{0,19}$/', $prefix))
			{
				return new BaseObject(-1, lang('menu.site_editor_prefix_format'));
			}
			$options = is_array($config->site_options ?? null) ? $config->site_options : [];
			if ($prefix === '' && $scope === 'all')
			{
				unset($options[$site->domain_srl]);
			}
			else
			{
				$options[$site->domain_srl] = ['prefix' => $prefix, 'scope' => $scope];
			}
			if (count($options))
			{
				$config->site_options = $options;
			}
			else
			{
				unset($config->site_options);
			}
		}

		$output = ModuleController::getInstance()->insertModuleConfig('menu', $config);
		if (!$output->toBool())
		{
			return $output;
		}
		self::_clearSitemapCache();
		$this->add('previous', $previous);
	}

	/**
	 * Set the site a module opens on (-1 for every site).
	 */
	public function procMenuAdminEditorSetModuleSite()
	{
		$mid = trim(strval(Context::get('target_mid')));
		$module_info = $mid !== '' ? ModuleModel::getModuleInfoByMid($mid) : ModuleModel::getModuleInfoByModuleSrl(intval(Context::get('module_srl')));
		if (!$module_info || !$module_info->module_srl)
		{
			throw new InvalidRequest;
		}
		$domain_srl = intval(Context::get('domain_srl'));
		if ($domain_srl !== -1)
		{
			$this->_getSite($domain_srl);
		}
		$previous = intval($module_info->domain_srl ?? -1);
		DB::getInstance()->query('UPDATE modules SET domain_srl = ? WHERE module_srl = ?', [$domain_srl, intval($module_info->module_srl)]);
		Cache::clearGroup('site_and_module');
		ModuleModel::$_mid_map = ModuleModel::$_module_srl_map = [];
		self::_clearSitemapCache();
		$this->add('previous', $previous);
	}

	/**
	 * Remember menu items exactly as they are stored, together with the
	 * permission rows of their modules, before who can see them is changed.
	 */
	public function getMenuAdminEditorRowSnapshot()
	{
		$srls = json_decode(strval(Context::get('items')), true);
		if (!is_array($srls))
		{
			throw new InvalidRequest;
		}
		$oDB = DB::getInstance();
		$rows = [];
		foreach (array_unique(array_map('intval', $srls)) as $srl)
		{
			$stmt = $oDB->query('SELECT * FROM menu_item WHERE menu_item_srl = ?', [$srl]);
			$found = $stmt ? $stmt->fetchAll() : [];
			if (!isset($found[0]))
			{
				continue;
			}
			$item = (array)$found[0];
			$module_srl = 0;
			$grants = [];
			if (($item['is_shortcut'] ?? 'N') !== 'Y' && strval($item['url'] ?? '') !== '')
			{
				$module_info = ModuleModel::getModuleInfoByMid($item['url']);
				if ($module_info && $module_info->module_srl)
				{
					$module_srl = intval($module_info->module_srl);
					$stmt = $oDB->query('SELECT name, group_srl FROM module_grants WHERE module_srl = ? ORDER BY name, group_srl', [$module_srl]);
					foreach ($stmt ? $stmt->fetchAll() : [] as $grant)
					{
						$grants[] = [strval($grant->name), intval($grant->group_srl)];
					}
				}
			}
			$rows[] = ['menu_item_srl' => $srl, 'item' => $item, 'module_srl' => $module_srl, 'grants' => $grants];
		}
		$this->add('rows', $rows);
	}

	/**
	 * Put back menu items and module permission rows remembered by
	 * getMenuAdminEditorRowSnapshot, value for value.
	 */
	public function procMenuAdminEditorRestoreRows()
	{
		$rows = json_decode(strval(Context::get('rows')), true);
		if (!is_array($rows))
		{
			throw new InvalidRequest;
		}
		$oDB = DB::getInstance();
		$touched = [];
		$oDB->begin();
		foreach ($rows as $row)
		{
			$srl = intval($row['menu_item_srl'] ?? 0);
			$saved = $row['item'] ?? null;
			$stmt = $oDB->query('SELECT * FROM menu_item WHERE menu_item_srl = ?', [$srl]);
			$found = $stmt ? $stmt->fetchAll() : [];
			if (!$srl || !is_array($saved) || !isset($found[0]) || !\MenuAdminController::isSafeMenuRow($saved))
			{
				continue;
			}
			$current = (array)$found[0];
			$columns = array_values(array_filter(array_keys($current), function($column) use ($saved) {
				return $column !== 'menu_item_srl' && array_key_exists($column, $saved);
			}));
			if (count($columns))
			{
				$params = array_map(function($column) use ($saved) { return $saved[$column]; }, $columns);
				$params[] = $srl;
				$oDB->query('UPDATE menu_item SET ' . implode(', ', array_map(function($column) { return '`' . $column . '` = ?'; }, $columns)) . ' WHERE menu_item_srl = ?', $params);
			}
			$touched[intval($current['menu_srl'])] = true;
			$touched[intval($saved['menu_srl'] ?? 0)] = true;

			$module_srl = intval($row['module_srl'] ?? 0);
			if ($module_srl && is_array($row['grants'] ?? null))
			{
				$oDB->query('DELETE FROM module_grants WHERE module_srl = ?', [$module_srl]);
				foreach ($row['grants'] as $grant)
				{
					$oDB->query('INSERT INTO module_grants (module_srl, name, group_srl) VALUES (?, ?, ?)', [$module_srl, strval($grant[0] ?? ''), intval($grant[1] ?? 0)]);
				}
				Cache::delete("site_and_module:module_grants:$module_srl");
			}
		}
		$oDB->commit();
		Cache::clearGroup('site_and_module');
		$oMenuAdminController = getAdminController('menu');
		foreach (array_keys($touched) as $menu_srl)
		{
			if ($menu_srl)
			{
				$oMenuAdminController->makeXmlFile($menu_srl);
			}
		}
	}

	/**
	 * Themes that can be applied to a site.
	 */
	public function getMenuAdminEditorThemes()
	{
		$this->add('themes', SiteTheme::getThemes());
	}

	/**
	 * Apply a theme to a site.
	 *
	 * The default site uses the existing theme apply (design_0.php), limited
	 * to its own modules when there are several sites. Another site gets its
	 * own design file and layout instance. Menus whose skin was set on their
	 * own keep it unless include_own is Y. Everything that may change is
	 * remembered first, and the returned token undoes all of it.
	 */
	public function procMenuAdminEditorApplyTheme()
	{
		$site = $this->_getSite(intval(Context::get('domain_srl')));
		$theme_name = trim(strval(Context::get('theme_name')));
		$include_own = Context::get('include_own') === 'Y';
		$info = Theme::getInfo($theme_name);
		if (!$info || !$info->apply->layout)
		{
			throw new InvalidRequest;
		}

		$state = EditorState::build($site->domain_srl);
		$site_modules = [];
		$own_skin = [];
		$own_layout = [];
		$collect = function($nodes) use (&$collect, &$site_modules, &$own_skin, &$own_layout) {
			foreach ($nodes as $node)
			{
				if (!empty($node->module_srl))
				{
					$srl = intval($node->module_srl);
					$site_modules[$srl] = true;
					if ($node->is_skin_fix === 'Y' || $node->is_mskin_fix === 'Y')
					{
						$own_skin[$srl] = true;
					}
					if (intval($node->layout_srl) !== -1 || intval($node->mlayout_srl) > 0)
					{
						$own_layout[$srl] = $node;
					}
				}
				$collect($node->children ?? []);
			}
		};
		foreach ($state->sitemaps as $sitemap)
		{
			$collect($sitemap->items);
		}

		$exclude = $include_own ? [] : array_keys($own_skin);
		if ($site->is_default && count($state->sites) > 1)
		{
			foreach (executeQueryArray('module.getMidList', new \stdClass)->data ?: [] as $module_info)
			{
				$domain_srl = intval($module_info->domain_srl);
				if ($domain_srl > -1 && $domain_srl !== intval($site->domain_srl) && !isset($site_modules[intval($module_info->module_srl)]))
				{
					$exclude[] = intval($module_info->module_srl);
				}
			}
		}

		$snap = SiteTheme::snapshot($site->domain_srl, $site->is_default, $theme_name);
		$old_layout = ($site->is_default || $state->site_theme->own) ? intval($state->site_default_layout->P) : 0;
		if ($site->is_default)
		{
			$output = Applier::apply($theme_name, 0, $exclude);
		}
		else
		{
			$output = SiteTheme::applyToSite($theme_name, $site->domain_srl, $exclude, $site->domain);
		}
		if (!$output->ok)
		{
			SiteTheme::restore($snap, $site->is_default);
			throw new \Zittme\Framework\Exception($output->message ?: 'msg_theme_not_found');
		}

		if ($include_own && count($own_layout))
		{
			foreach ($own_layout as $srl => $node)
			{
				DB::getInstance()->query('UPDATE modules SET layout_srl = -1, mlayout_srl = ? WHERE module_srl = ?', [intval($node->mlayout_srl) > 0 ? -1 : intval($node->mlayout_srl), $srl]);
			}
			Cache::clearGroup('site_and_module');
		}

		$design = SiteDesign::read($site->is_default ? 0 : $site->domain_srl);
		$new_layout = intval($design->layout_srl ?? 0);
		$moved = self::carrySlots($old_layout, $new_layout);

		$created = [];
		$stmt = DB::getInstance()->query('SELECT layout_srl FROM layouts');
		foreach ($stmt ? $stmt->fetchAll() : [] as $row)
		{
			if (!array_key_exists(intval($row->layout_srl), $snap['layouts']))
			{
				$created[] = intval($row->layout_srl);
			}
		}
		$snap['created_layouts'] = $created;
		$snap['is_default'] = $site->is_default;
		$token = self::_saveUndo($snap);

		Cache::clearGroup('site_and_module');
		$this->add('token', $token);
		$this->add('theme_title', html_entity_decode(strval($info->title), ENT_QUOTES, 'UTF-8'));
		if (!$site->is_default)
		{
			// A site without sitemaps of its own lists every sitemap, but a theme only touches the modules that belong to the site.
			$stmt = DB::getInstance()->query('SELECT module_srl FROM modules WHERE domain_srl = ?', [intval($site->domain_srl)]);
			$owned = [];
			foreach ($stmt ? $stmt->fetchAll() : [] as $row)
			{
				$owned[intval($row->module_srl)] = true;
			}
			$site_modules = array_intersect_key($site_modules, $owned);
			$own_skin = array_intersect_key($own_skin, $owned);
			$own_layout = array_intersect_key($own_layout, $owned);
		}
		$kept = $include_own ? 0 : count($own_skin + $own_layout);
		$this->add('changed', isset($output->applied) && !$site->is_default ? intval($output->applied) : count($site_modules) - $kept);
		$this->add('kept', $kept);
		$this->add('slots_moved', $moved);
	}

	/**
	 * Undo a theme change made by procMenuAdminEditorApplyTheme.
	 */
	public function procMenuAdminEditorRevertTheme()
	{
		$token = strval(Context::get('token'));
		$snap = self::_loadUndo($token);
		if (!$snap)
		{
			throw new InvalidRequest;
		}
		SiteTheme::restore($snap, !empty($snap['is_default']));
		Storage::delete(\RX_BASEDIR . self::UNDO_DIR . $token . '.php');
	}

	/**
	 * Copy a menu to another site: a new module of the same kind with the
	 * settings of the original one (no documents), linked at the end of a
	 * sitemap of that site.
	 */
	public function procMenuAdminEditorCopyToSite()
	{
		$item = $this->_getModuleItem(intval(Context::get('menu_item_srl')));
		$module_info = ModuleModel::getModuleInfoByMid($item->url);
		$target = $this->_getSite(intval(Context::get('domain_srl')));
		$menu = $this->_getEditableMenu(intval(Context::get('menu_srl')));
		if (!$module_info || !$module_info->module_srl)
		{
			throw new InvalidRequest;
		}

		$source_options = EditorState::getSiteOptions(intval(Context::get('from_domain_srl')));
		$target_options = EditorState::getSiteOptions($target->domain_srl);
		$base = $module_info->mid;
		if ($source_options->prefix !== '' && strpos($base, $source_options->prefix) === 0 && strlen($base) > strlen($source_options->prefix))
		{
			$base = substr($base, strlen($source_options->prefix));
		}
		$new_mid = self::suggestMid($target_options->prefix . $base);
		if ($new_mid === '')
		{
			throw new InvalidRequest;
		}

		$copy = new \stdClass;
		$copy->module_srl = $module_info->module_srl;
		$copy->mid_1 = $new_mid;
		$copy->browser_title_1 = $module_info->browser_title;
		$copy->isMenuCreate = false;
		getAdminController('module')->procModuleAdminCopyModule($copy);
		ModuleModel::$_mid_map = ModuleModel::$_module_srl_map = [];
		Cache::clearGroup('site_and_module');
		$new_module = ModuleModel::getModuleInfoByMid($new_mid);
		if (!$new_module || !$new_module->module_srl || intval($new_module->module_srl) === intval($module_info->module_srl))
		{
			return new BaseObject(-1, lang('menu.site_editor_error_generic'));
		}

		$domain_srl = $target_options->scope === 'site' ? intval($target->domain_srl) : -1;
		DB::getInstance()->query('UPDATE modules SET domain_srl = ?, menu_srl = ? WHERE module_srl = ?', [$domain_srl, intval($menu->menu_srl), intval($new_module->module_srl)]);

		$args = new \stdClass;
		$args->menu_srl = intval($menu->menu_srl);
		$args->parent_srl = 0;
		$args->menu_item_srl = getNextSequence();
		$args->name = $item->name;
		$args->url = $new_mid;
		$args->is_shortcut = 'N';
		$args->open_window = $item->open_window === 'Y' ? 'Y' : 'N';
		$args->expand = 'N';
		$args->listorder = -1 * $args->menu_item_srl;
		$output = executeQuery('menu.insertMenuItem', $args);
		if (!$output->toBool())
		{
			return $output;
		}

		Cache::clearGroup('site_and_module');
		ModuleModel::$_mid_map = ModuleModel::$_module_srl_map = [];
		getAdminController('menu')->makeXmlFile(intval($menu->menu_srl));
		$this->add('menu_item_srl', intval($args->menu_item_srl));
		$this->add('module_srl', intval($new_module->module_srl));
		$this->add('mid', $new_mid);
	}

	/**
	 * Undo a copy made by procMenuAdminEditorCopyToSite.
	 */
	public function procMenuAdminEditorRemoveCopy()
	{
		$item = $this->_getModuleItem(intval(Context::get('menu_item_srl')));
		$module_info = ModuleModel::getModuleInfoByModuleSrl(intval(Context::get('module_srl')));
		if (!$module_info || !$module_info->module_srl || $module_info->mid !== $item->url)
		{
			throw new InvalidRequest;
		}
		executeQuery('menu.deleteMenuItem', (object)['menu_item_srl' => intval($item->menu_item_srl)]);
		$output = ModuleController::getInstance()->deleteModule(intval($module_info->module_srl));
		if (!$output->toBool())
		{
			return $output;
		}
		Cache::clearGroup('site_and_module');
		ModuleModel::$_mid_map = ModuleModel::$_module_srl_map = [];
		getAdminController('menu')->makeXmlFile(intval($item->menu_srl));
	}

	/**
	 * New site wizard.
	 *
	 * prepare: makes the first page of the new site, which the domain needs
	 * as its index module. The domain itself is then added with the existing
	 * procAdminInsertDomain action.
	 * build: makes only what was chosen: the sitemaps and menus of the chosen
	 * set, the address prefix, and the chosen theme for that site only.
	 */
	public function procMenuAdminEditorCreateSite()
	{
		$phase = strval(Context::get('phase'));
		$prefix = strtolower(trim(strval(Context::get('prefix'))));
		$scope = Context::get('scope') === 'site' ? 'site' : 'all';
		$starter = strval(Context::get('starter'));
		if ($prefix !== '' && !preg_match('/^[a-z][a-z0-9_]{0,19}$/', $prefix))
		{
			return new BaseObject(-1, lang('menu.site_editor_prefix_format'));
		}
		if (!isset(self::STARTERS[$starter]))
		{
			throw new InvalidRequest;
		}

		if ($phase === 'prepare')
		{
			$home = self::STARTERS[$starter]['main'][0];
			$module_srl = $this->_createStarterModule($home, $prefix, -1, 0);
			$module_info = ModuleModel::getModuleInfoByModuleSrl($module_srl);
			$this->add('module_srl', $module_srl);
			$this->add('mid', $module_info->mid ?? '');
			return;
		}
		if ($phase !== 'build')
		{
			throw new InvalidRequest;
		}

		$domain = strval(Context::get('domain'));
		try
		{
			$domain = URL::getDomainFromUrl(strtolower(preg_match('@^https?://@', $domain) ? $domain : ('http://' . $domain)));
		}
		catch (\Throwable $e)
		{
			$domain = '';
		}
		$site_info = $domain ? ModuleModel::getSiteInfoByDomain($domain) : null;
		$home = ModuleModel::getModuleInfoByModuleSrl(intval(Context::get('home_module_srl')));
		if (!$site_info || $site_info->domain !== $domain || !$home || intval($site_info->index_module_srl) !== intval($home->module_srl))
		{
			throw new InvalidRequest;
		}
		$domain_srl = intval($site_info->domain_srl);
		$open_on = $scope === 'site' ? $domain_srl : -1;
		$created = (object)['domain_srl' => $domain_srl, 'menu_srls' => [], 'module_srls' => [intval($home->module_srl)], 'layout_srls' => [], 'design' => false, 'theme' => ''];

		if ($prefix !== '' || $scope === 'site')
		{
			$config = ModuleModel::getModuleConfig('menu');
			$config = is_object($config) ? clone $config : new \stdClass;
			$options = is_array($config->site_options ?? null) ? $config->site_options : [];
			$options[$domain_srl] = ['prefix' => $prefix, 'scope' => $scope];
			$config->site_options = $options;
			ModuleController::getInstance()->insertModuleConfig('menu', $config);
		}

		$oMenuAdminController = getAdminController('menu');
		$sitemaps = [];
		foreach (['main', 'footer'] as $group)
		{
			$items = self::STARTERS[$starter][$group];
			if (!count($items))
			{
				continue;
			}
			$output = $oMenuAdminController->addMenu(lang('menu.site_editor_wz_sm_' . $group), 0);
			if (!$output->toBool())
			{
				return $output;
			}
			$menu_srl = intval($output->get('menuSrl'));
			$created->menu_srls[] = $menu_srl;
			$sitemaps[$group] = $menu_srl;
			self::_setSitemapDomain($menu_srl, $domain_srl);

			$parents = [0 => 0];
			foreach ($items as $index => $spec)
			{
				$depth = intval($spec[3]);
				$parent_srl = $parents[$depth] ?? 0;
				if ($group === 'main' && $index === 0)
				{
					$module_srl = intval($home->module_srl);
					$mid = $home->mid;
					DB::getInstance()->query('UPDATE modules SET domain_srl = ?, menu_srl = ? WHERE module_srl = ?', [$open_on, $menu_srl, $module_srl]);
				}
				elseif ($spec[1] === 'folder')
				{
					$mid = '';
				}
				else
				{
					$module_srl = $this->_createStarterModule($spec, $prefix, $open_on, $menu_srl);
					$created->module_srls[] = $module_srl;
					$mid = ModuleModel::getModuleInfoByModuleSrl($module_srl)->mid ?? '';
				}

				$args = new \stdClass;
				$args->menu_srl = $menu_srl;
				$args->parent_srl = $parent_srl;
				$args->menu_item_srl = getNextSequence();
				$args->name = escape(lang('menu.site_editor_st_' . $spec[0]), true, true);
				$args->url = $spec[1] === 'folder' ? '#' : $mid;
				$args->is_shortcut = $spec[1] === 'folder' ? 'Y' : 'N';
				$args->open_window = 'N';
				$args->expand = 'N';
				$args->listorder = -1 * $args->menu_item_srl;
				$output = executeQuery('menu.insertMenuItem', $args);
				if (!$output->toBool())
				{
					return $output;
				}
				$parents[$depth + 1] = intval($args->menu_item_srl);
			}
		}

		$theme_name = trim(strval(Context::get('theme_name')));
		if ($theme_name !== '')
		{
			$layouts_before = [];
			foreach (executeQueryArray('layout.getLayoutList', new \stdClass)->data ?: [] as $row)
			{
				$layouts_before[intval($row->layout_srl)] = true;
			}
			$output = SiteTheme::applyToSite($theme_name, $domain_srl, [], $domain);
			if ($output->ok)
			{
				$created->design = true;
				$created->theme = $theme_name;
				foreach (executeQueryArray('layout.getLayoutList', new \stdClass)->data ?: [] as $row)
				{
					if (!isset($layouts_before[intval($row->layout_srl)]))
					{
						$created->layout_srls[] = intval($row->layout_srl);
					}
				}
				$this->_bindStarterSlots(intval($output->layout_srl), $sitemaps);
			}
		}

		Cache::clearGroup('site_and_module');
		ModuleModel::$_mid_map = ModuleModel::$_module_srl_map = [];
		foreach ($created->menu_srls as $menu_srl)
		{
			$oMenuAdminController->makeXmlFile($menu_srl);
		}
		$this->add('created', $created);
	}

	/**
	 * Undo the new site wizard: remove what it made, except the domain,
	 * which is then removed with the existing procAdminDeleteDomain action.
	 */
	public function procMenuAdminEditorRemoveSite()
	{
		$created = json_decode(strval(Context::get('created')));
		if (!is_object($created) || !isset($created->domain_srl))
		{
			throw new InvalidRequest;
		}
		$domain_srl = intval($created->domain_srl);
		$site = ModuleModel::getSiteInfo($domain_srl);
		if (!$site || $domain_srl <= 0 || ($site->is_default_domain ?? 'N') === 'Y')
		{
			throw new InvalidRequest;
		}

		$oMenuAdminController = getAdminController('menu');
		foreach (($created->menu_srls ?? []) as $menu_srl)
		{
			$menu_srl = intval($menu_srl);
			if ($menu_srl && $oMenuAdminController->deleteMenu($menu_srl, false)->toBool())
			{
				self::_setSitemapDomain($menu_srl, 0);
			}
		}
		$index_module_srl = intval($site->index_module_srl);
		foreach (($created->module_srls ?? []) as $module_srl)
		{
			$module_srl = intval($module_srl);
			if ($module_srl && $module_srl !== $index_module_srl)
			{
				ModuleController::getInstance()->deleteModule($module_srl);
			}
		}
		foreach (($created->layout_srls ?? []) as $layout_srl)
		{
			getAdminController('layout')->deleteLayout(intval($layout_srl));
		}
		if (!empty($created->design))
		{
			SiteDesign::restoreRaw($domain_srl, null);
		}
		if (!empty($created->theme) && Theme::isValidName(strval($created->theme)))
		{
			Storage::delete(\RX_BASEDIR . Applier::BACKUP_DIR . $created->theme . '_' . $domain_srl . '.php');
		}

		$config = ModuleModel::getModuleConfig('menu');
		if (is_object($config) && is_array($config->site_options ?? null) && isset($config->site_options[$domain_srl]))
		{
			$config = clone $config;
			unset($config->site_options[$domain_srl]);
			if (!count($config->site_options))
			{
				unset($config->site_options);
			}
			ModuleController::getInstance()->insertModuleConfig('menu', $config);
		}

		Cache::clearGroup('site_and_module');
		$this->add('index_module_srl', $index_module_srl);
	}

	/**
	 * Remove the first page made by the wizard, after its domain was removed.
	 */
	public function procMenuAdminEditorRemoveSiteHome()
	{
		$module_info = ModuleModel::getModuleInfoByModuleSrl(intval(Context::get('module_srl')));
		if (!$module_info || !$module_info->module_srl)
		{
			throw new InvalidRequest;
		}
		foreach (executeQueryArray('module.getDomains', (object)['list_count' => 1000, 'page' => 1])->data ?: [] as $domain)
		{
			if (intval($domain->index_module_srl) === intval($module_info->module_srl))
			{
				throw new InvalidRequest;
			}
		}
		$touched = [];
		$removed = [];
		$this->_takeOutOfUnlinked($module_info->mid, $touched, $removed);
		foreach (array_keys($touched) as $menu_srl)
		{
			getAdminController('menu')->makeXmlFile($menu_srl);
		}
		ModuleController::getInstance()->deleteModule(intval($module_info->module_srl));
		Cache::clearGroup('site_and_module');
	}

	/**
	 * Menus of all sites side by side.
	 */
	public function getMenuAdminEditorOverview()
	{
		$columns = [];
		foreach (EditorState::getSites() as $site)
		{
			$state = EditorState::build($site->domain_srl);
			$columns[] = (object)[
				'site' => $state->site,
				'sitemaps' => $state->sitemaps,
				'unlinked_count' => count($state->unlinked->items) + count($state->unlinked->orphans),
				'site_theme' => $state->site_theme,
				'site_default_layout' => $state->site_default_layout,
				'options' => $state->options,
				'layouts' => $state->layouts,
			];
		}
		$this->add('columns', $columns);
	}

	/**
	 * Get a site by its number.
	 *
	 * @param int $domain_srl
	 * @return object
	 */
	protected function _getSite(int $domain_srl): object
	{
		foreach (EditorState::getSites() as $site)
		{
			if ($site->domain_srl === $domain_srl)
			{
				return $site;
			}
		}
		throw new InvalidRequest;
	}

	/**
	 * Create a module of the wizard menu set, the way procMenuAdminInsertItem creates one.
	 *
	 * @param array $spec
	 * @param string $prefix
	 * @param int $domain_srl
	 * @param int $menu_srl
	 * @return int
	 */
	protected function _createStarterModule(array $spec, string $prefix, int $domain_srl, int $menu_srl): int
	{
		$args = new \stdClass;
		if (in_array($spec[1], self::PAGE_TYPES, true))
		{
			$args->module = 'page';
			$args->page_type = $spec[1];
		}
		else
		{
			$args->module = $spec[1];
		}
		$args->mid = self::suggestMid($prefix . $spec[2]);
		if ($args->mid === '')
		{
			throw new InvalidRequest;
		}
		$args->browser_title = escape(lang('menu.site_editor_st_' . $spec[0]), true, true);
		$args->menu_srl = $menu_srl;
		if ($menu_srl <= 0)
		{
			$args->isMenuCreate = false;
		}
		$args->domain_srl = $domain_srl;
		$args->layout_srl = -1;
		$args->mlayout_srl = -1;
		$args->skin = '/USE_DEFAULT/';
		$args->mskin = ModuleModel::getModuleDefaultSkin($args->module, 'M', 0, false) ?: '/USE_DEFAULT/';
		$args->is_skin_fix = 'N';
		$args->is_mskin_fix = 'N';
		if (\Mobile::isMobileEnabled() === true)
		{
			$args->use_mobile = 'Y';
		}
		$output = ModuleController::getInstance()->insertModule($args);
		if (!$output->toBool())
		{
			throw new \Zittme\Framework\Exception($output->getMessage());
		}
		ModuleModel::$_mid_map = ModuleModel::$_module_srl_map = [];
		return intval($output->get('module_srl'));
	}

	/**
	 * Connect the wizard sitemaps to the menu slots of the new layout.
	 *
	 * The main sitemap goes to the first slot (or one named like GNB/main),
	 * the footer sitemap to a slot named like FNB/footer. A sitemap without
	 * a matching slot is left unconnected.
	 *
	 * @param int $layout_srl
	 * @param array $sitemaps group => menu_srl
	 * @return void
	 */
	protected function _bindStarterSlots(int $layout_srl, array $sitemaps): void
	{
		$layout_info = $layout_srl ? LayoutModel::getLayout($layout_srl) : null;
		$slots = array_keys((array)($layout_info->menu ?? []));
		if (!count($slots))
		{
			return;
		}
		$main = null;
		$footer = null;
		foreach ($slots as $slot)
		{
			if ($main === null && preg_match('/gnb|main/i', $slot))
			{
				$main = $slot;
			}
			if ($footer === null && preg_match('/fnb|foot|bottom/i', $slot))
			{
				$footer = $slot;
			}
		}
		if ($main === null)
		{
			$main = $slots[0] !== $footer ? $slots[0] : null;
		}
		if ($main !== null && isset($sitemaps['main']))
		{
			self::_bindSlot($layout_srl, $main, $sitemaps['main']);
		}
		if ($footer !== null && $footer !== $main && isset($sitemaps['footer']))
		{
			self::_bindSlot($layout_srl, $footer, $sitemaps['footer']);
		}
	}

	/**
	 * Give the slots of a new layout the sitemaps that the slots with the
	 * same names had in the previous layout, when they are empty.
	 *
	 * @param int $old_layout
	 * @param int $new_layout
	 * @return int number of connected slots
	 */
	public static function carrySlots(int $old_layout, int $new_layout): int
	{
		if ($old_layout <= 0 || $new_layout <= 0 || $old_layout === $new_layout)
		{
			return 0;
		}
		$old = LayoutModel::getLayout($old_layout);
		$new = LayoutModel::getLayout($new_layout);
		if (!$old || !$new)
		{
			return 0;
		}
		$count = 0;
		foreach ((array)($new->menu ?? []) as $slot => $info)
		{
			$current = intval($info->menu_srl ?? 0);
			$previous = intval($old->menu->{$slot}->menu_srl ?? 0);
			if ($current === 0 && $previous !== 0)
			{
				if (self::_bindSlot($new_layout, strval($slot), $previous)->toBool())
				{
					$count++;
				}
			}
		}
		return $count;
	}

	/**
	 * Keep the data needed to undo a change, and return its token.
	 *
	 * @param array $data
	 * @return string
	 */
	protected static function _saveUndo(array $data): string
	{
		$dir = \RX_BASEDIR . self::UNDO_DIR;
		Storage::createDirectory($dir);
		foreach (Storage::readDirectory($dir) ?: [] as $file)
		{
			if (is_file($file) && filemtime($file) < time() - 172800)
			{
				Storage::delete($file);
			}
		}
		$token = Security::getRandom(24, 'alnum');
		Storage::writePHPData($dir . $token . '.php', $data);
		return $token;
	}

	/**
	 * Read the data kept by _saveUndo().
	 *
	 * @param string $token
	 * @return array|null
	 */
	protected static function _loadUndo(string $token): ?array
	{
		if (!preg_match('/^[a-zA-Z0-9]{24}$/', $token))
		{
			return null;
		}
		$file = \RX_BASEDIR . self::UNDO_DIR . $token . '.php';
		if (!Storage::exists($file) || filemtime($file) < time() - 86400)
		{
			return null;
		}
		$data = Storage::readPHPData($file);
		return is_array($data) ? $data : null;
	}

	/**
	 * Remove the cached sitemap.xml entries so that changes show at once.
	 *
	 * @return void
	 */
	protected static function _clearSitemapCache(): void
	{
		foreach (glob(\RX_BASEDIR . 'files/cache/sitemap/urls*.php') ?: [] as $file)
		{
			Storage::delete($file);
		}
	}
}
