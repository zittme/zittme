<?php

namespace Zittme\Modules\Autoinstall\Models\Kit;

use Zittme\Framework\Cache;
use Zittme\Framework\Config;
use Zittme\Framework\DB;
use Zittme\Framework\Storage;
use Zittme\Framework\Theme;

/**
 * Removes what a kit added and restores what it changed (installation snapshot).
 * A full rollback also removes packages that the kit installed.
 */
class Remover
{
	public const ORDER = ['setting', 'config', 'row', 'document', 'file', 'filepath', 'category', 'menu_item', 'module', 'menu', 'layout', 'lang', 'lang_same', 'media_dir', 'package_module', 'package'];

	/**
	 * Kinds handled only by a full rollback: packages the kit installed, and
	 * the module instances those packages created while being installed.
	 */
	public const FULL_ONLY = ['package_module', 'package'];

	/**
	 * Counts per kind, for the confirmation screen.
	 */
	public static function summary(Job $job, bool $full): array
	{
		$counts = $job->countItems();
		if (isset($counts['package_module']))
		{
			if ($full)
			{
				$counts['module'] = ($counts['module'] ?? 0) + $counts['package_module'];
			}
			unset($counts['package_module']);
		}
		if (!$full)
		{
			unset($counts['package']);
		}
		unset($counts['filepath'], $counts['lang_same']);
		return $counts;
	}

	public static function run(Job $job, bool $full, float $budget = 8.0): array
	{
		$deadline = microtime(true) + $budget;
		$job->status = Job::STATUS_REMOVING;
		$job->options->removal = $full ? 'rollback' : 'remove';
		$job->save();
		$kept = (array)($job->options->kept ?? []);

		$items = $job->getItems();
		$groups = [];
		foreach ($items as $item)
		{
			$groups[$item->kind][] = $item;
		}
		\PointController::pauseContentTriggers();
		try
		{
			foreach (self::ORDER as $kind)
			{
				if (in_array($kind, self::FULL_ONLY, true) && !$full)
				{
					continue;
				}
				$list = array_reverse($groups[$kind] ?? []);
				foreach ($list as $item)
				{
					if (microtime(true) > $deadline)
					{
						$job->options->kept = $kept;
						$job->save();
						return ['status' => $job->status, 'left' => count($job->getItems())];
					}
					$reason = self::removeItem($job, $item);
					if ($reason !== '')
					{
						$kept[] = ($item->kind === 'package' ? $item->ref : $item->target) . ': ' . (lang('autoinstall.kit_kept_reason')[$reason] ?? $reason);
					}
					$job->deleteItem((int)$item->item_srl);
				}
			}
		}
		catch (\Throwable $e)
		{
			$job->status = Job::STATUS_FAILED;
			$job->message = $e->getMessage();
			$job->save();
			return ['status' => $job->status, 'left' => count($job->getItems()), 'message' => $job->message];
		}
		finally
		{
			\PointController::resumeContentTriggers();
		}

		\ModuleAdminController::getInstance()->makeCacheDefinedLangCode();
		Cache::clearGroup('site_and_module');
		Cache::clearGroup('menu');
		$job->options->kept = array_values(array_unique($kept));
		$job->status = $full ? Job::STATUS_ROLLEDBACK : Job::STATUS_REMOVED;
		$job->message = '';
		if (Storage::isDirectory($job->getWorkPath()))
		{
			Storage::deleteDirectory($job->getWorkPath());
		}
		$job->save();
		return ['status' => $job->status, 'left' => count($job->getItems()), 'kept' => $job->options->kept];
	}

	protected static function before(object $item)
	{
		return $item->before_value ? Codec::unpack(json_decode($item->before_value)) : null;
	}

