<?php

namespace Zittme\Modules\Member\Identity;

use MemberModel;
use stdClass;

/**
 * 본인인증(휴대폰 실명인증) helper — configuration, driver factory and the
 * session store for a completed verification.
 *
 * Built into the member module (default OFF) the same way social login is:
 * providers are drivers under identity/drivers/, configured per provider in
 * the member admin. Danal(UAS) ships first; KG Inicis etc. can be added as
 * additional drivers without touching callers.
 */
class Base
{
	public static $config = null;

	/**
	 * Session key holding the last successful verification result.
	 */
	const SESSION_KEY = 'member_identity_verified';

	/**
	 * How long (seconds) a completed verification stays valid for consuming
	 * flows (signup form submit, adult gate, mypage confirm).
	 */
	const VERIFY_TTL = 1800;

	/**
	 * Providers shipped with the engine. Add new drivers here.
	 */
	public static $supported_providers = [
		'danal',
	];

	/**
	 * Member config with identity_* keys normalised so a never-configured
	 * site does not raise undefined-property warnings.
	 */
	public static function getConfig()
	{
		if (self::$config === null)
		{
			$config = MemberModel::getMemberConfig() ?: new stdClass();

			$config->identity_enable = $config->identity_enable ?? 'N';
			$config->identity_provider = $config->identity_provider ?? 'danal';
			$config->identity_dup_check = $config->identity_dup_check ?? 'Y';
			$config->identity_signup_required = $config->identity_signup_required ?? 'N';
			$config->identity_find_account = $config->identity_find_account ?? 'N';
			$config->identity_adult_mids = is_array($config->identity_adult_mids ?? null) ? $config->identity_adult_mids : [];
			// 나이 기준 그룹 편입 규칙: [group_srl => 최소 나이]. 비어 있으면 아무 일도 하지 않는다.
			$config->identity_age_groups = is_array($config->identity_age_groups ?? null) ? $config->identity_age_groups : [];
			$config->identity_adult_age = (int)($config->identity_adult_age ?? 19) ?: 19;
			$config->identity_danal_cpid = $config->identity_danal_cpid ?? '';
			$config->identity_danal_cppwd = $config->identity_danal_cppwd ?? '';
			$config->identity_danal_charset = $config->identity_danal_charset ?? 'UTF-8';

			self::$config = $config;
		}
		return self::$config;
	}

	/**
	 * Whether identity verification is enabled and the active provider is
	 * fully configured.
	 */
	public static function isEnabled(): bool
	{
		$config = self::getConfig();
		if ($config->identity_enable !== 'Y')
		{
			return false;
		}
		$driver = self::getDriver();
		return $driver !== null && $driver->isConfigured();
	}

	/**
	 * Instantiate the active (or a specific) provider driver.
	 */
	public static function getDriver(?string $provider = null)
	{
		$provider = strtolower($provider ?? self::getConfig()->identity_provider);
		if (!in_array($provider, self::$supported_providers, true))
		{
			return null;
		}

		$class_name = '\\Zittme\\Modules\\Member\\Identity\\Drivers\\' . ucfirst($provider);
		if (class_exists($class_name))
		{
			return $class_name::getInstance();
		}
		return null;
	}

	/**
	 * Store a successful verification in the session so consuming flows
	 * (signup, adult gate, mypage) can read it once within the TTL.
	 */
	public static function setVerified(array $result): void
	{
		$result['verified_at'] = time();
		$_SESSION[self::SESSION_KEY] = $result;
	}

	/**
	 * The current (unexpired) verification result, or null.
	 */
	public static function getVerified(): ?array
	{
		$result = $_SESSION[self::SESSION_KEY] ?? null;
		if (!is_array($result) || empty($result['verified_at']))
		{
			return null;
		}
		if (time() - $result['verified_at'] > self::VERIFY_TTL)
		{
			unset($_SESSION[self::SESSION_KEY]);
			return null;
		}
		return $result;
	}

	public static function clearVerified(): void
	{
		unset($_SESSION[self::SESSION_KEY]);
	}

