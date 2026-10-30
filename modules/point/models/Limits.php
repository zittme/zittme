<?php

namespace Zittme\Modules\Point\Models;

use Zittme\Framework\DB;

/**
 * Daily, monthly and per-reason earning caps.
 *
 * Only consulted when the administrator turns limits on. Callers hold the
 * member's balance lock, so the counters cannot race.
 */
class Limits
{
	/**
	 * How much of the requested amount may still be earned.
	 */
	public static function allowance(int $member_srl, string $reason_code, int $amount, object $config): int
	{
		$oDB = DB::getInstance();
		$today = date('Ymd');
		$allowed = $amount;

		$daily = intval($config->limit_daily ?? 0);
		if ($daily > 0)
		{
			$used = intval($oDB->query('SELECT SUM(earned) AS total FROM point_daily WHERE member_srl = ? AND ymd = ?', [$member_srl, $today])->fetchAll()[0]->total ?? 0);
			$allowed = min($allowed, $daily - $used);
		}

		$monthly = intval($config->limit_monthly ?? 0);
		if ($monthly > 0)
		{
			$used = intval($oDB->query('SELECT SUM(earned) AS total FROM point_daily WHERE member_srl = ? AND ymd >= ? AND ymd <= ?', [$member_srl, date('Ym') . '01', date('Ym') . '31'])->fetchAll()[0]->total ?? 0);
			$allowed = min($allowed, $monthly - $used);
		}

		$reasons = Config::parseReasonLimits((string)($config->limit_reasons ?? ''));
		if (isset($reasons[$reason_code]))
		{
			$used = intval($oDB->query('SELECT earned FROM point_daily WHERE member_srl = ? AND ymd = ? AND reason_code = ?', [$member_srl, $today, $reason_code])->fetchAll()[0]->earned ?? 0);
			$allowed = min($allowed, $reasons[$reason_code] - $used);
		}

		return max(0, $allowed);
	}

	/**
	 * Add an earning to today's counter.
	 */
	public static function record(int $member_srl, string $reason_code, int $amount): void
	{
		if ($amount <= 0)
		{
			return;
		}
		DB::getInstance()->query('INSERT INTO point_daily (member_srl, ymd, reason_code, earned) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE earned = earned + VALUES(earned)', [$member_srl, date('Ymd'), $reason_code, $amount]);
	}

	/**
	 * Delete counters older than the current month and the previous one.
	 */
	public static function prune(): void
	{
		DB::getInstance()->query('DELETE FROM point_daily WHERE ymd < ?', [date('Ym', strtotime('first day of last month')) . '01']);
	}
}
