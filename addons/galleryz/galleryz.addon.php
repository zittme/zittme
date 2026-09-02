<?php
/* Copyright (C) ZZAN Studio and Zittme Contributors */

if(!defined('RX_VERSION'))
{
	exit();
}

/**
 * @file galleryz.addon.php
 * @brief galleryZ — 본문 이미지를 갤러리로 넘겨보는 뷰어.
 *
 * 구 photoswipe 애드온의 후속. 외부 라이브러리 없이 동작하며
 * 확대(휠·더블탭·핀치) · 팬 · 스와이프 · 키보드 탐색 · 다운로드를 제공한다.
 * 구판의 제외/포함 클래스(photoswipe-escape / photoswipe-images)도 그대로 인식한다.
 */
if($called_position == 'after_module_proc' && Context::getResponseMethod() == 'HTML' && Context::get('module') != 'admin' && !isCrawler())
{
	Context::loadFile(array('./addons/galleryz/galleryz.css', '', '', null), true);
	Context::loadFile(array('./addons/galleryz/galleryz.js', 'body', '', null), true);

	// 하단 캡션(파일이름/설명) 표시 여부 — 구 photoswipe 애드온과 같은 설정
	$display = (isset($addon_info->display_name) && $addon_info->display_name === 'none') ? 'none' : '';
	if ($display === 'none')
	{
		Context::addHtmlHeader('<style>.gz-caption { display: none !important; }</style>');
	}
}

/* End of file galleryz.addon.php */
/* Location: ./addons/galleryz/galleryz.addon.php */
