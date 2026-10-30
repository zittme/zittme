<?php

namespace Zittme\Modules\Point\Models;

use Zittme\Framework\DB;
use Zittme\Framework\Storage;

/**
 * Summary numbers for the administrator screen.
 *
 * The ledger is never summed on demand. A job that runs once an hour reads
 * the rows added since the last run (by primary key) and adds them to three
 * small tables: totals per day and reason, earnings per week and member, and
 * the number of members per balance bucket. The tables can be emptied at any
 * time and rebuilt from the ledger.
 */
class Stats
{
	public const TABLES = ['point_stats_daily', 'point_stats_top', 'point_stats_balance'];
	public const BUCKET = 100;
	public const RUN_ROWS = 200000;
	public const CHUNK = 20000;
	public const BALANCE_BATCH = 50000;
	public const TOP_WEEKS = 8;
	public const DAILY_DAYS = 400;
	public const INTERVAL = 3600;
	public const TASK_HANDLER = 'Zittme\\Modules\\Point\\Models\\Stats::runTask';

	protected static $_locked = false;

	public static function tablesExist(): bool
	{
		$oDB = DB::getInstance();
		foreach (self::TABLES as $table_name)
		{
			if (!$oDB->isTableExists($table_name))
			{
				return false;
			}
		}
		return true;
	}

	/**
	 * Create the summary tables. Called only when an administrator turns summaries on.
	 */
	public static function installTables(): \BaseObject
	{
		$oDB = DB::getInstance();
		foreach (self::TABLES as $table_name)
		{
			if (!$oDB->isTableExists($table_name))
			{
				$output = $oDB->createTable(\RX_BASEDIR . 'modules/point/schemas/stats/' . $table_name . '.xml');
				if (!$output->toBool())
				{
					return $output;
				}
			}
		}
		return new \BaseObject;
	}

	/**
	 * Monday of the week that contains the given time, as Ymd.
	 */
	public static function weekStart(?int $time = null): string
	{
		$time = $time ?? time();
		$weekday = intval(date('N', $time));
		return date('Ymd', strtotime('-' . ($weekday - 1) . ' days', strtotime(date('Y-m-d', $time))));
	}

	/**
	 * Start collecting from the beginning of last week.
	 */
	public static function start(): \BaseObject
	{
		$output = self::installTables();
		if (!$output->toBool())
		{
			return $output;
		}
		$oDB = DB::getInstance();
		foreach (self::TABLES as $table_name)
		{
			$oDB->query('DELETE FROM ' . $table_name);
		}
		$from_date = date('Ymd', strtotime('-7 days', strtotime(self::weekStart()))) . '000000';
		$from = Ledger::firstSrlFrom($from_date);
		$base = intval($oDB->query('SELECT MAX(ledger_srl) AS m FROM point_ledger')->fetchAll()[0]->m ?? 0);
		State::set([
			'stats_from' => $from,
			'stats_cursor' => max(0, $from - 1),
			'stats_last' => '',
			'stats_balance_cursor' => 0,
			'stats_balance_ready' => 'N',
			'stats_balance_base' => $base,
			'stats_total' => 0,
			'stats_built' => 'N',
		]);
		self::registerTask();
		return new \BaseObject;
	}

	public static function lock(): bool
	{
		if (!self::$_locked)
		{
			self::$_locked = Storage::getLock('point_stats');
		}
		return self::$_locked;
	}

	/**
	 * Whether the last run is more than an hour old.
	 */
	public static function isStale(): bool
	{
		$last = (string)State::get('stats_last');
		return $last === '' || ztime($last) < time() - self::INTERVAL;
	}

	/**
	 * Whether the first pass (balance buckets and rows since last week) is finished.
	 */
	public static function isBuilt(): bool
	{
		return State::get('stats_balance_ready') === 'Y' && State::get('stats_built') === 'Y';
	}

	public static function isCaughtUp(): bool
	{
		if (State::get('stats_balance_ready') !== 'Y')
		{
			return false;
		}
		$max = intval(DB::getInstance()->query('SELECT MAX(ledger_srl) AS m FROM point_ledger')->fetchAll()[0]->m ?? 0);
		return intval(State::get('stats_cursor')) >= $max;
	}

