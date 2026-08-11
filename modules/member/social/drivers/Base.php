<?php

namespace Zittme\Modules\Member\Social\Drivers;

use FileHandler;
use Zittme\Modules\Member\Social\Base as SnsBase;

abstract class Base
{
	protected static $_instances = [];
	protected $service;
	protected $config;
	protected $request_content_type = 'application/x-www-form-urlencoded';

	public static function getInstance()
	{
		$class_name = static::class;
		if (!isset(self::$_instances[$class_name]))
		{
			self::$_instances[$class_name] = new static();
		}
		return self::$_instances[$class_name];
	}

	protected function __construct()
	{
		$this->service = strtolower(str_replace(__NAMESPACE__ . '\\', '', static::class));
		$this->config = SnsBase::getConfig();
	}

	abstract public function createAuthUrl(): string;

	abstract public function authenticate();

	abstract public function getUserInfo($access_token = null);

	public function getService(): string
	{
		return $this->service;
	}

	/**
	 * Absolute callback URL (with domain) for a given provider.
	 */
	public static function buildCallbackUrl(string $service): string
	{
		$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		$domain = \Zittme\Framework\URL::getCurrentDomain(true);
		return $protocol . '://' . $domain . \RX_BASEURL . 'index.php?module=member&act=procMemberSnsCallback&service=' . $service;
	}

	protected function generateState(): string
	{
		$state = md5(microtime() . mt_rand());
		$_SESSION['member_sns_auth']['state'] = $state;
		return $state;
	}

	protected function validateState($state): bool
	{
		$stored = $_SESSION['member_sns_auth']['state'] ?? null;
		unset($_SESSION['member_sns_auth']['state']);
		return (bool)($state && $stored && $state === $stored);
	}

	protected function requestAPI($url, $params = [], $headers = [])
	{
		$method = empty($params) ? 'GET' : 'POST';
		$response = FileHandler::getRemoteResource($url, null, 3, $method, $this->request_content_type, $headers, [], $params);
		return json_decode($response, true);
	}
}
