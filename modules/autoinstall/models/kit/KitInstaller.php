<?php

namespace Zittme\Modules\Autoinstall\Models\Kit;

use Context;
use Zittme\Framework\Cache;
use Zittme\Framework\Config;
use Zittme\Framework\DB;
use Zittme\Framework\Storage;
use Zittme\Framework\Theme;
use Zittme\Modules\Autoinstall\Models\Installer as PackageInstaller;
use Zittme\Modules\Autoinstall\Models\Package;

/**
 * Previews and installs a starter kit in small steps. See docs/STARTER-KIT-SPEC.md chapter 6.
 */
class KitInstaller
{
	public const STEPS = ['packages', 'media', 'lang', 'menus', 'layouts', 'modules', 'menu_items', 'documents', 'module_data', 'configs', 'site', 'finish'];
	public const MEDIA_BATCH = 200;
	public const DOCUMENT_BATCH = 50;

	/**
	 * Validate a downloaded or uploaded ZIP and open a job for it.
	 *
	 * @return Job|string job or error lang key
	 */
	public static function prepare(string $zip_path, array $meta)
	{
		$inspect = Archive::inspect($zip_path);
		if (!$inspect['ok'])
		{
			return $inspect['error'];
		}
		$manifest = $inspect['manifest'];
		if (Manifest::validate($manifest))
		{
			return 'msg_kit_manifest_invalid';
		}
		$signature = Signature::verify($inspect['manifest_raw'], $inspect['signature']);
		if ($signature !== 'valid' && !($signature === 'unsigned' && Signature::allowUnsigned()))
		{
			return 'msg_kit_signature_' . $signature;
		}

		Job::protectWorkBase();
		$work = 'job_' . \Zittme\Framework\Security::getRandom(24, 'alnum');
		$path = Job::getWorkBase() . $work . '/';
		if (!Archive::extract($zip_path, $path, $inspect['entries']))
		{
			Storage::deleteDirectory($path);
			return 'msg_kit_extract_failed';
		}
		$manifest_hash = hash('sha256', (string)$inspect['manifest_raw']);
		if (!is_file($path . Archive::MANIFEST_FILE) || !hash_equals($manifest_hash, hash_file('sha256', $path . Archive::MANIFEST_FILE)))
		{
			Storage::deleteDirectory($path);
			return 'msg_kit_integrity_failed';
		}
		$meta['manifest_sha256'] = $manifest_hash;
		Job::protectDirectory($path);
		$meta['signature'] = $signature;
		return Job::create($manifest, $work, $meta);
	}

	public static function isPackagePresent(object $req): array
	{
		$name = basename((string)$req->install_path);
		$version = '';
		$present = false;
		switch ($req->type)
		{
			case 'module':
				$present = Storage::exists(\RX_BASEDIR . 'modules/' . $name . '/conf/info.xml');
				if ($present)
				{
					$info = \ModuleModel::getModuleInfoXml($name);
					$version = (string)($info->version ?? '');
				}
				break;
			case 'theme':
				$info = Theme::getInfo($name);
				$present = (bool)$info;
				$version = (string)($info->version ?? '');
				break;
			default:
				$file = Package::INFO_FILES[$req->type] ?? 'conf/info.xml';
				$present = Storage::exists(\RX_BASEDIR . ltrim(substr((string)$req->install_path, 2), '/') . '/' . $file);
				if ($present && preg_match('!<version>([^<]+)</version>!', (string)Storage::read(\RX_BASEDIR . ltrim(substr((string)$req->install_path, 2), '/') . '/' . $file), $m))
				{
					$version = trim($m[1]);
				}
		}
		return ['present' => $present, 'version' => $version, 'ok' => $present && Version::satisfies($version, (string)($req->version ?? ''))];
	}