	/**
	 * Queue handler, run once an hour when the site has a task queue.
	 */
	public static function runTask($args = null, $options = null): void
	{
		if (Config::isStatsEnabled())
		{
			self::run(self::RUN_ROWS);
		}
	}

	public static function registerTask(): void
	{
		if (!config('queue.enabled') || intval(State::get('stats_task_srl')))
		{
			return;
		}
		try
		{
			$task_srl = \Zittme\Framework\Queue::addTaskAtInterval('0 * * * *', self::TASK_HANDLER);
			State::set(['stats_task_srl' => $task_srl]);
		}
		catch (\Throwable $e)
		{
			State::set(['stats_task_srl' => 0]);
		}
	}

	public static function cancelTask(): void
	{
		$task_srl = intval(State::get('stats_task_srl'));
		if ($task_srl)
		{
			try
			{
				\Zittme\Framework\Queue::cancelScheduledTask($task_srl);
			}
			catch (\Throwable $e)
			{
			}
			State::set(['stats_task_srl' => 0]);
		}
	}

	public static function hasTask(): bool
	{
		return config('queue.enabled') && intval(State::get('stats_task_srl')) > 0;
	}

	/**
	 * Add new ledger rows to the summary tables.
	 *
	 * @return object {processed, cursor, done, balance_ready}
	 */
	public static function run(int $max_rows = self::RUN_ROWS): object
	{
		$result = (object)['processed' => 0, 'cursor' => intval(State::get('stats_cursor')), 'done' => false, 'balance_ready' => State::get('stats_balance_ready') === 'Y'];
		if (!Config::isLedgerReady() || !self::tablesExist() || !self::lock())
		{
			return $result;
		}

		if (!$result->balance_ready)
		{
			$result->balance_ready = self::buildBalance(4);
			if (!$result->balance_ready)
			{
				return $result;
			}
		}

		$oDB = DB::getInstance();
		$cursor = intval(State::get('stats_cursor'));
		$base = intval(State::get('stats_balance_base'));
		$total = intval(State::get('stats_total'));
		$daily = [];
		$top = [];
		$buckets = [];
		$processed = 0;

		while ($processed < $max_rows)
		{
			$rows = $oDB->query('SELECT ledger_srl, member_srl, type, reason_code, amount, balance_after, regdate FROM point_ledger WHERE ledger_srl > ? ORDER BY ledger_srl LIMIT ' . self::CHUNK, [$cursor])->fetchAll();
			if (!$rows)
			{
				$result->done = true;
				break;
			}
			foreach ($rows as $row)
			{
				$cursor = intval($row->ledger_srl);
				$processed++;
				$type = (string)$row->type;
				$reason = (string)$row->reason_code;
				$amount = intval($row->amount);
				if ($amount === 0 || $type === 'archive' || $type === 'migrate' || ($type === 'reconcile' && $reason !== 'recalc'))
				{
					continue;
				}
				if ($type !== 'reconcile')
				{
					$ymd = substr((string)$row->regdate, 0, 8);
					$key = $ymd . "\t" . $reason;
					if (!isset($daily[$key]))
					{
						$daily[$key] = [0, 0, 0, 0];
					}
					if ($amount > 0)
					{
						$daily[$key][0] += $amount;
						$daily[$key][2]++;
						$week = self::weekStart(ztime($row->regdate));
						$member_key = $week . "\t" . intval($row->member_srl);
						$top[$member_key] = ($top[$member_key] ?? 0) + $amount;
					}
					else
					{
						$daily[$key][1] += -$amount;
						$daily[$key][3]++;
					}
				}
				if ($cursor > $base)
				{
					$after = intval($row->balance_after);
					$old_bucket = self::bucketOf($after - $amount);
					$new_bucket = self::bucketOf($after);
					if ($old_bucket !== $new_bucket)
					{
						$buckets[$old_bucket] = ($buckets[$old_bucket] ?? 0) - 1;
						$buckets[$new_bucket] = ($buckets[$new_bucket] ?? 0) + 1;
					}
					$total += $amount;
				}
			}
			if (count($rows) < self::CHUNK)
			{
				$result->done = true;
				break;
			}
		}

		$oDB->begin();
		try
		{
			self::flushDaily($daily);
			self::flushTop($top);
			self::flushBuckets($buckets);
			$oDB->commit();
		}
		catch (\Throwable $e)
		{
			$oDB->rollback();
			throw $e;
		}
		$state = ['stats_cursor' => $cursor, 'stats_last' => date('YmdHis'), 'stats_total' => $total];
		if ($result->done)
		{
			$state['stats_built'] = 'Y';
		}
		State::set($state);

		$oDB->query('DELETE FROM point_stats_top WHERE week < ?', [date('Ymd', strtotime('-' . (self::TOP_WEEKS * 7) . ' days', strtotime(self::weekStart())))]);
		$oDB->query('DELETE FROM point_stats_daily WHERE ymd < ?', [date('Ymd', strtotime('-' . self::DAILY_DAYS . ' days'))]);

		$result->processed = $processed;
		$result->cursor = $cursor;
		return $result;
	}

