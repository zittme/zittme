<?php
/* Copyright (C) NAVER <http://www.navercorp.com> */
/**
 * @class  messageView
 * @author NAVER (developers@xpressengine.com)
 * @brief view class of the message module
 */
class MessageView extends Message
{
	const DEFAULT_SKIN = 'zittme';
	const DEFAULT_ACCENT = '#2563eb';

	/**
	 * Force a screen type (used by previews).
	 */
	public $preview_type = null;

	/**
	 * @brief Display messages
	 */
	public function dispMessage($detail = null, $location = null)
	{
		// Get skin configuration. Sites that explicitly chose a skin keep their choice.
		$config = ModuleModel::getModuleConfig('message') ?: new stdClass;
		if(empty($config->skin))
		{
			$config->skin = self::DEFAULT_SKIN;
			// the zittme skin is responsive — reuse it for mobile too
			if(empty($config->mskin))
			{
				$config->mskin = '/USE_RESPONSIVE/';
			}
		}
		if(empty($config->mskin))
		{
			$config->mskin = 'default';
		}

		// Set the template path
		if (contains('mobile', get_class($this), false))
		{
			if($config->mskin === '/USE_RESPONSIVE/')
			{
				// 테마 결합 스킨명(테마|@|스킨)도 풀 수 있게 한다
				$template_path = Zittme\Framework\Theme::resolveSkinPath($this->module_path, $config->skin, 'skins') . '/';
				if(!is_dir($template_path))
				{
					$template_path = sprintf('%sskins/%s/', $this->module_path, 'default');
				}
			}
			else
			{
				$template_path = sprintf('%sm.skins/%s/', $this->module_path, $config->mskin);
				if(!is_dir($template_path))
				{
					$template_path = sprintf('%sm.skins/%s/', $this->module_path, 'default');
				}
			}
		}
		else
		{
			// 테마 결합 스킨명(테마|@|스킨)도 풀 수 있게 한다
			$template_path = Zittme\Framework\Theme::resolveSkinPath($this->module_path, $config->skin, 'skins');
			if(!is_dir($template_path))
			{
				$template_path = sprintf('%sskins/%s/', $this->module_path, 'default');
			}
		}
		$this->setTemplatePath($template_path);

		// Get the member configuration
		$member_config = ModuleModel::getModuleConfig('member');
		Context::set('member_config', $member_config);

		// Disable location if debug not available
		if (!Zittme\Framework\Debug::isEnabledForCurrentUser())
		{
			$location = null;
		}

		// Remove basedir from location (if any)
		if ($location && starts_with(\RX_BASEDIR, $location))
		{
			$location = substr($location, strlen(\RX_BASEDIR));
		}

		Context::set('ssl_mode', \RX_SSL);
		Context::set('system_message', nl2br($this->getMessage()));
		Context::set('system_message_detail', nl2br($detail ?? ''));
		Context::set('system_message_help', self::getErrorHelp(strval($detail ?? '')));
		Context::set('system_message_location', escape($location ?? ''));

		if ($this->getError())
		{
			if ($detail)
			{
				$this->add('errorDetail', $detail);
			}
			if ($location)
			{
				$this->add('errorLocation', $location);
			}
		}

		$this->setTemplateFile('system_message');

		// Default 403 Error
		$status_before = $this->getHttpStatusCode();
		if($status_before === 200)
		{
			$this->setHttpStatusCode(403);
		}

		Context::loadLang($this->module_path . 'lang');
		$mx = $this->buildScreen($config, strval($detail ?? ''), $location ?? '', $status_before);
		if ($mx->type === 'login' && ($config->login_status ?? '') === '200' && Context::getResponseMethod() === 'HTML')
		{
			$this->setHttpStatusCode(200);
		}
		Context::set('mx', $mx);
	}

