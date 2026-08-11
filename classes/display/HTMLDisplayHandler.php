<?php

class HTMLDisplayHandler
{
	/**
	 * jQuery versions
	 */
	public const JQUERY_V2 = '2.2.4';
	public const JQUERY_V2_MIGRATE = '1.4.1';
	public const JQUERY_V3 = '3.7.1';
	public const JQUERY_V3_MIGRATE = '3.6.0';

	/**
	 * Default viewport setting
	 */
	public const DEFAULT_VIEWPORT = 'width=device-width, initial-scale=1.0, user-scalable=yes';

	/**
	 * Reserved scripts
	 */
	public static $reservedCSS = '@\bcommon/css/(?:xe|zittme|mobile)\.(?:min\.)?(?:s?css|less)$@';
	public static $reservedJS = '@\bcommon/js/(?:jquery(?:-[123][0-9.x-]+)?|xe?|common|js_app|xml_handler|xml_js_filter)\.(?:min\.)?js$@';

	/**
	 * List of scripts to block loading
	 */
	public static $blockedScripts = array(
		'@(?:^|/)j[Qq]uery(?:-[0-9]+(?:\.[0-9x]+)*|-latest)?(?:\.min)?\.js$@',
	);

	/**
	 * Replacement table for XE compatibility
	 */
	public static $replacements = array(
		'@\bcommon/xeicon/@' => 'common/css/xeicon/',
		'@\beditor/skins/xpresseditor/js/xe_textarea\.(?:min\.)?js@' => 'editor/skins/ckeditor/js/xe_textarea.js',
		'@/lang$@' => '/lang/lang.xml',
	);

	/**
	 * Image type information for SEO
	 */
	protected $_image_type = 'none';

