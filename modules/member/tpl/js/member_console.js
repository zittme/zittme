/**
 * Member administration screen (member module)
 *
 * Reads go through member.getMemberAdmin* actions. Every change is saved
 * right away through member and point actions, then the list is read again.
 */
(function ($) {
	'use strict';

	var B, L, TM;
	var ROOT, LAYER, TIP;
	var S;
	var VIEW = null;
	var GMAP = {};
	var GROUPS = [];
	var PALETTE = ['#64748b', '#1b5fc0', '#0f766e', '#b45309', '#7a3fb5', '#be185d', '#0e7490', '#4d7c0f', '#9a3412', '#475569'];
	var AVA = ['#dbeafe', '#dcfce7', '#fef3c7', '#fce7f3', '#ede9fe', '#e0f2fe', '#fee2e2', '#f1f5f9'];
	var AVA_INK = ['#1e40af', '#166534', '#92400e', '#9d174d', '#5b21b6', '#075985', '#991b1b', '#334155'];
	var TOUR_KEY = 'zmc-tour-1';
	var COUNT_KEY = 'zmc-list-count';
	var LANG = (document.documentElement.getAttribute('lang') || 'ko').replace('_', '-');

	/* ── 문구 · 조사 ─────────────────────────── */
	function T(key) {
		var s = (L && L[key] !== undefined && L[key] !== null) ? String(L[key]) : key;
		for (var i = 1; i < arguments.length; i++) {
			s = s.split('%' + i).join(arguments[i] === undefined || arguments[i] === null ? '' : String(arguments[i]));
		}
		return josa(s);
	}
	function jong(ch) {
		var c = ch.charCodeAt(0);
		if (c >= 0xAC00 && c <= 0xD7A3) return (c - 0xAC00) % 28;
		if (/[178l]/i.test(ch)) return 8;
		if (/[036mn]/i.test(ch)) return 1;
		return 0;
	}
	function josa(s) {
		return s.replace(/\{([^{}\/]+)\/([^{}\/]+)\}/g, function (m, a, b, offset, all) {
			var i = offset - 1;
			while (i >= 0 && /[’'"”)\]」』\s]/.test(all.charAt(i))) i--;
			if (i < 0) return b;
			var j = jong(all.charAt(i));
			if (a === '으로') return (j === 0 || j === 8) ? b : a;
			return j ? a : b;
		});
	}
	function esc(s) {
		return String(s === undefined || s === null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function q(s) { return '‘' + s + '’'; }
	function fmt(n) {
		try { return Number(n || 0).toLocaleString(LANG); } catch (e) { return String(n || 0); }
	}

	/* ── 아이콘 ─────────────────────────── */
	var P = {
		plus: '<path d="M12 5v14M5 12h14"/>',
		minus: '<path d="M5 12h14"/>',
		more: '<circle cx="5" cy="12" r="1.4" fill="currentColor"/><circle cx="12" cy="12" r="1.4" fill="currentColor"/><circle cx="19" cy="12" r="1.4" fill="currentColor"/>',
		chev: '<path d="M6 9l6 6 6-6"/>',
		chevr: '<path d="M9 6l6 6-6 6"/>',
		back: '<path d="M15 18l-6-6 6-6"/>',
		search: '<circle cx="11" cy="11" r="6"/><path d="M20 20l-4-4"/>',
		undo: '<path d="M9 14L4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-3"/>',
		eyeoff: '<path d="M3 3l18 18"/><path d="M10.6 5.1A10 10 0 0 1 12 5c6 0 10 7 10 7a17 17 0 0 1-3.2 3.9M6.5 6.6C3.8 8.4 2 12 2 12s4 7 10 7a9.6 9.6 0 0 0 4.3-1"/>',
		lock: '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
		users: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M21.5 20a6.5 6.5 0 0 0-4-6"/>',
		userplus: '<circle cx="10" cy="8" r="4"/><path d="M2 21a8 8 0 0 1 14.5-4.7M19 14v6M16 17h6"/>',
		ext: '<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
		check: '<path d="M5 12l5 5 9-10"/>',
		x: '<path d="M6 6l12 12M18 6L6 18"/>',
		info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
		shield: '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>',
		mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
		coin: '<circle cx="12" cy="12" r="9"/><path d="M14.5 9.5c-.5-1-1.5-1.5-2.5-1.5-1.7 0-2.8 1-2.8 2.2 0 2.9 5.6 1.5 5.6 4.3 0 1.2-1.2 2.2-2.8 2.2-1.2 0-2.2-.6-2.7-1.6M12 6.5V8M12 16v1.5"/>',
		tag: '<path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9z"/><circle cx="8" cy="8" r="1.5"/>',
		clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		ban: '<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>',
		note: '<path d="M5 3h10l4 4v14H5z"/><path d="M9 12h6M9 16h4"/>',
		key: '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M17 6l3 3M15 8l2 2"/>',
		form: '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
		gear: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
		trash: '<path d="M4 7h16M10 11v6M14 11v6"/><path d="M6 7l1 13h10l1-13M9 7V4h6v3"/>',
		send: '<path d="M21 3L10 14"/><path d="M21 3l-7 18-4-7-7-4z"/>',
		doc: '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 12h6M9 16h6"/>',
		chat: '<path d="M4 5h16v11H9l-5 4z"/>',
		phone: '<rect x="7" y="3" width="10" height="18" rx="2"/><path d="M11 18h2"/>',
		tablet: '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M11 18h2"/>',
		monitor: '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>'
	};
	function ic(name, size, sw) {
		size = size || 16;
		return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' + (sw || 1.9) + '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + (P[name] || '') + '</svg>';
	}

	/* ── 용어 설명 ─────────────────────────── */
	function term(key, label) {
		var t = TM[key];
		if (!t) return esc(label || key);
		return '<span class="term" tabindex="0" data-term="' + key + '">' + esc(label || t.t) + '</span>';
	}
	var tipFor = null;
	function showTip(el) {
		var t = TM[el.getAttribute('data-term')];
		if (!t) return;
		tipFor = el;
		el.setAttribute('aria-describedby', 'zmc-tip');
		TIP.innerHTML = '<b>' + esc(t.t) + '</b><span>' + esc(t.d) + '</span>' + (t.e ? '<em>' + esc(t.e) + '</em>' : '');
		TIP.hidden = false;
		var r = el.getBoundingClientRect();
		var w = TIP.offsetWidth, h = TIP.offsetHeight;
		var x = r.left + r.width / 2 - w / 2, y = r.bottom + 8;
		if (y + h > innerHeight - 8) y = r.top - h - 8;
		x = Math.max(8, Math.min(x, innerWidth - w - 8));
		TIP.style.left = x + 'px';
		TIP.style.top = y + 'px';
	}
	function hideTip() {
		if (tipFor) tipFor.removeAttribute('aria-describedby');
		tipFor = null;
		if (TIP) TIP.hidden = true;
	}

	/* ── 날짜 ─────────────────────────── */
	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function ymdOf(d) { return d.getFullYear() + pad(d.getMonth() + 1) + pad(d.getDate()); }
	function parseYmd(s) {
		s = String(s || '');
		if (!/^\d{8}/.test(s)) return null;
		var d = new Date(+s.slice(0, 4), +s.slice(4, 6) - 1, +s.slice(6, 8), +(s.slice(8, 10) || 0), +(s.slice(10, 12) || 0));
		return isNaN(d.getTime()) ? null : d;
	}
	function dateDot(s) {
		s = String(s || '');
		return /^\d{8}/.test(s) ? s.slice(0, 4) + '.' + s.slice(4, 6) + '.' + s.slice(6, 8) : '';
	}
	function dateShort(s) {
		s = String(s || '');
		return /^\d{8}/.test(s) ? s.slice(2, 4) + '.' + s.slice(4, 6) + '.' + s.slice(6, 8) : '';
	}
	function dateTime(s) {
		s = String(s || '');
		if (!/^\d{12}/.test(s)) return dateDot(s);
		return dateDot(s) + ' ' + s.slice(8, 10) + ':' + s.slice(10, 12);
	}
	function monthDay(s) {
		var d = parseYmd(s);
		if (!d) return '';
		try { return new Intl.DateTimeFormat(LANG, { month: 'long', day: 'numeric' }).format(d); } catch (e) { return dateDot(s); }
	}
	function relTime(s) {
		var d = parseYmd(s);
		if (!d) return '';
		var now = new Date();
		var t0 = new Date(now.getFullYear(), now.getMonth(), now.getDate());
		var days = Math.floor((t0 - new Date(d.getFullYear(), d.getMonth(), d.getDate())) / 864e5);
		var hm = pad(d.getHours()) + ':' + pad(d.getMinutes());
		if (days <= 0) return T('rel_today', hm);
		if (days === 1) return T('rel_yesterday', hm);
		if (days < 7) return T('rel_days', days);
		if (days < 30) return T('rel_weeks', Math.floor(days / 7));
		return dateShort(s);
	}
	function addDays(n) {
		var d = new Date();
		d.setDate(d.getDate() + n);
		return ymdOf(d);
	}
	function inputDate(ymd) {
		return /^\d{8}$/.test(ymd || '') ? ymd.slice(0, 4) + '-' + ymd.slice(4, 6) + '-' + ymd.slice(6, 8) : '';
	}

	/* ── 서버 호출 ─────────────────────────── */
	function api(action, params) {
		return new Promise(function (resolve, reject) {
			$.exec_json(action, params || {}, function (data) {
				if (data && data.error && String(data.error) !== '0') { reject(data); return; }
				resolve(data || {});
			}, function (data) {
				reject(data || {});
				return false;
			});
		});
	}
	function fromPairs(list) {
		var map = {};
		(list || []).forEach(function (p) { if (p && p.length === 2 && p[1] !== null) map[p[0]] = p[1]; });
		return map;
	}
	function errText(e) {
		return (e && e.message && e.message !== 'error') ? String(e.message) : T('err_generic');
	}

	/* ── 상태 ─────────────────────────── */
	function savedCount() {
		try { var n = parseInt(localStorage.getItem(COUNT_KEY), 10); return [30, 50, 100].indexOf(n) >= 0 ? n : 0; } catch (e) { return 0; }
	}
	function fresh() {
		var f = {};
		var init = B.initial || {};
		Object.keys(init).forEach(function (k) { if (k !== 'page') f[k] = init[k]; });
		f.quick = f.quick || 'all';
		f.sort = f.sort || 'join';
		if (!B.initial || !B.initial.list_count) {
			var c = savedCount();
			if (c) f.list_count = c;
		}
		f.list_count = f.list_count || 30;
		var page = Math.max(1, parseInt(init.page || 1, 10));
		var firstGroup = GROUPS.filter(function (g) { return !g.admin && !g.def; })[0] || GROUPS.filter(function (g) { return !g.admin; })[0];
		return {
			f: f,
			cur: { cursor: '', offset: (page - 1) * f.list_count },
			stack: [], pageNo: page,
			rows: [], hasMore: false, nextCursor: '', loading: false, loaded: false,
			counts: null,
			sel: [], selInfo: {},
			open: null, detail: null, tab: 'info', detailLoading: false,
			pop: null, modal: null, toast: null,
			save: 'saved', busy: 0, undo: [],
			pt: { mode: 'give', amt: 500, memo: '' },
			bg: { mode: 'add', gid: firstGroup ? firstGroup.id : 0 },
			ban: { days: 7, date: '', reason: '' },
			msg: '',
			flash: null,
			tour: 0
		};
	}
	function row(id) {
		for (var i = 0; i < S.rows.length; i++) if (S.rows[i].id === id) return S.rows[i];
		return S.selInfo[id] || null;
	}
	function anyFilter() {
		var f = S.f;
		return !!(f.q || f.quick !== 'all' || f.group || f.state || f.join || f.last || extraKeys().length);
	}
	var EXTRA = ['join_on', 'join_after', 'join_before', 'last_on', 'last_after', 'last_before', 'ip', 'last_ip', 'birthday', 'memo', 'extra', 'social', 'mailing'];
	function extraKeys() { return EXTRA.filter(function (k) { return S.f[k] !== undefined && S.f[k] !== ''; }); }

	/* ── 저장 표시 · 실행 취소 ─────────────────────────── */
	function busy(on) {
		S.busy = Math.max(0, S.busy + (on ? 1 : -1));
		S.save = S.busy ? 'saving' : (S.save === 'fail' && !on ? 'fail' : 'saved');
		renderBar();
	}
	function failed(e) {
		S.save = 'fail';
		renderBar();
		toast(T('toast_failed'), errText(e), null, true);
	}
	function pushUndo(label, run) {
		S.undo.push({ label: label, run: run });
		if (S.undo.length > 20) S.undo.shift();
	}
	function undo() {
		var u = S.undo.pop();
		if (!u) { toast(T('toast_nothing_undo'), '', null); return; }
		busy(true);
		Promise.resolve().then(u.run).then(function () {
			busy(false);
			toast(T('toast_undone'), T('toast_undone_sub', u.label), null);
			refreshAll();
		}, function (e) { busy(false); failed(e); });
	}
	var toastTimer;
	function toast(title, sub, undoLabel, err) {
		S.toast = { title: title, sub: sub || '', undo: undoLabel || '', err: !!err };
		clearTimeout(toastTimer);
		toastTimer = setTimeout(function () { S.toast = null; renderLayer(); }, err ? 9000 : 7000);
		renderLayer();
	}

	/* ── 불러오기 ─────────────────────────── */
	var loadSeq = 0;
	function listParams() {
		var p = {};
		Object.keys(S.f).forEach(function (k) { if (S.f[k] !== undefined && S.f[k] !== null && S.f[k] !== '') p[k] = S.f[k]; });
		if (S.cur.cursor) p.cursor = S.cur.cursor;
		else if (S.cur.offset) p.offset = S.cur.offset;
		return p;
	}
	function loadList(keepSel) {
		var seq = ++loadSeq;
		S.loading = true;
		renderLoad();
		return api('member.getMemberAdminListJson', listParams()).then(function (d) {
			if (seq !== loadSeq) return;
			S.loading = false;
			S.loaded = true;
			S.rows = d.rows || [];
			S.hasMore = !!d.has_more;
			S.nextCursor = d.next_cursor || '';
			S.rows.forEach(function (r) { if (S.sel.indexOf(r.id) >= 0) S.selInfo[r.id] = r; });
			if (!keepSel) { S.sel = []; S.selInfo = {}; }
			render();
			maybeTour();
		}, function (e) {
			if (seq !== loadSeq) return;
			S.loading = false;
			S.loaded = true;
			render();
			failed(e);
		});
	}
	function loadCounts(refresh, recount) {
		return api('member.getMemberAdminQuickCounts', recount ? { refresh: 'Y', recount: 'Y' } : (refresh ? { refresh: 'Y' } : {})).then(function (d) {
			var c = d.counts || null;
			if (c) {
				var map = {};
				if (c.groups) GROUPS.forEach(function (g) { map[g.id] = 0; });
				(c.groups || []).forEach(function (p) { if (p && p.length === 2) map[p[0]] = p[1]; });
				c.groups = c.groups ? map : null;
			}
			S.counts = c;
			render();
		}, function () {});
	}
	function loadDetail(id) {
		S.detailLoading = true;
		return api('member.getMemberAdminDetail', { member_srl: id }).then(function (d) {
			if (S.open !== id) return;
			S.detail = d.member || null;
			S.detailLoading = false;
			render();
		}, function (e) {
			if (S.open !== id) return;
			S.detailLoading = false;
			S.open = null;
			S.detail = null;
			render();
			failed(e);
		});
	}
	function refreshAll() {
		if (VIEW) { VIEW.refresh(); return; }
		loadList(true);
		loadCounts(true);
		if (S.open) loadDetail(S.open);
	}
	function resetPaging() {
		S.cur = { cursor: '', offset: 0 };
		S.stack = [];
		S.pageNo = 1;
	}
	function applyFilter() {
		resetPaging();
		syncUrl();
		loadList(false);
	}
	function syncUrl() {
		if (!window.history || !history.replaceState) return;
		try {
			var u = new URL(location.href);
			['filter_type', 'is_admin', 'is_denied', 'selected_group_srl', 'search_target', 'search_keyword', 'sort_index', 'sort_order', 'page', 'list_count', 'cursor', 'offset', 'q', 'q_field', 'quick', 'group', 'state', 'join', 'last', 'sort'].concat(EXTRA).forEach(function (k) { u.searchParams.delete(k); });
			Object.keys(S.f).forEach(function (k) {
				var v = S.f[k];
				if (v === undefined || v === null || v === '') return;
				if ((k === 'quick' && v === 'all') || (k === 'sort' && v === 'join') || (k === 'list_count' && v === 30)) return;
				u.searchParams.set(k, v);
			});
			history.replaceState(null, '', u.toString());
		} catch (e) { }
	}

	/* ── 작은 조각 ─────────────────────────── */
	function avatar(m, cls) {
		var i = Math.abs(m.id || 0) % AVA.length;
		var ch = String(m.nick || m.name || '?').trim().charAt(0) || '?';
		if (m.photo) return '<span class="avatar photo' + (cls ? ' ' + cls : '') + '" aria-hidden="true"><img src="' + esc(m.photo) + '" alt="" loading="lazy"></span>';
		return '<span class="avatar' + (cls ? ' ' + cls : '') + '" style="background:' + AVA[i] + ';color:' + AVA_INK[i] + '" aria-hidden="true">' + esc(ch) + '</span>';
	}
	function gtag(id) {
		var g = GMAP[id];
		return g ? '<span class="gtag"><i style="background:' + g.color + '"></i>' + esc(g.title) + '</span>' : '';
	}
	var ST = {
		ok: { k: 'st_ok', c: 'ok' },
		unauthed: { k: 'st_unauthed', c: 'info', term: 'unauthed' },
		limited: { k: 'st_limited', c: 'danger', term: 'limit' },
		denied: { k: 'st_denied', c: 'danger', term: 'denied' },
		approved: { k: 'st_approved', c: 'ok', legacy: true }
	};
	function stt(state) {
		var s = ST[state] || ST.ok;
		return '<span class="stt ' + s.c + '">' + esc(T(s.k)) + '</span>';
	}
	function cbox(on, part) {
		return '<span class="cbox' + (on ? ' on' : part ? ' part' : '') + '">' + (on ? ic('check', 12, 3) : part ? ic('minus', 12, 3) : '') + '</span>';
	}
	function sw(on, act, label, dis) {
		return '<button type="button" class="switch' + (on ? ' on' : '') + '" role="switch" aria-checked="' + (on ? 'true' : 'false') + '" aria-label="' + esc(label || '') + '" data-act="' + act + '"' + (dis ? ' disabled' : '') + '></button>';
	}
	function socialLabel(t) { var k = 'sns_' + t; return L[k] ? T(k) : t; }
	function socTags(list) {
		return (list || []).map(function (t) { return '<span class="soc">' + esc(socialLabel(t)) + '</span>'; }).join('');
	}
	function names(ids) {
		var ns = ids.map(function (id) { var r = row(id); return r ? r.nick : ''; }).filter(Boolean);
		return ns.length <= 3 ? ns.join('·') : T('names_more', ns.slice(0, 2).join('·'), ns.length - 2);
	}
	function countText(n) { return n === null || n === undefined ? '' : fmt(n); }

	/* ── 그리기 ─────────────────────────── */
	function render() {
		var a = document.activeElement, keep = null;
		if (a && a.id && ROOT.contains(a)) {
			keep = { id: a.id, s: a.selectionStart, e: a.selectionEnd };
		}
		ROOT.classList.toggle('with-panel-on', !!S.open);
		if (VIEW && VIEW.patch && VIEW.patch()) {
			renderBar();
			renderLayer();
			return;
		}
		if (VIEW) {
			VIEW.beforeRender && VIEW.beforeRender();
			ROOT.innerHTML = '<div class="edbar" id="zmc-bar"></div>' + VIEW.html();
		} else {
			ROOT.innerHTML = '<div class="edbar" id="zmc-bar"></div><div class="workspace' + (S.open ? ' with-panel' : '') + '">' + sideHTML() + mainHTML() + (S.open ? panelHTML() : '') + '</div>';
		}
		ROOT.appendChild(LAYER);
		ROOT.appendChild(TIP);
		renderBar();
		renderLayer();
		if (VIEW && VIEW.afterRender) VIEW.afterRender();
		if (keep) {
			var el = document.getElementById(keep.id);
			if (el) {
				el.focus();
				try { if (keep.s !== null && keep.s !== undefined) el.setSelectionRange(keep.s, keep.e); } catch (e) { }
			}
		}
	}
	function renderLoad() {
		var lb = ROOT.querySelector('.loadbar');
		if (lb) lb.classList.toggle('on', !!S.loading);
	}
	function renderBar() {
		var bar = document.getElementById('zmc-bar');
		if (!bar) return;
		var sv = S.save === 'saving'
			? '<span class="savestate saving" role="status"><i class="dot"></i>' + esc(T('saving')) + '</span>'
			: S.save === 'fail'
				? '<span class="savestate fail" role="status"><i class="dot"></i>' + esc(T('save_failed')) + '</span>'
				: '<span class="savestate" role="status" title="' + esc(T('saved_hint')) + '">' + ic('check', 14, 2.6) + esc(T('saved')) + '</span>';
		var u = B.urls;
		var cur = B.view || 'list';
		var tab = function (key, url, icon, label) {
			return key === cur ? '<a href="' + esc(url) + '" role="tab" aria-selected="true" aria-current="page" class="on">' + ic(icon, 15) + esc(T(label)) + '</a>' : '<a href="' + esc(url) + '" role="tab" aria-selected="false">' + ic(icon, 15) + esc(T(label)) + '</a>';
		};
		var tabs = tab('list', u.list, 'users', 'view_list') + tab('groups', u.groups, 'tag', 'view_groups') + tab('form', u.signup, 'form', 'view_form') +
			(B.point_admin || B.point ? '<a href="' + esc((B.point && B.point.url) || B.point_admin) + '" role="tab" aria-selected="false">' + ic('coin', 15) + esc(T('view_point')) + '</a>' : '') +
			tab('settings', u.settings, 'gear', 'view_settings');
		bar.innerHTML = '<h1>' + esc(T('title')) + '</h1>' +
			'<div class="seg viewseg" role="tablist">' + tabs + '</div>' +
			'<span class="spacer"></span>' + sv +
			'<button type="button" class="btn d-undo" data-act="undo" title="' + esc(T('undo_hint')) + '"' + (S.undo.length ? '' : ' disabled') + '>' + ic('undo', 16) + esc(T('undo')) + '</button>' +
			'<button type="button" class="btn ghost help-btn" data-act="help">' + ic('info', 15) + esc(T('tour')) + '</button>';
	}

	var QUICK = [['all', 'q_all', 'users'], ['today', 'q_today', 'userplus'], ['unauthed', 'q_unauthed', 'mail', 'unauthed'], ['limited', 'q_limited', 'clock', 'limit'], ['denied', 'q_denied', 'ban', 'denied'], ['admin', 'q_admin', 'shield'], ['dormant', 'q_dormant', 'eyeoff', 'dormant']];
	function sideHTML() {
		var c = S.counts || {};
		var groupCounts = c.groups || {};
		var html = '<nav class="side" aria-label="' + esc(T('side_label')) + '"><h4>' + esc(T('side_quick')) + '</h4>';
		QUICK.forEach(function (x) {
			var n = c[x[0]];
			var on = S.f.quick === x[0] && !S.f.group;
			var warn = x[0] === 'unauthed' && n > 0;
			html += '<button type="button" class="fl' + (on ? ' on' : '') + '" data-act="quick" data-v="' + x[0] + '"><span class="ico">' + ic(x[2], 16) + '</span>' + (x[3] ? term(x[3], T(x[1])) : esc(T(x[1]))) +
				(warn ? '<span class="alert">' + fmt(n) + '</span>' : (n !== undefined && n !== null ? '<span class="n">' + (x[0] === 'all' && c.estimated ? esc(T('about_n', fmt(n))) : fmt(n)) + '</span>' : (n === null && x[0] !== 'limited' ? '<span class="n">—</span>' : ''))) + '</button>';
		});
		html += '<h4>' + term('group', T('side_groups')) + '</h4>';
		GROUPS.forEach(function (g) {
			var n = groupCounts[g.id];
			html += '<button type="button" class="fl' + (S.f.group === g.id ? ' on' : '') + '" data-act="fgroup" data-v="' + g.id + '"><span class="gdot" style="background:' + g.color + '"></span>' + esc(g.title) + (n !== undefined ? '<span class="n">' + fmt(n) + '</span>' : (c.estimated && !c.groups ? '<span class="n">—</span>' : '')) + '</button>';
		});
		if (c.estimated) html += '<button type="button" class="fl addline" data-act="recount">' + ic('undo', 15) + esc(T('recount')) + '</button>';
		html += '<a class="fl addline" href="' + esc(B.urls.groups) + '">' + ic('gear', 15) + esc(T('side_group_admin')) + '</a></nav>';
		return html;
	}

	function chip(k, label, on, value) {
		return '<button type="button" class="fchip' + (on ? ' on' : '') + '" data-act="pop" data-v="f-' + k + '" data-anchor="f-' + k + '">' + esc(on ? value : label) +
			(on ? '<span class="fx" data-act="fclear" data-v="' + k + '" role="button" aria-label="' + esc(T('clear_cond')) + '">' + ic('x', 13, 2.4) + '</span>' : ic('chev', 14)) + '</button>';
	}
	function dateCondLabel(prefix) {
		var f = S.f;
		if (f[prefix + '_on']) return T(prefix + '_on_label', dateDot(f[prefix + '_on']));
		if (f[prefix + '_after']) return T(prefix + '_after_label', dateDot(f[prefix + '_after']));
		if (f[prefix + '_before']) return T(prefix + '_before_label', dateDot(f[prefix + '_before']));
		return '';
	}
	function joinLabel() {
		var f = S.f;
		if (f.join) return f.join === '1' ? T('join_today') : T('join_recent', f.join);
		return dateCondLabel('join');
	}
	function lastLabel() {
		var f = S.f;
		if (f.last) return f.last === '1y' ? T('q_dormant') : T('last_within', f.last);
		return dateCondLabel('last');
	}
	var MORE = [['ip', 'more_ip'], ['last_ip', 'more_last_ip'], ['birthday', 'more_birthday'], ['memo', 'more_memo'], ['extra', 'more_extra'], ['social', 'more_social'], ['mailing', 'more_mailing']];
	function moreChips() {
		var html = '';
		if (S.f.q && S.f.q_field) {
			html += '<span class="fchip on">' + esc(T('qf_' + S.f.q_field)) + '<span class="fx" data-act="fclear" data-v="q_field" role="button" tabindex="0" aria-label="' + esc(T('clear_cond')) + '">' + ic('x', 13, 2.4) + '</span></span>';
		}
		MORE.forEach(function (m) {
			var v = S.f[m[0]];
			if (v === undefined || v === '') return;
			var text = m[0] === 'social' || m[0] === 'mailing' ? T(m[1] + '_' + v) : T(m[1]) + ': ' + v;
			html += '<button type="button" class="fchip on" data-act="pop" data-v="f-more" data-sub="' + m[0] + '" data-anchor="f-more">' + esc(text) + '<span class="fx" data-act="fclear" data-v="' + m[0] + '" role="button" aria-label="' + esc(T('clear_cond')) + '">' + ic('x', 13, 2.4) + '</span></button>';
		});
		return html;
	}
	function resultLabel() {
		var f = S.f, c = S.counts || {};
		var onlyQuick = !f.q && !f.group && !f.state && !f.join && !f.last && !extraKeys().length;
		if (onlyQuick && f.quick === 'all') {
			if (c.all === undefined) return T('members');
			return c.estimated ? T('members_about_n', fmt(c.all)) : T('members_n', fmt(c.all));
		}
		if (onlyQuick && c[f.quick] !== undefined && c[f.quick] !== null) {
			var qx = QUICK.filter(function (x) { return x[0] === f.quick; })[0];
			return T('label_n', T(qx[1]), fmt(c[f.quick]));
		}
		if (!f.q && f.group && f.quick === 'all' && !f.state && !f.join && !f.last && !extraKeys().length && GMAP[f.group] && c.groups && c.groups[f.group] !== undefined) {
			return T('label_n', GMAP[f.group].title, fmt(c.groups[f.group]));
		}
		if (S.pageNo > 1) return T('found_page', S.pageNo);
		return S.hasMore ? T('found_more', fmt(S.rows.length)) : T('found_n', fmt(S.rows.length));
	}
	function mainHTML() {
		var f = S.f;
		var tools = '<div class="listtools"><label class="search">' + ic('search', 16) + '<input id="zmc-q" type="search" autocomplete="off" placeholder="' + esc(T('search_ph')) + '" value="' + esc(f.q || '') + '" aria-label="' + esc(T('search_label')) + '">' +
			(f.q ? '<button type="button" class="x" data-act="qclear" aria-label="' + esc(T('search_clear')) + '">' + ic('x', 14) + '</button>' : '') + '</label>' +
			'<div class="fchips">' +
			chip('group', T('chip_group'), !!f.group, f.group && GMAP[f.group] ? GMAP[f.group].title : '') +
			chip('state', T('chip_state'), !!f.state, f.state ? T(ST[f.state].k) : '') +
			chip('join', T('chip_join'), !!joinLabel(), joinLabel()) +
			chip('last', T('chip_last'), !!lastLabel(), lastLabel()) +
			moreChips() +
			'<button type="button" class="fchip" data-act="pop" data-v="f-more" data-anchor="f-more">' + ic('plus', 14) + esc(T('chip_more')) + '</button></div></div>';
		var sorts = [['join', 'sort_join'], ['login', 'sort_login']].concat(B.point ? [['point', 'sort_point']] : []).concat([['nick', 'sort_nick']]);
		var resl = '<div class="resultline"><span><b>' + esc(resultLabel()) + '</b>' + (anyFilter() ? ' <button type="button" class="linkbtn" data-act="fclear" data-v="all" style="margin-left:8px">' + esc(T('clear_all')) + '</button>' : '') + '</span>' +
			'<span class="r"><select class="sel" id="zmc-sort" aria-label="' + esc(T('sort_label')) + '">' + sorts.map(function (s) { return '<option value="' + s[0] + '"' + (f.sort === s[0] ? ' selected' : '') + '>' + esc(T(s[1])) + '</option>'; }).join('') + '</select>' +
			'<a class="btn ghost" href="' + esc(B.urls.insert) + '">' + ic('userplus', 15) + esc(T('add_member')) + '</a></span></div>';
		var bulk = S.sel.length ? '<div class="bulkbar" role="region" aria-label="' + esc(T('bulk_label')) + '"><span>' + T('bulk_n', '<b>' + fmt(S.sel.length) + '</b>') + '</span>' +
			'<button type="button" class="btn' + (S.pop && S.pop.type === 'b-group' ? ' on' : '') + '" data-act="pop" data-v="b-group" data-anchor="b-group">' + ic('tag', 15) + esc(T('bulk_group')) + '</button>' +
			(B.point ? '<button type="button" class="btn' + (S.pop && S.pop.type === 'b-point' ? ' on' : '') + '" data-act="pop" data-v="b-point" data-anchor="b-point">' + ic('coin', 15) + esc(T('bulk_point')) + '</button>' : '') +
			'<button type="button" class="btn" data-act="modal" data-v="msg">' + ic('send', 15) + esc(T('bulk_msg')) + '</button>' +
			'<button type="button" class="btn' + (S.pop && S.pop.type === 'b-more' ? ' on' : '') + '" data-act="pop" data-v="b-more" data-anchor="b-more" aria-label="' + esc(T('bulk_more')) + '">' + ic('more', 16) + '</button>' +
			'<button type="button" class="clear" data-act="selclear">' + esc(T('bulk_clear')) + '</button></div>' : '';
		var rows = S.rows;
		var allOn = rows.length && rows.every(function (m) { return S.sel.indexOf(m.id) >= 0; });
		var part = !allOn && rows.some(function (m) { return S.sel.indexOf(m.id) >= 0; });
		var emptyText = !S.loaded ? T('loading') : (anyFilter() ? T('empty_filtered') : T('empty_all'));
		var table = '<table class="mtable"><thead><tr><th class="ck"><button type="button" data-act="selall" aria-label="' + esc(T('select_all')) + '">' + cbox(allOn, part) + '</button></th><th>' + esc(T('col_member')) + '</th><th>' + term('group', T('col_group')) + '</th>' + (B.point ? '<th class="num">' + term('point', T('col_point')) + '</th>' : '') + '<th>' + esc(T('col_state')) + '</th><th class="c-join">' + esc(T('col_join')) + '</th><th class="c-last">' + esc(T('col_last')) + '</th></tr></thead><tbody>' +
			(rows.length ? rows.map(function (m) {
				var on = S.sel.indexOf(m.id) >= 0;
				return '<tr class="' + (on ? 'sel ' : '') + (S.open === m.id ? 'open' : '') + '" data-act="open" data-v="' + m.id + '"><td class="ck"><button type="button" data-act="sel" data-v="' + m.id + '" aria-label="' + esc(T('select_one', m.nick)) + '">' + cbox(on) + '</button></td>' +
					'<td><div class="who">' + avatar(m) + '<div style="min-width:0"><div class="nm">' + esc(m.nick) + socTags(m.social) + '</div><div class="em">' + esc([m.name, m.email].filter(Boolean).join(' · ')) + '</div></div></div></td>' +
					'<td class="gcell">' + m.groups.map(gtag).join('') + '</td>' + (B.point ? '<td class="num">' + fmt(m.point) + '</td>' : '') + '<td>' + stt(m.state) + '</td>' +
					'<td class="c-join">' + esc(dateShort(m.regdate)) + '</td><td class="c-last">' + esc(m.last_login ? relTime(m.last_login) : '—') + '</td></tr>';
			}).join('') : '<tr class="emptyrow"><td class="empty" colspan="' + (B.point ? 7 : 6) + '">' + esc(emptyText) + (anyFilter() && S.loaded ? ' <button type="button" class="linkbtn" data-act="fclear" data-v="all">' + esc(T('clear_all')) + '</button>' : '') + '</td></tr>') +
			'</tbody></table>';
		var cards = '<div class="mcards">' + (rows.length ? rows.map(function (m) {
			var on = S.sel.indexOf(m.id) >= 0;
			return '<div class="mcard' + (on ? ' sel' : '') + '"><button type="button" data-act="sel" data-v="' + m.id + '" aria-label="' + esc(T('select_one', m.nick)) + '" style="padding:8px 4px">' + cbox(on) + '</button>' +
				'<button type="button" class="who" data-act="open" data-v="' + m.id + '" style="text-align:left">' + avatar(m) + '<div style="min-width:0"><div class="nm">' + esc(m.nick) + '</div><div class="em">' + esc(m.groups.map(function (id) { return GMAP[id] ? GMAP[id].title : ''; }).filter(Boolean).join(' · ') + (B.point ? ' · ' + T('pt_n', fmt(m.point)) : '')) + '</div></div></button>' +
				'<div class="mr">' + stt(m.state) + '<span>' + esc(m.last_login ? relTime(m.last_login) : '—') + '</span></div></div>';
		}).join('') : '<div class="empty">' + esc(emptyText) + '</div>') + '</div>';
		var counts = [30, 50, 100];
		var pager = (S.rows.length || S.pageNo > 1) ? '<div class="pager"><button type="button" class="btn" data-act="prev"' + (S.pageNo > 1 ? '' : ' disabled') + '>' + ic('back', 14) + esc(T('page_prev')) + '</button><span class="pg">' + esc(T('page_n', S.pageNo)) + '</span><button type="button" class="btn" data-act="next"' + (S.hasMore ? '' : ' disabled') + '>' + esc(T('page_next')) + ic('chevr', 14) + '</button>' +
			'<select class="sel" id="zmc-count" aria-label="' + esc(T('per_page_label')) + '">' + counts.map(function (n) { return '<option value="' + n + '"' + (S.f.list_count === n ? ' selected' : '') + '>' + esc(T('per_page', n)) + '</option>'; }).join('') + '</select></div>' : '';
		return '<section class="main" aria-label="' + esc(T('list_label')) + '">' + tools + resl + bulk + '<div class="loadbar' + (S.loading ? ' on' : '') + '"></div><div class="tablewrap" id="zmc-tablewrap">' + table + cards + pager + '</div></section>';
	}

	/* ── 회원 상세 패널 ─────────────────────────── */
	function tabsOf() {
		var t = [['info', 'tab_info']];
		if (B.point) t.push(['point', 'tab_point']);
		t.push(['act', 'tab_act'], ['login', 'tab_login'], ['ban', 'tab_ban'], ['memo', 'tab_memo']);
		return t;
	}
	function panelHTML() {
		var r = row(S.open) || { id: S.open, nick: '', name: '', user_id: '', state: 'ok' };
		var m = S.detail && S.detail.id === S.open ? S.detail : null;
		var nick = m ? m.nick : r.nick;
		var state = m ? m.state : r.state;
		var head = '<div class="ph"><button type="button" class="btn ghost back" data-act="close">' + ic('back', 16) + esc(T('back_to_list')) + '</button>' +
			'<div class="ph-k">' + term('member', T('member')) + '<button type="button" class="iconbtn ph-x" data-act="close" aria-label="' + esc(T('close')) + '">' + ic('x', 18) + '</button></div>' +
			'<div class="phead">' + avatar({ id: S.open, nick: nick, photo: m ? m.photo : r.photo }) + '<div style="min-width:0"><h2>' + esc(nick) + ' ' + stt(state) + '</h2><div class="ph-sub">' + esc([m ? m.name : r.name, m ? m.user_id : r.user_id].filter(Boolean).join(' · ')) + '</div></div></div>' +
			'<div class="ph-acts"><button type="button" class="btn" data-act="modal" data-v="msg1">' + ic('send', 15) + esc(T('act_msg')) + '</button>' +
			(B.point ? '<button type="button" class="btn" data-act="tab" data-v="point">' + ic('coin', 15) + esc(T('act_point')) + '</button>' : '') +
			'<button type="button" class="btn" data-act="pop" data-v="p-more" data-anchor="p-more" aria-label="' + esc(T('more')) + '">' + ic('more', 16) + '</button></div>' +
			'<div class="tabs" role="tablist">' + tabsOf().map(function (x) {
				var badge = '';
				if (m && x[0] === 'memo' && m.memo) badge = '<span class="cnt">1</span>';
				if (m && x[0] === 'ban' && (m.state === 'limited' || m.state === 'denied')) badge = '<span class="cnt danger">1</span>';
				return '<button type="button" role="tab" aria-selected="' + (S.tab === x[0]) + '" class="' + (S.tab === x[0] ? 'on' : '') + '" data-act="tab" data-v="' + x[0] + '">' + esc(T(x[1])) + badge + '</button>';
			}).join('') + '</div></div>';
		var body = !m ? '<div class="loading">' + esc(T('loading')) + '</div>' : ({ info: pInfo, point: pPoint, act: pAct, login: pLogin, ban: pBan, memo: pMemo }[S.tab] || pInfo)(m);
		var foot = '';
		if (m) {
			if (S.tab === 'point' && B.point) {
				foot = '<a href="' + esc(B.point.ledger ? B.point.history_url + m.id : B.point.history_url) + '">' + esc(T(B.point.ledger ? 'pt_full' : 'pt_open')) + ' ' + ic('chevr', 12) + '</a>';
			} else {
				foot = '<a href="' + esc(B.urls.edit + m.id) + '">' + esc(T('edit_full')) + ' ' + ic('ext', 12) + '</a>';
			}
		}
		return '<aside class="panel" aria-label="' + esc(T('panel_label')) + '">' + head + '<div class="pbody" id="zmc-pbody">' + body + '</div>' + (foot ? '<div class="pfoot">' + foot + '</div>' : '') + '</aside>';
	}
	function kvRow(label, value, raw) {
		return '<dt>' + (raw ? label : esc(label)) + '</dt><dd>' + value + '</dd>';
	}
	function pInfo(m) {
		var gs = GROUPS.filter(function (g) { return !g.admin || m.groups.indexOf(g.id) >= 0; });
		var html = '<div class="sec' + (S.flash === 'groups' ? ' flash' : '') + '"><h3>' + term('group', T('sec_groups')) + '<span class="h-r hint" style="margin:0">' + esc(T('groups_hint')) + '</span></h3><div class="grpchecks">' +
			gs.map(function (g) {
				return '<button type="button" class="checkline" data-act="mgroup" data-v="' + g.id + '"' + (g.admin ? ' disabled' : '') + ' aria-pressed="' + (m.groups.indexOf(g.id) >= 0) + '">' + cbox(m.groups.indexOf(g.id) >= 0) + '<span>' + esc(g.title) + (g.def ? ' ' + '<span class="bdg def">' + term('def_group', T('badge_def')) + '</span>' : '') + '</span></button>';
			}).join('') + '</div></div>';
		if (m.point) {
			html += '<div class="sec"><h3>' + term('point', T('sec_point')) + '<button type="button" class="h-r linkbtn" data-act="tab" data-v="point">' + esc(T('pt_history_link')) + ' ' + ic('chevr', 14) + '</button></h3><div class="bigpt"><b>' + fmt(m.point.value) + '</b><span>' + T('pt_unit_level', term('level', T('level')), m.point.level) + '</span></div></div>';
		}
		var kv = '';
		m.fields.forEach(function (f) {
			var v = f.value !== '' ? esc(f.value) : '<span class="dim">' + esc(T('empty_value')) + '</span>';
			kv += kvRow(f.label, v + (f.ext ? ' <span class="bdg ext">' + term('ext_var', T('badge_ext')) + '</span>' : ''));
		});
		kv += kvRow(T('kv_join'), esc(dateDot(m.regdate)));
		kv += kvRow(term('allow_mail', T('kv_mailing')), m.allow_mailing ? esc(T('mailing_yes')) : '<span class="dim">' + esc(T('mailing_no')) + '</span>', true);
		if (B.sns) {
			kv += kvRow(T('kv_social'), m.social.length ? socTags(m.social.map(function (s) { return s.type; })) + ' ' + T('social_joined', term('social', T('social'))) : '<span class="dim">' + esc(T('none')) + '</span>');
		}
		if (m.no_password) kv += kvRow(T('kv_password'), '<span class="dim">' + esc(T('no_password_social')) + '</span>');
		if (m.agreements.length) {
			var agreed = m.agreements.filter(function (a) { return a.agreed; });
			kv += kvRow(T('kv_agree'), agreed.length ? esc(agreed.map(function (a) { return a.title; }).join(' · ') + ' · ' + dateDot(agreed[agreed.length - 1].regdate)) : '<span class="dim">' + esc(T('none')) + '</span>');
		}
		if (m.identity) kv += kvRow(T('kv_identity'), esc(T('identity_done', dateDot(m.identity.regdate))));
		m.summary.forEach(function (s) {
			kv += kvRow(s.label, s.url ? '<a href="' + esc(s.url) + '">' + esc(s.value) + '</a>' : esc(s.value));
		});
		html += '<div class="sec"><h3>' + esc(T('sec_info')) + '<a class="h-r linkbtn" href="' + esc(B.urls.edit + m.id) + '">' + esc(T('edit_info')) + ' ' + ic('chevr', 14) + '</a></h3><dl class="kv">' + kv + '</dl></div>';
		return html;
	}
	function ptAfter(base, mode, n) { return base + (mode === 'give' ? n : -n); }
	function pPoint(m) {
		if (!m.point) return '<div class="sec"><p class="dim">' + esc(T('pt_unavailable')) + '</p></div>';
		var n = S.pt.amt || 0, ledger = m.point.ledger;
		var ok = n > 0 && (!ledger || S.pt.memo.trim());
		var html = '<div class="sec"><div class="bigpt"><b>' + fmt(m.point.value) + '</b><span>' + T('pt_unit_level', esc(T('level')), m.point.level) + '</span></div><div class="hint">' + esc(T('pt_level_hint')) + '</div></div>' +
			'<div class="sec' + (S.flash === 'pt' ? ' flash' : '') + '"><h3>' + esc(T('pt_give_take')) + '</h3><div class="seg full"><button type="button" class="' + (S.pt.mode === 'give' ? 'on' : '') + '" data-act="ptmode" data-v="give">' + ic('plus', 14) + esc(T('pt_give')) + '</button><button type="button" class="' + (S.pt.mode === 'take' ? 'on' : '') + '" data-act="ptmode" data-v="take">' + ic('minus', 14) + esc(T('pt_take')) + '</button></div>' +
			'<div class="amounts">' + [100, 500, 1000, 3000].map(function (a) { return '<button type="button" class="' + (S.pt.amt === a ? 'on' : '') + '" data-act="ptamt" data-v="' + a + '">' + fmt(a) + '</button>'; }).join('') + '</div>' +
			'<div class="field" style="margin-top:12px"><label for="zmc-ptamt">' + esc(T('pt_amount')) + '</label><input id="zmc-ptamt" class="inp" inputmode="numeric" value="' + n + '"></div>' +
			(ledger ? '<div class="field"><label for="zmc-ptmemo">' + esc(T('pt_reason')) + ' <span class="dim" style="font-weight:400">' + esc(T('pt_reason_req')) + '</span></label><input id="zmc-ptmemo" class="inp" maxlength="200" placeholder="' + esc(T('pt_reason_ph')) + '" value="' + esc(S.pt.memo) + '"></div>' : '') +
			'<div class="note" id="zmc-ptnote">' + ic('info', 14) + '<span>' + T('pt_preview', esc(m.nick), '<b>' + fmt(m.point.value) + ' → ' + fmt(ptAfter(m.point.value, S.pt.mode, n)) + '</b>') + (ledger ? ' ' + T('pt_preview_ledger', term('ledger', T('ledger'))) : '') + '</span></div>' +
			'<button type="button" class="btn primary lg block" style="margin-top:12px" id="zmc-ptgo" data-act="ptgo"' + (ok ? '' : ' disabled') + '>' + esc(T(S.pt.mode === 'give' ? 'pt_go_give' : 'pt_go_take', fmt(n))) + '</button>' +
			(ledger ? '' : '<p class="hint" style="margin-top:8px">' + esc(T('pt_off_hint')) + '</p>') + '</div>';
		if (!ledger) {
			html += '<div class="sec"><h3>' + term('ledger', T('ledger')) + '</h3><div class="offcard"><p>' + esc(T('pt_off_card')) + '</p>' + (B.point.policy_url ? '<a class="btn" href="' + esc(B.point.policy_url) + '">' + ic('note', 15) + esc(T('pt_off_btn')) + '</a>' : '') + '</div></div>';
		} else {
			html += '<div class="sec"><h3>' + term('ledger', T('ledger')) + '<a class="h-r linkbtn" href="' + esc(B.point.history_url + m.id) + '">' + esc(T('see_all')) + ' ' + ic('chevr', 14) + '</a></h3>' +
				(m.point.recent.length ? '<div class="list">' + m.point.recent.map(function (l) {
					var plus = l.amount >= 0;
					var label = l.reason || l.memo;
					return '<div class="li"><span class="lic">' + ic(plus ? 'plus' : 'minus', 15) + '</span><span class="lt">' + esc(label || T('pt_change')) + '<small>' + esc(dateTime(l.date)) + (l.by_admin ? ' · ' + esc(T('by_admin')) : '') + '</small></span><span class="r ' + (plus ? 'plus' : 'minus') + '">' + (plus ? '+' : '−') + fmt(Math.abs(l.amount)) + '</span></div>';
				}).join('') + '</div>' : '<p class="dim">' + esc(T('pt_no_history')) + '</p>') + '</div>';
		}
		return html;
	}
	function pAct(m) {
		var a = m.activity;
		var stats = [[fmt(a.documents), T('stat_docs')], [fmt(a.comments), T('stat_comments')]];
		m.summary.slice(0, 1).forEach(function (s) { stats.push([esc(s.value), s.label]); });
		var html = '<div class="stat3">' + stats.map(function (s) { return '<div><b>' + s[0] + '</b><span>' + esc(s[1]) + '</span></div>'; }).join('') + '</div>';
		html += '<div class="sec" style="padding-top:0"><h3>' + esc(T('recent_act')) + (a.documents ? '<a class="h-r linkbtn" href="' + esc(B.urls.documents + m.id) + '">' + esc(T('see_docs')) + ' ' + ic('chevr', 14) + '</a>' : '') + '</h3>' +
			(a.recent.length ? '<div class="list">' + a.recent.map(function (x) {
				return '<a class="li" href="' + esc(x.url) + '" target="_blank" rel="noopener"><span class="lic">' + ic(x.kind === 'document' ? 'doc' : 'chat', 15) + '</span><span class="lt">' + esc(x.title || T(x.kind === 'document' ? 'untitled_doc' : 'untitled_comment')) + '<small>' + esc([x.where, x.site, dateTime(x.date)].filter(Boolean).join(' · ')) + '</small></span></a>';
			}).join('') + '</div>' : '<p class="dim">' + esc(T('no_activity')) + '</p>') + '</div>';
		if (a.nick_log.length) {
			html += '<div class="sec"><h3>' + esc(T('nick_log')) + '</h3><div class="list">' + a.nick_log.map(function (x) {
				return '<div class="li"><span class="lt">' + esc(x.before) + ' → ' + esc(x.after) + '<small>' + esc(dateDot(x.date)) + '</small></span></div>';
			}).join('') + '</div></div>';
		}
		return html;
	}
	function deviceLabel(d) {
		var kind = T(d.kind === 'phone' ? 'dev_phone' : d.kind === 'tablet' ? 'dev_tablet' : 'dev_pc');
		return [kind, d.browser || d.os].filter(Boolean).join(' · ');
	}
	function pLogin(m) {
		var lg = m.logins;
		var html = '<div class="sec"><dl class="kv">' +
			kvRow(T('kv_last_login'), esc(m.last_login ? [dateTime(m.last_login), m.last_login_ip].filter(Boolean).join(' · ') : T('never'))) +
			kvRow(T('kv_join_ip'), esc([dateDot(m.regdate), m.ipaddress].filter(Boolean).join(' · '))) +
			'</dl><div class="hint" style="margin-top:8px">' + esc(T('login_hint')) + '</div></div>';
		html += '<div class="sec"><h3>' + esc(T('devices')) + (lg.devices.length ? '<span class="h-r"><button type="button" class="linkbtn" data-act="logoutall">' + esc(T('logout_all')) + '</button></span>' : '') + '</h3>' +
			(lg.devices.length ? '<div class="list">' + lg.devices.map(function (d) {
				return '<div class="li"><span class="lic">' + ic(d.kind === 'phone' ? 'phone' : d.kind === 'tablet' ? 'tablet' : 'monitor', 15) + '</span><span class="lt">' + esc(deviceLabel(d)) + '<small>' + esc(T('device_meta', d.ip, relTime(d.last))) + '</small></span><span class="r"><button type="button" class="btn" style="height:30px" data-act="logout1" data-v="' + d.id + '">' + esc(T('logout')) + '</button></span></div>';
			}).join('') + '</div>' : '<p class="dim">' + esc(T('no_devices')) + '</p>' + '<button type="button" class="btn" style="margin-top:8px" data-act="logoutall">' + ic('lock', 15) + esc(T('logout_all')) + '</button>') + '</div>';
		html += '<div class="sec"><h3>' + esc(T('login_fail')) + '</h3>' + (lg.failures.length ? '<div class="list">' + lg.failures.map(function (x) {
			return '<div class="li"><span class="lic danger">' + ic('x', 15) + '</span><span class="lt">' + esc(x.message) + '<small>' + esc(dateTime(x.time) + ' · ' + x.ip) + '</small></span></div>';
		}).join('') + '</div>' : '<p class="dim">' + esc(T('no_fail')) + '</p>') + '<div class="hint" style="margin-top:8px">' + esc(T('login_fail_hint')) + '</div></div>';
		return html;
	}
	function banTarget() {
		return S.ban.date || addDays(S.ban.days);
	}
	function pBan(m) {
		var now;
		if (m.state === 'limited') {
			now = '<div class="note danger" style="margin-top:16px">' + ic('clock', 14) + '<span><b>' + T('ban_now_limited', esc(monthDay(m.limit_date)), term('limit', T('st_limited'))) + '</b>' + (m.limited_reason ? '<br>' + esc(T('reason_is', htmlText(m.limited_reason))) : '') + '</span></div><button type="button" class="btn" style="margin-top:8px" data-act="unlimit"' + (m.self ? ' disabled' : '') + '>' + esc(T('ban_release')) + '</button>';
		} else if (m.state === 'denied') {
			now = '<div class="note danger" style="margin-top:16px">' + ic('ban', 14) + '<span><b>' + T('ban_now_denied', term('denied', T('ban_now_denied_term'))) + '</b>' + (m.refused_reason ? '<br>' + esc(T('reason_is', htmlText(m.refused_reason))) : '') + '</span></div><button type="button" class="btn" style="margin-top:8px" data-act="undeny">' + esc(T('ban_allow')) + '</button>';
		} else if (m.state === 'unauthed') {
			now = '<div class="note" style="margin-top:16px">' + ic('mail', 14) + '<span><b>' + T('ban_now_unauthed', term('unauthed', T('st_unauthed'))) + '</b></span></div><button type="button" class="btn" style="margin-top:8px" data-act="confirm1">' + esc(T('confirm_mail')) + '</button>';
		} else {
			now = '<div class="note ok" style="margin-top:16px">' + ic('check', 14) + '<span>' + esc(T('ban_none')) + '</span></div>';
		}
		if (m.self) {
			return now + '<div class="sec"><div class="note">' + ic('info', 14) + '<span>' + esc(T('ban_self')) + '</span></div></div>';
		}
		var target = banTarget();
		var html = now +
			'<div class="sec"><h3>' + term('limit', T('limit')) + '</h3><p class="hint" style="margin:0 0 8px">' + esc(T('limit_hint')) + '</p>' +
			'<div class="amounts">' + [3, 7, 30].map(function (d) { return '<button type="button" class="' + (!S.ban.date && S.ban.days === d ? 'on' : '') + '" data-act="bandays" data-v="' + d + '">' + esc(T('days_n', d)) + '</button>'; }).join('') +
			'<input type="date" class="inp" id="zmc-bandate" aria-label="' + esc(T('pick_date')) + '" min="' + inputDate(B.today) + '" value="' + inputDate(S.ban.date) + '"></div>' +
			'<div class="field" style="margin-top:12px"><label for="zmc-banwhy">' + esc(T('reason')) + '</label><input id="zmc-banwhy" class="inp" maxlength="200" placeholder="' + esc(T('limit_reason_ph')) + '" value="' + esc(S.ban.reason) + '"></div>' +
			'<button type="button" class="btn" data-act="limitgo">' + ic('clock', 15) + esc(S.ban.date ? T('limit_go_date', monthDay(target)) : T('limit_go', S.ban.days, monthDay(target))) + '</button></div>' +
			'<div class="sec"><div class="swrow"><span class="sw-t"><b>' + term('denied', T('deny')) + '</b><small>' + esc(T('deny_hint')) + '</small></span>' + sw(m.state === 'denied', 'denyswitch', T('deny')) + '</div></div>' +
			'<div class="sec"><h3 class="danger">' + esc(T('leave')) + '</h3><p class="hint" style="margin:0 0 8px">' + esc(T('leave_hint')) + '</p><button type="button" class="btn danger" data-act="modal" data-v="leave1">' + ic('trash', 15) + esc(T('leave')) + '</button></div>';
		return html;
	}
	function htmlText(s) {
		var d = document.createElement('textarea');
		d.innerHTML = String(s || '');
		return d.value;
	}
	function pMemo(m) {
		return '<div class="sec"><h3>' + esc(T('memo_title')) + '</h3><textarea class="inp" id="zmc-memo" rows="6" placeholder="' + esc(T('memo_ph')) + '">' + esc(m.memo) + '</textarea>' +
			'<div class="hint">' + ic('lock', 12) + esc(T('memo_hint')) + '</div></div>';
	}

	/* ── 겹치는 층 ─────────────────────────── */
	function groupCountOfSel(gid) {
		return S.sel.filter(function (id) { var r = row(id); return r && r.groups.indexOf(gid) >= 0; }).length;
	}
	function dateForm(prefix) {
		var mode = S.pop.dmode || 'on';
		return '<div class="sep"></div><div class="field" style="margin-top:8px"><label class="flabel" for="zmc-dmode">' + esc(T('pick_date')) + '</label><div class="row2"><select class="sel" id="zmc-dmode">' +
			['on', 'after', 'before'].map(function (k) { return '<option value="' + k + '"' + (mode === k ? ' selected' : '') + '>' + esc(T('dmode_' + k)) + '</option>'; }).join('') +
			'</select><input type="date" class="inp" id="zmc-dval" style="height:32px" value="' + inputDate(S.pop.dval || '') + '"></div></div><div class="pf"><button type="button" class="btn primary" data-act="dateapply" data-v="' + prefix + '">' + esc(T('apply')) + '</button></div>';
	}
	function popHTML() {
		var p = S.pop;
		if (!p) return '';
		var t = p.type, f = S.f;
		if (t === 'f-group') {
			var gc = (S.counts && S.counts.groups) || {};
			return '<div class="pop" style="width:260px"><h4>' + esc(T('pop_group')) + '</h4><div class="optlist">' + GROUPS.map(function (g) { return '<button type="button" class="opt' + (f.group === g.id ? ' on' : '') + '" data-act="fgroup" data-v="' + g.id + '"><span class="gdot" style="width:10px;height:10px;border-radius:3px;background:' + g.color + '"></span>' + esc(g.title) + (gc[g.id] !== undefined ? '<small>' + esc(T('n_people', fmt(gc[g.id]))) + '</small>' : '') + '</button>'; }).join('') + '</div></div>';
		}
		if (t === 'f-state') {
			return '<div class="pop" style="width:260px"><h4>' + esc(T('pop_state')) + '</h4><div class="optlist">' + Object.keys(ST).filter(function (k) { return !ST[k].legacy; }).map(function (k) { return '<button type="button" class="opt' + (f.state === k ? ' on' : '') + '" data-act="fstate" data-v="' + k + '">' + stt(k) + '</button>'; }).join('') + '</div></div>';
		}
		if (t === 'f-join') {
			return '<div class="pop" style="width:300px"><h4>' + esc(T('pop_join')) + '</h4><div class="optlist">' + [['1', 'join_opt_today'], ['7', 'join_opt_7'], ['30', 'join_opt_30']].map(function (x) { return '<button type="button" class="opt' + (f.join === x[0] ? ' on' : '') + '" data-act="fjoin" data-v="' + x[0] + '">' + esc(T(x[1])) + '</button>'; }).join('') + '</div>' + dateForm('join') + '</div>';
		}
		if (t === 'f-last') {
			return '<div class="pop" style="width:300px"><h4>' + esc(T('pop_last')) + '</h4><div class="optlist">' + [['7', 'last_opt_7'], ['30', 'last_opt_30'], ['1y', 'q_dormant']].map(function (x) { return '<button type="button" class="opt' + (f.last === x[0] ? ' on' : '') + '" data-act="flast" data-v="' + x[0] + '">' + esc(T(x[1])) + '</button>'; }).join('') + '</div>' + dateForm('last') + '</div>';
		}
		if (t === 'f-more') {
			if (p.sub) {
				var key = p.sub;
				if (key === 'social' || key === 'mailing') {
					return '<div class="pop" style="width:280px"><h4>' + esc(T('more_' + key)) + '</h4><div class="optlist">' + ['Y', 'N'].map(function (v) { return '<button type="button" class="opt' + (f[key] === v ? ' on' : '') + '" data-act="moreset" data-k="' + key + '" data-v="' + v + '">' + esc(T('more_' + key + '_' + v)) + '</button>'; }).join('') + '</div><div class="pf"><button type="button" class="btn" data-act="moreback">' + esc(T('back')) + '</button></div></div>';
				}
				return '<div class="pop" style="width:300px"><h4>' + esc(T('more_' + key)) + '</h4><p class="pl">' + esc(T('more_' + key + '_hint')) + '</p><input class="inp" id="zmc-morev" value="' + esc(f[key] || '') + '" placeholder="' + esc(T('more_' + key + '_ph')) + '"><div class="pf"><button type="button" class="btn" data-act="moreback">' + esc(T('back')) + '</button><button type="button" class="btn primary" data-act="moreapply" data-k="' + key + '">' + esc(T('apply')) + '</button></div></div>';
			}
			return '<div class="pop" style="width:280px"><h4>' + esc(T('pop_more')) + '</h4><p class="pl">' + esc(T('pop_more_hint')) + '</p><div class="optlist">' +
				SEARCH_FIELDS.map(function (k) { return '<button type="button" class="opt' + (f.q_field === k ? ' on' : '') + '" data-act="qfield" data-v="' + k + '">' + esc(T('qf_' + k)) + '</button>'; }).join('') +
				'<div class="sep"></div>' +
				MORE.filter(function (m) { return m[0] !== 'social' || B.sns; }).map(function (m) { return '<button type="button" class="opt' + (f[m[0]] ? ' on' : '') + '" data-act="moresub" data-v="' + m[0] + '">' + esc(T(m[1])) + '</button>'; }).join('') + '</div></div>';
		}
		if (t === 'b-group') {
			var g = GMAP[S.bg.gid];
			var n = S.sel.length;
			if (!g) return '';
			var already = groupCountOfSel(g.id);
			var sum = S.bg.mode === 'add' ? T('bg_sum_add', fmt(n - already), g.title) + (already ? ' ' + T('bg_sum_add_already', fmt(already)) : '') + ' ' + T('bg_sum_add_keep')
				: S.bg.mode === 'remove' ? T('bg_sum_remove', fmt(already), g.title) + (n - already ? ' ' + T('bg_sum_remove_not', fmt(n - already)) : '') + ' ' + T('bg_sum_remove_last')
					: T('bg_sum_set', fmt(n), g.title);
			return '<div class="pop" style="width:360px"><h4>' + ic('tag', 16) + esc(T('bg_title', fmt(n))) + '</h4><p class="pl">' + esc(names(S.sel)) + '</p>' +
				'<div class="seg full" style="margin-bottom:12px">' + [['add', 'bg_add'], ['remove', 'bg_remove'], ['set', 'bg_set']].map(function (x) { return '<button type="button" class="' + (S.bg.mode === x[0] ? 'on' : '') + '" data-act="bgmode" data-v="' + x[0] + '">' + esc(T(x[1])) + '</button>'; }).join('') + '</div>' +
				'<div class="optlist">' + GROUPS.filter(function (x) { return !x.admin; }).map(function (x) { return '<button type="button" class="opt' + (x.id === g.id ? ' on' : '') + '" data-act="bggid" data-v="' + x.id + '">' + cbox(x.id === g.id).replace('cbox', 'cbox radio') + esc(x.title) + '<small>' + esc(T('bg_in', fmt(groupCountOfSel(x.id)))) + '</small></button>'; }).join('') + '</div>' +
				'<div class="note' + (S.bg.mode === 'set' ? ' warn' : '') + '" style="margin-top:12px">' + ic('info', 14) + '<span>' + esc(sum) + '</span></div>' +
				'<div class="pf"><button type="button" class="btn" data-act="popclose">' + esc(T('close')) + '</button><button type="button" class="btn primary" data-act="bggo">' + esc(T(S.bg.mode === 'add' ? 'bg_go_add' : S.bg.mode === 'remove' ? 'bg_go_remove' : 'bg_go_set')) + '</button></div></div>';
		}
		if (t === 'b-point') {
			var cnt = S.sel.length, a = S.pt.amt || 0, ledger = B.point && B.point.ledger;
			var ok = a > 0 && (!ledger || S.pt.memo.trim());
			return '<div class="pop" style="width:360px"><h4>' + ic('coin', 16) + esc(T(S.pt.mode === 'give' ? 'bp_title_give' : 'bp_title_take', fmt(cnt))) + '</h4><p class="pl">' + esc(names(S.sel)) + '</p>' +
				'<div class="seg full"><button type="button" class="' + (S.pt.mode === 'give' ? 'on' : '') + '" data-act="ptmode" data-v="give">' + esc(T('pt_give')) + '</button><button type="button" class="' + (S.pt.mode === 'take' ? 'on' : '') + '" data-act="ptmode" data-v="take">' + esc(T('pt_take')) + '</button></div>' +
				'<div class="amounts">' + [100, 500, 1000, 3000].map(function (x) { return '<button type="button" class="' + (a === x ? 'on' : '') + '" data-act="ptamt" data-v="' + x + '">' + fmt(x) + '</button>'; }).join('') + '</div>' +
				'<div class="field" style="margin-top:12px"><label for="zmc-bpamt">' + esc(T('pt_amount')) + '</label><input id="zmc-bpamt" class="inp" inputmode="numeric" value="' + a + '"></div>' +
				(ledger ? '<div class="field"><label for="zmc-bpmemo">' + esc(T('pt_reason')) + ' <span class="dim" style="font-weight:400">' + esc(T('pt_reason_req')) + '</span></label><input id="zmc-bpmemo" class="inp" maxlength="200" placeholder="' + esc(T('pt_reason_ph_bulk')) + '" value="' + esc(S.pt.memo) + '"></div>' : '') +
				'<div class="impact"><div><b>' + esc(T('pt_n', fmt(a))) + '</b><span>' + esc(T('bp_each')) + '</span></div><div><b>' + esc(T('pt_n', fmt(a * cnt))) + '</b><span>' + esc(T('bp_total', fmt(cnt))) + '</span></div></div>' +
				'<div class="hint">' + esc((S.pt.mode === 'take' ? T('bp_minus_hint') + ' ' : '') + (ledger ? T('bp_ledger_on') : T('bp_ledger_off'))) + '</div>' +
				'<div class="pf"><button type="button" class="btn" data-act="popclose">' + esc(T('close')) + '</button><button type="button" class="btn primary" id="zmc-bpgo" data-act="bptgo"' + (ok ? '' : ' disabled') + '>' + esc(T(S.pt.mode === 'give' ? 'bp_go_give' : 'bp_go_take', fmt(a))) + '</button></div></div>';
		}
		if (t === 'b-more') {
			return '<div class="pop menu"><div class="optlist"><button type="button" class="opt" data-act="bulkconfirm">' + ic('mail', 15) + esc(T('bm_confirm')) + '</button><button type="button" class="opt" data-act="pop" data-v="b-limit" data-anchor="b-more">' + ic('clock', 15) + esc(T('limit')) + '</button><button type="button" class="opt" data-act="modal" data-v="deny">' + ic('ban', 15) + esc(T('deny')) + '</button><div class="sep"></div><button type="button" class="opt danger" data-act="modal" data-v="leave">' + ic('trash', 15) + esc(T('leave')) + '</button></div></div>';
		}
		if (t === 'b-limit') {
			var tg = banTarget();
			return '<div class="pop" style="width:340px"><h4>' + ic('clock', 16) + esc(T('bl_title', fmt(S.sel.length))) + '</h4><p class="pl">' + esc(T('limit_hint')) + '</p>' +
				'<div class="amounts">' + [3, 7, 30].map(function (d) { return '<button type="button" class="' + (!S.ban.date && S.ban.days === d ? 'on' : '') + '" data-act="bandays" data-v="' + d + '">' + esc(T('days_n', d)) + '</button>'; }).join('') + '<input type="date" class="inp" id="zmc-bandate" aria-label="' + esc(T('pick_date')) + '" min="' + inputDate(B.today) + '" value="' + inputDate(S.ban.date) + '"></div>' +
				'<div class="field" style="margin-top:12px"><label for="zmc-banwhy">' + esc(T('reason')) + '</label><input id="zmc-banwhy" class="inp" maxlength="200" placeholder="' + esc(T('limit_reason_ph')) + '" value="' + esc(S.ban.reason) + '"></div>' +
				'<div class="pf"><button type="button" class="btn" data-act="popclose">' + esc(T('close')) + '</button><button type="button" class="btn primary" data-act="bulklimit">' + esc(T('bl_go', monthDay(tg))) + '</button></div></div>';
		}
		if (t === 'p-more') {
			var m = S.detail;
			if (!m) return '';
			return '<div class="pop menu"><div class="optlist"><a class="opt" href="' + esc(B.urls.profile.replace('987654321', m.id)) + '" target="_blank" rel="noopener">' + ic('ext', 15) + esc(T('pm_profile')) + '</a><a class="opt" href="' + esc(B.urls.documents + m.id) + '">' + ic('doc', 15) + esc(T('pm_docs')) + '</a><button type="button" class="opt" data-act="resetmail">' + ic('key', 15) + esc(T('pm_reset')) + '</button><div class="sep"></div><button type="button" class="opt" data-act="tab" data-v="ban">' + ic('ban', 15) + esc(T('pm_ban')) + '</button></div></div>';
		}
		return '';
	}
	var SEARCH_FIELDS = ['nick_name', 'user_id', 'email_address', 'user_name', 'phone_number'];

	function modalHTML() {
		var md = S.modal;
		if (!md) return '';
		var ids, n;
		if (md === 'msg' || md === 'msg1') {
			ids = md === 'msg1' ? [S.open] : S.sel;
			n = ids.length;
			var who = md === 'msg1' ? (S.detail ? S.detail.nick : '') : names(ids);
			return '<div class="modal-back" data-act="modalclose" data-self="1"><div class="modal" role="dialog" aria-modal="true" aria-label="' + esc(T('msg_title', fmt(n))) + '"><div class="mh"><h3>' + esc(T('msg_title', fmt(n))) + '</h3><p>' + esc(who) + '</p></div><div class="mb">' +
				'<div class="note" style="margin-bottom:12px">' + ic('info', 14) + '<span>' + esc(T('msg_note')) + '</span></div>' +
				'<div class="field"><label for="zmc-msg">' + esc(T('msg_body')) + '</label><textarea id="zmc-msg" class="inp" rows="5" placeholder="' + esc(T('msg_ph')) + '">' + esc(S.msg) + '</textarea></div>' +
				'</div><div class="mf"><button type="button" class="btn" data-act="modalclose">' + esc(T('close')) + '</button><button type="button" class="btn primary" id="zmc-msggo" data-act="msggo"' + (S.msg.trim() ? '' : ' disabled') + '>' + esc(T('msg_go', fmt(n))) + '</button></div></div></div>';
		}
		if (md === 'leave' || md === 'leave1') {
			ids = md === 'leave1' ? [S.open] : S.sel.filter(function (id) { return id !== B.me; });
			n = ids.length;
			var w = md === 'leave1' ? (S.detail ? S.detail.nick : '') : names(ids);
			return '<div class="modal-back" data-act="modalclose" data-self="1"><div class="modal" role="dialog" aria-modal="true" aria-label="' + esc(T('leave_title', fmt(n))) + '"><div class="mh"><h3>' + esc(T('leave_title', fmt(n))) + '</h3><p>' + esc(w) + '</p></div><div class="mb"><div class="note danger">' + ic('info', 14) + '<span><b>' + esc(T('leave_warn')) + '</b> ' + esc(T('leave_warn_sub')) + '</span></div>' +
				(md === 'leave' && S.sel.indexOf(B.me) >= 0 ? '<p class="hint" style="margin-top:8px">' + esc(T('leave_skip_me')) + '</p>' : '') +
				'</div><div class="mf"><button type="button" class="btn" data-act="modalclose">' + esc(T('keep')) + '</button><button type="button" class="btn danger solid" data-act="leavego" data-v="' + md + '"' + (n ? '' : ' disabled') + '>' + ic('trash', 15) + esc(T('leave_go')) + '</button></div></div></div>';
		}
		if (md === 'deny') {
			ids = S.sel.filter(function (id) { return id !== B.me; });
			return '<div class="modal-back" data-act="modalclose" data-self="1"><div class="modal" role="dialog" aria-modal="true" aria-label="' + esc(T('deny_title', fmt(ids.length))) + '"><div class="mh"><h3>' + esc(T('deny_title', fmt(ids.length))) + '</h3><p>' + esc(names(ids)) + '</p></div><div class="mb"><p class="hint" style="margin:0 0 12px">' + esc(T('deny_hint')) + '</p>' +
				'<div class="field"><label for="zmc-denywhy">' + esc(T('reason')) + ' <span class="dim" style="font-weight:400">' + esc(T('optional')) + '</span></label><input id="zmc-denywhy" class="inp" maxlength="200" placeholder="' + esc(T('deny_reason_ph')) + '"></div>' +
				(S.sel.indexOf(B.me) >= 0 ? '<p class="hint">' + esc(T('skip_me')) + '</p>' : '') +
				'</div><div class="mf"><button type="button" class="btn" data-act="modalclose">' + esc(T('close')) + '</button><button type="button" class="btn primary" data-act="bulkdeny"' + (ids.length ? '' : ' disabled') + '>' + esc(T('deny_go', fmt(ids.length))) + '</button></div></div></div>';
		}
		if (md === 'bigpt') {
			var bp = S.bigpt;
			return '<div class="modal-back" data-act="modalclose" data-self="1"><div class="modal" role="dialog" aria-modal="true" aria-label="' + esc(T('big_title')) + '"><div class="mh"><h3>' + esc(T('big_title')) + '</h3><p>' + esc(T(bp.mode === 'give' ? 'big_desc_give' : 'big_desc_take', fmt(bp.amt), fmt(bp.ids.length), fmt(bp.amt * bp.ids.length))) + '</p></div><div class="mf"><button type="button" class="btn" data-act="modalclose">' + esc(T('back')) + '</button><button type="button" class="btn primary" data-act="bigptgo">' + esc(T('big_go')) + '</button></div></div></div>';
		}
		return '';
	}
	function toastHTML() {
		var t = S.toast;
		if (!t) return '';
		return '<div class="toast' + (t.err ? ' err' : '') + '" role="status"><span>' + esc(t.title) + (t.sub ? '<span class="t-sub">' + esc(t.sub) + '</span>' : '') + '</span>' + (t.undo ? '<button type="button" data-act="undo">' + esc(t.undo) + '</button>' : '') + '<button type="button" class="t-x" data-act="toastx" aria-label="' + esc(T('close')) + '">' + ic('x', 16) + '</button></div>';
	}
	var TOUR = [
		{ sel: '.side', msel: '.listtools', t: 'tour_1_t', mt: 'tour_1_mt', d: 'tour_1_d', md: 'tour_1_md' },
		{ sel: '.mtable tbody tr[data-act="open"]', msel: '.mcard', t: 'tour_2_t', mt: 'tour_2_mt', d: 'tour_2_d' },
		{ sel: '.edbar .savestate', msel: '.edbar .savestate', t: 'tour_3_t', d: 'tour_3_d' }
	];
	function isMobile() { return window.innerWidth < 768; }
	function tourSteps() { return VIEW ? (VIEW.tour || []) : TOUR; }
	function tourKey() { return VIEW ? 'zmc-tour-' + B.view + '-1' : TOUR_KEY; }
	function tourHTML() {
		if (!S.tour) return '';
		var steps = tourSteps();
		var s = steps[S.tour - 1];
		if (!s) return '';
		var mob = isMobile();
		return '<div class="tour" role="dialog" aria-modal="true" aria-label="' + esc(T('tour')) + '"><div class="tour-spot" id="zmc-spot"></div><div class="tour-bub" id="zmc-bub"><div class="tb-step">' + S.tour + ' / ' + steps.length + '</div><b>' + esc(T(mob && s.mt ? s.mt : s.t)) + '</b><p>' + esc(T(mob && s.md ? s.md : s.d)) + '</p><div class="tb-acts"><button type="button" class="btn ghost" data-act="tour-skip">' + esc(T('tour_skip')) + '</button><button type="button" class="btn primary" data-act="tour-next">' + esc(T(S.tour === steps.length ? 'tour_start' : 'tour_next')) + '</button></div></div></div>';
	}
	function placeTour() {
		if (!S.tour) return;
		var s = tourSteps()[S.tour - 1];
		if (!s) return;
		var el = ROOT.querySelector(isMobile() ? s.msel : s.sel);
		var spot = document.getElementById('zmc-spot'), bub = document.getElementById('zmc-bub');
		if (!spot || !bub) return;
		if (!el) {
			spot.style.cssText = 'display:none';
			bub.style.left = Math.max(8, (innerWidth - bub.offsetWidth) / 2) + 'px';
			bub.style.top = Math.max(8, (innerHeight - bub.offsetHeight) / 2) + 'px';
			return;
		}
		var r = el.getBoundingClientRect(), padding = 4;
		var top = Math.max(4, r.top - padding), h = Math.min(innerHeight - 8 - top, r.height + padding * 2);
		spot.style.cssText = 'left:' + (r.left - padding) + 'px;top:' + top + 'px;width:' + (r.width + padding * 2) + 'px;height:' + h + 'px';
		var bw = bub.offsetWidth, bh = bub.offsetHeight, x, y;
		if (r.right + 16 + bw < innerWidth) { x = r.right + 16; y = r.top + 24; }
		else if (r.left - 16 - bw > 0) { x = r.left - 16 - bw; y = r.top + 24; }
		else { x = r.left + r.width / 2 - bw / 2; y = r.bottom + 12; if (y + bh > innerHeight - 8) y = Math.max(8, r.top - bh - 12); }
		x = Math.max(8, Math.min(x, innerWidth - bw - 8));
		y = Math.max(8, Math.min(y, innerHeight - bh - 8));
		bub.style.left = x + 'px';
		bub.style.top = y + 'px';
	}
	function maybeTour() {
		if (S.tourChecked) return;
		S.tourChecked = true;
		var seen = '1';
		try { seen = localStorage.getItem(tourKey()); } catch (e) { seen = '1'; }
		if (!seen) {
			S.tour = 1;
			renderLayer();
		}
	}
	function endTour() {
		S.tour = 0;
		try { localStorage.setItem(tourKey(), '1'); } catch (e) { }
	}
	function renderLayer() {
		LAYER.innerHTML = (VIEW ? VIEW.popHTML() + VIEW.modalHTML() : popHTML() + modalHTML()) + toastHTML() + tourHTML();
		placePop();
		placeTour();
	}
	function placePop() {
		var p = LAYER.querySelector('.pop');
		if (!p || !S.pop) return;
		if (isMobile()) return;
		var a = ROOT.querySelector('[data-anchor="' + S.pop.anchor + '"]');
		if (!a) return;
		var r = a.getBoundingClientRect(), w = p.offsetWidth, h = p.offsetHeight;
		var x = r.left, y = r.bottom + 6;
		if (x + w > innerWidth - 8) x = r.right - w;
		if (y + h > innerHeight - 8) y = Math.max(8, r.top - h - 6);
		p.style.left = Math.max(8, x) + 'px';
		p.style.top = Math.max(8, y) + 'px';
	}

	/* ── 동작: 그룹 ─────────────────────────── */
	function groupsCall(ids, mode, gids) {
		return api('member.procMemberAdminUpdateMemberGroups', { member_srls: ids, mode: mode, group_srls: gids });
	}
	function restoreGroups(before) {
		var buckets = {};
		Object.keys(before).forEach(function (id) {
			var list = (before[id] || []).filter(function (g) { return GMAP[g] && !GMAP[g].admin; });
			var key = list.join(',');
			(buckets[key] = buckets[key] || []).push(+id);
		});
		var chain = Promise.resolve();
		Object.keys(buckets).forEach(function (key) {
			if (!key) return;
			chain = chain.then(function () { return groupsCall(buckets[key], 'set', key.split(',').map(Number)); });
		});
		return chain;
	}
	function toggleGroup(gid) {
		var m = S.detail;
		var g = GMAP[gid];
		if (!m || !g || g.admin) return;
		var had = m.groups.indexOf(gid) >= 0;
		if (had && m.groups.length === 1) { toast(T('toast_need_group'), T('toast_need_group_sub'), null); return; }
		busy(true);
		groupsCall([m.id], had ? 'remove' : 'add', [gid]).then(function (d) {
			busy(false);
			var before = fromPairs(d.before);
			pushUndo(T('undo_group', m.nick), function () { return restoreGroups(before); });
			S.flash = 'groups';
			toast(T(had ? 'toast_group_out' : 'toast_group_in', m.nick, g.title), had ? '' : T('toast_group_in_sub', g.title), T('undo'));
			refreshAll();
		}, function (e) { busy(false); failed(e); });
	}
	function bulkGroups() {
		var g = GMAP[S.bg.gid], ids = S.sel.slice(), mode = S.bg.mode;
		if (!g) return;
		S.pop = null;
		busy(true);
		groupsCall(ids, mode, [g.id]).then(function (d) {
			busy(false);
			var before = fromPairs(d.before);
			pushUndo(T('undo_bulk_group', fmt(ids.length)), function () { return restoreGroups(before); });
			var sub = names(ids);
			if (d.skipped) sub = T('toast_bg_skipped', fmt(d.skipped));
			toast(T(mode === 'add' ? 'toast_bg_add' : mode === 'remove' ? 'toast_bg_remove' : 'toast_bg_set', fmt(d.changed || 0), g.title), sub, T('undo'));
			refreshAll();
		}, function (e) { busy(false); failed(e); });
	}

	/* ── 동작: 포인트 ─────────────────────────── */
	function ledgerOn() { return !!(B.point && B.point.ledger); }
	function pointCall(id, delta, memo) {
		var p = { member_srl: id, point: (delta >= 0 ? '+' : '-') + Math.abs(delta) };
		if (ledgerOn() && memo) p.memo = memo;
		return api('point.procPointAdminUpdatePoint', p);
	}
	function bulkCall(ids, delta, memo, key) {
		var p = { member_srls: ids, amount: delta };
		if (ledgerOn() && memo) p.memo = memo;
		if (key) p.batch_key = key;
		return api('point.procPointAdminBulkAdjust', p);
	}
	function newBatchKey() {
		return 'zmc' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
	}
	function revertBatch(batchId, ids, delta, memo) {
		var opposite = function () { return bulkCall(ids, -delta, memo ? T('pt_undo_memo', memo) : '', newBatchKey()); };
		if (!ledgerOn() || !batchId) return opposite();
		return api('point.procPointAdminRevertBatch', { batch_id: batchId }).then(null, opposite);
	}
	function pointsDone(ids, mode, amt, memo, doneCount, failList) {
		var ledger = ledgerOn();
		if (failList.length) {
			toast(T('toast_pt_partial', fmt(doneCount), fmt(ids.length)), failList[0][1] ? String(failList[0][1]) : '', doneCount ? T(ledger ? 'revert' : 'undo') : '', true);
		} else if (ids.length === 1 && !S.ptBulk) {
			var r = row(ids[0]) || S.detail || {};
			toast(T(mode === 'give' ? 'toast_pt_give' : 'toast_pt_take', r.nick || '', fmt(amt)), ledger ? T('toast_pt_ledger', memo) : T('toast_pt_noledger'), T(ledger ? 'revert' : 'undo'));
		} else {
			toast(T(mode === 'give' ? 'toast_bp_give' : 'toast_bp_take', fmt(doneCount), fmt(amt)), ledger ? T('toast_bp_ledger', memo) : T('toast_pt_noledger'), T(ledger ? 'revert' : 'undo'));
		}
		S.pt.memo = '';
		S.flash = 'pt';
		refreshAll();
	}
	function runPoints(ids, mode, amt, memo, bulk) {
		if (S.ptRunning) return Promise.resolve();
		var delta = mode === 'give' ? amt : -amt;
		S.ptRunning = true;
		S.ptBulk = !!bulk;
		busy(true);
		if (bulk) {
			var key = S.pt.batchKey || newBatchKey();
			S.pt.batchKey = key;
			return bulkCall(ids, delta, memo, key).then(function (d) {
				S.ptRunning = false;
				busy(false);
				S.pt.batchKey = '';
				var doneIds = (d.done || []).map(function (p) { return p[0]; });
				if (doneIds.length) {
					var batchId = d.batch_id || key;
					pushUndo(T('undo_point', fmt(doneIds.length)), function () { return revertBatch(batchId, doneIds, delta, memo); });
				}
				pointsDone(ids, mode, amt, memo, doneIds.length, d.failed || []);
			}, function (e) { S.ptRunning = false; busy(false); failed(e); });
		}
		return pointCall(ids[0], delta, memo).then(function () {
			S.ptRunning = false;
			busy(false);
			pushUndo(T('undo_point', fmt(1)), function () { return pointCall(ids[0], -delta, memo ? T('pt_undo_memo', memo) : ''); });
			pointsDone(ids, mode, amt, memo, 1, []);
		}, function (e) { S.ptRunning = false; busy(false); failed(e); });
	}
	function askPoints(ids, bulk) {
		var amt = S.pt.amt || 0;
		if (!amt) return;
		if (B.point && B.point.ledger && !S.pt.memo.trim()) return;
		if (amt > 10000) {
			S.bigpt = { ids: ids, mode: S.pt.mode, amt: amt, memo: S.pt.memo.trim(), bulk: !!bulk };
			S.modal = 'bigpt';
			S.pop = null;
			renderLayer();
			return;
		}
		S.pop = null;
		runPoints(ids, S.pt.mode, amt, S.pt.memo.trim(), !!bulk);
	}

	/* ── 동작: 제재 · 메모 ─────────────────────────── */
	function fieldsCall(params) {
		return api('member.procMemberAdminUpdateMemberFields', params);
	}
	function restoreFields(previous, what) {
		var buckets = {};
		Object.keys(previous).forEach(function (id) {
			var p = previous[id];
			var payload = {};
			if (what.limit) {
				payload.limit_set = 'Y';
				payload.limit_date = p.limit_date && p.limit_date.slice(0, 8) >= B.today ? p.limit_date.slice(0, 8) : '';
				payload.limited_reason = htmlText(p.limited_reason);
			}
			if (what.status) {
				payload.status = p.status === 'APPROVED' || p.status === 'UNAUTHED' ? p.status : 'DENIED';
				payload.refused_reason = htmlText(p.refused_reason);
			}
			if (what.memo) {
				payload.memo_set = 'Y';
				payload.memo = p.memo;
			}
			var key = JSON.stringify(payload);
			(buckets[key] = buckets[key] || { payload: payload, ids: [] }).ids.push(+id);
		});
		var chain = Promise.resolve();
		Object.keys(buckets).forEach(function (key) {
			var b = buckets[key];
			if (what.memo) {
				b.ids.forEach(function (id) { chain = chain.then(function () { return fieldsCall($.extend({ member_srls: [id] }, b.payload)); }); });
			} else {
				chain = chain.then(function () { return fieldsCall($.extend({ member_srls: b.ids }, b.payload)); });
			}
		});
		return chain;
	}
	function applyFields(ids, params, what, label, done) {
		busy(true);
		return fieldsCall($.extend({ member_srls: ids }, params)).then(function (d) {
			busy(false);
			var previous = fromPairs(d.previous);
			if (Object.keys(previous).length) pushUndo(label, function () { return restoreFields(previous, what); });
			done(d);
			refreshAll();
		}, function (e) { busy(false); failed(e); });
	}
	function limitMembers(ids, label) {
		var date = banTarget();
		applyFields(ids, { limit_set: 'Y', limit_date: date, limited_reason: S.ban.reason.trim() }, { limit: true }, label, function (d) {
			var who = ids.length === 1 ? ((row(ids[0]) || S.detail || {}).nick || '') : '';
			if (!d.changed) { toast(T('toast_nothing_changed'), d.skipped ? T('toast_skipped_me') : '', null); return; }
			toast(ids.length === 1 ? T('toast_limit_one', who, monthDay(date)) : T('toast_limit_many', fmt(d.changed), monthDay(date)), d.revoked ? T('toast_logged_out') : '', T('undo'));
			S.ban.reason = '';
		});
	}
	var memoTimer = null, memoPending = null;
	function saveMemo() {
		if (!memoPending) return;
		var p = memoPending;
		memoPending = null;
		busy(true);
		fieldsCall({ member_srls: [p.id], memo_set: 'Y', memo: p.text }).then(function () {
			busy(false);
			if (S.detail && S.detail.id === p.id) S.detail.memo = p.text;
		}, function (e) { busy(false); failed(e); });
	}

	/* ── 클릭 ─────────────────────────── */
	function onClick(e) {
		e.zmcInside = true;
		var termEl = e.target.closest('.term');
		if (termEl && ROOT.contains(termEl) && !termEl.closest('button') && !termEl.closest('a')) {
			e.preventDefault();
			e.stopPropagation();
			if (tipFor === termEl) hideTip(); else showTip(termEl);
			return;
		}
		if (tipFor) hideTip();
		var el = e.target.closest('[data-act]');
		if (el && !ROOT.contains(el)) el = null;
		var act = el ? el.getAttribute('data-act') : '', v = el ? el.getAttribute('data-v') : '';
		if (el && (el.disabled || (el.hasAttribute('data-self') && e.target !== el))) return;
		if (S.pop && !e.target.closest('.pop') && act !== 'pop' && act !== 'fclear') {
			S.pop = null;
			render();
			if (!el) return;
		}
		if (!el) return;
		if (memoPending && (act === 'open' || act === 'close' || act === 'tab')) {
			clearTimeout(memoTimer);
			saveMemo();
		}
		var m = S.detail && S.detail.id === S.open ? S.detail : null;
		if (VIEW && ['undo', 'help', 'tour-next', 'tour-skip', 'toastx'].indexOf(act) < 0) {
			if (act === 'pop') {
				e.stopPropagation();
				S.pop = S.pop && S.pop.type === v && !el.hasAttribute('data-sub') ? null : { type: v, anchor: el.getAttribute('data-anchor'), sub: el.getAttribute('data-sub') || '', id: el.getAttribute('data-id') || '' };
				render();
				return;
			}
			if (act === 'popclose') { S.pop = null; render(); return; }
			if (act === 'modalclose') { S.modal = null; if (VIEW.modalClosed) VIEW.modalClosed(); renderLayer(); return; }
			VIEW.click(e, act, v, el);
			return;
		}
		switch (act) {
			case 'undo': undo(); return;
			case 'help': S.open = null; S.detail = null; S.pop = null; S.modal = null; if (VIEW && VIEW.onTour) VIEW.onTour(); S.tour = 1; render(); return;
			case 'tour-next': if (S.tour >= tourSteps().length) endTour(); else S.tour++; renderLayer(); return;
			case 'tour-skip': endTour(); renderLayer(); return;
			case 'toastx': S.toast = null; renderLayer(); return;
			case 'pop':
				e.stopPropagation();
				S.pop = S.pop && S.pop.type === v && !el.hasAttribute('data-sub') ? null : { type: v, anchor: el.getAttribute('data-anchor'), sub: el.getAttribute('data-sub') || '' };
				if (v === 'b-limit') { S.ban.date = ''; }
				if (v === 'b-point' && S.pop) { S.pt.batchKey = newBatchKey(); }
				render();
				return;
			case 'popclose': S.pop = null; render(); return;
			case 'recount': loadCounts(true, true).then(function () { refreshAll(); }); return;
			case 'quick': S.f.quick = v; delete S.f.group; S.open = null; S.detail = null; applyFilter(); return;
			case 'fgroup': S.f.group = S.f.group === +v ? undefined : +v; S.f.quick = 'all'; S.pop = null; applyFilter(); return;
			case 'fstate': S.f.state = S.f.state === v ? undefined : v; S.pop = null; applyFilter(); return;
			case 'fjoin': S.f.join = S.f.join === v ? undefined : v; ['join_on', 'join_after', 'join_before'].forEach(function (k) { delete S.f[k]; }); S.pop = null; applyFilter(); return;
			case 'flast': S.f.last = S.f.last === v ? undefined : v; ['last_on', 'last_after', 'last_before'].forEach(function (k) { delete S.f[k]; }); S.pop = null; applyFilter(); return;
			case 'dateapply': {
				var mode = (document.getElementById('zmc-dmode') || {}).value || 'on';
				var val = ((document.getElementById('zmc-dval') || {}).value || '').replace(/-/g, '');
				if (!/^\d{8}$/.test(val)) return;
				delete S.f[v];
				['_on', '_after', '_before'].forEach(function (k) { delete S.f[v + k]; });
				S.f[v + '_' + mode] = val;
				S.pop = null;
				applyFilter();
				return;
			}
			case 'qfield': S.f.q_field = S.f.q_field === v ? undefined : v; S.pop = null; if (S.f.q) applyFilter(); else { render(); document.getElementById('zmc-q').focus(); } return;
			case 'moresub': e.stopPropagation(); S.pop.sub = v; renderLayer(); var inp = document.getElementById('zmc-morev'); if (inp) inp.focus(); return;
			case 'moreback': e.stopPropagation(); S.pop.sub = ''; renderLayer(); return;
			case 'moreset': S.f[el.getAttribute('data-k')] = S.f[el.getAttribute('data-k')] === v ? undefined : v; S.pop = null; applyFilter(); return;
			case 'moreapply': {
				var k = el.getAttribute('data-k');
				var mv = ((document.getElementById('zmc-morev') || {}).value || '').trim();
				if (mv) S.f[k] = mv; else delete S.f[k];
				S.pop = null;
				applyFilter();
				return;
			}
			case 'fclear':
				e.stopPropagation();
				if (v === 'all') {
					var keepSort = S.f.sort, keepCount = S.f.list_count;
					S.f = { quick: 'all', sort: keepSort, list_count: keepCount };
				} else if (v === 'join' || v === 'last') {
					delete S.f[v];
					['_on', '_after', '_before'].forEach(function (x) { delete S.f[v + x]; });
				} else {
					delete S.f[v];
				}
				S.pop = null;
				applyFilter();
				return;
			case 'qclear': S.f.q = ''; delete S.f.q_field; applyFilter(); return;
			case 'sel': {
				e.stopPropagation();
				var id = +v, i = S.sel.indexOf(id);
				if (i >= 0) { S.sel.splice(i, 1); delete S.selInfo[id]; } else { S.sel.push(id); S.selInfo[id] = row(id); }
				render();
				return;
			}
			case 'selall': {
				e.stopPropagation();
				var ids = S.rows.map(function (r) { return r.id; });
				var all = ids.length && ids.every(function (x) { return S.sel.indexOf(x) >= 0; });
				if (all) { S.sel = []; S.selInfo = {}; } else { S.sel = ids; S.selInfo = {}; S.rows.forEach(function (r) { S.selInfo[r.id] = r; }); }
				render();
				return;
			}
			case 'selclear': S.sel = []; S.selInfo = {}; S.pop = null; render(); return;
			case 'prev':
				if (S.stack.length) S.cur = S.stack.pop();
				else S.cur = { cursor: '', offset: Math.max(0, (S.cur.offset || 0) - S.f.list_count) };
				S.pageNo = Math.max(1, S.pageNo - 1);
				loadList(false);
				var tw = document.getElementById('zmc-tablewrap'); if (tw) tw.scrollTop = 0;
				return;
			case 'next':
				if (!S.hasMore) return;
				S.stack.push(S.cur);
				S.cur = { cursor: S.nextCursor, offset: 0 };
				S.pageNo++;
				loadList(false);
				var tw2 = document.getElementById('zmc-tablewrap'); if (tw2) tw2.scrollTop = 0;
				return;
			case 'open': {
				var oid = +v;
				if (S.open === oid) return;
				S.open = oid; S.detail = null; S.tab = 'info'; S.pt.memo = ''; S.ban = { days: 7, date: '', reason: '' };
				render();
				loadDetail(oid);
				return;
			}
			case 'close': S.open = null; S.detail = null; render(); return;
			case 'tab': S.tab = v; S.pop = null; render(); return;
			case 'mgroup': toggleGroup(+v); return;
			case 'ptmode': S.pt.mode = v; render(); return;
			case 'ptamt': S.pt.amt = +v; render(); return;
			case 'ptgo': if (m) askPoints([m.id], false); return;
			case 'bptgo': askPoints(S.sel.slice(), true); return;
			case 'bigptgo': { var bp = S.bigpt; S.modal = null; S.bigpt = null; renderLayer(); runPoints(bp.ids, bp.mode, bp.amt, bp.memo, bp.bulk); return; }
			case 'bgmode': S.bg.mode = v; renderLayer(); return;
			case 'bggid': S.bg.gid = +v; renderLayer(); return;
			case 'bggo': bulkGroups(); return;
			case 'bulkconfirm': {
				var cids = S.sel.slice();
				S.pop = null;
				applyFields(cids, { status: 'APPROVED', only_from: 'UNAUTHED' }, { status: true }, T('undo_confirm'), function (d) {
					if (!d.changed) { toast(T('toast_no_unauthed'), '', null); return; }
					toast(T('toast_confirmed', fmt(d.changed)), T('toast_confirmed_sub'), T('undo'));
				});
				return;
			}
			case 'confirm1':
				if (!m) return;
				applyFields([m.id], { status: 'APPROVED', only_from: 'UNAUTHED' }, { status: true }, T('undo_confirm'), function (d) {
					if (d.changed) toast(T('toast_confirmed_one', m.nick), T('toast_confirmed_sub'), T('undo'));
				});
				return;
			case 'bandays': S.ban.days = +v; S.ban.date = ''; if (S.pop) renderLayer(); else render(); return;
			case 'limitgo': if (m) limitMembers([m.id], T('undo_limit', m.nick)); return;
			case 'bulklimit': { var lids = S.sel.slice(); S.pop = null; limitMembers(lids, T('undo_bulk_limit', fmt(lids.length))); return; }
			case 'unlimit':
				if (!m) return;
				applyFields([m.id], { limit_set: 'Y', limit_date: '' }, { limit: true }, T('undo_unlimit', m.nick), function () {
					toast(T('toast_unlimit', m.nick), '', T('undo'));
				});
				return;
			case 'denyswitch':
			case 'undeny':
				if (!m) return;
				if (m.state === 'denied') {
					applyFields([m.id], { status: 'APPROVED', only_from: 'DENIED' }, { status: true }, T('undo_deny', m.nick), function () {
						toast(T('toast_undeny', m.nick), '', T('undo'));
					});
				} else {
					applyFields([m.id], { status: 'DENIED' }, { status: true }, T('undo_deny', m.nick), function (d) {
						toast(T('toast_deny', m.nick), d.revoked ? T('toast_logged_out') : '', T('undo'));
					});
				}
				return;
			case 'bulkdeny': {
				var dids = S.sel.filter(function (x) { return x !== B.me; });
				var why = ((document.getElementById('zmc-denywhy') || {}).value || '').trim();
				S.modal = null;
				renderLayer();
				applyFields(dids, $.extend({ status: 'DENIED' }, why ? { refused_reason: why } : {}), { status: true }, T('undo_bulk_deny', fmt(dids.length)), function (d) {
					toast(T('toast_bulk_deny', fmt(d.changed || 0)), d.revoked ? T('toast_logged_out') : '', T('undo'));
				});
				return;
			}
			case 'logout1':
				if (!m) return;
				busy(true);
				api('member.procMemberAdminRevokeLogins', { member_srl: m.id, autologin_id: +v }).then(function () {
					busy(false);
					toast(T('toast_logout1'), T('toast_logout1_sub'), null);
					loadDetail(m.id);
				}, function (er) { busy(false); failed(er); });
				return;
			case 'logoutall':
				if (!m) return;
				busy(true);
				api('member.procMemberAdminRevokeLogins', { member_srl: m.id }).then(function () {
					busy(false);
					toast(T('toast_logout_all', m.nick), '', null);
					loadDetail(m.id);
				}, function (er) { busy(false); failed(er); });
				return;
			case 'resetmail':
				if (!m) return;
				S.pop = null;
				if (/@(sns|social-login)\.local$/i.test(m.email)) {
					toast(T('toast_reset_mail_temp', m.nick), T('toast_reset_mail_temp_sub'), null, true);
					return;
				}
				renderLayer();
				busy(true);
				api('member.procMemberFindAccount', { email_address: m.email }).then(function () {
					busy(false);
					toast(T('toast_reset_mail', m.nick), m.email, null);
				}, function (er) { busy(false); failed(er); });
				return;
			case 'modal':
				S.modal = v;
				S.pop = null;
				if (v === 'msg' || v === 'msg1') S.msg = '';
				render();
				var focusId = v.indexOf('msg') === 0 ? 'zmc-msg' : v === 'deny' ? 'zmc-denywhy' : '';
				if (focusId && document.getElementById(focusId)) document.getElementById(focusId).focus();
				return;
			case 'modalclose': S.modal = null; S.bigpt = null; renderLayer(); return;
			case 'msggo': {
				var mids = S.modal === 'msg1' ? [S.open] : S.sel.slice();
				var text = S.msg.trim();
				if (!text) return;
				S.modal = null;
				renderLayer();
				busy(true);
				api('member.procMemberAdminSelectedMemberManage', { type: 'modify', member_srls: mids, message: text }).then(function () {
					busy(false);
					toast(T('toast_msg', fmt(mids.length)), '', null);
				}, function (er) { busy(false); failed(er); });
				return;
			}
			case 'leavego': {
				var single = v === 'leave1';
				var lv = single ? [S.open] : S.sel.filter(function (x) { return x !== B.me; });
				S.modal = null;
				renderLayer();
				busy(true);
				var call = single ? api('member.procMemberAdminDelete', { member_srl: lv[0] }) : api('member.procMemberAdminSelectedMemberManage', { type: 'delete', member_srls: lv });
				call.then(function () {
					busy(false);
					toast(T('toast_left', fmt(lv.length)), '', null);
					if (single || lv.indexOf(S.open) >= 0) { S.open = null; S.detail = null; }
					S.sel = []; S.selInfo = {};
					refreshAll();
				}, function (er) { busy(false); failed(er); });
				return;
			}
		}
	}
	function onInput(e) {
		var t = e.target, id = t.id;
		if (VIEW) { if (VIEW.input) VIEW.input(e); return; }
		if (id === 'zmc-q') {
			S.f.q = t.value;
			clearTimeout(onInput.qt);
			onInput.qt = setTimeout(function () { applyFilter(); }, 350);
			return;
		}
		if (id === 'zmc-ptamt' || id === 'zmc-bpamt') {
			S.pt.amt = parseInt(String(t.value).replace(/\D/g, ''), 10) || 0;
			updatePointButtons();
			return;
		}
		if (id === 'zmc-ptmemo' || id === 'zmc-bpmemo') {
			S.pt.memo = t.value;
			updatePointButtons();
			return;
		}
		if (id === 'zmc-banwhy') { S.ban.reason = t.value; return; }
		if (id === 'zmc-msg') {
			S.msg = t.value;
			var b = document.getElementById('zmc-msggo');
			if (b) b.disabled = !S.msg.trim();
			return;
		}
		if (id === 'zmc-memo' && S.detail) {
			memoPending = { id: S.detail.id, text: t.value };
			S.save = 'saving';
			renderBar();
			clearTimeout(memoTimer);
			memoTimer = setTimeout(saveMemo, 700);
		}
	}
	function updatePointButtons() {
		var ledger = B.point && B.point.ledger;
		var ok = S.pt.amt > 0 && (!ledger || S.pt.memo.trim());
		var b1 = document.getElementById('zmc-ptgo');
		if (b1) {
			b1.disabled = !ok;
			b1.textContent = T(S.pt.mode === 'give' ? 'pt_go_give' : 'pt_go_take', fmt(S.pt.amt));
		}
		var note = document.getElementById('zmc-ptnote');
		if (note && S.detail && S.detail.point) {
			var span = note.querySelector('span');
			var base = S.detail.point.value;
			span.innerHTML = T('pt_preview', esc(S.detail.nick), '<b>' + fmt(base) + ' → ' + fmt(ptAfter(base, S.pt.mode, S.pt.amt)) + '</b>') + (ledger ? ' ' + T('pt_preview_ledger', term('ledger', T('ledger'))) : '');
		}
		var b2 = document.getElementById('zmc-bpgo');
		if (b2) {
			b2.disabled = !ok;
			b2.textContent = T(S.pt.mode === 'give' ? 'bp_go_give' : 'bp_go_take', fmt(S.pt.amt));
			var imp = LAYER.querySelectorAll('.impact b');
			if (imp.length === 2) {
				imp[0].textContent = T('pt_n', fmt(S.pt.amt));
				imp[1].textContent = T('pt_n', fmt(S.pt.amt * S.sel.length));
			}
		}
	}
	function onChange(e) {
		var t = e.target;
		if (VIEW) { if (VIEW.change) VIEW.change(e); return; }
		if (t.id === 'zmc-sort') { S.f.sort = t.value; applyFilter(); return; }
		if (t.id === 'zmc-count') {
			S.f.list_count = parseInt(t.value, 10) || 30;
			try { localStorage.setItem(COUNT_KEY, String(S.f.list_count)); } catch (er) { }
			applyFilter();
			return;
		}
		if (t.id === 'zmc-bandate') {
			S.ban.date = String(t.value || '').replace(/-/g, '');
			if (S.pop) renderLayer(); else render();
			return;
		}
		if (t.id === 'zmc-dmode' && S.pop) { S.pop.dmode = t.value; return; }
		if (t.id === 'zmc-dval' && S.pop) { S.pop.dval = String(t.value || '').replace(/-/g, ''); return; }
	}
	function onKey(e) {
		if ((e.ctrlKey || e.metaKey) && (e.key === 'z' || e.key === 'Z') && !/INPUT|TEXTAREA|SELECT/.test((document.activeElement || {}).tagName || '')) {
			if (!ROOT.offsetParent) return;
			e.preventDefault();
			undo();
			return;
		}
		if (e.key === 'Escape') {
			if (tipFor) { hideTip(); return; }
			if (S.tour) { endTour(); renderLayer(); return; }
			if (S.modal) { S.modal = null; if (VIEW && VIEW.modalClosed) VIEW.modalClosed(); renderLayer(); return; }
			if (S.pop) { S.pop = null; render(); return; }
		}
		if (VIEW) {
			if (VIEW.key) VIEW.key(e);
			return;
		}
		if (e.key === 'Escape') {
			return;
		}
		if (e.key === 'Enter') {
			var id = (document.activeElement || {}).id;
			if (id === 'zmc-morev') { e.preventDefault(); var b = LAYER.querySelector('[data-act="moreapply"]'); if (b) b.click(); }
			if (id === 'zmc-q') { e.preventDefault(); clearTimeout(onInput.qt); applyFilter(); }
		}
		if ((e.key === 'Enter' || e.key === ' ') && e.target.classList && e.target.classList.contains('fx')) {
			e.preventDefault();
			e.target.click();
		}
	}

	/* ── 시작 ─────────────────────────── */
	function boot() {
		B = window.ZMC_BOOT;
		ROOT = document.getElementById('zmc');
		if (!B || !ROOT) return;
		L = B.lang || {};
		TM = B.terms || {};
		var content = document.getElementById('content');
		if (content && content.contains(ROOT)) content.classList.add('zmc-host');

		(B.groups || []).forEach(function (g, i) {
			g.color = g.admin ? '#334155' : PALETTE[i % PALETTE.length];
			GMAP[g.id] = g;
			GROUPS.push(g);
		});

		LAYER = document.createElement('div');
		LAYER.className = 'zmc-layer';
		TIP = document.createElement('div');
		TIP.id = 'zmc-tip';
		TIP.className = 'termtip';
		TIP.setAttribute('role', 'tooltip');
		TIP.hidden = true;

		S = fresh();
		var views = window.ZMC_VIEWS || {};
		if (B.view && B.view !== 'list' && views[B.view]) {
			VIEW = views[B.view];
			VIEW.init(kit());
		}
		render();

		ROOT.addEventListener('click', onClick);
		ROOT.addEventListener('input', onInput);
		ROOT.addEventListener('change', onChange);
		document.addEventListener('keydown', onKey);
		document.addEventListener('click', function (e) {
			if (e.zmcInside) return;
			if (S.pop && !ROOT.contains(e.target)) { S.pop = null; render(); }
			if (tipFor && !e.target.closest('.term')) hideTip();
		});
		ROOT.addEventListener('mouseover', function (e) {
			var t = e.target.closest('.term');
			if (t) showTip(t);
			else if (tipFor) hideTip();
		});
		ROOT.addEventListener('focusin', function (e) { var t = e.target.closest('.term'); if (t) showTip(t); });
		ROOT.addEventListener('focusout', function (e) { if (e.target.closest('.term')) hideTip(); });
		window.addEventListener('scroll', hideTip, true);
		window.addEventListener('resize', function () { placePop(); placeTour(); });
		window.addEventListener('beforeunload', function () { if (memoPending) saveMemo(); });

		if (VIEW) {
			if (VIEW.start) VIEW.start();
			maybeTour();
			return;
		}
		loadCounts(false);
		loadList(false);
	}

	/* ── 다른 화면(그룹 · 가입 폼 · 회원 설정)에 넘기는 도구 ─────────────────────────── */
	function kit() {
		return {
			B: B, L: L, ROOT: ROOT, LAYER: LAYER, GROUPS: GROUPS, GMAP: GMAP, PALETTE: PALETTE,
			state: function () { return S; },
			T: T, esc: esc, q: q, fmt: fmt, ic: ic, icons: P, term: term, sw: sw, cbox: cbox, gtag: gtag,
			api: api, errText: errText, busy: busy, failed: failed, pushUndo: pushUndo, toast: toast,
			render: render, renderLayer: renderLayer, renderBar: renderBar, placePop: placePop, placeTour: placeTour,
			hideTip: hideTip, isMobile: isMobile, htmlText: htmlText, dateDot: dateDot, dateTime: dateTime, relTime: relTime
		};
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
	else boot();
})(jQuery);
