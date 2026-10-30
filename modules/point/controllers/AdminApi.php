<?php

namespace Zittme\Modules\Point\Controllers;

use Context;
use Zittme\Framework\DB;
use Zittme\Framework\Exception;
use Zittme\Framework\Exceptions\InvalidRequest;
use Zittme\Framework\Exceptions\TargetNotFound;
use Zittme\Modules\Point\Models\AdminData;
use Zittme\Modules\Point\Models\Config;
use Zittme\Modules\Point\Models\Ledger;
use Zittme\Modules\Point\Models\Migration;
use Zittme\Modules\Point\Models\State;
use Zittme\Modules\Point\Models\Stats;
use Zittme\Modules\Point\Services\PointService;

/**
 * Data and saving for the point console. Every action answers in JSON.
 *
 * Saving actions change one value at a time and merge it into the stored
 * configuration, so values that the screen does not show are never removed.
 */
class AdminApi extends \Point
{
	public const PAGE = 50;
	public const SEARCH_LIMIT = 200;
	public const BULK_LIMIT = 5000;
	public const BULK_AMOUNT_LIMIT = 10000000;
	public const NARROW_DAYS = 31;
	public const DENSE_GROUP = 50000;

	public const LEGACY_TARGETS = ['user_id', 'user_name', 'nick_name', 'email_address', 'regdate', 'regdate_more', 'regdate_less', 'last_login', 'last_login_more', 'last_login_less', 'extra_vars'];

	public const LIMIT_KEYS = [
		'insert_comment' => 'insert_comment_limit',
		'read_document' => 'read_document_limit',
		'read_document_author' => 'read_document_author_limit',
		'voted' => 'voted_limit',
		'blamed' => 'blamed_limit',
		'voter' => 'voter_limit',
		'blamer' => 'blamer_limit',
		'voted_comment' => 'voted_comment_limit',
		'blamed_comment' => 'blamed_comment_limit',
		'voter_comment' => 'voter_comment_limit',
		'blamer_comment' => 'blamer_comment_limit',
	];
	public const NOTICE_KEYS = [
		'read_document' => 'read_document_except_notice',
		'read_document_author' => 'read_document_author_except_notice',
	];
	public const REVERT_KEYS = [
		'insert_document' => 'insert_document_revert_on_delete',
		'insert_comment' => 'insert_comment_revert_on_delete',
		'upload_file' => 'upload_file_revert_on_delete',
		'first_document_point' => 'first_document_revert',
	];

	public function init()
	{
	}

	protected static function actor(): int
	{
		$logged_info = Context::get('logged_info');
		return is_object($logged_info) ? intval($logged_info->member_srl) : 0;
	}

	protected function addAll(array $values): void
	{
		foreach ($values as $key => $value)
		{
			$this->add($key, $value);
		}
	}

	/**
	 * Summary screen.
	 */
	public function getPointAdminDashboard()
	{
		$config = Config::get();
		$week = Context::get('week') === 'last' ? 'last' : 'this';
		$full = Config::isStatsEnabled($config) && Stats::tablesExist();
		if ($full && !Stats::hasTask() && Stats::isStale())
		{
			@set_time_limit(120);
			Stats::run(Stats::RUN_ROWS);
		}
		$full = $full && Stats::isBuilt();

		$holdings = Stats::holdings();
		$data = [
			'mode' => $full ? 'full' : 'basic',
			'ledger' => Config::isLedgerReady($config),
			'stats' => Config::isStatsEnabled($config),
			'holdings' => $holdings,
			'storage_rows' => Config::isLedgerReady($config) ? Stats::storage()->rows : 0,
		];

		if (!$full)
		{
			$richest = Stats::richest(5);
			$cards = AdminData::memberCards(array_keys($richest), false);
			$top = [];
			foreach ($richest as $srl => $point)
			{
				if (isset($cards[$srl]))
				{
					$top[] = ['member' => $cards[$srl], 'value' => $point];
				}
			}
			$data['top'] = $top;
			$data['highest'] = $top ? ['level' => $top[0]['member']['level'], 'nick' => $top[0]['member']['nick']] : null;
			$this->addAll($data);
			return;
		}

		$stats = Stats::week($week);
		$monday = $stats->monday;
		$top_raw = Stats::topMembers($monday, 5);
		$cards = AdminData::memberCards(array_keys($top_raw));
		$top = [];
		foreach ($top_raw as $srl => $earned)
		{
			if (isset($cards[$srl]))
			{
				$top[] = ['member' => $cards[$srl], 'value' => $earned];
			}
		}
		$rules = [];
		foreach ($stats->rules as $reason => $earned)
		{
			$rules[] = ['label' => self::reasonTitle((string)$reason), 'value' => $earned];
		}
		$days = [];
		foreach ($stats->days as $ymd => $values)
		{
			$days[] = ['ymd' => $ymd, 'earned' => $values['earned'], 'spent' => $values['spent']];
		}
		$data['week'] = [
			'which' => $week,
			'monday' => $monday,
			'sunday' => $stats->sunday,
			'end' => $stats->end,
			'earned' => $stats->earned,
			'spent' => $stats->spent,
			'change' => $stats->prev_earned > 0 ? intval(round(($stats->earned - $stats->prev_earned) * 100 / $stats->prev_earned)) : null,
			'top_spend' => $stats->top_spend ? ['label' => self::reasonTitle((string)$stats->top_spend[0]), 'value' => $stats->top_spend[1]] : null,
			'admin_given' => $stats->admin_given,
			'admin_count' => $stats->admin_count,
			'admin_takes' => $stats->admin_takes,
			'top' => $top,
			'rules' => $rules,
			'days' => $days,
		];
		$data['last_run'] = (string)State::get('stats_last');
		$this->addAll($data);
	}

	/**
	 * Short name of a reason for charts and cards.
	 */
	public static function reasonTitle(string $reason): string
	{
		$label = AdminData::text('pa_reason_' . $reason, '');
		if ($label === '')
		{
			$label = Admin::reasonLabel($reason);
			if (preg_match('/^(.+) · [a-z0-9_:]+$/', $label, $m))
			{
				$label = $m[1];
			}
		}
		return $label;
	}

