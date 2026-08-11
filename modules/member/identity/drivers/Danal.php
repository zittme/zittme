<?php

namespace Zittme\Modules\Member\Identity\Drivers;

use FileHandler;

/**
 * 다날(Danal) UAS 휴대폰 본인인증 driver.
 *
 * Flow (다날 본인인증 서비스 연동 가이드 1.1.6 / PHP 예제 1.2.x):
 *  1. ready()  : TXTYPE=ITEMSEND to uas.teledit.com → on RETURNCODE=0000 the
 *                response fields are auto-submitted (hidden form) to the
 *                wauth.teledit.com Start.php page where the user authenticates.
 *  2. Danal POSTs TID (+ our bypass values) to our TARGETURL callback.
 *  3. confirm(): TXTYPE=CONFIRM with the TID → verified person data
 *                (NAME / DOB / SEX / CI / DI / PHONE). IDENOPTION=1 requests
 *                DOB(8)+SEX as separate fields.
 *
 * Only RETURNCODE === '0000' is success; every hidden value from the browser
 * is untrusted until confirmed server-side (다날 보안 가이드).
 */
class Danal extends Base
{
	const SERVICE_URL = 'https://uas.teledit.com/uas/';
	const START_URL = 'https://wauth.teledit.com/Danal/WebAuth/Web/Start.php';
	const CONNECT_TIMEOUT = 30;

	public function isConfigured(): bool
	{
		return $this->config->identity_danal_cpid !== '' && $this->config->identity_danal_cppwd !== '';
	}

	/**
	 * TXTYPE=ITEMSEND — prepare the transaction and build the Start.php form.
	 */
	public function ready(string $target_url, string $back_url, array $bypass = []): array
	{
		$request = [
			// fixed values (다날 지정 — 변경 금지)
			'TXTYPE' => 'ITEMSEND',
			'SERVICE' => 'UAS',
			'AUTHTYPE' => '36',
			// credentials + callback
			'CPID' => $this->config->identity_danal_cpid,
			'CPPWD' => $this->config->identity_danal_cppwd,
			'TARGETURL' => $target_url,
			'CPTITLE' => \Zittme\Framework\URL::getCurrentDomain(true),
			'ORDERID' => 'ZM' . date('YmdHis') . mt_rand(1000, 9999),
		];

		$response = $this->callTrans($request);
		if (($response['RETURNCODE'] ?? '') !== '0000')
		{
			return [
				'success' => false,
				'message' => urldecode($response['RETURNMSG'] ?? 'NETWORK ERROR'),
				'code' => $response['RETURNCODE'] ?? '-1',
			];
		}

		// form fields = every response field except RETURNCODE/RETURNMSG,
		// plus bypass values Danal will POST back to our callback untouched.
		$params = [];
		foreach ($response as $key => $value)
		{
			if ($key === 'RETURNCODE' || $key === 'RETURNMSG' || trim($key) === '')
			{
				continue;
			}
			$params[$key] = $value;
		}
		$params['BackURL'] = $back_url;
		$params['IsCharSet'] = $this->config->identity_danal_charset ?: 'UTF-8';
		foreach ($bypass as $key => $value)
		{
			$params[$key] = $value;
		}

		return [
			'success' => true,
			'start_url' => self::START_URL,
			'params' => $params,
		];
	}

