<?php

namespace Zittme\Framework;

/**
 * 발송 메일의 공통 레이아웃.
 *
 * 모듈은 본문(안내 문구·정보 목록·버튼)만 만들고, 바깥 껍데기(헤더·카드·푸터)는
 * 여기서 한 벌로 씌운다. 메일 클라이언트는 <style> 블록과 최신 CSS를 거의 지원하지
 * 않으므로 table + inline style 로만 조립한다.
 *
 * 엔진 배포본이므로 사이트 이름·주소·브랜드 색은 설정에서 읽는다 — 특정 사이트를
 * 하드코딩하지 않는다.
 */
class MailTemplate
{
	/** 기본 포인트 색 (관리자 설정이 없을 때) */
	const DEFAULT_COLOR = '#2677e3';

	/**
	 * 본문을 공통 레이아웃으로 감싼다.
	 *
	 * @param string $title    카드 상단 제목
	 * @param string $body     본문 HTML (모듈이 만든 조각)
	 * @param array  $options  button_url / button_label / greeting / footer
	 */
	public static function wrap(string $title, string $body, array $options = []): string
	{
		$color = self::getPointColor();
		$site_title = self::getSiteTitle();
		$site_url = self::getSiteUrl();

		$safe_title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
		$safe_site_title = htmlspecialchars($site_title, ENT_QUOTES, 'UTF-8');
		$safe_site_url = htmlspecialchars($site_url, ENT_QUOTES, 'UTF-8');

		$greeting = '';
		if (!empty($options['greeting']))
		{
			$greeting = '<p style="margin:0 0 16px;font-size:14px;color:#6b7684">'
				. htmlspecialchars($options['greeting'], ENT_QUOTES, 'UTF-8') . '</p>';
		}

		$button = '';
		if (!empty($options['button_url']) && !empty($options['button_label']))
		{
			$button = self::button($options['button_url'], $options['button_label'], $color);
		}

		$footer = !empty($options['footer'])
			? htmlspecialchars($options['footer'], ENT_QUOTES, 'UTF-8')
			: sprintf(lang('mail_footer_notice'), $safe_site_title);

		return '<div style="margin:0;padding:36px 16px;background:#f2f5f9;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',\'Malgun Gothic\',\'Apple SD Gothic Neo\',sans-serif">'
			. '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:560px;margin:0 auto">'
			// 헤더 — 사이트 이름
			. '<tr><td style="padding:0 6px 16px">'
			. '<a href="' . $safe_site_url . '" target="_blank" style="font-size:20px;font-weight:800;color:' . $color . ';text-decoration:none;letter-spacing:-0.5px">' . $safe_site_title . '</a>'
			. '</td></tr>'
			// 본문 카드
			. '<tr><td style="background:#ffffff;border:1px solid #e5e8eb;border-radius:18px;padding:34px 32px">'
			. '<h1 style="margin:0 0 18px;font-size:19px;line-height:1.45;font-weight:800;color:#1c2330;letter-spacing:-0.3px">' . $safe_title . '</h1>'
			. $greeting
			. '<div style="font-size:15px;line-height:1.75;color:#333d4b">' . $body . '</div>'
			. $button
			. '</td></tr>'
			// 푸터
			. '<tr><td style="padding:20px 6px 0;font-size:12px;line-height:1.7;color:#8b95a1">' . $footer . '</td></tr>'
			. '</table></div>';
	}

	/**
	 * 라벨 : 값 형태의 정보 목록. 메일에서 <ul> 은 클라이언트마다 들여쓰기가
	 * 제각각이라 table 로 그린다.
	 */
	public static function infoTable(array $rows): string
	{
		if (!$rows)
		{
			return '';
		}

		$html = '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" '
			. 'style="margin:20px 0;background:#f7f9fc;border:1px solid #eaeef3;border-radius:12px">';
		foreach ($rows as $label => $value)
		{
			$html .= '<tr>'
				. '<td style="padding:11px 18px;font-size:13px;color:#8b95a1;white-space:nowrap;vertical-align:top">'
				. htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') . '</td>'
				// 값은 링크·강조를 담을 수 있어 그대로 둔다 — 호출부가 이스케이프한다
				. '<td style="padding:11px 18px 11px 0;font-size:14px;color:#333d4b;font-weight:600;word-break:break-all">'
				. $value . '</td>'
				. '</tr>';
		}
		return $html . '</table>';
	}

	/**
	 * 강조 상자 — 임시 비밀번호처럼 눈에 띄어야 하는 값.
	 */
	public static function highlight(string $label, string $value): string
	{
		$color = self::getPointColor();
		return '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:20px 0">'
			. '<tr><td style="padding:18px 20px;background:rgba(38,119,227,.06);border:1px solid ' . $color . ';border-radius:12px;text-align:center">'
			. '<div style="font-size:12px;color:#8b95a1;margin-bottom:6px">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>'
			. '<div style="font-size:20px;font-weight:800;color:' . $color . ';letter-spacing:0.5px">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</div>'
			. '</td></tr></table>';
	}

	/**
	 * 행동 버튼. 버튼을 못 누르는 환경을 위해 주소 원문도 함께 남긴다.
	 */
	public static function button(string $url, string $label, string $color = ''): string
	{
		$color = $color ?: self::getPointColor();
		$safe_url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
		$safe_label = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

		return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:26px 0 8px">'
			. '<tr><td style="border-radius:999px;background:' . $color . '">'
			. '<a href="' . $safe_url . '" target="_blank" style="display:inline-block;padding:13px 30px;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:999px">' . $safe_label . '</a>'
			. '</td></tr></table>'
			. '<p style="margin:0;font-size:12px;line-height:1.6;color:#8b95a1;word-break:break-all">'
			. lang('mail_button_fallback') . '<br /><a href="' . $safe_url . '" target="_blank" style="color:#8b95a1">' . $safe_url . '</a></p>';
	}

	/**
	 * 관리자가 지정한 포인트 색 (없으면 기본값).
	 */
	public static function getPointColor(): string
	{
		$color = (string)config('mail.point_color');
		return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : self::DEFAULT_COLOR;
	}

	protected static function getSiteTitle(): string
	{
		$title = (string)config('mail.default_name');
		if ($title === '')
		{
			$site_info = \Context::get('site_module_info');
			$title = (string)($site_info->browser_title ?? '');
		}
		return $title !== '' ? $title : \Context::getSiteTitle();
	}

	protected static function getSiteUrl(): string
	{
		return getFullSiteUrl();
	}
}
