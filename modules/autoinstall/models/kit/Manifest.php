<?php

namespace Zittme\Modules\Autoinstall\Models\Kit;

use Zittme\Modules\Autoinstall\Models\Package;

class Manifest
{
	public const SCHEMA = 1;
	public const PACKAGE_TYPES = ['module', 'addon', 'layout', 'widget', 'module-skin', 'widget-skin', 'editor-skin', 'editor-component', 'theme'];

	/**
	 * Validate a manifest. Returns a list of error keys (empty when valid).
	 */
	public static function validate(object $m): array
	{
		$errors = [];
		if ((int)($m->schema ?? 0) !== self::SCHEMA)
		{
			$errors[] = 'schema';
		}
		if (!self::isValidId((string)($m->id ?? '')))
		{
			$errors[] = 'id';
		}
		if (!preg_match('/^\d+\.\d+\.\d+$/', (string)($m->version ?? '')))
		{
			$errors[] = 'version';
		}
		if (!is_object($m->title ?? null) || trim((string)($m->title->ko ?? '')) === '' || trim((string)($m->title->en ?? '')) === '')
		{
			$errors[] = 'title';
		}
		if (!Version::isValidConstraint((string)($m->requires->core ?? '')))
		{
			$errors[] = 'requires';
		}
		foreach ((array)($m->packages ?? []) as $p)
		{
			if (!is_object($p) || !in_array($p->type ?? '', self::PACKAGE_TYPES, true))
			{
				$errors[] = 'packages';
				break;
			}
			$pattern = Package::INSTALL_PATHS[$p->type] ?? null;
			if (!$pattern || !preg_match($pattern, (string)($p->install_path ?? '')))
			{
				$errors[] = 'packages';
				break;
			}
			if (!Version::isValidConstraint((string)($p->version ?? '')))
			{
				$errors[] = 'packages';
				break;
			}
		}
		foreach ((array)($m->content ?? []) as $key => $path)
		{
			if ($key === 'module_data')
			{
				foreach ((array)$path as $module => $sub)
				{
					if (!preg_match('/^[a-z0-9_]+$/', (string)$module) || !self::isDataPath((string)$sub))
					{
						$errors[] = 'content';
						break 2;
					}
				}
				continue;
			}
			if (!self::isDataPath((string)$path))
			{
				$errors[] = 'content';
				break;
			}
		}
		return array_values(array_unique($errors));
	}

	public static function isValidId(string $id): bool
	{
		return (bool)preg_match('/^[a-z][a-z0-9_]{1,39}$/', $id);
	}

	public static function isDataPath(string $path): bool
	{
		return (bool)preg_match('!^data/[a-z0-9_\-]+\.json$!', $path);
	}

	public static function text($value, ?string $lang = null): string
	{
		if (is_string($value))
		{
			return $value;
		}
		if (!is_object($value))
		{
			return '';
		}
		$lang = $lang ?: \Context::getLangType();
		foreach ([$lang, 'en', 'ko'] as $code)
		{
			if (isset($value->{$code}) && trim((string)$value->{$code}) !== '')
			{
				return (string)$value->{$code};
			}
		}
		$vars = get_object_vars($value);
		return (string)(reset($vars) ?: '');
	}
}
