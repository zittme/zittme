(function() {
	'use strict';

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

	if (methods.length === 1) {
		methods[0].checked = true;
	}
	onSelect();

	} // init
})();
