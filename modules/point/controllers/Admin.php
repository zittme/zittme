<?php

namespace Zittme\Modules\Point\Controllers;

use Context;
use FileHandler;
use Zittme\Framework\DB;
use Zittme\Framework\Exception;
use Zittme\Framework\Exceptions\InvalidRequest;
use Zittme\Framework\Exceptions\TargetNotFound;
use Zittme\Modules\Point\Models\AdminData;
use Zittme\Modules\Point\Models\Config;
use Zittme\Modules\Point\Models\Ledger;
use Zittme\Modules\Point\Models\Maintenance;
use Zittme\Modules\Point\Models\Migration;
use Zittme\Modules\Point\Models\State;
use Zittme\Modules\Point\Models\Stats;
use Zittme\Modules\Point\Services\PointService;

/**
 * Point administration screens.
 *
 * Every legacy screen address opens the same console with the matching section
 * and conditions, so bookmarks and links from other modules keep working.
 */
class Admin extends \Point
{
	public const LIST_COUNT = 20;
	public const RECALC_BATCH = 1000;

	public function init()
	{
		Context::set('config', Config::get());
		Context::loadLang(\RX_BASEDIR . 'modules/member/lang');
		$this->setTemplatePath($this->module_path . 'views/admin/');
	}

	/**
	 * Point console: summary.
	 */
	public function dispPointAdminConfig()
	{
		$section = (string)Context::get('sec');
		$this->renderConsole(in_array($section, ['dash', 'rules', 'members', 'ledger', 'level', 'uses', 'policy'], true) ? $section : 'dash');
	}

	/**
	 * Point console: rules, with the per-board view open.
	 */
	public function dispPointAdminModuleConfig()
	{
		$this->renderConsole('rules', ['board_open' => 'insert_document']);
	}

	/**
	 * Point console: members, with the legacy list conditions.
	 */
	public function dispPointAdminPointList()
	{
		$query = [];
		$group_srl = intval(Context::get('selected_group_srl'));
		if ($group_srl > 0)
		{
			$query['group_srl'] = $group_srl;
		}
		$target = (string)Context::get('search_target');
		$keyword = trim((string)Context::get('search_keyword'));
		if ($keyword !== '' && in_array($target, AdminApi::LEGACY_TARGETS, true))
		{
			$query['search_target'] = $target;
			$query['search_keyword'] = $keyword;
		}
		$this->renderConsole('members', $query);
	}

	/**
	 * Point console: history, with the legacy search conditions.
	 */
	public function dispPointAdminLedger()
	{
		$query = [];
		$filter = $this->getLedgerFilter();
		if (!empty($filter['member_srl']))
		{
			$query['member_srl'] = $filter['member_srl'];
		}
		foreach (['kind', 'type', 'direction', 'reason_code', 'date_from', 'date_to'] as $key)
		{
			if (!empty($filter[$key]))
			{
				$query[$key] = $filter[$key];
			}
		}
		if (!empty($filter['actor_only']))
		{
			$query['actor_only'] = 'Y';
		}
		$this->renderConsole('ledger', $query);
	}

	/**
	 * Point console: settings.
	 */
	public function dispPointAdminPolicy()
	{
		$this->renderConsole('policy');
	}

	/**
	 * Ledger CSV download.
	 */
	public function dispPointAdminLedgerExport()
	{
		if (!Config::isLedgerReady())
		{
			throw new InvalidRequest;
		}
		@set_time_limit(0);
		$filter = $this->getLedgerFilter();
		if (!empty($filter['date_from']) && empty($filter['member_srl']))
		{
			$filter['srl_from'] = Ledger::firstSrlFrom($filter['date_from'] . '000000');
		}
		if (!empty($filter['date_to']) && empty($filter['member_srl']))
		{
			$filter['srl_to'] = Ledger::firstSrlFrom(date('Ymd', strtotime('+1 day', strtotime($filter['date_to']))) . '000000') ?: null;
		}
		$filename = 'point-ledger-' . date('Ymd-His') . '.csv';

		while (ob_get_level())
		{
			ob_end_clean();
		}
		header('Content-Type: text/csv; charset=UTF-8');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		header('Cache-Control: no-store');
		$out = fopen('php://output', 'w');
		fwrite($out, "\xEF\xBB\xBF");
		fputcsv($out, ['ledger_srl', 'regdate', 'member_srl', 'user_id', 'nick_name', 'wallet', 'type', 'reason_code', 'amount', 'balance_after', 'remaining', 'expires_at', 'status', 'memo', 'ref_type', 'ref_srl', 'actor_srl', 'ipaddress'], ',', '"', '');
		$after = 0;
		do
		{
			$rows = Ledger::chunk($filter, $after, 1000);
			$names = $this->getMemberNames(array_column($rows, 'member_srl'));
			foreach ($rows as $row)
			{
				$name = $names[$row->member_srl] ?? null;
				fputcsv($out, [
					$row->ledger_srl,
					self::formatDate($row->regdate),
					$row->member_srl,
					self::csvText($name->user_id ?? ''),
					self::csvText($name->nick_name ?? ''),
					$row->wallet,
					$row->type,
					self::csvText($row->reason_code),
					$row->amount,
					$row->balance_after,
					$row->remaining,
					$row->expires_at ? self::formatDate($row->expires_at) : '',
					$row->status,
					self::csvText($row->memo),
					self::csvText($row->ref_type),
					$row->ref_srl,
					$row->actor_srl,
					$row->ipaddress,
				], ',', '"', '');
				$after = intval($row->ledger_srl);
			}
			flush();
		}
		while (count($rows) === 1000);
		fclose($out);
		Context::close();
		exit;
	}

