<?php

namespace Zittme\Modules\Admin\Controllers\SystemConfig;

use Context;
use HTMLDisplayHandler;
use Zittme\Framework\Cache;
use Zittme\Framework\Config;
use Zittme\Framework\DateTime;
use Zittme\Framework\Exception;
use Zittme\Framework\Lang;
use Zittme\Framework\Router;
use Zittme\Modules\Admin\Controllers\Base;

class Advanced extends Base
{
	/**
	 * Display Advanced Settings page
	 */
	public function dispAdminConfigAdvanced()
	{
		// Object cache
		$object_cache_types = Cache::getSupportedDrivers();
		$object_cache_type = Config::get('cache.type');
		if ($object_cache_type)
		{
			$cache_default_ttl = Config::get('cache.ttl');
			$cache_servers = Config::get('cache.servers');
		}
		else
		{
			$cache_config = array_first(Config::get('cache'));
			if ($cache_config)
			{
				$object_cache_type = preg_replace('/^memcache$/', 'memcached', preg_replace('/:.+$/', '', $cache_config));
			}
			else
			{
				$object_cache_type = 'dummy';
			}
			$cache_default_ttl = 86400;
			$cache_servers = Config::get('cache');
		}

		Context::set('object_cache_types', $object_cache_types);
		Context::set('object_cache_type', $object_cache_type);
		Context::set('cache_default_ttl', $cache_default_ttl);

		if ($cache_servers)
		{
			if (preg_match('!^(/.+)(#[0-9]+)?$!', array_first($cache_servers), $matches))
			{
				Context::set('object_cache_host', $matches[1]);
				Context::set('object_cache_port', 0);
				Context::set('object_cache_dbnum', $matches[2] ? substr($matches[2], 1) : 0);
			}
			else
			{
				Context::set('object_cache_host', parse_url(array_first($cache_servers), PHP_URL_HOST) ?: null);
				Context::set('object_cache_port', parse_url(array_first($cache_servers), PHP_URL_PORT) ?: null);
				Context::set('object_cache_user', parse_url(array_first($cache_servers), PHP_URL_USER) ?? '');
				Context::set('object_cache_pass', parse_url(array_first($cache_servers), PHP_URL_PASS) ?? '');
				$cache_dbnum = preg_replace('/[^\d]/', '', strval(parse_url(array_first($cache_servers), PHP_URL_FRAGMENT) ?: parse_url(array_first($cache_servers), PHP_URL_PATH)));
				Context::set('object_cache_dbnum', $cache_dbnum === '' ? 1 : intval($cache_dbnum));
			}
		}
		else
		{
			Context::set('object_cache_host', null);
			Context::set('object_cache_port', null);
			Context::set('object_cache_dbnum', 1);
		}
		Context::set('cache_truncate_method', Config::get('cache.truncate_method'));
		Context::set('cache_control_header', array_map('trim', explode(',', Config::get('cache.cache_control') ?? 'must-revalidate, no-store, no-cache')));

		// Thumbnail settings
		$oDocumentModel = getModel('document');
		$config = $oDocumentModel->getDocumentConfig();
		Context::set('thumbnail_target', $config->thumbnail_target ?: 'attachment');
		Context::set('thumbnail_type', $config->thumbnail_type ?: 'fill');
		Context::set('thumbnail_quality', $config->thumbnail_quality ?: 75);
		if ($config->thumbnail_type === 'none')
		{
			Context::set('thumbnail_target', 'none');
			Context::set('thumbnail_type', 'fill');
		}

		// Default and enabled languages
		Context::set('supported_lang', Lang::getSupportedList());
		Context::set('default_lang', Config::get('locale.default_lang'));
		Context::set('enabled_lang', Config::get('locale.enabled_lang'));
		Context::set('auto_select_lang', Config::get('locale.auto_select_lang'));

		// Default time zone
		Context::set('timezones', DateTime::getTimezoneList());
		Context::set('selected_timezone', Config::get('locale.default_timezone'));

		// Other settings
		Context::set('use_rewrite', Router::getRewriteLevel());

		// Rewrite helper: detect nginx and generate a ready-to-use server config.
		$is_nginx = isset($_SERVER['SERVER_SOFTWARE']) && stripos($_SERVER['SERVER_SOFTWARE'], 'nginx') !== false;
		Context::set('rewrite_helper_nginx', $is_nginx);
		if ($is_nginx)
		{
			Context::set('rewrite_helper_config', self::generateNginxRewriteConfig());

			// Temporary file for client-side rewrite support check (same mechanism as the installer).
			$check_string = \Zittme\Framework\Security::getRandom(32);
			\FileHandler::writeFile(\RX_BASEDIR . 'files/cache/tmpRewriteCheck.txt', $check_string);
			Context::set('rewrite_check_url', \RX_BASEURL . 'REWRITE/CHECK/SRSLY/ANYTHING/GOES/files/cache/tmpRewriteCheck.txt');
			Context::set('rewrite_check_string', $check_string);
		}
		Context::set('use_mobile_view', (config('mobile.enabled') !== null ? config('mobile.enabled') : config('use_mobile_view')) ? true : false);
		Context::set('tablets_as_mobile', config('mobile.tablets') ? true : false);

		// Responsive view (experimental). It relies on CKEditor being able to switch
		// its toolbar and height in place, so it cannot be enabled without CKEditor.
		Context::set('use_responsive_view', config('mobile.responsive') ? true : false);
		Context::set('ckeditor_available', \Zittme\Framework\Storage::isDirectory(
			\RX_BASEDIR . 'modules/editor/skins/ckeditor'));
		Context::set('mobile_viewport', config('mobile.viewport') ?? HTMLDisplayHandler::DEFAULT_VIEWPORT);
		Context::set('use_ssl', Config::get('url.ssl'));
		Context::set('delay_session', Config::get('session.delay'));
		Context::set('delay_template_compile', Config::get('view.delay_compile'));
		Context::set('use_db_session', Config::get('session.use_db'));
		Context::set('partial_page_rendering', Config::get('view.partial_page_rendering') ?? 'internal_only');
		Context::set('manager_layout', Config::get('view.manager_layout'));
		Context::set('minify_scripts', Config::get('view.minify_scripts'));
		Context::set('concat_scripts', Config::get('view.concat_scripts'));
		Context::set('make_sourcemap', Config::get('view.make_sourcemap'));
		Context::set('jquery_version', Config::get('view.jquery_version'));
		Context::set('outgoing_proxy', Config::get('other.proxy'));

		$this->setTemplateFile('config_advanced');
	}