	/**
	 * Produce HTML compliant content given a module object.\n
	 * @param ModuleObject $oModule the module object
	 * @return string compiled template string
	 */
	public function toDoc(&$oModule)
	{
		// SECISSUE https://github.com/xpressengine/xe-core/issues/1583
		$oSecurity = new Security();
		$oSecurity->encodeHTML('is_keyword', 'search_keyword', 'search_target', 'order_target', 'order_type');

		$template_path = $oModule->getTemplatePath();
		$template_file = $oModule->getTemplateFile();

		if(!is_dir($template_path))
		{
			if($oModule->module_info->module == $oModule->module)
			{
				$skin = $oModule->origin_module_info->skin;
			}
			else
			{
				$skin = $oModule->module_config->skin;
			}

			if(Context::get('module') != 'admin' && strpos(Context::get('act'), 'Admin') === false)
			{
				if($skin && is_string($skin))
				{
					// 스킨 값이 '테마|@|스킨' 이면 테마 폴더 안을 가리킨다.
					// 예전에는 여기 조건문이 substr($x, 0, strlen($x)) != $x 로 항상 거짓이라
					// 분기가 실행되지 않았다. 경로 조립도 모듈 이름 자리에 스킨 이름을 넣고 있었다.
					$template_path = Zittme\Framework\Theme::resolveSkinPath(
						$oModule->module_path ?: $oModule->getTemplatePath(), $skin, 'skins'
					);
					if(!Zittme\Framework\Storage::isDirectory($template_path))
					{
						$template_path = $oModule->getTemplatePath();
					}
				}
				else
				{
					$template_path = $oModule->getTemplatePath();
				}
			}
			else
			{
				$template_path = $oModule->getTemplatePath();
			}
		}

		$oTemplate = new Zittme\Framework\Template($template_path, $template_file);
		$output = $oTemplate->compile();

		// add .x div for adminitration pages
		if(Context::getResponseMethod() == 'HTML')
		{
			$x_exclude_actions = array(
				'dispPageAdminContentModify' => true,
				'dispPageAdminMobileContentModify' => true,
				'dispPageAdminMobileContent' => true,
			);
			$current_act = strval(Context::get('act'));
			if(Context::get('module') != 'admin' && strpos($current_act, 'Admin') !== false && !isset($x_exclude_actions[$current_act]))
			{
				$output = '<div class="x">' . $output . '</div>';
			}

			// Wrap content in layout
			$use_layout = Context::get('layout') !== 'none';
			if (!$use_layout && isset($_REQUEST['layout']) && !self::isPartialPageRendering())
			{
				$use_layout = true;
			}
			if ($use_layout)
			{
				$start = microtime(true);

				Context::set('content', $output, false);

				$layout_path = $oModule->getLayoutPath();
				$layout_file = $oModule->getLayoutFile();

				$edited_layout_file = $oModule->getEditedLayoutFile();

				// get the layout information currently requested
				$oLayoutModel = getModel('layout');
				$layout_info = Context::get('layout_info');
				$layout_srl = $layout_info->layout_srl ?? 0;

				// compile if connected to the layout
				if($layout_srl > 0)
				{
					// handle separately if the layout is faceoff
					if($layout_info && isset($layout_info->type) && $layout_info->type == 'faceoff')
					{
						$oLayoutModel->doActivateFaceOff($layout_info);
						Context::set('layout_info', $layout_info);
					}

					// search if the changes CSS exists in the admin layout edit window
					$edited_layout_css = $oLayoutModel->getUserLayoutCss($layout_srl);

					if(FileHandler::exists($edited_layout_css))
					{
						Context::loadFile(array($edited_layout_css, 'all', '', 100));
					}
				}
				if (!$layout_path)
				{
					$layout_path = './common/tpl';
				}
				if (!$layout_file)
				{
					if ($layout_path === './common/tpl')
					{
						$layout_file = 'default_layout';
					}
					else
					{
						$layout_file = 'layout';
					}
				}

				$oTemplate = new Zittme\Framework\Template;
				$output = $oTemplate->compile($layout_path, $layout_file, $edited_layout_file);

				// Add layout header script.
				if ($layout_srl > 0)
				{
					$part_config = ModuleModel::getModulePartConfig('layout', $layout_srl);
					if ($part_config && isset($part_config->header_script))
					{
						Context::addHtmlHeader($part_config->header_script, true);
					}
				}

				// if popup_layout, remove admin bar.
				$realLayoutPath = FileHandler::getRealPath($layout_path);
				if(substr_compare($realLayoutPath, '/', -1) !== 0)
				{
					$realLayoutPath .= '/';
				}

				$pathInfo = pathinfo($layout_file);
				$onlyLayoutFile = $pathInfo['filename'];

				Zittme\Framework\Debug::addTime('layout', microtime(true) - $start);
			}
		}

		// Add OpenGraph and Twitter metadata
		if (config('seo.og_enabled') && Context::get('module') !== 'admin')
		{
			$this->_addOpenGraphMetadata();
			if (config('seo.twitter_enabled'))
			{
				$this->_addTwitterMetadata();
			}
		}

		// set icon
		$site_module_info = Context::get('site_module_info');
		$favicon_url = Zittme\Modules\Admin\Models\Icon::getFaviconUrl($site_module_info->domain_srl ?? 0);
		$dark_favicon_url = Zittme\Modules\Admin\Models\Icon::getDarkFaviconUrl($site_module_info->domain_srl ?? 0);
		$mobicon_url = Zittme\Modules\Admin\Models\Icon::getMobiconUrl($site_module_info->domain_srl ?? 0);
		Context::set('favicon_url', $favicon_url);
		Context::set('dark_favicon_url', $dark_favicon_url);
		Context::set('mobicon_url', $mobicon_url);

		// Only print the X-UA-Compatible meta tag if somebody is still using IE
		if (preg_match('!Trident/7\.0!', $_SERVER['HTTP_USER_AGENT'] ?? ''))
		{
			Context::addMetaTag('X-UA-Compatible', 'IE=edge', true);
		}

		return $output;
	}

	/**
	 * Check if partial page rendering (dropping the layout) is enabled.
	 *
	 * @return bool
	 */
	public static function isPartialPageRendering()
	{
		$ppr = config('view.partial_page_rendering') ?? 'internal_only';
		if ($ppr === 'disabled')
		{
			return false;
		}
		elseif ($ppr === 'ajax_only' && empty($_SERVER['HTTP_X_REQUESTED_WITH']))
		{
			return false;
		}
		elseif ($ppr === 'internal_only' && (!isset($_SERVER['HTTP_REFERER']) || !Zittme\Framework\URL::isInternalURL($_SERVER['HTTP_REFERER'])))
		{
			return false;
		}
		elseif ($ppr === 'except_robots' && isCrawler())
		{
			return false;
		}
		else
		{
			return true;
		}
	}

