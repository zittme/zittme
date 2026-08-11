<?php

/**
 * Mobile class
 *
 * @author NAVER (developers@xpressengine.com)
 */
class Mobile
{
	/**
	 * Whether mobile or not mobile mode
	 * @var bool
	 */
	protected static $_ismobile = null;

	/**
	 * Get instance of Mobile class
	 *
	 * @return Mobile
	 */
	public function getInstance()
	{
		return new self();
	}

	/**
	 * Get current mobile mode
	 *
	 * @return bool
	 */
	public static function isFromMobilePhone()
	{
		// Return cached result.
		if (self::$_ismobile !== null)
		{
			return self::$_ismobile;
		}

		// Not mobile if disabled explicitly.
		if (!self::isMobileEnabled() || Context::get('full_browse') || ($_COOKIE['FullBrowse'] ?? 0))
		{
			return self::$_ismobile = false;
		}

		// Try to detect from URL arguments and cookies, and finally fall back to user-agent detection.
		$m = Context::get('m');
		$cookie = isset($_COOKIE['rx_uatype']) ? $_COOKIE['rx_uatype'] : null;
		$uahash = base64_encode_urlsafe(md5($_SERVER['HTTP_USER_AGENT'] ?? '', true));
		if (strncmp($cookie ?? '', $uahash . ':', strlen($uahash) + 1) !== 0)
		{
			$cookie = null;
		}
		elseif ($m === null)
		{
			$m = substr($cookie, -1);
		}

		if ($m === '1')
		{
			self::$_ismobile = TRUE;
		}
		elseif ($m === '0')
		{
			self::$_ismobile = FALSE;
		}
		else
		{
			self::$_ismobile = Zittme\Framework\UA::isMobile() && (config('mobile.tablets') || !Zittme\Framework\UA::isTablet());
		}

		// Set cookie to prevent recalculation.
		$uatype = $uahash . ':' . (self::$_ismobile ? '1' : '0');
		if ($cookie !== $uatype)
		{
			Zittme\Framework\Cookie::set('rx_uatype', $uatype, ['expires' => 0, 'path' => \RX_BASEURL]);
		}

		return self::$_ismobile;
	}

	/**
	 * Get current mobile mode
	 *
	 * @deprecated
	 * @return bool
	 */
	public static function _isFromMobilePhone()
	{
		return self::isFromMobilePhone();
	}

	/**
	 * Detect mobile device by user agent
	 *
	 * @deprecated
	 * @return bool
	 */
	public static function isMobileCheckByAgent()
	{
		return Zittme\Framework\UA::isMobile();
	}

	/**
	 * Check if user-agent is a tablet PC as iPad or Andoid tablet.
	 *
	 * @deprecated
	 * @return bool
	 */
	public static function isMobilePadCheckByAgent()
	{
		return Zittme\Framework\UA::isTablet();
	}

	/**
	 * Set mobile mode
	 *
	 * @deprecated
	 * @param bool $ismobile
	 * @return void
	 */
	public static function setMobile($ismobile)
	{
		self::$_ismobile = (bool)$ismobile;
	}

	/**
	 * Check if mobile view is enabled
	 *
	 * @raturn bool
	 */
	public static function isMobileEnabled()
	{
		$mobile_enabled = config('mobile.enabled');
		if ($mobile_enabled === null)
		{
			$mobile_enabled = config('use_mobile_view') ? true : false;
		}
		return $mobile_enabled;
	}

	/**
	 * Narrow-screen state, cached per request.
	 * @var bool|null
	 */

	/**
	 * Viewport width below which the screen counts as narrow.
	 */
	const NARROW_BREAKPOINT = 768;

	/**
	 * Per-module view mode stored in modules.use_mobile (char(1)).
	 *
	 * 'Y' and 'N' keep their original meaning so existing installations and
	 * third-party code that compares against them are unaffected. Two values are
	 * added: 'R' for the responsive view, and '' meaning "follow the global
	 * setting". One Rhymix installation can serve several sites, so the view mode
	 * has to be settable per module, not only globally.
	 */
	const VIEW_MOBILE = 'Y';
	const VIEW_NONE = 'N';
	const VIEW_RESPONSIVE = 'R';
	const VIEW_INHERIT = '';

