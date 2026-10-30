<?php

namespace Zittme\Framework;

/**
 * The router class.
 */
class Router
{
	/**
	 * List of XE-compatible global routes.
	 */
	protected static $_global_routes = array(
		'$document_srl' => array(
			'regexp' => '#^(?<document_srl>[0-9]+)$#',
			'vars' => ['document_srl' => 'int'],
			'priority' => 0,
		),
		'$act' => array(
			'regexp' => '#^(?<act>rss|atom)$#',
			'vars' => ['act' => 'word'],
			'priority' => 0,
		),
		'sitemap.xml' => array(
			'regexp' => '#^sitemap\.xml$#',
			'vars' => [],
			'extra_vars' => ['module' => 'menu', 'act' => 'sitemap'],
			'enabled_by' => 'seo.sitemap',
			'priority' => 0,
		),
		'llms.txt' => array(
			'regexp' => '#^llms\.txt$#',
			'vars' => [],
			'extra_vars' => ['module' => 'menu', 'act' => 'llms'],
			'enabled_by' => 'seo.llms_txt',
			'priority' => 0,
		),
		'$mid' => array(
			'regexp' => '#^(?<mid>[a-zA-Z0-9_-]+)/?$#',
			'vars' => ['mid' => 'any'],
			'priority' => 0,
		),
		'$mid/$document_srl' => array(
			'regexp' => '#^(?<mid>[a-zA-Z0-9_-]+)/(?<document_srl>[0-9]+)$#',
			'vars' => ['mid' => 'any', 'document_srl' => 'int'],
			'priority' => 30,
		),
		'$mid/category/$category' => array(
			'regexp' => '#^(?<mid>[a-zA-Z0-9_-]+)/category/(?<category>[0-9]+)$#',
			'vars' => ['mid' => 'any', 'category' => 'int'],
			'priority' => 10,
		),
		'$mid/entry/$entry' => array(
			'regexp' => '#^(?<mid>[a-zA-Z0-9_-]+)/entry/(?<entry>[^/]+)$#',
			'vars' => ['mid' => 'any', 'entry' => 'any'],
			'priority' => 0,
		),
		'$mid/$act' => array(
			'regexp' => '#^(?<mid>[a-zA-Z0-9_-]+)/(?<act>rss|atom|api)$#',
			'vars' => ['mid' => 'any', 'act' => 'word'],
			'priority' => 20,
		),
		'files/download/link/$file_srl/$sid/$filename' => array(
			'regexp' => '#^files/download/link/(?<file_srl>[0-9]+)/(?<sid>[0-9a-f]+)/(?<filename>[^/]+)$#',
			'vars' => ['file_srl' => 'int', 'sid' => 'hex', 'filename' => 'any'],
			'extra_vars' => ['act' => 'procFileDownload'],
			'priority' => 0,
		),
		'files/download/$file_srl/$file_key/$filename' => array(
			'regexp' => '#^files/download/(?<file_srl>[0-9]+)/(?<file_key>[a-zA-Z0-9_-]+)/(?<filename>[^/]+)$#',
			'vars' => ['file_srl' => 'int', 'file_key' => 'any', 'filename' => 'any'],
			'extra_vars' => ['act' => 'procFileOutput'],
			'priority' => 0,
		),
		'common/rewrite/test/$test' => array(
			'regexp' => '#^common/rewrite/test/(?<test>[0-9]+)$#',
			'vars' => ['test' => 'int'],
			'extra_vars' => ['act' => 'dispAdminRewriteTest'],
			'priority' => 0,
		),
	);

	/**
	 * List of legacy URLs that should not be treated as prefixes.
	 */
	protected static $_except_prefixes = array(
		'rss' => true,
		'atom' => true,
	);

	/**
	 * List of legacy modules whose URLs should not be shortened.
	 */
	protected static $_except_modules = array(
		'socialxe' => true,
	);

