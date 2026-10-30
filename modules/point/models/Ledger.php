<?php

namespace Zittme\Modules\Point\Models;

use Zittme\Framework\DB;
use Zittme\Framework\Exceptions\DBError;

/**
 * Balance writer and ledger store.
 *
 * Every balance change goes through write(): the member's balance row is locked,
 * the new balance is computed from the locked value, one ledger row is appended
 * and the balance row is updated, all in the same transaction.
 */
class Ledger
{
	public const POLICY_CLAMP = 'clamp';
	public const POLICY_STRICT = 'strict';
	public const POLICY_NEGATIVE = 'negative';

	public const TYPES = [
		'earn', 'spend', 'set', 'restore', 'admin_add', 'admin_take',
		'expire', 'capped', 'migrate', 'reconcile', 'archive',
	];

	public const DEFAULT_WALLET = 'point';

	public const MAX_POINT = 2147483647;

	/**
	 * Change a member's balance.
	 *
	 * Request keys:
	 *   member_srl  int     target member
	 *   delta       int     signed change (ignored when target is given)
	 *   target      int     absolute new balance (legacy "update" mode)
	 *   type        string  ledger type
	 *   reason_code string  machine readable reason
	 *   memo        string  human readable note
	 *   note        string  value passed to point.setPoint triggers
	 *   policy      string  clamp | strict | negative
	 *   idem_key    string  optional idempotency key
	 *   ref_type, ref_srl   optional reference
	 *   actor_srl   int     member who caused the change (admin), 0 for system
	 *   apply_limit bool    apply earning limits when enabled
	 *   expires_days int    override expiry for this earning
	 *   trigger_mode string mode reported to point.setPoint triggers
	 *   triggers    bool    call point.setPoint triggers (default true)
	 *   apply_groups bool   move the member between level groups (default true)
	 */
	public static function write(array $request): \BaseObject
	{
		$member_srl = abs(intval($request['member_srl'] ?? 0));
		$has_target = array_key_exists('target', $request) && $request['target'] !== null;
		$policy = $request['policy'] ?? self::POLICY_CLAMP;
		$type = (string)($request['type'] ?? '');
		$idem_key = self::normalizeKey($request['idem_key'] ?? '');
		$use_triggers = $request['triggers'] ?? true;

		$config = Config::get();
		$ledger_ready = Config::isLedgerReady($config);

		// Values reported to the before trigger are computed from the cached balance, as before.
		$current_point = \PointModel::getPoint($member_srl, false, $exists);
		$current_level = \PointModel::getLevel($current_point, $config->level_step ?? []);
		if ($has_target)
		{
			$requested_point = intval($request['target']);
			if ($policy !== self::POLICY_NEGATIVE && $requested_point < 0)
			{
				$requested_point = 0;
			}
			$trigger_mode = $request['trigger_mode'] ?? 'update';
		}
		else
		{
			$requested_point = $current_point + intval($request['delta'] ?? 0);
			$trigger_mode = $request['trigger_mode'] ?? (intval($request['delta'] ?? 0) < 0 ? 'minus' : 'add');
		}

		$trigger_obj = new \stdClass;
		$trigger_obj->member_srl = $member_srl;
		$trigger_obj->mode = $trigger_mode;
		$trigger_obj->note = (string)($request['note'] ?? ($request['reason_code'] ?? ''));
		$trigger_obj->current_point = $current_point;
		$trigger_obj->current_level = $current_level;
		$trigger_obj->set_point = $requested_point;
		$trigger_obj->new_point = $requested_point;
		if ($use_triggers)
		{
			$trigger_output = \ModuleHandler::triggerCall('point.setPoint', 'before', $trigger_obj);
			if (!$trigger_output->toBool())
			{
				return $trigger_output;
			}
		}

		$oDB = DB::getInstance();
		$oDB->begin();
		try
		{
			$locked_point = self::lockBalance($member_srl);

			if ($idem_key !== '' && $ledger_ready)
			{
				$existing = $oDB->query('SELECT ledger_srl FROM point_ledger WHERE idem_key = ?', [$idem_key])->fetchAll();
				if ($existing)
				{
					$oDB->rollback();
					return self::duplicateResult($idem_key, $member_srl);
				}
			}

			// Work out the actual change from the locked balance.
			if ($has_target)
			{
				$delta = $requested_point - $locked_point;
			}
			else
			{
				$delta = intval($request['delta'] ?? 0);
			}

			$capped = false;
			$reason_code = self::normalizeReason($request['reason_code'] ?? '');
			if ($delta > 0 && !empty($request['apply_limit']) && $ledger_ready && Config::isLimitEnabled($config))
			{
				$allowed = Limits::allowance($member_srl, $reason_code, $delta, $config);
				if ($allowed < $delta)
				{
					$capped = true;
					$delta = $allowed;
				}
			}

			if ($delta < 0 && $locked_point + $delta < 0)
			{
				if ($policy === self::POLICY_STRICT)
				{
					$oDB->rollback();
					$output = new \BaseObject(-1, 'point.msg_point_insufficient');
					$output->add('balance', $locked_point);
					$output->add('required', -$delta);
					return $output;
				}
				if ($policy === self::POLICY_CLAMP)
				{
					$delta = $locked_point > 0 ? -$locked_point : 0;
				}
			}

			$new_point = $locked_point + $delta;
			if (abs($delta) > self::MAX_POINT || abs($new_point) > self::MAX_POINT)
			{
				$oDB->rollback();
				return new \BaseObject(-1, 'point.msg_invalid_amount');
			}
			$ledger_srl = 0;

			if ($ledger_ready && ($delta !== 0 || $capped || $idem_key !== ''))
			{
				$row_type = $type ?: ($delta < 0 ? ($request['type_minus'] ?? 'spend') : ($request['type_plus'] ?? 'earn'));
				if ($capped && $delta === 0)
				{
					$row_type = 'capped';
				}
				$expires_at = null;
				if ($delta > 0 && in_array($row_type, ['earn', 'admin_add'], true) && Config::isExpireEnabled($config))
				{
					$days = intval($request['expires_days'] ?? 0);
					$expires_at = $days > 0 ? date('YmdHis', strtotime('+' . $days . ' days')) : date('YmdHis', strtotime('+' . intval($config->expire_months) . ' months'));
				}
				$ledger_srl = self::insertRow([
					'member_srl' => $member_srl,
					'wallet' => $request['wallet'] ?? self::DEFAULT_WALLET,
					'type' => $row_type,
					'amount' => $delta,
					'balance_after' => $new_point,
					'remaining' => $delta > 0 ? $delta : 0,
					'expires_at' => $expires_at,
					'status' => $capped ? 'capped' : 'done',
					'reason_code' => $reason_code,
					'memo' => $request['memo'] ?? '',
					'ref_type' => $request['ref_type'] ?? null,
					'ref_srl' => $request['ref_srl'] ?? null,
					'idem_key' => $idem_key !== '' ? $idem_key : null,
					'actor_srl' => intval($request['actor_srl'] ?? 0),
				]);
			}

			if ($delta !== 0)
			{
				$oDB->query('UPDATE point SET point = ? WHERE member_srl = ?', [$new_point, $member_srl]);
				if ($delta > 0 && !empty($request['apply_limit']) && $ledger_ready && Config::isLimitEnabled($config))
				{
					Limits::record($member_srl, $reason_code, $delta);
				}
				if ($delta < 0 && $ledger_ready && empty($request['skip_consume']) && Config::isExpireEnabled($config))
				{
					self::consume($member_srl, -$delta);
				}
			}

			// Levels and groups follow the balance.
			$old_level = \PointModel::getLevel($locked_point, $config->level_step ?? []);
			$new_level = \PointModel::getLevel($new_point, $config->level_step ?? []);
			[$new_group_list, $del_group_list] = ($request['apply_groups'] ?? true) ? self::applyGroups($member_srl, $old_level, $new_level, $config) : [[], []];

			$trigger_obj->new_point = $new_point;
			$trigger_obj->new_level = $new_level;
			$trigger_obj->new_group_list = $new_group_list;
			$trigger_obj->del_group_list = $del_group_list;
			$trigger_obj->ledger_srl = $ledger_srl;
			if ($use_triggers)
			{
				\ModuleHandler::triggerCall('point.setPoint', 'after', $trigger_obj);
			}

			$oDB->commit();
		}
		catch (DBError $e)
		{
			$oDB->rollback();
			if ($idem_key !== '' && self::isDuplicateError($e))
			{
				return self::duplicateResult($idem_key, $member_srl);
			}
			return new \BaseObject(-1, $e->getMessage());
		}
		catch (\Throwable $e)
		{
			$oDB->rollback();
			throw $e;
		}

		\PointModel::setCachedPoint($member_srl, $new_point);
		if (class_exists('MemberController'))
		{
			\MemberController::clearMemberCache($member_srl);
		}

		$output = new \BaseObject;
		$output->add('ledger_srl', $ledger_srl);
		$output->add('balance', $new_point);
		$output->add('amount', $delta);
		$output->add('capped', $capped);
		$output->add('duplicate', false);
		return $output;
	}

