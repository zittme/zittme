<?php
/* Copyright (C) ZZAN Studio and Zittme Contributors */
/**
 * The view class of the menu module
 *
 * @package /modules/menu
 */
class MenuView extends Menu
{
	// Disable gzhandler
	public $gzhandler_enable = false;

	function init()
	{
	}

	/**
	 * Output the sitemap built from the site menu.
	 */
	function sitemap()
	{
		if (!config('seo.sitemap'))
		{
			throw new Zittme\Framework\Exceptions\TargetNotFound;
		}

		$site = $this->_getSitemapSite();
		$urls = $this->_addAlternates($this->_getCachedSitemapUrls($site), $site);

		Context::set('sitemap_urls', $urls);

		Context::setResponseMethod('RAW', 'text/xml');
		$this->setTemplatePath($this->module_path . 'tpl');
		$this->setTemplateFile('sitemap_xml');
	}

	/**
	 * Output the guide file that AI reads to understand the site.
	 */
	function llms()
	{
		if (!config('seo.llms_txt'))
		{
			throw new Zittme\Framework\Exceptions\TargetNotFound;
		}

		$lines = array();
		$title = trim((string)Context::getSiteTitle());
		$lines[] = '# ' . ($title !== '' ? $title : Zittme\Framework\URL::getCurrentDomain());

		$description = trim((string)Context::getSiteSubtitle());
		if ($description !== '')
		{
			$lines[] = '';
			$lines[] = '> ' . $description;
		}

		$entries = $this->_getCachedSitemapUrls($this->_getSitemapSite());
		if (count($entries))
		{
			$lines[] = '';
			$lines[] = '## Pages';
			foreach ($entries as $entry)
			{
				$name = ($entry['name'] ?? '') !== '' ? $entry['name'] : $entry['loc'];
				$name = trim(preg_replace('/[\[\]()`\s]+/u', ' ', (string)$name));
				$loc = str_replace(array('(', ')', ' ', "\r", "\n"), array('%28', '%29', '%20', '', ''), (string)$entry['loc']);
				$lines[] = '- [' . ($name !== '' ? $name : $loc) . '](' . $loc . ')';
			}
		}

		Context::setResponseMethod('RAW', 'text/plain');
		Context::set('llms_body', implode("\n", $lines) . "\n");
		$this->setTemplatePath($this->module_path . 'tpl');
		$this->setTemplateFile('llms_txt');
	}

	/**
	 * Attach the alternate language URLs of each entry.
	 *
	 * The cache is language independent, so this runs on every request.
	 *
	 * @param array $urls
	 * @return array
	 */
	function _addAlternates(array $urls, $site = null): array
	{
		$prefixes = Zittme\Framework\Router::getLangPrefixes();
		if (Zittme\Framework\Router::getLangPrefixLevel() < 2 || !count($prefixes))
		{
			return $urls;
		}

		$base = rtrim(Context::getDefaultUrl($site, \RX_SSL), '/') . \RX_BASEURL;
		$default_lang = config('locale.default_lang');

		foreach ($urls as $key => $entry)
		{
			$is_home = !empty($entry['home']);
			if ($entry['path'] === '' && !$is_home)
			{
				continue;
			}
			$alternates = array($default_lang => $entry['loc'], 'x-default' => $entry['loc']);
			foreach (array_keys($prefixes) as $lang)
			{
				$alternates[$lang] = $base . $lang . ($is_home ? '' : ('/' . $entry['path']));
			}
			$urls[$key]['alternates'] = $alternates;
		}

		return $urls;
	}

	/**
	 * Get the site whose own entries are listed, when sitemap.xml is split by site.
	 *
	 * Returns null when the setting is off (the default) or there is only one
	 * site, so that every site gets the same list as before.
	 *
	 * @return object|null
	 */
	function _getSitemapSite()
	{
		$config = ModuleModel::getModuleConfig('menu');
		if (($config->sitemap_per_domain ?? 'N') !== 'Y')
		{
			return null;
		}
		$site_module_info = Context::get('site_module_info');
		if (!$site_module_info || !isset($site_module_info->domain_srl))
		{
			return null;
		}
		$domains = executeQueryArray('module.getDomains', (object)['list_count' => 2, 'page' => 1]);
		if (count($domains->data ?: array()) < 2)
		{
			return null;
		}
		return $site_module_info;
	}

