<?php

namespace Zittme\Modules\Admin\Models;

use Zittme\Framework\Storage;
use Zittme\Framework\Filters\FileContentFilter;

class Icon
{
	/**
	 * Get favicon URL for a domain.
	 *
	 * @param int $domain_srl
	 * @return string
	 */
	public static function getFaviconUrl(int $domain_srl = 0): string
	{
		return self::getIconUrl($domain_srl, 'favicon.ico');
	}

	/**
	 * Get dark mode favicon URL for a domain.
	 *
	 * @param int $domain_srl
	 * @return string
	 */
	public static function getDarkFaviconUrl(int $domain_srl = 0): string
	{
		return self::getIconUrl($domain_srl, 'favicon.dark.ico');
	}

	/**
	 * Get mobile icon URL for a domain.
	 *
	 * @param int $domain_srl
	 * @return string
	 */
	public static function getMobiconUrl(int $domain_srl = 0): string
	{
		return self::getIconUrl($domain_srl, 'mobicon.png');
	}

	/**
	 * Admin logo filenames. The extension is kept in a sidecar file because the
	 * upload may be PNG or SVG and we must not guess when building the URL.
	 */
	const ADMIN_LOGO_LIGHT = 'admin_logo';
	const ADMIN_LOGO_DARK = 'admin_logo.dark';

	/**
	 * Get the admin header logo URL.
	 *
	 * @param bool $dark Dark mode variant
	 * @return string Empty string when not set (caller falls back to the Zittme logo)
	 */
	public static function getAdminLogoUrl(bool $dark = false): string
	{
		$base = $dark ? self::ADMIN_LOGO_DARK : self::ADMIN_LOGO_LIGHT;
		foreach (['svg', 'png', 'jpg', 'gif', 'webp'] as $ext)
		{
			$filename = 'files/attach/xeicon/' . $base . '.' . $ext;
			if (Storage::exists(\RX_BASEDIR . $filename))
			{
				return \RX_BASEURL . $filename . '?t=' . filemtime(\RX_BASEDIR . $filename);
			}
		}
		return '';
	}

	/**
	 * Save the admin header logo.
	 *
	 * @param array $file_info
	 * @param bool $dark
	 * @return bool
	 */
	public static function saveAdminLogo(array $file_info, bool $dark = false): bool
	{
		if (empty($file_info['tmp_name']) || !is_uploaded_file($file_info['tmp_name']))
		{
			return false;
		}

		$ext = strtolower(pathinfo($file_info['name'] ?? '', PATHINFO_EXTENSION));
		if (!in_array($ext, ['svg', 'png', 'jpg', 'jpeg', 'gif', 'webp'], true))
		{
			return false;
		}
		if ($ext === 'jpeg')
		{
			$ext = 'jpg';
		}

		// Content check. Use the core filter rather than an ad-hoc regex: it also
		// catches entity-encoded script tags, ev:* handlers and external hrefs in SVG.
		// An uploaded SVG is served from our own origin, so a scripted one would be
		// stored XSS against the admin.
		if (!FileContentFilter::check($file_info['tmp_name'], 'logo.' . $ext))
		{
			return false;
		}

		// Raster formats must additionally decode as a real image.
		if ($ext !== 'svg' && !@getimagesize($file_info['tmp_name']))
		{
			return false;
		}

		self::deleteAdminLogo($dark);
		$base = $dark ? self::ADMIN_LOGO_DARK : self::ADMIN_LOGO_LIGHT;
		return Storage::move($file_info['tmp_name'], \RX_BASEDIR . 'files/attach/xeicon/' . $base . '.' . $ext);
	}

	/**
	 * Delete the admin header logo.
	 *
	 * @param bool $dark
	 * @return bool
	 */
	public static function deleteAdminLogo(bool $dark = false): bool
	{
		$base = $dark ? self::ADMIN_LOGO_DARK : self::ADMIN_LOGO_LIGHT;
		$deleted = false;
		foreach (['svg', 'png', 'jpg', 'gif', 'webp'] as $ext)
		{
			$filename = \RX_BASEDIR . 'files/attach/xeicon/' . $base . '.' . $ext;
			if (Storage::exists($filename))
			{
				Storage::delete($filename);
				$deleted = true;
			}
		}
		return $deleted;
	}