	/**
	 * Generate a site-specific nginx server config for short URLs (rewrite).
	 *
	 * Values are detected from the current environment: domain, document root,
	 * SSL, and a best-effort guess of the PHP-FPM socket path.
	 */
	public static function generateNginxRewriteConfig(): string
	{
		$domain = preg_replace('/[^a-zA-Z0-9.\-:]/', '', $_SERVER['HTTP_HOST'] ?? 'example.com');
		$root = rtrim(strtr(\RX_BASEDIR, ['\\' => '/']), '/');
		$is_ssl = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
			|| (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

		// Best-effort PHP-FPM socket guess (Ubuntu/Debian convention), fallback to TCP.
		$php_ver = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		$socket_candidates = [
			"/run/php/php{$php_ver}-fpm.sock",
			'/run/php/php-fpm.sock',
			'/run/php-fpm/www.sock',
		];
		$fastcgi_pass = '127.0.0.1:9000';
		foreach ($socket_candidates as $sock)
		{
			if (file_exists($sock))
			{
				$fastcgi_pass = 'unix:' . $sock;
				break;
			}
		}

		$listen = $is_ssl
			? "    listen 443 ssl;\n    # ssl_certificate ...; ssl_certificate_key ...; (인증서 경로는 기존 설정 유지)"
			: '    listen 80;';

		return <<<CONF
server {
{$listen}
    server_name {$domain};
    root {$root};
    index index.php;

    client_max_body_size 100M;

    # PHP 처리 (include보다 먼저 선언해야 함)
    location ~ \\.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass {$fastcgi_pass};
    }

    # Zittme 공식 짧은 주소(rewrite) + 보안 차단 규칙
    include snippets/zittme.conf;
}
CONF;
	}

