<?php

namespace Zittme\Modules\Menu\Models;

use Zittme\Framework\Cache;
use Zittme\Framework\DB;
use Zittme\Framework\Storage;
use Zittme\Framework\Theme;
use Zittme\Framework\Themes\Applier;

/**
 * Apply a theme to one site from the site editor, and take it back exactly.
 *
 * The default site is handled by the existing Applier::apply(), the same
 * as the theme apply screen. Any other site gets the same module changes
 * limited to its own modules, but its default design is written to its own
 * file (files/site_design/design_d{domain_srl}.php) and it gets a layout
 * instance of its own, so that the other sites do not change.
 */
class SiteTheme extends Applier
{
	/**
	 * Modules that keep their skin in the module settings.
	 */
	const CONFIG_SKIN_MODULES = ['member', 'communication', 'message', 'ncenterlite'];

	/**
	 * Thumbnail file names looked up in the theme folder, in this order.
	 */
	const THUMBNAIL_FILES = ['thumbnail.jpg', 'thumbnail.webp', 'thumbnail.png', 'thumbnail.jpeg'];

	/**
	 * URL of the thumbnail image in the theme folder, or an empty string.
	 *
	 * @param string $name
	 * @return string
	 */
	public static function getThumbnail(string $name): string
	{
		if (!Theme::isValidName($name))
		{
			return '';
		}
		$path = Theme::getPath($name);
		foreach (self::THUMBNAIL_FILES as $file)
		{
			if (Storage::exists($path . $file))
			{
				return \RX_BASEURL . Theme::BASE_DIR . $name . '/' . $file . '?t=' . intval(@filemtime($path . $file));
			}
		}
		return '';
	}

	/**
	 * Theme cards for the editor.
	 *
	 * @return array
	 */
	public static function getThemes(): array
	{
		$list = [];
		foreach (Theme::getList() as $name => $info)
		{
			if (!$info->supported || !$info->apply->layout)
			{
				continue;
			}
			$skins = [];
			foreach ($info->apply->skins as $item)
			{
				$skins[$item->module] = true;
			}
			$list[] = (object)[
				'name' => $name,
				'title' => html_entity_decode(strval($info->title), ENT_QUOTES, 'UTF-8'),
				'skin_count' => count($skins),
				'responsive' => $info->apply->view_mode === 'R',
				'thumbnail' => self::getThumbnail($name),
			];
		}
		return $list;
	}

	/**
	 * Remember everything a theme apply may change, so that it can be put
	 * back exactly.
	 *
	 * @param int $domain_srl
	 * @param bool $is_default
	 * @param string $theme_name
	 * @return array
	 */
	public static function snapshot(int $domain_srl, bool $is_default, string $theme_name): array
	{
		$oDB = DB::getInstance();
		$modules = [];
		$stmt = $oDB->query('SELECT * FROM modules');
		foreach ($stmt ? $stmt->fetchAll() : [] as $row)
		{
			$modules[intval($row->module_srl)] = (array)$row;
		}
		$extra_vars = self::_readExtraVars();
		$configs = [];
		if ($is_default)
		{
			foreach (self::CONFIG_SKIN_MODULES as $module)
			{
				$stmt = $oDB->query('SELECT config FROM module_config WHERE module = ?', [$module]);
				$rows = $stmt ? $stmt->fetchAll() : [];
				$configs[$module] = isset($rows[0]) ? strval($rows[0]->config) : null;
			}
		}
		$layouts = [];
		$stmt = $oDB->query('SELECT layout_srl, extra_vars FROM layouts');
		foreach ($stmt ? $stmt->fetchAll() : [] as $row)
		{
			$layouts[intval($row->layout_srl)] = $row->extra_vars;
		}
		$backup_file = self::getBackupPath($theme_name, $is_default ? 0 : $domain_srl);
		return [
			'domain_srl' => $domain_srl,
			'design' => SiteDesign::readRaw($is_default ? 0 : $domain_srl),
			'backup_file' => file_exists($backup_file) ? strval(file_get_contents($backup_file)) : null,
			'backup_path' => $backup_file,
			'modules' => $modules,
			'extra_vars' => $extra_vars,
			'configs' => $configs,
			'layouts' => $layouts,
		];
	}

