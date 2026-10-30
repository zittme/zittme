<?php

namespace Zittme\Modules\Autoinstall\Models\Kit;

use Zittme\Framework\Storage;
use Zittme\Framework\Theme;

/**
 * Builds a starter kit from the current site. Work happens in files/kits/export/<token>/ in three steps
 * (collect, media, pack) so the admin screen can show progress and large sites do not time out.
 */
class Exporter
{
	public const CONFIG_BLOCKED_MODULES = ['member', 'zittme_pay', 'advanced_mailer', 'point', 'spamfilter', 'demobar', 'autoinstall', 'admin', 'install', 'session', 'communication', 'ncenterlite', 'integration_search', 'module', 'file', 'editor', 'layout', 'menu', 'addon', 'store'];
	public const SECRET_KEY_PATTERN = '/(key|secret|token|password|passwd|api|client_id|webhook|account|smtp|sms|mail|phone|email|merchant|licen[sc]e|private|auth(?!or)|credential|bank|cert|salt|ssn|dsn|passcode|(^|_)(pg|tel|fax|mobile|cellphone)(_|$))/i';
	public const MEDIA_BATCH = 300;
	public const MIN_CORE = '1.1.0';

	protected $token;
	protected $dir;
	public $state;

	public function __construct(string $token)
	{
		if (!preg_match('/^[a-zA-Z0-9]{16,64}$/', $token))
		{
			throw new \InvalidArgumentException('invalid export token');
		}
		$this->token = $token;
		$this->dir = Job::getWorkBase() . 'export/' . $token . '/';
		$raw = Storage::read($this->dir . '_state.json');
		$this->state = $raw ? json_decode($raw) : null;
	}

	public static function start(object $options): self
	{
		if (!Manifest::isValidId((string)($options->id ?? '')))
		{
			throw new \InvalidArgumentException(lang('autoinstall.msg_kit_id_rule'));
		}
		Job::protectWorkBase();
		$token = \Zittme\Framework\Security::getRandom(32, 'alnum');
		$exporter = new self($token);
		Storage::createDirectory($exporter->dir . 'data');
		Job::protectDirectory($exporter->dir);
		$exporter->state = (object)['step' => 'collect', 'options' => $options, 'media' => [], 'media_done' => 0, 'zip' => ''];
		$exporter->saveState();
		return $exporter;
	}

	public function getToken(): string
	{
		return $this->token;
	}

	public function getDir(): string
	{
		return $this->dir;
	}