	/**
	 * Check if an icon file exists, and if so, return its URL.
	 *
	 * @param int $domain_srl
	 * @param string $icon_name
	 * @return string
	 */
	public static function getIconUrl(int $domain_srl, string $icon_name): string
	{
		$filename = 'files/attach/xeicon/' . ($domain_srl ? ($domain_srl . '/') : '') . $icon_name;
		if (Storage::exists(\RX_BASEDIR . $filename))
		{
			return \RX_BASEURL . $filename . '?t=' . filemtime(\RX_BASEDIR . $filename);
		}
		else
		{
			return '';
		}
	}

	/**
	 * Get the default image for a domain.
	 *
	 * @param int $domain_srl
	 * @param int &$width
	 * @param int &$height
	 * @return string
	 */
	public static function getDefaultImageUrl(int $domain_srl = 0, &$width = 0, &$height = 0): string
	{
		$dir = 'files/attach/xeicon/' . ($domain_srl ? ($domain_srl . '/') : '');
		$info = Storage::readPHPData(\RX_BASEDIR . $dir . 'default_image.php');
		if ($info && Storage::exists(\RX_BASEDIR . $info['filename']))
		{
			$width = $info['width'];
			$height = $info['height'];
			return \RX_BASEURL . $info['filename'] . '?t=' . filemtime(\RX_BASEDIR . $info['filename']);
		}
		else
		{
			return '';
		}
	}

	/**
	 * Save an icon for a domain.
	 *
	 * @param int $domain_srl
	 * @param string $icon_name
	 * @param array $fileinfo
	 * @return bool
	 */
	public static function saveIcon(int $domain_srl, string $icon_name, array $file_info): bool
	{
		$filename = 'files/attach/xeicon/' . ($domain_srl ? ($domain_srl . '/') : '') . $icon_name;
		if (file_exists($file_info['tmp_name']) && is_uploaded_file($file_info['tmp_name']))
		{
			return Storage::move($file_info['tmp_name'], \RX_BASEDIR . $filename);
		}
		else
		{
			return false;
		}
	}

	/**
	 * Delete an icon for a domain.
	 *
	 * @param int $domain_srl
	 * @param string $icon_name
	 * @return bool
	 */
	public static function deleteIcon(int $domain_srl, string $icon_name): bool
	{
		$filename = 'files/attach/xeicon/' . ($domain_srl ? ($domain_srl . '/') : '') . $icon_name;
		if (Storage::exists(\RX_BASEDIR . $filename))
		{
			return Storage::delete(\RX_BASEDIR . $filename);
		}
		else
		{
			return false;
		}
	}

	/**
	 * Save the default image for a domain.
	 *
	 * @param int $domain_srl
	 * @param array $file_info
	 * @return bool
	 */
	public static function saveDefaultImage(int $domain_srl, array $file_info): bool
	{
		$dir = 'files/attach/xeicon/' . ($domain_srl ? ($domain_srl . '/') : '');
		if (file_exists($file_info['tmp_name']) && is_uploaded_file($file_info['tmp_name']))
		{
			list($width, $height, $type) = @getimagesize($file_info['tmp_name']);
			switch ($type)
			{
				case 'image/gif': $target_filename = $dir . 'default_image.gif'; break;
				case 'image/jpeg': $target_filename = $dir . 'default_image.jpg'; break;
				case 'image/png': default: $target_filename = $dir . 'default_image.png';
			}
			if (Storage::move($file_info['tmp_name'], \RX_BASEDIR . $target_filename))
			{
				Storage::writePHPData(\RX_BASEDIR . $dir . 'default_image.php', [
					'filename' => $target_filename,
					'width' => $width,
					'height' => $height,
				]);
				return true;
			}
			else
			{
				return false;
			}
		}
		else
		{
			return false;
		}
	}

	/**
	 * Delete the default image for a domain.
	 *
	 * @param int $domain_srl
	 * @return bool
	 */
	public static function deleteDefaultImage(int $domain_srl): bool
	{
		$dir = 'files/attach/xeicon/' . ($domain_srl ? ($domain_srl . '/') : '');
		$info = Storage::readPHPData(\RX_BASEDIR . $dir . 'default_image.php');
		if ($info && $info['filename'])
		{
			Storage::delete(\RX_BASEDIR . $dir . 'default_image.php');
			Storage::delete(\RX_BASEDIR . $info['filename']);
			return true;
		}
		else
		{
			return false;
		}
	}}
