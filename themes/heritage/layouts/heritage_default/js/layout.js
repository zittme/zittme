(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState !== 'loading') { fn(); }
		else { document.addEventListener('DOMContentLoaded', fn); }
	}

	ready(function () {

		// 헤더와 모바일 서랍에 하나씩 있다. 둘 다 같은 동작을 한다
		var themeBtns = document.querySelectorAll('[data-hr-theme-toggle]');
		for (var t = 0; t < themeBtns.length; t++) {
			themeBtns[t].addEventListener('click', function () {
				var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
				document.documentElement.setAttribute('data-theme', next);
				// 코어와 코어 스킨은 body 클래스를 본다. 토글할 때도 함께 갈아 끼운다
				document.body.classList.remove('color_scheme_light', 'color_scheme_dark');
				document.body.classList.add('color_scheme_' + next);
				try { localStorage.setItem('hr-theme', next); } catch (e) {}
			});
		}

		// 서랍·푸터의 언어 셀렉트는 값이 곧 이동할 주소다
		var langNavs = document.querySelectorAll('#hr_mobile_lang, select[data-hr-lang-nav]');
		for (var ln = 0; ln < langNavs.length; ln++) {
			langNavs[ln].addEventListener('change', function () {
				if (this.value) { window.location.href = this.value; }
			});
		}

		// 언어 목록: 버튼으로 여닫고, 바깥을 누르거나 Esc 로 닫는다
		var langBox = document.getElementById('hr_lang');
		if (langBox) {
			var langBtn = langBox.querySelector('.hr-lang-btn');
			var langList = langBox.querySelector('.hr-lang-list');
			var closeLang = function () {
				langList.hidden = true;
				langBtn.setAttribute('aria-expanded', 'false');
			};
			langBtn.addEventListener('click', function (e) {
				e.stopPropagation();
				var open = langList.hidden;
				langList.hidden = !open;
				langBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
			});
			document.addEventListener('click', function (e) {
				if (!langBox.contains(e.target)) closeLang();
			});
			document.addEventListener('keydown', function (e) {
				if (e.key === 'Escape') closeLang();
			});
		}

		var navbar = document.getElementById('hr_navbar');
		if (navbar) {
			var closeTimer = null;
			var subs = navbar.querySelectorAll('.hr-gnb-sub');
			var measure = function () {
				var tallest = 0;
				for (var i = 0; i < subs.length; i++) {
					var kids = subs[i].children;
					var h = 0;
					for (var k = 0; k < kids.length; k++) h += kids[k].offsetHeight;
					if (h > tallest) tallest = h;
				}
				navbar.style.setProperty('--hr-panel-h', tallest ? (tallest + 36) + 'px' : '0px');
			};
			measure();
			var resizeTimer = null;
			window.addEventListener('resize', function () {
				if (resizeTimer) clearTimeout(resizeTimer);
				resizeTimer = setTimeout(measure, 150);
			});

			var open = function () {
				if (closeTimer) { clearTimeout(closeTimer); closeTimer = null; }
				navbar.classList.add('is-open');
			};
			var close = function () {
				closeTimer = setTimeout(function () { navbar.classList.remove('is-open'); }, 140);
			};
			navbar.addEventListener('click', function (e) {
				var link = e.target.closest('a');
				if (link && (link.getAttribute('href') || '') === '#') {
					navbar.classList.remove('is-open');
					link.blur();
				}
			});
			navbar.addEventListener('mouseenter', open);
			navbar.addEventListener('mouseleave', close);
			navbar.addEventListener('focusin', open);
			navbar.addEventListener('focusout', close);
			document.addEventListener('keydown', function (e) {
				if (e.key === 'Escape') navbar.classList.remove('is-open');
			});
		}

		var burger = document.getElementById('hr_burger');
		var mobile = document.getElementById('hr_mobile');
		var dim = document.getElementById('hr_mobile_dim');
		if (burger && mobile) {
			var closeTimer2 = null;

			function openMobile() {
				if (closeTimer2) { clearTimeout(closeTimer2); closeTimer2 = null; }
				mobile.hidden = false;
				if (dim) dim.hidden = false;
				document.body.classList.add('hr-mobile-on');
				burger.setAttribute('aria-expanded', 'true');
				requestAnimationFrame(function () {
					mobile.classList.add('is-open');
					if (dim) dim.classList.add('is-open');
				});
			}

			function closeMobile() {
				mobile.classList.remove('is-open');
				if (dim) dim.classList.remove('is-open');
				document.body.classList.remove('hr-mobile-on');
				burger.setAttribute('aria-expanded', 'false');
				closeTimer2 = setTimeout(function () {
					mobile.hidden = true;
					if (dim) dim.hidden = true;
				}, 240);
			}

			burger.addEventListener('click', function () {
				if (burger.getAttribute('aria-expanded') === 'true') closeMobile();
				else openMobile();
			});
			if (dim) dim.addEventListener('click', closeMobile);
			var mclose = document.getElementById('hr_mobile_close');
			if (mclose) mclose.addEventListener('click', closeMobile);
			document.addEventListener('keydown', function (e) {
				if (e.key === 'Escape' && burger.getAttribute('aria-expanded') === 'true') closeMobile();
			});
			mobile.addEventListener('click', function (e) {
				if (e.target.closest('a')) closeMobile();
			});
			mobile.addEventListener('click', function (e) {
				var toggle = e.target.closest('.hr-mobile-toggle');
				if (!toggle) return;
				var sub = toggle.parentNode.querySelector('.hr-mobile-sub');
				if (!sub) return;
				var open = toggle.getAttribute('aria-expanded') === 'true';
				toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
				sub.hidden = open;
			});
		}

		var visual = document.querySelector('.hr-visual');
		if (!visual) return;

		var items = visual.querySelectorAll('.hr-visual-item');
		var dots = visual.querySelectorAll('.hr-visual-dots button');
		if (items.length < 2) return;

		var index = 0;
		var timer = null;
		var interval = parseInt(visual.getAttribute('data-interval'), 10);
		var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

		function show(next) {
			index = (next + items.length) % items.length;
			for (var i = 0; i < items.length; i++) {
				var on = (i === index);
				items[i].classList.toggle('is-on', on);
				items[i].setAttribute('aria-hidden', on ? 'false' : 'true');
				if (dots[i]) dots[i].classList.toggle('is-on', on);
			}
		}

		function start() {
			if (!interval || reduced) return;
			stop();
			timer = setInterval(function () { show(index + 1); }, interval * 1000);
		}
		function stop() { if (timer) { clearInterval(timer); timer = null; } }

		visual.addEventListener('click', function (e) {
			var nav = e.target.closest('.hr-visual-nav');
			if (nav) {
				show(index + (nav.classList.contains('prev') ? -1 : 1));
				start();
				return;
			}
			var dot = e.target.closest('.hr-visual-dots button');
			if (dot) {
				show(parseInt(dot.getAttribute('data-go'), 10) || 0);
				start();
			}
		});

		visual.addEventListener('mouseenter', stop);
		visual.addEventListener('mouseleave', start);
		visual.addEventListener('focusin', stop);
		document.addEventListener('visibilitychange', function () {
			if (document.hidden) { stop(); } else { start(); }
		});

		start();
	});
})();