	/**
	 * Internal cache for module and route information.
	 */
	protected static $_action_cache_prefix = array();
	protected static $_action_cache_module = array();
	protected static $_global_forwarded_cache = array();
	protected static $_internal_forwarded_cache = array();
	protected static $_route_cache = array();
	protected static $_lang_prefix_cache = null;
	protected static $_lang_prefix_level = null;

	/**
	 * Return the currently configured rewrite level.
	 *
	 * 0 = None
	 * 1 = XE-compatible rewrite rules only
	 * 2 = Full rewrite support
	 *
	 * @return int
	 */
	public static function getRewriteLevel(): int
	{
		$level = Config::get('url.rewrite');
		if ($level === null)
		{
			$level = Config::get('use_rewrite') ? 1 : 0;
		}
		return intval($level);
	}

	/**
	 * Return the currently configured language prefix level.
	 *
	 * 0 = None
	 * 1 = Recognize language prefixes, but redirect them to the unprefixed URL
	 * 2 = Full language prefix support
	 *
	 * Language prefixes require full rewrite support.
	 *
	 * @return int
	 */
	public static function getLangPrefixLevel(): int
	{
		if (self::$_lang_prefix_level !== null)
		{
			return self::$_lang_prefix_level;
		}

		$level = 0;
		if (self::getRewriteLevel() >= 2)
		{
			if (Config::get('url.lang_prefix'))
			{
				$level = 2;
			}
			elseif (Config::get('url.lang_prefix_redirect'))
			{
				$level = 1;
			}
		}
		return self::$_lang_prefix_level = $level;
	}

	/**
	 * Return the list of language codes that are used as a URL prefix.
	 *
	 * The default language is excluded because it uses unprefixed URLs.
	 *
	 * @return array
	 */
	public static function getLangPrefixes(): array
	{
		if (self::$_lang_prefix_cache !== null)
		{
			return self::$_lang_prefix_cache;
		}

		$result = array();
		$default_lang = Config::get('locale.default_lang');
		foreach (Config::get('locale.enabled_lang') ?: array() as $lang)
		{
			if ($lang !== $default_lang)
			{
				$result[$lang] = true;
			}
		}
		return self::$_lang_prefix_cache = $result;
	}

	/**
	 * Check the path that remains after a language prefix is removed.
	 *
	 * Returns null if the path starts with a slash or backslash, because
	 * it could be read as a protocol-relative URL when used in a redirect.
	 *
	 * @param string $path
	 * @return ?string
	 */
	public static function cleanLangRedirectPath(string $path): ?string
	{
		if ($path !== '' && ($path[0] === '/' || $path[0] === '\\'))
		{
			return null;
		}
		return $path;
	}

	/**
	 * Return the URL prefix for the given language, or an empty string.
	 *
	 * @param string $lang
	 * @return string
	 */
	public static function getLangPrefix(string $lang): string
	{
		return isset(self::getLangPrefixes()[$lang]) ? $lang : '';
	}

	/**
	 * Check whether a prefix is reserved and cannot be used as a module ID.
	 *
	 * Language codes are reserved only while language prefixes are in use.
	 *
	 * @param string $prefix
	 * @return bool
	 */
	public static function isReservedPrefix(string $prefix): bool
	{
		if (isset(self::$_except_prefixes[$prefix]))
		{
			return true;
		}
		if (self::getLangPrefixLevel() > 0)
		{
			return in_array($prefix, Config::get('locale.enabled_lang') ?: array(), true);
		}
		return false;
	}

