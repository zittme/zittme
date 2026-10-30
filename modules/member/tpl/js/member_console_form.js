/**
 * Member administration: signup form
 *
 * The order, use, required and public switches of the items are saved with
 * the old signup settings procedure (same signupForm data). Extra items are
 * added, changed and removed with the old extra item actions.
 */
(function ($) {
	'use strict';

	var K, T, esc, ic;
	var F = { values: {}, meta: {} };
	var U = { sel: '', device: 'P', pane: 'list', pv: null, pvSeq: 0, pvLoading: false, drag: null, adv: false, stash: null };
	var LOCK_REASON = { user_id: 'f_lock_login', email_address: 'f_lock_login', phone_number: 'f_lock_login', password: 'f_lock_password', nick_name: 'f_lock_nick' };
	var PRIVATE = ['email_address', 'phone_number', 'password'];
	var BASE_TYPE = {
		user_id: 'text', email_address: 'email_address', password: 'password', user_name: 'text', nick_name: 'text', phone_number: 'tel',
		homepage: 'homepage', blog: 'homepage', birthday: 'date', signature: 'textarea', profile_image: 'image', image_name: 'image', image_mark: 'image'
	};

	function S() { return K.state(); }
	function items() { return F.values.signup_form || []; }
	function item(name) { var l = items(); for (var i = 0; i < l.length; i++) if (l[i].name === name) return l[i]; return null; }
	function typeOf(it) { return it.ext ? it.column_type : (BASE_TYPE[it.name] || 'text'); }
	function typeLabel(type) { var k = 'f_t_' + type; return K.L[k] ? T(k) : T('f_t_text'); }
	function isChoice(it) { return it.ext && (F.meta.choice_types || []).indexOf(it.column_type) >= 0; }
	function marks(it) {
		if (!it.use) return '<span class="bdg off">' + esc(T('f_badge_off')) + '</span>';
		return (it.required ? '<span class="bdg req">' + esc(T('f_badge_req')) + '</span>' : '') + (isPublic(it) ? '<span class="bdg pub">' + esc(T('f_badge_pub')) + '</span>' : '');
	}
	function isPublic(it) {
		if (it.name === 'nick_name') return true;
		if (PRIVATE.indexOf(it.name) >= 0) return false;
		return it.use && it.public;
	}

	/* ── 불러오기 · 미리보기 ─────────────────────────── */
	function reload(selectName) {
		return K.api('member.getMemberAdminSettings', {}).then(function (d) {
			applyValues(d.values || {});
			if (selectName) U.sel = selectName;
			if (!item(U.sel)) U.sel = items().length ? items()[0].name : '';
			K.render();
			loadPreview();
		}, function (e) { K.failed(e); });
	}
	function applyValues(v) {
		var keep = {};
		Object.keys(F.values).forEach(function (k) { keep[k] = F.values[k]; });
		Object.keys(v).forEach(function (k) { if (k in keep || k === 'signup_form') keep[k] = v[k]; });
		F.values = keep;
	}
	function loadPreview() {
		var seq = ++U.pvSeq;
		U.pvLoading = true;
		renderPreviewState();
		return K.api('member.getMemberAdminSignupPreview', { device: U.device }).then(function (d) {
			if (seq !== U.pvSeq) return;
			U.pvLoading = false;
			U.pv = { html: d.html || '', css: d.css || [] };
			fillPreview();
		}, function (e) {
			if (seq !== U.pvSeq) return;
			U.pvLoading = false;
			renderPreviewState();
			K.failed(e);
		});
	}
	function renderPreviewState() {
		var st = document.getElementById('zmc-pvstage');
		if (st) st.classList.toggle('loading', !!U.pvLoading);
	}
	function baseHref() {
		return location.origin + location.pathname.replace(/[^\/]*$/, '');
	}
	function fillPreview() {
		var frame = document.getElementById('zmc-pvframe');
		if (!frame || !U.pv) return;
		var lang = (document.documentElement.getAttribute('lang') || 'ko');
		var css = U.pv.css.map(function (c) { return '<link rel="stylesheet" href="' + esc(c.url) + '" media="' + esc(c.media || 'all') + '">'; }).join('');
		var doc = '<!doctype html><html lang="' + esc(lang) + '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><base href="' + esc(baseHref()) + '">' + css +
			'<style>html,body{background:#fff}body{margin:0;padding:24px 20px 40px}a,button,input,select,textarea,label{pointer-events:none}.zmc-hl{outline:2px solid #f1d27a!important;outline-offset:4px;background:#fff6d1!important;border-radius:8px;transition:background .2s}</style></head><body class="zmc-preview">' + U.pv.html + '</body></html>';
		frame.onload = function () { renderPreviewState(); highlight(); };
		frame.srcdoc = doc;
		renderPreviewState();
	}
	function highlight() {
		var frame = document.getElementById('zmc-pvframe');
		var note = document.getElementById('zmc-pvnote');
		if (!frame) return;
		var doc;
		try { doc = frame.contentDocument; } catch (e) { return; }
		if (!doc || !doc.body) return;
		Array.prototype.forEach.call(doc.querySelectorAll('.zmc-hl'), function (el) { el.classList.remove('zmc-hl'); });
		var it = item(U.sel);
		if (!it) return;
		var name = it.name;
		var el = doc.querySelector('[name="' + name + '"],[name="' + name + '[]"],[name^="' + name + '["],#' + name.replace(/[^a-zA-Z0-9_-]/g, ''));
		if (!el && name === 'signature') el = doc.querySelector('textarea[disabled]');
		if (note) note.hidden = !!el || !it.use;
		if (!el) return;
		var block = el, depth = 0;
		while (block.parentElement && block.parentElement !== doc.body && depth < 6) {
			var p = block.parentElement;
			if (p.tagName === 'FORM' || p.tagName === 'FIELDSET') break;
			block = p;
			depth++;
			if (/^(LI|TR|DL|DIV|P|SECTION)$/.test(block.tagName) && block.querySelector('label, th, dt, .title')) break;
		}
		block.classList.add('zmc-hl');
		try {
			var se = doc.scrollingElement || doc.documentElement;
			var r = block.getBoundingClientRect();
			var view = frame.contentWindow ? frame.contentWindow.innerHeight : 600;
			se.scrollTop = Math.max(0, se.scrollTop + r.top - Math.max(16, (view - r.height) / 2));
		} catch (e) { }
	}

	/* ── 그리기 ─────────────────────────── */
	function rowHTML(it) {
		var lock = it.lock;
		return '<div class="frow' + (it.name === U.sel ? ' on' : '') + (it.use ? '' : ' off') + '" data-act="fsel" data-v="' + esc(it.name) + '" data-name="' + esc(it.name) + '" data-ext="' + (it.ext ? 1 : 0) + '">' +
			(lock ? '<span class="grip lockd" title="' + esc(T('f_lock_order')) + '">' + ic('lock', 15) + '</span>' : '<button type="button" class="grip" data-grip="f" data-v="' + esc(it.name) + '" aria-label="' + esc(T('f_move', it.title)) + '" title="' + esc(T('f_move_hint')) + '">' + ic('grip', 15) + '</button>') +
			'<span class="ft"><b>' + esc(it.title) + '</b><small>' + esc(typeLabel(typeOf(it))) + '</small></span><span class="marks">' + marks(it) + '</span></div>';
	}
	function listHTML() {
		var base = items().filter(function (x) { return !x.ext; }), ext = items().filter(function (x) { return x.ext; });
		return '<div class="flist" id="zmc-flist"><div class="ghd"><h2>' + K.term('signup_form', T('f_title')) + '</h2><p class="hint">' + esc(T('f_lead')) + '</p></div>' +
			'<div class="fprev-wrap"><button type="button" class="btn fprev-btn" data-act="fpv">' + ic('eye', 15) + esc(T('f_preview_btn')) + '</button></div>' +
			'<div class="fhd">' + esc(T('f_base')) + '</div><div class="fgroup" data-group="base">' + base.map(rowHTML).join('') + '</div>' +
			'<div class="fhd">' + esc(T('f_ext')) + ' · ' + K.term('ext_var', T('f_ext_term')) + '</div><div class="fgroup" data-group="ext">' + (ext.length ? ext.map(rowHTML).join('') : '<p class="hint fempty">' + esc(T('f_ext_none')) + '</p>') + '</div>' +
			'<button type="button" class="fl addline" data-act="pop" data-v="f-add" data-anchor="f-add">' + ic('plus', 16) + esc(T('f_add')) + '</button>' +
			'<div class="fhint"><a class="linkbtn" href="' + esc(K.B.urls.settings) + '">' + esc(T('f_rules_link')) + ic('chevr', 12) + '</a></div></div>';
	}
	function pvbarHTML() {
		var host = location.host;
		return '<div class="pvbar"><button type="button" class="iconbtn fprev-btn" data-act="fpvx" aria-label="' + esc(T('f_back_list')) + '">' + ic('back', 18) + '</button>' +
			'<div class="pvurl">' + ic('globe', 14) + '<b>' + esc(host) + '</b> › ' + esc(T('f_preview_page')) + ' <span class="dim">· ' + esc(T('f_preview')) + '</span></div>' +
			'<div class="seg" role="group" aria-label="' + esc(T('f_device')) + '"><button type="button" class="' + (U.device === 'P' ? 'on' : '') + '" data-act="fdev" data-v="P" aria-pressed="' + (U.device === 'P') + '">' + ic('monitor', 14) + esc(T('f_pc')) + '</button><button type="button" class="' + (U.device === 'M' ? 'on' : '') + '" data-act="fdev" data-v="M" aria-pressed="' + (U.device === 'M') + '">' + ic('phone', 14) + esc(T('f_phone')) + '</button></div>' +
			'<button type="button" class="iconbtn" data-act="fpvreload" title="' + esc(T('f_reload')) + '" aria-label="' + esc(T('f_reload')) + '">' + ic('reload', 16) + '</button></div>';
	}
	function previewHTML() {
		return '<section class="fpreview" aria-label="' + esc(T('f_preview')) + '">' + pvbarHTML() +
			'<div class="pvstage' + (U.pvLoading ? ' loading' : '') + '" id="zmc-pvstage"><div class="pvnote" id="zmc-pvnote" hidden>' + ic('info', 14) + '<span>' + esc(T('f_pv_notshown')) + '</span></div><div class="pvframe-wrap' + (U.device === 'M' ? ' phone' : '') + '"><iframe id="zmc-pvframe" class="pvframe" title="' + esc(T('f_preview')) + '" sandbox="allow-same-origin"></iframe></div></div></section>';
	}
	function swRow(title, sub, on, act, dis, termKey) {
		return '<div class="swrow"><span class="sw-t"><b>' + (termKey ? K.term(termKey, title) : esc(title)) + '</b><small>' + esc(sub) + '</small></span>' + K.sw(on, act, title, dis) + '</div>';
	}
	function sel(id, opts, value, label) {
		return '<select class="sel" id="' + id + '" aria-label="' + esc(label || '') + '">' + opts.map(function (o) { return '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(value) ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('') + '</select>';
	}
	function numField(id, label, value, unit) {
		return '<div class="field"><label for="' + id + '">' + esc(label) + '</label><div class="inrow"><input id="' + id + '" class="inp num" inputmode="numeric" value="' + esc(value) + '">' + (unit ? '<span class="unit">' + esc(unit) + '</span>' : '') + '</div></div>';
	}
	function panelHTML() {
		var it = item(U.sel);
		if (!it) return '<aside class="panel"><div class="pbody"><p class="dim" style="padding:24px 0">' + esc(T('f_pick')) + '</p></div></aside>';
		var v = F.values;
		var h = '<aside class="panel" aria-label="' + esc(T('f_panel')) + '"><div class="ph"><button type="button" class="btn ghost back" data-act="fback">' + ic('back', 16) + esc(T('f_back_list')) + '</button><div class="ph-k">' + (it.ext ? K.term('ext_var', T('f_ext_term')) : esc(T('f_base_one'))) + '</div><h2>' + esc(it.title) + '</h2><div class="ph-sub">' + esc(typeLabel(typeOf(it))) + '</div></div><div class="pbody" id="zmc-fpbody">';

		// 이름
		if (it.ext) {
			h += '<div class="sec"><div class="field"><label for="zmc-ftitle">' + esc(T('f_name')) + '</label><input id="zmc-ftitle" class="inp lang_code" maxlength="60" value="' + esc(it.column_title) + '"><div class="hint">' + esc(T('f_name_hint')) + '</div></div></div>';
		} else if (it.title_raw !== undefined) {
			h += '<div class="sec"><div class="field"><label for="zmc-ftitle">' + esc(T('f_name')) + '</label><input id="zmc-ftitle" class="inp lang_code" maxlength="60" value="' + esc(it.title_raw) + '"><div class="hint">' + esc(T('f_name_hint')) + '</div></div></div>';
		} else {
			h += '<div class="sec"><div class="field"><span class="flabel">' + esc(T('f_name')) + '</span><div class="roval">' + esc(it.title) + '</div><div class="hint">' + esc(T('f_name_fixed')) + '</div></div></div>';
		}

		// 스위치
		var useWhy = it.lock ? T(LOCK_REASON[it.name] || 'f_lock_login') : (it.use ? T('f_use_on') : T('f_use_off'));
		var reqWhy = it.lock ? T('f_req_lock') : (!it.use ? T('f_req_needuse') : T('f_req_hint'));
		var pubWhy, pubDis;
		if (it.name === 'nick_name') { pubWhy = T('f_pub_nick'); pubDis = true; }
		else if (PRIVATE.indexOf(it.name) >= 0) { pubWhy = T('f_pub_private'); pubDis = true; }
		else if (!it.use) { pubWhy = T('f_pub_needuse'); pubDis = true; }
		else { pubWhy = isPublic(it) ? T('f_pub_on') : T('f_pub_off'); pubDis = false; }
		h += '<div class="sec swgroup">' + swRow(T('f_use'), useWhy, it.use, 'fuse', it.lock) + swRow(T('f_req'), reqWhy, it.required, 'freq', it.lock || !it.use, 'required') + swRow(T('f_pub'), pubWhy, isPublic(it), 'fpub', pubDis, 'public') + '</div>';
		if (it.identifier) h += '<div class="sec"><div class="note">' + ic('key', 14) + '<span>' + esc(T('f_identifier_note')) + '</span></div></div>';

		// 종류별 세부
		if (it.image) {
			h += '<div class="sec"><h3>' + esc(T('f_image_size')) + '</h3><div class="row3">' + numField('zmc-fimg-w', T('f_image_w'), it.image.max_width, 'px') + numField('zmc-fimg-h', T('f_image_h'), it.image.max_height, 'px') + numField('zmc-fimg-f', T('f_image_f'), it.image.max_filesize, 'KB') + '</div><div class="hint">' + esc(T('f_image_hint')) + '</div>' +
				(it.name === 'profile_image' ? '<div style="margin-top:12px">' + swRow(T('f_image_ratio'), T('f_image_ratio_hint'), v.profile_image_force_ratio === 'Y', 'fratio') + '</div>' : '') + '</div>';
		}
		if (it.name === 'phone_number') {
			h += '<div class="sec"><h3>' + esc(T('f_phone_title')) + '</h3><div class="field"><label for="zmc-fcountry">' + esc(T('f_phone_country')) + '</label>' + sel('zmc-fcountry', (F.meta.countries || []).map(function (c) { return [c.value, c.label]; }), v.phone_number_default_country, T('f_phone_country')) + '</div>' +
				swRow(T('f_phone_hide'), T('f_phone_hide_hint'), v.phone_number_hide_country === 'Y', 'fphide') +
				swRow(T('f_phone_dup'), T('f_phone_dup_hint'), v.phone_number_allow_duplicate === 'Y', 'fpdup') +
				swRow(T('f_phone_sms'), T('f_phone_sms_hint'), v.phone_number_verify_by_sms === 'Y', 'fpsms') +
				'<div class="hint" style="margin-top:8px"><a class="linkbtn" href="' + esc(K.B.urls.settings) + '&amp;cat=join">' + esc(T('f_phone_more')) + ic('chevr', 12) + '</a></div></div>';
		}
		if (it.name === 'signature') {
			h += '<div class="sec"><h3>' + esc(T('f_sig_title')) + '</h3><div class="field"><label for="zmc-fsigskin">' + esc(T('f_sig_editor')) + '</label>' + sel('zmc-fsigskin', (F.meta.editor_skins || []).map(function (s) { return [s, s]; }), v.signature_editor_skin, T('f_sig_editor')) + '</div>' +
				numField('zmc-fsigh', T('f_sig_height'), v.signature_max_height, 'px') +
				swRow(T('f_sig_html'), T('f_sig_html_hint'), v.signature_html === 'Y', 'fsightml') +
				(v.signature_html === 'N' ? swRow(T('f_sig_retro'), T('f_sig_retro_hint'), v.signature_html_retroact === 'Y', 'fsigretro') : '') +
				swRow(T('f_sig_file'), T('f_sig_file_hint'), v.member_allow_fileupload === 'Y', 'fsigfile') +
				numField('zmc-fsigfs', T('f_sig_filesize'), v.member_max_filesize, 'KB') + '</div>';
		}
		if (it.ext) {
			var types = (F.meta.extra_types || []).map(function (t) { return [t, typeLabel(t)]; });
			h += '<div class="sec"><div class="field"><label for="zmc-ftype">' + esc(T('f_type')) + '</label>' + sel('zmc-ftype', types, it.column_type, T('f_type')) + '</div>';
			if (isChoice(it)) {
				h += '<div class="field"><span class="flabel">' + esc(T('f_choices')) + '</span><div class="choices" id="zmc-fchoices">' + it.options.map(function (o, i) {
					return '<div class="chrow"><input class="inp" data-choice="' + i + '" value="' + esc(o) + '" aria-label="' + esc(T('f_choice_n', i + 1)) + '"><button type="button" class="iconbtn" data-act="fchup" data-v="' + i + '" aria-label="' + esc(T('f_choice_up')) + '"' + (i ? '' : ' disabled') + '>' + ic('up', 14) + '</button><button type="button" class="iconbtn" data-act="fchdel" data-v="' + i + '" aria-label="' + esc(T('f_choice_del', o)) + '">' + ic('x', 14) + '</button></div>';
				}).join('') + '</div><button type="button" class="linkbtn" data-act="fchadd" style="margin-top:6px">' + ic('plus', 14) + esc(T('f_choice_add')) + '</button>' + (!it.options.length ? '<div class="hint">' + esc(T('f_choice_empty')) + '</div>' : '') + '</div>';
			}
			h += '<div class="field"><label for="zmc-fdefault">' + esc(T('f_default')) + '</label><input id="zmc-fdefault" class="inp" value="' + esc(it.default_value) + '"><div class="hint">' + esc(T('f_default_hint')) + '</div></div>' +
				'<div class="field"><label for="zmc-fdesc">' + esc(T('f_desc')) + '</label><textarea id="zmc-fdesc" class="inp" rows="3">' + esc(it.description) + '</textarea><div class="hint">' + esc(T('f_desc_hint')) + '</div></div></div>';
			h += '<div class="sec"><button type="button" class="linkbtn" data-act="fadv" aria-expanded="' + (U.adv ? 'true' : 'false') + '">' + ic(U.adv ? 'chev' : 'chevr', 14) + esc(T('f_adv')) + '</button>' + (U.adv ? '<div class="field" style="margin-top:10px"><label for="zmc-fcol">' + K.term('column_name', T('f_colname')) + '</label><input id="zmc-fcol" class="inp" maxlength="60" value="' + esc(it.column_name) + '" autocomplete="off" spellcheck="false"><div class="hint">' + esc(T('f_colname_hint')) + '</div></div>' : '') + '</div>';
			if (it.missing) h += '<div class="sec"><div class="note warn">' + ic('info', 14) + '<span>' + esc(T('f_missing')) + '</span></div></div>';
			h += '<div class="sec"><h3 class="danger">' + esc(T('f_delete')) + '</h3><p class="hint" style="margin:0 0 8px">' + esc(T('f_delete_hint')) + '</p><button type="button" class="btn danger" data-act="fdelask">' + ic('trash', 15) + esc(T('f_delete')) + '</button></div>';
		}
		return h + '</div></aside>';
	}
	function html() {
		return '<div class="workspace v-form' + (U.pane === 'panel' ? ' m-panel' : U.pane === 'preview' ? ' m-preview' : '') + '">' + listHTML() + previewHTML() + panelHTML() + '</div>';
	}
	function beforeRender() {
		var a = document.activeElement;
		U.stash = (a && K.ROOT.contains(a) && a.id && /^(zmc-f|lang_zmc-f)/.test(a.id) && a.tagName !== 'SELECT') ? { id: a.id, value: a.value, sel: U.sel, s: a.selectionStart, e: a.selectionEnd } : null;
		var b = document.getElementById('zmc-fpbody');
		U.scroll = b ? b.scrollTop : 0;
	}
	function afterRender() {
		var input = document.getElementById('zmc-ftitle');
		if (input && $.fn.xeApplyMultilingualUI) {
			try { $(input).xeApplyMultilingualUI(); } catch (e) { }
		}
		var b = document.getElementById('zmc-fpbody');
		if (b && U.scroll) b.scrollTop = U.scroll;
		var st = U.stash;
		U.stash = null;
		if (st && st.sel === U.sel) {
			var el = document.getElementById(st.id);
			if (el && !el.disabled) {
				el.value = st.value;
				if (st.id === 'lang_zmc-ftitle' && input) input.value = st.value;
				el.focus();
				try { el.setSelectionRange(st.s, st.e); } catch (e) { }
			}
		}
		var frame = document.getElementById('zmc-pvframe');
		if (U.pv && frame && !frame.getAttribute('srcdoc')) fillPreview();
	}
	function swapHTML(el, markup) {
		if (!el) return;
		var tmp = document.createElement('div');
		tmp.innerHTML = markup;
		if (tmp.firstChild) el.parentNode.replaceChild(tmp.firstChild, el);
	}
	function patch() {
		var ws = K.ROOT.querySelector('.workspace.v-form');
		if (!ws || !document.getElementById('zmc-pvframe')) return false;
		beforeRender();
		var keepList = ws.querySelector('.flist') ? ws.querySelector('.flist').scrollTop : 0;
		ws.className = 'workspace v-form' + (U.pane === 'panel' ? ' m-panel' : U.pane === 'preview' ? ' m-preview' : '');
		swapHTML(ws.querySelector('.flist'), listHTML());
		swapHTML(ws.querySelector('.panel'), panelHTML());
		swapHTML(ws.querySelector('.pvbar'), pvbarHTML());
		var wrap = ws.querySelector('.pvframe-wrap');
		if (wrap) wrap.classList.toggle('phone', U.device === 'M');
		var fl = ws.querySelector('.flist');
		if (fl) fl.scrollTop = keepList;
		afterRender();
		return true;
	}

	/* ── 팝오버 · 창 ─────────────────────────── */
	function popHTML() {
		var p = S().pop;
		if (!p || p.type !== 'f-add') return '';
		var types = ['text', 'textarea', 'select', 'radio', 'checkbox', 'date', 'tel_v2', 'kr_zip', 'email_address', 'homepage', 'number'];
		return '<div class="pop" style="width:340px"><h4>' + esc(T('f_add_title')) + '</h4><p class="pl">' + T('f_add_lead', K.term('ext_var', T('f_ext_term'))) + '</p><div class="optlist">' +
			types.map(function (t) { return '<button type="button" class="opt" data-act="faddgo" data-v="' + t + '">' + esc(typeLabel(t)) + '<small>' + esc(T('f_add_ex_' + t)) + '</small></button>'; }).join('') + '</div></div>';
	}
	function modalHTML() {
		var it = item(U.sel);
		if (S().modal === 'f-del' && it && it.ext) {
			return '<div class="modal-back" data-act="modalclose" data-self="1"><div class="modal" role="dialog" aria-modal="true" aria-label="' + esc(T('f_del_title', it.title)) + '"><div class="mh"><h3>' + esc(T('f_del_title', it.title)) + '</h3><p>' + esc(T('f_delete_hint')) + '</p></div>' +
				'<div class="mb"><div class="note">' + ic('info', 14) + '<span>' + esc(T('f_del_keep_values')) + '</span></div></div>' +
				'<div class="mf"><button type="button" class="btn" data-act="fhide">' + esc(T('f_del_hide_instead')) + '</button><button type="button" class="btn danger" data-act="fdelgo">' + ic('trash', 15) + esc(T('f_del_go')) + '</button></div></div></div>';
		}
		return '';
	}

	/* ── 저장 ─────────────────────────── */
	function formPayload(list) {
		return list.map(function (x) {
			var r = { name: x.name, use: !!x.use, required: !!x.required, public: !!x.public };
			if (x.title_raw !== undefined) r.title_raw = x.title_raw;
			if (x.image) r.image = x.image;
			return r;
		});
	}
	function saveKeys(changes, label, toastTitle, toastSub) {
		K.busy(true);
		return K.api('member.procMemberAdminSaveConfigKeys', { zmc_values: JSON.stringify(changes) }).then(function (d) {
			K.busy(false);
			var prev = d.previous || {};
			if (prev.signup_form) prev.signup_form = formPayload(prev.signup_form);
			K.pushUndo(label, function () { return K.api('member.procMemberAdminSaveConfigKeys', { zmc_values: JSON.stringify(prev) }); });
			applyValues(d.values || {});
			if (toastTitle) K.toast(toastTitle, toastSub || '', T('undo'));
			K.render();
			loadPreview();
			return d;
		}, function (e) { K.busy(false); K.failed(e); K.render(); throw e; });
	}
	function saveForm(list, label, toastTitle, toastSub) {
		return saveKeys({ signup_form: formPayload(list) }, label, toastTitle, toastSub);
	}
	function changed(name, patch) {
		return items().map(function (x) {
			if (x.name !== name) return x;
			var c = $.extend(true, {}, x);
			Object.keys(patch).forEach(function (k) { c[k] = patch[k]; });
			return c;
		});
	}
	function toggleUse() {
		var it = item(U.sel);
		if (!it || it.lock) return;
		var on = !it.use;
		saveForm(changed(it.name, { use: on, required: on ? it.required : false, public: on ? it.public : it.public }), T('f_undo_item', it.title), T(on ? 'f_toast_use_on' : 'f_toast_use_off', it.title), on ? '' : T('f_toast_use_off_sub')).then(null, function () { });
	}
	function toggleReq() {
		var it = item(U.sel);
		if (!it || it.lock || !it.use) return;
		saveForm(changed(it.name, { required: !it.required }), T('f_undo_item', it.title), T(!it.required ? 'f_toast_req_on' : 'f_toast_req_off', it.title), !it.required ? T('f_toast_req_on_sub') : '').then(null, function () { });
	}
	function togglePub() {
		var it = item(U.sel);
		if (!it || !it.use || it.name === 'nick_name' || PRIVATE.indexOf(it.name) >= 0) return;
		var on = !isPublic(it);
		saveForm(changed(it.name, { public: on }), T('f_undo_item', it.title), T(on ? 'f_toast_pub_on' : 'f_toast_pub_off', it.title), '').then(null, function () { });
	}
	function saveTitle(value) {
		var it = item(U.sel);
		value = String(value || '').trim();
		if (!it) return;
		if (it.ext) {
			if (!value || value === it.column_title) return;
			saveExtra(it, { column_title: value }, T('f_toast_renamed', it.title));
			return;
		}
		if (it.title_raw === undefined || value === it.title_raw) return;
		saveForm(changed(it.name, { title_raw: value }), T('f_undo_item', it.title), T('f_toast_renamed', it.title), '').then(null, function () { });
	}
	function saveImage(field, value) {
		var it = item(U.sel);
		if (!it || !it.image) return;
		value = String(value || '').replace(/[^0-9]/g, '');
		if (value === String(it.image[field])) return;
		var image = $.extend({}, it.image);
		image[field] = value;
		saveForm(changed(it.name, { image: image }), T('f_undo_item', it.title), T('f_toast_saved', it.title), '').then(null, function () { });
	}
	function saveSetting(key, value, label) {
		if (String(F.values[key]) === String(value)) return;
		var c = {};
		c[key] = value;
		saveKeys(c, label, T('f_toast_setting', label), '').then(null, function () { });
	}
	function extArgs(it, patch) {
		var a = {
			member_join_form_srl: it.srl,
			column_type: it.column_type,
			column_id: it.column_name,
			column_title: it.column_title,
			default_value: it.default_value,
			options: (it.options || []).join('\n'),
			description: it.description,
			required: it.required ? 'Y' : 'N'
		};
		Object.keys(patch || {}).forEach(function (k) { a[k] = patch[k]; });
		return a;
	}
	function saveExtra(it, patch, toastTitle) {
		var before = extArgs(it), after = extArgs(it, patch);
		var keepUse = it.use, name = it.name;
		var newName = String(after.column_id || '').toLowerCase();
		K.busy(true);
		return K.api('member.procMemberAdminInsertJoinForm', after).then(function () {
			if (keepUse) return null;
			return K.api('member.getMemberAdminSettings', {}).then(function (d) {
				var list = (d.values && d.values.signup_form) || [];
				list = list.map(function (x) { return x.name === newName ? $.extend({}, x, { use: false, required: false }) : x; });
				return K.api('member.procMemberAdminSaveConfigKeys', { zmc_values: JSON.stringify({ signup_form: formPayload(list) }) });
			});
		}).then(function () {
			K.busy(false);
			K.pushUndo(T('f_undo_item', it.title), function () {
				return K.api('member.procMemberAdminInsertJoinForm', before).then(function () {
					if (keepUse) return null;
					return K.api('member.getMemberAdminSettings', {}).then(function (d) {
						var list = ((d.values && d.values.signup_form) || []).map(function (x) { return x.name === name ? $.extend({}, x, { use: false, required: false }) : x; });
						return K.api('member.procMemberAdminSaveConfigKeys', { zmc_values: JSON.stringify({ signup_form: formPayload(list) }) });
					});
				});
			});
			K.toast(toastTitle, '', T('undo'));
			return reload(newName || name);
		}, function (e) { K.busy(false); K.failed(e); K.render(); });
	}
	function nextColumnName() {
		var used = {};
		items().forEach(function (x) { used[String(x.name).toLowerCase()] = true; if (x.column_name) used[String(x.column_name).toLowerCase()] = true; });
		for (var n = 1; n < 10000; n++) { if (!used['field_' + n]) return 'field_' + n; }
		return 'field_' + Date.now();
	}
	function addItem(type) {
		S().pop = null;
		var name = nextColumnName();
		var title = T('f_new_item');
		K.busy(true);
		K.api('member.procMemberAdminInsertJoinForm', { column_type: type, column_id: name, column_title: title, default_value: '', options: '', description: '', required: 'N' }).then(function () {
			K.busy(false);
			K.pushUndo(T('f_undo_add'), function () {
				return K.api('member.getMemberAdminSettings', {}).then(function (d) {
					var list = (d.values && d.values.signup_form) || [];
					var found = list.filter(function (x) { return x.name === name; })[0];
					return found ? K.api('member.procMemberAdminDeleteJoinForm', { member_join_form_srl: found.srl }) : null;
				});
			});
			K.toast(T('f_toast_added', typeLabel(type)), T('f_toast_added_sub'), T('undo'));
			U.pane = 'panel';
			return reload(name).then(function () {
				var input = document.getElementById('lang_zmc-ftitle') || document.getElementById('zmc-ftitle');
				if (input) { input.focus(); try { input.select(); } catch (e) { } }
			});
		}, function (e) { K.busy(false); K.failed(e); K.render(); });
	}
	function deleteItem() {
		var it = item(U.sel);
		if (!it || !it.ext) return;
		S().modal = null;
		K.renderLayer();
		var snapshot = formPayload(items());
		var args = extArgs(it);
		delete args.member_join_form_srl;
		K.busy(true);
		K.api('member.procMemberAdminDeleteJoinForm', { member_join_form_srl: it.srl }).then(function () {
			K.busy(false);
			K.pushUndo(T('f_undo_delete', it.title), function () {
				return K.api('member.procMemberAdminInsertJoinForm', args).then(function () {
					return K.api('member.procMemberAdminSaveConfigKeys', { zmc_values: JSON.stringify({ signup_form: snapshot }) });
				});
			});
			K.toast(T('f_toast_deleted', it.title), T('f_toast_deleted_sub'), T('undo'));
			U.sel = '';
			U.pane = 'list';
			reload('');
		}, function (e) { K.busy(false); K.failed(e); });
	}
	function saveChoices(list) {
		var it = item(U.sel);
		if (!it) return;
		var clean = list.map(function (x) { return String(x).replace(/\n/g, ' ').trim(); }).filter(function (x) { return x !== ''; });
		if (clean.join('\n') === (it.options || []).join('\n')) return;
		saveExtra(it, { options: clean.join('\n') }, T('f_toast_choices', it.title));
	}
	function currentChoices() {
		var box = document.getElementById('zmc-fchoices');
		if (!box) return (item(U.sel) || {}).options || [];
		return Array.prototype.map.call(box.querySelectorAll('input[data-choice]'), function (x) { return x.value; });
	}

	/* ── 순서 ─────────────────────────── */
	function reorder(name, targetName, after) {
		var list = items().slice();
		var it = item(name);
		if (!it || name === targetName) return;
		var group = list.filter(function (x) { return x.ext === it.ext; });
		var slots = [];
		list.forEach(function (x, i) { if (x.ext === it.ext) slots.push(i); });
		var names = group.map(function (x) { return x.name; }).filter(function (n) { return n !== name; });
		var at = names.indexOf(targetName);
		if (at < 0) at = names.length; else if (after) at++;
		names.splice(at, 0, name);
		var byName = {};
		group.forEach(function (x) { byName[x.name] = x; });
		names.forEach(function (n, i) { list[slots[i]] = byName[n]; });
		if (list.map(function (x) { return x.name; }).join(',') === items().map(function (x) { return x.name; }).join(',')) { K.render(); return; }
		F.values.signup_form = list;
		K.render();
		saveForm(list, T('f_undo_order', it.title), T('f_toast_order'), T('f_toast_order_sub')).then(null, function () { reload(U.sel); });
	}
	function onPointerDown(e) {
		var grip = e.target.closest('#zmc-flist .grip[data-grip]');
		if (!grip || e.button !== 0) return;
		e.preventDefault();
		var row = grip.closest('.frow');
		U.drag = { name: grip.getAttribute('data-v'), ext: row.getAttribute('data-ext'), y: e.clientY, moved: false, target: null, after: false };
		document.addEventListener('pointermove', onPointerMove);
		document.addEventListener('pointerup', onPointerUp);
	}
	function onPointerMove(e) {
		var d = U.drag;
		if (!d) return;
		if (!d.moved && Math.abs(e.clientY - d.y) < 4) return;
		d.moved = true;
		var rows = Array.prototype.slice.call(document.querySelectorAll('#zmc-flist .frow[data-ext="' + d.ext + '"]'));
		rows.forEach(function (r) { r.classList.remove('drop-before', 'drop-after'); r.classList.toggle('drag-src', r.getAttribute('data-name') === d.name); });
		var target = null, after = false;
		for (var i = 0; i < rows.length; i++) {
			var r = rows[i].getBoundingClientRect();
			if (e.clientY < r.top + r.height / 2) { target = rows[i]; break; }
		}
		if (!target && rows.length) { target = rows[rows.length - 1]; after = true; }
		d.target = target ? target.getAttribute('data-name') : null;
		d.after = after;
		if (target) target.classList.add(after ? 'drop-after' : 'drop-before');
	}
	function onPointerUp() {
		document.removeEventListener('pointermove', onPointerMove);
		document.removeEventListener('pointerup', onPointerUp);
		var d = U.drag;
		U.drag = null;
		if (!d || !d.moved || !d.target) { K.render(); return; }
		reorder(d.name, d.target, d.after);
	}
	function moveBy(name, delta) {
		var it = item(name);
		if (!it) return;
		var group = items().filter(function (x) { return x.ext === it.ext; }).map(function (x) { return x.name; });
		var i = group.indexOf(name), j = i + delta;
		if (i < 0 || j < 0 || j >= group.length) return;
		reorder(name, group[j], delta > 0);
		setTimeout(function () { var b = document.querySelector('#zmc-flist .grip[data-v="' + name + '"]'); if (b) b.focus(); }, 0);
	}

	/* ── 입력 ─────────────────────────── */
	function click(e, act, v) {
		var it = item(U.sel);
		switch (act) {
			case 'fsel':
				if (e.target.closest('.grip')) return;
				if (U.sel !== v) { U.sel = v; U.adv = false; }
				U.pane = 'panel';
				K.render();
				highlight();
				return;
			case 'fback': U.pane = 'list'; K.render(); return;
			case 'fpv': U.pane = 'preview'; K.render(); return;
			case 'fpvx': U.pane = 'list'; K.render(); return;
			case 'fdev': if (U.device !== v) { U.device = v; K.render(); loadPreview(); } return;
			case 'fpvreload': loadPreview(); return;
			case 'fuse': toggleUse(); return;
			case 'freq': toggleReq(); return;
			case 'fpub': togglePub(); return;
			case 'fratio': saveSetting('profile_image_force_ratio', F.values.profile_image_force_ratio === 'Y' ? 'N' : 'Y', T('f_image_ratio')); return;
			case 'fphide': saveSetting('phone_number_hide_country', F.values.phone_number_hide_country === 'Y' ? 'N' : 'Y', T('f_phone_hide')); return;
			case 'fpdup': saveSetting('phone_number_allow_duplicate', F.values.phone_number_allow_duplicate === 'Y' ? 'N' : 'Y', T('f_phone_dup')); return;
			case 'fpsms': saveSetting('phone_number_verify_by_sms', F.values.phone_number_verify_by_sms === 'Y' ? 'N' : 'Y', T('f_phone_sms')); return;
			case 'fsightml': saveSetting('signature_html', F.values.signature_html === 'Y' ? 'N' : 'Y', T('f_sig_html')); return;
			case 'fsigretro': saveSetting('signature_html_retroact', F.values.signature_html_retroact === 'Y' ? 'N' : 'Y', T('f_sig_retro')); return;
			case 'fsigfile': saveSetting('member_allow_fileupload', F.values.member_allow_fileupload === 'Y' ? 'N' : 'Y', T('f_sig_file')); return;
			case 'faddgo': addItem(v); return;
			case 'fdelask': S().modal = 'f-del'; K.renderLayer(); return;
			case 'fdelgo': deleteItem(); return;
			case 'fhide':
				S().modal = null;
				K.renderLayer();
				if (it && it.use) toggleUse();
				return;
			case 'fadv': U.adv = !U.adv; K.render(); return;
			case 'fchadd': {
				var list = currentChoices();
				list.push(T('f_choice_new', list.length + 1));
				saveChoices(list);
				return;
			}
			case 'fchdel': {
				var l2 = currentChoices();
				l2.splice(parseInt(v, 10), 1);
				if (it) saveExtra(it, { options: l2.map(function (x) { return String(x).trim(); }).filter(Boolean).join('\n') }, T('f_toast_choices', it.title));
				return;
			}
			case 'fchup': {
				var l3 = currentChoices(), i = parseInt(v, 10);
				if (i > 0) { var tmp = l3[i - 1]; l3[i - 1] = l3[i]; l3[i] = tmp; saveChoices(l3); }
				return;
			}
		}
	}
	function change(e) {
		var t = e.target, it = item(U.sel);
		if (!it) return;
		var id = t.id;
		if (id === 'zmc-ftitle' || id === 'lang_zmc-ftitle') {
			setTimeout(function () { var hidden = document.getElementById('zmc-ftitle'); if (hidden) saveTitle(hidden.value); }, 0);
			return;
		}
		if (id === 'zmc-fimg-w') { saveImage('max_width', t.value); return; }
		if (id === 'zmc-fimg-h') { saveImage('max_height', t.value); return; }
		if (id === 'zmc-fimg-f') { saveImage('max_filesize', t.value); return; }
		if (id === 'zmc-fcountry') { saveSetting('phone_number_default_country', t.value, T('f_phone_country')); return; }
		if (id === 'zmc-fsigskin') { saveSetting('signature_editor_skin', t.value, T('f_sig_editor')); return; }
		if (id === 'zmc-fsigh') { saveSetting('signature_max_height', String(t.value).replace(/[^0-9]/g, '') || '0', T('f_sig_height')); return; }
		if (id === 'zmc-fsigfs') { saveSetting('member_max_filesize', String(t.value).replace(/[^0-9]/g, '') || '0', T('f_sig_filesize')); return; }
		if (id === 'zmc-ftype' && it.ext) {
			saveExtra(it, { column_type: t.value }, T('f_toast_type', it.title, typeLabel(t.value)));
			return;
		}
		if (id === 'zmc-fdefault' && it.ext) { if (t.value !== it.default_value) saveExtra(it, { default_value: t.value.trim() }, T('f_toast_saved', it.title)); return; }
		if (id === 'zmc-fdesc' && it.ext) { if (t.value !== it.description) saveExtra(it, { description: t.value.trim() }, T('f_toast_saved', it.title)); return; }
		if (id === 'zmc-fcol' && it.ext) {
			var col = t.value.trim().toLowerCase();
			if (col === it.column_name) return;
			if (!/^[a-z][a-z0-9_]{0,59}$/.test(col)) { K.toast(T('f_colname_bad'), T('f_colname_hint'), null, true); t.value = it.column_name; return; }
			saveExtra(it, { column_id: col }, T('f_toast_colname', it.title));
			return;
		}
		if (t.hasAttribute('data-choice')) { saveChoices(currentChoices()); }
	}
	function key(e) {
		var t = e.target;
		if (e.key === 'Enter' && t && t.tagName === 'INPUT' && t.id !== 'zmc-q' && K.ROOT.contains(t) && !e.isComposing && t.closest('.panel')) {
			e.preventDefault();
			t.blur();
			return true;
		}
		if ((e.key === 'ArrowUp' || e.key === 'ArrowDown') && t && t.classList && t.classList.contains('grip') && t.closest('#zmc-flist')) {
			e.preventDefault();
			moveBy(t.getAttribute('data-v'), e.key === 'ArrowUp' ? -1 : 1);
			return true;
		}
		return false;
	}

	var VIEW = {
		tour: [
			{ sel: '#zmc-flist', msel: '#zmc-flist', t: 'f_tour_1_t', d: 'f_tour_1_d' },
			{ sel: '.fpreview', msel: '.fprev-btn', t: 'f_tour_2_t', d: 'f_tour_2_d', md: 'f_tour_2_md' },
			{ sel: '.v-form .panel', msel: '.edbar .savestate', t: 'f_tour_3_t', d: 'f_tour_3_d', md: 'f_tour_3_md' }
		],
		init: function (kit) {
			K = kit;
			T = K.T; esc = K.esc; ic = K.ic;
			K.icons.grip = '<circle cx="9" cy="6" r="1.3" fill="currentColor"/><circle cx="15" cy="6" r="1.3" fill="currentColor"/><circle cx="9" cy="12" r="1.3" fill="currentColor"/><circle cx="15" cy="12" r="1.3" fill="currentColor"/><circle cx="9" cy="18" r="1.3" fill="currentColor"/><circle cx="15" cy="18" r="1.3" fill="currentColor"/>';
			K.icons.globe = '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>';
			K.icons.eye = '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>';
			K.icons.reload = '<path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 4v7h-7"/>';
			K.icons.up = '<path d="M6 15l6-6 6 6"/>';
			var form = K.B.form || {};
			F.values = form.values || {};
			F.meta = form.meta || {};
			var init = K.B.initial || {};
			var want = '';
			if (init.join_form) {
				items().forEach(function (x) { if (x.ext && x.srl === parseInt(init.join_form, 10)) want = x.name; });
			}
			U.sel = want || (items().length ? items()[0].name : '');
			if (want) U.pane = 'panel';
			$(K.ROOT).on('selected.g11n', function () { setTimeout(function () { var hidden = document.getElementById('zmc-ftitle'); if (hidden) saveTitle(hidden.value); }, 0); });
			K.ROOT.addEventListener('pointerdown', onPointerDown);
		},
		start: function () { loadPreview(); },
		refresh: function () { reload(U.sel); },
		onTour: function () { U.pane = 'list'; },
		html: html,
		beforeRender: beforeRender,
		afterRender: afterRender,
		patch: patch,
		popHTML: popHTML,
		modalHTML: modalHTML,
		click: click,
		change: change,
		key: key
	};
	window.ZMC_VIEWS = window.ZMC_VIEWS || {};
	window.ZMC_VIEWS.form = VIEW;
})(jQuery);
