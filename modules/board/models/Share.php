<?php

namespace Zittme\Modules\Board\Models;

use Context;
use ModuleModel;

/**
 * Share buttons for board documents.
 *
 * Renders a skin-independent block of share buttons (HTML + inline SVG).
 * Skins print it with {$board_share|noescape} or {!! $board_share !!},
 * and boards can also insert it automatically below the document body.
 */
class Share
{
	public const CHANNELS = ['native', 'copy', 'kakao', 'facebook', 'x', 'naver', 'band', 'line', 'threads', 'email'];
	public const DEFAULT_CHANNELS = ['native', 'copy', 'kakao', 'facebook', 'x'];
	public const KAKAO_SDK = 'https://t1.kakaocdn.net/kakao_js_sdk/2.7.2/kakao.min.js';
	public const KAKAO_SDK_INTEGRITY = 'sha384-TiCUE00h649CAMonG018J2ujOgDKW/kVWlChEuu4jK2vxfAAD0eZxzCKakxg55G4';

	protected static $_assets_loaded = false;

	/**
	 * Get normalized share settings from module info.
	 *
	 * @param object $module_info
	 * @return object
	 */
	public static function getConfig($module_info): object
	{
		$config = new \stdClass;
		$config->use = ($module_info->share_use ?? 'N') === 'Y';
		$config->auto = ($module_info->share_auto ?? 'Y') !== 'N';
		$config->kakao_key = self::sanitizeKakaoKey($module_info->share_kakao_key ?? '');
		$config->channels = self::sanitizeChannels($module_info->share_channels ?? null);
		return $config;
	}

	/**
	 * Normalize a channel list.
	 *
	 * @param mixed $channels
	 * @return array
	 */
	public static function sanitizeChannels($channels): array
	{
		if ($channels === null || $channels === '')
		{
			return self::DEFAULT_CHANNELS;
		}
		if (!is_array($channels))
		{
			$channels = explode(',', (string)$channels);
		}
		$channels = array_map('trim', $channels);
		return array_values(array_intersect(self::CHANNELS, $channels));
	}

	/**
	 * Normalize a Kakao JavaScript key.
	 *
	 * @param mixed $key
	 * @return string
	 */
	public static function sanitizeKakaoKey($key): string
	{
		$key = trim((string)$key);
		return preg_match('/^[0-9a-f]{32}$/i', $key) ? $key : '';
	}

	/**
	 * Check whether a document can be shared publicly.
	 *
	 * @param object $module_info
	 * @param object $oDocument
	 * @return bool
	 */
	public static function isShareable($module_info, $oDocument): bool
	{
		if (!$oDocument || !$oDocument->isExists() || !$oDocument->isAccessible())
		{
			return false;
		}
		if ($oDocument->isSecret() || $oDocument->get('status') !== 'PUBLIC')
		{
			return false;
		}
		if (($module_info->consultation ?? 'N') === 'Y')
		{
			return false;
		}

		$guest = new \stdClass;
		$guest->member_srl = 0;
		$guest->is_admin = 'N';
		$guest->group_list = [];
		$grant = ModuleModel::getGrant($module_info, $guest);
		if (empty($grant->access) || empty($grant->view))
		{
			return false;
		}
		return true;
	}

	/**
	 * Build share URLs for each channel.
	 *
	 * @param string $url
	 * @param string $title
	 * @return array
	 */
	public static function getLinks(string $url, string $title): array
	{
		$u = rawurlencode($url);
		$t = rawurlencode($title);
		return [
			'facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . $u,
			'x' => 'https://x.com/intent/tweet?text=' . $t . '&url=' . $u,
			'naver' => 'https://share.naver.com/web/shareView?url=' . $u . '&title=' . $t,
			'band' => 'https://band.us/plugin/share?body=' . $t . '%0A' . $u . '&route=' . $u,
			'line' => 'https://social-plugins.line.me/lineit/share?url=' . $u,
			'threads' => 'https://www.threads.net/intent/post?text=' . $t . '&url=' . $u,
			'email' => 'mailto:?subject=' . $t . '&body=' . $t . '%0A' . $u,
		];
	}

