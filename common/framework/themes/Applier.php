<?php

namespace Zittme\Framework\Themes;

use Zittme\Framework\Cache;
use Zittme\Framework\Storage;
use Zittme\Framework\Theme;

/**
 * Apply a theme to a site, and revert.
 *
 * Applying is separate from installing (Installer). Only components
 * declared in the <apply> section of theme.xml are ever touched;
 * settings for anything else are neither read nor written.
 *
 * @see docs/THEME-PACKAGE.md
 */
class Applier
{
	/**
	 * Where config backups for revert are stored.
	 * Kept outside the theme directory so deleting a theme does not
	 * delete its backup.
	 */
	public const BACKUP_DIR = 'files/theme_backup/';

	/**
	 * Calculate what would change if the theme were applied,
	 * so the admin can review before confirming.
	 *
	 * @param string $theme_name
	 * @param int $domain_srl
	 * @return array
	 */
	public static function preview(string $theme_name, int $domain_srl = 0): array
	{
		$info = Theme::getInfo($theme_name);
		if (!$info)
		{
			return [];
		}

		$changes = [];

		foreach (['skins' => 'skin', 'mskins' => 'mskin'] as $key => $column)
		{
			foreach ($info->apply->{$key} as $item)
			{
				$value = Theme::combine($theme_name, $item->name);
				foreach (self::getModuleInstances($item->module, $domain_srl) as $module)
				{
					$current = $module->{$column} ?? '';
					if ($current === $value)
					{
						continue;
					}
					$changes[] = (object)[
						'module_srl' => (int)$module->module_srl,
						'mid' => $module->mid,
						'module' => $item->module,
						'column' => $column,
						'from' => $current,
						'to' => $value,
					];
				}
			}
		}

		return $changes;
	}

	/**
	 * Apply a theme.
	 *
	 * @param string $theme_name
	 * @param int $domain_srl
	 * @param array $exclude module_srls to skip
	 * @return object ok / message / applied
	 */
	public static function apply(string $theme_name, int $domain_srl = 0, array $exclude = []): object
	{
		$result = new \stdClass;
		$result->ok = false;
		$result->message = '';
		$result->applied = 0;

		$info = Theme::getInfo($theme_name);
		if (!$info)
		{
			$result->message = 'msg_theme_not_found';
			return $result;
		}

		$changes = self::preview($theme_name, $domain_srl);
		$exclude = array_map('intval', $exclude);

		// Back up current values, but only for entries this theme will
		// actually change, so that revert does not touch unrelated settings.
		$backup = [];
		foreach ($changes as $change)
		{
			if (in_array($change->module_srl, $exclude, true))
			{
				continue;
			}
			$backup[] = (object)[
				'module_srl' => $change->module_srl,
				'column' => $change->column,
				'value' => $change->from,
			];
		}
		self::saveBackup($theme_name, $domain_srl, $backup);

		foreach ($changes as $change)
		{
			if (in_array($change->module_srl, $exclude, true))
			{
				continue;
			}
			if (self::updateModuleColumn($change->module_srl, $change->column, $change->to))
			{
				$result->applied++;
			}
		}

		if ($info->apply->view_mode !== null)
		{
			self::applyViewMode($info, $theme_name, $domain_srl, $exclude);
		}

		// Default design (site-wide layout and default skins). Without this,
		// only existing module instances change and the design panel keeps
		// showing the previous layout/skin defaults.
		self::applyDefaultDesign($info, $theme_name);

		self::clearCaches();

		$result->ok = true;
		return $result;
	}

