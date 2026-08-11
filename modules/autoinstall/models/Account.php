<?php

namespace Zittme\Modules\Autoinstall\Models;

use Zittme\Framework\HTTP;
use Context;

/**
 * zitt.me 계정 연결.
 *
 * 관리자가 팝업에서 한 번 승인하면, 이 사이트의 도메인에 묶인 토큰이 발급되어
 * autoinstall 모듈 설정에 저장된다. 토큰은 다음 두 가지에만 쓴다.
 *
 *  1. 계정 상태 확인 (누구로 연결돼 있는지 표시)
 *  2. 스토어·전문가로 이동할 때 단기 티켓을 받아 자동 로그인
 *
 * 토큰은 서버 밖(브라우저 주소·링크)으로 절대 내보내지 않는다. 브라우저가 들고
 * 가는 값은 60초짜리 1회용 티켓뿐이다.
 */
class Account
{
	/** 계정 연결·자동 로그인을 담당하는 zitt.me 주소 */
	public const CONNECT_URL = 'https://zitt.me/index.php?module=store&act=dispStoreConnect';
	public const ACCOUNT_URL = 'https://zitt.me/index.php?module=store&act=procStoreConnectAccount';
	public const TICKET_URL = 'https://zitt.me/index.php?module=store&act=procStoreConnectTicket';
	public const REVOKE_URL = 'https://zitt.me/index.php?module=store&act=procStoreConnectRevoke';
	public const EXCHANGE_URL = 'https://zitt.me/index.php?module=store&act=procStoreConnectExchange';

	/**
	 * 저장된 연결 정보. 연결 전이면 null.
	 *
	 * @return ?object {token, nick_name, domain, connected_at}
	 */
	public static function get(): ?object
	{
		$config = \ModuleModel::getModuleConfig('autoinstall');
		$account = $config->zittme_account ?? null;
		if (!is_object($account) || empty($account->token))
		{
			return null;
		}

		// 저장은 암호문으로 한다. 설정을 그대로 들여다봐도 토큰이 드러나지 않는다.
		if (!empty($account->token_encrypted))
		{
			$plain = \Rhymix\Framework\Security::decrypt((string)$account->token);
			if ($plain === false || $plain === '')
			{
				return null;
			}
			$account = clone $account;
			$account->token = $plain;
		}
		return $account;
	}

	public static function isConnected(): bool
	{
		return self::get() !== null;
	}

	/**
	 * 연결 저장.
	 */
	public static function save(string $token, string $nick_name, string $domain): void
	{
		$config = \ModuleModel::getModuleConfig('autoinstall') ?: new \stdClass;
		$config->zittme_account = (object)[
			'token' => \Rhymix\Framework\Security::encrypt($token),
			'token_encrypted' => true,
			'nick_name' => $nick_name,
			'domain' => $domain,
			'connected_at' => date('YmdHis'),
		];
		\ModuleController::getInstance()->insertModuleConfig('autoinstall', $config);
	}

	/**
	 * 연결 해제. zitt.me 쪽 토큰도 함께 폐기한다(실패해도 로컬 저장은 지운다).
	 */
	public static function clear(): void
	{
		$account = self::get();
		if ($account)
		{
			try
			{
				HTTP::post(self::REVOKE_URL, ['token' => $account->token], [], [], ['timeout' => 5]);
			}
			catch (\Throwable $e)
			{
				// zitt.me 에 닿지 못해도 이 사이트의 연결은 끊는다
			}
		}

		$config = \ModuleModel::getModuleConfig('autoinstall') ?: new \stdClass;
		unset($config->zittme_account);
		\ModuleController::getInstance()->insertModuleConfig('autoinstall', $config);
	}

	/**
	 * 이 사이트를 연결할 때 팝업으로 열 주소.
	 */
	public static function getConnectUrl(): string
	{
		// 승인 후 이 주소로 되돌아온다. 팝업 없이 같은 탭에서 오간다.
		$return_url = getNotEncodedFullUrl('', 'module', 'admin', 'act', 'dispAutoinstallAdminConnectCallback');

		// PKCE — 비밀값(verifier)은 이 서버에만 두고 해시만 보낸다.
		// 돌아오는 코드가 새어도 verifier 를 모르면 토큰으로 바꿀 수 없다.
		//
		// 세션에 담으면 안 된다. 연결 링크는 관리자 화면을 열 때마다 새로 만들어지는데,
		// 세션 한 칸을 공유하면 그 사이 다른 화면이 한 번만 렌더돼도 값이 덮여 승인 후
		// 검증이 어긋난다. 연결 건마다 state 로 짝을 지어 파일에 보관한다.
		$verifier = \Rhymix\Framework\Security::getRandom(64, 'hex');
		$state = \Rhymix\Framework\Security::getRandom(32, 'hex');
		// 승인을 마치면 연결을 시작한 화면(스토어 / 전문가)으로 되돌려 준다
		self::saveVerifier($state, $verifier, (string)Context::get('act'));
		$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

		$return_url .= '&state=' . $state;

		return self::CONNECT_URL
			. '&site_domain=' . urlencode(self::getSiteDomain())
			. '&site_title=' . urlencode((string)Context::getSiteTitle())
			. '&return_url=' . urlencode($return_url)
			. '&code_challenge=' . urlencode($challenge);
	}

