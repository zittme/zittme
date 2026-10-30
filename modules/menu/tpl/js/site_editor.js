/**
 * Site editor (menu module)
 *
 * Every change is saved right away through the existing menu and module
 * actions, then the state is read again from the server.
 */
(function ($) {
	'use strict';

	var B, L, ST, UI, IDX, ROOT;
	var UNDO = [];
	var REMAP = {};
	var QUEUE = Promise.resolve();
	var lastFrameUrl = '';

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

	/* ── 아이콘 ─────────────────────────── */
	var P = {
		home: '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/>',
		folder: '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
		board: '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M8 9h8M8 13h8M8 17h5"/>',
		article: '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 12h6M9 16h6"/>',
		widget: '<rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="5" rx="1.5"/><rect x="13" y="10" width="8" height="11" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/>',
		outside: '<path d="M6 3h8l4 4v14H6z"/><path d="M10 12l-2 2.5 2 2.5M14 12l2 2.5-2 2.5"/>',
		link: '<path d="M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1 1"/><path d="M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1-1"/>',
		shortcut: '<path d="M15 4l5 5-5 5"/><path d="M20 9H10a6 6 0 0 0-6 6v4"/>',
		shop: '<path d="M5 8h14l-1 12H6z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
		blog: '<path d="M4 20l4-1 11-11-3-3L5 16z"/>',
		reservation: '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4M16 3v4M4 10h16"/>',
		module: '<path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z"/><path d="M4 7.5l8 4.5 8-4.5M12 12v9"/>',
		plus: '<path d="M12 5v14M5 12h14"/>',
		more: '<circle cx="5" cy="12" r="1.4" fill="currentColor"/><circle cx="12" cy="12" r="1.4" fill="currentColor"/><circle cx="19" cy="12" r="1.4" fill="currentColor"/>',
		chev: '<path d="M6 9l6 6 6-6"/>',
		chevr: '<path d="M9 6l6 6-6 6"/>',
		back: '<path d="M15 18l-6-6 6-6"/>',
		search: '<circle cx="11" cy="11" r="6"/><path d="M20 20l-4-4"/>',
		undo: '<path d="M9 14L4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-3"/>',
		eye: '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
		eyeoff: '<path d="M3 3l18 18"/><path d="M10.6 5.1A10 10 0 0 1 12 5c6 0 10 7 10 7a17 17 0 0 1-3.2 3.9M6.5 6.6C3.8 8.4 2 12 2 12s4 7 10 7a9.6 9.6 0 0 0 4.3-1"/>',
		lock: '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
		users: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M21.5 20a6.5 6.5 0 0 0-4-6"/>',
		globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
		ext: '<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
		monitor: '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
		tablet: '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M11 18h2"/>',
		phone: '<rect x="7" y="3" width="10" height="18" rx="2"/><path d="M11 18h2"/>',
		check: '<path d="M5 12l5 5 9-10"/>',
		x: '<path d="M6 6l12 12M18 6L6 18"/>',
		grip: '<circle cx="9" cy="6" r="1.3" fill="currentColor"/><circle cx="15" cy="6" r="1.3" fill="currentColor"/><circle cx="9" cy="12" r="1.3" fill="currentColor"/><circle cx="15" cy="12" r="1.3" fill="currentColor"/><circle cx="9" cy="18" r="1.3" fill="currentColor"/><circle cx="15" cy="18" r="1.3" fill="currentColor"/>',
		trash: '<path d="M4 7h16M10 11v6M14 11v6"/><path d="M6 7l1 13h10l1-13M9 7V4h6v3"/>',
		reload: '<path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 5v6h-6"/>',
		info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
		inbox: '<path d="M3 13h5l1 3h6l1-3h5"/><path d="M5 5h14l2 8v6H3v-6z"/>',
		layers: '<path d="M12 3l9 5-9 5-9-5z"/><path d="M3 13l9 5 9-5"/>',
		up: '<path d="M12 19V5M6 11l6-6 6 6"/>',
		down: '<path d="M12 5v14M6 13l6 6 6-6"/>',
		indent: '<path d="M4 6h16M10 12h10M4 18h16M4 9l3 3-3 3"/>',
		outdent: '<path d="M4 6h16M10 12h10M4 18h16M7 9l-3 3 3 3"/>',
		sparkle: '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5L18 18M6 18l2.5-2.5M15.5 8.5L18 6"/>',
		sites: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18"/>',
		searchoff: '<circle cx="11" cy="11" r="6"/><path d="M20 20l-4-4M4 4l16 16"/>',
		palette: '<path d="M12 3a9 9 0 1 0 0 18c1.1 0 2-.9 2-2 0-.5-.2-1-.5-1.3-.3-.4-.5-.8-.5-1.3 0-1.1.9-2 2-2h2.3A4.7 4.7 0 0 0 22 9.7C22 5.9 17.5 3 12 3z"/><circle cx="7.5" cy="11" r="1.2" fill="currentColor"/><circle cx="10.5" cy="7" r="1.2" fill="currentColor"/><circle cx="15.5" cy="7.5" r="1.2" fill="currentColor"/>',
		edit: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/>',
		copy: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
		move: '<path d="M5 12h14M13 6l6 6-6 6"/>'
	};
	function ic(name, size, sw) {
		size = size || 16;
		return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' + (sw || 1.9) + '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + (P[name] || P.module) + '</svg>';
	}

	/* ── 용어 설명 ─────────────────────────── */
	var TERM_KEYS = ['sitemap', 'slot', 'layout', 'skin', 'mid', 'widget', 'article', 'outside', 'shortcut', 'unlinked', 'who', 'vis_public', 'vis_member', 'vis_groups', 'vis_admin', 'inherit', 'page_block', 'menu_only', 'seo', 'seo_index', 'seo_sitemap', 'seo_sd', 'hidden', 'theme', 'scope', 'prefix', 'sitemap_split'];
	function termInfo(key) {
		if (TERM_KEYS.indexOf(key) < 0) return null;
		var e = L['term_' + key + '_e'];
		return { t: T('term_' + key + '_t'), d: T('term_' + key + '_d'), e: e ? T('term_' + key + '_e') : '' };
	}
	function term(key, label) {
		var info = termInfo(key);
		return '<span class="term" tabindex="0" data-term="' + key + '">' + esc(label || (info ? info.t : key)) + '</span>';
	}
	function midTag(mid) { return '<span class="midtag"><i>mid</i>' + esc(mid) + '</span>'; }
	function termWrap(key, html) { return '<span class="term chipterm" tabindex="0" data-term="' + key + '">' + html + '</span>'; }
	function stateChip(own) { return termWrap('inherit', '<span class="state ' + (own ? 'inh-own' : 'follow') + '">' + esc(T(own ? 'state_own' : 'state_default')) + '</span>'); }

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
	function rid(id) {
		var n = 0;
		while (REMAP[id] && n++ < 50) id = REMAP[id];
		return id;
	}

	function refresh() {
		var p = api('menu.getMenuAdminEditorState', { domain_srl: ST.site.domain_srl }).then(function (data) {
			if (data.state) setState(data.state);
		});
		if (UI.view === 'overview') p = p.then(loadOverview);
		return p;
	}

	/**
	 * Run one change: save indicator, undo entry, toast, state refresh.
	 * o = { label, run: fn → Promise, undo: fn → Promise, toast: [title, sub], done: fn(result), local: fn() }
	 */
	function mutate(o) {
		UI.pending++;
		UI.failed = null;
		renderSave();
		if (o.local) { try { o.local(); render(); } catch (e) { } }
		var p = QUEUE.then(function () {
			return o.run().then(function (r) {
				if (o.undo && !o.isUndo) {
					UNDO.push({ label: o.label, run: o.undo });
					if (UNDO.length > 30) UNDO.shift();
				}
				return refresh().then(function () {
					if (o.done) o.done(r);
					if (o.toast) toast(o.toast[0], o.toast[1], !o.isUndo && !!o.undo, o.isUndo ? null : o.toast[2]);
					reloadFrame();
					return r;
				});
			}).catch(function (err) {
				UI.failed = o;
				toast(T('toast_failed'), (err && err.message) ? String(err.message) : T('error_generic'), false);
				return refresh().catch(function () { });
			});
		}).then(function (r) {
			UI.pending--;
			renderSave();
			render();
			return r;
		});
		QUEUE = p.catch(function () { });
		return p;
	}
	function undo() {
		var u = UNDO.pop();
		if (!u) { toast(T('toast_nothing_undo'), '', false); render(); return; }
		mutate({
			label: u.label, isUndo: true, run: u.run,
			toast: [T('toast_undone'), T('toast_undone_sub', u.label)]
		});
	}
	function retry() {
		var o = UI.failed;
		if (!o) return;
		UI.failed = null;
		mutate(o);
	}

	/* ── 상태 · 색인 ─────────────────────────── */
	function setState(state) {
		ST = state;
		IDX = {};
		ST.sitemaps.forEach(function (sm) { walk(sm.items, null, sm, 0); });
		var unl = { menu_srl: ST.unlinked.menu_srl, unlinked: true, title: T('unlinked'), items: ST.unlinked.items };
		walk(ST.unlinked.items, null, unl, 0);
		function walk(list, parent, sm, depth) {
			list.forEach(function (it) {
				IDX[it.id] = { item: it, parent: parent, sitemap: sm, list: list, depth: depth };
				walk(it.children || [], it, sm, depth + 1);
			});
		}
		UI.sel = (UI.sel || []).filter(function (x) { return !!IDX[x]; });
		if (UI.kc && !IDX[UI.kc.id]) UI.kc = null;
		if (UI.focus.type === 'multi' && UI.sel.length < 2) UI.focus = UI.sel.length ? { type: 'item', id: UI.sel[0] } : { type: 'site' };
		if (UI.focus.type === 'item' && !IDX[UI.focus.id]) UI.focus = { type: 'site' };
		if (UI.focus.type === 'sitemap' && !sitemapBySrl(UI.focus.id)) UI.focus = { type: 'site' };
		if (UI.focus.type === 'orphan' && !orphanBySrl(UI.focus.id)) UI.focus = { type: 'site' };
	}
	function find(id) { return IDX[id] || null; }
	function sitemapBySrl(srl) { for (var i = 0; i < ST.sitemaps.length; i++) if (ST.sitemaps[i].menu_srl === srl) return ST.sitemaps[i]; return null; }
	function orphanBySrl(srl) { for (var i = 0; i < ST.unlinked.orphans.length; i++) if (ST.unlinked.orphans[i].module_srl === srl) return ST.unlinked.orphans[i]; return null; }
	function layoutBySrl(srl, type) { var list = ST.layouts[type || 'P'] || []; for (var i = 0; i < list.length; i++) if (list[i].layout_srl === srl) return list[i]; return null; }
	function isModule(it) { return !!(it && it.module_srl); }
	function hasDesign(it) { return isModule(it); }
	function hasSkin(it) { return isModule(it) && it.kind !== 'widget' && it.kind !== 'outside' && skinsOf(it).P.length > 0; }
	function skinsOf(it) { return (ST.skins && ST.skins[it.module]) || { P: [], M: [], default_P: '', default_M: '' }; }
	function parentKey(f) { return f.parent ? f.parent.id : f.sitemap.menu_srl; }
	function prevSibling(f, exceptId) {
		var list = f.list.filter(function (x) { return x.id !== exceptId; });
		var i = list.indexOf(f.item);
		return i > 0 ? list[i - 1].id : 0;
	}
	function isDescendant(ancestorId, id) {
		var f = find(id);
		while (f && f.parent) { if (f.parent.id === ancestorId) return true; f = find(f.parent.id); }
		return false;
	}
	function allMids() { var m = {}; (ST.mids || []).forEach(function (x) { m[String(x).toLowerCase()] = true; }); return m; }

	function kindLabel(it) {
		switch (it.kind) {
			case 'widget': return T('kind_widget');
			case 'article': return T('kind_article');
			case 'outside': return T('kind_outside');
			case 'board': return T('kind_board');
			case 'link': return T('kind_link');
			case 'shortcut': return T('kind_shortcut');
			case 'folder': return T('kind_folder');
		}
		return (ST.module_titles && ST.module_titles[it.module]) || it.module || T('kind_link');
	}
	function kindIcon(kind) {
		if (P[kind]) return kind;
		if (kind === 'commerce') return 'shop';
		if (kind === 'blogz') return 'blog';
		if (kind === 'reservation') return 'reservation';
		return 'module';
	}
	function whoOf(it) {
		var g = String(it.group_srls || '');
		if (g === '') return { type: 'public', groups: [] };
		if (g === '-1') return { type: 'member', groups: [] };
		if (g === '-3') return { type: 'admin', groups: [] };
		if (g === '-4') return { type: 'guest', groups: [] };
		return { type: 'groups', groups: g.split(',').map(function (x) { return parseInt(x, 10); }).filter(function (x) { return x > 0; }) };
	}
	function groupNames(ids) {
		return ids.map(function (id) { for (var i = 0; i < ST.groups.length; i++) if (ST.groups[i].group_srl === id) return ST.groups[i].title; return null; }).filter(Boolean);
	}
	function whoLabel(w) {
		if (w.type === 'groups') return T('badge_groups', w.groups.length);
		return T({ public: 'who_public', member: 'badge_member', admin: 'badge_admin', guest: 'badge_guest' }[w.type]);
	}
	function targetOf(it) {
		if (it.kind !== 'shortcut') return null;
		for (var id in IDX) { var x = IDX[id].item; if (x.is_shortcut === 'N' && x.url === it.target_mid) return x; }
		return null;
	}
	function viewUrlOf(it) {
		if (!it) return ST.site.base_url;
		if (it.kind === 'shortcut') { var t = targetOf(it); return t ? viewUrlOf(t) : ST.site.base_url; }
		if (isModule(it)) return it.view_url;
		return ST.site.base_url;
	}

	/* ── 디자인 · 권한 ─────────────────────────── */
	function designOf(it) {
		if (!isModule(it)) return null;
		var mob = !!ST.mobile_enabled;
		var skinable = hasSkin(it);
		var d = {
			layoutOwn: it.layout_srl !== -1,
			mOwn: mob && it.mlayout_srl > 0,
			skinOwn: skinable && it.is_skin_fix === 'Y',
			mskinOwn: skinable && mob && skinsOf(it).M.length > 0 && it.is_mskin_fix === 'Y'
		};
		d.any = d.layoutOwn || d.mOwn || d.skinOwn || d.mskinOwn;
		return d;
	}
	function whoRows(w) {
		if (w.type === 'member') return ['-1'];
		if (w.type === 'admin') return ['-3'];
		if (w.type === 'guest') return ['-4'];
		if (w.type === 'groups') return w.groups.map(String);
		return [];
	}
	function sameRows(a, b) {
		var x = (a || []).filter(function (v) { return v !== '0'; }).map(String).sort().join(',');
		var y = (b || []).filter(function (v) { return v !== '0'; }).map(String).sort().join(',');
		return x === y;
	}
	var GR = {};
	function loadGrants(srl, force) {
		if (!srl) return Promise.resolve(null);
		if (GR[srl] && !force) return GR[srl];
		var p = api('menu.getMenuAdminEditorGrants', { module_srl: srl }).then(function (r) {
			p.data = r.grants || [];
			return p.data;
		}).catch(function () { if (GR[srl] === p) delete GR[srl]; return null; });
		GR[srl] = p;
		return p;
	}
	function grantsNow(srl) { return GR[srl] && GR[srl].data ? GR[srl].data : null; }
	function grantBy(list, name) { for (var i = 0; i < (list || []).length; i++) if (list[i].name === name) return list[i]; return null; }
	var GRANT_DEFAULT = { guest: 'public', member: 'member', not_member: 'guest', manager: 'admin', root: 'admin' };
	function grantValue(g) {
		var rows = g.rows || [];
		if (!rows.length) return { type: GRANT_DEFAULT[g.default] || 'public', groups: [], isDefault: true };
		var r = rows[0];
		if (r === '0') return { type: 'public', groups: [] };
		if (r === '-1' || r === '-2') return { type: 'member', groups: [] };
		if (r === '-4') return { type: 'guest', groups: [] };
		if (r === '-3') return { type: 'admin', groups: [] };
		return { type: 'groups', groups: rows.map(function (x) { return parseInt(x, 10); }).filter(function (x) { return x > 0; }) };
	}
	function grantOptions(g) {
		if (g.default === 'manager' || g.default === 'root') return ['groups', 'admin'];
		if (g.default !== 'guest') return ['member', 'guest', 'groups', 'admin'];
		return ['public', 'member', 'guest', 'groups', 'admin'];
	}
	function grantsLocked(list) {
		return (list || []).some(function (g) { return g.default !== 'guest' && (g.rows || []).indexOf('0') >= 0; });
	}
	function accessLinked(it, list) {
		var w = whoOf(it);
		if (w.type === 'public') return false;
		var g = grantBy(list, 'access');
		return !!g && sameRows(g.rows, whoRows(w));
	}
	function scopeOf(it, list) {
		if (!isModule(it) || !list || grantsLocked(list)) return 'menu';
		if (UI.scope[it.id]) return UI.scope[it.id];
		if (whoOf(it).type === 'public') return 'page';
		return accessLinked(it, list) ? 'page' : 'menu';
	}
	function saveAuth(id, srl, exposure, overrides) {
		return loadGrants(srl, true).then(function (list) {
			if (!list || grantsLocked(list)) throw { message: T('grants_readonly') };
			var htPerm = {};
			var any = false;
			list.forEach(function (g) {
				var rows = overrides && overrides[g.name] !== undefined ? overrides[g.name] : g.rows;
				htPerm[g.name] = (rows || []).filter(function (x) { return x !== '0'; }).join(',');
				if (htPerm[g.name]) any = true;
			});
			if (!any && htPerm.manager !== undefined) htPerm.manager = '-3';
			return api('menu.procMenuAdminUpdateAuth', { menu_item_srl: id, exposure: exposure, htPerm: htPerm });
		}).then(function (r) { delete GR[srl]; return r; });
	}
	function snapRows(ids) {
		return api('menu.getMenuAdminEditorRowSnapshot', { items: JSON.stringify(ids) }).then(function (r) { return r.rows || []; });
	}
	function restoreRows(rows) {
		return api('menu.procMenuAdminEditorRestoreRows', { rows: JSON.stringify(rows || []) });
	}
	function itemsOfSite() {
		var out = [];
		ST.sitemaps.forEach(function (sm) { (function walk(list) { list.forEach(function (x) { out.push(x); walk(x.children || []); }); })(sm.items); });
		return out;
	}
	function moduleItems(exceptId) {
		return itemsOfSite().filter(function (x) { return isModule(x) && x.is_shortcut === 'N' && x.id !== exceptId; });
	}

	/* ── 그리기 ─────────────────────────── */
	function render() {
		if (!ROOT) return;
		renderBar();
		var ov = ROOT.querySelector('.zse-ov');
		ROOT.classList.toggle('ov-mode', UI.view === 'overview');
		if (UI.view === 'overview') {
			renderOverview();
			renderLayer();
			return;
		}
		if (ov) ov.innerHTML = '';
		renderRail();
		renderTree();
		renderPanel();
		renderPreview();
		renderLayer();
		var ws = ROOT.querySelector('.zse-ws');
		ws.classList.toggle('nopreview', !UI.preview);
		ws.classList.toggle('m-panel', UI.mPane === 'panel');
		ws.classList.toggle('single-site', ST.sites.length < 2);
	}

	function renderSave() {
		var el = ROOT && ROOT.querySelector('.savestate');
		if (!el) return;
		var h;
		if (UI.pending > 0) h = '<span class="savestate saving" role="status"><i class="dot"></i>' + esc(T('saving')) + '</span>';
		else if (UI.failed) h = '<span class="savestate fail" role="status"><i class="dot"></i>' + esc(T('save_failed')) + '<button type="button" class="linkbtn" data-act="retry">' + esc(T('retry')) + '</button></span>';
		else h = '<span class="savestate" role="status">' + ic('check', 14, 2.6) + esc(T('saved')) + '</span>';
		el.outerHTML = h;
		var u = ROOT.querySelector('[data-act="undo"].d-undo');
		if (u) u.disabled = !UNDO.length;
	}

	function renderBar() {
		var bar = ROOT.querySelector('.zse-bar');
		var sitePick = ST.sites.length > 1 ? '<button type="button" class="sitepick" data-act="m-sites" data-anchor="m-sites"><span class="fav sm">' + esc(initial(ST.site.title)) + '</span><span class="sp-t"><span class="sp-n">' + esc(ST.site.title) + '</span><span class="sp-d">' + esc(ST.site.domain) + '</span></span>' + ic('chev', 16) + '</button>' : '';
		var viewSeg = '<div class="seg viewseg" role="tablist"><button type="button" role="tab" aria-selected="' + (UI.view !== 'overview') + '" class="' + (UI.view !== 'overview' ? 'on' : '') + '" data-act="view" data-v="edit">' + ic('edit', 14) + esc(T('view_edit')) + '</button><button type="button" role="tab" aria-selected="' + (UI.view === 'overview') + '" class="' + (UI.view === 'overview' ? 'on' : '') + '" data-act="view" data-v="overview">' + ic('sites', 14) + esc(T('view_overview')) + '</button></div>';
		bar.innerHTML = '<h1>' + esc(T('title')) + '</h1>' + viewSeg + (UI.view === 'overview' ? '' : sitePick) + '<span class="spacer"></span><span class="savestate"></span>' +
			'<button type="button" class="btn d-undo" data-act="undo" title="' + esc(T('undo')) + ' (Ctrl+Z)"' + (UNDO.length ? '' : ' disabled') + '>' + ic('undo', 16) + esc(T('undo')) + '</button>' +
			'<button type="button" class="btn ghost help-btn" data-act="help" data-anchor="help">' + ic('info', 15) + esc(T('help')) + '</button>' +
			'<button type="button" class="btn ghost legacy-link" data-act="legacy" data-anchor="legacy">' + esc(T('legacy')) + ic('chev', 14) + '</button>' +
			'<button type="button" class="btn pv-toggle" data-act="toggle-preview" aria-pressed="' + (UI.preview ? 'true' : 'false') + '">' + ic(UI.preview ? 'eye' : 'eyeoff', 15) + esc(UI.preview ? T('preview_on') : T('preview_off')) + '</button>';
		renderSave();
	}
	function initial(s) { s = String(s || '').trim(); return s ? s.charAt(0).toUpperCase() : '·'; }
	var FAV_COLORS = ['#1b5fc0', '#2f7d32', '#14213d', '#7a3fb5', '#b45309', '#0f766e', '#be123c'];
	function favColor(i) { return FAV_COLORS[i % FAV_COLORS.length]; }

	function renderRail() {
		var rail = ROOT.querySelector('.zse-rail');
		rail.innerHTML = '<div class="r-label">' + esc(T('rail_sites')) + '</div>' + ST.sites.map(function (s, i) {
			return '<button type="button" class="site-btn' + (s.domain_srl === ST.site.domain_srl ? ' on' : '') + '" data-act="site" data-v="' + s.domain_srl + '" title="' + esc(s.title + ' · ' + s.domain) + '" aria-current="' + (s.domain_srl === ST.site.domain_srl ? 'true' : 'false') + '"><span class="fav" style="background:' + favColor(i) + '">' + esc(initial(s.title)) + '</span><span class="sn">' + esc(s.title) + '</span></button>';
		}).join('') + '<button type="button" class="site-btn add" data-act="wizard" title="' + esc(T('wz_title')) + '"><span class="fav add">' + ic('plus', 18) + '</span><span class="sn">' + esc(T('rail_new_site')) + '</span></button>';
	}

	function siteIndex() { for (var i = 0; i < ST.sites.length; i++) if (ST.sites[i].domain_srl === ST.site.domain_srl) return i; return 0; }

	function badgesHTML(it, sel) {
		var marks = [];
		var d = designOf(it);
		var so = seoOf(it);
		if (so && !so.any) marks.push({ p: 0, l: T('badge_seo_off'), h: '<span class="bdg seo" title="' + esc(T('tip_seo_off')) + '">' + ic('searchoff', 12) + esc(T('badge_seo_off')) + '</span>' });
		if (it.is_hidden === 'Y') marks.push({ p: 2, l: T('badge_hidden'), h: '<span class="bdg vis" title="' + esc(T('tip_hidden')) + '">' + ic('eyeoff', 12) + esc(T('badge_hidden')) + '</span>' });
		if (d && (d.layoutOwn || d.mOwn)) {
			var ltype = d.layoutOwn ? 'P' : 'M';
			var ln = layoutName(ltype === 'P' ? it.layout_srl : it.mlayout_srl, ltype).t;
			var llabel = d.layoutOwn ? T('chip_layout') : T('chip_mlayout');
			marks.push({ p: 1, l: llabel + ' · ' + ln, h: '<button type="button" class="chip own" data-act="chip" data-v="layout" data-id="' + it.id + '" title="' + esc(T('chip_own_layout_tip', ln)) + '"><i class="sw"></i><span>' + esc(llabel) + '</span></button>' });
		}
		if (d && (d.skinOwn || d.mskinOwn)) {
			var sn0 = skinName(it, d.skinOwn ? 'P' : 'M').t;
			marks.push({ p: 1, l: T('chip_skin') + ' · ' + sn0, h: '<button type="button" class="chip own" data-act="chip" data-v="skin" data-id="' + it.id + '" title="' + esc(T('chip_own_skin_tip', sn0)) + '"><i class="sw"></i><span>' + esc(T('chip_skin')) + '</span></button>' });
		}
		if (it.is_home) marks.push({ p: 2, l: T('badge_home'), h: '<span class="bdg home">' + esc(T('badge_home')) + '</span>' });
		var w = whoOf(it);
		if (w.type !== 'public') {
			var tip = w.type === 'groups' ? T('tip_groups', groupNames(w.groups).join(', ')) : T({ member: 'tip_member', admin: 'tip_admin', guest: 'tip_guest' }[w.type]);
			marks.push({ p: 2, l: whoLabel(w), h: '<span class="bdg vis" title="' + esc(tip) + '">' + ic(w.type === 'admin' ? 'lock' : 'users', 12) + esc(whoLabel(w)) + '</span>' });
		}
		if (it.open_window === 'Y') marks.push({ p: 3, l: T('badge_new_window'), h: '<span class="bdg tgt">' + esc(T('badge_new_window')) + '</span>' });
		if (it.kind === 'shortcut') {
			var t = targetOf(it);
			var tn = t ? t.name : it.target_mid;
			marks.push({ p: 3, l: '→ ' + tn, h: '<span class="bdg tgt" title="' + esc(T('tip_shortcut', tn)) + '">→ ' + esc(tn) + '</span>' });
		}
		if (it.kind === 'link' && it.url && it.url !== '#') marks.push({ p: 3, l: it.url, h: '<span class="bdg tgt" title="' + esc(T('tip_link', it.url)) + '">' + ic('ext', 12) + '</span>' });
		marks.sort(function (a, b) { return a.p - b.p; });
		var show = marks.slice(0, 2), rest = marks.slice(2);
		var h = show.map(function (m) { return m.h; }).join('');
		if (rest.length) h += '<span class="bdg more" title="' + esc(rest.map(function (m) { return m.l; }).join(' · ')) + '">+' + rest.length + '</span>';
		if (d && !d.any && sel) h = '<button type="button" class="chip follow-chip" data-act="chip" data-v="layout" data-id="' + it.id + '" title="' + esc(T('chip_default_tip')) + '"><span>' + esc(T('state_default')) + '</span></button>' + h;
		return '<span class="marks">' + h + '</span>';
	}

	function matchSearch(it) {
		var qs = UI.search.trim().toLowerCase();
		if (!qs) return true;
		return String(it.name).toLowerCase().indexOf(qs) >= 0 || String(it.mid || '').toLowerCase().indexOf(qs) >= 0;
	}
	function visibleInSearch(it) {
		if (!UI.search.trim()) return true;
		if (matchSearch(it)) return true;
		return (it.children || []).some(visibleInSearch);
	}

	function rowHTML(it, depth, sm) {
		if (!visibleInSearch(it)) return '';
		var sel = UI.sel.indexOf(it.id) >= 0 && (UI.focus.type === 'item' || UI.focus.type === 'multi' || UI.focus.type === 'kind');
		var kids = it.children && it.children.length;
		var closed = kids && UI.closed[it.id] && !UI.search.trim();
		var flash = UI.flash.indexOf(it.id) >= 0;
		var nm = '<span class="nm">' + esc(it.name) + '</span>';
		if (UI.renaming === it.id) nm = '<input class="inp rename" id="zse-rename" maxlength="250" value="' + esc(it.name) + '" aria-label="' + esc(T('row_rename')) + '">';
		var tip = it.name + (it.mid ? ' · ' + T('panel_mid') + '(mid) ' + it.mid : (it.url && it.kind === 'link' ? ' · ' + it.url : ''));
		var so = seoOf(it);
		var seoBtn = so ? '<button type="button" class="iconbtn seo-btn' + (so.any ? '' : ' off') + '" data-act="seo-toggle" data-id="' + it.id + '" title="' + esc(T(so.any ? 'seo_off_btn' : 'seo_on_btn')) + '" aria-label="' + esc(T(so.any ? 'seo_off_btn' : 'seo_on_btn')) + '">' + ic(so.any ? 'search' : 'searchoff', 15) + '</button>' : '';
		var hideBtn = ST.hidden_ready ? '<button type="button" class="iconbtn hide-btn' + (it.is_hidden === 'Y' ? ' off' : '') + '" data-act="hidden-toggle" data-id="' + it.id + '" title="' + esc(T(it.is_hidden === 'Y' ? 'unhide_btn' : 'hide_btn')) + '" aria-label="' + esc(T(it.is_hidden === 'Y' ? 'unhide_btn' : 'hide_btn')) + '">' + ic(it.is_hidden === 'Y' ? 'eye' : 'eyeoff', 15) + '</button>' : '';
		var acts = '<span class="acts"><button type="button" class="iconbtn" data-act="inline-add" data-g="' + sm.menu_srl + '" data-parent="' + it.id + '" title="' + esc(T('add_sub')) + '" aria-label="' + esc(T('add_sub')) + '">' + ic('plus', 15) + '</button>' + seoBtn + hideBtn +
			'<button type="button" class="iconbtn" data-act="row-menu" data-id="' + it.id + '" data-anchor="rm-' + it.id + '" title="' + esc(T('more')) + '" aria-label="' + esc(T('more')) + '">' + ic('more', 15) + '</button></span>';
		var h = '<div class="row' + (sel ? ' is-sel' : '') + (flash ? ' flash' : '') + '" data-act="row" data-id="' + it.id + '" title="' + esc(tip) + '" style="--lv:' + depth + '" role="treeitem" aria-level="' + (depth + 1) + '" aria-selected="' + (sel ? 'true' : 'false') + '"' + (kids ? ' aria-expanded="' + (!closed) + '"' : '') + ' tabindex="' + (sel ? '0' : '-1') + '">' +
			'<span class="grip" aria-hidden="true">' + ic('grip', 12) + '</span>' +
			(kids ? '<button type="button" class="tw' + (closed ? ' closed' : '') + '" data-act="toggle-item" data-id="' + it.id + '" aria-label="' + esc(it.name) + '">' + ic('chev', 14) + '</button>' : '<span class="tw"></span>') +
			'<span class="ki">' + ic(it.is_home ? 'home' : kindIcon(it.kind), 16) + '</span>' + nm + badgesHTML(it, sel) + acts + '</div>';
		if (kids && !closed) h += it.children.map(function (c) { return rowHTML(c, depth + 1, sm); }).join('');
		if (UI.inline && UI.inline.parent === it.id) h += inlineHTML(depth + 1);
		return h;
	}

	function slotBadge(sm) {
		var u = sm.slots || [];
		if (!u.length) return '<span class="slot none" title="' + esc(T('sitemap_slots_none')) + '">' + esc(T('slot_none')) + '</span>';
		var list = u.map(function (x) { return x.layout_title + ' · ' + x.slot_title; }).join('\n');
		return '<span class="slot" title="' + esc(list) + '">' + esc(u[0].slot_title) + '</span>' + (u.length > 1 ? '<span class="bdg more" title="' + esc(list) + '">+' + (u.length - 1) + '</span>' : '');
	}

	function groupHTML(sm) {
		var key = 'm' + sm.menu_srl;
		var searching = !!UI.search.trim();
		if (searching && !sm.items.some(visibleInSearch)) return '';
		var closed = UI.closed[key] && !searching;
		var on = UI.focus.type === 'sitemap' && UI.focus.id === sm.menu_srl;
		var h = '<div class="grp"><div class="grphead' + (on ? ' on' : '') + '" data-act="sitemap" data-g="' + sm.menu_srl + '">' +
			'<button type="button" class="tw' + (closed ? ' closed' : '') + '" data-act="toggle-group" data-g="' + sm.menu_srl + '" aria-label="' + esc(sm.title) + '">' + ic('chev', 14) + '</button>' +
			'<span class="gname">' + esc(sm.title) + '</span>' + slotBadge(sm) +
			(sm.seo_off ? '<span class="bdg seo" title="' + esc(T('tip_sm_seo_off')) + '">' + ic('searchoff', 12) + esc(T('badge_seo_off')) + '</span>' : '') +
			'<span class="gacts"><button type="button" class="iconbtn" data-act="inline-add" data-g="' + sm.menu_srl + '" data-parent="" title="' + esc(T('add_menu')) + '" aria-label="' + esc(T('add_menu')) + '">' + ic('plus', 15) + '</button>' +
			'<button type="button" class="iconbtn" data-act="sm-menu" data-g="' + sm.menu_srl + '" data-anchor="sm-' + sm.menu_srl + '" title="' + esc(T('more')) + '" aria-label="' + esc(T('more')) + '">' + ic('more', 15) + '</button>' + '</span></div>';
		if (!closed) {
			h += sm.items.map(function (it) { return rowHTML(it, 0, sm); }).join('');
			if (!searching) {
				if (UI.inline && UI.inline.g === sm.menu_srl && !UI.inline.parent) h += inlineHTML(0);
				else h += '<button type="button" class="addline" data-act="inline-add" data-g="' + sm.menu_srl + '" data-parent="" style="--lv:0">' + ic('plus', 14) + esc(T('add_menu')) + '</button>';
			}
		}
		return h + '</div>';
	}

	function unlinkedHTML() {
		var items = ST.unlinked.items || [], orphans = ST.unlinked.orphans || [];
		var total = items.length + orphans.length;
		if (!total) return '';
		var searching = !!UI.search.trim();
		var open = UI.closed.unl === false || searching;
		var rowsFor = function (list, orphan) {
			return list.filter(function (it) { return matchSearch(it); }).map(function (it) {
				var key = orphan ? it.module_srl : it.id;
				var sel = orphan ? (UI.focus.type === 'orphan' && UI.focus.id === key) : (UI.focus.type === 'item' && UI.focus.id === key);
				return '<div class="row unl-row' + (sel ? ' is-sel' : '') + '" data-act="' + (orphan ? 'orphan' : 'row') + '" data-' + (orphan ? 'srl' : 'id') + '="' + key + '" style="--lv:0" role="treeitem" aria-level="1" aria-selected="' + (sel ? 'true' : 'false') + '" tabindex="-1" title="' + esc(it.name + (it.mid ? ' · mid ' + it.mid : '')) + '"><span class="grip" aria-hidden="true">' + ic('grip', 12) + '</span><span class="tw"></span><span class="ki">' + ic(kindIcon(it.kind), 16) + '</span><span class="nm">' + esc(it.name) + '</span>' + (it.mid ? midTag(it.mid) : '') + '</div>';
			}).join('');
		};
		var body = open ? '<div class="unl-note">' + esc(T('unlinked_note')) + '</div>' + rowsFor(items, false) + rowsFor(orphans, true) : '';
		return '<div class="grp unl"><div class="grphead unl-head" data-act="toggle-unl"><button type="button" class="tw' + (open ? '' : ' closed') + '" aria-label="' + esc(T('unlinked')) + '" aria-expanded="' + open + '">' + ic('chev', 14) + '</button><span class="ki">' + ic('inbox', 16) + '</span><span class="gname">' + term('unlinked') + '</span><span class="gcount">' + total + '</span></div>' + body + '</div>';
	}

	function smAddHTML() {
		if (!UI.smAdd) return '<button type="button" class="addline sm-addline" data-act="sm-add" style="--lv:0">' + ic('folder', 14) + esc(T('sm_add')) + '</button>';
		return '<div class="inline-add sm-add" style="--lv:0"><div class="ia-row"><span class="kindchip static">' + ic('folder', 14) + esc(T('sitemap')) + '</span>' +
			'<input id="zse-sm-add" autocomplete="off" placeholder="' + esc(T('sm_add_placeholder')) + '" value="' + esc(UI.smAdd.name) + '" aria-label="' + esc(T('sm_add_placeholder')) + '"></div>' +
			'<div class="ia-sub"><span class="ia-keys">' + T('ia_keys') + '</span></div>' +
			(UI.smAdd.error ? '<div class="hint err">' + ic('info', 13) + esc(UI.smAdd.error) + '</div>' : '') + '</div>';
	}
	function targetOptions(selectedId, exceptId) {
		return moduleItems(exceptId).map(function (x) {
			return '<option value="' + x.id + '"' + (x.id === selectedId ? ' selected' : '') + '>' + esc(x.name) + ' (' + esc(x.mid) + ')</option>';
		}).join('');
	}

	function inlineHTML(lv) {
		var a = UI.inline;
		var kind = a.kind;
		var isLink = kind === 'link', isFolder = kind === 'folder', isShortcut = kind === 'shortcut';
		var sub;
		if (isLink) sub = '<span>' + esc(T('kind_desc_link')) + '</span>';
		else if (isFolder) sub = '<span>' + esc(T('kind_desc_folder')) + '</span>';
		else if (isShortcut) sub = '<label class="ia-target"><span>' + esc(T('ia_target_label')) + '</span><select class="inp sel" id="zse-ia-target" aria-label="' + esc(T('ia_target_label')) + '"><option value="">' + esc(T('shortcut_pick')) + '</option>' + targetOptions(a.target || 0, 0) + '</select></label>';
		else sub = '<span>' + esc(T('ia_mid')) + ' ' + midTag(a.name.trim() ? suggestMid(a.name, kind) : '…') + '</span>';
		return '<div class="inline-add" style="--lv:' + lv + '">' +
			'<div class="ia-row"><button type="button" class="kindchip" data-act="ia-kind" data-anchor="ia-kind" aria-haspopup="true">' + ic(kindIcon(kindOfType(kind)), 14) + esc(kindTitle(kind)) + ic('chev', 12) + '</button>' +
			'<input id="zse-ia" autocomplete="off"' + (isLink ? '' : ' maxlength="250"') + ' placeholder="' + esc(isLink ? T('ia_placeholder_link') : T('ia_placeholder')) + '" value="' + esc(a.name) + '" aria-label="' + esc(T('ia_placeholder')) + '"></div>' +
			'<div class="ia-sub">' + sub + '<span class="ia-keys">' + T('ia_keys') + '</span></div>' +
			(a.error ? '<div class="hint err">' + ic('info', 13) + esc(a.error) + '</div>' : '') + '</div>';
	}

	function renderTree() {
		var tree = ROOT.querySelector('.zse-tree');
		var keepScroll = tree.querySelector('.treescroll') ? tree.querySelector('.treescroll').scrollTop : 0;
		var siteOn = UI.focus.type === 'site';
		var firstG = ST.sitemaps[0];
		var empty = !ST.sitemaps.some(function (sm) { return sm.items.length; });
		var body;
		if (empty && !UI.inline) {
			body = '<div class="emptystate"><div class="es-ico">' + ic('plus', 28) + '</div><h3>' + esc(T('empty_title')) + '</h3><p>' + esc(T('empty_desc')) + '</p>' +
				(firstG ? '<button type="button" class="btn primary lg" data-act="inline-add" data-g="' + firstG.menu_srl + '" data-parent="">' + ic('plus', 16) + esc(T('add_menu')) + '</button>' : '') + '</div>' + ST.sitemaps.map(groupHTML).join('') + smAddHTML() + unlinkedHTML();
		} else {
			body = ST.sitemaps.map(groupHTML).join('') + (UI.search.trim() ? '' : smAddHTML()) + unlinkedHTML();
			if (UI.search.trim() && !/class="row/.test(body)) body = '<div class="noresult">' + esc(T('no_result')) + '</div>';
		}
		var searchVal = UI.search;
		tree.innerHTML =
			'<div class="sitecard"><button type="button" class="sc-head' + (siteOn ? ' on' : '') + '" data-act="focus-site" title="' + esc(T('site_settings')) + '"><span class="fav" style="background:' + favColor(siteIndex()) + '">' + esc(initial(ST.site.title)) + '</span><span class="sc-meta"><span class="sc-name">' + esc(ST.site.title) + '</span><span class="sc-dom">' + esc(ST.site.domain) + '</span></span><span class="sc-set">' + ic('more', 16) + '</span></button>' +
				'<div class="themecard' + (UI.focus.type === 'theme' ? ' on' : '') + '"><span class="tc-ico">' + ic('palette', 18) + '</span><span class="tc-t"><span class="tc-k">' + term('theme', T('site_theme')) + '</span><span class="tc-n">' + esc(themeName()) + '</span></span><button type="button" class="btn" data-act="open-theme">' + esc(T('theme_change')) + '</button></div></div>' +
			'<div class="treetools"><label class="search">' + ic('search', 15) + '<input id="zse-search" placeholder="' + esc(T('search')) + '" aria-label="' + esc(T('search')) + '" value="' + esc(searchVal) + '"></label>' +
			(firstG ? '<button type="button" class="btn primary" data-act="inline-add" data-g="' + firstG.menu_srl + '" data-parent="">' + ic('plus', 15) + esc(T('add_menu')) + '</button>' : '') + '</div>' +
			'<div class="treescroll" role="tree" aria-label="' + esc(ST.site.title) + '">' + body + '</div>';
		tree.querySelector('.treescroll').scrollTop = keepScroll;
		if (UI.searchFocus) {
			var si = document.getElementById('zse-search');
			if (si) { si.focus(); si.setSelectionRange(si.value.length, si.value.length); }
		}
		var ri = document.getElementById('zse-rename');
		if (ri) { ri.focus(); ri.select(); }
		var sma = document.getElementById('zse-sm-add');
		if (sma && UI.smAddFocus) { sma.focus(); sma.setSelectionRange(sma.value.length, sma.value.length); UI.smAddFocus = false; }
		var ia = document.getElementById('zse-ia');
		if (ia && UI.inlineFocus) { ia.focus(); ia.setSelectionRange(ia.value.length, ia.value.length); }
	}

	/* ── 종류 ─────────────────────────── */
	function kindOfType(type) {
		if (type === 'WIDGET' || type === 'ARTICLE' || type === 'OUTSIDE') return type.toLowerCase();
		return type;
	}
	function kindTitle(type) {
		var k = kindOfType(type);
		if (['widget', 'article', 'outside', 'board', 'link', 'folder', 'shortcut'].indexOf(k) >= 0) return T('kind_' + k);
		for (var i = 0; i < ST.kinds.length; i++) if (ST.kinds[i].type === type) return ST.kinds[i].title;
		return type;
	}
	function addableTypes() {
		var pages = [], modules = [];
		ST.kinds.forEach(function (k) {
			if (k.module === 'page') pages.push(k.type);
			else modules.push(k.type);
		});
		modules.sort(function (a, b) { return a === 'board' ? -1 : b === 'board' ? 1 : 0; });
		return { pages: pages, modules: modules, links: ['link', 'shortcut', 'folder'] };
	}
	var WORDS = { '채용': 'recruit', '공고': 'notice', '공지': 'notice', '공지사항': 'notice', '후기': 'review', '문의': 'qna', '질문': 'qna', '질문답변': 'qna', '소개': 'about', '자료': 'data', '자료실': 'data', '행사': 'event', '이벤트': 'event', '기획전': 'event', '갤러리': 'gallery', '소식': 'news', '뉴스': 'news', '자유': 'free', '자유게시판': 'free', '상품': 'product', '보도': 'press', '보도자료': 'press', '연혁': 'history', '인사말': 'greeting', '안내': 'guide', '블로그': 'blog', '예약': 'booking', '홈': 'home', '회사': 'company', '제품': 'product', '사례': 'case', '고객': 'customer', '게시판': 'board', '약관': 'terms', '이용약관': 'terms', '개인정보처리방침': 'privacy', '오시는': 'location', '길': 'map', '팀': 'team', '서비스': 'service', '커뮤니티': 'community', '포트폴리오': 'portfolio', '자주': 'faq', '묻는': '', '질문들': 'faq' };
	function suggestMid(name, type) {
		var n = String(name || '').trim();
		var base;
		if (/^[a-z0-9 _-]+$/i.test(n)) base = n.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
		else base = n.split(/[\s·,/]+/).map(function (w) { return WORDS[w] || ''; }).filter(Boolean).join('_');
		if (!base || !/^[a-z]/.test(base)) {
			var k = kindOfType(type);
			base = (k === 'widget' || k === 'article' || k === 'outside') ? 'page' : String(k).replace(/[^a-z0-9_]/gi, '').toLowerCase() || 'menu';
		}
		var pre = (ST.options && ST.options.prefix) || '';
		if (pre && base.indexOf(pre) !== 0) base = pre + base;
		base = base.slice(0, 36);
		var used = allMids();
		if (!used[base] && base.length > 1) return base;
		for (var i = 2; i < 1000; i++) if (!used[base + i]) return base + i;
		return base;
	}

	/* ── 설정 패널 ─────────────────────────── */
	function collapsible(key, title, sum, body) {
		var open = !!UI.open[key];
		return '<div class="collap' + (open ? ' open' : '') + '"><button type="button" data-act="collap" data-v="' + key + '" aria-expanded="' + open + '"><span class="tw">' + ic('chev', 14) + '</span><b>' + title + '</b><span class="sum">' + esc(sum) + '</span></button><div class="cbody">' + body + '</div></div>';
	}
	function thumbHTML(url, label) {
		if (url) return '<span class="thumb"><img src="' + esc(url) + '" alt="" loading="lazy"></span>';
		return '<span class="thumb ph" aria-hidden="true">' + ic('layers', 20) + '</span>';
	}
	function card(opts) {
		return '<button type="button" class="dcard' + (opts.on ? ' on' : '') + '" data-act="' + opts.act + '" data-v="' + esc(opts.v) + '" aria-pressed="' + (opts.on ? 'true' : 'false') + '">' + (opts.thumbHtml || thumbHTML(opts.thumb)) + '<span class="dc-b"><span class="dc-t">' + esc(opts.t) + '</span>' + (opts.s ? '<span class="dc-s">' + esc(opts.s) + '</span>' : '') + '</span><span class="ck">' + ic('check', 12, 3) + '</span></button>';
	}
	function layoutValue(it, type) { return type === 'M' ? it.mlayout_srl : it.layout_srl; }
	function layoutName(srl, type) {
		if (srl === -1) {
			var d = layoutBySrl(ST.site_default_layout[type], type);
			return { t: T('layout_site_default'), s: d ? d.title : T('layout_none') };
		}
		if (srl === -2) return { t: T('layout_pc_same'), s: T('layout_pc_same_desc') };
		if (!srl) return { t: T('layout_none'), s: T('layout_none_desc') };
		var L1 = layoutBySrl(srl, type);
		return L1 ? { t: L1.title, s: L1.layout_title !== L1.title ? L1.layout_title : '' } : { t: '#' + srl, s: '' };
	}
	function layoutThumb(srl, type) {
		if (srl === -1) { var d = layoutBySrl(ST.site_default_layout[type], type); return d ? d.thumbnail : ''; }
		var x = layoutBySrl(srl, type);
		return x ? x.thumbnail : '';
	}
	function layoutCards(cur, type, act) {
		act = act || 'set-layout';
		var h = '<div class="cards">';
		if (type === 'M') h += card({ act: act, v: '-2', on: cur === -2, t: T('layout_pc_same'), s: T('layout_pc_same_desc'), thumbHtml: '<span class="thumb ph">' + ic('phone', 20) + '</span>' });
		var def = layoutBySrl(ST.site_default_layout[type], type);
		h += card({ act: act, v: '-1', on: cur === -1, t: type === 'M' ? T('layout_mobile_default') : T('layout_site_default'), s: def ? def.title : T('layout_none'), thumb: def ? def.thumbnail : '' });
		(ST.layouts[type] || []).forEach(function (x) {
			h += card({ act: act, v: String(x.layout_srl), on: cur === x.layout_srl, t: x.title, s: x.layout_title !== x.title ? x.layout_title : '', thumb: x.thumbnail });
		});
		if (type === 'P') h += card({ act: act, v: '0', on: cur === 0, t: T('layout_none'), s: T('layout_none_desc'), thumbHtml: '<span class="thumb ph">' + ic('article', 20) + '</span>' });
		return h + '</div>';
	}
	function skinValue(it, type) {
		if (type === 'M') {
			if (it.mskin === '/USE_RESPONSIVE/') return '/USE_RESPONSIVE/';
			return it.is_mskin_fix === 'Y' ? it.mskin : '';
		}
		return it.is_skin_fix === 'Y' ? it.skin : '';
	}
	function skinInfo(it, type, name) {
		var sk = skinsOf(it);
		var list = sk[type] || [];
		for (var i = 0; i < list.length; i++) if (list[i].name === name) return list[i];
		return null;
	}
	function skinName(it, type) {
		var v = skinValue(it, type);
		var sk = skinsOf(it);
		if (v === '/USE_RESPONSIVE/') return { t: T('skin_pc_same'), s: '', thumb: '' };
		if (!v) { var d = skinInfo(it, type, sk['default_' + type]); return { t: T('skin_site_default'), s: d ? d.title : (sk['default_' + type] || ''), thumb: d ? d.thumbnail : '' }; }
		var x = skinInfo(it, type, v);
		return { t: x ? x.title : v, s: '', thumb: x ? x.thumbnail : '' };
	}
	function skinCards(it, type, cur, act) {
		act = act || 'set-skin';
		var sk = skinsOf(it);
		var h = '<div class="cards">';
		if (type === 'M') h += card({ act: act, v: '/USE_RESPONSIVE/', on: cur === '/USE_RESPONSIVE/', t: T('skin_pc_same'), thumbHtml: '<span class="thumb ph">' + ic('phone', 20) + '</span>' });
		var d = skinInfo(it, type, sk['default_' + type]);
		h += card({ act: act, v: '', on: cur === '', t: T('skin_site_default'), s: d ? d.title : (sk['default_' + type] || ''), thumb: d ? d.thumbnail : '' });
		(sk[type] || []).forEach(function (x) {
			h += card({ act: act, v: x.name, on: cur === x.name, t: x.title, s: '', thumb: x.thumbnail });
		});
		return h + '</div>';
	}

	function sectionHead(termKey, own, resetAct) {
		return '<h3>' + term(termKey) + (own === null ? '' : stateChip(own)) + (own && resetAct ? '<span class="h-r"><button type="button" class="linkbtn" data-act="' + resetAct + '">' + esc(T('reset_default')) + '</button></span>' : '') + '</h3>';
	}
	function designTabs(dtab) {
		if (!ST.mobile_enabled) return '';
		return '<div class="seg" style="margin-bottom:8px" role="tablist"><button type="button" role="tab" aria-selected="' + (dtab === 'P') + '" class="' + (dtab === 'P' ? 'on' : '') + '" data-act="dtab" data-v="P">' + ic('monitor', 14) + esc(T('tab_pc')) + '</button><button type="button" role="tab" aria-selected="' + (dtab === 'M') + '" class="' + (dtab === 'M' ? 'on' : '') + '" data-act="dtab" data-v="M">' + ic('phone', 14) + esc(T('tab_mobile')) + '</button></div>';
	}
	function pickRow(key, thumb, fallbackIcon, title, sub) {
		var open = UI.pick === key;
		return '<button type="button" class="pickrow' + (open ? ' open' : '') + '" data-act="pick" data-v="' + key + '" aria-expanded="' + open + '"><span class="pr-th">' + (thumb ? '<img src="' + esc(thumb) + '" alt="">' : ic(fallbackIcon, 18)) + '</span><span class="pr-t"><b>' + esc(title) + '</b>' + (sub ? '<small>' + esc(sub) + '</small>' : '') + '</span><span class="pr-go">' + esc(open ? T('fold') : T('change')) + ic('chev', 14) + '</span></button>';
	}

	function designHTML(it) {
		var d = designOf(it);
		var dtab = UI.dtab === 'M' && ST.mobile_enabled ? 'M' : 'P';
		var lv = layoutValue(it, dtab);
		var ln = layoutName(lv, dtab);
		var lsub;
		if (dtab === 'P' && d.layoutOwn) lsub = T(lv > 0 && lv === ST.site_default_layout.P ? 'own_same_d' : 'own_d');
		else if (dtab === 'M' && d.mOwn) lsub = T('own_d');
		else lsub = ln.s;
		if (dtab === 'P' && d.mOwn) lsub += ' · ' + T('mobile_layout_note', layoutName(it.mlayout_srl, 'M').t);
		var h = '<div class="sec" id="zse-sec-design">' + sectionHead('layout', d.layoutOwn || d.mOwn, 'reset-layout') + designTabs(dtab) +
			pickRow('layout', layoutThumb(lv, dtab), 'layers', ln.t, lsub) +
			(UI.pick === 'layout' ? '<div class="pickbody">' + layoutCards(lv, dtab) + '</div>' : '') + '</div>';
		if (hasSkin(it)) {
			var stype = dtab === 'M' && skinsOf(it).M.length ? 'M' : 'P';
			var sOwnNow = stype === 'P' ? d.skinOwn : d.mskinOwn;
			var sn = skinName(it, stype);
			h += '<div class="sec">' + sectionHead('skin', d.skinOwn || d.mskinOwn, 'reset-skin') +
				pickRow('skin', sn.thumb, kindIcon(it.kind), sn.t, sOwnNow ? T('own_d') : sn.s) +
				(UI.pick === 'skin' ? '<div class="pickbody">' + skinCards(it, stype, skinValue(it, stype)) + '</div>' : '') + '</div>';
		}
		return h;
	}

	function noDesignHTML(it) {
		if (it.kind === 'shortcut') {
			var t = targetOf(it);
			return '<div class="sec"><h3>' + term('layout') + '<span class="sep-dot">·</span>' + term('skin') + '</h3><div class="nodesign"><span class="nd-ico">' + ic('shortcut', 18) + '</span><span class="nd-t"><b>' + esc(T('no_design_title')) + '</b><small>' + esc(T('no_design_shortcut', t ? t.name : it.target_mid)) + '</small></span>' +
				(t ? '<button type="button" class="btn" data-act="goto" data-id="' + t.id + '">' + esc(T('goto_that')) + ic('chevr', 14) + '</button>' : '') + '</div></div>';
		}
		var why = it.kind === 'link' ? 'no_design_link' : it.kind === 'folder' ? 'no_design_folder' : 'no_design_missing';
		return '<div class="sec"><div class="note">' + ic('info', 14) + '<span>' + esc(T(why)) + '</span></div></div>';
	}

	function newWindowRow(it) {
		var on = it.open_window === 'Y';
		return '<div class="swrow"><span class="sw-t">' + esc(T('new_window')) + '<small>' + esc(T(on ? 'new_window_on' : 'new_window_off')) + '</small></span><button type="button" class="switch' + (on ? ' on' : '') + '" role="switch" aria-checked="' + on + '" aria-label="' + esc(T('new_window')) + '" data-act="new-window" data-id="' + it.id + '"></button></div>';
	}

	function groupChecks(ids, act, extra) {
		return '<div class="grpchecks">' + ST.groups.map(function (g) {
			var on = ids.indexOf(g.group_srl) >= 0;
			return '<button type="button" class="checkline gc" role="checkbox" aria-checked="' + on + '" data-act="' + act + '" data-v="' + g.group_srl + '"' + (extra || '') + '><span class="cbox' + (on ? ' on' : '') + '">' + (on ? ic('check', 12, 3) : '') + '</span><span>' + esc(g.title) + '</span></button>';
		}).join('') + '</div>' + (B.urls && B.urls.groups ? '<a class="grp-admin" href="' + esc(B.urls.groups) + '" target="_blank" rel="noopener">' + esc(T('group_admin')) + ' ' + ic('ext', 12) + '</a>' : '');
	}
	function radioLine(act, v, on, termKey, sub, disabled) {
		return '<button type="button" class="checkline radio" role="radio" aria-checked="' + on + '" data-act="' + act + '" data-v="' + v + '"' + (disabled ? ' disabled' : '') + '><span class="cbox round' + (on ? ' on' : '') + '">' + (on ? ic('check', 12, 3) : '') + '</span><span>' + term(termKey) + '<small>' + esc(sub) + '</small></span></button>';
	}
	function ensureGrants(srl) {
		if (srl && !GR[srl]) loadGrants(srl).then(function () { renderPanel(); });
	}

	function whoSegHTML(type, act) {
		var opts = [['public', 'who_public'], ['member', 'who_member'], ['groups', 'who_groups'], ['admin', 'who_admin']];
		return '<div class="seg full" role="radiogroup" aria-label="' + esc(T('term_who_t')) + '">' + opts.map(function (o) {
			var on = type === o[0];
			return '<button type="button" role="radio" aria-checked="' + on + '" class="' + (on ? 'on' : '') + '" data-act="' + act + '" data-v="' + o[0] + '">' + esc(T(o[1])) + '</button>';
		}).join('') + '</div>';
	}

	function whoHTML(it) {
		var w = UI.whoDraft && UI.whoDraft.id === it.id ? UI.whoDraft.w : whoOf(it);
		var h = '<div class="sec" id="zse-sec-who"><h3>' + term('who') + '</h3>' + whoSegHTML(w.type, 'set-who');
		if (w.type === 'guest') h += '<div class="hint">' + ic('info', 13) + esc(T('who_guest_note')) + '</div>';
		if (w.type === 'groups') {
			h += '<div class="flabel grp-label">' + esc(T('who_groups_label')) + ' <small>' + esc(w.groups.length ? T('who_groups_count', w.groups.length) : T('who_groups_pick')) + '</small></div>' + groupChecks(w.groups, 'who-group');
		}
		var list = isModule(it) ? grantsNow(it.module_srl) : null;
		if (isModule(it)) ensureGrants(it.module_srl);
		if (isModule(it) && w.type !== 'public') {
			if (!list) h += '<div class="hint">' + esc(T('grants_loading')) + '</div>';
			else if (grantsLocked(list)) h += '<div class="hint">' + ic('info', 13) + esc(T('scope_page_locked')) + '</div>';
			else {
				var sc = scopeOf(it, list);
				h += '<div class="flabel scope-label">' + esc(T('scope_label')) + '</div><div class="radios" role="radiogroup" aria-label="' + esc(T('scope_label')) + '">' +
					radioLine('set-scope', 'page', sc === 'page', 'page_block', T('scope_page_d')) +
					radioLine('set-scope', 'menu', sc === 'menu', 'menu_only', T('scope_menu_d')) + '</div>';
			}
		}
		h += '<div class="hint">' + esc(T('who_hint')) + '</div>' + hiddenRowHTML(it);
		if (it.kind === 'board') {
			if (!list) h += '<div class="hint">' + esc(T('grants_loading')) + '</div>';
			else {
				var acc = grantBy(list, 'access');
				var sum = acc ? T('grants_sum', grantLabel(grantValue(acc))) : '';
				h += '<div class="grants-wrap">' + collapsible('grants', esc(T('grants_title')), sum, grantsHTML(it, list)) + '</div>';
			}
		}
		return h + '</div>';
	}
	function grantLabel(v) {
		if (v.type === 'groups') return T('badge_groups', v.groups.length);
		return T('grant_opt_' + v.type);
	}
	function grantsHTML(it, list) {
		var locked = grantsLocked(list);
		var linked = accessLinked(it, list);
		var h = locked
			? '<div class="note">' + ic('info', 14) + '<span>' + esc(T('grants_readonly')) + (it.setup_url ? ' <a href="' + esc(it.setup_url) + '" target="_blank" rel="noopener">' + esc(T('grants_open_setup')) + ' ' + ic('ext', 12) + '</a>' : '') + '</span></div>'
			: '<div class="hint top">' + esc(T('grants_hint')) + '</div>';
		h += '<div class="grants">' + list.map(function (g) {
			var draft = UI.grantDraft && UI.grantDraft.id === it.id && UI.grantDraft.key === g.name ? UI.grantDraft : null;
			var v = draft ? { type: 'groups', groups: draft.groups } : grantValue(g);
			var opts = grantOptions(g);
			if (opts.indexOf(v.type) < 0) opts = opts.concat([v.type]);
			var row = '<div class="gr"><span class="gr-t">' + esc(g.title) + (g.name === 'access' && linked ? '<small>' + esc(T('grants_linked')) + '</small>' : '') + '</span>' +
				'<select class="inp sel" data-bind="grant" data-key="' + esc(g.name) + '" aria-label="' + esc(g.title) + '"' + (locked ? ' disabled' : '') + '>' + opts.map(function (o) {
					return '<option value="' + o + '"' + (v.type === o ? ' selected' : '') + '>' + esc(T('grant_opt_' + o)) + '</option>';
				}).join('') + '</select></div>';
			if (v.type === 'groups' && !locked) row += '<div class="gr-g">' + groupChecks(v.groups, 'grant-group', ' data-key="' + esc(g.name) + '"') + '</div>';
			return row;
		}).join('') + '</div>';
		return h;
	}

	function advHTML(it) {
		var body = scopeRowHTML(it) + newWindowRow(it) + '<div class="adv-acts">' +
			'<button type="button" class="btn" data-act="kind-open" data-id="' + it.id + '"' + (it.is_home ? ' disabled title="' + esc(T('err_kind_home')) + '"' : '') + '>' + ic('reload', 14) + esc(T('kind_change')) + '</button>' +
			'<button type="button" class="btn" data-act="copy" data-id="' + it.id + '">' + ic('layers', 14) + esc(T('row_copy')) + '</button></div>' +
			(it.is_home ? '<div class="hint">' + esc(T('err_kind_home')) + '</div>' : '');
		return '<div class="sec adv">' + collapsible('adv', esc(T('adv')), it.open_window === 'Y' ? T('badge_new_window') : '', body) + '</div>';
	}

	/* 노출 */
	function seoOf(it, sm) {
		if (!isModule(it)) return null;
		if (sm === undefined) { var f = find(it.id); sm = f ? f.sitemap : null; }
		var s = it.seo || {};
		var smOff = !!(sm && !sm.unlinked && sm.seo_off);
		var inh = s.seo_own !== 'Y' && smOff;
		var noindex = s.robots_tag === 'noindex';
		var r = {
			index: !(noindex || inh),
			sitemap: s.seo_sitemap === 'N' ? false : s.seo_sitemap === 'Y' ? true : !(noindex || inh),
			sd: s.seo_structured === 'N' ? false : s.seo_structured === 'Y' ? true : !inh,
			own: s.seo_own === 'Y' || noindex || !!s.seo_sitemap || !!s.seo_structured,
			smOff: smOff,
			raw: s
		};
		r.count = (r.index ? 1 : 0) + (r.sitemap ? 1 : 0) + (r.sd ? 1 : 0);
		r.any = r.count > 0;
		return r;
	}
	function seoDefault(so) {
		return { seo_own: '', robots_tag: so.raw.robots_tag ? 'all' : '', seo_sitemap: '', seo_structured: '' };
	}
	function seoTarget(so, on) {
		if (on) return so.smOff ? { seo_own: 'Y', robots_tag: 'all', seo_sitemap: 'Y', seo_structured: 'Y' } : seoDefault(so);
		return so.smOff ? seoDefault(so) : { seo_own: 'Y', robots_tag: 'noindex', seo_sitemap: 'N', seo_structured: 'N' };
	}
	function seoKeyTarget(so, key) {
		var v = { index: so.index, sitemap: so.sitemap, sd: so.sd };
		v[key] = !v[key];
		if (v.index && v.sitemap && v.sd && !so.smOff) return seoDefault(so);
		if (!v.index && !v.sitemap && !v.sd && so.smOff) return seoDefault(so);
		return { seo_own: 'Y', robots_tag: v.index ? 'all' : 'noindex', seo_sitemap: v.sitemap ? 'Y' : 'N', seo_structured: v.sd ? 'Y' : 'N' };
	}
	function sameSeo(raw, values) {
		return Object.keys(values).every(function (k) { return String(raw[k] || '') === String(values[k] || ''); });
	}
	function doSeo(id, values, toastMsg) {
		var f = find(id);
		if (!f || !isModule(f.item)) return;
		var it = f.item;
		if (sameSeo(it.seo || {}, values)) return;
		var prev = null;
		mutate({
			label: T('undo_seo', it.name),
			run: function () { return api('menu.procMenuAdminEditorSaveSeo', { module_srl: it.module_srl, values: JSON.stringify(values) }).then(function (r) { prev = r.previous || {}; return r; }); },
			undo: function () { return api('menu.procMenuAdminEditorSaveSeo', { module_srl: it.module_srl, values: JSON.stringify(prev || {}) }); },
			toast: toastMsg,
			done: function () { flash([id]); }
		});
	}
	function seoToggle(id, on) {
		var f = find(id);
		var so = f && seoOf(f.item);
		if (!so) return;
		if (on === undefined) on = !so.any;
		doSeo(id, seoTarget(so, on), on ? [T('toast_seo_on', f.item.name), T('toast_seo_on_sub')] : [T('toast_seo_off', f.item.name), T('toast_seo_off_sub')]);
	}
	function doBulkSeo(mode) {
		var items = selectedItems().filter(isModule);
		var plan = items.map(function (x) {
			var so = seoOf(x);
			return { id: x.id, srl: x.module_srl, values: mode === 'default' ? seoDefault(so) : seoTarget(so, mode === 'on'), prev: null };
		}).filter(function (p) { var x = find(p.id).item; return !sameSeo(x.seo || {}, p.values); });
		if (!plan.length) return;
		mutate({
			label: T('undo_bulk', plan.length),
			run: function () { return seq(plan, function (p) { return api('menu.procMenuAdminEditorSaveSeo', { module_srl: p.srl, values: JSON.stringify(p.values) }).then(function (r) { p.prev = r.previous || {}; }); }); },
			undo: function () { return seq(plan, function (p) { return api('menu.procMenuAdminEditorSaveSeo', { module_srl: p.srl, values: JSON.stringify(p.prev || {}) }); }); },
			toast: [T(mode === 'on' ? 'toast_bulk_seo_on' : mode === 'off' ? 'toast_bulk_seo_off' : 'toast_bulk_seo_default', plan.length), T('toast_bulk_sub', plan.map(function (p) { return find(p.id).item.name; }).join(', '))],
			done: function () { flash(plan.map(function (p) { return p.id; })); }
		});
	}
	function doSitemapSeo(srl, off) {
		var sm = sitemapBySrl(srl);
		if (!sm || !!sm.seo_off === off) return;
		mutate({
			label: T('undo_sm_seo', sm.title),
			run: function () { return api('menu.procMenuAdminEditorSaveSitemapSeo', { menu_srl: srl, off: off ? 'Y' : 'N' }); },
			undo: function () { return api('menu.procMenuAdminEditorSaveSitemapSeo', { menu_srl: rid(srl), off: off ? 'N' : 'Y' }); },
			toast: off ? [T('toast_sm_seo_off', sm.title), T('toast_sm_seo_off_sub')] : [T('toast_sm_seo_on', sm.title), T('toast_sm_seo_on_sub')]
		});
	}
	function seoSwitch(act, on, label, sub, extra) {
		return '<div class="swrow"><span class="sw-t">' + label + '<small>' + esc(sub) + '</small></span><button type="button" class="switch' + (on ? ' on' : '') + '" role="switch" aria-checked="' + on + '" data-act="' + act + '"' + (extra || '') + '></button></div>';
	}
	function seoHTML(it) {
		var so = seoOf(it);
		if (!so) return '';
		var src = so.own ? T('seo_src_own') : so.smOff ? T('seo_src_sitemap', find(it.id).sitemap.title) : T('seo_src_site');
		var state = so.any ? (so.count < 3 ? T('seo_state_partial') : T('seo_state_on')) : T('seo_state_off');
		var h = '<div class="sec" id="zse-sec-seo"><h3>' + term('seo') + stateChip(so.own) + (so.own ? '<span class="h-r"><button type="button" class="linkbtn" data-act="seo-reset">' + esc(T('reset_default')) + '</button></span>' : '') + '</h3>' +
			seoSwitch('seo-toggle', so.any, esc(T('seo_main')), state + ' ' + src, ' data-id="' + it.id + '" aria-label="' + esc(T('seo_main')) + '"');
		var w = whoOf(it);
		if (w.type !== 'public' && so.any) h += '<div class="note warn">' + ic('info', 14) + '<span>' + esc(T('seo_who_warn', whoLabel(w))) + ' <button type="button" class="linkbtn" data-act="seo-toggle" data-id="' + it.id + '">' + esc(T('seo_who_off')) + '</button></span></div>';
		var body = [['index', 'seo_index', 'seo_index_d'], ['sitemap', 'seo_sitemap', 'seo_sitemap_d'], ['sd', 'seo_sd', 'seo_sd_d']].map(function (k) {
			return seoSwitch('seo-key', so[k[0]], term(k[1]), T(k[2]), ' data-v="' + k[0] + '" aria-label="' + esc(T('term_' + k[1] + '_t')) + '"');
		}).join('') + '<div class="hint">' + esc(T('seo_llms_hint')) + '</div>';
		h += '<div class="seo-detail">' + collapsible('seo-detail', esc(T('seo_detail')), T('seo_count', so.count), body) + '</div>';
		return h + '</div>';
	}
	function hiddenRowHTML(it) {
		if (!ST.hidden_ready) return '';
		var on = it.is_hidden === 'Y';
		return '<div class="swrow hidden-row"><span class="sw-t">' + term('hidden') + '<small>' + esc(T(on ? 'hidden_on' : 'hidden_off')) + '</small></span><button type="button" class="switch' + (on ? ' on' : '') + '" role="switch" aria-checked="' + on + '" aria-label="' + esc(T('term_hidden_t')) + '" data-act="hidden-toggle" data-id="' + it.id + '"></button></div>';
	}
	function doHidden(id) {
		var f = find(id);
		if (!f || !ST.hidden_ready) return;
		var it = f.item;
		var now = it.is_hidden === 'Y' ? 'N' : 'Y';
		mutate({
			label: T('undo_hidden', it.name),
			local: function () { it.is_hidden = now; },
			run: function () { return api('menu.procMenuAdminEditorSaveItem', { menu_item_srl: id, is_hidden: now }); },
			undo: function () { return api('menu.procMenuAdminEditorSaveItem', { menu_item_srl: rid(id), is_hidden: now === 'Y' ? 'N' : 'Y' }); },
			toast: now === 'Y' ? [T('toast_hidden', it.name), T('toast_hidden_sub')] : [T('toast_unhidden', it.name), ''],
			done: function () { flash([id]); }
		});
	}
	function scopeRowHTML(it) {
		if (ST.sites.length < 2 || !isModule(it)) return '';
		var on = it.domain_srl === ST.site.domain_srl;
		var sub = on ? T('scope_on_d', ST.site.domain) : (it.domain_srl === -1 ? T('scope_off_d') : T('scope_other_d', siteTitle(it.domain_srl)));
		return '<div class="swrow"><span class="sw-t">' + term('scope') + '<small>' + esc(sub) + '</small></span><button type="button" class="switch' + (on ? ' on' : '') + '" role="switch" aria-checked="' + on + '" aria-label="' + esc(T('term_scope_t')) + '" data-act="scope-toggle" data-id="' + it.id + '"></button></div>';
	}
	function siteTitle(domain_srl) {
		for (var i = 0; i < ST.sites.length; i++) if (ST.sites[i].domain_srl === domain_srl) return ST.sites[i].title;
		return '';
	}
	function doModuleSite(id, domain_srl) {
		var f = find(id);
		if (!f || !isModule(f.item)) return;
		var it = f.item, old = it.domain_srl;
		if (old === domain_srl) return;
		mutate({
			label: T('undo_scope', it.name),
			run: function () { return api('menu.procMenuAdminEditorSetModuleSite', { module_srl: it.module_srl, domain_srl: domain_srl }); },
			undo: function () { return api('menu.procMenuAdminEditorSetModuleSite', { module_srl: it.module_srl, domain_srl: old }); },
			toast: domain_srl === -1 ? [T('toast_scope_off', it.name), ''] : [T('toast_scope_on', it.name, ST.site.domain), ''],
			done: function () { flash([id]); }
		});
	}

	/* 사이트 테마 */
	function themeName() {
		var t = ST.site_theme && ST.site_theme.theme;
		if (t) return t.title;
		var l = layoutBySrl(ST.site_default_layout.P, 'P');
		return l ? l.title : T('layout_none');
	}
	var THEMES = null;
	function loadThemes() {
		if (THEMES) return Promise.resolve(THEMES);
		return api('menu.getMenuAdminEditorThemes', {}).then(function (r) { THEMES = r.themes || []; return THEMES; });
	}
	function themeThumb(t, tag) {
		if (t.thumbnail) return '<span class="thumb img"><img src="' + esc(t.thumbnail) + '" alt="" loading="lazy" decoding="async">' + (tag || '') + '</span>';
		return '<span class="thumb ph">' + ic('palette', 20) + (tag || '') + '</span>';
	}
	function siteCounts() {
		var follow = 0, own = [];
		itemsOfSite().forEach(function (x) {
			if (!isModule(x)) return;
			var d = designOf(x);
			if (d.any) own.push(x); else follow++;
		});
		return { follow: follow, own: own };
	}
	function panelTheme() {
		var site = ST.site;
		var cur = ST.site_theme && ST.site_theme.theme ? ST.site_theme.theme.name : '';
		var cand = UI.themeCand || '';
		var h = '<div class="ph"><button type="button" class="btn ghost back" data-act="theme-cancel">' + ic('back', 16) + esc(T('close')) + '</button><div class="ph-k">' + ic('palette', 14) + term('theme', T('site_theme')) + '</div><h2>' + esc(T('theme_change')) + '</h2><div class="ph-sub">' + esc(T(ST.sites.length > 1 ? 'theme_scope' : 'theme_scope_one', site.title, site.domain)) + '</div></div><div class="pbody">';
		if (!THEMES) {
			loadThemes().then(function () { renderPanel(true); }).catch(function () { THEMES = []; renderPanel(true); });
			h += '<div class="sec"><div class="hint">' + esc(T('grants_loading')) + '</div></div>';
		} else if (!THEMES.length) {
			h += '<div class="sec"><div class="note">' + ic('info', 14) + '<span>' + esc(T('theme_none')) + '</span></div></div>';
		} else {
			h += '<div class="sec"><div class="cards theme">' + THEMES.map(function (t) {
				var on = cand === t.name;
				return '<button type="button" class="dcard' + (on ? ' on' : '') + '" data-act="theme-cand" data-v="' + esc(t.name) + '" aria-pressed="' + on + '">' + themeThumb(t, t.name === cur ? '<span class="cur-tag">' + esc(T('theme_now')) + '</span>' : '') + '<span class="dc-b"><span class="dc-t">' + esc(t.title) + '</span><span class="dc-s">' + esc(T('theme_skins', t.skin_count)) + '</span></span><span class="ck">' + ic('check', 12, 3) + '</span></button>';
			}).join('') + '</div></div>';
			if (cand) {
				var ct = siteCounts();
				var also = !!UI.themeAlsoOwn;
				var candTitle = '';
				THEMES.forEach(function (t) { if (t.name === cand) candTitle = t.title; });
				h += '<div class="sec"><h3>' + esc(T('theme_impact')) + '</h3><div class="impact"><div><b>' + (ct.follow + (also ? ct.own.length : 0)) + '</b><span>' + esc(T('theme_impact_change', candTitle)) + '</span></div><div><b>' + (also ? 0 : ct.own.length) + '</b><span>' + esc(T('theme_impact_keep')) + '</span></div></div>' +
					(ct.own.length ? '<button type="button" class="checkline" role="checkbox" aria-checked="' + also + '" data-act="theme-also-own"><span class="cbox' + (also ? ' on' : '') + '">' + (also ? ic('check', 12, 3) : '') + '</span><span>' + esc(T('theme_also_own', ct.own.length)) + '<small>' + esc(namesShort(ct.own.map(function (x) { return x.name; }), 5)) + '</small></span></button>' : '') +
					'<div class="note">' + ic('info', 14) + '<span>' + esc(T(site.is_default ? 'theme_note_default' : 'theme_note_site')) + '</span></div></div>';
			}
		}
		h += '</div><div class="pfoot"><span class="legacy"><a href="' + esc(B.urls.themes) + '" target="_blank" rel="noopener">' + esc(T('legacy_themes')) + ' ' + ic('ext', 12) + '</a></span><span class="pf-r"><button type="button" class="btn" data-act="theme-cancel">' + esc(T('kc_cancel')) + '</button><button type="button" class="btn primary" data-act="theme-apply"' + (cand && cand !== '' ? '' : ' disabled') + '>' + esc(cand ? T('theme_apply', themeTitle(cand)) : T('theme_pick')) + '</button></span></div>';
		return h;
	}
	function themeTitle(name) {
		var r = name;
		(THEMES || []).forEach(function (t) { if (t.name === name) r = t.title; });
		return r;
	}
	function applyTheme() {
		var name = UI.themeCand;
		if (!name) return;
		var site = ST.site, also = !!UI.themeAlsoOwn;
		var ct = siteCounts();
		var res = null;
		var title = themeTitle(name);
		UI.focus = { type: 'site' };
		UI.themeCand = '';
		var before = itemsOfSite().filter(function (x) { return isModule(x) && (also || !designOf(x).any); }).map(function (x) { return x.id; });
		var op = {
			label: T('undo_theme', site.title),
			run: function () {
				return api('menu.procMenuAdminEditorApplyTheme', { domain_srl: site.domain_srl, theme_name: name, include_own: also ? 'Y' : 'N' }).then(function (r) {
					res = r;
					var keep = also ? [] : ct.own.map(function (x) { return x.name; });
					var changed = ct.follow + (also ? ct.own.length : 0);
					op.toast = [T('toast_theme', site.title, title), keep.length ? T('toast_theme_sub_keep', changed, namesShort(keep)) : T('toast_theme_sub', changed)];
					return r;
				});
			},
			undo: function () { return api('menu.procMenuAdminEditorRevertTheme', { token: res ? res.token : '' }); },
			done: function () { UI.themeAlsoOwn = false; flash(before); }
		};
		mutate(op);
	}

	function namesShort(list, n) {
		n = n || 3;
		return list.length > n ? T('names_more', list.slice(0, n).join('·'), list.length - n) : list.join('·');
	}
	function themeSecHTML() {
		var ct = siteCounts();
		var l = layoutBySrl(ST.site_default_layout.P, 'P');
		var own = ct.own.map(function (x) { return x.name; });
		var note = (ct.follow + own.length) ? T('theme_site_note', ct.follow + own.length, ct.follow) + (own.length ? ' ' + T('theme_site_note_own', namesShort(own)) : '') : '';
		var shared = !ST.site.is_default && ST.site_theme && !ST.site_theme.own;
		return '<div class="sec" id="zse-sec-theme"><h3>' + term('theme', T('site_theme')) + '<span class="h-r"><button type="button" class="btn primary" data-act="open-theme">' + esc(T('theme_change')) + '</button></span></h3>' +
			'<div class="designcard"><span class="dc-ico">' + ic('palette', 22) + '</span><span><span class="dc-v">' + esc(themeName()) + '</span><span class="dc-n">' + term('layout') + ' · ' + esc(l ? l.title : T('layout_none')) + '</span></span></div>' +
			(shared ? '<div class="hint">' + ic('info', 13) + esc(T('theme_shared')) + '</div>' : '') +
			(note ? '<div class="note">' + ic('info', 14) + '<span>' + esc(note) + '</span></div>' : '') + '</div>';
	}

	/* 사이트 한눈에 보기 */
	var OV = null;
	function loadOverview() {
		return api('menu.getMenuAdminEditorOverview', {}).then(function (r) { OV = r.columns || []; });
	}
	function ovFind(id) {
		var out = null;
		(OV || []).forEach(function (col) {
			col.sitemaps.forEach(function (sm) {
				(function walk(list, parent) {
					list.forEach(function (x) {
						if (x.id === id) out = { item: x, col: col, sitemap: sm, parent: parent, list: list };
						walk(x.children || [], x);
					});
				})(sm.items, null);
			});
		});
		return out;
	}
	function ovRow(it, depth, col, sm) {
		var so = seoOf(it, sm);
		var marks = [];
		if (so && !so.any) marks.push('<span class="bdg seo">' + ic('searchoff', 12) + esc(T('badge_seo_off')) + '</span>');
		if (it.is_home) marks.push('<span class="bdg home">' + esc(T('badge_home')) + '</span>');
		if (it.is_hidden === 'Y') marks.push('<span class="bdg vis">' + ic('eyeoff', 12) + esc(T('badge_hidden')) + '</span>');
		var canCopy = isModule(it) && (OV || []).length > 1;
		var h = '<div class="row ov-row" style="--lv:' + depth + '" title="' + esc(it.name + (it.mid ? ' · mid ' + it.mid : '')) + '"><span class="grip"></span><span class="tw"></span><span class="ki">' + ic(it.is_home ? 'home' : kindIcon(it.kind), 16) + '</span><span class="nm">' + esc(it.name) + '</span><span class="marks">' + marks.slice(0, 2).join('') + '</span>' +
			(canCopy ? '<span class="acts"><button type="button" class="iconbtn" data-act="ov-row" data-id="' + it.id + '" data-anchor="ov-' + it.id + '" title="' + esc(T('ov_copy_title')) + '" aria-label="' + esc(T('ov_copy_title')) + '">' + ic('copy', 15) + '</button></span>' : '') + '</div>';
		return h + (it.children || []).map(function (c) { return ovRow(c, depth + 1, col, sm); }).join('');
	}
	function ovStats(col) {
		var total = 0, own = 0;
		col.sitemaps.forEach(function (sm) {
			(function walk(list) { list.forEach(function (x) { total++; if (isModule(x) && (x.layout_srl !== -1 || x.is_skin_fix === 'Y')) own++; walk(x.children || []); }); })(sm.items);
		});
		return '<span>' + esc(T('ov_count', total)) + '</span><span>' + esc(T('ov_own', own)) + '</span><span>' + esc(col.options.prefix ? T('ov_prefix', col.options.prefix) : T('ov_prefix_none')) + '</span>';
	}
	function renderOverview() {
		var ov = ROOT.querySelector('.zse-ov');
		if (!OV) {
			ov.innerHTML = '<div class="ov-hint">' + esc(T('grants_loading')) + '</div>';
			loadOverview().then(function () { if (UI.view === 'overview') renderOverview(); }).catch(function () { OV = []; renderOverview(); });
			return;
		}
		var keep = {};
		ov.querySelectorAll('.ov-body').forEach(function (b) { keep[b.getAttribute('data-d')] = b.scrollTop; });
		ov.innerHTML = '<div class="ov-hint"><b>' + esc(T('view_overview')) + '</b><span>' + esc(T('ov_hint', OV.length)) + '</span>' + (OV.length > 1 ? '<span>' + ic('copy', 14) + ' ' + esc(T('ov_hint_copy')) + '</span>' : '') + '<button type="button" class="btn" data-act="view" data-v="edit">' + ic('edit', 14) + esc(T('ov_back')) + '</button></div>' +
			'<div class="ov-cols">' + OV.map(function (col, i) {
				var t = col.site_theme && col.site_theme.theme ? col.site_theme.theme.title : '';
				return '<section class="ov-col" aria-label="' + esc(col.site.title) + '"><div class="ov-head"><span class="fav sm" style="background:' + favColor(i) + '">' + esc(initial(col.site.title)) + '</span><div class="oh-t"><div class="oh-name">' + esc(col.site.title) + '</div><div class="oh-dom">' + esc(col.site.domain) + '</div></div><button type="button" class="ov-design" data-act="ov-open" data-v="' + col.site.domain_srl + '" title="' + esc(T('ov_open')) + '">' + (t ? '<span>' + esc(t) + '</span>' : '') + ic('chevr', 14) + '</button></div>' +
					'<div class="ov-body" data-d="' + col.site.domain_srl + '">' + col.sitemaps.map(function (sm) {
						return '<div class="grp"><div class="grphead"><span class="tw"></span><span class="gname">' + esc(sm.title) + '</span>' + slotBadge(sm) + '</div>' + sm.items.map(function (it) { return ovRow(it, 0, col, sm); }).join('') + '</div>';
					}).join('') + (col.unlinked_count ? '<div class="ov-unl">' + ic('inbox', 14) + term('unlinked') + ' <b>' + col.unlinked_count + '</b></div>' : '') + '</div>' +
					'<div class="ov-stats">' + ovStats(col) + '</div></section>';
			}).join('') + '<button type="button" class="ov-add" data-act="wizard">' + ic('plus', 22) + '<span>' + esc(T('wz_title')) + '</span></button></div>';
		ov.querySelectorAll('.ov-body').forEach(function (b) { var k = b.getAttribute('data-d'); if (keep[k]) b.scrollTop = keep[k]; });
	}
	function ovSiteBySrl(srl) {
		for (var i = 0; i < (OV || []).length; i++) if (OV[i].site.domain_srl === srl) return OV[i];
		return null;
	}
	function ovPopHTML(p) {
		var f = ovFind(p.id);
		if (!f) return '';
		var others = OV.filter(function (c) { return c.site.domain_srl !== f.col.site.domain_srl && c.sitemaps.length; });
		var base = f.item.mid;
		var pre = f.col.options.prefix;
		if (pre && base.indexOf(pre) === 0 && base.length > pre.length) base = base.slice(pre.length);
		return '<div class="pop menu" id="zse-pop" role="menu" style="width:320px"><div class="mh">' + esc(T('ov_pop_title', f.item.name)) + '</div>' +
			others.map(function (c) { return '<button type="button" class="opt" role="menuitem" data-act="ov-copy" data-id="' + f.item.id + '" data-v="' + c.site.domain_srl + '">' + ic('copy', 15) + esc(T('ov_copy_to', c.site.title)) + '<small>' + esc(uniqueMid((c.options.prefix || '') + base)) + '</small></button>'; }).join('') +
			'<div class="sep"></div>' +
			others.map(function (c) { return '<button type="button" class="opt" role="menuitem" data-act="ov-move" data-id="' + f.item.id + '" data-v="' + c.site.domain_srl + '">' + ic('move', 15) + esc(T('ov_move_to', c.site.title)) + '</button>'; }).join('') +
			'<div class="sep"></div><div class="hint pad">' + esc(T('ov_pop_hint')) + '</div></div>';
	}
	function uniqueMid(base) {
		base = String(base).toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 36);
		var used = allMids();
		if (!used[base]) return base;
		for (var i = 2; i < 1000; i++) if (!used[base + i]) return base + i;
		return base;
	}
	function doCopyToSite(id, domain_srl) {
		var f = ovFind(id), to = ovSiteBySrl(domain_srl);
		if (!f || !to || !to.sitemaps.length) return;
		var res = null;
		var op = {
			label: T('undo_copy', f.item.name),
			run: function () {
				return api('menu.procMenuAdminEditorCopyToSite', { menu_item_srl: id, domain_srl: domain_srl, from_domain_srl: f.col.site.domain_srl, menu_srl: to.sitemaps[0].menu_srl }).then(function (r) {
					res = r;
					op.toast = [T('toast_ov_copied', f.item.name, to.site.title), T('toast_ov_copied_sub', r.mid)];
					return r;
				});
			},
			undo: function () { return api('menu.procMenuAdminEditorRemoveCopy', { menu_item_srl: res.menu_item_srl, module_srl: res.module_srl }); }
		};
		mutate(op);
	}
	function doMoveToSite(id, domain_srl) {
		var f = ovFind(id), to = ovSiteBySrl(domain_srl);
		if (!f || !to || !to.sitemaps.length) return;
		var it = f.item, from = f.col.site.domain_srl;
		var oldParent = f.parent ? f.parent.id : f.sitemap.menu_srl;
		var i = f.list.indexOf(it), oldSource = i > 0 ? f.list[i - 1].id : 0;
		var top = to.sitemaps[0].items;
		var source = top.length ? top[top.length - 1].id : 0;
		var changeSite = isModule(it) && it.domain_srl === from;
		mutate({
			label: T('undo_move', it.name),
			run: function () {
				return api('menu.procMenuAdminMoveItem', moveParams(id, to.sitemaps[0].menu_srl, source)).then(function () {
					if (changeSite) return api('menu.procMenuAdminEditorSetModuleSite', { module_srl: it.module_srl, domain_srl: domain_srl });
				});
			},
			undo: function () {
				return api('menu.procMenuAdminMoveItem', moveParams(id, oldParent, oldSource)).then(function () {
					if (changeSite) return api('menu.procMenuAdminEditorSetModuleSite', { module_srl: it.module_srl, domain_srl: from });
				});
			},
			toast: [T('toast_ov_moved', it.name, to.site.title), changeSite ? T('toast_ov_moved_sub', to.site.domain) : '']
		});
	}

	/* 새 사이트 마법사 */
	var STARTERS = {
		company: { main: [['home', 'widget', 'home', 0], ['about', 'folder', '', 0], ['greeting', 'article', 'greeting', 1], ['location', 'article', 'location', 1], ['business', 'article', 'business', 0], ['pr', 'folder', '', 0], ['notice', 'board', 'notice', 1], ['press', 'board', 'press', 1], ['contact', 'article', 'contact', 0]], footer: [['privacy', 'article', 'privacy', 0]] },
		community: { main: [['home', 'widget', 'home', 0], ['notice', 'board', 'notice', 0], ['free', 'board', 'free', 0], ['qna', 'board', 'qna', 0], ['gallery', 'board', 'gallery', 0]], footer: [['terms', 'article', 'terms', 0]] },
		blank: { main: [['home', 'widget', 'home', 0]], footer: [] }
	};
	function starterCount(k) { return STARTERS[k].main.length + STARTERS[k].footer.length; }
	function starterModules(k) { return STARTERS[k].main.concat(STARTERS[k].footer).filter(function (x) { return x[1] !== 'folder'; }).length; }
	function prefixFromDomain(domain) {
		var first = String(domain || '').toLowerCase().replace(/^https?:\/\//, '').split('.')[0].replace(/[^a-z0-9]/g, '');
		if (!/^[a-z]/.test(first)) return '';
		return first.slice(0, 19) + '_';
	}
	function wizardHTML() {
		var w = UI.wz;
		if (!w) return '';
		var steps = [['wz_s1', w.domain || T('wz_s1_d')], ['wz_s2', w.theme ? themeTitle(w.theme) : T('wz_theme_same')], ['wz_s3', T('wz_set_' + w.starter)], ['wz_s4', T('wz_s4_d')]];
		var side = '<div class="wz-side"><h2>' + esc(T('wz_title')) + '</h2>' + steps.map(function (s, i) {
			var n = i + 1;
			return '<button type="button" class="wz-step' + (w.step === n ? ' on' : w.step > n ? ' done' : '') + '" data-act="wz-go" data-v="' + n + '"' + (n > w.step && !wzValid(1) ? ' disabled' : '') + '><i>' + (w.step > n ? ic('check', 13, 3) : n) + '</i><span>' + esc(T(s[0])) + '<small>' + esc(s[1]) + '</small></span></button>';
		}).join('') + '<div class="wz-foot">' + esc(T('wz_side_foot')) + '</div></div>';
		var body = '';
		var pre = w.prefix;
		if (w.step === 1) {
			var de = wzDomainError();
			body = '<h3>' + esc(T('wz_s1')) + '</h3><p class="lead">' + esc(T('wz_s1_lead')) + '</p>' +
				'<div class="field"><label for="zse-wz-domain">' + esc(T('wz_domain')) + '</label><input class="inp" id="zse-wz-domain" data-wz="domain" value="' + esc(w.domain) + '" placeholder="brand.example.com" spellcheck="false" autocomplete="off"><div class="hint' + (w.touched && de ? ' err' : '') + '">' + esc(w.touched && de ? T(de) : T('wz_domain_hint')) + '</div></div>' +
				'<div class="field"><label for="zse-wz-name">' + esc(T('wz_name')) + '</label><input class="inp" id="zse-wz-name" data-wz="name" value="' + esc(w.name) + '" autocomplete="off">' + (w.touched && !w.name.trim() ? '<div class="hint err">' + esc(T('wz_name_needed')) + '</div>' : '') + '</div>' +
				'<div class="field"><label for="zse-wz-prefix">' + term('prefix', T('prefix_label')) + ' <span class="midtag sm"><i>mid</i></span></label><input class="inp narrow" id="zse-wz-prefix" data-wz="prefix" value="' + esc(pre) + '" spellcheck="false" autocomplete="off"><div class="hint' + (wzPrefixBad() ? ' err' : '') + '">' + esc(wzPrefixBad() ? T('prefix_format') : T('wz_prefix_hint', pre + 'notice', (w.domain || 'brand.example.com') + '/' + pre + 'notice')) + '</div></div>' +
				'<div class="swrow"><span class="sw-t">' + esc(T('wz_scope')) + '<small>' + esc(T('wz_scope_d')) + '</small></span><button type="button" class="switch' + (w.scope ? ' on' : '') + '" role="switch" aria-checked="' + w.scope + '" aria-label="' + esc(T('wz_scope')) + '" data-act="wz-scope"></button></div>';
		} else if (w.step === 2) {
			if (!THEMES) loadThemes().then(function () { renderLayer(); }).catch(function () { THEMES = []; renderLayer(); });
			body = '<h3>' + esc(T('wz_s2_title')) + '</h3><p class="lead">' + esc(T('wz_s2_lead')) + '</p><div class="cards wz-themes">' +
				'<button type="button" class="dcard' + (!w.theme ? ' on' : '') + '" data-act="wz-theme" data-v="" aria-pressed="' + !w.theme + '"><span class="thumb ph">' + ic('layers', 20) + '</span><span class="dc-b"><span class="dc-t">' + esc(T('wz_theme_same')) + '</span><span class="dc-s">' + esc(T('wz_theme_same_d')) + '</span></span><span class="ck">' + ic('check', 12, 3) + '</span></button>' +
				(THEMES || []).map(function (t) {
					return '<button type="button" class="dcard' + (w.theme === t.name ? ' on' : '') + '" data-act="wz-theme" data-v="' + esc(t.name) + '" aria-pressed="' + (w.theme === t.name) + '">' + themeThumb(t) + '<span class="dc-b"><span class="dc-t">' + esc(t.title) + '</span><span class="dc-s">' + esc(T('theme_skins', t.skin_count)) + '</span></span><span class="ck">' + ic('check', 12, 3) + '</span></button>';
				}).join('') + '</div>' + (THEMES ? '' : '<div class="hint">' + esc(T('grants_loading')) + '</div>');
		} else if (w.step === 3) {
			var st = STARTERS[w.starter];
			var tree = '';
			[['main', 'wz_sm_main'], ['footer', 'wz_sm_footer']].forEach(function (g) {
				if (!st[g[0]].length) return;
				tree += '<div class="mt-g">' + esc(T(g[1])) + '</div>' + st[g[0]].map(function (x) {
					return '<div class="mt-i" style="--lv:' + x[3] + '">' + ic(x[0] === 'home' ? 'home' : kindIcon(x[1]), 14) + '<span>' + esc(T('st_' + x[0])) + '</span>' + (x[2] ? '<code>' + esc(pre + x[2]) + '</code>' : '') + '</div>';
				}).join('');
			});
			body = '<h3>' + esc(T('wz_s3')) + '</h3><p class="lead">' + esc(pre ? T('wz_s3_lead', pre) : T('wz_s3_lead_none')) + '</p><div class="wz-split"><div class="starter">' +
				['company', 'community', 'blank'].map(function (k) {
					return '<button type="button" class="dcard' + (w.starter === k ? ' on' : '') + '" data-act="wz-starter" data-v="' + k + '" aria-pressed="' + (w.starter === k) + '"><span class="dc-b"><span class="dc-t">' + esc(T('wz_set_' + k)) + '</span><span class="dc-s">' + esc(T('wz_set_' + k + '_d')) + '</span><span class="dc-s">' + esc(T('wz_set_count', starterCount(k))) + '</span></span><span class="ck">' + ic('check', 12, 3) + '</span></button>';
				}).join('') + '</div><div><div class="flabel">' + esc(T('wz_tree')) + '</div><div class="mini-tree">' + tree + '</div></div></div>';
		} else {
			var st2 = STARTERS[w.starter];
			var sms = st2.footer.length ? 2 : 1;
			body = '<h3>' + esc(T('wz_s4')) + '</h3><p class="lead">' + esc(T('wz_s4_lead')) + '</p><div class="summary">' +
				'<div class="li"><span class="ico">' + ic('globe', 16) + '</span><div>' + esc(w.name + ' · ' + w.domain) + '<small>' + esc(T('wz_sum_domain')) + '</small></div></div>' +
				'<div class="li"><span class="ico">' + ic('folder', 16) + '</span><div>' + esc(T('wz_sum_sm', sms)) + '<small>' + esc(w.theme ? T('wz_sum_sm_slot') : T('wz_sum_sm_noslot')) + '</small></div></div>' +
				'<div class="li"><span class="ico">' + ic('board', 16) + '</span><div>' + esc(T('wz_sum_mod', starterModules(w.starter))) + '<small>' + esc((pre ? T('wz_sum_mod_pre', pre) + ' ' : '') + T(w.scope ? 'wz_sum_scope_on' : 'wz_sum_scope_off')) + '</small></div></div>' +
				'<div class="li"><span class="ico">' + ic('palette', 16) + '</span><div>' + esc(w.theme ? T('wz_sum_theme', themeTitle(w.theme)) : T('wz_theme_same')) + '<small>' + esc(T(w.theme ? 'wz_sum_theme_d' : 'wz_sum_theme_same_d')) + '</small></div></div></div>' +
				(w.error ? '<div class="note warn">' + ic('info', 14) + '<span>' + esc(w.error) + '</span></div>' : '');
		}
		var foot = '<div class="wz-foot2"><button type="button" class="btn" data-act="wz-close">' + esc(T('kc_cancel')) + '</button><span class="spacer"></span>' +
			(w.step > 1 ? '<button type="button" class="btn lg" data-act="wz-go" data-v="' + (w.step - 1) + '">' + esc(T('wz_prev')) + '</button>' : '') +
			(w.step < 4 ? '<button type="button" class="btn primary lg" data-act="wz-go" data-v="' + (w.step + 1) + '">' + esc(T('tour_next')) + '</button>' : '<button type="button" class="btn primary lg" data-act="wz-create"' + (w.busy ? ' disabled' : '') + '>' + esc(w.busy ? T('saving') : T('wz_create')) + '</button>') + '</div>';
		return '<div class="modal-back"><div class="wizard" role="dialog" aria-modal="true" aria-label="' + esc(T('wz_title')) + '">' + side + '<div class="wz-main"><div class="wz-body">' + body + '</div>' + foot + '</div></div></div>';
	}
	function wzDomainError() {
		var d = String(UI.wz.domain || '').trim().toLowerCase().replace(/^https?:\/\//, '').replace(/\/.*$/, '');
		if (!d) return 'wz_domain_needed';
		if (!/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+(:\d+)?$/.test(d)) return 'wz_domain_format';
		for (var i = 0; i < ST.sites.length; i++) if (String(ST.sites[i].domain).toLowerCase() === d) return 'wz_domain_taken';
		return '';
	}
	function wzPrefixBad() { var p = UI.wz.prefix; return p !== '' && !/^[a-z][a-z0-9_]{0,19}$/.test(p); }
	function wzValid(step) {
		var w = UI.wz;
		if (step >= 1 && (wzDomainError() || !w.name.trim() || wzPrefixBad())) return false;
		return true;
	}
	function wzGo(n) {
		var w = UI.wz;
		if (n > w.step && !wzValid(w.step)) { w.touched = true; renderLayer(); return; }
		w.step = Math.max(1, Math.min(4, n));
		w.error = '';
		renderLayer();
	}
	function createSite() {
		var w = UI.wz;
		if (!w || w.busy || !wzValid(1)) return;
		w.busy = true; w.error = '';
		renderLayer();
		var domain = String(w.domain).trim().toLowerCase().replace(/^https?:\/\//, '').replace(/\/.*$/, '');
		var defSite = null;
		ST.sites.forEach(function (s) { if (s.is_default) defSite = s; });
		var params = { prefix: w.prefix, scope: w.scope ? 'site' : 'all', starter: w.starter };
		var home = 0, created = null, domainMade = false;
		UI.pending++;
		renderSave();
		var p = QUEUE.then(function () {
			return api('menu.procMenuAdminEditorCreateSite', Object.assign({ phase: 'prepare' }, params)).then(function (r) {
				home = parseInt(r.module_srl, 10) || 0;
				return api('admin.procAdminInsertDomain', { domain: domain, title: w.name.trim(), subtitle: '', index_module_srl: home, index_document_srl: 0, default_lang: 'default', default_timezone: 'default', color_scheme: 'auto', domain_security: defSite ? defSite.security || 'none' : 'none', http_port: '', https_port: '', meta_keywords: '', meta_description: '', html_header: '', html_footer: '', copy_domain_srl: -1 });
			}).then(function () {
				domainMade = true;
				return api('menu.procMenuAdminEditorCreateSite', Object.assign({ phase: 'build', domain: domain, home_module_srl: home, theme_name: w.theme }, params));
			}).then(function (r) {
				created = r.created;
				return api('menu.getMenuAdminEditorState', { domain_srl: created.domain_srl });
			}).then(function (d) {
				var name = w.name.trim();
				UI.wz = null;
				saveUndoStack();
				setState(d.state);
				loadUndoStack();
				UI.focus = { type: 'site' }; UI.view = 'edit'; OV = null; lastFrameUrl = '';
				UNDO.push({ label: T('undo_site', name), run: function () { return removeSite(created, home); } });
				toast(T('toast_site', name), T('toast_site_sub'), true);
			}).catch(function (err) {
				var msg = (err && err.message) ? String(err.message) : T('error_generic');
				var clean = created ? removeSite(created, home) : (domainMade ? Promise.resolve() : (home ? api('menu.procMenuAdminEditorRemoveSiteHome', { module_srl: home }).catch(function () { }) : Promise.resolve()));
				return clean.catch(function () { }).then(function () {
					if (UI.wz) { UI.wz.busy = false; UI.wz.error = msg; }
				});
			});
		}).then(function () {
			UI.pending--;
			renderSave();
			render();
		});
		QUEUE = p.catch(function () { });
	}
	function removeSite(created, home) {
		return api('menu.procMenuAdminEditorRemoveSite', { created: JSON.stringify(created) }).then(function () {
			return api('admin.procAdminDeleteDomain', { domain_srl: created.domain_srl });
		}).then(function () {
			return api('menu.procMenuAdminEditorRemoveSiteHome', { module_srl: home });
		}).then(function () {
			if (ST.site.domain_srl === created.domain_srl) {
				var def = null;
				ST.sites.forEach(function (s) { if (s.is_default) def = s; });
				return api('menu.getMenuAdminEditorState', { domain_srl: def ? def.domain_srl : 0 }).then(function (d) { setState(d.state); lastFrameUrl = ''; UI.focus = { type: 'site' }; });
			}
		});
	}

	/* 사이트마다 실행 취소 기록 */
	var UNDO_BY = {};
	function saveUndoStack() { UNDO_BY[ST.site.domain_srl] = UNDO; }
	function loadUndoStack() { UNDO = UNDO_BY[ST.site.domain_srl] || []; }

	/* 사이트 설정 · 고급 */
	function siteOptionsHTML() {
		var o = ST.options || { prefix: '', scope: 'all' };
		var draft = UI.prefixDraft !== undefined && UI.prefixDraft !== null ? UI.prefixDraft : o.prefix;
		var body = '<div class="field"><label for="zse-f-prefix">' + term('prefix', T('prefix_label')) + ' <span class="midtag sm"><i>mid</i></span></label><input class="inp" id="zse-f-prefix" data-bind="prefix" value="' + esc(draft) + '" placeholder="' + esc(T('prefix_none')) + '" spellcheck="false" autocomplete="off"><div class="hint' + (UI.prefixErr ? ' err' : '') + '">' + esc(UI.prefixErr ? T('prefix_format') : T('prefix_hint', (draft || '') + 'notice', String(ST.site.base_url || '').replace(/^https?:\/\//, '').replace(/\/$/, '') + '/' + (draft || '') + 'notice')) + '</div></div>';
		if (ST.sites.length > 1) body += '<div class="swrow"><span class="sw-t">' + esc(T('scope_new')) + '<small>' + esc(T(o.scope === 'site' ? 'scope_new_on' : 'scope_new_off')) + '</small></span><button type="button" class="switch' + (o.scope === 'site' ? ' on' : '') + '" role="switch" aria-checked="' + (o.scope === 'site') + '" aria-label="' + esc(T('scope_new')) + '" data-act="scope-new"></button></div>';
		var sum = o.prefix ? T('prefix_sum', o.prefix) : T('prefix_sum_none');
		var h = '<div class="sec">' + collapsible('site-adv', esc(T('adv')), sum, body) + '</div>';
		if (ST.sites.length > 1) {
			var on = !!(ST.options && ST.options.sitemap_per_domain);
			h += '<div class="sec"><h3>' + term('sitemap_split', T('sitemap_split')) + '</h3><div class="swrow"><span class="sw-t">' + esc(T('sitemap_split_label')) + '<small>' + esc(T(on ? 'sitemap_split_on' : 'sitemap_split_off')) + '</small></span><button type="button" class="switch' + (on ? ' on' : '') + '" role="switch" aria-checked="' + on + '" aria-label="' + esc(T('sitemap_split_label')) + '" data-act="sitemap-split"></button></div></div>';
		}
		return h;
	}
	function savePrefix(value) {
		var o = ST.options || { prefix: '', scope: 'all' };
		value = String(value || '').trim().toLowerCase();
		UI.prefixDraft = value;
		if (value !== '' && !/^[a-z][a-z0-9_]{0,19}$/.test(value)) { UI.prefixErr = true; renderPanel(true); return; }
		UI.prefixErr = false;
		UI.prefixDraft = null;
		if (value === o.prefix) { renderPanel(true); return; }
		doOptions({ domain_srl: ST.site.domain_srl, prefix: value, prefix_set: 'Y' }, { domain_srl: ST.site.domain_srl, prefix: o.prefix, prefix_set: 'Y' }, [value ? T('toast_prefix', value) : T('toast_prefix_none'), T('toast_prefix_sub')]);
	}
	function doOptions(params, oldParams, toastMsg) {
		mutate({
			label: T('undo_options'),
			run: function () { return api('menu.procMenuAdminEditorSaveOptions', params); },
			undo: function () { return api('menu.procMenuAdminEditorSaveOptions', oldParams); },
			toast: toastMsg
		});
	}

	function panelItem(f) {
		var it = f.item;
		var sm = f.sitemap;
		var kindName = kindLabel(it);
		var kindTerm = ['widget', 'article', 'outside', 'shortcut'].indexOf(it.kind) >= 0 ? term(it.kind, kindName) : esc(kindName);
		var where = sm.unlinked ? esc(T('unlinked')) : (q(esc(sm.title)) + ' ' + term('sitemap'));
		var head = '<div class="ph"><button type="button" class="btn ghost back" data-act="m-back">' + ic('back', 16) + esc(T('close')) + '</button><div class="ph-k">' + ic(it.is_home ? 'home' : kindIcon(it.kind), 14) + kindTerm + ' · ' + where + '</div><h2>' + esc(it.name) + '</h2>';
		if (it.mid) head += '<div class="ph-sub">' + esc(T('panel_mid')) + ' ' + midTag(it.mid) + ' · ' + esc(it.view_url) + '</div>';
		else if (it.kind === 'link' && it.url) head += '<div class="ph-sub">' + esc(it.url) + '</div>';
		var acts = '';
		if (it.setup_url) acts += '<a class="btn" href="' + esc(it.setup_url) + '" target="_blank" rel="noopener">' + ic(kindIcon(it.kind), 14) + esc(T('panel_open_setup', kindName)) + ' ' + ic('ext', 12) + '</a>';
		if (it.view_url) acts += '<a class="btn ghost" href="' + esc(it.view_url) + '" target="_blank" rel="noopener">' + ic('ext', 14) + esc(T('panel_open_new')) + '</a>';
		if (acts) head += '<div class="ph-acts">' + acts + '</div>';
		head += '</div>';
		if (sm.unlinked) {
			return head + '<div class="pbody">' + unlinkedRelinkHTML(it, false) + '</div>' +
				'<div class="pfoot"><span class="legacy"><a href="' + esc(B.urls.sitemap) + '" target="_blank" rel="noopener">' + esc(T('panel_open_legacy')) + ' ' + ic('ext', 12) + '</a></span></div>';
		}

		var h = '<div class="sec"><div class="field"><label for="zse-f-name">' + esc(T('panel_name')) + '</label><input class="inp lang_code" id="zse-f-name" data-bind="name" maxlength="250" value="' + esc(it.is_lang ? it.name_key : it.name) + '"></div>';
		if (it.mid) {
			var m = UI.midCheck && UI.midCheck.id === it.id ? UI.midCheck : null;
			var hint;
			if (m && m.state === 'checking') hint = '<div class="hint">' + esc(T('panel_mid_checking')) + '</div>';
			else if (m && m.state === 'bad') hint = '<div class="hint err">' + ic('info', 13) + esc(T('panel_mid_' + m.reason)) + (m.suggestion ? ' <button type="button" class="linkbtn" data-act="mid-use" data-v="' + esc(m.suggestion) + '">' + esc(m.suggestion) + '</button>' : '') + '</div>';
			else if (m && m.state === 'ok' && m.value !== it.mid) hint = '<div class="hint ok">' + ic('check', 13, 2.4) + esc(T('panel_mid_ok')) + ' · <button type="button" class="linkbtn" data-act="mid-save">' + esc(T('panel_mid_save')) + '</button></div>';
			else hint = '<div class="hint">' + esc(it.view_url) + '</div>';
			h += '<div class="field"><label for="zse-f-mid">' + term('mid', T('panel_mid')) + ' <span class="midtag sm"><i>mid</i></span></label><div class="addr"><input id="zse-f-mid" data-bind="mid" value="' + esc(m && m.value !== undefined ? m.value : it.mid) + '" spellcheck="false" autocomplete="off" aria-label="' + esc(T('panel_mid')) + ' (mid)"></div>' + hint + '</div>';
		}
		if (it.kind === 'link') {
			var le = UI.linkErr && UI.linkErr.id === it.id ? UI.linkErr : null;
			h += '<div class="field"><label for="zse-f-link">' + esc(T('panel_link_url')) + '</label><input class="inp" id="zse-f-link" data-bind="link" value="' + esc(le ? le.value : it.url) + '" spellcheck="false" autocomplete="off">' +
				(le ? '<div class="hint err">' + ic('info', 13) + esc(T('link_invalid')) + '</div>' : '<div class="hint">' + esc(T('link_hint')) + '</div>') + '</div>';
		}
		if (it.kind === 'shortcut') {
			var t = targetOf(it);
			h += '<div class="field"><label for="zse-f-target">' + esc(T('panel_target')) + '</label><select class="inp sel" id="zse-f-target" data-bind="target">' + (t ? '' : '<option value="">' + esc(T('shortcut_pick')) + '</option>') + targetOptions(t ? t.id : 0, it.id) + '</select></div>';
		}
		if (it.kind === 'link' || it.kind === 'shortcut') h += '<div class="field">' + newWindowRow(it) + '</div>';
		h += '</div>';

		h += hasDesign(it) ? designHTML(it) : noDesignHTML(it);
		h += whoHTML(it);
		h += seoHTML(it);
		if (isModule(it)) h += advHTML(it);

		var foot = '<div class="pfoot"><span class="legacy"><a href="' + esc(B.urls.sitemap) + '" target="_blank" rel="noopener">' + esc(T('panel_open_legacy')) + ' ' + ic('ext', 12) + '</a></span>' +
			'<button type="button" class="btn danger" data-act="remove" data-id="' + it.id + '" title="' + esc(T('remove_title')) + '">' + ic('trash', 14) + esc(T('remove')) + '</button></div>';
		return head + '<div class="pbody">' + h + '</div>' + foot;
	}

	function archivePreview(mid, tag) {
		var used = allMids();
		for (var i = 1; i < 1000; i++) {
			var sfx = '_' + tag + i;
			var c = String(mid).slice(0, 40 - sfx.length) + sfx;
			if (!used[c.toLowerCase()]) return c;
		}
		return mid + '_' + tag;
	}
	function restoreHTML(it) {
		if (!it.restore) return '';
		var f = find(it.restore.item_id);
		if (!f) return '';
		var cur = f.item;
		return '<div class="restorebox"><b>' + esc(T('restore_title')) + '</b><p>' + esc(T('restore_desc', cur.name, kindLabel(cur), it.restore.mid, kindLabel(it), archivePreview(it.restore.mid, 'new'))) + '</p><button type="button" class="btn primary" data-act="restore-kind" data-id="' + cur.id + '" data-srl="' + it.module_srl + '">' + ic('undo', 14) + esc(T('restore_btn')) + '</button></div>';
	}

	function unlinkedRelinkHTML(it, orphan) {
		return '<div class="sec"><div class="note">' + ic('info', 14) + '<span>' + esc(T('unlinked_info')) + '</span></div>' + restoreHTML(it) + '</div>' +
			'<div class="sec"><h3>' + esc(T('unlinked_relink')) + '</h3><div class="list">' + ST.sitemaps.map(function (sm) {
				return '<div class="li">' + ic('folder', 15) + '<span>' + esc(sm.title) + '</span><span class="r"><button type="button" class="btn" data-act="relink" data-g="' + sm.menu_srl + '" data-' + (orphan ? 'srl' : 'id') + '="' + (orphan ? it.module_srl : it.id) + '">' + esc(T('unlinked_here')) + '</button></span></div>';
			}).join('') + '</div></div>';
	}

	function panelOrphan(it) {
		return '<div class="ph"><button type="button" class="btn ghost back" data-act="m-back">' + ic('back', 16) + esc(T('close')) + '</button><div class="ph-k">' + ic('inbox', 14) + term('unlinked') + '</div><h2>' + esc(it.name) + '</h2><div class="ph-sub">' + esc(kindLabel(it)) + ' · ' + midTag(it.mid) + ' · ' + esc(T('unlinked_never')) + '</div>' +
			(it.setup_url ? '<div class="ph-acts"><a class="btn" href="' + esc(it.setup_url) + '" target="_blank" rel="noopener">' + esc(T('panel_open_setup', kindLabel(it))) + ' ' + ic('ext', 12) + '</a></div>' : '') + '</div>' +
			'<div class="pbody">' + unlinkedRelinkHTML(it, true) + '</div>' +
			'<div class="pfoot"><span class="legacy"><a href="' + esc(B.urls.modules) + '" target="_blank" rel="noopener">' + esc(T('unlinked_module_admin')) + ' ' + ic('ext', 12) + '</a></span></div>';
	}

	/* 종류 바꾸기 */
	function typeOfItem(it) {
		return ['widget', 'article', 'outside'].indexOf(it.kind) >= 0 ? it.kind.toUpperCase() : it.module;
	}
	function pickCandidates(to) {
		var k = kindOfType(to);
		return (ST.unlinked.items || []).concat(ST.unlinked.orphans || []).filter(function (x) { return isModule(x) && x.kind === k; });
	}
	function panelKind() {
		var kc = UI.kc;
		var f = find(kc.id);
		if (!f) { UI.kc = null; return panelSite(); }
		var it = f.item;
		var oldName = kindLabel(it);
		var to = kc.to;
		var toName = to ? kindTitle(to) : '';
		var chk = kc.check || {};
		var types = addableTypes();
		var all = types.pages.concat(types.modules);
		var cur = typeOfItem(it);
		var cands = to ? pickCandidates(to) : [];
		var archiveMid = chk.archive_mid || archivePreview(it.mid, 'old');
		var newMid = chk.new_mid || '';
		var home = !!chk.is_home || it.is_home;
		var ready = !home && to && to !== cur && (kc.src === 'new' || kc.pick) && kc.check;
		var h = '<div class="ph"><button type="button" class="btn ghost back" data-act="kc-cancel">' + ic('back', 16) + esc(T('kc_back')) + '</button><div class="ph-k">' + ic('reload', 14) + esc(T('kind_change')) + '</div><h2>' + esc(it.name) + '</h2><div class="ph-sub">' + esc(T('kc_now', oldName)) + ' · ' + midTag(it.mid) + '</div></div><div class="pbody">';
		h += '<div class="sec"><div class="note">' + ic('info', 14) + '<span>' + esc(T('kc_note', oldName)) + '</span></div>' + (home ? '<div class="note warn">' + ic('info', 14) + '<span>' + esc(T('err_kind_home')) + '</span></div>' : '') + '</div>';
		h += '<div class="sec"><h3>' + esc(T('kc_step1')) + '</h3><div class="optgrid">' + all.map(function (type) {
			var isCur = type === cur;
			return '<button type="button" class="opt' + (to === type ? ' on' : '') + '" data-act="kc-to" data-v="' + esc(type) + '"' + (isCur || home ? ' disabled' : '') + '>' + ic(kindIcon(kindOfType(type)), 16) + '<span>' + esc(kindTitle(type)) + '</span>' + (isCur ? '<small>' + esc(T('kc_current')) + '</small>' : '') + '</button>';
		}).join('') + '</div></div>';
		if (to) {
			h += '<div class="sec"><h3>' + esc(T('kc_step2')) + '</h3><div class="seg full"><button type="button" class="' + (kc.src === 'new' ? 'on' : '') + '" data-act="kc-src" data-v="new">' + esc(T('kc_src_new')) + '</button><button type="button" class="' + (kc.src === 'pick' ? 'on' : '') + '" data-act="kc-src" data-v="pick">' + esc(T('kc_src_pick', cands.length)) + '</button></div>';
			if (kc.src === 'pick') {
				h += cands.length ? '<div class="list picklist">' + cands.map(function (u) {
					return '<button type="button" class="opt' + (kc.pick === u.module_srl ? ' on' : '') + '" data-act="kc-pick" data-v="' + u.module_srl + '">' + ic(kindIcon(u.kind), 15) + '<span>' + esc(u.name) + '</span><small>' + esc(u.mid) + '</small></button>';
				}).join('') + '</div><div class="hint">' + esc(T('kc_pick_hint')) + '</div>' : '<div class="hint">' + esc(T('kc_pick_none')) + '</div>';
			}
			h += '</div>';
		}
		if (to && kc.src === 'new') {
			h += '<div class="sec"><h3>' + esc(T('kc_step3')) + '</h3><div class="radios">' +
				'<button type="button" class="checkline radio" role="radio" aria-checked="' + (kc.addr === 'inherit') + '" data-act="kc-addr" data-v="inherit"><span class="cbox round' + (kc.addr === 'inherit' ? ' on' : '') + '">' + (kc.addr === 'inherit' ? ic('check', 12, 3) : '') + '</span><span><b>' + esc(T('kc_addr_inherit')) + '</b> <span class="state follow">' + esc(T('kc_recommended')) + '</span><small>' + esc(T('kc_addr_inherit_d', toName, it.mid, oldName, archiveMid)) + '</small></span></button>' +
				'<button type="button" class="checkline radio" role="radio" aria-checked="' + (kc.addr === 'new') + '" data-act="kc-addr" data-v="new"><span class="cbox round' + (kc.addr === 'new' ? ' on' : '') + '">' + (kc.addr === 'new' ? ic('check', 12, 3) : '') + '</span><span><b>' + esc(T('kc_addr_new')) + '</b><small>' + esc(T('kc_addr_new_d', toName, newMid || '…', oldName, it.mid)) + '</small></span></button></div></div>';
			if (kc.addr === 'inherit') {
				h += '<div class="sec"><h3>' + esc(T('kc_map_title')) + '</h3><div class="addrmap"><div><code>' + esc(it.mid) + '</code><span>' + esc(T('kc_map_new', toName)) + '</span></div><div><code>' + esc(archiveMid) + '</code><span>' + esc(T('kc_map_old', oldName)) + '</span></div></div>' +
					'<div class="hint">' + esc(T('kc_inherit_hint', toName)) + '</div>' +
					(chk.alias_count ? '<div class="note warn">' + ic('info', 14) + '<span>' + esc(T('kc_alias_warn', it.mid, chk.alias_count)) + '</span></div>' : '') + '</div>';
			}
			h += '<div class="sec"><h3>' + esc(T('kc_step4')) + '</h3><div class="hint">' + esc(T('kc_layout_hint', layoutName(it.layout_srl, 'P').t, toName)) + '</div></div>';
		}
		h += '</div><div class="pfoot"><span class="legacy">' + esc(T('kc_undo_hint')) + '</span><span class="pf-r"><button type="button" class="btn" data-act="kc-cancel">' + esc(T('kc_cancel')) + '</button><button type="button" class="btn primary" data-act="kc-apply"' + (ready ? '' : ' disabled') + '>' + esc(to ? T('kc_apply', toName) : T('kc_choose')) + '</button></span></div>';
		return h;
	}

	/* 여러 개 선택 */
	function selectedItems() {
		return UI.sel.filter(function (id) { return !!find(id) && !find(id).sitemap.unlinked; }).map(function (id) { return find(id).item; });
	}
	function sameOf(list, fn) {
		if (!list.length) return null;
		var v = fn(list[0]);
		for (var i = 1; i < list.length; i++) if (fn(list[i]) !== v) return null;
		return v;
	}
	function panelMulti() {
		var items = selectedItems();
		var dz = items.filter(isModule), ex = items.filter(function (x) { return !isModule(x); });
		var mixed = '<span class="h-r"><span class="state follow">' + esc(T('multi_mixed')) + '</span></span>';
		var dtab = UI.dtab === 'M' && ST.mobile_enabled ? 'M' : 'P';
		var h = '<div class="ph"><button type="button" class="btn ghost back" data-act="m-back">' + ic('back', 16) + esc(T('close')) + '</button><div class="ph-k">' + ic('layers', 14) + esc(T('multi_k')) + '</div><h2>' + esc(T('multi_title', items.length)) + '</h2>' +
			'<div class="selpills">' + dz.map(function (i) { return '<span>' + esc(i.name) + '</span>'; }).join('') + ex.map(function (i) { return '<span class="x">' + esc(i.name) + '</span>'; }).join('') + '</div>' +
			(ex.length && dz.length ? '<div class="hint">' + ic('info', 13) + esc(T('multi_excluded', ex.map(function (i) { return i.name; }).join(', '))) + '</div>' : '') + '</div><div class="pbody">';
		if (dz.length) {
			var lay = sameOf(dz, function (i) { return layoutValue(i, dtab); });
			h += '<div class="sec"><h3>' + term('layout') + (lay === null ? mixed : '') + '</h3>' + designTabs(dtab) + layoutCards(lay === null ? -99 : lay, dtab, 'bulk-layout') + '</div>';
			var skinable = dz.filter(hasSkin);
			var mod = sameOf(dz, function (i) { return i.module; });
			if (skinable.length === dz.length && mod !== null) {
				var stype = dtab === 'M' && skinsOf(dz[0]).M.length ? 'M' : 'P';
				var sk = sameOf(dz, function (i) { return skinValue(i, stype); });
				h += '<div class="sec"><h3>' + term('skin') + '<span class="sep-dot">·</span>' + esc(T('multi_skin_head', kindLabel(dz[0]), dz.length)) + (sk === null ? mixed : '') + '</h3>' + skinCards(dz[0], stype, sk === null ? '\u0000' : sk, 'bulk-skin') + '</div>';
			} else {
				h += '<div class="sec"><h3>' + term('skin') + '</h3><div class="note">' + ic('info', 14) + '<span>' + esc(T('multi_skin_kinds')) + '</span></div></div>';
			}
		}
		var wt = sameOf(items, function (i) { var w = whoOf(i); return w.type + ':' + w.groups.join(','); });
		var wtype = UI.bulkWho ? UI.bulkWho.type : (wt === null ? null : wt.split(':')[0]);
		h += '<div class="sec"><h3>' + term('who') + (wt === null && !UI.bulkWho ? mixed : '') + '</h3>' + whoSegHTML(wtype, 'bulk-who');
		if (wtype === 'groups') {
			var gids = UI.bulkWho ? UI.bulkWho.groups : whoOf(items[0]).groups;
			h += '<div class="flabel grp-label">' + esc(T('who_groups_label')) + ' <small>' + esc(gids.length ? T('who_groups_count', gids.length) : T('who_groups_pick')) + '</small></div>' + groupChecks(gids, 'bulk-who-group');
		}
		if (dz.length) {
			h += '<div class="flabel scope-label">' + esc(T('scope_label')) + '</div><div class="radios">' +
				radioLine('bulk-scope', 'page', UI.bulkScope === 'page', 'page_block', T('scope_page_d')) +
				radioLine('bulk-scope', 'menu', UI.bulkScope === 'menu', 'menu_only', T('scope_menu_d')) + '</div>';
		}
		h += '</div>';
		if (dz.length) {
			var st = dz.map(function (x) { return seoOf(x).any ? 'on' : 'off'; });
			var same = st.every(function (x) { return x === st[0]; }) ? st[0] : null;
			h += '<div class="sec"><h3>' + term('seo') + (same === null ? mixed : '') + '</h3><div class="seg full" role="group" aria-label="' + esc(T('term_seo_t')) + '"><button type="button" class="' + (same === 'on' ? 'on' : '') + '" data-act="bulk-seo" data-v="on">' + esc(T('seo_bulk_on')) + '</button><button type="button" class="' + (same === 'off' ? 'on' : '') + '" data-act="bulk-seo" data-v="off">' + esc(T('seo_bulk_off')) + '</button><button type="button" data-act="bulk-seo" data-v="default">' + esc(T('seo_bulk_default')) + '</button></div></div>';
		}
		h += '</div>';
		h += '<div class="pfoot"><button type="button" class="btn ghost" data-act="clear-sel">' + esc(T('multi_clear')) + '</button><button type="button" class="btn danger" data-act="remove-sel" title="' + esc(T('remove_title')) + '">' + ic('trash', 14) + esc(T('multi_remove', topSelected().length)) + '</button></div>';
		return h;
	}

	function ownerTitle(sm) {
		for (var i = 0; i < ST.sites.length; i++) if (ST.sites[i].domain_srl === sm.domain_srl) return ST.sites[i].title;
		return ST.site.title;
	}
	function panelSitemap(sm) {
		var uses = sm.slots || [];
		var usesHTML = uses.length
			? '<div class="list uses">' + uses.map(function (u) {
				return '<div class="li">' + ic('layers', 15) + '<span class="u-t"><b>' + esc(u.layout_title) + '</b><br><span class="u-s">' + esc(u.slot_title) + ' <code>' + esc(u.slot) + '</code></span></span><span class="r"><button type="button" class="btn ghost" data-act="bind-slot" data-layout="' + u.layout_srl + '" data-slot="' + esc(u.slot) + '" data-g="0">' + esc(T('slot_unbind')) + '</button></span></div>';
			}).join('') + '</div>'
			: '<div class="note warn">' + ic('info', 14) + '<span><b>' + esc(T('sitemap_slots_none')) + '</b><br>' + esc(T('site_slots_empty')) + '</span></div>';
		var pick = '';
		if (UI.slotPick) {
			var groups = (ST.layouts.P || []).filter(function (Lx) { return Lx.slots.length; });
			pick = '<div class="slotpick">' + groups.map(function (Lx) {
				return '<div class="sp-l"><div class="sp-h">' + ic('layers', 14) + '<b>' + esc(Lx.title) + '</b><small>' + esc(T('sitemap_slot_count', Lx.slots.length)) + '</small></div>' + Lx.slots.map(function (s) {
					var cur = s.resolved_menu_srl || 0;
					var right;
					if (cur === sm.menu_srl) right = '<span class="state own">' + esc(T('slot_connected')) + '</span>';
					else if (cur) {
						var other = sitemapBySrl(cur);
						var nm = other ? other.title : (cur === ST.unlinked.menu_srl ? T('unlinked') : '#');
						right = '<span class="sp-now">' + esc(T('slot_now', nm)) + '</span><button type="button" class="btn" data-act="bind-slot" data-layout="' + Lx.layout_srl + '" data-slot="' + esc(s.id) + '" data-g="' + sm.menu_srl + '">' + esc(T('slot_replace')) + '</button>';
					} else right = '<span class="sp-now">' + esc(T('slot_empty')) + '</span><button type="button" class="btn primary" data-act="bind-slot" data-layout="' + Lx.layout_srl + '" data-slot="' + esc(s.id) + '" data-g="' + sm.menu_srl + '">' + esc(T('slot_connect')) + '</button>';
					return '<div class="sp-r"><span>' + esc(s.title) + ' <code>' + esc(s.id) + '</code></span><span class="r">' + right + '</span></div>';
				}).join('') + '</div>';
			}).join('') + '</div>';
		}
		var count = 0;
		(function cnt(list) { list.forEach(function (x) { count++; cnt(x.children || []); }); })(sm.items);
		var titleVal = /^\$user_lang->/.test(sm.title_key) ? sm.title_key : sm.title;
		return '<div class="ph"><button type="button" class="btn ghost back" data-act="m-back">' + ic('back', 16) + esc(T('close')) + '</button><div class="ph-k">' + ic('folder', 14) + term('sitemap') + '</div><h2>' + esc(sm.title) + '</h2><div class="ph-sub">' + esc(uses.length ? T('sitemap_slots', uses.length) : T('sitemap_slots_none')) + '</div></div>' +
			'<div class="pbody"><div class="sec"><div class="field"><label for="zse-sm-name">' + esc(T('sm_name')) + '</label><input class="inp lang_code" id="zse-sm-name" data-bind="smname" value="' + esc(titleVal) + '"></div></div>' +
			'<div class="sec" id="zse-sec-uses"><h3><span>' + esc(T('slot_bind_title')).replace('%T', term('slot')) + '</span></h3>' + usesHTML +
			'<button type="button" class="btn' + (UI.slotPick ? ' on-pick' : '') + '" style="margin-top:12px" data-act="slot-pick" aria-expanded="' + !!UI.slotPick + '">' + ic(UI.slotPick ? 'x' : 'plus', 14) + esc(UI.slotPick ? T('slot_close') : T('slot_bind')) + '</button>' + pick + '</div>' +
			'<div class="sec" id="zse-sec-smseo"><h3>' + term('seo') + '</h3>' + seoSwitch('sm-seo', !sm.seo_off, esc(T('sm_seo_label')), T(sm.seo_off ? 'sm_seo_off_d' : 'sm_seo_on_d'), ' data-g="' + sm.menu_srl + '" aria-label="' + esc(T('sm_seo_label')) + '"') + '</div>' +
			(count ? '<div class="sec"><h3><span>' + term('layout') + '<span class="sep-dot">·</span>' + term('skin') + '<span class="sep-dot">·</span>' + term('who') + '</span></h3><button type="button" class="btn" data-act="select-group" data-g="' + sm.menu_srl + '">' + ic('layers', 14) + esc(T('sm_select_all')) + '</button><div class="hint">' + esc(T('sm_select_all_hint')) + '</div></div>' : '') +
			'<div class="sec"><dl class="kv"><dt>' + esc(T('sitemap_items')) + '</dt><dd>' + count + '</dd>' + (ST.sites.length > 1 ? '<dt>' + esc(T('sm_owner')) + '</dt><dd>' + esc(ownerTitle(sm)) + '</dd>' : '') + '</dl></div></div>' +
			'<div class="pfoot"><span class="legacy"><a href="' + esc(B.urls.sitemap) + '" target="_blank" rel="noopener">' + esc(T('sitemap_legacy')) + ' ' + ic('ext', 12) + '</a></span>' +
			'<button type="button" class="btn danger" data-act="sm-delete" data-g="' + sm.menu_srl + '"' + (count ? ' disabled title="' + esc(T('sm_delete_disabled')) + '"' : '') + '>' + ic('trash', 14) + esc(T('sm_delete')) + '</button></div>';
	}

	function panelSite() {
		var site = ST.site;
		var home = null;
		for (var id in IDX) if (IDX[id].item.is_home && !IDX[id].sitemap.unlinked) { home = IDX[id].item; break; }
		var layouts = (ST.site_layouts || []).map(function (srl) { return layoutBySrl(srl, 'P'); }).filter(function (x) { return x && x.slots.length; });
		var slotsHTML = layouts.map(function (Lx) {
			return '<div class="sp-l"><div class="sp-h">' + ic('layers', 14) + '<b>' + esc(Lx.title) + '</b><small>' + esc((Lx.layout_srl === ST.site_default_layout.P ? T('layout_site_default') + ' · ' : '') + T('sitemap_slot_count', Lx.slots.length)) + '</small></div>' + Lx.slots.map(function (s) {
				var cur = s.resolved_menu_srl || 0;
				var options = '<option value="0">' + esc(T('slot_empty_option')) + '</option>' + ST.sitemaps.map(function (sm) {
					return '<option value="' + sm.menu_srl + '"' + (cur === sm.menu_srl ? ' selected' : '') + '>' + esc(sm.title) + '</option>';
				}).join('');
				return '<div class="sp-r"><span>' + esc(s.title) + ' <code>' + esc(s.id) + '</code></span><span class="r"><select class="inp sel" data-bind="slotsel" data-layout="' + Lx.layout_srl + '" data-slot="' + esc(s.id) + '" aria-label="' + esc(Lx.title + ' ' + s.title) + '">' + options + '</select></span></div>';
			}).join('') + '</div>';
		}).join('');
		var unused = ST.sitemaps.filter(function (sm) { return !(sm.slots || []).length; });
		return '<div class="ph"><button type="button" class="btn ghost back" data-act="m-back">' + ic('back', 16) + esc(T('close')) + '</button><div class="ph-k">' + ic('globe', 14) + esc(T('site_settings')) + '</div><h2><span class="fav sm" style="background:' + favColor(siteIndex()) + '">' + esc(initial(site.title)) + '</span>' + esc(site.title) + '</h2><div class="ph-sub">' + esc(site.domain) + (site.is_default ? ' · ' + esc(T('site_default')) : '') + '</div></div>' +
			'<div class="pbody">' +
			themeSecHTML() +
			'<div class="sec"><dl class="kv"><dt>' + esc(T('site_home')) + '</dt><dd>' + (home ? esc(home.name) + ' ' + midTag(home.mid) : esc(T('site_none'))) + '</dd></dl></div>' +
			'<div class="sec" id="zse-sec-slots"><h3><span>' + esc(T('site_slots_title')).replace('%T', term('slot')) + '</span></h3>' + (slotsHTML || '<div class="note">' + ic('info', 14) + '<span>' + esc(T('site_slots_nolayout')) + '</span></div>') +
			(unused.length ? '<div class="hint">' + ic('info', 13) + esc(T('site_slot_unused', unused.map(function (x) { return q(x.title); }).join(', '))) + '</div>' : '') + '</div>' +
			siteOptionsHTML() +
			'</div>' +
			'<div class="pfoot"><span class="legacy">' + esc(T('legacy')) + ' <a href="' + esc(B.urls.domains) + '" target="_blank" rel="noopener">' + esc(T('legacy_domains')) + ' ' + ic('ext', 12) + '</a><a href="' + esc(B.urls.design) + '" target="_blank" rel="noopener">' + esc(T('legacy_design')) + ' ' + ic('ext', 12) + '</a></span></div>';
	}

	function panelInline() {
		var a = UI.inline;
		var parent = a.parent ? find(a.parent) : null;
		var sm = sitemapBySrl(a.g);
		var k = kindOfType(a.kind);
		var mid = (k !== 'link' && k !== 'folder' && k !== 'shortcut' && a.name.trim()) ? suggestMid(a.name, a.kind) : '';
		var base = String(ST.site.base_url || '').replace(/\/$/, '');
		return '<div class="ph"><div class="ph-k">' + ic('plus', 14) + esc(T('ia_making')) + '</div><h2>' + esc(a.name.trim() || T('ia_name_needed')) + '</h2><div class="ph-sub">' + esc(parent ? T('ia_under_item', parent.item.name) : T('ia_end_of_sitemap', sm ? sm.title : '')) + '</div></div>' +
			'<div class="pbody"><div class="sec"><h3>' + esc(T('ia_result')) + '</h3><div class="summary"><div class="li"><span class="ico">' + ic(kindIcon(k), 16) + '</span><div>' + esc(T('ia_count', kindTitle(a.kind))) + (mid ? '<small>' + esc(T('ia_mid')) + ' ' + midTag(mid) + ' · ' + esc(base + '/' + mid) + '</small>' : '') + '</div></div></div></div>' +
			'<div class="sec"><div class="note">' + ic('info', 14) + '<span>' + esc(T('ia_note')) + '</span></div></div></div>';
	}

	function panelKey() {
		var f = UI.focus;
		return f.type + ':' + (f.id || '') + ':' + ST.site.domain_srl + (UI.kc ? ':kc' : '') + (f.type === 'multi' ? ':' + UI.sel.join(',') : '');
	}

	function renderPanel(force) {
		var p = ROOT.querySelector('.zse-panel');
		var active = document.activeElement;
		var key = panelKey();
		if (!force && p._key === key && active && p.contains(active) && /^(INPUT|TEXTAREA|SELECT)$/.test(active.tagName) && !UI.inline) return;
		var h;
		var f = UI.focus;
		if (f.type === 'inline' && UI.inline) h = panelInline();
		else if (f.type === 'theme') h = panelTheme();
		else if (f.type === 'item' && UI.kc && UI.kc.id === f.id && find(f.id)) h = panelKind();
		else if (f.type === 'multi' && selectedItems().length > 1) h = panelMulti();
		else if (f.type === 'item' && find(f.id)) h = panelItem(find(f.id));
		else if (f.type === 'sitemap' && sitemapBySrl(f.id)) h = panelSitemap(sitemapBySrl(f.id));
		else if (f.type === 'orphan' && orphanBySrl(f.id)) h = panelOrphan(orphanBySrl(f.id));
		else h = panelSite();
		var body = p.querySelector('.pbody');
		var keep = body ? body.scrollTop : 0;
		p.innerHTML = h;
		if (p._key === key && p.querySelector('.pbody')) p.querySelector('.pbody').scrollTop = keep;
		p._key = key;
		['zse-f-name', 'zse-sm-name'].forEach(function (nid) {
			var nameInput = document.getElementById(nid);
			if (nameInput && $.fn.xeApplyMultilingualUI) {
				try { $(nameInput).xeApplyMultilingualUI(); } catch (e) { }
			}
		});
	}

	/* ── 미리보기 ─────────────────────────── */
	function previewItem() {
		var f = UI.focus;
		if (f.type === 'item' && find(f.id)) {
			var it = find(f.id).item;
			if (it.kind === 'folder' && it.children && it.children.length) it = it.children[0];
			return it;
		}
		if (f.type === 'orphan' && orphanBySrl(f.id)) return orphanBySrl(f.id);
		if (f.type === 'multi') { var ms = selectedItems(); return ms.length ? ms[0] : null; }
		return null;
	}
	function renderPreview() {
		var pv = ROOT.querySelector('.zse-pv');
		if (!UI.preview) return;
		if (!pv.querySelector('iframe')) {
			pv.innerHTML = '<div class="pvbar"><div class="pvurl" id="zse-pvurl"></div><div class="seg" id="zse-pvdev" role="group"></div><button type="button" class="iconbtn" data-act="pv-reload" title="' + esc(T('pv_refresh')) + '" aria-label="' + esc(T('pv_refresh')) + '">' + ic('reload', 16) + '</button><a class="iconbtn" id="zse-pvopen" target="_blank" rel="noopener" title="' + esc(T('pv_open')) + '" aria-label="' + esc(T('pv_open')) + '">' + ic('ext', 16) + '</a></div><div class="pvstage" id="zse-pvstage"><div class="pvfit" id="zse-pvfit"><iframe class="pvframe" id="zse-pvframe" title="' + esc(T('pv_open')) + '"></iframe></div></div>';
		}
		var url = viewUrlOf(previewItem());
		var shown = url.replace(/^https?:\/\//, '');
		var slash = shown.indexOf('/');
		document.getElementById('zse-pvurl').innerHTML = ic('lock', 13) + '<span>' + (slash > 0 ? '<b>' + esc(shown.slice(0, slash)) + '</b>' + esc(shown.slice(slash)) : '<b>' + esc(shown) + '</b>') + '</span>';
		document.getElementById('zse-pvopen').setAttribute('href', url);
		document.getElementById('zse-pvdev').innerHTML = [['pc', 'monitor', 'pv_pc'], ['tablet', 'tablet', 'pv_tablet'], ['mobile', 'phone', 'pv_mobile']].map(function (d) {
			return '<button type="button" class="' + (UI.device === d[0] ? 'on' : '') + '" data-act="device" data-v="' + d[0] + '" title="' + esc(T(d[2])) + '" aria-label="' + esc(T(d[2])) + '" aria-pressed="' + (UI.device === d[0]) + '">' + ic(d[1], 15) + '</button>';
		}).join('');
		if (url !== lastFrameUrl) {
			lastFrameUrl = url;
			document.getElementById('zse-pvframe').setAttribute('src', url);
		}
		fitPreview();
	}
	function reloadFrame() {
		var fr = document.getElementById('zse-pvframe');
		if (!fr || !UI.preview) return;
		lastFrameUrl = '';
		renderPreview();
		try { if (fr.getAttribute('src') === lastFrameUrl) fr.contentWindow.location.reload(); } catch (e) { fr.setAttribute('src', lastFrameUrl); }
	}
	function fitPreview() {
		var st = document.getElementById('zse-pvstage'), fit = document.getElementById('zse-pvfit'), fr = document.getElementById('zse-pvframe');
		if (!st || !fit || !fr || !st.clientWidth) return;
		var W = st.clientWidth - 32, H = st.clientHeight - 32;
		var tw = { pc: Math.max(W, 1200), tablet: 820, mobile: 390 }[UI.device];
		var sc = Math.min(1, W / tw);
		fit.style.width = Math.round(tw * sc) + 'px';
		fit.style.height = H + 'px';
		fr.style.width = tw + 'px';
		fr.style.height = Math.round(H / sc) + 'px';
		fr.style.transform = 'scale(' + sc + ')';
	}

	/* ── 겹침 층 ─────────────────────────── */
	function popHTML() {
		var p = UI.pop;
		if (!p) return '';
		if (p.type === 'row-menu') {
			var f = find(p.id);
			if (!f) return '';
			var w = whoOf(f.item);
			var mob = isMobile();
			return '<div class="pop menu" id="zse-pop" role="menu">' +
				(mob ? '<div class="s-grid">' + [['up', 'up'], ['down', 'down'], ['in', 'indent'], ['out', 'outdent']].map(function (d) { return '<button type="button" data-act="mv" data-id="' + p.id + '" data-v="' + d[0] + '">' + ic(d[1], 18) + esc(T('mv_' + d[0])) + '</button>'; }).join('') + '</div>' : '') +
				'<button type="button" class="opt" role="menuitem" data-act="rename" data-id="' + p.id + '">' + esc(T('row_rename')) + '<small>F2</small></button>' +
				'<button type="button" class="opt" role="menuitem" data-act="inline-add" data-g="' + f.sitemap.menu_srl + '" data-parent="' + p.id + '">' + esc(T('add_sub')) + '</button>' +
				'<button type="button" class="opt" role="menuitem" data-act="copy" data-id="' + p.id + '">' + esc(T('row_copy')) + '</button>' +
				(isModule(f.item) && !f.item.is_home ? '<button type="button" class="opt" role="menuitem" data-act="kind-open" data-id="' + p.id + '">' + esc(T('kind_change')) + '</button>' : '') +
				(f.item.setup_url ? '<a class="opt" role="menuitem" href="' + esc(f.item.setup_url) + '" target="_blank" rel="noopener">' + esc(T('row_open_settings')) + '<small>' + ic('ext', 13) + '</small></a>' : '') +
				(seoOf(f.item) ? '<button type="button" class="opt" role="menuitem" data-act="seo-toggle" data-id="' + p.id + '">' + ic(seoOf(f.item).any ? 'searchoff' : 'search', 15) + esc(T(seoOf(f.item).any ? 'seo_off_btn' : 'seo_on_btn')) + '<small>' + esc(T('seo_menu_sub')) + '</small></button>' : '') +
				(ST.hidden_ready ? '<button type="button" class="opt" role="menuitem" data-act="hidden-toggle" data-id="' + p.id + '">' + ic(f.item.is_hidden === 'Y' ? 'eye' : 'eyeoff', 15) + esc(T(f.item.is_hidden === 'Y' ? 'unhide_btn' : 'hide_btn')) + '</button>' : '') +
				'<div class="sep"></div><div class="mh">' + esc(T('row_who')) + '</div>' +
				[['public', 'who_public'], ['member', 'who_member'], ['groups', 'who_groups'], ['admin', 'who_admin']].map(function (o) {
					return '<button type="button" class="opt' + (w.type === o[0] ? ' on' : '') + '" role="menuitemradio" aria-checked="' + (w.type === o[0]) + '" data-act="row-who" data-id="' + p.id + '" data-v="' + o[0] + '">' + esc(T(o[1])) + '</button>';
				}).join('') +
				'<div class="sep"></div><button type="button" class="opt danger" role="menuitem" data-act="remove" data-id="' + p.id + '">' + ic('trash', 15) + esc(T('row_remove')) + '<small>Del</small></button></div>';
		}
		if (p.type === 'ia-kind') {
			var t = addableTypes();
			var block = function (title, list) {
				if (!list.length) return '';
				return '<div class="mh">' + esc(title) + '</div>' + list.map(function (type) {
					return '<button type="button" class="opt' + (UI.inline && UI.inline.kind === type ? ' on' : '') + '" data-act="ia-setkind" data-v="' + esc(type) + '">' + ic(kindIcon(kindOfType(type)), 16) + esc(kindTitle(type)) + '</button>';
				}).join('');
			};
			return '<div class="pop menu" id="zse-pop" style="width:260px">' + block(T('kind_group_page'), t.pages) + block(T('kind_group_module'), t.modules) + block(T('kind_group_link'), t.links) + '</div>';
		}
		if (p.type === 'sm-menu') {
			var psm = sitemapBySrl(p.g);
			if (!psm) return '';
			var others = ST.sites.filter(function (s) { return s.domain_srl !== psm.domain_srl; });
			return '<div class="pop menu" id="zse-pop" role="menu" style="width:300px"><div class="mh">' + esc(psm.title) + '</div>' +
				'<button type="button" class="opt" role="menuitemcheckbox" aria-checked="' + !psm.seo_off + '" data-act="sm-seo" data-g="' + psm.menu_srl + '">' + ic(psm.seo_off ? 'searchoff' : 'search', 15) + esc(T('sm_seo_label')) + '<small><span class="switch mini' + (psm.seo_off ? '' : ' on') + '" aria-hidden="true"></span></small></button>' +
				'<button type="button" class="opt" role="menuitem" data-act="sitemap" data-g="' + psm.menu_srl + '">' + esc(T('sm_menu_settings')) + '</button>' +
				(others.length ? '<div class="sep"></div><div class="mh">' + esc(T('sm_move')) + '</div>' : '') + others.map(function (s) {
				return '<button type="button" class="opt" role="menuitem" data-act="sm-move" data-g="' + psm.menu_srl + '" data-v="' + s.domain_srl + '"><span class="fav xs" style="background:' + favColor(ST.sites.indexOf(s)) + '">' + esc(initial(s.title)) + '</span>' + esc(s.title) + '<small>' + esc(s.domain) + '</small></button>';
			}).join('') + '</div>';
		}
		if (p.type === 'ov-row') return ovPopHTML(p);
		if (p.type === 'help') {
			return '<div class="pop menu" id="zse-pop" style="width:300px"><button type="button" class="opt" data-act="tour-start">' + ic('sparkle', 16) + esc(T('help_tour')) + '<small>3</small></button><div class="sep"></div><div class="mh">' + esc(T('help_terms')) + '</div><div class="hint pad">' + esc(T('help_terms_desc')).replace('%L', term('layout')).replace('%S', term('skin')).replace('%M', term('sitemap')) + '</div><div class="sep"></div><div class="mh">' + esc(T('help_keys')) + '</div><div class="keys-list"><span><kbd>F2</kbd> ' + esc(T('key_rename')) + '</span><span><kbd>Alt</kbd>+<kbd>↑</kbd><kbd>↓</kbd> ' + esc(T('key_order')) + '</span><span><kbd>Tab</kbd> · <kbd>Shift</kbd>+<kbd>Tab</kbd> ' + esc(T('key_indent')) + '</span><span><kbd>Del</kbd> ' + esc(T('key_remove')) + '</span><span><kbd>Ctrl</kbd>+<kbd>Z</kbd> ' + esc(T('key_undo')) + '</span><span><kbd>Ctrl</kbd>·<kbd>Shift</kbd>+' + esc(T('key_multi_click')) + ' ' + esc(T('key_multi')) + '</span></div></div>';
		}
		if (p.type === 'legacy') {
			return '<div class="pop menu" id="zse-pop" style="width:280px"><div class="mh">' + esc(T('legacy_title')) + '</div>' + [['sitemap', 'legacy_sitemap'], ['design', 'legacy_design'], ['layouts', 'legacy_layouts'], ['domains', 'legacy_domains']].map(function (x) {
				return '<a class="opt" href="' + esc(B.urls[x[0]]) + '" target="_blank" rel="noopener">' + esc(T(x[1])) + '<small>' + ic('ext', 13) + '</small></a>';
			}).join('') + '</div>';
		}
		if (p.type === 'sites') {
			return '<div class="pop menu" id="zse-pop" style="width:300px"><div class="mh">' + esc(T('rail_sites')) + '</div>' + ST.sites.map(function (s, i) {
				return '<button type="button" class="opt' + (s.domain_srl === ST.site.domain_srl ? ' on' : '') + '" data-act="site" data-v="' + s.domain_srl + '"><span class="fav xs" style="background:' + favColor(i) + '">' + esc(initial(s.title)) + '</span>' + esc(s.title) + '<small>' + esc(s.domain) + '</small></button>';
			}).join('') + '<div class="sep"></div><button type="button" class="opt" data-act="wizard">' + ic('plus', 16) + esc(T('wz_title')) + '</button></div>';
		}
		return '';
	}
	var TOUR = [
		{ sel: '.zse-tree .treescroll', t: 'tour_1_t', d: 'tour_1_d' },
		{ sel: '.zse-panel', msel: '.zse-tree .row[data-act="row"]', t: 'tour_2_t', d: 'tour_2_d' },
		{ sel: '.zse-bar .savestate', t: 'tour_3_t', d: 'tour_3_d' }
	];
	function tourHTML() {
		if (!UI.tour) return '';
		var s = TOUR[UI.tour - 1];
		return '<div class="tour" role="dialog" aria-modal="true" aria-label="' + esc(T(s.t)) + '"><div class="tour-spot" id="zse-tourspot"></div><div class="tour-bub" id="zse-tourbub"><div class="tb-step">' + UI.tour + ' / 3</div><b>' + esc(T(s.t)) + '</b><p>' + esc(T(s.d)) + '</p><div class="tb-acts"><button type="button" class="btn ghost" data-act="tour-skip">' + esc(T('tour_skip')) + '</button><button type="button" class="btn primary" data-act="tour-next">' + esc(UI.tour === 3 ? T('tour_start') : T('tour_next')) + '</button></div></div></div>';
	}
	function renderLayer() {
		var layer = ROOT.querySelector('.zse-layer');
		var h = popHTML();
		if (UI.toast) h += '<div class="toast" role="status" aria-live="polite"><span>' + esc(UI.toast.title) + (UI.toast.sub ? '<span class="t-sub">' + esc(UI.toast.sub) + '</span>' : '') + '</span>' + (UI.toast.action ? '<button type="button" data-act="toast-action">' + esc(UI.toast.action.label) + '</button>' : '') + (UI.toast.canUndo ? '<button type="button" data-act="undo">' + esc(T('undo')) + '</button>' : '') + '<button type="button" class="t-x" data-act="toast-x" aria-label="' + esc(T('toast_close')) + '">' + ic('x', 16) + '</button></div>';
		h += wizardHTML();
		h += tourHTML();
		layer.innerHTML = h;
		placePop();
		placeTour();
		if (UI.wz && UI.wzFocus) { var wi = document.getElementById('zse-wz-domain'); if (wi) wi.focus(); UI.wzFocus = false; }
		var pop = document.getElementById('zse-pop');
		if (pop && UI.pop && UI.pop.focus) { var first = pop.querySelector('button, a'); if (first) first.focus(); UI.pop.focus = false; }
	}
	function placePop() {
		var pop = document.getElementById('zse-pop');
		if (!pop || !UI.pop) return;
		var x, y;
		var a = UI.pop.anchor ? ROOT.querySelector('[data-anchor="' + UI.pop.anchor + '"]') : null;
		pop.style.maxHeight = '';
		var pw = pop.offsetWidth, ph = pop.offsetHeight;
		if (UI.pop.x !== undefined) { x = UI.pop.x; y = UI.pop.y; }
		else if (a) {
			var r = a.getBoundingClientRect();
			if (!r.width && a.closest('.row')) r = a.closest('.row').getBoundingClientRect();
			x = UI.pop.type === 'ia-kind' ? r.left : r.right - pw;
			if (x < 8) x = r.left;
			var below = innerHeight - r.bottom - 14, above = r.top - 14;
			if (ph <= below || below >= above) {
				y = r.bottom + 6;
				if (ph > below) { pop.style.maxHeight = Math.max(160, below) + 'px'; ph = Math.max(160, below); }
			} else {
				if (ph > above) { pop.style.maxHeight = above + 'px'; ph = above; }
				y = r.top - ph - 6;
			}
		}
		else { x = 100; y = 100; }
		x = Math.max(8, Math.min(x, innerWidth - pw - 8));
		y = Math.max(8, Math.min(y, innerHeight - ph - 8));
		pop.style.left = x + 'px';
		pop.style.top = y + 'px';
	}
	function placeTour() {
		if (!UI.tour) return;
		var s = TOUR[UI.tour - 1];
		var el = ROOT.querySelector(isMobile() && s.msel ? s.msel : s.sel);
		var spot = document.getElementById('zse-tourspot'), bub = document.getElementById('zse-tourbub');
		if (!el || !spot || !bub) return;
		var r = el.getBoundingClientRect();
		var pad = 4;
		var top = Math.max(4, r.top - pad), hh = Math.min(innerHeight - 8 - top, r.height + pad * 2);
		spot.style.cssText = 'left:' + (r.left - pad) + 'px;top:' + top + 'px;width:' + (r.width + pad * 2) + 'px;height:' + hh + 'px';
		var bw = bub.offsetWidth, bh = bub.offsetHeight, x, y;
		if (r.right + 16 + bw < innerWidth) { x = r.right + 16; y = r.top + 24; }
		else if (r.left - 16 - bw > 0) { x = r.left - 16 - bw; y = r.top + 24; }
		else { x = r.left + r.width / 2 - bw / 2; y = r.bottom + 12; if (y + bh > innerHeight - 8) y = Math.max(8, r.top + 48); }
		bub.style.left = Math.max(8, Math.min(x, innerWidth - bw - 8)) + 'px';
		bub.style.top = Math.max(8, Math.min(y, innerHeight - bh - 8)) + 'px';
		var next = bub.querySelector('[data-act="tour-next"]');
		if (next && document.activeElement !== next) next.focus();
	}
	var toastTimer;
	function toast(title, sub, canUndo, action) {
		UI.toast = { title: title, sub: sub || '', canUndo: !!canUndo, action: action || null };
		clearTimeout(toastTimer);
		toastTimer = setTimeout(function () { UI.toast = null; renderLayer(); }, action ? 12000 : 7000);
		renderLayer();
	}

	/* ── 용어 말풍선 ─────────────────────────── */
	var tipEl, tipFor = null;
	function showTip(el) {
		var info = termInfo(el.getAttribute('data-term'));
		if (!info) return;
		tipFor = el;
		el.setAttribute('aria-describedby', 'zse-termtip');
		tipEl.innerHTML = '<b>' + esc(info.t) + '</b><span>' + esc(info.d) + '</span>' + (info.e ? '<em>' + esc(info.e) + '</em>' : '');
		tipEl.hidden = false;
		var r = el.getBoundingClientRect();
		var w = tipEl.offsetWidth, h = tipEl.offsetHeight;
		var x = r.left + r.width / 2 - w / 2, y = r.bottom + 8;
		if (y + h > innerHeight - 8) y = r.top - h - 8;
		x = Math.max(8, Math.min(x, innerWidth - w - 8));
		tipEl.style.left = x + 'px';
		tipEl.style.top = y + 'px';
	}
	function hideTip() { if (tipFor) tipFor.removeAttribute('aria-describedby'); tipFor = null; if (tipEl) tipEl.hidden = true; }

	/* ── 동작 ─────────────────────────── */
	function isMobile() { return window.innerWidth < 768; }
	function select(id) {
		UI.focus = { type: 'item', id: id };
		UI.sel = [id];
		UI.anchor = id;
		UI.kc = null;
		UI.grantDraft = null;
		UI.linkErr = null;
		UI.bulkWho = null;
		UI.inline = null;
		UI.renaming = null;
		UI.midCheck = null;
		UI.whoDraft = null;
		UI.slotPick = false;
		if (isMobile()) UI.mPane = 'panel';
	}
	function serialize(it) {
		return {
			id: it.id, name_key: it.is_lang ? it.name_key : it.name_key, url: it.url, is_shortcut: it.is_shortcut, open_window: it.open_window,
			expand: it.expand, group_srls: it.group_srls, icon: it.icon, 'class': it['class'], desc: it.desc,
			children: (it.children || []).map(serialize)
		};
	}
	function moveParams(id, parent, source) {
		return { mode: 'move', target_srl: id, parent_srl: parent, source_srl: source || 0 };
	}

	function doRename(id, value) {
		var f = find(id);
		if (!f) return;
		value = String(value || '').trim();
		var old = f.item.is_lang ? f.item.name_key : f.item.name;
		if (!value || value === old) return;
		if (value.length > 250) { toast(T('toast_failed'), T('name_too_long'), false); return; }
		var oldName = f.item.name;
		var op = {
			label: T('undo_rename', f.item.name),
			local: function () { if (!/^\$user_lang->/.test(value)) f.item.name = value; },
			run: function () { return api('menu.procMenuAdminEditorSaveItem', { menu_item_srl: id, menu_name: value }); },
			undo: function () { return api('menu.procMenuAdminEditorSaveItem', { menu_item_srl: rid(id), menu_name: old }); },
			toast: [T('toast_renamed', oldName, value), ''],
			done: function () {
				var nf = find(rid(id));
				if (nf) op.toast = [T('toast_renamed', oldName, nf.item.name), ''];
				flash([id]);
			}
		};
		mutate(op);
	}

	function doMove(id, parent, source, msg) {
		var f = find(id);
		if (!f) return;
		var oldParent = parentKey(f), oldSource = prevSibling(f, null);
		if (String(parent) === String(oldParent) && String(source || 0) === String(oldSource || 0)) return;
		mutate({
			label: T('undo_move', f.item.name),
			run: function () { return api('menu.procMenuAdminMoveItem', moveParams(id, parent, source)); },
			undo: function () { return api('menu.procMenuAdminMoveItem', moveParams(rid(id), rid(oldParent), rid(oldSource))); },
			toast: msg,
			done: function () { UI.focus = { type: 'item', id: id }; flash([id]); }
		});
	}

	function doRemove(id, viaDrag) {
		var f = find(id);
		if (!f) return;
		var it = f.item;
		var hasHome = (function chk(x) { return x.is_home || (x.children || []).some(chk); })(it);
		if (hasHome) { toast(T('remove_home'), '', false); return; }
		if (!viaDrag) {
			var msg = T('remove_confirm', it.name) + (it.children && it.children.length ? '\n' + T('remove_confirm_children', it.children.length) : '');
			if (!window.confirm(msg)) return;
		}
		var parent = parentKey(f), after = prevSibling(f, null);
		var data = JSON.stringify([serialize(it)]);
		mutate({
			label: T('undo_remove', it.name),
			run: function () { return api('menu.procMenuAdminDeleteItem', { menu_item_srl: id, is_force: 'Y', delete_module: 'N' }); },
			undo: function () {
				return api('menu.procMenuAdminEditorRestoreItems', { parent_srl: rid(parent), after_srl: after ? rid(after) : 0, items: data }).then(function (r) {
					if (r.menu_item_srl) REMAP[id] = r.menu_item_srl;
					return r;
				});
			},
			toast: [T('toast_removed', it.name), T('toast_removed_sub')],
			done: function () { UI.focus = { type: 'site' }; UI.pop = null; if (isMobile()) UI.mPane = 'tree'; }
		});
	}

	function doRelinkOrphan(module_srl, parent, after, msg) {
		var o = orphanBySrl(module_srl);
		if (!o) return;
		var newId = 0, snap = null;
		mutate({
			label: T('undo_add', o.name),
			run: function () {
				return api('menu.procMenuAdminEditorRestoreItems', { parent_srl: parent, after_srl: after, items: JSON.stringify([{ name_key: o.name_key, url: o.mid, is_shortcut: 'N', open_window: 'N', expand: 'N', group_srls: '' }]) }).then(function (r) { newId = r.menu_item_srl; snap = r.snapshot; return r; });
			},
			undo: function () { return api('menu.procMenuAdminEditorRevertRows', { snapshot: JSON.stringify(snap || {}) }); },
			toast: msg || [T('toast_relinked', o.name), T('toast_relinked_sub', o.mid)],
			done: function () { if (newId) { select(newId); flash([newId]); } }
		});
	}

	function doRelinkItem(id, parent, after, msg) {
		var f = find(id);
		if (!f || !f.sitemap.unlinked) return;
		var it = f.item, newId = 0, snap = null;
		mutate({
			label: T('undo_add', it.name),
			run: function () {
				return api('menu.procMenuAdminEditorRestoreItems', { parent_srl: parent, after_srl: after, remove_srl: id, items: JSON.stringify([serialize(it)]) }).then(function (r) { newId = r.menu_item_srl; snap = r.snapshot; return r; });
			},
			undo: function () { return api('menu.procMenuAdminEditorRevertRows', { snapshot: JSON.stringify(snap || {}) }); },
			toast: msg || [T('toast_relinked', it.name), T('toast_relinked_sub', it.mid || '')],
			done: function () { if (newId) { select(newId); flash([newId]); } }
		});
	}

	function doDesign(id, kind, type, value) {
		var f = find(id);
		if (!f || !isModule(f.item)) return;
		var it = f.item;
		var params, undoParams, label, toastMsg;
		if (kind === 'layout') {
			var old = layoutValue(it, type);
			if (String(old) === String(value)) return;
			params = { target_module_srl: it.module_srl, skin_type: type, layout_srl: String(value) };
			undoParams = { target_module_srl: it.module_srl, skin_type: type, layout_srl: String(old) };
			label = T('undo_layout', it.name);
			toastMsg = [T('toast_layout', it.name, layoutName(parseInt(value, 10), type).t), ''];
		} else {
			var oldv = skinValue(it, type);
			if (oldv === value) return;
			var mk = function (v) {
				if (v === '') return { target_module_srl: it.module_srl, skin_type: type, is_skin_fix: type === 'M' ? 'Y' : 'N', skin_name: type === 'M' ? '/USE_DEFAULT/' : '', skin_vars: '{}' };
				return { target_module_srl: it.module_srl, skin_type: type, is_skin_fix: 'Y', skin_name: v, skin_vars: '{}' };
			};
			params = mk(value);
			undoParams = mk(oldv);
			label = T('undo_skin', it.name);
			var si = value === '' ? T('skin_site_default') : value === '/USE_RESPONSIVE/' ? T('skin_pc_same') : ((skinInfo(it, type, value) || {}).title || value);
			toastMsg = [T('toast_skin', it.name, si), ''];
		}
		mutate({
			label: label,
			run: function () { return api('module.procModuleAdminSetDesignInfo', params); },
			undo: function () { return api('module.procModuleAdminSetDesignInfo', undoParams); },
			toast: toastMsg,
			done: function () { flash([id]); }
		});
	}

	function seq(list, fn) {
		return list.reduce(function (p, x, i) { return p.then(function () { return fn(x, i); }); }, Promise.resolve());
	}
	function whoValue(type, groups) {
		return type === 'public' ? '' : type === 'member' ? '-1' : type === 'admin' ? '-3' : (groups || []).join(',');
	}
	function whoToast(name, value) {
		var w = whoOf({ group_srls: value });
		if (w.type === 'public') return T('toast_who_public', name);
		if (w.type === 'groups') return T('toast_who_groups', name, groupNames(w.groups).join(', '));
		return T(w.type === 'member' ? 'toast_who_member' : 'toast_who_admin', name);
	}
	function currentExposure(id, fallback) {
		var f = find(rid(id));
		return f ? String(f.item.group_srls || '') : fallback;
	}

	function doWho(id, type, groups) {
		var f = find(id);
		if (!f) return;
		var it = f.item;
		var old = String(it.group_srls || '');
		var value = whoValue(type, groups);
		if (type === 'groups' && !value) { UI.whoDraft = { id: id, w: { type: 'groups', groups: [] } }; render(); return; }
		UI.whoDraft = null;
		if (value === old) { render(); return; }
		var srl = it.module_srl;
		if (isModule(it) && !grantsNow(srl)) {
			loadGrants(srl).then(function () { doWho(id, type, groups); });
			return;
		}
		var list = isModule(it) ? grantsNow(srl) : null;
		var useAuth = !!list && !grantsLocked(list) && scopeOf(it, list) === 'page';
		var oldAccess = useAuth ? ((grantBy(list, 'access') || {}).rows || []).slice() : null;
		var newAccess = whoRows(whoOf({ group_srls: value }));
		var sub = '';
		if (isModule(it) && value !== '') sub = T(useAuth ? 'toast_scope_page' : 'toast_scope_menu');
		delete UI.scope[id];
		var snap = null;
		mutate({
			label: T('undo_who', it.name),
			local: function () { it.group_srls = value; },
			run: function () {
				return snapRows([id]).then(function (s) {
					snap = s;
					if (useAuth) return saveAuth(id, srl, value, { access: newAccess });
					return api('menu.procMenuAdminEditorSaveItem', { menu_item_srl: id, exposure_set: 'Y', exposure: value });
				});
			},
			undo: function () { return restoreRows(snap); },
			toast: [whoToast(it.name, value), sub, (value !== '' && isModule(it) && seoOf(it) && seoOf(it).any) ? { label: T('who_seo_offer'), run: function () { seoToggle(id, false); } } : null],
			done: function () { flash([id]); }
		});
	}

	function doScope(id, scope) {
		var f = find(id);
		if (!f || !isModule(f.item)) return;
		var it = f.item, srl = it.module_srl;
		var list = grantsNow(srl);
		if (!list || grantsLocked(list) || whoOf(it).type === 'public') { UI.scope[id] = scope; render(); return; }
		var now = accessLinked(it, list) ? 'page' : 'menu';
		if (now === scope) { delete UI.scope[id]; render(); return; }
		var oldAccess = ((grantBy(list, 'access') || {}).rows || []).slice();
		var newAccess = scope === 'page' ? whoRows(whoOf(it)) : [];
		delete UI.scope[id];
		var snap = null;
		mutate({
			label: T('undo_who', it.name),
			run: function () { return snapRows([id]).then(function (s) { snap = s; return saveAuth(id, srl, currentExposure(id, it.group_srls), { access: newAccess }); }); },
			undo: function () { return restoreRows(snap); },
			toast: [T(scope === 'page' ? 'toast_scope_page' : 'toast_scope_menu'), ''],
			done: function () { flash([id]); }
		});
	}

	function grantRowsFor(g, type, groups) {
		var rows;
		if (type === 'public') rows = ['0'];
		else if (type === 'member') rows = ['-1'];
		else if (type === 'guest') rows = ['-4'];
		else if (type === 'admin') rows = ['-3'];
		else rows = (groups || []).map(String);
		if (type !== 'groups' && (GRANT_DEFAULT[g.default] || 'public') === type) rows = [];
		return rows;
	}
	function doGrant(id, key, type, groups) {
		var f = find(id);
		if (!f || !isModule(f.item)) return;
		var it = f.item, srl = it.module_srl;
		var list = grantsNow(srl);
		var g = grantBy(list, key);
		if (!g || grantsLocked(list)) return;
		if (type === 'groups' && !(groups || []).length) { UI.grantDraft = { id: id, key: key, groups: [] }; render(); return; }
		UI.grantDraft = null;
		var oldRows = (g.rows || []).slice();
		var newRows = grantRowsFor(g, type, groups);
		if (oldRows.slice().sort().join(',') === newRows.slice().sort().join(',')) { render(); return; }
		var label = grantLabel(type === 'groups' ? { type: 'groups', groups: groups } : { type: type });
		var over = {}, back = {};
		over[key] = newRows;
		back[key] = oldRows;
		var snap = null;
		mutate({
			label: T('undo_grant', it.name),
			run: function () { return snapRows([id]).then(function (s) { snap = s; return saveAuth(id, srl, currentExposure(id, it.group_srls), over); }); },
			undo: function () { return restoreRows(snap); },
			toast: [T('toast_grant', it.name, g.title, label), '']
		});
	}

	function designArgs(srl, kind, type, value) {
		if (kind === 'layout') return { target_module_srl: srl, skin_type: type, layout_srl: String(value) };
		if (value === '') return { target_module_srl: srl, skin_type: type, is_skin_fix: type === 'M' ? 'Y' : 'N', skin_name: type === 'M' ? '/USE_DEFAULT/' : '', skin_vars: '{}' };
		return { target_module_srl: srl, skin_type: type, is_skin_fix: 'Y', skin_name: value, skin_vars: '{}' };
	}
	function doReset(id, kind) {
		var f = find(id);
		if (!f || !isModule(f.item)) return;
		var it = f.item, d = designOf(it);
		var steps = [];
		if (kind === 'layout') {
			if (d.layoutOwn) steps.push({ type: 'P', now: -1, old: it.layout_srl });
			if (d.mOwn) steps.push({ type: 'M', now: -1, old: it.mlayout_srl });
		} else {
			if (d.skinOwn) steps.push({ type: 'P', now: '', old: skinValue(it, 'P') });
			if (d.mskinOwn) steps.push({ type: 'M', now: '', old: skinValue(it, 'M') });
		}
		if (!steps.length) return;
		mutate({
			label: T('undo_reset', it.name),
			run: function () { return seq(steps, function (s) { return api('module.procModuleAdminSetDesignInfo', designArgs(it.module_srl, kind, s.type, s.now)); }); },
			undo: function () { return seq(steps, function (s) { return api('module.procModuleAdminSetDesignInfo', designArgs(it.module_srl, kind, s.type, s.old)); }); },
			toast: kind === 'layout' ? [T('toast_reset_layout', it.name), T('toast_reset_layout_sub')] : [T('toast_reset_skin', it.name), T('toast_reset_skin_sub')],
			done: function () { flash([id]); }
		});
	}

	function linkOk(url) {
		return /^([a-z]+:(\/\/|[a-z0-9_-])|\.{0,2}\/)/i.test(url) && !/^(javascript|vbscript|data):/i.test(url);
	}
	function doLink(id, url) {
		var f = find(id);
		if (!f || f.item.kind !== 'link') return;
		url = String(url || '').trim();
		var old = f.item.url;
		if (url === old) { UI.linkErr = null; return; }
		if (!linkOk(url)) { UI.linkErr = { id: id, value: url }; renderPanel(true); return; }
		UI.linkErr = null;
		mutate({
			label: T('undo_link', f.item.name),
			run: function () { return api('menu.procMenuAdminEditorSaveItem', { menu_item_srl: id, link_url: url }); },
			undo: function () { return api('menu.procMenuAdminEditorSaveItem', { menu_item_srl: rid(id), link_url: old }); },
			toast: [T('toast_link', f.item.name), url],
			done: function () { flash([id]); }
		});
	}
	function doTarget(id, targetId) {
		var f = find(id), tf = find(targetId);
		if (!f || !tf || f.item.kind !== 'shortcut') return;
		var old = targetOf(f.item);
		if (old && old.id === targetId) return;
		mutate({
			label: T('undo_target', f.item.name),
			run: function () { return api('menu.procMenuAdminEditorSaveItem', { menu_item_srl: id, shortcut_target: targetId }); },
			undo: old ? function () { return api('menu.procMenuAdminEditorSaveItem', { menu_item_srl: rid(id), shortcut_target: rid(old.id) }); } : null,
			toast: [T('toast_target', f.item.name, tf.item.name), ''],
			done: function () { flash([id]); }
		});
	}
	function doNewWindow(id) {
		var f = find(id);
		if (!f) return;
		var old = f.item.open_window === 'Y' ? 'Y' : 'N';
		var now = old === 'Y' ? 'N' : 'Y';
		mutate({
			label: T('undo_new_window', f.item.name),
			local: function () { f.item.open_window = now; },
			run: function () { return api('menu.procMenuAdminEditorSaveItem', { menu_item_srl: id, open_window: now }); },
			undo: function () { return api('menu.procMenuAdminEditorSaveItem', { menu_item_srl: rid(id), open_window: old }); },
			toast: [T(now === 'Y' ? 'toast_new_window_on' : 'toast_new_window_off', f.item.name), ''],
			done: function () { flash([id]); }
		});
	}

	function doCopy(id) {
		var f = find(id);
		if (!f || f.sitemap.unlinked) return;
		var created = 0;
		var op = {
			label: T('undo_copy', f.item.name),
			run: function () {
				return api('menu.procMenuAdminCopyItem', { parent_srl: f.parent ? f.parent.id : 0, menu_item_srl: id }).then(function (r) {
					created = parseInt((r.insertedMenuItemSrlList || [])[0], 10) || 0;
					return r;
				});
			},
			undo: function () { return api('menu.procMenuAdminDeleteItem', { menu_item_srl: rid(created), is_force: 'Y', delete_module: 'N' }); },
			done: function () {
				var c = created && find(created);
				if (c) { select(created); flash([created]); }
				toast(T('toast_copied', f.item.name), c && c.item.mid ? T('toast_copied_sub', c.item.mid) : '', true);
			}
		};
		mutate(op);
	}

	function openKind(id) {
		var f = find(id);
		if (!f || !isModule(f.item)) return;
		select(id);
		UI.kc = { id: id, to: null, src: 'new', addr: 'inherit', pick: 0, check: null };
		UI.mPane = 'panel';
		render();
		api('menu.getMenuAdminEditorKindCheck', { menu_item_srl: id }).then(function (r) {
			if (UI.kc && UI.kc.id === id) { UI.kc.check = r; renderPanel(true); }
		}).catch(function (err) { toast(T('toast_failed'), (err && err.message) || T('error_generic'), false); });
	}
	function applyKind() {
		var kc = UI.kc;
		var f = kc && find(kc.id);
		if (!f || !kc.to || !kc.check) return;
		var it = f.item, id = it.id;
		var mode = kc.src === 'pick' ? 'pick' : kc.addr === 'inherit' ? 'inherit' : 'new';
		if (mode === 'pick' && !kc.pick) return;
		var toName = kindTitle(kc.to), oldName = kindLabel(it);
		var msg = T('kc_confirm', it.name, toName);
		if (mode === 'inherit' && kc.check.alias_count) msg += '\n\n' + T('kc_alias_warn', it.mid, kc.check.alias_count);
		if (!window.confirm(msg)) return;
		var res = null;
		var params = { menu_item_srl: id, mode: mode };
		if (mode === 'pick') params.module_srl = kc.pick; else params.module_type = kc.to;
		var op = {
			label: T('undo_kind', it.name),
			run: function () {
				return api('menu.procMenuAdminEditorChangeKind', params).then(function (r) {
					res = r;
					op.toast = [T('toast_kind', it.name, toName), T('toast_kind_sub', toName, r.new_mid, oldName, r.archived_mid)];
					return r;
				});
			},
			undo: function () {
				var back = mode === 'inherit'
					? api('menu.procMenuAdminEditorRevertKind', { menu_item_srl: rid(id), module_srl: res.archived_module_srl })
					: api('menu.procMenuAdminEditorChangeKind', { menu_item_srl: rid(id), mode: 'pick', module_srl: res.archived_module_srl });
				return back.then(function () { return api('menu.procMenuAdminEditorRevertRows', { snapshot: JSON.stringify(res.snapshot || {}) }); });
			},
			done: function () { UI.kc = null; GR = {}; flash([id]); }
		};
		UI.kc = null;
		mutate(op);
	}
	function doRevertKind(itemId, srl) {
		var f = find(itemId);
		var node = null;
		(ST.unlinked.items || []).concat(ST.unlinked.orphans || []).forEach(function (x) { if (x.module_srl === srl) node = x; });
		if (!f || !node) return;
		var cur = f.item, curKind = kindLabel(cur), oldKind = kindLabel(node);
		var res = null;
		var op = {
			label: T('undo_restore', cur.name),
			run: function () {
				return api('menu.procMenuAdminEditorRevertKind', { menu_item_srl: itemId, module_srl: srl }).then(function (r) {
					res = r;
					op.toast = [T('toast_restored', cur.name, oldKind), T('toast_restored_sub', r.restored_mid, curKind, r.parked_mid)];
					return r;
				});
			},
			undo: function () {
				return api('menu.procMenuAdminEditorRevertKind', { menu_item_srl: rid(itemId), module_srl: res.parked_module_srl, park_mid: res.restored_from_mid }).then(function () {
					return api('menu.procMenuAdminEditorRevertRows', { snapshot: JSON.stringify(res.snapshot || {}) });
				});
			},
			done: function () { GR = {}; select(itemId); flash([itemId]); }
		};
		mutate(op);
	}

	/* 사이트맵 */
	function smTitleValue(sm) { return /^\$user_lang->/.test(sm.title_key) ? sm.title_key : sm.title; }
	function doSitemapAdd(name) {
		name = String(name || '').trim();
		if (!name) { UI.smAdd.error = T('sm_name_needed'); render(); return; }
		var created = 0;
		UI.smAdd = null;
		mutate({
			label: T('undo_sm_add', name),
			run: function () { return api('menu.procMenuAdminEditorSaveSitemap', { menu_srl: 0, title: name, domain_srl: ST.site.domain_srl }).then(function (r) { created = parseInt(r.menu_srl, 10) || 0; return r; }); },
			undo: function () { return api('menu.procMenuAdminEditorDeleteSitemap', { menu_srl: rid(created) }); },
			toast: [T('toast_sm_added', name), T('toast_sm_added_sub')],
			done: function () { if (created && sitemapBySrl(created)) { UI.focus = { type: 'sitemap', id: created }; UI.closed['m' + created] = false; } }
		});
	}
	function doSitemapRename(srl, value) {
		var sm = sitemapBySrl(srl);
		value = String(value || '').trim();
		if (!sm || !value) return;
		var old = smTitleValue(sm);
		if (value === old) return;
		mutate({
			label: T('undo_sm_rename', sm.title),
			run: function () { return api('menu.procMenuAdminEditorSaveSitemap', { menu_srl: srl, title: value }); },
			undo: function () { return api('menu.procMenuAdminEditorSaveSitemap', { menu_srl: rid(srl), title: old }); },
			toast: [T('toast_sm_renamed', /^\$user_lang->/.test(value) ? sm.title : value), '']
		});
	}
	function doSitemapDelete(srl) {
		var sm = sitemapBySrl(srl);
		if (!sm || sm.items.length) return;
		if (!window.confirm(T('sm_delete_confirm', sm.title))) return;
		var title = smTitleValue(sm);
		var res = null;
		var op = {
			label: T('undo_sm_delete', sm.title),
			run: function () {
				return api('menu.procMenuAdminEditorDeleteSitemap', { menu_srl: rid(srl) }).then(function (r) {
					res = r;
					var n = (r.unbound || []).length;
					op.toast = [T('toast_sm_deleted', sm.title), n ? T('toast_sm_deleted_sub', n) : ''];
					return r;
				});
			},
			undo: function () {
				return api('menu.procMenuAdminEditorSaveSitemap', { menu_srl: 0, title: title, domain_srl: res && res.domain_srl ? res.domain_srl : 0 }).then(function (r) {
					var made = parseInt(r.menu_srl, 10) || 0;
					if (made) REMAP[srl] = made;
					return seq((res && res.unbound) || [], function (s) { return api('menu.procMenuAdminEditorBindSlot', { layout_srl: s.layout_srl, slot: s.slot, menu_srl: made }); });
				});
			},
			done: function () { UI.focus = { type: 'site' }; }
		};
		mutate(op);
	}
	function doSitemapMove(srl, domain_srl) {
		var sm = sitemapBySrl(srl);
		if (!sm) return;
		var old = sm.domain_srl || 0;
		if (old === domain_srl) return;
		var to = null;
		ST.sites.forEach(function (s) { if (s.domain_srl === domain_srl) to = s; });
		if (!to) return;
		var op = {
			label: T('undo_sm_move', sm.title),
			run: function () { return api('menu.procMenuAdminEditorSetSitemapSite', { menu_srl: rid(srl), domain_srl: domain_srl }); },
			undo: function () { return api('menu.procMenuAdminEditorSetSitemapSite', { menu_srl: rid(srl), domain_srl: old }); },
			toast: [T('toast_sm_moved', sm.title, to.title), ''],
			done: function () {
				var still = !!sitemapBySrl(rid(srl));
				op.toast = [T('toast_sm_moved', sm.title, to.title), T(still ? 'toast_sm_moved_stay' : 'toast_sm_moved_sub', to.title)];
				if (!still && UI.focus.type === 'sitemap') UI.focus = { type: 'site' };
			}
		};
		mutate(op);
	}

	/* 여러 개 */
	function multiClick(id, range) {
		var base = (UI.focus.type === 'item' || UI.focus.type === 'multi') ? UI.sel.filter(function (x) { return find(x) && !find(x).sitemap.unlinked; }) : [];
		var sel;
		if (range && UI.anchor && find(UI.anchor)) {
			var order = Array.prototype.map.call(ROOT.querySelectorAll('.zse-tree .row[data-act="row"]'), function (r) { return parseInt(r.getAttribute('data-id'), 10); })
				.filter(function (x) { return find(x) && !find(x).sitemap.unlinked; });
			var i = order.indexOf(UI.anchor), j = order.indexOf(id);
			sel = (i < 0 || j < 0) ? [id] : order.slice(Math.min(i, j), Math.max(i, j) + 1);
		} else {
			sel = base.slice();
			var at = sel.indexOf(id);
			if (at >= 0) sel.splice(at, 1); else sel.push(id);
			UI.anchor = id;
		}
		UI.sel = sel;
		UI.kc = null; UI.inline = null; UI.renaming = null; UI.bulkWho = null; UI.whoDraft = null; UI.grantDraft = null;
		UI.focus = sel.length > 1 ? { type: 'multi' } : sel.length ? { type: 'item', id: sel[0] } : { type: 'site' };
	}
	function treeOrder(items) {
		var order = itemsOfSite().map(function (x) { return x.id; });
		return items.slice().sort(function (a, b) { return order.indexOf(a.id) - order.indexOf(b.id); });
	}
	function bulkDone(items) {
		return function () { flash(items.map(function (x) { return x.id; })); };
	}
	function doBulkLayout(value) {
		var items = selectedItems().filter(isModule);
		var type = UI.dtab === 'M' && ST.mobile_enabled ? 'M' : 'P';
		var olds = items.map(function (x) { return layoutValue(x, type); });
		if (!items.length || olds.every(function (v) { return v === value; })) return;
		mutate({
			label: T('undo_bulk', items.length),
			run: function () { return seq(items, function (x) { return api('module.procModuleAdminSetDesignInfo', designArgs(x.module_srl, 'layout', type, value)); }); },
			undo: function () { return seq(items, function (x, i) { return api('module.procModuleAdminSetDesignInfo', designArgs(x.module_srl, 'layout', type, olds[i])); }); },
			toast: [T('toast_bulk_layout', items.length, layoutName(value, type).t), T('toast_bulk_sub', items.map(function (x) { return x.name; }).join(', '))],
			done: bulkDone(items)
		});
	}
	function doBulkSkin(value) {
		var items = selectedItems().filter(isModule);
		if (!items.length) return;
		var type = UI.dtab === 'M' && ST.mobile_enabled && skinsOf(items[0]).M.length ? 'M' : 'P';
		var olds = items.map(function (x) { return skinValue(x, type); });
		if (olds.every(function (v) { return v === value; })) return;
		var nm = value === '' ? T('skin_site_default') : value === '/USE_RESPONSIVE/' ? T('skin_pc_same') : ((skinInfo(items[0], type, value) || {}).title || value);
		mutate({
			label: T('undo_bulk', items.length),
			run: function () { return seq(items, function (x) { return api('module.procModuleAdminSetDesignInfo', designArgs(x.module_srl, 'skin', type, value)); }); },
			undo: function () { return seq(items, function (x, i) { return api('module.procModuleAdminSetDesignInfo', designArgs(x.module_srl, 'skin', type, olds[i])); }); },
			toast: [T('toast_bulk_skin', items.length, nm), T('toast_bulk_sub', items.map(function (x) { return x.name; }).join(', '))],
			done: bulkDone(items)
		});
	}
	function doBulkWho(type, groups) {
		var items = selectedItems();
		if (!items.length) return;
		if (type === 'groups' && !(groups || []).length) { UI.bulkWho = { type: 'groups', groups: [] }; render(); return; }
		var mods = items.filter(isModule);
		var need = UI.bulkScope === 'page' ? mods.filter(function (x) { return !grantsNow(x.module_srl); }) : [];
		if (need.length) {
			Promise.all(need.map(function (x) { return loadGrants(x.module_srl); })).then(function () { doBulkWho(type, groups); });
			return;
		}
		var value = whoValue(type, groups);
		var plan = items.map(function (x) {
			var list = isModule(x) ? grantsNow(x.module_srl) : null;
			var auth = UI.bulkScope === 'page' && !!list && !grantsLocked(list);
			return { id: x.id, srl: x.module_srl, old: String(x.group_srls || ''), auth: auth, oldAccess: auth ? ((grantBy(list, 'access') || {}).rows || []).slice() : null };
		});
		if (plan.every(function (p) { return p.old === value; }) && !plan.some(function (p) { return p.auth && !sameRows(p.oldAccess, whoRows(whoOf({ group_srls: value }))); })) return;
		UI.bulkWho = type === 'groups' ? { type: 'groups', groups: groups.slice() } : null;
		var newAccess = whoRows(whoOf({ group_srls: value }));
		var w = whoOf({ group_srls: value });
		var label = w.type === 'groups' ? groupNames(w.groups).join(', ') : T({ public: 'who_public', member: 'who_member', admin: 'who_admin' }[w.type]);
		var snap = null;
		mutate({
			label: T('undo_bulk', items.length),
			run: function () {
				return snapRows(plan.map(function (p) { return p.id; })).then(function (s) {
					snap = s;
					return seq(plan, function (p) {
						if (p.auth) return saveAuth(p.id, p.srl, value, { access: newAccess });
						return api('menu.procMenuAdminEditorSaveItem', { menu_item_srl: p.id, exposure_set: 'Y', exposure: value });
					});
				});
			},
			undo: function () { return restoreRows(snap); },
			toast: [T('toast_bulk_who', items.length, label), T('toast_bulk_sub', items.map(function (x) { return x.name; }).join(', '))],
			done: bulkDone(items)
		});
	}
	function topSelected() {
		var items = selectedItems();
		return items.filter(function (x) { return !items.some(function (o) { return o.id !== x.id && isDescendant(o.id, x.id); }); });
	}
	function doBulkRemove() {
		var all = treeOrder(topSelected());
		var hasHome = function (x) { return x.is_home || (x.children || []).some(hasHome); };
		var items = all.filter(function (x) { return !hasHome(x); });
		var skipped = all.length - items.length;
		if (!items.length) { toast(T('remove_home'), '', false); return; }
		if (!window.confirm(T('multi_remove_confirm', items.length))) return;
		var removedIds = {};
		var plan = items.map(function (x) {
			var f = find(x.id);
			var siblings = f.list.filter(function (s) { return !removedIds[s.id]; });
			var i = siblings.indexOf(x);
			removedIds[x.id] = true;
			return { id: x.id, parent: parentKey(f), after: i > 0 ? siblings[i - 1].id : 0, data: JSON.stringify([serialize(x)]) };
		});
		mutate({
			label: T('undo_bulk', items.length),
			run: function () { return seq(plan, function (p) { return api('menu.procMenuAdminDeleteItem', { menu_item_srl: p.id, is_force: 'Y', delete_module: 'N' }); }); },
			undo: function () {
				return seq(plan.slice().reverse(), function (p) {
					return api('menu.procMenuAdminEditorRestoreItems', { parent_srl: rid(p.parent), after_srl: p.after ? rid(p.after) : 0, items: p.data }).then(function (r) {
						if (r.menu_item_srl) REMAP[p.id] = r.menu_item_srl;
						return r;
					});
				});
			},
			toast: [T('toast_bulk_removed', items.length), skipped ? T('multi_home_skip') : T('toast_removed_sub')],
			done: function () { UI.sel = []; UI.focus = { type: 'site' }; }
		});
	}

	function doMid(id, value) {
		var f = find(id);
		if (!f || !f.item.mid) return;
		var old = f.item.mid;
		if (!value || value === old) return;
		mutate({
			label: T('undo_mid', f.item.name),
			run: function () { return api('menu.procMenuAdminEditorSaveItem', { menu_item_srl: id, new_mid: value }); },
			undo: function () { return api('menu.procMenuAdminEditorSaveItem', { menu_item_srl: rid(id), new_mid: old }); },
			toast: [T('toast_mid', f.item.name, value), ''],
			done: function () { UI.midCheck = null; flash([id]); }
		});
	}

	function doBindSlot(layout_srl, slot, menu_srl) {
		var Lx = layoutBySrl(layout_srl, 'P');
		if (!Lx) return;
		var s = null;
		Lx.slots.forEach(function (x) { if (x.id === slot) s = x; });
		if (!s) return;
		var oldRaw = s.menu_srl || 0;
		var prevSm = sitemapBySrl(s.resolved_menu_srl || 0);
		var newSm = sitemapBySrl(menu_srl);
		var msg;
		if (newSm) msg = [T('toast_slot_bound', newSm.title, Lx.title, s.title), prevSm ? T('toast_slot_bound_sub', prevSm.title) : ''];
		else msg = [T('toast_slot_unbound', Lx.title, s.title), prevSm ? (prevSm.slots.length > 1 ? T('toast_slot_unbound_sub', prevSm.title) : T('toast_slot_unbound_last', prevSm.title)) : ''];
		mutate({
			label: T('undo_slot', Lx.title, s.title),
			run: function () { return api('menu.procMenuAdminEditorBindSlot', { layout_srl: layout_srl, slot: slot, menu_srl: menu_srl }); },
			undo: function () { return api('menu.procMenuAdminEditorBindSlot', { layout_srl: layout_srl, slot: slot, menu_srl: oldRaw }); },
			toast: msg
		});
	}

	function addInline() {
		var a = UI.inline;
		if (!a) return;
		var raw = a.name.trim();
		var k = kindOfType(a.kind);
		if (!raw) { a.error = T('ia_name_needed'); render(); return; }
		var parent = a.parent ? a.parent : a.g;
		var params = { parent_srl: parent, menu_open_window: 'N', menu_expand: 'N', menu_name_key: '' };
		var name = raw, url = '';
		if (k === 'link') {
			var m = raw.match(/^(.*?)\s+((?:https?:\/\/|\/)\S+)$/i);
			if (!m || !m[1].trim()) { a.error = T('ia_link_needed'); render(); return; }
			name = m[1].trim(); url = m[2];
			if (name.length > 250) { a.error = T('name_too_long'); render(); return; }
			params.is_shortcut = 'Y'; params.shortcut_target = url;
		} else if (k === 'folder') {
			params.is_shortcut = 'Y'; params.shortcut_target = '';
		} else if (k === 'shortcut') {
			if (!a.target || !find(a.target)) { a.error = T('shortcut_pick'); render(); return; }
			params.is_shortcut = 'Y'; params.shortcut_target = String(a.target);
		} else {
			params.is_shortcut = 'N'; params.module_type = a.kind;
		}
		params.menu_name = name;
		a.error = '';
		var created = 0, mid = '';
		var run = function () {
			var pre = (k === 'link' || k === 'folder' || k === 'shortcut') ? Promise.resolve() : api('menu.getMenuAdminEditorCheckMid', { target_mid: suggestMid(name, a.kind) }).then(function (r) {
				mid = r.suggestion || suggestMid(name, a.kind);
				params.module_id = mid;
			});
			return pre.then(function () { return api('menu.procMenuAdminInsertItem', params); }).then(function (r) {
				created = r.menu_item_srl;
				if (mid && ST.options && ST.options.scope === 'site') return api('menu.procMenuAdminEditorSetModuleSite', { target_mid: mid, domain_srl: ST.site.domain_srl }).then(function () { return r; });
				return r;
			});
		};
		a.name = '';
		UI.inlineFocus = true;
		mutate({
			label: T('undo_add', name),
			run: run,
			undo: function () { return api('menu.procMenuAdminDeleteItem', { menu_item_srl: rid(created), is_force: 'Y', delete_module: 'N' }); },
			done: function () {
				if (created) flash([created]);
				if (UI.inline && UI.inline.parent) UI.closed[UI.inline.parent] = false;
				toast(T(k === 'link' || k === 'folder' || k === 'shortcut' ? 'toast_added_link' : 'toast_added', name, kindTitle(a.kind)), mid ? T('toast_added_sub', mid) + (ST.options && ST.options.scope === 'site' ? ' ' + T('toast_added_scope') : '') : '', true);
			}
		});
	}

	function flash(ids) {
		UI.flash = ids;
		setTimeout(function () { UI.flash = []; ROOT.querySelectorAll('.row.flash').forEach(function (r) { r.classList.remove('flash'); }); }, 2200);
	}

	function moveBy(id, dir) {
		var f = find(id);
		if (!f || f.sitemap.unlinked) return;
		var list = f.list, i = list.indexOf(f.item);
		var parent = parentKey(f);
		if (dir === 'up') {
			if (i <= 0) return cannot();
			doMove(id, parent, i >= 2 ? list[i - 2].id : 0, [T('toast_moved', f.item.name), '']);
		} else if (dir === 'down') {
			if (i >= list.length - 1) return cannot();
			doMove(id, parent, list[i + 1].id, [T('toast_moved', f.item.name), '']);
		} else if (dir === 'in') {
			if (i <= 0) return cannot();
			var prev = list[i - 1];
			var kids = prev.children || [];
			doMove(id, prev.id, kids.length ? kids[kids.length - 1].id : 0, [T('toast_moved_into', f.item.name, prev.name), '']);
		} else if (dir === 'out') {
			if (!f.parent) return cannot();
			var pf = find(f.parent.id);
			doMove(id, parentKey(pf), f.parent.id, [T('toast_moved_out', f.item.name), '']);
		}
		function cannot() { toast(T('toast_cannot_move'), '', false); }
	}

	function startRename(id) {
		var f = find(id);
		if (!f || f.sitemap.unlinked) return;
		if (f.item.is_lang) {
			select(id);
			render();
			var n = ROOT.querySelector('.zse-panel .g11n input:not([type=hidden])') || document.getElementById('zse-f-name');
			if (n) n.focus();
			return;
		}
		UI.renaming = id;
		UI.pop = null;
		render();
	}

	function checkMidLater(id, value) {
		clearTimeout(checkMidLater.t);
		UI.midCheck = { id: id, value: value, state: 'checking' };
		checkMidLater.t = setTimeout(function () {
			var f = find(id);
			if (!f) return;
			if (value === f.item.mid) { UI.midCheck = null; renderPanelHint(); return; }
			api('menu.getMenuAdminEditorCheckMid', { target_mid: value, current_mid: f.item.mid }).then(function (r) {
				if (!UI.midCheck || UI.midCheck.value !== value) return;
				UI.midCheck = r.reason ? { id: id, value: value, state: 'bad', reason: r.reason, suggestion: r.suggestion } : { id: id, value: value, state: 'ok' };
				renderPanelHint();
			}).catch(function () { });
		}, 350);
	}
	function renderPanelHint() {
		var input = document.getElementById('zse-f-mid');
		var pos = input ? input.selectionStart : 0;
		var had = input && document.activeElement === input;
		renderPanel(true);
		var again = document.getElementById('zse-f-mid');
		if (again && had) { again.focus(); try { again.setSelectionRange(pos, pos); } catch (e) { } }
	}

	/* ── 이벤트 ─────────────────────────── */
	function onClick(e) {
		var el = e.target.closest('[data-act]');
		var inPop = e.target.closest('.pop');
		if (UI.pop && !inPop && !(el && ['row-menu', 'ia-kind', 'help', 'legacy', 'm-sites', 'sm-menu', 'ov-row'].indexOf(el.getAttribute('data-act')) >= 0)) {
			UI.pop = null;
			renderLayer();
		}
		if (!el || !ROOT.contains(el)) return;
		var act = el.getAttribute('data-act');
		var v = el.getAttribute('data-v');
		var id = parseInt(el.getAttribute('data-id'), 10) || 0;
		if (el.tagName === 'A') return;
		switch (act) {
			case 'site':
				var ds = parseInt(v, 10);
				UI.pop = null;
				if (ds === ST.site.domain_srl) { render(); return; }
				UI.focus = { type: 'site' }; UI.inline = null; UI.search = ''; UI.sel = []; UI.kc = null; UI.smAdd = null; GR = {}; UI.themeCand = ''; UI.prefixDraft = null; UI.prefixErr = false;
				saveUndoStack();
				api('menu.getMenuAdminEditorState', { domain_srl: ds }).then(function (d) { setState(d.state); loadUndoStack(); lastFrameUrl = ''; render(); });
				return;
			case 'focus-site': UI.focus = { type: 'site' }; UI.inline = null; if (isMobile()) UI.mPane = 'panel'; break;
			case 'row':
				if (e.target.closest('.acts') || e.target.closest('.tw') || e.target.closest('input')) return;
				if ((e.ctrlKey || e.metaKey || e.shiftKey) && !isMobile() && find(id) && !find(id).sitemap.unlinked) { multiClick(id, e.shiftKey); break; }
				select(id);
				break;
			case 'chip':
				select(id); UI.pick = v; render();
				var ds2 = document.getElementById('zse-sec-design'); if (ds2) ds2.scrollIntoView({ block: 'nearest' });
				return;
			case 'sm-add': UI.smAdd = { name: '', error: '' }; UI.smAddFocus = true; UI.inline = null; break;
			case 'sm-delete': doSitemapDelete(parseInt(el.getAttribute('data-g'), 10)); return;
			case 'sm-menu': var smg = parseInt(el.getAttribute('data-g'), 10); UI.pop = UI.pop && UI.pop.type === 'sm-menu' && UI.pop.g === smg ? null : { type: 'sm-menu', g: smg, anchor: 'sm-' + smg, focus: true }; renderLayer(); return;
			case 'sm-move': UI.pop = null; doSitemapMove(parseInt(el.getAttribute('data-g'), 10), parseInt(v, 10)); return;
			case 'select-group':
				var sg = sitemapBySrl(parseInt(el.getAttribute('data-g'), 10));
				if (!sg) return;
				var sids = []; (function walk(list) { list.forEach(function (x) { sids.push(x.id); walk(x.children || []); }); })(sg.items);
				if (!sids.length) return;
				UI.sel = sids; UI.anchor = sids[0]; UI.kc = null; UI.bulkWho = null; UI.closed['m' + sg.menu_srl] = false;
				UI.focus = sids.length > 1 ? { type: 'multi' } : { type: 'item', id: sids[0] };
				break;
			case 'new-window': doNewWindow(id); return;
			case 'reset-layout': if (UI.focus.type === 'item') doReset(UI.focus.id, 'layout'); return;
			case 'reset-skin': if (UI.focus.type === 'item') doReset(UI.focus.id, 'skin'); return;
			case 'set-scope': if (UI.focus.type === 'item') doScope(UI.focus.id, v); return;
			case 'grant-group':
				if (UI.focus.type !== 'item') return;
				var gkey = el.getAttribute('data-key');
				var glist = grantsNow(find(UI.focus.id).item.module_srl);
				var gg = grantBy(glist, gkey);
				if (!gg) return;
				var gcur = UI.grantDraft && UI.grantDraft.id === UI.focus.id && UI.grantDraft.key === gkey ? UI.grantDraft.groups.slice() : grantValue(gg).groups.slice();
				var gid2 = parseInt(v, 10);
				var gat = gcur.indexOf(gid2);
				if (gat >= 0) gcur.splice(gat, 1); else gcur.push(gid2);
				gcur.sort(function (a, b) { return a - b; });
				doGrant(UI.focus.id, gkey, 'groups', gcur);
				return;
			case 'copy': UI.pop = null; doCopy(id); return;
			case 'kind-open': UI.pop = null; openKind(id); return;
			case 'kc-to': if (UI.kc) { UI.kc.to = v; UI.kc.pick = 0; } break;
			case 'kc-src': if (UI.kc) { UI.kc.src = v; UI.kc.pick = 0; } break;
			case 'kc-addr': if (UI.kc) UI.kc.addr = v; break;
			case 'kc-pick': if (UI.kc) UI.kc.pick = parseInt(v, 10) || 0; break;
			case 'kc-cancel': UI.kc = null; break;
			case 'kc-apply': applyKind(); return;
			case 'restore-kind': doRevertKind(id, parseInt(el.getAttribute('data-srl'), 10)); return;
			case 'bulk-layout': doBulkLayout(parseInt(v, 10)); return;
			case 'bulk-skin': doBulkSkin(v || ''); return;
			case 'bulk-who': if (v === 'groups') { UI.bulkWho = { type: 'groups', groups: [] }; break; } UI.bulkWho = null; doBulkWho(v, []); return;
			case 'bulk-who-group':
				var bg = UI.bulkWho && UI.bulkWho.type === 'groups' ? UI.bulkWho.groups.slice() : [];
				var bgid = parseInt(v, 10), bat = bg.indexOf(bgid);
				if (bat >= 0) bg.splice(bat, 1); else bg.push(bgid);
				bg.sort(function (a, b) { return a - b; });
				UI.bulkWho = { type: 'groups', groups: bg };
				if (bg.length) { doBulkWho('groups', bg); return; }
				break;
			case 'bulk-scope': UI.bulkScope = v === 'menu' ? 'menu' : 'page'; break;
			case 'clear-sel': UI.sel = []; UI.focus = { type: 'site' }; UI.bulkWho = null; break;
			case 'remove-sel': doBulkRemove(); return;
			case 'orphan':
				UI.focus = { type: 'orphan', id: parseInt(el.getAttribute('data-srl'), 10) }; UI.inline = null; if (isMobile()) UI.mPane = 'panel';
				break;
			case 'sitemap':
				if (e.target.closest('.gacts') || e.target.closest('.tw')) return;
				UI.focus = { type: 'sitemap', id: parseInt(el.getAttribute('data-g'), 10) }; UI.inline = null; UI.slotPick = false; UI.pop = null; if (isMobile()) UI.mPane = 'panel';
				break;
			case 'toggle-group': var gk = 'm' + el.getAttribute('data-g'); UI.closed[gk] = !UI.closed[gk]; break;
			case 'toggle-item': UI.closed[id] = !UI.closed[id]; break;
			case 'toggle-unl': UI.closed.unl = UI.closed.unl === false ? true : false; break;
			case 'inline-add':
				var prev = UI.inline;
				var types = addableTypes();
				var defKind = prev ? prev.kind : (types.modules.indexOf('board') >= 0 ? 'board' : (types.pages[0] || 'link'));
				UI.inline = { g: parseInt(el.getAttribute('data-g'), 10), parent: parseInt(el.getAttribute('data-parent'), 10) || 0, kind: defKind, name: '', error: '' };
				if (UI.inline.parent) UI.closed[UI.inline.parent] = false;
				UI.closed['m' + UI.inline.g] = false;
				UI.search = '';
				UI.focus = { type: 'inline' }; UI.pop = null; UI.renaming = null; UI.mPane = 'tree'; UI.inlineFocus = true;
				break;
			case 'ia-kind': UI.pop = UI.pop && UI.pop.type === 'ia-kind' ? null : { type: 'ia-kind', anchor: 'ia-kind', focus: true }; renderLayer(); return;
			case 'ia-setkind': if (UI.inline) { UI.inline.kind = v; UI.inline.error = ''; } UI.pop = null; UI.inlineFocus = true; break;
			case 'row-menu': select(id); UI.pop = { type: 'row-menu', id: id, anchor: 'rm-' + id, focus: true }; break;
			case 'rename': startRename(id); return;
			case 'row-who': UI.pop = null; select(id); if (v === 'groups') { UI.whoDraft = { id: id, w: { type: 'groups', groups: whoOf(find(id).item).groups } }; render(); var sw = document.getElementById('zse-sec-who'); if (sw) sw.scrollIntoView({ block: 'nearest' }); return; } doWho(id, v); break;
			case 'set-who':
				if (UI.focus.type !== 'item') return;
				if (v === 'groups') { var cw = whoOf(find(UI.focus.id).item); UI.whoDraft = { id: UI.focus.id, w: { type: 'groups', groups: cw.type === 'groups' ? cw.groups : [] } }; break; }
				doWho(UI.focus.id, v);
				return;
			case 'who-group':
				if (UI.focus.type !== 'item') return;
				var cur = UI.whoDraft && UI.whoDraft.id === UI.focus.id ? UI.whoDraft.w.groups.slice() : whoOf(find(UI.focus.id).item).groups.slice();
				var gid = parseInt(v, 10);
				var at = cur.indexOf(gid);
				if (at >= 0) cur.splice(at, 1); else cur.push(gid);
				cur.sort(function (a, b) { return a - b; });
				if (!cur.length) { UI.whoDraft = { id: UI.focus.id, w: { type: 'groups', groups: [] } }; doWho(UI.focus.id, 'public'); return; }
				UI.whoDraft = { id: UI.focus.id, w: { type: 'groups', groups: cur } };
				doWho(UI.focus.id, 'groups', cur);
				return;
			case 'remove': UI.pop = null; doRemove(id || (UI.focus.type === 'item' ? UI.focus.id : 0)); return;
			case 'relink':
				var gsrl = parseInt(el.getAttribute('data-g'), 10);
				var sm = sitemapBySrl(gsrl);
				var last = sm && sm.items.length ? sm.items[sm.items.length - 1].id : 0;
				if (el.getAttribute('data-srl')) {
					doRelinkOrphan(parseInt(el.getAttribute('data-srl'), 10), gsrl, last ? last : -1);
				} else {
					var fu = find(id);
					doRelinkItem(id, gsrl, last ? last : -1, [T('toast_relinked', fu ? fu.item.name : ''), T('toast_relinked_sub', fu ? fu.item.mid : '')]);
				}
				return;
			case 'pick': UI.pick = UI.pick === v ? null : v; break;
			case 'dtab': UI.dtab = v; break;
			case 'set-layout': if (UI.focus.type === 'item') doDesign(UI.focus.id, 'layout', UI.dtab === 'M' && ST.mobile_enabled ? 'M' : 'P', parseInt(v, 10)); return;
			case 'set-skin': if (UI.focus.type === 'item') { var fit = find(UI.focus.id); doDesign(UI.focus.id, 'skin', UI.dtab === 'M' && ST.mobile_enabled && fit && skinsOf(fit.item).M.length ? 'M' : 'P', v || ''); } return;
			case 'mid-use': var mi = document.getElementById('zse-f-mid'); if (mi && UI.focus.type === 'item') { mi.value = v; checkMidLater(UI.focus.id, v); } return;
			case 'mid-save': if (UI.focus.type === 'item' && UI.midCheck && UI.midCheck.state === 'ok') doMid(UI.focus.id, UI.midCheck.value); return;
			case 'goto': select(id); var gr = ROOT.querySelector('.row[data-id="' + id + '"]'); render(); gr = ROOT.querySelector('.row[data-id="' + id + '"]'); if (gr) gr.scrollIntoView({ block: 'nearest' }); return;
			case 'slot-pick': UI.slotPick = !UI.slotPick; break;
			case 'bind-slot': doBindSlot(parseInt(el.getAttribute('data-layout'), 10), el.getAttribute('data-slot'), parseInt(el.getAttribute('data-g'), 10) || 0); return;
			case 'collap': UI.open[v] = !UI.open[v]; break;
			case 'device': UI.device = v; break;
			case 'pv-reload': reloadFrame(); return;
			case 'toggle-preview': UI.preview = !UI.preview; if (!UI.preview) { ROOT.querySelector('.zse-pv').innerHTML = ''; lastFrameUrl = ''; } savePref(); break;
			case 'undo': UI.pop = null; undo(); return;
			case 'retry': retry(); return;
			case 'toast-x': UI.toast = null; renderLayer(); return;
			case 'help': UI.pop = UI.pop && UI.pop.type === 'help' ? null : { type: 'help', anchor: 'help', focus: true }; renderLayer(); return;
			case 'legacy': UI.pop = UI.pop && UI.pop.type === 'legacy' ? null : { type: 'legacy', anchor: 'legacy', focus: true }; renderLayer(); return;
			case 'm-sites': UI.pop = UI.pop && UI.pop.type === 'sites' ? null : { type: 'sites', anchor: 'm-sites', focus: true }; renderLayer(); return;
			case 'm-back': UI.mPane = 'tree'; break;
			case 'tour-start': UI.pop = null; UI.tour = 1; break;
			case 'tour-next': UI.tour = UI.tour >= 3 ? 0 : UI.tour + 1; if (!UI.tour) tourDone(); break;
			case 'tour-skip': UI.tour = 0; tourDone(); break;
			case 'mv': UI.pop = null; moveBy(id, v); return;
			case 'view':
				UI.pop = null;
				if (v === 'overview') { UI.view = 'overview'; OV = null; } else UI.view = 'edit';
				if (UI.view === 'edit') { lastFrameUrl = ''; ROOT.querySelector('.zse-pv').innerHTML = ''; }
				break;
			case 'ov-open':
				var od = parseInt(v, 10);
				UI.view = 'edit'; UI.pop = null; lastFrameUrl = ''; ROOT.querySelector('.zse-pv').innerHTML = '';
				if (od !== ST.site.domain_srl) {
					saveUndoStack();
					UI.focus = { type: 'site' }; UI.sel = []; UI.kc = null; GR = {};
					api('menu.getMenuAdminEditorState', { domain_srl: od }).then(function (d) { setState(d.state); loadUndoStack(); render(); });
					return;
				}
				break;
			case 'ov-row': UI.pop = UI.pop && UI.pop.type === 'ov-row' && UI.pop.id === id ? null : { type: 'ov-row', id: id, anchor: 'ov-' + id, focus: true }; renderLayer(); return;
			case 'ov-copy': UI.pop = null; doCopyToSite(id, parseInt(v, 10)); return;
			case 'ov-move': UI.pop = null; doMoveToSite(id, parseInt(v, 10)); return;
			case 'wizard':
				UI.pop = null;
				UI.wz = { step: 1, domain: '', name: '', prefix: '', prefixTouched: false, scope: true, theme: '', starter: 'company', touched: false, busy: false, error: '' };
				UI.wzFocus = true;
				break;
			case 'wz-close': if (UI.wz && !UI.wz.busy) UI.wz = null; break;
			case 'wz-go': if (UI.wz && !UI.wz.busy) wzGo(parseInt(v, 10)); return;
			case 'wz-scope': if (UI.wz) UI.wz.scope = !UI.wz.scope; renderLayer(); return;
			case 'wz-theme': if (UI.wz) UI.wz.theme = v || ''; renderLayer(); return;
			case 'wz-starter': if (UI.wz) UI.wz.starter = v; renderLayer(); return;
			case 'wz-create': createSite(); return;
			case 'open-theme': UI.focus = { type: 'theme' }; UI.themeCand = ''; UI.themeAlsoOwn = false; UI.inline = null; if (isMobile()) UI.mPane = 'panel'; break;
			case 'theme-cand': UI.themeCand = UI.themeCand === v ? '' : v; break;
			case 'theme-also-own': UI.themeAlsoOwn = !UI.themeAlsoOwn; break;
			case 'theme-cancel': UI.focus = { type: 'site' }; UI.themeCand = ''; break;
			case 'theme-apply': applyTheme(); return;
			case 'seo-toggle': UI.pop = null; seoToggle(id || (UI.focus.type === 'item' ? UI.focus.id : 0)); return;
			case 'seo-key':
				if (UI.focus.type !== 'item') return;
				var sf = find(UI.focus.id), sso = sf && seoOf(sf.item);
				if (!sso) return;
				doSeo(UI.focus.id, seoKeyTarget(sso, v), [T('toast_seo_key', sf.item.name, T('term_seo_' + (v === 'sd' ? 'sd' : v) + '_t')), '']);
				return;
			case 'seo-reset':
				if (UI.focus.type !== 'item') return;
				var rf = find(UI.focus.id), rso = rf && seoOf(rf.item);
				if (rso) doSeo(UI.focus.id, seoDefault(rso), [T('toast_seo_reset', rf.item.name), '']);
				return;
			case 'bulk-seo': doBulkSeo(v); return;
			case 'sm-seo': UI.pop = null; var sg2 = sitemapBySrl(parseInt(el.getAttribute('data-g'), 10)); if (sg2) doSitemapSeo(sg2.menu_srl, !sg2.seo_off); return;
			case 'hidden-toggle': UI.pop = null; doHidden(id); return;
			case 'scope-toggle': var scf = find(id); if (scf && isModule(scf.item)) doModuleSite(id, scf.item.domain_srl === ST.site.domain_srl ? -1 : ST.site.domain_srl); return;
			case 'scope-new':
				var oo = ST.options || { prefix: '', scope: 'all' };
				var ns = oo.scope === 'site' ? 'all' : 'site';
				doOptions({ domain_srl: ST.site.domain_srl, scope: ns }, { domain_srl: ST.site.domain_srl, scope: oo.scope }, [T(ns === 'site' ? 'toast_scope_new_on' : 'toast_scope_new_off', ST.site.domain), '']);
				return;
			case 'sitemap-split':
				var spd = !!(ST.options && ST.options.sitemap_per_domain);
				doOptions({ sitemap_per_domain: spd ? 'N' : 'Y' }, { sitemap_per_domain: spd ? 'Y' : 'N' }, [T(spd ? 'toast_split_off' : 'toast_split_on'), T(spd ? 'toast_split_off_sub' : 'toast_split_on_sub')]);
				return;
			case 'toast-action':
				var ta = UI.toast && UI.toast.action;
				UI.toast = null; renderLayer();
				if (ta && ta.run) ta.run();
				return;
			default: return;
		}
		render();
		if (act === 'row' && !isMobile()) {
			var focusRow = ROOT.querySelector('.zse-tree .row.is-sel[data-id="' + id + '"]');
			if (focusRow) focusRow.focus({ preventScroll: true });
		}
	}

	function onKey(e) {
		var t = e.target;
		if (UI.wz) {
			if (e.key === 'Escape' && !UI.wz.busy) { UI.wz = null; render(); }
			else if (e.key === 'Enter' && !e.isComposing && t.getAttribute && t.getAttribute('data-wz')) { e.preventDefault(); wzGo(UI.wz.step + 1); }
			return;
		}
		if (t.id === 'zse-ia') {
			if (e.key === 'Enter' && !e.isComposing) { e.preventDefault(); UI.inline.name = t.value; addInline(); }
			else if (e.key === 'Escape') { UI.inline = null; UI.focus = { type: 'site' }; UI.pop = null; render(); }
			return;
		}
		if (t.id === 'zse-sm-add' && UI.smAdd) {
			if (e.key === 'Enter' && !e.isComposing) { e.preventDefault(); UI.smAdd.name = t.value; doSitemapAdd(t.value); }
			else if (e.key === 'Escape') { UI.smAdd = null; render(); }
			return;
		}
		if (['zse-f-link', 'zse-sm-name', 'lang_zse-sm-name', 'lang_zse-f-name'].indexOf(t.id) >= 0 && e.key === 'Enter' && !e.isComposing) { e.preventDefault(); t.blur(); return; }
		if (t.id === 'zse-rename') {
			if (e.key === 'Enter' && !e.isComposing) { e.preventDefault(); var rid0 = UI.renaming; UI.renaming = null; doRename(rid0, t.value); render(); }
			else if (e.key === 'Escape') { UI.renaming = null; render(); }
			return;
		}
		if (t.id === 'zse-f-mid' && e.key === 'Enter' && !e.isComposing) {
			e.preventDefault();
			if (UI.midCheck && UI.midCheck.state === 'ok' && UI.focus.type === 'item') doMid(UI.focus.id, UI.midCheck.value);
			return;
		}
		if (e.key === 'Escape') {
			if (UI.tour) { UI.tour = 0; tourDone(); render(); return; }
			if (tipFor) { hideTip(); return; }
			if (UI.pop) { UI.pop = null; renderLayer(); return; }
		}
		if (t.matches && t.matches('input, textarea, select, [contenteditable="true"]')) return;
		if (UI.tour) return;
		if ((e.ctrlKey || e.metaKey) && !e.shiftKey && String(e.key).toLowerCase() === 'z') { e.preventDefault(); undo(); return; }
		if (UI.focus.type === 'multi') {
			if (e.key === 'Delete') { e.preventDefault(); doBulkRemove(); }
			else if (e.key === 'Escape') { UI.sel = []; UI.focus = { type: 'site' }; UI.bulkWho = null; render(); }
			return;
		}
		if (UI.focus.type !== 'item') return;
		var f = find(UI.focus.id);
		if (!f) return;
		if (e.key === 'F2') { e.preventDefault(); startRename(UI.focus.id); return; }
		if (e.key === 'Delete') { e.preventDefault(); if (!f.sitemap.unlinked) doRemove(UI.focus.id); return; }
		if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) { e.preventDefault(); moveBy(UI.focus.id, e.key === 'ArrowUp' ? 'up' : 'down'); return; }
		if (e.key === 'Tab' && document.activeElement && document.activeElement.closest && document.activeElement.closest('.zse-tree .row')) { e.preventDefault(); moveBy(UI.focus.id, e.shiftKey ? 'out' : 'in'); return; }
		if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
			var rows = Array.prototype.slice.call(ROOT.querySelectorAll('.zse-tree .row[data-act="row"]'));
			var ids = rows.map(function (r) { return parseInt(r.getAttribute('data-id'), 10); });
			var i = ids.indexOf(UI.focus.id);
			var n = e.key === 'ArrowDown' ? Math.min(ids.length - 1, i + 1) : Math.max(0, i - 1);
			if (ids[n]) { e.preventDefault(); select(ids[n]); if (isMobile()) UI.mPane = 'tree'; render(); var r = ROOT.querySelector('.zse-tree .row[data-id="' + ids[n] + '"]'); if (r) { r.focus(); r.scrollIntoView({ block: 'nearest' }); } }
		}
	}

	function onInput(e) {
		var t = e.target;
		var wk = t.getAttribute && t.getAttribute('data-wz');
		if (wk && UI.wz) {
			if (wk === 'prefix') { UI.wz.prefix = t.value.trim().toLowerCase(); UI.wz.prefixTouched = true; return; }
			UI.wz[wk] = t.value;
			if (wk === 'domain' && !UI.wz.prefixTouched) {
				UI.wz.prefix = prefixFromDomain(t.value);
				var pi = document.getElementById('zse-wz-prefix');
				if (pi) pi.value = UI.wz.prefix;
			}
			return;
		}
		if (t.id === 'zse-ia' && UI.inline) {
			UI.inline.name = t.value;
			UI.inline.error = '';
			var sub = t.closest('.inline-add').querySelector('.ia-sub .midtag');
			if (sub) sub.innerHTML = '<i>mid</i>' + esc(t.value.trim() ? suggestMid(t.value, UI.inline.kind) : '…');
			renderPanel(true);
			return;
		}
		if (t.id === 'zse-sm-add' && UI.smAdd) { UI.smAdd.name = t.value; UI.smAdd.error = ''; return; }
		if (t.id === 'zse-search') { UI.search = t.value; UI.searchFocus = true; renderTree(); UI.searchFocus = false; return; }
		if (t.id === 'zse-f-mid' && UI.focus.type === 'item') { checkMidLater(UI.focus.id, t.value.trim()); }
	}

	function onChange(e) {
		var t = e.target;
		if (t.getAttribute('data-wz')) return;
		if (t.getAttribute('data-bind') === 'prefix') { savePrefix(t.value); return; }
		if (t.getAttribute('data-bind') === 'slotsel') {
			doBindSlot(parseInt(t.getAttribute('data-layout'), 10), t.getAttribute('data-slot'), parseInt(t.value, 10) || 0);
			return;
		}
		if (t.id === 'zse-ia-target' && UI.inline) { UI.inline.target = parseInt(t.value, 10) || 0; UI.inline.error = ''; return; }
		var bind = t.getAttribute('data-bind');
		if (UI.focus.type === 'item' && bind === 'target') { if (t.value) doTarget(UI.focus.id, parseInt(t.value, 10)); return; }
		if (UI.focus.type === 'item' && bind === 'link') { doLink(UI.focus.id, t.value); return; }
		if (UI.focus.type === 'item' && bind === 'grant') { if (t.value === 'groups') { doGrant(UI.focus.id, t.getAttribute('data-key'), 'groups', []); } else doGrant(UI.focus.id, t.getAttribute('data-key'), t.value); return; }
		if ((t.id === 'zse-f-name' || t.id === 'zse-sm-name' || (t.closest && t.closest('.g11n') && t.closest('.zse-panel')))) {
			setTimeout(saveNameField, 0);
		}
	}
	function saveNameField() {
		if (UI.focus.type === 'sitemap') {
			var smInput = document.getElementById('zse-sm-name');
			if (smInput) doSitemapRename(UI.focus.id, smInput.value);
			return;
		}
		var hidden = document.getElementById('zse-f-name');
		if (!hidden || UI.focus.type !== 'item') return;
		var f = find(UI.focus.id);
		if (!f) return;
		var value = String(hidden.value || '').trim();
		var cur = f.item.is_lang ? f.item.name_key : f.item.name;
		if (value && value !== cur) doRename(f.item.id, value);
	}

	/* ── 끌어 옮기기 ─────────────────────────── */
	var DR = null, suppressClick = false, ghost;
	function onPointerDown(e) {
		if (e.button !== 0 || isMobile()) return;
		var row = e.target.closest('.zse-tree .row');
		if (!row || e.target.closest('button, input, a, select')) return;
		var orphan = row.getAttribute('data-act') === 'orphan';
		var id = orphan ? parseInt(row.getAttribute('data-srl'), 10) : parseInt(row.getAttribute('data-id'), 10);
		var nmEl = row.querySelector('.nm');
		DR = { id: id, orphan: orphan, unl: orphan || !!(find(id) && find(id).sitemap.unlinked), x: e.clientX, y: e.clientY, active: false, name: nmEl ? nmEl.textContent : '', kind: row.querySelector('.ki').innerHTML };
	}
	function onPointerMove(e) {
		if (!DR) return;
		if (!DR.active) { if (Math.abs(e.clientX - DR.x) + Math.abs(e.clientY - DR.y) < 6) return; DR.active = true; startDrag(); }
		e.preventDefault();
		DR.cx = e.clientX; DR.cy = e.clientY;
		computeTarget(e.clientX, e.clientY);
		paintDrag();
	}
	function onPointerUp() {
		if (!DR) return;
		var d = DR; DR = null;
		if (!d.active) return;
		suppressClick = true;
		setTimeout(function () { suppressClick = false; }, 60);
		endDrag(d);
	}
	function startDrag() {
		ROOT.classList.add('dragging');
		UI.pop = null; renderLayer(); hideTip();
		var src = DR.orphan ? ROOT.querySelector('.row[data-srl="' + DR.id + '"]') : ROOT.querySelector('.row[data-id="' + DR.id + '"]');
		if (src) src.classList.add('drag-src');
		DR.scrollT = setInterval(function () {
			if (!DR) return;
			var sc = ROOT.querySelector('.zse-tree .treescroll');
			var r = sc.getBoundingClientRect();
			if (DR.cy < r.top + 40) sc.scrollTop -= 10; else if (DR.cy > r.bottom - 40) sc.scrollTop += 10;
		}, 30);
	}
	function computeTarget(x, y) {
		var el = document.elementFromPoint(x, y);
		DR.target = null;
		if (!el || !ROOT.contains(el)) return;
		var unlHead = el.closest('.unl-head');
		if (unlHead || el.closest('.grp.unl')) {
			if (!DR.unl) DR.target = { type: 'unlink', el: unlHead || ROOT.querySelector('.unl-head') };
			return;
		}
		var gh = el.closest('.grphead[data-g]') || el.closest('.addline');
		if (gh) {
			DR.target = { type: 'end', g: parseInt(gh.getAttribute('data-g'), 10), el: gh };
			var gk = 'm' + gh.getAttribute('data-g');
			if (UI.closed[gk]) hoverOpen(gk, function () { UI.closed[gk] = false; });
			return;
		}
		var row = el.closest('.row[data-act="row"]');
		if (!row) return;
		var tid = parseInt(row.getAttribute('data-id'), 10);
		var f = find(tid);
		if (!f || f.sitemap.unlinked) return;
		var r = row.getBoundingClientRect();
		var lv = parseInt(row.style.getPropertyValue('--lv'), 10) || 0;
		var leftOfText = x < r.left + 28 + lv * 20;
		var isLast = f.parent && f.list[f.list.length - 1] === f.item;
		var kidsOpen = f.item.children && f.item.children.length && !UI.closed[tid];
		if (!DR.unl && tid === DR.id) {
			if (leftOfText && f.parent) DR.target = { type: 'out', id: f.parent.id, el: row, lv: lv - 1 };
			else DR.target = { type: 'self', el: row };
			return;
		}
		if (!DR.unl && isDescendant(DR.id, tid)) { DR.target = { type: 'self', el: row }; return; }
		var rel = (y - r.top) / r.height;
		var type = rel < 0.28 ? 'before' : rel > 0.72 ? 'after' : 'into';
		if (type === 'after' && isLast && leftOfText && !kidsOpen) { DR.target = { type: 'out', id: f.parent.id, el: row, lv: lv - 1 }; return; }
		if (type === 'after' && kidsOpen) type = 'into-first';
		DR.target = { type: type, id: tid, el: row, lv: (type === 'into' || type === 'into-first') ? lv + 1 : lv };
		if (type === 'into' && UI.closed[tid]) hoverOpen('i' + tid, function () { UI.closed[tid] = false; });
	}
	var hoverKey = null, hoverT = null;
	function hoverOpen(key, fn) {
		if (hoverKey === key) return;
		clearTimeout(hoverT);
		hoverKey = key;
		hoverT = setTimeout(function () {
			if (!DR) return;
			fn(); hoverKey = null; renderTree();
			var src = DR.orphan ? ROOT.querySelector('.row[data-srl="' + DR.id + '"]') : ROOT.querySelector('.row[data-id="' + DR.id + '"]');
			if (src) src.classList.add('drag-src');
		}, 600);
	}
	function dropLabel(d) {
		var t = d.target;
		if (!t) return '';
		if (t.type === 'self') return T('drop_no');
		if (t.type === 'unlink') return T('remove');
		if (d.unl) return T('drop_relink');
		if (t.type === 'out') return T('drop_out');
		if (t.type === 'into' || t.type === 'into-first') return T('drop_into');
		if (t.type === 'end') return T('drop_end');
		return t.type === 'before' ? T('drop_before') : T('drop_after');
	}
	function paintDrag() {
		ROOT.querySelectorAll('.drop-before, .drop-after, .drop-into, .drop-end, .drop-unlink, .drop-no').forEach(function (x) { x.classList.remove('drop-before', 'drop-after', 'drop-into', 'drop-end', 'drop-unlink', 'drop-no'); });
		var t = DR && DR.target;
		if (t && t.el) {
			var cls = { before: 'drop-before', after: 'drop-after', into: 'drop-into', 'into-first': 'drop-after', out: 'drop-after', end: 'drop-end', unlink: 'drop-unlink', self: 'drop-no' }[t.type];
			t.el.classList.add(cls);
			t.el.style.setProperty('--dl', (8 + Math.max(0, t.lv || 0) * 20 + 24) + 'px');
		}
		if (!DR) return;
		ghost.hidden = false;
		ghost.innerHTML = '<span class="g-grip">' + ic('grip', 12) + '</span>' + DR.kind + '<span>' + esc(DR.name) + '</span>' + (t ? '<span class="dl' + (t.type === 'unlink' ? ' warn' : t.type === 'self' ? ' muted' : '') + '">' + esc(dropLabel(DR)) + '</span>' : '');
		ghost.style.left = (DR.cx + 14) + 'px';
		ghost.style.top = (DR.cy + 10) + 'px';
	}
	function clearDrag() {
		ROOT.classList.remove('dragging');
		ghost.hidden = true;
		clearTimeout(hoverT); hoverKey = null;
		ROOT.querySelectorAll('.drag-src, .drop-before, .drop-after, .drop-into, .drop-end, .drop-unlink, .drop-no').forEach(function (x) { x.classList.remove('drag-src', 'drop-before', 'drop-after', 'drop-into', 'drop-end', 'drop-unlink', 'drop-no'); });
	}
	function endDrag(d) {
		clearInterval(d.scrollT);
		clearDrag();
		var t = d.target;
		if (!t || t.type === 'self') return;
		if (t.type === 'unlink') { if (!d.unl) doRemove(d.id, true); return; }
		var parent, source, msg;
		var name = d.name;
		var exceptId = d.orphan ? -1 : d.id;
		var siblingsWithout = function (list) { return list.filter(function (x) { return x.id !== exceptId; }); };
		var tf = t.id ? find(t.id) : null;
		if (t.type === 'end') {
			var sm = sitemapBySrl(t.g);
			if (!sm) return;
			var top = siblingsWithout(sm.items);
			parent = sm.menu_srl; source = top.length ? top[top.length - 1].id : 0;
			msg = [T('toast_moved_end', name, sm.title), ''];
		} else if (t.type === 'into') {
			var kids = siblingsWithout(tf.item.children || []);
			parent = tf.item.id; source = kids.length ? kids[kids.length - 1].id : 0;
			msg = [T('toast_moved_into', name, tf.item.name), ''];
		} else if (t.type === 'into-first') {
			parent = tf.item.id; source = 0;
			msg = [T('toast_moved_into', name, tf.item.name), ''];
		} else if (t.type === 'out') {
			var pf = find(t.id);
			parent = parentKey(pf); source = pf.item.id;
			msg = [T('toast_moved_out', name), ''];
		} else {
			var list = siblingsWithout(tf.list);
			parent = parentKey(tf);
			if (t.type === 'after') source = tf.item.id;
			else { var i = list.indexOf(tf.item); source = i > 0 ? list[i - 1].id : 0; }
			msg = [T('toast_moved', name), ''];
		}
		if (d.orphan) {
			doRelinkOrphan(d.id, parent, source ? source : 0, [T('toast_relinked', name), '']);
			return;
		}
		var from = find(d.id);
		if (from && !from.sitemap.unlinked) {
			var toSm = t.type === 'end' ? sitemapBySrl(t.g) : (tf ? tf.sitemap : null);
			if (toSm && toSm.menu_srl !== from.sitemap.menu_srl) msg[1] = T('toast_moved_sitemap', from.sitemap.title, toSm.title);
			else if (from.item.children && from.item.children.length) msg[1] = T('toast_children_moved', from.item.children.length);
		} else if (from) {
			doRelinkItem(d.id, parent, source ? source : 0, [T('toast_relinked', name), T('toast_relinked_sub', from.item.mid || '')]);
			return;
		}
		doMove(d.id, parent, source, msg);
	}

	/* ── 시작 ─────────────────────────── */
	function tourDone() { try { localStorage.setItem('zse-tour-1', '1'); } catch (e) { } }
	function savePref() { try { localStorage.setItem('zse-preview', UI.preview ? '1' : '0'); } catch (e) { } }
	function fitHeight() {
		if (!ROOT) return;
		var top = ROOT.getBoundingClientRect().top + window.scrollY;
		ROOT.style.height = Math.max(480, window.innerHeight - top) + 'px';
		fitPreview();
		placePop();
		placeTour();
	}

	function boot() {
		ROOT = document.getElementById('zse');
		B = window.ZSE_BOOT;
		if (!ROOT || !B) return;
		L = B.lang || {};
		var pv = true;
		try { pv = localStorage.getItem('zse-preview') !== '0'; } catch (e) { }
		UI = { focus: { type: 'site' }, closed: { unl: true }, open: {}, inline: null, pick: null, dtab: 'P', search: '', device: 'pc', preview: pv, pop: null, toast: null, renaming: null, slotPick: false, tour: 0, pending: 0, failed: null, flash: [], mPane: 'tree', midCheck: null, whoDraft: null, sel: [], anchor: null, kc: null, smAdd: null, smAddFocus: false, scope: {}, bulkScope: 'page', bulkWho: null, grantDraft: null, linkErr: null, view: 'edit', wz: null, themeCand: '', themeAlsoOwn: false, prefixDraft: null, prefixErr: false };
		setState(B.state);
		var content = document.getElementById('content');
		if (content && content.contains(ROOT)) content.classList.add('zse-host');
		ROOT.innerHTML = '<div class="zse-bar"></div><div class="zse-ws"><nav class="zse-rail" aria-label="' + esc(T('rail_sites')) + '"></nav><section class="zse-tree" aria-label="' + esc(T('sitemap')) + '"></section><section class="zse-pv" aria-label="' + esc(T('pv_open')) + '"></section><aside class="zse-panel" aria-label="' + esc(T('site_settings')) + '"></aside></div><div class="zse-ov ov-view" aria-label="' + esc(T('view_overview')) + '"></div><div class="zse-layer"></div>';
		tipEl = document.createElement('div');
		tipEl.id = 'zse-termtip'; tipEl.className = 'termtip'; tipEl.setAttribute('role', 'tooltip'); tipEl.hidden = true;
		ROOT.appendChild(tipEl);
		ghost = document.createElement('div');
		ghost.className = 'dghost'; ghost.hidden = true;
		ROOT.appendChild(ghost);
		try { if (!localStorage.getItem('zse-tour-1')) UI.tour = 1; } catch (e) { }

		ROOT.addEventListener('click', function (e) {
			if (suppressClick) { e.stopPropagation(); e.preventDefault(); suppressClick = false; return; }
			var t = e.target.closest('.term');
			if (t && !t.closest('button')) { e.stopPropagation(); if (tipFor === t) hideTip(); else showTip(t); return; }
			if (tipFor) hideTip();
		}, true);
		document.addEventListener('click', onClick);
		ROOT.addEventListener('dblclick', function (e) {
			var row = e.target.closest('.zse-tree .row[data-act="row"]');
			if (row && e.target.closest('.nm')) startRename(parseInt(row.getAttribute('data-id'), 10));
		});
		ROOT.addEventListener('contextmenu', function (e) {
			var row = e.target.closest('.zse-tree .row[data-act="row"]');
			if (!row || e.target.closest('input')) return;
			var id = parseInt(row.getAttribute('data-id'), 10);
			var f = find(id);
			if (!f || f.sitemap.unlinked) return;
			e.preventDefault();
			select(id);
			UI.pop = { type: 'row-menu', id: id, x: e.clientX, y: e.clientY, focus: true };
			render();
		});
		document.addEventListener('keydown', onKey);
		ROOT.addEventListener('input', onInput);
		ROOT.addEventListener('change', onChange);
		$(ROOT).on('selected.g11n', function () { setTimeout(saveNameField, 0); });
		ROOT.addEventListener('focusout', function (e) {
			if (e.target.id === 'zse-rename' && UI.renaming) {
				var v = e.target.value, idr = UI.renaming;
				UI.renaming = null;
				setTimeout(function () { doRename(idr, v); render(); }, 0);
			}
			if (e.target.closest && e.target.closest('.term')) hideTip();
		});
		ROOT.addEventListener('mouseover', function (e) {
			var t = e.target.closest('.term');
			if (t) showTip(t);
			else if (tipFor && !e.target.closest('.termtip')) hideTip();
		});
		ROOT.addEventListener('focusin', function (e) { var t = e.target.closest && e.target.closest('.term'); if (t) showTip(t); });
		ROOT.addEventListener('pointerdown', onPointerDown);
		document.addEventListener('pointermove', onPointerMove);
		document.addEventListener('pointerup', onPointerUp);
		document.addEventListener('keydown', function (e) { if (DR && DR.active && e.key === 'Escape') { clearInterval(DR.scrollT); DR = null; clearDrag(); } });
		window.addEventListener('resize', fitHeight);
		window.addEventListener('scroll', function () { hideTip(); }, true);
		window.addEventListener('beforeunload', function (e) {
			if (UI.pending > 0) { e.preventDefault(); e.returnValue = ''; }
		});

		var focusItem = parseInt(B.focus_item, 10) || 0;
		if (focusItem && find(focusItem)) {
			select(focusItem);
			UI.tour = 0;
		}
		render();
		fitHeight();
		setTimeout(fitHeight, 50);
		if (focusItem && find(focusItem)) {
			setTimeout(function () {
				var into = function (el) {
					for (var box = el && el.parentElement; box && box !== ROOT; box = box.parentElement) {
						if (box.scrollHeight > box.clientHeight + 4 && /auto|scroll/.test(getComputedStyle(box).overflowY)) {
							box.scrollTop += el.getBoundingClientRect().top - box.getBoundingClientRect().top - 24;
							return;
						}
					}
				};
				into(ROOT.querySelector('.zse-tree .row[data-id="' + focusItem + '"]'));
				into(document.getElementById('zse-sec-who'));
			}, 120);
		}
	}

	window.ZittmeSiteEditor = { boot: boot };
	$(boot);
})(jQuery);
