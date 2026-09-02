/**
 * galleryZ — 본문 이미지를 갤러리로 넘겨보는 Zittme 기본 애드온의 뷰어 엔진.
 *
 * 구 photoswipe 애드온(PhotoSwipe 4 + jQuery)을 대체하는 의존성 없는 재작성판.
 * 본문(.Zittme_content, .xe_content) 이미지를 갤러리로 묶어
 * 확대 · 이동 · 스와이프 · 키보드 탐색을 제공한다.
 *
 * 제외 규칙(구판과 동일):
 *  - a, pre, code 등 안의 이미지와 .rx-escape/.photoswipe-escape 는 제외
 *  - .photoswipe-images 를 붙이면 위 규칙과 무관하게 포함
 *  - 코어 구성요소 경로(modules/ 등)는 제외하되 문서 이미지(modules/*\/docs/)는 포함
 */
(function () {
	'use strict';

	var CONTAINER_SELECTOR = '.Zittme_content, .xe_content';
	var SKIP_ANCESTORS = 'a, pre, code, xml, textarea, input, select, option, script, style, iframe, button, embed, object, ins';
	var RE_SKIP_SRC = /(?:modules|addons|classes|common|layouts|libs|widgets|widgetstyles)\//i;
	var RE_ALLOW_DOCS = /modules\/[a-z0-9_]+\/docs\//i;

	var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	/* ---------- 대상 수집 ---------- */

	function isCandidate(img) {
		if (!img.src) { return false; }
		if (img.classList.contains('photoswipe-images') || img.classList.contains('galleryz-images')) { return true; }
		if (img.classList.contains('rx-escape') || img.classList.contains('photoswipe-escape') || img.classList.contains('galleryz-escape')) { return false; }
		if (img.closest(SKIP_ANCESTORS)) { return false; }
		if (RE_SKIP_SRC.test(img.getAttribute('src')) && !RE_ALLOW_DOCS.test(img.getAttribute('src'))) { return false; }
		return true;
	}

	function collect(container) {
		var out = [];
		container.querySelectorAll('img').forEach(function (img) {
			if (isCandidate(img)) { out.push(img); }
		});
		return out;
	}

	/* ---------- 오버레이 DOM ---------- */

	var root = null;
	var els = {};
	var state = null;

	function buildOverlay() {
		if (root) { return; }
		root = document.createElement('div');
		root.className = 'gz';
		root.setAttribute('role', 'dialog');
		root.setAttribute('aria-modal', 'true');
		root.setAttribute('aria-label', 'Image viewer');
		root.innerHTML =
			'<div class="gz-backdrop"></div>' +
			'<div class="gz-stage"></div>' +
			'<div class="gz-top">' +
				'<span class="gz-counter"></span>' +
				'<span class="gz-tools">' +
				'<button type="button" class="gz-btn gz-play" aria-label="Slideshow" title="슬라이드쇼">' +
					'<svg class="gz-ico-play" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>' +
					'<svg class="gz-ico-pause" viewBox="0 0 24 24"><path d="M6 5h4v14H6zm8 0h4v14h-4z"/></svg></button>' +
				'<button type="button" class="gz-btn gz-fs" aria-label="Fullscreen" title="전체화면">' +
					'<svg viewBox="0 0 24 24"><path d="M4 4h6v2H6v4H4zm10 0h6v6h-2V6h-4zM4 14h2v4h4v2H4zm14 0h2v6h-6v-2h4z"/></svg></button>' +
					'<a class="gz-btn gz-download" download target="_blank" rel="noopener" aria-label="Download" title="다운로드">' +
						'<svg viewBox="0 0 24 24"><path d="M12 3v10.6l3.6-3.6 1.4 1.4-6 6-6-6L6.4 10l3.6 3.6V3h2zM5 19h14v2H5z"/></svg></a>' +
					'<button type="button" class="gz-btn gz-close" aria-label="Close" title="닫기 (Esc)">' +
						'<svg viewBox="0 0 24 24"><path d="M18.3 5.7 13.4 10.6 18.3 15.5 16.9 16.9 12 12l-4.9 4.9-1.4-1.4L10.6 10.6 5.7 5.7 7.1 4.3 12 9.2l4.9-4.9z"/></svg></button>' +
				'</span>' +
			'</div>' +
			'<button type="button" class="gz-nav gz-prev" aria-label="Previous">' +
				'<svg viewBox="0 0 24 24"><path d="M15.5 4.5 8 12l7.5 7.5-1.6 1.6L4.9 12l9-9.1z"/></svg></button>' +
			'<button type="button" class="gz-nav gz-next" aria-label="Next">' +
				'<svg viewBox="0 0 24 24"><path d="M8.5 4.5 16 12l-7.5 7.5 1.6 1.6L19.1 12l-9-9.1z"/></svg></button>' +
			'<div class="gz-strip" role="tablist"></div>' +
			'<div class="gz-caption"></div>' +
			'<div class="gz-spinner" hidden></div>';
		document.body.appendChild(root);

		els.backdrop = root.querySelector('.gz-backdrop');
		els.stage = root.querySelector('.gz-stage');
		els.counter = root.querySelector('.gz-counter');
		els.caption = root.querySelector('.gz-caption');
		els.download = root.querySelector('.gz-download');
		els.close = root.querySelector('.gz-close');
		els.prev = root.querySelector('.gz-prev');
		els.next = root.querySelector('.gz-next');
		els.spinner = root.querySelector('.gz-spinner');
		els.strip = root.querySelector('.gz-strip');
		els.play = root.querySelector('.gz-play');
		els.fs = root.querySelector('.gz-fs');

		els.close.addEventListener('click', close);
		els.play.addEventListener('click', toggleSlideshow);
		els.fs.addEventListener('click', toggleFullscreen);
		els.backdrop.addEventListener('click', close);
		els.prev.addEventListener('click', function () { stopSlideshow(); go(-1); });
		els.next.addEventListener('click', function () { stopSlideshow(); go(1); });
		['pointermove', 'pointerdown', 'keydown', 'wheel', 'touchstart'].forEach(function (ev) {
			root.addEventListener(ev, wake, { passive: true });
		});

		bindGestures();
	}

	/* ---------- 열기 / 닫기 / 이동 ---------- */

	function open(thumbs, index, opener) {
		buildOverlay();
		state = {
			thumbs: thumbs,
			index: index,
			scale: 1, tx: 0, ty: 0,
			img: null,
			opener: opener || null,
			pointers: new Map(),
			pinchBase: 0, pinchScale: 1,
			dragX: 0, swipeFrom: null
		};
		document.documentElement.classList.add('gz-lock');
		root.classList.add('is-open');
		buildStrip();
		show(index, thumbs[index]);
		els.close.focus({ preventScroll: true });
		// 모바일 뒤로가기로 닫히도록 히스토리에 한 칸 쌓는다
		try { history.pushState({ gz: true }, ''); state.pushed = true; } catch (err) { state.pushed = false; }
		wake();
	}

	function close() {
		if (!state) { return; }
		if (state.pushed) { state.pushed = false; history.back(); return; }
		teardown();
	}

	function teardown() {
		if (!state) { return; }
		var thumb = state.thumbs[state.index];
		var img = state.img;
		root.classList.remove('is-open');
		document.documentElement.classList.remove('gz-lock');
		if (img && thumb && !reduceMotion) { flipTo(img, thumb); }
		var opener = state.opener;
		window.setTimeout(function () {
			els.stage.innerHTML = '';
			els.spinner.hidden = true;
		}, 220);
		stopSlideshow();
		stopIdle();
		if (document.fullscreenElement) { document.exitFullscreen().catch(function () {}); }
		state = null;
		if (opener && opener.focus) { opener.focus({ preventScroll: true }); }
	}

	window.addEventListener('popstate', function () {
		if (state) { state.pushed = false; teardown(); }
	});

	function go(dir) {
		if (!state) { return; }
		var next = state.index + dir;
		if (next < 0 || next >= state.thumbs.length) { bump(dir); return; }
		state.index = next;
		state.scale = 1; state.tx = 0; state.ty = 0;
		show(next, null);
		preload(next + dir);
	}

	function bump(dir) {
		// 끝에서 더 넘기려 할 때 살짝 튕겨 알림
		if (reduceMotion || !state || !state.img) { return; }
		var img = state.img;
		img.style.transition = 'transform .18s ease';
		img.style.transform = baseTransform() + ' translateX(' + (dir * -24) + 'px)';
		window.setTimeout(function () { if (state && state.img === img) { applyTransform(); } }, 180);
	}

	function show(index, fromThumb) {
		var thumb = state.thumbs[index];
		els.stage.innerHTML = '';

		var img = document.createElement('img');
		img.className = 'gz-img';
		img.alt = thumb.alt || '';
		img.draggable = false;
		state.img = img;
		els.stage.appendChild(img);

		els.spinner.hidden = false;
		img.addEventListener('load', function () { els.spinner.hidden = true; }, { once: true });
		img.addEventListener('error', function () { els.spinner.hidden = true; }, { once: true });
		img.src = thumb.currentSrc || thumb.src;
		if (img.complete) { els.spinner.hidden = true; }

		applyTransform();

		if (fromThumb && !reduceMotion) { flipFrom(img, fromThumb); }
		else if (!reduceMotion) {
			img.style.opacity = '0';
			img.style.transform = baseTransform() + ' scale(.97)';
			requestAnimationFrame(function () {
				img.style.transition = 'opacity .22s ease, transform .22s ease';
				img.style.opacity = '1';
				applyTransform();
			});
		}

		els.counter.textContent = (index + 1) + ' / ' + state.thumbs.length;
		els.download.href = thumb.currentSrc || thumb.src;
		var cap = thumb.getAttribute('alt') || thumb.getAttribute('title') || '';
		els.caption.textContent = cap;
		els.caption.classList.toggle('is-empty', cap === '');
		els.prev.classList.toggle('is-off', index === 0);
		els.next.classList.toggle('is-off', index === state.thumbs.length - 1);
		syncStrip(index);
	}

	function preload(index) {
		if (!state || index < 0 || index >= state.thumbs.length) { return; }
		var pre = new Image();
		pre.src = state.thumbs[index].currentSrc || state.thumbs[index].src;
	}

	/* ---------- FLIP 애니메이션 (썸네일 ↔ 뷰어) ---------- */

	function flipFrom(img, thumb) {
		var apply = function () {
			var from = thumb.getBoundingClientRect();
			var to = img.getBoundingClientRect();
			if (!to.width || !to.height) { return; }
			var dx = (from.left + from.width / 2) - (to.left + to.width / 2);
			var dy = (from.top + from.height / 2) - (to.top + to.height / 2);
			var s = from.width / to.width;
			img.style.transition = 'none';
			img.style.transform = 'translate(' + dx + 'px,' + dy + 'px) scale(' + s + ')';
			requestAnimationFrame(function () {
				img.style.transition = 'transform .28s cubic-bezier(.2,.8,.2,1)';
				applyTransform();
			});
		};
		if (img.complete && img.naturalWidth) { apply(); }
		else { img.addEventListener('load', apply, { once: true }); }
	}

	function flipTo(img, thumb) {
		var from = img.getBoundingClientRect();
		if (!from.width) { return; }
		var to = thumb.getBoundingClientRect();
		var dx = (to.left + to.width / 2) - (from.left + from.width / 2);
		var dy = (to.top + to.height / 2) - (from.top + from.height / 2);
		var s = to.width / from.width;
		img.style.transition = 'transform .22s ease, opacity .22s ease';
		img.style.transform = img.style.transform + ' translate(' + (dx / state.scale) + 'px,' + (dy / state.scale) + 'px) scale(' + s + ')';
		img.style.opacity = '0';
	}

	/* ---------- 변환(줌 · 팬 · 스와이프 드래그) ---------- */

	function baseTransform() {
		return 'translate(' + state.tx + 'px,' + state.ty + 'px) scale(' + state.scale + ')';
	}

	function applyTransform(withDrag) {
		if (!state || !state.img) { return; }
		var t = baseTransform();
		if (withDrag && state.dragX) { t += ' translateX(' + (state.dragX / state.scale) + 'px)'; }
		state.img.style.transform = t;
	}

	function setZoom(scale, cx, cy) {
		scale = Math.max(1, Math.min(4, scale));
		if (scale === state.scale) { return; }
		var rect = els.stage.getBoundingClientRect();
		var ox = (cx !== undefined ? cx : rect.left + rect.width / 2) - (rect.left + rect.width / 2);
		var oy = (cy !== undefined ? cy : rect.top + rect.height / 2) - (rect.top + rect.height / 2);
		var k = scale / state.scale;
		state.tx = (state.tx - ox) * k + ox;
		state.ty = (state.ty - oy) * k + oy;
		state.scale = scale;
		if (scale === 1) { state.tx = 0; state.ty = 0; }
		clampPan();
		state.img.style.transition = reduceMotion ? 'none' : 'transform .2s ease';
		applyTransform();
		root.classList.toggle('is-zoomed', scale > 1);
	}

	function clampPan() {
		if (!state.img) { return; }
		var stage = els.stage.getBoundingClientRect();
		var w = state.img.offsetWidth * state.scale;
		var h = state.img.offsetHeight * state.scale;
		var maxX = Math.max(0, (w - stage.width) / 2 + 40);
		var maxY = Math.max(0, (h - stage.height) / 2 + 40);
		state.tx = Math.max(-maxX, Math.min(maxX, state.tx));
		state.ty = Math.max(-maxY, Math.min(maxY, state.ty));
	}

	/* ---------- 썸네일 스트립 ---------- */

	function buildStrip() {
		els.strip.innerHTML = '';
		root.classList.toggle('has-strip', state.thumbs.length > 1);
		if (state.thumbs.length < 2) { return; }
		state.thumbs.forEach(function (thumb, i) {
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'gz-strip-item';
			b.setAttribute('role', 'tab');
			b.setAttribute('aria-label', String(i + 1));
			var im = document.createElement('img');
			im.src = thumb.currentSrc || thumb.src;
			im.alt = '';
			im.draggable = false;
			b.appendChild(im);
			b.addEventListener('click', function () {
				if (!state || state.index === i) { return; }
				state.index = i;
				state.scale = 1; state.tx = 0; state.ty = 0;
				show(i, null);
			});
			els.strip.appendChild(b);
		});
	}

	function syncStrip(index) {
		var items = els.strip.children;
		for (var i = 0; i < items.length; i++) {
			items[i].classList.toggle('is-on', i === index);
		}
		if (items[index]) {
			items[index].scrollIntoView({ block: 'nearest', inline: 'center', behavior: reduceMotion ? 'auto' : 'smooth' });
		}
	}

	/* ---------- 슬라이드쇼 ---------- */

	var slideTimer = null;

	function toggleSlideshow() {
		if (slideTimer) { stopSlideshow(); return; }
		if (!state || state.thumbs.length < 2) { return; }
		root.classList.add('is-playing');
		slideTimer = window.setInterval(function () {
			if (!state) { stopSlideshow(); return; }
			// 끝에 닿으면 처음으로 순환
			if (state.index >= state.thumbs.length - 1) {
				state.index = 0;
				state.scale = 1; state.tx = 0; state.ty = 0;
				show(0, null);
			} else {
				go(1);
			}
		}, 3500);
	}

	function stopSlideshow() {
		if (slideTimer) { window.clearInterval(slideTimer); slideTimer = null; }
		if (root) { root.classList.remove('is-playing'); }
	}

	/* ---------- 전체화면 ---------- */

	function toggleFullscreen() {
		if (document.fullscreenElement) {
			document.exitFullscreen().catch(function () {});
		} else if (root.requestFullscreen) {
			root.requestFullscreen().catch(function () {});
		}
	}

	/* ---------- 유휴 시 UI 숨김 ---------- */

	var idleTimer = null;

	function wake() {
		if (!root) { return; }
		root.classList.remove('is-idle');
		if (idleTimer) { window.clearTimeout(idleTimer); }
		idleTimer = window.setTimeout(function () {
			if (state) { root.classList.add('is-idle'); }
		}, 2600);
	}

	function stopIdle() {
		if (idleTimer) { window.clearTimeout(idleTimer); idleTimer = null; }
		if (root) { root.classList.remove('is-idle'); }
	}

	/* ---------- 입력 ---------- */

	function bindGestures() {
		document.addEventListener('keydown', function (e) {
			if (!state) { return; }
			if (e.key === 'Escape') { close(); }
			else if (e.key === 'ArrowLeft') { stopSlideshow(); go(-1); }
			else if (e.key === 'ArrowRight') { stopSlideshow(); go(1); }
			else if (e.key === ' ') { e.preventDefault(); toggleSlideshow(); }
		});

		els.stage.addEventListener('wheel', function (e) {
			if (!state) { return; }
			e.preventDefault();
			var next = state.scale * (e.deltaY < 0 ? 1.18 : 1 / 1.18);
			setZoom(next < 1.05 ? 1 : next, e.clientX, e.clientY);
		}, { passive: false });

		els.stage.addEventListener('dblclick', function (e) {
			if (!state) { return; }
			setZoom(state.scale > 1 ? 1 : 2.2, e.clientX, e.clientY);
		});

		els.stage.addEventListener('pointerdown', function (e) {
			if (!state || e.button > 0) { return; }
			els.stage.setPointerCapture(e.pointerId);
			state.pointers.set(e.pointerId, { x: e.clientX, y: e.clientY, t: Date.now() });
			if (state.pointers.size === 2) {
				var pts = Array.from(state.pointers.values());
				state.pinchBase = Math.hypot(pts[0].x - pts[1].x, pts[0].y - pts[1].y);
				state.pinchScale = state.scale;
			} else if (state.pointers.size === 1) {
				state.swipeFrom = { x: e.clientX, y: e.clientY, tx: state.tx, ty: state.ty, t: Date.now() };
				state.moved = false;
				if (state.img) { state.img.style.transition = 'none'; }
			}
		});

		els.stage.addEventListener('pointermove', function (e) {
			if (!state || !state.pointers.has(e.pointerId)) { return; }
			var p = state.pointers.get(e.pointerId);
			p.x = e.clientX; p.y = e.clientY;

			if (state.pointers.size === 2 && state.pinchBase > 0) {
				state.moved = true;
				var pts = Array.from(state.pointers.values());
				var dist = Math.hypot(pts[0].x - pts[1].x, pts[0].y - pts[1].y);
				var cx = (pts[0].x + pts[1].x) / 2;
				var cy = (pts[0].y + pts[1].y) / 2;
				var next = state.pinchScale * (dist / state.pinchBase);
				state.img.style.transition = 'none';
				setZoom(next < 1.05 ? 1 : next, cx, cy);
				return;
			}
			if (!state.swipeFrom) { return; }
			var dx = e.clientX - state.swipeFrom.x;
			var dy = e.clientY - state.swipeFrom.y;
			if (Math.abs(dx) > 6 || Math.abs(dy) > 6) { state.moved = true; }
			if (state.scale > 1) {
				state.tx = state.swipeFrom.tx + dx;
				state.ty = state.swipeFrom.ty + dy;
				clampPan();
				applyTransform();
			} else {
				state.dragX = dx;
				// 세로로 크게 끌면 닫기 제스처
				if (Math.abs(dy) > Math.abs(dx)) {
					root.style.setProperty('--gz-fade', String(Math.max(.35, 1 - Math.abs(dy) / 420)));
					state.img.style.transform = baseTransform() + ' translateY(' + dy + 'px)';
				} else {
					root.style.removeProperty('--gz-fade');
					applyTransform(true);
				}
			}
		});

		var endPointer = function (e) {
			if (!state || !state.pointers.has(e.pointerId)) { return; }
			state.pointers.delete(e.pointerId);
			if (state.pointers.size > 0) { return; }
			state.pinchBase = 0;
			root.style.removeProperty('--gz-fade');

			var sf = state.swipeFrom;
			state.swipeFrom = null;
			var dragX = state.dragX;
			state.dragX = 0;
			if (state.scale > 1 || !sf) { return; }

			var dy = e.clientY - sf.y;
			var dt = Date.now() - sf.t;
			if (Math.abs(dy) > 130 && Math.abs(dy) > Math.abs(dragX)) { close(); return; }
			if (Math.abs(dragX) > 70 || (Math.abs(dragX) > 30 && dt < 250)) {
				stopSlideshow();
				go(dragX < 0 ? 1 : -1);
			} else if (state.img) {
				state.img.style.transition = reduceMotion ? 'none' : 'transform .2s ease';
				applyTransform();
			}
		};
		els.stage.addEventListener('pointerup', endPointer);
		els.stage.addEventListener('pointercancel', endPointer);

		// 이미지 밖(무대 여백) 탭은 닫기
		els.stage.addEventListener('click', function (e) {
			if (!state) { return; }
			if (state.moved) { state.moved = false; return; }
			if (e.target === els.stage) { close(); }
		});
	}

	/* ---------- 초기화 ---------- */

	function init() {
		document.querySelectorAll(CONTAINER_SELECTOR).forEach(function (container) {
			if (container.dataset.gzBound) { return; }
			container.dataset.gzBound = '1';
			container.addEventListener('click', function (e) {
				var img = e.target.closest('img');
				if (!img || !container.contains(img) || !isCandidate(img)) { return; }
				var thumbs = collect(container);
				var index = thumbs.indexOf(img);
				if (index === -1) { return; }
				e.preventDefault();
				open(thumbs, index, img);
			});
			collect(container).forEach(function (img) { img.classList.add('gz-thumb'); });
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