	/**
	 * Get the sitemaps of a site, as {menu_srl: true}.
	 *
	 * @param object $site
	 * @return array
	 */
	function _getSiteMenuSrls($site): array
	{
		$result = array();
		foreach (Zittme\Modules\Menu\Models\EditorState::getSiteMenuSrlsFor(intval($site->domain_srl)) as $menu_srl)
		{
			$result[intval($menu_srl)] = true;
		}
		$config = ModuleModel::getModuleConfig('menu');
		$unlinked_menu_srl = intval($config->unlinked_menu_srl ?? 0);
		if ($unlinked_menu_srl && ($site->is_default_domain ?? 'N') === 'Y')
		{
			$result[$unlinked_menu_srl] = true;
		}
		elseif ($unlinked_menu_srl)
		{
			unset($result[$unlinked_menu_srl]);
		}
		return $result;
	}

	/**
	 * Return the sitemap entries, rebuilding the cache when it has expired.
	 *
	 * @return array
	 */
	function _getCachedSitemapUrls($site = null): array
	{
		if ($site)
		{
			$cache_name = 'urls_d' . intval($site->domain_srl);
		}
		else
		{
			$site_module_info = Context::get('site_module_info');
			$is_default = !$site_module_info || ($site_module_info->is_default_domain ?? 'Y') === 'Y';
			$cache_name = $is_default ? 'urls' : ('urls_s' . intval($site_module_info->domain_srl));
		}
		$cache_file = \RX_BASEDIR . 'files/cache/sitemap/' . $cache_name . '.php';
		$ttl = max(300, intval(config('seo.sitemap_ttl') ?: 3600));

		if (file_exists($cache_file) && filemtime($cache_file) > time() - $ttl)
		{
			$cached = @include $cache_file;
			if (is_array($cached))
			{
				return $cached;
			}
		}

		$urls = $this->_buildSitemapUrls($site);

		FileHandler::makeDir('./files/cache/sitemap');
		FileHandler::writeFile($cache_file, '<?php return ' . var_export($urls, true) . ';');

		return $urls;
	}

	/**
	 * Collect the sitemap entries from the site menu and recent documents.
	 *
	 * @return array
	 */
	function _buildSitemapUrls($site = null): array
	{
		$base = rtrim(Context::getDefaultUrl($site, \RX_SSL), '/') . \RX_BASEURL;
		$rewrite_level = Zittme\Framework\Router::getRewriteLevel();
		$short = $rewrite_level >= 2;
		$urls = array();
		$mids = array();

		$site_info = $site ?: Context::get('site_module_info');
		$home_module = ModuleModel::getModuleInfoByModuleSrl(intval($site_info->index_module_srl ?? $site_info->module_srl ?? 0));
		$home_mid = $home_module->mid ?? '';

		$oMenuAdminModel = getAdminModel('menu');
		$menus = $oMenuAdminModel->getMenus();
		$site_menu_srls = $site ? $this->_getSiteMenuSrls($site) : null;
		$unlinked_menu_srl = intval(ModuleModel::getModuleConfig('menu')->unlinked_menu_srl ?? 0);
		foreach ($menus ?: array() as $menu)
		{
			if ($site_menu_srls !== null && !isset($site_menu_srls[intval($menu->menu_srl)]))
			{
				continue;
			}
			$output = $oMenuAdminModel->getMenuItems($menu->menu_srl);
			foreach ($output->data ?: array() as $item)
			{
				$url = trim((string)$item->url);
				if ($url === '' || $item->is_shortcut === 'Y' || preg_match('#^(https?:)?//#', $url))
				{
					continue;
				}
				if (!preg_match('/^[a-zA-Z0-9_-]+$/', $url) || isset($mids[$url]))
				{
					continue;
				}
				if (!$this->_isIndexable($url, intval($menu->menu_srl), $site))
				{
					continue;
				}
				// Items that are hidden, limited to groups or parked outside the menus are listed only when asked for
				if (($unlinked_menu_srl && intval($menu->menu_srl) === $unlinked_menu_srl) || !empty($item->group_srls) || (($item->is_hidden ?? 'N') === 'Y'))
				{
					$item_module = ModuleModel::getModuleInfoByMid($url);
					if (strval($item_module->seo_sitemap ?? '') !== 'Y')
					{
						continue;
					}
				}
				$mids[$url] = true;
				$is_home = ($url === $home_mid);
				$entry = array(
					'loc' => $is_home ? $base : ($base . Zittme\Framework\Router::getURL(array('mid' => $url), $rewrite_level)),
					'path' => ($short && !$is_home) ? $url : '',
					'lastmod' => '',
					'name' => trim(html_entity_decode((string)Context::replaceUserLang($item->name), ENT_QUOTES, 'UTF-8')),
				);
				if ($is_home)
				{
					$entry['home'] = true;
				}
				$urls[] = $entry;
			}
		}

		$count = intval(config('seo.sitemap_documents'));
		if ($count > 0 && count($mids))
		{
			$urls = array_merge($urls, $this->_getRecentDocumentUrls(array_keys($mids), $count, $base, $short));
		}

		$trigger_obj = new stdClass;
		$trigger_obj->mids = array_keys($mids);
		$trigger_obj->base = $base;
		$trigger_obj->short = $short;
		$trigger_obj->urls = array();
		ModuleHandler::triggerCall('menu.getSitemapUrls', 'after', $trigger_obj);
		foreach ($trigger_obj->urls as $extra)
		{
			if (is_array($extra) && !empty($extra['loc']))
			{
				$urls[] = array(
					'loc' => $extra['loc'],
					'path' => (string)($extra['path'] ?? ''),
					'lastmod' => (string)($extra['lastmod'] ?? ''),
					'name' => (string)($extra['name'] ?? ''),
				);
			}
		}

		return $urls;
	}

