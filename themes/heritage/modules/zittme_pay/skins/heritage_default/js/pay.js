/**
 * 짓미 페이 — 결제 화면.
 *
 * 이 파일은 결제수단별 분기를 최소한으로만 갖는다. "결제창이 필요한가" 는 서버가
 * requires_client 로 알려 주고, 결제창에 넘길 값도 서버가 만들어 준다.
 * 새 PG 를 붙일 때 이 파일을 고칠 일이 없어야 한다.
 */
(function() {
	'use strict';

	/**
	 * DOM 이 준비된 뒤에 시작한다.
	 *
	 * 코어의 Context::addJsFile 은 기본값이 head 라서 이 파일은 <head> 에서 즉시 실행된다.
	 * 그 시점에는 #zpay-checkout 도, 템플릿 맨 아래에서 넣어 주는 window.ZPAY_BOOT 도
	 * 아직 존재하지 않는다. 곧바로 실행하면 아무것도 못 찾고 조용히 빠져나가, 결제 버튼이
	 * disabled 인 채로 남는다(화면은 멀쩡해 보여서 원인을 찾기 어렵다).
	 */
	function start() {
		var boot = window.ZPAY_BOOT || null;
		var root = document.getElementById('zpay-checkout');
		if (!boot || !root) {
			return;
		}
		init(boot, root);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}

	function init(boot, root) {

	var submit = document.getElementById('zpay-submit');
	var bankBox = document.getElementById('zpay-bank');
	var errorBox = document.getElementById('zpay-error');
	var methods = root.querySelectorAll('input[name="zpay_gateway"]');

	/**
	 * 라이믹스는 성공이든 실패든 HTTP 200 에 { error: 0|-1, message } 를 담아 준다.
	 * 그래서 상태코드가 아니라 error 값을 보고 갈라야 한다.
	 */
	function unwrap(payload) {
		if (!payload || typeof payload !== 'object') {
			throw new Error('invalid response');
		}
		if (Number(payload.error) !== 0) {
			throw new Error(payload.message || 'error');
		}
		return payload;
	}

	function request(act, params) {
		var body = Object.assign({ module: 'zittme_pay', act: act }, params || {});
		var headers = { 'Content-Type': 'application/json' };
		var csrf = document.querySelector('meta[name="csrf-token"]');
		if (csrf) {
			headers['X-CSRF-Token'] = csrf.getAttribute('content');
		}

		return fetch('./', {
			method: 'POST',
			headers: headers,
			credentials: 'same-origin',
			body: JSON.stringify(body)
		}).then(function(response) {
			return response.json();
		}).then(unwrap);
	}

	function showError(message) {
		errorBox.textContent = message;
		errorBox.hidden = false;
	}

	function clearError() {
		errorBox.textContent = '';
		errorBox.hidden = true;
	}

	function setBusy(busy) {
		submit.disabled = busy || !selectedGateway();
		submit.classList.toggle('is-busy', busy);
	}

	function selectedGateway() {
		var checked = root.querySelector('input[name="zpay_gateway"]:checked');
		return checked ? checked.value : '';
	}

	/**
	 * 결제수단을 고를 때마다 화면을 맞춘다.
	 */
	function onSelect() {
		var name = selectedGateway();

		Array.prototype.forEach.call(methods, function(input) {
			input.closest('.zpay-method').classList.toggle('is-on', input.checked);
		});

		if (bankBox) {
			bankBox.hidden = (name !== 'banktransfer');
		}

		submit.disabled = !name;
		clearError();
	}

	/**
	 * 결제창이 필요한 결제수단. 서버가 준 값 그대로 SDK 에 넘긴다.
	 *
	 * 금액을 여기서 만들지 않는 것이 중요하다. 브라우저가 만든 금액은 어차피 서버가
	 * 승인 직전에 자기 주문 금액과 대조해 버리므로, 애초에 서버가 준 값만 전달한다.
	 */
	function openPaymentWindow(gatewayName, payload) {
		if (gatewayName === 'toss') {
			if (typeof window.TossPayments !== 'function') {
				showError('TossPayments SDK not loaded');
				setBusy(false);
				return;
			}
			var toss = window.TossPayments(payload.clientKey);
			toss.requestPayment('카드', {
				amount: payload.amount,
				orderId: payload.orderId,
				orderName: payload.orderName,
				customerName: payload.customerName,
				customerEmail: payload.customerEmail,
				successUrl: payload.successUrl,
				failUrl: payload.failUrl
			}).catch(function(error) {
				showError(error && error.message ? error.message : 'payment cancelled');
				setBusy(false);
			});
			return;
		}

		if (gatewayName === 'inicis') {
			if (!window.INIStdPay || typeof window.INIStdPay.pay !== 'function') {
				showError('INIStdPay SDK not loaded');
				setBusy(false);
				return;
			}
			// 이니시스는 폼 POST 방식이라 숨은 폼을 만들어 넘긴다
			var oldForm = document.getElementById('zpay-inicis-form');
			if (oldForm) {
				oldForm.parentNode.removeChild(oldForm);
			}
			var form = document.createElement('form');
			form.id = 'zpay-inicis-form';
			form.method = 'post';
			form.hidden = true;
			Object.keys(payload.fields || {}).forEach(function(key) {
				var input = document.createElement('input');
				input.type = 'hidden';
				input.name = key;
				input.value = payload.fields[key];
				form.appendChild(input);
			});
			document.body.appendChild(form);
			window.INIStdPay.pay('zpay-inicis-form');
			setBusy(false);
			return;
		}

		if (gatewayName === 'kcp') {
			if (typeof window.KCP_Pay_Execute_Web !== 'function') {
				showError('KCP SDK not loaded');
				setBusy(false);
				return;
			}
			// KCP 도 폼 방식이라 숨은 폼을 만들어 넘긴다
			var oldKcpForm = document.getElementById('zpay-kcp-form');
			if (oldKcpForm) {
				oldKcpForm.parentNode.removeChild(oldKcpForm);
			}
			var kcpForm = document.createElement('form');
			kcpForm.id = 'zpay-kcp-form';
			kcpForm.name = 'zpay_kcp_form';
			kcpForm.method = 'post';
			kcpForm.hidden = true;
			Object.keys(payload.fields || {}).forEach(function(key) {
				var input = document.createElement('input');
				input.type = 'hidden';
				input.name = key;
				input.value = payload.fields[key];
				kcpForm.appendChild(input);
			});
			document.body.appendChild(kcpForm);
			window.KCP_Pay_Execute_Web(kcpForm);
			setBusy(false);
			return;
		}

		if (gatewayName === 'nicepay') {
			if (!window.AUTHNICE || typeof window.AUTHNICE.requestPay !== 'function') {
				showError('NICE Pay SDK not loaded');
				setBusy(false);
				return;
			}
			window.AUTHNICE.requestPay({
				clientId: payload.clientId,
				method: payload.method,
				orderId: payload.orderId,
				amount: payload.amount,
				goodsName: payload.goodsName,
				buyerName: payload.buyerName,
				buyerEmail: payload.buyerEmail,
				buyerTel: payload.buyerTel,
				returnUrl: payload.returnUrl,
				fnError: function(result) {
					showError(result && result.errorMsg ? result.errorMsg : 'payment failed');
					setBusy(false);
				}
			});
			// 인증이 끝나면 나이스페이가 returnUrl 로 POST 한다. 여기서 할 일은 없다.
			return;
		}

		if (gatewayName === 'portone') {
			if (!window.PortOne || typeof window.PortOne.requestPayment !== 'function') {
				showError('PortOne SDK not loaded');
				setBusy(false);
				return;
			}
			window.PortOne.requestPayment({
				storeId: payload.storeId,
				channelKey: payload.channelKey,
				paymentId: payload.paymentId,
				orderName: payload.orderName,
				totalAmount: payload.totalAmount,
				currency: payload.currency,
				customer: payload.customer,
				redirectUrl: payload.redirectUrl
			}).then(function(response) {
				if (response && response.code) {
					showError(response.message || 'payment failed');
					setBusy(false);
					return;
				}
				// PC 는 같은 창에서 끝난다. 결과 확정은 서버 콜백(조회 검증)이 한다.
				window.location.href = payload.redirectUrl + '&paymentId=' + encodeURIComponent(payload.paymentId);
			}).catch(function(error) {
				showError(error && error.message ? error.message : 'payment cancelled');
				setBusy(false);
			});
			return;
		}

		showError('unsupported gateway: ' + gatewayName);
		setBusy(false);
	}

	function onSubmit() {
		var gatewayName = selectedGateway();
		if (!gatewayName) {
			return;
		}

		clearError();
		setBusy(true);

		var params = {
			state: boot.state,
			gateway: gatewayName
		};

		if (gatewayName === 'banktransfer') {
			var bankIndex = root.querySelector('input[name="zpay_bank_index"]:checked');
			var depositor = document.getElementById('zpay-depositor');
			params.bank_index = bankIndex ? bankIndex.value : 0;
			params.depositor_name = depositor ? depositor.value : '';
		}

		request('procZittme_payReady', params).then(function(data) {
			if (data.requires_client) {
				openPaymentWindow(gatewayName, data.request);
				return;
			}
			// 서버에서 처리가 끝난 결제수단은 결과 화면 주소를 돌려준다.
			window.location.href = data.redirect_url || './';
		}).catch(function(error) {
			showError(error.message || 'error');
			setBusy(false);
		});
	}

	Array.prototype.forEach.call(methods, function(input) {
		input.addEventListener('change', onSelect);
	});
	submit.addEventListener('click', onSubmit);

	// 결제수단이 하나뿐이면 미리 골라 둔다.
	if (methods.length === 1) {
		methods[0].checked = true;
	}
	onSelect();

	} // init
})();
