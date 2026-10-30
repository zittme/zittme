/**
 * Member administration: member settings
 *
 * Settings are shown by purpose. Each change is saved right away through
 * member.procMemberAdminSaveConfigKeys, which runs the procedure of the old
 * settings tab with every other value of that tab unchanged.
 */
(function ($) {
	'use strict';

	var K, T, esc, ic;
	var V = {}, M = {};
	var U = { cat: 'join', q: '', joinKey: false, modal: null, list: null, agree: 0, agreeDraft: null, mid: '', nick: null, scroll: 0, stash: null, anchor: '' };
	var CATS = [['join', 'userplus'], ['login', 'key'], ['social', 'globe'], ['terms', 'doc'], ['profile', 'user'], ['features', 'menu'], ['design', 'palette'], ['adv', 'gear']];

	function S() { return K.state(); }
	function L(key) { return K.L[key] !== undefined ? T(key) : ''; }
	function sv(id, html) { return html; }

	/* ── 조각 ─────────────────────────── */
	function srow(id, ctrl, extra, opts) {
		opts = opts || {};
		var title = opts.title !== undefined ? opts.title : esc(T('s_' + id));
		var desc = opts.desc !== undefined ? opts.desc : esc(L('s_' + id + '_d'));
		return '<div class="srow' + (opts.cls ? ' ' + opts.cls : '') + '" data-row="' + id + '"' + (opts.anchor ? ' id="zmc-s-' + opts.anchor + '"' : '') + '><div class="st"><b>' + title + '</b>' + (desc ? '<small>' + desc + '</small>' : '') + (extra || '') + '</div>' + (ctrl ? '<div class="sv">' + ctrl + '</div>' : '') + '</div>';
	}
	function swc(key, label, dis) {
		var on = V[key] === 'Y';
		return '<button type="button" class="switch' + (on ? ' on' : '') + '" role="switch" aria-checked="' + on + '" aria-label="' + esc(label || T('s_' + key)) + '" data-act="stog" data-k="' + key + '"' + (dis ? ' disabled' : '') + '></button>';
	}
	function selc(key, opts, label, value) {
		value = value === undefined ? V[key] : value;
		return '<select class="sel" data-k="' + key + '" aria-label="' + esc(label || T('s_' + key)) + '">' + opts.map(function (o) {
			return '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(value) ? ' selected' : '') + (o[2] ? ' disabled' : '') + '>' + esc(o[1]) + '</option>';
		}).join('') + '</select>';
	}
	function txt(key, opts) {
		opts = opts || {};
		return '<input class="inp' + (opts.cls ? ' ' + opts.cls : '') + '" id="zmc-s-' + key + '" data-k="' + key + '" value="' + esc(V[key]) + '" aria-label="' + esc(opts.label || T('s_' + key)) + '"' + (opts.ph ? ' placeholder="' + esc(opts.ph) + '"' : '') + (opts.type ? ' type="' + opts.type + '"' : '') + ' autocomplete="off" spellcheck="false">';
	}
	function num(key, unit, label) {
		return '<span class="inrow"><input class="inp num" id="zmc-s-' + key + '" data-k="' + key + '" data-num="1" inputmode="numeric" value="' + esc(V[key]) + '" aria-label="' + esc(label || T('s_' + key)) + '">' + (unit ? '<span class="unit">' + esc(unit) + '</span>' : '') + '</span>';
	}
	function sub(html) { return '<div class="ssub">' + html + '</div>'; }
	function readonly(value, label) {
		return '<span class="copyrow"><input class="inp" readonly value="' + esc(value) + '" aria-label="' + esc(label) + '"><button type="button" class="btn" data-act="scopy" data-v="' + esc(value) + '">' + esc(T('s_copy')) + '</button></span>';
	}
	function allNote() {
		return '<div class="note snote">' + ic('globe', 14) + '<span>' + T('s_all_sites_note', K.term('all_sites', T('s_all_sites'))) + '</span></div>';
	}

	/* ── 칸 ─────────────────────────── */
	var R = {};
	R.enable_join = function () {
		var mode = U.joinKey ? 'KEY' : V.enable_join;
		var extra = '';
		if (mode === 'KEY') {
			extra = sub('<div class="field"><label for="zmc-s-enable_join_key">' + esc(T('s_enable_join_key')) + '</label>' + txt('enable_join_key', { ph: T('s_enable_join_key_ph') }) + '<div class="hint">' + esc(T('s_enable_join_key_d', M.signup_url || '')) + '</div></div>');
		}
		return srow('enable_join', selc('enable_join', [['Y', T('s_join_Y')], ['N', T('s_join_N')], ['KEY', T('s_join_KEY')]], '', mode), extra);
	};
	R.enable_confirm = function () {
		var unit = [[86400, T('s_unit_day')], [3600, T('s_unit_hour')], [60, T('s_unit_min')], [1, T('s_unit_sec')]];
		var extra = sub('<div class="inline">' + esc(T('s_authmail_expires')) + ' ' + num('authmail_expires', '', T('s_authmail_expires')) + selc('authmail_expires_unit', unit, T('s_authmail_expires_unit')) + '</div>');
		return srow('enable_confirm', swc('enable_confirm'), extra, { title: K.term('mail_auth', T('s_enable_confirm')) });
	};
	R.def_group = function () {
		var defs = K.GROUPS.filter(function (g) { return g.def; });
		return srow('def_group', defs.map(function (g) { return K.gtag(g.id); }).join('') + '<a class="linkbtn" href="' + esc(K.B.urls.groups) + '">' + esc(T('s_def_group_link')) + '</a>', '', { title: K.term('def_group', T('s_def_group')) });
	};
	R.limit_day = function () {
		var extra = sub('<div class="field"><label for="zmc-s-limit_day_description">' + esc(T('s_limit_day_description')) + '</label>' + txt('limit_day_description', { cls: 'lang_code', ph: T('s_limit_day_description_ph') }) + '</div>');
		return srow('limit_day', num('limit_day', T('s_unit_days')), extra);
	};
	R.redirect_url = function () { return srow('redirect_url', txt('redirect_url', { cls: 'w260', ph: T('s_redirect_url_ph') })); };
	R.email_hosts = function () {
		var n = (M.lists.email_hosts || []).length;
		var extra = sub('<div class="inline">' + selc('emailhost_check', [['allowed', T('s_emailhost_allowed')], ['prohibited', T('s_emailhost_prohibited')]], T('s_emailhost_check')) + '</div><div class="hint">' + esc(T(V.emailhost_check === 'prohibited' ? 's_emailhost_prohibited_d' : 's_emailhost_allowed_d')) + '</div>');
		return srow('email_hosts', '<button type="button" class="btn" data-act="slist" data-v="email_hosts">' + esc(n ? T('s_list_edit', K.fmt(n)) : T('s_list_add')) + '</button>', extra);
	};
	R.denied_ids = function () {
		if (!M.lists.use_user_id) return '';
		var n = (M.lists.denied_ids || []).length;
		return srow('denied_ids', '<button type="button" class="btn" data-act="slist" data-v="denied_ids">' + esc(n ? T('s_list_edit', K.fmt(n)) : T('s_list_add')) + '</button>');
	};
	R.denied_nicks = function () {
		var n = (M.lists.denied_nicks || []).length;
		return srow('denied_nicks', '<button type="button" class="btn" data-act="slist" data-v="denied_nicks">' + esc(n ? T('s_list_edit', K.fmt(n)) : T('s_list_add')) + '</button>');
	};
	R.special_phone = function () {
		var extra = sub('<div class="row2f"><div class="field"><label for="zmc-s-special_phone_number">' + esc(T('s_special_phone_number')) + '</label>' + txt('special_phone_number', { ph: '01000000000' }) + '</div><div class="field"><label for="zmc-s-special_phone_code">' + esc(T('s_special_phone_code')) + '</label>' + txt('special_phone_code', { ph: '000000' }) + '</div></div>');
		return srow('special_phone', '', extra);
	};
	R.sms_limit = function () {
		return srow('sms_limit', '<span class="inline">' + num('max_auth_sms_count', T('s_unit_times'), T('s_max_auth_sms_count')) + '<span class="dim">/</span>' + num('max_auth_sms_count_time', T('s_unit_secs'), T('s_max_auth_sms_count_time')) + '</span>');
	};
	R.form_link = function () {
		return srow('form_link', '<a class="btn" href="' + esc(K.B.urls.signup) + '">' + ic('form', 15) + esc(T('s_form_link_go')) + '</a>', '', { title: K.term('signup_form', T('s_form_link')) });
	};

	R.identifiers = function () {
		var opts = [['user_id', T('s_id_user_id')], ['email_address', T('s_id_email_address')], ['phone_number', T('s_id_phone_number')]];
		var html = '<div class="checks">' + opts.map(function (o) {
			var on = (V.identifiers || []).indexOf(o[0]) >= 0;
			return '<button type="button" class="checkline" data-act="sident" data-v="' + o[0] + '" aria-pressed="' + on + '">' + K.cbox(on) + '<span>' + esc(o[1]) + '</span></button>';
		}).join('') + '</div>';
		return srow('identifiers', '', sub(html), { title: K.term('identifier', T('s_identifiers')) });
	};
	R.password_strength = function () {
		return srow('password_strength', selc('password_strength', [['low', T('s_pw_low')], ['normal', T('s_pw_normal')], ['high', T('s_pw_high')]]), '<div class="hint">' + esc(T('s_pw_' + V.password_strength + '_d')) + '</div>');
	};
	R.login_limit = function () {
		return srow('login_limit', '<span class="inline">' + num('max_error_count', T('s_unit_times'), T('s_max_error_count')) + '<span class="dim">/</span>' + num('max_error_count_time', T('s_unit_secs'), T('s_max_error_count_time')) + '</span>');
	};
	R.login_failure_except_ip = function () {
		return srow('login_failure_except_ip', '', sub('<textarea class="inp" id="zmc-s-login_failure_except_ip" data-k="login_failure_except_ip" rows="3" aria-label="' + esc(T('s_login_failure_except_ip')) + '" placeholder="' + esc(T('s_ip_ph')) + '">' + esc(V.login_failure_except_ip) + '</textarea>'));
	};
	['signup_dup_button', 'password_change_invalidate_other_sessions', 'login_invalidate_other_sessions', 'member_profile_view', 'allow_nickname_change', 'update_nickname_log', 'allow_duplicate_nickname', 'nickname_spaces', 'password_hashing_auto_upgrade', 'sns_login_enable', 'force_mid'].forEach(function (key) {
		R[key] = function () { return srow(key, swc(key)); };
	});
	R.change_password_date = function () { return srow('change_password_date', num('change_password_date', T('s_unit_days'))); };
	R.after_login_url = function () { return srow('after_login_url', txt('after_login_url', { cls: 'w260', ph: T('s_url_ph') })); };
	R.after_logout_url = function () { return srow('after_logout_url', txt('after_logout_url', { cls: 'w260', ph: T('s_url_ph') })); };

	function snsRow(service) {
		var on = V['sns_' + service + '_enable'] === 'Y';
		var ready = V[service + '_client_id'] && V[service + '_client_secret'];
		var desc = esc(on ? (ready ? T('s_sns_on') : T('s_sns_on_nokey')) : T('s_sns_off'));
		var extra = sub('<div class="row2f"><div class="field"><label for="zmc-s-' + service + '_client_id">' + esc(T('s_sns_client_id')) + '</label>' + txt(service + '_client_id', { label: T('s_sns_client_id') }) + '</div><div class="field"><label for="zmc-s-' + service + '_client_secret">' + esc(T('s_sns_client_secret')) + '</label>' + txt(service + '_client_secret', { label: T('s_sns_client_secret') }) + '</div></div>' +
			'<div class="field"><span class="flabel">' + esc(T('s_sns_callback')) + '</span>' + readonly((M.sns_callbacks || {})[service] || '', T('s_sns_callback')) + '<div class="hint">' + esc(T('s_sns_callback_d')) + '</div></div>');
		return srow('sns_' + service, swc('sns_' + service + '_enable', T('s_sns_' + service)), extra, { title: esc(T('s_sns_' + service)), desc: desc });
	}
	R.identity_enable = function () { return srow('identity_enable', swc('identity_enable'), '', { anchor: 'identity' }); };
	R.identity_provider = function () {
		return srow('identity_provider', selc('identity_provider', (M.providers || []).map(function (p) { return [p, L('s_provider_' + p) || p]; })));
	};
	['identity_dup_check', 'identity_signup_required', 'identity_find_account'].forEach(function (key) {
		R[key] = function () { return srow(key, swc(key)); };
	});
	R.identity_adult = function () {
		var mids = M.mids || [];
		var html = '<div class="field"><label for="zmc-s-identity_adult_age">' + esc(T('s_identity_adult_age')) + '</label>' + num('identity_adult_age', T('s_unit_age'), T('s_identity_adult_age')) + '</div>' +
			'<div class="field"><span class="flabel">' + esc(T('s_identity_adult_mids')) + '</span><div class="checks cols">' + (mids.length ? mids.map(function (m) {
				var on = (V.identity_adult_mids || []).indexOf(m.value) >= 0;
				return '<button type="button" class="checkline" data-act="smid" data-v="' + esc(m.value) + '" aria-pressed="' + on + '">' + K.cbox(on) + '<span>' + esc(m.label) + '</span></button>';
			}).join('') : '<span class="dim">' + esc(T('none')) + '</span>') + '</div></div>';
		return srow('identity_adult', '', sub(html));
	};
	R.identity_age_groups = function () {
		var map = {};
		(V.identity_age_groups || []).forEach(function (p) { map[p[0]] = p[1]; });
		var html = '<div class="agegroups">' + K.GROUPS.map(function (g) {
			var on = map[g.id] !== undefined;
			return '<div class="agrow"><button type="button" class="checkline" data-act="sage" data-v="' + g.id + '" aria-pressed="' + on + '">' + K.cbox(on) + '<span>' + esc(g.title) + '</span></button>' + (on ? '<span class="inrow"><input class="inp num" data-age="' + g.id + '" inputmode="numeric" value="' + esc(map[g.id]) + '" aria-label="' + esc(T('s_age_of', g.title)) + '"><span class="unit">' + esc(T('s_unit_age_over')) + '</span></span>' : '') + '</div>';
		}).join('') + '</div>';
		return srow('identity_age_groups', '', sub(html));
	};
	R.identity_danal = function () {
		var html = '<div class="row2f"><div class="field"><label for="zmc-s-identity_danal_cpid">CPID</label>' + txt('identity_danal_cpid', { label: 'CPID' }) + '</div><div class="field"><label for="zmc-s-identity_danal_cppwd">CPPWD</label>' + txt('identity_danal_cppwd', { label: 'CPPWD', type: 'password' }) + '</div></div>' +
			'<div class="field"><label>' + esc(T('s_charset')) + '</label>' + selc('identity_danal_charset', [['UTF-8', 'UTF-8'], ['EUC-KR', 'EUC-KR']], T('s_charset')) + '</div>' +
			'<div class="field"><span class="flabel">' + esc(T('s_identity_callback')) + '</span>' + readonly(M.identity_callback || '', T('s_identity_callback')) + '</div>';
		return srow('identity_danal', '', sub(html));
	};
	R.identity_truora = function () {
		if (!M.identity_webhook && (M.providers || []).indexOf('truora') < 0) return '';
		var secret = function (key) {
			var saved = V[key + '_saved'] === 'Y';
			return '<input class="inp" type="password" id="zmc-s-' + key + '" data-k="' + key + '" data-secret="1" value="" autocomplete="new-password" placeholder="' + esc(saved ? T('s_secret_saved') : '') + '" aria-label="' + esc(T('s_' + key)) + '">';
		};
		var html = '<div class="field"><label>' + esc(T('s_identity_truora_mode')) + '</label>' + selc('identity_truora_mode', [['live', T('s_truora_live')], ['mock', T('s_truora_mock')]], T('s_identity_truora_mode')) +
			(V.identity_truora_mode === 'mock' && V.identity_provider === 'truora' && V.identity_enable === 'Y' ? '<div class="note danger" style="margin-top:6px">' + ic('info', 14) + '<span>' + esc(T('s_truora_mock_warn')) + '</span></div>' : '') + '</div>' +
			'<div class="row2f"><div class="field"><label for="zmc-s-identity_truora_api_key">' + esc(T('s_identity_truora_api_key')) + '</label>' + secret('identity_truora_api_key') + '</div><div class="field"><label for="zmc-s-identity_truora_flow_id">' + esc(T('s_identity_truora_flow_id')) + '</label>' + txt('identity_truora_flow_id', { ph: 'IPF...' }) + '</div></div>' +
			'<div class="row2f"><div class="field"><label for="zmc-s-identity_truora_countries">' + esc(T('s_identity_truora_countries')) + '</label>' + txt('identity_truora_countries', { ph: 'MX,CO,PE,CL' }) + '</div><div class="field"><label for="zmc-s-identity_truora_ttl">' + esc(T('s_identity_truora_ttl')) + '</label>' + num('identity_truora_ttl', T('s_unit_mins')) + '</div></div>' +
			'<div class="field"><label for="zmc-s-identity_truora_webhook_secret">' + esc(T('s_identity_truora_webhook_secret')) + '</label>' + secret('identity_truora_webhook_secret') + '</div>' +
			(M.identity_webhook ? '<div class="field"><span class="flabel">' + esc(T('s_identity_webhook')) + '</span>' + readonly(M.identity_webhook, T('s_identity_webhook')) + '</div>' : '') +
			((V.identity_truora_api_key_saved === 'Y' || V.identity_truora_webhook_secret_saved === 'Y') ? '<button type="button" class="btn danger" data-act="smodal" data-v="s-clear">' + esc(T('s_truora_clear')) + '</button>' : '');
		return srow('identity_truora', '', sub(html));
	};

	R.identity_extra = function () {
		var list = V.identity_extra || [];
		return list.map(function (f) {
			var key = f.name, ctrl = '', extra = '';
			var title = esc(f.label || key);
			if (f.type === 'check') ctrl = swc(key, f.label || key);
			else if (f.type === 'choice') ctrl = selc(key, (f.options || []).map(function (o) { return [o[0], o[1]]; }), f.label || key);
			else if (f.type === 'checks') {
				extra = sub('<div class="checks cols">' + (f.options || []).map(function (o) {
					var on = (V[key] || []).indexOf(o[0]) >= 0;
					return '<button type="button" class="checkline" data-act="sextra" data-k="' + esc(key) + '" data-v="' + esc(o[0]) + '" aria-pressed="' + on + '">' + K.cbox(on) + '<span>' + esc(o[1]) + '</span></button>';
				}).join('') + '</div>');
			}
			else if (f.type === 'textarea') extra = sub('<textarea class="inp" id="zmc-s-' + esc(key) + '" data-k="' + esc(key) + '" rows="3" aria-label="' + title + '">' + esc(V[key]) + '</textarea>');
			else extra = sub(txt(key, { label: f.label || key, type: f.type === 'password' ? 'password' : '' }));
			return srow('x_' + key, ctrl, extra, { title: title, desc: esc(f.desc || '') });
		}).join('');
	};
	R.agreements = function () {
		var out = '';
		for (var i = 1; i <= 5; i++) {
			var a = V['agreement_' + i] || { title: '', content: '', type: 'disabled' };
			var title = a.title || T('s_agree_n', i);
			var desc = a.type === 'disabled' ? T('s_agree_off') : (a.type === 'required' ? T('s_agree_req') : T('s_agree_opt'));
			out += srow('agreement_' + i, selc('agreement_' + i, [['required', T('s_agree_type_required')], ['optional', T('s_agree_type_optional')], ['disabled', T('s_agree_type_disabled')]], T('s_agree_type', title), a.type) +
				'<button type="button" class="btn" data-act="sagree" data-v="' + i + '">' + esc(T('s_agree_edit')) + '</button>', '', { title: esc(title), desc: esc(desc + (a.content ? '' : ' · ' + T('s_agree_empty'))), cls: a.type === 'disabled' ? 'dimrow' : '' });
		}
		return out;
	};

	R.nickname_symbols = function () {
		var extra = V.nickname_symbols === 'LIST' ? sub('<div class="field"><label for="zmc-s-nickname_symbols_allowed_list">' + esc(T('s_nickname_symbols_allowed_list')) + '</label>' + txt('nickname_symbols_allowed_list', { ph: '_ - .' }) + '</div>') : '';
		return srow('nickname_symbols', selc('nickname_symbols', [['N', T('s_sym_N')], ['Y', T('s_sym_Y')], ['LIST', T('s_sym_LIST')]]), extra);
	};

	['scrapped_documents', 'saved_documents', 'my_documents', 'my_comments', 'active_logins'].forEach(function (key) {
		R['feat_' + key] = function () { return srow('feat_' + key, swc(key, T('s_feat_' + key)), '', { desc: esc(T(V[key] === 'Y' ? 's_feat_on' : 's_feat_off')) }); };
	});
	R.feat_nickname_log = function () {
		var off = V.update_nickname_log !== 'Y';
		return srow('feat_nickname_log', swc('nickname_log', T('s_feat_nickname_log'), off), '', { desc: esc(off ? T('s_feat_nick_needlog') : T(V.nickname_log === 'Y' ? 's_feat_on' : 's_feat_off')) });
	};

	R.member_mid = function () {
		return srow('member_mid', '', sub('<div class="midrow"><span class="baseurl">' + esc(M.site_url || '') + '</span><input class="inp" id="zmc-s-member_mid" data-k="member_mid" data-confirm="1" value="' + esc(V.member_mid) + '" aria-label="' + esc(T('s_member_mid')) + '" autocomplete="off" spellcheck="false"></div>'));
	};
	function layoutOpts(list, key) {
		var opts = [[0, T('s_layout_none')]];
		var cur = V[key];
		if (cur === -1) opts.push([-1, T('s_layout_site')]);
		if (cur === -2) opts.push([-2, T('s_layout_same_pc')]);
		(list || []).forEach(function (l) { opts.push([l.value, l.label]); });
		if (cur > 0 && !(list || []).some(function (l) { return l.value === cur; })) opts.push([cur, T('s_layout_missing')]);
		return opts;
	}
	function skinOpts(list, key) {
		var opts = (list || []).map(function (s) {
			if (s.value === '/USE_DEFAULT/') return [s.value, T('s_skin_default', s.default_of || '')];
			if (s.value === '/USE_RESPONSIVE/') return [s.value, T('s_skin_responsive')];
			return [s.value, s.label];
		});
		if (V[key] && !opts.some(function (o) { return o[0] === V[key]; })) opts.push([V[key], V[key]]);
		return opts;
	}
	R.layout_srl = function () { return srow('layout_srl', selc('layout_srl', layoutOpts(M.layouts, 'layout_srl'))); };
	R.mlayout_srl = function () { return srow('mlayout_srl', selc('mlayout_srl', layoutOpts(M.mlayouts, 'mlayout_srl'))); };
	R.skin = function () {
		var skin = (M.skins || []).filter(function (s) { return s.value === V.skin; })[0];
		var cs = skin ? skin.colorsets : [];
		var extra = cs && cs.length ? sub('<div class="inline">' + esc(T('s_colorset')) + ' ' + selc('colorset', cs.map(function (c) { return [c.name, c.title]; }), T('s_colorset'), V.colorset || '') + '</div>') : '';
		return srow('skin', selc('skin', skinOpts(M.skins, 'skin')), extra);
	};
	R.mskin = function () { return srow('mskin', selc('mskin', skinOpts(M.mskins, 'mskin'))); };

	R.password_hashing_algorithm = function () {
		return srow('password_hashing_algorithm', selc('password_hashing_algorithm', (M.password_algos || []).map(function (a) { return [a.value, a.label, !a.usable]; })));
	};
	R.password_hashing_work_factor = function () {
		var opts = [];
		for (var i = 4; i <= 16; i++) opts.push([i, (i < 10 ? '0' : '') + i]);
		return srow('password_hashing_work_factor', selc('password_hashing_work_factor', opts));
	};
	R.password_reset_method = function () {
		return srow('password_reset_method', selc('password_reset_method', [[2, T('s_reset_2')], [1, T('s_reset_1')]]));
	};
	R.member_sync = function () {
		return srow('member_sync', '<button type="button" class="btn" data-act="smodal" data-v="s-sync">' + esc(T('s_member_sync_go')) + '</button>');
	};
	R.nick_log = function () {
		var n = U.nick;
		var head = '<div class="nicksearch">' + '<select class="sel" id="zmc-nick-target" aria-label="' + esc(T('s_nick_target')) + '">' + [['after', T('s_nick_after')], ['before', T('s_nick_before')], ['user_id', T('s_nick_user_id')]].map(function (o) { return '<option value="' + o[0] + '"' + ((n && n.target) === o[0] ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('') + '</select>' +
			'<input class="inp" id="zmc-nick-q" value="' + esc(n ? n.keyword : '') + '" placeholder="' + esc(T('s_nick_ph')) + '" aria-label="' + esc(T('s_nick_ph')) + '"><button type="button" class="btn" data-act="snick">' + ic('search', 15) + esc(T('s_nick_find')) + '</button></div>';
		var body;
		if (!n || n.loading || !n.rows) body = '<p class="dim">' + esc(T('loading')) + '</p>';
		else if (!n.rows.length) body = '<p class="dim">' + esc(T('s_nick_empty')) + '</p>';
		else body = '<div class="list nicklist">' + n.rows.map(function (r) {
			return '<div class="li"><span class="lt"><b>' + esc(r.before) + ' → ' + esc(r.after) + '</b><small>' + esc([K.dateTime(r.date), r.user_id].filter(Boolean).join(' · ')) + '</small></span></div>';
		}).join('') + '</div>' + (n.total > 1 ? '<div class="pager"><button type="button" class="btn" data-act="snickpage" data-v="' + (n.page - 1) + '"' + (n.page > 1 ? '' : ' disabled') + '>' + ic('back', 14) + esc(T('page_prev')) + '</button><span class="pg">' + n.page + ' / ' + n.total + '</span><button type="button" class="btn" data-act="snickpage" data-v="' + (n.page + 1) + '"' + (n.page < n.total ? '' : ' disabled') + '>' + esc(T('page_next')) + ic('chevr', 14) + '</button></div>' : '');
		return srow('nick_log', '', sub(head + body), { anchor: 'nick_log' });
	};

	var ROWS = {
		join: [['card', ['enable_join', 'enable_confirm', 'signup_dup_button', 'def_group', 'limit_day', 'redirect_url', 'email_hosts', 'denied_ids', 'denied_nicks']], ['card', ['special_phone', 'sms_limit']], ['card', ['form_link']]],
		login: [['card', ['identifiers', 'password_strength', 'login_limit', 'login_failure_except_ip', 'password_change_invalidate_other_sessions', 'login_invalidate_other_sessions', 'change_password_date', 'after_login_url', 'after_logout_url']]],
		social: [['card', ['sns_login_enable', 'SNS']], ['title', 's_identity_title'], ['card', ['identity_enable', 'identity_provider', 'identity_dup_check', 'identity_signup_required', 'identity_find_account', 'identity_adult', 'identity_age_groups']], ['card', ['identity_danal']], ['card', ['identity_truora']], ['card', ['identity_extra']]],
		terms: [['card', ['agreements']]],
		profile: [['card', ['member_profile_view', 'allow_nickname_change', 'update_nickname_log', 'allow_duplicate_nickname', 'nickname_symbols', 'nickname_spaces']]],
		features: [['lead', 's_features_lead'], ['card', ['feat_scrapped_documents', 'feat_saved_documents', 'feat_my_documents', 'feat_my_comments', 'feat_active_logins', 'feat_nickname_log']]],
		design: [['card', ['member_mid', 'force_mid']], ['card', ['layout_srl', 'skin', 'mlayout_srl', 'mskin']]],
		adv: [['warn', 's_adv_warn'], ['card', ['password_hashing_algorithm', 'password_hashing_work_factor', 'password_hashing_auto_upgrade', 'password_reset_method', 'member_sync']], ['card', ['nick_log']]]
	};
	function rowHTML(id) {
		if (id === 'SNS') return (M.services || []).map(snsRow).join('');
		return R[id] ? R[id]() : '';
	}
	function catBody(cat) {
		var h = cat === 'design' ? '<div class="note snote">' + ic('palette', 14) + '<span>' + esc(T('s_design_note')) + '</span></div>' : (cat === 'adv' ? '' : allNote());
		ROWS[cat].forEach(function (block) {
			if (block[0] === 'card') {
				var inner = block[1].map(rowHTML).join('');
				if (inner) h += '<div class="card">' + inner + '</div>';
			} else if (block[0] === 'title') {
				h += '<h3 class="stitle">' + esc(T(block[1])) + '</h3>';
			} else if (block[0] === 'lead') {
				h += '<p class="lead">' + T(block[1], K.term('member_menu', T('s_member_menu'))) + '</p>';
			} else if (block[0] === 'warn') {
				h += '<div class="note warn snote">' + ic('info', 14) + '<span>' + esc(T(block[1])) + '</span></div>';
			}
		});
		return h;
	}

	/* ── 찾기 ─────────────────────────── */
	function searchIndex() {
		var list = [];
		CATS.forEach(function (c) {
			ROWS[c[0]].forEach(function (block) {
				if (block[0] !== 'card') return;
				block[1].forEach(function (id) {
					if (id === 'SNS') {
						(M.services || []).forEach(function (s) { list.push({ cat: c[0], id: 'SNS:' + s, text: [T('s_sns_' + s), T('s_sns_client_id'), L('s_sns_k')].join(' ') }); });
						return;
					}
					if (id === 'agreements') {
						for (var i = 1; i <= 5; i++) {
							var a = V['agreement_' + i] || {};
							list.push({ cat: c[0], id: 'AGREE:' + i, text: [a.title || T('s_agree_n', i), L('s_agreements_k')].join(' ') });
						}
						return;
					}
					list.push({ cat: c[0], id: id, text: [L('s_' + id), L('s_' + id + '_d'), L('s_' + id + '_k')].join(' ') });
				});
			});
		});
		return list;
	}
	function searchBody() {
		var q = U.q.trim().toLowerCase();
		var hits = searchIndex().filter(function (x) { return x.text.toLowerCase().indexOf(q) >= 0; });
		var h = '<h2>' + esc(T('s_found', U.q.trim(), K.fmt(hits.length))) + '</h2><p class="lead">' + esc(T('s_found_lead')) + '</p>';
		if (!hits.length) return h + '<div class="card"><p class="lead" style="padding:16px 0">' + esc(T('s_found_none')) + '</p></div>';
		var lastCat = '';
		var body = '';
		hits.forEach(function (x) {
			if (x.cat !== lastCat) {
				if (body) h += '<div class="card">' + body + '</div>';
				body = '';
				h += '<div class="usegroup">' + esc(T('s_cat_' + x.cat)) + '</div>';
				lastCat = x.cat;
			}
			if (x.id.indexOf('SNS:') === 0) body += snsRow(x.id.slice(4));
			else if (x.id.indexOf('AGREE:') === 0) body += agreeRowOnly(parseInt(x.id.slice(6), 10));
			else body += rowHTML(x.id);
		});
		if (body) h += '<div class="card">' + body + '</div>';
		return h;
	}
	function agreeRowOnly(i) {
		var tmp = document.createElement('div');
		tmp.innerHTML = R.agreements();
		var row = tmp.querySelector('[data-row="agreement_' + i + '"]');
		return row ? row.outerHTML : '';
	}

	/* ── 그리기 ─────────────────────────── */
	function html() {
		var nav = '<nav class="snav" aria-label="' + esc(T('s_nav')) + '"><label class="search findset">' + ic('search', 15) + '<input id="zmc-sq" type="search" autocomplete="off" placeholder="' + esc(T('s_find_ph')) + '" value="' + esc(U.q) + '" aria-label="' + esc(T('s_find')) + '"></label>' +
			CATS.map(function (c) { return '<button type="button" class="fl' + (c[0] === U.cat && !U.q ? ' on' : '') + '" data-act="scat" data-v="' + c[0] + '"' + (c[0] === U.cat && !U.q ? ' aria-current="true"' : '') + '><span class="ico">' + ic(c[1], 16) + '</span><span class="sl">' + esc(T('s_cat_' + c[0])) + '<small>' + esc(T('s_cat_' + c[0] + '_d')) + '</small></span></button>'; }).join('') + '</nav>';
		var body;
		if (U.q.trim()) {
			body = '<label class="search findset msearch">' + ic('search', 15) + '<input id="zmc-sq2" type="search" autocomplete="off" placeholder="' + esc(T('s_find_ph')) + '" value="' + esc(U.q) + '" aria-label="' + esc(T('s_find')) + '"></label>' + searchBody();
		} else {
			body = '<label class="search findset msearch">' + ic('search', 15) + '<input id="zmc-sq2" type="search" autocomplete="off" placeholder="' + esc(T('s_find_ph')) + '" value="" aria-label="' + esc(T('s_find')) + '"></label>' +
				'<div class="msnav">' + CATS.map(function (c) { return '<button type="button" class="fchip' + (c[0] === U.cat ? ' on' : '') + '" data-act="scat" data-v="' + c[0] + '">' + esc(T('s_cat_' + c[0])) + '</button>'; }).join('') + '</div>' +
				'<h2>' + esc(T('s_cat_' + U.cat)) + '</h2><p class="lead">' + esc(T('s_cat_' + U.cat + '_d')) + '</p>' + catBody(U.cat);
		}
		return '<div class="workspace v-settings">' + nav + '<section class="spage" id="zmc-spage"><div class="spage-in">' + body + '</div></section></div>';
	}
	function beforeRender() {
		var p = document.getElementById('zmc-spage');
		U.scroll = p ? p.scrollTop : 0;
		var a = document.activeElement;
		U.stash = (a && K.ROOT.contains(a) && a.hasAttribute && a.hasAttribute('data-k') && a.tagName !== 'SELECT') ? { id: a.id, value: a.value } : null;
	}
	function afterRender() {
		var p = document.getElementById('zmc-spage');
		if (p && U.scroll) p.scrollTop = U.scroll;
		var lc = document.getElementById('zmc-s-limit_day_description');
		if (lc && $.fn.xeApplyMultilingualUI) {
			try { $(lc).xeApplyMultilingualUI(); } catch (e) { }
		}
		var st = U.stash;
		U.stash = null;
		if (st && st.id) {
			var el = document.getElementById(st.id);
			if (el && !el.disabled) { el.value = st.value; el.focus(); }
		}
		if (U.anchor) {
			var target = document.getElementById('zmc-s-' + U.anchor);
			U.anchor = '';
			if (target && p) { p.scrollTop = Math.max(0, target.offsetTop - 16); target.classList.add('flash'); }
		}
	}

	/* ── 팝오버 · 창 ─────────────────────────── */
	function agreeContent() {
		var input = document.getElementById('zmc_agree_content');
		var content = input ? input.value : '';
		var frame = K.ROOT.querySelector('.agree-editor');
		try {
			var w = frame && frame.contentWindow;
			if (w && w.CKEDITOR && w.CKEDITOR.instances) {
				Object.keys(w.CKEDITOR.instances).forEach(function (k) { content = w.CKEDITOR.instances[k].getData(); });
			}
		} catch (e) { }
		return content;
	}
	function listOf(kind) { return (M.lists[kind] || []).slice(); }
	function modalHTML() {
		var md = S().modal;
		if (md === 's-list' && U.list) {
			var kind = U.list;
			var items = listOf(kind);
			return '<div class="modal-back" data-act="modalclose" data-self="1"><div class="modal" role="dialog" aria-modal="true" aria-label="' + esc(T('s_' + kind)) + '"><div class="mh"><h3>' + esc(T('s_' + kind)) + '</h3><p>' + esc(T('s_' + kind + '_d')) + '</p></div><div class="mb">' +
				(items.length ? '<div class="tags">' + items.map(function (x) { return '<span class="tagx">' + esc(x) + '<button type="button" data-act="slistdel" data-v="' + esc(x) + '" aria-label="' + esc(T('s_list_del', x)) + '">' + ic('x', 12) + '</button></span>'; }).join('') + '</div>' : '<p class="dim">' + esc(T('s_list_empty')) + '</p>') +
				'<div class="field" style="margin-top:12px"><label for="zmc-listadd">' + esc(T('s_list_add_label')) + '</label><textarea id="zmc-listadd" class="inp" rows="3" placeholder="' + esc(T('s_' + kind + '_ph')) + '"></textarea><div class="hint">' + esc(T('s_list_add_hint')) + '</div></div></div>' +
				'<div class="mf"><button type="button" class="btn" data-act="modalclose">' + esc(T('close')) + '</button><button type="button" class="btn primary" data-act="slistadd">' + esc(T('s_list_add_go')) + '</button></div></div></div>';
		}
		if (md === 's-agree' && U.agree) {
			var i = U.agree;
			var input = document.getElementById('zmc_agree_content');
			if (input) U.agreeDraft = { title: (document.getElementById('zmc-agree-title') || {}).value, content: agreeContent(), type: (document.getElementById('zmc-agree-type') || {}).value };
			var a = U.agreeDraft || V['agreement_' + i];
			return '<div class="modal-back" data-act="modalclose" data-self="1"><div class="modal wide" role="dialog" aria-modal="true" aria-label="' + esc(T('s_agree_edit_title', a.title || T('s_agree_n', i))) + '"><div class="mh"><h3>' + esc(T('s_agree_edit_title', a.title || T('s_agree_n', i))) + '</h3><p>' + esc(T('s_agree_edit_lead')) + '</p></div><div class="mb">' +
				'<div class="row2f"><div class="field"><label for="zmc-agree-title">' + esc(T('s_agree_title')) + '</label><input id="zmc-agree-title" class="inp" value="' + esc(a.title) + '" maxlength="200"></div><div class="field"><label for="zmc-agree-type">' + esc(T('s_agree_kind')) + '</label><select id="zmc-agree-type" class="sel">' + [['required', T('s_agree_type_required')], ['optional', T('s_agree_type_optional')], ['disabled', T('s_agree_type_disabled')]].map(function (o) { return '<option value="' + o[0] + '"' + (o[0] === a.type ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('') + '</select></div></div>' +
				'<div class="field"><span class="flabel">' + esc(T('s_agree_content')) + '</span><input type="hidden" id="zmc_agree_content" value="' + esc(a.content) + '"><iframe class="editor_iframe agree-editor" title="' + esc(T('s_agree_content')) + '" src="' + esc(K.B.urls.editor_frame + 'zmc_agree_content') + '"></iframe></div></div>' +
				'<div class="mf"><button type="button" class="btn" data-act="modalclose">' + esc(T('close')) + '</button><button type="button" class="btn primary" data-act="sagreego">' + esc(T('s_agree_save')) + '</button></div></div></div>';
		}
		if (md === 's-mid') {
			return '<div class="modal-back" data-act="modalclose" data-self="1"><div class="modal" role="dialog" aria-modal="true" aria-label="' + esc(T('s_mid_title')) + '"><div class="mh"><h3>' + esc(T('s_mid_title')) + '</h3><p>' + esc(T('s_mid_lead', V.member_mid, U.mid)) + '</p></div><div class="mb"><div class="note warn">' + ic('info', 14) + '<span>' + esc(T('s_mid_warn')) + '</span></div></div>' +
				'<div class="mf"><button type="button" class="btn" data-act="modalclose">' + esc(T('s_keep')) + '</button><button type="button" class="btn primary" data-act="smidgo">' + esc(T('s_mid_go')) + '</button></div></div></div>';
		}
		if (md === 's-sync') {
			return '<div class="modal-back" data-act="modalclose" data-self="1"><div class="modal" role="dialog" aria-modal="true" aria-label="' + esc(T('s_member_sync')) + '"><div class="mh"><h3>' + esc(T('s_member_sync')) + '</h3><p>' + esc(T('s_member_sync_d')) + '</p></div><div class="mb"><div class="note warn">' + ic('info', 14) + '<span>' + esc(T('s_sync_warn')) + '</span></div></div>' +
				'<div class="mf"><button type="button" class="btn" data-act="modalclose">' + esc(T('s_keep')) + '</button><button type="button" class="btn primary" data-act="ssyncgo">' + esc(T('s_member_sync_go')) + '</button></div></div></div>';
		}
		if (md === 's-clear') {
			return '<div class="modal-back" data-act="modalclose" data-self="1"><div class="modal" role="dialog" aria-modal="true" aria-label="' + esc(T('s_truora_clear')) + '"><div class="mh"><h3>' + esc(T('s_truora_clear')) + '</h3><p>' + esc(T('s_truora_clear_d')) + '</p></div>' +
				'<div class="mf"><button type="button" class="btn" data-act="modalclose">' + esc(T('s_keep')) + '</button><button type="button" class="btn danger" data-act="scleargo">' + esc(T('s_truora_clear_go')) + '</button></div></div></div>';
		}
		return '';
	}

	/* ── 저장 ─────────────────────────── */
	function save(changes, label, opts) {
		opts = opts || {};
		K.busy(true);
		return K.api('member.procMemberAdminSaveConfigKeys', { zmc_values: JSON.stringify(changes) }).then(function (d) {
			K.busy(false);
			V = d.values || V;
			if (d.lists) M.lists = d.lists;
			var prev = d.previous || {};
			var canUndo = !opts.noUndo && Object.keys(prev).length;
			if (canUndo) {
				if (prev.signup_form) delete prev.signup_form;
				K.pushUndo(label, function () { return K.api('member.procMemberAdminSaveConfigKeys', { zmc_values: JSON.stringify(prev) }); });
			}
			K.toast(opts.title || T('s_toast_saved', label), opts.sub || '', canUndo ? T('undo') : '');
			K.render();
			return d;
		}, function (e) { K.busy(false); K.failed(e); K.render(); });
	}
	function labelOf(key) {
		var xf = (V.identity_extra || []).filter(function (f) { return f.name === key; })[0];
		if (xf) return xf.label || T('s_identity_title');
		var map = { enable_join_key: 'enable_join', authmail_expires: 'enable_confirm', authmail_expires_unit: 'enable_confirm', limit_day_description: 'limit_day', emailhost_check: 'email_hosts',
			special_phone_number: 'special_phone', special_phone_code: 'special_phone', max_auth_sms_count: 'sms_limit', max_auth_sms_count_time: 'sms_limit', max_error_count: 'login_limit', max_error_count_time: 'login_limit',
			identity_adult_age: 'identity_adult', identity_danal_cpid: 'identity_danal', identity_danal_cppwd: 'identity_danal', identity_danal_charset: 'identity_danal', identity_truora_mode: 'identity_truora',
			identity_truora_api_key: 'identity_truora', identity_truora_flow_id: 'identity_truora', identity_truora_countries: 'identity_truora', identity_truora_ttl: 'identity_truora', identity_truora_webhook_secret: 'identity_truora',
			nickname_symbols_allowed_list: 'nickname_symbols', colorset: 'skin', nickname_log: 'feat_nickname_log', scrapped_documents: 'feat_scrapped_documents', saved_documents: 'feat_saved_documents',
			my_documents: 'feat_my_documents', my_comments: 'feat_my_comments', active_logins: 'feat_active_logins' };
		var ag = /^agreement_(\d)$/.exec(key);
		if (ag) return (V[key] && V[key].title) || T('s_agree_n', ag[1]);
		var m = /^([a-z]+)_client_(id|secret)$/.exec(key) || /^sns_([a-z]+)_enable$/.exec(key);
		if (m && key !== 'sns_login_enable') return T('s_sns_' + m[1]);
		return T('s_' + (map[key] || key));
	}
	function subFor(key, value) {
		if (key === 'enable_confirm' && value === 'Y') return T('s_confirm_on_sub', K.fmt(M.members || 0));
		if (key === 'password_hashing_algorithm') return T('s_algo_sub');
		if (key === 'password_strength') return T('s_pw_sub');
		if (key === 'identity_enable' && value === 'Y') return T('s_identity_on_sub');
		if (key === 'skin' || key === 'mskin' || key === 'layout_srl' || key === 'mlayout_srl') return T('s_design_sub');
		return '';
	}
	function saveOne(key, value) {
		var c = {};
		c[key] = value;
		var secret = key === 'identity_truora_api_key' || key === 'identity_truora_webhook_secret';
		return save(c, labelOf(key), { sub: subFor(key, value), noUndo: secret });
	}
	function toggle(key) {
		var on = V[key] !== 'Y';
		saveOne(key, on ? 'Y' : 'N');
	}
	function loadNick(page) {
		var n = U.nick || { page: 1, target: 'after', keyword: '' };
		n.page = page || 1;
		n.loading = true;
		U.nick = n;
		K.api('member.getMemberAdminNickLog', { page: n.page, search_target: n.target, search_keyword: n.keyword }).then(function (d) {
			n.loading = false;
			n.rows = d.rows || [];
			n.page = d.page || 1;
			n.total = d.total_page || 1;
			K.render();
		}, function (e) { n.loading = false; n.rows = []; n.total = 1; K.failed(e); K.render(); });
	}
	function listCall(kind, mode, value) {
		if (kind === 'denied_ids') {
			return mode === 'delete' ? K.api('member.procMemberAdminUpdateDeniedID', { user_id: value, mode: 'delete' }) : K.api('member.procMemberAdminInsertDeniedID', { user_id: value });
		}
		if (kind === 'denied_nicks') {
			return K.api('member.procMemberAdminUpdateDeniedNickName', { nick_name: value, mode: mode === 'delete' ? 'delete' : 'insert' });
		}
		return K.api('member.procMemberAdminUpdateManagedEmailHosts', { email_hosts: value, mode: mode === 'delete' ? 'delete' : 'insert' });
	}
	function reloadLists() {
		return K.api('member.getMemberAdminSettings', {}).then(function (d) {
			V = d.values || V;
			if (d.lists) M.lists = d.lists;
			K.render();
		});
	}
	function listAdd() {
		var kind = U.list;
		var raw = ((document.getElementById('zmc-listadd') || {}).value || '').split(/[\n,]+/).map(function (x) { return x.trim(); }).filter(Boolean);
		var have = listOf(kind);
		raw = raw.filter(function (x, i) { return raw.indexOf(x) === i && have.indexOf(kind === 'email_hosts' ? x.toLowerCase() : x) < 0; });
		if (!raw.length) return;
		var value = kind === 'email_hosts' ? raw.join('\n') : raw.join(',');
		K.busy(true);
		listCall(kind, 'insert', value).then(function () {
			K.busy(false);
			K.pushUndo(T('s_undo_list', T('s_' + kind)), function () {
				var chain = Promise.resolve();
				raw.forEach(function (x) { chain = chain.then(function () { return listCall(kind, 'delete', kind === 'email_hosts' ? x.toLowerCase() : x); }); });
				return chain;
			});
			K.toast(T('s_toast_list_add', K.fmt(raw.length)), T('s_' + kind), T('undo'));
			return reloadLists();
		}, function (e) { K.busy(false); K.failed(e); });
	}
	function listDel(value) {
		var kind = U.list;
		K.busy(true);
		listCall(kind, 'delete', value).then(function () {
			K.busy(false);
			K.pushUndo(T('s_undo_list', T('s_' + kind)), function () { return listCall(kind, 'insert', value); });
			K.toast(T('s_toast_list_del', value), T('s_' + kind), T('undo'));
			return reloadLists();
		}, function (e) { K.busy(false); K.failed(e); });
	}

	/* ── 입력 ─────────────────────────── */
	function click(e, act, v, el) {
		switch (act) {
			case 'scat':
				U.cat = v; U.q = '';
				K.render();
				var p = document.getElementById('zmc-spage');
				if (p) p.scrollTop = 0;
				syncUrl();
				if (v === 'adv' && !U.nick) loadNick(1);
				return;
			case 'stog': toggle(el.getAttribute('data-k')); return;
			case 'sident': {
				var list = (V.identifiers || []).slice(), i = list.indexOf(v);
				if (i >= 0) list.splice(i, 1); else list.push(v);
				if (!list.length) { K.toast(T('s_ident_need'), '', null, true); return; }
				saveOne('identifiers', list);
				return;
			}
			case 'smid': {
				var mids = (V.identity_adult_mids || []).slice(), j = mids.indexOf(v);
				if (j >= 0) mids.splice(j, 1); else mids.push(v);
				saveOne('identity_adult_mids', mids);
				return;
			}
			case 'sextra': {
				var xk = el.getAttribute('data-k');
				var xl = (V[xk] || []).slice(), xi = xl.indexOf(v);
				if (xi >= 0) xl.splice(xi, 1); else xl.push(v);
				saveOne(xk, xl);
				return;
			}
			case 'sage': {
				var id = parseInt(v, 10);
				var ages = (V.identity_age_groups || []).filter(function (x) { return x[0] !== id; });
				if (ages.length === (V.identity_age_groups || []).length) ages.push([id, 19]);
				saveOne('identity_age_groups', ages);
				return;
			}
			case 'slist': U.list = v; S().modal = 's-list'; K.renderLayer(); var ta = document.getElementById('zmc-listadd'); if (ta) ta.focus(); return;
			case 'slistadd': listAdd(); return;
			case 'slistdel': listDel(v); return;
			case 'sagree': U.agree = parseInt(v, 10); U.agreeDraft = null; S().modal = 's-agree'; K.renderLayer(); return;
			case 'sagreego': {
				var n = U.agree;
				var a = {
					title: (document.getElementById('zmc-agree-title') || {}).value || '',
					type: (document.getElementById('zmc-agree-type') || {}).value || 'disabled',
					content: agreeContent()
				};
				S().modal = null; U.agree = 0; U.agreeDraft = null;
				K.renderLayer();
				saveOne('agreement_' + n, a);
				return;
			}
			case 'smodal': S().modal = v; K.renderLayer(); return;
			case 'smidgo': S().modal = null; K.renderLayer(); saveOne('member_mid', U.mid); return;
			case 'ssyncgo':
				S().modal = null;
				K.renderLayer();
				K.busy(true);
				K.api('importer.procImporterAdminSync', {}).then(function (d) {
					K.busy(false);
					K.toast(T('s_sync_done'), d && d.message && d.message !== 'success' ? String(d.message) : '', null);
				}, function (er) { K.busy(false); K.failed(er); });
				return;
			case 'scleargo':
				S().modal = null;
				K.renderLayer();
				save({ identity_truora_clear_secrets: 'Y' }, T('s_identity_truora'), { noUndo: true, title: T('s_truora_cleared') });
				return;
			case 'scopy':
				try { navigator.clipboard.writeText(v); K.toast(T('s_copied'), v, null); } catch (er) { }
				return;
			case 'snick': {
				var n2 = U.nick || {};
				n2.target = (document.getElementById('zmc-nick-target') || {}).value || 'after';
				n2.keyword = ((document.getElementById('zmc-nick-q') || {}).value || '').trim();
				U.nick = n2;
				loadNick(1);
				return;
			}
			case 'snickpage': loadNick(parseInt(v, 10) || 1); return;
		}
	}
	function syncUrl() {
		if (!window.history || !history.replaceState) return;
		try {
			var u = new URL(location.href);
			u.searchParams.set('act', 'dispMemberAdminConfig');
			u.searchParams.set('cat', U.cat);
			['page', 'search_target', 'search_keyword'].forEach(function (k) { u.searchParams.delete(k); });
			history.replaceState(null, '', u.toString());
		} catch (e) { }
	}
	function valueFrom(el) {
		var key = el.getAttribute('data-k');
		var value = el.value;
		if (el.hasAttribute('data-num')) value = String(value).replace(/[^0-9]/g, '') || '0';
		if (key === 'layout_srl' || key === 'mlayout_srl' || key === 'password_hashing_work_factor' || key === 'password_reset_method' || key === 'authmail_expires_unit') value = parseInt(value, 10);
		return value;
	}
	function change(e) {
		var t = e.target;
		if (t.id === 'zmc-nick-target') return;
		if (t.hasAttribute && t.hasAttribute('data-age')) {
			var gid = parseInt(t.getAttribute('data-age'), 10);
			var age = parseInt(String(t.value).replace(/[^0-9]/g, ''), 10) || 19;
			var ages = (V.identity_age_groups || []).map(function (x) { return x[0] === gid ? [gid, Math.max(1, Math.min(99, age))] : x; });
			saveOne('identity_age_groups', ages);
			return;
		}
		if (t.id === 'lang_zmc-s-limit_day_description' || t.id === 'zmc-s-limit_day_description') {
			setTimeout(function () { var hidden = document.getElementById('zmc-s-limit_day_description'); if (hidden && hidden.value !== V.limit_day_description) saveOne('limit_day_description', hidden.value); }, 0);
			return;
		}
		if (!t.hasAttribute || !t.hasAttribute('data-k')) return;
		var key = t.getAttribute('data-k');
		if (key.indexOf('agreement_') === 0) {
			var cur = $.extend({}, V[key]);
			cur.type = t.value;
			saveOne(key, cur);
			return;
		}
		var value = valueFrom(t);
		if (t.hasAttribute('data-secret')) {
			if (String(value).trim() === '') return;
			saveOne(key, String(value).trim());
			return;
		}
		if (key === 'enable_join') {
			if (value === 'KEY') {
				if (!V.enable_join_key) { U.joinKey = true; K.render(); var ki = document.getElementById('zmc-s-enable_join_key'); if (ki) ki.focus(); return; }
				save({ enable_join: 'KEY', enable_join_key: V.enable_join_key }, labelOf('enable_join'));
				return;
			}
			U.joinKey = false;
			saveOne('enable_join', value);
			return;
		}
		if (key === 'enable_join_key') {
			var k = String(value).trim();
			if (!k) return;
			U.joinKey = false;
			save({ enable_join: 'KEY', enable_join_key: k }, labelOf('enable_join'));
			return;
		}
		if (key === 'member_mid') {
			var mid = String(value).trim();
			if (!mid || mid === V.member_mid) { t.value = V.member_mid; return; }
			U.mid = mid;
			S().modal = 's-mid';
			K.renderLayer();
			return;
		}
		if (key === 'skin') {
			var skin = (M.skins || []).filter(function (s) { return s.value === value; })[0];
			var cs = skin ? skin.colorsets : [];
			var c = { skin: value };
			if (cs && cs.length && !cs.some(function (x) { return x.name === V.colorset; })) c.colorset = cs[0].name;
			save(c, labelOf('skin'), { sub: subFor('skin') });
			return;
		}
		if (String(V[key]) === String(value)) return;
		saveOne(key, value);
	}
	function input(e) {
		var t = e.target;
		if (t.id === 'zmc-sq' || t.id === 'zmc-sq2') {
			U.q = t.value;
			clearTimeout(input.timer);
			input.timer = setTimeout(function () {
				var id = t.id;
				K.render();
				var el = document.getElementById(U.q.trim() || id === 'zmc-sq' ? id : 'zmc-sq');
				if (el) { el.focus(); try { el.setSelectionRange(el.value.length, el.value.length); } catch (er) { } }
			}, 200);
		}
	}
	function key(e) {
		var t = e.target;
		if (e.key === 'Enter' && t && !e.isComposing) {
			if (t.id === 'zmc-nick-q') { e.preventDefault(); click(e, 'snick'); return true; }
			if (t.id === 'zmc-listadd' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); listAdd(); return true; }
			if (t.tagName === 'INPUT' && (t.hasAttribute('data-k') || t.id === 'lang_zmc-s-limit_day_description' || t.hasAttribute('data-age'))) { e.preventDefault(); t.blur(); return true; }
		}
		return false;
	}

	var VIEW = {
		tour: [
			{ sel: '.snav', msel: '.msnav', t: 's_tour_1_t', d: 's_tour_1_d' },
			{ sel: '.snav .findset', msel: '.msearch', t: 's_tour_2_t', d: 's_tour_2_d' },
			{ sel: '.edbar .savestate', msel: '.edbar .savestate', t: 's_tour_3_t', d: 's_tour_3_d' }
		],
		init: function (kit) {
			K = kit;
			T = K.T; esc = K.esc; ic = K.ic;
			K.icons.globe = '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>';
			K.icons.user = '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>';
			K.icons.menu = '<path d="M4 7h16M4 12h16M4 17h10"/>';
			K.icons.palette = '<path d="M12 3a9 9 0 1 0 0 18c1.2 0 2-.8 2-1.8 0-.5-.2-.9-.5-1.3-.3-.4-.5-.8-.5-1.3 0-1 .8-1.6 1.8-1.6H17a4 4 0 0 0 4-4c0-4.4-4-8-9-8z"/><circle cx="7.5" cy="11" r="1.2"/><circle cx="10" cy="7" r="1.2"/><circle cx="14.5" cy="7" r="1.2"/>';
			var st = K.B.settings || {};
			V = st.values || {};
			M = st.meta || {};
			M.lists = M.lists || {};
			M.members = st.members || 0;
			M.signup_url = K.B.urls.signup_page;
			var init = K.B.initial || {};
			if (init.cat && CATS.some(function (c) { return c[0] === init.cat; })) U.cat = init.cat;
			U.anchor = init.anchor || '';
			if (init.nick_page || init.nick_keyword) {
				U.nick = { page: init.nick_page || 1, target: ['before', 'after', 'user_id'].indexOf(init.nick_target) >= 0 ? init.nick_target : 'after', keyword: init.nick_keyword || '' };
			}
			$(K.ROOT).on('selected.g11n', function () { setTimeout(function () { var hidden = document.getElementById('zmc-s-limit_day_description'); if (hidden && hidden.value !== V.limit_day_description) saveOne('limit_day_description', hidden.value); }, 0); });
		},
		start: function () { if (U.cat === 'adv') loadNick(U.nick ? U.nick.page : 1); },
		refresh: function () {
			K.api('member.getMemberAdminSettings', {}).then(function (d) {
				V = d.values || V;
				if (d.lists) M.lists = d.lists;
				K.render();
			}, function (e) { K.failed(e); });
		},
		onTour: function () { U.q = ''; },
		html: html,
		beforeRender: beforeRender,
		afterRender: afterRender,
		popHTML: function () { return ''; },
		modalHTML: modalHTML,
		modalClosed: function () { U.list = null; U.agree = 0; U.agreeDraft = null; },
		click: click,
		change: change,
		input: input,
		key: key
	};
	window.ZMC_VIEWS = window.ZMC_VIEWS || {};
	window.ZMC_VIEWS.settings = VIEW;
})(jQuery);