	/**
	 * Extract request arguments from the current URL.
	 *
	 * @param string $method
	 * @param string $url
	 * @param int $rewrite_level
	 * @return Request
	 */
	public static function parseURL(string $method, string $url, int $rewrite_level): Request
	{
		// Prepare the return object.
		$result = new Request;

		// Separate additional arguments from the URL.
		$args = array();
		$argstart = strpos($url, '?');
		if ($argstart !== false)
		{
			@parse_str(substr($url, $argstart + 1), $args);
			$url = substr($url, 0, $argstart);
			$result->setAll($args);
		}

		// Decode the URL into plain UTF-8.
		$url = $result->url = urldecode($url);
		if ($url === '')
		{
			return $result;
		}
		if (function_exists('mb_check_encoding') && !mb_check_encoding($url, 'UTF-8'))
		{
			$result->setRouteStatus(404);
			return $result;
		}

		// Separate the language prefix from the rest of the URL.
		$lang_prefix_level = self::getLangPrefixLevel();
		if ($lang_prefix_level > 0)
		{
			$slashpos = strpos($url, '/');
			$prefix = ($slashpos === false) ? $url : substr($url, 0, $slashpos);
			if (isset(self::getLangPrefixes()[$prefix]))
			{
				$url = ($slashpos === false) ? '' : substr($url, $slashpos + 1);
				if (self::cleanLangRedirectPath($url) === null)
				{
					$result->setRouteStatus(404);
					return $result;
				}
				$result->url = $url;
				if ($lang_prefix_level >= 2)
				{
					$result->setRouteOption('lang_prefix', $prefix);
				}
				else
				{
					$result->setRouteOption('lang_prefix_redirect', $url);
				}
				if ($url === '')
				{
					return $result;
				}
			}
			elseif ($prefix === Config::get('locale.default_lang') && in_array($prefix, Config::get('locale.enabled_lang') ?: array(), true) && !(($module_info = \ModuleModel::getModuleInfoByMid($prefix)) && $module_info->module))
			{
				$url = ($slashpos === false) ? '' : substr($url, $slashpos + 1);
				if (self::cleanLangRedirectPath($url) === null)
				{
					$result->setRouteStatus(404);
					return $result;
				}
				$result->url = $url;
				$result->setRouteOption('lang_prefix_redirect', $url);
				if ($url === '')
				{
					return $result;
				}
			}
		}

		// Try to detect the prefix. This might be $mid.
		if ($rewrite_level >= 2 && preg_match('#^([a-zA-Z0-9_-]+)(?:/(.*))?$#s', $url, $matches) && !isset(self::$_except_prefixes[$matches[1]]))
		{
			// Separate the prefix and the internal part of the URL.
			$prefix = $matches[1];
			$internal_url = $matches[2] ?? '';
			$prefix_type = 'mid';

			// Find the module associated with this prefix.
			$module_name = '';
			$action_info = self::_getActionInfoByPrefix($prefix, $module_name);
			if ($action_info === false)
			{
				$action_info = self::_getActionInfoByModule($prefix);
				if ($action_info !== false)
				{
					$module_name = $prefix;
					$prefix_type = 'module';
				}
			}

			// If a module is found, try its routes.
			if ($action_info)
			{
				// Try the index action.
				if ($prefix_type === 'mid' && $internal_url === '' && !isset($args['act']) && $action_info->default_index_act)
				{
					$allargs = array_merge($args, [$prefix_type => $prefix]);
					$result->module = $module_name;
					$result->mid = $prefix;
					$result->setAll($allargs);
					self::_fillActionProperties($result, $action_info->action->{$action_info->default_index_act});
					return $result;
				}

				// Try the list of routes defined by the module.
				foreach ($action_info->route->{$method} ?? [] as $regexp => $action)
				{
					if (preg_match($regexp, $internal_url, $matches))
					{
						$matches = array_filter($matches, 'is_string', \ARRAY_FILTER_USE_KEY);
						$allargs = array_merge($args, $matches, [$prefix_type => $prefix, 'act' => $action]);
						$result->module = $module_name;
						$result->mid = $prefix_type === 'mid' ? $prefix : '';
						$result->act = $action;
						$result->setAll($allargs);
						self::_fillActionProperties($result, $action_info->action->{$action});
						return $result;
					}
				}

				// Check other modules.
				if ($prefix_type === 'mid')
				{
					$forwarded_routes = self::_getForwardedRoutes('internal');
					foreach ($forwarded_routes[$method] ?? [] as $regexp => $action)
					{
						if (preg_match($regexp, $internal_url, $matches))
						{
							$matches = array_filter($matches, 'is_string', \ARRAY_FILTER_USE_KEY);
							$allargs = array_merge($args, $matches, [$prefix_type => $prefix, 'act' => $action[1]]);
							$result->module = $action[0];
							$result->mid = $prefix;
							$result->act = $action[1];
							$result->setRouteOption('is_forwarded', true);
							$result->setAll($allargs);
							self::_fillActionProperties($result);
							return $result;
						}
					}
				}

				// Try the generic mid/act pattern.
				if (preg_match('#^[a-zA-Z0-9_]+$#', $internal_url))
				{
					$allargs = array_merge($args, [$prefix_type => $prefix, 'act' => $internal_url]);
					$result->module = $module_name;
					$result->mid = $prefix_type === 'mid' ? $prefix : '';
					$result->act = $internal_url;
					$result->setRouteOption('is_forwarded', true);
					$result->setAll($allargs);
					self::_fillActionProperties($result);
					return $result;
				}

				// If the module defines a 404 error handler, call it.
				if ($internal_url && isset($action_info->error_handlers[404]))
				{
					$allargs = array_merge($args, [$prefix_type => $prefix, 'act' => $action_info->error_handlers[404]]);
					$result->module = $module_name;
					$result->mid = $prefix_type === 'mid' ? $prefix : '';
					$result->act = $action_info->error_handlers[404];
					$result->setRouteOption('is_forwarded', false);
					$result->setAll($allargs);
					self::_fillActionProperties($result);
					return $result;
				}
			}
		}

		// Try registered global routes.
		if ($rewrite_level >= 2)
		{
			$global_routes = self::_getForwardedRoutes('global');
			foreach ($global_routes[$method] ?? [] as $regexp => $action)
			{
				if (preg_match($regexp, $url, $matches))
				{
					$matches = array_filter($matches, 'is_string', \ARRAY_FILTER_USE_KEY);
					$allargs = array_merge($args, $matches, ['act' => $action[1]]);
					$result->module = $action[0];
					$result->act = $action[1];
					$result->setRouteOption('is_forwarded', true);
					$result->setAll($allargs);
					self::_fillActionProperties($result);
					return $result;
				}
			}
		}

		// Try XE-compatible global routes.
		foreach (self::$_global_routes as $route_info)
		{
			if (isset($route_info['enabled_by']) && !Config::get($route_info['enabled_by']))
			{
				continue;
			}
			if (preg_match($route_info['regexp'], $url, $matches))
			{
				$matches = array_filter($matches, 'is_string', \ARRAY_FILTER_USE_KEY);
				$allargs = array_merge($args, $matches, $route_info['extra_vars'] ?? []);
				$result->module = $allargs['module'] ?? '';
				$result->mid = ($allargs['mid'] ?? '') ?: '';
				$result->act = ($allargs['act'] ?? '') ?: '';
				$result->setRouteOption('is_forwarded', false);
				$result->setAll($allargs);
				self::_fillActionProperties($result);
				return $result;
			}
		}

		// If no pattern matches, return either an empty route or a 404 error.
		$result->module = isset($args['module']) ? $args['module'] : '';
		$result->mid = isset($args['mid']) ? $args['mid'] : '';
		$result->act = isset($args['act']) ? $args['act'] : '';
		$result->setAll($args);
		if ($url === '' || $url === 'index.php')
		{
			$result->url = '';
			return $result;
		}
		else
		{
			$result->setRouteStatus(404);
			return $result;
		}
	}

