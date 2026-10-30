<?php

namespace Zittme\Modules\Point\Models;

use Zittme\Framework\DB;
use Zittme\Framework\Storage;

/**
 * Brings existing balances into the ledger in small batches.
 *
 * Each batch covers a range of member numbers and writes one opening row per
 * member whose balance differs from the sum of his ledger rows. The row key
 * (migrate:{member_srl}) makes a re-run harmless, and the cursor lets the job
 * stop and resume at any point. Balances are never touched.
 */
class Migration
{
	public const DEFAULT_BATCH = 1000;
	public const MAX_BATCH = 5000;

	protected static $_locked = false;

	/**
	 * Hold the batch lock for the rest of this process.
	 */
	public static function lock(): bool
	{
		if (!self::$_locked)
		{
			self::$_locked = Storage::getLock('point_ledger_batch');
		}
		return self::$_locked;
	}

	/**
	 * Current progress.
	 */
	public static function status(): object
	{
		$oDB = DB::getInstance();
		$status = new \stdClass;
		$status->ready = Config::isLedgerReady();
		$status->done = State::get('migrate_done') === 'Y';
		$status->cursor = intval(State::get('migrate_cursor'));
		$status->inserted = intval(State::get('migrate_inserted'));
		$status->started = (string)State::get('migrate_started');
		$status->finished = (string)State::get('migrate_finished');
		$status->reconcile_needed = State::get('reconcile_needed') === 'Y';
		$status->reconcile_cursor = intval(State::get('reconcile_cursor'));
		$status->reconcile_run = (string)State::get('reconcile_run');
		$status->reconcile_inserted = intval(State::get('reconcile_inserted'));
		$status->max_srl = 0;
		$status->remaining = 0;
		$status->total = 0;
		$status->percent = $status->done && $status->reconcile_run === '' ? 100 : 0;
		if ($status->ready)
		{
			$range = $oDB->query('SELECT MIN(member_srl) AS lo, MAX(member_srl) AS hi FROM point')->fetchAll()[0] ?? null;
			$min_srl = intval($range->lo ?? 0);
			$status->max_srl = intval($range->hi ?? 0);
			$status->total = self::estimateMembers();
			$cursor = $status->reconcile_run !== '' ? $status->reconcile_cursor : $status->cursor;
			$running = !$status->done || $status->reconcile_run !== '';
			if ($running)
			{
				$span = max(1, $status->max_srl - $min_srl + 1);
				$status->percent = $cursor <= $min_srl ? 0 : max(0, min(99, intval(floor(($cursor - $min_srl + 1) * 100 / $span))));
				$status->remaining = intval(round($status->total * (100 - $status->percent) / 100));
			}
		}
		return $status;
	}

