<?php

namespace Zittme\Modules\Point\Models;

use Zittme\Framework\Storage;

/**
 * Progress of batch jobs (migration, reconcile, expiry, retention).
 *
 * Kept in a small file instead of the module configuration so that
 * running a batch never invalidates the site-wide module cache.
 */
class State
{
	protected const DEFAULTS = [
		'migrate_cursor' => 0,
		'migrate_done' => 'N',
		'migrate_inserted' => 0,
		'migrate_started' => '',
		'migrate_finished' => '',
		'reconcile_cursor' => 0,
		'reconcile_run' => '',
		'reconcile_needed' => 'N',
		'reconcile_inserted' => 0,
		'expire_cursor' => 0,
		'expire_last' => '',
		'retention_cursor' => 0,
		'retention_last' => '',
		'stats_cursor' => 0,
		'stats_last' => '',
		'stats_from' => 0,
		'stats_balance_cursor' => 0,
		'stats_balance_ready' => 'N',
		'stats_balance_base' => 0,
		'stats_total' => 0,
		'stats_built' => 'N',
		'stats_task_srl' => 0,
		'holdings_total' => 0,
		'holdings_members' => 0,
		'holdings_last' => '',
	];

	protected static $_cache = null;

	public static function path(): string
	{
		return \RX_BASEDIR . 'files/config/point_ledger.php';
	}

	public static function all(): array
	{
		if (self::$_cache === null)
		{
			$data = Storage::exists(self::path()) ? Storage::readPHPData(self::path()) : [];
			self::$_cache = array_merge(self::DEFAULTS, is_array($data) ? $data : []);
		}
		return self::$_cache;
	}

	public static function get(string $key)
	{
		return self::all()[$key] ?? null;
	}

	public static function set(array $values): bool
	{
		$data = array_merge(self::all(), $values);
		self::$_cache = $data;
		return Storage::writePHPData(self::path(), $data);
	}

	public static function reset(): void
	{
		self::$_cache = null;
	}
}