	/**
	 * Check if the responsive view is enabled globally.
	 *
	 * Responsive view keeps the PC skin and layout at all times and only lets
	 * device-specific *settings* (list counts, editor toolbar and height)
	 * follow the actual screen width.
	 *
	 * @return bool
	 */
	public static function isResponsiveViewEnabled()
	{
		return config('mobile.responsive') ? true : false;
	}

	/**
	 * Resolve the view mode that applies to the module being displayed.
	 *
	 * @param object|null $module_info Defaults to the module currently displayed
	 * @return string One of VIEW_MOBILE, VIEW_NONE, VIEW_RESPONSIVE
	 */
	public static function getViewMode($module_info = null)
	{
		if ($module_info === null)
		{
			$module_info = Context::get('current_module_info');
		}

		$mode = $module_info->use_mobile ?? self::VIEW_INHERIT;
		if ($mode === self::VIEW_MOBILE || $mode === self::VIEW_NONE || $mode === self::VIEW_RESPONSIVE)
		{
			return $mode;
		}

		// Not set, or an unknown value: fall back to the global configuration.
		if (self::isResponsiveViewEnabled())
		{
			return self::VIEW_RESPONSIVE;
		}
		return self::isMobileEnabled() ? self::VIEW_MOBILE : self::VIEW_NONE;
	}

	/**
	 * Check if the responsive view applies to the module being displayed.
	 *
	 * @param object|null $module_info
	 * @return bool
	 */
	public static function isResponsiveView($module_info = null)
	{
		return self::getViewMode($module_info) === self::VIEW_RESPONSIVE;
	}

	/**
	 * Normalize a submitted view mode before storing it.
	 *
	 * Each module used to sanitize use_mobile on its own, and they disagreed:
	 * one forced everything that was not 'Y' to 'N', another to ''. Both would
	 * silently discard 'R'. Save paths should call this instead.
	 *
	 * @param mixed $value
	 * @return string One of VIEW_MOBILE, VIEW_NONE, VIEW_RESPONSIVE, VIEW_INHERIT
	 */
	public static function sanitizeViewMode($value)
	{
		$value = is_string($value) ? strtoupper(trim($value)) : '';
		if ($value === self::VIEW_MOBILE || $value === self::VIEW_NONE || $value === self::VIEW_RESPONSIVE)
		{
			return $value;
		}
		return self::VIEW_INHERIT;
	}

	/**
	 * Human-readable label for a view mode, for admin screens.
	 *
	 * @param string $mode
	 * @return string
	 */
	public static function getViewModeLabel($mode)
	{
		// Keys live in common/lang so every module's setting screen can use them.
		switch ($mode)
		{
			case self::VIEW_MOBILE: return lang('view_mode_mobile');
			case self::VIEW_NONE: return lang('view_mode_none');
			case self::VIEW_RESPONSIVE: return lang('view_mode_responsive');
			default: return lang('view_mode_inherit');
		}
	}

	/**
	 * Check whether the current screen is narrow enough to use mobile settings.
	 *
	 * This is deliberately separate from isFromMobilePhone(): that one answers
	 * "which skin and layout do we render" and must keep its existing meaning,
	 * because core and third-party code select templates with it. This one
	 * answers "which settings apply", which is the only thing the responsive
	 * view changes.
	 *
	 * When the responsive view is off, this falls through to isFromMobilePhone()
	 * so behaviour is identical to before.
	 *
	 * @return bool
	 */
	public static function isNarrowScreen()
	{
		// Deliberately not cached: the answer depends on which module is being
		// displayed, and this can be called before that module is known.

		// Responsive view does not apply to this module: behave exactly as before.
		// The per-module setting wins over the global one, because one installation
		// can serve several sites with different view modes.
		if (!self::isResponsiveView())
		{
			return self::isFromMobilePhone();
		}

		// An explicit ?m= wins, so existing PC/mobile switches keep working.
		$m = Context::get('m');
		if ($m === '1' || $m === 1)
		{
			return true;
		}
		if ($m === '0' || $m === 0)
		{
			return false;
		}

		// The browser reports its width in a cookie. Only a plain integer is
		// accepted; anything else is treated as absent rather than trusted.
		$width = $_COOKIE['rx_viewport'] ?? null;
		if ($width !== null && ctype_digit((string)$width))
		{
			$width = intval($width);
			if ($width > 0 && $width < 10000)
			{
				return $width < self::NARROW_BREAKPOINT;
			}
		}

		// No usable cookie (first visit): fall back to the user agent.
		return \Zittme\Framework\UA::isMobile();
	}
}