	/**
	 * Number of balance rows, from table statistics for large tables.
	 */
	public static function estimateMembers(): int
	{
		$oDB = DB::getInstance();
		$estimate = 0;
		try
		{
			$stmt = $oDB->getHandle()->prepare('SELECT TABLE_ROWS AS row_count FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
			$stmt->execute([$oDB->prefix . 'point']);
			$estimate = intval($stmt->fetchAll()[0]->row_count ?? 0);
			$stmt->closeCursor();
		}
		catch (\Throwable $e)
		{
			$estimate = 0;
		}
		if ($estimate < 100000)
		{
			$estimate = intval($oDB->query('SELECT COUNT(*) AS c FROM (SELECT 1 FROM point LIMIT 100000) AS sample')->fetchAll()[0]->c ?? 0);
		}
		return $estimate;
	}

	/**
	 * Process one batch of the opening-balance migration.
	 */
	public static function runBatch(int $size = self::DEFAULT_BATCH): object
	{
		return self::process('migrate', $size);
	}

	/**
	 * Start a reconcile pass that fixes members whose ledger sum no longer matches the balance.
	 */
	public static function startReconcile(): void
	{
		State::set(['reconcile_run' => date('YmdHis'), 'reconcile_cursor' => 0, 'reconcile_inserted' => 0]);
	}

	/**
	 * Process one batch of a reconcile pass.
	 */
	public static function runReconcileBatch(int $size = self::DEFAULT_BATCH): object
	{
		if ((string)State::get('reconcile_run') === '')
		{
			self::startReconcile();
		}
		return self::process('reconcile', $size);
	}

	protected static function process(string $kind, int $size): object
	{
		$result = new \stdClass;
		$result->kind = $kind;
		$result->processed = 0;
		$result->inserted = 0;
		$result->done = false;
		$result->cursor = 0;

		if (!Config::isLedgerReady())
		{
			$result->error = 'point.msg_ledger_not_ready';
			return $result;
		}
		if (!self::lock())
		{
			$result->error = 'point.msg_batch_running';
			return $result;
		}

		$size = max(1, min(self::MAX_BATCH, $size));
		$oDB = DB::getInstance();
		$cursor_key = $kind === 'migrate' ? 'migrate_cursor' : 'reconcile_cursor';
		$cursor = intval(State::get($cursor_key));
		if ($kind === 'migrate' && !State::get('migrate_started'))
		{
			State::set(['migrate_started' => date('YmdHis')]);
		}

		$srls = $oDB->query('SELECT member_srl FROM point WHERE member_srl > ? ORDER BY member_srl LIMIT ' . $size, [$cursor])->fetchAll();
		if (!$srls)
		{
			$result->done = true;
			$result->cursor = $cursor;
			if ($kind === 'migrate')
			{
				State::set(['migrate_done' => 'Y', 'migrate_finished' => date('YmdHis')]);
			}
			else
			{
				State::set(['reconcile_run' => '', 'reconcile_cursor' => 0, 'reconcile_needed' => 'N']);
			}
			return $result;
		}

		$last = intval(end($srls)->member_srl);
		$key_prefix = $kind === 'migrate' ? 'migrate:' : ('reconcile:' . State::get('reconcile_run') . ':');
		$type = $kind === 'migrate' ? 'migrate' : 'reconcile';
		$now = date('YmdHis');

		$sum = '(SELECT IFNULL(SUM(point_ledger.amount), 0) FROM point_ledger WHERE point_ledger.member_srl = point.member_srl)';
		$stmt = $oDB->query('INSERT IGNORE INTO point_ledger (member_srl, wallet, type, amount, balance_after, remaining, status, reason_code, memo, idem_key, actor_srl, ipaddress, regdate) '
			. 'SELECT point.member_srl, ?, ?, point.point - ' . $sum . ', point.point, GREATEST(point.point - ' . $sum . ', 0), ?, ?, ?, CONCAT(?, point.member_srl), 0, ?, ? '
			. 'FROM point WHERE point.member_srl > ? AND point.member_srl <= ? AND point.point <> ' . $sum,
			[Ledger::DEFAULT_WALLET, $type, 'done', $type, '', $key_prefix, '', $now, $cursor, $last]);
		$inserted = $stmt ? $stmt->rowCount() : 0;

		$result->processed = count($srls);
		$result->inserted = $inserted;
		$result->cursor = $last;
		$counter_key = $kind === 'migrate' ? 'migrate_inserted' : 'reconcile_inserted';
		State::set([$cursor_key => $last, $counter_key => intval(State::get($counter_key)) + $inserted]);
		if (count($srls) < $size)
		{
			$result->done = true;
			if ($kind === 'migrate')
			{
				State::set(['migrate_done' => 'Y', 'migrate_finished' => date('YmdHis')]);
			}
			else
			{
				State::set(['reconcile_run' => '', 'reconcile_cursor' => 0, 'reconcile_needed' => 'N']);
			}
		}
		return $result;
	}

	/**
	 * Members whose ledger sum differs from the balance (sampled, for diagnostics).
	 */
	public static function findMismatches(int $limit = 20, int $after = 0): array
	{
		$sum = '(SELECT IFNULL(SUM(point_ledger.amount), 0) FROM point_ledger WHERE point_ledger.member_srl = point.member_srl)';
		return DB::getInstance()->query('SELECT point.member_srl, point.point, ' . $sum . ' AS ledger_sum FROM point WHERE point.member_srl > ? AND point.point <> ' . $sum . ' ORDER BY point.member_srl LIMIT ' . intval($limit), [$after])->fetchAll();
	}
}
