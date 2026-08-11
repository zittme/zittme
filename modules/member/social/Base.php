<?php

namespace Zittme\Modules\Member\Social;

use Context;
use MemberModel;
use stdClass;

/**
 * Social login helper — configuration, driver factory and enabled-service
 * resolution. Ported into the member module so social login is a built-in
 * member feature (default OFF), configured per provider in the admin.
 */
class Base
{
	public static $config = null;

	/**
	 * Providers shipped with the engine. The ZZAN SSO ("rhymix") provider and
	 * OAuth-provider role are intentionally excluded from the core.
	 */
	public static $supported_services = [
		'kakao',
		'naver',
		'google',
	];

	/**
	 * Load the social login configuration from the member module config.
	 * All keys are normalised with defaults so a never-configured site does
	 * not raise undefined-property warnings.
	 */
	public static function getConfig()
	{
		if (self::$config === null)
		{
			$config = MemberModel::getMemberConfig() ?: new stdClass();

			$config->sns_login_enable = $config->sns_login_enable ?? 'N';
			foreach (self::$supported_services as $service)
			{
				$config->{'sns_' . $service . '_enable'} = $config->{'sns_' . $service . '_enable'} ?? 'N';
				$config->{$service . '_client_id'} = $config->{$service . '_client_id'} ?? '';
				$config->{$service . '_client_secret'} = $config->{$service . '_client_secret'} ?? '';
			}

			self::$config = $config;
		}
		return self::$config;
	}

	/**
	 * Whether social login is globally enabled and at least one provider is usable.
	 */
	public static function isEnabled(): bool
	{
		$config = self::getConfig();
		return $config->sns_login_enable === 'Y' && count(self::getEnabledServices()) > 0;
	}

	/**
	 * Providers that are toggled on AND have both client id/secret filled in.
	 */
	public static function getEnabledServices(): array
	{
		$config = self::getConfig();
		$services = [];
		foreach (self::$supported_services as $service)
		{
			if ($config->{'sns_' . $service . '_enable'} === 'Y'
				&& $config->{$service . '_client_id'}
				&& $config->{$service . '_client_secret'})
			{
				$services[] = $service;
			}
		}
		return $services;
	}

	/**
	 * Whether a single provider is enabled and usable.
	 */
	public static function isServiceEnabled(string $service): bool
	{
		return in_array(strtolower($service), self::getEnabledServices(), true);
	}

	/**
	 * Instantiate the driver for a service (singleton per class).
	 */
	public static function getDriver(string $service)
	{
		$service = strtolower($service);
		if (!in_array($service, self::$supported_services, true))
		{
			return null;
		}

		$class_name = '\\Zittme\\Modules\\Member\\Social\\Drivers\\' . ucfirst($service);
		if (class_exists($class_name))
		{
			return $class_name::getInstance();
		}
		return null;
	}
}
