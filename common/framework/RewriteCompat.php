<?php

namespace Zittme\Framework;

class RewriteCompat
{
	public const LEGACY_DIRS = ['addons', 'files', 'layouts', 'm.layouts', 'modules', 'widgets', 'widgetstyles'];

	public const STATIC_EXTENSIONS = [
		'css', 'js', 'map', 'json', 'txt',
		'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp',
		'woff', 'woff2', 'ttf', 'otf', 'eot',
		'mp4', 'webm', 'ogg', 'mp3', 'wav', 'pdf', 'zip',
	];

	public const PROTECTED_PREFIXES = ['files/config/', 'files/env/', 'files/member_extra_info/', 'files/cache/template/', 'files/sessions/', 'files/tmp/'];

	public const PROBE_PATH = 'files/cache/tmpRewriteCheck.txt';

	public static function handle(): void
	{
		if (\PHP_SAPI === 'cli' || empty($_SERVER['REQUEST_URI']))
		{
			return;
		}
		$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
		if ($method !== 'GET' && $method !== 'HEAD')
		{
			return;
		}

		$action = self::resolve((string)$_SERVER['REQUEST_URI'], \RX_BASEURL, \RX_BASEDIR);
		if ($action === null)
		{
			return;
		}

		if ($action['type'] === 'probe')
		{
			header('Content-Type: text/plain; charset=UTF-8');
			header('Cache-Control: no-store');
			readfile($action['file']);
			exit;
		}

		if ($action['type'] === 'redirect')
		{
			$query = (string)parse_url((string)$_SERVER['REQUEST_URI'], \PHP_URL_QUERY);
			header('Cache-Control: no-store');
			header('Location: ' . $action['location'] . ($query !== '' ? '?' . $query : ''), true, 301);
			exit;
		}
	}

	public static function resolve(string $request_uri, string $baseurl, string $basedir): ?array
	{
		$path = (string)parse_url($request_uri, \PHP_URL_PATH);
		if ($path === '')
		{
			return null;
		}
		$baseurl = rtrim($baseurl, '/') . '/';
		if (strncmp($path, $baseurl, strlen($baseurl)) !== 0)
		{
			return null;
		}
		$rel = rawurldecode(substr($path, strlen($baseurl)));

		if ($rel === '' || preg_match('/[\x00-\x1f\x7f]/', $rel) || strpos($rel, '\\') !== false || preg_match('#(^|/)\.\.(/|$)#', $rel))
		{
			return null;
		}

		if (preg_match('#(?:^|/)REWRITE/CHECK/SRSLY/ANYTHING/GOES/' . preg_quote(self::PROBE_PATH, '#') . '$#', $rel))
		{
			$file = $basedir . self::PROBE_PATH;
			return is_file($file) ? ['type' => 'probe', 'file' => $file] : null;
		}

		$dirs = implode('|', array_map(function ($d) { return preg_quote($d, '#'); }, self::LEGACY_DIRS));
		if (preg_match('#^.+?/((?:' . $dirs . ')/.+)$#', $rel, $m))
		{
			$target = $m[1];
			if (self::isServableStatic($target, $basedir))
			{
				return ['type' => 'redirect', 'location' => $baseurl . self::encodePath($target)];
			}
			return null;
		}

		if (preg_match('#^(.+)\.min\.(css|js)$#', $rel, $m) && !is_file($basedir . $rel))
		{
			$target = $m[1] . '.' . $m[2];
			if (self::isServableStatic($target, $basedir))
			{
				return ['type' => 'redirect', 'location' => $baseurl . self::encodePath($target)];
			}
		}

		return null;
	}

	protected static function isServableStatic(string $rel, string $basedir): bool
	{
		foreach (self::PROTECTED_PREFIXES as $prefix)
		{
			if (strncmp($rel, $prefix, strlen($prefix)) === 0)
			{
				return false;
			}
		}
		$ext = strtolower((string)pathinfo($rel, \PATHINFO_EXTENSION));
		if ($ext === '' || !in_array($ext, self::STATIC_EXTENSIONS, true))
		{
			return false;
		}
		$full = $basedir . $rel;
		if (!is_file($full))
		{
			return false;
		}
		$real = realpath($full);
		$root = realpath($basedir);
		if ($real === false || $root === false)
		{
			return false;
		}
		$root = rtrim(str_replace('\\', '/', $root), '/') . '/';
		$real = str_replace('\\', '/', $real);
		return strncmp($real, $root, strlen($root)) === 0;
	}

	protected static function encodePath(string $rel): string
	{
		return implode('/', array_map('rawurlencode', explode('/', $rel)));
	}
}
