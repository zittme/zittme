<?php

namespace Zittme\Modules\Member\Social\Drivers;

use Context;

class Kakao extends Base
{
	const KAKAO_OAUTH2_URI = 'https://kauth.kakao.com/oauth/';
	const KAKAO_API_URI = 'https://kapi.kakao.com/';

	public function createAuthUrl(): string
	{
		$params = [
			'response_type' => 'code',
			'client_id'     => $this->config->kakao_client_id,
			'redirect_uri'  => self::buildCallbackUrl('kakao'),
			'state'         => $this->generateState(),
		];

		return self::KAKAO_OAUTH2_URI . 'authorize?' . http_build_query($params, '', '&');
	}

	public function authenticate()
	{
		$code = Context::get('code');
		$state = Context::get('state');

		if (!$code || !$this->validateState($state))
		{
			return new \BaseObject(-1, 'msg_invalid_request');
		}

		$params = [
			'grant_type'    => 'authorization_code',
			'client_id'     => $this->config->kakao_client_id,
			'client_secret' => $this->config->kakao_client_secret,
			'redirect_uri'  => self::buildCallbackUrl('kakao'),
			'code'          => $code,
		];

		$response = $this->requestAPI(self::KAKAO_OAUTH2_URI . 'token', $params);
		if (!$response || !isset($response['access_token']))
		{
			return new \BaseObject(-1, 'msg_errer_api_connect');
		}

		return $response;
	}

	public function getUserInfo($access_token = null)
	{
		$headers = [
			'Authorization' => 'Bearer ' . $access_token,
		];

		$response = $this->requestAPI(self::KAKAO_API_URI . 'v2/user/me', [], $headers);
		if (!$response || !isset($response['id']))
		{
			return null;
		}

		$userInfo = new \stdClass();
		$userInfo->sns_id = $response['id'];
		$userInfo->sns_type = 'kakao';
		$userInfo->sns_name = $response['properties']['nickname'] ?? '';
		$userInfo->sns_email = $response['kakao_account']['email'] ?? '';
		$userInfo->sns_profile_image = $response['properties']['profile_image'] ?? '';

		return $userInfo;
	}
}