	/**
	 * Update advanced configuration.
	 */
	public function procAdminUpdateAdvanced()
	{
		$vars = Context::getRequestVars();

		// Object cache
		if ($vars->object_cache_type)
		{
			if ($vars->object_cache_type === 'memcached' || $vars->object_cache_type === 'redis')
			{
				if (starts_with('unix:/', $vars->object_cache_host ?? ''))
				{
					$cache_servers = array(substr($vars->object_cache_host ?? '', 5));
				}
				elseif (starts_with('/', $vars->object_cache_host ?? ''))
				{
					$cache_servers = array($vars->object_cache_host ?? '');
				}
				else
				{
					if (trim($vars->object_cache_user ?? '') !== '' || trim($vars->object_cache_pass ?? '') !== '')
					{
						$auth = sprintf('%s:%s@', urlencode(trim($vars->object_cache_user ?? '')), urlencode(trim($vars->object_cache_pass ?? '')));
					}
					else
					{
						$auth = '';
					}
					$cache_servers = array($vars->object_cache_type . '://' . $auth . ($vars->object_cache_host ?? '') . ':' . intval($vars->object_cache_port ?? 0));
				}

				if ($vars->object_cache_type === 'redis')
				{
					$cache_servers[0] .= '#' . intval($vars->object_cache_dbnum ?? 0);
				}
			}
			else
			{
				$cache_servers = array();
			}
			if (!Cache::getDriverInstance($vars->object_cache_type, $cache_servers))
			{
				throw new Exception('msg_cache_handler_not_supported');
			}
			Config::set('cache', array(
				'type' => $vars->object_cache_type,
				'ttl' => intval($vars->cache_default_ttl ?: 86400),
				'servers' => $cache_servers,
			));
		}
		else
		{
			Config::set('cache', array());
		}

		// Cache truncate method
		if (in_array($vars->cache_truncate_method, array('delete', 'empty')))
		{
			Config::set('cache.truncate_method', $vars->cache_truncate_method);
		}

		$cache_control = ['no-cache'];
		foreach (['no-cache', 'no-store', 'must-revalidate'] as $val)
		{
			if (isset($vars->cache_control_header) && in_array($val, $vars->cache_control_header))
			{
				$cache_control[] = $val;
			}
		}
		Config::set('cache.cache_control', implode(', ', array_reverse($cache_control)));

		// Thumbnail settings
		$oDocumentModel = getModel('document');
		$document_config = $oDocumentModel->getDocumentConfig();
		$document_config->thumbnail_target = $vars->thumbnail_target ?: 'attachment';
		$document_config->thumbnail_type = $vars->thumbnail_type ?: 'fill';
		$document_config->thumbnail_quality = intval($vars->thumbnail_quality) ?: 75;
		$oModuleController = getController('module');
		$oModuleController->insertModuleConfig('document', $document_config);

		// Responsive view. Refuse to turn it on without CKEditor rather than saving a
		// setting that cannot work — the toolbar/height switching depends on it.
		$responsive = ($vars->use_responsive_view ?? 'N') === 'Y';
		if ($responsive && !\Zittme\Framework\Storage::isDirectory(\RX_BASEDIR . 'modules/editor/skins/ckeditor'))
		{
			throw new Exception('msg_responsive_view_requires_ckeditor');
		}
		Config::set('mobile.responsive', $responsive);

		// Mobile view. The two are mutually exclusive: the responsive view keeps the PC
		// skin at all times, so a separate mobile view would contradict it. Enforced here
		// rather than only in the form, so the combination cannot be posted directly.
		$mobile_enabled = !$responsive && $vars->use_mobile_view === 'Y';
		Config::set('mobile.enabled', $mobile_enabled);
		Config::set('mobile.tablets', $vars->tablets_as_mobile === 'Y');
		Config::set('mobile.viewport', utf8_trim($vars->mobile_viewport ?? ''));
		if (Config::get('use_mobile_view') !== null)
		{
			Config::set('use_mobile_view', $mobile_enabled);
		}

		// Languages and time zone
		$enabled_lang = $vars->enabled_lang;
		if (!in_array($vars->default_lang, $enabled_lang ?: []))
		{
			$enabled_lang[] = $vars->default_lang;
		}
		Config::set('locale.default_lang', $vars->default_lang);
		Config::set('locale.enabled_lang', array_values($enabled_lang));
		Config::set('locale.auto_select_lang', $vars->auto_select_lang === 'Y');
		Config::set('locale.default_timezone', $vars->default_timezone);

		// Proxy
		$proxy = trim($vars->outgoing_proxy ?? '');
		if ($proxy !== '' && !preg_match('!^(https?|socks)://.+!', $proxy))
		{
			throw new Exception('msg_invalid_outgoing_proxy');
		}

		// Other settings
		Config::set('url.rewrite', intval($vars->use_rewrite));
		Config::set('use_rewrite', $vars->use_rewrite > 0);
		Config::set('session.delay', $vars->delay_session === 'Y');
		Config::set('session.use_db', $vars->use_db_session === 'Y');
		Config::set('view.partial_page_rendering', $vars->partial_page_rendering);
		Config::set('view.manager_layout', $vars->manager_layout ?: 'module');
		Config::set('view.minify_scripts', $vars->minify_scripts ?: 'common');
		Config::set('view.concat_scripts', $vars->concat_scripts ?: 'none');
		Config::set('view.make_sourcemap', $vars->make_sourcemap === 'Y');
		Config::set('view.delay_compile', intval($vars->delay_template_compile));
		Config::set('view.jquery_version', $vars->jquery_version == 3 ? 3 : 2);
		Config::set('other.proxy', $proxy);

		// Save
		if (!Config::save())
		{
			throw new Exception('msg_failed_to_save_config');
		}

		$this->setMessage('success_updated');
		$this->setRedirectUrl(Context::get('success_return_url') ?: getNotEncodedUrl('', 'module', 'admin', 'act', 'dispAdminConfigAdvanced'));
	}
}