	/**
	 * Put back what snapshot() remembered.
	 *
	 * @param array $snap
	 * @param bool $is_default
	 * @return void
	 */
	public static function restore(array $snap, bool $is_default): void
	{
		$oDB = DB::getInstance();
		$domain_srl = intval($snap['domain_srl'] ?? 0);

		$now = [];
		$stmt = $oDB->query('SELECT * FROM modules');
		foreach ($stmt ? $stmt->fetchAll() : [] as $row)
		{
			$now[intval($row->module_srl)] = (array)$row;
		}
		foreach ($snap['modules'] ?? [] as $module_srl => $row)
		{
			if (!isset($now[$module_srl]) || $now[$module_srl] === $row)
			{
				continue;
			}
			$columns = array_values(array_filter(array_keys($row), function($column) {
				return $column !== 'module_srl' && preg_match('/^[a-z_]+$/', $column);
			}));
			$params = array_map(function($column) use ($row) { return $row[$column]; }, $columns);
			$params[] = intval($module_srl);
			$oDB->query('UPDATE modules SET ' . implode(', ', array_map(function($column) { return '`' . $column . '` = ?'; }, $columns)) . ' WHERE module_srl = ?', $params);
		}

		$now = self::_readExtraVars();
		$before = $snap['extra_vars'] ?? null;
		if (is_array($before))
		{
			foreach (array_unique(array_merge(array_keys($before), array_keys($now))) as $module_srl)
			{
				if (($before[$module_srl] ?? []) === ($now[$module_srl] ?? []) || !isset($snap['modules'][$module_srl]))
				{
					continue;
				}
				$oDB->query('DELETE FROM module_extra_vars WHERE module_srl = ?', [intval($module_srl)]);
				foreach ($before[$module_srl] ?? [] as $name => $value)
				{
					$oDB->query('INSERT INTO module_extra_vars (module_srl, name, value) VALUES (?, ?, ?)', [intval($module_srl), strval($name), $value]);
				}
				Cache::delete('site_and_module:module_extra_vars:' . intval($module_srl));
			}
		}

		foreach ($snap['configs'] ?? [] as $module => $raw)
		{
			if ($raw === null)
			{
				$oDB->query('DELETE FROM module_config WHERE module = ?', [$module]);
			}
			else
			{
				$oDB->query('UPDATE module_config SET config = ? WHERE module = ?', [$raw, $module]);
			}
			Cache::delete('site_and_module:module_config:' . $module);
		}

		$known = array_keys($snap['layouts'] ?? []);
		$last_known = count($known) ? max($known) : 0;
		$stmt = $oDB->query('SELECT layout_srl, extra_vars FROM layouts');
		foreach ($stmt ? $stmt->fetchAll() : [] as $row)
		{
			$layout_srl = intval($row->layout_srl);
			if (!array_key_exists($layout_srl, $snap['layouts'] ?? []))
			{
				if ($layout_srl > $last_known && in_array($layout_srl, array_map('intval', $snap['created_layouts'] ?? []), true))
				{
					getAdminController('layout')->deleteLayout($layout_srl);
				}
				continue;
			}
			if ($row->extra_vars !== $snap['layouts'][$layout_srl])
			{
				$oDB->query('UPDATE layouts SET extra_vars = ? WHERE layout_srl = ?', [$snap['layouts'][$layout_srl], $layout_srl]);
			}
			Cache::delete('layout:' . $layout_srl);
		}

		SiteDesign::restoreRaw($is_default ? 0 : $domain_srl, $snap['design'] ?? null);
		$backup_path = strval($snap['backup_path'] ?? '');
		if ($backup_path !== '' && strpos($backup_path, \RX_BASEDIR . self::BACKUP_DIR) === 0)
		{
			if (($snap['backup_file'] ?? null) === null)
			{
				Storage::delete($backup_path);
			}
			else
			{
				Storage::write($backup_path, $snap['backup_file']);
			}
		}
		Cache::clearGroup('site_and_module');
	}

