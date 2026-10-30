<?php

namespace Zittme\Modules\Point\Models;

/**
 * Ledger policy settings stored alongside the legacy point configuration.
 *
 * Every policy is off by default so that existing sites behave exactly as before.
 */
class Config
{
	public const DEFAULTS = [
		'ledger_ready' => 'N',
		'limit_enabled' => 'N',
		'limit_daily' => 0,
		'limit_monthly' => 0,
		'limit_reasons' => '',
		'expire_enabled' => 'N',
		'expire_months' => 12,
		'retention_enabled' => 'N',
		'retention_months' => 24,
		'mypage_enabled' => 'N',
		'stats_enabled' => 'N',
		'big_confirm' => 'N',
	];

	public const BIG_CONFIRM_PER_MEMBER = 10000;
	public const BIG_CONFIRM_TOTAL = 1000000;
	public const BIG_CONFIRM_MEMBERS = 1000;

	public const MIN_RETENTION_MONTHS = 6;

	/**
	 * Get the full point configuration with ledger defaults filled in.
	 */
	public static function get(): object
	{
		$config = \ModuleModel::getModuleConfig('point');
		$config = is_object($config) ? clone $config : new \stdClass;
		foreach (self::DEFAULTS as $key => $value)
		{
			if (!isset($config->$key))
			{
				$config->$key = $value;
			}
		}
		return $config;
	}

	/**
	 * Merge the given keys into the stored configuration.
	 */
	public static function update(array $values): \BaseObject
	{
		$config = \ModuleModel::getModuleConfig('point');
		$config = is_object($config) ? clone $config : new \stdClass;
		foreach ($values as $key => $value)
		{
			$config->$key = $value;
		}
		$output = \ModuleController::getInstance()->insertModuleConfig('point', $config);
		self::flush();
		return $output;
	}

	/**
	 * Forget the configuration cached in memory by the legacy classes.
	 */
	public static function flush(): void
	{
		\Point::resetConfigCache();
	}

	public static function isLedgerReady(?object $config = null): bool
	{
		$config = $config ?: self::get();
		return ($config->ledger_ready ?? 'N') === 'Y';
	}

	public static function isLimitEnabled(object $config): bool
	{
		return ($config->limit_enabled ?? 'N') === 'Y';
	}

	public static function isExpireEnabled(object $config): bool
	{
		return ($config->expire_enabled ?? 'N') === 'Y' && (int)($config->expire_months ?? 0) > 0;
	}

	public static function isRetentionEnabled(object $config): bool
	{
		return ($config->retention_enabled ?? 'N') === 'Y' && (int)($config->retention_months ?? 0) >= self::MIN_RETENTION_MONTHS;
	}

	public static function isStatsEnabled(?object $config = null): bool
	{
		$config = $config ?: self::get();
		return ($config->stats_enabled ?? 'N') === 'Y' && self::isLedgerReady($config);
	}

	public static function isMyPageEnabled(?object $config = null): bool
	{
		$config = $config ?: self::get();
		return ($config->mypage_enabled ?? 'N') === 'Y';
	}

	/**
	 * Parse per-reason daily caps written as "reason_code=amount" lines.
	 *
	 * @return array<string, int>
	 */
	public static function parseReasonLimits(string $text): array
	{
		$result = [];
		foreach (preg_split('/[\r\n,]+/', $text) as $line)
		{
			if (preg_match('/^\s*([a-z0-9_.:\-]{1,40})\s*=\s*([0-9]+)\s*$/i', $line, $m))
			{
				$result[strtolower($m[1])] = (int)$m[2];
			}
		}
		return $result;
	}
}
