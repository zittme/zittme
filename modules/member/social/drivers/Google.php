<?php

namespace Zittme\Modules\Member\Social\Drivers;

use Context;

class Google extends Base
{
	const GOOGLE_OAUTH2_URI = 'https://accounts.google.com/o/oauth2/';

	protected $request_content_type = null;

	public function createAuthUrl(): string
	{
		$scope = [
			'https://www.googleapis.com/auth/userinfo.email',
			'https://www.googleapis.com/auth/userinfo.profile',
		];

		$params = [
			'scope'         => implode(' ', $scope),
			'access_type'   => 'offline',
			'response_type' => 'code',
			'client_id'     => $this->config->google_client_id,
			'redirect_uri'  => self::buildCallbackUrl('google'),
			'state'         => $this->generateState(),
		];

		return self::GOOGLE_OAUTH2_URI . 'auth?' . http_build_query($params, '', '&');
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
			'client_id'     => $this->config->google_client_id,
			'client_secret' => $this->config->google_client_secret,
			'redirect_uri'  => self::buildCallbackUrl('google'),
			'code'          => $code,
		];

		$response = $this->requestAPI(self::GOOGLE_OAUTH2_URI . 'token', $params);
		if (!$response || !isset($response['access_token']))
		{
			return new \BaseObject(-1, 'msg_errer_api_connect');
		}

		return $response;
	}

	public function getUserInfo($access_token = null)
	{
		$url = 'https://www.googleapis.com/oauth2/v3/userinfo?access_token=' . $access_token;
		$response = $this->requestAPI($url);

		if (!$response || empty($response) || !isset($response['sub']))
		{
			return null;
		}

		$userInfo = new \stdClass();
		$userInfo->sns_id = $response['sub'];
		$userInfo->sns_type = 'google';
		$userInfo->sns_name = $response['name'] ?? '';
		$userInfo->sns_email = $response['email'] ?? '';
		$userInfo->sns_profile_image = $response['picture'] ?? '';
		// Email-verified flag — safety gate for auto-linking to an existing member.
		$ev = $response['email_verified'] ?? false;
		$userInfo->sns_email_verified = ($ev === true || $ev === 'true' || $ev === 1 || $ev === '1');

		return $userInfo;
	}
}