	/**
	 * Draw the console. The screen itself is built in the browser from the boot data.
	 */
	protected function renderConsole(string $section, array $query = [])
	{
		if (Context::get('module') !== 'admin')
		{
			$params = ['', 'module', 'admin'];
			foreach ($_GET as $key => $value)
			{
				if (is_scalar($value) && !in_array($key, ['module', 'mid', 'vid'], true))
				{
					$params[] = $key;
					$params[] = $value;
				}
			}
			$this->setRedirectUrl(getNotEncodedUrl(...$params));
			return;
		}

		$config = Config::get();
		$status = Migration::status();
		$groups = [];
		foreach (\MemberModel::getGroups() ?: [] as $group)
		{
			$groups[] = [
				'id' => intval($group->group_srl),
				'title' => html_entity_decode(Context::replaceUserLang((string)$group->title)),
				'admin' => ($group->is_admin ?? 'N') === 'Y',
				'def' => ($group->is_default ?? 'N') === 'Y',
			];
		}
		$logged_info = Context::get('logged_info');
		$boot = [
			'section' => $section,
			'query' => $query,
			'lang' => Context::getLangType(),
			'point_name' => (string)($config->point_name ?? 'point'),
			'able' => ($config->able_module ?? 'N') === 'Y',
			'ledger' => Config::isLedgerReady($config),
			'ledger_busy' => $status->ready && (!$status->done || $status->reconcile_run !== ''),
			'ledger_job' => $status->ready && !$status->done ? 'migrate' : ($status->ready && $status->reconcile_run !== '' ? 'reconcile' : ''),
			'stats' => Config::isStatsEnabled($config),
			'big_confirm' => ($config->big_confirm ?? 'N') === 'Y',
			'big' => [Config::BIG_CONFIRM_PER_MEMBER, Config::BIG_CONFIRM_TOTAL, Config::BIG_CONFIRM_MEMBERS],
			'groups' => $groups,
			'me' => is_object($logged_info) ? intval($logged_info->member_srl) : 0,
			'today' => date('Ymd'),
			'week' => Stats::weekStart(),
			'icon_base' => \RX_BASEURL . 'modules/point/icons/',
			'urls' => [
				'members' => getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMemberAdminList'),
				'groups' => getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMemberAdminGroupList'),
				'signup' => getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMemberAdminSignUpConfig'),
				'settings' => getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMemberAdminConfig'),
				'point' => getNotEncodedUrl('', 'module', 'admin', 'act', 'dispPointAdminConfig'),
				'member_info' => getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMemberAdminInfo', 'member_srl', ''),
				'csv' => getNotEncodedUrl('', 'module', 'admin', 'act', 'dispPointAdminLedgerExport'),
			],
			't' => self::consoleStrings(),
		];
		Context::set('pa_boot_json', json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
		Context::loadFile([$this->module_path . 'views/admin/point-admin.css', '', '', 100]);
		Context::loadFile([$this->module_path . 'views/admin/point-admin.js', 'body', '', 100]);
		$this->setTemplateFile('console');
	}

	/**
	 * Screen texts of the console in the current language, with English as the fallback.
	 */
	public static function consoleStrings(): array
	{
		$read = function (string $code): array {
			$file = \RX_BASEDIR . 'modules/point/lang/' . $code . '.php';
			if (!preg_match('/^[a-zA-Z-]+$/', $code) || !file_exists($file))
			{
				return [];
			}
			$lang = new \stdClass;
			include $file;
			$strings = [];
			foreach (get_object_vars($lang) as $key => $value)
			{
				if (strncmp($key, 'pa_', 3) === 0 && is_string($value))
				{
					$strings[substr($key, 3)] = $value;
				}
			}
			return $strings;
		};
		$lang_type = Context::getLangType() ?: 'ko';
		return array_merge($read('en'), $lang_type === 'en' ? [] : $read($lang_type));
	}

	/**
	 * Save the default configuration (legacy form).
	 */
	public function procPointAdminInsertConfig()
	{
		$config = \ModuleModel::getModuleConfig('point');
		$config = is_object($config) ? $config : new \stdClass;
		$args = Context::getRequestVars();
		$oModuleController = getController('module');

		if (($args->able_module ?? '') === 'Y')
		{
			if (($config->able_module ?? '') === 'N')
			{
				$this->installLedgerTables();
				$this->registerTriggers();
				$this->syncMemberMenuTrigger(($config->mypage_enabled ?? 'N') === 'Y');
			}
			$config->able_module = 'Y';
		}
		else
		{
			$config->able_module = 'N';
			$oModuleController->deleteModuleTriggers('point');
		}

		$config->point_name = trim((string)($args->point_name ?? '')) ?: 'point';

		foreach (['signup_point', 'login_point'] as $key)
		{
			$config->$key = self::clampPoint($args->$key ?? 0);
		}
		foreach (self::moduleNumericKeys() as $key)
		{
			$config->$key = self::clampPoint($args->$key ?? 0);
		}

		$config->first_document_point = max(0, self::clampPoint($args->first_document_point ?? 0));
		$config->first_document_revert = (($args->first_document_revert ?? '') === 'Y') ? 'Y' : 'N';

		$config->read_document_except_notice =(($args->read_document_except_notice ?? '') === 'Y');
		$config->read_document_author_except_notice = (($args->read_document_author_except_notice ?? '') === 'Y');

		$config->insert_document_revert_on_delete = (($args->insert_document_revert_on_delete ?? '') === 'Y');
		$config->insert_comment_revert_on_delete = (($args->insert_comment_revert_on_delete ?? '') === 'Y');
		$config->upload_file_revert_on_delete = (($args->upload_file_revert_on_delete ?? '') === 'Y');

		$config->insert_comment_limit = $config->no_point_date = (int)($args->insert_comment_limit ?? 0);
		foreach (['read_document_limit', 'voter_limit', 'blamer_limit', 'voted_limit', 'blamed_limit', 'read_document_author_limit',
			'voter_comment_limit', 'blamer_comment_limit', 'voted_comment_limit', 'blamed_comment_limit'] as $key)
		{
			$config->$key = (int)($args->$key ?? 0);
		}

		$max_level = (int)($args->max_level ?? 0);
		if ($max_level >= 10000)
		{
			throw new Exception('point.msg_max_level_too_high');
		}
		$config->max_level = max(1, $max_level);

		$config->level_icon = basename((string)($args->level_icon ?? '')) ?: 'default';
		$config->level_icon_type = self::detectIconType($this->module_path . 'icons/' . $config->level_icon);
		if ($config->level_icon_type === '')
		{
			throw new Exception('msg_level_icon_not_found');
		}

		$config->disable_download = (($args->disable_download ?? '') === 'Y') ? 'Y' : 'N';
		$config->disable_read_document = (($args->disable_read_document ?? '') === 'Y') ? 'Y' : 'N';
		$config->disable_read_document_except_robots = (($args->disable_read_document_except_robots ?? '') === 'Y') ? 'Y' : 'N';

		$config->point_group = [];
		foreach (\MemberModel::getGroups() ?: [] as $group)
		{
			if ($group->is_admin == 'Y' || $group->is_default == 'Y')
			{
				continue;
			}
			$key = 'point_group_' . $group->group_srl;
			if (isset($args->$key))
			{
				$level = $args->$key;
				if ($level > $config->max_level)
				{
					$level = $config->max_level;
				}
				if ($level && $level < 1)
				{
					$level = 1;
				}
				$config->point_group[$group->group_srl] = $level;
			}
		}

		$config->group_reset = $args->group_reset ?? 'Y';
		$config->group_ratchet = $args->group_ratchet ?? 'N';

		$level_step = array_map('intval', explode(',', ($args->level_step ?? '') ?: '0'));
		$config->level_step = [];
		for ($i = 1; $i <= $config->max_level; $i++)
		{
			$config->level_step[$i] = isset($level_step[$i - 1]) ? $level_step[$i - 1] : array_last($level_step);
		}
		$config->expression = $args->expression ?? '';

		$oModuleController->insertModuleConfig('point', $config);
		\Point::resetConfigCache();

		$this->setMessage('success_updated');
		$this->setRedirectUrl(Context::get('success_return_url') ?: getNotEncodedUrl('', 'module', 'admin', 'act', 'dispPointAdminConfig'));
	}

	/**
	 * Save per-module points from a grid. Only the posted numbers change;
	 * other values of each board (such as "take back on delete") are kept.
	 */
	public function procPointAdminInsertModuleConfig()
	{
		$args = Context::getRequestVars();
		$module_config = [];
		foreach (self::moduleNumericKeys() as $key)
		{
			if (isset($args->$key) && is_array($args->$key))
			{
				foreach ($args->$key as $module_srl => $value)
				{
					$module_config[intval($module_srl)][$key] = $value;
				}
			}
		}

		$oModuleController = getController('module');
		foreach ($module_config as $module_srl => $values)
		{
			if ($module_srl > 0)
			{
				$merged = self::modulePartConfig($module_srl);
				foreach ($values as $key => $value)
				{
					if (trim((string)$value) === '')
					{
						unset($merged[$key]);
					}
					else
					{
						$merged[$key] = $value;
					}
				}
				$oModuleController->insertModulePartConfig('point', $module_srl, $merged);
			}
		}

		$this->setMessage('success_updated');
		$this->setRedirectUrl(Context::get('success_return_url') ?: getNotEncodedUrl('', 'module', 'admin', 'act', 'dispPointAdminModuleConfig'));
	}

	/**
	 * Save per-module points from a module's additional settings.
	 */
	public function procPointAdminInsertPointModuleConfig()
	{
		$module_srl = Context::get('target_module_srl');
		if (!$module_srl)
		{
			throw new InvalidRequest;
		}
		$module_srl = preg_match('/^([0-9,]+)$/', $module_srl) ? explode(',', $module_srl) : [$module_srl];

		$boolean_keys = ['insert_document_revert_on_delete', 'insert_comment_revert_on_delete', 'upload_file_revert_on_delete'];
		$oModuleController = getController('module');
		foreach ($module_srl as $srl)
		{
			$srl = trim($srl ?? '');
			if (!$srl)
			{
				continue;
			}
			$config = [];
			foreach (self::moduleNumericKeys() as $key)
			{
				$value = trim(Context::get($key) ?? '');
				if ($value !== '')
				{
					$config[$key] = self::clampPoint($value);
				}
			}
			foreach ($boolean_keys as $key)
			{
				$value = trim(Context::get($key) ?? '');
				if ($value !== '')
				{
					$config[$key] = ($value === 'Y');
				}
			}
			$oModuleController->insertModulePartConfig('point', $srl, $config);
		}

		$this->setError(-1);
		$this->setMessage('success_updated', 'info');
		$this->setRedirectUrl(Context::get('success_return_url') ?: getNotEncodedUrl('', 'module', 'admin', 'act', 'dispBoardAdminContent'));
	}

	/**
	 * Give or take points by hand.
	 *
	 * Accepts "+10", "-10" or "10" (set to exactly 10) in the point field.
	 * A reason is required while point history is recorded; without history
	 * only the balance changes, so the reason may be left empty.
	 */
	public function procPointAdminUpdatePoint()
	{
		$member_srl = intval(Context::get('member_srl'));
		$point = trim((string)Context::get('point'));
		$memo = trim((string)Context::get('memo'));

		if (!$member_srl || !\MemberModel::getMemberInfoByMemberSrl($member_srl))
		{
			throw new TargetNotFound('point.msg_member_not_found');
		}
		if (!preg_match('/^(\+|-)?([0-9]+)$/', $point, $m))
		{
			throw new Exception('point.msg_invalid_amount');
		}
		$ledger_ready = Config::isLedgerReady();
		if ($memo === '')
		{
			if ($ledger_ready)
			{
				throw new Exception('point.msg_reason_required');
			}
			$memo = 'admin';
		}

		$before = (int)\PointModel::getPoint($member_srl, true);
		$logged_info = Context::get('logged_info');
		$actor_srl = is_object($logged_info) ? intval($logged_info->member_srl) : 0;
		if (strlen(ltrim($m[2], '0')) > 10 || intval($m[2]) > Ledger::MAX_POINT)
		{
			throw new Exception('point.msg_invalid_amount');
		}
		$amount = intval($m[2]);
		if ($m[1] === '+')
		{
			$output = PointService::adjust($member_srl, $amount, $memo, $actor_srl);
		}
		elseif ($m[1] === '-')
		{
			$output = PointService::adjust($member_srl, -$amount, $memo, $actor_srl);
		}
		else
		{
			$output = PointService::adjustTo($member_srl, $amount, $memo, $actor_srl);
		}
		if (!$output->toBool())
		{
			return $output;
		}

		$config = Config::get();
		$balance = intval($output->get('balance'));
		$this->add('before', $before);
		$this->add('balance', $balance);
		$this->add('level', \PointModel::getLevel($balance, $config->level_step ?? []));
		$this->add('ledger_srl', $output->get('ledger_srl'));
		$this->setMessage('success_updated');
		$this->setRedirectUrl(Context::get('success_return_url') ?: getNotEncodedUrl('', 'module', 'admin', 'act', 'dispPointAdminPointList'));
	}

	/**
	 * Save ledger policy settings (legacy form).
	 */
	public function procPointAdminInsertPolicy()
	{
		$args = Context::getRequestVars();
		$values = [
			'limit_enabled' => (($args->limit_enabled ?? '') === 'Y') ? 'Y' : 'N',
			'limit_daily' => max(0, intval($args->limit_daily ?? 0)),
			'limit_monthly' => max(0, intval($args->limit_monthly ?? 0)),
			'limit_reasons' => self::normalizeReasonLimits((string)($args->limit_reasons ?? '')),
			'expire_enabled' => (($args->expire_enabled ?? '') === 'Y') ? 'Y' : 'N',
			'expire_months' => max(1, min(120, intval($args->expire_months ?? 12))),
			'retention_enabled' => (($args->retention_enabled ?? '') === 'Y') ? 'Y' : 'N',
			'retention_months' => max(Config::MIN_RETENTION_MONTHS, min(240, intval($args->retention_months ?? 24))),
			'mypage_enabled' => (($args->mypage_enabled ?? '') === 'Y') ? 'Y' : 'N',
		];
		if ($values['limit_enabled'] === 'Y' && !$values['limit_daily'] && !$values['limit_monthly'] && $values['limit_reasons'] === '')
		{
			throw new Exception('point.msg_limit_empty');
		}

		$output = Config::update($values);
		if (!$output->toBool())
		{
			return $output;
		}

		$config = Config::get();
		if (($config->able_module ?? 'N') === 'Y')
		{
			$this->syncMemberMenuTrigger($values['mypage_enabled'] === 'Y');
		}

		$this->setMessage('success_updated');
		$this->setRedirectUrl(Context::get('success_return_url') ?: getNotEncodedUrl('', 'module', 'admin', 'act', 'dispPointAdminPolicy'));
	}

	/**
	 * Start or stop recording point changes in the ledger (legacy form).
	 */
	public function procPointAdminSetLedger()
	{
		$enabled = Context::get('ledger_enabled') === 'Y';
		$was_enabled = Config::isLedgerReady();
		$output = $this->setLedgerRecording($enabled);
		if (!$output->toBool())
		{
			return $output;
		}
		if ($enabled && !$was_enabled && State::get('migrate_done') === 'Y')
		{
			State::set(['reconcile_needed' => 'Y']);
		}

		$this->setMessage('success_updated');
		$this->setRedirectUrl(Context::get('success_return_url') ?: getNotEncodedUrl('', 'module', 'admin', 'act', 'dispPointAdminPolicy'));
	}

	/**
	 * Run one step of a batch job and report progress (called repeatedly by the admin screen).
	 */
	public function procPointAdminRunBatch()
	{
		$job = (string)Context::get('job');
		if (!Config::isLedgerReady())
		{
			throw new Exception('point.msg_ledger_not_ready');
		}
		@set_time_limit(120);
		switch ($job)
		{
			case 'migrate':
				$result = Migration::runBatch(Migration::DEFAULT_BATCH);
				break;
			case 'reconcile_start':
				Migration::startReconcile();
				$result = Migration::runReconcileBatch(Migration::DEFAULT_BATCH);
				break;
			case 'reconcile':
				$result = Migration::runReconcileBatch(Migration::DEFAULT_BATCH);
				break;
			case 'maintenance':
				$results = Maintenance::run(5);
				$result = (object)['done' => true, 'processed' => 0, 'inserted' => 0, 'cursor' => 0, 'jobs' => $results];
				break;
			case 'stats':
				if (!Config::isStatsEnabled())
				{
					throw new InvalidRequest;
				}
				$run = Stats::run(Stats::RUN_ROWS);
				$result = (object)['done' => $run->balance_ready && $run->done, 'processed' => $run->processed, 'inserted' => 0, 'cursor' => $run->cursor];
				break;
			default:
				throw new InvalidRequest;
		}
		if (!empty($result->error))
		{
			throw new Exception($result->error);
		}

		$status = Migration::status();
		if ($job === 'migrate' && !empty($result->done) && State::get('reconcile_needed') === 'Y')
		{
			$result->done = false;
			$this->add('next', 'reconcile_start');
		}
		$this->add('done', !empty($result->done));
		$this->add('processed', intval($result->processed ?? 0));
		$this->add('inserted', intval($result->inserted ?? 0));
		$this->add('cursor', intval($result->cursor ?? 0));
		$this->add('remaining', $status->remaining);
		$this->add('total', $status->total);
		$this->add('percent', $job === 'stats' ? (!empty($result->done) ? 100 : 50) : (!empty($result->done) ? 100 : $status->percent));
		$this->add('max_srl', $status->max_srl);
		$this->add('jobs', $result->jobs ?? null);
	}

	/**
	 * Recalculate every balance from posts, comments and files (first step).
	 *
	 * The new balances are written through the ledger in procPointAdminApplyPoint,
	 * so that when history is on, each difference is kept as one line.
	 */
	public function procPointAdminReCal()
	{
		@set_time_limit(0);
		$config = \ModuleModel::getModuleConfig('point');
		$module_config = \ModuleModel::getModulePartConfigs('point');
		$member = [];

		$output = executeQueryArray('point.getMemberCount');
		if (!$output->toBool())
		{
			return $output;
		}
		foreach ($output->data ?: [] as $val)
		{
			if ($val->member_srl)
			{
				$member[$val->member_srl] = 0;
			}
		}

		foreach ([['point.getDocumentPoint', 'insert_document'], ['point.getCommentPoint', 'insert_comment'], ['point.getFilePoint', 'upload_file']] as [$query_id, $key])
		{
			$output = executeQueryArray($query_id);
			if (!$output->toBool())
			{
				return $output;
			}
			foreach ($output->data ?: [] as $val)
			{
				if (!$val->member_srl)
				{
					continue;
				}
				$insert_point = !empty($module_config[$val->module_srl][$key]) ? $module_config[$val->module_srl][$key] : $config->$key;
				$member[$val->member_srl] = ($member[$val->member_srl] ?? 0) + $insert_point * $val->count;
			}
		}

		$str = '';
		foreach ($member as $key => $val)
		{
			$val += (int)$config->signup_point;
			$str .= $key . ',' . $val . "\r\n";
		}
		\Zittme\Framework\Storage::write(\RX_BASEDIR . 'files/env/pointRecal.txt', $str);

		$this->add('total', count($member));
		$this->add('position', 0);
		$this->setMessage(sprintf(lang('point_recal_message'), 0, $this->get('total')));
	}

	/**
	 * Apply recalculated balances through the ledger, a batch of members at a time.
	 */
	public function procPointAdminApplyPoint()
	{
		@set_time_limit(120);
		$position = (int)Context::get('position');
		$total = (int)Context::get('total');
		$path = \RX_BASEDIR . 'files/env/pointRecal.txt';
		if (!file_exists($path))
		{
			throw new InvalidRequest;
		}

		$logged_info = Context::get('logged_info');
		$actor_srl = is_object($logged_info) ? intval($logged_info->member_srl) : 0;
		$targets = [];
		$idx = 0;
		$f = fopen($path, 'r');
		while (!feof($f))
		{
			$str = trim((string)fgets($f, 1024));
			$idx++;
			if ($idx <= $position)
			{
				continue;
			}
			if ($str !== '')
			{
				[$member_srl, $point] = explode(',', $str);
				$targets[intval($member_srl)] = intval($point);
			}
			if (count($targets) >= self::RECALC_BATCH)
			{
				break;
			}
		}
		$finished = feof($f);
		fclose($f);

		if ($targets)
		{
			$current = [];
			$srls = array_keys($targets);
			foreach (DB::getInstance()->query('SELECT member_srl, point FROM point WHERE member_srl IN (' . implode(',', array_fill(0, count($srls), '?')) . ')', $srls)->fetchAll() as $row)
			{
				$current[intval($row->member_srl)] = intval($row->point);
			}
			foreach ($targets as $member_srl => $point)
			{
				if (isset($current[$member_srl]) && $current[$member_srl] === $point)
				{
					continue;
				}
				Ledger::write([
					'member_srl' => $member_srl,
					'target' => $point,
					'type' => 'reconcile',
					'reason_code' => 'recalc',
					'note' => 'recalc',
					'policy' => Ledger::POLICY_NEGATIVE,
					'actor_srl' => $actor_srl,
					'triggers' => false,
					'apply_groups' => false,
				]);
			}
		}

		if ($finished)
		{
			FileHandler::removeFile($path);
			$idx = $total;
			\Zittme\Framework\Cache::clearGroup('member');
			FileHandler::rename('./files/member_extra_info/point', './files/member_extra_info/point.old');
			FileHandler::removeDir('./files/member_extra_info/point.old');
		}

		$this->add('total', $total);
		$this->add('position', min($idx, $total));
		$this->setMessage(sprintf(lang('point_recal_message'), min($idx, $total), $total));
	}

	/**
	 * Reset per-module points.
	 */
	public function procPointAdminReset()
	{
		$module_srl = Context::get('module_srls');
		if (!$module_srl)
		{
			throw new InvalidRequest;
		}
		$module_srl = preg_match('/^([0-9,]+)$/', $module_srl) ? explode(',', $module_srl) : [$module_srl];
		foreach ($module_srl as $srl)
		{
			$srl = trim($srl);
			if (!$srl)
			{
				continue;
			}
			$args = new \stdClass;
			$args->module = 'point';
			$args->module_srl = $srl;
			executeQuery('module.deleteModulePartConfig', $args);
		}
		\Zittme\Framework\Cache::clearGroup('site_and_module');
		$this->setMessage('success_updated');
	}

	/**
	 * @deprecated
	 */
	public function cacheActList()
	{
		return;
	}

	/**
	 * Keep a point value inside the range of the point column.
	 */
	public static function clampPoint($value): int
	{
		return max(-Ledger::MAX_POINT, min(Ledger::MAX_POINT, (int)$value));
	}

	/**
	 * Point keys that can be set per module.
	 */
	public static function moduleNumericKeys(): array
	{
		return [
			'insert_document', 'insert_comment', 'upload_file', 'download_file', 'read_document',
			'voter', 'blamer', 'voter_comment', 'blamer_comment',
			'download_file_author', 'read_document_author', 'voted', 'blamed', 'voted_comment', 'blamed_comment',
		];
	}

	/**
	 * A board's own point values as an array.
	 */
	public static function modulePartConfig(int $module_srl): array
	{
		$part = \ModuleModel::getModulePartConfig('point', $module_srl);
		if (is_object($part))
		{
			return get_object_vars($part);
		}
		return is_array($part) ? $part : [];
	}

	/**
	 * File type of a level icon set: gif, png or svg ('' when the set has no level 1 icon).
	 */
	public static function detectIconType(string $dir): string
	{
		if (!is_dir($dir))
		{
			return '';
		}
		foreach (['gif', 'png', 'svg'] as $type)
		{
			if (file_exists($dir . '/1.' . $type))
			{
				return $type;
			}
		}
		return '';
	}

	/**
	 * Ledger conditions from the request, accepting the legacy parameter names.
	 */
	protected function getLedgerFilter(): array
	{
		$filter = [];
		$member_key = (string)Context::get('member_key');
		$member = trim((string)Context::get('member'));
		if ($member !== '')
		{
			switch ($member_key)
			{
				case 'member_srl':
					$filter['member_srl'] = intval($member);
					break;
				case 'nick_name':
					$filter['member_srl'] = intval(\MemberModel::getMemberSrlByNickName($member));
					break;
				case 'email_address':
					$filter['member_srl'] = intval(\MemberModel::getMemberSrlByEmailAddress($member));
					break;
				default:
					$filter['member_srl'] = intval(\MemberModel::getMemberSrlByUserID($member));
					break;
			}
			if (!$filter['member_srl'])
			{
				$filter['member_srl'] = -1;
			}
		}
		elseif (intval(Context::get('member_srl')) > 0)
		{
			$filter['member_srl'] = intval(Context::get('member_srl'));
		}

		$kind = (string)Context::get('kind');
		if (in_array($kind, Ledger::KINDS, true))
		{
			$filter['kind'] = $kind;
		}
		$type = (string)Context::get('type');
		if (in_array($type, Ledger::TYPES, true))
		{
			$kinds = ['earn' => 'earn', 'spend' => 'spend', 'admin_add' => 'admin', 'admin_take' => 'admin', 'expire' => 'expire'];
			if (isset($kinds[$type]) && empty($filter['kind']) && ($type === 'admin_add' || $type === 'admin_take' || $type === 'expire'))
			{
				$filter['kind'] = $kinds[$type];
				if ($type !== 'expire')
				{
					$filter['direction'] = $type === 'admin_add' ? 'plus' : 'minus';
				}
			}
			else
			{
				$filter['type'] = $type;
			}
		}
		$direction = (string)Context::get('direction');
		if (in_array($direction, ['plus', 'minus'], true))
		{
			$filter['direction'] = $direction;
		}
		$reason_code = strtolower(trim((string)Context::get('reason_code')));
		if (preg_match('/^[a-z0-9_.:\-]{1,40}$/', $reason_code))
		{
			$filter['reason_code'] = $reason_code;
		}
		foreach (['date_from', 'date_to'] as $key)
		{
			$value = preg_replace('/[^0-9]/', '', (string)Context::get($key));
			if (strlen($value) === 8)
			{
				$filter[$key] = $value;
			}
		}
		if (Context::get('actor_only') === 'Y' || Context::get('admin_only') === 'Y')
		{
			$filter['actor_only'] = true;
		}
		return $filter;
	}

	protected function getMemberNames(array $srls): array
	{
		$srls = array_values(array_unique(array_filter(array_map('intval', $srls))));
		if (!$srls)
		{
			return [];
		}
		$rows = DB::getInstance()->query('SELECT member_srl, user_id, nick_name FROM member WHERE member_srl IN (' . implode(',', array_fill(0, count($srls), '?')) . ')', $srls)->fetchAll();
		$names = [];
		foreach ($rows as $row)
		{
			$names[$row->member_srl] = $row;
		}
		return $names;
	}

	/**
	 * Read a language list (such as reason_list) as a plain array.
	 */
	public static function langList(string $key): array
	{
		$list = lang($key);
		if ($list instanceof \ArrayObject)
		{
			return $list->getArrayCopy();
		}
		if (is_object($list))
		{
			return get_object_vars($list);
		}
		return is_array($list) ? $list : [];
	}

	public static function reasonLabel(string $reason_code): string
	{
		if ($reason_code === '')
		{
			return '';
		}
		$labels = self::langList('point.reason_list');
		if (is_array($labels) && isset($labels[$reason_code]))
		{
			return $labels[$reason_code];
		}
		$base = preg_replace('/_cancel$/', '', $reason_code);
		if (is_array($labels) && isset($labels[$base]))
		{
			return $labels[$base] . ' ' . lang('point.reason_cancel_suffix');
		}
		return self::moduleReasonLabel($reason_code);
	}

	/**
	 * Label for a reason code sent by another module.
	 *
	 * The module part is the text before ":" (passport:ride) or before the first "_" (kin_stake).
	 * The module may define $lang->point_reason_{rest} or $lang->point_reason_{code} in its own
	 * language file; otherwise the module title is shown with the remaining code.
	 */
	public static function moduleReasonLabel(string $reason_code): string
	{
		static $cache = [];
		if (isset($cache[$reason_code]))
		{
			return $cache[$reason_code];
		}
		if (preg_match('/^[a-z0-9_]+$/', $reason_code) && is_dir(\RX_BASEDIR . 'modules/' . $reason_code))
		{
			$info = \ModuleModel::getModuleInfoXml($reason_code);
			return $cache[$reason_code] = is_object($info) && !empty($info->title) ? $info->title : $reason_code;
		}
		if (!preg_match('/^([a-z0-9]+)(?::|_)(.+)$/', $reason_code, $m) || !is_dir(\RX_BASEDIR . 'modules/' . $m[1]))
		{
			return $cache[$reason_code] = $reason_code;
		}
		$module = $m[1];
		$rest = str_replace(':', '_', $m[2]);
		Context::loadLang(\RX_BASEDIR . 'modules/' . $module . '/lang');
		foreach (['point_reason_' . $rest, 'point_reason_' . str_replace(':', '_', $reason_code)] as $key)
		{
			$text = lang($module . '.' . $key);
			if (is_string($text) && $text !== '' && $text !== $key && $text !== $module . '.' . $key)
			{
				return $cache[$reason_code] = $text;
			}
		}
		$info = \ModuleModel::getModuleInfoXml($module);
		$title = is_object($info) && !empty($info->title) ? $info->title : $module;
		return $cache[$reason_code] = $title . ' · ' . $rest;
	}

	public static function formatDate(string $value, string $format = 'Y-m-d H:i:s'): string
	{
		return strlen($value) >= 8 ? date($format, ztime($value)) : $value;
	}

	protected static function csvText($value): string
	{
		$value = (string)$value;
		return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
	}

	public static function normalizeReasonLimits(string $text): string
	{
		$lines = [];
		foreach (Config::parseReasonLimits($text) as $reason => $amount)
		{
			$lines[] = $reason . '=' . $amount;
		}
		return implode("\n", $lines);
	}
}