	protected function saveState(): void
	{
		Storage::write($this->dir . '_state.json', json_encode($this->state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	protected function writeData(string $name, $data): string
	{
		$path = 'data/' . $name . '.json';
		Storage::write($this->dir . $path, Codec::encode($data));
		return $path;
	}

	/**
	 * Run one step. Returns ['step' => next step, 'done' => int, 'total' => int].
	 */
	public function run(): array
	{
		if (!$this->state)
		{
			throw new \RuntimeException('export not found');
		}
		switch ($this->state->step)
		{
			case 'collect':
				$this->collect();
				$this->state->step = 'media';
				break;
			case 'media':
				if ($this->copyMedia())
				{
					$this->state->step = 'pack';
				}
				break;
			case 'pack':
				$this->pack();
				$this->state->step = 'done';
				break;
		}
		$this->saveState();
		return [
			'step' => $this->state->step,
			'done' => (int)$this->state->media_done,
			'total' => count((array)$this->state->media),
			'zip' => $this->state->step === 'done' ? basename($this->state->zip) : '',
		];
	}

	public function getZipPath(): string
	{
		return $this->state && $this->state->zip ? $this->dir . basename($this->state->zip) : '';
	}

	protected static function readDesign(): object
	{
		$designInfo = new \stdClass;
		$file = \RX_BASEDIR . 'files/site_design/design_0.php';
		if (is_readable($file))
		{
			include $file;
		}
		if (!isset($designInfo->module))
		{
			$designInfo->module = new \stdClass;
		}
		return $designInfo;
	}

	public static function getSiteHosts(): array
	{
		$hosts = [];
		$output = executeQueryArray('module.getDomains', []);
		foreach ($output->data ?: [] as $row)
		{
			$hosts[] = (string)$row->domain;
		}
		$default = parse_url((string)config('url.default'), PHP_URL_HOST);
		if ($default)
		{
			$hosts[] = $default;
		}
		return $hosts;
	}

	/**
	 * Everything that can be chosen on the export screen.
	 */
	public static function getChoices(): object
	{
		$choices = new \stdClass;
		$ctx = new ExportContext([]);
		$choices->modules = $ctx->query("SELECT module_srl, module, mid, browser_title, menu_srl FROM modules WHERE module NOT IN ('admin', 'member') ORDER BY module_srl ASC");
		$choices->menus = [];
		foreach ($ctx->query("SELECT menu_srl, title FROM menu WHERE title NOT LIKE '\\_\\_%' ORDER BY menu_srl ASC") as $row)
		{
			$row['items'] = (int)(($ctx->query('SELECT COUNT(*) AS c FROM menu_item WHERE menu_srl = ?', [$row['menu_srl']])[0]['c']) ?? 0);
			$choices->menus[] = $row;
		}
		$choices->layouts = $ctx->query('SELECT layout_srl, layout, title, layout_type FROM layouts ORDER BY layout_srl ASC');
		$choices->importers = [];
		foreach ($choices->modules as $row)
		{
			$importer = self::getImporter($row['module']);
			if ($importer && !isset($choices->importers[$row['module']]))
			{
				$choices->importers[$row['module']] = $importer->getKitInfo();
			}
		}
		$choices->locales = array_keys(\Context::loadLangSelected() ?: []);
		$choices->design = self::readDesign();
		return $choices;
	}

	public static function getImporter(string $module): ?KitImporter
	{
		if (!preg_match('/^[a-z0-9_]+$/', $module))
		{
			return null;
		}
		$class = 'Zittme\\Modules\\' . ucfirst($module) . '\\Kit\\Importer';
		if (!Storage::exists(\RX_BASEDIR . 'modules/' . $module . '/kit/Importer.php') || !class_exists($class))
		{
			return null;
		}
		$importer = new $class;
		return $importer instanceof KitImporter ? $importer : null;
	}

	/**
	 * Order modules so that importers declaring 'after' come after the modules they depend on.
	 */
	public static function sortImporters(array $modules): array
	{
		$modules = array_values(array_unique(array_map('strval', $modules)));
		$after = [];
		foreach ($modules as $module)
		{
			$importer = self::getImporter($module);
			$after[$module] = $importer ? array_values(array_intersect((array)($importer->getKitInfo()['after'] ?? []), $modules)) : [];
		}
		$sorted = [];
		$visit = function ($module, $depth) use (&$visit, &$sorted, $after) {
			if (in_array($module, $sorted, true) || $depth > 20)
			{
				return;
			}
			foreach ($after[$module] ?? [] as $dep)
			{
				$visit($dep, $depth + 1);
			}
			if (!in_array($module, $sorted, true))
			{
				$sorted[] = $module;
			}
		};
		foreach ($modules as $module)
		{
			$visit($module, 0);
		}
		return $sorted;
	}

	protected function collect(): void
	{
		$opt = $this->state->options;
		$design = self::readDesign();

		$module_rows = [];
		$srls = array_values(array_filter(array_map('intval', (array)($opt->module_srls ?? []))));
		$tmp = new ExportContext([]);
		foreach ($tmp->query('SELECT * FROM modules WHERE module_srl IN (' . ExportContext::inClause($srls) . ') ORDER BY module_srl ASC', $srls) as $row)
		{
			if (in_array($row['module'], ['admin', 'member'], true))
			{
				continue;
			}
			$module_rows[(int)$row['module_srl']] = $row;
		}
		$mids = [];
		foreach ($module_rows as $srl => $row)
		{
			$mids[$srl] = $row['mid'];
		}
		$ctx = new ExportContext($mids, self::getSiteHosts());
		$ctx->include_media = !empty($opt->include_media);
		$ctx->include_documents = !empty($opt->include_documents);

		$menu_srls = array_values(array_filter(array_map('intval', (array)($opt->menu_srls ?? []))));
		$layout_srls = array_values(array_filter(array_map('intval', (array)($opt->layout_srls ?? []))));
		foreach ($module_rows as $row)
		{
			foreach (['layout_srl', 'mlayout_srl'] as $col)
			{
				if ($col === 'mlayout_srl' && self::isResponsive($row))
				{
					continue;
				}
				$val = (int)$row[$col];
				if ($val === -1)
				{
					$val = (int)($col === 'layout_srl' ? ($design->layout_srl ?? 0) : ($design->mlayout_srl ?? 0));
				}
				if ($val > 0 && !in_array($val, $layout_srls, true))
				{
					$layout_srls[] = $val;
				}
			}
		}

		$manifest = new \stdClass;
		$manifest->schema = Manifest::SCHEMA;
		$manifest->id = (string)$opt->id;
		$manifest->version = (string)$opt->version;
		$manifest->title = $opt->title;
		$manifest->description = $opt->description ?? (object)[];
		$manifest->author = (string)($opt->author ?? '');
		$core = Version::normalize(Version::coreVersion());
		if (version_compare($core, self::MIN_CORE, '<'))
		{
			$core = self::MIN_CORE;
		}
		$manifest->requires = (object)['core' => '>=' . $core, 'php' => '>=7.4'];
		$manifest->packages = [];
		$manifest->locales = (object)['default' => (string)config('locale.default_lang'), 'enabled' => array_values((array)($opt->locales ?? []))];
		$manifest->site = new \stdClass;
		$manifest->content = new \stdClass;

		$layouts = $this->collectLayouts($ctx, $layout_srls, $menu_srls);
		$modules = $this->collectModules($ctx, $module_rows, $design, $menu_srls);
		$menus = $this->collectMenus($ctx, $menu_srls, $module_rows);
		$documents = ['documents' => [], 'files' => []];
		if ($ctx->include_documents)
		{
			$documents = $this->collectDocuments($ctx);
		}
		$configs = $this->collectConfigs($ctx, $module_rows);

		$data_modules = [];
		foreach ((array)($opt->module_data ?? []) as $module)
		{
			if (self::getImporter((string)$module))
			{
				$data_modules[] = (string)$module;
			}
		}
		$opt->module_data = $data_modules;
		$module_data = new \stdClass;
		foreach (self::sortImporters($data_modules) as $module)
		{
			$importer = self::getImporter((string)$module);
			if ($importer)
			{
				$module_data->{$module} = $this->writeData('module-' . $module, $importer->exportKit($ctx));
			}
		}

		$site = new \stdClass;
		$index_srl = (int)(($ctx->query('SELECT index_module_srl FROM domains WHERE is_default_domain = ?', ['Y'])[0]['index_module_srl']) ?? 0);
		if ($index_srl && isset($module_rows[$index_srl]))
		{
			$site->front = ExportContext::ref('m', $index_srl);
		}
		if (!empty($design->layout_srl) && in_array((int)$design->layout_srl, $layout_srls, true))
		{
			$site->layout = ExportContext::ref('L', $design->layout_srl);
		}
		if (!empty($design->mlayout_srl) && in_array((int)$design->mlayout_srl, $layout_srls, true))
		{
			$site->mlayout = ExportContext::ref('L', $design->mlayout_srl);
		}
		$used_themes = [];
		foreach ($modules as $mod)
		{
			foreach ([$mod->skin, $mod->mskin] as $skin_value)
			{
				$split = Theme::split((string)$skin_value);
				if ($split)
				{
					$used_themes[$split['theme']] = true;
				}
			}
		}
		foreach ($layouts as $layout)
		{
			$split = Theme::split((string)$layout->layout);
			if ($split)
			{
				$used_themes[$split['theme']] = true;
			}
		}
		$site->skins = new \stdClass;
		foreach (['member', 'communication'] as $module)
		{
			$config = \ModuleModel::getModuleConfig($module);
			$split = ($config && !empty($config->skin)) ? Theme::split((string)$config->skin) : null;
			$candidate = $split ? (string)$config->skin : '';
			if (!$split || !isset($used_themes[$split['theme']]))
			{
				$candidate = '';
				foreach (array_keys($used_themes) as $theme_name)
				{
					$dirs = glob(Theme::getPath($theme_name) . 'modules/' . $module . '/skins/*', GLOB_ONLYDIR) ?: [];
					if ($dirs)
					{
						$candidate = Theme::combine($theme_name, basename($dirs[0]));
						break;
					}
				}
			}
			if ($candidate !== '')
			{
				$site->skins->{$module} = $candidate;
			}
		}
		$manifest->site = $site;

		$lang = [];
		$pending = array_keys($ctx->lang_codes);
		$seen = [];
		while ($pending)
		{
			$code = array_shift($pending);
			if (isset($seen[$code]))
			{
				continue;
			}
			$seen[$code] = true;
			foreach ($ctx->query('SELECT lang_code, value FROM lang WHERE name = ?', [$code]) as $row)
			{
				$before = array_keys($ctx->lang_codes);
				$lang[$code][$row['lang_code']] = $ctx->rewrite((string)$row['value']);
				foreach (array_diff(array_keys($ctx->lang_codes), $before) as $new_code)
				{
					$pending[] = $new_code;
				}
			}
		}

		$manifest->content->lang = $this->writeData('lang', (object)$lang);
		$manifest->content->menus = $this->writeData('menus', $menus);
		$manifest->content->layouts = $this->writeData('layouts', $layouts);
		$manifest->content->modules = $this->writeData('modules', $modules);
		$manifest->content->documents = $this->writeData('documents', $documents['documents']);
		$manifest->content->files = $this->writeData('files', $documents['files']);
		$manifest->content->configs = $this->writeData('configs', $configs);
		$manifest->content->module_data = $module_data;

		$manifest->packages = $this->detectPackages($module_rows, $layouts, $site, $opt);
		$manifest->stats = (object)[
			'modules' => count($modules),
			'menus' => count($menus),
			'layouts' => count($layouts),
			'documents' => count($documents['documents']),
			'files' => count($documents['files']),
			'media' => count($ctx->media),
			'lang' => count($lang),
		];
		$manifest->exported = (object)['at' => date('c'), 'core' => Version::coreVersion(), 'source' => (string)(parse_url((string)config('url.default'), PHP_URL_HOST) ?: '')];

		$this->state->media = (object)$ctx->media;
		$this->state->manifest = $manifest;
	}

	protected static function isResponsive(array $row): bool
	{
		return ($row['use_mobile'] ?? '') === 'R' || (($row['use_mobile'] ?? '') !== 'Y' && config('mobile.responsive'));
	}

	protected static function readMenuVars(string $layout): array
	{
		$split = Theme::split($layout);
		$path = $split
			? Theme::getPath($split['theme']) . 'layouts/' . basename($split['name']) . '/conf/info.xml'
			: \RX_BASEDIR . 'layouts/' . basename($layout) . '/conf/info.xml';
		$xml = Storage::exists($path) ? (string)Storage::read($path) : '';
		preg_match_all('/<menu\s+name="([a-zA-Z0-9_]+)"/', $xml, $m);
		return $m[1] ?? [];
	}

	protected function collectLayouts(ExportContext $ctx, array $layout_srls, array $menu_srls): array
	{
		$out = [];
		$mids = array_flip($ctx->modules);
		foreach ($ctx->query('SELECT * FROM layouts WHERE layout_srl IN (' . ExportContext::inClause($layout_srls) . ') ORDER BY layout_srl ASC', $layout_srls) as $row)
		{
			$vars = $row['extra_vars'] ? @unserialize($row['extra_vars'], ['allowed_classes' => ['stdClass']]) : new \stdClass;
			if (!is_object($vars) || Codec::hasForeignObject($vars))
			{
				$vars = new \stdClass;
			}
			$vars = self::stripSecrets($vars);
			$menu_names = self::readMenuVars((string)$row['layout']);
			$menu_vars = new \stdClass;
			$mid_vars = new \stdClass;
			$url_vars = new \stdClass;
			foreach (get_object_vars($vars) as $key => $val)
			{
				if (in_array($key, $menu_names, true) || $key === 'menu')
				{
					if (is_scalar($val) && in_array((int)$val, $menu_srls, true))
					{
						$menu_vars->{$key} = ExportContext::ref('M', $val);
					}
					unset($vars->{$key});
					continue;
				}
				if (is_string($val) && isset($mids[$val]))
				{
					$mid_vars->{$key} = ExportContext::ref('m', $mids[$val]);
					continue;
				}
				if (is_string($val) && preg_match('!^/([a-zA-Z][a-zA-Z0-9_]*)(/.*|\?.*|#.*)?$!', $val, $m) && isset($mids[$m[1]]))
				{
					$url_vars->{$key} = (object)['module' => ExportContext::ref('m', $mids[$m[1]]), 'suffix' => $m[2] ?? ''];
				}
			}
			$out[] = (object)[
				'ref' => ExportContext::ref('L', $row['layout_srl']),
				'layout' => (string)$row['layout'],
				'title' => $ctx->rewrite((string)$row['title']),
				'layout_type' => (string)($row['layout_type'] ?: 'P'),
				'extra_vars' => $ctx->rewriteValue(Codec::pack($vars)),
				'menu_vars' => $menu_vars,
				'mid_vars' => $mid_vars,
				'url_vars' => $url_vars,
			];
		}
		return $out;
	}

	protected static function symbolicGroup(int $group_srl, array $groups): ?string
	{
		$special = [0 => 'guest', -1 => 'member', -2 => 'site', -3 => 'manager'];
		if (isset($special[$group_srl]))
		{
			return $special[$group_srl];
		}
		$group = $groups[$group_srl] ?? null;
		if ($group && $group['is_admin'] === 'Y')
		{
			return 'admin_group';
		}
		if ($group && $group['is_default'] === 'Y')
		{
			return 'default_group';
		}
		return null;
	}

	protected function collectModules(ExportContext $ctx, array $module_rows, object $design, array $menu_srls): array
	{
		$groups = [];
		foreach ($ctx->query('SELECT group_srl, is_default, is_admin FROM member_group') as $g)
		{
			$groups[(int)$g['group_srl']] = $g;
		}
		$out = [];
		foreach ($module_rows as $srl => $row)
		{
			$module = $row['module'];
			$responsive = self::isResponsive($row);
			foreach (['layout_srl' => 'layout_srl', 'mlayout_srl' => 'mlayout_srl'] as $col => $design_key)
			{
				$val = (int)$row[$col];
				if ($col === 'mlayout_srl' && $responsive && $val === -1)
				{
					$row[$col] = $row['layout_srl'];
					continue;
				}
				if ($val === -1)
				{
					$val = (int)($design->{$design_key} ?? 0);
				}
				$row[$col] = $val > 0 ? ExportContext::ref('L', $val) : '';
			}
			$skin = (string)$row['skin'];
			if ($skin === '' || $skin === '/USE_DEFAULT/')
			{
				$skin = (string)($design->module->{$module}->skin ?? '') ?: '/USE_DEFAULT/';
			}
			$mskin = (string)$row['mskin'];
			if ($responsive && ($mskin === '' || $mskin === '/USE_DEFAULT/'))
			{
				$mskin = $skin;
			}
			elseif ($mskin === '' || $mskin === '/USE_DEFAULT/')
			{
				$mskin = (string)($design->module->{$module}->mskin ?? '') ?: '/USE_DEFAULT/';
			}

			$extra = new \stdClass;
			foreach ($ctx->query('SELECT name, value FROM module_extra_vars WHERE module_srl = ?', [$srl]) as $ev)
			{
				if ($ev['name'] === 'regdate')
				{
					continue;
				}
				$extra->{$ev['name']} = $ctx->rewrite((string)$ev['value']);
			}
			$doc_refs = new \stdClass;
			if ($module === 'page')
			{
				foreach (['document_srl', 'mdocument_srl'] as $key)
				{
					if (!empty($extra->{$key}) && ctype_digit((string)$extra->{$key}))
					{
						$doc_refs->{$key} = ExportContext::ref('d', $extra->{$key});
						unset($extra->{$key});
					}
				}
			}

			$extra = self::stripSecrets($extra);
			$part = new \stdClass;
			foreach ($ctx->query('SELECT module, config FROM module_part_config WHERE module_srl = ?', [$srl]) as $pc)
			{
				if (in_array($pc['module'], self::CONFIG_BLOCKED_MODULES, true) && $pc['module'] !== 'editor' && $pc['module'] !== 'file')
				{
					continue;
				}
				$config = @unserialize((string)$pc['config'], ['allowed_classes' => ['stdClass']]);
				if ($config !== false)
				{
					$part->{$pc['module']} = $ctx->rewriteValue(Codec::pack(self::stripSecrets($config)));
				}
			}

			$grants = new \stdClass;
			foreach ($ctx->query('SELECT name, group_srl FROM module_grants WHERE module_srl = ?', [$srl]) as $gr)
			{
				$sym = self::symbolicGroup((int)$gr['group_srl'], $groups);
				if ($sym !== null)
				{
					$grants->{$gr['name']}[] = $sym;
				}
			}

			$categories = [];
			foreach ($ctx->query('SELECT * FROM document_categories WHERE module_srl = ? ORDER BY list_order ASC', [$srl]) as $cat)
			{
				$categories[] = (object)[
					'ref' => ExportContext::ref('c', $cat['category_srl']),
					'parent' => (int)$cat['parent_srl'] ? ExportContext::ref('c', $cat['parent_srl']) : '',
					'title' => $ctx->rewrite((string)$cat['title']),
					'description' => $ctx->rewrite((string)($cat['description'] ?? '')),
					'color' => (string)($cat['color'] ?? ''),
					'expand' => (string)($cat['expand'] ?? 'N'),
					'list_order' => (int)$cat['list_order'],
				];
			}

			$out[] = (object)[
				'ref' => ExportContext::ref('m', $srl),
				'module' => $module,
				'mid' => (string)$row['mid'],
				'browser_title' => $ctx->rewrite((string)$row['browser_title']),
				'description' => $ctx->rewrite((string)($row['description'] ?? '')),
				'content' => $ctx->rewrite((string)($row['content'] ?? '')),
				'mcontent' => $ctx->rewrite((string)($row['mcontent'] ?? '')),
				'layout' => $row['layout_srl'],
				'mlayout' => $row['mlayout_srl'],
				'skin' => $skin,
				'mskin' => $mskin,
				'use_mobile' => (string)($row['use_mobile'] ?? ''),
				'is_skin_fix' => (string)($row['is_skin_fix'] ?? 'N'),
				'is_mskin_fix' => (string)($row['is_mskin_fix'] ?? 'N'),
				'open_rss' => (string)($row['open_rss'] ?? ''),
				'header_text' => $ctx->rewrite((string)($row['header_text'] ?? '')),
				'footer_text' => $ctx->rewrite((string)($row['footer_text'] ?? '')),
				'mheader_text' => $ctx->rewrite((string)($row['mheader_text'] ?? '')),
				'mfooter_text' => $ctx->rewrite((string)($row['mfooter_text'] ?? '')),
				'menu' => in_array((int)$row['menu_srl'], $menu_srls, true) ? ExportContext::ref('M', $row['menu_srl']) : '',
				'extra_vars' => $extra,
				'document_refs' => $doc_refs,
				'part_config' => $part,
				'grants' => $grants,
				'categories' => $categories,
			];
		}
		return $out;
	}

	protected function collectMenus(ExportContext $ctx, array $menu_srls, array $module_rows): array
	{
		$mids = [];
		foreach ($module_rows as $srl => $row)
		{
			$mids[$row['mid']] = $srl;
		}
		$all_mids = [];
		foreach ($ctx->query('SELECT mid FROM modules') as $row)
		{
			$all_mids[$row['mid']] = true;
		}
		$out = [];
		foreach ($ctx->query('SELECT * FROM menu WHERE menu_srl IN (' . ExportContext::inClause($menu_srls) . ') ORDER BY menu_srl ASC', $menu_srls) as $menu)
		{
			$items = [];
			$kept = [];
			$children = [];
			foreach ($ctx->query('SELECT * FROM menu_item WHERE menu_srl = ? ORDER BY listorder ASC', [$menu['menu_srl']]) as $item)
			{
				$children[(int)$item['parent_srl']][] = $item;
			}
			$ordered = [];
			$queue = [0];
			while ($queue)
			{
				$parent_srl = array_shift($queue);
				foreach ($children[$parent_srl] ?? [] as $item)
				{
					$ordered[] = $item;
					$queue[] = (int)$item['menu_item_srl'];
				}
			}
			foreach ($ordered as $item)
			{
				$url = (string)$item['url'];
				$parent = (int)$item['parent_srl'];
				if ($parent && !isset($kept[$parent]))
				{
					continue;
				}
				$entry = (object)[
					'ref' => ExportContext::ref('I', $item['menu_item_srl']),
					'parent' => $parent ? ExportContext::ref('I', $parent) : '',
					'name' => $ctx->rewrite((string)$item['name']),
					'url' => '',
					'module' => '',
					'is_shortcut' => (string)$item['is_shortcut'],
					'open_window' => (string)$item['open_window'],
					'expand' => (string)$item['expand'],
					'icon' => $ctx->rewrite((string)($item['icon'] ?? '')),
					'class' => (string)($item['class'] ?? ''),
					'desc' => $ctx->rewrite((string)($item['desc'] ?? '')),
					'normal_btn' => $ctx->rewrite((string)($item['normal_btn'] ?? '')),
					'hover_btn' => $ctx->rewrite((string)($item['hover_btn'] ?? '')),
					'active_btn' => $ctx->rewrite((string)($item['active_btn'] ?? '')),
					'group_srls' => self::symbolicGroupList((string)($item['group_srls'] ?? '')),
					'listorder' => (int)$item['listorder'],
				];
				if (isset($mids[$url]))
				{
					$entry->module = ExportContext::ref('m', $mids[$url]);
				}
				elseif (isset($all_mids[$url]))
				{
					continue;
				}
				else
				{
					$entry->url = $url;
				}
				$kept[(int)$item['menu_item_srl']] = true;
				$items[] = $entry;
			}
			$out[] = (object)[
				'ref' => ExportContext::ref('M', $menu['menu_srl']),
				'title' => $ctx->rewrite((string)$menu['title']),
				'items' => $items,
			];
		}
		return $out;
	}

	protected static function symbolicGroupList(string $value): array
	{
		$out = [];
		foreach (array_filter(explode(',', $value), 'strlen') as $g)
		{
			$map = [-1 => 'member', -3 => 'manager', -4 => 'not_member'];
			if (isset($map[(int)$g]))
			{
				$out[] = $map[(int)$g];
			}
		}
		return $out;
	}

	protected function collectDocuments(ExportContext $ctx): array
	{
		$srls = $ctx->moduleSrls();
		$docs = [];
		$files = [];
		$rows = $ctx->query("SELECT * FROM documents WHERE module_srl IN (" . ExportContext::inClause($srls) . ") AND status = 'PUBLIC' ORDER BY list_order DESC", $srls);
		foreach ($rows as $row)
		{
			$doc_srl = (int)$row['document_srl'];
			$extra = [];
			foreach ($ctx->query('SELECT var_idx, lang_code, value, eid FROM document_extra_vars WHERE document_srl = ? AND var_idx >= 0', [$doc_srl]) as $ev)
			{
				$extra[] = (object)['idx' => (int)$ev['var_idx'], 'lang' => (string)$ev['lang_code'], 'value' => $ctx->rewrite((string)$ev['value']), 'eid' => (string)$ev['eid']];
			}
			if (empty($this->state->options->include_doc_fields))
			{
				$extra = [];
			}
			foreach ($ctx->query("SELECT * FROM files WHERE upload_target_srl = ? AND isvalid = 'Y' ORDER BY file_srl ASC", [$doc_srl]) as $file)
			{
				$path = preg_replace('!^\./!', '', (string)$file['uploaded_filename']);
				if (strpos($path, 'files/attach/') !== 0 || strpos($path, '..') !== false || !Storage::isFile(\RX_BASEDIR . $path))
				{
					continue;
				}
				$rel = substr($path, strlen('files/attach/'));
				$ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
				if (!in_array($ext, Archive::ALLOWED_EXTENSIONS, true) || $ext === 'json' || $ext === 'txt')
				{
					continue;
				}
				$ctx->media[$rel] = \RX_BASEDIR . $path;
				$files[] = (object)[
					'ref' => ExportContext::ref('f', $file['file_srl']),
					'document' => ExportContext::ref('d', $doc_srl),
					'module' => ExportContext::ref('m', $row['module_srl']),
					'source_filename' => (string)$file['source_filename'],
					'media' => $rel,
					'direct_download' => (string)$file['direct_download'],
					'cover_image' => (string)($file['cover_image'] ?? 'N'),
				];
			}
			$docs[] = (object)[
				'ref' => ExportContext::ref('d', $doc_srl),
				'module' => ExportContext::ref('m', $row['module_srl']),
				'category' => (int)$row['category_srl'] ? ExportContext::ref('c', $row['category_srl']) : '',
				'lang_code' => (string)$row['lang_code'],
				'is_notice' => (string)$row['is_notice'],
				'title' => $ctx->rewrite((string)$row['title']),
				'title_bold' => (string)$row['title_bold'],
				'title_color' => (string)$row['title_color'],
				'content' => $ctx->rewrite((string)$row['content']),
				'tags' => (string)$row['tags'],
				'extra_vars' => $ctx->rewriteSerialized($row['extra_vars']),
				'document_extra_vars' => $extra,
				'comment_status' => (string)$row['comment_status'],
				'voted_count' => (int)$row['voted_count'],
				'readed_count' => (int)$row['readed_count'],
				'comments' => empty($this->state->options->include_comments) ? [] : $this->collectComments($ctx, $doc_srl),
				'status' => 'PUBLIC',
				'regdate' => (string)$row['regdate'],
				'last_update' => (string)$row['last_update'],
			];
		}
		return ['documents' => $docs, 'files' => $files];
	}

	/**
	 * Public comments of a document, parents before replies (comments_list head/arrange order).
	 * Authors are kept as display names only; member accounts are never exported.
	 */
	protected function collectComments(ExportContext $ctx, int $document_srl): array
	{
		$out = [];
		$rows = $ctx->query('SELECT comments.comment_srl, comments.parent_srl, comments.content, comments.nick_name, comments.voted_count, comments.blamed_count, comments.regdate, comments.last_update, comments_list.depth FROM comments, comments_list WHERE comments.document_srl = ? AND comments_list.comment_srl = comments.comment_srl AND comments.status = 1 AND comments.is_secret = ? ORDER BY comments_list.head ASC, comments_list.arrange ASC', [$document_srl, 'N']);
		$kept = [];
		foreach ($rows as $row)
		{
			$parent = (int)$row['parent_srl'];
			if ($parent && !isset($kept[$parent]))
			{
				continue;
			}
			$kept[(int)$row['comment_srl']] = true;
			$out[] = (object)[
				'ref' => ExportContext::ref('r', $row['comment_srl']),
				'parent' => $parent ? ExportContext::ref('r', $parent) : '',
				'depth' => (int)$row['depth'],
				'nick_name' => (string)$row['nick_name'],
				'content' => $ctx->rewrite((string)$row['content']),
				'voted_count' => (int)$row['voted_count'],
				'blamed_count' => (int)$row['blamed_count'],
				'regdate' => (string)$row['regdate'],
				'last_update' => (string)$row['last_update'],
			];
		}
		return $out;
	}

	public static function stripSecrets($value)
	{
		if (is_object($value) || is_array($value))
		{
			$is_object = is_object($value);
			$out = $is_object ? new \stdClass : [];
			foreach ($value as $key => $item)
			{
				if (is_string($key) && preg_match(self::SECRET_KEY_PATTERN, $key))
				{
					continue;
				}
				if ($is_object)
				{
					$out->{$key} = self::stripSecrets($item);
				}
				else
				{
					$out[$key] = self::stripSecrets($item);
				}
			}
			return $out;
		}
		return $value;
	}

	protected function collectConfigs(ExportContext $ctx, array $module_rows): object
	{
		$out = new \stdClass;
		$names = [];
		foreach ($module_rows as $row)
		{
			$names[$row['module']] = true;
		}
		foreach (array_keys($names) as $module)
		{
			if (in_array($module, self::CONFIG_BLOCKED_MODULES, true))
			{
				continue;
			}
			$config = \ModuleModel::getModuleConfig($module);
			if ($config)
			{
				$out->{$module} = $ctx->rewriteValue(Codec::pack(self::stripSecrets($config)));
			}
		}
		return $out;
	}

	protected function detectPackages(array $module_rows, array $layouts, object $site, object $opt): array
	{
		$need = [];
		$add = function (string $type, string $path) use (&$need) {
			$need[$path] = $type;
		};
		foreach ($module_rows as $row)
		{
			if (!\Context::isDefaultPlugin($row['module'], 'module'))
			{
				$add('module', './modules/' . $row['module']);
			}
			foreach (['skin', 'mskin'] as $col)
			{
				$split = Theme::split($row[$col] ?? '');
				if ($split)
				{
					$add('theme', './themes/' . $split['theme']);
				}
			}
		}
		foreach ((array)($opt->module_data ?? []) as $module)
		{
			if (!\Context::isDefaultPlugin((string)$module, 'module'))
			{
				$add('module', './modules/' . $module);
			}
		}
		foreach ($layouts as $layout)
		{
			$split = Theme::split($layout->layout);
			if ($split)
			{
				$add('theme', './themes/' . $split['theme']);
			}
			elseif (!\Context::isDefaultPlugin($layout->layout, 'layout'))
			{
				$add('layout', './layouts/' . $layout->layout);
			}
		}
		foreach ((array)($site->skins ?? []) as $skin)
		{
			$split = Theme::split((string)$skin);
			if ($split)
			{
				$add('theme', './themes/' . $split['theme']);
			}
		}

		$out = [];
		foreach ($need as $path => $type)
		{
			$name = basename($path);
			$version = '';
			if ($type === 'module')
			{
				$info = \ModuleModel::getModuleInfoXml($name);
				$version = (string)($info->version ?? '');
			}
			elseif ($type === 'theme')
			{
				$info = Theme::getInfo($name);
				$version = (string)($info->version ?? '');
			}
			$srl_output = executeQuery('autoinstall.getPackageSrlByPath', ['path' => $path]);
			$package_srl = (int)($srl_output->data->package_srl ?? 0);
			$override = $opt->package_srls->{$path} ?? null;
			if ($override)
			{
				$package_srl = (int)$override;
			}
			$out[] = (object)[
				'type' => $type,
				'install_path' => $path,
				'package_srl' => $package_srl,
				'version' => $version ? '>=' . Version::normalize($version) : '',
			];
		}
		return $out;
	}

	protected function copyMedia(): bool
	{
		$media = (array)$this->state->media;
		$keys = array_keys($media);
		$start = (int)$this->state->media_done;
		$end = min(count($keys), $start + self::MEDIA_BATCH);
		for ($i = $start; $i < $end; $i++)
		{
			$rel = $keys[$i];
			$name = Archive::normalizeEntryName('media/' . $rel);
			if ($name === null || !Archive::isAllowedFile($name))
			{
				unset($this->state->media->{$rel});
				continue;
			}
			Storage::copy($media[$rel], $this->dir . $name);
		}
		$this->state->media_done = $end;
		return $end >= count($keys);
	}

	protected function pack(): void
	{
		$manifest = $this->state->manifest;
		$files = [];
		$entries = [];
		foreach (glob($this->dir . 'data/*.json') ?: [] as $path)
		{
			$entries['data/' . basename($path)] = $path;
		}
		foreach ((array)$this->state->media as $rel => $abs)
		{
			$name = 'media/' . $rel;
			if (Storage::isFile($this->dir . $name))
			{
				$entries[$name] = $this->dir . $name;
			}
		}
		$bytes = 0;
		foreach ($entries as $name => $path)
		{
			$files[$name] = hash_file('sha256', $path);
			$bytes += filesize($path);
		}
		$manifest->stats->bytes = $bytes;
		$manifest->integrity = (object)['algo' => 'sha256', 'files' => (object)$files];
		$raw = Codec::encode($manifest);
		Storage::write($this->dir . 'kit.json', $raw);

		$zip_name = $manifest->id . '-' . $manifest->version . '.zip';
		$zip_path = $this->dir . $zip_name;
		$zip = new \ZipArchive;
		if ($zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true)
		{
			throw new \RuntimeException('cannot create zip');
		}
		$zip->addFile($this->dir . 'kit.json', 'kit.json');
		foreach ($entries as $name => $path)
		{
			$zip->addFile($path, $name);
		}
		$zip->close();
		$this->state->zip = $zip_name;
		unset($this->state->manifest);
		$this->state->manifest_summary = (object)['id' => $manifest->id, 'version' => $manifest->version, 'stats' => $manifest->stats, 'packages' => $manifest->packages];
	}

	public function cleanup(): void
	{
		Storage::deleteDirectory($this->dir);
	}
}