	/**
	 * Run a callback while holding the lock on a member's balance row.
	 *
	 * @return mixed whatever the callback returns
	 */
	public static function withLock(int $member_srl, callable $callback)
	{
		$member_srl = abs($member_srl);
		$oDB = DB::getInstance();
		$oDB->begin();
		try
		{
			$balance = self::lockBalance($member_srl);
			$result = $callback($balance);
			if ($result instanceof \BaseObject && !$result->toBool())
			{
				$oDB->rollback();
				return $result;
			}
			$oDB->commit();
			return $result;
		}
		catch (\Throwable $e)
		{
			$oDB->rollback();
			throw $e;
		}
	}

	/**
	 * Lock the balance row and return the current balance, creating the row if needed.
	 */
	public static function lockBalance(int $member_srl): int
	{
		$oDB = DB::getInstance();
		$rows = $oDB->query('SELECT point FROM point WHERE member_srl = ? FOR UPDATE', [$member_srl])->fetchAll();
		if (!$rows)
		{
			$oDB->query('INSERT IGNORE INTO point (member_srl, point) VALUES (?, 0)', [$member_srl]);
			$rows = $oDB->query('SELECT point FROM point WHERE member_srl = ? FOR UPDATE', [$member_srl])->fetchAll();
		}
		return $rows ? intval($rows[0]->point) : 0;
	}