	/**
	 * Modules whose pages are always excluded from search engines.
	 */
	protected static $_noindex_modules = array(
		'admin' => true,
		'communication' => true,
		'member' => true,
		'message' => true,
		'ncenterlite' => true,
		'session' => true,
		'zittme_pay' => true,
	);

	/**
	 * Check whether the page of the given module ID belongs in the sitemap.
	 *
	 * @param string $mid
	 * @return bool
	 */
	function _isIndexable($mid, $menu_srl = 0, $site = null): bool
	{
		$module_info = ModuleModel::getModuleInfoByMid($mid);
		if (!$module_info || !$module_info->module)
		{
			return false;
		}
		if (isset(self::$_noindex_modules[$module_info->module]))
		{
			return false;
		}
		if (Zittme\Modules\Menu\Models\Exposure::isOutOfSitemap($module_info, intval($menu_srl)))
		{
			return false;
		}
		if ($site && intval($module_info->domain_srl ?? -1) > -1 && intval($module_info->domain_srl) !== intval($site->domain_srl))
		{
			return false;
		}
		return true;
	}

	/**
	 * Collect recent documents of the given modules.
	 *
	 * @param array $mids
	 * @param int $count
	 * @param string $base
	 * @param bool $short
	 * @return array
	 */
	function _getRecentDocumentUrls(array $mids, int $count, string $base, bool $short): array
	{
		$module_srls = array();
		foreach ($mids as $mid)
		{
			$module_info = ModuleModel::getModuleInfoByMid($mid);
			if ($module_info && $module_info->module_srl)
			{
				$module_srls[$module_info->module_srl] = $mid;
			}
		}
		if (!count($module_srls))
		{
			return array();
		}

		$args = new stdClass;
		$args->module_srl = array_keys($module_srls);
		$args->sort_index = 'documents.list_order';
		$args->order_type = 'asc';
		$args->list_count = $count;
		$args->statusList = array('PUBLIC');

		$output = executeQueryArray('document.getDocumentList', $args, array('document_srl', 'module_srl', 'title', 'last_update'));
		if (!$output->toBool() || !$output->data)
		{
			return array();
		}

		$aliases = array();
		$document_srls = array_map('intval', array_column($output->data, 'document_srl'));
		if (count($document_srls))
		{
			$alias_stmt = Zittme\Framework\DB::getInstance()->query('SELECT document_srl, alias_title FROM document_aliases WHERE document_srl IN (' . implode(',', $document_srls) . ')');
			foreach ($alias_stmt ? $alias_stmt->fetchAll() : array() as $alias)
			{
				if (!isset($aliases[$alias->document_srl]))
				{
					$aliases[$alias->document_srl] = $alias->alias_title;
				}
			}
		}

		$urls = array();
		foreach ($output->data as $document)
		{
			$mid = $module_srls[$document->module_srl] ?? '';
			if ($mid === '')
			{
				continue;
			}
			$alias = $aliases[$document->document_srl] ?? '';
			if ($alias !== '')
			{
				$path = $mid . '/entry/' . rawurlencode($alias);
				$query = 'index.php?mid=' . $mid . '&entry=' . rawurlencode($alias);
			}
			else
			{
				$path = $mid . '/' . $document->document_srl;
				$query = Zittme\Framework\Router::getURL(array('mid' => $mid, 'document_srl' => $document->document_srl), Zittme\Framework\Router::getRewriteLevel());
			}
			$urls[] = array(
				'loc' => $base . ($short ? $path : $query),
				'path' => $short ? $path : '',
				'lastmod' => $document->last_update ? date('c', ztime($document->last_update)) : '',
				'name' => trim(html_entity_decode((string)$document->title, ENT_QUOTES, 'UTF-8')),
			);
		}

		return $urls;
	}
}
