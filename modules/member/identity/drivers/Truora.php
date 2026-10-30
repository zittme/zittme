<?php

namespace Zittme\Modules\Member\Identity\Drivers;

use Zittme\Framework\HTTP;

/**
 * Truora Digital Identity (web flow) driver.
 *
 * Flow:
 *  1. ready()   : POST api.account.truora.com/v1/api-keys (key_type=web,
 *                 grant=digital-identity) → temporary web token. The browser is
 *                 sent to identity.truora.com/?token=... . account_id carries our
 *                 state ticket so the finished process can be bound to it.
 *  2. Truora redirects the browser to redirect_url with ?process_id=...
 *  3. confirm() : GET api.identity.truora.com/v1/processes/{id}/result and use
 *                 only what the API returns (browser values are untrusted).
 *  4. handleWebhook(): HS256 JWT signed with the webhook secret; the process is
 *                 re-fetched from the API before anything is stored.
 *
 * Mock mode replaces steps 1–3 with a local simulator so the whole flow can be
 * exercised without credentials.
 */
class Truora extends Base
{
	const TOKEN_URL = 'https://api.account.truora.com/v1/api-keys';
	const FLOW_URL = 'https://identity.truora.com/';
	const RESULT_URL = 'https://api.identity.truora.com/v1/processes/%s/result';
	const TIMEOUT = 20;
	const PENDING_RETRIES = 3;
	const PENDING_WAIT = 2;
	const ACCOUNT_PREFIX = 'zm_';
	const MOCK_TTL = 3600;
	const MOCK_CI_PREFIX = 'TRMOCK:';

	public function isConfigured(): bool
	{
		if ($this->isMock())
		{
			return true;
		}
		return $this->config->identity_truora_api_key !== '' && $this->config->identity_truora_flow_id !== '';
	}

	public function isMock(): bool
	{
		return $this->config->identity_truora_mode === 'mock';
	}

	/**
	 * Mock verification is for testing only: an administrator session, or a
	 * visitor from an allowed IP while IP-restricted debugging is on.
	 */
	public static function isMockAllowed(): bool
	{
		$logged_info = \Context::get('logged_info');
		if ($logged_info && !empty($logged_info->member_srl) && ($logged_info->is_admin ?? 'N') === 'Y')
		{
			return true;
		}
		return config('debug.enabled') && config('debug.display_to') === 'ip' && \Zittme\Framework\Debug::isEnabledForCurrentUser();
	}

	public static function isMockCi(string $ci): bool
	{
		return strncmp($ci, self::MOCK_CI_PREFIX, strlen(self::MOCK_CI_PREFIX)) === 0;
	}

	/**
	 * Truora appends ?process_id= to the redirect URL, which may already carry a
	 * query string, so the state can arrive glued to it ("<state>?process_id=…").
	 */
	public function parseCallback(array $request): array
	{
		$state = (string)($request['IDENTITY_STATE'] ?? '');
		$process_id = (string)($request['process_id'] ?? '');
		if (strpos($state, '?') !== false)
		{
			list($state, $tail) = explode('?', $state, 2);
			parse_str($tail, $extra);
			if ($process_id === '' && isset($extra['process_id']))
			{
				$process_id = (string)$extra['process_id'];
			}
		}
		return [
			'state' => $state,
			'tid' => self::isValidProcessId($process_id) ? $process_id : '',
		];
	}

