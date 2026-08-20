'use strict';

/**
 * 간단 도구상자 정의. 반응형 뷰에서 툴바를 실행 중에 바꿀 때도 쓰므로
 * 초기화 함수 안에 두지 않고 모듈 수준 상수로 뺀다.
 */
const SIMPLE_TOOLBAR = [
	{ name: 'styles', items: [ 'Font', 'FontSize', '-', 'Bold', 'Italic', 'Underline', 'Strike', 'TextColor', 'BGColor' ] },
	{ name: 'paragraph', items: [ 'JustifyLeft', 'JustifyCenter', 'JustifyRight' ] },
	{ name: 'clipboard', items: [ 'Cut', 'Copy', 'Paste' ] },
	{ name: 'insert', items: [ 'Link', 'Image', 'Table', 'poll_maker' ] },
	{ name: 'tools', items: [ 'Maximize', '-', 'Source' ] }
];

/**
 * Initialize each instance of CKEditor on the page.
 */
$(function() {

	// 다크 테마일 때 작성 영역(iframe 본문)에도 다크 배경·글자색을 주입한다.
	// 스킨 CSS(zitt-ui.css)는 크롬(툴바 등)만 다루고 iframe 내부에는 닿지 않기 때문.
	// instanceReady 는 테마 전환으로 재생성될 때도 다시 발생하므로 항상 최신 테마가 반영된다.
	// (CKEDITOR 는 여기(DOM ready) 시점에야 로드가 보장되므로 최상단에서 호출하면 안 된다)
	if (window.CKEDITOR && !CKEDITOR._zittDarkHook) {
		CKEDITOR._zittDarkHook = true;

		const zittDarkNow = function () {
			const attr = document.documentElement.getAttribute('data-theme');
			if (attr) {
				return attr === 'dark';
			}
			// 기기 설정은 코어가 읽어 body 클래스로 옮겨 준다
			if (document.body && document.body.classList.contains('color_scheme_dark')) {
				return true;
			}
			return typeof getColorScheme === 'function' && getColorScheme() === 'dark';
		};

		// 드롭다운 패널(색상선택·스타일 목록 등)은 별도 iframe 문서라 페이지 CSS가 닿지 않는다.
		// 다크일 때 패널 iframe 생성 시점에 내부에 다크 스타일을 직접 주입한다.
		// (테마 전환 시 에디터가 재생성되며 패널도 새로 만들어지므로 생성 시점 테마만 보면 된다)
		const ZITT_PANEL_DARK_CSS =
			'html, body { background: #1c1f26 !important; }' +
			' .cke_panel_block, .cke_panel_list, .cke_panel_grouptitle { background: #1c1f26 !important; color: #e4e7ec !important; border-color: #2b3038 !important; }' +
			' .cke_panel_listItem a { color: #e4e7ec !important; }' +
			' .cke_panel_listItem a:hover, .cke_panel_listItem a:focus { background: rgba(38,119,227,.2) !important; }' +
			// 자체 밝은 배경을 가진 스타일 미리보기(Special Container 등)는 글자를 다시 어둡게.
			// 안 그러면 밝은 배경 + 밝은 글자가 되어 보이지 않는다.
			' .cke_panel_listItem a [style*="background"], .cke_panel_listItem a [class*="container"] { color: #1c2330 !important; }' +
			' a.cke_colorauto, a.cke_colormore { color: #e4e7ec !important; border: 1px solid transparent !important; background: transparent !important; }' +
			' a.cke_colorauto:hover, a.cke_colormore:hover, a.cke_colorbox:hover { border-color: #2677e3 !important; }' +
			' .cke_colorbox { border: 1px solid #2b3038 !important; }' +
			' table, td { border-color: #2b3038 !important; }';
		const zittPatchPanel = function (panelEl) {
			const frame = panelEl.querySelector('iframe');
			if (!frame) {
				return;
			}
			const patch = function () {
				try {
					const d = frame.contentDocument;
					if (!d || !d.head || d.getElementById('zed-panel-dark')) {
						return;
					}
					const s = d.createElement('style');
					s.id = 'zed-panel-dark';
					s.textContent = ZITT_PANEL_DARK_CSS;
					d.head.appendChild(s);
				} catch (e) {}
			};
			frame.addEventListener('load', patch);
			patch();
		};
		new MutationObserver(function (muts) {
			if (!zittDarkNow()) {
				return;
			}
			muts.forEach(function (m) {
				Array.prototype.forEach.call(m.addedNodes, function (node) {
					if (node.nodeType !== 1) {
						return;
					}
					if (node.classList && node.classList.contains('cke_panel')) {
						zittPatchPanel(node);
					} else if (node.querySelectorAll) {
						Array.prototype.forEach.call(node.querySelectorAll('.cke_panel'), zittPatchPanel);
					}
				});
			});
		}).observe(document.body, { childList: true, subtree: true });
		CKEDITOR.on('instanceReady', function (ev) {
			const attr = document.documentElement.getAttribute('data-theme');
			const dark = attr ? attr === 'dark' : zittDarkNow();
			if (!dark) {
				return;
			}
			try {
				const doc = ev.editor.document;
				if (doc && doc.$ && doc.$.head) {
					const style = doc.$.createElement('style');
					style.textContent = 'html, body { background: #1c1f26 !important; color: #e4e7ec !important; caret-color: #e4e7ec; }'
						+ ' body a { color: #6ea8ef; }'
						+ ' body table, body th, body td { border-color: #2b3038 !important; }';
					doc.$.head.appendChild(style);
				}
			} catch (e) {}
		});
	}
	$('.rx_ckeditor').each(function() {

		// Load editor configuration.
		const container = $(this);
		const form = container.closest('form');
		const editor_sequence = parseInt(container.data('editorSequence'), 10);
		const config = container.data('editorConfig');

		// zittEditor: 레이아웃(data-theme) 또는 브라우저 다크모드에 따라 스킨 자동 선택
		const zittIsDark = function () {
			const attr = document.documentElement.getAttribute('data-theme');
			if (attr) {
				return attr === 'dark';
			}
			// 기기 설정은 코어가 읽어 body 클래스로 옮겨 준다
			if (document.body && document.body.classList.contains('color_scheme_dark')) {
				return true;
			}
			return typeof getColorScheme === 'function' && getColorScheme() === 'dark';
		};
		// 스킨은 항상 moono-lisa 고정. CKEditor 4 는 페이지당 스킨 CSS 를 한 번만 로드하므로
		// 테마에 따라 스킨을 바꾸면(다크 접속 = moono-dark 기준) 토글 시 스킨이 못 따라와 깨진다.
		// 다크 모드는 전적으로 zitt-ui.css 의 CSS 변수 + iframe/패널 주입으로 처리한다.
		config.skin = 'moono-lisa';
		// 코어의 자동 다크모드(cke_auto_dark_mode: CSS 필터 반전)도 쓰지 않는다 — 이중 적용으로 깨짐.
		$('body').removeClass('cke_auto_dark_mode');

		// If the default font is not set, use the browser default font.
		if (config.default_font === 'none' && window.getComputedStyle) {
			let test_content = $('<div class="Zittme_content xe_content"></div>').hide().appendTo($(document.body));
			let test_styles = window.getComputedStyle(test_content[0], null);
			if (test_styles && test_styles.getPropertyValue) {
				let default_font = test_styles.getPropertyValue('font-family');
				if (default_font) {
					config.default_font = $.trim(default_font.split(',')[0].replace(/['"]/g, ''));
					config.css_content = '.Zittme_content.editable { font-family:' + default_font + '; } ' + config.css_content;
				}
			}
		}

		// Define the initial structure for CKEditor settings.
		const settings = {
			ckeconfig: {
				height: config.height,
				skin: config.skin,
				contentsCss: config.css_files,
	            font_defaultLabel: config.default_font,
	            font_names: config.fonts.join(';'),
	            fontSize_defaultLabel: config.default_font_size,
	            fontSize_sizes: config.font_sizes.join(';'),
				toolbarStartupExpanded: !config.hide_toolbar,
				toolbarCanCollapse: true,
				allowedContent: true,
				startupFocus: config.focus,
				language: config.language,
				iframe_attributes: {},
				versionCheck: false,
				rx_allow_upload: config.allow_upload,
				xe_editor_sequence: editor_sequence,
			},
			loadXeComponent: true,
			enableToolbar: true
		};

		// Add stylesheets from the current document.
		$('link[rel=stylesheet]').each(function() {
			settings.ckeconfig.contentsCss.push($(this).attr('href'));
		});

		// Add and remove plugins.
		if (config.add_plugins) {
			settings.ckeconfig.extraPlugins = config.add_plugins.join(',');
		}
		if (config.remove_plugins) {
			settings.ckeconfig.removePlugins = config.remove_plugins.join(',');
		}

		// Add editor components.
		if (config.enable_component) {
			settings.ckeconfig.xe_component_arrays = config.components;
		} else {
			settings.ckeconfig.xe_component_arrays = {};
			settings.loadXeComponent = false;
		}

		if (!config.enable_default_component) {
			settings.enableToolbar = false;
			settings.ckeconfig.toolbarCanCollapse = false;
		}

		// Patch for iOS: https://github.com/rhymix/rhymix/issues/932
		if (config.ios_patch) {
			$('head').append('<style>'
				+ '.cke_wysiwyg_div { padding: 8px !important; }'
				+ 'html { min-width: unset; min-height: unset; width: unset; height: unset; margin: unset; padding: unset; }'
				+ config.css_content.replace(/\.Zittme_content\.editable/g, '.cke_wysiwyg_div')
				+ '</style>'
			);
		}

		// Define the simple toolbar.
		if (config.toolbar === 'simple') {
			settings.ckeconfig.toolbar = SIMPLE_TOOLBAR;
		}

		// Support legacy HTML (full editing) mode.
		if (!config.legacy_html_mode) {
			settings.ckeconfig.removeButtons = 'Save,Preview,Print,Cut,Copy,Paste,Source';
		}

		// Disable loading of custom configuration if config.js does not exist.
		if (!config.custom_config_exists) {
			CKEDITOR.config.customConfig = '';
		}

		// Prevent removal of icon fonts and Google code.
		CKEDITOR.dtd.$removeEmpty.i = 0;
		CKEDITOR.dtd.$removeEmpty.ins = 0;

		// Set the cache-busting timestamp for plugins.
		CKEDITOR.timestamp = config.timestamp;

		// Set the custom CSS content.
		CKEDITOR.addCss(config.css_content);

		// Initialize the CKEditor XE app.
		const ckeApp = container.XeCkEditor(settings);

		// Add use_editor and use_html fields to the parent form.
		const use_editor = form.find('input[name=use_editor]');
		const use_html = form.find('input[name=use_html]');
		if (use_editor.length) {
			use_editor.val('Y');
		} else {
			form.append('<input type="hidden" name="use_editor" value="Y" />');
		}
		if (use_html.length) {
			use_html.val('Y');
		} else {
			form.append('<input type="hidden" name="use_html" value="Y" />');
		}

		// 테마 전환(레이아웃 토글·시스템 다크모드 변경) 시 에디터를 내용 보존한 채 재생성한다.
		// 스킨은 그대로지만, 재생성해야 작성 영역 iframe·드롭다운 패널에 현재 테마의
		// 다크 스타일 주입이 처음부터 다시 이뤄진다 (instanceReady 재발생).
		let zittCurrentDark = zittIsDark();
		const zittApplyTheme = function () {
			const wantDark = zittIsDark();
			if (wantDark === zittCurrentDark) {
				return;
			}
			const instance = _getCkeInstance(editor_sequence);
			if (!instance) {
				return;
			}
			const data = instance.getData();
			const newSettings = $.extend(true, {}, settings);
			try {
				instance.destroy(true);
				container.XeCkEditor(newSettings);
				const revived = _getCkeInstance(editor_sequence);
				if (revived) {
					revived.setData(data);
				}
				zittCurrentDark = wantDark;
			} catch (e) {
				// 재생성 실패 시 기존 화면 유지 — 글을 잃는 것보다 낫다.
			}
		};
		new MutationObserver(zittApplyTheme).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
		// 코어가 기기 설정 변화에 맞춰 body 클래스를 갈아 끼운다
		if (document.body) {
			new MutationObserver(zittApplyTheme).observe(document.body, { attributes: true, attributeFilter: ['class'] });
		}

		// 반응형 뷰: 창 폭이 브레이크포인트를 넘으면 툴바와 높이를 그 자리에서 바꾼다.
		// 페이지를 다시 불러오지 않으므로 작성 중인 글이 그대로 남는다.
		// (코어가 responsive-view.js 를 로드했을 때만 이벤트가 온다)
		if (config.responsive_settings) {
			document.addEventListener('zittme:viewportmodechange', function (ev) {
				const narrow = ev.detail && ev.detail.narrow;
				const want = narrow ? config.responsive_settings.narrow : config.responsive_settings.wide;
				if (!want) {
					return;
				}

				const instance = _getCkeInstance(editor_sequence);
				if (!instance) {
					return;
				}

				// 높이는 데이터를 건드리지 않고 바로 반영된다.
				if (want.height && instance.resize) {
					try { instance.resize('100%', parseInt(want.height, 10)); } catch (e) {}
				}

				// 툴바는 CKEditor 4 에서 실행 중 교체가 안 되므로 재생성해야 한다.
				// 내용을 꺼냈다가 다시 넣어 글은 보존한다 (실행취소 이력과 커서는 잃는다).
				if (want.toolbar !== config.toolbar) {
					config.toolbar = want.toolbar;
					const data = instance.getData();
					const newSettings = $.extend(true, {}, settings);
					newSettings.ckeconfig.height = parseInt(want.height, 10) || newSettings.ckeconfig.height;
					newSettings.ckeconfig.toolbar = want.toolbar === 'simple' ? SIMPLE_TOOLBAR : undefined;
					newSettings.ckeconfig.toolbarStartupExpanded = !want.hide_toolbar;
					try {
						instance.destroy(true);
						const revived = container.XeCkEditor(newSettings);
						const newInstance = _getCkeInstance(editor_sequence);
						if (newInstance) {
							newInstance.setData(data);
						}
					} catch (e) {
						// 재생성에 실패하면 원래 화면을 유지한다. 글을 잃는 것보다 낫다.
					}
				}

				// 코어 스크립트에 "처리했으니 새로고침하지 말 것"을 알린다.
				ev.preventDefault();
			});
		}

	});
});

/**
 * This function is only retained for backward compatibility.
 * Do not depend on it for any reason.
 */
function ckInsertUploadedFile() {
	if (typeof console == "object" && typeof console.warn == "function") {
		const msg = "DEPRECATED : ckInsertUploadedFile() is obsolete in Zittme.";
		if (navigator.userAgent.match(/Firefox/)) {
			console.error(msg);
		} else {
			console.warn(msg);
		}
	}
}

/**
 * Legacy function to get iframe content and insert it into CKEditor.
 */
function editorReplaceHTML(iframe_obj, content) {
	if (typeof console == "object" && typeof console.warn == "function") {
		const msg = "DEPRECATED : editorReplaceHTML() is deprecated in Zittme.";
		if (navigator.userAgent.match(/Firefox/)) {
			console.error(msg);
		} else {
			console.warn(msg);
		}
	}
	var editor_sequence = parseInt(iframe_obj.id.replace(/^.*_/, ''), 10);
	_getCkeInstance(editor_sequence).insertHtml(content, 'unfiltered_html');
}

/**
 * Legacy function to get a direct reference to the CKEditor container element.
 */
function editorGetIFrame(editor_sequence) {
	if (typeof console == "object" && typeof console.warn == "function") {
		const msg = "DEPRECATED : editorGetIFrame() is deprecated in Zittme.";
		if (navigator.userAgent.match(/Firefox/)) {
			console.error(msg);
		} else {
			console.warn(msg);
		}
	}
	return $('#ckeditor_instance_' + editor_sequence).get(0);
}

/**
 * Legacy function to get an instance of CKEditor.
 */
function _getCkeInstance(editor_sequence) {
	return $('#ckeditor_instance_' + editor_sequence).data('cke_instance');
}

/**
 * Legacy function to get the container element for CKEditor.
 */
function _getCkeContainer(editor_sequence) {
	return $('#ckeditor_instance_' + editor_sequence);
}

/**
 * Legacy function to get HTML content from CKEditor.
 */
function editorGetContent(editor_sequence) {
	return _getCkeInstance(editor_sequence).getData();
}

/**
 * Legacy function to get text content from CKEditor.
 */
function editorGetContentTextarea_xe(editor_sequence) {
	return _getCkeInstance(editor_sequence).getText();
}

/**
 * Legacy function to get currently selected text from CKEditor.
 */
function editorGetSelectedHtml(editor_sequence) {
	return _getCkeInstance(editor_sequence).getSelection().getSelectedText();
}