	/**
	 * Create a URL for the given set of arguments.
	 *
	 * @param array $args
	 * @param int $rewrite_level
	 * @return string
	 */
	public static function getURL(array $args, int $rewrite_level): string
	{
		// If rewrite is turned off, just create a query string.
		if ($rewrite_level == 0)
		{
			return 'index.php?' . http_build_query($args);
		}

		// Cache the number of arguments and their keys.
		$count = count($args);
		$keys = array_keys($args);

		// If there are no arguments, return the URL of the main page.
		if ($count == 0)
		{
			return '';
		}

		// If there is only one argument, try either $mid or $document_srl.
		if ($rewrite_level >= 1 && $count == 1 && ($keys[0] === 'mid' || $keys[0] === 'document_srl'))
		{
			return urlencode($args[$keys[0]]);
		}

		// If the list of keys is already cached, return the corresponding route.
		$keys_sorted = $keys; sort($keys_sorted);
		$keys_string = implode('.', $keys_sorted) . ':' . ($args['mid'] ?? '') . ':' . ($args['act'] ?? '');
		if (isset(self::$_route_cache[$rewrite_level][$keys_string]))
		{
			return self::_insertRouteVars(self::$_route_cache[$rewrite_level][$keys_string], $args);
		}

		// Remove $mid and $act from arguments and work with the remainder.
		$args2 = $args; unset($args2['module'], $args2['mid'], $args2['act']);

		// If $mid exists, try routes defined in the module.
		if ($rewrite_level >= 2 && (isset($args['mid']) || isset($args['module'])))
		{
			// Get module action info.
			if (isset($args['mid']) && $args['mid'] !== '')
			{
				$action_info = self::_getActionInfoByPrefix($args['mid']);
				$prefix_type = 'mid';
			}
			elseif (isset($args['module']) && $args['module'] !== '')
			{
				$action_info = self::_getActionInfoByModule($args['module']);
				$prefix_type = 'module';
			}

			// If there is no $act, use the default action.
			if (isset($args['act']) && $args['act'] !== '')
			{
				$act = $args['act'];
			}
			else
			{
				$act = $action_info->default_index_act;
			}

			// Check if $act has any routes defined.
			$action = $action_info->action->{$act} ?? null;
			if ($action && $action->route)
			{
				$result = self::_getBestMatchingRoute($action->route, $args2);
				if ($result !== false)
				{
					self::$_route_cache[$rewrite_level][$keys_string] = '$' . $prefix_type . '/' . $result . '$act:delete';
					$internal_url = self::_insertRouteVars($result, $args2);
					return $args[$prefix_type] . ($internal_url ? ('/' . $internal_url) : '');
				}
				elseif (isset($args['act']) && $args['act'] === $action_info->default_index_act && !count($args2))
				{
					self::$_route_cache[$rewrite_level][$keys_string] = '$' . $prefix_type . '$act:delete';
					return $args[$prefix_type];
				}
			}

			// Check other modules for $act.
			if ($prefix_type === 'mid')
			{
				$forwarded_routes = self::_getForwardedRoutes('internal');
				if (isset($forwarded_routes['reverse'][$act]))
				{
					$result = self::_getBestMatchingRoute($forwarded_routes['reverse'][$act], $args2);
					if ($result !== false)
					{
						self::$_route_cache[$rewrite_level][$keys_string] = '$' . $prefix_type . '/' . $result . '$act:delete';
						$internal_url = self::_insertRouteVars($result, $args2);
						return $args[$prefix_type] . ($internal_url ? ('/' . $internal_url) : '');
					}
				}
			}

			// Try the generic mid/act pattern.
			if (($prefix_type !== 'module' || !isset(self::$_except_modules[$args[$prefix_type]])) && isset($args['act']))
			{
				self::$_route_cache[$rewrite_level][$keys_string] = '$' . $prefix_type . '/$act';
				$internal_url = $args['act'] . (count($args2) ? ('?' . http_build_query($args2)) : '');
				return $args[$prefix_type] . ($internal_url ? ('/' . $internal_url) : '');
			}
		}

		// Try registered global routes.
		if ($rewrite_level >= 2 && (isset($args['act']) && $args['act'] !== ''))
		{
			$global_routes = self::_getForwardedRoutes('global');
			if (isset($global_routes['reverse'][$args['act']]))
			{
				$result = self::_getBestMatchingRoute($global_routes['reverse'][$args['act']], $args2);
				if ($result !== false)
				{
					self::$_route_cache[$rewrite_level][$keys_string] = $result . '$act:delete';
					return self::_insertRouteVars($result, $args2);
				}
			}
		}

		// Try XE-compatible global routes.
		if ($rewrite_level >= 1)
		{
			// Static routes with extra_vars only apply when every extra var is given and matches.
			$static_routes = array();
			$dynamic_routes = array();
			foreach (self::$_global_routes as $route => $route_info)
			{
				if (!empty($route_info['extra_vars']))
				{
					$static_routes[$route] = $route_info;
				}
				else
				{
					$dynamic_routes[$route] = $route_info;
				}
			}
			foreach ($static_routes as $route => $route_info)
			{
				$matched = true;
				foreach ($route_info['extra_vars'] as $key => $val)
				{
					if (!isset($args[$key]) || $args[$key] !== $val)
					{
						$matched = false;
						break;
					}
				}
				foreach (array_keys($route_info['vars'] ?? array()) as $key)
				{
					if ($matched && !isset($args[$key]))
					{
						$matched = false;
					}
				}
				if ($matched)
				{
					self::$_route_cache[$rewrite_level][$keys_string] = $route;
					return self::_insertRouteVars($route, array_diff_key($args, $route_info['extra_vars']));
				}
			}

			if (!isset($args['act']) || ($args['act'] === 'rss' || $args['act'] === 'atom'))
			{
				$result = self::_getBestMatchingRoute($dynamic_routes, $args);
				if ($result !== false)
				{
					self::$_route_cache[$rewrite_level][$keys_string] = $result;
					return self::_insertRouteVars($result, $args);
				}
			}
		}

		// If no route matches, just create a query string.
		self::$_route_cache[$rewrite_level][$keys_string] = 'index.php';
		return 'index.php?' . http_build_query($args);
	}