	/**
	 * Filter HTML that came from a kit. The admin shortcut in the core insert functions is never relied on.
	 * With $keep_widgets, widget placeholder images of page modules are kept when they carry nothing executable.
	 */
	protected static function cleanHtml(string $html, bool $keep_widgets = false): string
	{
		if ($html === '' || strpos($html, '<') === false)
		{
			return $html;
		}
		$kept = [];
		if ($keep_widgets)
		{
			$html = preg_replace_callback('~<img\b[^>]*\bwidget="[a-zA-Z0-9_]+"[^>]*>~i', function ($m) use (&$kept) {
				$decoded = html_entity_decode(html_entity_decode($m[0], \ENT_QUOTES | \ENT_HTML5, 'UTF-8'), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
				if (preg_match('/\son[a-z]+\s*=|javascript\s*:|<\s*(script|iframe|object|embed)/i', $decoded))
				{
					return '';
				}
				$token = '[[KITWIDGET' . count($kept) . 'X' . substr(md5($m[0]), 0, 8) . ']]';
				$kept[$token] = $m[0];
				return $token;
			}, $html);
		}
		$html = \Zittme\Framework\Filters\HTMLFilter::clean($html, true);
		return $kept ? strtr($html, $kept) : $html;
	}

	protected static function cleanValue($value)
	{
		return Codec::mapStrings($value, function ($s) {
			return self::cleanHtml($s);
		});
	}

	protected static function isSafeColor(string $color): bool
	{
		return $color === '' || (bool)preg_match('/^#?[0-9a-f]{3,6}$/i', $color);
	}

	/**
	 * Returns the URL if it is http(s), relative or a mid after decoding and removing hidden characters, otherwise an empty string.
	 */
	protected static function safeMenuUrl(string $url): string
	{
		$url = trim($url);
		$check = $url;
		for ($i = 0; $i < 3; $i++)
		{
			$check = html_entity_decode(rawurldecode($check), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
		}
		$check = preg_replace('/[\x00-\x20\x7f\x{00a0}\x{200b}-\x{200d}\x{feff}]+/u', '', $check);
		if ($check === null || $check === '')
		{
			return '';
		}
		if (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $check))
		{
			return preg_match('!^https?://!i', $check) ? $url : '';
		}
		return $check[0] === '\\' ? '' : $url;
	}

	/**
	 * A layout or skin value: a plain name, or theme|@|name with both parts plain.
	 */
	protected static function isSafeComponentName(string $value, bool $allow_special = false): bool
	{
		if ($allow_special && in_array($value, ['/USE_DEFAULT/', '/USE_RESPONSIVE/'], true))
		{
			return true;
		}
		if (preg_match('/^[a-zA-Z0-9_]+$/', $value))
		{
			return true;
		}
		$split = Theme::split($value);
		return $split && preg_match('/^[a-zA-Z0-9_]+$/', $split['name']);
	}

	protected static function nextFreeMid(string $mid, array $taken = []): string
	{
		$base = preg_replace('/[^a-zA-Z0-9_]/', '', $mid) ?: 'kit';
		if (!preg_match('/^[a-zA-Z]/', $base))
		{
			$base = 'k' . $base;
		}
		$candidate = $base;
		$n = 1;
		while (\ModuleModel::isIDExists($candidate) || \Zittme\Framework\Router::isReservedPrefix($candidate) || in_array($candidate, $taken, true))
		{
			$n++;
			$candidate = $base . '_' . $n;
		}
		return $candidate;
	}

	/**
	 * Everything the preview screen shows.
	 */
	public static function preview(Job $job): object
	{
		$m = $job->getManifest();
		if (!$m)
		{
			throw new \Zittme\Framework\Exception('msg_kit_work_missing');
		}
		$p = new \stdClass;
		$p->manifest = $m;
		$p->blockers = [];
		$p->core_ok = Version::satisfies(Version::coreVersion(), (string)($m->requires->core ?? ''));
		if (!$p->core_ok)
		{
			$p->blockers[] = 'core';
		}
		if (!empty($m->requires->php) && !Version::satisfies(\PHP_VERSION, (string)$m->requires->php))
		{
			$p->blockers[] = 'php';
		}

		$p->packages = [];
		foreach ((array)($m->packages ?? []) as $req)
		{
			$local = self::isPackagePresent($req);
			$row = (object)['req' => $req, 'local' => (object)$local, 'action' => 'keep', 'store' => null, 'paid' => false, 'licensed' => true];
			if (!$local['ok'])
			{
				$row->action = $local['present'] ? 'update' : 'install';
				$pkg = Store::resolvePackage($req);
				$row->store = $pkg;
				if (!$pkg && self::isLocalOnly($req, $local))
				{
					$row->action = 'local';
				}
				elseif (!$pkg)
				{
					$row->action = 'unavailable';
					if (empty($req->optional))
					{
						$p->blockers[] = 'package';
					}
				}
				else
				{
					$row->paid = ($pkg->extra_vars->distribution ?? 'FREE') === 'PAID';
					$row->licensed = !$row->paid || $pkg->install_type === 'paid_licensed';
					if (!$row->licensed)
					{
						$p->blockers[] = 'license';
					}
					elseif (!Version::satisfies((string)$pkg->last_release_version, (string)($req->version ?? '')))
					{
						$row->action = 'unavailable';
						$p->blockers[] = 'package';
					}
				}
			}
			$p->packages[] = $row;
		}

		$lang = (array)$job->readData((string)($m->content->lang ?? ''));
		$current_lang = Context::getLangType();
		$display = function (string $value) use ($lang, $current_lang) {
			return preg_replace_callback('/\$user_lang->([a-zA-Z0-9_]+)/', function ($mm) use ($lang, $current_lang) {
				$values = (array)($lang[$mm[1]] ?? []);
				return (string)($values[$current_lang] ?? $values['en'] ?? reset($values) ?: $mm[1]);
			}, $value);
		};
		$p->title = Manifest::text($m->title ?? '');
		$p->description = Manifest::text($m->description ?? '');
		$p->modules = [];
		$taken = [];
		foreach ((array)$job->readData((string)($m->content->modules ?? '')) as $mod)
		{
			$new = self::nextFreeMid((string)$mod->mid, $taken);
			$taken[] = $new;
			$p->modules[] = (object)['ref' => $mod->ref, 'module' => $mod->module, 'mid' => $mod->mid, 'new_mid' => $new, 'renamed' => $new !== $mod->mid, 'title' => $display((string)$mod->browser_title)];
		}
		$p->menus = (array)$job->readData((string)($m->content->menus ?? ''));
		$p->layouts = (array)$job->readData((string)($m->content->layouts ?? ''));
		$p->stats = $m->stats ?? new \stdClass;

		$lang = (array)$job->readData((string)($m->content->lang ?? ''));
		$p->lang_new = 0;
		$p->lang_renamed = 0;
		foreach ($lang as $code => $values)
		{
			$existing = self::getLangValues((string)$code);
			if (!$existing)
			{
				$p->lang_new++;
			}
			elseif ($existing != (array)$values)
			{
				$p->lang_renamed++;
			}
		}

		$p->module_data = [];
		foreach ((array)($m->content->module_data ?? []) as $module => $path)
		{
			$data = $job->readData((string)$path);
			$rows = 0;
			foreach ((array)($data->tables ?? []) as $table => $list)
			{
				$rows += count((array)$list);
			}
			$p->module_data[] = (object)['module' => $module, 'rows' => $rows];
		}

		$enabled = array_keys(Context::loadLangSelected() ?: []);
		$p->locales_missing = array_values(array_diff((array)($m->locales->enabled ?? []), $enabled));
		$p->can_set_front = !empty($m->site->front);
		$p->site_skins = (array)($m->site->skins ?? []);
		$p->signature = $job->signature;
		$p->blockers = array_values(array_unique($p->blockers));
		return $p;
	}

	protected static function getLangValues(string $code): array
	{
		$out = [];
		$output = executeQueryArray('module.getLang', ['name' => $code]);
		foreach ($output->data ?: [] as $row)
		{
			$out[(string)$row->lang_code] = (string)$row->value;
		}
		return $out;
	}

	/**
	 * Run the current step for a limited time. Returns progress info.
	 */
	public static function run(Job $job): array
	{
		$manifest = $job->getManifest();
		if (!$manifest)
		{
			$job->status = Job::STATUS_FAILED;
			$job->message = 'msg_kit_work_missing';
			$job->save();
			return self::progress($job);
		}
		$job->status = Job::STATUS_RUNNING;
		$job->message = '';
		$ctx = new ImportContext($job, $manifest);

		\PointController::pauseContentTriggers();
		try
		{
			while ($ctx->hasTime() && $job->status === Job::STATUS_RUNNING)
			{
				$index = (int)$job->state->step;
				if ($index >= count(self::STEPS))
				{
					$job->status = Job::STATUS_DONE;
					break;
				}
				$step = self::STEPS[$index];
				$method = 'step' . str_replace(' ', '', ucwords(str_replace('_', ' ', $step)));
				$finished = self::$method($job, $manifest, $ctx);
				if ($finished)
				{
					$job->state->step = $index + 1;
					$job->state->cursor = 0;
					$job->state->sub = 0;
					$job->state->total = 0;
				}
				$job->save();
				if ($step === 'packages' && !$finished)
				{
					break;
				}
			}
		}
		catch (\Throwable $e)
		{
			$job->status = Job::STATUS_FAILED;
			$job->message = $e->getMessage();
		}
		finally
		{
			\PointController::resumeContentTriggers();
		}
		$job->save();
		return self::progress($job);
	}

	public static function progress(Job $job): array
	{
		$index = min((int)$job->state->step, count(self::STEPS));
		return [
			'job_srl' => $job->job_srl,
			'status' => $job->status,
			'step' => self::STEPS[$index] ?? 'done',
			'step_index' => $index,
			'step_count' => count(self::STEPS),
			'cursor' => (int)($job->state->cursor ?? 0),
			'total' => (int)($job->state->total ?? 0),
			'message' => $job->message ? (lang('autoinstall.' . $job->message) !== 'autoinstall.' . $job->message ? lang('autoinstall.' . $job->message) : $job->message) : '',
		];
	}

	protected static function stepPackages(Job $job, object $m, ImportContext $ctx): bool
	{
		$list = array_values((array)($m->packages ?? []));
		$ctx->setTotal(count($list));
		$i = $ctx->getCursor();
		if ($i >= count($list))
		{
			return true;
		}
		$req = $list[$i];
		$ref = (string)$req->install_path;
		if ($job->mapped('package', $ref) === null)
		{
			$local = self::isPackagePresent($req);
			if (!$local['ok'])
			{
				$package = Store::resolvePackage($req);
				if (!$package && self::isLocalOnly($req, $local))
				{
					if ($req->type === 'module')
					{
						self::installModule(basename($ref));
					}
				}
				elseif (!$package)
				{
					if (empty($req->optional))
					{
						throw new \RuntimeException(lang('autoinstall.msg_kit_package_unavailable') . ' ' . $ref);
					}
				}
				else
				{
					if (!$package->isInstallable('minimal'))
					{
						throw new \RuntimeException(lang('autoinstall.msg_autoinstall_package_not_installable') . ' ' . $ref);
					}
					$last_module_srl = self::getLastModuleSrl();
					$output = PackageInstaller::downloadPackage($package);
					if (!$output->toBool())
					{
						throw new \RuntimeException(lang($output->getMessage()) . ' ' . $ref);
					}
					\Zittme\Framework\Session::close();
					$output = PackageInstaller::installPackage($package, $local['present'] ? 'update' : 'install');
					\Zittme\Framework\Session::start();
					if (!$output->toBool())
					{
						throw new \RuntimeException(strip_tags(lang($output->getMessage())) . ' ' . $ref);
					}
					if ($req->type === 'module')
					{
						self::installModule(basename($ref));
					}
					if (!$local['present'])
					{
						foreach (self::getModuleSrlsAfter($last_module_srl) as $module_srl)
						{
							$job->record('package_module', '', (string)$module_srl);
						}
					}
					$job->record('package', $ref, $local['present'] ? 'updated' : 'installed', (object)['existed' => $local['present'], 'version' => $local['version'], 'type' => $req->type]);
				}
			}
			elseif ($req->type === 'module')
			{
				self::installModule(basename($ref));
			}
		}
		$ctx->setCursor($i + 1);
		return $i + 1 >= count($list);
	}

	/**
	 * A requirement exported with package_srl 0 is not in any store yet.
	 * If this site already has a copy, use it even when the version differs.
	 */
	protected static function isLocalOnly(object $req, array $local): bool
	{
		return (int)($req->package_srl ?? 0) === 0 && !empty($local['present']);
	}

	/**
	 * The largest module_srl on this site, so that module instances created by
	 * installing a package can be told apart from the ones that were already there.
	 */
	protected static function getLastModuleSrl(): int
	{
		$stmt = DB::getInstance()->query('SELECT MAX(module_srl) AS m FROM modules');
		$row = $stmt ? ($stmt->fetchAll(\PDO::FETCH_ASSOC)[0] ?? []) : [];
		return (int)($row['m'] ?? 0);
	}

	/**
	 * @return int[] module_srls created after getLastModuleSrl() returned $last
	 */
	protected static function getModuleSrlsAfter(int $last): array
	{
		$stmt = DB::getInstance()->query('SELECT module_srl FROM modules WHERE module_srl > ? ORDER BY module_srl', [$last]);
		return array_map('intval', $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : []);
	}

	public static function installModule(string $module): void
	{
		if (!preg_match('/^[a-z0-9_]+$/', $module))
		{
			return;
		}
		$class = \ModuleModel::getModuleInstallClass($module);
		$oInstall = \InstallAdminController::getInstance();
		Context::set('module_name', $module);
		$need_install = $class && method_exists($class, 'checkUpdate') && self::needsInstall($module);
		if ($need_install)
		{
			$oInstall->procInstallAdminInstall();
		}
		$class = \ModuleModel::getModuleInstallClass($module);
		if ($class && method_exists($class, 'checkUpdate') && $class->checkUpdate())
		{
			$oInstall->procInstallAdminUpdate();
		}
	}

	protected static function needsInstall(string $module): bool
	{
		$schemas = glob(\RX_BASEDIR . 'modules/' . $module . '/schemas/*.xml') ?: [];
		$oDB = DB::getInstance();
		foreach ($schemas as $schema)
		{
			if (!$oDB->isTableExists(basename($schema, '.xml')))
			{
				return true;
			}
		}
		return false;
	}

	protected static function stepMedia(Job $job, object $m, ImportContext $ctx): bool
	{
		$source = $job->getWorkPath() . 'media/';
		$list_file = $job->getWorkPath() . '_media_list.json';
		$list = Storage::exists($list_file) ? json_decode((string)Storage::read($list_file), true) : null;
		if (!is_array($list))
		{
			$list = [];
			if (Storage::isDirectory($source))
			{
				$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
				foreach ($it as $file)
				{
					if ($file->isFile())
					{
						$list[] = substr(str_replace('\\', '/', $file->getPathname()), strlen($source));
					}
				}
			}
			sort($list);
			Storage::write($list_file, json_encode($list));
		}
		$ctx->setTotal(count($list));
		$target_base = \RX_BASEDIR . $job->getMediaBase();
		if ($job->mapped('media_dir', 'media') === null)
		{
			Storage::createDirectory($target_base);
			$job->record('media_dir', 'media', $job->getMediaBase());
		}
		$i = $ctx->getCursor();
		$end = min(count($list), $i + self::MEDIA_BATCH);
		for (; $i < $end; $i++)
		{
			$rel = $list[$i];
			if (Archive::normalizeEntryName('media/' . $rel) === null || !Archive::isAllowedFile($rel))
			{
				continue;
			}
			Storage::copy($source . $rel, $target_base . $rel);
			if (!$ctx->hasTime())
			{
				$i++;
				break;
			}
		}
		$ctx->setCursor($i);
		return $i >= count($list);
	}

	protected static function stepLang(Job $job, object $m, ImportContext $ctx): bool
	{
		$lang = (array)$job->readData((string)($m->content->lang ?? ''));
		foreach ($lang as $code => $values)
		{
			$code = (string)$code;
			if (!preg_match('/^[a-zA-Z0-9_]+$/', $code) || $job->mapped('lang', $code) !== null || $job->mapped('lang_same', $code) !== null)
			{
				continue;
			}
			$values = array_map('strval', (array)$values);
			$existing = self::getLangValues($code);
			$name = $code;
			if ($existing)
			{
				$rewritten = [];
				foreach ($values as $lc => $v)
				{
					$rewritten[$lc] = $ctx->rewrite($v);
				}
				if ($existing == $rewritten)
				{
					$job->record('lang_same', $code, $code);
					continue;
				}
				$name = substr($code, 0, 70) . '_' . $job->kit_id;
				$n = 1;
				while (self::getLangValues($name))
				{
					$n++;
					$name = substr($code, 0, 70) . '_' . $job->kit_id . $n;
				}
			}
			$job->record('lang', $code, $name);
		}
		foreach ($lang as $code => $values)
		{
			$name = $job->mapped('lang', (string)$code);
			if ($name === null || self::getLangValues($name))
			{
				continue;
			}
			foreach ((array)$values as $lc => $value)
			{
				if (!preg_match('/^[a-zA-Z\-]+$/', (string)$lc))
				{
					continue;
				}
				executeQuery('module.insertLang', ['name' => $name, 'lang_code' => $lc, 'value' => $ctx->rewrite((string)$value)]);
			}
		}
		\ModuleAdminController::getInstance()->makeCacheDefinedLangCode();
		return true;
	}

	protected static function stepMenus(Job $job, object $m, ImportContext $ctx): bool
	{
		foreach ((array)$job->readData((string)($m->content->menus ?? '')) as $menu)
		{
			if ($job->mapped('menu', (string)$menu->ref) !== null)
			{
				continue;
			}
			$srl = getNextSequence();
			$output = executeQuery('menu.insertMenu', ['menu_srl' => $srl, 'site_srl' => 0, 'title' => $ctx->rewrite((string)$menu->title), 'listorder' => $srl * -1]);
			if (!$output->toBool())
			{
				throw new \RuntimeException($output->getMessage());
			}
			$job->record('menu', (string)$menu->ref, (string)$srl);
		}
		return true;
	}

	protected static function stepLayouts(Job $job, object $m, ImportContext $ctx): bool
	{
		foreach ((array)$job->readData((string)($m->content->layouts ?? '')) as $layout)
		{
			if ($job->mapped('layout', (string)$layout->ref) !== null)
			{
				continue;
			}
			if (!self::isSafeComponentName((string)$layout->layout))
			{
				throw new \RuntimeException(lang('autoinstall.msg_kit_manifest_invalid') . ' ' . (string)$layout->ref);
			}
			$vars = Codec::unpack(self::cleanValue($ctx->rewriteValue($layout->extra_vars ?? new \stdClass)));
			if (!is_object($vars))
			{
				$vars = new \stdClass;
			}
			foreach ((array)($layout->menu_vars ?? []) as $key => $ref)
			{
				$vars->{$key} = (int)($job->mapped('menu', (string)$ref) ?? 0);
			}
			$srl = getNextSequence();
			$args = new \stdClass;
			$args->layout_srl = $srl;
			$args->site_srl = 0;
			$args->layout = (string)$layout->layout;
			$args->title = (string)$layout->title;
			$args->layout_type = in_array($layout->layout_type ?? 'P', ['P', 'M'], true) ? $layout->layout_type : 'P';
			$args->extra_vars = serialize($vars);
			$output = \LayoutAdminController::getInstance()->insertLayout($args);
			if (!$output->toBool())
			{
				throw new \RuntimeException($output->getMessage());
			}
			$job->record('layout', (string)$layout->ref, (string)$srl);
			Cache::delete('layout:' . $srl);
		}
		return true;
	}

	protected static function mapGroups(array $symbols, string $permission = ''): array
	{
		static $groups = null;
		if ($groups === null)
		{
			$groups = ['guest' => 0, 'member' => -1, 'site' => -2, 'manager' => -3];
			$output = executeQueryArray('member.getGroups', []);
			foreach ($output->data ?: [] as $g)
			{
				if ($g->is_admin === 'Y' && !isset($groups['admin_group']))
				{
					$groups['admin_group'] = (int)$g->group_srl;
				}
				if ($g->is_default === 'Y' && !isset($groups['default_group']))
				{
					$groups['default_group'] = (int)$g->group_srl;
				}
			}
		}
		$admin_only = (bool)preg_match('/(^|_)(manager|manage|admin|administrator)(_|$)/i', $permission);
		$out = [];
		foreach ($symbols as $sym)
		{
			if (!is_string($sym) || !isset($groups[$sym]))
			{
				continue;
			}
			if ($admin_only && $sym !== 'admin_group')
			{
				continue;
			}
			$out[] = $groups[$sym];
		}
		return $out;
	}

	protected static function stepModules(Job $job, object $m, ImportContext $ctx): bool
	{
		$oModuleController = \ModuleController::getInstance();
		foreach ((array)$job->readData((string)($m->content->modules ?? '')) as $mod)
		{
			$ref = (string)$mod->ref;
			if ($job->mapped('module', $ref) !== null)
			{
				continue;
			}
			if (!preg_match('/^[a-z0-9_]+$/', (string)$mod->module) || !\ModuleModel::getModuleInfoXml($mod->module))
			{
				throw new \RuntimeException(lang('autoinstall.msg_kit_module_missing') . ' ' . $mod->module);
			}
			$args = new \stdClass;
			$args->module = (string)$mod->module;
			$args->mid = self::nextFreeMid((string)$mod->mid);
			$args->browser_title = (string)$ctx->rewrite((string)$mod->browser_title);
			$args->description = self::cleanHtml($ctx->rewrite((string)($mod->description ?? '')));
			$keep_widgets = $mod->module === 'page' && strtoupper((string)($mod->extra_vars->page_type ?? '')) === 'WIDGET';
			$args->content = self::cleanHtml($ctx->rewrite((string)($mod->content ?? '')), $keep_widgets);
			$args->mcontent = self::cleanHtml($ctx->rewrite((string)($mod->mcontent ?? '')), $keep_widgets);
			$args->layout_srl = $mod->layout ? (int)($job->mapped('layout', (string)$mod->layout) ?? -1) : -1;
			$args->mlayout_srl = $mod->mlayout ? (int)($job->mapped('layout', (string)$mod->mlayout) ?? -1) : -1;
			$args->skin = self::isSafeComponentName((string)$mod->skin, true) ? (string)$mod->skin : '/USE_DEFAULT/';
			$args->mskin = self::isSafeComponentName((string)$mod->mskin, true) ? (string)$mod->mskin : '/USE_DEFAULT/';
			$args->is_skin_fix = $args->skin === '/USE_DEFAULT/' ? 'N' : 'Y';
			$args->is_mskin_fix = in_array($args->mskin, ['/USE_DEFAULT/', '/USE_RESPONSIVE/'], true) ? 'N' : 'Y';
			if (!empty($mod->use_mobile))
			{
				$args->use_mobile = (string)$mod->use_mobile;
			}
			$args->open_rss = (string)($mod->open_rss ?? '') ?: null;
			foreach (['header_text', 'footer_text', 'mheader_text', 'mfooter_text'] as $col)
			{
				$args->{$col} = self::cleanHtml($ctx->rewrite((string)($mod->{$col} ?? '')));
			}
			$args->menu_srl = $mod->menu ? (int)($job->mapped('menu', (string)$mod->menu) ?? 0) : 0;
			$args->site_srl = 0;
			$args->isMenuCreate = false;
			$extra = Codec::unpack(self::cleanValue($ctx->rewriteValue($mod->extra_vars ?? new \stdClass)));
			$reserved = $ctx->getColumns('modules');
			foreach ((array)$extra as $key => $val)
			{
				if (!isset($args->{$key}) && preg_match('/^[a-zA-Z0-9_]+$/', (string)$key) && !in_array($key, $reserved, true) && $key !== 'isMenuCreate')
				{
					$args->{$key} = $val;
				}
			}
			$output = $oModuleController->insertModule($args);
			if (!$output->toBool())
			{
				throw new \RuntimeException(lang($output->getMessage()) . ' ' . $args->mid);
			}
			$srl = (int)$output->get('module_srl');
			$job->record('module', $ref, (string)$srl);

			foreach ((array)($mod->part_config ?? []) as $pmodule => $config)
			{
				if (preg_match('/^[a-z0-9_]+$/', (string)$pmodule) && (in_array($pmodule, ['editor', 'file'], true) || (!in_array($pmodule, Exporter::CONFIG_BLOCKED_MODULES, true) && \ModuleModel::getModuleInfoXml($pmodule))))
				{
					$oModuleController->insertModulePartConfig($pmodule, $srl, Exporter::stripSecrets(Codec::unpack(self::cleanValue($ctx->rewriteValue($config)))));
				}
			}
			$grants = new \stdClass;
			foreach ((array)($mod->grants ?? []) as $name => $symbols)
			{
				if (preg_match('/^[a-zA-Z0-9_]+$/', (string)$name))
				{
					$grants->{$name} = self::mapGroups((array)$symbols, (string)$name);
				}
			}
			if (countobj($grants))
			{
				$oModuleController->insertModuleGrants($srl, $grants);
			}

			$oDocumentController = \DocumentController::getInstance();
			foreach ((array)($mod->categories ?? []) as $cat)
			{
				$cargs = new \stdClass;
				$cargs->category_srl = getNextSequence();
				$cargs->module_srl = $srl;
				$cargs->parent_srl = $cat->parent ? (int)($job->mapped('category', (string)$cat->parent) ?? 0) : 0;
				$cargs->title = $ctx->rewrite((string)$cat->title);
				$cargs->description = $ctx->rewrite((string)($cat->description ?? ''));
				$cargs->color = self::isSafeColor((string)($cat->color ?? '')) ? (string)($cat->color ?? '') : '';
				$cargs->expand = (string)($cat->expand ?? 'N');
				$cargs->list_order = $cargs->category_srl;
				$coutput = $oDocumentController->insertCategory($cargs);
				if ($coutput->toBool())
				{
					$job->record('category', (string)$cat->ref, (string)$cargs->category_srl);
				}
			}
			if (!empty($mod->categories))
			{
				$oDocumentController->makeCategoryFile($srl);
			}
		}
		return true;
	}

	protected static function stepMenuItems(Job $job, object $m, ImportContext $ctx): bool
	{
		$touched = [];
		foreach ((array)$job->readData((string)($m->content->menus ?? '')) as $menu)
		{
			$menu_srl = (int)($job->mapped('menu', (string)$menu->ref) ?? 0);
			if (!$menu_srl)
			{
				continue;
			}
			$touched[] = $menu_srl;
			foreach ((array)$menu->items as $item)
			{
				if ($job->mapped('menu_item', (string)$item->ref) !== null)
				{
					continue;
				}
				$url = '';
				if (!empty($item->module))
				{
					$url = $ctx->mid((string)$item->module);
					if ($url === '')
					{
						continue;
					}
				}
				else
				{
					$url = self::safeMenuUrl((string)$item->url);
					if ($url === '')
					{
						continue;
					}
				}
				$groups = [];
				foreach ((array)($item->group_srls ?? []) as $sym)
				{
					$map = ['member' => -1, 'manager' => -3, 'not_member' => -4];
					if (isset($map[$sym]))
					{
						$groups[] = $map[$sym];
					}
				}
				$srl = getNextSequence();
				$args = new \stdClass;
				$args->menu_item_srl = $srl;
				$args->menu_srl = $menu_srl;
				$args->parent_srl = $item->parent ? (int)($job->mapped('menu_item', (string)$item->parent) ?? 0) : 0;
				$args->name = $ctx->rewrite((string)$item->name);
				$args->url = $url;
				$args->is_shortcut = ($item->is_shortcut ?? 'N') === 'Y' ? 'Y' : 'N';
				$args->open_window = ($item->open_window ?? 'N') === 'Y' ? 'Y' : 'N';
				$args->expand = ($item->expand ?? 'N') === 'Y' ? 'Y' : 'N';
				$args->icon = $ctx->rewrite((string)($item->icon ?? ''));
				$args->class = preg_replace('/[^a-z0-9\x20_-]/', '', (string)($item->class ?? ''));
				$args->desc = $ctx->rewrite((string)($item->desc ?? ''));
				foreach (['normal_btn', 'hover_btn', 'active_btn'] as $btn)
				{
					if (!empty($item->{$btn}))
					{
						$args->{$btn} = $ctx->rewrite((string)$item->{$btn});
					}
				}
				$args->group_srls = implode(',', $groups);
				$args->listorder = isset($item->listorder) ? (int)$item->listorder : -1 * $srl;
				$output = executeQuery('menu.insertMenuItem', $args);
				if (!$output->toBool())
				{
					throw new \RuntimeException($output->getMessage());
				}
				$job->record('menu_item', (string)$item->ref, (string)$srl);
			}
		}
		$oMenuAdminController = \MenuAdminController::getInstance();
		foreach (array_unique($touched) as $menu_srl)
		{
			$oMenuAdminController->makeXmlFile($menu_srl);
		}

		foreach ((array)$job->readData((string)($m->content->layouts ?? '')) as $layout)
		{
			$srl = (int)($job->mapped('layout', (string)$layout->ref) ?? 0);
			if (!$srl || (!countobj($layout->mid_vars ?? null) && !countobj($layout->url_vars ?? null)))
			{
				continue;
			}
			$row = executeQuery('layout.getLayout', ['layout_srl' => $srl])->data;
			if (!$row)
			{
				continue;
			}
			$vars = $row->extra_vars ? unserialize($row->extra_vars, ['allowed_classes' => ['stdClass']]) : new \stdClass;
			if (!is_object($vars))
			{
				$vars = new \stdClass;
			}
			foreach ((array)($layout->mid_vars ?? []) as $key => $ref)
			{
				$mid = $ctx->mid((string)$ref);
				if ($mid !== '')
				{
					$vars->{$key} = $mid;
				}
			}
			foreach ((array)($layout->url_vars ?? []) as $key => $spec)
			{
				$mid = $ctx->mid((string)($spec->module ?? ''));
				if ($mid !== '')
				{
					$vars->{$key} = '/' . $mid . (string)($spec->suffix ?? '');
				}
			}
			executeQuery('layout.updateLayout', ['layout_srl' => $srl, 'extra_vars' => serialize($vars)]);
			Cache::delete('layout:' . $srl);
		}
		return true;
	}

	protected static function getAdminInfo(int $member_srl): ?object
	{
		$info = $member_srl ? \MemberModel::getMemberInfoByMemberSrl($member_srl) : null;
		return ($info && !empty($info->member_srl)) ? $info : null;
	}

	protected static function importComments(ImportContext $ctx, int $module_srl, int $document_srl, array $comments): void
	{
		if (!$comments)
		{
			return;
		}
		$oCommentController = \CommentController::getInstance();
		$map = [];
		foreach ($comments as $c)
		{
			$parent_ref = (string)($c->parent ?? '');
			if ($parent_ref !== '' && !isset($map[$parent_ref]))
			{
				continue;
			}
			$obj = new \stdClass;
			$obj->comment_srl = getNextSequence();
			$obj->module_srl = $module_srl;
			$obj->document_srl = $document_srl;
			$obj->parent_srl = $parent_ref !== '' ? $map[$parent_ref] : 0;
			$obj->content = self::cleanHtml($ctx->rewrite((string)($c->content ?? '')));
			$obj->nick_name = trim((string)($c->nick_name ?? '')) ?: 'guest';
			$obj->member_srl = 0;
			$obj->user_id = '';
			$obj->user_name = '';
			$obj->email_address = '';
			$obj->homepage = '';
			$obj->password = \Zittme\Framework\Security::getRandom(24);
			$obj->is_secret = 'N';
			$obj->notify_message = 'N';
			$obj->status = 1;
			$obj->regdate = preg_match('/^\d{14}$/', (string)($c->regdate ?? '')) ? $c->regdate : date('YmdHis');
			$obj->last_update = preg_match('/^\d{14}$/', (string)($c->last_update ?? '')) ? $c->last_update : $obj->regdate;
			$output = $oCommentController->insertComment($obj, true, false);
			if (!$output->toBool())
			{
				continue;
			}
			$comment_srl = (int)($output->get('comment_srl') ?: $obj->comment_srl);
			$map[(string)$c->ref] = $comment_srl;
			$votes = [max(0, (int)($c->voted_count ?? 0)), min(0, (int)($c->blamed_count ?? 0))];
			if ($votes[0] || $votes[1])
			{
				DB::getInstance()->query('UPDATE comments SET voted_count = ?, blamed_count = ? WHERE comment_srl = ?', [$votes[0], $votes[1], $comment_srl]);
			}
		}
	}

	protected static function stepDocuments(Job $job, object $m, ImportContext $ctx): bool
	{
		$docs = array_values((array)$job->readData((string)($m->content->documents ?? '')));
		$files_by_doc = [];
		foreach ((array)$job->readData((string)($m->content->files ?? '')) as $file)
		{
			$files_by_doc[(string)$file->document][] = $file;
		}
		$ctx->setTotal(count($docs));
		$admin = self::getAdminInfo($ctx->member_srl);
		$oDocumentController = \DocumentController::getInstance();
		$oFileController = \FileController::getInstance();
		$i = $ctx->getCursor();
		$end = min(count($docs), $i + self::DOCUMENT_BATCH);
		for (; $i < $end; $i++)
		{
			if (!$ctx->hasTime())
			{
				break;
			}
			$doc = $docs[$i];
			$ref = (string)$doc->ref;
			if ($job->mapped('document', $ref) !== null)
			{
				continue;
			}
			$module_srl = $ctx->moduleSrl((string)$doc->module);
			if (!$module_srl)
			{
				continue;
			}
			$document_srl = getNextSequence();
			$uploaded = 0;
			$cover_files = [];
			foreach ($files_by_doc[$ref] ?? [] as $file)
			{
				$rel = (string)$file->media;
				$source = $job->getWorkPath() . 'media/' . $rel;
				if (Archive::normalizeEntryName('media/' . $rel) === null || !Storage::isFile($source))
				{
					continue;
				}
				$foutput = $oFileController->insertFile(['tmp_name' => $source, 'name' => (string)$file->source_filename], $module_srl, $document_srl, 0, true);
				if ($foutput->toBool())
				{
					$uploaded++;
					if (($file->cover_image ?? 'N') === 'Y')
					{
						$cover_files[] = (int)$foutput->get('file_srl');
					}
					$job->record('file', (string)$file->ref, (string)$foutput->get('file_srl'));
					$job->record('filepath', $rel, (string)$foutput->get('uploaded_filename'));
				}
			}

			$obj = new \stdClass;
			$obj->document_srl = $document_srl;
			$obj->module_srl = $module_srl;
			$obj->category_srl = $doc->category ? (int)($job->mapped('category', (string)$doc->category) ?? 0) : 0;
			$obj->lang_code = (string)($doc->lang_code ?? '') ?: Context::getLangType();
			$obj->is_notice = ($doc->is_notice ?? 'N') === 'Y' ? 'Y' : 'N';
			$obj->title = $ctx->rewrite((string)$doc->title);
			$obj->title_bold = (string)($doc->title_bold ?? 'N');
			$obj->title_color = self::isSafeColor((string)($doc->title_color ?? '')) ? (string)($doc->title_color ?? '') : '';
			$obj->content = self::cleanHtml($ctx->rewrite((string)$doc->content));
			$obj->tags = (string)($doc->tags ?? '');
			$obj->comment_status = (string)($doc->comment_status ?? 'ALLOW');
			$obj->status = 'PUBLIC';
			$obj->allow_trackback = 'N';
			$obj->notify_message = 'N';
			$obj->regdate = preg_match('/^\d{14}$/', (string)($doc->regdate ?? '')) ? $doc->regdate : date('YmdHis');
			$obj->last_update = preg_match('/^\d{14}$/', (string)($doc->last_update ?? '')) ? $doc->last_update : $obj->regdate;
			$obj->member_srl = $admin ? (int)$admin->member_srl : 0;
			$obj->user_id = $admin ? (string)$admin->user_id : '';
			$obj->user_name = $admin ? (string)$admin->user_name : '';
			$obj->nick_name = $admin ? (string)$admin->nick_name : 'admin';
			$obj->email_address = $admin ? (string)$admin->email_address : '';
			$obj->homepage = '';
			$obj->uploaded_count = $uploaded;
			$output = $oDocumentController->insertDocument($obj, true);
			if (!$output->toBool())
			{
				throw new \RuntimeException(lang($output->getMessage()) . ' ' . $ref);
			}
			$job->record('document', $ref, (string)$document_srl);
			if (!empty($doc->extra_vars))
			{
				$doc_extra = $ctx->rewriteSerialized((string)$doc->extra_vars);
				if ($doc_extra)
				{
					DB::getInstance()->query('UPDATE documents SET extra_vars = ? WHERE document_srl = ?', [$doc_extra, $document_srl]);
				}
			}
			foreach ((array)($doc->document_extra_vars ?? []) as $ev)
			{
				\DocumentController::insertDocumentExtraVar($module_srl, $document_srl, (int)$ev->idx, self::cleanHtml($ctx->rewrite((string)$ev->value)), (string)($ev->eid ?? '') ?: null, (string)($ev->lang ?? '') ?: null);
			}
			self::importComments($ctx, $module_srl, $document_srl, (array)($doc->comments ?? []));
			$counts = [max(0, (int)($doc->voted_count ?? 0)), max(0, (int)($doc->readed_count ?? 0))];
			if ($counts[0] || $counts[1])
			{
				DB::getInstance()->query('UPDATE documents SET voted_count = ?, readed_count = ? WHERE document_srl = ?', [$counts[0], $counts[1], $document_srl]);
			}
			if ($uploaded)
			{
				$oFileController->setFilesValid($document_srl, 'doc');
				DB::getInstance()->query('UPDATE documents SET uploaded_count = ? WHERE document_srl = ?', [$uploaded, $document_srl]);
				foreach ($cover_files as $cover_srl)
				{
					DB::getInstance()->query("UPDATE files SET cover_image = 'Y' WHERE file_srl = ?", [$cover_srl]);
				}
			}
		}
		$ctx->setCursor($i);
		if ($i < count($docs))
		{
			return false;
		}

		foreach ((array)$job->readData((string)($m->content->modules ?? '')) as $mod)
		{
			$srl = $ctx->moduleSrl((string)$mod->ref);
			if (!$srl || !countobj($mod->document_refs ?? null))
			{
				continue;
			}
			$vars = new \stdClass;
			foreach ((array)$mod->document_refs as $key => $doc_ref)
			{
				$doc_srl = (int)($job->mapped('document', (string)$doc_ref) ?? 0);
				if ($doc_srl)
				{
					$vars->{$key} = $doc_srl;
				}
			}
			if (countobj($vars))
			{
				$current = \ModuleModel::getModuleExtraVars($srl)[$srl] ?? new \stdClass;
				foreach ($vars as $key => $val)
				{
					$current->{$key} = $val;
				}
				\ModuleController::getInstance()->insertModuleExtraVars($srl, $current);
				Cache::clearGroup('site_and_module');
			}
		}
		return true;
	}

	protected static function stepModuleData(Job $job, object $m, ImportContext $ctx): bool
	{
		$modules = Exporter::sortImporters(array_keys((array)($m->content->module_data ?? [])));
		$sub = (int)($job->state->sub ?? 0);
		while ($sub < count($modules))
		{
			$module = $modules[$sub];
			$importer = Exporter::getImporter($module);
			if (!$importer)
			{
				throw new \RuntimeException(lang('autoinstall.msg_kit_importer_missing') . ' ' . $module);
			}
			$path = (string)$m->content->module_data->{$module};
			$data = json_decode(json_encode($job->readData($path)), true) ?: [];
			if (!$importer->importKit($data, $ctx))
			{
				return false;
			}
			$sub++;
			$job->state->sub = $sub;
			$job->state->cursor = 0;
			$job->save();
			if (!$ctx->hasTime())
			{
				return $sub >= count($modules);
			}
		}
		return true;
	}

	protected static function stepConfigs(Job $job, object $m, ImportContext $ctx): bool
	{
		$configs = $job->readData((string)($m->content->configs ?? ''));
		foreach ((array)$configs as $module => $config)
		{
			if (!preg_match('/^[a-z0-9_]+$/', (string)$module) || in_array($module, Exporter::CONFIG_BLOCKED_MODULES, true))
			{
				continue;
			}
			if ($job->mapped('config', (string)$module) !== null)
			{
				continue;
			}
			$current = \ModuleModel::getModuleConfig($module);
			if ($current && countobj($current))
			{
				continue;
			}
			$value = Codec::unpack(self::cleanValue($ctx->rewriteValue($config)));
			$value = Exporter::stripSecrets($value);
			\ModuleController::getInstance()->insertModuleConfig($module, $value);
			$job->record('config', (string)$module, (string)$module, null);
		}
		return true;
	}

	protected static function readDesignRaw(): string
	{
		$file = \RX_BASEDIR . 'files/site_design/design_0.php';
		return Storage::exists($file) ? (string)Storage::read($file) : '';
	}

	protected static function stepSite(Job $job, object $m, ImportContext $ctx): bool
	{
		$opt = $job->options;
		if (!empty($opt->set_front) && !empty($m->site->front) && $job->mapped('setting', 'front') === null)
		{
			$srl = $ctx->moduleSrl((string)$m->site->front);
			$row = DB::getInstance()->query("SELECT domain_srl, index_module_srl FROM domains WHERE is_default_domain = 'Y'");
			$current = $row ? ($row->fetchAll(\PDO::FETCH_ASSOC)[0] ?? null) : null;
			if ($srl && $current)
			{
				$job->record('setting', 'front', (string)$current['domain_srl'], (object)['index_module_srl' => (int)$current['index_module_srl']]);
				DB::getInstance()->query('UPDATE domains SET index_module_srl = ? WHERE domain_srl = ?', [$srl, (int)$current['domain_srl']]);
				Cache::clearGroup('site_and_module');
			}
		}
		if (!empty($opt->set_front) && $job->mapped('setting', 'design') === null)
		{
			$layout_srl = !empty($m->site->layout) ? (int)($job->mapped('layout', (string)$m->site->layout) ?? 0) : 0;
			$mlayout_srl = !empty($m->site->mlayout) ? (int)($job->mapped('layout', (string)$m->site->mlayout) ?? 0) : 0;
			if ($layout_srl || $mlayout_srl)
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
				$job->record('setting', 'design', 'files/site_design/design_0.php', (object)['raw' => self::readDesignRaw(), 'info' => unserialize(serialize($designInfo), ['allowed_classes' => ['stdClass']])]);
				$designInfo->layout_srl = $layout_srl ?: ($designInfo->layout_srl ?? 0);
				$designInfo->mlayout_srl = $mlayout_srl ?: ($designInfo->mlayout_srl ?? 0);
				\Zittme\Modules\Admin\Controllers\Design::getInstance()->makeDefaultDesignFile($designInfo);
			}
		}
		if (!empty($opt->apply_site_skins))
		{
			foreach ((array)($m->site->skins ?? []) as $module => $skin)
			{
				if (!in_array($module, ['member', 'communication'], true) || !Theme::split((string)$skin) || !self::isSafeComponentName((string)$skin) || $job->mapped('setting', 'skin:' . $module) !== null)
				{
					continue;
				}
				$split = Theme::split((string)$skin);
				if (!Theme::getInfo($split['theme']))
				{
					continue;
				}
				$config = \ModuleModel::getModuleConfig($module) ?: new \stdClass;
				$job->record('setting', 'skin:' . $module, $module, (object)['skin' => $config->skin ?? null]);
				$config->skin = (string)$skin;
				\ModuleController::getInstance()->insertModuleConfig($module, $config);
			}
		}
		if (!empty($opt->enable_locales) && $job->mapped('setting', 'locales') === null)
		{
			$enabled = (array)Config::get('locale.enabled_lang');
			$wanted = array_values(array_unique(array_merge($enabled, array_filter((array)($m->locales->enabled ?? []), function ($l) {
				return is_string($l) && preg_match('/^[a-zA-Z\-]{2,8}$/', $l);
			}))));
			$supported = array_keys(\Zittme\Framework\Lang::getSupportedList());
			$wanted = array_values(array_intersect($wanted, $supported));
			if ($wanted != $enabled)
			{
				$job->record('setting', 'locales', 'locale.enabled_lang', (object)['enabled' => $enabled]);
				Config::set('locale.enabled_lang', $wanted);
				Config::save();
			}
		}
		return true;
	}

	protected static function stepFinish(Job $job, object $m, ImportContext $ctx): bool
	{
		Cache::clearGroup('site_and_module');
		Cache::clearGroup('menu');
		\ModuleAdminController::getInstance()->makeCacheDefinedLangCode();
		Storage::deleteDirectory($job->getWorkPath());
		return true;
	}
}