	/**
	 * Build the view data used by the default message skin.
	 *
	 * @param object $config
	 * @param string $detail
	 * @param string $location
	 * @param int $status
	 * @return object
	 */
	public function buildScreen($config, string $detail, string $location, int $status): object
	{
		$logged_info = Context::get('logged_info');
		$is_logged = $logged_info && !empty($logged_info->member_srl);
		$is_admin = $is_logged && ($logged_info->is_admin ?? 'N') === 'Y';
		$message = strval($this->getMessage());
		$type = $this->preview_type ?: self::classify($message, $detail, $status, $is_logged);

		$mx = new stdClass;
		$mx->type = $type;
		$mx->status = in_array($type, ['maintenance']) ? 503 : ($status === 200 ? 403 : $status);
		$mx->is_logged = $is_logged;
		$mx->is_admin = $is_admin;
		$mx->title = lang('message.mx_title_' . $type);
		$mx->desc = lang('message.mx_desc_' . $type);
		$mx->reason = '';
		$clean_message = trim(escape(strip_tags(str_replace(['<br>', '<br />', '<br/>'], "\n", $message)), false));
		$maintenance_message = trim(strval($config->maintenance_message ?? ''));

		if ($type === 'notice')
		{
			$mx->title = $clean_message !== '' ? nl2br($clean_message) : $mx->title;
		}
		elseif ($type === 'maintenance')
		{
			$lock_title = defined('_XE_SITELOCK_TITLE_') ? strval(constant('_XE_SITELOCK_TITLE_')) : '';
			$lock_message = defined('_XE_SITELOCK_MESSAGE_') ? strval(constant('_XE_SITELOCK_MESSAGE_')) : '';
			if ($lock_title !== '' && $lock_title !== lang('admin.sitelock_in_use'))
			{
				$mx->title = escape(strip_tags($lock_title), false);
			}
			$public_text = $lock_message !== '' ? $lock_message : $maintenance_message;
			if ($public_text !== '')
			{
				$mx->desc = nl2br(escape(strip_tags($public_text), false));
			}
		}
		elseif ($clean_message !== '' && !self::isGenericMessage($message))
		{
			$mx->reason = nl2br($clean_message);
		}

		$mx->home_url = \RX_BASEURL;
		$mx->return_url = getRequestUriByServerEnviroment();
		$mx->contact_url = self::sanitizeLink(strval($config->contact_url ?? ''));
		$mx->show_powered = ($config->show_powered ?? 'N') === 'Y';
		$site_title = trim(strip_tags(strval(Context::getSiteTitle())));
		if ($site_title === '')
		{
			$site_title = strval(Context::get('site_module_info')->domain ?? parse_url(\RX_BASEURL, PHP_URL_HOST) ?? '');
		}
		$mx->site_title = escape($site_title, false);

		$mx->logo_url = '';
		if (($config->show_logo ?? 'Y') !== 'N')
		{
			$mx->logo_url = self::sanitizeLink(strval($config->logo_url ?? ''), false);
			if ($mx->logo_url === '' && class_exists('Zittme\Modules\Admin\Models\Icon'))
			{
				$site_module_info = Context::get('site_module_info');
				$mx->logo_url = \Zittme\Modules\Admin\Models\Icon::getMobiconUrl(intval($site_module_info->domain_srl ?? 0));
			}
		}

		$accent = self::normalizeColor(strval($config->accent_color ?? '')) ?: self::DEFAULT_ACCENT;
		$mx->colors = self::buildPalette($accent);

		$mx->detail = '';
		if ($is_admin)
		{
			$mx->detail = (object)[
				'status' => $mx->status,
				'message' => $clean_message,
				'detail' => nl2br(escape($detail)),
				'help' => self::getErrorHelp($detail),
				'location' => escape($location),
				'request' => escape(Context::getRequestMethod() . ' ' . ($_SERVER['REQUEST_URI'] ?? '')),
				'time' => date('Y-m-d H:i:s'),
			];
		}

		return $mx;
	}

	/**
	 * Decide which screen to show.
	 *
	 * @param string $message
	 * @param string $detail
	 * @param int $status
	 * @param bool $is_logged
	 * @return string
	 */
	public static function classify(string $message, string $detail, int $status, bool $is_logged): string
	{
		if (defined('_XE_SITELOCK_') || $status === 503)
		{
			return 'maintenance';
		}
		if ($status >= 500 || self::messageIs($message, ['msg_dbconnect_failed', 'msg_server_error']))
		{
			return 'server';
		}
		if ($status === 404 || $status === 410 || self::messageIs($message, ['msg_module_is_not_exists', 'msg_not_founded', 'msg_invalid_document', 'msg_not_exists_member']))
		{
			return 'notfound';
		}
		if (starts_with('ERR_CSRF', $detail) || self::messageIs($message, ['msg_security_violation', 'msg_sec_upload_blocked']))
		{
			return 'security';
		}
		if (self::messageIs($message, ['msg_not_logged']) || (!$is_logged && ($status === 401 || self::messageIs($message, ['msg_not_permitted', 'msg_is_not_administrator']))))
		{
			return 'login';
		}
		if ($status === 401 || self::messageIs($message, ['msg_not_permitted', 'msg_is_not_administrator', 'msg_required_not_logged']))
		{
			return 'denied';
		}
		if (in_array($status, [400, 405, 413, 414, 422], true) || self::messageIs($message, ['msg_invalid_request']))
		{
			return 'badrequest';
		}
		return 'notice';
	}

	/**
	 * Check whether a translated message equals one of the given language keys.
	 *
	 * @param string $message
	 * @param array $keys
	 * @return bool
	 */
	public static function messageIs(string $message, array $keys): bool
	{
		$message = trim($message);
		foreach ($keys as $key)
		{
			if ($message === $key || $message === lang($key) || $message === lang('member.' . $key) || $message === lang('module.' . $key))
			{
				return true;
			}
		}
		return false;
	}

	/**
	 * Check whether a message is one of the generic system messages.
	 *
	 * @param string $message
	 * @return bool
	 */
	public static function isGenericMessage(string $message): bool
	{
		return self::messageIs($message, [
			'msg_module_is_not_exists', 'msg_not_founded', 'msg_invalid_document', 'msg_security_violation',
			'msg_not_logged', 'msg_not_permitted', 'msg_is_not_administrator', 'msg_invalid_request',
			'msg_dbconnect_failed', 'msg_server_error',
		]);
	}

