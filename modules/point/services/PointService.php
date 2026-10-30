<?php

namespace Zittme\Modules\Point\Services;

use Zittme\Modules\Point\Models\Config;
use Zittme\Modules\Point\Models\Ledger;

/**
 * Point API for other modules.
 *
 * Every write locks the member's balance row, so concurrent requests are applied
 * one at a time. Pass an idem_key to make a request safe to repeat: the second
 * call with the same key returns the first result (duplicate = true) instead of
 * changing the balance again.
 *
 * Each call returns a BaseObject. On success it carries:
 *   ledger_srl  ledger row number (0 before the ledger table is installed)
 *   balance     balance after the change
 *   amount      signed change actually applied
 *   capped      true when an earning limit reduced the amount
 *   duplicate   true when the idem_key had already been used
 *
 * Options accepted by earn, spend and restore:
 *   idem_key, ref_type, ref_srl, memo, actor_srl, wallet,
 *   apply_limit (earn only, default true), expires_days (earn only)
 */
class PointService
{
	/**
	 * Current balance. Uses the same cache as PointModel::getPoint().
	 */
	public static function balance(int $member_srl): int
	{
		return (int)\PointModel::getPoint($member_srl);
	}

	/**
	 * Give points for an activity or reward.
	 */
	public static function earn(int $member_srl, int $amount, string $reason_code, array $options = []): \BaseObject
	{
		if ($member_srl <= 0 || $amount <= 0)
		{
			return new \BaseObject(-1, 'point.msg_invalid_amount');
		}
		return Ledger::write(self::request($member_srl, $reason_code, $options, [
			'delta' => $amount,
			'type' => 'earn',
			'policy' => Ledger::POLICY_CLAMP,
			'apply_limit' => $options['apply_limit'] ?? true,
			'expires_days' => $options['expires_days'] ?? 0,
		]));
	}

	/**
	 * Take points for a purchase or fee. Fails without changing anything when the balance is too low.
	 */
	public static function spend(int $member_srl, int $amount, string $reason_code, array $options = []): \BaseObject
	{
		if ($member_srl <= 0 || $amount <= 0)
		{
			return new \BaseObject(-1, 'point.msg_invalid_amount');
		}
		return Ledger::write(self::request($member_srl, $reason_code, $options, [
			'delta' => -$amount,
			'type' => 'spend',
			'policy' => Ledger::POLICY_STRICT,
		]));
	}

	/**
	 * Give back points that were spent (refund, cancelled order). Earning limits do not apply.
	 */
	public static function restore(int $member_srl, int $amount, string $reason_code, array $options = []): \BaseObject
	{
		if ($member_srl <= 0 || $amount <= 0)
		{
			return new \BaseObject(-1, 'point.msg_invalid_amount');
		}
		return Ledger::write(self::request($member_srl, $reason_code, $options, [
			'delta' => $amount,
			'type' => 'restore',
			'policy' => Ledger::POLICY_CLAMP,
		]));
	}

	/**
	 * Manual adjustment by an administrator. A reason is required and the balance may go below zero.
	 */
	public static function adjust(int $member_srl, int $amount, string $memo, int $actor_srl = 0, array $options = []): \BaseObject
	{
		$memo = trim($memo);
		if ($member_srl <= 0 || $amount === 0)
		{
			return new \BaseObject(-1, 'point.msg_invalid_amount');
		}
		if ($memo === '')
		{
			return new \BaseObject(-1, 'point.msg_reason_required');
		}
		$options['memo'] = $memo;
		$options['actor_srl'] = $actor_srl;
		return Ledger::write(self::request($member_srl, $options['reason_code'] ?? 'admin', $options, [
			'delta' => $amount,
			'type' => $amount > 0 ? 'admin_add' : 'admin_take',
			'policy' => Ledger::POLICY_NEGATIVE,
		]));
	}

