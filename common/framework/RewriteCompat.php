<?php

namespace Zittme\Framework;

/**
 * Compatibility layer for web servers that only have the standard
 * "try_files $uri $uri/ /index.php?$query_string" rule and none of the
 * XE-style rewrite rules shipped in .htaccess / zittme-nginx.conf.
 *
 * On such servers three kinds of requests fall through to index.php that the
 * XE-style rules used to answer at the web server level:
 *
 *  1. The installer's rewrite probe (REWRITE/CHECK/.../files/cache/tmpRewriteCheck.txt).
 *     We answer it with the probe file, so "short URLs" can be enabled during
 *     installation instead of only afterwards.
 *  2. Legacy relative asset paths such as /board/files/attach/x.jpg, produced by
 *     old content that references assets without a leading slash.
 *  3. Minified asset URLs (*.min.css / *.min.js) for which only the plain file exists.
 *
 * Cases 2 and 3 are answered with a 301 redirect to the canonical path, never by
 * streaming the file through PHP, so the web server keeps serving static files
 * (and keeps enforcing its own deny rules) exactly as before.
 *
 * Servers that do have the XE-style rules never reach this code for those
 * requests, so their behaviour is unchanged. Everything else falls through to
 * the normal request flow.
 */
class RewriteCompat
{
	/**
	 * Directories that legacy relative paths may point into.
	 */
	public const LEGACY_DIRS = ['addons', 'files', 'layouts', 'm.layouts', 'modules', 'widgets', 'widgetstyles'];

	/**
	 * Static file extensions we are willing to redirect to.
	 */
	public const STATIC_EXTENSIONS = [
		'css', 'js', 'map', 'json', 'txt',
		'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp',
		'woff', 'woff2', 'ttf', 'otf', 'eot',
		'mp4', 'webm', 'ogg', 'mp3', 'wav', 'pdf', 'zip',
	];

	/**
	 * Paths under files/ that must never be exposed, even if an odd file with a
	 * static-looking extension exists there.
	 */
	public const PROTECTED_PREFIXES = ['files/config/', 'files/env/', 'files/member_extra_info/', 'files/cache/template/', 'files/sessions/', 'files/tmp/'];

	public const PROBE_PATH = 'files/cache/tmpRewriteCheck.txt';

	/**
	 * Entry point called from index.php before Context::init().
	 * Performs at most one side effect (serve the probe or redirect) and exits;
	 * otherwise returns and lets the request continue.
	 */
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

	/**
	 * Decide what to do with a request. Pure function, no side effects, so it
	 * can be unit-tested from the command line.
	 *
	 * @param string $request_uri  Raw REQUEST_URI
	 * @param string $baseurl      RX_BASEURL ('/' or '/sub/')
	 * @param string $basedir      RX_BASEDIR (with trailing slash)
	 * @return array|null  ['type' => 'probe', 'file' => ...] | ['type' => 'redirect', 'location' => ...] | null
	 */
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

		// Reject anything that could escape the site root or smuggle header characters.
		if ($rel === '' || preg_match('/[\x00-\x1f\x7f]/', $rel) || strpos($rel, '\\') !== false || preg_match('#(^|/)\.\.(/|$)#', $rel))
		{
			return null;
		}

		// 1. Installer rewrite probe. Only while the probe file exists.
		if (preg_match('#(?:^|/)REWRITE/CHECK/SRSLY/ANYTHING/GOES/' . preg_quote(self::PROBE_PATH, '#') . '$#', $rel))
		{
			$file = $basedir . self::PROBE_PATH;
			return is_file($file) ? ['type' => 'probe', 'file' => $file] : null;
		}

		// 2. Legacy relative asset path: <anything>/<legacy dir>/<file>
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

		// 3. Missing *.min.css / *.min.js with the plain file present.
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

	/**
	 * A relative path is servable if it is an existing regular file inside the
	 * site root, has a static extension, and is not under a protected prefix.
	 */
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

	/**
	 * Percent-encode each path segment so the Location header is always a valid URL.
	 */
	protected static function encodePath(string $rel): string
	{
		return implode('/', array_map('rawurlencode', explode('/', $rel)));
	}
}
