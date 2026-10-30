<?php

namespace Zittme\Modules\Autoinstall\Models\Kit;

class Version
{
	/**
	 * Check a version against constraints such as ">=1.1.0 <2.0.0".
	 */
	public static function satisfies(string $version, string $constraint): bool
	{
		$constraint = trim($constraint);
		if ($constraint === '' || $constraint === '*')
		{
			return true;
		}
		if ($version === '')
		{
			return false;
		}
		foreach (preg_split('/[\s,]+/', $constraint) as $part)
		{
			if (!preg_match('/^(>=|<=|>|<|=|==)?\s*v?(\d+(?:\.\d+){0,3})$/', $part, $m))
			{
				return false;
			}
			$op = $m[1] ?: '>=';
			if ($op === '=')
			{
				$op = '==';
			}
			if (!version_compare(self::normalize($version), self::normalize($m[2]), $op))
			{
				return false;
			}
		}
		return true;
	}

	public static function isValidConstraint(string $constraint): bool
	{
		$constraint = trim($constraint);
		if ($constraint === '' || $constraint === '*')
		{
			return true;
		}
		foreach (preg_split('/[\s,]+/', $constraint) as $part)
		{
			if (!preg_match('/^(>=|<=|>|<|=|==)?\s*v?\d+(?:\.\d+){0,3}$/', $part))
			{
				return false;
			}
		}
		return true;
	}

	public static function normalize(string $version): string
	{
		$version = ltrim(trim($version), 'v');
		$parts = explode('.', preg_replace('/[^0-9.].*$/', '', $version));
		while (count($parts) < 3)
		{
			$parts[] = '0';
		}
		return implode('.', $parts);
	}

	public static function coreVersion(): string
	{
		return defined('ZITTME_VERSION') ? (string)\ZITTME_VERSION : (string)\RX_VERSION;
	}
}