	/**
	 * when display mode is HTML, prepare code before print.
	 * @param string $output compiled template string
	 * @return void
	 */
	public function prepareToPrint(&$output)
	{
		if(Context::getResponseMethod() != 'HTML')
		{
			return;
		}

		$start = microtime(true);

		// move <style>...</style> in body to the header
		$output = preg_replace_callback('!<style(.*?)>(.*?)<\/style>!is', array($this, '_moveStyleToHeader'), $output);

		// move <link> and <meta> in body to the header
		$output = preg_replace_callback('!<(link|meta)\b(.*?)>!is', array($this, '_moveLinkToHeader'), $output);

		// change a meta fine(widget often put the tag like <!--Meta:path--> to the content because of caching)
		$output = preg_replace_callback('/<!--(#)?Meta:([a-z0-9\_\-\/\.\@\:]+)(\?\$\_\_Context\-\>[a-z0-9\_\-\/\.\@\:\>]+)?-->/is', array($this, '_transMeta'), $output);

		// handles a relative path generated by using the rewrite module
		if(Context::isAllowRewrite())
		{
			$pattern = '/(action)=(["\'])(["\'])/s';
			$output = preg_replace($pattern, '$1=$2' . \RX_BASEURL . '$3', $output);

			$pattern = '/(action|poster|src|href)=(["\'])\.\/([^"\']*)(["\'])/s';
			$output = preg_replace($pattern, '$1=$2' . \RX_BASEURL . '$3$4', $output);

			$pattern = '/src=(["\'])((?:files\/(?:attach|cache|faceOff|member_extra_info|thumbnails)|addons|common|(?:m\.)?layouts|modules|widgets|widgetstyle)\/[^"\']+)(["\'])/s';
			$output = preg_replace($pattern, 'src=$1' . \RX_BASEURL . '$2$3', $output);

			$pattern = '/href=(["\'])(\?[^"\']+)/s';
			$output = preg_replace($pattern, 'href=$1' . \RX_BASEURL . '$2', $output);
		}

		// prevent the 2nd request due to url(none) of the background-image
		$output = preg_replace('/url\((["\']?)none(["\']?)\)/is', 'none', $output);

		$INPUT_ERROR = Context::get('INPUT_ERROR');
		if(is_array($INPUT_ERROR) && count($INPUT_ERROR))
		{
			$keys = array_map(function($str) { return preg_quote($str, '@'); }, array_keys($INPUT_ERROR));
			$keys = '(' . implode('|', $keys) . ')';

			$output = preg_replace_callback('@(<input)([^>]*?)\sname="' . $keys . '"([^>]*?)/?>@is', array(&$this, '_preserveValue'), $output);
			$output = preg_replace_callback('@<select[^>]*\sname="' . $keys . '".+</select>@isU', array(&$this, '_preserveSelectValue'), $output);
			$output = preg_replace_callback('@<textarea[^>]*\sname="' . $keys . '".+</textarea>@isU', array(&$this, '_preserveTextAreaValue'), $output);
		}

		Zittme\Framework\Debug::addTime('trans_content', microtime(true) - $start);

		// Remove unnecessary information
		$output = preg_replace('/member\_\-([0-9]+)/s', 'member_0', $output);

		// convert the final layout
		Context::set('content', $output);
		if(Mobile::isFromMobilePhone())
		{
			$this->_loadMobileJSCSS();
		}
		else
		{
			$this->_loadDesktopJSCSS();
		}
		$oTemplate = new Zittme\Framework\Template('./common/tpl', 'common_layout');
		$output = $oTemplate->compile();

		// replace the user-defined-language
		$output = Context::replaceUserLang($output);

		// Zittme front admin bar (bottom-left) for logged-in admins.
		// See docs/FRONT-ADMIN-BAR.md
		$this->_injectAdminBar($output);

		// remove template path comment tag
		/*
		if(!Zittme\Framework\Debug::isEnabledForCurrentUser())
		{
			$output = preg_replace('/\n<!-- Template (?:start|end) : .*? -->\r?\n/', "\n", $output);
		}
		*/
	}

