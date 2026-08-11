<?php

namespace Zittme\Modules\Autoinstall\Models;

use Zittme\Framework\Cache;
use Zittme\Framework\HTTP;

/**
 * zitt.me 전문가 조회 — 스토어와 같은 방식으로 관리자 화면 안에서 훑어본다.
 *
 * 읽기 전용이다. 목록·프로필·포트폴리오만 가져오고, 의뢰 등록이나 전문가 등록처럼
 * 입력·첨부·권한이 얽힌 동작은 zitt.me 본장에서 처리한다(계정을 연결해 두었으면
 * 자동 로그인 상태로 열린다).
 */
class ExpertHub
{
	public const LIST_URL = 'https://api.zitt.me/experts/index.json';
	public const PROFILE_URL = 'https://api.zitt.me/experts/%d.json';
	public const PROJECTS_URL = 'https://api.zitt.me/projects/index.json';
	/** 의뢰 등록은 토큰 인증이라 관문이 아니라 본 사이트로 바로 보낸다 */
	public const CREATE_PROJECT_URL = 'https://zitt.me/index.php?module=expert&act=procExpertApiCreateProject';
	public const PORTFOLIOS_URL = 'https://api.zitt.me/portfolios/index.json';
	public const PORTFOLIO_URL = 'https://api.zitt.me/portfolios/%d.json';

	/** 응답 캐시 수명(초) — 목록은 자주 바뀌지 않는다 */
	public const CACHE_TTL = 600;

	/**
	 * 전문가 목록.
	 *
	 * @return ?object {experts, page, total_page, total_count, fields}
	 */
	public static function getList(int $page = 1, string $field = '', string $keyword = ''): ?object
	{
		$query = ['page' => max(1, $page)];
		if ($field !== '')
		{
			$query['field'] = $field;
		}
		if ($keyword !== '')
		{
			$query['s'] = $keyword;
		}

		$cache_key = 'autoinstall:experts:' . md5(json_encode($query));
		$cached = Cache::get($cache_key);
		if (is_object($cached))
		{
			return $cached;
		}

		$response = self::fetch(self::LIST_URL . '?' . http_build_query($query));
		if ($response)
		{
			Cache::set($cache_key, $response, self::CACHE_TTL, true);
		}
		return $response;
	}

	/**
	 * 포트폴리오(작업물) 목록 — 전문가 화면의 기본 데이터.
	 *
	 * @return ?object {portfolios, page, total_page, total_count, fields}
	 */
	public static function getPortfolios(int $page = 1, string $field = ''): ?object
	{
		$query = ['page' => max(1, $page)];
		if ($field !== '')
		{
			$query['field'] = $field;
		}

		$cache_key = 'autoinstall:portfolios:' . md5(json_encode($query));
		$cached = Cache::get($cache_key);
		if (is_object($cached))
		{
			return $cached;
		}

		$response = self::fetch(self::PORTFOLIOS_URL . '?' . http_build_query($query));
		if ($response)
		{
			Cache::set($cache_key, $response, self::CACHE_TTL, true);
		}
		return $response;
	}

	/**
	 * 작업 의뢰 목록.
	 *
	 * @return ?object {projects, page, total_page, total_count, fields}
	 */
	public static function getProjects(int $page = 1, string $field = '', bool $include_closed = false): ?object
	{
		$query = ['page' => max(1, $page)];
		if ($field !== '')
		{
			$query['field'] = $field;
		}
		if ($include_closed)
		{
			$query['status'] = 'all';
		}

		$cache_key = 'autoinstall:projects:' . md5(json_encode($query));
		$cached = Cache::get($cache_key);
		if (is_object($cached))
		{
			return $cached;
		}

		$response = self::fetch(self::PROJECTS_URL . '?' . http_build_query($query));
		if ($response)
		{
			// 의뢰는 모집 상황이 빨리 바뀌므로 짧게 잡는다
			Cache::set($cache_key, $response, 180, true);
		}
		return $response;
	}

	/**
	 * 포트폴리오 상세 (작성한 전문가 정보 포함).
	 */
	public static function getPortfolio(int $portfolio_srl): ?object
	{
		if ($portfolio_srl <= 0)
		{
			return null;
		}

		$cache_key = 'autoinstall:portfolio:' . $portfolio_srl;
		$cached = Cache::get($cache_key);
		if (is_object($cached))
		{
			return $cached;
		}

		$response = self::fetch(sprintf(self::PORTFOLIO_URL, $portfolio_srl));
		if ($response)
		{
			Cache::set($cache_key, $response, self::CACHE_TTL, true);
		}
		return $response;
	}

	/**
	 * 전문가 프로필 + 포트폴리오.
	 */
	public static function getProfile(int $profile_srl): ?object
	{
		if ($profile_srl <= 0)
		{
			return null;
		}

		$cache_key = 'autoinstall:expert_profile:' . $profile_srl;
		$cached = Cache::get($cache_key);
		if (is_object($cached))
		{
			return $cached;
		}

		$response = self::fetch(sprintf(self::PROFILE_URL, $profile_srl));
		if ($response)
		{
			Cache::set($cache_key, $response, self::CACHE_TTL, true);
		}
		return $response;
	}