	public static function bucketOf(int $balance): int
	{
		return intval(floor($balance / self::BUCKET));
	}

	/**
	 * Count members per balance bucket, a few batches at a time.
	 *
	 * @return bool true when all members have been counted
	 */
	public static function buildBalance(int $max_batches = 4): bool
	{
		$oDB = DB::getInstance();
		$cursor = intval(State::get('stats_balance_cursor'));
		$total = intval(State::get('stats_total'));
		for ($i = 0; $i < $max_batches; $i++)
		{
			$edge = $oDB->query('SELECT member_srl FROM point WHERE member_srl > ? ORDER BY member_srl LIMIT 1 OFFSET ' . (self::BALANCE_BATCH - 1), [$cursor])->fetchAll()[0] ?? null;
			$upper = $edge ? intval($edge->member_srl) : PHP_INT_MAX;
			$rows = $oDB->query('SELECT FLOOR(point / ' . self::BUCKET . ') AS bucket, COUNT(*) AS cnt, SUM(point) AS total FROM point WHERE member_srl > ? AND member_srl <= ? GROUP BY FLOOR(point / ' . self::BUCKET . ')', [$cursor, $upper])->fetchAll();
			$buckets = [];
			foreach ($rows as $row)
			{
				$buckets[intval($row->bucket)] = intval($row->cnt);
				$total += intval($row->total);
			}
			self::flushBuckets($buckets);
			if (!$edge)
			{
				State::set(['stats_balance_cursor' => 0, 'stats_balance_ready' => 'Y', 'stats_total' => $total]);
				return true;
			}
			$cursor = $upper;
			State::set(['stats_balance_cursor' => $cursor, 'stats_total' => $total]);
		}
		return false;
	}

	protected static function flushDaily(array $daily): void
	{
		$oDB = DB::getInstance();
		foreach (array_chunk($daily, 300, true) as $chunk)
		{
			$values = [];
			$params = [];
			foreach ($chunk as $key => [$earned, $spent, $cnt_plus, $cnt_minus])
			{
				[$ymd, $reason] = explode("\t", $key, 2);
				$values[] = '(?, ?, ?, ?, ?, ?)';
				array_push($params, $ymd, $reason, $earned, $spent, $cnt_plus, $cnt_minus);
			}
			$oDB->query('INSERT INTO point_stats_daily (ymd, reason_code, earned, spent, cnt_plus, cnt_minus) VALUES ' . implode(', ', $values)
				. ' ON DUPLICATE KEY UPDATE earned = earned + VALUES(earned), spent = spent + VALUES(spent), cnt_plus = cnt_plus + VALUES(cnt_plus), cnt_minus = cnt_minus + VALUES(cnt_minus)', $params);
		}
	}

