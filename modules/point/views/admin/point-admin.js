(function () {
	'use strict';

	var bootEl = document.getElementById('ptadm-boot');
	var root = document.getElementById('ptadm');
	if (!bootEl || !root) {
		return;
	}
	var B = JSON.parse(bootEl.textContent);
	var L = B.t || {};
	var LOCALES = { ko: 'ko-KR', en: 'en-US', ja: 'ja-JP', vi: 'vi-VN', es: 'es-ES', 'zh-TW': 'zh-TW' };
	var LOCALE = LOCALES[B.lang] || B.lang || 'en-US';

	/* ── 말 · 숫자 ── */
	function jong(w) {
		w = String(w);
		var c = w.charCodeAt(w.length - 1);
		if (c >= 0xAC00 && c <= 0xD7A3) {
			return (c - 0xAC00) % 28;
		}
		var d = w.charAt(w.length - 1);
		if (/[0-9]/.test(d)) {
			return { 0: 21, 1: 8, 2: 0, 3: 16, 4: 0, 5: 0, 6: 1, 7: 8, 8: 8, 9: 0 }[d];
		}
		return /[LMNlmnRr]$/.test(w) ? 1 : 0;
	}
	function josa(word, pair) {
		var p = pair.split('/');
		var j = jong(word);
		if (p[1] === '로') {
			return (j === 0 || j === 8) ? p[1] : p[0];
		}
		return j ? p[0] : p[1];
	}
	function T(key, vars) {
		var s = L[key];
		if (s === undefined) {
			s = key;
		}
		if (vars) {
			s = s.replace(/\{(~?)(\w+)(?:\|([^}]+))?\}/g, function (m, only, k, j) {
				if (vars[k] === undefined) {
					return m;
				}
				var v = String(vars[k]);
				if (only && j) {
					return josa(v, j);
				}
				return j ? v + josa(v, j) : v;
			});
		}
		return s;
	}
	function esc(s) {
		return String(s === undefined || s === null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function fmt(n) {
		return Number(n || 0).toLocaleString(LOCALE);
	}
	function signed(n) {
		n = Number(n || 0);
		return (n > 0 ? '+' : n < 0 ? '−' : '') + fmt(Math.abs(n));
	}
	function ymdDate(ymd) {
		return new Date(+ymd.slice(0, 4), +ymd.slice(4, 6) - 1, +ymd.slice(6, 8));
	}
	function dateLabel(ymd) {
		try {
			return new Intl.DateTimeFormat(LOCALE, { month: 'long', day: 'numeric', weekday: 'short' }).format(ymdDate(ymd));
		} catch (e) {
			return ymd;
		}
	}
	function shortDate(ymd) {
		try {
			return new Intl.DateTimeFormat(LOCALE, { month: 'numeric', day: 'numeric' }).format(ymdDate(ymd));
		} catch (e) {
			return ymd;
		}
	}
	function weekdayLabel(ymd) {
		try {
			return new Intl.DateTimeFormat(LOCALE, { weekday: 'short' }).format(ymdDate(ymd));
		} catch (e) {
			return '';
		}
	}
	function ymdOf(d) {
		return d.getFullYear() + String(d.getMonth() + 1).padStart(2, '0') + String(d.getDate()).padStart(2, '0');
	}
	function addDays(ymd, n) {
		var d = ymdDate(ymd);
		d.setDate(d.getDate() + n);
		return ymdOf(d);
	}
	function timeOf(ymdhis) {
		return ymdhis && ymdhis.length >= 12 ? ymdhis.slice(8, 10) + ':' + ymdhis.slice(10, 12) : '';
	}
	function hoursAgo(ymdhis) {
		if (!ymdhis || ymdhis.length < 14) {
			return 0;
		}
		var d = new Date(+ymdhis.slice(0, 4), +ymdhis.slice(4, 6) - 1, +ymdhis.slice(6, 8), +ymdhis.slice(8, 10), +ymdhis.slice(10, 12));
		return Math.floor((Date.now() - d.getTime()) / 3600000);
	}
	function bytesLabel(n) {
		n = Number(n || 0);
		if (n >= 1e9) {
			return T('size_gb', { n: fmt(Math.round(n / 1e8) / 10) });
		}
		if (n >= 1e6) {
			return T('size_mb', { n: fmt(Math.round(n / 1e6)) });
		}
		return T('size_mb', { n: fmt(Math.max(1, Math.round(n / 1e6))) });
	}

	/* ── 아이콘 ── */
	var P = {
		plus: '<path d="M12 5v14M5 12h14"/>',
		minus: '<path d="M5 12h14"/>',
		chev: '<path d="M6 9l6 6 6-6"/>',
		chevr: '<path d="M9 6l6 6-6 6"/>',
		back: '<path d="M15 18l-6-6 6-6"/>',
		search: '<circle cx="11" cy="11" r="6"/><path d="M20 20l-4-4"/>',
		undo: '<path d="M9 14L4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-3"/>',
		users: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M21.5 20a6.5 6.5 0 0 0-4-6"/>',
		user: '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
		ext: '<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
		check: '<path d="M5 12l5 5 9-10"/>',
		x: '<path d="M6 6l12 12M18 6L6 18"/>',
		info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
		coin: '<circle cx="12" cy="12" r="9"/><path d="M14.5 9.5c-.5-1-1.5-1.5-2.5-1.5-1.7 0-2.8 1-2.8 2.2 0 2.9 5.6 1.5 5.6 4.3 0 1.2-1.2 2.2-2.8 2.2-1.2 0-2.2-.6-2.7-1.6M12 6.5V8M12 16v1.5"/>',
		tag: '<path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9z"/><circle cx="8" cy="8" r="1.5"/>',
		clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		note: '<path d="M5 3h10l4 4v14H5z"/><path d="M9 12h6M9 16h4"/>',
		activity: '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
		form: '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
		gear: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
		board: '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M8 9h8M8 13h8M8 17h5"/>',
		shop: '<path d="M5 8h14l-1 12H6z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
		download: '<path d="M12 4v12M7 11l5 5 5-5"/><path d="M4 18v2h16v-2"/>',
		star: '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/>',
		lock: '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
		smile: '<circle cx="12" cy="12" r="9"/><path d="M8.5 14.5a4.5 4.5 0 0 0 7 0M9 10h.01M15 10h.01"/>',
		map: '<path d="M9 4L3 6v14l6-2 6 2 6-2V4l-6 2z"/><path d="M9 4v14M15 6v14"/>',
		home: '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/>'
	};
	function ic(name, size, sw) {
		size = size || 16;
		return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' + (sw || 1.9) + '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (P[name] || '') + '</svg>';
	}
	var AVA = ['#dbeafe', '#dcfce7', '#fef3c7', '#fce7f3', '#ede9fe', '#e0f2fe', '#fee2e2', '#f1f5f9'];
	var AVA_INK = ['#1e40af', '#166534', '#92400e', '#9d174d', '#5b21b6', '#075985', '#991b1b', '#334155'];
	function avatar(m) {
		var a = (m && m.ava) || 0;
		return '<span class="avatar" style="background:' + AVA[a] + ';color:' + AVA_INK[a] + '">' + esc((m && m.initial) || '?') + '</span>';
	}
	function term(key, label) {
		return '<span class="term" tabindex="0" data-term="' + key + '">' + (label || T('term_' + key + '_t')) + '</span>';
	}
	function cbox(on) {
		return '<span class="cbox' + (on ? ' on' : '') + '">' + (on ? ic('check', 12, 3) : '') + '</span>';
	}
	function sw(on, act, extra, label, dis) {
		return '<button type="button" class="switch' + (on ? ' on' : '') + '" role="switch" aria-checked="' + !!on + '" aria-label="' + esc(label || '') + '" data-act="' + act + '"' + (extra || '') + (dis ? ' disabled' : '') + '></button>';
	}
	function srow(title, desc, ctrl, extra) {
		return '<div class="srow"><div class="st"><b>' + title + '</b><small>' + desc + '</small>' + (extra || '') + '</div><div class="sv">' + ctrl + '</div></div>';
	}
	function newBadge() {
		return ' <span class="bdg">' + T('badge_new') + '</span>';
	}
	function lvIcon(level) {
		if (!S.levels) {
			return '';
		}
		return '<img class="lvimg" src="' + esc(B.icon_base + S.levels.icon + '/' + level + '.' + S.levels.icon_type) + '" alt="" loading="lazy">';
	}
	function lvBadge(m) {
		return '<span class="lvb">' + (m.icon ? '<img class="lvimg" src="' + esc(m.icon) + '" alt="" loading="lazy">' : '') + '<span class="dim">' + T('level_n', { n: m.level }) + '</span></span>';
	}
	function pointUnit() {
		return T('unit_point');
	}

	/* ── 서버 ── */
	function csrf() {
		var m = document.querySelector('meta[name="csrf-token"]');
		return m ? m.getAttribute('content') : '';
	}
	function api(act, params) {
		var body = Object.assign({ module: 'point', act: act }, params || {});
		return fetch(window.request_uri || './', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
			credentials: 'same-origin',
			body: JSON.stringify(body)
		}).then(function (r) {
			return r.json();
		}).then(function (d) {
			if (d && d.error && Number(d.error) !== 0) {
				var err = new Error(d.message || 'error');
				err.data = d;
				throw err;
			}
			return d;
		});
	}
	function fail(e) {
		saving(false);
		toast(T('toast_failed'), e && e.message && e.message !== 'error' ? e.message : T('toast_failed_sub'), null);
		render();
	}

	/* ── 상태 ── */
	var NAV = [
		['dash', 'activity'], ['rules', 'coin'], ['members', 'users'], ['ledger', 'note'], ['level', 'star'], ['uses', 'shop'], ['policy', 'gear']
	];
	var q = B.query || {};
	var S = {
		sec: B.section || 'dash',
		ledger: !!B.ledger,
		busy: !!B.ledger_busy,
		job: B.ledger_job || '',
		stats: !!B.stats,
		able: !!B.able,
		bigConfirm: !!B.big_confirm,
		week: 'this',
		dash: null,
		rules: null,
		fold: {},
		boardOpen: q.board_open || null,
		mem: { rows: null, next: '', q: '', sort: 'point', group: q.group_srl || 0, target: q.search_target || '', keyword: q.search_keyword || '', loading: false, capped: false },
		led: { rows: null, next: '', more: 0, kind: q.kind || '', member: q.member_srl || 0, memberCard: null, type: q.type || '', direction: q.direction || '', reason: q.reason_code || '', actor: q.actor_only === 'Y', from: q.date_from || '', to: q.date_to || '', loading: false },
		levels: null,
		lvOpen: false,
		uses: null,
		policy: null,
		open: null,
		panel: null,
		sel: [],
		selNames: {},
		pt: { mode: 'give', amt: 500, memo: '' },
		pop: null,
		modal: null,
		toast: null,
		undo: [],
		save: 'saved',
		flash: null,
		prog: null,
		chk: { ret: true, stats: true },
		est: null,
		advOpen: false,
		recal: null,
		err: {}
	};
	if (S.led.member || S.led.kind || S.led.reason || S.led.type) {
		S.sec = 'ledger';
	}

	var saveTimer = null;
	function saving(on) {
		clearTimeout(saveTimer);
		if (on) {
			S.save = 'saving';
		} else {
			saveTimer = setTimeout(function () {
				S.save = 'saved';
				renderBar();
			}, 350);
		}
		renderBar();
	}
	var toastTimer = null;
	function toast(title, sub, undo, label) {
		S.toast = { title: title, sub: sub || '', undo: undo || null, label: label || T('cmd_undo') };
		clearTimeout(toastTimer);
		toastTimer = setTimeout(function () {
			S.toast = null;
			renderLayer();
		}, 7000);
	}
	function pushUndo(label, fn) {
		var item = { label: label, run: fn };
		S.undo.push(item);
		if (S.undo.length > 20) {
			S.undo.shift();
		}
		return item;
	}
	function runUndo(item) {
		if (!item) {
			item = S.undo.pop();
		} else {
			if (S.undo.indexOf(item) < 0) {
				S.toast = null;
				renderLayer();
				return;
			}
			S.undo = S.undo.filter(function (x) { return x !== item; });
		}
		if (!item) {
			return;
		}
		S.toast = null;
		saving(true);
		Promise.resolve(item.run()).then(function () {
			saving(false);
			toast(T('toast_undone'), T('toast_undone_sub', { label: item.label }), null);
			render();
		}).catch(fail);
	}

	/* ── 머리 ── */
	function renderBar() {
		var el = document.getElementById('pa-edbar');
		var tabs = [['members', T('head_members'), 'users', B.urls.members], ['groups', T('head_groups'), 'tag', B.urls.groups], ['signup', T('head_signup'), 'form', B.urls.signup], ['point', T('head_point'), 'coin', B.urls.point], ['settings', T('head_settings'), 'gear', B.urls.settings]];
		var sv = S.save === 'saving'
			? '<span class="savestate saving" role="status"><i class="dot"></i>' + T('save_saving') + '</span>'
			: '<span class="savestate" role="status">' + ic('check', 14, 2.6) + T('save_saved') + '</span>';
		el.innerHTML = '<h1>' + T('head_title') + '</h1><nav class="seg viewseg" aria-label="' + esc(T('head_title')) + '">' + tabs.map(function (t) {
			return '<a href="' + esc(t[3]) + '" class="' + (t[0] === 'point' ? 'on' : '') + '"' + (t[0] === 'point' ? ' aria-current="page"' : '') + '>' + ic(t[2], 15) + esc(t[1]) + '</a>';
		}).join('') + '</nav><span class="spacer"></span>' + sv +
			'<button type="button" class="btn d-undo" data-act="undo"' + (S.undo.length ? '' : ' disabled') + '>' + ic('undo', 16) + T('cmd_undo') + '</button>';
	}

	/* ── 화면 ── */
	function render() {
		renderBar();
		renderWork();
		renderLayer();
	}
	function renderWork() {
		var ws = document.getElementById('pa-work');
		var keepScroll = document.getElementById('pa-page');
		var top = keepScroll ? keepScroll.scrollTop : 0;
		ws.className = 'pa-work v-point' + (S.open ? ' with-panel' : '');
		var nav = '<nav class="snav" aria-label="' + esc(T('head_point')) + '">' + NAV.map(function (n) {
			return '<button type="button" class="fl' + (S.sec === n[0] ? ' on' : '') + '" data-act="sec" data-v="' + n[0] + '"><span class="ico">' + ic(n[1], 16) + '</span><span>' + T('nav_' + n[0]) + '<small>' + T('nav_' + n[0] + '_d') + '</small></span></button>';
		}).join('') + '</nav>';
		var mnav = '<div class="msnav">' + NAV.map(function (n) {
			return '<button type="button" class="fchip' + (S.sec === n[0] ? ' on' : '') + '" data-act="sec" data-v="' + n[0] + '">' + T('nav_' + n[0]) + '</button>';
		}).join('') + '</div>';
		var body = { dash: secDash, rules: secRules, members: secMembers, ledger: secLedger, level: secLevel, uses: secUses, policy: secPolicy }[S.sec]();
		ws.innerHTML = nav + '<section class="ppage" id="pa-page"><div class="ppage-in">' + mnav + body + '</div></section>' + (S.open ? panelHTML() : '');
		var page = document.getElementById('pa-page');
		if (page && keepScroll && !S.resetScroll) {
			page.scrollTop = top;
		}
		S.resetScroll = false;
	}
	function pending(what) {
		if (S.err[what]) {
			return '<div class="emptybox">' + T('load_failed') + ' <button type="button" class="linkbtn" data-act="retry" data-v="' + what + '">' + T('cmd_retry') + '</button></div>';
		}
		setTimeout(function () { load(what); }, 0);
		return loading();
	}
	function loading() {
		return '<div class="pa-loading" role="status"><i class="dot"></i>' + T('loading') + '</div>';
	}

	/* 요약 */
	function secDash() {
		var d = S.dash;
		if (!d) {
			return '<h2>' + T('dash_title') + '</h2>' + pending('dash');
		}
		if (d.mode !== 'full') {
			return dashBasic(d);
		}
		var w = d.week;
		var head = '<h2>' + T('dash_title') + '<span class="r"><span class="seg"><button type="button" class="' + (S.week === 'this' ? 'on' : '') + '" data-act="week" data-v="this">' + T('week_this') + '</button><button type="button" class="' + (S.week === 'last' ? 'on' : '') + '" data-act="week" data-v="last">' + T('week_last') + '</button></span></span></h2>';
		var range = S.week === 'this' ? T('dash_range_this', { from: dateLabel(w.monday) }) : T('dash_range_last', { from: dateLabel(w.monday), to: dateLabel(w.sunday) });
		var ago = hoursAgo(d.last_run);
		var clock = ago >= 2 ? T('dash_late', { n: ago }) : T('dash_hourly', { time: timeOf(d.last_run) });
		var lead = '<p class="lead">' + range + ' ' + T('dash_click') + ' <span class="dim nowrap">' + ic('clock', 12) + clock + '</span></p>';
		var change = w.change === null || w.change === undefined ? '<span>' + T('dash_change_none') + '</span>' : T('dash_change', { n: '<b class="' + (w.change >= 0 ? 'up' : 'dn') + '">' + (w.change >= 0 ? '+' : '−') + Math.abs(w.change) + '%</b>' });
		var kpis = '<div class="kpis">' +
			'<button type="button" class="kpi" data-act="dashgo" data-v="earn"><div class="k">' + ic('plus', 14) + T('kpi_earned') + '</div><div class="v">' + fmt(w.earned) + '<small>' + pointUnit() + '</small></div><div class="d">' + change + '</div></button>' +
			'<button type="button" class="kpi" data-act="dashgo" data-v="spend"><div class="k">' + ic('minus', 14) + T('kpi_spent') + '</div><div class="v">' + fmt(w.spent) + '<small>' + pointUnit() + '</small></div><div class="d">' + (w.top_spend ? T('kpi_spent_top', { place: esc(w.top_spend.label), n: fmt(w.top_spend.value) }) : T('kpi_spent_none')) + '</div></button>' +
			'<div class="kpi"><div class="k">' + ic('coin', 14) + T('kpi_holdings') + '</div><div class="v">' + fmt(d.holdings.total) + '<small>' + pointUnit() + '</small></div><div class="d">' + T('kpi_average', { n: fmt(d.holdings.average) }) + '</div></div>' +
			'<button type="button" class="kpi" data-act="dashgo" data-v="admin"><div class="k">' + ic('user', 14) + T('kpi_admin') + '</div><div class="v">' + fmt(w.admin_given) + '<small>' + pointUnit() + '</small></div><div class="d">' + (w.admin_takes ? T('kpi_admin_times_takes', { n: fmt(w.admin_count), m: fmt(w.admin_takes) }) : T('kpi_admin_times', { n: fmt(w.admin_count) })) + '</div></button></div>';
		var top = '<div class="card"><h3>' + T(S.week === 'this' ? 'dash_top_this' : 'dash_top_last') + '<span class="h-r"><button type="button" class="linkbtn" data-act="sec" data-v="members">' + T('nav_members') + ' ' + ic('chevr', 14) + '</button></span></h3><div class="list pb12">' +
			(w.top.length ? w.top.map(function (t, i) {
				var m = t.member;
				return '<button type="button" class="li wide" data-act="open" data-v="' + m.srl + '"><b class="rank">' + (i + 1) + '</b>' + avatar(m) + '<span class="mn">' + esc(m.nick) + '<small>' + esc(m.groups.concat([T('level_n', { n: m.level })]).join(' · ')) + '</small></span><span class="r plus">+' + fmt(t.value) + '</span></button>';
			}).join('') : '<p class="empty">' + T('dash_top_empty') + '</p>') + '</div></div>';
		var rmax = w.rules.length ? w.rules[0].value : 1;
		var rules = '<div class="card"><h3>' + T('dash_rules') + '<span class="h-r"><button type="button" class="linkbtn" data-act="sec" data-v="rules">' + T('dash_rules_edit') + ' ' + ic('chevr', 14) + '</button></span></h3><div class="pb14 pt4">' +
			(w.rules.length ? w.rules.map(function (r) {
				return '<div class="hbar"><span class="hl" title="' + esc(r.label) + '">' + esc(r.label) + '</span><span class="ht"><i style="width:' + Math.max(2, Math.round(r.value / rmax * 100)) + '%"></i></span><span class="hv">' + fmt(r.value) + '</span></div>';
			}).join('') : '<p class="empty">' + T('dash_rules_empty') + '</p>') + '</div></div>';
		var max = 1;
		w.days.forEach(function (x) {
			max = Math.max(max, x.earned, x.spent);
		});
		var bars = '<div class="card"><h3>' + T('dash_daily') + '</h3><div class="weekbars">' + w.days.map(function (x) {
			return '<div class="wb"><div class="bars"><i class="e" style="height:' + Math.round(x.earned / max * 108) + 'px" title="' + esc(T('kpi_earned') + ' ' + fmt(x.earned)) + '"></i><i class="s" style="height:' + Math.round(x.spent / max * 108) + 'px" title="' + esc(T('kpi_spent') + ' ' + fmt(x.spent)) + '"></i></div><span class="wl">' + esc(weekdayLabel(x.ymd)) + '</span></div>';
		}).join('') + '</div><div class="legend"><span><i class="le"></i>' + T('kpi_earned') + '</span><span><i class="ls"></i>' + T('kpi_spent') + '</span></div><div class="gap14"></div></div>';
		return head + lead + bigLedgerNote(d) + kpis + '<div class="cols2">' + top + rules + '</div>' + bars;
	}
	function bigLedgerNote(d) {
		if (!d.storage_rows || d.storage_rows < 10000000 || (S.policy && S.policy.retention)) {
			return '';
		}
		return '<div class="note warn mb12">' + ic('info', 14) + '<span>' + T('dash_big_ledger') + ' <button type="button" class="linkbtn" data-act="sec" data-v="policy">' + T('nav_policy') + ' ' + ic('chevr', 12) + '</button></span></div>';
	}
	function dashBasic(d) {
		var h = d.holdings;
		var hi = d.highest;
		var html = '<h2>' + T('dash_title') + '</h2><p class="lead">' + T('dash_basic_lead') + '</p>' +
			'<div class="kpis two"><div class="kpi"><div class="k">' + ic('coin', 14) + T('kpi_holdings') + '</div><div class="v">' + fmt(h.total) + '<small>' + pointUnit() + '</small></div><div class="d">' + T('kpi_average', { n: fmt(h.average) }) + '</div></div>' +
			'<div class="kpi"><div class="k">' + ic('star', 14) + T('kpi_highest') + '</div><div class="v">' + (hi ? T('level_n', { n: hi.level }) : '—') + '</div><div class="d">' + (hi ? T('kpi_highest_who', { nick: esc(hi.nick) }) : '') + '</div></div></div>';
		var top = '<div class="card"><h3>' + T('dash_richest') + '<span class="h-r"><button type="button" class="linkbtn" data-act="sec" data-v="members">' + T('nav_members') + ' ' + ic('chevr', 14) + '</button></span></h3><div class="list pb12">' +
			(d.top.length ? d.top.map(function (t, i) {
				var m = t.member;
				return '<button type="button" class="li wide" data-act="open" data-v="' + m.srl + '"><b class="rank">' + (i + 1) + '</b>' + avatar(m) + '<span class="mn">' + esc(m.nick) + '<small>' + T('level_n', { n: m.level }) + '</small></span><span class="r"><b>' + fmt(t.value) + '</b></span></button>';
			}).join('') : '<p class="empty">' + T('dash_richest_empty') + '</p>') + '</div></div>';
		var side;
		if (S.busy) {
			side = progressCard(true);
		} else if (S.ledger) {
			side = '<div class="offcard"><span class="oi">' + ic('activity', 22) + '</span><div class="ob"><h3>' + T('stats_off_title') + '</h3><p>' + T('stats_off_desc') + '</p><div class="oa"><button type="button" class="btn primary" data-act="statson">' + T('stats_off_btn') + '</button></div></div></div>';
		} else {
			side = offCard();
		}
		return html + bigLedgerNote(d) + '<div class="cols2">' + top + side + '</div>';
	}
	function offCard(size) {
		if (size === 'small') {
			return '<div class="offcard sm"><p>' + T('off_small') + '</p><button type="button" class="btn" data-act="ledgeron">' + ic('note', 15) + T('off_btn') + '</button></div>';
		}
		var li = ['off_li1', 'off_li2', 'off_li3', 'off_li4'];
		return '<div class="offcard"><span class="oi">' + ic('note', 22) + '</span><div class="ob"><h3>' + T('off_title') + '</h3><p>' + T('off_desc') + '</p><ul>' + li.map(function (k) {
			return '<li>' + T(k) + '</li>';
		}).join('') + '</ul><div class="oa"><button type="button" class="btn primary" data-act="ledgeron">' + T('off_btn') + '</button><span class="hint m0">' + T('off_hint') + '</span></div></div></div>';
	}
	function progressCard(card) {
		var p = S.prog || { percent: 0, total: 0 };
		var done = Math.round((p.total || 0) * (p.percent || 0) / 100);
		var inner = '<div class="progress"><i style="width:' + (p.percent || 0) + '%"></i></div><div class="hint">' + T('prog_text', { total: fmt(p.total || 0), done: fmt(done) }) + '</div>';
		if (!card) {
			return inner;
		}
		return '<div class="offcard"><span class="oi">' + ic('note', 22) + '</span><div class="ob"><h3>' + T('prog_title', { n: p.percent || 0 }) + '</h3>' + inner + '</div></div>';
	}

	/* 규칙 */
	var RULES = [
		{ g: 'signup', rows: [{ k: 'signup_point', who: 'signup' }, { k: 'login_point', who: 'login', sub: 'sub_login' }] },
		{ g: 'doc', rows: [
			{ k: 'insert_document', who: 'author', board: 1, revert: 1 },
			{ k: 'first_document_point', who: 'author', revert: 1, sub: 'sub_first', ledgerOnly: 1 },
			{ k: 'insert_comment', who: 'commenter', board: 1, revert: 1, limit: 1 },
			{ k: 'upload_file', who: 'uploader', board: 1, revert: 1 }
		] },
		{ g: 'read', rows: [
			{ k: 'read_document', who: 'reader', board: 1, limit: 1, notice: 1, sub: 'sub_read' },
			{ k: 'read_document_author', who: 'author', board: 1, limit: 1, notice: 1 },
			{ k: 'download_file', who: 'downloader', board: 1 },
			{ k: 'download_file_author', who: 'uploader', board: 1 }
		] },
		{ g: 'vote', fold: 1, rows: [
			{ k: 'voted', who: 'author', board: 1, limit: 1 },
			{ k: 'blamed', who: 'author', board: 1, limit: 1 },
			{ k: 'voter', who: 'voter', board: 1, limit: 1 },
			{ k: 'blamer', who: 'blamer', board: 1, limit: 1 },
			{ k: 'voted_comment', who: 'commenter', board: 1, limit: 1 },
			{ k: 'blamed_comment', who: 'commenter', board: 1, limit: 1 },
			{ k: 'voter_comment', who: 'voter', board: 1, limit: 1 },
			{ k: 'blamer_comment', who: 'blamer', board: 1, limit: 1 }
		] }
	];
	function findRule(k) {
		var found = null;
		RULES.forEach(function (g) {
			g.rows.forEach(function (r) {
				if (r.k === k) {
					found = r;
				}
			});
		});
		return found;
	}
	function ptin(val, act, key, extra) {
		return '<span class="ptin' + (Number(val) < 0 ? ' minus' : '') + '"><button type="button" data-act="' + act + '" data-k="' + key + '" data-d="-1"' + (extra || '') + ' aria-label="' + esc(T('aria_less')) + '">' + ic('minus', 13, 2.4) + '</button><input inputmode="numeric" value="' + esc(fmt(val)) + '" data-ptin="' + act + '" data-k="' + key + '"' + (extra || '') + ' aria-label="' + esc(T('aria_points')) + '"><button type="button" data-act="' + act + '" data-k="' + key + '" data-d="1"' + (extra || '') + ' aria-label="' + esc(T('aria_more')) + '">' + ic('plus', 13, 2.4) + '</button></span>';
	}
	function condText(r) {
		var R = S.rules;
		var parts = [];
		if (r.limit) {
			var days = R.limits[r.k] || 0;
			parts.push(days ? T('cond_days', { n: fmt(days) }) : T('cond_nolimit'));
		}
		if (r.notice && R.notice[r.k]) {
			parts.push(T('cond_notice'));
		}
		return parts.join(' · ');
	}
	function secRules() {
		var R = S.rules;
		if (!R) {
			return '<h2>' + T('nav_rules') + '</h2>' + pending('rules');
		}
		var rows = '';
		RULES.forEach(function (grp) {
			var open = !grp.fold || S.fold[grp.g];
			var nonzero = grp.rows.filter(function (r) { return R.values[r.k] !== 0; });
			rows += '<tr class="grp"><td colspan="5"><button type="button" class="checkline grphead" data-act="fold" data-v="' + grp.g + '">' + (grp.fold ? '<span class="tw">' + ic(open ? 'chev' : 'chevr', 14) + '</span>' : '') + T('grp_' + grp.g) +
				(grp.fold && !open ? '<span class="dim grpsum">' + esc(nonzero.map(function (r) { return T('rule_' + r.k) + ' ' + (R.values[r.k] > 0 ? '+' : '') + R.values[r.k]; }).join(' · ')) + ((grp.rows.length - nonzero.length) ? ' ' + T('grp_zero_rest', { n: grp.rows.length - nonzero.length }) : '') + '</span>' : '') + '</button></td></tr>';
			if (!open) {
				return;
			}
			grp.rows.forEach(function (r) {
				var v = R.values[r.k];
				var ov = R.overrides[r.k] ? Object.keys(R.overrides[r.k]) : [];
				var blink = r.board ? '<button type="button" class="linkbtn" data-act="board" data-v="' + r.k + '">' + (ov.length ? '<span class="ownchip">' + T('board_n_own', { n: ov.length }) + '</span>' : T('board_each')) + ' ' + ic(S.boardOpen === r.k ? 'chev' : 'chevr', 13) + '</button>' : '';
				var who = T('who_' + r.who);
				var sub = (v > 0 ? T('rule_give', { who: who }) : v < 0 ? T('rule_take', { who: who }) : T('rule_zero', { who: who })) + (r.sub ? ' · ' + T(r.sub) : '') + (r.ledgerOnly && !R.ledger ? ' · ' + T('sub_needs_ledger') : '');
				var cond = condText(r);
				var condCell = (r.limit || r.notice) ? '<button type="button" class="condbtn" data-act="cond" data-k="' + r.k + '" data-anchor="cond-' + r.k + '">' + esc(cond) + '</button>' : '<span class="dim">—</span>';
				var revCell = r.revert ? '<button type="button" class="revbtn" data-act="revert" data-k="' + r.k + '" aria-pressed="' + !!R.revert[r.k] + '">' + cbox(R.revert[r.k]) + '<span class="' + (R.revert[r.k] ? '' : 'dim') + '">' + (R.revert[r.k] ? term('revert', T('revert_on')) : T('revert_off')) + '</span></button>' : '<span class="dim">—</span>';
				rows += '<tr class="' + (S.flash === r.k ? 'hl ' : '') + (v === 0 ? 'off' : '') + '" id="rule-' + r.k + '"><td class="rname"><b>' + T('rule_' + r.k) + '</b><small>' + sub + '</small>' + (blink ? '<div class="mshow">' + blink + '</div>' : '') + '</td>' +
					'<td>' + ptin(v, 'rule', r.k) + '</td><td class="hide-m">' + condCell + '</td><td class="hide-m">' + revCell + '</td><td class="ar hide-m">' + blink + '</td></tr>';
				if (S.boardOpen === r.k) {
					var map = R.overrides[r.k] || {};
					if (!R.boards.length) {
						rows += '<tr class="boardrow"><td colspan="5" class="dim">' + T('board_none') + '</td></tr>';
					}
					R.boards.forEach(function (b) {
						var own = map[b.srl] !== undefined;
						var val = own ? map[b.srl] : v;
						rows += '<tr class="boardrow"><td class="rname"><b class="fw5">' + esc(b.title) + '</b><small>' + esc(b.domain) + '</small></td><td>' + ptin(val, 'bv', r.k, ' data-b="' + b.srl + '"') + '</td><td colspan="2" class="hide-m">' +
							(own ? '<span class="ownchip">' + term('own', T('own')) + '</span>' : '<span class="followchip">' + term('follow', T('follow')) + ' · ' + (v > 0 ? '+' : '') + fmt(v) + T('unit_point') + '</span>') +
							'</td><td class="ar">' + (own ? '<button type="button" class="linkbtn" data-act="breset" data-k="' + r.k + '" data-b="' + b.srl + '">' + T('board_reset') + '</button>' : '') + '</td></tr>';
					});
				}
			});
		});
		return '<h2>' + T('nav_rules') + '</h2><p class="lead">' + T('rules_lead') + '</p>' +
			'<div class="card"><table class="rtable"><thead><tr><th>' + T('col_activity') + '</th><th>' + T('col_points') + '</th><th class="hide-m">' + T('col_condition') + '</th><th class="hide-m">' + T('col_delete') + '</th><th></th></tr></thead><tbody>' + rows + '</tbody></table><div class="gap8"></div></div>' +
			'<div class="note">' + ic('info', 14) + '<span>' + T('rules_note', { follow: term('follow', T('follow')) }) + '</span></div>';
	}

	/* 회원별 */
	function secMembers() {
		var M = S.mem;

		var stats = S.stats;
		var groups = (B.groups || []).filter(function (g) { return !g.admin || true; });
		var tools = '<div class="listtools"><label class="search">' + ic('search', 16) + '<input id="pa-q" type="search" placeholder="' + esc(T('mem_search')) + '" value="' + esc(M.q) + '" aria-label="' + esc(T('mem_search')) + '"></label>' +
			'<select class="sel" id="pa-sort" aria-label="' + esc(T('aria_sort')) + '"><option value="point"' + (M.sort === 'point' ? ' selected' : '') + '>' + T('sort_point') + '</option>' + (stats ? '<option value="last"' + (M.sort === 'last' ? ' selected' : '') + '>' + T('sort_last') + '</option>' : '') + '<option value="recent"' + (M.sort === 'recent' ? ' selected' : '') + '>' + T('sort_recent') + '</option></select>' +
			'<select class="sel" id="pa-group" aria-label="' + esc(T('aria_group')) + '"><option value="0">' + T('group_all') + '</option>' + groups.map(function (g) {
				return '<option value="' + g.id + '"' + (+M.group === g.id ? ' selected' : '') + '>' + esc(g.title) + '</option>';
			}).join('') + '</select></div>';
		if (M.target && M.keyword) {
			tools += '<div class="fchips mb8"><button type="button" class="fchip on" data-act="legacyclear">' + esc(T('legacy_target_' + M.target) + ': ' + M.keyword) + '<span class="fx">' + ic('x', 13, 2.4) + '</span></button></div>';
		}
		var sel = S.sel;
		var bulk = sel.length ? '<div class="bulkbar"><span>' + T('bulk_selected', { n: '<b>' + fmt(sel.length) + '</b>' }) + '</span><button type="button" class="btn' + (S.pop && S.pop.type === 'bulk' ? ' on' : '') + '" data-act="pop" data-v="bulk" data-anchor="bulk">' + ic('coin', 15) + T('bulk_give') + '</button><button type="button" class="clear" data-act="selclear">' + T('bulk_clear') + '</button></div>' : '';
		var head = '<h2>' + T('nav_members') + '</h2><p class="lead">' + T('mem_lead') + '</p>' + tools + bulk;
		if (!M.rows) {
			return head + pending('members');
		}
		var rows = M.rows;
		var weekCol = stats ? '<th class="num">' + T(M.sort === 'last' ? 'col_week_last' : 'col_week_this') + '</th>' : '';
		var allOn = rows.length && rows.every(function (m) { return sel.indexOf(m.srl) >= 0; });
		var table = '<table class="mtable"><thead><tr><th class="ck"><button type="button" data-act="selall" aria-label="' + esc(T('aria_select_all')) + '">' + cbox(allOn) + '</button></th><th>' + T('col_member') + '</th><th class="num">' + T('col_balance') + '</th><th>' + T('col_level') + '</th>' + weekCol + '</tr></thead><tbody>' +
			rows.map(function (m) {
				var on = sel.indexOf(m.srl) >= 0;
				return '<tr class="' + (on ? 'sel ' : '') + (S.open === m.srl ? 'open' : '') + '" data-act="open" data-v="' + m.srl + '"><td class="ck"><button type="button" data-act="sel" data-v="' + m.srl + '" aria-label="' + esc(T('aria_select', { nick: m.nick })) + '">' + cbox(on) + '</button></td><td><div class="who">' + avatar(m) + '<div><div class="nm">' + esc(m.nick) + '</div><div class="em">' + esc([m.name].concat(m.groups).filter(Boolean).join(' · ')) + '</div></div></div></td><td class="num"><b>' + fmt(m.point) + '</b></td><td>' + lvBadge(m) + '</td>' + (stats ? '<td class="num plus">+' + fmt(m.week || 0) + '</td>' : '') + '</tr>';
			}).join('') + '</tbody></table>';
		var cards = '<div class="mcards">' + rows.map(function (m) {
			var on = sel.indexOf(m.srl) >= 0;
			return '<div class="mcard' + (on ? ' sel' : '') + '"><button type="button" class="mck" data-act="sel" data-v="' + m.srl + '" aria-label="' + esc(T('aria_select', { nick: m.nick })) + '">' + cbox(on) + '</button><button type="button" class="who" data-act="open" data-v="' + m.srl + '">' + avatar(m) + '<div class="mn"><div class="nm">' + esc(m.nick) + '</div><div class="em">' + T('level_n', { n: m.level }) + '</div></div></button><div class="mr"><b class="ink">' + fmt(m.point) + T('unit_point') + '</b>' + (stats ? '<span class="plus">+' + fmt(m.week || 0) + '</span>' : '') + '</div></div>';
		}).join('') + '</div>';
		var empty = rows.length ? '' : '<div class="emptybox">' + T(M.q ? 'mem_empty_search' : 'mem_empty') + '</div>';
		var more = M.next ? '<div class="morebox"><button type="button" class="btn" data-act="memmore"' + (M.loading ? ' disabled' : '') + '>' + ic('chev', 14) + T('more') + '</button></div>' : (M.capped ? '<div class="hint center">' + T('mem_capped') + '</div>' : '');
		return head + (rows.length ? table + cards : empty) + more;
	}

	/* 내역 */
	var KIND_CHIPS = ['earn', 'spend', 'admin', 'revoke'];
	function kindChip(k) {
		var known = { earn: 1, spend: 1, admin: 1, revoke: 1, expire: 1 };
		var c = known[k] ? k : 'neutral';
		return '<span class="typechip ' + c + '">' + T('kind_' + k) + '</span>';
	}
	function periodLabel() {
		var D = S.led;
		if (!D.from && !D.to) {
			return T('period_all');
		}
		var monday = B.week;
		if (D.from === monday && (!D.to || D.to === B.today)) {
			return T('period_this_week');
		}
		if (D.from === addDays(monday, -7) && D.to === addDays(monday, -1)) {
			return T('period_last_week');
		}
		return shortDate(D.from || D.to) + (D.to && D.to !== D.from ? ' ~ ' + shortDate(D.to) : '');
	}
	function ledgerQuery() {
		var D = S.led;
		var p = {};
		if (D.member) { p.member_srl = D.member; }
		if (D.kind) { p.kind = D.kind; }
		if (D.type) { p.type = D.type; }
		if (D.direction) { p.direction = D.direction; }
		if (D.reason) { p.reason_code = D.reason; }
		if (D.actor) { p.actor_only = 'Y'; }
		if (D.from) { p.date_from = D.from; }
		if (D.to) { p.date_to = D.to; }
		return p;
	}
	function secLedger() {
		if (!S.ledger || S.busy) {
			return '<h2>' + T('nav_ledger') + '</h2><p class="lead">' + T('ledger_off_lead') + '</p>' + (S.busy ? progressCard(true) : offCard());
		}
		var D = S.led;

		var chip = function (v, l) {
			return '<button type="button" class="fchip' + (D.kind === v ? ' on' : '') + '" data-act="kind" data-v="' + v + '">' + l + '</button>';
		};
		var qs = ledgerQuery();
		var csv = B.urls.csv + Object.keys(qs).map(function (k) { return '&' + encodeURIComponent(k) + '=' + encodeURIComponent(qs[k]); }).join('');
		var legacy = '';
		if (D.type) { legacy += '<button type="button" class="fchip on" data-act="ledclear" data-v="type">' + esc(T('chip_type', { v: T('ltype_' + D.type) })) + '<span class="fx">' + ic('x', 13, 2.4) + '</span></button>'; }
		if (D.direction) { legacy += '<button type="button" class="fchip on" data-act="ledclear" data-v="direction">' + T(D.direction === 'plus' ? 'chip_plus' : 'chip_minus') + '<span class="fx">' + ic('x', 13, 2.4) + '</span></button>'; }
		if (D.reason) { legacy += '<button type="button" class="fchip on" data-act="ledclear" data-v="reason">' + esc(T('chip_reason', { v: D.reason })) + '<span class="fx">' + ic('x', 13, 2.4) + '</span></button>'; }
		if (D.actor) { legacy += '<button type="button" class="fchip on" data-act="ledclear" data-v="actor">' + T('chip_actor') + '<span class="fx">' + ic('x', 13, 2.4) + '</span></button>'; }
		var chips = '<div class="fchips mb12">' + chip('', T('kind_all')) + KIND_CHIPS.map(function (k) { return chip(k, T('kind_chip_' + k)); }).join('') + (S.expireOn ? chip('expire', T('kind_chip_expire')) : '') +
			(D.member ? '<button type="button" class="fchip on" data-act="ledmember" data-v="">' + (D.member < 0 ? T('chip_member_unknown') : T('chip_member', { nick: esc(D.memberCard ? D.memberCard.nick : '') })) + '<span class="fx">' + ic('x', 13, 2.4) + '</span></button>' : '<button type="button" class="fchip" data-act="pop" data-v="pickmember" data-anchor="pickmember">' + ic('user', 14) + T('chip_pick_member') + '</button>') +
			'<button type="button" class="fchip' + (D.from || D.to ? ' on' : '') + '" data-act="pop" data-v="period" data-anchor="period">' + ic('clock', 14) + esc(periodLabel()) + ' ' + ic('chev', 14) + '</button>' + legacy + '</div>';
		var narrowHint = (D.kind || D.reason || D.type || D.direction || D.actor) && !D.member ? '<div class="hint mt-4 mb10">' + T('ledger_narrow_hint') + '</div>' : '';
		var head = '<h2>' + T('nav_ledger') + '<span class="r"><a class="btn" href="' + esc(csv) + '">' + ic('download', 15) + T('csv') + '</a></span></h2><p class="lead">' + T('ledger_lead', { ledger: term('ledger', T('term_ledger_t')) }) + '</p>' + chips + narrowHint;
		if (!D.rows) {
			return head + pending('ledger');
		}
		var rows = D.rows;
		var whoCell = function (r) {
			if (r.by === 'admin') {
				return '<span class="gtag"' + (r.actor ? ' title="' + esc(r.actor) + '"' : '') + '>' + T('by_admin') + '</span>';
			}
			return '<span class="dim">' + T('by_' + r.by) + '</span>';
		};
		var revertCell = function (r) {
			if (r.can_revert) {
				return ' <button type="button" class="linkbtn" data-act="revertrow" data-v="' + r.srl + '">' + T('cmd_revert') + '</button>';
			}
			if (r.reverted) {
				return ' <span class="dim small">' + T('reverted') + '</span>';
			}
			return '';
		};
		var table = '<table class="mtable ledger"><thead><tr><th>' + T('col_when') + '</th><th>' + T('col_who') + '</th><th>' + T('col_why') + '</th><th class="num">' + T('col_amount') + '</th><th class="num hide-m">' + T('col_after') + '</th><th class="hide-m">' + T('col_by') + '</th></tr></thead><tbody>' +
			rows.map(function (r) {
				return '<tr data-act="open" data-v="' + r.member.srl + '"><td class="dim">' + esc(r.at) + '</td><td><div class="who">' + avatar(r.member) + '<span class="nm">' + esc(r.member.nick || T('member_gone')) + '</span></div></td><td class="why">' + kindChip(r.kind) + ' ' + esc(r.why) + (r.where ? '<div class="dim small">' + esc(r.where) + '</div>' : '') + '</td><td class="num ' + (r.amount > 0 ? 'plus' : 'minus') + '">' + signed(r.amount) + '</td><td class="num hide-m">' + (r.archived ? '—' : fmt(r.balance)) + '</td><td class="hide-m">' + whoCell(r) + revertCell(r) + '</td></tr>';
			}).join('') + '</tbody></table>';
		var cards = '<div class="mcards">' + rows.map(function (r) {
			return '<div class="mcard">' + avatar(r.member) + '<button type="button" class="who col" data-act="open" data-v="' + r.member.srl + '"><span class="nm">' + esc(r.member.nick || T('member_gone')) + ' ' + kindChip(r.kind) + '</span><span class="em">' + esc(r.why) + '</span><span class="em">' + esc(r.at) + (r.by === 'admin' ? ' · ' + T('by_admin') : '') + '</span></button><span class="mr"><b class="' + (r.amount > 0 ? 'plus' : 'minus') + '">' + signed(r.amount) + '</b><span>' + T('after_n', { n: fmt(r.balance) }) + '</span>' + (r.can_revert ? '<button type="button" class="linkbtn" data-act="revertrow" data-v="' + r.srl + '">' + T('cmd_revert') + '</button>' : '') + '</span></div>';
		}).join('') + '</div>';
		var more = '';
		if (D.next) {
			more = '<div class="morebox"><button type="button" class="btn" data-act="ledmore"' + (D.loading ? ' disabled' : '') + '>' + ic('chev', 14) + T('more_50') + '</button><div class="hint center mt6">' + T('ledger_more_hint') + '</div></div>';
		} else if (D.more) {
			more = '<div class="morebox"><button type="button" class="btn" data-act="ledbefore"' + (D.loading ? ' disabled' : '') + '>' + ic('chev', 14) + T('more_before') + '</button></div>';
		}
		var empty = rows.length ? '' : '<div class="emptybox">' + T('ledger_empty') + '</div>';
		return head + (rows.length ? table + cards : empty) + more;
	}

	/* 레벨 */
	function secLevel() {
		var V = S.levels;
		if (!V) {
			return '<h2>' + T('nav_level') + '</h2>' + pending('level');
		}
		var counts = V.counts;
		var shown = S.lvOpen ? V.steps.length : Math.min(12, V.steps.length);
		var max = 1;
		if (counts) {
			for (var i = 1; i <= shown; i++) {
				max = Math.max(max, counts[i] || 0);
			}
		}
		var groupsOf = function (level) {
			return (B.groups || []).filter(function (g) { return V.links[g.id] === level; });
		};
		var rows = '';
		for (var l = 1; l <= shown; l++) {
			var gs = groupsOf(l);
			rows += '<div class="lvrow' + (S.flash === 'lv' + l ? ' hl' : '') + '"><span class="lvn">' + lvIcon(l) + l + '</span><span>' + ptin(V.steps[l - 1], 'lv', String(l)) + '</span>' +
				(counts ? '<span class="ht"><i style="width:' + Math.round((counts[l] || 0) / max * 100) + '%"></i></span><span class="cnt">' + T('people_n', { n: fmt(counts[l] || 0) }) + '</span>' : '<span class="ht empty"></span><span class="cnt dim">—</span>') +
				'<span class="grp">' + gs.map(function (g) { return '<span class="gtag">' + esc(g.title) + '</span>'; }).join('') + '<button type="button" class="linkbtn small" data-act="pop" data-v="lvgroup" data-l="' + l + '" data-anchor="lvg' + l + '">' + (gs.length ? T('lv_group_edit') : T('lv_group_add')) + '</button></span></div>';
		}
		var restCount = 0;
		if (counts && !S.lvOpen) {
			for (var k = 13; k <= V.steps.length; k++) {
				restCount += counts[k] || 0;
			}
		}
		var rest = V.steps.length > 12 ? '<div class="lvrow dim"><span></span><span>' + (S.lvOpen ? '' : T('lv_rest', { from: 13, to: V.steps.length })) + '</span><span></span><span class="cnt">' + (S.lvOpen || !counts ? '' : T('people_n', { n: fmt(restCount) })) + '</span><span><button type="button" class="linkbtn" data-act="lvopen">' + T(S.lvOpen ? 'lv_fold' : 'lv_unfold') + '</button></span></div>' : '';
		var setName = function (s) {
			return s.id === 'default' ? T('icon_default') : s.id === 'default_vector' ? T('icon_vector') : s.id;
		};
		return '<h2>' + T('nav_level') + '</h2><p class="lead">' + T('level_lead') + '</p>' +
			'<div class="card">' + srow(T('lv_max'), T('lv_max_desc', { n: V.max_level }), '<input class="inp w80" id="pa-maxlv" inputmode="numeric" value="' + V.max_level + '" aria-label="' + esc(T('lv_max')) + '">') +
			srow(T('lv_icon'), T('lv_icon_desc'), '<select class="sel" id="pa-iconset" aria-label="' + esc(T('lv_icon')) + '">' + V.sets.map(function (s) { return '<option value="' + esc(s.id) + '"' + (s.id === V.icon ? ' selected' : '') + '>' + esc(setName(s)) + '</option>'; }).join('') + '</select>') + '</div>' +
			'<div class="card"><h3>' + T('lv_table') + '<span class="h-r"><button type="button" class="linkbtn" data-act="pop" data-v="formula" data-anchor="formula">' + T('lv_formula') + '</button></span></h3><p class="lead">' + T('lv_table_lead') + '</p>' +
			(counts ? '' : '<div class="note mb8">' + ic('info', 14) + '<span>' + T('lv_counts_off') + '</span></div>') +
			'<div class="lvrow head"><span>' + T('col_level') + '</span><span>' + T('lv_col_need') + '</span><span class="hth">' + T('lv_col_dist') + '</span><span class="cnt">' + T('lv_col_people') + '</span><span class="grp">' + T('lv_col_group') + '</span></div>' + rows + rest + '<div class="gap8"></div></div>' +
			'<div class="card"><h3>' + T('lv_groups') + '</h3><p class="lead">' + T('lv_groups_lead') + '</p>' +
			srow(T('lv_reset'), T('lv_reset_desc'), sw(V.group_reset, 'cfg', ' data-k="group_reset"', T('lv_reset'))) +
			srow(T('lv_ratchet'), T('lv_ratchet_desc'), sw(V.group_ratchet, 'cfg', ' data-k="group_ratchet"', T('lv_ratchet'))) + '</div>';
	}

	/* 쓰는 곳 */
	var USE_LOOK = {
		exchange: ['shop', '#0f766e'], kin: ['board', '#1b5fc0'], attendance: ['clock', '#7a3fb5'], profile: ['star', '#b45309'], meetup: ['users', '#be123c'],
		passport: ['map', '#0369a1'], reaction: ['smile', '#c2410c'], board: ['lock', '#475569'], commerce: ['tag', '#2f7d32'], esports: ['activity', '#334155'],
		lodging: ['home', '#0e7490'], reservation: ['clock', '#6d28d9']
	};
	function secUses() {
		var U = S.uses;
		if (!U) {
			return '<h2>' + T('nav_uses') + '</h2>' + pending('uses');
		}
		var card = function (c) {
			var look = USE_LOOK[c.id] || ['coin', '#475569'];
			var meta = [];
			if (c.week) {
				meta.push(T('use_week', { n: fmt(c.week.count), plus: fmt(c.week.plus), minus: fmt(c.week.minus) }));
			}
			(c.meta || []).forEach(function (m) { meta.push(T('use_meta_' + m)); });
			if (!c.on) {
				meta.push(T('use_not_in_ledger'));
			}
			var desc = c.id === 'exchange' && !c.on ? T('use_exchange_sep_desc') : T('use_' + c.id + '_desc');
			return '<div class="usecard' + (c.on ? '' : ' off') + '"><span class="ui" style="background:' + look[1] + '1a;color:' + look[1] + '">' + ic(look[0], 18) + '</span><span class="ut"><b>' + T('use_' + c.id) + '<span class="bdg' + (c.on ? '' : ' off') + '">' + T('kind_use_' + c.kind) + '</span></b><small>' + desc + '</small>' + (meta.length ? '<span class="meta">' + meta.map(function (x) { return '<span>' + esc(x) + '</span>'; }).join('') + '</span>' : '') + '</span>' + (c.url ? '<a class="linkbtn nowrap" href="' + esc(c.url) + '">' + T('use_settings') + ' ' + ic('ext', 12) + '</a>' : '') + '</div>';
		};
		var custom = (U.custom || []).map(function (c) {
			return '<div class="usecard' + (c.on ? '' : ' off') + '"><span class="ui" style="background:#4755691a;color:#475569">' + ic('coin', 18) + '</span><span class="ut"><b>' + esc(c.title) + '<span class="bdg' + (c.on ? '' : ' off') + '">' + T('kind_use_' + c.kind) + '</span></b><small>' + esc(c.description) + '</small>' + (c.meta.length ? '<span class="meta">' + c.meta.map(function (x) { return '<span>' + esc(x) + '</span>'; }).join('') + '</span>' : '') + '</span>' + (c.url ? '<a class="linkbtn nowrap" href="' + esc(c.url) + '">' + T('use_settings') + ' ' + ic('ext', 12) + '</a>' : '') + '</div>';
		}).join('');
		var others = Object.keys(U.others || {}).map(function (label) {
			var o = U.others[label];
			return '<div class="usecard"><span class="ui" style="background:#4755691a;color:#475569">' + ic('coin', 18) + '</span><span class="ut"><b>' + esc(label) + '<span class="bdg">' + T('use_other') + '</span></b><small>' + T('use_other_desc') + '</small><span class="meta"><span>' + esc(T('use_week', { n: fmt(o.count), plus: fmt(o.plus), minus: fmt(o.minus) })) + '</span></span></span></div>';
		}).join('');
		var on = U.cards.filter(function (c) { return c.on; });
		var off = U.cards.filter(function (c) { return !c.on; });
		return '<h2>' + T('nav_uses') + '</h2><p class="lead">' + T('uses_lead') + '</p><div class="uselist">' + on.map(card).join('') + custom + others + off.map(card).join('') + '</div>' +
			'<div class="note mt16">' + ic('info', 14) + '<span>' + T('uses_note', { separate: term('separate', T('term_separate_t')) }) + '</span></div>';
	}

	/* 설정 */
	function secPolicy() {
		var Pd = S.policy;
		if (!Pd) {
			return '<h2>' + T('nav_policy') + '</h2>' + pending('policy');
		}
		var off = !S.ledger || S.busy;
		var need = '<span class="dim small nowrap">' + T('need_ledger') + '</span>';
		var ledgerCtrl = S.busy ? '<span class="dim nowrap">' + T('prog_short', { n: (S.prog && S.prog.percent) || 0 }) + '</span>' : (S.ledger ? sw(true, 'ledgeroff', '', T('pol_ledger')) : '<button type="button" class="btn primary" data-act="ledgeron">' + T('off_btn') + '</button>');
		var statsDesc = T('pol_stats_desc') + (S.stats && Pd.stats_last ? ' ' + T('pol_stats_last', { time: timeOf(Pd.stats_last) }) : '');
		var html = '<h2>' + T('nav_policy') + '</h2><p class="lead">' + T('policy_lead') + '</p>' +
			'<div class="card" id="ledger-card">' +
			srow(T('pol_ledger') + newBadge(), T('pol_ledger_desc'), ledgerCtrl, S.busy ? progressCard(false) : '') +
			srow(T('pol_stats') + newBadge(), statsDesc, off ? need : sw(S.stats, 'statstoggle', '', T('pol_stats'))) + '</div>';
		html += '<div class="card">' +
			srow(T('pol_able'), T('pol_able_desc'), sw(Pd.able, 'cfg', ' data-k="able_module"', T('pol_able'))) +
			srow(T('pol_name'), T('pol_name_desc'), '<input class="inp w140" id="pa-pname" value="' + esc(Pd.point_name) + '" maxlength="20" aria-label="' + esc(T('pol_name')) + '">') +
			srow(T('pol_download'), T('pol_download_desc'), sw(Pd.disable_download, 'cfg', ' data-k="disable_download"', T('pol_download'))) +
			srow(T('pol_read'), T('pol_read_desc'), sw(Pd.disable_read_document, 'cfg', ' data-k="disable_read_document"', T('pol_read')),
				Pd.disable_read_document ? '<button type="button" class="checkline mt8" data-act="cfgchk" data-k="disable_read_document_except_robots">' + cbox(Pd.disable_read_document_except_robots) + '<span>' + T('pol_read_robots') + '</span></button>' : '') +
			srow(T('pol_mypage') + newBadge(), T('pol_mypage_desc'), sw(Pd.mypage, 'cfg', ' data-k="mypage_enabled"', T('pol_mypage'))) +
			srow(T('pol_big') + newBadge(), T('pol_big_desc', { per: fmt(B.big[0]), total: fmt(B.big[1]), members: fmt(B.big[2]) }), sw(Pd.big_confirm, 'cfg', ' data-k="big_confirm"', T('pol_big'))) + '</div>';
		var limitExtra = off ? '' : '<div class="inline mt8"><label>' + T('pol_limit_daily') + ' <input class="inp w100" id="pa-ldaily" inputmode="numeric" value="' + (Pd.limit_daily || '') + '" placeholder="200"></label><label>' + T('pol_limit_monthly') + ' <input class="inp w100" id="pa-lmonthly" inputmode="numeric" value="' + (Pd.limit_monthly || '') + '" placeholder="3000"></label></div>' +
			'<details class="mt8"' + (Pd.limit_reasons ? ' open' : '') + '><summary class="linkbtn">' + T('pol_limit_reasons') + '</summary><textarea class="inp mt6" id="pa-lreasons" rows="3" placeholder="insert_comment=50">' + esc(Pd.limit_reasons) + '</textarea><div class="hint">' + T('pol_limit_reasons_hint') + '</div></details>';
		var expireExtra = off ? '' : '<div class="inline mt8"><label>' + T('pol_expire_after') + ' <input class="inp w80" id="pa-emonths" inputmode="numeric" value="' + (Pd.expire_months || 12) + '"> ' + T('unit_months') + '</label></div>';
		var retOpts = [0, 24, 36, 60];
		if (Pd.retention && retOpts.indexOf(Pd.retention) < 0) {
			retOpts.push(Pd.retention);
		}
		var retSel = '<select class="sel" id="pa-retention" aria-label="' + esc(T('pol_retention')) + '">' + retOpts.map(function (m) {
			return '<option value="' + m + '"' + (Pd.retention === m ? ' selected' : '') + '>' + (m === 0 ? T('ret_off') : (m % 12 === 0 ? T('ret_years', { n: m / 12 }) : T('ret_months', { n: m }))) + '</option>';
		}).join('') + '</select>';
		var st = Pd.storage || {};
		var retNote = '';
		if (!off) {
			retNote = '<div class="note' + (st.rows >= 10000000 ? ' warn' : '') + ' mt10">' + ic('info', 14) + '<span>' + T('ret_now', { rows: '<b>' + rowsLabel(st.rows) + '</b>', size: '<b>' + bytesLabel(st.bytes) + '</b>' }) + (st.rows >= 10000000 ? ' ' + T('ret_big') : '') + '</span></div>';
		}
		html += '<div class="card' + (off ? ' dimcard' : '') + '">' +
			srow(T('pol_limit') + newBadge(), T('pol_limit_desc'), off ? need : sw(Pd.limit_enabled, 'cfg', ' data-k="limit_enabled"', T('pol_limit')), limitExtra) +
			srow(T('pol_expire') + newBadge(), T('pol_expire_desc'), off ? need : sw(Pd.expire_enabled, 'cfg', ' data-k="expire_enabled"', T('pol_expire')), expireExtra) +
			srow(T('pol_retention') + newBadge(), T('pol_retention_desc'), off ? need : retSel, retNote) + '</div>';
		var recal = S.recal ? '<div class="progress"><i style="width:' + S.recal.percent + '%"></i></div><div class="hint">' + T('recal_progress', { done: fmt(S.recal.done), total: fmt(S.recal.total) }) + '</div>' : '';
		html += '<div class="card">' + srow(T('pol_recal'), T('pol_recal_desc') + ' ' + T(S.ledger ? 'pol_recal_on' : 'pol_recal_off'), '<button type="button" class="btn" data-act="recal"' + (S.recal ? ' disabled' : '') + '>' + T('pol_recal_btn') + '</button>', recal) + '</div>';
		if (S.ledger && !S.busy) {
			var m = Pd.migration || {};
			var adv = '<div class="srow"><div class="st"><b>' + T('adv_migrate') + '</b><small>' + (m.done ? T('adv_migrate_done', { date: m.finished ? shortDate(m.finished.slice(0, 8)) : '' }) : T('adv_migrate_pending')) + (Pd.mismatches !== undefined ? ' ' + (Pd.mismatches ? T('adv_mismatch', { n: Pd.mismatches }) : T('adv_mismatch_none')) : '') + '</small></div><div class="sv"><button type="button" class="btn" data-act="mismatch">' + T('adv_check') + '</button><button type="button" class="btn" data-act="reconcile">' + T('adv_reconcile') + '</button></div></div>' +
				'<div class="srow"><div class="st"><b>' + T('adv_cron') + '</b><small>' + T('adv_cron_desc') + '</small><code class="cmd">php index.php point.maintenance</code><code class="cmd">php index.php point.stats</code><code class="cmd">php index.php point.migrate</code></div></div>';
			html += '<div class="card collap' + (S.advOpen ? ' open' : '') + '"><button type="button" class="collhead" data-act="adv"><span class="tw">' + ic('chevr', 14) + '</span><b>' + T('adv_title') + '</b><span class="sum">' + T('adv_sum') + '</span></button><div class="cbody">' + adv + '</div></div>';
		}
		return html;
	}
	function rowsLabel(n) {
		n = Number(n || 0);
		if (B.lang === 'ko' || B.lang === 'ja' || B.lang === 'zh-TW') {
			if (n >= 1e8) {
				return T('rows_eok', { n: fmt(Math.round(n / 1e7) / 10) });
			}
			if (n >= 1e4) {
				return T('rows_man', { n: fmt(Math.round(n / 1e4)) });
			}
		} else if (n >= 1e6) {
			return T('rows_million', { n: fmt(Math.round(n / 1e5) / 10) });
		}
		return T('rows_n', { n: fmt(n) });
	}

	/* 오른쪽 패널 */
	function panelHTML() {
		var D = S.panel;
		var m = D ? D.member : null;
		var head = '<div class="ph"><button type="button" class="btn ghost back" data-act="close">' + ic('back', 16) + T('cmd_back') + '</button>' +
			'<div class="ph-k">' + T('panel_kicker') + '<button type="button" class="iconbtn ph-x" data-act="close" aria-label="' + esc(T('cmd_close')) + '">' + ic('x', 18) + '</button></div>' +
			(m ? '<div class="phead">' + avatar(m) + '<div class="mn"><h2>' + esc(m.nick) + '</h2><div class="ph-sub">' + esc([m.name, m.uid].filter(Boolean).join(' · ')) + '</div></div></div>' : '') + '<div class="ph-gap"></div></div>';
		if (!D) {
			return '<aside class="panel" aria-label="' + esc(T('panel_kicker')) + '">' + head + '<div class="pbody">' + loading() + '</div></aside>';
		}
		var amts = [100, 500, 1000, 3000];
		var n = S.pt.amt || 0;
		var after = D.balance + (S.pt.mode === 'give' ? n : -n);
		var body = '<div class="sec"><div class="bigpt"><b>' + fmt(D.balance) + '</b><span>' + pointUnit() + ' · ' + term('level', T('level_n', { n: D.level })) + '</span></div><div class="hint">' + T('panel_level_hint') + '</div></div>' +
			'<div class="sec' + (S.flash === 'pt' ? ' flash' : '') + '"><h3>' + T('panel_give_take') + '</h3><div class="seg full"><button type="button" class="' + (S.pt.mode === 'give' ? 'on' : '') + '" data-act="ptmode" data-v="give">' + ic('plus', 14) + T('give') + '</button><button type="button" class="' + (S.pt.mode === 'take' ? 'on' : '') + '" data-act="ptmode" data-v="take">' + ic('minus', 14) + T('take') + '</button></div>' +
			'<div class="amounts">' + amts.map(function (a) { return '<button type="button" class="' + (S.pt.amt === a ? 'on' : '') + '" data-act="ptamt" data-v="' + a + '">' + fmt(a) + '</button>'; }).join('') + '</div>' +
			'<div class="field mt12"><label for="pa-ptamt">' + T('field_points') + '</label><input id="pa-ptamt" class="inp" inputmode="numeric" value="' + n + '"></div>' +
			(D.ledger_ready ? '<div class="field"><label for="pa-ptmemo">' + T('field_reason') + ' <span class="dim fw4">' + T('field_reason_req') + '</span></label><input id="pa-ptmemo" class="inp" maxlength="200" placeholder="' + esc(T('field_reason_ph')) + '" value="' + esc(S.pt.memo) + '"></div>' : '') +
			'<div class="note" id="pa-ptnote">' + ic('info', 14) + '<span>' + panelPreview(D, m, n, after) + '</span></div>' +
			'<button type="button" class="btn primary lg block mt12" id="pa-ptgo" data-act="ptgo"' + ((S.pt.memo.trim() || !D.ledger_ready) && n ? '' : ' disabled') + '>' + T(S.pt.mode === 'give' ? 'give_n' : 'take_n', { n: fmt(n) }) + '</button>' +
			(D.ledger_ready ? '' : '<p class="hint mt8">' + T('panel_off_hint') + '</p>') + '</div>';
		if (!D.ledger_ready) {
			body += '<div class="sec"><h3>' + term('ledger', T('term_ledger_t')) + '</h3>' + offCard('small') + '</div>';
		} else {
			body += '<div class="sec"><h3>' + term('ledger', T('term_ledger_t')) + '<button type="button" class="h-r linkbtn" data-act="ptfull">' + T('panel_all') + ' ' + ic('chevr', 14) + '</button></h3><div class="list">' +
				(D.recent.length ? D.recent.map(function (l) {
					return '<div class="li"><span class="lic">' + ic(l.amount >= 0 ? 'plus' : 'minus', 15) + '</span><span class="mn">' + esc(l.why) + '<small>' + esc(l.at) + (l.by === 'admin' ? ' · ' + T('by_admin') : '') + '</small></span><span class="r ' + (l.amount >= 0 ? 'plus' : 'minus') + '">' + signed(l.amount) + '</span></div>';
				}).join('') : '<p class="empty">' + T('panel_no_rows') + '</p>') + '</div></div>';
		}
		var foot = '<div class="pfoot"><span class="legacy"><a href="#" data-act="ptfull">' + T(D.ledger_ready ? 'panel_foot_ledger' : 'panel_foot_tab') + ' ' + ic('chevr', 12) + '</a><a href="' + esc(B.urls.member_info + m.srl) + '">' + T('panel_member_info') + ' ' + ic('ext', 12) + '</a></span></div>';
		return '<aside class="panel" aria-label="' + esc(T('panel_kicker')) + '">' + head + '<div class="pbody" id="pa-pbody">' + body + '</div>' + foot + '</aside>';
	}
	function panelPreview(D, m, n, after) {
		var text = T('panel_preview', { nick: esc(m.nick), from: fmt(D.balance), to: fmt(after) });
		if (D.ledger_ready) {
			text += ' ' + T('panel_preview_ledger', { ledger: term('ledger', T('term_ledger_t')) });
		}
		if (S.pt.mode === 'take' && after < 0) {
			text += ' ' + T('take_negative_hint');
		}
		return text;
	}
	function refreshPanelPreview() {
		var D = S.panel;
		if (!D) {
			return;
		}
		var n = S.pt.amt || 0;
		var after = D.balance + (S.pt.mode === 'give' ? n : -n);
		var note = document.getElementById('pa-ptnote');
		if (note) {
			note.innerHTML = ic('info', 14) + '<span>' + panelPreview(D, D.member, n, after) + '</span>';
		}
		var go = document.getElementById('pa-ptgo');
		if (go) {
			go.disabled = !((S.pt.memo.trim() || !D.ledger_ready) && n);
			go.textContent = T(S.pt.mode === 'give' ? 'give_n' : 'take_n', { n: fmt(n) });
		}
	}

	/* 팝오버 · 창 · 알림 */
	function popHTML() {
		var p = S.pop;
		if (!p) {
			return '';
		}
		if (p.type === 'bulk') {
			var n = S.sel.length;
			var a = S.pt.amt || 0;
			var names = S.sel.slice(0, 3).map(function (id) { return S.selNames[id] || T('member_gone'); });
			var who = n <= 3 ? names.join('·') : T('names_more', { names: names.slice(0, 2).join('·'), n: n - 2 });
			return '<div class="pop" style="width:360px"><h4>' + ic('coin', 16) + T(S.pt.mode === 'give' ? 'bulk_title_give' : 'bulk_title_take', { n: fmt(n) }) + '</h4><p class="pl">' + esc(who) + '</p>' +
				'<div class="seg full"><button type="button" class="' + (S.pt.mode === 'give' ? 'on' : '') + '" data-act="ptmode" data-v="give">' + T('give') + '</button><button type="button" class="' + (S.pt.mode === 'take' ? 'on' : '') + '" data-act="ptmode" data-v="take">' + T('take') + '</button></div>' +
				'<div class="amounts">' + [100, 500, 1000, 3000].map(function (x) { return '<button type="button" class="' + (a === x ? 'on' : '') + '" data-act="ptamt" data-v="' + x + '">' + fmt(x) + '</button>'; }).join('') + '</div>' +
				'<div class="field mt12"><label for="pa-bpamt">' + T('field_points') + '</label><input id="pa-bpamt" class="inp" inputmode="numeric" value="' + a + '"></div>' +
				(S.ledger ? '<div class="field"><label for="pa-bpmemo">' + T('field_reason') + ' <span class="dim fw4">' + T('field_reason_req') + '</span></label><input id="pa-bpmemo" class="inp" maxlength="200" placeholder="' + esc(T('field_reason_bulk_ph')) + '" value="' + esc(S.pt.memo) + '"></div>' : '') +
				'<div class="impact" id="pa-impact"><div><b>' + fmt(a) + T('unit_point') + '</b><span>' + T('impact_one') + '</span></div><div><b>' + fmt(a * n) + T('unit_point') + '</b><span>' + T('impact_all', { n: fmt(n) }) + '</span></div></div>' +
				'<div class="hint">' + (S.pt.mode === 'take' ? T('take_negative_example') + ' ' : '') + T(S.ledger ? 'bulk_hint_ledger' : 'bulk_hint_off') + '</div>' +
				'<div class="pf"><button type="button" class="btn" data-act="popclose">' + T('cmd_close') + '</button><button type="button" class="btn primary" id="pa-bpgo" data-act="bulkgo"' + ((S.pt.memo.trim() || !S.ledger) && a ? '' : ' disabled') + '>' + T(S.pt.mode === 'give' ? 'bulk_go_give' : 'bulk_go_take', { n: fmt(a) }) + '</button></div></div>';
		}
		if (p.type === 'cond') {
			var r = findRule(p.k);
			var R = S.rules;
			return '<div class="pop" style="width:320px"><h4>' + T('cond_title', { rule: T('rule_' + p.k) }) + '</h4><p class="pl">' + T('cond_lead') + '</p>' +
				(r.limit ? '<div class="field"><label for="pa-cdays">' + T('cond_days_label') + '</label><div class="inline"><input id="pa-cdays" class="inp w80" inputmode="numeric" value="' + (R.limits[p.k] || 0) + '"> <span class="dim">' + T('cond_days_unit') + '</span></div><div class="hint">' + T('cond_days_hint') + '</div></div>' : '') +
				(r.notice ? '<button type="button" class="checkline mt8" data-act="condnotice" data-k="' + p.k + '">' + cbox(R.notice[p.k]) + '<span>' + T('cond_notice') + '</span></button>' : '') +
				'<div class="pf"><button type="button" class="btn" data-act="popclose">' + T('cmd_close') + '</button>' + (r.limit ? '<button type="button" class="btn primary" data-act="condsave" data-k="' + p.k + '">' + T('cmd_save') + '</button>' : '') + '</div></div>';
		}
		if (p.type === 'period') {
			var D = S.led;
			var opts = [['today', B.today, B.today], ['7', addDays(B.today, -6), B.today], ['week', B.week, B.today], ['lastweek', addDays(B.week, -7), addDays(B.week, -1)], ['31', addDays(B.today, -30), B.today]];
			return '<div class="pop" style="width:300px"><h4>' + T('period_title') + '</h4><div class="optlist">' + (D.member || !(D.kind || D.reason || D.type || D.direction || D.actor) ? '<button type="button" class="opt' + (!D.from && !D.to ? ' on' : '') + '" data-act="period" data-from="" data-to="">' + T('period_all') + '</button>' : '') +
				opts.map(function (o) { return '<button type="button" class="opt' + (D.from === o[1] && D.to === o[2] ? ' on' : '') + '" data-act="period" data-from="' + o[1] + '" data-to="' + o[2] + '">' + T('period_' + o[0]) + '<small>' + shortDate(o[1]) + (o[1] !== o[2] ? ' ~ ' + shortDate(o[2]) : '') + '</small></button>'; }).join('') + '</div>' +
				'<div class="inline mt8"><input type="date" class="inp" id="pa-dfrom" value="' + (D.from ? D.from.slice(0, 4) + '-' + D.from.slice(4, 6) + '-' + D.from.slice(6, 8) : '') + '" aria-label="' + esc(T('period_from')) + '"><span class="dim">~</span><input type="date" class="inp" id="pa-dto" value="' + (D.to ? D.to.slice(0, 4) + '-' + D.to.slice(4, 6) + '-' + D.to.slice(6, 8) : '') + '" aria-label="' + esc(T('period_to')) + '"></div>' +
				'<div class="pf"><button type="button" class="btn" data-act="popclose">' + T('cmd_close') + '</button><button type="button" class="btn primary" data-act="periodgo">' + T('cmd_apply') + '</button></div></div>';
		}
		if (p.type === 'pickmember') {
			return '<div class="pop" style="width:320px"><h4>' + T('pick_title') + '</h4><label class="search">' + ic('search', 16) + '<input id="pa-pickq" type="search" placeholder="' + esc(T('mem_search')) + '" value="' + esc(p.q || '') + '" aria-label="' + esc(T('mem_search')) + '"></label><div class="optlist mt8" id="pa-picklist">' + pickList(p) + '</div></div>';
		}
		if (p.type === 'lvgroup') {
			var V = S.levels;
			var linkable = (B.groups || []).filter(function (g) { return !g.admin && !g.def; });
			return '<div class="pop" style="width:320px"><h4>' + T('lvg_title', { n: p.l }) + '</h4><p class="pl">' + T('lvg_lead') + '</p><div class="optlist">' +
				(linkable.length ? linkable.map(function (g) {
					var at = V.links[g.id];
					return '<button type="button" class="opt' + (at === p.l ? ' on' : '') + '" data-act="lvglink" data-g="' + g.id + '" data-l="' + p.l + '">' + cbox(at === p.l) + esc(g.title) + (at && at !== p.l ? '<small>' + T('level_n', { n: at }) + '</small>' : '') + '</button>';
				}).join('') : '<p class="empty">' + T('lvg_none') + '</p>') + '</div><div class="pf"><button type="button" class="btn" data-act="popclose">' + T('cmd_close') + '</button></div></div>';
		}
		if (p.type === 'formula') {
			var V2 = S.levels;
			return '<div class="pop" style="width:360px"><h4>' + T('formula_title') + '</h4><p class="pl">' + T('formula_lead') + '</p><input class="inp" id="pa-expr" value="' + esc(V2.expression || 'Math.pow(i, 2) * 90') + '" aria-label="' + esc(T('formula_title')) + '"><div class="hint" id="pa-exprprev">' + formulaPreview(V2.expression || 'Math.pow(i, 2) * 90') + '</div><div class="pf"><button type="button" class="btn" data-act="popclose">' + T('cmd_close') + '</button><button type="button" class="btn primary" data-act="formulago">' + T('formula_go', { n: V2.max_level }) + '</button></div></div>';
		}
		return '';
	}
	function pickList(p) {
		if (!p.rows) {
			return '<p class="empty">' + T('pick_hint') + '</p>';
		}
		if (!p.rows.length) {
			return '<p class="empty">' + T('mem_empty_search') + '</p>';
		}
		return p.rows.slice(0, 8).map(function (m) {
			return '<button type="button" class="opt" data-act="ledmember" data-v="' + m.srl + '">' + avatar(m) + esc(m.nick) + '<small>' + esc(m.uid) + '</small></button>';
		}).join('');
	}
	function formulaFn(expr) {
		if (!/^[\d\s+\-*/().,i]*(Math\.(pow|round|floor|ceil|sqrt|min|max)[\d\s+\-*/().,i]*)*$/.test(expr)) {
			return null;
		}
		try {
			var fn = new Function('i', 'return (' + expr + ');');
			var v = fn(2);
			return isFinite(v) ? fn : null;
		} catch (e) {
			return null;
		}
	}
	function formulaPreview(expr) {
		var fn = formulaFn(expr);
		if (!fn) {
			return T('formula_bad');
		}
		return T('formula_preview', { list: [1, 2, 3, 10].map(function (i) { return T('level_n', { n: i }) + ' ' + fmt(Math.round(fn(i))); }).join(' · ') });
	}
	function modalHTML() {
		var m = S.modal;
		if (!m) {
			return '';
		}
		if (m.type === 'ledgeron') {
			var e = S.est;
			var c = S.chk;
			var ck = function (k, t, d) {
				return '<button type="button" class="checkline py6" data-act="chk" data-v="' + k + '">' + cbox(c[k]) + '<span><b class="fw6">' + t + '</b><small class="block dim">' + d + '</small></span></button>';
			};
			var members = e ? fmt(e.members) : '…';
			var perDay = e && e.estimate ? fmt(e.estimate.per_day) : '…';
			var perYear = e && e.estimate ? rowsLabel(e.estimate.per_year) : '…';
			var size = e && e.estimate ? bytesLabel(e.estimate.bytes_year) : '…';
			return '<div class="modal-back" data-act="modalclose"><div class="modal w560" role="dialog" aria-modal="true" aria-label="' + esc(T('on_title')) + '" data-stop="1"><div class="mh"><h3>' + T('on_title') + '</h3><p>' + T('on_lead') + '</p></div><div class="mb"><div class="list uses">' +
				'<div class="li"><span class="lic">' + ic('users', 15) + '</span><span><b class="fw6">' + T('on_move', { n: members }) + '</b><small>' + T('on_move_desc') + '</small></span></div>' +
				'<div class="li"><span class="lic">' + ic('note', 15) + '</span><span><b class="fw6">' + T('on_rows', { n: perDay }) + '</b><small>' + T('on_rows_desc', { year: perYear, size: size }) + '</small></span></div>' +
				'<div class="li"><span class="lic">' + ic('check', 15) + '</span><span><b class="fw6">' + T('on_can') + '</b><small>' + T('on_can_desc') + '</small></span></div>' +
				'</div><div class="mt12">' + ck('ret', T('on_with_ret'), T('on_with_ret_desc')) + ck('stats', T('on_with_stats'), T('on_with_stats_desc')) + '</div>' +
				'<div class="note mt12">' + ic('info', 14) + '<span>' + T('on_note') + '</span></div></div>' +
				'<div class="mf"><button type="button" class="btn" data-act="modalclose">' + T('cmd_keep') + '</button><button type="button" class="btn primary" data-act="ledgergo">' + T('off_btn') + '</button></div></div></div>';
		}
		if (m.type === 'confirm') {
			return '<div class="modal-back" data-act="modalclose"><div class="modal" role="dialog" aria-modal="true" aria-label="' + esc(m.title) + '" data-stop="1"><div class="mh"><h3>' + esc(m.title) + '</h3><p>' + esc(m.text || '') + '</p></div><div class="mf"><button type="button" class="btn" data-act="modalclose">' + T('cmd_keep') + '</button><button type="button" class="btn ' + (m.danger ? 'danger' : 'primary') + '" data-act="confirmgo">' + esc(m.ok) + '</button></div></div></div>';
		}
		return '';
	}
	function toastHTML() {
		var t = S.toast;
		if (!t) {
			return '';
		}
		return '<div class="toast" role="status"><span>' + esc(t.title) + (t.sub ? '<span class="t-sub">' + esc(t.sub) + '</span>' : '') + '</span>' + (t.undo ? '<button type="button" data-act="toastundo">' + esc(t.label) + '</button>' : '') + '<button type="button" class="t-x iconbtn" data-act="toastx" aria-label="' + esc(T('cmd_close')) + '">' + ic('x', 16) + '</button></div>';
	}
	function renderLayer() {
		var layer = document.getElementById('pa-layer');
		var focusId = document.activeElement && layer.contains(document.activeElement) ? document.activeElement.id : '';
		layer.innerHTML = popHTML() + modalHTML() + toastHTML();
		placePop();
		if (focusId) {
			var f = document.getElementById(focusId);
			if (f) {
				f.focus();
				if (f.setSelectionRange && f.value !== undefined && f.type !== 'number' && f.type !== 'date') {
					f.setSelectionRange(f.value.length, f.value.length);
				}
			}
		}
	}
	function placePop() {
		var p = document.querySelector('#pa-layer .pop');
		if (!p || !S.pop) {
			return;
		}
		if (window.innerWidth < 768) {
			p.style.cssText = 'left:8px;right:8px;bottom:8px;top:auto;width:auto;max-height:72vh;overflow:auto';
			return;
		}
		var a = document.querySelector('[data-anchor="' + S.pop.anchor + '"]');
		if (!a) {
			return;
		}
		var r = a.getBoundingClientRect();
		var w = p.offsetWidth;
		var h = p.offsetHeight;
		var x = r.left;
		var y = r.bottom + 6;
		if (x + w > window.innerWidth - 8) {
			x = r.right - w;
		}
		if (y + h > window.innerHeight - 8) {
			y = Math.max(8, r.top - h - 6);
		}
		p.style.left = Math.max(8, x) + 'px';
		p.style.top = y + 'px';
	}

	/* 말풍선 */
	var tipEl = document.createElement('div');
	tipEl.className = 'termtip';
	tipEl.id = 'pa-termtip';
	tipEl.setAttribute('role', 'tooltip');
	tipEl.hidden = true;
	root.appendChild(tipEl);
	var tipFor = null;
	function showTip(el) {
		var key = el.getAttribute('data-term');
		var t = T('term_' + key + '_t');
		if (t === 'term_' + key + '_t') {
			return;
		}
		tipFor = el;
		el.setAttribute('aria-describedby', 'pa-termtip');
		var ex = L['term_' + key + '_ex'];
		tipEl.innerHTML = '<b>' + t + '</b><span>' + T('term_' + key + '_d') + '</span>' + (ex ? '<em>' + ex + '</em>' : '');
		tipEl.hidden = false;
		var r = el.getBoundingClientRect();
		var w = tipEl.offsetWidth;
		var h = tipEl.offsetHeight;
		var x = r.left + r.width / 2 - w / 2;
		var y = r.bottom + 8;
		if (y + h > window.innerHeight - 8) {
			y = r.top - h - 8;
		}
		x = Math.max(8, Math.min(x, window.innerWidth - w - 8));
		tipEl.style.left = x + 'px';
		tipEl.style.top = y + 'px';
	}
	function hideTip() {
		if (tipFor) {
			tipFor.removeAttribute('aria-describedby');
		}
		tipFor = null;
		tipEl.hidden = true;
	}
	root.addEventListener('mouseover', function (e) {
		var t = e.target.closest('.term');
		if (t) {
			showTip(t);
		} else if (tipFor) {
			hideTip();
		}
	});
	root.addEventListener('focusin', function (e) {
		var t = e.target.closest('.term');
		if (t) {
			showTip(t);
		}
	});
	root.addEventListener('focusout', function (e) {
		if (e.target.closest('.term')) {
			hideTip();
		}
	});
	window.addEventListener('scroll', hideTip, true);

	/* ── 불러오기 ── */
	var loadingNow = {};
	function load(what, force) {
		if (loadingNow[what] && !force) {
			return;
		}
		loadingNow[what] = true;
		S.err[what] = false;
		var done = function () {
			loadingNow[what] = false;
		};
		var p;
		if (what === 'dash') {
			p = api('getPointAdminDashboard', { week: S.week }).then(function (d) {
				S.dash = d;
			});
		} else if (what === 'rules') {
			p = api('getPointAdminRules').then(function (d) {
				S.rules = d;
			});
		} else if (what === 'members') {
			S.mem.loading = true;
			var M = S.mem;
			p = api('getPointAdminMemberList', { q: M.q, sort: M.sort, group_srl: M.group, search_target: M.target, search_keyword: M.keyword, cursor: force === 'more' ? M.next : '' }).then(function (d) {
				M.loading = false;
				M.rows = force === 'more' ? (M.rows || []).concat(d.rows) : d.rows;
				M.next = d.next;
				M.capped = d.capped;
				if (d.sort !== M.sort) {
					M.sort = d.sort;
				}
				d.rows.forEach(function (m) { S.selNames[m.srl] = m.nick; });
			});
			if (!S.levels) {
				load('level');
			}
		} else if (what === 'ledger') {
			var D = S.led;
			D.loading = true;
			var params = ledgerQuery();
			if (force === 'more') {
				params.cursor = D.next;
			}
			if (force === 'before') {
				params.before = D.more;
			}
			p = api('getPointAdminLedgerPage', params).then(function (d) {
				D.loading = false;
				D.rows = (force === 'more' || force === 'before') ? (D.rows || []).concat(d.rows) : d.rows;
				D.next = d.next;
				D.more = d.more_before;
				if (d.narrow) {
					D.from = d.date_from;
					D.to = d.date_to;
				}
				if (d.member) {
					D.memberCard = d.member;
				}
			});
		} else if (what === 'level') {
			p = api('getPointAdminLevels').then(function (d) {
				S.levels = d;
			});
		} else if (what === 'uses') {
			p = api('getPointAdminUsage').then(function (d) {
				S.uses = d;
			});
		} else if (what === 'policy') {
			p = api('getPointAdminPolicy').then(function (d) {
				S.policy = d;
				S.expireOn = d.expire_enabled;
			});
		}
		return p.then(function () {
			done();
			renderWork();
		}).catch(function (e) {
			done();
			S.err[what] = true;
			if (what === 'members') {
				S.mem.loading = false;
			}
			if (what === 'ledger') {
				S.led.loading = false;
			}
			fail(e);
		});
	}
	function openMember(srl) {
		srl = +srl;
		if (!srl) {
			return;
		}
		S.open = srl;
		S.panel = null;
		S.pt.memo = '';
		renderWork();
		api('getPointAdminMemberPoint', { member_srl: srl }).then(function (d) {
			if (S.open === srl) {
				S.panel = d;
				renderWork();
			}
		}).catch(fail);
	}
	function refreshAfterPoints() {
		S.dash = null;
		if (S.mem.rows) {
			load('members', true);
		}
		if (S.led.rows && S.sec === 'ledger') {
			load('ledger', true);
		} else {
			S.led.rows = null;
		}
		if (S.open) {
			api('getPointAdminMemberPoint', { member_srl: S.open }).then(function (d) {
				S.panel = d;
				renderWork();
			});
		}
	}

	/* ── 저장 ── */
	function saveRule(k, v) {
		var R = S.rules;
		var old = R.values[k];
		if (old === v) {
			render();
			return;
		}
		var go = function () {
			R.values[k] = v;
			S.flash = k;
			saving(true);
			render();
			api('procPointAdminSaveRule', { key: k, value: v }).then(function () {
				saving(false);
				var item = pushUndo(T('undo_rule', { rule: T('rule_' + k) }), function () {
					return api('procPointAdminSaveRule', { key: k, value: old }).then(function () { R.values[k] = old; });
				});
				toast(T('toast_rule', { rule: T('rule_' + k), from: fmt(old), to: fmt(v) }), T('toast_rule_sub'), item);
				render();
			}).catch(function (e) {
				R.values[k] = old;
				fail(e);
			});
		};
		var big = old !== 0 && (Math.sign(v) !== Math.sign(old) || Math.abs(v) >= Math.abs(old) * 10) && Math.abs(v - old) >= 50;
		if (big) {
			S.modal = { type: 'confirm', title: T('big_rule_title', { rule: T('rule_' + k), from: fmt(old), to: fmt(v) }), text: T('big_rule_text'), ok: T('big_rule_ok'), run: go };
			render();
			return;
		}
		go();
	}
	function saveBoard(k, srl, v) {
		var R = S.rules;
		var map = R.overrides[k] = R.overrides[k] || {};
		var old = map[srl];
		var board = R.boards.filter(function (b) { return b.srl === +srl; })[0] || { title: '' };
		if (v === null) {
			delete map[srl];
		} else {
			map[srl] = v;
		}
		saving(true);
		render();
		api('procPointAdminSaveModuleRule', { module_srl: srl, key: k, value: v === null ? '' : v }).then(function () {
			saving(false);
			var item = pushUndo(T('undo_board', { board: board.title }), function () {
				return api('procPointAdminSaveModuleRule', { module_srl: srl, key: k, value: old === undefined ? '' : old }).then(function () {
					if (old === undefined) {
						delete map[srl];
					} else {
						map[srl] = old;
					}
				});
			});
			if (v === null) {
				toast(T('toast_board_reset', { board: board.title }), '', item);
			} else {
				toast(T('toast_board', { board: board.title, n: (v > 0 ? '+' : '') + fmt(v) }), T('toast_board_sub'), item);
			}
			render();
		}).catch(function (e) {
			if (old === undefined) {
				delete map[srl];
			} else {
				map[srl] = old;
			}
			fail(e);
		});
	}
	function saveRuleKey(key, value, label, onDone) {
		saving(true);
		return api('procPointAdminSaveRule', { key: key, value: value }).then(function (d) {
			saving(false);
			if (onDone) {
				onDone(d);
			}
			render();
			return d;
		}).catch(fail);
	}
	function saveLevel(l, v) {
		var V = S.levels;
		var old = V.steps[l - 1];
		if (old === v || v < 0) {
			render();
			return;
		}
		V.steps[l - 1] = v;
		S.flash = 'lv' + l;
		saving(true);
		render();
		api('procPointAdminSaveLevelStep', { level: l, value: v }).then(function (d) {
			saving(false);
			var item = pushUndo(T('undo_level', { n: l }), function () {
				return api('procPointAdminSaveLevelStep', { level: l, value: old }).then(function () { V.steps[l - 1] = old; });
			});
			var sub;
			if (d.moved !== null && d.moved !== undefined) {
				sub = v < old ? T('toast_level_up', { n: fmt(d.moved), level: l }) : T('toast_level_down', { n: fmt(d.moved), level: l });
			} else {
				sub = T('toast_level_next');
			}
			toast(T('toast_level', { level: l, from: fmt(old), to: fmt(v) }), sub, item);
			if (V.counts) {
				load('level', true);
			}
			render();
		}).catch(function (e) {
			V.steps[l - 1] = old;
			fail(e);
		});
	}
	function saveConfig(key, value, okTitle, okSub, revertFn) {
		saving(true);
		return api('procPointAdminSaveConfigKey', { key: key, value: value }).then(function (d) {
			saving(false);
			var item = null;
			if (revertFn) {
				var old = d.old;
				item = pushUndo(okTitle, function () {
					return api('procPointAdminSaveConfigKey', { key: key, value: revertFn(old) }).then(function () { S.policy = null; S.levels = null; });
				});
			}
			if (okTitle) {
				toast(okTitle, okSub || '', item);
				renderLayer();
			}
			return d;
		}).catch(function (e) {
			fail(e);
			throw e;
		});
	}

	/* 지급 · 회수 */
	function needsBigConfirm(perMember, count) {
		if (!S.bigConfirm) {
			return false;
		}
		return Math.abs(perMember) > B.big[0] || Math.abs(perMember) * count >= B.big[1] || count >= B.big[2];
	}
	function giveOne() {
		var D = S.panel;
		var n = S.pt.amt || 0;
		if (!D || !n) {
			return;
		}
		var srl = D.member.srl;
		var nick = D.member.nick;
		var amount = S.pt.mode === 'give' ? n : -n;
		var memo = S.pt.memo.trim();
		var go = function () {
			saving(true);
			api('procPointAdminUpdatePoint', { member_srl: srl, point: (amount > 0 ? '+' : '-') + Math.abs(amount), memo: memo }).then(function (d) {
				saving(false);
				S.pt.memo = '';
				var item;
				var title = T(amount > 0 ? 'toast_gave' : 'toast_took', { nick: nick, n: fmt(Math.abs(amount)) });
				var sub = D.ledger_ready ? T('toast_gave_sub_ledger', { memo: memo }) : T('toast_gave_sub_off');
				if (D.ledger_ready && amount > 0 && d.ledger_srl) {
					item = pushUndo(T('undo_give', { nick: nick }), function () {
						return api('procPointAdminRevert', { ledger_srl: d.ledger_srl }).then(refreshAfterPoints);
					});
					toast(title, sub, item, T('cmd_revert'));
				} else {
					item = pushUndo(T('undo_give', { nick: nick }), function () {
						return api('procPointAdminUpdatePoint', { member_srl: srl, point: (amount > 0 ? '-' : '+') + Math.abs(amount), memo: D.ledger_ready ? T('memo_undo', { memo: memo }) : '' }).then(refreshAfterPoints);
					});
					toast(title, sub, item);
				}
				refreshAfterPoints();
				render();
			}).catch(fail);
		};
		if (needsBigConfirm(amount, 1)) {
			S.modal = { type: 'confirm', title: T('big_title', { total: fmt(Math.abs(amount)) }), text: T('big_text_one', { nick: nick, n: fmt(Math.abs(amount)) }), ok: T(amount > 0 ? 'give_n' : 'take_n', { n: fmt(Math.abs(amount)) }), run: go };
			render();
			return;
		}
		go();
	}
	function giveBulk() {
		var n = S.pt.amt || 0;
		var srls = S.sel.slice();
		if (!n || !srls.length) {
			return;
		}
		var amount = S.pt.mode === 'give' ? n : -n;
		var memo = S.pt.memo.trim();
		var go = function () {
			if (S.bulkBusy) {
				return;
			}
			S.bulkBusy = true;
			S.pt.batch = S.pt.batch || ('b' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10));
			S.pop = null;
			saving(true);
			render();
			api('procPointAdminBulkAdjust', { member_srls: srls.join(','), amount: amount, memo: memo, batch_key: S.pt.batch }).then(function (d) {
				S.bulkBusy = false;
				S.pt.batch = '';
				saving(false);
				S.pt.memo = '';
				S.sel = [];
				var title = T(amount > 0 ? 'toast_bulk_give' : 'toast_bulk_take', { n: fmt(d.count), a: fmt(Math.abs(amount)) });
				var sub = d.failed.length ? T('toast_bulk_failed', { n: fmt(d.failed.length) }) : (d.ledger ? T('toast_bulk_sub_ledger') : T('toast_gave_sub_off'));
				var item;
				if (d.ledger && amount > 0) {
					item = pushUndo(T('undo_bulk', { n: d.count }), function () {
						return api('procPointAdminRevertBatch', { batch: d.batch }).then(refreshAfterPoints);
					});
					toast(title, sub, item, T('cmd_revert'));
				} else {
					var done = Object.keys(d.balances || {});
					item = pushUndo(T('undo_bulk', { n: d.count }), function () {
						return api('procPointAdminBulkAdjust', { member_srls: done.join(','), amount: -amount, memo: d.ledger ? T('memo_undo', { memo: memo }) : '' }).then(refreshAfterPoints);
					});
					toast(title, sub, item);
				}
				refreshAfterPoints();
				render();
			}).catch(function (e) {
				S.bulkBusy = false;
				if (!S.ledger) {
					S.pt.batch = '';
				}
				fail(e);
			});
		};
		if (needsBigConfirm(amount, srls.length)) {
			S.modal = { type: 'confirm', title: T('big_title', { total: fmt(Math.abs(amount) * srls.length) }), text: T('big_text', { n: fmt(srls.length), a: fmt(Math.abs(amount)) }), ok: T(amount > 0 ? 'bulk_go_give' : 'bulk_go_take', { n: fmt(Math.abs(amount)) }), run: go };
			render();
			return;
		}
		go();
	}

	/* 내역 기록 켜기 · 배치 */
	function runJob(job, onDone) {
		api('procPointAdminRunBatch', { job: job }).then(function (d) {
			S.prog = { percent: d.percent, total: d.total };
			if (d.next) {
				renderWork();
				runJob(d.next, onDone);
				return;
			}
			if (d.done) {
				onDone();
				return;
			}
			renderWork();
			setTimeout(function () { runJob(job === 'reconcile_start' ? 'reconcile' : job, onDone); }, 150);
		}).catch(function (e) {
			fail(e);
		});
	}
	function startLedger() {
		var c = S.chk;
		S.modal = null;
		S.sec = 'policy';
		S.open = null;
		S.prog = { percent: 0, total: S.est ? S.est.members : 0 };
		S.busy = true;
		saving(true);
		render();
		api('procPointAdminEnableLedger', { retention: c.ret ? 'Y' : 'N', stats: c.stats ? 'Y' : 'N' }).then(function (d) {
			S.ledger = true;
			S.stats = !!d.stats;
			runJob(d.job, function () {
				var finish = function () {
					S.busy = false;
					S.prog = null;
					saving(false);
					S.policy = null;
					S.dash = null;
					S.led.rows = null;
					S.panel = null;
					toast(T('toast_on'), T('toast_on_sub', { n: fmt(S.est ? S.est.members : 0) }), null);
					render();
				};
				if (S.stats) {
					runJob('stats', finish);
				} else {
					finish();
				}
			});
		}).catch(fail);
	}
	function resumeJob() {
		if (S.busy && S.job) {
			S.prog = S.prog || { percent: 0, total: 0 };
			runJob(S.job, function () {
				S.busy = false;
				S.prog = null;
				S.policy = null;
				S.dash = null;
				toast(T('toast_on'), T('toast_on_sub_short'), null);
				render();
			});
		}
	}
	function startRecal() {
		S.recal = { percent: 0, done: 0, total: 0 };
		saving(true);
		render();
		var step = function (d) {
			var total = +d.total || 0;
			var pos = +d.position || 0;
			S.recal = { percent: total ? Math.round(pos / total * 100) : 100, done: pos, total: total };
			renderWork();
			if (pos >= total) {
				S.recal = null;
				saving(false);
				toast(T('toast_recal'), T(S.ledger ? 'toast_recal_sub_on' : 'toast_recal_sub_off'), null);
				refreshAfterPoints();
				render();
				return;
			}
			api('procPointAdminApplyPoint', { position: pos, total: total }).then(step).catch(function (e) {
				S.recal = null;
				fail(e);
			});
		};
		api('procPointAdminReCal').then(step).catch(function (e) {
			S.recal = null;
			fail(e);
		});
	}

	/* ── 클릭 ── */
	document.addEventListener('click', function (e) {
		if (!root.contains(e.target)) {
			return;
		}
		var el = e.target.closest('[data-act]');
		if (S.pop && !e.target.closest('.pop') && !(el && el.getAttribute('data-act') === 'pop')) {
			S.pop = null;
			renderLayer();
			if (!el) {
				return;
			}
		}
		if (!el) {
			return;
		}
		var act = el.getAttribute('data-act');
		var v = el.getAttribute('data-v');
		if (act === 'modalclose' && e.target.closest('[data-stop]') && el.classList.contains('modal-back')) {
			return;
		}
		if (el.tagName === 'A' && act !== 'ptfull') {
			return;
		}
		if (act === 'open' && e.target.closest('button[data-act="sel"]')) {
			return;
		}
		e.preventDefault();
		handle(act, v, el);
	});
	function handle(act, v, el) {
		var k = el.getAttribute('data-k');
		switch (act) {
			case 'sec':
				S.sec = v;
				S.pop = null;
				S.flash = null;
				S.resetScroll = true;
				if (v === 'dash') {
					S.dash = null;
				}
				if (v === 'policy') {
					S.policy = null;
				}
				render();
				return;
			case 'undo':
				runUndo();
				return;
			case 'retry':
				S.err[v] = false;
				renderWork();
				return;
			case 'toastundo':
				runUndo(S.toast && S.toast.undo);
				return;
			case 'toastx':
				S.toast = null;
				renderLayer();
				return;
			case 'week':
				S.week = v;
				S.dash = null;
				renderWork();
				return;
			case 'dashgo':
				var w = S.dash && S.dash.week;
				S.led = Object.assign(S.led, { rows: null, kind: v, member: 0, memberCard: null, type: '', direction: '', reason: '', actor: false, from: w ? w.monday : '', to: w ? w.end : '' });
				S.sec = 'ledger';
				S.resetScroll = true;
				render();
				return;
			case 'open':
				openMember(v);
				return;
			case 'close':
				S.open = null;
				S.panel = null;
				renderWork();
				return;
			case 'fold':
				S.fold[v] = !S.fold[v];
				renderWork();
				return;
			case 'board':
				S.boardOpen = S.boardOpen === v ? null : v;
				renderWork();
				return;
			case 'rule':
				saveRule(k, S.rules.values[k] + (+el.getAttribute('data-d')));
				return;
			case 'bv':
				var srl = +el.getAttribute('data-b');
				var map = S.rules.overrides[k] || {};
				var cur = map[srl] !== undefined ? map[srl] : S.rules.values[k];
				saveBoard(k, srl, cur + (+el.getAttribute('data-d')));
				return;
			case 'breset':
				saveBoard(k, +el.getAttribute('data-b'), null);
				return;
			case 'cond':
				S.pop = { type: 'cond', k: k, anchor: 'cond-' + k };
				renderLayer();
				return;
			case 'condnotice':
				var nkey = { read_document: 'read_document_except_notice', read_document_author: 'read_document_author_except_notice' }[k];
				var nold = S.rules.notice[k];
				S.rules.notice[k] = !nold;
				renderLayer();
				saveRuleKey(nkey, nold ? 'N' : 'Y', '', function () {
					var item = pushUndo(T('undo_rule', { rule: T('rule_' + k) }), function () {
						return api('procPointAdminSaveRule', { key: nkey, value: nold ? 'Y' : 'N' }).then(function () { S.rules.notice[k] = nold; });
					});
					toast(T(nold ? 'toast_notice_off' : 'toast_notice_on', { rule: T('rule_' + k) }), T('toast_rule_sub'), item);
				});
				return;
			case 'condsave':
				var input = document.getElementById('pa-cdays');
				var days = Math.max(0, parseInt(String(input ? input.value : '0').replace(/[^\d]/g, ''), 10) || 0);
				var lkey = k + '_limit';
				var lold = S.rules.limits[k];
				S.pop = null;
				if (days === lold) {
					renderLayer();
					return;
				}
				S.rules.limits[k] = days;
				saveRuleKey(lkey, days, '', function () {
					var item = pushUndo(T('undo_rule', { rule: T('rule_' + k) }), function () {
						return api('procPointAdminSaveRule', { key: lkey, value: lold }).then(function () { S.rules.limits[k] = lold; });
					});
					toast(T('toast_cond', { rule: T('rule_' + k), cond: days ? T('cond_days', { n: fmt(days) }) : T('cond_nolimit') }), T('toast_rule_sub'), item);
				});
				return;
			case 'revert':
				var rkey = { insert_document: 'insert_document_revert_on_delete', insert_comment: 'insert_comment_revert_on_delete', upload_file: 'upload_file_revert_on_delete', first_document_point: 'first_document_revert' }[k];
				var rold = S.rules.revert[k];
				S.rules.revert[k] = !rold;
				saveRuleKey(rkey, rold ? 'N' : 'Y', '', function () {
					var item = pushUndo(T('undo_rule', { rule: T('rule_' + k) }), function () {
						return api('procPointAdminSaveRule', { key: rkey, value: rold ? 'Y' : 'N' }).then(function () { S.rules.revert[k] = rold; });
					});
					toast(T(rold ? 'toast_revert_off' : 'toast_revert_on', { rule: T('rule_' + k) }), T('toast_rule_sub'), item);
				});
				return;
			case 'sel':
				var id = +v;
				var i = S.sel.indexOf(id);
				if (i >= 0) {
					S.sel.splice(i, 1);
				} else {
					S.sel.push(id);
				}
				if (!S.sel.length && S.pop && S.pop.type === 'bulk') {
					S.pop = null;
				}
				renderWork();
				renderLayer();
				return;
			case 'selall':
				var rows = S.mem.rows || [];
				var all = rows.length && rows.every(function (m) { return S.sel.indexOf(m.srl) >= 0; });
				rows.forEach(function (m) {
					var j = S.sel.indexOf(m.srl);
					if (all && j >= 0) {
						S.sel.splice(j, 1);
					} else if (!all && j < 0) {
						S.sel.push(m.srl);
					}
				});
				renderWork();
				return;
			case 'selclear':
				S.sel = [];
				S.pop = null;
				render();
				return;
			case 'pop':
				var anchor = el.getAttribute('data-anchor');
				if (S.pop && S.pop.anchor === anchor) {
					S.pop = null;
				} else {
					S.pop = { type: v, anchor: anchor, l: +el.getAttribute('data-l') || 0 };
					if (v === 'bulk') {
						S.pt.memo = '';
					}
				}
				el.setAttribute('data-anchor', anchor);
				renderWork();
				renderLayer();
				if (S.pop && v === 'pickmember') {
					var pq = document.getElementById('pa-pickq');
					if (pq) {
						pq.focus();
					}
				}
				return;
			case 'popclose':
				S.pop = null;
				render();
				return;
			case 'ptmode':
				S.pt.mode = v;
				renderWork();
				renderLayer();
				return;
			case 'ptamt':
				S.pt.amt = +v;
				renderWork();
				renderLayer();
				return;
			case 'ptgo':
				giveOne();
				return;
			case 'bulkgo':
				giveBulk();
				return;
			case 'ptfull':
				if (!S.open) {
					return;
				}
				S.led = Object.assign(S.led, { rows: null, kind: '', member: S.open, memberCard: S.panel ? S.panel.member : null, type: '', direction: '', reason: '', actor: false, from: '', to: '' });
				S.sec = 'ledger';
				S.resetScroll = true;
				render();
				return;
			case 'memmore':
				load('members', 'more');
				renderWork();
				return;
			case 'legacyclear':
				S.mem.target = '';
				S.mem.keyword = '';
				S.mem.rows = null;
				renderWork();
				return;
			case 'kind':
				S.led.kind = v;
				S.led.rows = null;
				if (v && !S.led.member && !S.led.from) {
					S.led.from = '';
				}
				renderWork();
				return;
			case 'ledclear':
				if (v === 'type') { S.led.type = ''; }
				if (v === 'direction') { S.led.direction = ''; }
				if (v === 'reason') { S.led.reason = ''; }
				if (v === 'actor') { S.led.actor = false; }
				S.led.rows = null;
				renderWork();
				return;
			case 'ledmember':
				S.led.member = +v || 0;
				S.led.memberCard = null;
				S.led.rows = null;
				S.pop = null;
				if (S.led.member && S.pop) {
					S.pop = null;
				}
				render();
				return;
			case 'period':
				S.led.from = el.getAttribute('data-from') || '';
				S.led.to = el.getAttribute('data-to') || '';
				S.led.rows = null;
				S.pop = null;
				render();
				return;
			case 'periodgo':
				var f = (document.getElementById('pa-dfrom') || {}).value || '';
				var t = (document.getElementById('pa-dto') || {}).value || '';
				S.led.from = f.replace(/-/g, '');
				S.led.to = t.replace(/-/g, '');
				if (S.led.from && S.led.to && S.led.from > S.led.to) {
					var tmp = S.led.from;
					S.led.from = S.led.to;
					S.led.to = tmp;
				}
				S.led.rows = null;
				S.pop = null;
				render();
				return;
			case 'ledmore':
				load('ledger', 'more');
				renderWork();
				return;
			case 'ledbefore':
				load('ledger', 'before');
				renderWork();
				return;
			case 'revertrow':
				saving(true);
				api('procPointAdminRevert', { ledger_srl: v }).then(function (d) {
					saving(false);
					var row = (S.led.rows || []).filter(function (r) { return r.srl === +v; })[0];
					var nick = row ? row.member.nick : '';
					toast(T('toast_reverted', { nick: nick, n: fmt(Math.abs(d.amount)) }), T('toast_reverted_sub'), null);
					refreshAfterPoints();
					render();
				}).catch(fail);
				return;
			case 'lv':
				var l = +k;
				saveLevel(l, Math.max(0, S.levels.steps[l - 1] + (+el.getAttribute('data-d')) * 100));
				return;
			case 'lvopen':
				S.lvOpen = !S.lvOpen;
				renderWork();
				return;
			case 'lvglink':
				var g = +el.getAttribute('data-g');
				var level = +el.getAttribute('data-l');
				var links = Object.assign({}, S.levels.links);
				var before = Object.assign({}, S.levels.links);
				if (links[g] === level) {
					delete links[g];
				} else {
					links[g] = level;
				}
				S.levels.links = links;
				renderWork();
				renderLayer();
				var gt = ((B.groups || []).filter(function (x) { return x.id === g; })[0] || {}).title || '';
				saveConfig('point_group', JSON.stringify(links), links[g] ? T('toast_lvg_on', { group: gt, n: level }) : T('toast_lvg_off', { group: gt }), T('toast_level_next'), function () { return JSON.stringify(before); }).catch(function () { S.levels.links = before; renderWork(); });
				return;
			case 'formulago':
				var expr = (document.getElementById('pa-expr') || {}).value || '';
				var fn = formulaFn(expr);
				if (!fn) {
					return;
				}
				var V = S.levels;
				var oldSteps = V.steps.slice();
				var oldExpr = V.expression;
				var steps = [];
				for (var n = 1; n <= V.max_level; n++) {
					steps.push(Math.max(0, Math.round(fn(n))));
				}
				S.pop = null;
				saving(true);
				api('procPointAdminSaveLevelStep', { steps: steps.join(','), expression: expr }).then(function () {
					saving(false);
					V.steps = steps;
					V.expression = expr;
					var item = pushUndo(T('undo_formula'), function () {
						return api('procPointAdminSaveLevelStep', { steps: oldSteps.join(','), expression: oldExpr }).then(function () { V.steps = oldSteps; V.expression = oldExpr; });
					});
					toast(T('toast_formula', { n: V.max_level }), T('toast_level_next'), item);
					if (V.counts) {
						load('level', true);
					}
					render();
				}).catch(fail);
				return;
			case 'cfg':
				var cur2 = el.classList.contains('on');
				var val = cur2 ? 'N' : 'Y';
				el.classList.toggle('on', !cur2);
				el.setAttribute('aria-checked', String(!cur2));
				var msgs = {
					able_module: ['toast_able_on', 'toast_able_off'], disable_download: ['toast_download_on', 'toast_download_off'], disable_read_document: ['toast_read_on', 'toast_read_off'],
					mypage_enabled: ['toast_mypage_on', 'toast_mypage_off'], big_confirm: ['toast_big_on', 'toast_big_off'], group_reset: ['toast_reset_on', 'toast_reset_off'], group_ratchet: ['toast_ratchet_on', 'toast_ratchet_off'],
					limit_enabled: ['toast_limit_on', 'toast_limit_off'], expire_enabled: ['toast_expire_on', 'toast_expire_off']
				}[k] || ['toast_saved', 'toast_saved'];
				saveConfig(k, val, T(val === 'Y' ? msgs[0] : msgs[1]), '', function (o) { return o === 'Y' || o === true ? 'Y' : 'N'; }).then(function () {
					if (k === 'big_confirm') { S.bigConfirm = val === 'Y'; }
					if (k === 'able_module') { S.able = val === 'Y'; }
					if (S.policy) {
						var pk = { able_module: 'able', mypage_enabled: 'mypage', big_confirm: 'big_confirm' }[k] || k;
						S.policy[pk] = val === 'Y';
					}
					if (S.levels && (k === 'group_reset' || k === 'group_ratchet')) {
						S.levels[k] = val === 'Y';
					}
					render();
				}).catch(function () {
					el.classList.toggle('on', cur2);
					render();
				});
				return;
			case 'cfgchk':
				var on = S.policy[k];
				S.policy[k] = !on;
				renderWork();
				saveConfig(k, on ? 'N' : 'Y', T('toast_saved'), '', function (o) { return o === 'Y' ? 'Y' : 'N'; }).then(render).catch(function () { S.policy[k] = on; render(); });
				return;
			case 'ledgeron':
				S.modal = { type: 'ledgeron' };
				S.pop = null;
				S.chk = { ret: true, stats: true };
				render();
				if (!S.est) {
					api('getPointAdminPolicy', { estimate: 'Y' }).then(function (d) {
						S.est = { estimate: d.estimate, members: d.members };
						renderLayer();
					}).catch(function () {});
				}
				return;
			case 'chk':
				S.chk[v] = !S.chk[v];
				renderLayer();
				return;
			case 'ledgergo':
				startLedger();
				return;
			case 'ledgeroff':
				saving(true);
				api('procPointAdminDisableLedger').then(function () {
					saving(false);
					S.ledger = false;
					S.stats = false;
					S.mem.rows = null;
					S.levels = null;
					S.policy = null;
					S.dash = null;
					S.led.rows = null;
					S.panel && (S.panel.ledger_ready = false);
					toast(T('toast_off'), T('toast_off_sub'), null);
					render();
				}).catch(fail);
				return;
			case 'statson':
			case 'statstoggle':
				var turnOn = act === 'statson' ? true : !S.stats;
				saving(true);
				api('procPointAdminSetStats', { value: turnOn ? 'Y' : 'N' }).then(function () {
					S.stats = turnOn;
					S.dash = null;
					S.policy = null;
					S.levels = null;
					S.mem.rows = null;
					if (turnOn) {
						S.prog = { percent: 0, total: 0 };
						render();
						runJob('stats', function () {
							saving(false);
							S.prog = null;
							S.dash = null;
							toast(T('toast_stats_on'), T('toast_stats_on_sub'), null);
							render();
						});
					} else {
						saving(false);
						toast(T('toast_stats_off'), T('toast_stats_off_sub'), null);
						render();
					}
				}).catch(fail);
				return;
			case 'recal':
				S.modal = { type: 'confirm', title: T('recal_title'), text: T(S.ledger ? 'recal_text_on' : 'recal_text_off'), ok: T('pol_recal_btn'), danger: true, run: startRecal };
				render();
				return;
			case 'confirmgo':
				var run = S.modal && S.modal.run;
				S.modal = null;
				render();
				if (run) {
					run();
				}
				return;
			case 'modalclose':
				S.modal = null;
				render();
				return;
			case 'adv':
				S.advOpen = !S.advOpen;
				renderWork();
				return;
			case 'mismatch':
				api('getPointAdminPolicy', { mismatch: 'Y' }).then(function (d) {
					S.policy = d;
					renderWork();
				}).catch(fail);
				return;
			case 'reconcile':
				S.busy = true;
				S.prog = { percent: 0, total: 0 };
				saving(true);
				render();
				runJob('reconcile_start', function () {
					S.busy = false;
					S.prog = null;
					saving(false);
					S.policy = null;
					toast(T('toast_reconciled'), '', null);
					render();
				});
				return;
		}
	}

	/* ── 입력 ── */
	function numberOf(s) {
		var str = String(s).replace(/[^\d-]/g, '');
		var n = parseInt(str, 10);
		return isNaN(n) ? null : n;
	}
	root.addEventListener('change', function (e) {
		var t = e.target;
		var kind = t.getAttribute('data-ptin');
		if (kind) {
			var n = numberOf(t.value);
			if (n === null) {
				render();
				return;
			}
			var k = t.getAttribute('data-k');
			if (kind === 'rule') {
				saveRule(k, n);
			} else if (kind === 'bv') {
				saveBoard(k, +t.getAttribute('data-b'), n);
			} else if (kind === 'lv') {
				saveLevel(+k, Math.max(0, n));
			}
			return;
		}
		if (t.id === 'pa-sort') {
			S.mem.sort = t.value;
			S.mem.rows = null;
			renderWork();
		} else if (t.id === 'pa-group') {
			S.mem.group = +t.value;
			S.mem.rows = null;
			renderWork();
		} else if (t.id === 'pa-maxlv') {
			var mx = numberOf(t.value);
			var oldMax = S.levels.max_level;
			if (!mx || mx < 1 || mx > 1000 || mx === oldMax) {
				renderWork();
				return;
			}
			saveConfig('max_level', mx, T('toast_maxlv', { n: mx }), '', function () { return oldMax; }).then(function () { load('level', true); }).catch(function () {});
		} else if (t.id === 'pa-iconset') {
			var oldSet = S.levels.icon;
			saveConfig('level_icon', t.value, T('toast_iconset'), '', function () { return oldSet; }).then(function () { load('level', true); }).catch(function () {});
		} else if (t.id === 'pa-pname') {
			var oldName = S.policy.point_name;
			saveConfig('point_name', t.value, T('toast_pname', { name: t.value || 'point' }), '', function () { return oldName; }).then(function (d) { S.policy.point_name = d.value; }).catch(function () {});
		} else if (t.id === 'pa-ldaily' || t.id === 'pa-lmonthly') {
			var key = t.id === 'pa-ldaily' ? 'limit_daily' : 'limit_monthly';
			saveConfig(key, numberOf(t.value) || 0, T('toast_saved'), '').then(function (d) { S.policy[key] = d.value; }).catch(function () {});
		} else if (t.id === 'pa-lreasons') {
			saveConfig('limit_reasons', t.value, T('toast_saved'), '').then(function (d) { S.policy.limit_reasons = d.value; render(); }).catch(function () {});
		} else if (t.id === 'pa-emonths') {
			saveConfig('expire_months', numberOf(t.value) || 12, T('toast_saved'), '').then(function (d) { S.policy.expire_months = d.value; }).catch(function () {});
		} else if (t.id === 'pa-retention') {
			var oldRet = S.policy.retention;
			var months = +t.value;
			saveConfig('retention', months, months ? T('toast_ret_on', { label: months % 12 === 0 ? T('ret_years', { n: months / 12 }) : T('ret_months', { n: months }) }) : T('toast_ret_off'), months ? T('toast_ret_on_sub') : '', function () { return oldRet; }).then(function (d) { S.policy.retention = d.value; render(); }).catch(function () {});
		}
	});
	var searchTimer = null;
	root.addEventListener('input', function (e) {
		var t = e.target;
		if (t.id === 'pa-q') {
			S.mem.q = t.value;
			clearTimeout(searchTimer);
			searchTimer = setTimeout(function () {
				S.mem.rows = null;
				load('members', true).then(function () {
					var qEl = document.getElementById('pa-q');
					if (qEl) {
						qEl.focus();
						qEl.setSelectionRange(qEl.value.length, qEl.value.length);
					}
				});
			}, 300);
		} else if (t.id === 'pa-ptamt' || t.id === 'pa-bpamt') {
			S.pt.amt = Math.abs(numberOf(t.value) || 0);
			if (t.id === 'pa-ptamt') {
				refreshPanelPreview();
			} else {
				refreshBulk();
			}
		} else if (t.id === 'pa-ptmemo') {
			S.pt.memo = t.value;
			refreshPanelPreview();
		} else if (t.id === 'pa-bpmemo') {
			S.pt.memo = t.value;
			refreshBulk();
		} else if (t.id === 'pa-expr') {
			var prev = document.getElementById('pa-exprprev');
			if (prev) {
				prev.textContent = formulaPreview(t.value);
			}
		} else if (t.id === 'pa-pickq') {
			S.pop.q = t.value;
			clearTimeout(searchTimer);
			searchTimer = setTimeout(function () {
				if (!S.pop || S.pop.type !== 'pickmember') {
					return;
				}
				if (!t.value.trim()) {
					S.pop.rows = null;
					document.getElementById('pa-picklist').innerHTML = pickList(S.pop);
					return;
				}
				api('getPointAdminMemberList', { q: t.value.trim(), sort: 'point' }).then(function (d) {
					if (S.pop && S.pop.type === 'pickmember') {
						S.pop.rows = d.rows;
						var list = document.getElementById('pa-picklist');
						if (list) {
							list.innerHTML = pickList(S.pop);
						}
					}
				}).catch(function () {});
			}, 300);
		}
	});
	function refreshBulk() {
		var n = S.sel.length;
		var a = S.pt.amt || 0;
		var imp = document.getElementById('pa-impact');
		if (imp) {
			imp.innerHTML = '<div><b>' + fmt(a) + T('unit_point') + '</b><span>' + T('impact_one') + '</span></div><div><b>' + fmt(a * n) + T('unit_point') + '</b><span>' + T('impact_all', { n: fmt(n) }) + '</span></div>';
		}
		var go = document.getElementById('pa-bpgo');
		if (go) {
			go.disabled = !((S.pt.memo.trim() || !S.ledger) && a);
			go.textContent = T(S.pt.mode === 'give' ? 'bulk_go_give' : 'bulk_go_take', { n: fmt(a) });
		}
	}
	root.addEventListener('keydown', function (e) {
		var t = e.target;
		if (e.key === 'Enter' && t.getAttribute && t.getAttribute('data-ptin')) {
			t.blur();
		}
		if (e.key === 'Enter' && t.id === 'pa-ptmemo') {
			var go = document.getElementById('pa-ptgo');
			if (go && !go.disabled) {
				giveOne();
			}
		}
		if (e.key === 'Escape') {
			if (S.modal) {
				S.modal = null;
				render();
			} else if (S.pop) {
				S.pop = null;
				renderLayer();
			} else if (tipFor) {
				hideTip();
			}
		}
	});
	window.addEventListener('resize', placePop);

	render();
	if (S.sec === 'policy' || S.busy) {
		load('policy');
	}
	resumeJob();
})();
