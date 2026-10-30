<?php

namespace Zittme\Modules\Autoinstall\Models\Kit;

/**
 * Converts PHP values (objects, associative arrays) to JSON-safe data and back
 * without losing the difference between stdClass and associative arrays.
 */
class Codec
{
	public const ARRAY_TAG = '__kit_array';

	public static function pack($value)
	{
		if (is_object($value))
		{
			$out = [];
			foreach (get_object_vars($value) as $key => $item)
			{
				$out[(string)$key] = self::pack($item);
			}
			return (object)$out;
		}
		if (is_array($value))
		{
			if ($value === [] || array_keys($value) === range(0, count($value) - 1))
			{
				return array_map([self::class, 'pack'], $value);
			}
			$out = [];
			foreach ($value as $key => $item)
			{
				$out[(string)$key] = self::pack($item);
			}
			return (object)[self::ARRAY_TAG => (object)$out];
		}
		return $value;
	}

	public static function unpack($value)
	{
		if (is_object($value))
		{
			$vars = get_object_vars($value);
			if (count($vars) === 1 && isset($vars[self::ARRAY_TAG]) && is_object($vars[self::ARRAY_TAG]))
			{
				$out = [];
				foreach (get_object_vars($vars[self::ARRAY_TAG]) as $key => $item)
				{
					$out[$key] = self::unpack($item);
				}
				return $out;
			}
			$out = new \stdClass;
			foreach ($vars as $key => $item)
			{
				$out->{$key} = self::unpack($item);
			}
			return $out;
		}
		if (is_array($value))
		{
			return array_map([self::class, 'unpack'], $value);
		}
		return $value;
	}

	public static function encode($value): string
	{
		return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR);
	}

	public static function decode(string $json)
	{
		return json_decode($json);
	}

	/**
	 * Apply a callback to every string inside a value, keeping its shape.
	 */
	public static function mapStrings($value, callable $callback)
	{
		if (is_string($value))
		{
			return $callback($value);
		}
		if (is_array($value))
		{
			foreach ($value as $key => $item)
			{
				$value[$key] = self::mapStrings($item, $callback);
			}
			return $value;
		}
		if (is_object($value))
		{
			$out = clone $value;
			foreach (get_object_vars($value) as $key => $item)
			{
				$out->{$key} = self::mapStrings($item, $callback);
			}
			return $out;
		}
		return $value;
	}

	/**
	 * Replace strings inside PHP-serialized data safely by round-tripping.
	 */
	public static function mapSerialized(string $serialized, callable $callback): string
	{
		$data = @unserialize($serialized, ['allowed_classes' => ['stdClass']]);
		if (($data === false && $serialized !== serialize(false)) || self::hasForeignObject($data))
		{
			return '';
		}
		return serialize(self::mapStrings($data, $callback));
	}

	/**
	 * Whether a value holds an object other than stdClass (unserialize() turns those into __PHP_Incomplete_Class).
	 */
	public static function hasForeignObject($value, int $depth = 0): bool
	{
		if ($depth > 64)
		{
			return true;
		}
		if (is_object($value))
		{
			if (!($value instanceof \stdClass))
			{
				return true;
			}
			$value = get_object_vars($value);
		}
		if (is_array($value))
		{
			foreach ($value as $item)
			{
				if (self::hasForeignObject($item, $depth + 1))
				{
					return true;
				}
			}
		}
		return false;
	}
}