	protected static function flushTop(array $top): void
	{
		$oDB = DB::getInstance();
		foreach (array_chunk($top, 500, true) as $chunk)
		{
			$values = [];
			$params = [];
			foreach ($chunk as $key => $earned)
			{
				[$week, $member_srl] = explode("\t", $key, 2);
				$values[] = '(?, ?, ?)';
				array_push($params, $week, intval($member_srl), $earned);
			}
			$oDB->query('INSERT INTO point_stats_top (week, member_srl, earned) VALUES ' . implode(', ', $values) . ' ON DUPLICATE KEY UPDATE earned = earned + VALUES(earned)', $params);
		}
	}

	protected static function flushBuckets(array $buckets): void
	{
		$oDB = DB::getInstance();
		foreach ($buckets as $bucket => $delta)
		{
			if ($delta === 0)
			{
				continue;
			}
			$oDB->query('INSERT INTO point_stats_balance (bucket, cnt) VALUES (?, GREATEST(?, 0)) ON DUPLICATE KEY UPDATE cnt = GREATEST(cnt + ?, 0)', [$bucket, $delta, $delta]);
		}
	}

	/**
	 * Numbers for one week of the summary screen.
	 */
	public static function week(string $which = 'this'): object
	{
		$oDB = DB::getInstance();
		$this_monday = self::weekStart();
		$monday = $which === 'last' ? date('Ymd', strtotime('-7 days', strtotime($this_monday))) : $this_monday;
		$sunday = date('Ymd', strtotime('+6 days', strtotime($monday)));
		$today = date('Ymd');
		$end = $which === 'last' ? $sunday : $today;
		$days_in = (int)round((strtotime($end) - strtotime($monday)) / 86400);
		$prev_monday = date('Ymd', strtotime('-7 days', strtotime($monday)));
		$prev_end = date('Ymd', strtotime('+' . $days_in . ' days', strtotime($prev_monday)));

		$rows = $oDB->query('SELECT ymd, reason_code, earned, spent, cnt_plus, cnt_minus FROM point_stats_daily WHERE ymd >= ? AND ymd <= ?', [$monday, $sunday])->fetchAll();
		$prev = $oDB->query('SELECT reason_code, SUM(earned) AS earned, SUM(spent) AS spent FROM point_stats_daily WHERE ymd >= ? AND ymd <= ? GROUP BY reason_code', [$prev_monday, $prev_end])->fetchAll();

		$result = (object)[
			'monday' => $monday,
			'sunday' => $sunday,
			'end' => $end,
			'earned' => 0,
			'spent' => 0,
			'prev_earned' => 0,
			'admin_given' => 0,
			'admin_count' => 0,
			'admin_takes' => 0,
			'days' => [],
			'rules' => [],
			'top_spend' => null,
		];
		for ($i = 0; $i < 7; $i++)
		{
			$result->days[date('Ymd', strtotime('+' . $i . ' days', strtotime($monday)))] = ['earned' => 0, 'spent' => 0];
		}
		$rules = [];
		$spend_places = [];
		foreach ($rows as $row)
		{
			$reason = (string)$row->reason_code;
			$earned = intval($row->earned);
			$spent = intval($row->spent);
			$counted = self::isActivity($reason);
			if ($reason === 'admin')
			{
				$result->admin_given += $earned;
				$result->admin_count += intval($row->cnt_plus);
				$result->admin_takes += intval($row->cnt_minus);
			}
			elseif ($reason === 'admin_revert')
			{
				$result->admin_takes += intval($row->cnt_minus);
			}
			if ($counted)
			{
				$result->earned += $earned;
				if ($reason !== 'admin' && $reason !== 'expire')
				{
					$result->spent += $spent;
					if ($spent > 0)
					{
						$spend_places[$reason] = ($spend_places[$reason] ?? 0) + $spent;
					}
				}
				if (isset($result->days[$row->ymd]))
				{
					$result->days[$row->ymd]['earned'] += $earned;
					if ($reason !== 'admin' && $reason !== 'expire')
					{
						$result->days[$row->ymd]['spent'] += $spent;
					}
				}
				if ($earned > 0)
				{
					$rules[$reason] = ($rules[$reason] ?? 0) + $earned;
				}
			}
		}
		foreach ($prev as $row)
		{
			if (self::isActivity((string)$row->reason_code))
			{
				$result->prev_earned += intval($row->earned);
			}
		}
		arsort($rules);
		$result->rules = array_slice($rules, 0, 6, true);
		arsort($spend_places);
		if ($spend_places)
		{
			$result->top_spend = [array_key_first($spend_places), reset($spend_places)];
		}
		return $result;
	}