	/**
	 * Allow only safe link schemes.
	 *
	 * @param string $url
	 * @param bool $allow_contact_schemes
	 * @return string
	 */
	public static function sanitizeLink(string $url, bool $allow_contact_schemes = true): string
	{
		$url = trim($url);
		if ($url === '' || preg_match('/[\s<>"\']/', $url))
		{
			return '';
		}
		$pattern = $allow_contact_schemes ? '#^(https?://|mailto:|tel:|/(?!/))#i' : '#^(https?://|/(?!/))#i';
		return preg_match($pattern, $url) ? $url : '';
	}

	/**
	 * Normalize a #rrggbb color.
	 *
	 * @param string $color
	 * @return string
	 */
	public static function normalizeColor(string $color): string
	{
		$color = strtolower(trim($color));
		if (preg_match('/^#?([0-9a-f]{3})$/', $color, $m))
		{
			$color = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
		}
		elseif (preg_match('/^#?([0-9a-f]{6})$/', $color, $m))
		{
			$color = '#' . $m[1];
		}
		else
		{
			return '';
		}
		return $color;
	}

	/**
	 * Build accessible accent colors from a base color.
	 *
	 * @param string $accent
	 * @return object
	 */
	public static function buildPalette(string $accent): object
	{
		$light_bg = '#ffffff';
		$dark_bg = '#171a21';
		$white = '#ffffff';
		$ink = '#0b0d12';

		$on = self::contrast($accent, $white) >= self::contrast($accent, $ink) ? $white : $ink;
		$button = $accent;
		for ($i = 0; $i < 40 && self::contrast($button, $on) < 4.6; $i++)
		{
			$button = self::mix($button, $on === $white ? '#000000' : '#ffffff', 0.06);
		}
		$hover = self::mix($button, $on === $white ? '#000000' : '#ffffff', 0.14);
		if (self::contrast($hover, $button) < 1.15)
		{
			$alt = self::mix($button, $on, 0.14);
			if (self::contrast($alt, $on) >= 4.5)
			{
				$hover = $alt;
			}
		}

		$link = $accent;
		for ($i = 0; $i < 40 && self::contrast($link, $light_bg) < 4.6; $i++)
		{
			$link = self::mix($link, '#000000', 0.06);
		}
		$link_dark = $accent;
		for ($i = 0; $i < 40 && self::contrast($link_dark, $dark_bg) < 4.6; $i++)
		{
			$link_dark = self::mix($link_dark, '#ffffff', 0.06);
		}

		return (object)[
			'accent' => $accent,
			'button' => $button,
			'button_hover' => $hover,
			'on_button' => $on,
			'link' => $link,
			'link_dark' => $link_dark,
		];
	}

	/**
	 * Mix two #rrggbb colors.
	 *
	 * @param string $a
	 * @param string $b
	 * @param float $ratio
	 * @return string
	 */
	public static function mix(string $a, string $b, float $ratio): string
	{
		$ca = sscanf($a, '#%02x%02x%02x');
		$cb = sscanf($b, '#%02x%02x%02x');
		$out = '#';
		for ($i = 0; $i < 3; $i++)
		{
			$out .= sprintf('%02x', (int)round($ca[$i] + ($cb[$i] - $ca[$i]) * $ratio));
		}
		return $out;
	}

	/**
	 * WCAG contrast ratio between two #rrggbb colors.
	 *
	 * @param string $a
	 * @param string $b
	 * @return float
	 */
	public static function contrast(string $a, string $b): float
	{
		$la = self::luminance($a);
		$lb = self::luminance($b);
		return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
	}

	/**
	 * Relative luminance of a #rrggbb color.
	 *
	 * @param string $color
	 * @return float
	 */
	public static function luminance(string $color): float
	{
		$c = sscanf($color, '#%02x%02x%02x');
		$l = [];
		foreach ($c as $v)
		{
			$v = $v / 255;
			$l[] = $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
		}
		return 0.2126 * $l[0] + 0.7152 * $l[1] + 0.0722 * $l[2];
	}

	/**
	 * Get friendly help message for common types of errors.
	 *
	 * @param string $error_message
	 * @return string
	 */
	public static function getErrorHelp(string $error_message): string
	{
		$regexp_list = [
			'/Class [\'"]Object[\'"] not found/' => 'baseobject',
			'/Undefined constant [\'"][^\'"]+?[\'"]/' => 'undef_constant',
			'/Attempt to assign property [\'"][^\'"]+?[\'"] on null/' => 'undef_object',
			'/Argument #\d+ \(\$\w+\) must be of type (Countable\|)?array, \w+ given ?/' => 'not_array',
			'/Syntax error, unexpected end of file\b/i' => 'unexpected_eof',
		];

		foreach ($regexp_list as $regexp => $key)
		{
			if (preg_match($regexp, $error_message))
			{
				return lang('message.error_help.' . $key);
			}
		}

		return '';
	}
}
/* End of file message.view.php */
/* Location: ./modules/message/message.view.php */
