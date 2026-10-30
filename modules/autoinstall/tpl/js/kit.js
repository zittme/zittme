(function() {
	'use strict';

	function call(act, data) {
		return Zittme.ajax('autoinstall.' + act, data || {});
	}

	function parse(json) {
		try { return JSON.parse(json || '{}'); } catch (e) { return {}; }
	}

	function setBar(box, ratio) {
		var bar = box.querySelector('.zmkit-bar span');
		var wrap = box.querySelector('.zmkit-bar');
		var pct = Math.max(0, Math.min(100, Math.round(ratio * 100)));
		if (bar) { bar.style.width = pct + '%'; }
		if (wrap) { wrap.setAttribute('aria-valuenow', pct); }
	}

	function initStart() {
		var form = document.getElementById('zmkitStart');
		if (!form) { return; }
		form.addEventListener('submit', function(e) {
			e.preventDefault();
			var btn = form.querySelector('button[type=submit]');
			if (btn.disabled) { return; }
			btn.disabled = true;
			var data = { job_srl: form.dataset.jobSrl };
			form.querySelectorAll('input[type=checkbox]').forEach(function(input) {
				if (input.checked) { data[input.name] = 'Y'; }
			});
			call('procAutoinstallAdminKitStart', data).then(function() {
				location.href = form.dataset.jobUrl;
			}).catch(function(err) {
				btn.disabled = false;
				NativeAlert(err.message);
			});
		});
	}

	function initProgress() {
		var box = document.getElementById('zmkitProgress');
		if (!box) { return; }
		var names = parse(box.dataset.stepNames);
		var stepEl = document.getElementById('zmkitStep');
		var statusEl = document.getElementById('zmkitStatus');
		var errorEl = document.getElementById('zmkitError');
		var busy = false;

		function showError(message) {
			errorEl.textContent = message || '';
			errorEl.hidden = !message;
		}

		function render(p) {
			var ratio = (p.step_index + (p.total ? Math.min(p.cursor / p.total, 1) : 0)) / p.step_count;
			setBar(box, p.status === 'done' ? 1 : ratio);
			var label = names[p.step] || p.step;
			if (p.total) { label += ' (' + Math.min(p.cursor, p.total) + ' / ' + p.total + ')'; }
			stepEl.textContent = label;
			box.querySelectorAll('.zmkit-steps li').forEach(function(li, i) {
				li.classList.toggle('is-done', i < p.step_index || p.status === 'done');
				li.classList.toggle('is-current', i === p.step_index && p.status !== 'done');
			});
			statusEl.className = 'zmkit-status is-' + p.status;
		}

		function run(resume) {
			if (busy) { return; }
			busy = true;
			showError('');
			call('procAutoinstallAdminKitRun', { job_srl: box.dataset.jobSrl, resume: resume ? 'Y' : 'N' }).then(function(res) {
				busy = false;
				var p = res.progress;
				render(p);
				if (p.status === 'running') {
					setTimeout(function() { run(false); }, 150);
				} else if (p.status === 'failed') {
					showError(box.dataset.msgFailed + ' ' + (p.message || ''));
					setTimeout(function() { location.reload(); }, 1200);
				} else {
					location.reload();
				}
			}).catch(function(err) {
				busy = false;
				showError(err.message);
			});
		}

		function remove(mode) {
			var question = mode === 'rollback' ? box.dataset.confirmRollback : box.dataset.confirmRemove;
			if (!confirm(question)) { return; }
			box.querySelectorAll('button').forEach(function(b) { b.disabled = true; });
			stepEl.textContent = box.dataset.msgRemoving;
			(function loop() {
				call('procAutoinstallAdminKitRemove', { job_srl: box.dataset.jobSrl, mode: mode }).then(function(res) {
					var r = res.result;
					if (r.status === 'removing') {
						stepEl.textContent = box.dataset.msgRemoving + ' (' + r.left + ')';
						setTimeout(loop, 150);
					} else {
						location.reload();
					}
				}).catch(function(err) {
					showError(err.message);
					box.querySelectorAll('button').forEach(function(b) { b.disabled = false; });
				});
			})();
		}

		box.querySelectorAll('[data-kit-resume]').forEach(function(btn) {
			btn.addEventListener('click', function() { run(true); });
		});
		box.querySelectorAll('[data-kit-remove]').forEach(function(btn) {
			btn.addEventListener('click', function() { remove(btn.dataset.kitRemove); });
		});
		if (box.dataset.autorun === 'Y') {
			run(false);
		}
	}

	function initExport() {
		var form = document.getElementById('zmkitExport');
		if (!form) { return; }
		var names = parse(form.dataset.stepNames);
		var box = document.getElementById('zmkitExportProgress');
		var stepEl = box.querySelector('[data-export-step]');
		var errorEl = box.querySelector('[data-export-error]');
		var steps = ['collect', 'media', 'pack', 'done'];

		form.addEventListener('submit', function(e) {
			e.preventDefault();
			var btn = form.querySelector('.zmkit-actions button');
			btn.disabled = true;
			box.hidden = false;
			errorEl.hidden = true;
			var data = {};
			new FormData(form).forEach(function(value, key) {
				if (key.slice(-2) === '[]') {
					key = key.slice(0, -2);
					(data[key] = data[key] || []).push(value);
				} else {
					data[key] = value;
				}
			});
			stepEl.textContent = names.collect || '';
			call('procAutoinstallAdminKitExportStart', data).then(function(res) {
				var token = res.token;
				(function loop() {
					call('procAutoinstallAdminKitExportRun', { token: token }).then(function(r) {
						var ex = r.export;
						var idx = steps.indexOf(ex.step);
						var part = ex.step === 'media' && ex.total ? ex.done / ex.total : 0;
						setBar(box, (idx + part) / (steps.length - 1));
						stepEl.textContent = (names[ex.step] || ex.step) + (ex.step === 'media' && ex.total ? ' (' + ex.done + ' / ' + ex.total + ')' : '');
						if (ex.step === 'done') {
							var link = box.querySelector('[data-export-download]');
							var url = new URL(form.dataset.downloadUrl, location.href);
							url.searchParams.set('token', token);
							link.href = url.toString();
							link.hidden = false;
							box.querySelector('[data-export-done]').hidden = false;
							btn.disabled = false;
						} else {
							setTimeout(loop, 100);
						}
					}).catch(function(err) {
						errorEl.textContent = err.message;
						errorEl.hidden = false;
						btn.disabled = false;
					});
				})();
			}).catch(function(err) {
				errorEl.textContent = err.message;
				errorEl.hidden = false;
				btn.disabled = false;
			});
		});
	}

	document.addEventListener('DOMContentLoaded', function() {
		initStart();
		initProgress();
		initExport();
	});
})();
