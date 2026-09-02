/* editorZ — 툴바 동작 + editorRelKeys 등록(simpleeditor와 동일 패턴).
   ⚠️ editorRelKeys 등록이 없으면 제출 시 content 값이 hidden input에 동기화되지 않아
   "내용값은 필수입니다" 에러가 발생한다 (실제 원인, 2026-07-02 확인). */
(function ($) {
    'use strict';

    try { console.log('[edz] editorZ v2026-07-03e (img-align selection) loaded'); } catch (e) {}

    // 저장/동기화용 HTML — 편집 전용 표시(edz-img-selected)를 제거한 본문을 반환
    window.edzCleanHtml = function (el) {
        if (!el) return '';
        var clone = el.cloneNode(true);
        clone.querySelectorAll('img.edz-img-selected').forEach(function (im) {
            im.classList.remove('edz-img-selected');
            if (!im.getAttribute('class')) im.removeAttribute('class');
        });
        return clone.innerHTML;
    };

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

    function insertLink(seq) {
        var url = window.prompt('연결할 URL을 입력하세요');
        if (!url) return;
        focusBody(seq);
        document.execCommand('createLink', false, url);
    }

    function insertHr(seq) {
        focusBody(seq);
        document.execCommand('insertHorizontalRule', false, null);
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
    function insertTableFromModal(seq, modal) {
        var rows = Math.max(1, Math.min(30, parseInt(modal.querySelector('.edz__num--rows').value, 10) || 3));
        var cols = Math.max(1, Math.min(10, parseInt(modal.querySelector('.edz__num--cols').value, 10) || 3));
        var pct = (100 / cols).toFixed(4);
        var colgroup = '';
        for (var c = 0; c < cols; c++) colgroup += '<col style="width:' + pct + '%">';
        var cells = '';
        for (c = 0; c < cols; c++) cells += '<td><br></td>';
        var trs = '';
        for (var r = 0; r < rows; r++) trs += '<tr>' + cells + '</tr>';
        var html = '<table class="edz-table"><colgroup>' + colgroup + '</colgroup><tbody>' + trs + '</tbody></table><p><br></p>';
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
            if (resizing) { resizing = null; bodyEl.style.cursor = ''; }
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
                    tr.parentNode.insertBefore(nr, tr.nextSibling);
                } else if (act === 'row-del') {
                    if (rows.length > 1) tr.parentNode.removeChild(tr);
                } else if (act === 'col-add') {
                    Array.prototype.forEach.call(rows, function (row) { row.insertCell(idx + 1).innerHTML = '<br>'; });
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
                        if (cg && cg.children[idx]) { cg.removeChild(cg.children[idx]); activeTable.style.width = ''; normalizeCols(cg); }
                    }
                }
                update();
            });
        });

        function normalizeCols(cg) {
            var n = cg.children.length; if (!n) return;
            var w = (100 / n).toFixed(4);
            Array.prototype.forEach.call(cg.children, function (c) { c.style.width = w + '%'; });
        }
    }

    // Tab=목록/문단 들여쓰기, 인용(blockquote)에서 빈 줄 Enter=인용 탈출
    function bindKeys(bodyEl, seq) {
        bodyEl.addEventListener('keydown', function (e) {
            if (e.key === 'Tab') {
                e.preventDefault();
                document.execCommand(e.shiftKey ? 'outdent' : 'indent');
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
            window.alert('첨부파일 기능이 비활성화되어 있습니다.');
        }
    }

    // YouTube 링크에서 videoId 추출 (youtu.be / watch?v= / embed / shorts / live 지원)
    function parseYouTubeId(url) {
        if (!url) return null;
        var m = String(url).match(/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/|live\/|v\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/);
        return m ? m[1] : null;
    }
    // 반응형 16:9 YouTube 임베드 HTML (PC/모바일 모두 비율 유지)
    function youtubeEmbedHtml(id) {
        return '<div class="edz-embed"><iframe src="https://www.youtube.com/embed/' + id +
            '" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe></div><p><br></p>';
    }

    // Vimeo 링크에서 videoId 추출
    function parseVimeoId(url) {
        if (!url) return null;
        var m = String(url).match(/vimeo\.com\/(?:video\/|channels\/[\w]+\/|groups\/[\w]+\/videos\/)?(\d+)/);
        return m ? m[1] : null;
    }
    function vimeoEmbedHtml(id) {
        return '<div class="edz-embed"><iframe src="https://player.vimeo.com/video/' + id +
            '" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe></div><p><br></p>';
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

        var linkBtn = toolbar.querySelector('#edzLinkBtn');
        if (linkBtn) linkBtn.addEventListener('click', function () { insertLink(seq); });

        var hrBtn = toolbar.querySelector('#edzHrBtn');
        if (hrBtn) hrBtn.addEventListener('click', function () { insertHr(seq); });

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
                    plat.textContent = v.label + ' 링크를 인식했습니다';
                    plat.hidden = false;
                    preview.innerHTML = v.html.replace('<p><br></p>', '');
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
    $(function () {
        $('.edz__body').each(function () {
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
                body.html(content_input.val());
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
                if (!imgs.length) return; // 텍스트/HTML 붙여넣기는 기본 동작 유지
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

            // Tab 들여쓰기 / 인용 Enter 탈출 / 표 열 리사이즈
            bindKeys(this, editor_sequence);
        });

        document.querySelectorAll('.edz__tool').forEach(bindToolbar);
    });

    // 코어 파일업로드 위젯(ckeditor용 file_upload.html 재사용)이 기대하는 CKEditor 시뮬레이션 API
    window._getCkeContainer = function (editor_sequence) {
        return $('#edz_instance_' + editor_sequence);
    };
    window._getCkeInstance = function (editor_sequence) {
        var instance = $('#edz_instance_' + editor_sequence);
        return {
            getData: function () { return String(instance.html()); },
            setData: function (content) { instance.html(content); },
            insertHtml: function (content) {
                instance.focus();
                document.execCommand('insertHTML', false, content);
            }
        };
    };
})(jQuery);