	/**
	 * Whether a reason counts as points given or spent (not a correction of an earlier row).
	 */
	public static function isActivity(string $reason): bool
	{
		return !in_array($reason, Ledger::REVERT_REASONS, true) && substr($reason, -7) !== '_cancel' && $reason !== 'recalc';
	}

	/**
	 * Members who earned the most in a week.
	 *
	 * @return array [member_srl => earned]
	 */
	public static function topMembers(string $week, int $limit = 5): array
	{
		$rows = DB::getInstance()->query('SELECT member_srl, earned FROM point_stats_top WHERE week = ? ORDER BY earned DESC LIMIT ' . intval($limit), [$week])->fetchAll();
		$result = [];
		foreach ($rows as $row)
		{
			$result[intval($row->member_srl)] = intval($row->earned);
		}
		return $result;
	}

	/**
	 * Earnings in a week for the given members.
	 *
	 * @return array [member_srl => earned]
	 */
	public static function weekEarned(array $member_srls, string $week): array
	{
		$member_srls = array_values(array_filter(array_map('intval', $member_srls)));
		if (!$member_srls || !self::tablesExist())
		{
			return [];
		}
		$rows = DB::getInstance()->query('SELECT member_srl, earned FROM point_stats_top WHERE week = ? AND member_srl IN (' . implode(',', array_fill(0, count($member_srls), '?')) . ')', array_merge([$week], $member_srls))->fetchAll();
		$result = [];
		foreach ($rows as $row)
		{
			$result[intval($row->member_srl)] = intval($row->earned);
		}
		return $result;
	}

	/**
	 * Members per balance bucket.
	 *
	 * @return array [bucket => count]
	 */
	public static function buckets(): array
	{
		if (!self::tablesExist() || State::get('stats_balance_ready') !== 'Y')
		{
			return [];
		}
		$result = [];
		foreach (DB::getInstance()->query('SELECT bucket, cnt FROM point_stats_balance WHERE cnt > 0 ORDER BY bucket')->fetchAll() as $row)
		{
			$result[intval($row->bucket)] = intval($row->cnt);
		}
		return $result;
	}

	/**
	 * Members per level, counted from the balance buckets.
	 *
	 * @return array [level => count]
	 */
	public static function levelCounts(array $buckets, array $level_step, int $max_level): array
	{
		$counts = array_fill(0, $max_level + 1, 0);
		foreach ($buckets as $bucket => $cnt)
		{
			$level = \PointModel::getLevel($bucket * self::BUCKET, $level_step);
			$level = max(0, min($max_level, $level));
			$counts[$level] += $cnt;
		}
		return $counts;
	}

	/**
	 * Members whose balance lies in [from, to).
	 */
	public static function membersBetween(array $buckets, int $from, int $to): int
	{
		if ($from > $to)
		{
			[$from, $to] = [$to, $from];
		}
		$sum = 0;
		foreach ($buckets as $bucket => $cnt)
		{
			$value = $bucket * self::BUCKET;
			if ($value >= $from && $value < $to)
			{
				$sum += $cnt;
			}
		}
		return $sum;
	}