	/**
	 * Load and cache module action info.
	 *
	 * @param string $prefix
	 * @return object|false
	 */
	protected static function _getActionInfoByPrefix(string $prefix, string &$module_name = '')
	{
		if (isset(self::$_action_cache_prefix[$prefix]))
		{
			$module_name = self::$_action_cache_prefix[$prefix];
			return self::_getActionInfoByModule(self::$_action_cache_prefix[$prefix]) ?: false;
		}

		$module_info = \ModuleModel::getModuleInfoByMid($prefix);
		if ($module_info && $module_info->module)
		{
			$module_name = self::$_action_cache_prefix[$prefix] = $module_info->module;
			return self::_getActionInfoByModule(self::$_action_cache_prefix[$prefix]) ?: false;
		}
		else
		{
			return self::$_action_cache_prefix[$prefix] = false;
		}
	}

	/**
	 * Load and cache module action info.
	 *
	 * @param string $prefix
	 * @return object|false
	 */
	protected static function _getActionInfoByModule(string $module)
	{
		if (isset(self::$_action_cache_module[$module]))
		{
			return self::$_action_cache_module[$module];
		}

		$action_info = \ModuleModel::getModuleActionXml($module);
		return self::$_action_cache_module[$module] = $action_info ?: false;
	}

	/**
	 * Fill additional properties of an action.
	 *
	 * @param Request $request
	 * @param ?object $action
	 * @return void
	 */
	protected static function _fillActionProperties(Request $request, ?object $action = null): void
	{
		if (!$action)
		{
			if ($request->module && $request->act)
			{
				$action_info = \ModuleModel::getModuleActionXml($request->module);
				if (isset($action_info->action->{$request->act}))
				{
					$action = $action_info->action->{$request->act};
				}
			}
		}
		if (!$action)
		{
			return;
		}

		$request->setRouteOption('check_csrf', ($action->check_csrf === 'false') ? false : true);
		$request->setRouteOption('is_indexable', ($action->meta_noindex === 'true') ? false : true);
		$request->setRouteOption('enable_session', ($action->session === 'false') ? false : true);
		$request->setRouteOption('cache_control', ($action->cache_control === 'false') ? false : true);
	}