	/**
	 * TXTYPE=CONFIRM — verify the TID with the Danal server.
	 */
	public function confirm(array $post): array
	{
		$tid = trim((string)($post['TID'] ?? ''));
		if ($tid === '')
		{
			return ['success' => false, 'message' => 'TID missing', 'code' => '-1'];
		}

		$request = [
			'TXTYPE' => 'CONFIRM',
			'TID' => $tid,
			// 0 고정. 1 로 보내면 다날이 "잘못된 값이 입력된 필드가 존재합니다"로
			// 거부한다(CP 계약에 포함되지 않은 옵션). 휴대폰번호는 CONFIRM 응답에
			// 실려 오는 필드를 그대로 읽는다 — 아래 phone 매핑 참고.
			'CONFIRMOPTION' => '0',
			// 1 = DOB(YYYYMMDD) and SEX returned as separate fields
			'IDENOPTION' => '1',
		];

		$response = $this->callTrans($request);
		if (($response['RETURNCODE'] ?? '') !== '0000')
		{
			return [
				'success' => false,
				'message' => urldecode($response['RETURNMSG'] ?? 'CONFIRM FAILED'),
				'code' => $response['RETURNCODE'] ?? '-1',
			];
		}

		// 어떤 필드가 오는지 확인용 — 개인정보인 값은 남기지 않고 키 이름만 기록한다.
		// (전화번호 필드명을 확정한 뒤 이 줄은 제거할 것)
		error_log('[identity/danal] CONFIRM fields: ' . implode(',', array_keys($response)));

		// SEX: 1 = male, 0 = female
		$sex_raw = (string)($response['SEX'] ?? '');
		$sex = $sex_raw === '1' ? 'M' : ($sex_raw === '0' ? 'F' : '');

		return [
			'success' => true,
			'provider' => 'danal',
			'tid' => $tid,
			'name' => $this->decodeField($response['NAME'] ?? ''),
			'birthday' => preg_replace('/[^0-9]/', '', (string)($response['DOB'] ?? '')),
			'sex' => $sex,
			// 응답 필드명이 계약/버전에 따라 다를 수 있어 알려진 이름을 모두 본다
			'phone' => preg_replace('/[^0-9]/', '', $this->decodeField(
				$response['PHONE'] ?? ($response['PHONENO'] ?? ($response['MOBILE'] ?? ($response['PHONENUMBER'] ?? '')))
			)),
			'telecom' => $this->decodeField($response['CARRIER'] ?? ($response['TELECOM'] ?? '')),
			'ci' => $this->decodeField($response['CI'] ?? ''),
			'di' => $this->decodeField($response['DI'] ?? ''),
			'raw' => $response,
		];
	}

	/**
	 * key=value&… POST to the Danal UAS server (와이어 포맷은 다날 예제의
	 * CallTrans와 동일). Response is a key=value&… string.
	 */
	protected function callTrans(array $data): array
	{
		$charset = $this->config->identity_danal_charset ?: 'UTF-8';
		$headers = ['Content-Type' => 'application/x-www-form-urlencoded;charset=' . $charset];

		$response = FileHandler::getRemoteResource(
			self::SERVICE_URL, null, self::CONNECT_TIMEOUT, 'POST',
			'application/x-www-form-urlencoded;charset=' . $charset,
			$headers, [], $data
		);

		if (!$response)
		{
			return ['RETURNCODE' => '-1', 'RETURNMSG' => 'NETWORK ERROR'];
		}
		return $this->parseResponse((string)$response);
	}

	/**
	 * "A=1&B=2" → ['A'=>'1','B'=>'2'] — values kept raw (다날 예제와 동일하게
	 * 전달용 필드는 그대로 통과시키고, 표시용 필드만 decodeField로 복호).
	 */
	protected function parseResponse(string $str): array
	{
		$data = [];
		foreach (explode('&', $str) as $line)
		{
			$pair = explode('=', $line, 2);
			if (count($pair) === 2)
			{
				$data[$pair[0]] = $pair[1];
			}
		}
		return $data;
	}

	/**
	 * Decode a display field: urldecode + EUC-KR→UTF-8 when the CP charset is
	 * EUC-KR (다날 응답은 요청 charset을 따른다).
	 */
	protected function decodeField(string $value): string
	{
		$value = urldecode($value);
		$charset = strtoupper($this->config->identity_danal_charset ?: 'UTF-8');
		if ($charset === 'EUC-KR' && function_exists('iconv'))
		{
			$converted = @iconv('EUC-KR', 'UTF-8//IGNORE', $value);
			if ($converted !== false)
			{
				$value = $converted;
			}
		}
		return $value;
	}
}