	/**
	 * @return string empty when removed, otherwise the reason it was kept
	 */
	protected static function removeItem(Job $job, object $item): string
	{
		$target = (string)$item->target;
		switch ($item->kind)
		{
			case 'setting':
				$before = self::before($item);
				if ($item->ref === 'front' && $before)
				{
					DB::getInstance()->query('UPDATE domains SET index_module_srl = ? WHERE domain_srl = ?', [(int)$before->index_module_srl, (int)$target]);
				}
				elseif ($item->ref === 'design' && $before)
				{
					$file = \RX_BASEDIR . 'files/site_design/design_0.php';
					if ((string)$before->raw === '')
					{
						Storage::delete($file);
					}
					elseif (is_object($before->info ?? null))
					{
						$info = $before->info;
						$info->layout_srl = (int)($info->layout_srl ?? 0);
						$info->mlayout_srl = (int)($info->mlayout_srl ?? 0);
						$info->module = is_object($info->module ?? null) ? $info->module : new \stdClass;
						\Zittme\Modules\Admin\Controllers\Design::getInstance()->makeDefaultDesignFile($info);
					}
					elseif (self::isPlainDesignFile((string)$before->raw))
					{
						Storage::write($file, (string)$before->raw);
					}
				}
				elseif ($item->ref === 'locales' && $before)
				{
					Config::set('locale.enabled_lang', array_values((array)$before->enabled));
					Config::save();
				}
				elseif (strpos((string)$item->ref, 'skin:') === 0 && $before)
				{
					$module = substr((string)$item->ref, 5);
					$config = \ModuleModel::getModuleConfig($module) ?: new \stdClass;
					if ($before->skin === null)
					{
						unset($config->skin);
					}
					else
					{
						$config->skin = (string)$before->skin;
					}
					\ModuleController::getInstance()->insertModuleConfig($module, $config);
				}
				Cache::clearGroup('site_and_module');
				return '';

			case 'config':
				if (preg_match('/^[a-z0-9_]+$/', $target))
				{
					executeQuery('module.deleteModuleConfig', ['module' => $target]);
					Cache::delete('site_and_module:module_config:' . $target);
				}
				return '';

			case 'row':
				$parts = explode(':', $target);
				if (count($parts) === 3 && preg_match('/^[a-z0-9_]+$/', $parts[0]) && preg_match('/^[a-z0-9_]+$/', $parts[1]))
				{
					$ctx = new ImportContext($job, new \stdClass);
					if (in_array($parts[1], $ctx->getColumns($parts[0]), true))
					{
						DB::getInstance()->query('DELETE FROM ' . $parts[0] . ' WHERE `' . $parts[1] . '` = ?', [(int)$parts[2]]);
					}
				}
				return '';

			case 'document':
				$oDocument = \DocumentModel::getDocument((int)$target);
				if ($oDocument && $oDocument->isExists())
				{
					\DocumentController::getInstance()->deleteDocument((int)$target, true, true, $oDocument);
				}
				return '';

			case 'file':
				$file = \FileModel::getFile((int)$target);
				if ($file && !empty($file->file_srl))
				{
					\FileController::getInstance()->deleteFile((int)$target);
				}
				return '';

			case 'category':
				$output = executeQuery('document.getCategory', ['category_srl' => (int)$target]);
				if ($output->data)
				{
					\DocumentController::getInstance()->deleteCategory((int)$target);
				}
				return '';

			case 'menu_item':
				executeQuery('menu.deleteMenuItem', ['menu_item_srl' => (int)$target]);
				return '';

			case 'module':
			case 'package_module':
				if (\ModuleModel::getModuleInfoByModuleSrl((int)$target))
				{
					\ModuleController::getInstance()->deleteModule((int)$target);
				}
				DB::getInstance()->query('DELETE FROM module_part_config WHERE module_srl = ?', [(int)$target]);
				return '';

			case 'menu':
				$srl = (int)$target;
				executeQuery('menu.deleteMenuItems', ['menu_srl' => $srl]);
				executeQuery('menu.deleteMenu', ['menu_srl' => $srl]);
				foreach (glob(\RX_BASEDIR . 'files/cache/menu/' . $srl . '.*') ?: [] as $file)
				{
					Storage::delete($file);
				}
				return '';

			case 'layout':
				\LayoutAdminController::getInstance()->deleteLayout((int)$target, false);
				Cache::delete('layout:' . (int)$target);
				return '';

			case 'lang':
				if (preg_match('/^[a-zA-Z0-9_]+$/', $target))
				{
					executeQuery('module.deleteLang', ['name' => $target]);
				}
				return '';

			case 'media_dir':
				if (preg_match('!^files/attach/kits/[a-z0-9_]+_\d+/$!', $target))
				{
					Storage::deleteDirectory(\RX_BASEDIR . $target);
				}
				return '';

			case 'package':
				return self::removePackage($item);
		}
		return '';
	}