	/**
	 * Get the list of routes that are registered for action-forward.
	 *
	 * @param string $type
	 * @return array
	 */
	protected static function _getForwardedRoutes(string $type): array
	{
		if ($type === 'internal' && count(self::$_internal_forwarded_cache))
		{
			return self::$_internal_forwarded_cache;
		}
		if ($type === 'global' && count(self::$_global_forwarded_cache))
		{
			return self::$_global_forwarded_cache;
		}

		self::$_global_forwarded_cache['GET'] = array();
		self::$_global_forwarded_cache['POST'] = array();
		self::$_global_forwarded_cache['reverse'] = array();
		self::$_internal_forwarded_cache['GET'] = array();
		self::$_internal_forwarded_cache['POST'] = array();
		self::$_internal_forwarded_cache['reverse'] = array();

		$action_forward = \ModuleModel::getActionForward();
		foreach ($action_forward as $action_name => $action_info)
		{
			if ($action_info->route_regexp)
			{
				foreach ($action_info->route_regexp as $regexp_info)
				{
					if ($action_info->global_route === 'Y')
					{
						self::$_global_forwarded_cache[$regexp_info[0]][$regexp_info[1]] = [$action_info->module, $action_name];
					}
					else
					{
						self::$_internal_forwarded_cache[$regexp_info[0]][$regexp_info[1]] = [$action_info->module, $action_name];
					}
				}
				if ($action_info->global_route === 'Y')
				{
					self::$_global_forwarded_cache['reverse'][$action_name] = $action_info->route_config;
				}
				else
				{
					self::$_internal_forwarded_cache['reverse'][$action_name] = $action_info->route_config;
				}
			}
		}
		return $type === 'internal' ? self::$_internal_forwarded_cache : self::$_global_forwarded_cache;
	}