	/**
	 * Rules table: every activity value with its conditions, and the boards that set their own values.
	 */
	public function getPointAdminRules()
	{
		$config = Config::get();
		$keys = array_merge(['signup_point', 'login_point', 'first_document_point'], Admin::moduleNumericKeys());
		$values = [];
		foreach ($keys as $key)
		{
			$values[$key] = intval($config->$key ?? 0);
		}
		$limits = [];
		foreach (self::LIMIT_KEYS as $key => $limit_key)
		{
			$limits[$key] = intval($key === 'insert_comment' ? (($config->insert_comment_limit ?? 0) ?: ($config->no_point_date ?? 0)) : ($config->$limit_key ?? 0));
		}
		$notice = [];
		foreach (self::NOTICE_KEYS as $key => $notice_key)
		{
			$notice[$key] = !empty($config->$notice_key);
		}
		$revert = [];
		foreach (self::REVERT_KEYS as $key => $revert_key)
		{
			$revert[$key] = $key === 'first_document_point' ? (($config->first_document_revert ?? 'N') === 'Y') : (($config->$revert_key ?? true) !== false);
		}

		$boards = [];
		$own = [];
		foreach (\ModuleModel::getModulePartConfigs('point') ?: [] as $module_srl => $part)
		{
			$own[] = intval($module_srl);
		}
		$own_where = $own ? ' OR modules.module_srl IN (' . implode(',', $own) . ')' : '';
		$rows = DB::getInstance()->query("SELECT modules.module_srl, modules.mid, modules.module, modules.browser_title, modules.domain_srl FROM modules WHERE modules.module = 'board' OR EXISTS (SELECT 1 FROM documents WHERE documents.module_srl = modules.module_srl)" . $own_where . " ORDER BY modules.domain_srl, modules.module_srl")->fetchAll();
		$domains = AdminData::domains();
		foreach ($rows as $row)
		{
			$boards[] = [
				'srl' => intval($row->module_srl),
				'title' => html_entity_decode(Context::replaceUserLang((string)$row->browser_title)) ?: (string)$row->mid,
				'domain' => $domains[intval($row->domain_srl)] ?? '',
			];
		}
		$overrides = [];
		foreach (\ModuleModel::getModulePartConfigs('point') ?: [] as $module_srl => $part)
		{
			$part = is_object($part) ? get_object_vars($part) : (array)$part;
			foreach (Admin::moduleNumericKeys() as $key)
			{
				if (isset($part[$key]) && $part[$key] !== '' && $part[$key] !== null)
				{
					$overrides[$key][intval($module_srl)] = intval($part[$key]);
				}
			}
		}

		$this->addAll([
			'values' => $values,
			'limits' => $limits,
			'notice' => $notice,
			'revert' => $revert,
			'boards' => $boards,
			'overrides' => (object)$overrides,
			'ledger' => Config::isLedgerReady($config),
		]);
	}

	/**
	 * Save one rule value or one rule condition.
	 */
	public function procPointAdminSaveRule()
	{
		$key = (string)Context::get('key');
		$raw = trim((string)Context::get('value'));
		$config = Config::get();
		$numeric = array_merge(['signup_point', 'login_point', 'first_document_point'], Admin::moduleNumericKeys());
		$values = [];

		if (in_array($key, $numeric, true))
		{
			if (!preg_match('/^-?[0-9]{1,9}$/', $raw))
			{
				throw new Exception('point.msg_invalid_amount');
			}
			$old = intval($config->$key ?? 0);
			$new = $key === 'first_document_point' ? max(0, intval($raw)) : intval($raw);
			$values[$key] = $new;
		}
		elseif (in_array($key, self::LIMIT_KEYS, true))
		{
			$new = max(0, min(36500, intval($raw)));
			$old = intval($key === 'insert_comment_limit' ? (($config->insert_comment_limit ?? 0) ?: ($config->no_point_date ?? 0)) : ($config->$key ?? 0));
			$values[$key] = $new;
			if ($key === 'insert_comment_limit')
			{
				$values['no_point_date'] = $new;
			}
		}
		elseif (in_array($key, self::NOTICE_KEYS, true))
		{
			$old = !empty($config->$key);
			$new = $raw === 'Y';
			$values[$key] = $new;
		}
		elseif ($key === 'first_document_revert')
		{
			$old = ($config->first_document_revert ?? 'N') === 'Y';
			$new = $raw === 'Y';
			$values[$key] = $new ? 'Y' : 'N';
		}
		elseif (in_array($key, self::REVERT_KEYS, true))
		{
			$old = ($config->$key ?? true) !== false;
			$new = $raw === 'Y';
			$values[$key] = $new;
		}
		else
		{
			throw new InvalidRequest;
		}

		$output = Config::update($values);
		if (!$output->toBool())
		{
			return $output;
		}
		$this->addAll(['key' => $key, 'old' => $old, 'value' => $new]);
	}

	/**
	 * Save or clear one value of one board. Other values of that board are kept.
	 */
	public function procPointAdminSaveModuleRule()
	{
		$module_srl = intval(Context::get('module_srl'));
		$key = (string)Context::get('key');
		$raw = trim((string)Context::get('value'));
		if (!$module_srl || !in_array($key, Admin::moduleNumericKeys(), true))
		{
			throw new InvalidRequest;
		}
		if (!\ModuleModel::getModuleInfoByModuleSrl($module_srl))
		{
			throw new TargetNotFound;
		}
		if ($raw !== '' && !preg_match('/^-?[0-9]{1,9}$/', $raw))
		{
			throw new Exception('point.msg_invalid_amount');
		}
		$part = Admin::modulePartConfig($module_srl);
		$old = (isset($part[$key]) && $part[$key] !== '') ? intval($part[$key]) : null;
		if ($raw === '')
		{
			unset($part[$key]);
		}
		else
		{
			$part[$key] = intval($raw);
		}
		$output = getController('module')->insertModulePartConfig('point', $module_srl, $part);
		if (is_object($output) && method_exists($output, 'toBool') && !$output->toBool())
		{
			return $output;
		}
		$this->addAll(['module_srl' => $module_srl, 'key' => $key, 'old' => $old, 'value' => $raw === '' ? null : intval($raw)]);
	}