	/**
	 * 승인 후 받은 코드를 토큰과 교환한다 (서버간 호출).
	 *
	 * @return bool 성공 여부
	 */
	public static function exchangeCode(string $code, string $state): bool
	{
		if (!preg_match('/^[a-f0-9]{32}$/', $code))
		{
			return false;
		}

		// 승인 요청 때 만들어 둔 비밀값 (state 로 짝을 찾는다). 1회용이다.
		$verifier = self::takeVerifier($state);
		if ($verifier === '')
		{
			return false;
		}

		try
		{
			$request = HTTP::post(self::EXCHANGE_URL, [
				'code' => $code,
				'code_verifier' => $verifier,
			], [], [], ['timeout' => 10]);
			if ($request->getStatusCode() !== 200)
			{
				return false;
			}
			$response = json_decode((string)$request->getBody()->getContents());
			if (empty($response->token))
			{
				return false;
			}
			self::save((string)$response->token, (string)($response->nick_name ?? ''), self::getSiteDomain());
			return true;
		}
		catch (\Throwable $e)
		{
			return false;
		}
	}

	/**
	 * 스토어/전문가로 자동 로그인 상태로 이동할 주소.
	 * 서버간 호출로 1회용 티켓을 받아 온다. 실패하면 그냥 공개 주소를 돌려준다.
	 *
	 * @param string $to 'store' | 'expert'
	 */
	public static function getEnterUrl(string $to): string
	{
		$fallback = ($to === 'expert') ? 'https://zitt.me/expert' : 'https://zitt.me/store';
		$account = self::get();
		if (!$account)
		{
			return $fallback;
		}

		try
		{
			$request = HTTP::post(self::TICKET_URL, [
				'token' => $account->token,
				'to' => $to,
			], [], [], ['timeout' => 5]);
			if ($request->getStatusCode() !== 200)
			{
				return $fallback;
			}
			$response = json_decode((string)$request->getBody()->getContents());
			return (!empty($response->enter_url)) ? (string)$response->enter_url : $fallback;
		}
		catch (\Throwable $e)
		{
			return $fallback;
		}
	}

	/**
	 * 이 사이트의 도메인 (토큰이 묶이는 대상).
	 */
	public static function getSiteDomain(): string
	{
		$host = parse_url(getFullSiteUrl(), \PHP_URL_HOST);
		return strtolower((string)$host);
	}

	/** 연결 대기 값 보관 위치 (웹에서 읽히지 않도록 nginx 에서 막는다) */
	protected static function verifierPath(string $state): string
	{
		return \RX_BASEDIR . 'files/cache/zittme_connect/' . preg_replace('/[^a-f0-9]/', '', $state) . '.json';
	}

	/**
	 * verifier 를 state 에 묶어 보관한다. 오래된 것은 함께 정리한다.
	 */
	protected static function saveVerifier(string $state, string $verifier, string $origin_act = ''): void
	{
		$dir = \RX_BASEDIR . 'files/cache/zittme_connect/';
		foreach ((array)glob($dir . '*.json') as $file)
		{
			if (@filemtime($file) < time() - 1800)
			{
				@unlink($file);
			}
		}
		\FileHandler::writeFile(self::verifierPath($state), json_encode([
			'verifier' => $verifier,
			'origin_act' => $origin_act,
			'issued' => time(),
		]));
	}

	/**
	 * 보관해 둔 verifier 를 꺼내며 지운다. 없거나 만료면 빈 문자열.
	 */
	protected static function takeVerifier(string $state): string
	{
		if (!preg_match('/^[a-f0-9]{32}$/', $state))
		{
			return '';
		}
		$path = self::verifierPath($state);
		$raw = \FileHandler::readFile($path);
		\FileHandler::removeFile($path);
		$data = $raw ? json_decode($raw, true) : null;
		if (!is_array($data) || empty($data['verifier']) || (time() - (int)$data['issued']) > 1800)
		{
			return '';
		}
		// 되돌아갈 화면을 함께 기억해 둔다 (exchangeCode 를 부른 쪽에서 꺼내 쓴다)
		self::$last_origin_act = (string)($data['origin_act'] ?? '');
		return (string)$data['verifier'];
	}

	/** 마지막으로 소비한 연결 건이 시작된 화면 */
	protected static $last_origin_act = '';

	/**
	 * 연결을 시작한 화면으로 돌아갈 act. 알 수 없으면 스토어.
	 */
	public static function getOriginAct(): string
	{
		$allowed = [
			'dispAutoinstallAdminIndex',
			'dispAutoinstallAdminExperts',
			'dispAutoinstallAdminPortfolios',
			'dispAutoinstallAdminProjectWrite',
		];
		return in_array(self::$last_origin_act, $allowed, true)
			? self::$last_origin_act : 'dispAutoinstallAdminIndex';
	}

}