	/**
	 * Total points held by members and the number of members.
	 *
	 * Uses the balance buckets when summaries are on. Otherwise the balance table
	 * is added up at most once an hour and the result is kept.
	 */
	public static function holdings(): object
	{
		if (Config::isStatsEnabled() && State::get('stats_balance_ready') === 'Y' && self::tablesExist())
		{
			$members = intval(DB::getInstance()->query('SELECT SUM(cnt) AS c FROM point_stats_balance')->fetchAll()[0]->c ?? 0);
			$total = intval(State::get('stats_total'));
			return (object)['total' => $total, 'members' => $members, 'average' => $members ? intdiv($total, $members) : 0, 'checked' => (string)State::get('stats_last')];
		}
		$last = (string)State::get('holdings_last');
		if ($last === '' || ztime($last) < time() - self::INTERVAL)
		{
			$row = DB::getInstance()->query('SELECT SUM(point) AS total, COUNT(*) AS members FROM point')->fetchAll()[0] ?? null;
			State::set(['holdings_total' => intval($row->total ?? 0), 'holdings_members' => intval($row->members ?? 0), 'holdings_last' => date('YmdHis')]);
		}
		$total = intval(State::get('holdings_total'));
		$members = intval(State::get('holdings_members'));
		return (object)['total' => $total, 'members' => $members, 'average' => $members ? intdiv($total, $members) : 0, 'checked' => (string)State::get('holdings_last')];
	}

	/**
	 * Members with the highest balances, read backwards on the balance index.
	 *
	 * @return array [member_srl => point]
	 */
	public static function richest(int $limit = 5): array
	{
		$result = [];
		foreach (DB::getInstance()->query('SELECT member_srl, point FROM point ORDER BY point DESC, member_srl DESC LIMIT ' . intval($limit))->fetchAll() as $row)
		{
			$result[intval($row->member_srl)] = intval($row->point);
		}
		return $result;
	}

	/**
	 * Estimated size of the ledger from table statistics (no COUNT).
	 */
	public static function storage(): object
	{
		$result = (object)['rows' => 0, 'bytes' => 0, 'per_row' => 350];
		try
		{
			$oDB = DB::getInstance();
			$stmt = $oDB->getHandle()->prepare('SELECT TABLE_ROWS AS row_count, DATA_LENGTH + INDEX_LENGTH AS byte_size FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
			$stmt->execute([$oDB->prefix . 'point_ledger']);
			$row = $stmt->fetchAll()[0] ?? null;
			$stmt->closeCursor();
			if ($row)
			{
				$result->rows = intval($row->row_count ?? 0);
				$result->bytes = intval($row->byte_size ?? 0);
				if ($result->rows > 1000)
				{
					$result->per_row = max(100, intdiv($result->bytes, $result->rows));
				}
			}
		}
		catch (\Throwable $e)
		{
		}
		return $result;
	}

	/**
	 * Rows per day the ledger would add, estimated from the last 7 days of activity.
	 *
	 * Each count reads at most 200,000 index entries of a date range.
	 */
	public static function activityEstimate(object $config): object
	{
		$oDB = DB::getInstance();
		$since = date('YmdHis', strtotime('-7 days'));
		$cap = 200000;
		$count = function (string $table, string $column) use ($oDB, $since, $cap) {
			if (!$oDB->isTableExists($table))
			{
				return 0;
			}
			$row = $oDB->query('SELECT COUNT(*) AS c FROM (SELECT 1 FROM ' . $table . ' WHERE ' . $column . ' >= ? LIMIT ' . $cap . ') AS recent', [$since])->fetchAll()[0] ?? null;
			return intval($row->c ?? 0);
		};
		$documents = $count('documents', 'regdate');
		$comments = $count('comments', 'regdate');
		$logins = $count('member', 'last_login');
		$signups = $count('member', 'regdate');
		$votes = $count('document_voted_log', 'regdate');

		$nonzero = function ($key) use ($config) {
			return intval($config->$key ?? 0) !== 0 ? 1 : 0;
		};
		$weekly = $documents * max($nonzero('insert_document'), $nonzero('upload_file'))
			+ $comments * $nonzero('insert_comment')
			+ $logins * 4 * $nonzero('login_point')
			+ $signups * $nonzero('signup_point')
			+ $votes * (($nonzero('voted') || $nonzero('blamed')) ? 1 : 0) + $votes * (($nonzero('voter') || $nonzero('blamer')) ? 1 : 0);
		$per_day = (int)ceil($weekly / 7);
		$per_row = self::storage()->per_row ?: 350;
		return (object)['per_day' => $per_day, 'per_year' => $per_day * 365, 'bytes_year' => $per_day * 365 * $per_row];
	}
}
