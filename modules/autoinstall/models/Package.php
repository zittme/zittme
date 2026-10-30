<?php

namespace Zittme\Modules\Autoinstall\Models;

use Zittme\Framework\Cache;
use Zittme\Framework\DB;
use Zittme\Framework\Helpers\DBResultHelper;
use Zittme\Framework\HTTP;
use Zittme\Framework\Storage;
use Context;

#[\AllowDynamicProperties]
class Package
{
	/**
	 * Package list API URL
	 */
	public const PACKAGE_LIST_URL = 'https://api.zitt.me/pds/index.json';
	public const PACKAGE_DETAIL_URL = 'https://api.zitt.me/pds/detail/%d.json';

	/**
	 * Install path format of each type of package
	 */
	public const INSTALL_PATHS = [
		'module' => '!^\./modules/(?<name>[a-zA-Z0-9_]+)$!',
		'addon' => '!^\./addons/(?<name>[a-zA-Z0-9_]+)$!',
		'layout' => '!^\./layouts/(?<name>[a-zA-Z0-9_]+)$!',
		'widget' => '!^\./widgets/(?<name>[a-zA-Z0-9_]+)$!',
		'module-skin' => '!^\./modules/(?<module_name>[a-zA-Z0-9_]+)/(?<skin_type>(?:m\.)?skins)/(?<skin_name>[a-zA-Z0-9_]+)$!',
		'widget-skin' => '!^\./widgets/(?<widget_name>[a-zA-Z0-9_]+)/(?<skin_type>(?:m\.)?skins)/(?<skin_name>[a-zA-Z0-9_]+)$!',
		'editor-skin' => '!^\./modules/editor/skins/(?<name>[a-zA-Z0-9_]+)$!',
		'editor-component' => '!^\./modules/editor/components/(?<name>[a-zA-Z0-9_]+)$!',
		// 테마는 구성요소를 원래 자리로 흩뿌리지 않고 자기 폴더 안에 담는다.
		// 그래서 zip 하나 -> 경로 하나라는 기존 설치 방식에 그대로 맞는다.
		'theme' => '!^\./themes/(?<name>[a-zA-Z0-9_]+)$!',
	];

	/**
	 * Path to info file of each type of package
	 */
	public const INFO_FILES = [
		'module' => 'conf/info.xml',
		'addon' => 'conf/info.xml',
		'layout' => 'conf/info.xml',
		'widget' => 'conf/info.xml',
		'module-skin' => 'skin.xml',
		'widget-skin' => 'skin.xml',
		'editor-skin' => 'skin.xml',
		'editor-component' => 'info.xml',
		'theme' => 'theme.xml',
	];

	/**
	 * Reserved names.
	 */
	public const RESERVED_SKIN_NAMES = [
		'admin',
		'default',
		'xedition',
	];

	/*
	 * Properties for DB columns
	 */
	public $package_srl;
	public $type;
	public $title;
	public $description;
	public $author;
	public $license;
	public $last_release_version;
	public $install_path;
	public $install_type;
	public $created;
	public $updated;
	public $featured_count;
	public $list_order;
	public $extra_vars;

	/**
	 * Get type name of the current package.
	 *
	 * @return string
	 */
	public function getType()
	{
		return lang('autoinstall.typename.' . $this->type);
	}

	/**
	 * Get the name of the module, widget, or skin of the current package.
	 *
	 * @return string
	 */
	public function getName()
	{
		return basename($this->install_path);
	}

	/**
	 * Get the lowest price of the current package.
	 *
	 * @return ?int
	 */
	public function getLowestPrice()
	{
		if (empty($this->extra_vars->pricing))
		{
			return null;
		}

		$prices = [];
		foreach ($this->extra_vars->pricing as $pricing)
		{
			$prices[] = intval($pricing->price);
		}
		sort($prices);
		return $prices[0] ?? null;
	}

