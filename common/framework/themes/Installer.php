<?php

namespace Zittme\Framework\Themes;

use Zittme\Framework\Storage;
use Zittme\Framework\Theme;
use Zittme\Framework\Parsers\ThemeInfoParser;

/**
 * Install and remove themes.
 *
 * A theme is extracted as a whole into ./themes/{name}/ rather than
 * scattered into original component paths, so it can never overwrite
 * unrelated skins and can be removed by deleting one directory.
 *
 * @see docs/THEME-PACKAGE.md
 */
class Installer
{
	/**
	 * Maximum size of a single file inside the archive.
	 */
	public const MAX_FILE_SIZE = 104857600; // 100MB

	/**
	 * Validate a theme zip without extracting anything.
	 * Extraction only happens after every check passes, so a failed
	 * install never leaves a half-extracted theme behind.
	 *
	 * @param string $zip_path
	 * @return object ok / message / info / prefix
	 */
	public static function inspect(string $zip_path): object
	{
		$result = new \stdClass;
		$result->ok = false;
		$result->message = '';
		$result->info = null;
		$result->prefix = '';

		$zip = new \ZipArchive;
		if ($zip->open($zip_path) !== true)
		{
			$result->message = 'msg_theme_invalid_zip';
			return $result;
		}

		// theme.xml may be at the top level or inside a single wrapper directory.
		$prefix = '';
		if (!$zip->statName('theme.xml'))
		{
			for ($i = 0; $i < $zip->numFiles; $i++)
			{
				$name = str_replace('\\', '/', $zip->getNameIndex($i));
				if (preg_match('!^([^/]+)/theme\.xml$!', $name, $m))
				{
					$prefix = $m[1] . '/';
					break;
				}
			}
		}
		if ($prefix === '' && !$zip->statName('theme.xml'))
		{
			$zip->close();
			$result->message = 'msg_theme_no_manifest';
			return $result;
		}

		$xml_content = $zip->getFromName($prefix . 'theme.xml');
		if ($xml_content === false)
		{
			$zip->close();
			$result->message = 'msg_theme_no_manifest';
			return $result;
		}

		$temp_xml = \RX_BASEDIR . 'files/cache/theme_manifest_' . md5($zip_path . microtime()) . '.xml';
		Storage::write($temp_xml, $xml_content);
		$info = ThemeInfoParser::loadXML($temp_xml, 'temp');
		Storage::delete($temp_xml);

		if (!$info)
		{
			$zip->close();
			$result->message = 'msg_theme_invalid_manifest';
			return $result;
		}
		if (!$info->supported)
		{
			$zip->close();
			$result->message = 'msg_theme_schema_too_new';
			return $result;
		}
		if (!count($info->components))
		{
			$zip->close();
			$result->message = 'msg_theme_no_component';
			return $result;
		}

		// Check that every declared component actually exists in the archive.
		$entries = [];
		for ($i = 0; $i < $zip->numFiles; $i++)
		{
			$stat = $zip->statIndex($i);
			$name = str_replace('\\', '/', $stat['name']);

			if (strpos($name, '../') !== false || strpos($name, '..\\') !== false)
			{
				$zip->close();
				$result->message = 'msg_theme_unsafe_path';
				return $result;
			}
			if ($stat['size'] > self::MAX_FILE_SIZE)
			{
				$zip->close();
				$result->message = 'msg_theme_file_too_large';
				return $result;
			}
			if ($prefix !== '' && strpos($name, $prefix) !== 0)
			{
				continue;
			}
			$entries[] = substr($name, strlen($prefix));
		}

		foreach ($info->components as $component)
		{
			$found = false;
			foreach ($entries as $entry)
			{
				if (strpos($entry, $component->path . '/') === 0)
				{
					$found = true;
					break;
				}
			}
			if (!$found)
			{
				$zip->close();
				$result->message = 'msg_theme_component_missing';
				return $result;
			}
		}

		$zip->close();

		$result->ok = true;
		$result->info = $info;
		$result->prefix = $prefix;
		return $result;
	}

	/**
	 * Install a theme.
	 *
	 * @param string $zip_path
	 * @param string $name
	 * @param bool $overwrite
	 * @return object ok / message / info
	 */
	public static function install(string $zip_path, string $name, bool $overwrite = false): object
	{
		$result = self::inspect($zip_path);
		if (!$result->ok)
		{
			return $result;
		}

		if (!Theme::isValidName($name))
		{
			$result->ok = false;
			$result->message = 'msg_theme_invalid_name';
			return $result;
		}

		$target = Theme::getPath($name);
		if (Storage::isDirectory($target) && !$overwrite)
		{
			$result->ok = false;
			$result->message = 'msg_theme_already_exists';
			return $result;
		}

		$zip = new \ZipArchive;
		if ($zip->open($zip_path) !== true)
		{
			$result->ok = false;
			$result->message = 'msg_theme_invalid_zip';
			return $result;
		}

		$prefix = $result->prefix;
		for ($i = 0; $i < $zip->numFiles; $i++)
		{
			$stat = $zip->statIndex($i);
			$entry = str_replace('\\', '/', $stat['name']);
			if ($prefix !== '' && strpos($entry, $prefix) !== 0)
			{
				continue;
			}

			$relative = substr($entry, strlen($prefix));
			if ($relative === '' || strpos($relative, '../') !== false)
			{
				continue;
			}

			$absolute = $target . $relative;
			if (substr($relative, -1) === '/')
			{
				Storage::createDirectory(rtrim($absolute, '/'));
				continue;
			}

			Storage::createDirectory(dirname($absolute));
			$content = $zip->getFromIndex($i);
			if ($content !== false)
			{
				Storage::write($absolute, $content);
			}
		}
		$zip->close();

		Theme::clearCache();

		$result->info = Theme::getInfo($name);
		return $result;
	}

	/**
	 * Remove a theme. Refuses if the theme is still in use somewhere,
	 * unless $force is set.
	 *
	 * @param string $name
	 * @param bool $force
	 * @return object ok / message / in_use
	 */
	public static function remove(string $name, bool $force = false): object
	{
		$result = new \stdClass;
		$result->ok = false;
		$result->message = '';
		$result->in_use = [];

		if (!Theme::isValidName($name) || !Storage::isDirectory(Theme::getPath($name)))
		{
			$result->message = 'msg_theme_not_found';
			return $result;
		}

		$result->in_use = Applier::findUsage($name);
		if (count($result->in_use) && !$force)
		{
			$result->message = 'msg_theme_in_use';
			return $result;
		}

		if (!Storage::deleteDirectory(Theme::getPath($name)))
		{
			$result->message = 'msg_theme_delete_failed';
			return $result;
		}

		Theme::clearCache();

		$result->ok = true;
		return $result;
	}
}
