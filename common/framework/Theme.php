<?php

namespace Zittme\Framework;

/**
 * Theme metadata and status.
 *
 * A theme bundles layouts, module skins, and widget skins in its own
 * directory under ./themes/{name}/, mirroring their original paths.
 *
 * @see docs/THEME-PACKAGE.md
 */
class Theme
{
	/**
	 * Base directory for themes, relative to RX_BASEDIR.
	 */
	public const BASE_DIR = 'themes/';

	/**
	 * Separator between theme name and component name in stored skin values,
	 * e.g. 'biztheme|@|dark' in the modules.skin column.
	 */
	public const SEPARATOR = '|@|';

	/**
	 * Per-request cache of theme info.
	 *
	 * @var array
	 */
	protected static $_cache = [];

	/**
	 * Get the theme currently applied to this site, determined from actual
	 * skin settings. If skins from multiple themes are mixed, the most used
	 * theme wins.
	 *
	 * @param int $domain_srl
	 * @return ?object
	 */
	public static function getAppliedTheme(int $domain_srl = 0): ?object
	{
		$names = self::getNames();
		if (!count($names))
		{
			return null;
		}

		$args = new \stdClass;
		if ($domain_srl > 0)
		{
			$args->domain_srl = $domain_srl;
		}
		$output = executeQueryArray('module.getMidList', $args);
		if (!$output->toBool() || empty($output->data))
		{
			return null;
		}

		$counts = [];
		foreach ($output->data as $module)
		{
			foreach (['skin', 'mskin'] as $column)
			{
				$parts = self::split($module->{$column} ?? '');
				if ($parts && in_array($parts['theme'], $names, true))
				{
					$counts[$parts['theme']] = ($counts[$parts['theme']] ?? 0) + 1;
				}
			}
		}

		if (!count($counts))
		{
			return null;
		}

		arsort($counts);
		return self::getInfo((string)array_key_first($counts));
	}

	/**
	 * Clear the info cache. Call after installing or deleting a theme.
	 *
	 * @return void
	 */
	public static function clearCache(): void
	{
		self::$_cache = [];
	}

	/**
	 * Get the list of installed theme names.
	 *
	 * @return array
	 */
	public static function getNames(): array
	{
		$base = \RX_BASEDIR . self::BASE_DIR;
		if (!Storage::isDirectory($base))
		{
			return [];
		}

		$names = [];
		foreach ((Storage::readDirectory($base, false, true, false) ?: []) as $name)
		{
			if (!self::isValidName($name))
			{
				continue;
			}
			if (Storage::exists($base . $name . '/theme.xml'))
			{
				$names[] = $name;
			}
		}

		natcasesort($names);
		return array_values($names);
	}

	/**
	 * Get info of all installed themes.
	 *
	 * @return array [name => info]
	 */
	public static function getList(): array
	{
		$list = [];
		foreach (self::getNames() as $name)
		{
			$info = self::getInfo($name);
			if ($info)
			{
				$list[$name] = $info;
			}
		}
		return $list;
	}

	/**
	 * Get info of a theme.
	 *
	 * @param string $name
	 * @return ?object
	 */
	public static function getInfo(string $name): ?object
	{
		if (!self::isValidName($name))
		{
			return null;
		}
		if (array_key_exists($name, self::$_cache))
		{
			return self::$_cache[$name];
		}

		$filename = self::getPath($name) . 'theme.xml';
		$info = Storage::exists($filename) ? Parsers\ThemeInfoParser::loadXML($filename, $name) : null;
		return self::$_cache[$name] = $info;
	}

	/**
	 * Get the full path to a theme, with trailing slash.
	 *
	 * @param string $name
	 * @return string
	 */
	public static function getPath(string $name): string
	{
		return \RX_BASEDIR . self::BASE_DIR . $name . '/';
	}

	/**
	 * Check whether a string is a valid theme name.
	 * Theme names are used in filesystem paths, so they must be validated.
	 *
	 * @param string $name
	 * @return bool
	 */
	public static function isValidName(string $name): bool
	{
		return (bool)preg_match('/^[a-zA-Z0-9_]+$/', $name);
	}

	/**
	 * Split a stored skin value into theme and component names.
	 *
	 * @param mixed $value e.g. 'biztheme|@|dark'
	 * @return ?array ['theme' => ..., 'name' => ...], or null if not a theme value
	 */
	public static function split($value): ?array
	{
		if (!is_string($value) || strpos($value, self::SEPARATOR) === false)
		{
			return null;
		}

		$parts = explode(self::SEPARATOR, $value, 2);
		if (count($parts) !== 2 || !self::isValidName($parts[0]) || $parts[1] === '')
		{
			return null;
		}

		return ['theme' => $parts[0], 'name' => $parts[1]];
	}

	/**
	 * Combine theme and component names into a stored skin value.
	 *
	 * @param string $theme
	 * @param string $name
	 * @return string
	 */
	public static function combine(string $theme, string $name): string
	{
		return $theme . self::SEPARATOR . $name;
	}

	/**
	 * Get the base path for looking up a module skin inside a theme,
	 * suitable for the $path argument of ModuleModel::loadSkinInfo().
	 *
	 * @param string $theme
	 * @param string $module_name
	 * @return string
	 */
	public static function getModulePath(string $theme, string $module_name): string
	{
		return self::getPath($theme) . 'modules/' . $module_name;
	}

