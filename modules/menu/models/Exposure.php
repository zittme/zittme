<?php

namespace Zittme\Modules\Menu\Models;

use ModuleModel;
use Zittme\Framework\Config;

/**
 * Search exposure of a menu (module) and of a whole sitemap.
 *
 * Values of a module are kept in module_extra_vars:
 *  - robots_tag: existing value of the board and page settings ('noindex' or 'all')
 *  - seo_sitemap: 'N' leaves the address out of sitemap.xml, 'Y' keeps it
 *  - seo_structured: 'N' leaves out the page's structured data (JSON-LD)
 *  - seo_own: 'Y' when the values above were set for this menu on its own
 * A whole sitemap is turned off in the menu module settings (seo_off).
 *
 * Without any of the new values, every result is the same as before:
 * only robots_tag 'noindex' hides a page from search engines and sitemap.xml.
 */
class Exposure
{
	/**
	 * Module settings keys.
	 */
	const KEYS = ['robots_tag', 'seo_sitemap', 'seo_structured', 'seo_own'];

	/**
	 * Remove the cached sitemap.xml entries so that changes show at once.
	 *
	 * Does nothing while sitemap.xml is off, unless forced.
	 *
	 * @param bool $documents_only Only act when documents are listed in sitemap.xml
	 * @param bool $force Clear the cache even if sitemap.xml is off
	 * @return void
	 */
	public static function flushSitemapCache(bool $documents_only = false, bool $force = false): void
	{
		if (!$force && !Config::get('seo.sitemap'))
		{
			return;
		}
		if ($documents_only && intval(Config::get('seo.sitemap_documents')) <= 0)
		{
			return;
		}
		foreach (glob(\RX_BASEDIR . 'files/cache/sitemap/urls*.php') ?: [] as $file)
		{
			@unlink($file);
		}
	}

	/**
	 * Get the sitemaps whose exposure is turned off, as {menu_srl: true}.
	 *
	 * @return array
	 */
	public static function getSitemapsOff(): array
	{
		$config = ModuleModel::getModuleConfig('menu');
		$result = [];
		foreach ((array)($config->seo_off ?? []) as $menu_srl => $off)
		{
			if (intval($menu_srl) > 0 && $off)
			{
				$result[intval($menu_srl)] = true;
			}
		}
		return $result;
	}

	/**
	 * Check whether a sitemap is turned off.
	 *
	 * @param int $menu_srl
	 * @return bool
	 */
	public static function isSitemapOff(int $menu_srl): bool
	{
		if ($menu_srl <= 0)
		{
			return false;
		}
		return isset(self::getSitemapsOff()[$menu_srl]);
	}

	/**
	 * Check whether the module follows the exposure of its sitemap.
	 *
	 * @param object $module_info
	 * @param int $menu_srl
	 * @return bool
	 */
	protected static function _inheritsOff($module_info, int $menu_srl): bool
	{
		if (($module_info->seo_own ?? '') === 'Y')
		{
			return false;
		}
		if ($menu_srl <= 0)
		{
			$menu_srl = intval($module_info->menu_srl ?? 0);
		}
		return self::isSitemapOff($menu_srl);
	}

	/**
	 * Check whether search engines are told not to index the page.
	 *
	 * @param object $module_info
	 * @param int $menu_srl
	 * @return bool
	 */
	public static function isNoindex($module_info, int $menu_srl = 0): bool
	{
		if (!is_object($module_info))
		{
			return false;
		}
		if (($module_info->robots_tag ?? '') === 'noindex')
		{
			return true;
		}
		return self::_inheritsOff($module_info, $menu_srl);
	}

	/**
	 * Check whether a visitor who is not logged in can open the module.
	 *
	 * Modules whose access, list or view is limited to members or groups must not
	 * reach crawlers through sitemap.xml, llms.txt or search engine notices.
	 *
	 * @param object $module_info
	 * @return bool
	 */
	public static function isOpenToGuests($module_info): bool
	{
		if (!is_object($module_info) || empty($module_info->module))
		{
			return true;
		}
		if (($module_info->consultation ?? '') === 'Y')
		{
			return false;
		}
		if (!empty($module_info->mid) && class_exists('Zittme\Modules\Member\Identity\Base'))
		{
			try
			{
				$identity = \Zittme\Modules\Member\Identity\Base::getConfig();
				if (($identity->identity_enable ?? 'N') === 'Y' && in_array(strval($module_info->mid), (array)($identity->identity_adult_mids ?? []), true))
				{
					return false;
				}
			}
			catch (\Throwable $e)
			{
				return false;
			}
		}
		try
		{
			$guest = new \stdClass;
			$guest->member_srl = 0;
			$guest->is_admin = 'N';
			$guest->group_list = array();
			$grant = ModuleModel::getGrant($module_info, $guest);
		}
		catch (\Throwable $e)
		{
			return false;
		}
		if (isset($grant->access) && !$grant->access)
		{
			return false;
		}
		foreach (array('list', 'view') as $key)
		{
			if (isset($grant->{$key}) && $grant->{$key} === false)
			{
				return false;
			}
		}
		return true;
	}

	/**
	 * Check whether the address is left out of sitemap.xml and llms.txt.
	 *
	 * @param object $module_info
	 * @param int $menu_srl
	 * @return bool
	 */
	public static function isOutOfSitemap($module_info, int $menu_srl = 0): bool
	{
		if (is_object($module_info) && !self::isOpenToGuests($module_info))
		{
			return true;
		}
		if (!is_object($module_info))
		{
			return false;
		}
		$value = strval($module_info->seo_sitemap ?? '');
		if ($value === 'N')
		{
			return true;
		}
		if ($value === 'Y')
		{
			return false;
		}
		if (($module_info->robots_tag ?? '') === 'noindex')
		{
			return true;
		}
		return self::_inheritsOff($module_info, $menu_srl);
	}

	/**
	 * Check whether the structured data of the page is left out.
	 *
	 * @param object $module_info
	 * @param int $menu_srl
	 * @return bool
	 */
	public static function isStructuredOff($module_info, int $menu_srl = 0): bool
	{
		if (!is_object($module_info))
		{
			return false;
		}
		$value = strval($module_info->seo_structured ?? '');
		if ($value === 'N')
		{
			return true;
		}
		if ($value === 'Y')
		{
			return false;
		}
		return self::_inheritsOff($module_info, $menu_srl);
	}
}