	/**
	 * Read the extra variables of all modules, as {module_srl: {name: value}}.
	 *
	 * @return array
	 */
	protected static function _readExtraVars(): array
	{
		$result = [];
		$stmt = DB::getInstance()->query('SELECT module_srl, name, value FROM module_extra_vars ORDER BY module_srl, name');
		foreach ($stmt ? $stmt->fetchAll() : [] as $row)
		{
			$result[intval($row->module_srl)][strval($row->name)] = $row->value;
		}
		return $result;
	}

	/**
	 * Apply a theme to a site that is not the default one.
	 *
	 * @param string $theme_name
	 * @param int $domain_srl
	 * @param array $exclude module_srls to skip
	 * @param string $site_label shown after the title of a new layout instance
	 * @return object ok / message / applied / layout_srl / mlayout_srl
	 */
	public static function applyToSite(string $theme_name, int $domain_srl, array $exclude, string $site_label): object
	{
		$result = (object)['ok' => false, 'message' => '', 'applied' => 0, 'layout_srl' => 0, 'mlayout_srl' => 0];
		$info = Theme::getInfo($theme_name);
		if (!$info || $domain_srl <= 0)
		{
			$result->message = 'msg_theme_not_found';
			return $result;
		}

		$exclude = array_map('intval', $exclude);
		$changes = self::preview($theme_name, $domain_srl);
		$backup = [];
		foreach ($changes as $change)
		{
			if (!in_array($change->module_srl, $exclude, true))
			{
				$backup[] = (object)['module_srl' => $change->module_srl, 'column' => $change->column, 'value' => $change->from];
			}
		}
		self::saveBackup($theme_name, $domain_srl, $backup);
		foreach ($changes as $change)
		{
			if (!in_array($change->module_srl, $exclude, true) && self::updateModuleColumn($change->module_srl, $change->column, $change->to))
			{
				$result->applied++;
			}
		}
		if ($info->apply->view_mode !== null)
		{
			self::applyViewMode($info, $theme_name, $domain_srl, $exclude);
		}

		$designInfo = new \stdClass;
		$designInfo->module = new \stdClass;
		if ($info->apply->layout)
		{
			$designInfo->layout_srl = self::getSiteLayoutInstance($theme_name, $info->apply->layout, 'P', $info, $site_label, $domain_srl);
		}
		if ($info->apply->mlayout)
		{
			$designInfo->mlayout_srl = self::getSiteLayoutInstance($theme_name, $info->apply->mlayout, 'M', $info, $site_label, $domain_srl);
		}
		foreach (['skins' => 'skin', 'mskins' => 'mskin'] as $key => $column)
		{
			foreach ($info->apply->{$key} as $item)
			{
				if (!isset($designInfo->module->{$item->module}))
				{
					$designInfo->module->{$item->module} = new \stdClass;
				}
				$designInfo->module->{$item->module}->{$column} = Theme::combine($theme_name, $item->name);
			}
		}
		if (!$info->apply->mlayout && !empty($designInfo->layout_srl))
		{
			$designInfo->mlayout_srl = $designInfo->layout_srl;
		}
		if ($info->apply->view_mode === 'R')
		{
			foreach ($info->apply->skins as $item)
			{
				$module = $designInfo->module->{$item->module};
				if (empty($module->mskin) || !self::hasMobileSkinFor($info, $item->module))
				{
					$module->mskin = Theme::combine($theme_name, $item->name);
				}
			}
		}
		SiteDesign::write($domain_srl, $designInfo);
		self::clearCaches();

		$result->ok = true;
		$result->layout_srl = intval($designInfo->layout_srl ?? 0);
		$result->mlayout_srl = intval($designInfo->mlayout_srl ?? 0);
		return $result;
	}