	/**
	 * Members with their balances.
	 *
	 * Sorts: point (balance, highest first), recent (newest member first), last (earned last week, needs summaries).
	 * Pages continue from a cursor; the total number of members is never counted.
	 */
	public function getPointAdminMemberList()
	{
		$oDB = DB::getInstance();
		$config = Config::get();
		$sort = (string)Context::get('sort');
		$stats = Config::isStatsEnabled($config) && Stats::tablesExist();
		if (!in_array($sort, ['point', 'recent', 'last'], true) || ($sort === 'last' && !$stats))
		{
			$sort = 'point';
		}
		$group_srl = max(0, intval(Context::get('group_srl')));
		$q = trim((string)Context::get('q'));
		$cursor = (string)Context::get('cursor');
		[$member_where, $member_params] = self::legacyMemberCondition((string)Context::get('search_target'), trim((string)Context::get('search_keyword')));

		$group_join = $group_srl ? ' INNER JOIN member_group_member ON member_group_member.member_srl = %s.member_srl AND member_group_member.group_srl = ?' : '';
		$group_exists = '';
		if ($group_srl && $q === '' && self::groupSize($group_srl) >= self::DENSE_GROUP)
		{
			$group_exists = '(SELECT 1 FROM member_group_member WHERE member_group_member.group_srl = ? AND member_group_member.member_srl = %s.member_srl LIMIT 1) IS NOT NULL';
		}
		$next = '';
		$srls = [];

		if ($q !== '')
		{
			$like = addcslashes($q, '%_\\') . '%';
			$params = [$like, $like, $like];
			$sql = 'SELECT member.member_srl FROM member' . ($group_srl ? sprintf($group_join, 'member') : '') . ' WHERE (member.user_id LIKE ? OR member.nick_name LIKE ? OR member.email_address LIKE ?)';
			if ($group_srl)
			{
				array_splice($params, 0, 0, [$group_srl]);
			}
			if ($member_where)
			{
				$sql .= ' AND ' . $member_where;
				$params = array_merge($params, $member_params);
			}
			$rows = $oDB->query($sql . ' LIMIT ' . self::SEARCH_LIMIT, $params)->fetchAll();
			$srls = array_map('intval', array_column($rows, 'member_srl'));
		}
		elseif ($sort === 'recent')
		{
			$params = $group_srl ? [$group_srl] : [];
			$where = $group_exists ? [sprintf($group_exists, 'member')] : [];
			if (ctype_digit($cursor) && $cursor !== '')
			{
				$where[] = 'member.member_srl < ?';
				$params[] = intval($cursor);
			}
			if ($member_where)
			{
				$where[] = $member_where;
				$params = array_merge($params, $member_params);
			}
			$rows = $oDB->query('SELECT member.member_srl FROM member' . ($group_srl && !$group_exists ? sprintf($group_join, 'member') : '') . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY member.member_srl DESC LIMIT ' . (self::PAGE + 1), $params)->fetchAll();
			if (count($rows) > self::PAGE)
			{
				$rows = array_slice($rows, 0, self::PAGE);
				$next = (string)end($rows)->member_srl;
			}
			$srls = array_map('intval', array_column($rows, 'member_srl'));
		}
		elseif ($sort === 'last')
		{
			$week = date('Ymd', strtotime('-7 days', strtotime(Stats::weekStart())));
			$params = $group_srl ? [$group_srl, $week] : [$week];
			$where = $group_exists ? [sprintf($group_exists, 'point_stats_top'), 'point_stats_top.week = ?'] : ['point_stats_top.week = ?'];
			if (preg_match('/^(\d+):(\d+)$/', $cursor, $m))
			{
				$where[] = '(point_stats_top.earned < ? OR (point_stats_top.earned = ? AND point_stats_top.member_srl < ?))';
				array_push($params, intval($m[1]), intval($m[1]), intval($m[2]));
			}
			$join_member = '';
			if ($member_where)
			{
				$join_member = ' INNER JOIN member ON member.member_srl = point_stats_top.member_srl';
				$where[] = $member_where;
				$params = array_merge($params, $member_params);
			}
			$rows = $oDB->query('SELECT point_stats_top.member_srl, point_stats_top.earned FROM point_stats_top' . ($group_srl && !$group_exists ? sprintf($group_join, 'point_stats_top') : '') . $join_member . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY point_stats_top.earned DESC, point_stats_top.member_srl DESC LIMIT ' . (self::PAGE + 1), $params)->fetchAll();
			if (count($rows) > self::PAGE)
			{
				$rows = array_slice($rows, 0, self::PAGE);
				$last = end($rows);
				$next = intval($last->earned) . ':' . intval($last->member_srl);
			}
			$srls = array_map('intval', array_column($rows, 'member_srl'));
		}
		else
		{
			$params = $group_srl ? [$group_srl] : [];
			$where = $group_exists ? [sprintf($group_exists, 'point')] : [];
			if (preg_match('/^(-?\d+):(\d+)$/', $cursor, $m))
			{
				$where[] = '(point.point < ? OR (point.point = ? AND point.member_srl < ?))';
				array_push($params, intval($m[1]), intval($m[1]), intval($m[2]));
			}
			$join_member = '';
			if ($member_where)
			{
				$join_member = ' INNER JOIN member ON member.member_srl = point.member_srl';
				$where[] = $member_where;
				$params = array_merge($params, $member_params);
			}
			$rows = $oDB->query('SELECT point.member_srl, point.point FROM point' . ($group_srl && !$group_exists ? sprintf($group_join, 'point') : '') . $join_member . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY point.point DESC, point.member_srl DESC LIMIT ' . (self::PAGE + 1), $params)->fetchAll();
			if (count($rows) > self::PAGE)
			{
				$rows = array_slice($rows, 0, self::PAGE);
				$last = end($rows);
				$next = intval($last->point) . ':' . intval($last->member_srl);
			}
			$srls = array_map('intval', array_column($rows, 'member_srl'));
		}

		$cards = AdminData::memberCards($srls);
		$this_week = $stats ? Stats::weekEarned($srls, Stats::weekStart()) : [];
		$last_week = $stats && $sort === 'last' ? Stats::weekEarned($srls, date('Ymd', strtotime('-7 days', strtotime(Stats::weekStart())))) : [];
		$list = [];
		foreach ($srls as $srl)
		{
			if (!isset($cards[$srl]))
			{
				continue;
			}
			$card = $cards[$srl];
			$card['week'] = $sort === 'last' ? ($last_week[$srl] ?? 0) : ($this_week[$srl] ?? 0);
			$list[] = $card;
		}
		if ($q !== '')
		{
			usort($list, function ($a, $b) {
				return $b['point'] <=> $a['point'] ?: $b['srl'] <=> $a['srl'];
			});
		}

		$this->addAll(['rows' => $list, 'next' => $next, 'sort' => $sort, 'stats' => $stats, 'capped' => $q !== '' && count($srls) >= self::SEARCH_LIMIT]);
	}

	/**
	 * Members in a group, counted up to DENSE_GROUP only.
	 */
	protected static function groupSize(int $group_srl): int
	{
		$row = DB::getInstance()->query('SELECT COUNT(*) AS c FROM (SELECT 1 FROM member_group_member WHERE group_srl = ? LIMIT ' . self::DENSE_GROUP . ') AS sample', [$group_srl])->fetchAll()[0] ?? null;
		return intval($row->c ?? 0);
	}

	/**
	 * A condition on the member table for the legacy "search target" parameters.
	 *
	 * @return array [where, params]
	 */
	public static function legacyMemberCondition(string $target, string $keyword): array
	{
		if ($keyword === '' || !in_array($target, self::LEGACY_TARGETS, true))
		{
			return ['', []];
		}
		$digits = preg_replace('/[^0-9]/', '', $keyword);
		switch ($target)
		{
			case 'user_id':
			case 'user_name':
			case 'nick_name':
			case 'email_address':
				return ['member.' . $target . ' LIKE ?', ['%' . str_replace(' ', '%', addcslashes($keyword, '%_\\')) . '%']];
			case 'extra_vars':
				return ['member.extra_vars LIKE ?', ['%' . addcslashes($keyword, '%_\\') . '%']];
			case 'regdate':
			case 'last_login':
				return ['member.' . $target . ' LIKE ?', [$digits . '%']];
			case 'regdate_more':
			case 'last_login_more':
				return ['member.' . substr($target, 0, -5) . ' >= ?', [str_pad($digits, 14, '0')]];
			case 'regdate_less':
			case 'last_login_less':
				return ['member.' . substr($target, 0, -5) . ' <= ?', [str_pad($digits, 14, '9')]];
		}
		return ['', []];
	}

	/**
	 * One member's balance, level and recent history (for the side panel).
	 */
	public function getPointAdminMemberPoint()
	{
		$data = AdminData::memberPoint(intval(Context::get('member_srl')), 5);
		if (!$data)
		{
			throw new TargetNotFound('point.msg_member_not_found');
		}
		$this->addAll($data);
	}

	/**
	 * History lines, newest first, continued with a cursor.
	 */
	public function getPointAdminLedgerPage()
	{
		if (!Config::isLedgerReady())
		{
			$this->addAll(['rows' => [], 'next' => '', 'more_before' => 0, 'ledger' => false]);
			return;
		}
		$filter = [];
		$member_srl = intval(Context::get('member_srl'));
		if ($member_srl)
		{
			$filter['member_srl'] = $member_srl;
		}
		$kind = (string)Context::get('kind');
		if (in_array($kind, Ledger::KINDS, true))
		{
			$filter['kind'] = $kind;
		}
		$type = (string)Context::get('type');
		if (in_array($type, Ledger::TYPES, true))
		{
			$filter['type'] = $type;
		}
		$direction = (string)Context::get('direction');
		if (in_array($direction, ['plus', 'minus'], true))
		{
			$filter['direction'] = $direction;
		}
		$reason = strtolower(trim((string)Context::get('reason_code')));
		if (preg_match('/^[a-z0-9_.:\-]{1,40}$/', $reason))
		{
			$filter['reason_code'] = $reason;
		}
		if (Context::get('actor_only') === 'Y')
		{
			$filter['actor_only'] = true;
		}
		$date_from = preg_replace('/[^0-9]/', '', (string)Context::get('date_from'));
		$date_to = preg_replace('/[^0-9]/', '', (string)Context::get('date_to'));
		$date_from = strlen($date_from) === 8 ? $date_from : '';
		$date_to = strlen($date_to) === 8 ? $date_to : '';

		$narrow = !$member_srl && (!empty($filter['kind']) || !empty($filter['type']) || !empty($filter['reason_code']) || !empty($filter['direction']) || !empty($filter['actor_only']));
		if ($narrow)
		{
			$date_to = $date_to ?: date('Ymd');
			$earliest = date('Ymd', strtotime('-' . (self::NARROW_DAYS - 1) . ' days', strtotime($date_to)));
			if (!$date_from || $date_from < $earliest)
			{
				$date_from = $earliest;
			}
		}
		if ($date_from)
		{
			$filter['date_from'] = $date_from;
		}
		if ($date_to)
		{
			$filter['date_to'] = $date_to;
		}
		if (!$member_srl)
		{
			if ($date_from)
			{
				$filter['srl_from'] = Ledger::firstSrlFrom($date_from . '000000');
			}
			if ($date_to && $date_to < date('Ymd'))
			{
				$filter['srl_to'] = Ledger::firstSrlFrom(date('Ymd', strtotime('+1 day', strtotime($date_to))) . '000000');
			}
		}

		$cursor = (string)Context::get('cursor');
		$before = intval(Context::get('before'));
		if ($before > 0 && !$member_srl)
		{
			$filter['srl_to'] = $before;
			$cursor = '';
		}
		$page = Ledger::page($filter, $cursor, self::PAGE);
		$member = $member_srl ? (AdminData::memberCards([$member_srl])[$member_srl] ?? null) : null;
		$this->addAll([
			'rows' => AdminData::decorateRows($page->items),
			'next' => $page->next,
			'more_before' => $page->more_before,
			'date_from' => $date_from,
			'date_to' => $date_to,
			'narrow' => $narrow,
			'member' => $member,
			'ledger' => true,
		]);
	}

	/**
	 * Take back points given by an administrator: a new line with the opposite amount.
	 */
	public function procPointAdminRevert()
	{
		if (!Config::isLedgerReady())
		{
			throw new Exception('point.msg_ledger_not_ready');
		}
		$output = self::revertRow(intval(Context::get('ledger_srl')), self::actor());
		if (!$output->toBool())
		{
			return $output;
		}
		$this->addAll(['balance' => $output->get('balance'), 'ledger_srl' => $output->get('ledger_srl'), 'member_srl' => $output->get('member_srl'), 'amount' => $output->get('amount')]);
	}

	public static function revertRow(int $ledger_srl, int $actor_srl, bool $allow_take = false): \BaseObject
	{
		$row = DB::getInstance()->query('SELECT * FROM point_ledger WHERE ledger_srl = ?', [$ledger_srl])->fetchAll()[0] ?? null;
		if (!$row)
		{
			return new \BaseObject(-1, 'point.msg_ledger_row_not_found');
		}
		$is_give = $row->type === 'admin_add' && intval($row->amount) > 0;
		$is_take = $allow_take && $row->type === 'admin_take' && intval($row->amount) < 0;
		if ((!$is_give && !$is_take) || $row->reason_code === 'admin_revert')
		{
			return new \BaseObject(-1, 'point.msg_revert_admin_only');
		}
		$memo = trim((string)$row->memo) ?: 'admin';
		$output = PointService::adjust(intval($row->member_srl), -intval($row->amount), $memo, $actor_srl, [
			'reason_code' => 'admin_revert',
			'idem_key' => 'revert:' . $ledger_srl,
			'ref_type' => 'ledger',
			'ref_srl' => $ledger_srl,
		]);
		if ($output->toBool() && $output->get('duplicate'))
		{
			return new \BaseObject(-1, 'point.msg_already_reverted');
		}
		if ($output->toBool())
		{
			$output->add('member_srl', intval($row->member_srl));
		}
		return $output;
	}

	/**
	 * Give or take the same amount to several members.
	 *
	 * Input: member_srls (array or comma separated), amount (signed), memo, batch_key (optional).
	 * A repeated call with the same batch_key does not give twice while history is on.
	 */
	public function procPointAdminBulkAdjust()
	{
		$input = Context::get('member_srls');
		$input = is_array($input) ? $input : explode(',', (string)$input);
		$srls = array_values(array_unique(array_filter(array_map('intval', $input))));
		$amount = intval(Context::get('amount'));
		$memo = trim((string)Context::get('memo'));
		if (!$srls || count($srls) > self::BULK_LIMIT || $amount === 0)
		{
			throw new Exception('point.msg_invalid_amount');
		}
		if (abs($amount) > self::BULK_AMOUNT_LIMIT)
		{
			throw new Exception('point.msg_bulk_amount_limit');
		}
		$ready = Config::isLedgerReady();
		if ($memo === '')
		{
			if ($ready)
			{
				throw new Exception('point.msg_reason_required');
			}
			$memo = 'admin';
		}
		$batch_key = trim((string)Context::get('batch_key'));
		if ($batch_key !== '' && !preg_match('/^[A-Za-z0-9_\-]{6,40}$/', $batch_key))
		{
			throw new InvalidRequest;
		}
		if (!$ready && $batch_key !== '')
		{
			if (\Zittme\Framework\Cache::get('point_bulk:' . $batch_key))
			{
				throw new InvalidRequest;
			}
			\Zittme\Framework\Cache::set('point_bulk:' . $batch_key, 1, 600);
		}
		@set_time_limit(0);
		$batch = $batch_key !== '' ? $batch_key : date('YmdHis') . str_pad((string)mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
		$actor = self::actor();
		$done = [];
		$done_list = [];
		$failed = [];
		$existing = [];
		foreach (DB::getInstance()->query('SELECT member_srl FROM member WHERE member_srl IN (' . implode(',', array_fill(0, count($srls), '?')) . ')', $srls)->fetchAll() as $row)
		{
			$existing[intval($row->member_srl)] = true;
		}
		foreach ($srls as $srl)
		{
			if (!isset($existing[$srl]))
			{
				$failed[] = [$srl, lang('point.msg_member_not_found')];
				continue;
			}
			$output = PointService::adjust($srl, $amount, $memo, $actor, $ready ? ['idem_key' => 'bulk:' . $batch . ':' . $srl] : []);
			if ($output->toBool())
			{
				$done[$srl] = intval($output->get('balance'));
				$done_list[] = [$srl, intval($output->get('balance'))];
			}
			else
			{
				$failed[] = [$srl, lang($output->getMessage())];
			}
		}
		$this->addAll([
			'batch_id' => $batch,
			'batch' => $batch,
			'done' => $done_list,
			'failed' => $failed,
			'count' => count($done),
			'balances' => (object)$done,
			'amount' => $amount,
			'ledger' => $ready,
		]);
	}

	/**
	 * Undo a whole batch made with procPointAdminBulkAdjust: each line gets an opposite line.
	 *
	 * Input: batch_id. Needs point history; without it, call procPointAdminBulkAdjust with the opposite amount.
	 */
	public function procPointAdminRevertBatch()
	{
		if (!Config::isLedgerReady())
		{
			throw new Exception('point.msg_ledger_not_ready');
		}
		$batch = (string)(Context::get('batch_id') ?? Context::get('batch'));
		if ($batch === '')
		{
			$batch = (string)Context::get('batch');
		}
		if (!preg_match('/^[A-Za-z0-9_\-]{6,40}$/', $batch))
		{
			throw new InvalidRequest;
		}
		@set_time_limit(0);
		$rows = DB::getInstance()->query('SELECT ledger_srl FROM point_ledger WHERE idem_key LIKE ?', ['bulk:' . str_replace('_', '\\_', $batch) . ':%'])->fetchAll();
		$actor = self::actor();
		$done = [];
		$failed = [];
		$balances = [];
		foreach ($rows as $row)
		{
			$output = self::revertRow(intval($row->ledger_srl), $actor, true);
			if ($output->toBool())
			{
				$srl = intval($output->get('member_srl'));
				$balances[$srl] = intval($output->get('balance'));
				$done[] = [$srl, intval($output->get('balance'))];
			}
			else
			{
				$failed[] = [intval($row->ledger_srl), lang($output->getMessage())];
			}
		}
		$this->addAll(['batch_id' => $batch, 'count' => count($done), 'done' => $done, 'failed' => $failed, 'balances' => (object)$balances]);
	}

	/**
	 * Levels: thresholds, icons, linked groups and, with summaries, members per level.
	 */
	public function getPointAdminLevels()
	{
		$config = Config::get();
		$max = max(1, intval($config->max_level ?? 30));
		$steps = [];
		for ($i = 1; $i <= $max; $i++)
		{
			$steps[] = intval($config->level_step[$i] ?? 0);
		}
		$sets = [];
		foreach (\FileHandler::readDir(\RX_BASEDIR . 'modules/point/icons') ?: [] as $dir)
		{
			$type = Admin::detectIconType(\RX_BASEDIR . 'modules/point/icons/' . $dir);
			if ($type !== '')
			{
				$sets[] = ['id' => $dir, 'type' => $type];
			}
		}
		$counts = null;
		if (Config::isStatsEnabled($config))
		{
			$buckets = Stats::buckets();
			if ($buckets)
			{
				$counts = Stats::levelCounts($buckets, $config->level_step ?? [], $max);
			}
		}
		$links = [];
		foreach (($config->point_group ?? []) as $group_srl => $level)
		{
			$links[intval($group_srl)] = intval($level);
		}
		$this->addAll([
			'max_level' => $max,
			'steps' => $steps,
			'icon' => (string)($config->level_icon ?? 'default'),
			'icon_type' => (string)($config->level_icon_type ?? 'gif'),
			'sets' => $sets,
			'links' => (object)$links,
			'group_reset' => ($config->group_reset ?? 'Y') !== 'N',
			'group_ratchet' => ($config->group_ratchet ?? 'N') === 'Y',
			'expression' => (string)($config->expression ?? ''),
			'counts' => $counts,
		]);
	}

	/**
	 * Save the threshold of one level, or every threshold at once (steps).
	 */
	public function procPointAdminSaveLevelStep()
	{
		$config = Config::get();
		$max = max(1, intval($config->max_level ?? 30));
		$old_steps = [];
		for ($i = 1; $i <= $max; $i++)
		{
			$old_steps[$i] = intval($config->level_step[$i] ?? 0);
		}
		$steps = $old_steps;
		$level = intval(Context::get('level'));
		$list = trim((string)Context::get('steps'));
		$moved = null;
		if ($list !== '')
		{
			$values = array_map('intval', explode(',', $list));
			for ($i = 1; $i <= $max; $i++)
			{
				$steps[$i] = $values[$i - 1] ?? end($values);
			}
		}
		elseif ($level >= 1 && $level <= $max)
		{
			$steps[$level] = max(0, intval(Context::get('value')));
			if (Config::isStatsEnabled($config))
			{
				$buckets = Stats::buckets();
				if ($buckets)
				{
					$moved = Stats::membersBetween($buckets, $old_steps[$level], $steps[$level]);
				}
			}
		}
		else
		{
			throw new InvalidRequest;
		}
		$values = ['level_step' => $steps];
		if (Context::get('expression') !== null && $list !== '')
		{
			$values['expression'] = mb_substr(trim((string)Context::get('expression')), 0, 200);
		}
		$output = Config::update($values);
		if (!$output->toBool())
		{
			return $output;
		}
		$this->addAll(['level' => $level, 'old' => $level ? $old_steps[$level] : null, 'value' => $level ? $steps[$level] : null, 'steps' => array_values($steps), 'old_steps' => array_values($old_steps), 'moved' => $moved]);
	}

	/**
	 * Save one setting. Only the keys below are accepted.
	 */
	public function procPointAdminSaveConfigKey()
	{
		$key = (string)Context::get('key');
		$raw = trim((string)Context::get('value'));
		$config = Config::get();
		$yn = $raw === 'Y' ? 'Y' : 'N';
		$old = $config->$key ?? null;
		$values = [];

		switch ($key)
		{
			case 'point_name':
				$values[$key] = mb_substr($raw, 0, 20) ?: 'point';
				break;
			case 'able_module':
				$values[$key] = $yn;
				break;
			case 'disable_download':
			case 'disable_read_document':
			case 'disable_read_document_except_robots':
			case 'big_confirm':
			case 'mypage_enabled':
				$values[$key] = $yn;
				break;
			case 'group_reset':
			case 'group_ratchet':
				$values[$key] = $yn;
				break;
			case 'max_level':
				$max = intval($raw);
				if ($max < 1 || $max > 1000)
				{
					throw new Exception('point.msg_max_level_range');
				}
				$steps = [];
				$last = 0;
				for ($i = 1; $i <= $max; $i++)
				{
					$last = isset($config->level_step[$i]) ? intval($config->level_step[$i]) : ($last ?: 0);
					$steps[$i] = $last;
				}
				$values['max_level'] = $max;
				$values['level_step'] = $steps;
				break;
			case 'level_icon':
				$set = basename($raw);
				$type = Admin::detectIconType(\RX_BASEDIR . 'modules/point/icons/' . $set);
				if ($set === '' || $type === '')
				{
					throw new Exception('point.msg_level_icon_not_found');
				}
				$values['level_icon'] = $set;
				$values['level_icon_type'] = $type;
				break;
			case 'point_group':
				$map = json_decode($raw, true);
				if (!is_array($map))
				{
					throw new InvalidRequest;
				}
				$max = max(1, intval($config->max_level ?? 30));
				$allowed = [];
				foreach (\MemberModel::getGroups() ?: [] as $group)
				{
					if ($group->is_admin !== 'Y' && $group->is_default !== 'Y')
					{
						$allowed[intval($group->group_srl)] = true;
					}
				}
				$clean = [];
				foreach ($map as $group_srl => $level)
				{
					$group_srl = intval($group_srl);
					$level = intval($level);
					if (isset($allowed[$group_srl]) && $level >= 1)
					{
						$clean[$group_srl] = min($max, $level);
					}
				}
				$values['point_group'] = $clean;
				break;
			case 'expression':
				$values[$key] = mb_substr($raw, 0, 200);
				break;
			case 'limit_enabled':
				self::requireLedger();
				if ($yn === 'Y' && !intval($config->limit_daily ?? 0) && !intval($config->limit_monthly ?? 0) && trim((string)($config->limit_reasons ?? '')) === '')
				{
					throw new Exception('point.msg_limit_empty');
				}
				$values[$key] = $yn;
				break;
			case 'limit_daily':
			case 'limit_monthly':
				self::requireLedger();
				$values[$key] = max(0, intval($raw));
				break;
			case 'limit_reasons':
				self::requireLedger();
				$values[$key] = Admin::normalizeReasonLimits((string)Context::get('value'));
				break;
			case 'expire_enabled':
				self::requireLedger();
				$values[$key] = $yn;
				break;
			case 'expire_months':
				self::requireLedger();
				$values[$key] = max(1, min(120, intval($raw)));
				break;
			case 'retention':
				self::requireLedger();
				$months = intval($raw);
				$old = Config::isRetentionEnabled($config) ? intval($config->retention_months) : 0;
				if ($months <= 0)
				{
					$values['retention_enabled'] = 'N';
				}
				else
				{
					$values['retention_enabled'] = 'Y';
					$values['retention_months'] = max(Config::MIN_RETENTION_MONTHS, min(240, $months));
				}
				break;
			default:
				throw new InvalidRequest;
		}

		$output = Config::update($values);
		if (!$output->toBool())
		{
			return $output;
		}
		$config = Config::get();
		if ($key === 'able_module')
		{
			if ($yn === 'Y')
			{
				$this->installLedgerTables();
				$this->registerTriggers();
				$this->syncMemberMenuTrigger(($config->mypage_enabled ?? 'N') === 'Y');
			}
			else
			{
				getController('module')->deleteModuleTriggers('point');
			}
		}
		if ($key === 'mypage_enabled' && ($config->able_module ?? 'N') === 'Y')
		{
			$this->syncMemberMenuTrigger($yn === 'Y');
		}
		$this->addAll(['key' => $key, 'old' => is_object($old) ? get_object_vars($old) : $old, 'value' => $key === 'retention' ? (Config::isRetentionEnabled($config) ? intval($config->retention_months) : 0) : ($values[$key] ?? null)]);
	}

	protected static function requireLedger(): void
	{
		if (!Config::isLedgerReady())
		{
			throw new Exception('point.msg_ledger_not_ready');
		}
	}

	/**
	 * Turn point history on. Existing balances are brought in as opening lines by the batch
	 * that the screen runs next (job "migrate", or "reconcile" when history was on before).
	 */
	public function procPointAdminEnableLedger()
	{
		$output = $this->setLedgerRecording(true);
		if (!$output->toBool())
		{
			return $output;
		}
		$values = [];
		if (Context::get('retention') === 'Y')
		{
			$config = Config::get();
			$values['retention_enabled'] = 'Y';
			$values['retention_months'] = max(Config::MIN_RETENTION_MONTHS, intval($config->retention_months ?? 24) ?: 24);
		}
		if (Context::get('stats') === 'Y')
		{
			$values['stats_enabled'] = 'Y';
		}
		if ($values)
		{
			Config::update($values);
		}
		if (!empty($values['stats_enabled']))
		{
			$output = Stats::start();
			if (!$output->toBool())
			{
				return $output;
			}
		}
		$job = 'migrate';
		if (State::get('migrate_done') === 'Y')
		{
			Migration::startReconcile();
			$job = 'reconcile';
		}
		$this->addAll(['job' => $job, 'stats' => !empty($values['stats_enabled'])]);
	}

	/**
	 * Stop recording point history. Lines already kept stay as they are.
	 */
	public function procPointAdminDisableLedger()
	{
		$output = $this->setLedgerRecording(false);
		if (!$output->toBool())
		{
			return $output;
		}
		Stats::cancelTask();
		$this->add('ledger', false);
	}

	/**
	 * Turn summary collection on or off.
	 */
	public function procPointAdminSetStats()
	{
		$on = Context::get('value') === 'Y';
		if ($on)
		{
			self::requireLedger();
			Config::update(['stats_enabled' => 'Y']);
			$output = Stats::start();
			if (!$output->toBool())
			{
				return $output;
			}
		}
		else
		{
			Config::update(['stats_enabled' => 'N']);
			Stats::cancelTask();
		}
		$this->add('stats', $on);
	}

	/**
	 * Settings screen data: switches, history status and size estimates.
	 */
	public function getPointAdminPolicy()
	{
		$config = Config::get();
		$status = Migration::status();
		$storage = Config::isLedgerReady($config) ? Stats::storage() : (object)['rows' => 0, 'bytes' => 0, 'per_row' => 350];
		$data = [
			'able' => ($config->able_module ?? 'N') === 'Y',
			'point_name' => (string)($config->point_name ?? 'point'),
			'disable_download' => ($config->disable_download ?? 'N') === 'Y',
			'disable_read_document' => ($config->disable_read_document ?? 'N') === 'Y',
			'disable_read_document_except_robots' => ($config->disable_read_document_except_robots ?? 'N') === 'Y',
			'mypage' => ($config->mypage_enabled ?? 'N') === 'Y',
			'big_confirm' => ($config->big_confirm ?? 'N') === 'Y',
			'ledger' => Config::isLedgerReady($config),
			'stats' => Config::isStatsEnabled($config),
			'stats_last' => (string)State::get('stats_last'),
			'stats_task' => Stats::hasTask(),
			'limit_enabled' => ($config->limit_enabled ?? 'N') === 'Y',
			'limit_daily' => intval($config->limit_daily ?? 0),
			'limit_monthly' => intval($config->limit_monthly ?? 0),
			'limit_reasons' => (string)($config->limit_reasons ?? ''),
			'expire_enabled' => ($config->expire_enabled ?? 'N') === 'Y',
			'expire_months' => intval($config->expire_months ?? 12),
			'retention' => Config::isRetentionEnabled($config) ? intval($config->retention_months) : 0,
			'migration' => [
				'busy' => $status->ready && (!$status->done || $status->reconcile_run !== ''),
				'job' => $status->ready && !$status->done ? 'migrate' : ($status->reconcile_run !== '' ? 'reconcile' : ''),
				'done' => $status->done,
				'percent' => $status->percent,
				'total' => $status->total,
				'inserted' => $status->inserted,
				'finished' => (string)State::get('migrate_finished'),
				'reconcile_needed' => $status->reconcile_needed,
			],
			'storage' => $storage,
		];
		if (Context::get('estimate') === 'Y')
		{
			$data['estimate'] = Stats::activityEstimate($config);
			$data['members'] = Migration::estimateMembers();
		}
		if (Context::get('mismatch') === 'Y' && Config::isLedgerReady($config) && $status->done)
		{
			$data['mismatches'] = count(Migration::findMismatches(5));
		}
		$this->addAll($data);
	}

	/**
	 * Where points are given or spent on this site.
	 */
	public function getPointAdminUsage()
	{
		$config = Config::get();
		$week = [];
		if (Config::isStatsEnabled($config) && Stats::tablesExist())
		{
			$monday = Stats::weekStart();
			foreach (DB::getInstance()->query('SELECT reason_code, SUM(earned) AS earned, SUM(spent) AS spent, SUM(cnt_plus) AS cnt_plus, SUM(cnt_minus) AS cnt_minus FROM point_stats_daily WHERE ymd >= ? GROUP BY reason_code', [$monday])->fetchAll() as $row)
			{
				$week[(string)$row->reason_code] = $row;
			}
		}
		$sum = function (array $prefixes) use (&$week) {
			$result = ['plus' => 0, 'minus' => 0, 'count' => 0, 'seen' => false];
			foreach ($week as $reason => $row)
			{
				foreach ($prefixes as $prefix)
				{
					if (strncmp($reason, $prefix, strlen($prefix)) === 0)
					{
						$result['plus'] += intval($row->earned);
						$result['minus'] += intval($row->spent);
						$result['count'] += intval($row->cnt_plus) + intval($row->cnt_minus);
						$result['seen'] = true;
						$row->used = true;
					}
				}
			}
			return $result;
		};
		$installed = function (string $module) {
			return is_dir(\RX_BASEDIR . 'modules/' . $module) && file_exists(\RX_BASEDIR . 'modules/' . $module . '/conf/info.xml');
		};
		$admin_url = function (string $module) {
			$xml = \ModuleModel::getModuleActionXml($module);
			$act = is_object($xml) ? ($xml->admin_index_act ?? '') : '';
			return $act ? getNotEncodedUrl('', 'module', 'admin', 'act', $act) : '';
		};

		$cards = [];
		$add = function (string $id, string $kind, bool $on, array $meta, array $prefixes = []) use (&$cards, $sum, $admin_url, $week) {
			$numbers = $prefixes ? $sum($prefixes) : null;
			$cards[] = [
				'id' => $id,
				'kind' => $kind,
				'on' => $on,
				'meta' => $meta,
				'week' => ($numbers && $numbers['seen']) ? $numbers : null,
				'url' => $admin_url($id === 'board' ? 'board' : $id),
			];
		};

		if ($installed('exchange'))
		{
			$core = false;
			if (class_exists('Zittme\\Modules\\Exchange\\Models\\Config'))
			{
				try
				{
					$core = \Zittme\Modules\Exchange\Models\Config::get()->ledger === 'core';
				}
				catch (\Throwable $e)
				{
					$core = false;
				}
			}
			$add('exchange', $core ? 'spend' : 'separate', $core, [], $core ? ['exchange:', 'exchange_'] : []);
		}
		if ($installed('kin'))
		{
			$add('kin', 'stake', true, [], ['kin_']);
		}
		if ($installed('attendance'))
		{
			$add('attendance', 'give', true, [], ['attendance']);
		}
		if ($installed('profile'))
		{
			$add('profile', 'level', true, [], ['profile_']);
		}
		if ($installed('meetup'))
		{
			$add('meetup', 'give', true, [], ['meetup_']);
		}
		if ($installed('passport'))
		{
			$add('passport', 'give', true, [], ['passport:', 'passport_']);
		}
		if ($installed('reaction'))
		{
			$add('reaction', 'give', true, [], ['reaction']);
		}
		$add('board', 'block', true, [
			($config->disable_download ?? 'N') === 'Y' ? 'download_on' : 'download_off',
			($config->disable_read_document ?? 'N') === 'Y' ? 'read_on' : 'read_off',
		]);
		foreach (['commerce', 'esports', 'lodging', 'reservation'] as $module)
		{
			if ($installed($module))
			{
				$add($module, 'separate', false, []);
			}
		}

		$others = [];
		foreach ($week as $reason => $row)
		{
			if (!empty($row->used) || preg_match('/^(signup|login|insert_|delete_|update_|upload_|download_|read_|voted|blamed|voter|blamer|first_document|admin|expire|recalc|legacy)/', $reason))
			{
				continue;
			}
			$label = self::reasonTitle($reason);
			$others[$label] = ($others[$label] ?? ['plus' => 0, 'minus' => 0, 'count' => 0]);
			$others[$label]['plus'] += intval($row->earned);
			$others[$label]['minus'] += intval($row->spent);
			$others[$label]['count'] += intval($row->cnt_plus) + intval($row->cnt_minus);
		}

		$extra = new \stdClass;
		$extra->cards = [];
		\ModuleHandler::triggerCall('point.getUsage', 'after', $extra);
		$custom = [];
		foreach (is_array($extra->cards) ? $extra->cards : [] as $card)
		{
			$card = (array)$card;
			if (!empty($card['title']))
			{
				$custom[] = [
					'title' => (string)$card['title'],
					'kind' => in_array($card['kind'] ?? '', ['give', 'spend', 'stake', 'level', 'block', 'separate'], true) ? $card['kind'] : 'give',
					'description' => (string)($card['description'] ?? ''),
					'meta' => array_values(array_map('strval', (array)($card['meta'] ?? []))),
					'url' => (string)($card['url'] ?? ''),
					'on' => ($card['kind'] ?? '') !== 'separate',
				];
			}
		}

		$this->addAll(['cards' => $cards, 'custom' => $custom, 'others' => (object)$others, 'stats' => Config::isStatsEnabled($config)]);
	}
}