	public function ready(string $target_url, string $back_url, array $bypass = []): array
	{
		$state = (string)($bypass['IDENTITY_STATE'] ?? '');
		if (!preg_match('/^[a-f0-9]{32}$/', $state))
		{
			return ['success' => false, 'message' => lang('msg_invalid_request')];
		}
		$redirect_url = $target_url . '&IDENTITY_STATE=' . $state;

		if ($this->isMock())
		{
			if (!self::isMockAllowed())
			{
				return ['success' => false, 'message' => lang('identity_truora_mock_forbidden')];
			}
			return [
				'success' => true,
				'method' => 'get',
				'start_url' => self::siteIndexUrl(),
				'params' => [
					'act' => 'dispMemberIdentityTruoraMock',
					'IDENTITY_STATE' => $state,
				],
			];
		}

		$ttl = (int)$this->config->identity_truora_ttl;
		$request = [
			'key_type' => 'web',
			'grant' => 'digital-identity',
			'api_key_version' => '1',
			'country' => 'ALL',
			'redirect_url' => $redirect_url,
			'flow_id' => $this->config->identity_truora_flow_id,
			'account_id' => self::ACCOUNT_PREFIX . $state,
			'time_to_live_access_link' => (string)max(5, min(2880, $ttl ?: 30)),
		];

		$response = $this->callApi('POST', self::TOKEN_URL, $request);
		$token = (string)($response['body']['api_key'] ?? '');
		if ($response['status'] < 200 || $response['status'] >= 300 || $token === '')
		{
			$this->logError('token', $response['status'], $response['body']);
			return ['success' => false, 'message' => lang('identity_truora_unavailable'), 'code' => (string)$response['status']];
		}

		return [
			'success' => true,
			'method' => 'get',
			'start_url' => self::FLOW_URL,
			'params' => ['token' => $token],
		];
	}

	public function confirm(array $post): array
	{
		$parsed = $this->parseCallback($post);
		$state = $parsed['state'];
		$process_id = $parsed['tid'];
		if ($process_id === '' || !preg_match('/^[a-f0-9]{32}$/', $state))
		{
			return ['success' => false, 'message' => lang('msg_invalid_request'), 'code' => '-1'];
		}

		$result = null;
		for ($i = 0; $i < self::PENDING_RETRIES; $i++)
		{
			$result = $this->fetchResult($process_id);
			if ($result === null || strtolower((string)($result['status'] ?? '')) !== 'pending')
			{
				break;
			}
			if (!$this->isMock() && $i < self::PENDING_RETRIES - 1)
			{
				sleep(self::PENDING_WAIT);
			}
		}

		return $this->mapResult($result, $state, $process_id);
	}

	/**
	 * Webhook: verify the HS256 signature, then re-fetch each referenced process
	 * from the API. Returns the verified results keyed by state ticket.
	 *
	 * @return array ['valid'=>bool, 'results'=>[state => result]]
	 */
	public function handleWebhook(string $body): array
	{
		$secret = (string)$this->config->identity_truora_webhook_secret;
		if ($secret === '')
		{
			return ['valid' => false, 'results' => []];
		}
		$claims = self::decodeJwt(trim($body), $secret);
		if ($claims === null)
		{
			return ['valid' => false, 'results' => []];
		}

		$results = [];
		foreach (array_slice(array_unique(self::collectProcessIds($claims)), 0, 10) as $process_id)
		{
			$fetched = $this->fetchResult($process_id);
			$account_id = (string)($fetched['account_id'] ?? '');
			if (strpos($account_id, self::ACCOUNT_PREFIX) !== 0)
			{
				continue;
			}
			$state = substr($account_id, strlen(self::ACCOUNT_PREFIX));
			if (!preg_match('/^[a-f0-9]{32}$/', $state))
			{
				continue;
			}
			$results[$state] = $this->mapResult($fetched, $state, $process_id);
		}
		return ['valid' => true, 'results' => $results];
	}

