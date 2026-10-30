<?php

namespace Zittme\Framework;

/**
 * The HTTP class for making requests to external resources.
 */
class HTTP
{
	/**
	 * The default timeout for requests.
	 */
	public const DEFAULT_TIMEOUT = 3;

	/**
	 * Cache the Guzzle client instance here.
	 */
	protected static $_client = null;

	/**
	 * Reset the Guzzle client instance.
	 */
	public static function resetClient(): void
	{
		self::$_client = null;
	}

	/**
	 * Make a GET request.
	 *
	 * @param string $url
	 * @param string|array $data
	 * @param array $headers
	 * @param array $cookies
	 * @param array $settings
	 * @return object
	 */
	public static function get(string $url, $data = null, array $headers = [], array $cookies = [], array $settings = []): object
	{
		return self::request($url, 'GET', $data, $headers, $cookies, $settings);
	}

	/**
	 * Make a HEAD request.
	 *
	 * @param string $url
	 * @param string|array $data
	 * @param array $headers
	 * @param array $cookies
	 * @param array $settings
	 * @return \Psr\Http\Message\ResponseInterface
	 */
	public static function head(string $url, $data = null, array $headers = [], array $cookies = [], array $settings = []): \Psr\Http\Message\ResponseInterface
	{
		return self::request($url, 'HEAD', $data, $headers, $cookies, $settings);
	}

	/**
	 * Make a POST request.
	 *
	 * @param string $url
	 * @param string|array $data
	 * @param array $headers
	 * @param array $cookies
	 * @param array $settings
	 * @return \Psr\Http\Message\ResponseInterface
	 */
	public static function post(string $url, $data = null, array $headers = [], array $cookies = [], array $settings = []): \Psr\Http\Message\ResponseInterface
	{
		return self::request($url, 'POST', $data, $headers, $cookies, $settings);
	}

	/**
	 * Download a file.
	 *
	 * This helps save memory when downloading large files,
	 * by streaming the response body directly to the filesystem
	 * instead of buffering it in memory.
	 *
	 * @param string $url
	 * @param string $target_filename
	 * @param string|array $data
	 * @param array $headers
	 * @param array $cookies
	 * @param array $settings
	 * @return \Psr\Http\Message\ResponseInterface
	 */
	public static function download(string $url, string $target_filename, string $method = 'GET', $data = null, array $headers = [], array $cookies = [], array $settings = []): \Psr\Http\Message\ResponseInterface
	{
		// Try to create the parent directory to save the file.
		$target_dir = dirname($target_filename);
		if (!Storage::isDirectory($target_dir) && !Storage::createDirectory($target_dir))
		{
			return new Helpers\HTTPHelper(new Exception('Cannot create directory: ' . $target_dir));
		}
		if (!Storage::isWritable($target_dir) && (!Storage::exists($target_filename) || !Storage::isWritable($target_filename)))
		{
			return new Helpers\HTTPHelper(new Exception('No permission to write file: ' . $target_filename));
		}

		// Pass to request() with appropriate settings for the filename.
		$settings['sink'] = $target_filename;
		return self::request($url, $method, $data, $headers, $cookies, $settings);
	}

	/**
	 * Make any type of request.
	 *
	 * @param string $url
	 * @param string $method
	 * @param string|array $data
	 * @param array $headers
	 * @param array $cookies
	 * @param array $settings
	 * @return \Psr\Http\Message\ResponseInterface
	 */
	public static function request(string $url, string $method = 'GET', $data = null, array $headers = [], array $cookies = [], array $settings = []): \Psr\Http\Message\ResponseInterface
	{
		// Create a new Guzzle client, or reuse a cached one if available.
		$guzzle = self::_createClient();

		// Populate settings.
		$block_private = !empty($settings['block_private']);
		$settings = self::_populateSettings($url, $method, '', $data, $headers, $cookies, $settings);

		// Send the request.
		try
		{
			if ($block_private)
			{
				return self::_requestPublicOnly($guzzle, $url, $method, $settings);
			}
			$response = $guzzle->request($method, $url, $settings);
		}
		catch (\Throwable $e)
		{
			$response = new Helpers\HTTPHelper($e);
		}

		return $response;
	}

