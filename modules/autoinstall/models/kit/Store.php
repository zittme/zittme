<?php

namespace Zittme\Modules\Autoinstall\Models\Kit;

use Zittme\Framework\Cache;
use Zittme\Framework\HTTP;
use Zittme\Framework\Storage;
use Zittme\Modules\Autoinstall\Models\Package;

/**
 * Store API access for starter kits and the packages they need.
 */
class Store
{
	public static function getSiteUrl(): string
	{
		$url = config('autoinstall.store_site');
		return is_string($url) && preg_match('!^https://[a-z0-9.\-]+(:\d+)?/?$!i', $url) ? rtrim($url, '/') : '';
	}

	public static function listUrl(): string
	{
		$site = self::getSiteUrl();
		if ($site)
		{
			return $site . '/index.php?module=store&act=dispStoreApiIndex&type=kit';
		}
		return Package::PACKAGE_LIST_URL . '?type=kit';
	}

	public static function detailUrl(int $package_srl): string
	{
		$site = self::getSiteUrl();
		if ($site)
		{
			return $site . '/index.php?module=store&act=dispStoreApiDetail&package_srl=' . $package_srl;
		}
		return sprintf(Package::PACKAGE_DETAIL_URL, $package_srl);
	}

	public static function packageListUrl(): string
	{
		$site = self::getSiteUrl();
		return $site ? $site . '/index.php?module=store&act=dispStoreApiIndex' : Package::PACKAGE_LIST_URL;
	}

	protected static function sanitizeLinks(object $item): object
	{
		foreach (['demo_url', 'store_url', 'enter_url'] as $key)
		{
			if (isset($item->{$key}))
			{
				$item->{$key} = Package::safeLink($item->{$key});
			}
		}
		return $item;
	}

	protected static function getJson(string $url, int $timeout = 20, bool $with_account = false)
	{
		$request = HTTP::get($url, null, Package::getApiHeaders($with_account), [], ['timeout' => $timeout]);
		if ($request->getStatusCode() !== 200)
		{
			return null;
		}
		$data = json_decode($request->getBody()->getContents());
		return is_object($data) ? $data : null;
	}

	/**
	 * @return object[]
	 */
	public static function getKits(bool $refresh = false): array
	{
		$cache_key = 'autoinstall:kit_list:' . md5(self::listUrl());
		if (!$refresh)
		{
			$cached = Cache::get($cache_key);
			if (is_array($cached))
			{
				return $cached;
			}
		}
		$data = self::getJson(self::listUrl());
		$kits = [];
		foreach ((array)($data->packages ?? []) as $item)
		{
			if (is_object($item) && ($item->type ?? '') === 'kit')
			{
				$kits[] = self::sanitizeLinks($item);
			}
		}
		Cache::set($cache_key, $kits, 600, true);
		return $kits;
	}

	public static function getDetail(int $package_srl, bool $refresh = false): ?Package
	{
		if ($package_srl <= 0)
		{
			return null;
		}
		$cache_key = 'autoinstall:kit_detail:' . md5(self::detailUrl($package_srl));
		if (!$refresh)
		{
			$cached = Cache::get($cache_key);
			if ($cached instanceof Package)
			{
				return $cached;
			}
		}
		$data = self::getJson(self::detailUrl($package_srl), 20, true);
		if (!$data || !isset($data->package_srl))
		{
			return null;
		}
		$package = new Package;
		$package->extra_vars = new \stdClass;
		foreach ($data as $key => $val)
		{
			if (property_exists($package, $key))
			{
				$package->{$key} = $val;
			}
			else
			{
				$package->extra_vars->{$key} = $val;
			}
		}
		self::sanitizeLinks($package->extra_vars);
		$ttl = strpos((string)$package->install_type, 'paid') === 0 ? 30 : 600;
		Cache::set($cache_key, $package, $ttl, true);
		return $package;
	}

	/**
	 * Find a package by install path in the store list (used when the kit's package_srl belongs to another store).
	 */
	public static function findByInstallPath(string $install_path): ?Package
	{
		$cache_key = 'autoinstall:kit_pkg_index:' . md5(self::packageListUrl());
		$index = Cache::get($cache_key);
		if (!is_array($index))
		{
			$index = [];
			$data = self::getJson(self::packageListUrl());
			foreach ((array)($data->packages ?? []) as $item)
			{
				if (isset($item->install_path, $item->package_srl))
				{
					$index[(string)$item->install_path] = (int)$item->package_srl;
				}
			}
			Cache::set($cache_key, $index, 600, true);
		}
		$srl = $index[$install_path] ?? 0;
		$package = $srl ? self::getDetail($srl, true) : null;
		return ($package && (string)$package->install_path === $install_path) ? $package : null;
	}

	/**
	 * Resolve a kit package requirement to a store package.
	 */
	public static function resolvePackage(object $requirement): ?Package
	{
		$srl = (int)($requirement->package_srl ?? 0);
		if ($srl)
		{
			$package = self::getDetail($srl);
			if ($package && $package->install_path === $requirement->install_path && $package->type === $requirement->type)
			{
				return $package;
			}
		}
		$package = self::findByInstallPath((string)$requirement->install_path);
		return ($package && $package->type === $requirement->type) ? $package : null;
	}

	/**
	 * Download a kit ZIP and check its transport hash.
	 */
	public static function downloadKit(Package $package, string $dest): string
	{
		$url = (string)($package->extra_vars->install_url ?? '');
		if (!preg_match('!^https://!i', $url))
		{
			return 'msg_autoinstall_package_has_no_download_url';
		}
		Storage::createDirectory(dirname($dest));
		if (Storage::exists($dest))
		{
			Storage::delete($dest);
		}
		$request = Package::downloadFile($url, $dest, 60);
		if ($request->getStatusCode() !== 200 || !Storage::exists($dest) || filesize($dest) === 0)
		{
			Storage::delete($dest);
			return ($request->getStatusCode() === 403) ? 'msg_kit_license_required' : 'msg_autoinstall_download_failed';
		}
		$expected = strtolower((string)($package->extra_vars->file_sha256 ?? ''));
		if (!preg_match('/^[0-9a-f]{64}$/', $expected) || !hash_equals($expected, hash_file('sha256', $dest)))
		{
			Storage::delete($dest);
			return 'msg_kit_hash_mismatch';
		}
		return '';
	}
}