	/* ---------------------------------------------------------------------
	 * Ticket store (session-independent).
	 *
	 * The provider posts the callback CROSS-SITE, so the session cookie may
	 * not be sent (SameSite) and PHP would mint a NEW session — replacing the
	 * opener's session cookie and invalidating its CSRF tokens. Therefore the
	 * callback never touches the session: state + the confirmed result live in
	 * a server-side file store keyed by a random ticket. The opener reloads
	 * with ?identity_ticket=... and the consuming page claims it INTO its own
	 * (original) session.
	 * ------------------------------------------------------------------- */

	/** Ticket lifetime: issue→callback, and callback→claim. */
	const TICKET_TTL = 600;

	/** Max concurrent unclaimed tickets per issuer (anti-abuse). */
	const TICKET_MAX_PER_ISSUER = 5;

	protected static function ticketDir(): string
	{
		return \RX_BASEDIR . 'files/cache/member_identity/';
	}

	protected static function ticketPath(string $state): string
	{
		return self::ticketDir() . preg_replace('/[^a-f0-9]/', '', $state) . '.json';
	}

	/**
	 * Fingerprint of the browser that started the verification. The claim must
	 * come from the same browser, so a leaked ticket (Referer / history / logs)
	 * cannot be redeemed by an attacker. Session id is included when available
	 * but never required — the callback itself stays session-free.
	 */
	protected static function issuerFingerprint(): string
	{
		$parts = [
			(string)($_SERVER['REMOTE_ADDR'] ?? ''),
			(string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
		];
		return hash('sha256', implode('|', $parts));
	}

	/**
	 * Session id hash, or '' when no session is active. Compared only when
	 * present on BOTH ends so a session regenerated mid-flow (e.g. login)
	 * never locks out a legitimate user, while same-NAT/same-UA attackers
	 * still cannot redeem someone else's ticket.
	 */
	protected static function issuerSessionHash(): string
	{
		$sid = function_exists('session_id') ? (string)@session_id() : '';
		return $sid === '' ? '' : hash('sha256', $sid);
	}

	/**
	 * Delete expired ticket files (called on issue/claim — no cron needed).
	 */
	protected static function purgeExpiredTickets(): void
	{
		$dir = self::ticketDir();
		if (!is_dir($dir))
		{
			return;
		}
		$now = time();
		foreach ((array)glob($dir . '*.json') as $file)
		{
			if (@filemtime($file) < $now - (self::TICKET_TTL * 2))
			{
				@unlink($file);
			}
		}
	}

	/**
	 * Issue a new state ticket (verification popup start), bound to this browser.
	 */
	public static function issueTicketState(): string
	{
		self::purgeExpiredTickets();

		// anti-abuse: cap unclaimed tickets held by this issuer
		$fingerprint = self::issuerFingerprint();
		$mine = 0;
		foreach ((array)glob(self::ticketDir() . '*.json') as $file)
		{
			$data = json_decode((string)@file_get_contents($file), true);
			if (is_array($data) && ($data['fp'] ?? '') === $fingerprint)
			{
				$mine++;
			}
		}
		if ($mine >= self::TICKET_MAX_PER_ISSUER)
		{
			throw new \Zittme\Framework\Exception('msg_too_many_requests');
		}

		$state = \Zittme\Framework\Security::getRandom(32, 'hex');
		\FileHandler::writeFile(self::ticketPath($state), json_encode([
			'issued' => time(),
			'fp' => $fingerprint,
			'sid' => self::issuerSessionHash(),
		]));
		return $state;
	}

	/**
	 * Whether a state ticket exists and is fresh (callback correlation).
	 * The fingerprint is NOT checked here — the callback comes from the
	 * provider's server/browser context, not the issuing browser.
	 */
	public static function isTicketValid(string $state): bool
	{
		if (!preg_match('/^[a-f0-9]{32}$/', $state))
		{
			return false;
		}
		$raw = \FileHandler::readFile(self::ticketPath($state));
		if (!$raw)
		{
			return false;
		}
		$data = json_decode($raw, true);
		return is_array($data) && !empty($data['issued']) && (time() - $data['issued']) <= self::TICKET_TTL;
	}

	/**
	 * Store the confirmed result under the ticket (callback, session-free).
	 * The issuer fingerprint from the issue step is preserved so the claim can
	 * verify that the same browser is redeeming it.
	 */
	public static function storeTicketResult(string $state, array $result): void
	{
		$existing = json_decode((string)\FileHandler::readFile(self::ticketPath($state)), true);
		$result['fp'] = is_array($existing) ? ($existing['fp'] ?? '') : '';
		$result['sid'] = is_array($existing) ? ($existing['sid'] ?? '') : '';
		$result['stored_at'] = time();
		\FileHandler::writeFile(self::ticketPath($state), json_encode($result, JSON_UNESCAPED_UNICODE));
	}

	/**
	 * Claim a ticket into the CURRENT session and apply member policies
	 * (CI duplicate / re-verify same-person / persist). Returns
	 * ['success'=>bool, 'message'=>string].
	 */
	public static function claimTicket(string $state, string $purpose = ''): array
	{
		if (!preg_match('/^[a-f0-9]{32}$/', $state))
		{
			return ['success' => false, 'message' => lang('msg_invalid_request')];
		}
		self::purgeExpiredTickets();

		$path = self::ticketPath($state);
		$raw = \FileHandler::readFile($path);
		\FileHandler::removeFile($path); // single use
		if (!$raw)
		{
			return ['success' => false, 'message' => lang('identity_failed')];
		}
		$result = json_decode($raw, true);
		if (!is_array($result) || empty($result['ci']) || empty($result['stored_at']) || (time() - $result['stored_at']) > self::TICKET_TTL)
		{
			return ['success' => false, 'message' => lang('identity_failed')];
		}

		// The redeeming browser must be the one that started the verification.
		// A ticket leaked via Referer / history / proxy logs is useless elsewhere.
		if (!hash_equals((string)($result['fp'] ?? ''), self::issuerFingerprint()))
		{
			return ['success' => false, 'message' => lang('identity_ticket_mismatch')];
		}
		// tighten with the session when both ends have one (same-NAT attackers)
		$issued_sid = (string)($result['sid'] ?? '');
		$current_sid = self::issuerSessionHash();
		if ($issued_sid !== '' && $current_sid !== '' && !hash_equals($issued_sid, $current_sid))
		{
			return ['success' => false, 'message' => lang('identity_ticket_mismatch')];
		}
		unset($result['fp'], $result['sid'], $result['stored_at']);

		$config = self::getConfig();
		$identityModel = IdentityModel::getInstance();
		$logged_info = \Context::get('logged_info');
		$member_srl = ($logged_info && !empty($logged_info->member_srl)) ? (int)$logged_info->member_srl : 0;

		// duplicate-signup guard: one CI = one account
		// 계정 찾기는 "이미 가입된 본인"을 확인하는 흐름이므로 이 규칙을 적용하지
		// 않는다 — 적용하면 정상 이용자가 "이미 사용된 정보입니다"로 막힌다.
		if ($config->identity_dup_check === 'Y' && $purpose !== 'find_account')
		{
			$holder = $identityModel->getByCi((string)$result['ci']);
			if ($holder && (int)$holder->member_srl !== $member_srl)
			{
				return ['success' => false, 'message' => lang('identity_ci_duplicate')];
			}
		}

		// re-verify policy: same person only; refresh phone number only
		$existing = $member_srl ? $identityModel->getByMemberSrl($member_srl) : null;
		if ($existing && !hash_equals((string)$existing->ci, (string)$result['ci']))
		{
			return ['success' => false, 'message' => lang('identity_ci_mismatch')];
		}

		self::setVerified($result);

		if ($member_srl)
		{
			if ($existing)
			{
				$identityModel->saveForMember($member_srl, [
					'provider' => $result['provider'] ?? $existing->provider,
					'ci' => $existing->ci,
					'di' => $existing->di,
					'name' => $existing->name,
					'birthday' => $existing->birthday,
					'sex' => $existing->sex,
					'phone' => !empty($result['phone']) ? $result['phone'] : $existing->phone,
					'telecom' => !empty($result['telecom']) ? $result['telecom'] : $existing->telecom,
					'tid' => $result['tid'] ?? '',
				]);
			}
			else
			{
				$identityModel->saveForMember($member_srl, $result);
			}

			// 나이 기준 그룹 편입 (관리자가 규칙을 켠 경우에만)
			self::applyAgeGroups($member_srl, (string)($result['birthday'] ?? ''));
		}

		return ['success' => true, 'message' => lang('identity_success')];
	}

	/**
	 * Convenience: claim ?identity_ticket=... if present on the request.
	 * Sets identity_claim_error in the Context on failure.
	 *
	 * $discard_stale=true 로 부르면 티켓 없이 들어온 요청(= 인증 팝업에서 막
	 * 돌아온 것이 아니라 페이지를 새로 연 것)에서 이전 인증 결과를 폐기한다.
	 * 가입/계정찾기처럼 비로그인 상태로 진행하는 흐름은 앞사람이 인증만 하고
	 * 이탈했을 때 다음 사람이 그 인증(이름·생년월일·CI)을 그대로 물려받게 되므로
	 * 공용 PC 에서 반드시 끊어야 한다.
	 */
	public static function claimTicketFromRequest(bool $discard_stale = false, string $purpose = ''): void
	{
		$ticket = (string)\Context::get('identity_ticket');
		if ($ticket === '')
		{
			if ($discard_stale)
			{
				self::clearVerified();
			}
			return;
		}
		$claim = self::claimTicket($ticket, $purpose);
		if (empty($claim['success']))
		{
			\Context::set('identity_claim_error', $claim['message']);
		}
	}

	/**
	 * 인증으로 확인된 나이에 따라 회원을 지정 그룹에 편입한다.
	 *
	 * 관리자가 그룹별로 "만 N세 이상이면 편입"을 켜 둔 경우에만 동작하며,
	 * 넣기만 하고 빼지는 않는다 — 자동 회수는 운영 사고 위험이 커서, 조건을
	 * 잃은 회원의 그룹 해제는 관리자가 판단하도록 남긴다.
	 *
	 * @return int[] 실제로 편입한 group_srl 목록
	 */
	public static function applyAgeGroups(int $member_srl, string $birthday): array
	{
		if (!$member_srl)
		{
			return [];
		}
		$rules = self::getConfig()->identity_age_groups;
		if (!$rules)
		{
			return [];
		}
		$age = self::getAgeFromBirthday($birthday);
		if ($age === null)
		{
			return [];
		}

		$joined = [];
		$member_groups = \MemberModel::getMemberGroups($member_srl) ?: [];
		foreach ($rules as $group_srl => $min_age)
		{
			$group_srl = (int)$group_srl;
			$min_age = (int)$min_age;
			if ($group_srl <= 0 || $min_age <= 0 || $age < $min_age)
			{
				continue;
			}
			if (isset($member_groups[$group_srl]))
			{
				continue;
			}
			$output = \MemberController::addMemberToGroup($member_srl, $group_srl);
			if ($output->toBool())
			{
				$joined[] = $group_srl;
			}
		}
		return $joined;
	}

	/**
	 * Age (Korean "만 나이") computed from a YYYYMMDD birthday.
	 */
	public static function getAgeFromBirthday(string $birthday): ?int
	{
		// 저장된 값의 형식이 8자리 숫자로만 들어온다고 가정하지 않는다.
		// 구분자가 섞이거나(1990-01-01) 6자리(YYMMDD)로 들어온 값도 계산해야
		// 실제로 인증을 마친 회원이 미인증으로 떨어지지 않는다.
		$birthday = preg_replace('/[^0-9]/', '', $birthday);
		if (strlen($birthday) === 6)
		{
			// 두 자리 연도: 올해보다 크면 1900년대로 본다
			$yy = (int)substr($birthday, 0, 2);
			$century = ($yy > (int)date('y')) ? '19' : '20';
			$birthday = $century . $birthday;
		}
		if (!preg_match('/^\d{8}$/', $birthday))
		{
			return null;
		}
		$birth = \DateTime::createFromFormat('Ymd', $birthday);
		if (!$birth)
		{
			return null;
		}
		return (int)$birth->diff(new \DateTime('now'))->y;
	}
}
