/**
 * Member administration: groups
 *
 * Groups are saved by the existing group actions (insert, update, order,
 * delete). "Where it is used" is only read here; permissions are changed in
 * the site editor.
 */
(function ($) {
	'use strict';

	var K, T, esc, ic;
	var D = { groups: [], estimated: false, image_mark: false };
	var U = { sel: 0, usage: {}, loading: {}, pane: 'list', filebox: null, del: null, drag: null };

	function S() { return K.state(); }
	function G(id) { for (var i = 0; i < D.groups.length; i++) if (D.groups[i].id === id) return D.groups[i]; return null; }
	function color(g) {
		if (g.admin) return '#334155';
		var i = K.GROUPS.map(function (x) { return x.id; }).indexOf(g.id);
		if (i < 0) i = D.groups.indexOf(g);
		return K.PALETTE[(i < 0 ? 0 : i) % K.PALETTE.length];
	}
	function defGroup() { for (var i = 0; i < D.groups.length; i++) if (D.groups[i].def) return D.groups[i]; return null; }
	function names(ids) {
		return ids.map(function (id) { var g = G(id); return g ? g.title : ''; }).filter(Boolean);
	}
	function countText(g) { return g.count === null ? T('count_unknown') : T('g_people', K.fmt(g.count)); }
	function swatch(g, size) {
		if (D.image_mark && g.image_mark) {
			return '<span class="gsw img" style="background:' + color(g) + '"><img src="' + esc(g.image_mark) + '" alt=""></span>';
		}
		return '<span class="gsw" style="background:' + color(g) + '">' + ic(g.admin ? 'shield' : g.def ? 'userplus' : 'users', size || 16) + '</span>';
	}

	/* ── 불러오기 ─────────────────────────── */
	function load(keepSel) {
		return K.api('member.getMemberAdminGroups', {}).then(function (d) {
			setData(d.group_data || {});
			if (!keepSel || !G(U.sel)) U.sel = D.groups.length ? D.groups[0].id : 0;
			K.render();
			loadUsage(U.sel, true);
		}, function (e) { K.failed(e); });
	}
	function setData(data) {
		D.groups = data.groups || [];
		D.estimated = !!data.estimated;
		D.image_mark = !!data.image_mark;
	}
	function loadUsage(id, force) {
		if (!id) return Promise.resolve(null);
		if (U.usage[id] && !force) return Promise.resolve(U.usage[id]);
		U.loading[id] = true;
		return K.api('member.getMemberAdminGroupUsage', { group_srl: id }).then(function (d) {
			U.usage[id] = { usage: d.usage || [], sites: d.sites || [] };
			U.loading[id] = false;
			if (U.sel === id) K.render();
			return U.usage[id];
		}, function (e) {
			U.loading[id] = false;
			K.failed(e);
			return null;
		});
	}

	/* ── 그리기 ─────────────────────────── */
	function listHTML() {
		var h = '<div class="glist-wrap"><div class="ghd"><h2>' + K.term('group', T('g_list_title')) + ' <span class="dim">' + esc(T('g_count', K.fmt(D.groups.length))) + '</span></h2><p class="hint">' + esc(T('g_list_hint')) + '</p></div><div class="glist" id="zmc-glist" role="list">';
		D.groups.forEach(function (g, i) {
			h += '<div class="grow' + (g.id === U.sel ? ' on' : '') + '" role="listitem" data-act="gsel" data-v="' + g.id + '" data-id="' + g.id + '">' +
				'<button type="button" class="grip" data-grip="g" data-v="' + g.id + '" aria-label="' + esc(T('g_move', g.title)) + '" title="' + esc(T('g_move_hint')) + '">' + ic('grip', 16) + '</button>' +
				swatch(g) + '<span class="gt"><b>' + esc(g.title) + (g.def ? '<span class="bdg def">' + esc(T('badge_def')) + '</span>' : '') + (g.admin ? '<span class="bdg adm">' + esc(T('g_badge_admin')) + '</span>' : '') + '</b>' +
				'<small>' + esc(g.description || '') + '</small></span><span class="gn">' + esc(countText(g)) + '</span></div>';
		});
		h += '</div><button type="button" class="fl addline" data-act="gadd">' + ic('plus', 16) + esc(T('g_add')) + '</button>';
		if (D.estimated) h += '<p class="hint gnote">' + esc(T('g_count_cached')) + '</p>';
		return h + '</div>';
	}

	var KIND = {
		menu: ['menu', 'g_kind_menu'],
		grant: ['doc', 'g_kind_grant'],
		category: ['tag', 'g_kind_category'],
		point: ['coin', 'g_kind_point'],
		join: ['userplus', 'g_kind_join'],
		admin: ['shield', 'g_kind_admin'],
		identity: ['key', 'g_kind_identity'],
		other: ['layers', 'g_kind_other']
	};
	function whoText(item) {
		var others = (item.others || []).filter(function (id) { return id > 0 && G(id); });
		var all = [U.sel].concat(others);
		return T('g_who_groups', names(all).join('·'));
	}
	function useLine(item) {
		var k = KIND[item.kind] || KIND.other;
		var title, sub, link = '';
		if (item.kind === 'menu') {
			title = T('g_use_menu', item.title);
			sub = T(k[1]) + ' · ' + (item.where ? item.where + ' · ' : '') + T('g_use_menu_who', whoText(item));
			if (item.url) link = '<a class="linkbtn" href="' + esc(item.url) + '" target="_blank" rel="noopener">' + esc(T('g_open_editor')) + ic('ext', 12) + '</a>';
		} else if (item.kind === 'grant') {
			title = item.title + ' · ' + item.where;
			sub = T(k[1]) + ' · ' + whoText(item);
			if (item.url) link = '<a class="linkbtn" href="' + esc(item.url) + '" target="_blank" rel="noopener">' + esc(T('g_open_editor')) + ic('ext', 12) + '</a>';
		} else if (item.kind === 'category') {
			title = T('g_use_category', item.title);
			sub = T(k[1]) + (item.where ? ' · ' + item.where : '') + ' · ' + whoText(item);
			if (item.url) link = '<a class="linkbtn" href="' + esc(item.url) + '" target="_blank" rel="noopener">' + esc(T('g_open_setting')) + ic('ext', 12) + '</a>';
		} else if (item.kind === 'point') {
			title = T('g_use_point', item.level);
			sub = T(k[1]) + ' · ' + T('g_use_point_sub');
			if (item.url) link = '<a class="linkbtn" href="' + esc(item.url) + '">' + esc(T('g_open_point')) + ic('chevr', 12) + '</a>';
		} else if (item.kind === 'join') {
			title = T('g_use_join');
			sub = T(k[1]) + ' · ' + T('g_use_join_sub');
		} else if (item.kind === 'admin') {
			title = T('g_use_admin');
			sub = T(k[1]) + ' · ' + T('g_use_admin_sub');
		} else if (item.kind === 'identity') {
			title = T('g_use_identity', item.age);
			sub = T(k[1]) + ' · ' + T(item.on ? 'g_use_identity_on' : 'g_use_identity_off');
			if (item.url) link = '<a class="linkbtn" href="' + esc(item.url) + '">' + esc(T('g_open_settings')) + ic('chevr', 12) + '</a>';
		} else {
			title = item.title;
			sub = T(k[1]) + (item.where ? ' · ' + item.where : '');
			if (item.url) link = '<a class="linkbtn" href="' + esc(item.url) + '" target="_blank" rel="noopener">' + esc(T('g_open_setting')) + ic('ext', 12) + '</a>';
		}
		return '<div class="li"><span class="lic">' + ic(k[0], 15) + '</span><span class="lt"><b>' + esc(title) + '</b><small>' + esc(sub) + '</small></span>' + (link ? '<span class="r">' + link + '</span>' : '') + '</div>';
	}
	function usesHTML(g) {
		var data = U.usage[g.id];
		var h = '<div class="card" id="zmc-uses"><h3>' + K.term('uses', T('g_uses_title')) + (data ? '<span class="h-r dim">' + esc(T('g_uses_n', K.fmt(data.usage.length))) + '</span>' : '') + '</h3><p class="lead">' + esc(T('g_uses_lead')) + '</p>';
		if (!data) return h + '<p class="dim gload">' + esc(T('loading')) + '</p></div>';
		var sites = data.sites || [];
		var siteMap = {};
		sites.forEach(function (s) { siteMap[s.id] = s; });
		var groups = {}, order = [];
		data.usage.forEach(function (u) {
			var key = sites.length > 1 && u.site && siteMap[u.site] ? String(u.site) : '_';
			if (!groups[key]) { groups[key] = []; order.push(key); }
			groups[key].push(u);
		});
		order.sort(function (a, b) { return a === '_' ? -1 : b === '_' ? 1 : 0; });
		if (!data.usage.length) {
			h += '<div class="note">' + ic('info', 14) + '<span>' + esc(T('g_uses_none')) + '</span></div>';
		}
		order.forEach(function (key) {
			if (sites.length > 1) {
				var s = siteMap[key];
				h += '<div class="usegroup">' + (s ? '<span class="sitepill"><span class="fav">' + esc(String(s.title || s.domain).charAt(0).toUpperCase()) + '</span>' + esc(s.title) + ' <span class="dim">' + esc(s.domain) + '</span></span>' : esc(T('g_all_sites'))) + '</div>';
			}
			h += '<div class="list uses">' + groups[key].map(useLine).join('') + '</div>';
		});
		h += '<div class="usefoot"><span class="hint">' + ic('info', 13) + esc(T('g_uses_who_hint')) + '</span><a class="linkbtn" href="' + esc(K.B.urls.site_editor) + '" target="_blank" rel="noopener">' + esc(T('g_open_editor_site')) + ic('ext', 12) + '</a></div>';
		return h + '</div>';
	}
	function pageHTML() {
		var g = G(U.sel);
		if (!g) return '<section class="gpage"><div class="gpage-in"><p class="dim">' + esc(T('loading')) + '</p></div></section>';
		var levelText = g.level !== null && g.level !== undefined ? T('g_level_on', g.level) : (g.def || g.admin ? T('g_level_none_fixed') : T('g_level_none'));
		var h = '<section class="gpage" aria-label="' + esc(g.title) + '"><div class="gpage-in"><button type="button" class="btn ghost back" data-act="gback">' + ic('back', 16) + esc(T('g_back')) + '</button>' +
			'<div class="ghead">' + swatch(g, 22) + '<div class="gh-t"><h2>' + esc(g.title) + (g.def ? '<span class="bdg def">' + K.term('def_group', T('g_def_group')) + '</span>' : '') + (g.admin ? '<span class="bdg adm">' + K.term('admin_group', T('g_admin_group')) + '</span>' : '') + '</h2><p>' + esc(countText(g) + (g.description ? ' · ' + g.description : '')) + '</p></div>' +
			'<div class="r"><a class="btn" href="' + esc(K.B.urls.group_members + g.id) + '">' + ic('users', 15) + esc(g.count === null ? T('g_see_members_plain') : T('g_see_members', K.fmt(g.count))) + '</a><button type="button" class="btn" data-act="pop" data-v="g-more" data-anchor="g-more" aria-label="' + esc(T('more')) + '">' + ic('more', 16) + '</button></div></div>';
		h += usesHTML(g);
		h += '<div class="card"><h3>' + esc(T('g_name_title')) + '</h3><div class="field"><label for="zmc-gname">' + esc(T('g_name')) + '</label><input id="zmc-gname" class="inp lang_code" maxlength="250" value="' + esc(g.title_raw) + '" data-gid="' + g.id + '"><div class="hint">' + esc(T('g_name_hint')) + '</div></div>' +
			'<div class="field"><label for="zmc-gdesc">' + esc(T('g_desc')) + '</label><input id="zmc-gdesc" class="inp" maxlength="250" value="' + esc(g.description) + '" data-gid="' + g.id + '"><div class="hint">' + esc(T('g_desc_hint')) + '</div></div></div>';
		h += '<div class="card"><h3>' + esc(T('g_rules_title')) + '</h3>' +
			'<div class="srow"><div class="st"><b>' + K.term('def_group', T('g_def_switch')) + '</b><small>' + esc(g.admin ? T('g_def_admin_off') : g.def ? T('g_def_on_hint') : T('g_def_off_hint')) + '</small></div><div class="sv">' + K.sw(g.def, 'gdef', T('g_def_switch'), g.admin || g.def) + '</div></div>' +
			'<div class="srow"><div class="st"><b>' + K.term('level', T('g_level_title')) + '</b><small>' + esc(levelText) + '</small></div><div class="sv">' + (K.B.urls.point_level ? '<a class="linkbtn" href="' + esc(K.B.urls.point_level) + '">' + esc(T('g_open_point')) + ic('chevr', 12) + '</a>' : '') + '</div></div>' +
			'<div class="srow"><div class="st"><b>' + K.term('mark', T('g_mark_title')) + '</b><small>' + esc(T('g_mark_hint')) + '</small>' + (!D.image_mark ? '<small class="warnline">' + esc(T('g_mark_off_note')) + '</small>' : '') + '</div><div class="sv">' +
			(g.image_mark ? '<span class="markprev"><img src="' + esc(g.image_mark) + '" alt=""></span><button type="button" class="btn" data-act="gmark">' + esc(T('g_mark_change')) + '</button><button type="button" class="btn ghost" data-act="gmarkoff">' + esc(T('g_mark_remove')) + '</button>' : '<button type="button" class="btn" data-act="gmark">' + ic('image', 15) + esc(T('g_mark_pick')) + '</button>') + '</div></div>' +
			'<div class="srow"><div class="st"><b>' + esc(T('g_mark_all_title')) + '</b><small>' + esc(T('g_mark_all_hint')) + '</small></div><div class="sv">' + K.sw(D.image_mark, 'gmarkall', T('g_mark_all_title')) + '</div></div></div>';
		return h + '</div></section>';
	}
	function html() {
		return '<div class="workspace v-groups' + (U.pane === 'detail' ? ' m-detail' : '') + '">' + listHTML() + pageHTML() + '</div>';
	}
	function beforeRender() {
		var a = document.activeElement;
		U.stash = (a && K.ROOT.contains(a) && /^(zmc-gdesc|zmc-gname|lang_zmc-gname)$/.test(a.id)) ? { id: a.id, value: a.value, gid: U.sel } : null;
	}
	function afterRender() {
		var input = document.getElementById('zmc-gname');
		if (input && $.fn.xeApplyMultilingualUI) {
			try { $(input).xeApplyMultilingualUI(); } catch (e) { }
		}
		var st = U.stash;
		U.stash = null;
		if (st && st.gid === U.sel) {
			var el = document.getElementById(st.id);
			if (el && !el.disabled) {
				el.value = st.value;
				if (st.id === 'lang_zmc-gname' && input) input.value = st.value;
				el.focus();
			}
		}
	}

	/* ── 팝오버 · 창 ─────────────────────────── */
	function popHTML() {
		var p = S().pop;
		if (!p) return '';
		var g = G(U.sel);
		if (p.type === 'g-more' && g) {
			var why = g.admin ? T('g_del_admin_no') : g.def ? T('g_del_def_no') : '';
			return '<div class="pop menu"><div class="optlist"><button type="button" class="opt danger" data-act="gdelask"' + (why ? ' disabled' : '') + '>' + ic('trash', 15) + esc(T('g_delete')) + '</button>' + (why ? '<p class="pl" style="margin:4px 8px 8px">' + esc(why) + '</p>' : '') + '</div></div>';
		}
		return '';
	}
	function effectLine(item, g) {
		var others = (item.others || []).filter(function (id) { return id > 0 && G(id); });
		var special = (item.others || []).filter(function (id) { return id <= 0 && id !== -3; });
		var left = names(others).join('·');
		var bad = false, title, sub, link = item.url || '';
		if (item.kind === 'menu') {
			if (others.length) { title = T('g_eff_menu_left', item.title, left); sub = T('g_eff_menu_left_sub', g.title); }
			else { title = T('g_eff_menu_admin', item.title); sub = T('g_eff_menu_admin_sub', g.title); bad = true; }
		} else if (item.kind === 'grant') {
			if (special.length) { title = T('g_eff_grant_same', item.title, item.where); sub = T('g_eff_grant_same_sub'); }
			else if (others.length) { title = T('g_eff_grant_left', item.title, item.where, left); sub = T('g_eff_left_sub', g.title); }
			else { title = T('g_eff_grant_admin', item.title, item.where); sub = T('g_eff_admin_sub'); bad = true; }
		} else if (item.kind === 'category') {
			if (others.length) { title = T('g_eff_cat_left', item.title, left); sub = T('g_eff_left_sub', g.title); }
			else { title = T('g_eff_cat_admin', item.title); sub = T('g_eff_admin_sub'); bad = true; }
		} else if (item.kind === 'point') {
			title = T('g_eff_point', item.level); sub = T('g_eff_point_sub');
		} else if (item.kind === 'identity') {
			title = T('g_eff_identity', item.age); sub = T('g_eff_identity_sub');
		} else if (item.kind === 'other') {
			title = item.title + (item.where ? ' · ' + item.where : ''); sub = item.effect || T('g_eff_other_sub');
		} else {
			return '';
		}
		return '<div class="li' + (bad ? ' bad' : '') + '"><span class="lic' + (bad ? ' danger' : '') + '">' + ic(bad ? 'lock' : (KIND[item.kind] || KIND.other)[0], 15) + '</span><span class="lt"><b>' + esc(title) + '</b><small>' + esc(sub) + '</small></span>' + (bad && link ? '<span class="r"><a class="linkbtn" href="' + esc(link) + '" target="_blank" rel="noopener">' + esc(T(item.kind === 'category' ? 'g_open_setting' : 'g_open_editor')) + ic('ext', 12) + '</a></span>' : '') + '</div>';
	}
	function modalHTML() {
		var md = S().modal;
		if (md === 'g-del' && U.del) {
			var g = G(U.del), dg = defGroup();
			if (!g) return '';
			var data = U.usage[g.id];
			var lines = '<div class="li"><span class="lic">' + ic('users', 15) + '</span><span class="lt"><b>' + esc(g.count === null ? T('count_unknown') : g.count ? T('g_eff_members', K.fmt(g.count), dg ? dg.title : '') : T('g_eff_members_none')) + '</b>' + (g.count ? '<small>' + esc(T('g_eff_members_sub')) + '</small>' : '') + '</span></div>';
			if (data) data.usage.forEach(function (u) { lines += effectLine(u, g); });
			return '<div class="modal-back" data-act="modalclose" data-self="1"><div class="modal" role="dialog" aria-modal="true" aria-label="' + esc(T('g_del_title', g.title)) + '"><div class="mh"><h3>' + esc(T('g_del_title', g.title)) + '</h3><p>' + esc(T('g_del_lead')) + '</p></div><div class="mb">' +
				(data ? '<div class="list uses effects">' + lines + '</div>' : '<p class="dim">' + esc(T('loading')) + '</p>') +
				'<div class="note warn" style="margin-top:12px">' + ic('info', 14) + '<span>' + esc(T('g_del_warn')) + '</span></div><p class="hint" style="margin-top:8px">' + esc(T('g_del_known')) + '</p></div>' +
				'<div class="mf"><button type="button" class="btn" data-act="modalclose">' + esc(T('g_keep')) + '</button><button type="button" class="btn danger" data-act="gdelgo"' + (data ? '' : ' disabled') + '>' + ic('trash', 15) + esc(T('g_del_go')) + '</button></div></div></div>';
		}
		if (md === 'g-mark' && U.filebox) {
			var fb = U.filebox;
			var gm = G(U.sel);
			var imgs = fb.images.length ? '<div class="markgrid">' + fb.images.map(function (src) {
				return '<button type="button" class="markpick' + (gm && gm.image_mark === src ? ' on' : '') + '" data-act="gmarkset" data-v="' + esc(src) + '"><img src="' + esc(src) + '" alt=""></button>';
			}).join('') + '</div>' : '<div class="note">' + ic('info', 14) + '<span>' + esc(T('g_mark_empty')) + '</span></div>';
			var pager = fb.total_page > 1 ? '<div class="pager"><button type="button" class="btn" data-act="gmarkpage" data-v="' + (fb.page - 1) + '"' + (fb.page > 1 ? '' : ' disabled') + '>' + ic('back', 14) + esc(T('page_prev')) + '</button><span class="pg">' + fb.page + ' / ' + fb.total_page + '</span><button type="button" class="btn" data-act="gmarkpage" data-v="' + (fb.page + 1) + '"' + (fb.page < fb.total_page ? '' : ' disabled') + '>' + esc(T('page_next')) + ic('chevr', 14) + '</button></div>' : '';
			return '<div class="modal-back" data-act="modalclose" data-self="1"><div class="modal" role="dialog" aria-modal="true" aria-label="' + esc(T('g_mark_pick_title')) + '"><div class="mh"><h3>' + esc(T('g_mark_pick_title')) + '</h3><p>' + esc(T('g_mark_pick_lead')) + '</p></div><div class="mb">' + imgs + pager + '</div>' +
				'<div class="mf"><a class="btn ghost" href="' + esc(fb.url) + '" target="_blank" rel="noopener" style="margin-right:auto">' + esc(T('g_mark_upload')) + ic('ext', 12) + '</a><button type="button" class="btn" data-act="modalclose">' + esc(T('close')) + '</button></div></div></div>';
		}
		return '';
	}

	/* ── 저장 ─────────────────────────── */
	function updateArgs(g, over) {
		var a = { group_srl: g.id, title: g.title_raw, description: g.description, is_default: g.def ? 'Y' : 'N', image_mark: g.image_mark };
		Object.keys(over || {}).forEach(function (k) { a[k] = over[k]; });
		return a;
	}
	function renumber() {
		return K.api('member.procMemberAdminUpdateGroupOrder', { group_srls: D.groups.map(function (x) { return x.id; }) });
	}
	function saveGroup(g, over, label, toastTitle, toastSub) {
		var before = updateArgs(g);
		var after = updateArgs(g, over);
		K.busy(true);
		return K.api('member.procMemberAdminUpdateGroup', after).then(renumber).then(function () {
			K.busy(false);
			K.pushUndo(label, function () { return K.api('member.procMemberAdminUpdateGroup', before); });
			K.toast(toastTitle, toastSub || '', T('undo'));
			return load(true);
		}, function (e) { K.busy(false); K.failed(e); K.render(); });
	}
	function saveName(value) {
		var g = G(U.sel);
		value = String(value || '').trim();
		if (!g || !value || value === g.title_raw) return;
		var old = g.title;
		saveGroup(g, { title: value }, T('g_undo_rename', old), T('g_toast_renamed', old), T('g_toast_renamed_sub'));
	}
	function saveDesc(value) {
		var g = G(U.sel);
		value = String(value || '').trim();
		if (!g || value === g.description) return;
		saveGroup(g, { description: value }, T('g_undo_desc', g.title), T('g_toast_desc', g.title), '');
	}
	function setDefault() {
		var g = G(U.sel), prev = defGroup();
		if (!g || g.def || g.admin) return;
		var after = updateArgs(g, { is_default: 'Y' });
		K.busy(true);
		K.api('member.procMemberAdminUpdateGroup', after).then(renumber).then(function () {
			K.busy(false);
			if (prev) K.pushUndo(T('g_undo_def', g.title), function () { return K.api('member.procMemberAdminUpdateGroup', updateArgs(prev, { is_default: 'Y' })); });
			K.toast(T('g_toast_def', g.title), prev ? T('g_toast_def_sub', prev.title) : '', prev ? T('undo') : '');
			load(true);
		}, function (e) { K.busy(false); K.failed(e); K.render(); });
	}
	function saveOrder(ids, label) {
		var before = D.groups.map(function (x) { return x.id; });
		if (ids.join(',') === before.join(',')) return;
		var byId = {};
		D.groups.forEach(function (x) { byId[x.id] = x; });
		D.groups = ids.map(function (id) { return byId[id]; });
		K.render();
		K.busy(true);
		K.api('member.procMemberAdminUpdateGroupOrder', { group_srls: ids }).then(function () {
			K.busy(false);
			K.pushUndo(label, function () { return K.api('member.procMemberAdminUpdateGroupOrder', { group_srls: before }); });
			K.toast(T('g_toast_order'), T('g_toast_order_sub'), T('undo'));
		}, function (e) { K.busy(false); K.failed(e); load(true); });
	}
	function addGroup() {
		K.busy(true);
		var title = T('g_new_name');
		K.api('member.procMemberAdminInsertGroup', { title: title, description: '', is_default: 'N', image_mark: '' }).then(function (d) {
			var id = parseInt(d.group_srl, 10);
			var ids = D.groups.map(function (x) { return x.id; }).concat(id ? [id] : []);
			return K.api('member.procMemberAdminUpdateGroupOrder', { group_srls: ids }).then(function () {
				K.busy(false);
				if (id) K.pushUndo(T('g_undo_add'), function () { return K.api('member.procMemberAdminDeleteGroup', { group_srl: id }); });
				U.sel = id || U.sel;
				U.pane = 'detail';
				K.toast(T('g_toast_added'), T('g_toast_added_sub'), T('undo'));
				return load(true).then(function () {
					var input = document.getElementById('lang_zmc-gname') || document.getElementById('zmc-gname');
					if (input) { input.focus(); try { input.select(); } catch (e) { } }
				});
			});
		}).then(null, function (e) { K.busy(false); K.failed(e); load(true); });
	}
	function askDelete() {
		var g = G(U.sel);
		if (!g || g.def || g.admin) return;
		U.del = g.id;
		S().pop = null;
		S().modal = 'g-del';
		K.render();
		loadUsage(g.id, true).then(function () { if (S().modal === 'g-del') K.renderLayer(); });
	}
	function doDelete() {
		var g = G(U.del), dg = defGroup();
		if (!g) return;
		S().modal = null;
		U.del = null;
		K.renderLayer();
		K.busy(true);
		K.api('member.procMemberAdminDeleteGroup', { group_srl: g.id }).then(function () {
			K.busy(false);
			K.toast(T('g_toast_deleted', g.title), g.count && dg ? T('g_toast_deleted_sub', K.fmt(g.count), dg.title) : '', '');
			delete U.usage[g.id];
			U.sel = 0;
			U.pane = 'list';
			K.api('member.getMemberAdminQuickCounts', { refresh: 'Y' }).then(function () { load(false); }, function () { load(false); });
		}, function (e) { K.busy(false); K.failed(e); });
	}
	function openMarks(page) {
		K.api('member.getMemberAdminFileBox', { page: page || 1 }).then(function (d) {
			U.filebox = d.filebox || { images: [], page: 1, total_page: 1, url: K.B.urls.filebox };
			S().modal = 'g-mark';
			K.renderLayer();
		}, function (e) { K.failed(e); });
	}
	function setMark(src) {
		var g = G(U.sel);
		if (!g || g.image_mark === src) { S().modal = null; K.renderLayer(); return; }
		S().modal = null;
		K.renderLayer();
		saveGroup(g, { image_mark: src }, T('g_undo_mark', g.title), T(src ? 'g_toast_mark' : 'g_toast_mark_off', g.title), src && !D.image_mark ? T('g_mark_off_note') : '');
	}
	function setMarkAll(on) {
		K.busy(true);
		K.api('member.procMemberAdminSaveConfigKeys', { zmc_values: JSON.stringify({ group_image_mark: on ? 'Y' : 'N' }) }).then(function () {
			K.busy(false);
			K.pushUndo(T('g_undo_mark_all'), function () { return K.api('member.procMemberAdminSaveConfigKeys', { zmc_values: JSON.stringify({ group_image_mark: on ? 'N' : 'Y' }) }); });
			K.toast(T(on ? 'g_toast_mark_all_on' : 'g_toast_mark_all_off'), '', T('undo'));
			load(true);
		}, function (e) { K.busy(false); K.failed(e); });
	}

	/* ── 끌어 옮기기 ─────────────────────────── */
	function onPointerDown(e) {
		var grip = e.target.closest('#zmc-glist .grip');
		if (!grip || e.button !== 0) return;
		e.preventDefault();
		var id = parseInt(grip.getAttribute('data-v'), 10);
		U.drag = { id: id, y: e.clientY, moved: false, target: null };
		document.addEventListener('pointermove', onPointerMove);
		document.addEventListener('pointerup', onPointerUp);
	}
	function onPointerMove(e) {
		var d = U.drag;
		if (!d) return;
		if (!d.moved && Math.abs(e.clientY - d.y) < 4) return;
		d.moved = true;
		var rows = Array.prototype.slice.call(document.querySelectorAll('#zmc-glist .grow'));
		rows.forEach(function (r) { r.classList.remove('drop-before', 'drop-after'); r.classList.toggle('drag-src', parseInt(r.getAttribute('data-id'), 10) === d.id); });
		var target = null, after = false;
		for (var i = 0; i < rows.length; i++) {
			var r = rows[i].getBoundingClientRect();
			if (e.clientY < r.top + r.height / 2) { target = rows[i]; break; }
		}
		if (!target && rows.length) { target = rows[rows.length - 1]; after = true; }
		d.target = target ? parseInt(target.getAttribute('data-id'), 10) : null;
		d.after = after;
		if (target) target.classList.add(after ? 'drop-after' : 'drop-before');
	}
	function onPointerUp() {
		document.removeEventListener('pointermove', onPointerMove);
		document.removeEventListener('pointerup', onPointerUp);
		var d = U.drag;
		U.drag = null;
		if (!d || !d.moved || d.target === null) { K.render(); return; }
		var ids = D.groups.map(function (x) { return x.id; }).filter(function (x) { return x !== d.id; });
		var at = ids.indexOf(d.target);
		if (d.target === d.id) { K.render(); return; }
		if (at < 0) at = ids.length;
		ids.splice(d.after ? at + 1 : at, 0, d.id);
		var g = G(d.id);
		saveOrder(ids, T('g_undo_order', g ? g.title : ''));
	}
	function moveBy(id, delta) {
		var ids = D.groups.map(function (x) { return x.id; });
		var i = ids.indexOf(id), j = i + delta;
		if (i < 0 || j < 0 || j >= ids.length) return;
		ids.splice(i, 1);
		ids.splice(j, 0, id);
		var g = G(id);
		saveOrder(ids, T('g_undo_order', g ? g.title : ''));
		setTimeout(function () { var b = document.querySelector('#zmc-glist .grip[data-v="' + id + '"]'); if (b) b.focus(); }, 0);
	}

	/* ── 입력 ─────────────────────────── */
	function click(e, act, v) {
		switch (act) {
			case 'gsel': {
				if (e.target.closest('.grip')) return;
				var id = parseInt(v, 10);
				if (U.sel !== id) { U.sel = id; loadUsage(id); }
				U.pane = 'detail';
				K.render();
				var page = K.ROOT.querySelector('.gpage');
				if (page) page.scrollTop = 0;
				return;
			}
			case 'gback': U.pane = 'list'; K.render(); return;
			case 'gadd': addGroup(); return;
			case 'gdef': setDefault(); return;
			case 'gdelask': askDelete(); return;
			case 'gdelgo': doDelete(); return;
			case 'gmark': openMarks(1); return;
			case 'gmarkpage': openMarks(parseInt(v, 10) || 1); return;
			case 'gmarkset': setMark(v); return;
			case 'gmarkoff': setMark(''); return;
			case 'gmarkall': setMarkAll(!D.image_mark); return;
		}
	}
	function change(e) {
		var t = e.target;
		if (t.id === 'zmc-gdesc') { saveDesc(t.value); return; }
		if (t.id === 'zmc-gname' || t.id === 'lang_zmc-gname') {
			setTimeout(function () { var hidden = document.getElementById('zmc-gname'); if (hidden) saveName(hidden.value); }, 0);
		}
	}
	function key(e) {
		var t = e.target;
		if (e.key === 'Enter' && t && (t.id === 'zmc-gdesc' || t.id === 'lang_zmc-gname' || t.id === 'zmc-gname') && !e.isComposing) {
			e.preventDefault();
			t.blur();
			return true;
		}
		if ((e.key === 'ArrowUp' || e.key === 'ArrowDown') && t && t.classList && t.classList.contains('grip') && t.closest('#zmc-glist')) {
			e.preventDefault();
			moveBy(parseInt(t.getAttribute('data-v'), 10), e.key === 'ArrowUp' ? -1 : 1);
			return true;
		}
		return false;
	}

	var VIEW = {
		tour: [
			{ sel: '.glist-wrap', msel: '.glist-wrap', t: 'g_tour_1_t', d: 'g_tour_1_d' },
			{ sel: '#zmc-uses', msel: '.glist-wrap', t: 'g_tour_2_t', d: 'g_tour_2_d', md: 'g_tour_2_md' },
			{ sel: '.edbar .savestate', msel: '.edbar .savestate', t: 'g_tour_3_t', d: 'g_tour_3_d' }
		],
		init: function (kit) {
			K = kit;
			T = K.T; esc = K.esc; ic = K.ic;
			K.icons.grip = '<circle cx="9" cy="6" r="1.3" fill="currentColor"/><circle cx="15" cy="6" r="1.3" fill="currentColor"/><circle cx="9" cy="12" r="1.3" fill="currentColor"/><circle cx="15" cy="12" r="1.3" fill="currentColor"/><circle cx="9" cy="18" r="1.3" fill="currentColor"/><circle cx="15" cy="18" r="1.3" fill="currentColor"/>';
			K.icons.menu = '<path d="M4 7h16M4 12h16M4 17h10"/>';
			K.icons.image = '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.8"/><path d="M21 16l-5-5-8 8"/>';
			K.icons.layers = '<path d="M12 4l9 5-9 5-9-5z"/><path d="M3 14l9 5 9-5"/>';
			setData(K.B.group_data || {});
			var want = parseInt((K.B.initial || {}).group, 10) || 0;
			U.sel = want && G(want) ? want : (D.groups.length ? D.groups[0].id : 0);
			if (want && G(want)) U.pane = 'detail';
			$(K.ROOT).on('selected.g11n', function () { setTimeout(function () { var hidden = document.getElementById('zmc-gname'); if (hidden) saveName(hidden.value); }, 0); });
			K.ROOT.addEventListener('pointerdown', onPointerDown);
		},
		start: function () { loadUsage(U.sel); },
		refresh: function () { U.usage = {}; load(true); },
		onTour: function () { U.pane = 'list'; },
		html: html,
		beforeRender: beforeRender,
		afterRender: afterRender,
		popHTML: popHTML,
		modalHTML: modalHTML,
		modalClosed: function () { U.del = null; },
		click: click,
		change: change,
		key: key
	};
	window.ZMC_VIEWS = window.ZMC_VIEWS || {};
	window.ZMC_VIEWS.groups = VIEW;
})(jQuery);