	/**
	 * Append one ledger row and return its number.
	 */
	public static function insertRow(array $row): int
	{
		$oDB = DB::getInstance();
		$oDB->query('INSERT INTO point_ledger (member_srl, wallet, type, amount, balance_after, remaining, expires_at, status, reason_code, memo, ref_type, ref_srl, idem_key, actor_srl, ipaddress, regdate) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
			intval($row['member_srl']),
			substr((string)($row['wallet'] ?? self::DEFAULT_WALLET), 0, 10),
			substr((string)$row['type'], 0, 20),
			intval($row['amount']),
			intval($row['balance_after']),
			intval($row['remaining'] ?? 0),
			$row['expires_at'] ?? null,
			substr((string)($row['status'] ?? 'done'), 0, 10),
			substr((string)($row['reason_code'] ?? ''), 0, 40),
			mb_substr((string)($row['memo'] ?? ''), 0, 250),
			isset($row['ref_type']) && $row['ref_type'] !== '' ? substr((string)$row['ref_type'], 0, 40) : null,
			isset($row['ref_srl']) && $row['ref_srl'] !== '' ? intval($row['ref_srl']) : null,
			$row['idem_key'] ?? null,
			intval($row['actor_srl'] ?? 0),
			substr((string)($row['ipaddress'] ?? \RX_CLIENT_IP), 0, 60),
			$row['regdate'] ?? date('YmdHis'),
		]);
		return $oDB->getInsertID();
	}

	/**
	 * Use up the oldest expiring earnings first.
	 */
	public static function consume(int $member_srl, int $amount): void
	{
		$oDB = DB::getInstance();
		while ($amount > 0)
		{
			$rows = $oDB->query('SELECT ledger_srl, remaining FROM point_ledger WHERE member_srl = ? AND remaining > 0 ORDER BY expires_at IS NULL, expires_at, ledger_srl LIMIT 100', [$member_srl])->fetchAll();
			if (!$rows)
			{
				return;
			}
			foreach ($rows as $row)
			{
				$take = min($amount, intval($row->remaining));
				$oDB->query('UPDATE point_ledger SET remaining = remaining - ? WHERE ledger_srl = ?', [$take, $row->ledger_srl]);
				$amount -= $take;
				if ($amount <= 0)
				{
					return;
				}
			}
		}
	}

	/**
	 * Move a member between groups when the level changes.
	 *
	 * @return array [new_group_list, del_group_list]
	 */
	public static function applyGroups(int $member_srl, int $current_level, int $new_level, object $config): array
	{
		$new_group_list = [];
		$del_group_list = [];
		if (($config->group_ratchet ?? 'N') === 'Y')
		{
			$change_group = ($new_level > $current_level);
		}
		else
		{
			$change_group = ($new_level != $current_level);
		}
		if (!$change_group)
		{
			return [$new_group_list, $del_group_list];
		}

		$point_group = $config->point_group ?? [];
		if (!$point_group || !is_array($point_group) || !count($point_group))
		{
			return [$new_group_list, $del_group_list];
		}

		$default_group = \MemberModel::getDefaultGroup();
		asort($point_group);

		if (($config->group_reset ?? 'Y') != 'N')
		{
			if (in_array($new_level, $point_group))
			{
				foreach ($point_group as $group_srl => $target_level)
				{
					$del_group_list[] = $group_srl;
					if ($target_level == $new_level)
					{
						$new_group_list[] = $group_srl;
					}
				}
			}
			else
			{
				$i = $new_level;
				while ($i > 0)
				{
					if (in_array($i, $point_group))
					{
						foreach ($point_group as $group_srl => $target_level)
						{
							if ($target_level == $i)
							{
								$new_group_list[] = $group_srl;
							}
						}
						$i = 0;
					}
					$i--;
				}
			}
			foreach ($point_group as $group_srl => $target_level)
			{
				if ($target_level > $new_level)
				{
					$del_group_list[] = $group_srl;
				}
			}
			$del_group_list[] = $default_group->group_srl;
		}
		else
		{
			foreach ($point_group as $group_srl => $target_level)
			{
				$del_group_list[] = $group_srl;
				if ($target_level <= $new_level)
				{
					$new_group_list[] = $group_srl;
				}
			}
		}

		if (empty($new_group_list[0]))
		{
			$new_group_list[0] = $default_group->group_srl;
		}
		if ($del_group_list)
		{
			$args = new \stdClass;
			$args->member_srl = $member_srl;
			$args->group_srl = implode(',', $del_group_list);
			executeQuery('point.deleteMemberGroup', $args);
		}
		foreach ($new_group_list as $group_srl)
		{
			$args = new \stdClass;
			$args->member_srl = $member_srl;
			$args->group_srl = $group_srl;
			executeQuery('member.addMemberToGroup', $args);
		}

		return [$new_group_list, $del_group_list];
	}

	/**
	 * Find a ledger row by its idempotency key.
	 */
	public static function findByIdemKey(string $idem_key): ?object
	{
		$idem_key = self::normalizeKey($idem_key);
		if ($idem_key === '')
		{
			return null;
		}
		$rows = DB::getInstance()->query('SELECT * FROM point_ledger WHERE idem_key = ? LOCK IN SHARE MODE', [$idem_key])->fetchAll();
		return $rows ? $rows[0] : null;
	}

	/**
	 * Look up a ledger row by its idempotency key without taking any lock.
	 */
	public static function peekIdemKey(string $idem_key): ?object
	{
		$idem_key = self::normalizeKey($idem_key);
		if ($idem_key === '')
		{
			return null;
		}
		$rows = DB::getInstance()->query('SELECT * FROM point_ledger WHERE idem_key = ?', [$idem_key])->fetchAll();
		return $rows ? $rows[0] : null;
	}

	/**
	 * A member's ledger rows, newest first.
	 *
	 * @return object {items, page, has_next}
	 */
	public static function getMemberHistory(int $member_srl, array $filter = [], int $page = 1, int $count = 20): object
	{
		$filter['member_srl'] = $member_srl;
		return self::search($filter, $page, $count);
	}

	/**
	 * Search ledger rows for administrators.
	 *
	 * Uses "fetch one more" paging so that large ledgers never need COUNT(*).
	 *
	 * @return object {items, page, has_next}
	 */
	public static function search(array $filter = [], int $page = 1, int $count = 20): object
	{
		$page = max(1, min(100000, $page));
		$count = max(1, min(200, $count));
		[$where, $params] = self::buildWhere($filter);
		$sql = 'SELECT * FROM point_ledger' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY ledger_srl DESC LIMIT ' . (($page - 1) * $count) . ', ' . ($count + 1);
		$rows = DB::getInstance()->query($sql, $params)->fetchAll();

		$result = new \stdClass;
		$result->has_next = count($rows) > $count;
		$result->items = array_slice($rows, 0, $count);
		$result->page = $page;
		return $result;
	}

	/**
	 * Stream ledger rows in ledger_srl order for CSV export.
	 */
	public static function chunk(array $filter, int $after_srl, int $size = 1000): array
	{
		[$where, $params] = self::buildWhere($filter);
		$where[] = 'ledger_srl < ?';
		$params[] = $after_srl > 0 ? $after_srl : PHP_INT_MAX;
		$sql = 'SELECT * FROM point_ledger WHERE ' . implode(' AND ', $where) . ' ORDER BY ledger_srl DESC LIMIT ' . intval($size);
		return DB::getInstance()->query($sql, $params)->fetchAll();
	}

	/**
	 * Totals for one member, split into earned and spent.
	 */
	public static function getMemberTotals(int $member_srl, array $filter = []): object
	{
		$filter['member_srl'] = $member_srl;
		[$where, $params] = self::buildWhere($filter);
		$sql = 'SELECT SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) AS plus, SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END) AS minus, COUNT(*) AS cnt FROM point_ledger WHERE ' . implode(' AND ', $where);
		$row = DB::getInstance()->query($sql, $params)->fetchAll()[0] ?? null;
		$totals = new \stdClass;
		$totals->plus = intval($row->plus ?? 0);
		$totals->minus = intval($row->minus ?? 0);
		$totals->count = intval($row->cnt ?? 0);
		return $totals;
	}

	/**
	 * Earnings that will expire before the given time.
	 */
	public static function getExpiringSoon(int $member_srl, string $before): int
	{
		$rows = DB::getInstance()->query('SELECT SUM(remaining) AS total FROM point_ledger WHERE member_srl = ? AND remaining > 0 AND expires_at IS NOT NULL AND expires_at <= ?', [$member_srl, $before])->fetchAll();
		return intval($rows[0]->total ?? 0);
	}

	/**
	 * Reason codes that take back points given earlier.
	 */
	public const REVERT_REASONS = ['delete_document', 'delete_comment', 'delete_file', 'first_document_revert', 'admin_revert'];

	/**
	 * Types that only move rows around and never change a balance by themselves.
	 */
	public const BOOKKEEPING_TYPES = ['migrate', 'reconcile', 'archive'];

	/**
	 * Kinds shown as chips on the administrator screen.
	 */
	public const KINDS = ['earn', 'spend', 'admin', 'revoke', 'expire'];

	/**
	 * The chip kind of one ledger row.
	 */
	public static function kindOf(object $row): string
	{
		$reason = (string)$row->reason_code;
		if (in_array($reason, self::REVERT_REASONS, true) || substr($reason, -7) === '_cancel')
		{
			return 'revoke';
		}
		switch ((string)$row->type)
		{
			case 'admin_add':
			case 'admin_take':
				return 'admin';
			case 'expire':
				return 'expire';
			case 'migrate':
			case 'reconcile':
			case 'archive':
			case 'capped':
				return (string)$row->type;
		}
		return intval($row->amount) < 0 ? 'spend' : 'earn';
	}

	/**
	 * SQL condition for a chip kind.
	 *
	 * @return array [where, params]
	 */
	public static function kindWhere(string $kind): array
	{
		$reverts = implode(',', array_fill(0, count(self::REVERT_REASONS), '?'));
		$not_revert = '(reason_code IS NULL OR (reason_code NOT IN (' . $reverts . ') AND reason_code NOT LIKE ?))';
		$special = "type NOT IN ('admin_add', 'admin_take', 'expire', 'migrate', 'reconcile', 'archive', 'capped')";
		switch ($kind)
		{
			case 'earn':
				return ['amount > 0 AND ' . $special . ' AND ' . $not_revert, array_merge(self::REVERT_REASONS, ['%\_cancel'])];
			case 'spend':
				return ['amount < 0 AND ' . $special . ' AND ' . $not_revert, array_merge(self::REVERT_REASONS, ['%\_cancel'])];
			case 'admin':
				return ["type IN ('admin_add', 'admin_take')", []];
			case 'revoke':
				return ['(reason_code IN (' . $reverts . ') OR reason_code LIKE ?)', array_merge(self::REVERT_REASONS, ['%\_cancel'])];
			case 'expire':
				return ["type = 'expire'", []];
		}
		return ['', []];
	}

	/**
	 * The first row number written at or after the given time (YmdHis).
	 *
	 * Row numbers grow with time, so a binary search over the primary key finds
	 * the boundary in about 30 single-row lookups, however large the table is.
	 */
	public static function firstSrlFrom(string $regdate): int
	{
		$oDB = DB::getInstance();
		$range = $oDB->query('SELECT MIN(ledger_srl) AS lo, MAX(ledger_srl) AS hi FROM point_ledger')->fetchAll()[0] ?? null;
		if (!$range || !$range->hi)
		{
			return 0;
		}
		$lo = intval($range->lo);
		$hi = intval($range->hi) + 1;
		while ($lo < $hi)
		{
			$mid = intdiv($lo + $hi, 2);
			$row = $oDB->query("SELECT ledger_srl, regdate FROM point_ledger WHERE ledger_srl >= ? AND type <> 'archive' ORDER BY ledger_srl LIMIT 1", [$mid])->fetchAll()[0] ?? null;
			if (!$row || strcmp((string)$row->regdate, $regdate) >= 0)
			{
				$hi = $mid;
			}
			else
			{
				$lo = intval($row->ledger_srl) + 1;
			}
		}
		return $lo;
	}

	/**
	 * One page of ledger rows for administrators, newest first, without COUNT(*).
	 *
	 * Without a member, rows are read backwards by primary key. Kind and reason
	 * conditions only look at the given date range and at most $scan_limit row
	 * numbers per call; when the page is not full, more_before tells where the
	 * next call should continue.
	 *
	 * Cursor for the member view: "regdate:ledger_srl". Otherwise: ledger_srl.
	 *
	 * @return object {items, next, more_before}
	 */
	public static function page(array $filter, string $cursor = '', int $count = 50, int $scan_limit = 200000): object
	{
		$oDB = DB::getInstance();
		$count = max(1, min(200, $count));
		$result = (object)['items' => [], 'next' => '', 'more_before' => 0];

		if (!empty($filter['member_srl']))
		{
			[$where, $params] = self::buildWhere($filter);
			if (preg_match('/^(\d{14}):(\d+)$/', $cursor, $m))
			{
				$where[] = '(regdate < ? OR (regdate = ? AND ledger_srl < ?))';
				array_push($params, $m[1], $m[1], intval($m[2]));
			}
			$rows = $oDB->query('SELECT * FROM point_ledger WHERE ' . implode(' AND ', $where) . ' ORDER BY regdate DESC, ledger_srl DESC LIMIT ' . ($count + 1), $params)->fetchAll();
			if (count($rows) > $count)
			{
				$rows = array_slice($rows, 0, $count);
				$last = end($rows);
				$result->next = $last->regdate . ':' . $last->ledger_srl;
			}
			$result->items = $rows;
			return $result;
		}

		$upper = ctype_digit($cursor) && $cursor !== '' ? intval($cursor) : 0;
		if (!empty($filter['srl_to']) && (!$upper || $upper > intval($filter['srl_to'])))
		{
			$upper = intval($filter['srl_to']);
		}
		$filter['srl_to'] = $upper ?: null;

		$narrow = !empty($filter['kind']) || !empty($filter['reason_code']) || !empty($filter['type']) || !empty($filter['direction']) || !empty($filter['actor_only']);
		$floor = intval($filter['srl_from'] ?? 0);
		if ($narrow)
		{
			$top = $upper ?: intval($oDB->query('SELECT MAX(ledger_srl) AS m FROM point_ledger')->fetchAll()[0]->m ?? 0) + 1;
			$scan_floor = max($floor, $top - $scan_limit);
			$filter['srl_from'] = $scan_floor;
		}

		[$where, $params] = self::buildWhere($filter);
		$rows = $oDB->query('SELECT * FROM point_ledger WHERE ' . implode(' AND ', $where) . ' ORDER BY ledger_srl DESC LIMIT ' . ($count + 1), $params)->fetchAll();
		if (count($rows) > $count)
		{
			$rows = array_slice($rows, 0, $count);
			$result->next = (string)end($rows)->ledger_srl;
		}
		elseif ($narrow && $filter['srl_from'] > $floor && $filter['srl_from'] > 1)
		{
			$result->more_before = intval($filter['srl_from']);
		}
		$result->items = $rows;
		return $result;
	}

	/**
	 * Which of the given rows have already been reverted.
	 *
	 * @return array<int, int> original ledger_srl => revert ledger_srl
	 */
	public static function findReverts(array $ledger_srls): array
	{
		$keys = [];
		foreach (array_unique(array_map('intval', $ledger_srls)) as $srl)
		{
			if ($srl > 0)
			{
				$keys[] = 'revert:' . $srl;
			}
		}
		if (!$keys)
		{
			return [];
		}
		$rows = DB::getInstance()->query('SELECT ledger_srl, ref_srl FROM point_ledger WHERE idem_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')', $keys)->fetchAll();
		$result = [];
		foreach ($rows as $row)
		{
			$result[intval($row->ref_srl)] = intval($row->ledger_srl);
		}
		return $result;
	}

	protected static function buildWhere(array $filter): array
	{
		$where = [];
		$params = [];
		if (!empty($filter['member_srl']))
		{
			$where[] = 'member_srl = ?';
			$params[] = intval($filter['member_srl']);
		}
		if (!empty($filter['type']))
		{
			$types = array_values(array_intersect((array)$filter['type'], self::TYPES));
			if ($types)
			{
				$where[] = 'type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
				$params = array_merge($params, $types);
			}
		}
		if (!empty($filter['direction']))
		{
			$where[] = $filter['direction'] === 'minus' ? 'amount < 0' : 'amount > 0';
		}
		if (!empty($filter['reason_code']))
		{
			$where[] = 'reason_code = ?';
			$params[] = (string)$filter['reason_code'];
		}
		if (!empty($filter['date_from']) && preg_match('/^\d{8}$/', $filter['date_from']))
		{
			$where[] = 'regdate >= ?';
			$params[] = $filter['date_from'] . '000000';
		}
		if (!empty($filter['date_to']) && preg_match('/^\d{8}$/', $filter['date_to']))
		{
			$where[] = 'regdate <= ?';
			$params[] = $filter['date_to'] . '235959';
		}
		if (!empty($filter['actor_only']))
		{
			$where[] = 'actor_srl > 0';
		}
		if (!empty($filter['kind']))
		{
			[$kind_where, $kind_params] = self::kindWhere((string)$filter['kind']);
			if ($kind_where !== '')
			{
				$where[] = $kind_where;
				$params = array_merge($params, $kind_params);
			}
		}
		if (!empty($filter['srl_from']))
		{
			$where[] = 'ledger_srl >= ?';
			$params[] = intval($filter['srl_from']);
		}
		if (!empty($filter['srl_to']))
		{
			$where[] = 'ledger_srl < ?';
			$params[] = intval($filter['srl_to']);
		}
		if (!$where)
		{
			$where[] = '1 = 1';
		}
		return [$where, $params];
	}

	/**
	 * Turn a legacy note into a reason code, keeping free text as the memo.
	 *
	 * @return array [reason_code, memo]
	 */
	public static function splitNote(string $note): array
	{
		$note = trim($note);
		if ($note === '')
		{
			return ['legacy', ''];
		}
		if (preg_match('/^[a-z0-9_.:\-]{1,40}$/i', $note))
		{
			return [strtolower($note), ''];
		}
		return ['legacy', $note];
	}

	public static function normalizeReason($reason_code): string
	{
		$reason_code = strtolower(trim((string)$reason_code));
		return preg_match('/^[a-z0-9_.:\-]{1,40}$/', $reason_code) ? $reason_code : 'legacy';
	}

	public static function normalizeKey($idem_key): string
	{
		$idem_key = trim((string)$idem_key);
		if ($idem_key === '')
		{
			return '';
		}
		return strlen($idem_key) > 100 ? 'h:' . hash('sha256', $idem_key) : $idem_key;
	}

	protected static function isDuplicateError(\Throwable $e): bool
	{
		$previous = $e->getPrevious();
		if ($previous instanceof \PDOException && isset($previous->errorInfo[1]) && intval($previous->errorInfo[1]) === 1062)
		{
			return true;
		}
		return stripos($e->getMessage(), 'Duplicate entry') !== false;
	}

	protected static function duplicateResult(string $idem_key, int $member_srl): \BaseObject
	{
		$row = self::findByIdemKey($idem_key);
		if (!$row)
		{
			return new \BaseObject(-1, 'point.msg_idem_conflict');
		}
		if (intval($row->member_srl) !== $member_srl)
		{
			return new \BaseObject(-1, 'point.msg_idem_conflict');
		}
		$output = new \BaseObject;
		$output->add('ledger_srl', intval($row->ledger_srl));
		$output->add('balance', \PointModel::getPoint($member_srl, true));
		$output->add('amount', intval($row->amount));
		$output->add('capped', $row->status === 'capped');
		$output->add('duplicate', true);
		return $output;
	}
}