	/**
	 * Inject the Zittme front admin bar (bottom-left) on HTML front pages for
	 * logged-in admins: quick links to the admin dashboard, current module's
	 * settings and the layout settings (opened in a slim left slide-in modal).
	 * See docs/FRONT-ADMIN-BAR.md
	 */
	protected function _injectAdminBar(&$output)
	{
		$logged_info = Context::get('logged_info');
		if(!$logged_info || ($logged_info->is_admin ?? 'N') !== 'Y')
		{
			return;
		}
		$minfo = Context::get('current_module_info');
		if($minfo && ($minfo->module ?? '') === 'admin')
		{
			return;
		}
		// don't inject into the bar's own slim modal (the layout settings form)
		if(Context::get('act') === 'dispLayoutAdminModifyForm')
		{
			return;
		}
		// don't inject into dedicated full-screen consoles (commerce/reservation/bizchat 등)
		if(Context::get('zmc_console') || Context::get('layout') === 'none' || preg_match('/^disp\w+Console$/', (string)Context::get('act')))
		{
			return;
		}
		// respect the on/off setting (관리자 화면 설정 › 사용자 페이지 관리바)
		$admin_config = getModel('module')->getModuleConfig('admin');
		if($admin_config && isset($admin_config->front_admin_bar) && $admin_config->front_admin_bar === 'N')
		{
			return;
		}
		if(stripos($output, '</body>') === false)
		{
			return;
		}
		// 팝업·모달 창에는 넣지 않는다. 좁은 창에서 좌하단 바가 버튼을 가린다
		if(strpos($output, 'class="x popup"') !== false)
		{
			return;
		}

		$e = function($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
		$admin_url = getNotEncodedUrl('', 'module', 'admin');
		$mid = Context::get('mid');
		$buttons = '';

		// current module settings (kept inside the site layout where possible)
		if($minfo && !empty($minfo->module))
		{
			$mod_url = '';
			// 관리 화면은 admin 모듈로 연다. mid 로 열면 사이트 레이아웃 안에서
			// 관리 폼이 그려져 테마 색과 뒤섞인다.
			if($minfo->module === 'board')
			{
				$mod_url = getNotEncodedUrl('', 'module', 'admin', 'act', 'dispBoardAdminBoardInfo', 'module_srl', $minfo->module_srl);
			}
			else if($minfo->module === 'page')
			{
				$mod_url = getNotEncodedUrl('', 'module', 'admin', 'act', 'dispPageAdminInfo', 'module_srl', $minfo->module_srl);
			}
			if($mod_url)
			{
				// module settings open in the current window (full page)
				$buttons .= '<a class="zab-btn" href="' . $e($mod_url) . '">모듈 설정</a>';
			}
		}

		// layout settings — chrome-less form action opened in the slim left modal
		$layout_srl = ($minfo && !empty($minfo->layout_srl)) ? $minfo->layout_srl : 0;
		if($layout_srl)
		{
			$lurl = getNotEncodedUrl('', 'module', 'layout', 'act', 'dispLayoutAdminModifyForm', 'layout_srl', $layout_srl);
			$buttons .= '<button type="button" class="zab-btn" data-zab-open="' . $e($lurl) . '">레이아웃 설정</button>';
		}

		$gear = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>';

		$bar = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">'
			. '<div id="zittme-adminbar">'
			. '<a class="zab-gear" href="' . $e($admin_url) . '" target="_blank" rel="noopener" title="관리자 페이지">' . $gear . '</a>'
			. $buttons
			. '</div>'
			. '<div id="zab-modal" hidden><div class="zab-back"></div><div class="zab-panel">'
			. '<div class="zab-phead"><span>설정</span><button type="button" class="zab-close" aria-label="닫기">&times;</button></div>'
			. '<iframe class="zab-frame" title="설정"></iframe></div></div>'
			. '<style>'
			. '#zittme-adminbar{position:fixed;left:16px;bottom:16px;z-index:2147483000;display:flex;align-items:center;gap:6px;background:#1d2433;color:#fff;padding:6px;border-radius:24px;box-shadow:0 8px 24px rgba(16,24,40,.28);font-family:"Pretendard Variable",Pretendard,system-ui,sans-serif}'
			. '#zittme-adminbar .zab-gear{width:36px;height:36px;border-radius:18px;display:flex;align-items:center;justify-content:center;color:#cdd5e0;text-decoration:none}'
			. '#zittme-adminbar .zab-gear:hover{background:#2a3245;color:#fff}'
			. '#zittme-adminbar .zab-btn{border:0;background:transparent;color:#e4e7ec;font-family:"Pretendard Variable",Pretendard,system-ui,sans-serif;font-size:13px;font-weight:600;padding:8px 14px;border-radius:18px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;line-height:1}'
			. '#zittme-adminbar .zab-btn:hover{background:#2677e3}'
			. '#zab-modal{position:fixed;inset:0;z-index:2147483001}'
			. '#zab-modal .zab-back{position:absolute;inset:0;background:rgba(16,24,40,.35)}'
			. '#zab-modal .zab-panel{position:absolute;right:0;top:0;bottom:0;width:min(460px,92vw);background:#fff;box-shadow:0 0 40px rgba(16,24,40,.3);display:flex;flex-direction:column;transform:translateX(100%);transition:transform .22s ease}'
			. '#zab-modal.zab-open .zab-panel{transform:none}'
			. '#zab-modal .zab-phead{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid #eaecf0;font-weight:800;color:#1d2433;font-family:"Pretendard Variable",Pretendard,system-ui,sans-serif}'
			. '#zab-modal .zab-close{border:0;background:#f2f4f7;color:#667085;width:28px;height:28px;border-radius:14px;font-size:18px;cursor:pointer;line-height:1}'
			. '#zab-modal .zab-frame{flex:1;border:0;width:100%}'
			. '</style>'
			. '<script>(function(){var m=document.getElementById("zab-modal");if(!m)return;var f=m.querySelector(".zab-frame");'
			. 'function theme(){var d=document.documentElement;var t=d.getAttribute("data-zm-theme")||d.getAttribute("data-theme");if(t==="light"||t==="dark")return t;return window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light";}'
			. 'function open(u){f.src=u+(u.indexOf("?")<0?"?":"&")+"zab_theme="+theme();m.hidden=false;requestAnimationFrame(function(){m.classList.add("zab-open");});}function close(){m.classList.remove("zab-open");setTimeout(function(){m.hidden=true;f.src="about:blank";},220);}'
			. 'document.querySelectorAll("#zittme-adminbar [data-zab-open]").forEach(function(b){b.addEventListener("click",function(){open(b.getAttribute("data-zab-open"));});});'
			. 'm.querySelector(".zab-back").addEventListener("click",close);m.querySelector(".zab-close").addEventListener("click",close);})();</script>';

		$output = preg_replace('@</body>@i', $bar . '</body>', $output, 1);
	}

	/**
	 * when display mode is HTML, prepare code before print about <input> tag value.
	 * @param array $match input value.
	 * @return string input value.
	 */
	function _preserveValue($match)
	{
		$INPUT_ERROR = Context::get('INPUT_ERROR');
		if (!is_scalar($INPUT_ERROR[$match[3]]))
		{
			return $match[0];
		}

		$str = $match[1] . $match[2] . ' name="' . $match[3] . '"' . $match[4];

		// get type
		$type = 'text';
		if(preg_match('/\stype="([^"]+)"/i', $str, $m))
		{
			$type = strtolower($m[1]);
		}

		switch($type)
		{
			case 'radio':
			case 'checkbox':
				if(preg_match('@\s(?i:value)="' . preg_quote($INPUT_ERROR[$match[3]], '@') . '"@', $str))
				{
					$str = preg_replace('@\schecked(="[^"]*?")?@', ' checked="checked"', $str);
				}
				break;
			default:
				if (!preg_match('@\svalue="([^"]*?)"@', $str))
				{
					$str = $str . ' value=""';
				}
				$str = preg_replace_callback('@\svalue="([^"]*?)"@', function() use($INPUT_ERROR, $match) {
					return ' value="' . escape($INPUT_ERROR[$match[3]], true) . '"';
				}, $str);
		}

		return $str . ' />';
	}

	/**
	 * when display mode is HTML, prepare code before print about <select> tag value.
	 * @param array $matches select tag.
	 * @return string select tag.
	 */
	function _preserveSelectValue($matches)
	{
		$INPUT_ERROR = Context::get('INPUT_ERROR');
		preg_replace('@\sselected(="[^"]*?")?@', ' ', $matches[0]);
		preg_match('@<select.*?>@is', $matches[0], $mm);

		preg_match_all('@<option[^>]*\svalue="([^"]*)".+</option>@isU', $matches[0], $m);

		$key = array_search($INPUT_ERROR[$matches[1]], $m[1]);
		if($key === FALSE)
		{
			return $matches[0];
		}

		$m[0][$key] = preg_replace('@(\svalue=".*?")@is', '$1 selected="selected"', $m[0][$key]);

		return $mm[0] . implode('', $m[0]) . '</select>';
	}

	/**
	 * when display mode is HTML, prepare code before print about <textarea> tag value.
	 * @param array $matches textarea tag information.
	 * @return string textarea tag
	 */
	function _preserveTextAreaValue($matches)
	{
		$INPUT_ERROR = Context::get('INPUT_ERROR');
		preg_match('@<textarea.*?>@is', $matches[0], $mm);
		return $mm[0] . escape($INPUT_ERROR[$matches[1]], true) . '</textarea>';
	}

	/**
	 * Move <style> in the document body to the <head> section.
	 *
	 * @param array $matches
	 * @return void
	 */
	function _moveStyleToHeader($matches)
	{
		if(isset($matches[1]) && stristr($matches[1], 'scoped'))
		{
			return $matches[0];
		}
		Context::addHtmlHeader($matches[0]);
	}

	/**
	 * Move <link> and <meta> in the document body to the <head> section.
	 *
	 * @param array $matches
	 * @return void
	 */
	function _moveLinkToHeader($matches)
	{
		if ($matches[1] === 'link' && preg_match('/\brel="([^"]+)"/', $matches[2], $rel) && $rel[1] !== 'stylesheet' && preg_match('/\bhref="([^"]+)"/', $matches[2], $href))
		{
			Context::addLink($href[1], $rel[1]);
		}
		else
		{
			Context::addHtmlHeader($matches[0]);
		}
	}

	/**
	 * add given .css or .js file names in widget code to Context
	 * @param array $matches
	 * @return void
	 */
	function _transMeta($matches)
	{
		if($matches[1])
		{
			return '';
		}
		if($matches[3] ?? false)
		{
			$vars = Context::get(str_replace('?$__Context->', '', $matches[3]));
			Context::loadFile(array($matches[2], null, null, null, $vars));
		}
		else
		{
			Context::loadFile($matches[2]);
		}
	}

	/**
	 * Add OpenGraph metadata tags.
	 *
	 * @return void
	 */
	function _addOpenGraphMetadata()
	{
		// Get information about the current request.
		$page_type = 'website';
		$current_module_info = Context::get('current_module_info');
		$site_module_info = Context::get('site_module_info');
		$document_srl = Context::get('document_srl');
		$grant = Context::get('grant');
		$permitted = isset($grant->access) ? $grant->access : false;
		if (isset($grant->view) && !$grant->view)
		{
			$permitted = false;
		}
		if ($document_srl && $permitted)
		{
			if (isset($grant->consultation_read) && !$grant->consultation_read && $current_module_info->consultation === 'Y')
			{
				$permitted = false;
			}
			else
			{
				$oDocument = Context::get('oDocument') ?: DocumentModel::getDocument($document_srl, false, false);
				if (is_object($oDocument) && $oDocument->document_srl == $document_srl)
				{
					$page_type = 'article';
					if (method_exists($oDocument, 'isSecret') && $oDocument->isSecret())
					{
						$permitted = false;
					}
				}
			}
		}

		// Get existing metadata.
		$og_data = array();
		foreach (Context::getOpenGraphData() as $val)
		{
			$og_data[$val['property']] = $val['content'];
		}

		// Add basic metadata.
		Context::addOpenGraphData('og:title', $permitted ? Context::getBrowserTitle() : lang('msg_not_permitted'));
		Context::addOpenGraphData('og:site_name', Context::getSiteTitle());
		if (!isset($og_data['og:description']) || !Context::getMetaTag('description'))
		{
			if ($page_type === 'article' && $permitted && config('seo.og_extract_description'))
			{
				$description = trim(utf8_normalize_spaces($oDocument->getContentText(200)));
			}
			else
			{
				$description = Context::getMetaTag('description');
			}
			Context::addOpenGraphData('og:description', $description);
			Context::addMetaTag('description', $description);
		}

		// Add metadata about this page.
		if (!isset($og_data['og:type']))
		{
			Context::addOpenGraphData('og:type', $page_type);
		}
		if (!isset($og_data['og:url']) || !Context::getCanonicalURL())
		{
			if ($page_type === 'article')
			{
				$canonical_url = getNotEncodedFullUrl('', 'mid', $current_module_info->mid, 'document_srl', $document_srl);
			}
			elseif (($page = Context::get('page')) > 1)
			{
				$canonical_url = getNotEncodedFullUrl('', 'mid', $current_module_info->mid, 'page', $page);
			}
			elseif (isset($current_module_info->module_srl) && $current_module_info->module_srl == ($site_module_info->module_srl ?? 0))
			{
				$canonical_url = getNotEncodedFullUrl('');
			}
			else
			{
				if (Zittme\Framework\Router::getRewriteLevel() === 2 && Context::getCurrentRequest()->url !== '')
				{
					$canonical_url = Zittme\Framework\URL::getCurrentDomainURL(\RX_BASEURL . preg_replace('/\?.*$/', '', \RX_REQUEST_URL));
				}
				else
				{
					$canonical_url = getNotEncodedFullUrl('', 'mid', $current_module_info->mid);
				}
			}
			Context::setCanonicalURL($canonical_url);
		}

		// Add metadata about the locale.
		$lang_type = Context::getLangType();
		$locales = (include \RX_BASEDIR . 'common/defaults/locales.php');
		if (isset($locales[$lang_type]))
		{
			Context::addOpenGraphData('og:locale', $locales[$lang_type]['locale']);
		}
		if ($page_type === 'article' && $permitted && $oDocument->getLangCode() !== $lang_type && isset($locales[$oDocument->getLangCode()]))
		{
			Context::addOpenGraphData('og:locale:alternate', $locales[$oDocument->getLangCode()]);
		}

		// Add image.
		if ($document_images = Context::getMetaImages())
		{
			// pass
		}
		elseif ($page_type === 'article' && $permitted && config('seo.og_extract_images'))
		{
			if (($document_images = Zittme\Framework\Cache::get("seo:document_images:$document_srl")) === null)
			{
				$document_images = array();
				if ($oDocument->hasUploadedFiles())
				{
					$document_files = $oDocument->getUploadedFiles();
					usort($document_files, function($a, $b) {
						return ord($b->cover_image) - ord($a->cover_image);
					});

					foreach ($document_files as $file)
					{
						if ($file->isvalid !== 'Y' || !preg_match('/\.(?:bmp|gif|jpe?g|png|webp|mp4)$/i', $file->uploaded_filename))
						{
							continue;
						}

						if (str_starts_with($file->mime_type, 'video/'))
						{
							if ($file->thumbnail_filename)
							{
								list($width, $height) = @getimagesize($file->thumbnail_filename);
								if ($width >= 100 || $height >= 100)
								{
									$document_images[] = array('filepath' => $file->thumbnail_filename, 'width' => $width, 'height' => $height);
									break;
								}
							}
						}
						else
						{
							list($width, $height) = @getimagesize($file->uploaded_filename);
							if ($width >= 100 || $height >= 100)
							{
								$document_images[] = array('filepath' => $file->uploaded_filename, 'width' => $width, 'height' => $height);
								break;
							}
						}
					}
				}
				Zittme\Framework\Cache::set("seo:document_images:$document_srl", $document_images);
			}
		}
		else
		{
			$document_images = null;
		}

		if ($document_images)
		{
			$first_image = array_first($document_images);
			$first_image['filepath'] = preg_replace('/^.\\/files\\//', \RX_BASEURL . 'files/', $first_image['filepath']);
			Context::addOpenGraphData('og:image', Zittme\Framework\URL::getCurrentDomainURL($first_image['filepath']));
			Context::addOpenGraphData('og:image:width', $first_image['width']);
			Context::addOpenGraphData('og:image:height', $first_image['height']);
			$this->_image_type = 'document';
		}
		elseif ($default_image = getAdminModel('admin')->getSiteDefaultImageUrl($site_module_info->domain_srl, $width, $height))
		{
			Context::addOpenGraphData('og:image', Zittme\Framework\URL::getCurrentDomainURL($default_image));
			if ($width && $height)
			{
				Context::addOpenGraphData('og:image:width', $width);
				Context::addOpenGraphData('og:image:height', $height);
			}
			$this->_image_type = 'site';
		}
		else
		{
			$this->_image_type = 'none';
		}

		// Add tags and hashtags for articles.
		if ($page_type === 'article' && $permitted)
		{
			$tags = $oDocument->getTags();
			foreach ($tags as $tag)
			{
				if ($tag !== '')
				{
					Context::addOpenGraphData('og:article:tag', $tag);
				}
			}

			if (config('seo.og_extract_hashtags'))
			{
				$hashtags = $oDocument->getHashtags();
				foreach ($hashtags as $hashtag)
				{
					if (!in_array($hashtag, $tags))
					{
						Context::addOpenGraphData('og:article:tag', escape($hashtag, false));
					}
				}
			}

			Context::addOpenGraphData('og:article:section', Context::replaceUserLang($current_module_info->browser_title));
		}

		// Add author name for articles.
		if ($page_type === 'article' && $permitted && config('seo.og_use_nick_name'))
		{
			Context::addMetaTag('author', $oDocument->getNickName());
			Context::addOpenGraphData('og:article:author', $oDocument->getNickName());
		}

		// Add datetime for articles.
		if ($page_type === 'article' && $permitted && config('seo.og_use_timestamps'))
		{
			Context::addOpenGraphData('og:article:published_time', $oDocument->getRegdate('c'));
			Context::addOpenGraphData('og:article:modified_time', $oDocument->getUpdate('c'));
		}
	}

	/**
	 * Add Twitter metadata tags.
	 *
	 * @return void
	 */
	function _addTwitterMetadata()
	{
		$card_type = $this->_image_type === 'document' ? 'summary_large_image' : 'summary';
		Context::addMetaTag('twitter:card', $card_type, false, false);

		foreach(Context::getOpenGraphData() as $val)
		{
			if ($val['property'] === 'og:title')
			{
				Context::addMetaTag('twitter:title', $val['content'], false, false);
			}
			if ($val['property'] === 'og:description')
			{
				Context::addMetaTag('twitter:description', $val['content'], false, false);
			}
			if ($val['property'] === 'og:image' && $this->_image_type === 'document')
			{
				Context::addMetaTag('twitter:image', $val['content'], false, false);
			}
		}
	}

	/**
	 * import basic .js files.
	 * @return void
	 */
	public function _loadDesktopJSCSS()
	{
		$this->_loadCommonJSCSS();
	}

	/**
	 * import basic .js files for mobile
	 */
	private function _loadMobileJSCSS()
	{
		$this->_loadCommonJSCSS();
	}

	/**
	 * import common .js and .css files for (both desktop and mobile)
	 */
	private function _loadCommonJSCSS()
	{
		$jquery_version = config('view.jquery_version') ?: 2;
		if ($jquery_version == 3)
		{
			$jquery_version = self::JQUERY_V3;
			$jquery_migrate_version = self::JQUERY_V3_MIGRATE;
		}
		else
		{
			$jquery_version = self::JQUERY_V2;
			$jquery_migrate_version = self::JQUERY_V2_MIGRATE;
		}

		Context::loadFile(array('./common/css/zittme.scss', '', '', -1600000000), true);

		// Responsive view: the browser has to tell the server how wide it is, because
		// list counts and editor settings are decided while rendering. Loaded only when
		// the feature is on, so nothing changes for existing sites.
		// Only when the responsive view actually applies to the module on screen.
		// Loading it whenever the site default is responsive is wrong: a module still
		// set to the mobile view always reports "not narrow", so the script would keep
		// asking for a reload that can never change the answer.
		if (Mobile::isResponsiveView())
		{
			Context::addHtmlHeader(sprintf('<script>window.zittmeNarrowServer=%s;</script>',
				Mobile::isNarrowScreen() ? 'true' : 'false'));
			Context::loadFile(array('./common/js/responsive-view.js', 'head', '', -1590000000), true);
		}

		$original_file_list = array(
			'plugins/jquery.migrate/jquery-migrate-' . $jquery_migrate_version . '.min.js',
			'plugins/cookie/js.cookie.min.js',
			'plugins/blankshield/blankshield.min.js',
			'plugins/uri/URI.min.js',
		);

		if (str_contains($_SERVER['HTTP_USER_AGENT'] ?? '', 'Trident/'))
		{
			$original_file_list[] = 'polyfills/formdata.min.js';
			$original_file_list[] = 'polyfills/promise.min.js';
		}

		$original_file_list[] = 'x.js';
		$original_file_list[] = 'common.js';
		$original_file_list[] = 'js_app.js';
		$original_file_list[] = 'xml_handler.js';
		$original_file_list[] = 'xml_js_filter.js';

		if(config('view.minify_scripts') === 'none')
		{
			Context::loadFile(array('./common/js/jquery-' . $jquery_version . '.js', 'head', '', -1800000000), true);
			foreach($original_file_list as $filename)
			{
				Context::loadFile(array('./common/js/' . $filename, 'head', '', -1700000000), true);
			}
		}
		else
		{
			Context::loadFile(array('./common/js/jquery-' . $jquery_version . '.min.js', 'head', '', -1800000000), true);
			$concat_target_filename = 'files/cache/assets/minified/Zittme.min.js';
			if(file_exists(\RX_BASEDIR . $concat_target_filename))
			{
				$concat_target_mtime = filemtime(\RX_BASEDIR . $concat_target_filename);
				$original_mtime = 0;
				foreach($original_file_list as $filename)
				{
					$original_mtime = max($original_mtime, filemtime(\RX_BASEDIR . 'common/js/' . $filename));
				}
				if($concat_target_mtime > $original_mtime)
				{
					Context::loadFile(array('./' . $concat_target_filename, 'head', '', -1700000000), true);
					return;
				}
			}
			Zittme\Framework\Formatter::minifyJS(array_map(function($str) {
				return \RX_BASEDIR . 'common/js/' . $str;
			}, $original_file_list), \RX_BASEDIR . $concat_target_filename);
			Context::loadFile(array('./' . $concat_target_filename, 'head', '', -1700000000), true);
		}
	}
}
