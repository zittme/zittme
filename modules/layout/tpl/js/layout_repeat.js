/**
 * Layout repeat-field editor.
 * Renders add / reorder / delete / visibility / schedule UI for every
 * <div class="rx-repeat"> mounted by layout_info_view.html, and serializes the
 * item cards back into the hidden input as a JSON array on change / submit.
 * See docs/LAYOUT-REPEAT-FIELDS.md
 */
(function () {
  "use strict";
  if (window.__rxrLoaded) return;   // guard against double-include
  window.__rxrLoaded = true;

  function uid() { return "r" + Math.random().toString(36).slice(2, 10); }

  // 'YYYYMMDDHHIISS' <-> datetime-local 'YYYY-MM-DDTHH:MM'
  function toInput(v) {
    if (!v || v.length < 12) return "";
    return v.slice(0, 4) + "-" + v.slice(4, 6) + "-" + v.slice(6, 8) + "T" + v.slice(8, 10) + ":" + v.slice(10, 12);
  }
  function fromInput(v) {
    if (!v) return "";
    var d = v.replace(/[^0-9]/g, "");
    return (d + "000000").slice(0, 14);
  }

  function uploadUrl() {
    return location.origin + "/index.php?module=layout&act=procLayoutAdminRepeatImageUpload";
  }

  function el(tag, cls, html) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (html != null) e.innerHTML = html;
    return e;
  }

  function fieldControl(def, name, value) {
    var type = def.type || "text";
    var wrap = el("label", "rxr-field");
    wrap.appendChild(el("span", "rxr-flabel", def.title || name));
    var input;
    if (type === "textarea") {
      input = el("textarea");
      input.value = value || "";
    } else if (type === "select") {
      input = el("select");
      var opts = def.options || {};
      Object.keys(opts).forEach(function (k) {
        var o = opts[k], label = (o && (o.val || o.title)) || k;
        var opt = el("option"); opt.value = k; opt.textContent = label;
        if (String(value) === String(k)) opt.selected = true;
        input.appendChild(opt);
      });
    } else if (type === "checkbox") {
      input = el("input"); input.type = "checkbox"; input.checked = !!value;
    } else if (type === "colorpicker" || type === "color") {
      input = el("input"); input.type = "color"; input.value = value || "#000000";
    } else if (type === "image") {
      input = el("input"); input.type = "text"; input.value = value || ""; input.placeholder = "이미지 경로";
      input.className = "rxr-imgpath";
      var row = el("div", "rxr-imgrow");
      var preview = el("img", "rxr-preview");
      if (value) { preview.src = "/" + value.replace(/^\//, ""); preview.style.display = "block"; }
      var file = el("input"); file.type = "file"; file.accept = "image/*"; file.className = "rxr-imgfile";
      file.addEventListener("change", function () {
        if (!file.files || !file.files[0]) return;
        var fd = new FormData();
        fd.append("img", file.files[0]);
        fd.append("layout_srl", wrap.closest(".rx-repeat").getAttribute("data-layout-srl"));
        wrap.classList.add("rxr-uploading");
        fetch(uploadUrl(), { method: "POST", body: fd, credentials: "same-origin" })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            var p = j && (j.path || (j.rx && j.rx.path));
            if (p) { input.value = p; preview.src = "/" + p.replace(/^\//, ""); preview.style.display = "block"; serialize(wrap.closest(".rx-repeat")); }
            else alert("업로드 실패");
          })
          .catch(function () { alert("업로드 실패"); })
          .finally(function () { wrap.classList.remove("rxr-uploading"); file.value = ""; });
      });
      row.appendChild(input); row.appendChild(file);
      wrap.appendChild(preview); wrap.appendChild(row);
      input.setAttribute("data-fname", name);
      return wrap;
    } else {
      input = el("input"); input.type = "text"; input.value = value || "";
    }
    input.setAttribute("data-fname", name);
    wrap.appendChild(input);
    return wrap;
  }

  function ic(paths) {
    return '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + paths + "</svg>";
  }
  var SVG = {
    chevron: ic('<path d="M6 9l6 6 6-6"/>'),
    grip: '<svg viewBox="0 0 24 24" width="12" height="14" fill="currentColor"><circle cx="9" cy="6" r="1.4"/><circle cx="15" cy="6" r="1.4"/><circle cx="9" cy="12" r="1.4"/><circle cx="15" cy="12" r="1.4"/><circle cx="9" cy="18" r="1.4"/><circle cx="15" cy="18" r="1.4"/></svg>',
    pencil: ic('<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>'),
    up: ic('<path d="M12 19V5M5 12l7-7 7 7"/>'),
    down: ic('<path d="M12 5v14M19 12l-7 7-7-7"/>'),
    trash: ic('<path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M6 6l1 14a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-14"/>')
  };

  // pick a label for the collapsed summary: main_title, else first text field
  function summaryKey(schema) {
    if (schema.main_title) return "main_title";
    var keys = Object.keys(schema);
    for (var i = 0; i < keys.length; i++) {
      var t = schema[keys[i]].type;
      if (!t || t === "text") return keys[i];
    }
    return keys[0];
  }

  function card(root, schema, item, collapsed) {
    var c = el("div", "rxr-card");
    c.setAttribute("draggable", "true");
    c._id = item._id || uid();
    if (collapsed) c.classList.add("rxr-collapsed");
    var sumKey = summaryKey(schema);

    var head = el("div", "rxr-head");
    var toggle = el("span", "rxr-toggle", SVG.chevron);
    var grip = el("span", "rxr-grip", SVG.grip);
    var summary = el("span", "rxr-summary");
    summary.textContent = (item._label || item[sumKey] || "(제목 없음)");
    function toggleCollapse() { c.classList.toggle("rxr-collapsed"); }
    toggle.addEventListener("click", toggleCollapse);
    summary.addEventListener("click", toggleCollapse);
    // pencil: open the form and focus the admin label
    var pencil = el("button", "rxr-btn rxr-edit", SVG.pencil); pencil.type = "button"; pencil.title = "관리용 제목 수정";
    pencil.onclick = function () { c.classList.remove("rxr-collapsed"); var l = c.querySelector('[data-fname="_label"]'); if (l) { l.focus(); l.select(); } };
    head.appendChild(toggle);
    head.appendChild(grip);
    head.appendChild(summary);
    head.appendChild(pencil);
    c._summary = summary; c._sumKey = sumKey;
    var vis = el("label", "rxr-vis");
    var visInput = el("input"); visInput.type = "checkbox"; visInput.checked = item._visible !== false;
    visInput.className = "rxr-visible";
    vis.appendChild(visInput); vis.appendChild(el("span", null, "노출"));
    head.appendChild(vis);
    var up = el("button", "rxr-btn", SVG.up); up.type = "button"; up.title = "위로";
    var down = el("button", "rxr-btn", SVG.down); down.type = "button"; down.title = "아래로";
    var del = el("button", "rxr-btn rxr-del", SVG.trash); del.type = "button"; del.title = "삭제";
    up.onclick = function () { if (c.previousElementSibling) c.parentNode.insertBefore(c, c.previousElementSibling); serialize(root); };
    down.onclick = function () { if (c.nextElementSibling) c.parentNode.insertBefore(c.nextElementSibling, c); serialize(root); };
    del.onclick = function () { if (confirm("이 항목을 삭제할까요?")) { c.remove(); serialize(root); } };
    head.appendChild(up); head.appendChild(down); head.appendChild(del);
    c.appendChild(head);

    var body = el("div", "rxr-body");
    // built-in admin label (관리용 제목) — core-supported, used for the summary
    var labelField = el("label", "rxr-field rxr-labelfield");
    labelField.appendChild(el("span", "rxr-flabel", "관리용 제목 (관리 목록 표시용)"));
    var labelInput = el("input"); labelInput.type = "text"; labelInput.value = item._label || "";
    labelInput.setAttribute("data-fname", "_label"); labelInput.placeholder = "예: 봄 신상 배너";
    labelField.appendChild(labelInput);
    body.appendChild(labelField);
    Object.keys(schema).forEach(function (fname) {
      body.appendChild(fieldControl(schema[fname], fname, item[fname]));
    });
    // built-in schedule
    var sched = el("div", "rxr-sched");
    var s1 = el("label", "rxr-field"); s1.appendChild(el("span", "rxr-flabel", "노출 시작"));
    var startI = el("input"); startI.type = "datetime-local"; startI.className = "rxr-start"; startI.value = toInput(item._start_date);
    s1.appendChild(startI);
    var s2 = el("label", "rxr-field"); s2.appendChild(el("span", "rxr-flabel", "노출 종료"));
    var endI = el("input"); endI.type = "datetime-local"; endI.className = "rxr-end"; endI.value = toInput(item._end_date);
    s2.appendChild(endI);
    sched.appendChild(s1); sched.appendChild(s2);
    body.appendChild(sched);
    c.appendChild(body);

    function refreshSummary() {
      var lab = c.querySelector('[data-fname="_label"]');
      var ctrl = c.querySelector('[data-fname="' + sumKey + '"]');
      c._summary.textContent = (lab && lab.value) ? lab.value : ((ctrl && ctrl.value) ? ctrl.value : "(제목 없음)");
    }
    c.addEventListener("input", function () { refreshSummary(); serialize(root); });
    c.addEventListener("change", function () { refreshSummary(); serialize(root); });

    // drag reorder
    c.addEventListener("dragstart", function (e) { c.classList.add("rxr-dragging"); e.dataTransfer.effectAllowed = "move"; });
    c.addEventListener("dragend", function () { c.classList.remove("rxr-dragging"); serialize(root); });
    return c;
  }

  function collect(c, schema) {
    var out = { _id: c._id, _visible: c.querySelector(".rxr-visible").checked };
    var labEl = c.querySelector('[data-fname="_label"]');
    out._label = labEl ? labEl.value : "";
    out._start_date = fromInput(c.querySelector(".rxr-start").value);
    out._end_date = fromInput(c.querySelector(".rxr-end").value);
    Object.keys(schema).forEach(function (fname) {
      var ctrl = c.querySelector('[data-fname="' + fname + '"]');
      if (!ctrl) return;
      if (ctrl.type === "checkbox") out[fname] = ctrl.checked;
      else out[fname] = ctrl.value;
    });
    return out;
  }

  function serialize(root) {
    var schema = root._schema;
    var items = [];
    root.querySelectorAll(".rxr-list > .rxr-card").forEach(function (c) { items.push(collect(c, schema)); });
    document.getElementById("repeat_input_" + root.getAttribute("data-name")).value = JSON.stringify(items);
  }

  function init(root) {
    var schema = JSON.parse(root.getAttribute("data-schema") || "{}");
    var value = JSON.parse(root.getAttribute("data-value") || "[]");
    root._schema = schema;

    var list = el("div", "rxr-list");
    root.appendChild(list);
    (value || []).forEach(function (item) { list.appendChild(card(root, schema, item, true)); });

    // drop target
    list.addEventListener("dragover", function (e) {
      e.preventDefault();
      var dragging = list.querySelector(".rxr-dragging");
      if (!dragging) return;
      var after = null, cards = list.querySelectorAll(".rxr-card:not(.rxr-dragging)");
      for (var i = 0; i < cards.length; i++) {
        var box = cards[i].getBoundingClientRect();
        if (e.clientY < box.top + box.height / 2) { after = cards[i]; break; }
      }
      if (after) list.insertBefore(dragging, after); else list.appendChild(dragging);
    });

    var bar = el("div", "rxr-bar");
    var add = el("button", "rxr-add", "＋ 항목 추가");
    add.type = "button";
    add.onclick = function () { list.appendChild(card(root, schema, { _id: uid(), _visible: true }, false)); serialize(root); };
    var foldAll = el("button", "rxr-fold", "모두 접기");
    foldAll.type = "button";
    var folded = true;
    foldAll.onclick = function () {
      folded = !folded;
      list.querySelectorAll(".rxr-card").forEach(function (c) { c.classList.toggle("rxr-collapsed", folded); });
      foldAll.textContent = folded ? "모두 펴기" : "모두 접기";
    };
    bar.appendChild(add); bar.appendChild(foldAll);
    root.appendChild(bar);

    // serialize before the form submits
    var form = root.closest("form");
    if (form && !form._rxrHooked) {
      form._rxrHooked = true;
      form.addEventListener("submit", function () {
        document.querySelectorAll(".rx-repeat").forEach(serialize);
      }, true);
    }
    serialize(root);
  }

  // Initialize any un-initialized mounts. Works whether the settings form is a
  // full page load OR injected dynamically into a slide-in panel (AJAX).
  function boot() {
    document.querySelectorAll(".rx-repeat:not([data-rxr-init])").forEach(function (r) {
      r.setAttribute("data-rxr-init", "1");
      try { init(r); } catch (e) { /* keep other mounts alive */ }
    });
  }
  window.rxrBoot = boot;

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
  // catch mounts added later (dynamically loaded panels)
  try {
    new MutationObserver(boot).observe(document.documentElement, { childList: true, subtree: true });
  } catch (e) { /* no observer: full-page load path already handled */ }
})();
