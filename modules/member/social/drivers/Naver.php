<?php

namespace Zittme\Modules\Member\Social\Drivers;

use Context;

class Naver extends Base
{
	const NAVER_OAUTH2_URI = 'https://nid.naver.com/oauth2.0/';

	public function createAuthUrl(): string
	{
		$params = [
			'response_type' => 'code',
			'client_id'     => $this->config->naver_client_id,
			'redirect_uri'  => self::buildCallbackUrl('naver'),
			'state'         => $this->generateState(),
		];

		return self::NAVER_OAUTH2_URI . 'authorize?' . http_build_query($params, '', '&');
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
			'client_id'     => $this->config->naver_client_id,
			'client_secret' => $this->config->naver_client_secret,
			'code'          => $code,
			'state'         => $state,
		];

		$response = $this->requestAPI(self::NAVER_OAUTH2_URI . 'token', $params);
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

		$response = $this->requestAPI('https://openapi.naver.com/v1/nid/me', [], $headers);
		if (!$response || !isset($response['response']))
		{
			return null;
		}

		$profile = $response['response'];
		$userInfo = new \stdClass();
		$userInfo->sns_id = $profile['id'];
		$userInfo->sns_type = 'naver';
		$userInfo->sns_name = $profile['nickname'] ?: $profile['name'];
		$userInfo->sns_email = $profile['email'] ?? '';
		$userInfo->sns_profile_image = $profile['profile_image'] ?? '';

		return $userInfo;
	}
}
