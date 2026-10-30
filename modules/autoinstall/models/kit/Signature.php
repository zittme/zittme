<?php

namespace Zittme\Modules\Autoinstall\Models\Kit;

/**
 * Ed25519 signatures over the kit manifest.
 */
class Signature
{
	public const CONTEXT = "zittme-kit-v1\n";

	public const TRUSTED_KEYS = [
		'zittme-2026a' => 'HSohXMM2oEfcf3OCWArkZy00MCPlJR8BhrLeT1Yo8hg=',
	];

	public const REVOKED_KEYS = [];

	public static function message(string $manifest_raw): string
	{
		return self::CONTEXT . hash('sha256', $manifest_raw);
	}

	public static function getTrustedKeys(): array
	{
		$keys = self::TRUSTED_KEYS;
		$extra = config('autoinstall.kit_trusted_keys');
		if (is_array($extra))
		{
			foreach ($extra as $key_id => $public)
			{
				if (is_string($key_id) && is_string($public))
				{
					$keys[$key_id] = $public;
				}
			}
		}
		foreach (self::REVOKED_KEYS as $key_id)
		{
			unset($keys[$key_id]);
		}
		return $keys;
	}

	/**
	 * @return string 'valid', 'unsigned', 'unknown_key', 'invalid', 'unsupported'
	 */
	public static function verify(string $manifest_raw, ?object $signature): string
	{
		if (!$signature || empty($signature->sig) || empty($signature->key_id))
		{
			return 'unsigned';
		}
		if (($signature->alg ?? 'ed25519') !== 'ed25519')
		{
			return 'invalid';
		}
		if (!function_exists('sodium_crypto_sign_verify_detached'))
		{
			return 'unsupported';
		}
		$keys = self::getTrustedKeys();
		$key_id = (string)$signature->key_id;
		if (!isset($keys[$key_id]))
		{
			return 'unknown_key';
		}
		$public = base64_decode($keys[$key_id], true);
		$sig = base64_decode((string)$signature->sig, true);
		if ($public === false || $sig === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES)
		{
			return 'invalid';
		}
		try
		{
			return sodium_crypto_sign_verify_detached($sig, self::message($manifest_raw), $public) ? 'valid' : 'invalid';
		}
		catch (\Throwable $e)
		{
			return 'invalid';
		}
	}

	public static function allowUnsigned(): bool
	{
		return config('autoinstall.kit_allow_unsigned') === true;
	}

	public static function sign(string $manifest_raw, string $key_id, string $secret_b64): object
	{
		$secret = base64_decode($secret_b64, true);
		return (object)[
			'key_id' => $key_id,
			'alg' => 'ed25519',
			'sig' => base64_encode(sodium_crypto_sign_detached(self::message($manifest_raw), $secret)),
		];
	}
}