	/**
	 * Get the path to a widget inside a theme.
	 *
	 * @param string $theme
	 * @param string $widget_name
	 * @return string
	 */
	public static function getWidgetPath(string $theme, string $widget_name): string
	{
		return self::getPath($theme) . 'widgets/' . $widget_name;
	}

	/**
	 * Get the path to a layout inside a theme.
	 *
	 * @param string $theme
	 * @param string $layout_name
	 * @param string $layout_type P or M
	 * @return string
	 */
	public static function getLayoutPath(string $theme, string $layout_name, string $layout_type = 'P'): string
	{
		$dir = ($layout_type === 'M') ? 'm.layouts/' : 'layouts/';
		return self::getPath($theme) . $dir . $layout_name . '/';
	}

	/**
	 * Resolve a skin value to an actual template path. Plain skin names
	 * resolve under the module as before; 'theme|@|skin' values resolve
	 * inside the theme directory. Callers need not distinguish the two.
	 *
	 * @param string $base_path e.g. RX_BASEDIR . 'modules/board/'
	 * @param string $skin e.g. 'dark' or 'biztheme|@|dark'
	 * @param string $dir skins or m.skins
	 * @return string
	 */
	public static function resolveSkinPath(string $base_path, string $skin, string $dir = 'skins'): string
	{
		$base_path = rtrim($base_path, '/');

		$parts = self::split($skin);
		if (!$parts)
		{
			return $base_path . '/' . $dir . '/' . $skin;
		}

		$owner = self::toRelativePath($base_path);
		if ($owner === null)
		{
			return $base_path . '/' . $dir . '/' . $parts['name'];
		}

		return self::getPath($parts['theme']) . $owner . '/' . $dir . '/' . $parts['name'];
	}

	/**
	 * Convert a component location to the relative path used inside a theme.
	 * Theme directories mirror original paths, so stripping RX_BASEDIR or
	 * a leading './' is sufficient.
	 *
	 *   RX_BASEDIR . 'modules/board'  ->  modules/board
	 *   './widgets/banner/'           ->  widgets/banner
	 *
	 * @param string $path
	 * @return ?string null if the path does not match the expected pattern
	 */
	public static function toRelativePath(string $path): ?string
	{
		$path = str_replace('\\', '/', rtrim($path, '/'));

		$basedir = rtrim(str_replace('\\', '/', \RX_BASEDIR), '/');
		if (strpos($path, $basedir) === 0)
		{
			$path = substr($path, strlen($basedir));
		}
		$path = ltrim($path, './');

		if (!preg_match('!^(modules|widgets|addons|layouts|m\.layouts)(/[a-zA-Z0-9_.]+)+$!', $path))
		{
			return null;
		}

		return $path;
	}

	/**
	 * Get skins of a module provided by all installed themes.
	 * Keys are in 'theme|@|skin' format so the result can be merged
	 * with a regular skin list.
	 *
	 * @param string $module_name e.g. board
	 * @param string $dir skins or m.skins
	 * @return array
	 */
	public static function getModuleSkins(string $module_name, string $dir = 'skins'): array
	{
		if (!preg_match('/^[a-zA-Z0-9_]+$/', $module_name) || !in_array($dir, ['skins', 'm.skins'], true))
		{
			return [];
		}

		$result = [];
		foreach (self::getNames() as $theme)
		{
			$base = self::getModulePath($theme, $module_name);
			$skin_dir = $base . '/' . $dir . '/';
			if (!Storage::isDirectory($skin_dir))
			{
				continue;
			}

			foreach ((Storage::readDirectory($skin_dir, false, true, false) ?: []) as $skin_name)
			{
				if (!Storage::isDirectory($skin_dir . $skin_name))
				{
					continue;
				}

				$skin_info = \ModuleModel::loadSkinInfo($base, $skin_name, $dir);
				if (!$skin_info)
				{
					$skin_info = new \stdClass;
					$skin_info->title = $skin_name;
				}

				$theme_info = self::getInfo($theme);
				$skin_info->theme = $theme;
				$skin_info->theme_title = $theme_info->title ?? $theme;

				$result[self::combine($theme, $skin_name)] = $skin_info;
			}
		}

		return $result;
	}

	/**
	 * Get layouts provided by all installed themes.
	 *
	 * @param string $layout_type P or M
	 * @return array ['theme|@|layout' => info]
	 */
	public static function getLayouts(string $layout_type = 'P'): array
	{
		$dir = ($layout_type === 'M') ? 'm.layouts/' : 'layouts/';

		$result = [];
		foreach (self::getNames() as $theme)
		{
			$layout_dir = self::getPath($theme) . $dir;
			if (!Storage::isDirectory($layout_dir))
			{
				continue;
			}

			foreach ((Storage::readDirectory($layout_dir, false, true, false) ?: []) as $layout_name)
			{
				$info_file = $layout_dir . $layout_name . '/conf/info.xml';
				if (!Storage::exists($info_file))
				{
					continue;
				}

				$info = Parsers\LayoutInfoParser::loadXML($info_file, $layout_name, $layout_dir . $layout_name . '/');
				if (!$info)
				{
					continue;
				}

				$theme_info = self::getInfo($theme);
				$info->theme = $theme;
				$info->theme_title = $theme_info->title ?? $theme;

				$result[self::combine($theme, $layout_name)] = $info;
			}
		}

		return $result;
	}
}
