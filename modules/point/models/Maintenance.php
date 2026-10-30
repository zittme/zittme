<?php

namespace Zittme\Modules\Point\Models;

use Zittme\Framework\DB;

/**
 * Optional housekeeping: expiring old earnings and archiving old ledger rows.
 *
 * Both jobs walk the ledger by primary key in fixed-size ranges so that no
 * single statement scans or locks a large part of the table.
 */
class Maintenance
{
	public const RANGE = 20000;

	/**
	 * Run every enabled job for a limited number of ranges.
	 *
	 * @return array job name => result
	 */
	public static function run(int $max_ranges = 10): array
	{
		$config = Config::get();
		$results = [];
		if (!Config::isLedgerReady($config) || !Migration::lock())
		{
			return $results;
		}
		if (Config::isExpireEnabled($config))
		{
			$results['expire'] = self::expire($max_ranges);
		}
		if (Config::isRetentionEnabled($config))
		{
			$results['retention'] = self::archive(intval($config->retention_months), $max_ranges);
		}
		if (Config::isLimitEnabled($config))
		{
			Limits::prune();
		}
		return $results;
	}

	/**
	 * Expire earnings whose validity has ended.
	 */
	public static function expire(int $max_ranges = 10): object
	{
		$oDB = DB::getInstance();
		$result = (object)['ranges' => 0, 'members' => 0, 'expired' => 0, 'done' => false];
		$now = date('YmdHis');
		$max_srl = intval($oDB->query('SELECT MAX(ledger_srl) AS m FROM point_ledger')->fetchAll()[0]->m ?? 0);
		$cursor = intval(State::get('expire_cursor'));

		while ($result->ranges < $max_ranges)
		{
			if ($cursor >= $max_srl)
			{
				$cursor = 0;
				$result->done = true;
				break;
			}
			$upper = $cursor + self::RANGE;
			$members = $oDB->query('SELECT member_srl, SUM(remaining) AS total FROM point_ledger WHERE ledger_srl > ? AND ledger_srl <= ? AND remaining > 0 AND expires_at IS NOT NULL AND expires_at <= ? GROUP BY member_srl', [$cursor, $upper, $now])->fetchAll();
			foreach ($members as $member)
			{
				$member_srl = intval($member->member_srl);
				$expired = Ledger::withLock($member_srl, function ($balance) use ($oDB, $member_srl, $cursor, $upper, $now) {
					$rows = $oDB->query('SELECT SUM(remaining) AS total FROM point_ledger WHERE member_srl = ? AND ledger_srl > ? AND ledger_srl <= ? AND remaining > 0 AND expires_at IS NOT NULL AND expires_at <= ?', [$member_srl, $cursor, $upper, $now])->fetchAll();
					$total = intval($rows[0]->total ?? 0);
					$oDB->query('UPDATE point_ledger SET remaining = 0 WHERE member_srl = ? AND ledger_srl > ? AND ledger_srl <= ? AND remaining > 0 AND expires_at IS NOT NULL AND expires_at <= ?', [$member_srl, $cursor, $upper, $now]);
					$amount = min($total, max(0, $balance));
					if ($amount > 0)
					{
						$output = Ledger::write([
							'member_srl' => $member_srl,
							'delta' => -$amount,
							'type' => 'expire',
							'reason_code' => 'expire',
							'note' => 'expire',
							'policy' => Ledger::POLICY_CLAMP,
							'skip_consume' => true,
						]);
						if (!$output->toBool())
						{
							return $output;
						}
					}
					return $amount;
				});
				if (is_int($expired))
				{
					$result->members++;
					$result->expired += $expired;
				}
			}
			$cursor = $upper;
			$result->ranges++;
		}

		State::set(['expire_cursor' => $cursor, 'expire_last' => $now]);
		return $result;
	}

	/**
	 * Replace ledger rows older than the retention period with one summary row per member.
	 *
	 * Rows that still carry an expiring balance are kept. Idempotency keys of archived
	 * rows are dropped with them.
	 */
	public static function archive(int $months, int $max_ranges = 10): object
	{
		$oDB = DB::getInstance();
		$result = (object)['ranges' => 0, 'archived' => 0, 'summaries' => 0, 'done' => false];
		$months = max(Config::MIN_RETENTION_MONTHS, $months);
		$cutoff = date('YmdHis', strtotime('-' . $months . ' months'));
		$cursor = intval(State::get('retention_cursor'));
		$condition = 'ledger_srl > ? AND ledger_srl <= ? AND regdate < ? AND type <> ? AND (expires_at IS NULL OR remaining = 0)';

		while ($result->ranges < $max_ranges)
		{
			$first = $oDB->query('SELECT ledger_srl, regdate FROM point_ledger WHERE ledger_srl > ? ORDER BY ledger_srl LIMIT 1', [$cursor])->fetchAll();
			if (!$first || $first[0]->regdate >= $cutoff)
			{
				$cursor = 0;
				$result->done = true;
				break;
			}
			$lower = intval($first[0]->ledger_srl) - 1;
			$upper = $lower + self::RANGE;
			$params = [$lower, $upper, $cutoff, 'archive'];

			$oDB->begin();
			try
			{
				$stmt = $oDB->query('INSERT INTO point_ledger (member_srl, wallet, type, amount, balance_after, remaining, expires_at, status, reason_code, memo, actor_srl, ipaddress, regdate) '
					. 'SELECT member_srl, wallet, ?, SUM(amount), 0, SUM(remaining), NULL, ?, ?, CONCAT(COUNT(*), ?), 0, ?, MAX(regdate) FROM point_ledger WHERE ' . $condition . ' GROUP BY member_srl, wallet',
					array_merge(['archive', 'done', 'archive', ' rows', ''], $params));
				$result->summaries += $stmt ? $stmt->rowCount() : 0;
				$stmt = $oDB->query('DELETE FROM point_ledger WHERE ' . $condition, $params);
				$result->archived += $stmt ? $stmt->rowCount() : 0;
				$oDB->commit();
			}
			catch (\Throwable $e)
			{
				$oDB->rollback();
				throw $e;
			}
			$cursor = $upper;
			$result->ranges++;
		}

		State::set(['retention_cursor' => $cursor, 'retention_last' => date('YmdHis')]);
		return $result;
	}
}
