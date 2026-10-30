<?php

namespace Zittme\Modules\Autoinstall\Models\Kit;

use Zittme\Framework\Storage;

/**
 * Reads and validates a starter kit ZIP.
 */
class Archive
{
	public const ALLOWED_EXTENSIONS = ['json', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'mp4', 'webm', 'ico', 'txt'];
	public const MAX_ENTRY_SIZE = 20 * 1024 * 1024;
	public const MAX_TOTAL_SIZE = 300 * 1024 * 1024;
	public const MAX_ENTRIES = 5000;
	public const SIGNATURE_FILE = 'signature.json';
	public const MANIFEST_FILE = 'kit.json';

	/**
	 * Validate a single entry name. Returns the normalized name or null when rejected.
	 */
	public static function normalizeEntryName(string $name): ?string
	{
		if ($name === '' || strpos($name, "\0") !== false || preg_match('/[\x00-\x1f\x7f]/', $name))
		{
			return null;
		}
		if (strpos($name, '\\') !== false || preg_match('!^[a-zA-Z]:!', $name) || $name[0] === '/')
		{
			return null;
		}
		$is_dir = substr($name, -1) === '/';
		$parts = explode('/', rtrim($name, '/'));
		foreach ($parts as $part)
		{
			if ($part === '' || $part === '.' || $part === '..' || $part[0] === '.')
			{
				return null;
			}
			if (!preg_match('/^[\w\-. ()\x{AC00}-\x{D7A3}]+$/u', $part))
			{
				return null;
			}
		}
		return implode('/', $parts) . ($is_dir ? '/' : '');
	}

	public static function isAllowedFile(string $name): bool
	{
		$base = strtolower(basename($name));
		if (preg_match('/\.(php\d?|phtml|phar|pht|phps|inc|htaccess|htpasswd|user\.ini|cgi|pl|py|sh|exe|svg|svgz|html?|xhtml|js|mjs)(\.|$)/', $base))
		{
			return false;
		}
		$ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
		return in_array($ext, self::ALLOWED_EXTENSIONS, true);
	}

	/**
	 * Inspect a ZIP. Returns ['ok' => bool, 'error' => string, 'entries' => [name => size], 'manifest' => ?object, 'signature' => ?object].
	 */
	public static function inspect(string $zip_path): array
	{
		$result = ['ok' => false, 'error' => '', 'entries' => [], 'manifest' => null, 'manifest_raw' => '', 'signature' => null];
		if (!class_exists('ZipArchive'))
		{
			$result['error'] = 'msg_kit_zip_unsupported';
			return $result;
		}
		$zip = new \ZipArchive;
		if ($zip->open($zip_path) !== true)
		{
			$result['error'] = 'msg_kit_zip_invalid';
			return $result;
		}
		if ($zip->numFiles > self::MAX_ENTRIES)
		{
			$zip->close();
			$result['error'] = 'msg_kit_zip_too_many';
			return $result;
		}

		$total = 0;
		for ($i = 0; $i < $zip->numFiles; $i++)
		{
			$stat = $zip->statIndex($i);
			$raw = (string)($stat['name'] ?? '');
			$name = self::normalizeEntryName($raw);
			if ($name === null || $name !== $raw)
			{
				$zip->close();
				$result['error'] = 'msg_kit_zip_bad_path';
				$result['bad_entry'] = $raw;
				return $result;
			}
			if (substr($name, -1) === '/')
			{
				continue;
			}
			if (!self::isAllowedFile($name))
			{
				$zip->close();
				$result['error'] = 'msg_kit_zip_bad_file';
				$result['bad_entry'] = $name;
				return $result;
			}
			if (!self::isAllowedLocation($name))
			{
				$zip->close();
				$result['error'] = 'msg_kit_zip_bad_path';
				$result['bad_entry'] = $name;
				return $result;
			}
			$size = (int)$stat['size'];
			if ($size > self::MAX_ENTRY_SIZE)
			{
				$zip->close();
				$result['error'] = 'msg_kit_zip_too_large';
				$result['bad_entry'] = $name;
				return $result;
			}
			$total += $size;
			if ($total > self::MAX_TOTAL_SIZE)
			{
				$zip->close();
				$result['error'] = 'msg_kit_zip_too_large';
				return $result;
			}
			if (isset($result['entries'][$name]))
			{
				$zip->close();
				$result['error'] = 'msg_kit_zip_bad_path';
				$result['bad_entry'] = $name;
				return $result;
			}
			$result['entries'][$name] = $size;
		}

		$raw_manifest = self::readLimited($zip, self::MANIFEST_FILE, (int)($result['entries'][self::MANIFEST_FILE] ?? 0));
		if ($raw_manifest === false)
		{
			$zip->close();
			$result['error'] = 'msg_kit_no_manifest';
			return $result;
		}
		$manifest = json_decode($raw_manifest);
		if (!is_object($manifest))
		{
			$zip->close();
			$result['error'] = 'msg_kit_manifest_invalid';
			return $result;
		}
		$raw_signature = self::readLimited($zip, self::SIGNATURE_FILE, (int)($result['entries'][self::SIGNATURE_FILE] ?? 0));
		if ($raw_signature !== false)
		{
			$signature = json_decode($raw_signature);
			$result['signature'] = is_object($signature) ? $signature : null;
		}

		$files = (array)($manifest->integrity->files ?? []);
		foreach ($result['entries'] as $name => $size)
		{
			if ($name === self::MANIFEST_FILE || $name === self::SIGNATURE_FILE)
			{
				continue;
			}
			$expected = strtolower((string)($files[$name] ?? ''));
			if (!preg_match('/^[0-9a-f]{64}$/', $expected))
			{
				$zip->close();
				$result['error'] = 'msg_kit_integrity_failed';
				$result['bad_entry'] = $name;
				return $result;
			}
			$ctx = hash_init('sha256');
			$stream = $zip->getStream($name);
			if (!$stream)
			{
				$zip->close();
				$result['error'] = 'msg_kit_zip_invalid';
				return $result;
			}
			$read = 0;
			while (!feof($stream))
			{
				$chunk = (string)fread($stream, 65536);
				$read += strlen($chunk);
				if ($read > $size)
				{
					fclose($stream);
					$zip->close();
					$result['error'] = 'msg_kit_zip_too_large';
					$result['bad_entry'] = $name;
					return $result;
				}
				hash_update($ctx, $chunk);
			}
			fclose($stream);
			if (!hash_equals($expected, hash_final($ctx)))
			{
				$zip->close();
				$result['error'] = 'msg_kit_integrity_failed';
				$result['bad_entry'] = $name;
				return $result;
			}
		}
		foreach (array_keys($files) as $listed)
		{
			if (!isset($result['entries'][$listed]))
			{
				$zip->close();
				$result['error'] = 'msg_kit_integrity_failed';
				$result['bad_entry'] = $listed;
				return $result;
			}
		}

		$zip->close();
		$result['ok'] = true;
		$result['manifest'] = $manifest;
		$result['manifest_raw'] = $raw_manifest;
		return $result;
	}