	/**
	 * Map a Truora process result to the engine's verification result.
	 */
	protected function mapResult(?array $result, string $state, string $process_id): array
	{
		if ($result === null)
		{
			return ['success' => false, 'message' => lang('identity_truora_unavailable'), 'code' => '-1'];
		}

		if (!hash_equals(self::ACCOUNT_PREFIX . $state, (string)($result['account_id'] ?? '')))
		{
			return ['success' => false, 'message' => lang('identity_ticket_mismatch'), 'code' => 'account_mismatch'];
		}
		$flow_id = (string)$this->config->identity_truora_flow_id;
		if (!$this->isMock() && $flow_id !== '' && isset($result['flow_id']) && (string)$result['flow_id'] !== $flow_id)
		{
			return ['success' => false, 'message' => lang('identity_failed'), 'code' => 'flow_mismatch'];
		}

		$status = strtolower((string)($result['status'] ?? ''));
		if ($status === 'pending')
		{
			return ['success' => false, 'pending' => true, 'message' => lang('identity_truora_pending'), 'code' => 'pending'];
		}
		if ($status !== 'success')
		{
			$failure = strtolower((string)($result['failure_status'] ?? ($result['validation_failure_status'] ?? '')));
			if ($failure === 'expired')
			{
				$message = lang('identity_truora_expired');
			}
			elseif ($failure === 'canceled' || $failure === 'cancelled')
			{
				$message = lang('identity_cancelled');
			}
			elseif ($failure === 'declined')
			{
				$message = lang('identity_truora_declined');
			}
			else
			{
				$message = lang('identity_failed');
			}
			return ['success' => false, 'message' => $message, 'code' => $failure ?: $status];
		}

		$doc = self::findDocumentDetails($result);
		$country = strtoupper((string)($doc['country'] ?? ($result['country'] ?? '')));
		if (!$this->isCountryAllowed($country))
		{
			return ['success' => false, 'message' => lang('identity_truora_country_not_allowed'), 'code' => 'country'];
		}

		$document_number = preg_replace('/[^A-Za-z0-9]/', '', (string)($doc['document_number'] ?? ($result['document_number'] ?? '')));
		if ($document_number === '')
		{
			return ['success' => false, 'message' => lang('identity_failed'), 'code' => 'no_document'];
		}
		$document_type = strtolower((string)($doc['document_type'] ?? ''));

		$first = trim((string)($result['first_name'] ?? ($doc['first_name'] ?? ($doc['name'] ?? ''))));
		$last = trim((string)($result['last_name'] ?? ($doc['last_name'] ?? '')));
		$gender = strtoupper(substr((string)($doc['gender'] ?? ''), 0, 1));

		$phone = (string)(self::findFirstKey($result, ['phone_number', 'phone', 'cellphone']) ?? '');

		return [
			'success' => true,
			'provider' => 'truora',
			'tid' => $process_id,
			'name' => trim($first . ' ' . $last),
			'birthday' => self::normalizeDate((string)($doc['date_of_birth'] ?? '')),
			'sex' => in_array($gender, ['M', 'F'], true) ? $gender : '',
			'phone' => preg_replace('/[^0-9]/', '', $phone),
			'telecom' => '',
			'country' => $country,
			'document_type' => $document_type,
			'ci' => ($this->isMock() ? self::MOCK_CI_PREFIX : '') . self::makeCi($country, $document_type, $document_number),
			'di' => '',
		];
	}

	/**
	 * Stable per-person key for duplicate checks. The document number itself is
	 * never stored; only this site-keyed HMAC is.
	 */
	public static function makeCi(string $country, string $document_type, string $document_number): string
	{
		$key = (string)config('crypto.authentication_key');
		return 'TR:' . hash_hmac('sha256', 'truora|' . $country . '|' . $document_type . '|' . strtoupper($document_number), $key);
	}

	protected function isCountryAllowed(string $country): bool
	{
		$allowed = array_filter(array_map('trim', explode(',', strtoupper((string)$this->config->identity_truora_countries))));
		return !$allowed || in_array($country, $allowed, true);
	}

	protected function fetchResult(string $process_id): ?array
	{
		if (!self::isValidProcessId($process_id))
		{
			return null;
		}
		if ($this->isMock())
		{
			return $this->mockFetch($process_id);
		}
		$response = $this->callApi('GET', sprintf(self::RESULT_URL, rawurlencode($process_id)));
		if ($response['status'] !== 200 || !is_array($response['body']))
		{
			$this->logError('result', $response['status'], $response['body']);
			return null;
		}
		return $response['body'];
	}