	/**
	 * Set the balance to an exact value by an administrator. A reason is required.
	 */
	public static function adjustTo(int $member_srl, int $target, string $memo, int $actor_srl = 0, array $options = []): \BaseObject
	{
		$memo = trim($memo);
		if ($member_srl <= 0)
		{
			return new \BaseObject(-1, 'point.msg_invalid_amount');
		}
		if ($memo === '')
		{
			return new \BaseObject(-1, 'point.msg_reason_required');
		}
		$options['memo'] = $memo;
		$options['actor_srl'] = $actor_srl;
		return Ledger::write(self::request($member_srl, $options['reason_code'] ?? 'admin', $options, [
			'target' => $target,
			'type' => '',
			'type_plus' => 'admin_add',
			'type_minus' => 'admin_take',
			'policy' => Ledger::POLICY_NEGATIVE,
		]));
	}

	/**
	 * Run a callback while holding the member's balance lock.
	 *
	 * Use this when a decision depends on the balance or on your own counters
	 * (for example a daily cap). earn/spend called inside the callback join the
	 * same transaction. Return a failed BaseObject to roll everything back.
	 *
	 * @param callable $callback function(int $balance): mixed
	 * @return mixed
	 */
	public static function withLock(int $member_srl, callable $callback)
	{
		return Ledger::withLock($member_srl, $callback);
	}

	/**
	 * Look up the ledger row written with an idempotency key.
	 */
	public static function findByIdemKey(string $idem_key): ?object
	{
		if (!Config::isLedgerReady())
		{
			return null;
		}
		return Ledger::findByIdemKey($idem_key);
	}

	/**
	 * A member's ledger rows, newest first.
	 *
	 * Filter keys: type (string|array), direction (plus|minus), reason_code, date_from, date_to (Ymd).
	 *
	 * @return object {items, page, has_next}
	 */
	public static function history(int $member_srl, array $filter = [], int $page = 1, int $count = 20): object
	{
		if (!Config::isLedgerReady())
		{
			return (object)['items' => [], 'page' => $page, 'has_next' => false];
		}
		return Ledger::getMemberHistory($member_srl, $filter, $page, $count);
	}

	/**
	 * The last few history lines of a member, ready to show.
	 *
	 * Each item: ledger_srl, amount, label (why), memo, date (YmdHis), date_label,
	 * is_admin (changed by an administrator), can_revert (administrator gift not yet reverted).
	 * Returns an empty list while point history is off.
	 *
	 * @return array<int, array>
	 */
	public static function recent(int $member_srl, int $count = 5): array
	{
		if ($member_srl <= 0 || !Config::isLedgerReady())
		{
			return [];
		}
		$page = Ledger::page(['member_srl' => $member_srl], '', max(1, min(50, $count)));
		$rows = \Zittme\Modules\Point\Models\AdminData::decorateRows($page->items, false);
		$result = [];
		foreach ($page->items as $i => $row)
		{
			$result[] = [
				'ledger_srl' => intval($row->ledger_srl),
				'amount' => intval($row->amount),
				'label' => $rows[$i]['why'],
				'memo' => (string)$row->memo,
				'date' => (string)$row->regdate,
				'date_label' => $rows[$i]['at'],
				'is_admin' => intval($row->actor_srl) > 0,
				'can_revert' => $rows[$i]['can_revert'],
			];
		}
		return $result;
	}

	/**
	 * Whether the ledger table is installed. Balances work either way.
	 */
	public static function isLedgerReady(): bool
	{
		return Config::isLedgerReady();
	}

	protected static function request(int $member_srl, string $reason_code, array $options, array $request): array
	{
		return $request + [
			'member_srl' => $member_srl,
			'reason_code' => $reason_code,
			'note' => $reason_code,
			'memo' => $options['memo'] ?? '',
			'idem_key' => $options['idem_key'] ?? '',
			'ref_type' => $options['ref_type'] ?? null,
			'ref_srl' => $options['ref_srl'] ?? null,
			'actor_srl' => $options['actor_srl'] ?? 0,
			'wallet' => $options['wallet'] ?? Ledger::DEFAULT_WALLET,
		];
	}
}