	/**
	 * Apply the theme to the site default design (files/site_design/design_0.php):
	 * the default layout instance and per-module default skins.
	 *
	 * @param object $info
	 * @param string $theme_name
	 * @return void
	 */
	protected static function applyDefaultDesign(object $info, string $theme_name): void
	{
		$design_file = \RX_BASEDIR . 'files/site_design/design_0.php';
		$designInfo = new \stdClass;
		if (is_readable($design_file))
		{
			include $design_file;
		}
		if (!isset($designInfo->layout_srl))
		{
			$designInfo->layout_srl = 0;
		}
		if (!isset($designInfo->mlayout_srl))
		{
			$designInfo->mlayout_srl = 0;
		}
		if (!isset($designInfo->module))
		{
			$designInfo->module = new \stdClass;
		}

		if ($info->apply->layout)
		{
			$layout_srl = self::getLayoutInstance($theme_name, $info->apply->layout, 'P', $info);
			if ($layout_srl)
			{
				$designInfo->layout_srl = $layout_srl;
			}
		}
		if ($info->apply->mlayout)
		{
			$mlayout_srl = self::getLayoutInstance($theme_name, $info->apply->mlayout, 'M', $info);
			if ($mlayout_srl)
			{
				$designInfo->mlayout_srl = $mlayout_srl;
			}
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

		self::writeDefaultDesignFile($designInfo);
		self::applyConfigSkins($info, $theme_name);
	}

	/**
	 * Modules that store their skin in the module config instead of a module
	 * instance (member). The design default alone does not reach them when a
	 * value is already saved, so the config is updated directly.
	 *
	 * @param object $info
	 * @param string $theme_name
	 * @return void
	 */
	protected static function applyConfigSkins(object $info, string $theme_name): void
	{
		$config_skin_modules = ['member'];

		foreach (['skins' => 'skin', 'mskins' => 'mskin'] as $key => $column)
		{
			foreach ($info->apply->{$key} as $item)
			{
				if (!in_array($item->module, $config_skin_modules, true))
				{
					continue;
				}
				$config = \ModuleModel::getModuleConfig($item->module) ?: new \stdClass;
				$config->{$column} = Theme::combine($theme_name, $item->name);
				try
				{
					\ModuleController::getInstance()->insertModuleConfig($item->module, $config);
				}
				catch (\Throwable $e)
				{
					// Outside a web request the module controller is unavailable;
					// the design default still covers fresh installations.
				}
			}
		}
	}

	/**
	 * Write files/site_design/design_0.php. Same format as the admin
	 * Design controller, but without instantiating a module controller
	 * so that it also works outside a web request.
	 *
	 * @param object $designInfo
	 * @return void
	 */
	protected static function writeDefaultDesignFile(object $designInfo): void
	{
		$buff = [];
		$buff[] = '<?php if(!defined("__XE__")) exit();';
		$buff[] = '$designInfo = new stdClass;';
		if (!empty($designInfo->layout_srl))
		{
			$buff[] = sprintf('$designInfo->layout_srl = %d; ', intval($designInfo->layout_srl));
		}
		if (!empty($designInfo->mlayout_srl))
		{
			$buff[] = sprintf('$designInfo->mlayout_srl = %d;', intval($designInfo->mlayout_srl));
		}
		$buff[] = '$designInfo->module = new stdClass;';
		foreach ($designInfo->module as $module_name => $skin_info)
		{
			$buff[] = sprintf('$designInfo->module->{%s} = new stdClass;', var_export(strval($module_name), true));
			foreach ($skin_info as $target => $skin_name)
			{
				$buff[] = sprintf('$designInfo->module->{%s}->{%s} = %s;',
					var_export(strval($module_name), true), var_export(strval($target), true), var_export(strval($skin_name), true));
			}
		}

		Storage::write(\RX_BASEDIR . 'files/site_design/design_0.php', implode(PHP_EOL, $buff));
	}

	/**
	 * Find the layout instance for a theme layout, creating one if needed.
	 *
	 * @param string $theme_name
	 * @param object $item apply->layout entry (name, vars)
	 * @param string $layout_type P or M
	 * @param object $info theme info (for the title)
	 * @return int layout_srl, 0 on failure
	 */
	protected static function getLayoutInstance(string $theme_name, object $item, string $layout_type, object $info): int
	{
		$combined = Theme::combine($theme_name, $item->name);

		$output = executeQueryArray('layout.getLayoutList', new \stdClass);
		foreach (($output->data ?? []) as $layout)
		{
			if (($layout->layout ?? '') === $combined && ($layout->layout_type ?? 'P') === $layout_type)
			{
				return (int)$layout->layout_srl;
			}
		}

		$args = new \stdClass;
		$args->layout_srl = getNextSequence();
		$args->site_srl = 0;
		$args->layout = $combined;
		$args->title = $info->title ?: $theme_name;
		$args->layout_type = $layout_type;
		if (!empty($item->vars))
		{
			$args->extra_vars = serialize((object)$item->vars);
		}
		$insert = executeQuery('layout.insertLayout', $args);

		return $insert->toBool() ? (int)$args->layout_srl : 0;
	}

	/**
	 * Revert to the state before the theme was applied.
	 *
	 * @param string $theme_name
	 * @param int $domain_srl
	 * @return object ok / message / reverted
	 */
	public static function revert(string $theme_name, int $domain_srl = 0): object
	{
		$result = new \stdClass;
		$result->ok = false;
		$result->message = '';
		$result->reverted = 0;

		$backup = self::loadBackup($theme_name, $domain_srl);
		if (!$backup)
		{
			$result->message = 'msg_theme_no_backup';
			return $result;
		}

		foreach ($backup as $item)
		{
			// Skip if the skin the backup points to no longer exists.
			if ($item->value !== '' && !self::skinExists($item->module_srl, $item->value, $item->column))
			{
				continue;
			}
			if (self::updateModuleColumn($item->module_srl, $item->column, $item->value))
			{
				$result->reverted++;
			}
		}

		self::clearCaches();

		$result->ok = true;
		return $result;
	}

	/**
	 * Find where a theme is currently in use, across all sites.
	 *
	 * @param string $theme_name
	 * @return array
	 */
	public static function findUsage(string $theme_name): array
	{
		$prefix = $theme_name . Theme::SEPARATOR;

		$args = new \stdClass;
		$args->s_skin = $prefix;
		$args->s_mskin = $prefix;
		$output = executeQueryArray('module.getModuleListBySkin', $args);

		$usage = [];
		foreach (($output->data ?: []) as $module)
		{
			foreach (['skin', 'mskin'] as $column)
			{
				if (strpos((string)($module->{$column} ?? ''), $prefix) === 0)
				{
					$usage[] = (object)[
						'module_srl' => (int)$module->module_srl,
						'mid' => $module->mid,
						'column' => $column,
					];
				}
			}
		}

		$layout_output = executeQueryArray('layout.getLayoutList', new \stdClass);
		foreach (($layout_output->data ?? []) as $layout)
		{
			if (strpos((string)($layout->layout ?? ''), $prefix) === 0)
			{
				$usage[] = (object)[
					'layout_srl' => (int)$layout->layout_srl,
					'title' => $layout->title,
					'column' => 'layout',
				];
			}
		}

		return $usage;
	}

	/**
	 * Get instances of a module type, restricted to the given site
	 * so that other sites on the same installation are not affected.
	 *
	 * @param string $module e.g. board
	 * @param int $domain_srl
	 * @return array
	 */
	protected static function getModuleInstances(string $module, int $domain_srl): array
	{
		$args = new \stdClass;
		$args->module = $module;
		if ($domain_srl > 0)
		{
			$args->domain_srls = [$domain_srl];
		}

		$output = executeQueryArray('module.getMidList', $args);
		return $output->data ?: [];
	}

	/**
	 * Update one skin column of a module instance.
	 *
	 * @param int $module_srl
	 * @param string $column skin or mskin
	 * @param string $value
	 * @return bool
	 */
	protected static function updateModuleColumn(int $module_srl, string $column, string $value): bool
	{
		if (!in_array($column, ['skin', 'mskin'], true))
		{
			return false;
		}

		$module_info = \ModuleModel::getModuleInfoByModuleSrl($module_srl);
		if (!$module_info || !$module_info->module_srl)
		{
			return false;
		}

		$module_info->{$column} = $value;
		$output = \ModuleController::getInstance()->updateModule($module_info);
		return $output->toBool();
	}

	/**
	 * Check whether the skin a backup entry points to still exists.
	 *
	 * @param int $module_srl
	 * @param string $skin
	 * @param string $column
	 * @return bool
	 */
	protected static function skinExists(int $module_srl, string $skin, string $column): bool
	{
		if (in_array($skin, ['/USE_DEFAULT/', '/USE_RESPONSIVE/'], true))
		{
			return true;
		}

		$module_info = \ModuleModel::getModuleInfoByModuleSrl($module_srl);
		if (!$module_info)
		{
			return false;
		}

		$dir = ($column === 'mskin') ? 'm.skins' : 'skins';
		$base = \RX_BASEDIR . 'modules/' . $module_info->module;
		return Storage::isDirectory(Theme::resolveSkinPath($base, $skin, $dir));
	}

	/**
	 * Apply the view mode declared by the theme. For responsive themes,
	 * mobile skins without an explicit declaration are set to match the
	 * PC skin.
	 *
	 * @param object $info
	 * @param string $theme_name
	 * @param int $domain_srl
	 * @param array $exclude
	 * @return void
	 */
	protected static function applyViewMode(object $info, string $theme_name, int $domain_srl, array $exclude): void
	{
		if ($info->apply->view_mode !== 'R')
		{
			return;
		}

		foreach ($info->apply->skins as $item)
		{
			$has_mskin = false;
			foreach ($info->apply->mskins as $mitem)
			{
				if ($mitem->module === $item->module)
				{
					$has_mskin = true;
					break;
				}
			}
			if ($has_mskin)
			{
				continue;
			}

			$value = Theme::combine($theme_name, $item->name);
			foreach (self::getModuleInstances($item->module, $domain_srl) as $module)
			{
				if (in_array((int)$module->module_srl, $exclude, true))
				{
					continue;
				}
				self::updateModuleColumn((int)$module->module_srl, 'mskin', $value);
			}
		}
	}

	/**
	 * @param string $theme_name
	 * @param int $domain_srl
	 * @return string
	 */
	protected static function getBackupPath(string $theme_name, int $domain_srl): string
	{
		return \RX_BASEDIR . self::BACKUP_DIR . $theme_name . '_' . $domain_srl . '.php';
	}

	/**
	 * @param string $theme_name
	 * @param int $domain_srl
	 * @param array $data
	 * @return void
	 */
	protected static function saveBackup(string $theme_name, int $domain_srl, array $data): void
	{
		Storage::createDirectory(\RX_BASEDIR . self::BACKUP_DIR);
		Storage::writePHPData(self::getBackupPath($theme_name, $domain_srl), $data);
	}

	/**
	 * @param string $theme_name
	 * @param int $domain_srl
	 * @return array
	 */
	protected static function loadBackup(string $theme_name, int $domain_srl): array
	{
		$path = self::getBackupPath($theme_name, $domain_srl);
		if (!Storage::exists($path))
		{
			return [];
		}
		$data = Storage::readPHPData($path);
		return is_array($data) ? $data : [];
	}

	/**
	 * @return void
	 */
	protected static function clearCaches(): void
	{
		Cache::clearGroup('site_and_module');
	}
}