	protected function callApi(string $method, string $url, array $form = []): array
	{
		$headers = [
			'Truora-API-Key' => (string)$this->config->identity_truora_api_key,
			'Accept' => 'application/json',
		];
		if ($method === 'POST')
		{
			$headers['Content-Type'] = 'application/x-www-form-urlencoded';
		}
		$response = HTTP::request($url, $method, $method === 'POST' ? $form : null, $headers, [], ['timeout' => self::TIMEOUT]);
		$status = (int)$response->getStatusCode();
		$body = json_decode((string)$response->getBody(), true);
		return ['status' => $status, 'body' => is_array($body) ? $body : []];
	}

	/**
	 * Log only the status and Truora's error code/message — never the API key,
	 * token or personal data.
	 */
	protected function logError(string $stage, int $status, $body): void
	{
		$code = is_array($body) ? (string)($body['code'] ?? ($body['http_code'] ?? '')) : '';
		$message = is_array($body) ? (string)($body['message'] ?? '') : '';
		error_log(sprintf('[identity/truora] %s failed: HTTP %d %s %s', $stage, $status, $code, mb_substr($message, 0, 120)));
	}

	public static function isValidProcessId(string $process_id): bool
	{
		return (bool)preg_match('/^[A-Za-z0-9_-]{6,64}$/', $process_id);
	}

	/**
	 * HS256 JWT verification. Any other algorithm is rejected.
	 */
	public static function decodeJwt(string $jwt, string $secret): ?array
	{
		$parts = explode('.', $jwt);
		if (count($parts) !== 3)
		{
			return null;
		}
		$header = json_decode(self::base64UrlDecode($parts[0]), true);
		if (!is_array($header) || strtoupper((string)($header['alg'] ?? '')) !== 'HS256')
		{
			return null;
		}
		$expected = hash_hmac('sha256', $parts[0] . '.' . $parts[1], $secret, true);
		if (!hash_equals($expected, self::base64UrlDecode($parts[2])))
		{
			return null;
		}
		$claims = json_decode(self::base64UrlDecode($parts[1]), true);
		if (!is_array($claims))
		{
			return null;
		}
		if (isset($claims['exp']) && is_numeric($claims['exp']) && (int)$claims['exp'] < time() - 60)
		{
			return null;
		}
		return $claims;
	}

	protected static function base64UrlDecode(string $value): string
	{
		$value = strtr($value, '-_', '+/');
		$pad = strlen($value) % 4;
		if ($pad)
		{
			$value .= str_repeat('=', 4 - $pad);
		}
		return (string)base64_decode($value, true);
	}

	protected static function collectProcessIds(array $data, int $depth = 0): array
	{
		$ids = [];
		if ($depth > 6)
		{
			return $ids;
		}
		foreach ($data as $key => $value)
		{
			if (is_array($value))
			{
				$ids = array_merge($ids, self::collectProcessIds($value, $depth + 1));
			}
			elseif (in_array((string)$key, ['identity_process_id', 'process_id'], true) && is_string($value) && self::isValidProcessId($value))
			{
				$ids[] = $value;
			}
		}
		return $ids;
	}

	protected static function findDocumentDetails(array $result): array
	{
		foreach ((array)($result['validations'] ?? []) as $validation)
		{
			if (is_array($validation) && isset($validation['details']['document_details']) && is_array($validation['details']['document_details']))
			{
				return $validation['details']['document_details'];
			}
		}
		return is_array($result['document_details'] ?? null) ? $result['document_details'] : [];
	}

	protected static function findFirstKey(array $data, array $keys, int $depth = 0)
	{
		if ($depth > 6)
		{
			return null;
		}
		foreach ($keys as $key)
		{
			if (isset($data[$key]) && is_scalar($data[$key]) && (string)$data[$key] !== '')
			{
				return $data[$key];
			}
		}
		foreach ($data as $value)
		{
			if (is_array($value))
			{
				$found = self::findFirstKey($value, $keys, $depth + 1);
				if ($found !== null)
				{
					return $found;
				}
			}
		}
		return null;
	}