	/**
	 * Whether a stored design file has only the plain assignments that makeDefaultDesignFile() writes.
	 */
	protected static function isPlainDesignFile(string $raw): bool
	{
		$lines = preg_split('/\r\n|\n|\r/', trim($raw));
		if (!$lines || !preg_match('/^<\?php if\(!defined\("__XE__"\)\) exit\(\);$/', array_shift($lines)))
		{
			return false;
		}
		$name = "'[a-zA-Z0-9_|@/.\\-]*'";
		foreach ($lines as $line)
		{
			if (trim($line) === '$designInfo = new stdClass;')
			{
				continue;
			}
			if (!preg_match('#^\$designInfo(->layout_srl = \d+;|->mlayout_srl = \d+;|->module = new stdClass;|->module->\{' . $name . '\} = new stdClass;|->module->\{' . $name . '\}->\{' . $name . '\} = ' . $name . ';)\s*$#', $line))
			{
				return false;
			}
		}
		return true;
	}

	protected static function removePackage(object $item): string
	{
		$before = self::before($item);
		if (!$before || !empty($before->existed))
		{
			return 'updated';
		}
		$path = (string)$item->ref;
		$name = basename($path);
		$pattern = \Zittme\Modules\Autoinstall\Models\Package::INSTALL_PATHS[$before->type] ?? null;
		if (!$pattern || !preg_match($pattern, $path) || !preg_match('/^[a-zA-Z0-9_]+$/', $name))
		{
			return 'kept';
		}
		if ($before->type === 'module')
		{
			if (\Context::isDefaultPlugin($name, 'module'))
			{
				return 'kept';
			}
			$count = DB::getInstance()->query('SELECT COUNT(*) AS c FROM modules WHERE module = ?', [$name]);
			$row = $count ? ($count->fetchAll(\PDO::FETCH_ASSOC)[0] ?? ['c' => 0]) : ['c' => 0];
			if ((int)$row['c'] > 0)
			{
				return 'in_use';
			}
			$class = \ModuleModel::getModuleInstallClass($name);
			if ($class && method_exists($class, 'moduleUninstall'))
			{
				$class->moduleUninstall();
			}
			Storage::deleteDirectory(\RX_BASEDIR . 'modules/' . $name);
			return '';
		}
		if ($before->type === 'theme' && Theme::isValidName($name))
		{
			$like = $name . Theme::SEPARATOR . '%';
			$stmt = DB::getInstance()->query('SELECT COUNT(*) AS c FROM modules WHERE skin LIKE ? OR mskin LIKE ?', [$like, $like]);
			$used = $stmt ? (int)($stmt->fetchAll(\PDO::FETCH_ASSOC)[0]['c'] ?? 0) : 0;
			$stmt = DB::getInstance()->query('SELECT COUNT(*) AS c FROM layouts WHERE layout LIKE ?', [$like]);
			$used += $stmt ? (int)($stmt->fetchAll(\PDO::FETCH_ASSOC)[0]['c'] ?? 0) : 0;
			if ($used > 0)
			{
				return 'in_use';
			}
			Storage::deleteDirectory(Theme::getPath($name));
			return '';
		}
		return 'kept';
	}
}