	/**
	 * Find the layout instance a site uses for a theme layout, or make one.
	 *
	 * An instance made for a site has the site name after its title, so that
	 * the logo and colors of one site do not change another site. A new
	 * instance shows the sitemap of that site in its main menu slot, never
	 * the sitemap of another site.
	 *
	 * @param string $theme_name
	 * @param object $item
	 * @param string $layout_type
	 * @param object $info
	 * @param string $site_label
	 * @param int $domain_srl
	 * @return int
	 */
	protected static function getSiteLayoutInstance(string $theme_name, object $item, string $layout_type, object $info, string $site_label, int $domain_srl): int
	{
		$combined = Theme::combine($theme_name, $item->name);
		$title = ($info->title ?: $theme_name) . ' (' . $site_label . ')';
		$output = executeQueryArray('layout.getLayoutList', new \stdClass);
		foreach (($output->data ?? []) as $layout)
		{
			if (($layout->layout ?? '') === $combined && ($layout->layout_type ?? 'P') === $layout_type && strval($layout->title) === $title)
			{
				return (int)$layout->layout_srl;
			}
		}

		$args = new \stdClass;
		$args->layout_srl = getNextSequence();
		$args->site_srl = 0;
		$args->layout = $combined;
		$args->title = $title;
		$args->layout_type = $layout_type;
		$vars = !empty($item->vars) ? (object)$item->vars : new \stdClass;
		$slot = self::getMainMenuSlot($combined, $layout_type);
		$menu_srl = $slot !== '' ? self::getSiteMenuSrl($domain_srl) : 0;
		if ($menu_srl)
		{
			$menu = getAdminModel('menu')->getMenu($menu_srl);
			$vars->{$slot} = strval($menu_srl);
			$vars->menu_name_list = [$menu_srl => $menu->title ?? null];
		}
		if (countobj($vars))
		{
			$args->extra_vars = serialize($vars);
		}
		$insert = executeQuery('layout.insertLayout', $args);
		return $insert->toBool() ? (int)$args->layout_srl : 0;
	}

	/**
	 * The menu slot of a layout that holds the main navigation.
	 *
	 * @param string $layout
	 * @param string $layout_type
	 * @return string
	 */
	protected static function getMainMenuSlot(string $layout, string $layout_type): string
	{
		$info = \LayoutModel::getLayoutInfo($layout, null, $layout_type);
		$slots = array_keys((array)($info->menu ?? []));
		foreach ($slots as $slot)
		{
			if (preg_match('/gnb|main/i', $slot))
			{
				return strval($slot);
			}
		}
		foreach ($slots as $slot)
		{
			if (!preg_match('/fnb|foot|bottom/i', $slot))
			{
				return strval($slot);
			}
		}
		return '';
	}

	/**
	 * The sitemap of a site: its default menu, or else the first sitemap that
	 * belongs to it. 0 when it has none.
	 *
	 * @param int $domain_srl
	 * @return int
	 */
	public static function getSiteMenuSrl(int $domain_srl): int
	{
		$oMenuAdminModel = getAdminModel('menu');
		$stmt = DB::getInstance()->query('SELECT default_menu_srl FROM domains WHERE domain_srl = ?', [$domain_srl]);
		$rows = $stmt ? $stmt->fetchAll() : [];
		$default = intval($rows[0]->default_menu_srl ?? 0);
		if ($default > 0 && $oMenuAdminModel->getMenu($default))
		{
			return $default;
		}
		$own = array_keys(array_filter(EditorState::getSitemapDomains(), function($srl) use ($domain_srl) {
			return $srl === $domain_srl;
		}));
		sort($own);
		foreach ($own as $menu_srl)
		{
			if ($oMenuAdminModel->getMenu($menu_srl))
			{
				return intval($menu_srl);
			}
		}
		return 0;
	}
}