	protected static function normalizeDate(string $value): string
	{
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m))
		{
			return $m[1] . $m[2] . $m[3];
		}
		$digits = preg_replace('/[^0-9]/', '', $value);
		return strlen($digits) === 8 ? $digits : '';
	}

	protected static function siteIndexUrl(): string
	{
		$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		return $protocol . '://' . \Zittme\Framework\URL::getCurrentDomain(true) . \RX_BASEURL . 'index.php';
	}

	/* ---------------------------------------------------------------------
	 * Mock mode: local simulator of the hosted flow and the result API.
	 * ------------------------------------------------------------------- */

	protected static function mockDir(): string
	{
		return \RX_BASEDIR . 'files/cache/member_identity/truora_mock/';
	}

	/**
	 * Create a simulated finished process and return the callback URL.
	 */
	public function mockCreate(string $state, array $input): string
	{
		if (!self::isMockAllowed())
		{
			throw new \Zittme\Framework\Exceptions\FeatureDisabled;
		}
		$now = time();
		foreach ((array)glob(self::mockDir() . '*.json') as $file)
		{
			if (@filemtime($file) < $now - self::MOCK_TTL)
			{
				@unlink($file);
			}
		}

		$outcome = (string)($input['outcome'] ?? 'success');
		if (!in_array($outcome, ['success', 'declined', 'expired', 'canceled', 'pending'], true))
		{
			$outcome = 'success';
		}
		$process_id = 'IDPmock' . \Zittme\Framework\Security::getRandom(16, 'hex');
		$country = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)($input['country'] ?? 'MX'))) ?: 'MX';
		$birth = preg_replace('/[^0-9-]/', '', (string)($input['date_of_birth'] ?? '1990-01-01'));

		$data = [
			'process_id' => $process_id,
			'account_id' => self::ACCOUNT_PREFIX . $state,
			'flow_id' => 'IPFmock',
			'country' => $country,
			'status' => $outcome === 'success' || $outcome === 'pending' ? 'success' : 'failure',
			'failure_status' => in_array($outcome, ['declined', 'expired', 'canceled'], true) ? $outcome : '',
			'first_name' => mb_substr(trim((string)($input['first_name'] ?? '')), 0, 40),
			'last_name' => mb_substr(trim((string)($input['last_name'] ?? '')), 0, 40),
			'resolve_at' => $outcome === 'pending' ? $now + 20 : 0,
			'validations' => [[
				'type' => 'document-validation',
				'validation_status' => 'success',
				'details' => ['document_details' => [
					'country' => $country,
					'document_type' => 'national-id',
					'document_number' => mb_substr(preg_replace('/[^A-Za-z0-9]/', '', (string)($input['document_number'] ?? '')), 0, 30),
					'date_of_birth' => $birth . 'T00:00:00Z',
					'gender' => strtoupper(substr((string)($input['gender'] ?? ''), 0, 1)),
				]],
			], [
				'type' => 'phone-verification',
				'validation_status' => 'success',
				'details' => ['phone_number' => preg_replace('/[^0-9+]/', '', (string)($input['phone'] ?? ''))],
			]],
		];
		\FileHandler::writeFile(self::mockDir() . $process_id . '.json', json_encode($data, JSON_UNESCAPED_UNICODE));

		return self::buildActionUrl('procMemberIdentityCallback') . '&IDENTITY_STATE=' . $state . '&process_id=' . $process_id;
	}

	protected function mockFetch(string $process_id): ?array
	{
		$raw = \FileHandler::readFile(self::mockDir() . preg_replace('/[^A-Za-z0-9_-]/', '', $process_id) . '.json');
		$data = $raw ? json_decode($raw, true) : null;
		if (!is_array($data))
		{
			return null;
		}
		if (!empty($data['resolve_at']) && time() < (int)$data['resolve_at'])
		{
			$data['status'] = 'pending';
		}
		unset($data['resolve_at']);
		return $data;
	}
}