	/**
	 * Get installation environment of the current package.
	 *
	 * @param string $type 'core' or 'php'
	 * @return string
	 */
	public function getInstallEnvironment($type)
	{
		if ($type === 'core')
		{
			$min = $this->extra_vars->install_env->core_min ?? null;
			$max = $this->extra_vars->install_env->core_max ?? null;
		}
		elseif ($type === 'php')
		{
			$min = $this->extra_vars->install_env->php_min ?? null;
			$max = $this->extra_vars->install_env->php_max ?? null;
		}
		else
		{
			return '';
		}

		if ($min && $max)
		{
			return sprintf('%s - %s', $min, $max);
		}
		elseif ($min)
		{
			return sprintf('%s %s', $min, lang('autoinstall.install_environment.min'));
		}
		elseif ($max)
		{
			return sprintf('%s %s', $max, lang('autoinstall.install_environment.max'));
		}
		else
		{
			return '';
		}
	}

	/**
	 * Check the install environment.
	 *
	 * @return bool
	 */
	public function checkInstallEnvironment(): bool
	{
		if (!empty($this->extra_vars->install_env->core_min) && version_compare(\RX_VERSION, $this->extra_vars->install_env->core_min, '<'))
		{
			return false;
		}
		if (!empty($this->extra_vars->install_env->core_max))
		{
			$core_max = $this->extra_vars->install_env->core_max . '.999';
			if (version_compare(\RX_VERSION, $core_max, '>'))
			{
				return false;
			}
		}
		if (!empty($this->extra_vars->install_env->php_min) && version_compare(\PHP_VERSION, $this->extra_vars->install_env->php_min, '<'))
		{
			return false;
		}
		if (!empty($this->extra_vars->install_env->php_max))
		{
			$php_max = $this->extra_vars->install_env->php_max . '.999';
			if (version_compare(\PHP_VERSION, $php_max, '>'))
			{
				return false;
			}
		}
		if (!class_exists('ZipArchive'))
		{
			return false;
		}

		return true;
	}

	/**
	 * Check the install permissions.
	 *
	 * @return bool
	 */
	/**
	 * Get the path that blocks installation due to missing write permission.
	 *
	 * @return ?string null if everything is writable
	 */
	public function getUnwritablePath(): ?string
	{
		$path = \RX_BASEDIR . rtrim(ltrim($this->install_path, './'), '/');
		$check = $path;
		while (strlen($check) >= strlen(\RX_BASEDIR))
		{
			if (Storage::exists($check) && Storage::isDirectory($check))
			{
				return Storage::isWritable($check) ? null : ('./' . ltrim(substr($check, strlen(\RX_BASEDIR)), '/'));
			}
			$parent = dirname($check);
			if ($parent === $check)
			{
				break;
			}
			$check = $parent;
		}
		return './';
	}

	/**
	 * Get the user account the web server runs as, for permission guidance.
	 *
	 * @return string
	 */
	public static function getProcessUser(): string
	{
		if (function_exists('posix_geteuid') && function_exists('posix_getpwuid'))
		{
			$info = posix_getpwuid(posix_geteuid());
			if (!empty($info['name']))
			{
				return $info['name'];
			}
		}
		return get_current_user() ?: 'www-data';
	}

	public function checkInstallPermissions(): bool
	{
		$path = \RX_BASEDIR . rtrim(ltrim($this->install_path, './'), '/');
		if (Storage::exists($path) && Storage::isDirectory($path))
		{
			return Storage::isWritable($path);
		}
		else
		{
			$parent_path = dirname($path);
			if (Storage::exists($parent_path) && Storage::isDirectory($parent_path))
			{
				return Storage::isWritable($parent_path);
			}
			else
			{
				$parent_path = dirname($parent_path);
				if (Storage::exists($parent_path) && Storage::isDirectory($parent_path))
				{
					return Storage::isWritable($parent_path);
				}
				else
				{
					return false;
				}
			}
		}
	}