	/**
	 * Send a request only to public addresses (the 'block_private' setting).
	 *
	 * Every hop is resolved and checked here, and the connection is pinned to the
	 * address that was checked. Redirects are followed manually so that each
	 * destination goes through the same check.
	 *
	 * @param \GuzzleHttp\Client $guzzle
	 * @param string $url
	 * @param string $method
	 * @param array $settings
	 * @return \Psr\Http\Message\ResponseInterface
	 */
	protected static function _requestPublicOnly(\GuzzleHttp\Client $guzzle, string $url, string $method, array $settings): \Psr\Http\Message\ResponseInterface
	{
		$max_redirects = 3;
		$settings['allow_redirects'] = false;
		for ($hop = 0; ; $hop++)
		{
			$pin = self::_checkPublicUrl($url);
			if ($pin === null)
			{
				return new Helpers\HTTPHelper(new \RuntimeException('Blocked request to a non-public address'));
			}

			$hop_settings = $settings;
			if ($pin !== '' && defined('CURLOPT_RESOLVE'))
			{
				$parts = parse_url($url);
				$port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
				$hop_settings['curl'][\CURLOPT_RESOLVE] = [sprintf('%s:%d:%s', $parts['host'], $port, $pin)];
			}

			$response = $guzzle->request($method, $url, $hop_settings);
			$status = $response->getStatusCode();
			if ($status < 300 || $status >= 400 || $status === 304 || !$response->hasHeader('Location'))
			{
				return $response;
			}
			if ($hop >= $max_redirects)
			{
				return new Helpers\HTTPHelper(new \RuntimeException('Too many redirects'));
			}

			$url = (string)\GuzzleHttp\Psr7\UriResolver::resolve(new \GuzzleHttp\Psr7\Uri($url), new \GuzzleHttp\Psr7\Uri($response->getHeaderLine('Location')));
			if ($status === 303 || (($status === 301 || $status === 302) && $method === 'POST'))
			{
				$method = 'GET';
				unset($settings['body'], $settings['form_params'], $settings['multipart'], $settings['json']);
			}
		}
	}