	/**
	 * Render the share block for a document.
	 *
	 * @param object $module_info
	 * @param object $oDocument
	 * @param bool $auto
	 * @return string
	 */
	public static function render($module_info, $oDocument, bool $auto = false): string
	{
		$config = self::getConfig($module_info);
		if (!$config->use || !$config->channels || !self::isShareable($module_info, $oDocument))
		{
			return '';
		}

		self::loadAssets();

		$url = $oDocument->getPermanentUrl();
		$title = $oDocument->getTitleText();
		$links = self::getLinks($url, $title);

		$items = [];
		foreach ($config->channels as $channel)
		{
			$label = lang('board_share_via_' . $channel);
			$icon = self::getIcon($channel);
			if (isset($links[$channel]))
			{
				$external = $channel !== 'email';
				$items[] = sprintf(
					'<li><a class="zm-share__btn zm-share__btn--%s" href="%s" data-share="%s"%s aria-label="%s" title="%s">%s</a></li>',
					$channel, escape($links[$channel]), $channel,
					$external ? ' target="_blank" rel="noopener noreferrer nofollow"' : '',
					escape($external ? sprintf(lang('board_share_new_window'), $label) : $label), escape($label), $icon
				);
			}
			else
			{
				$items[] = sprintf(
					'<li%s><button type="button" class="zm-share__btn zm-share__btn--%s" data-share="%s" aria-label="%s" title="%s">%s</button></li>',
					$channel === 'native' ? ' hidden' : '', $channel, $channel, escape($label), escape($label), $icon
				);
			}
		}

		return sprintf(
			'<div class="zm-share" data-zm-share data-document-srl="%d" data-url="%s" data-title="%s"%s data-msg-copied="%s" data-msg-copy-failed="%s" data-msg-kakao-paste="%s"%s>'
			. '<span class="zm-share__label" aria-hidden="true">%s</span>'
			. '<ul class="zm-share__list" aria-label="%s">%s</ul>'
			. '<span class="zm-share__toast" role="status" aria-live="polite"></span>'
			. '</div>',
			$oDocument->document_srl, escape($url), escape($title),
			$config->kakao_key ? sprintf(' data-kakao-key="%s" data-kakao-sdk="%s" data-kakao-sdk-integrity="%s"', escape($config->kakao_key), escape(self::KAKAO_SDK), escape(self::KAKAO_SDK_INTEGRITY)) : '',
			escape(lang('board_share_copied')), escape(lang('board_share_copy_failed')), escape(lang('board_share_kakao_paste')),
			$auto ? ' data-zm-share-auto hidden' : '',
			escape(lang('board_share')), escape(lang('board_share_this_document')), implode('', $items)
		);
	}

	/**
	 * Prepare share output for the current document view.
	 *
	 * @param object $module_info
	 * @param object $oDocument
	 * @return void
	 */
	public static function prepareView($module_info, $oDocument): void
	{
		$html = self::render($module_info, $oDocument);
		Context::set('board_share', $html);
		if ($html !== '' && self::getConfig($module_info)->auto)
		{
			Context::addHtmlFooter(self::render($module_info, $oDocument, true));
		}
	}

	/**
	 * Load CSS and JS once.
	 *
	 * @return void
	 */
	public static function loadAssets(): void
	{
		if (self::$_assets_loaded)
		{
			return;
		}
		self::$_assets_loaded = true;
		Context::loadFile(['./modules/board/tpl/css/share.css', '', '', 100]);
		Context::loadFile(['./modules/board/tpl/js/share.js', 'body', '', 100]);
	}