	/**
	 * Check if the current package is installable.
	 *
	 * @param string $check_type 'all', 'minimal', 'environment', 'permissions'
	 * @return bool
	 */
	public function isInstallable(string $check_type = 'all'): bool
	{
		// 무료 자료와, 이 사이트 도메인에 라이선스를 활성화한 유료 자료(paid_licensed)만 바로 설치한다.
		if (!preg_match('/^(free_(auto|unverified)|paid_licensed)$/', (string)$this->install_type))
		{
			return false;
		}
		if (in_array($check_type, ['all', 'minimal']) && !self::_validateInstallPath($this->install_path, $this->type))
		{
			return false;
		}
		if (in_array($check_type, ['all', 'environment']) && !$this->checkInstallEnvironment())
		{
			return false;
		}
		if (in_array($check_type, ['all', 'permissions']) && !$this->checkInstallPermissions())
		{
			return false;
		}

		return true;
	}

	/**
	 * Check if the current package is installed.
	 *
	 * @return bool
	 */
	public function isInstalled(): bool
	{
		if (!self::_validateInstallPath($this->install_path, $this->type))
		{
			return false;
		}

		$basedir = \RX_BASEDIR . rtrim(ltrim($this->install_path, './'), '/') . '/';
		$info_filename = self::INFO_FILES[$this->type] ?? null;
		if (Storage::exists($basedir . $info_filename))
		{
			return true;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Check if an update is available for the current package.
	 *
	 * @return object
	 */
	public function getUpdateInfo(): object
	{
		$result = new \stdClass;
		$result->update_available = false;
		$result->installed_version = null;
		$result->latest_version = null;

		if (!$this->last_release_version)
		{
			return $result;
		}

		if (!$this->isInstalled())
		{
			return $result;
		}

		$basedir = \RX_BASEDIR . rtrim(ltrim($this->install_path, './'), '/') . '/';
		$info_filename = self::INFO_FILES[$this->type] ?? null;
		$info = Storage::read($basedir . $info_filename);
		if (!$info)
		{
			return $result;
		}

		if (preg_match('!<version>([^<]+)</version>!', $info, $matches))
		{
			$result->update_available = version_compare($matches[1], $this->last_release_version, '<') ? true : false;
			$result->installed_version = $matches[1];
			$result->latest_version = $this->last_release_version;
		}

		return $result;
	}

	/**
	 * Get cached package count.
	 *
	 * @param string $type
	 * @return int
	 */
	public static function getPackageCount($type = 'all'): int
	{
		$args = new \stdClass;
		$args->type = ($type === 'all' || $type === 'featured') ? null : $type;
		return executeQuery('autoinstall.getPackageCount', $args)->data->count ?? 0;
	}

	/**
	 * Get package information
	 *
	 * @param int $package_srl
	 * @return ?self
	 */
	public static function getPackage($package_srl): ?self
	{
		$args = new \stdClass;
		$args->package_srl = intval($package_srl);
		$output = executeQuery('autoinstall.getPackage', $args, [], 'auto', self::class);
		if (!$output->toBool())
		{
			return null;
		}
		return $output->data;
	}

	/**
	 * Search packages
	 *
	 * @param string $type
	 * @param ?string $search_keyword
	 * @param int $count
	 * @param int $page
	 * @return DBResultHelper
	 */
	public static function searchPackages($type, $search_keyword = null, $count = 20, $page = 1): DBResultHelper
	{
		$args = new \stdClass;
		$args->type = ($type === 'all' || $type === 'featured') ? null : $type;
		$args->search_keyword = $search_keyword ?: null;
		$args->sort_index = ($type === 'featured') ? 'featured_count' : 'list_order';
		$args->list_count = $count;
		$args->page = $page;
		$output = executeQueryArray('autoinstall.getPackages', $args, [], self::class);

		// Cap featured list to 3 pages
		if ($type === 'featured' && $output->total_page > 3)
		{
			$output->total_count = $count * 3;
			$output->total_page = 3;
			$output->page_navigation->total_count = $count * 3;
			$output->page_navigation->total_page = 3;
			$output->page_navigation->page_count = 3;
			$output->page_navigation->last_page = 3;
		}

		return $output;
	}

	/**
	 * Get package detail from official repository
	 *
	 * @param int $package_srl
	 * @return ?self
	 */
	public static function getPackageDetail(int $package_srl): ?self
	{
		// Check the cache.
		$cache_key = sprintf('autoinstall:package_detail:%d', $package_srl);
		$package = Cache::get($cache_key);
		if ($package instanceof self)
		{
			return $package;
		}

		// Fetch the latest package information.
		$detail_url = sprintf(self::PACKAGE_DETAIL_URL, $package_srl);
		$headers = self::getApiHeaders();
		$request = HTTP::get($detail_url, null, $headers, [], ['timeout' => 20]);
		if ($request->getStatusCode() !== 200)
		{
			return null;
		}
		$response = $request->getBody()->getContents();
		if ($response === '')
		{
			return null;
		}
		$response = json_decode($response);
		if (!$response || !isset($response->package_srl))
		{
			return null;
		}

		// Parse into a Package object
		$package = new self();
		$package->extra_vars = new \stdClass();
		foreach ($response as $key => $val)
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

		// Store in the cache and return.
		// 유료 자료는 구매·도메인 등록 직후 설치 가능 여부가 바뀌므로 짧게만 캐시한다.
		$ttl = (strpos((string)$package->install_type, 'paid') === 0 && Account::get()) ? 60 : 3600;
		Cache::set($cache_key, $package, $ttl, true);
		return $package;
	}

	/**
	 * zitt.me 스토어 API 요청 헤더. 관리자가 정보 전송에 동의했고 계정이 연결돼 있을 때만
	 * 토큰과 도메인을 함께 보내 유료 자료의 라이선스(이 사이트 도메인) 판정을 받는다.
	 * 목록 요청에는 보내지 않는다($with_account = false).
	 *
	 * @param bool $with_account
	 * @return array
	 */
	public static function getApiHeaders(bool $with_account = true): array
	{
		$headers = [
			'Accept' => 'application/json',
			'User-Agent' => sprintf('Autoinstall Rhymix/%s PHP/%s (%s)', \RX_VERSION, \PHP_VERSION, \PHP_OS_FAMILY),
		];
		$account = $with_account ? Account::get() : null;
		if ($account && !empty($account->token))
		{
			$headers['Authorization'] = 'Bearer ' . $account->token;
			// 시스템 설정의 도메인 전부. 스토어는 이 중 하나가 라이선스 도메인과 맞으면 통과시킨다.
			$headers['X-Zittme-Domains'] = implode(',', Account::getSiteDomains());
		}
		return $headers;
	}

	/**
	 * Whether a URL is an https address on a host that may receive the account token and domain list.
	 */
	public static function isStoreUrl(string $url): bool
	{
		$parts = parse_url($url);
		if (!$parts || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']))
		{
			return false;
		}
		$allowed = ['zitt.me', 'www.zitt.me', 'api.zitt.me'];
		$site = config('autoinstall.store_site');
		if (is_string($site) && ($site_host = parse_url($site, \PHP_URL_HOST)))
		{
			$allowed[] = strtolower($site_host);
		}
		return in_array(strtolower($parts['host']), $allowed, true);
	}

	/**
	 * A link given by the store, returned only if it is https on zitt.me, a subdomain of it, or the configured store site.
	 */
	public static function safeLink($url): string
	{
		$url = is_string($url) ? trim($url) : '';
		if ($url === '' || self::isStoreUrl($url))
		{
			return $url;
		}
		$parts = parse_url($url);
		if (!$parts || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']))
		{
			return '';
		}
		return preg_match('/\.zitt\.me$/i', $parts['host']) ? $url : '';
	}

	/**
	 * Download a file over https. Account headers go only to store hosts, and every redirect is checked again.
	 *
	 * @return \Psr\Http\Message\ResponseInterface
	 */
	public static function downloadFile(string $url, string $dest, int $timeout = 20)
	{
		$request = null;
		for ($i = 0; $i < 4; $i++)
		{
			if (!preg_match('!^https://!i', $url))
			{
				$request = null;
				break;
			}
			$headers = self::getApiHeaders(self::isStoreUrl($url));
			$request = HTTP::download($url, $dest, 'GET', null, $headers, [], ['timeout' => $timeout, 'allow_redirects' => false]);
			$status = $request->getStatusCode();
			if ($status < 300 || $status >= 400)
			{
				return $request;
			}
			$location = trim($request->getHeaderLine('Location'));
			if ($location === '')
			{
				break;
			}
			$url = (string)\GuzzleHttp\Psr7\UriResolver::resolve(new \GuzzleHttp\Psr7\Uri($url), new \GuzzleHttp\Psr7\Uri($location));
		}
		if (Storage::exists($dest))
		{
			Storage::delete($dest);
		}
		return $request ?: new \Zittme\Framework\Helpers\HTTPHelper(new \Exception('Invalid download URL'));
	}

	/**
	 * Update package list from official repository
	 *
	 * @return bool
	 */
	public static function updatePackageList(): bool
	{
		// Fetch the latest package list.
		$request = HTTP::get(self::PACKAGE_LIST_URL, null, [], [], ['timeout' => 20]);
		if ($request->getStatusCode() !== 200)
		{
			return false;
		}
		$response = $request->getBody()->getContents();
		if ($response === '')
		{
			return false;
		}
		$response = json_decode($response);
		if (!$response || !isset($response->category))
		{
			return false;
		}

		// Store the package list in the database.
		$oDB = DB::getInstance();
		$oDB->begin();
		$oDB->query('TRUNCATE TABLE autoinstall_packages');
		$list_order = 1;
		foreach (array_reverse($response->packages) as $package)
		{
			$args = new \stdClass;
			$extra_vars = new \stdClass;
			$args->package_srl = $package->package_srl;
			$args->type = $package->type;
			$args->title = $package->title;
			$args->description = $package->description;
			$args->author = $package->author;
			$args->license = $package->license ?: '';
			$args->last_release_version = $package->last_release_version ?: '';
			$args->install_path = $package->install_path ?: '';
			$args->install_type = $package->install_type ?: '';
			$args->created = $package->created;
			$args->updated = $package->updated;
			$args->list_order = $list_order++;

			$featured_count = sqrt($package->download_count) + $package->like_count;
			$age = (time() - ztime($args->updated)) / (86400 * 365);
			$featured_count = $featured_count / (1 + $age);
			$args->featured_count = round($featured_count);

			foreach ($package as $key => $val)
			{
				if (!isset($args->{$key}) && isset($val))
				{
					$extra_vars->{$key} = $val;
				}
			}
			$args->extra_vars = json_encode($extra_vars, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			$output = executeQuery('autoinstall.insertPackage', $args);
			if (!$output->toBool())
			{
				$oDB->rollback();
				return false;
			}
		}
		$oDB->commit();
		Cache::clearGroup('autoinstall');
		return true;
	}

	/**
	 * Decoding an instance of the Package class from a database row
	 */
	public function __construct()
	{
		if ($this->extra_vars)
		{
			$this->extra_vars = json_decode($this->extra_vars);
		}
	}

	/**
	 * Validate the install path.
	 *
	 * @param string $path
	 * @param string $type
	 * @return bool
	 */
	protected static function _validateInstallPath($path, $type): bool
	{
		if (!$path)
		{
			return false;
		}

		$pattern = self::INSTALL_PATHS[$type] ?? null;
		if (!$pattern || !preg_match($pattern, $path, $matches))
		{
			return false;
		}

		$name = $matches['skin_name'] ?? $matches['name'] ?? null;
		if (preg_match('!-(?:skin|component)$!', $type))
		{
			if (in_array($name, self::RESERVED_SKIN_NAMES, true))
			{
				return false;
			}
		}
		else
		{
			if (Context::isDefaultPlugin($name, $type))
			{
				return false;
			}
			if (Context::isBlacklistedPlugin($name, $type))
			{
				return false;
			}
		}

		return true;
	}
}