document.addEventListener('DOMContentLoaded', function () {
	var nc = document.querySelector('.hr-nc');
	if (!nc) return;
	var btn = nc.querySelector('.hr-nc-btn');
	var panel = nc.querySelector('.hr-nc-panel');
	if (!btn || !panel) return;

	btn.addEventListener('click', function (e) {
		e.stopPropagation();
		var open = panel.hasAttribute('hidden');
		if (open) { panel.removeAttribute('hidden'); } else { panel.setAttribute('hidden', ''); }
		btn.setAttribute('aria-expanded', open ? 'true' : 'false');
	});
	document.addEventListener('click', function (e) {
		if (!panel.hasAttribute('hidden') && !nc.contains(e.target)) {
			panel.setAttribute('hidden', '');
			btn.setAttribute('aria-expanded', 'false');
		}
	});

	var readall = nc.querySelector('.hr-nc-readall');
	if (readall) {
		readall.addEventListener('click', function () {
			var csrf = document.querySelector('meta[name="csrf-token"]');
			fetch('./', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf ? csrf.content : '' },
				credentials: 'same-origin',
				body: JSON.stringify({ module: 'ncenterlite', act: 'procNcenterliteNotifyReadAll' })
			}).then(function () { window.location.reload(); });
		});
	}
});
