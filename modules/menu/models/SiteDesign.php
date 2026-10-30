<?php

namespace Zittme\Modules\Menu\Models;

use FileHandler;
use LayoutModel;
use ModuleModel;
use Zittme\Framework\Storage;
use Zittme\Framework\Theme;

/**
 * Default design of each site.
 *
 * The default site uses files/site_design/design_0.php as before. Another
 * site gets its own files/site_design/design_d{domain_srl}.php only when a
 * theme is applied to that site in the site editor. Without that file the
 * site keeps using design_0.php.
 */
class SiteDesign
{
	/**
	 * Get the path of the design file of a site.
	 *
	 * @param int $domain_srl 0 for the default site
	 * @return string
	 */
	public static function getPath(int $domain_srl): string
	{
		return \RX_BASEDIR . 'files/site_design/' . ($domain_srl > 0 ? 'design_d' . $domain_srl : 'design_0') . '.php';
	}

	/**
	 * Read a design file.
	 *
	 * @param int $domain_srl 0 for the default site
	 * @return object|null
	 */
	public static function read(int $domain_srl): ?object
	{
		$designInfo = null;
		$file = self::getPath($domain_srl);
		if (is_readable($file))
		{
			include $file;
		}
		return is_object($designInfo) ? $designInfo : null;
	}

	/**
	 * Check whether a site that is not the default one has its own design.
	 *
	 * @param int $domain_srl
	 * @return bool
	 */
	public static function hasOwn(int $domain_srl): bool
	{
		return $domain_srl > 0 && file_exists(self::getPath($domain_srl));
	}

	/**
	 * Write a design file in the same format as design_0.php.
	 *
	 * @param int $domain_srl 0 for the default site
	 * @param object $designInfo
	 * @return bool
	 */
	public static function write(int $domain_srl, object $designInfo): bool
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
		foreach ((array)($designInfo->module ?? []) as $module_name => $skin_info)
		{
			$buff[] = sprintf('$designInfo->module->{%s} = new stdClass;', var_export(strval($module_name), true));
			foreach ((array)$skin_info as $target => $skin_name)
			{
				$buff[] = sprintf('$designInfo->module->{%s}->{%s} = %s;', var_export(strval($module_name), true), var_export(strval($target), true), var_export(strval($skin_name), true));
			}
		}
		FileHandler::makeDir(\RX_BASEDIR . 'files/site_design/');
		return Storage::write(self::getPath($domain_srl), implode(\PHP_EOL, $buff));
	}

	/**
	 * Read the raw content of a design file, or null when there is none.
	 *
	 * @param int $domain_srl
	 * @return string|null
	 */
	public static function readRaw(int $domain_srl): ?string
	{
		$file = self::getPath($domain_srl);
		return file_exists($file) ? strval(file_get_contents($file)) : null;
	}

	/**
	 * Put back the raw content of a design file, or remove it.
	 *
	 * @param int $domain_srl
	 * @param string|null $raw
	 * @return void
	 */
	public static function restoreRaw(int $domain_srl, ?string $raw): void
	{
		$file = self::getPath($domain_srl);
		if ($raw === null)
		{
			if ($domain_srl > 0 && file_exists($file))
			{
				Storage::delete($file);
			}
			return;
		}
		Storage::write($file, $raw);
	}

	/**
	 * Get the design a site actually uses: its own file, or design_0.php.
	 *
	 * @param int $domain_srl
	 * @param bool $is_default
	 * @return object
	 */
	public static function getEffective(int $domain_srl, bool $is_default): object
	{
		$own = (!$is_default && self::hasOwn($domain_srl)) ? self::read($domain_srl) : null;
		$base = self::read(0) ?: new \stdClass;
		if (!$own)
		{
			return (object)['own' => false, 'info' => $base];
		}
		return (object)['own' => true, 'info' => $own, 'base' => $base];
	}

	/**
	 * Get the default skin of a module type on a site.
	 *
	 * @param object $effective result of getEffective()
	 * @param string $module_name
	 * @param string $type P or M
	 * @return string
	 */
	public static function getDefaultSkin(object $effective, string $module_name, string $type): string
	{
		$target = $type === 'M' ? 'mskin' : 'skin';
		if ($effective->own && !empty($effective->info->module->{$module_name}->{$target}))
		{
			return strval($effective->info->module->{$module_name}->{$target});
		}
		return strval(ModuleModel::getModuleDefaultSkin($module_name, $type, 0, false));
	}

	/**
	 * Describe the theme a layout instance belongs to.
	 *
	 * @param int $layout_srl
	 * @return object|null name, title
	 */
	public static function getThemeOfLayout(int $layout_srl): ?object
	{
		if ($layout_srl <= 0)
		{
			return null;
		}
		$raw = LayoutModel::getLayoutRawData($layout_srl, ['layout']);
		$parts = Theme::split(strval($raw->layout ?? ''));
		if (!$parts)
		{
			return null;
		}
		$info = Theme::getInfo($parts['theme']);
		if (!$info)
		{
			return null;
		}
		return (object)['name' => $parts['theme'], 'title' => html_entity_decode(strval($info->title), ENT_QUOTES, 'UTF-8')];
	}
}