	/**
	 * Read one entry, stopping when it turns out larger than the size its header announced.
	 *
	 * @return string|false
	 */
	protected static function readLimited(\ZipArchive $zip, string $name, int $size)
	{
		$stream = $zip->getStream($name);
		if (!$stream)
		{
			return false;
		}
		$data = stream_get_contents($stream, $size + 1);
		fclose($stream);
		return ($data === false || strlen($data) > $size) ? false : $data;
	}

	public static function isAllowedLocation(string $name): bool
	{
		if ($name === self::MANIFEST_FILE || $name === self::SIGNATURE_FILE)
		{
			return true;
		}
		if (preg_match('!^data/[a-z0-9_\-]+\.json$!', $name))
		{
			return true;
		}
		if (strpos($name, 'media/') === 0 && strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'json')
		{
			return true;
		}
		return false;
	}

	/**
	 * Extract a validated ZIP into a directory. Every target path is re-checked against the base.
	 */
	public static function extract(string $zip_path, string $target_dir, array $entries): bool
	{
		$target_dir = rtrim($target_dir, '/') . '/';
		if (!Storage::isDirectory($target_dir) && !Storage::createDirectory($target_dir))
		{
			return false;
		}
		$base = realpath($target_dir);
		if ($base === false)
		{
			return false;
		}
		$base = rtrim(str_replace('\\', '/', $base), '/') . '/';

		$zip = new \ZipArchive;
		if ($zip->open($zip_path) !== true)
		{
			return false;
		}
		foreach ($entries as $name => $size)
		{
			if (self::normalizeEntryName($name) !== $name || !self::isAllowedFile($name) || !self::isAllowedLocation($name))
			{
				$zip->close();
				return false;
			}
			$dest = $base . $name;
			$dir = dirname($dest);
			if (!Storage::isDirectory($dir) && !Storage::createDirectory($dir))
			{
				$zip->close();
				return false;
			}
			$real_dir = realpath($dir);
			if ($real_dir === false || strpos(rtrim(str_replace('\\', '/', $real_dir), '/') . '/', $base) !== 0)
			{
				$zip->close();
				return false;
			}
			$stream = $zip->getStream($name);
			$fp = $stream ? fopen($dest, 'wb') : false;
			if (!$stream || !$fp)
			{
				$zip->close();
				return false;
			}
			$limit = min(self::MAX_ENTRY_SIZE, (int)$size);
			$written = stream_copy_to_stream($stream, $fp, $limit + 1);
			fclose($stream);
			fclose($fp);
			if ($written > $limit)
			{
				Storage::delete($dest);
				$zip->close();
				return false;
			}
		}
		$zip->close();
		return true;
	}
}