	/**
	 * Find the best matching route for an array of variables.
	 *
	 * @param array $routes
	 * @param array $vars
	 * @return string|false
	 */
	protected static function _getBestMatchingRoute(array $routes, array $vars)
	{
		// If the action only has one route, select it.
		if (count($routes) == 1)
		{
			$only_route = key($routes);
			$matched_arguments = array_intersect_key($routes[$only_route]['vars'], $vars);
			if (count($matched_arguments) !== count($routes[$only_route]['vars']))
			{
				return false;
			}
			return $only_route;
		}

		// If the action has multiple routes, select the one that matches the most arguments.
		else
		{
			// Order the routes by the number of matched arguments.
			$reordered_routes = array();
			foreach ($routes as $route => $route_vars)
			{
				$matched_arguments = array_intersect_key($route_vars['vars'], $vars);
				if (count($matched_arguments) === count($route_vars['vars']))
				{
					$reordered_routes[$route] = ($route_vars['priority'] * 1000) + count($matched_arguments);
				}
			}
			if (!count($reordered_routes))
			{
				return false;
			}
			arsort($reordered_routes);
			$best_route = array_key_first($reordered_routes);
			return $best_route;
		}
	}

	/**
	 * Insert variables into a route.
	 *
	 * @param string $route
	 * @param array $vars
	 * @return string
	 */
	protected static function _insertRouteVars(string $route, array $vars): string
	{
		// Replace variable placeholders with actual variable values.
		$route = preg_replace_callback('#\\$([a-zA-Z0-9_]+)(:[a-z]+)?#i', function($match) use(&$vars) {
			if (isset($vars[$match[1]]))
			{
				$replacement = urlencode(strval($vars[$match[1]]));
				unset($vars[$match[1]]);
				return (isset($match[2]) && $match[2] === ':delete') ? '' : $replacement;
			}
			else
			{
				return '';
			}
		}, $route);

		// Replace unnecessary regexp.
		$route = preg_replace('/\\.[*+?]/', '', $route);

		// Add a query string for the remaining arguments.
		return $route . (count($vars) ? ('?' . http_build_query($vars)) : '');
	}
}
