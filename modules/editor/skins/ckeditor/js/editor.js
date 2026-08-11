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
	$('.rx_ckeditor').each(function() {

		// Load editor configuration.
		const container = $(this);
		const form = container.closest('form');
		const editor_sequence = parseInt(container.data('editorSequence'), 10);
		const config = container.data('editorConfig');

		// Apply auto dark mode.
		if (config.auto_dark_mode) {
			$('body').addClass('cke_auto_dark_mode');
			if (getColorScheme() === 'dark') {
				if (config.skin !== 'moono-lisa') {
					config.skin = 'moono-dark';
				}
			}
		}

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