	/**
	 * 작업 의뢰 등록 — 연결된 계정으로 zitt.me 에 올린다.
	 *
	 * @param array $fields 폼에서 받은 값들
	 * @return array{ok:bool, url?:string, error?:string}
	 */
	public static function createProject(array $fields): array
	{
		$account = Account::get();
		if (!$account)
		{
			return ['ok' => false, 'error' => 'not_connected'];
		}

		$fields['token'] = $account->token;

		try
		{
			$request = HTTP::post(self::CREATE_PROJECT_URL, $fields, [], [], ['timeout' => 15]);
			$response = json_decode((string)$request->getBody()->getContents());
			if ($request->getStatusCode() !== 200 || empty($response->project_srl))
			{
				return ['ok' => false, 'error' => (string)($response->error ?? 'request_failed')];
			}

			// 방금 올린 의뢰가 목록에 바로 보이도록 캐시를 비운다
			// (지우지 않으면 캐시가 만료될 때까지 '모집중' 목록에서 빠져 보인다)
			Cache::clearGroup('autoinstall');

			return ['ok' => true, 'url' => (string)($response->url ?? '')];
		}
		catch (\Throwable $e)
		{
			return ['ok' => false, 'error' => 'request_failed'];
		}
	}

	/** 첨부 전송 대상 */
	public const UPLOAD_URL = 'https://zitt.me/index.php?module=expert&act=procExpertApiUploadFile';

	/**
	 * 에디터로 이 사이트에 올라간 첨부를 zitt.me 로 옮기고, 본문의 주소를 바꿔 준다.
	 *
	 * 의뢰는 zitt.me 에 올라가므로 첨부가 이 사이트에 남아 있으면, 사이트를 닫거나
	 * 내부망이면 상대가 파일을 볼 수 없다. 그래서 등록 시점에 파일을 함께 옮긴다.
	 *
	 * @param string $content 에디터 본문
	 * @param int $editor_target_srl 첨부가 귀속된 임시 srl
	 * @return string 주소를 바꾼 본문
	 */
	public static function transferAttachments(string $content, array $files): string
	{
		$account = Account::get();
		if (!$account || !$files)
		{
			return $content;
		}

		$images = [];
		$links = [];
		foreach ($files as $file)
		{
			if (!is_array($file) || ($file['error'] ?? \UPLOAD_ERR_NO_FILE) !== \UPLOAD_ERR_OK)
			{
				continue;
			}

			$url = self::uploadFile($file['tmp_name'], (string)$file['name'], $account->token);
			if ($url === '')
			{
				continue;
			}

			$name = escape((string)$file['name']);
			if (preg_match('/\.(jpe?g|png|gif|webp)$/i', (string)$file['name']))
			{
				$images[] = '<p><img src="' . escape($url) . '" alt="' . $name . '" style="max-width:100%" /></p>';
			}
			else
			{
				$links[] = '<li><a href="' . escape($url) . '" target="_blank" rel="noopener">' . $name . '</a></li>';
			}
		}

		// 이미지는 본문 아래에 그대로 보이고, 그 밖의 파일은 목록으로 붙인다
		if ($images)
		{
			$content .= "\n" . implode("\n", $images);
		}
		if ($links)
		{
			$content .= "\n<ul>" . implode('', $links) . '</ul>';
		}

		return $content;
	}

	/**
	 * 파일 하나를 zitt.me 로 올린다. 성공하면 그쪽 주소, 실패하면 빈 문자열.
	 *
	 * multipart 전송이라 curl 을 직접 쓴다.
	 */
	protected static function uploadFile(string $path, string $name, string $token): string
	{
		if (!function_exists('curl_init'))
		{
			return '';
		}

		$ch = curl_init(self::UPLOAD_URL);
		curl_setopt_array($ch, [
			\CURLOPT_POST => true,
			\CURLOPT_RETURNTRANSFER => true,
			\CURLOPT_TIMEOUT => 60,
			\CURLOPT_POSTFIELDS => [
				'token' => $token,
				'file' => new \CURLFile($path, mime_content_type($path) ?: 'application/octet-stream', $name),
			],
		]);
		$body = curl_exec($ch);
		$status = (int)curl_getinfo($ch, \CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($status !== 200 || !$body)
		{
			return '';
		}
		$response = json_decode((string)$body);
		return !empty($response->url) ? (string)$response->url : '';
	}

	/**
	 * 원격 JSON 한 번 가져오기. 실패하면 null (화면은 빈 상태로 안내한다).
	 */
	protected static function fetch(string $url): ?object
	{
		try
		{
			$request = HTTP::get($url, null, [], [], ['timeout' => 10]);
			if ($request->getStatusCode() !== 200)
			{
				return null;
			}
			$response = json_decode((string)$request->getBody()->getContents());
			return is_object($response) ? $response : null;
		}
		catch (\Throwable $e)
		{
			return null;
		}
	}
}