	/**
	 * Check that a URL points to a public address.
	 *
	 * Returns the address to connect to (an empty string if no pinning is needed),
	 * or null if the URL must not be requested. The host names of the current site
	 * are trusted even if they resolve to a private address.
	 *
	 * @param string $url
	 * @return ?string
	 */
	protected static function _checkPublicUrl(string $url): ?string
	{
		$parts = parse_url($url);
		if (!$parts || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']))
		{
			return null;
		}

		$host = strtolower(trim($parts['host'], '[]'));
		$site_module_info = \Context::get('site_module_info');
		$trusted = array_filter([
			strtolower(strval(parse_url(strval(\Context::getDefaultUrl()), \PHP_URL_HOST))),
			strtolower(strval($site_module_info->domain ?? '')),
		]);
		if (in_array($host, $trusted, true))
		{
			return '';
		}

		if (filter_var($host, \FILTER_VALIDATE_IP))
		{
			return self::isPublicIP($host) ? $host : null;
		}
		if (preg_match('/^[0-9a-fx.]+$/i', $host) || strpos($host, ':') !== false || strpos($host, '%') !== false)
		{
			return null;
		}

		$addresses = [];
		try
		{
			$records = @dns_get_record($host, \DNS_A | \DNS_AAAA);
			foreach (is_array($records) ? $records : [] as $record)
			{
				$ip = $record['ip'] ?? ($record['ipv6'] ?? '');
				if ($ip !== '')
				{
					$addresses[] = $ip;
				}
			}
		}
		catch (\Throwable $e)
		{
		}
		if (!count($addresses))
		{
			$addresses = @gethostbynamel($host) ?: [];
		}
		if (!count($addresses))
		{
			return null;
		}

		foreach ($addresses as $ip)
		{
			if (!self::isPublicIP($ip))
			{
				return null;
			}
		}
		return $addresses[0];
	}

	/**
	 * Check whether an IP address is a public (globally routable) address.
	 *
	 * @param string $ip
	 * @return bool
	 */
	public static function isPublicIP(string $ip): bool
	{
		$packed = @inet_pton($ip);
		if ($packed === false)
		{
			return false;
		}

		if (strlen($packed) === 16)
		{
			$embedded = null;
			if (strncmp($packed, str_repeat("\0", 10) . "\xff\xff", 12) === 0 || strncmp($packed, str_repeat("\0", 12), 12) === 0)
			{
				$embedded = substr($packed, 12);
			}
			elseif (strncmp($packed, "\x00\x64\xff\x9b" . str_repeat("\0", 8), 12) === 0)
			{
				$embedded = substr($packed, 12);
			}
			elseif (strncmp($packed, "\x20\x02", 2) === 0)
			{
				$embedded = substr($packed, 2, 4);
			}
			if ($embedded !== null)
			{
				return self::isPublicIP(inet_ntop($embedded));
			}
			if (strncmp($packed, "\x20\x01\x0d\xb8", 4) === 0 || strncmp($packed, "\x01\x00" . str_repeat("\0", 6), 8) === 0)
			{
				return false;
			}
			return filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6 | \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE) !== false
				&& ord($packed[0]) !== 0xff
				&& (ord($packed[0]) & 0xfe) !== 0xfc
				&& (ord($packed[0]) !== 0xfe || (ord($packed[1]) & 0xc0) < 0x80);
		}

		$long = unpack('N', $packed)[1];
		$blocked = [
			['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16],
			['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15],
			['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
		];
		foreach ($blocked as [$net, $bits])
		{
			$mask = $bits === 0 ? 0 : (~((1 << (32 - $bits)) - 1) & 0xffffffff);
			if (($long & $mask) === (ip2long($net) & $mask))
			{
				return false;
			}
		}
		return true;
	}

	/**
	 * Make any type of request asynchronously.
	 *
	 * @param string $url
	 * @param string $method
	 * @param string|array $data
	 * @param array $headers
	 * @param array $cookies
	 * @param array $settings
	 * @return \GuzzleHttp\Promise\PromiseInterface
	 */
	public static function async(string $url, string $method = 'GET', $data = null, array $headers = [], array $cookies = [], array $settings = []): \GuzzleHttp\Promise\PromiseInterface
	{
		// Create a new Guzzle client, or reuse a cached one if available.
		$guzzle = self::_createClient();

		// Populate settings.
		$settings = self::_populateSettings($url, $method, 'async', $data, $headers, $cookies, $settings);

		// Send the request.
		return $guzzle->requestAsync($method, $url, $settings);
	}

	/**
	 * Make multiple concurrent requests.
	 *
	 * @param array $requests[url, method, data, headers, cookies, settings]
	 * @return array
	 */
	public static function multiple(array $requests): array
	{
		// Create a new Guzzle client, or reuse a cached one if available.
		$guzzle = self::_createClient();

		// Add settings for each request.
		$promises = [];
		foreach ($requests as $key => $val)
		{
			$settings = self::_populateSettings($val['url'], $val['method'] ?? 'GET', 'multiple', $val['data'] ?? null, $val['headers'] ?? [], $val['cookies'] ?? [], $val['settings'] ?? []);
			$promise = $guzzle->requestAsync($val['method'] ?? 'GET', $val['url'], $settings);
			$promises[$key] = $promise;
		}

		// Wait for all the requests to complete.
		$responses = \GuzzleHttp\Promise\Utils::settle($promises)->wait();
		$result = [];
		foreach ($responses as $key => $val)
		{
			if ($val['state'] === 'fulfilled')
			{
				$result[$key] = $val['value'];
			}
			else
			{
				$result[$key] = new Helpers\HTTPHelper($val['reason']);
			}
		}

		return $result;
	}

	/**
	 * Create a Guzzle client with default options.
	 *
	 * @return \GuzzleHttp\Client
	 */
	protected static function _createClient(): \GuzzleHttp\Client
	{
		if (!self::$_client)
		{
			// Create default settings.
			$settings = [
				'http_errors' => false,
				'timeout' => self::DEFAULT_TIMEOUT,
				'verify' => \RX_BASEDIR . 'common/vendor/composer/ca-bundle/res/cacert.pem',
			];

			// Add proxy settings.
			$proxy = config('other.proxy') ?: (defined('__PROXY_SERVER__') ? constant('__PROXY_SERVER__') : '');
			if ($proxy !== '')
			{
				$proxy = parse_url($proxy);
				$proxy_scheme = preg_match('/^(https|socks)/', $proxy['scheme'] ?? '') ? ($proxy['scheme'] . '://') : 'http://';
				$proxy_auth = (!empty($proxy['user']) && !empty($proxy['pass'])) ? ($proxy['user'] . ':' . $proxy['pass'] . '@') : '';
				if (!empty($proxy['host']) && !empty($proxy['port']))
				{
					$settings['proxy'] = sprintf('%s%s%s:%s', $proxy_scheme, $proxy_auth, $proxy['host'], $proxy['port']);
				}
			}

			self::$_client = new \GuzzleHttp\Client($settings);
		}
		return self::$_client;
	}

	/**
	 * Populate a settings array with various options.
	 *
	 * @param string $url
	 * @param string $method
	 * @param string $type
	 * @param string|array $data
	 * @param array $headers
	 * @param array $cookies
	 * @param array $settings
	 * @return array
	 */
	protected static function _populateSettings(string $url, string $method = 'GET', string $type = '', $data = null, array $headers = [], array $cookies = [], array $settings = []): array
	{
		// Remove options that are handled before the request is sent.
		unset($settings['block_private']);

		// Set headers.
		if ($headers)
		{
			$settings['headers'] = $headers;
		}

		// Set cookies.
		if ($cookies && !isset($settings['cookies']))
		{
			$jar = \GuzzleHttp\Cookie\CookieJar::fromArray($cookies, parse_url($url, \PHP_URL_HOST));
			$settings['cookies'] = $jar;
		}

		// Set the body or POST data.
		if (is_string($data) && strlen($data) > 0)
		{
			$settings['body'] = $data;
		}
		elseif (is_array($data) && count($data) > 0)
		{
			if (isset($headers['Content-Type']) && preg_match('!^multipart/form-data\b!i', $headers['Content-Type']))
			{
				$settings['multipart'] = [];
				foreach ($data as $key => $val)
				{
					$settings['multipart'][] = ['name' => $key, 'contents' => $val];
				}
			}
			elseif (isset($headers['Content-Type']) && preg_match('!^application/json\b!i', $headers['Content-Type']))
			{
				$settings['json'] = $data;
			}
			elseif ($method !== 'GET')
			{
				$settings['form_params'] = $data;
			}
			else
			{
				$settings['query'] = $data;
			}
		}

		// Set the callback for debugging.
		if (Debug::isEnabledForCurrentUser() && in_array('slow_remote_requests', config('debug.display_content')))
		{
			$log = array();
			$log['url'] = $url;
			$log['verb'] = $method;
			$log['type'] = $type;
			$log['status'] = 0;
			$log['elapsed_time'] = 0;
			$log['called_file'] = $log['called_line'] = $log['called_method'] = null;
			$log['redirect_to'] = null;
			$log['backtrace'] = [];

			$bt = debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS);
			foreach ($bt as $no => $call)
			{
				if($call['file'] !== __FILE__ && $call['file'] !== \RX_BASEDIR . 'classes/file/FileHandler.class.php')
				{
					$log['called_file'] = $bt[$no]['file'];
					$log['called_line'] = $bt[$no]['line'];
					$next = $no + 1;
					if (isset($bt[$next]))
					{
						$log['called_method'] = ($bt[$next]['class'] ?? '') . ($bt[$next]['type'] ?? '') . ($bt[$next]['function'] ?? '');
						$log['backtrace'] = array_slice($bt, $next, 1);
					}
					break;
				}
			}

			$settings['on_stats'] = function($stats) use($log) {
				$log['url'] = strval($stats->getEffectiveUri());
				$log['status'] = $stats->hasResponse() ? $stats->getResponse()->getStatusCode() : 0;
				$log['elapsed_time'] = $stats->getTransferTime();
				$log['redirect_to'] = $stats->hasResponse() ? ($stats->getResponse()->getHeaderLine('location') ?: null) : null;
				Debug::addRemoteRequest($log);
			};
		}
		else
		{
			$settings['on_stats'] = function($stats) {
				Debug::addTime('remote', $stats->getTransferTime());
			};
		}

		return $settings;
	}
}
