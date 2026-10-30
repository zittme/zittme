<?php

namespace Zittme\Modules\Member\Models;

use Context;
use MemberModel;
use ModuleHandler;
use ModuleModel;
use Zittme\Framework\DB;

/**
 * Groups for the member administration screen: the list with the number of
 * members, and the places where one group is chosen ("where it is used").
 *
 * Nothing here writes. Group changes are saved by the existing group actions.
 */
class ConsoleGroups
{
	/**
	 * Groups in their order, with the values the edit form needs.
	 */
	public static function listData(): array
	{
		$config = MemberModel::getMemberConfig();
		$counts = ConsoleQuery::getCounts(false);
		$by_group = isset($counts['groups']) ? [] : null;
		foreach ($counts['groups'] ?? [] as $pair)
		{
			$by_group[intval($pair[0])] = intval($pair[1]);
		}
		$point_levels = self::pointLevels();

		$list = [];
		foreach (MemberModel::getGroups() ?: [] as $group)
		{
			$group_srl = intval($group->group_srl);
			$title_raw = strval($group->title);
			$is_lang = strncmp($title_raw, '$user_lang->', 12) === 0;
			$list[] = [
				'id' => $group_srl,
				'title' => html_entity_decode(Context::replaceUserLang($title_raw, true), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
				'title_raw' => $is_lang ? $title_raw : html_entity_decode($title_raw, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
				'description' => html_entity_decode(strval($group->description ?? ''), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
				'def' => ($group->is_default ?? 'N') === 'Y',
				'admin' => ($group->is_admin ?? 'N') === 'Y',
				'image_mark' => strval($group->image_mark ?? ''),
				'count' => $by_group === null ? null : ($by_group[$group_srl] ?? 0),
				'level' => $point_levels[$group_srl] ?? null,
			];
		}
		return [
			'groups' => $list,
			'estimated' => !empty($counts['estimated']),
			'image_mark' => ($config->group_image_mark ?? 'N') === 'Y',
		];
	}

	/**
	 * Point level that moves members into each group.
	 */
	protected static function pointLevels(): array
	{
		if (!class_exists('PointModel'))
		{
			return [];
		}
		$point_config = \PointModel::getConfig();
		if (!is_object($point_config) || ($point_config->able_module ?? 'N') !== 'Y')
		{
			return [];
		}
		$levels = [];
		foreach ((array)($point_config->point_group ?? []) as $group_srl => $level)
		{
			$levels[intval($group_srl)] = intval($level);
		}
		return $levels;
	}

	/**
	 * Every place where a group is chosen, for the group page and the
	 * delete confirmation. Each place carries the other groups chosen
	 * together, so the screen can say what stays after the group is gone.
	 */
	public static function getUsage(int $group_srl): array
	{
		$groups = MemberModel::getGroups() ?: [];
		if (!isset($groups[$group_srl]))
		{
			return [];
		}
		$group = $groups[$group_srl];
		Context::loadLang(\RX_BASEDIR . 'modules/menu/lang');
		Context::loadLang(\RX_BASEDIR . 'modules/module/lang');

		$sites = self::sites();
		$default_site = 0;
		foreach ($sites as $site)
		{
			if ($site['default'])
			{
				$default_site = $site['id'];
			}
		}
		$editor_url = getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMenuAdminSiteEditor');
		$usage = [];

		if (($group->is_admin ?? 'N') === 'Y')
		{
			$usage[] = ['kind' => 'admin', 'site' => 0];
		}
		if (($group->is_default ?? 'N') === 'Y')
		{
			$usage[] = ['kind' => 'join', 'site' => 0];
		}

		// Menus: who can see the menu item.
		$sitemap_sites = class_exists('Zittme\\Modules\\Menu\\Models\\EditorState') ? \Zittme\Modules\Menu\Models\EditorState::getSitemapDomains() : [];
		$menu_titles = [];
		$admin_menus = [];
		$unlinked_menu = intval(ModuleModel::getModuleConfig('menu')->unlinked_menu_srl ?? 0);
		foreach (DB::getInstance()->query('SELECT menu_srl, title FROM menu')->fetchAll() as $row)
		{
			if (strncmp(strval($row->title), '__ADMINMENU', 11) === 0)
			{
				$admin_menus[intval($row->menu_srl)] = true;
				continue;
			}
			$menu_titles[intval($row->menu_srl)] = intval($row->menu_srl) === $unlinked_menu ? lang('menu.site_editor_unlinked') : html_entity_decode(Context::replaceUserLang(strval($row->title), true), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
		}
		$rows = DB::getInstance()->query("SELECT menu_item_srl, menu_srl, name, url, is_shortcut, group_srls FROM menu_item WHERE group_srls IS NOT NULL AND group_srls <> ''")->fetchAll();
		foreach ($rows as $row)
		{
			$list = self::srlList($row->group_srls);
			if (!in_array($group_srl, $list, true) || isset($admin_menus[intval($row->menu_srl)]))
			{
				continue;
			}
			$menu_srl = intval($row->menu_srl);
			$site = $sitemap_sites[$menu_srl] ?? $default_site;
			$usage[] = [
				'kind' => 'menu',
				'site' => isset($sites[$site]) ? $site : $default_site,
				'title' => self::text($row->name),
				'where' => $menu_titles[$menu_srl] ?? '',
				'others' => array_values(array_diff($list, [$group_srl])),
				'url' => $editor_url . '&domain_srl=' . (isset($sites[$site]) ? $site : $default_site) . '&menu_item_srl=' . intval($row->menu_item_srl),
			];
		}

		// Module permissions, with the other groups of the same permission.
		$grant_rows = DB::getInstance()->query('SELECT module_srl, name FROM module_grants WHERE group_srl = ?', [$group_srl])->fetchAll();
		if ($grant_rows)
		{
			$module_srls = array_values(array_unique(array_map(function($row) { return intval($row->module_srl); }, $grant_rows)));
			$marks = implode(',', array_fill(0, count($module_srls), '?'));
			$others = [];
			foreach (DB::getInstance()->query('SELECT module_srl, name, group_srl FROM module_grants WHERE module_srl IN (' . $marks . ')', $module_srls)->fetchAll() as $row)
			{
				$others[intval($row->module_srl)][strval($row->name)][] = intval($row->group_srl);
			}
			$modules = [];
			foreach (DB::getInstance()->query('SELECT module_srl, module, mid, browser_title, domain_srl FROM modules WHERE module_srl IN (' . $marks . ')', $module_srls)->fetchAll() as $row)
			{
				$modules[intval($row->module_srl)] = $row;
			}
			$specs = [];
			foreach ($grant_rows as $row)
			{
				$module_srl = intval($row->module_srl);
				$module = $modules[$module_srl] ?? null;
				if (!$module)
				{
					continue;
				}
				if (!isset($specs[$module->module]))
				{
					$specs[$module->module] = self::grantTitles(strval($module->module));
				}
				$site = intval($module->domain_srl);
				$usage[] = [
					'kind' => 'grant',
					'site' => isset($sites[$site]) ? $site : $default_site,
					'title' => self::text($module->browser_title ?: $module->mid),
					'where' => $specs[$module->module][$row->name] ?? strval($row->name),
					'others' => array_values(array_diff($others[$module_srl][$row->name] ?? [], [$group_srl])),
					'url' => self::moduleEditorUrl($editor_url, strval($module->mid), isset($sites[$site]) ? $site : $default_site, $sitemap_sites),
				];
			}
		}

		// Document categories limited to some groups.
		$rows = DB::getInstance()->query("SELECT document_categories.category_srl, document_categories.module_srl, document_categories.title, document_categories.group_srls FROM document_categories WHERE document_categories.group_srls IS NOT NULL AND document_categories.group_srls <> ''")->fetchAll();
		$category_modules = [];
		foreach ($rows as $row)
		{
			$list = self::srlList($row->group_srls);
			if (!in_array($group_srl, $list, true))
			{
				continue;
			}
			$module_srl = intval($row->module_srl);
			if (!array_key_exists($module_srl, $category_modules))
			{
				$category_modules[$module_srl] = ModuleModel::getModuleInfoByModuleSrl($module_srl);
			}
			$module = $category_modules[$module_srl];
			$site = $module ? intval($module->domain_srl) : 0;
			$usage[] = [
				'kind' => 'category',
				'site' => isset($sites[$site]) ? $site : $default_site,
				'title' => self::text($row->title),
				'where' => $module ? self::text($module->browser_title ?: $module->mid) : '',
				'others' => array_values(array_diff($list, [$group_srl])),
				'url' => ($module && $module->module === 'board') ? getNotEncodedUrl('', 'module', 'admin', 'act', 'dispBoardAdminCategoryInfo', 'module_srl', $module_srl) : '',
			];
		}

		// Point level that moves members into the group.
		$levels = self::pointLevels();
		if (isset($levels[$group_srl]))
		{
			$usage[] = [
				'kind' => 'point',
				'site' => 0,
				'level' => $levels[$group_srl],
				'url' => getNotEncodedUrl('', 'module', 'admin', 'act', 'dispPointAdminConfig', 'sec', 'level'),
			];
		}

		// Identity verification age rule.
		$identity = \Zittme\Modules\Member\Identity\Base::getConfig();
		if (isset($identity->identity_age_groups[$group_srl]))
		{
			$usage[] = [
				'kind' => 'identity',
				'site' => 0,
				'age' => intval($identity->identity_age_groups[$group_srl]),
				'on' => ($identity->identity_enable ?? 'N') === 'Y',
				'url' => getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMemberAdminConfig', 'cat', 'social'),
			];
		}

		// Other modules add their own places through this trigger.
		$trigger = new \stdClass;
		$trigger->group_srl = $group_srl;
		$trigger->usage = [];
		ModuleHandler::triggerCall('member.getGroupUsage', 'after', $trigger);
		foreach ((array)$trigger->usage as $item)
		{
			$item = (array)$item;
			$title = trim(strip_tags(strval($item['title'] ?? '')));
			if ($title === '')
			{
				continue;
			}
			$site = intval($item['site'] ?? 0);
			$url = strval($item['url'] ?? '');
			$usage[] = [
				'kind' => 'other',
				'site' => isset($sites[$site]) ? $site : 0,
				'title' => $title,
				'where' => trim(strip_tags(strval($item['detail'] ?? ''))),
				'effect' => trim(strip_tags(strval($item['effect'] ?? ''))),
				'url' => preg_match('#^(https?://|/|\./|index\.php)#', $url) ? $url : '',
			];
		}

		return ['usage' => $usage, 'sites' => array_values($sites)];
	}

	/**
	 * Sites (domains), keyed by domain_srl. One site means no grouping.
	 */
	protected static function sites(): array
	{
		$sites = [];
		if (class_exists('Zittme\\Modules\\Menu\\Models\\EditorState'))
		{
			foreach (\Zittme\Modules\Menu\Models\EditorState::getSites() as $site)
			{
				$sites[intval($site->domain_srl)] = [
					'id' => intval($site->domain_srl),
					'domain' => strval($site->domain),
					'title' => strval($site->title),
					'default' => !empty($site->is_default),
				];
			}
		}
		return $sites;
	}

	protected static function moduleEditorUrl(string $editor_url, string $mid, int $site, array $sitemap_sites): string
	{
		if ($mid === '')
		{
			return '';
		}
		$items = executeQueryArray('menu.getMenuItemByUrl', (object)['url' => $mid, 'is_shortcut' => 'N'])->data ?: [];
		foreach ($items as $item)
		{
			$item_site = $sitemap_sites[intval($item->menu_srl)] ?? $site;
			return $editor_url . '&domain_srl=' . intval($item_site ?: $site) . '&menu_item_srl=' . intval($item->menu_item_srl);
		}
		return $editor_url . '&domain_srl=' . $site;
	}

	/**
	 * Permission names as the site editor shows them.
	 */
	protected static function grantTitles(string $module): array
	{
		$titles = ['access' => lang('menu.site_editor_grant_access'), 'manager' => lang('menu.site_editor_grant_manager')];
		$xml_info = ModuleModel::getModuleActionXml($module);
		foreach ((array)($xml_info->grant ?? []) as $name => $grant)
		{
			$titles[$name] = strval($grant->title ?? $name);
		}
		return $titles;
	}

	protected static function srlList($value): array
	{
		$list = [];
		foreach (explode(',', strval($value)) as $item)
		{
			$item = intval(trim($item));
			if ($item !== 0)
			{
				$list[] = $item;
			}
		}
		return array_values(array_unique($list));
	}

	protected static function text($value): string
	{
		return trim(html_entity_decode(strip_tags(Context::replaceUserLang(strval($value), true)), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
	}

	/**
	 * Images of the file box that can be a group image mark.
	 */
	public static function fileBox(int $page): array
	{
		$args = new \stdClass;
		$args->page = max(1, $page);
		$args->list_count = 24;
		$args->page_count = 5;
		$args->fileextension = ['gif', 'png', 'jpg', 'jpeg', 'webp', 'svg'];
		$output = executeQueryArray('module.getModuleFileBoxList', $args);
		$base = getUrl('');
		$images = [];
		foreach ($output->data ?: [] as $row)
		{
			$images[] = $base . $row->filename;
		}
		return [
			'images' => $images,
			'page' => intval($output->page ?? 1),
			'total_page' => intval($output->total_page ?? 1),
			'url' => getNotEncodedUrl('', 'module', 'admin', 'act', 'dispModuleAdminFileBox'),
		];
	}
}
