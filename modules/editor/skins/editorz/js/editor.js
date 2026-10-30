/* editorZ — 툴바 동작 + editorRelKeys 등록(simpleeditor와 동일 패턴).
   ⚠️ editorRelKeys 등록이 없으면 제출 시 content 값이 hidden input에 동기화되지 않아
   "내용값은 필수입니다" 에러가 발생한다 (실제 원인, 2026-07-02 확인). */
(function ($) {
    'use strict';

    var composing = false;

    // 저장/동기화용 HTML — 편집 전용 표시(edz-img-selected)를 제거한 본문을 반환
    window.edzCleanHtml = function (el) {
        if (!el) return '';
        var clone = el.cloneNode(true);
        clone.querySelectorAll('img.edz-img-selected').forEach(function (im) {
            im.classList.remove('edz-img-selected');
            if (!im.getAttribute('class')) im.removeAttribute('class');
        });
        // 브라우저가 aspect-ratio 를 "1.7778 / 1" 로 다시 써 두는데, 서버 필터는 공백 없는 값만 통과시킨다
        clone.querySelectorAll('[style*="aspect-ratio"]').forEach(function (el) {
            el.setAttribute('style', el.getAttribute('style')
                .replace(/aspect-ratio:\s*([0-9.]+)\s*\/\s*1\s*(;|$)/, 'aspect-ratio:$1$2')
                .replace(/aspect-ratio:\s*(\d+)\s*\/\s*(\d+)/, 'aspect-ratio:$1/$2'));
        });
        return clone.innerHTML;
    };

    // 저장돼 있던 글을 에디터에 넣을 때 허용한 태그·속성만 남기고 나머지는 비활성 영역에서 먼저 걸러 낸다
    var LOAD_TAGS = /^(A|ABBR|AUDIO|B|BIG|BLOCKQUOTE|BR|CAPTION|CENTER|CITE|CODE|COL|COLGROUP|DD|DEL|DIV|DL|DT|EM|FIGCAPTION|FIGURE|FONT|H[1-6]|HR|I|IFRAME|IMG|INS|KBD|LI|MARK|OL|P|PRE|Q|S|SAMP|SMALL|SOURCE|SPAN|STRIKE|STRONG|SUB|SUP|TABLE|TBODY|TD|TFOOT|TH|THEAD|TR|TT|U|UL|VIDEO)$/;
    var LOAD_ATTRS = /^(class|title|lang|dir|align|valign|width|height|colspan|rowspan|span|alt|start|type|reversed|border|cellpadding|cellspacing|bgcolor|color|face|size|editor_component|controls|loop|muted|preload|playsinline|frameborder|allow|allowfullscreen|loading|referrerpolicy|scrolling|target|rel|data-[a-z0-9_.:\-]+)$/;
    var LOAD_STYLE_PROPS = /^(color|background|background-color|font|font-size|font-family|font-weight|font-style|text-align|text-decoration|text-indent|line-height|letter-spacing|word-break|word-spacing|white-space|vertical-align|width|height|max-width|max-height|min-width|min-height|aspect-ratio|margin(-(top|right|bottom|left))?|padding(-(top|right|bottom|left))?|border(-(top|right|bottom|left))?(-(width|style|color|radius))?|border-collapse|border-spacing|table-layout|display|float|clear|list-style|list-style-type|object-fit|opacity|box-sizing|overflow|overflow-wrap|text-overflow|direction)$/;
    // 서버 기본 iframe 허용 목록(common/defaults/whitelist.php)과 같은 범위
    var LOAD_IFRAME_SRC = /^https:\/\/(www\.youtube\.com\/embed\/|youtube\.com\/embed\/|www\.youtube-nocookie\.com\/embed\/|player\.vimeo\.com\/video\/|w\.soundcloud\.com\/|www\.google\.com\/maps\/embed|giphy\.com\/|vod\.sooplive\.co\.kr\/|tv\.kakao\.com\/|play-tv\.kakao\.com\/|chzzk\.naver\.com\/)/i;

    function loadSqueeze(value) {
        var out = '', s = String(value);
        for (var i = 0; i < s.length; i++) {
            var c = s.charCodeAt(i);
            if (c <= 32 || (c >= 127 && c <= 160) || c === 5760 || c === 6158 || (c >= 8192 && c <= 8207) || (c >= 8232 && c <= 8239) || c === 8287 || c === 12288 || c === 65279) continue;
            out += s.charAt(i);
        }
        return out;
    }

    function loadUrlOk(value, kind, tag) {
        var s = loadSqueeze(value);
        var m = s.match(/^([a-z][a-z0-9+.\-]*):/i);
        if (!m) return true;
        var scheme = m[1].toLowerCase();
        if (scheme === 'http' || scheme === 'https') return true;
        if (kind === 'href') return scheme === 'mailto' || scheme === 'tel';
        return tag === 'IMG' && /^data:image\/(png|jpe?g|gif|webp|avif);base64,/i.test(s);
    }

    function loadStyleClean(value) {
        var keep = [];
        String(value).replace(/\/\*[\s\S]*?\*\//g, '').split(';').forEach(function (d) {
            var i = d.indexOf(':');
            if (i < 0) return;
            var k = d.slice(0, i).trim().toLowerCase(), v = d.slice(i + 1).trim();
            if (LOAD_STYLE_PROPS.test(k) && v && !/url\s*\(|image-set|expression|javascript:|@import|behavior|binding|[\\<>]/i.test(v)) keep.push(k + ':' + v);
        });
        return keep.join(';');
    }

    function loadSanitize(parent) {
        Array.prototype.slice.call(parent.childNodes).forEach(function (n) {
            if (n.nodeType === 3) return;
            if (n.nodeType !== 1) { n.remove(); return; }
            var tag = n.tagName.toUpperCase();
            if (n.namespaceURI !== 'http://www.w3.org/1999/xhtml') { n.remove(); return; }
            if (!LOAD_TAGS.test(tag)) {
                if (/^(SCRIPT|STYLE|TEMPLATE|NOSCRIPT|OBJECT|EMBED|APPLET|SVG|MATH|META|LINK|BASE|FORM|INPUT|BUTTON|SELECT|TEXTAREA|OPTION|OPTGROUP|FRAME|FRAMESET|HEAD|TITLE|XMP|PLAINTEXT|NOEMBED|NOFRAMES|DIALOG|CANVAS|MAP|AREA|PARAM|TRACK|PORTAL|FENCEDFRAME)$/.test(tag)) { n.remove(); return; }
                loadSanitize(n);
                while (n.firstChild) parent.insertBefore(n.firstChild, n);
                n.remove();
                return;
            }
            var url = null, poster = null;
            Array.prototype.slice.call(n.attributes).forEach(function (a) {
                var name = a.name.toLowerCase();
                if (name === 'style') {
                    var st = loadStyleClean(a.value);
                    if (st) n.setAttribute('style', st); else n.removeAttribute('style');
                } else if (name === 'href' && tag === 'A') {
                    if (!loadUrlOk(a.value, 'href', tag)) n.removeAttribute(a.name);
                } else if (name === 'src' && /^(IMG|IFRAME|VIDEO|AUDIO|SOURCE)$/.test(tag)) {
                    url = a.value;
                } else if (name === 'poster' && tag === 'VIDEO') {
                    poster = a.value;
                } else if (!LOAD_ATTRS.test(name)) {
                    n.removeAttribute(a.name);
                }
            });
            if (tag === 'IFRAME') {
                if (url === null || !LOAD_IFRAME_SRC.test(loadSqueeze(url))) { n.remove(); return; }
                n.setAttribute('src', loadSqueeze(url));
                n.removeAttribute('srcdoc');
                n.removeAttribute('sandbox');
                return;
            }
            if (url !== null && !loadUrlOk(url, 'src', tag)) {
                if (tag === 'IMG') { n.remove(); return; }
                n.removeAttribute('src');
            }
            if (poster !== null && !loadUrlOk(poster, 'src', tag)) n.removeAttribute('poster');
            loadSanitize(n);
        });
    }

    window.edzLoadHtml = function (el, html) {
        var t = document.createElement('template');
        t.innerHTML = String(html || '');
        loadSanitize(t.content);
        while (el.firstChild) el.removeChild(el.firstChild);
        el.appendChild(document.importNode(t.content, true));
    };

    // 웹·워드에서 붙여넣은 HTML 을 글에 쓸 수 있는 태그와 서식만 남겨 정리한다
    var PASTE_KEEP = /^(A|B|STRONG|I|EM|U|S|STRIKE|DEL|SUB|SUP|P|BR|UL|OL|LI|BLOCKQUOTE|TABLE|THEAD|TBODY|TR|TD|TH|IMG|HR|PRE|CODE|H2|H3)$/;
    var PASTE_STYLE_KEEP = /^(text-align|color|background-color|font-weight|font-style|text-decoration)$/;
    window.edzCleanPaste = function (html) {
        var t = document.createElement('template');
        t.innerHTML = String(html || '');
        var root = t.content;
        root.querySelectorAll('script,style,meta,link,title,head,iframe,object,embed,form,input,button,select,textarea,svg,noscript').forEach(function (n) { n.remove(); });
        var walk = function (node) {
            Array.prototype.slice.call(node.childNodes).forEach(function (n) {
                if (n.nodeType === 8) { n.remove(); return; }
                if (n.nodeType !== 1) return;
                walk(n);
                var tag = n.tagName;
                if (/^H1$/.test(tag) || /^H[4-6]$/.test(tag)) { var h = document.createElement(tag === 'H1' ? 'h2' : 'h3'); while (n.firstChild) h.appendChild(n.firstChild); n.replaceWith(h); n = h; tag = h.tagName; }
                if (tag === 'DIV') {
                    if (n.querySelector('p,h2,h3,ul,ol,table,blockquote,pre,hr')) { while (n.firstChild) n.parentNode.insertBefore(n.firstChild, n); n.remove(); return; }
                    var p = document.createElement('p');
                    if (n.getAttribute('style')) p.setAttribute('style', n.getAttribute('style'));
                    while (n.firstChild) p.appendChild(n.firstChild);
                    n.replaceWith(p); n = p; tag = 'P';
                }
                if (!PASTE_KEEP.test(tag)) { while (n.firstChild) n.parentNode.insertBefore(n.firstChild, n); n.remove(); return; }
                var keepStyle = [];
                (n.getAttribute('style') || '').split(';').forEach(function (d) {
                    var i = d.indexOf(':');
                    if (i < 0) return;
                    var k = d.slice(0, i).trim().toLowerCase(), v = d.slice(i + 1).trim();
                    if (PASTE_STYLE_KEEP.test(k) && !/url\(|expression|javascript:/i.test(v)) keepStyle.push(k + ':' + v);
                });
                var keepHref = tag === 'A' ? n.getAttribute('href') : null;
                var keepSrc = tag === 'IMG' ? n.getAttribute('src') : null;
                var keepAlt = tag === 'IMG' ? n.getAttribute('alt') : null;
                Array.prototype.slice.call(n.attributes).forEach(function (a) { n.removeAttribute(a.name); });
                if (keepStyle.length) n.setAttribute('style', keepStyle.join(';'));
                if (keepHref && /^(https?:|mailto:|tel:|#|\/)/i.test(keepHref)) { n.setAttribute('href', keepHref); n.setAttribute('target', '_blank'); n.setAttribute('rel', 'noopener'); }
                if (keepSrc && /^(https?:|\/\/|\/)/i.test(keepSrc)) { n.setAttribute('src', keepSrc); if (keepAlt) n.setAttribute('alt', keepAlt); } else if (tag === 'IMG') { n.remove(); }
            });
        };
        walk(root);
        var div = document.createElement('div');
        div.appendChild(root.cloneNode(true));
        return div.innerHTML;
    };

    function msg(el, key) {
        var w = el && el.closest ? el.closest('.edz') : null;
        return w ? (w.getAttribute('data-m-' + key) || '') : '';
    }

    function getBody(seq) {
        return document.getElementById('edz_instance_' + seq);
    }

    function focusBody(seq) {
        var body = getBody(seq);
        if (body) body.focus();
    }

    function runCmd(seq, cmd, val) {
        // 이미지가 선택된 상태의 정렬: execCommand는 이미지를 빼먹고 옆 문단만 정렬하는
        // 브라우저 버그가 있어, 아예 execCommand를 건너뛰고 이미지에 직접 정렬을 적용한다.
        if (/^justify(Left|Center|Right|Full)$/.test(cmd)) {
            var body = getBody(seq);
            // ① 클릭으로 선택된 이미지 ② 드래그 블록 선택에 포함된 이미지 — 어느 쪽이든
            // 이미지 정렬 의도로 보고 execCommand 없이 이미지만 정렬 (옆 문단 오염 방지)
            var targetImgs = [];
            if (body && lastClickedImg && body.contains(lastClickedImg)) {
                targetImgs = [lastClickedImg];
            } else if (body) {
                targetImgs = imagesInSelection(body);
            }
            if (targetImgs.length) {
                targetImgs.forEach(function (im) { alignImage(im, cmd); });
                return;
            }
        }
        focusBody(seq);
        try {
            document.execCommand(cmd, false, val || null);
        } catch (e) { /* 미지원 브라우저는 조용히 무시 */ }
    }

    function putCaretIn(cell) {
        if (!cell) return;
        var r = document.createRange();
        r.selectNodeContents(cell);
        r.collapse(true);
        var s = window.getSelection();
        s.removeAllRanges();
        s.addRange(r);
    }

    /** 현재 선택 범위에 포함된 본문 이미지 목록 */
    function imagesInSelection(body) {
        var out = [];
        var sel = window.getSelection();
        if (!sel || !sel.rangeCount) return out;
        var range = sel.getRangeAt(0);
        var node = range.commonAncestorContainer;
        var el = node.nodeType === 1 ? node : node.parentElement;
        if (!el || !body.contains(el)) return out;
        if (el.tagName === 'IMG') return [el];
        if (el.querySelectorAll) {
            el.querySelectorAll('img').forEach(function (im) {
                try { if (range.intersectsNode(im)) out.push(im); } catch (e) {}
            });
        }
        return out;
    }

    /** 이미지 1장에 정렬 적용 — 부모 블록 text-align + 이미지 자체 block/margin (확실한 방식) */
    function alignImage(im, cmd) {
        var align = cmd === 'justifyCenter' ? 'center' : (cmd === 'justifyRight' ? 'right' : 'left');
        im.style.display = 'block';
        im.style.marginLeft = (align === 'center' || align === 'right') ? 'auto' : '0';
        im.style.marginRight = (align === 'center' || align === 'left') ? 'auto' : '0';
        var p = im.parentElement;
        if (p && p.tagName !== 'BODY' && !p.classList.contains('edz__body')) {
            p.style.textAlign = align;
        }
        // 저장 동기화 트리거 (input 이벤트)
        try { im.closest('.edz__body').dispatchEvent(new Event('input', { bubbles: true })); } catch (e) {}
    }

    // 마지막으로 클릭한 이미지 — 정렬 시 커서가 이미지를 벗어나 있어도 함께 정렬
    var lastClickedImg = null;

    // 링크 레이어 열기/삽입 — 브라우저 prompt 대신 에디터 내 모달 사용
    var linkSavedRange = null;
    function openLinkLayer(seq) {
        var wrap = document.getElementById('edz_wrap_' + seq);
        var modal = wrap && wrap.querySelector('.edz__modal--link');
        if (!modal) return;
        linkSavedRange = saveRange();
        var urlIn = modal.querySelector('.edz__lurl');
        var blank = modal.querySelector('.edz__lblank');
        var nofollow = modal.querySelector('.edz__lnofollow');
        // 커서가 이미 링크 위/선택 안이면 기존 값 채워 편집
        var a = currentAnchor();
        urlIn.value = a ? a.getAttribute('href') || '' : '';
        blank.checked = a ? a.getAttribute('target') === '_blank' : true;
        nofollow.checked = a ? /nofollow/.test(a.getAttribute('rel') || '') : false;
        modal.querySelector('.edz__lerr').hidden = true;
        modal.hidden = false;
        urlIn.focus();
    }
    function currentAnchor() {
        var s = window.getSelection();
        if (!s || !s.rangeCount) return null;
        var n = s.getRangeAt(0).startContainer;
        while (n && n.nodeType === 3) n = n.parentNode;
        while (n && n.tagName !== 'A' && !(n.classList && n.classList.contains('edz__body'))) n = n.parentNode;
        return (n && n.tagName === 'A') ? n : null;
    }
    function insertLink(seq, modal) {
        var url = modal.querySelector('.edz__lurl').value.trim();
        if (!url) {
            var le = modal.querySelector('.edz__lerr');
            le.textContent = le.getAttribute('data-empty');
            le.hidden = false;
            return;
        }
        var lerr = modal.querySelector('.edz__lerr');
        if (/^[a-z][a-z0-9+.-]*:/i.test(url) && !/^(https?|mailto|tel):/i.test(url)) { lerr.textContent = lerr.getAttribute('data-scheme'); lerr.hidden = false; return; }
        if (!/^([a-z][a-z0-9+.-]*:|\/\/|#|\/)/i.test(url)) url = 'https://' + url;
        var blank = modal.querySelector('.edz__lblank').checked;
        var nofollow = modal.querySelector('.edz__lnofollow').checked;
        focusBody(seq);
        restoreRange(linkSavedRange);
        var existing = currentAnchor();
        if (existing && (window.getSelection() + '') === '') {
            // 선택 없이 링크 위에 커서만 있을 때: 기존 링크 속성만 갱신
            applyAnchorAttrs(existing, url, blank, nofollow);
        } else {
            document.execCommand('createLink', false, url);
            // 방금 만든 링크(들)에 target/rel 적용
            var wrap = document.getElementById('edz_wrap_' + seq);
            var body = wrap.querySelector('.edz__body');
            body.querySelectorAll('a[href="' + url.replace(/"/g, '\\"') + '"]').forEach(function (a) {
                applyAnchorAttrs(a, url, blank, nofollow);
            });
        }
        try { document.getElementById('edz_wrap_' + seq).querySelector('.edz__body').dispatchEvent(new Event('input', { bubbles: true })); } catch (e) {}
        modal.hidden = true;
    }
    function applyAnchorAttrs(a, url, blank, nofollow) {
        a.setAttribute('href', url);
        if (blank) { a.setAttribute('target', '_blank'); } else { a.removeAttribute('target'); }
        var rel = [];
        if (blank) rel.push('noopener');
        if (nofollow) rel.push('nofollow');
        if (rel.length) { a.setAttribute('rel', rel.join(' ')); } else { a.removeAttribute('rel'); }
    }

    function insertHr(seq) {
        focusBody(seq);
        document.execCommand('insertHTML', false, '<hr><p><br></p>');
    }

    // 색은 고정하지 않는다. 밝은 사이트와 어두운 사이트 어느 쪽에서 보아도 읽히도록 반투명 회색과 글자색 상속만 쓴다.
    function insertQuote(seq) {
        focusBody(seq);
        document.execCommand('formatBlock', false, 'BLOCKQUOTE');
        var body = getBody(seq);
        if (!body) return;
        body.querySelectorAll('blockquote').forEach(function (bq) {
            if (bq.getAttribute('data-edz-quote')) return;
            bq.setAttribute('data-edz-quote', '1');
            bq.style.cssText = 'margin:12px 0;padding:10px 16px;border-left:3px solid rgba(128,128,128,.6);background:rgba(128,128,128,.1);border-radius:0 6px 6px 0;';
        });
        try { body.dispatchEvent(new Event('input', { bubbles: true })); } catch (e) {}
    }

    // 표: 레이어에서 행/열 입력 후 삽입
    var tableSavedRange = null;
    function openTableLayer(seq) {
        var wrap = document.getElementById('edz_wrap_' + seq);
        var modal = wrap && wrap.querySelector('.edz__modal--table');
        if (!modal) return;
        tableSavedRange = saveRange();
        modal.hidden = false;
        var rowsIn = modal.querySelector('.edz__num--rows');
        if (rowsIn) { rowsIn.focus(); rowsIn.select(); }
    }
    var TABLE_STYLE = 'border-collapse:collapse;width:100%;table-layout:fixed;margin:12px 0';
    var CELL_STYLE = 'border:1px solid rgba(128,128,128,.45);padding:8px 10px;vertical-align:top;word-break:break-word';
    function insertTableFromModal(seq, modal) {
        var rows = Math.max(1, Math.min(30, parseInt(modal.querySelector('.edz__num--rows').value, 10) || 3));
        var cols = Math.max(1, Math.min(10, parseInt(modal.querySelector('.edz__num--cols').value, 10) || 3));
        var pct = (100 / cols).toFixed(4);
        var colgroup = '';
        for (var c = 0; c < cols; c++) colgroup += '<col style="width:' + pct + '%">';
        var cells = '';
        for (c = 0; c < cols; c++) cells += '<td style="' + CELL_STYLE + '"><br></td>';
        var trs = '';
        for (var r = 0; r < rows; r++) trs += '<tr>' + cells + '</tr>';
        var html = '<table class="edz-table" style="' + TABLE_STYLE + '"><colgroup>' + colgroup + '</colgroup><tbody>' + trs + '</tbody></table><p><br></p>';
        focusBody(seq);
        restoreRange(tableSavedRange);
        document.execCommand('insertHTML', false, html);
        modal.hidden = true;
    }

    // 표 리사이즈: 셀 오른쪽 경계=인접 두 열 함께 조정(총폭 유지), 아래 경계=행 높이
    var MINCOL = 40;
    // 드래그 시작 시 모든 열을 현재 렌더 폭 기준 px로 고정(px/% 혼용 방지)
    function lockColsToPx(table) {
        var cg = table.querySelector('colgroup');
        var firstRow = table.tBodies[0] && table.tBodies[0].rows[0];
        if (!cg || !firstRow) return null;
        var already = cg.children[0] && /px$/.test(cg.children[0].style.width || '');
        if (!already) {
            var total = 0;
            Array.prototype.forEach.call(cg.children, function (col, k) {
                var cell = firstRow.cells[k];
                var w = Math.round(cell ? cell.getBoundingClientRect().width : 80);
                col.style.width = w + 'px';
                total += w;
            });
            table.style.tableLayout = 'fixed';
            table.style.width = total + 'px';
        }
        return cg;
    }
    function initTableResize(bodyEl) {
        var resizing = null;
        bodyEl.addEventListener('mousemove', function (e) {
            if (resizing) return;
            var td = e.target.closest ? e.target.closest('td,th') : null;
            if (!td || !bodyEl.contains(td)) { bodyEl.style.cursor = ''; return; }
            var rect = td.getBoundingClientRect();
            if (e.clientX > rect.right - 6) bodyEl.style.cursor = 'col-resize';
            else if (e.clientY > rect.bottom - 6) bodyEl.style.cursor = 'row-resize';
            else bodyEl.style.cursor = '';
        });
        // 이미지 클릭 → 이미지 노드 자체를 선택 + 마지막 클릭 이미지로 기억 (정렬/삭제 커맨드 대상)
        bodyEl.addEventListener('click', function (e) {
            if (!e.target || e.target.tagName !== 'IMG') {
                lastClickedImg = null;
                bodyEl.querySelectorAll('img.edz-img-selected').forEach(function (im) { im.classList.remove('edz-img-selected'); });
                return;
            }
            lastClickedImg = e.target;
            bodyEl.querySelectorAll('img.edz-img-selected').forEach(function (im) { im.classList.remove('edz-img-selected'); });
            e.target.classList.add('edz-img-selected');
            try {
                var sel = window.getSelection();
                var r = document.createRange();
                r.selectNode(e.target);
                sel.removeAllRanges();
                sel.addRange(r);
            } catch (err) {}
        });
        // 타이핑을 시작하면 이미지 기억 해제 (텍스트 정렬 의도로 전환)
        bodyEl.addEventListener('keydown', function () { lastClickedImg = null; });
        bodyEl.addEventListener('mousedown', function (e) {
            var td = e.target.closest ? e.target.closest('td,th') : null;
            if (!td || !bodyEl.contains(td)) return;
            var rect = td.getBoundingClientRect();
            if (e.clientX > rect.right - 6) {
                var table = td.closest('table.edz-table');
                var cg = lockColsToPx(table);
                if (!cg) return;
                var i = td.cellIndex, last = cg.children.length - 1;
                var colA = cg.children[i], colB = (i < last) ? cg.children[i + 1] : null;
                resizing = {
                    type: 'col', table: table, colA: colA, colB: colB, startX: e.clientX,
                    startA: parseFloat(colA.style.width) || rect.width,
                    startB: colB ? (parseFloat(colB.style.width) || 0) : 0,
                    startTableW: parseFloat(table.style.width) || 0
                };
                e.preventDefault();
            } else if (e.clientY > rect.bottom - 6) {
                var tr = td.parentNode;
                resizing = { type: 'row', tr: tr, startY: e.clientY, startH: tr.getBoundingClientRect().height };
                e.preventDefault();
            }
        });
        document.addEventListener('mousemove', function (e) {
            if (!resizing) return;
            if (resizing.type === 'col') {
                var dx = e.clientX - resizing.startX;
                if (resizing.colB) {
                    // 인접 두 열 함께 조정 → 총폭 유지, 다른 열은 그대로. 클램프 재보정으로 포인터 일치.
                    if (resizing.startA + dx < MINCOL) dx = MINCOL - resizing.startA;
                    if (resizing.startB - dx < MINCOL) dx = resizing.startB - MINCOL;
                    resizing.colA.style.width = (resizing.startA + dx) + 'px';
                    resizing.colB.style.width = (resizing.startB - dx) + 'px';
                } else {
                    // 마지막 열: 열 + 테이블 전체 폭 함께 증감
                    if (resizing.startA + dx < MINCOL) dx = MINCOL - resizing.startA;
                    resizing.colA.style.width = (resizing.startA + dx) + 'px';
                    resizing.table.style.width = (resizing.startTableW + dx) + 'px';
                }
            } else if (resizing.type === 'row') {
                resizing.tr.style.height = Math.max(24, resizing.startH + (e.clientY - resizing.startY)) + 'px';
            }
        });
        document.addEventListener('mouseup', function () {
            if (!resizing) return;
            if (resizing.type === 'col') {
                // 화면 폭이 달라져도 맞도록 px 를 다시 비율로 돌려 둔다
                var cols = resizing.table.querySelectorAll('colgroup > col'), sum = 0;
                cols.forEach(function (c) { sum += parseFloat(c.style.width) || 0; });
                if (sum) {
                    cols.forEach(function (c) { c.style.width = ((parseFloat(c.style.width) || 0) / sum * 100).toFixed(3) + '%'; });
                    resizing.table.style.width = '100%';
                }
            }
            resizing = null; bodyEl.style.cursor = '';
        });
    }

    // 표 플로팅 툴바: 셀 안에 커서가 있으면 표 위에 떠서 셀 배경색·행/열 추가삭제 제공
    function initTableToolbar(bodyEl, wrap) {
        var ttool = wrap.querySelector('.edz__ttool');
        if (!ttool) return;
        var activeCell = null, activeTable = null;

        function currentCell() {
            var s = window.getSelection();
            if (!s.rangeCount) return null;
            var n = s.anchorNode;
            var el = n.nodeType === 1 ? n : n.parentElement;
            return el && el.closest ? el.closest('td,th') : null;
        }
        function update() {
            var cell = currentCell();
            if (cell && bodyEl.contains(cell)) {
                activeCell = cell;
                activeTable = cell.closest('table.edz-table');
                var tr = activeTable.getBoundingClientRect(), wr = wrap.getBoundingClientRect();
                ttool.style.left = Math.max(0, tr.left - wr.left) + 'px';
                ttool.style.top = Math.max(0, tr.top - wr.top - 40) + 'px';
                ttool.hidden = false;
            } else {
                ttool.hidden = true; activeCell = null; activeTable = null;
            }
        }
        bodyEl.addEventListener('keyup', update);
        bodyEl.addEventListener('mouseup', update);

        // 툴바 클릭이 셀 커서를 뺏지 않게 (커스텀 피커 제외)
        ttool.addEventListener('mousedown', function (e) { if (!e.target.closest('.edz__pick')) e.preventDefault(); });

        var colorBtn = ttool.querySelector('#edzCellColorBtn');
        var colorPop = ttool.querySelector('#edzCellColorPop');
        if (colorBtn && colorPop) {
            colorBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                var h = colorPop.hasAttribute('hidden');
                document.querySelectorAll('.edz__pop').forEach(function (p) { p.setAttribute('hidden', ''); });
                if (h) colorPop.removeAttribute('hidden');
            });
            colorPop.querySelectorAll('.edz__sw').forEach(function (sw) {
                if (sw.classList.contains('edz__sw--pick')) return;
                sw.addEventListener('click', function (e) {
                    e.stopPropagation();
                    if (activeCell) activeCell.style.background = (sw.getAttribute('data-color') === 'transparent' ? '' : sw.getAttribute('data-color'));
                    colorPop.setAttribute('hidden', '');
                });
            });
            var pick = colorPop.querySelector('.edz__pick');
            if (pick) pick.addEventListener('input', function () { if (activeCell) activeCell.style.background = pick.value; });
        }

        ttool.querySelectorAll('[data-tact]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (!activeCell || !activeTable) return;
                var act = btn.getAttribute('data-tact');
                var tr = activeCell.parentNode, idx = activeCell.cellIndex;
                var rows = activeTable.tBodies[0].rows;
                var cg = activeTable.querySelector('colgroup');
                if (act === 'row-add') {
                    var nr = tr.cloneNode(true);
                    Array.prototype.forEach.call(nr.cells, function (c) { c.innerHTML = '<br>'; c.style.background = ''; });
                    nr.style.height = '';
                    tr.parentNode.insertBefore(nr, tr.nextSibling);
                } else if (act === 'row-del') {
                    if (rows.length > 1) {
                        var nextRow = tr.nextElementSibling || tr.previousElementSibling;
                        tr.parentNode.removeChild(tr);
                        activeCell = nextRow.cells[Math.min(idx, nextRow.cells.length - 1)];
                    }
                } else if (act === 'col-add') {
                    Array.prototype.forEach.call(rows, function (row) {
                        var nc = row.insertCell(idx + 1);
                        nc.innerHTML = '<br>';
                        nc.setAttribute('style', CELL_STYLE);
                    });
                    if (cg) {
                        var col = document.createElement('col');
                        if (cg.children[idx] && cg.children[idx].nextSibling) cg.insertBefore(col, cg.children[idx].nextSibling);
                        else cg.appendChild(col);
                        activeTable.style.width = ''; // px 고정 해제 → 100% 폭으로 균등 재분배
                        normalizeCols(cg);
                    }
                } else if (act === 'col-del') {
                    if (rows[0].cells.length > 1) {
                        Array.prototype.forEach.call(rows, function (row) { if (row.cells[idx]) row.deleteCell(idx); });
                        activeCell = tr.cells[Math.min(idx, tr.cells.length - 1)];
                        if (cg && cg.children[idx]) { cg.removeChild(cg.children[idx]); activeTable.style.width = ''; normalizeCols(cg); }
                    }
                }
                putCaretIn(activeCell);
                update();
                try { bodyEl.dispatchEvent(new Event('input', { bubbles: true })); } catch (er) {}
            });
        });

        function normalizeCols(cg) {
            var n = cg.children.length; if (!n) return;
            var w = (100 / n).toFixed(4);
            Array.prototype.forEach.call(cg.children, function (c) { c.style.width = w + '%'; });
        }
    }

    // 문단 정리: Enter 가 언제나 같은 모양(<p> 한 줄)이 되도록 본문 맨 위 단계를 블록으로만 둔다.
    //   문단 밖에 떠 있는 글자·인라인은 <p> 로 감싸고, 속성 없는 맨 위 <div> 는 <p> 로 바꾼다.
    var BLOCK_TAGS = /^(P|H[1-6]|UL|OL|BLOCKQUOTE|TABLE|HR|PRE|FIGURE|DIV|SECTION|ARTICLE|ASIDE|NAV|DL|IFRAME|VIDEO|AUDIO|DETAILS|FORM)$/;
    function isPlainDiv(el) {
        if (el.tagName !== 'DIV' || el.attributes.length) return false;
        for (var i = 0; i < el.children.length; i++) { if (BLOCK_TAGS.test(el.children[i].tagName)) return false; }
        return true;
    }
    function normalizeBody(bodyEl) {
        var sel = window.getSelection();
        var keep = (sel && sel.rangeCount && bodyEl.contains(sel.anchorNode)) ? sel.getRangeAt(0) : null;
        var kNode = keep ? keep.startContainer : null, kOff = keep ? keep.startOffset : 0;
        var changed = false;
        // 문단 안에 블록이 든 것(<p><ul>…</ul></p> — 빈 줄에서 목록을 켜면 크롬이 이렇게 만든다)은
        //   문단을 풀어 블록을 밖으로 꺼낸다. 남은 글자는 아래 맨 위 단계 정리에서 다시 <p> 로 감싼다.
        var nested;
        while ((nested = Array.prototype.filter.call(bodyEl.querySelectorAll('p'), function (p) {
            for (var i = 0; i < p.children.length; i++) { if (BLOCK_TAGS.test(p.children[i].tagName)) return true; }
            return false;
        })).length) {
            var outer = nested[0], parent = outer.parentNode;
            while (outer.firstChild) parent.insertBefore(outer.firstChild, outer);
            parent.removeChild(outer);
            changed = true;
        }
        var node = bodyEl.firstChild, run = null;
        while (node) {
            var next = node.nextSibling;
            var inline = (node.nodeType === 3) || (node.nodeType === 1 && !BLOCK_TAGS.test(node.tagName));
            if (node.nodeType === 3 && !run && !/\S/.test(node.nodeValue) && (!next || next.nodeType === 1 && BLOCK_TAGS.test(next.tagName))) {
                // 블록 사이 공백 글자는 지운다 (캐럿이 없을 때만)
                if (kNode !== node) { bodyEl.removeChild(node); changed = true; }
            } else if (inline && node.nodeType === 1 && node.tagName === 'BR') {
                // 문단 밖 <br> 은 문단 경계. 앞에 글이 없으면 빈 줄 하나
                if (run) { bodyEl.removeChild(node); }
                else { var blank = document.createElement('p'); bodyEl.replaceChild(blank, node); blank.appendChild(node); }
                run = null;
                changed = true;
            } else if (inline && node.nodeType !== 8) {
                if (!run) { run = document.createElement('p'); bodyEl.insertBefore(run, node); }
                run.appendChild(node);
                changed = true;
            } else {
                run = null;
                if (node.nodeType === 1 && isPlainDiv(node)) {
                    var p = document.createElement('p');
                    while (node.firstChild) p.appendChild(node.firstChild);
                    bodyEl.replaceChild(p, node);
                    changed = true;
                }
            }
            node = next;
        }
        // 속이 빈 문단은 캐럿이 들어갈 수 있게 <br> 을 넣는다
        bodyEl.querySelectorAll(':scope > p').forEach(function (p) {
            if (!p.firstChild) { p.appendChild(document.createElement('br')); changed = true; }
        });
        if (!bodyEl.firstChild) {
            var e = document.createElement('p');
            e.appendChild(document.createElement('br'));
            bodyEl.appendChild(e);
            changed = true;
        }
        if (changed && kNode && bodyEl.contains(kNode)) {
            try { var r = document.createRange(); r.setStart(kNode, Math.min(kOff, kNode.nodeType === 3 ? kNode.length : kNode.childNodes.length)); r.collapse(true); sel.removeAllRanges(); sel.addRange(r); } catch (er) {}
        }
        return changed;
    }
    window.edzNormalize = normalizeBody;

    // Tab=목록/문단 들여쓰기, 인용(blockquote)에서 빈 줄 Enter=인용 탈출
    function bindKeys(bodyEl, seq) {
        // Enter 가 <div> 대신 <p> 를 만들게 한다 (크롬·사파리 기본값은 div)
        var setSep = function () {
            try {
                document.execCommand('defaultParagraphSeparator', false, 'p');
                document.execCommand('styleWithCSS', false, true);
            } catch (e) {}
        };
        setSep();
        bodyEl.addEventListener('focus', setSep);
        if (!bodyEl.children.length && !/\S/.test(bodyEl.textContent)) bodyEl.innerHTML = '<p><br></p>';
        normalizeBody(bodyEl);
        // 전체 선택 뒤 지우기 등으로 문단 밖에 글자가 생기면 바로 문단으로 감싼다
        bodyEl.addEventListener('compositionstart', function () { composing = true; });
        bodyEl.addEventListener('compositionend', function () {
            composing = false;
            bodyEl.dispatchEvent(new Event('input', { bubbles: true }));
        });
        bodyEl.addEventListener('input', function () {
            if (composing) return;
            var stray = false;
            for (var n = bodyEl.firstChild; n; n = n.nextSibling) {
                if ((n.nodeType === 3 && /\S/.test(n.nodeValue)) || (n.nodeType === 1 && (!BLOCK_TAGS.test(n.tagName) || isPlainDiv(n)))) { stray = true; break; }
            }
            if (!stray) stray = !!bodyEl.querySelector('p > ul, p > ol, p > p, p > table, p > blockquote, p > h2, p > h3, p > div, p > hr');
            if (stray || !bodyEl.firstChild) normalizeBody(bodyEl);
        });
        bodyEl.addEventListener('keydown', function (e) {
            if (composing || e.isComposing) return;
            // 빈 본문에서 첫 글자를 칠 때 문단 안에서 시작하도록
            if (!bodyEl.firstChild || (bodyEl.childNodes.length === 1 && bodyEl.firstChild.nodeName === 'BR')) {
                bodyEl.innerHTML = '<p><br></p>';
                var r0 = document.createRange(); r0.setStart(bodyEl.firstChild, 0); r0.collapse(true);
                var s0 = window.getSelection(); s0.removeAllRanges(); s0.addRange(r0);
            }
        }, true);
        bodyEl.addEventListener('keydown', function (e) {
            if (e.key === 'Tab' && !composing) {
                var tn = window.getSelection().anchorNode;
                var tel = tn && (tn.nodeType === 1 ? tn : tn.parentElement);
                if (tel && tel.closest && tel.closest('li')) {
                    e.preventDefault();
                    document.execCommand(e.shiftKey ? 'outdent' : 'indent');
                }
                return;
            }
            if (e.key === 'Enter' && !e.shiftKey) {
                var sel = window.getSelection();
                if (!sel.rangeCount) return;
                var n = sel.anchorNode;
                var el = (n.nodeType === 1) ? n : n.parentElement;
                var bq = el && el.closest ? el.closest('blockquote') : null;
                if (bq && bodyEl.contains(bq)) {
                    // 인용 안에서 Enter = 빈 줄 만들지 않고 즉시 인용 밖으로 (여러 줄 인용은 Shift+Enter)
                    e.preventDefault();
                    var p = document.createElement('p');
                    p.appendChild(document.createElement('br'));
                    if (bq.nextSibling) bq.parentNode.insertBefore(p, bq.nextSibling);
                    else bq.parentNode.appendChild(p);
                    var r = document.createRange();
                    r.setStart(p, 0); r.collapse(true);
                    sel.removeAllRanges(); sel.addRange(r);
                }
            }
        });
        initTableResize(bodyEl);
        var wrap = document.getElementById('edz_wrap_' + seq);
        if (wrap) initTableToolbar(bodyEl, wrap);
    }

    // 에디터가 속한 wrap에서 재사용된 xeUploader의 실제 file input을 찾아 클릭(업로드 다이얼로그 오픈)
    function triggerFileInput(seq) {
        var wrap = document.getElementById('edz_wrap_' + seq);
        if (!wrap) return;
        var input = wrap.querySelector('.xefu-dropzone input[type="file"]');
        if (input) {
            input.click();
        } else {
            window.alert(msg(wrap, 'fileoff'));
        }
    }

    // YouTube 링크에서 videoId 추출 (youtu.be / watch?v= / embed / shorts / live 지원)
    function parseYouTubeId(url) {
        if (!url) return null;
        var m = String(url).match(/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/|live\/|v\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/);
        return m ? m[1] : null;
    }
    // 반응형 16:9 YouTube 임베드 HTML (PC/모바일 모두 비율 유지)
    var EMBED_STYLE = 'width:100%;aspect-ratio:1.7778;border:0;display:block;margin:12px 0';
    function youtubeEmbedHtml(id) {
        return '<iframe src="https://www.youtube.com/embed/' + id + '" style="' + EMBED_STYLE +
            '" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe><p><br></p>';
    }

    // Vimeo 링크에서 videoId 추출
    function parseVimeoId(url) {
        if (!url) return null;
        var m = String(url).match(/vimeo\.com\/(?:video\/|channels\/[\w]+\/|groups\/[\w]+\/videos\/)?(\d+)/);
        return m ? m[1] : null;
    }
    function vimeoEmbedHtml(id) {
        return '<iframe src="https://player.vimeo.com/video/' + id + '" style="' + EMBED_STYLE +
            '" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe><p><br></p>';
    }

    // 지원 플랫폼 목록 — URL 을 넣으면 {label, html} 을 돌려준다. 여기 한 줄만 추가하면 플랫폼이 늘어난다.
    var VIDEO_PLATFORMS = [
        { key: 'youtube', label: 'YouTube', parse: parseYouTubeId, embed: youtubeEmbedHtml },
        { key: 'vimeo', label: 'Vimeo', parse: parseVimeoId, embed: vimeoEmbedHtml }
    ];
    function detectVideo(url) {
        for (var i = 0; i < VIDEO_PLATFORMS.length; i++) {
            var id = VIDEO_PLATFORMS[i].parse(url);
            if (id) return { label: VIDEO_PLATFORMS[i].label, html: VIDEO_PLATFORMS[i].embed(id) };
        }
        return null;
    }

    // 동영상 레이어 열기/조작
    var videoSavedRange = null;
    function openVideoLayer(seq) {
        var wrap = document.getElementById('edz_wrap_' + seq);
        var modal = wrap && wrap.querySelector('.edz__modal--video');
        if (!modal) return;
        videoSavedRange = saveRange();
        var urlIn = modal.querySelector('.edz__vurl');
        if (urlIn) urlIn.value = '';
        resetVideoModal(modal);
        modal.hidden = false;
        if (urlIn) urlIn.focus();
    }
    function resetVideoModal(modal) {
        var pv = modal.querySelector('.edz__vpreview');
        var plat = modal.querySelector('.edz__vplat');
        pv.hidden = true; pv.innerHTML = '';
        plat.hidden = true; plat.textContent = '';
        modal.querySelector('.edz__verr').hidden = true;
        modal.querySelector('.edz__vinsert').disabled = true;
    }
    function insertVideo(seq, modal) {
        var url = modal.querySelector('.edz__vurl').value.trim();
        var v = detectVideo(url);
        if (!v) { modal.querySelector('.edz__verr').hidden = false; return; }
        focusBody(seq);
        restoreRange(videoSavedRange);
        document.execCommand('insertHTML', false, v.html);
        modal.hidden = true;
    }

    // 선택영역 저장/복원 (커스텀 color picker는 다이얼로그가 뜨며 포커스가 빠져 선택이 사라지므로)
    function saveRange() {
        var s = window.getSelection();
        return (s && s.rangeCount) ? s.getRangeAt(0).cloneRange() : null;
    }
    function restoreRange(range) {
        if (!range) return;
        var s = window.getSelection();
        s.removeAllRanges();
        s.addRange(range);
    }

    // 이모지 데이터 (외부 라이브러리 없이 유니코드만 사용)
    var EMOJI = {
        face: '😀 😃 😄 😁 😆 😅 😂 🤣 😊 🙂 😉 😍 🥰 😘 😗 😋 😛 🤪 🤨 🧐 🤓 😎 🥳 😏 😒 😞 😔 😟 😕 🙁 😣 😖 😫 😩 🥺 😢 😭 😤 😠 😡 🤬 😱 😨 😰 😥 😓 🤔 🤗 🤭 🤫 😴 🤤 😪 😵 🤐 🥴 🤢 🤮 🤧 😷 🤒 🤕 🥶 🥵 😇 🤠',
        gesture: '👍 👎 👌 🤌 🤏 ✌️ 🤞 🤟 🤘 🤙 👈 👉 👆 👇 ☝️ ✋ 🤚 🖐️ 🖖 👋 🤝 🙏 ✍️ 💪 🦾 👏 🙌 👐 🤲 🤜 🤛 ✊ 👊 🫶 🫰',
        heart: '❤️ 🧡 💛 💚 💙 💜 🖤 🤍 🤎 💔 ❣️ 💕 💞 💓 💗 💖 💘 💝 💟 💌 🔥 ✨ ⭐ 🌟 💫 💯 ✅ ❌ ⭕ 🎉 🎊 🏆 🥇 👑 💎',
        object: '🎁 🎈 🎀 🛒 💰 💳 💸 🏷️ 📦 📱 💻 ⌨️ 🖥️ 🖨️ 📷 📸 🎥 🔔 📢 📣 💡 🔦 🔋 🔌 🧾 📌 📍 📎 🔗 ✏️ 🖊️ 📝 📚 📖 🗓️ ⏰ ⏳ 🔒 🔑 🛎️',
        nature: '🌿 🍀 🌱 🌳 🌲 🌴 🌵 🌷 🌸 💐 🌹 🌺 🌻 🌼 🌞 🌝 🌛 🌙 ⭐ ☁️ ⛅ 🌈 ☔ ❄️ ⛄ 💧 🌊 🔥 🍃 🐶 🐱 🐰 🐻 🐼 🐨 🦊 🐧 🐦 🦋 🐝',
        food: '🍎 🍏 🍊 🍋 🍌 🍉 🍇 🍓 🫐 🍈 🍒 🍑 🥭 🍍 🥥 🥝 🍅 🍆 🥑 🥦 🥬 🥒 🌽 🥕 🧄 🧅 🥔 🍠 🥐 🍞 🧀 🍗 🍖 🍔 🍟 🍕 🌭 🥪 🍜 🍣 🍰 🍩 🍪 ☕ 🍵 🍺'
    };
    function buildEmojiGrid(grid, cat) {
        grid.innerHTML = '';
        (EMOJI[cat] || '').split(' ').forEach(function (ch) {
            if (!ch) return;
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'edz__emoji';
            b.textContent = ch;
            grid.appendChild(b);
        });
    }

    // 서식 지우기 — 선택 영역의 인라인 서식·형광펜·링크·색을 제거하고 일반 본문으로
    function clearFormat(seq) {
        focusBody(seq);
        try { document.execCommand('removeFormat', false, null); } catch (e) {}
        try { document.execCommand('unlink', false, null); } catch (e) {}
        // removeFormat 이 못 지우는 배경색(형광펜) 잔여 정리
        try { document.execCommand('hiliteColor', false, 'transparent'); } catch (e) {}
        try {
            var body = getBody(seq);
            if (body) body.dispatchEvent(new Event('input', { bubbles: true }));
        } catch (e) {}
    }

    // 글자색/형광펜 팝오버 공용 처리 (팔레트 스와치 + 커스텀 color picker)
    // focusFn: 해당 에디터 본문에 포커스, applyFn(color): 색 적용(execCommand)
    function bindPopover(toggleEl, popEl, focusFn, applyFn) {
        if (!toggleEl || !popEl) return;
        var saved = null;
        toggleEl.addEventListener('click', function (e) {
            e.stopPropagation();
            var isHidden = popEl.hasAttribute('hidden');
            document.querySelectorAll('.edz__pop').forEach(function (p) { p.setAttribute('hidden', ''); });
            if (isHidden) { saved = saveRange(); popEl.removeAttribute('hidden'); }
        });
        function commit(color) {
            focusFn();
            restoreRange(saved);
            applyFn(color);
        }
        // 팔레트 스와치 클릭 (커스텀 피커 label 안의 것은 제외 — input이 따로 처리)
        popEl.querySelectorAll('.edz__sw').forEach(function (sw) {
            if (sw.classList.contains('edz__sw--pick')) return;
            sw.addEventListener('click', function (e) {
                e.stopPropagation();
                commit(sw.getAttribute('data-color'));
                popEl.setAttribute('hidden', '');
            });
        });
        // 커스텀 color picker: 값 변경 시 적용 (input=실시간, change=확정 후 닫기)
        var pick = popEl.querySelector('.edz__pick');
        if (pick) {
            pick.addEventListener('input', function (e) { e.stopPropagation(); commit(pick.value); });
            pick.addEventListener('change', function (e) { e.stopPropagation(); commit(pick.value); popEl.setAttribute('hidden', ''); });
        }
    }

    document.addEventListener('click', function () {
        document.querySelectorAll('.edz__pop').forEach(function (p) { p.setAttribute('hidden', ''); });
    });
    // 커서가 있는 곳의 서식을 툴바에 켜진 상태로 보여 준다
    var ACTIVE_CMDS = ['bold', 'italic', 'underline', 'strikeThrough', 'insertUnorderedList', 'insertOrderedList'];
    document.addEventListener('selectionchange', function () {
        var sel = window.getSelection();
        var node = sel && sel.anchorNode;
        var body = node && (node.nodeType === 1 ? node : node.parentElement);
        body = body && body.closest ? body.closest('.edz__body') : null;
        if (!body) return;
        var wrap = body.closest('.edz');
        ACTIVE_CMDS.forEach(function (cmd) {
            var on = false;
            try { on = document.queryCommandState(cmd); } catch (e) {}
            wrap.querySelectorAll('.edz__tool [data-cmd="' + cmd + '"]').forEach(function (b) { b.classList.toggle('is-on', on); });
        });
        var label = wrap.querySelector('#edzFmtLabel');
        if (label) {
            var blk = '';
            try { blk = String(document.queryCommandValue('formatBlock') || '').toUpperCase(); } catch (e) {}
            label.textContent = blk === 'H2' ? msg(wrap, 'h2') : (blk === 'H3' ? msg(wrap, 'h3') : msg(wrap, 'p'));
        }
    });
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && e.key.toLowerCase() === 'k') {
            var t = e.target.closest ? e.target.closest('.edz__body') : null;
            if (t) {
                e.preventDefault();
                openLinkLayer(t.getAttribute('data-editor-sequence'));
            }
            return;
        }
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.edz__pop').forEach(function (p) { p.setAttribute('hidden', ''); });
        document.querySelectorAll('.edz__modal').forEach(function (m) { m.hidden = true; });
    });

    // 글이 놓이는 자리의 실제 글자색이 밝으면 어두운 화면으로 본다
    function syncScheme() {
        document.querySelectorAll('.edz').forEach(function (wrap) {
            var host = wrap.parentElement || document.body;
            var m = (getComputedStyle(host).color || '').match(/[0-9.]+/g);
            if (!m || m.length < 3) return;
            var lum = 0.299 * m[0] + 0.587 * m[1] + 0.114 * m[2];
            wrap.setAttribute('data-edz-scheme', lum > 140 ? 'dark' : 'light');
        });
    }

    function bindToolbar(toolbar) {
        var seq = toolbar.getAttribute('data-editor-sequence');

        // ★ 선택영역 유지: 툴바 요소를 mousedown할 때 기본동작(포커스 이동)을 막아
        //   contenteditable의 선택(range)이 사라지지 않게 한다 → 색상/서식이 블록에 정상 적용.
        //   단, 커스텀 color picker(input[type=color])는 다이얼로그를 열어야 하므로 예외(선택은 저장/복원으로 처리).
        toolbar.addEventListener('mousedown', function (e) {
            if (e.target.closest('.edz__pick')) return;
            e.preventDefault();
        });

        toolbar.querySelectorAll('[data-cmd]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                runCmd(seq, btn.getAttribute('data-cmd'), btn.getAttribute('data-val'));
            });
        });

        // 본문 포맷 드롭다운 (본문/대제목/소제목)
        var fmtBtn = toolbar.querySelector('#edzFmtBtn');
        var fmtPop = toolbar.querySelector('#edzFmtPop');
        var fmtLabel = toolbar.querySelector('#edzFmtLabel');
        if (fmtBtn && fmtPop) {
            fmtBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                var isHidden = fmtPop.hasAttribute('hidden');
                document.querySelectorAll('.edz__pop').forEach(function (p) { p.setAttribute('hidden', ''); });
                if (isHidden) fmtPop.removeAttribute('hidden');
            });
            fmtPop.querySelectorAll('button[data-block]').forEach(function (mi) {
                mi.addEventListener('click', function (e) {
                    e.stopPropagation();
                    runCmd(seq, 'formatBlock', mi.getAttribute('data-block'));
                    if (fmtLabel) fmtLabel.textContent = mi.getAttribute('data-label');
                    fmtPop.setAttribute('hidden', '');
                });
            });
        }

        // 아이콘 드롭다운 메뉴(정렬·목록): 토글 + 선택 시 버튼 아이콘 미러 + 닫기.
        //   실제 명령은 각 항목의 data-cmd 를 위 [data-cmd] 일괄 핸들러가 실행한다.
        function bindIconMenu(btnId, popId) {
            var b = toolbar.querySelector(btnId), pop = toolbar.querySelector(popId);
            if (!b || !pop) return;
            b.addEventListener('click', function (e) {
                e.stopPropagation();
                var isHidden = pop.hasAttribute('hidden');
                document.querySelectorAll('.edz__pop').forEach(function (p) { p.setAttribute('hidden', ''); });
                if (isHidden) pop.removeAttribute('hidden');
            });
            pop.querySelectorAll('button[data-cmd]').forEach(function (mi) {
                mi.addEventListener('click', function () {
                    var ico = b.querySelector('.edz__menu-ico');
                    var src = mi.querySelector('svg');
                    if (ico && src) ico.innerHTML = src.innerHTML;
                    pop.setAttribute('hidden', '');
                });
            });
        }
        bindIconMenu('#edzAlignBtn', '#edzAlignPop');
        bindIconMenu('#edzListBtn', '#edzListPop');

        var moreBtn = toolbar.querySelector('#edzMoreBtn');
        var morePop = toolbar.querySelector('#edzMorePop');
        if (moreBtn && morePop) {
            moreBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                var isHidden = morePop.hasAttribute('hidden');
                document.querySelectorAll('.edz__pop').forEach(function (p) { p.setAttribute('hidden', ''); });
                if (isHidden) morePop.removeAttribute('hidden');
            });
        }

        var linkBtn = toolbar.querySelector('#edzLinkBtn');
        if (linkBtn) linkBtn.addEventListener('click', function () { openLinkLayer(seq); });

        // 서식 지우기
        var clearBtn = toolbar.querySelector('#edzClearBtn');
        if (clearBtn) clearBtn.addEventListener('click', function () { clearFormat(seq); });

        // 이모지 픽커
        var emojiBtn = toolbar.querySelector('#edzEmojiBtn');
        var emojiPop = toolbar.querySelector('#edzEmojiPop');
        var emojiGrid = toolbar.querySelector('#edzEmojiGrid');
        if (emojiBtn && emojiPop && emojiGrid) {
            var emojiSaved = null;
            buildEmojiGrid(emojiGrid, 'face');
            emojiBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                var isHidden = emojiPop.hasAttribute('hidden');
                document.querySelectorAll('.edz__pop').forEach(function (p) { p.setAttribute('hidden', ''); });
                if (isHidden) {
                    emojiSaved = saveRange();
                    emojiPop.style.left = ''; emojiPop.style.right = '';
                    emojiPop.removeAttribute('hidden');
                    // 화면 오른쪽으로 넘치면 버튼 오른쪽 기준으로 뒤집어 안 잘리게
                    var r = emojiPop.getBoundingClientRect();
                    if (r.right > window.innerWidth - 8) { emojiPop.style.left = 'auto'; emojiPop.style.right = '0'; }
                }
            });
            emojiPop.querySelectorAll('.edz__emoji-tab').forEach(function (t) {
                t.addEventListener('click', function (e) {
                    e.stopPropagation();
                    emojiPop.querySelectorAll('.edz__emoji-tab').forEach(function (x) { x.classList.remove('is-on'); });
                    t.classList.add('is-on');
                    buildEmojiGrid(emojiGrid, t.getAttribute('data-cat'));
                });
            });
            emojiGrid.addEventListener('click', function (e) {
                var b = e.target.closest('.edz__emoji');
                if (!b) return;
                e.stopPropagation();
                focusBody(seq);
                restoreRange(emojiSaved);
                document.execCommand('insertText', false, b.textContent);
                emojiSaved = saveRange();
            });
            emojiPop.addEventListener('click', function (e) { e.stopPropagation(); });
        }

        var hrBtn = toolbar.querySelector('#edzHrBtn');
        if (hrBtn) hrBtn.addEventListener('click', function () { insertHr(seq); });

        var quoteBtn = toolbar.querySelector('#edzQuoteBtn');
        if (quoteBtn) quoteBtn.addEventListener('click', function () { insertQuote(seq); });

        var tableBtn = toolbar.querySelector('#edzTableBtn');
        if (tableBtn) tableBtn.addEventListener('click', function () { openTableLayer(seq); });

        var wrap = document.getElementById('edz_wrap_' + seq);
        var tableModal = wrap && wrap.querySelector('.edz__modal--table');
        if (tableModal) {
            var okBtn = tableModal.querySelector('.edz__modal-ok');
            var cancelBtn = tableModal.querySelector('.edz__modal-cancel');
            if (okBtn) okBtn.addEventListener('click', function () { insertTableFromModal(seq, tableModal); });
            if (cancelBtn) cancelBtn.addEventListener('click', function () { tableModal.hidden = true; });
            tableModal.addEventListener('click', function (e) { if (e.target === tableModal) tableModal.hidden = true; });
        }

        var imgBtn = toolbar.querySelector('#edzImageBtn');
        if (imgBtn) imgBtn.addEventListener('click', function () { triggerFileInput(seq); });

        var vidBtn = toolbar.querySelector('#edzVideoBtn');
        if (vidBtn) vidBtn.addEventListener('click', function () { openVideoLayer(seq); });

        // 동영상 레이어 — 링크 붙여넣으면 플랫폼 자동 인식 + 미리보기
        var videoModal = wrap && wrap.querySelector('.edz__modal--video');
        if (videoModal) {
            var urlIn = videoModal.querySelector('.edz__vurl');
            var preview = videoModal.querySelector('.edz__vpreview');
            var plat = videoModal.querySelector('.edz__vplat');
            var err = videoModal.querySelector('.edz__verr');
            var vInsert = videoModal.querySelector('.edz__vinsert');
            if (urlIn) urlIn.addEventListener('input', function () {
                var raw = urlIn.value.trim();
                var v = detectVideo(raw);
                if (v) {
                    err.hidden = true;
                    plat.textContent = msg(videoModal, 'found').replace('%s', v.label);
                    plat.hidden = false;
                    preview.innerHTML = v.html.replace('<p><br></p>', '').replace('margin:12px 0', 'margin:0');
                    preview.hidden = false;
                    vInsert.disabled = false;
                } else {
                    plat.hidden = true; plat.textContent = '';
                    preview.hidden = true; preview.innerHTML = '';
                    err.hidden = (raw === '');
                    vInsert.disabled = true;
                }
            });
            // 붙여넣기 즉시 반응하도록
            if (urlIn) urlIn.addEventListener('paste', function () { setTimeout(function () { urlIn.dispatchEvent(new Event('input')); }, 0); });
            if (urlIn) urlIn.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !vInsert.disabled) { e.preventDefault(); insertVideo(seq, videoModal); } });
            if (vInsert) vInsert.addEventListener('click', function () { insertVideo(seq, videoModal); });
            videoModal.querySelectorAll('.edz__modal-cancel').forEach(function (c) {
                c.addEventListener('click', function () { videoModal.hidden = true; });
            });
            videoModal.addEventListener('click', function (e) { if (e.target === videoModal) videoModal.hidden = true; });
        }

        // 링크 레이어 — URL + 새 탭/nofollow 옵션
        var linkModal = wrap && wrap.querySelector('.edz__modal--link');
        if (linkModal) {
            var lurl = linkModal.querySelector('.edz__lurl');
            var lInsert = linkModal.querySelector('.edz__linsert');
            if (lurl) lurl.addEventListener('input', function () { linkModal.querySelector('.edz__lerr').hidden = true; });
            if (lurl) lurl.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); insertLink(seq, linkModal); } });
            if (lInsert) lInsert.addEventListener('click', function () { insertLink(seq, linkModal); });
            linkModal.querySelectorAll('.edz__modal-cancel').forEach(function (c) {
                c.addEventListener('click', function () { linkModal.hidden = true; });
            });
            linkModal.addEventListener('click', function (e) { if (e.target === linkModal) linkModal.hidden = true; });
        }

        var fileBtn = toolbar.querySelector('#edzFileBtn');
        if (fileBtn) fileBtn.addEventListener('click', function () { triggerFileInput(seq); });

        // 글자색 (팔레트 + 커스텀 피커)
        var colorToggle = toolbar.querySelector('#edzColorBtn');
        var colorPop = toolbar.querySelector('#edzColorPop');
        var colorBar = toolbar.querySelector('#edzColorBar');
        bindPopover(colorToggle, colorPop, function () { focusBody(seq); }, function (color) {
            document.execCommand('foreColor', false, color);
            if (colorBar) colorBar.style.background = color;
        });

        // 형광펜 (연한 팔레트 + 커스텀 피커 + ✕해제)
        var hiliteToggle = toolbar.querySelector('#edzHiliteBtn');
        var hilitePop = toolbar.querySelector('#edzHilitePop');
        var hiliteBar = toolbar.querySelector('#edzHiliteBar');
        bindPopover(hiliteToggle, hilitePop, function () { focusBody(seq); }, function (color) {
            try { document.execCommand('hiliteColor', false, color); }
            catch (e) { document.execCommand('backColor', false, color); }
            if (hiliteBar) hiliteBar.style.background = (color === 'transparent' ? '' : color);
        });
    }

    // 폼 연동: primary/content hidden input 등록 + 초기값 로드 + 실시간 동기화.
    // simpleeditor.js의 등록 로직과 완전히 동일한 계약을 따른다 (editor_common.js가 이를 전제로 동작).
    function initEditors() {
        $('.edz__body').each(function () {
            if (this.getAttribute('data-edz-ready')) return;
            this.setAttribute('data-edz-ready', '1');
            var body = $(this);
            var editor_sequence = body.data('editorSequence');
            var content_key = body.data('editorContentKeyName');
            var primary_key = body.data('editorPrimaryKeyName');
            var insert_form = body.closest('form');
            var content_input = insert_form.find('input,textarea').filter('[name=' + content_key + ']');

            insert_form[0].setAttribute('editor_sequence', editor_sequence);
            editorRelKeys[editor_sequence] = {};
            editorRelKeys[editor_sequence].primary = insert_form.find("input[name='" + primary_key + "']").get(0);
            editorRelKeys[editor_sequence].content = content_input;
            editorRelKeys[editor_sequence].func = editorGetContent;

            // 기존 글 수정 시 저장된 내용을 본문에 로드
            if (content_input.length && content_input.val()) {
                window.edzLoadHtml(body.get(0), content_input.val());
            }

            // 입력/포커스아웃마다 hidden input에 동기화 (제출 시점에 최신 상태가 반영되도록)
            body.on('input blur mouseup keyup', function () {
                content_input.val(window.edzCleanHtml(body.get(0)));
            });

            // 이미지 붙여넣기 → base64 인라인(작성제한) 대신 하단 파일첨부 업로더로 태워 파일 첨부
            body.on('paste', function (e) {
                var oe = e.originalEvent || e;
                var cd = oe.clipboardData || window.clipboardData;
                if (!cd || !cd.items) return;
                var imgs = [];
                for (var i = 0; i < cd.items.length; i++) {
                    var it = cd.items[i];
                    if (it.kind === 'file' && it.type && it.type.indexOf('image/') === 0) {
                        var f = it.getAsFile();
                        if (f) imgs.push(f);
                    }
                }
                if (!imgs.length) {
                    var pasted = cd.getData ? cd.getData('text/html') : '';
                    if (pasted) {
                        e.preventDefault();
                        document.execCommand('insertHTML', false, window.edzCleanPaste(pasted));
                    }
                    // 붙여넣은 빈 문단(<p></p>)은 글 보기에서 높이가 0 이 되어 빈 줄이 사라지므로 끝난 뒤 문단을 정리한다
                    setTimeout(function () {
                        var el = body.get(0);
                        normalizeBody(el);
                        content_input.val(window.edzCleanHtml(el));
                    }, 0);
                    return;
                }
                var input = document.querySelector('#xefu-container-' + editor_sequence + ' input[type=file]')
                         || document.querySelector('input[name=Filedata][data-editor-sequence="' + editor_sequence + '"]');
                if (!input) return; // 첨부 위젯 없으면 기본 동작
                e.preventDefault();
                try {
                    var dt = new DataTransfer();
                    imgs.forEach(function (f, idx) {
                        var ext = (f.type && f.type.split('/')[1]) ? f.type.split('/')[1].replace('jpeg', 'jpg') : 'png';
                        var nm = (f.name && f.name !== 'image.png') ? f.name : ('paste_' + Date.now() + '_' + idx + '.' + ext);
                        try { dt.items.add(new File([f], nm, { type: f.type })); } catch (er) { dt.items.add(f); }
                    });
                    input.value = '';
                    input.files = dt.files;
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                } catch (er) {}
            });

            var counter = body.closest('.edz').find('.edz__count');
            var updateCount = function () {
                var n = body.text().replace(/[​\s]+$/g, '').replace(/^\s+/, '').length;
                counter.text(msg(body.get(0), 'count').replace('%s', n.toLocaleString()));
            };
            body.on('input', updateCount);
            updateCount();

            // 사진 끌어다 놓기 → 붙여넣기와 같은 업로더로 보낸다
            var dragDepth = 0;
            body.on('dragenter', function (e) {
                var dt = (e.originalEvent || e).dataTransfer;
                if (dt && Array.prototype.indexOf.call(dt.types || [], 'Files') >= 0) { dragDepth++; body.addClass('is-drop'); }
            }).on('dragleave', function () {
                if (--dragDepth <= 0) { dragDepth = 0; body.removeClass('is-drop'); }
            }).on('dragover', function (e) {
                var dt = (e.originalEvent || e).dataTransfer;
                if (dt && Array.prototype.indexOf.call(dt.types || [], 'Files') >= 0) e.preventDefault();
            }).on('drop', function (e) {
                dragDepth = 0; body.removeClass('is-drop');
                var dt = (e.originalEvent || e).dataTransfer;
                var files = dt && dt.files ? Array.prototype.filter.call(dt.files, function (f) { return f.type.indexOf('image/') === 0; }) : [];
                if (!files.length) return;
                var input = document.querySelector('#xefu-container-' + editor_sequence + ' input[type=file]')
                         || document.querySelector('input[name=Filedata][data-editor-sequence="' + editor_sequence + '"]');
                if (!input) return;
                e.preventDefault();
                try {
                    var tr = new DataTransfer();
                    files.forEach(function (f) { tr.items.add(f); });
                    input.value = '';
                    input.files = tr.files;
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                } catch (er) {}
            });

            // Tab 들여쓰기 / 인용 Enter 탈출 / 표 열 리사이즈
            bindKeys(this, editor_sequence);
        });

        document.querySelectorAll('.edz__tool').forEach(function (tool) {
            if (tool.getAttribute('data-edz-ready')) return;
            tool.setAttribute('data-edz-ready', '1');
            bindToolbar(tool);
            enhanceA11y(tool.closest('.edz'));
        });
        syncScheme();
    }

    function enhanceA11y(wrap) {
        if (!wrap) return;
        wrap.querySelectorAll('.edz__tool button[title], .edz__ttool button[title]').forEach(function (b) {
            if (!b.getAttribute('aria-label')) b.setAttribute('aria-label', b.getAttribute('title'));
        });
        wrap.querySelectorAll('.edz__fmt > button').forEach(function (b) { b.setAttribute('aria-haspopup', 'true'); });
        wrap.querySelectorAll('.edz__modal').forEach(function (m) {
            m.setAttribute('role', 'dialog');
            m.setAttribute('aria-modal', 'true');
            var h = m.querySelector('.edz__modal-h');
            if (h) m.setAttribute('aria-label', h.textContent);
        });
        wrap.querySelectorAll('.edz__sw').forEach(function (sw) {
            if (sw.tagName === 'LABEL') return;
            sw.setAttribute('role', 'button');
            sw.setAttribute('tabindex', '0');
            sw.setAttribute('aria-label', sw.getAttribute('title') || sw.getAttribute('data-color') || '');
            sw.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); sw.click(); }
            });
        });
    }

    $(initEditors);

    var initTimer = null;
    var queueSync = function () { clearTimeout(initTimer); initTimer = setTimeout(syncScheme, 50); };
    if (window.MutationObserver) {
        new MutationObserver(queueSync).observe(document.documentElement, { attributes: true, attributeFilter: ['class', 'data-theme', 'style'] });
        $(function () {
            if (document.body) new MutationObserver(queueSync).observe(document.body, { attributes: true, attributeFilter: ['class', 'data-theme', 'style'] });
            // 댓글 수정처럼 나중에 열리는 에디터도 같은 방식으로 연결한다
            new MutationObserver(function () {
                if (document.querySelector('.edz__body:not([data-edz-ready])')) initEditors();
            }).observe(document.body, { childList: true, subtree: true });
        });
    }
    if (window.matchMedia) {
        var mq = window.matchMedia('(prefers-color-scheme: dark)');
        if (mq.addEventListener) mq.addEventListener('change', queueSync);
    }

    // 코어 파일업로드 위젯(ckeditor용 file_upload.html 재사용)이 기대하는 CKEditor 시뮬레이션 API
    window._getCkeContainer = function (editor_sequence) {
        return $('#edz_instance_' + editor_sequence);
    };
    window._getCkeInstance = function (editor_sequence) {
        var instance = $('#edz_instance_' + editor_sequence);
        return {
            getData: function () { return String(instance.html()); },
            setData: function (content) { window.edzLoadHtml(instance.get(0), content); },
            insertHtml: function (content) {
                instance.focus();
                document.execCommand('insertHTML', false, content);
            }
        };
    };
})(jQuery);
