<?php
/* Copyright (C) NAVER <http://www.navercorp.com> */
/**
 * @class  installView
 * @author NAVER (developers@xpressengine.com)
 * @brief View class of install module
 */
class installView extends install
{
	public static $checkEnv = false;
	public static $rewriteCheckFilePath = 'files/cache/tmpRewriteCheck.txt';
	public static $rewriteCheckString = '';

	/**
	 * @brief Initialization
	 */
	function init()
	{
		// Stop if already installed.
		if (Context::isInstalled())
		{
			throw new Zittme\Framework\Exception('msg_already_installed');
		}

		// Set the browser title.
		Context::setBrowserTitle(lang('introduce_title'));

		// Specify the template path.
		$this->setTemplatePath($this->module_path.'tpl');

		// Set default frontend configurations.
		config('view.jquery_version', 3);
		config('view.minify_scripts', 'none');
		config('view.concat_scripts', 'none');

		// Check the environment.
		$oInstallController = getController('install');
		self::$checkEnv = $oInstallController->checkInstallEnv();
		if (self::$checkEnv)
		{
			$oInstallController->makeDefaultDirectory();
		}
	}

	/**
	 * @brief Index page
	 */
	function dispInstallIndex()
	{
		// If there is an autoinstall config file, use it.
		if (file_exists(RX_BASEDIR . 'config/install.config.php'))
		{
			include RX_BASEDIR . 'config/install.config.php';

			if (isset($install_config) && is_array($install_config))
			{
				$oInstallController = getController('install');
				$output = $oInstallController->procInstall($install_config);
				if (!$output->toBool())
				{
					return $output;
				}
				else
				{
					$this->setRedirectUrl(RX_BASEURL);
				}
			}
		}

		// Otherwise, display the license agreement screen.
		Context::set('lang_type', Context::getLangType());
		$this->setTemplateFile('license_agreement');
	}

	/**
	 * @brief Display messages about installation environment
	 */
	function dispInstallCheckEnv()
	{
		// Create a temporary file for mod_rewrite check.
		self::$rewriteCheckString = Zittme\Framework\Security::getRandom(32);
		FileHandler::writeFile(RX_BASEDIR . self::$rewriteCheckFilePath, self::$rewriteCheckString);

		// Check if the web server is nginx.
		$use_nginx = stripos($_SERVER['SERVER_SOFTWARE'], 'nginx') !== false;
		Context::set('use_nginx', $use_nginx);

		// Generate a ready-to-use nginx config and commands for the rewrite helper layer.
		if ($use_nginx)
		{
			$root = rtrim(strtr(\RX_BASEDIR, ['\\' => '/']), '/');
			$domain = preg_replace('/[^a-zA-Z0-9.\-:]/', '', $_SERVER['HTTP_HOST'] ?? 'example.com');
			Context::set('nginx_helper_config', Zittme\Modules\Admin\Controllers\SystemConfig\Advanced::generateNginxRewriteConfig());
			Context::set('nginx_helper_commands',
				"# 한 번에 자동 설정 (권장) — 백업 후 수정하며, 실패 시 자동 롤백됩니다\n" .
				"sudo bash {$root}/common/scripts/setup-nginx.sh {$domain}\n\n" .
				"# ─── 또는 수동 설정 ───\n" .
				"# 1) 동봉된 rewrite 규칙을 nginx snippet으로 복사\n" .
				"cp {$root}/common/manual/server_config/zittme-nginx.conf /etc/nginx/snippets/zittme.conf\n\n" .
				"# 2) 사이트 설정에 아래 '생성된 설정'을 반영 (기존 SSL 설정은 유지)\n" .
				"nano /etc/nginx/sites-available/{$domain}\n\n" .
				"# 3) 문법 검사 후 적용\n" .
				"nginx -t && systemctl reload nginx");
		}
		$this->setTemplateFile('check_env');
	}

	/**
	 * @brief Configure the database
	 */
	function dispInstallDBConfig()
	{
		// Display check_env if it is not installable
		if(!self::$checkEnv)
		{
			return $this->dispInstallCheckEnv();
		}

		// Delete mod_rewrite check file
		FileHandler::removeFile(RX_BASEDIR . self::$rewriteCheckFilePath);

		// Save mod_rewrite check status.
		if(Context::get('rewrite') === 'Y')
		{
			Context::set('use_rewrite', $_SESSION['use_rewrite'] = 'Y');
		}

		Context::set('error_return_url', getNotEncodedUrl('', 'act', Context::get('act')));
		$this->setTemplateFile('db_config');
	}

	/**
	 * @brief Display a screen to enter DB and administrator's information
	 */
	function dispInstallOtherConfig()
	{
		// Display check_env if not installable
		if(!self::$checkEnv)
		{
			return $this->dispInstallCheckEnv();
		}

		// Get list of time zones.
		Context::set('timezones', Zittme\Framework\DateTime::getTimezoneList());

		// Automatically select a time zone for the user.
		Context::set('selected_timezone', $this->detectUserTimeZone());

		// Always use SSL if installing via SSL.
		Context::set('use_ssl', RX_SSL ? 'always' : 'none');
		Context::set('sitelock_ip_range', $this->detectUserIPRange());
		$this->setTemplateFile('other_config');
	}

	/**
	 * Detect the IP range of the user.
	 */
	function detectUserIPRange()
	{
		if (RX_CLIENT_IP_VERSION === 4)
		{
			return preg_replace('/\.\d+$/', '.*', RX_CLIENT_IP);
		}
		elseif (function_exists('inet_pton'))
		{
			$binary = inet_pton(RX_CLIENT_IP);
			$binary = substr($binary, 0, 8) . str_repeat(chr(0), 8);
			return inet_ntop($binary) . '/64';
		}
		else
		{
			return RX_CLIENT_IP;
		}
	}

	/**
	 * Detect best time zone for the user.
	 */
	function detectUserTimeZone()
	{
		switch (Context::getLangType())
		{
			case 'ko': return 'Asia/Seoul';
			case 'en': return 'Europe/London';
			case 'ja': return 'Asia/Tokyo';
			case 'zh-CN': return 'Asia/Shanghai';
			case 'zh-TW': return 'Asia/Taipei';
			case 'de': return 'Europe/Berlin';
			case 'es': return 'Europe/Madrid';
			case 'fr': return 'Europe/Paris';
			case 'mn': return 'Asia/Ulaanbaatar';
			case 'ru': return 'Europe/Moscow';
			case 'tr': return 'Europe/Istanbul';
			case 'vi': return 'Asia/Ho_Chi_Minh';
			default: return 'UTC';
		}
	}
}
/* End of file install.view.php */
/* Location: ./modules/install/install.view.php */
