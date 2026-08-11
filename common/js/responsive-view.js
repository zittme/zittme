/**
 * Zittme 반응형 뷰 — 뷰포트 동기화
 *
 * 서버는 목록 개수·페이지 번호·에디터 설정을 렌더링 시점에 정하므로, 브라우저가
 * 자기 폭을 알려주지 않으면 "창을 줄이면 모바일 설정"이 성립하지 않는다.
 * 이 스크립트는 현재 폭을 쿠키에 기록하고, 브레이크포인트를 넘을 때만 화면을
 * 다시 요청한다.
 *
 * 작성 중인 내용이 있으면 절대 새로고침하지 않는다. 쿠키만 갱신해 두고 다음
 * 이동부터 반영한다 — 글이 날아가는 것보다 한 박자 늦는 편이 낫다.
 *
 * 이 파일은 반응형 뷰가 켜져 있을 때만 로드된다.
 */
(function (w, d) {
	'use strict';

	// classes/mobile/Mobile.class.php 의 NARROW_BREAKPOINT 와 반드시 같아야 한다
	var BREAKPOINT = 768;
	var COOKIE = 'rx_viewport';
	var DEBOUNCE = 300;

	function width() {
		return w.innerWidth || d.documentElement.clientWidth || 0;
	}

	function isNarrow(px) {
		return px < BREAKPOINT;
	}

	function writeCookie(px) {
		// 세션 쿠키 + SameSite=Lax. 폭 정보만 담기므로 민감정보가 아니다.
		d.cookie = COOKIE + '=' + px + '; path=/; SameSite=Lax' +
			(location.protocol === 'https:' ? '; Secure' : '');
	}

	/**
	 * 사용자가 입력 중인 내용이 있는가.
	 * 있으면 새로고침하지 않는다.
	 */
	function hasUnsavedInput() {
		// 리치 에디터 (CKEditor 4)
		if (w.CKEDITOR && w.CKEDITOR.instances) {
			for (var k in w.CKEDITOR.instances) {
				if (!w.CKEDITOR.instances.hasOwnProperty(k)) continue;
				var inst = w.CKEDITOR.instances[k];
				try {
					if (inst && inst.checkDirty && inst.checkDirty()) return true;
					if (inst && inst.getData && inst.getData().replace(/<[^>]*>|\s|&nbsp;/g, '') !== '') return true;
				} catch (e) { /* 인스턴스 파기 중이면 무시 */ }
			}
		}

		// 일반 폼: 값이 있는 textarea, 초기값과 달라진 입력
		var i, els = d.querySelectorAll('textarea');
		for (i = 0; i < els.length; i++) {
			if ((els[i].value || '').trim() !== '') return true;
		}
		els = d.querySelectorAll('input[type="text"], input[type="password"], input[type="email"], input[type="search"], input[type="url"], input[type="number"]');
		for (i = 0; i < els.length; i++) {
			if (els[i].value !== els[i].defaultValue) return true;
		}
		els = d.querySelectorAll('input[type="file"]');
		for (i = 0; i < els.length; i++) {
			if (els[i].files && els[i].files.length) return true;
		}
		return false;
	}

	/**
	 * 서버가 클라이언트와 다른 판단을 계속 유지하면 새로고침이 무한 반복된다.
	 * (예: 모듈이 아직 모바일 전용 뷰라 서버는 항상 "넓음"이라고 답하는 경우)
	 * 한 번 다시 요청해도 결과가 같으면 더 시도하지 않는다.
	 */
	function reloadGuardKey() {
		return 'rx_reload_guard:' + location.pathname + location.search;
	}

	function reload() {
		try {
			if (w.sessionStorage.getItem(reloadGuardKey())) {
				return;                       // 이미 한 번 시도했다 — 반복하지 않는다
			}
			w.sessionStorage.setItem(reloadGuardKey(), '1');
			// 스크롤 위치를 보존해 화면이 튀지 않게 한다
			w.sessionStorage.setItem('rx_scroll', String(w.pageYOffset || 0));
		} catch (e) {
			return;                           // 저장소를 못 쓰면 안전한 쪽(재요청 안 함)으로
		}
		w.location.reload();
	}

	/**
	 * 서버 판단과 실제 폭이 일치하면 가드를 푼다. 이후 사용자가 창 크기를
	 * 바꿨을 때는 정상적으로 다시 요청할 수 있어야 한다.
	 */
	function clearReloadGuard() {
		try { w.sessionStorage.removeItem(reloadGuardKey()); } catch (e) {}
	}

	function restoreScroll() {
		try {
			var y = w.sessionStorage.getItem('rx_scroll');
			if (y !== null) {
				w.sessionStorage.removeItem('rx_scroll');
				w.scrollTo(0, parseInt(y, 10) || 0);
			}
		} catch (e) {}
	}

	var lastNarrow = null;
	var timer = null;

	function sync(initial) {
		var px = width();
		if (!px) return;

		var narrow = isNarrow(px);
		writeCookie(px);

		// 첫 진입: 서버는 쿠키(없으면 UA 추정)로 이미 화면을 그렸다.
		// 서버가 실제 폭과 다르게 판단했다면 한 번만 다시 요청한다.
		// zittmeNarrowServer 는 서버가 인라인으로 내려주는 자기 판단값이다.
		if (initial) {
			lastNarrow = narrow;
			if (typeof w.zittmeNarrowServer !== 'boolean') {
				return;
			}
			if (w.zittmeNarrowServer === narrow) {
				clearReloadGuard();          // 서버와 일치 — 다음 변화에 다시 반응할 수 있게
				return;
			}
			if (!hasUnsavedInput()) {
				reload();
			}
			return;
		}

		// 브레이크포인트를 실제로 넘었을 때만 반응한다
		if (narrow === lastNarrow) return;
		lastNarrow = narrow;

		// 에디터처럼 자체적으로 대응할 수 있는 컴포넌트에 먼저 알린다.
		// 처리했다고 응답하면(handled) 새로고침하지 않는다.
		var ev;
		try {
			ev = new CustomEvent('zittme:viewportmodechange', {
				detail: { narrow: narrow, width: px }, cancelable: true
			});
		} catch (e) {
			ev = d.createEvent('CustomEvent');
			ev.initCustomEvent('zittme:viewportmodechange', false, true, { narrow: narrow, width: px });
		}
		var notCancelled = d.dispatchEvent(ev);

		if (!notCancelled) return;          // 누군가 처리함
		if (hasUnsavedInput()) return;       // 작성 중 — 다음 이동부터 반영
		reload();
	}

	w.zittmeResponsive = {
		isNarrow: function () { return isNarrow(width()); },
		breakpoint: BREAKPOINT
	};

	// 서버가 첫 화면을 그릴 때 쓸 수 있도록 최대한 이르게 기록한다
	sync(true);
	restoreScroll();

	w.addEventListener('resize', function () {
		clearTimeout(timer);
		timer = setTimeout(function () { sync(false); }, DEBOUNCE);
	});
})(window, document);