	/**
	 * Get an inline SVG icon.
	 *
	 * @param string $channel
	 * @return string
	 */
	public static function getIcon(string $channel): string
	{
		$paths = [
			'native' => '<path d="M12 3v12M7.5 7.5 12 3l4.5 4.5M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
			'copy' => '<path d="M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1.2 1.2M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1.2-1.2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
			'kakao' => '<path d="M12 3.5c-5 0-9 3.1-9 7 0 2.5 1.7 4.7 4.2 6l-.9 3.4c-.1.3.3.6.6.4l4-2.7h1.1c5 0 9-3.1 9-7s-4-7.1-9-7.1z" fill="currentColor"/>',
			'facebook' => '<path d="M13.5 21v-7.5h2.5l.4-3h-2.9V8.6c0-.9.3-1.5 1.5-1.5h1.5V4.4c-.3 0-1.2-.1-2.2-.1-2.2 0-3.7 1.3-3.7 3.8v2.4H8v3h2.6V21h2.9z" fill="currentColor"/>',
			'x' => '<path d="M17.2 3.5h2.9l-6.3 7.2 7.4 9.8h-5.8l-4.5-5.9-5.2 5.9H2.8l6.7-7.7L2.4 3.5h5.9l4.1 5.4 4.8-5.4zm-1 15.3h1.6L7.9 5.1H6.2l10 13.7z" fill="currentColor"/>',
			'naver' => '<path d="M14.6 12.4 9.1 4.5H4.5v15h4.9v-7.9l5.5 7.9h4.6v-15h-4.9v7.9z" fill="currentColor"/>',
			'band' => '<path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zm3.3 12.6c-.8.6-2 .9-3.6.9H9V7.5h2.6c1.3 0 2.3.3 3 .8.6.5.9 1.1.9 1.9 0 .9-.5 1.6-1.4 2 1.2.3 1.9 1.1 1.9 2.2 0 .5-.2 1-.7 1.2zm-4.2-6.4v2.1h.6c.9 0 1.3-.4 1.3-1.1s-.4-1-1.3-1h-.6zm0 3.7v2.4h.9c1 0 1.5-.4 1.5-1.2s-.5-1.2-1.5-1.2h-.9z" fill="currentColor"/>',
			'line' => '<path d="M12 3.5c-5 0-9 3.2-9 7.2 0 3.6 3.2 6.6 7.5 7.1.3.1.7.2.8.5.1.2.1.6 0 .8l-.1.8c0 .2-.2.9.8.5s5.4-3.2 7.4-5.4c1.4-1.5 2-2.9 2-4.3.6-4-3.4-7.2-9.4-7.2zm-3.2 9.3H7c-.3 0-.5-.2-.5-.5V8.8c0-.3.2-.5.5-.5s.5.2.5.5v3h1.3c.3 0 .5.2.5.5s-.2.5-.5.5zm1.8-.5c0 .3-.2.5-.5.5s-.5-.2-.5-.5V8.8c0-.3.2-.5.5-.5s.5.2.5.5v3.5zm4.2 0c0 .2-.1.4-.3.5h-.2c-.2 0-.3-.1-.4-.2l-1.8-2.4v2.1c0 .3-.2.5-.5.5s-.5-.2-.5-.5V8.8c0-.2.1-.4.3-.5h.2c.2 0 .3.1.4.2l1.8 2.4V8.8c0-.3.2-.5.5-.5s.5.2.5.5v3.5zm2.9-2.2c.3 0 .5.2.5.5s-.2.5-.5.5h-1.3v.8h1.3c.3 0 .5.2.5.5s-.2.5-.5.5h-1.8c-.3 0-.5-.2-.5-.5V8.8c0-.3.2-.5.5-.5h1.8c.3 0 .5.2.5.5s-.2.5-.5.5h-1.3v.8h1.3z" fill="currentColor"/>',
			'threads' => '<path d="M16.3 11.3c-.1 0-.2-.1-.3-.1-.2-3.2-1.9-5-4.8-5h-.1c-1.7 0-3.2.7-4.1 2.1l1.6 1.1c.7-1 1.7-1.2 2.5-1.2 1 0 1.8.3 2.2.9.3.4.6 1 .7 1.7-.9-.1-1.8-.2-2.8-.1-2.8.2-4.6 1.8-4.5 4.1.1 1.2.7 2.2 1.7 2.8.8.5 1.9.8 3 .8 1.5-.1 2.6-.6 3.4-1.6.6-.8 1-1.8 1.2-3 .7.4 1.2 1 1.5 1.6.5 1.1.5 2.9-1 4.4-1.3 1.3-2.9 1.9-5.3 1.9-2.6 0-4.6-.9-5.9-2.5-1.2-1.5-1.9-3.7-1.9-6.5s.6-5 1.9-6.5c1.3-1.7 3.3-2.5 5.9-2.5 2.7 0 4.7.9 6 2.5.7.8 1.1 1.8 1.5 3l1.9-.5c-.4-1.5-1.1-2.8-2-3.9C17.1 2.7 14.6 1.6 11.3 1.6c-3.2 0-5.7 1.1-7.4 3.2-1.5 1.9-2.3 4.5-2.3 7.8s.8 5.9 2.3 7.8c1.7 2.1 4.2 3.2 7.4 3.2h.1c2.9 0 4.9-.8 6.6-2.4 2.2-2.2 2.1-4.9 1.4-6.6-.5-1.1-1.5-2.1-2.8-2.7l-.3-.6zm-4.6 4.9c-1.2.1-2.4-.5-2.5-1.5-.1-.8.6-1.7 2.6-1.8h.7c.7 0 1.4.1 2 .2-.2 2.4-1.5 3-2.8 3.1z" fill="currentColor"/>',
			'email' => '<path d="M4 6h16v12H4z M4 7l8 6 8-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>',
		];
		return '<svg class="zm-share__icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">' . ($paths[$channel] ?? '') . '</svg>';
	}
}
