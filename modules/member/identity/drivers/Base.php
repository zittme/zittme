<?php

namespace Zittme\Modules\Member\Identity\Drivers;

use Zittme\Modules\Member\Identity\Base as IdentityBase;

/**
 * Identity-verification provider driver contract.
 *
 * ready()  : server-to-server prepare call; returns the provider's browser
 *            start URL + the hidden form fields to auto-submit.
 * confirm(): server-to-server verification of the provider callback; returns
 *            the verified person (name / birthday / sex / phone / CI / DI).
 *
 * Never trust values POSTed by the browser — only what confirm() returns from
 * the provider server counts (다날 보안 가이드).
 */
abstract class Base
{
	protected static $_instances = [];
	protected $provider;
	protected $config;

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
		$this->provider = strtolower(str_replace(__NAMESPACE__ . '\\', '', static::class));
		$this->config = IdentityBase::getConfig();
	}

	public function getProvider(): string
	{
		return $this->provider;
	}

	/**
	 * Whether the admin has filled in every credential this driver needs.
	 */
	abstract public function isConfigured(): bool;

	/**
	 * Prepare a verification transaction.
	 *
	 * @param string $target_url absolute callback URL (our server)
	 * @param string $back_url   absolute URL to return to on cancel/error
	 * @param array  $bypass     extra values the provider must POST back to us
	 * @return array ['success'=>bool, 'start_url'=>string, 'params'=>array, 'message'=>string]
	 */
	abstract public function ready(string $target_url, string $back_url, array $bypass = []): array;

	/**
	 * Confirm a finished transaction with the provider server.
	 *
	 * @param array $post the callback POST data (TID etc.)
	 * @return array ['success'=>bool, 'ci','di','name','birthday','sex','phone','tid','message','raw']
	 */
	abstract public function confirm(array $post): array;

	/**
	 * Absolute URL (with scheme+domain) for a member-module action.
	 */
	public static function buildActionUrl(string $act, array $extra = []): string
	{
		$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		$domain = \Zittme\Framework\URL::getCurrentDomain(true);
		// 파라미터는 act 하나만 쓴다.
		// 인증 제공사(다날)는 브라우저를 TARGETURL 로 돌려보낼 때 첫 파라미터만 남기고
		// 뒤(&act=...)를 잘라 버린다. module=member&act=... 로 주면 브라우저 복귀 요청이
		// index.php?module=member 가 되어 act 를 잃고 CSRF 오류로 떨어진다.
		// act 이름만 있어도 코어가 모듈(member)을 역추적하므로 module 파라미터는 불필요하다.
		$url = $protocol . '://' . $domain . \RX_BASEURL . 'index.php?act=' . $act;
		foreach ($extra as $key => $value)
		{
			// 위 이유로 추가 파라미터는 URL 이 아니라 bypass(POST 본문)로 전달할 것
			$url .= '&' . $key . '=' . urlencode((string)$value);
		}
		return $url;
	}
}
