(function () {
	'use strict';

	var popupChannels = { facebook: 1, x: 1, naver: 1, band: 1, line: 1, threads: 1 };
	var kakaoLoading = null;

	function canNativeShare() {
		return typeof navigator.share === 'function';
	}

	function isTouchDevice() {
		return window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
	}

	function toast(box, message) {
		var el = box.querySelector('.zm-share__toast');
		if (!el) {
			return;
		}
		el.textContent = message;
		el.classList.add('is-shown');
		clearTimeout(el._zmTimer);
		el._zmTimer = setTimeout(function () {
			el.classList.remove('is-shown');
		}, 2200);
	}

	function fallbackCopy(text) {
		var ta = document.createElement('textarea');
		ta.value = text;
		ta.setAttribute('readonly', '');
		ta.style.position = 'fixed';
		ta.style.top = '-1000px';
		document.body.appendChild(ta);
		ta.select();
		var ok = false;
		try {
			ok = document.execCommand('copy');
		} catch (e) {
			ok = false;
		}
		document.body.removeChild(ta);
		return ok;
	}

	function copyLink(box, message) {
		var url = box.getAttribute('data-url');
		var done = function () { toast(box, message || box.getAttribute('data-msg-copied')); };
		var fail = function () {
			if (fallbackCopy(url)) {
				done();
			} else {
				toast(box, box.getAttribute('data-msg-copy-failed'));
			}
		};
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(url).then(done, fail);
		} else {
			fail();
		}
	}

	function nativeShare(box) {
		navigator.share({
			title: box.getAttribute('data-title'),
			url: box.getAttribute('data-url')
		}).catch(function () {});
	}

	function loadKakao(box) {
		if (window.Kakao && window.Kakao.Share) {
			return Promise.resolve(window.Kakao);
		}
		if (!kakaoLoading) {
			kakaoLoading = new Promise(function (resolve, reject) {
				var s = document.createElement('script');
				s.src = box.getAttribute('data-kakao-sdk');
				var integrity = box.getAttribute('data-kakao-sdk-integrity');
				if (integrity) {
					s.integrity = integrity;
				}
				s.crossOrigin = 'anonymous';
				s.onload = function () { resolve(window.Kakao); };
				s.onerror = function () { kakaoLoading = null; reject(); };
				document.head.appendChild(s);
			});
		}
		return kakaoLoading;
	}

	function kakaoShare(box) {
		var key = box.getAttribute('data-kakao-key');
		var fallback = function () {
			if (canNativeShare()) {
				nativeShare(box);
			} else {
				copyLink(box, box.getAttribute('data-msg-kakao-paste'));
			}
		};
		if (!key) {
			fallback();
			return;
		}
		loadKakao(box).then(function (Kakao) {
			if (!Kakao.isInitialized()) {
				Kakao.init(key);
			}
			Kakao.Share.sendScrap({ requestUrl: box.getAttribute('data-url') });
		}).catch(fallback);
	}

	function onClick(e) {
		var btn = e.target.closest ? e.target.closest('.zm-share [data-share]') : null;
		if (!btn) {
			return;
		}
		var box = btn.closest('[data-zm-share]');
		var channel = btn.getAttribute('data-share');
		if (channel === 'copy') {
			e.preventDefault();
			copyLink(box);
		} else if (channel === 'native') {
			e.preventDefault();
			nativeShare(box);
		} else if (channel === 'kakao') {
			e.preventDefault();
			kakaoShare(box);
		} else if (popupChannels[channel] && !isTouchDevice()) {
			e.preventDefault();
			window.open(btn.href, 'zm_share', 'noopener,noreferrer,width=600,height=640');
		}
	}

	function placeAuto(box) {
		var others = document.querySelectorAll('[data-zm-share]:not([data-zm-share-auto])');
		if (others.length) {
			box.parentNode.removeChild(box);
			return;
		}
		var srl = box.getAttribute('data-document-srl');
		var target = document.querySelector('[class*="document_' + srl + '_"].xe_content')
			|| document.querySelector('.rhymix_content')
			|| document.querySelector('.xe_content');
		if (!target) {
			box.parentNode.removeChild(box);
			return;
		}
		target.parentNode.insertBefore(box, target.nextSibling);
		box.hidden = false;
	}

	function init() {
		var boxes = document.querySelectorAll('[data-zm-share]');
		Array.prototype.forEach.call(boxes, function (box) {
			if (box.hasAttribute('data-zm-share-auto')) {
				placeAuto(box);
			}
			var native = box.querySelector('[data-share="native"]');
			if (native && canNativeShare() && isTouchDevice()) {
				var item = native.parentNode;
				item.hidden = false;
				item.parentNode.insertBefore(item, item.parentNode.firstChild);
			}
		});
		document.addEventListener('click', onClick);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
