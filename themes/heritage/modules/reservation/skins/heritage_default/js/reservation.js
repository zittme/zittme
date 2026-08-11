/* 예약 달력 — 월 단위로 잔여 슬롯(JSON)을 받아 그린다 */
(function () {
	'use strict';

	function boot() {
		var root = document.getElementById('rsv_calendar');
		if (!root) return;

		var resource = root.getAttribute('data-resource');
		var slotsUrl = root.getAttribute('data-slots-url');
		var formUrl = root.getAttribute('data-form-url');
		var grid = document.getElementById('rsv_cal_grid');
		var monthLabel = document.getElementById('rsv_cal_month');
		var timesBox = document.getElementById('rsv_times');
		var timesGrid = document.getElementById('rsv_times_grid');
		var timesDate = document.getElementById('rsv_times_date');

		var current = new Date();
		current.setDate(1);
		var slotsByDate = {}; // 'YYYYMMDD' -> [slot]
		var selectedYmd = null;

		var DOW = ['일', '월', '화', '수', '목', '금', '토'];

		function ymd(d) {
			return d.getFullYear() + String(d.getMonth() + 1).padStart(2, '0') + String(d.getDate()).padStart(2, '0');
		}

		function fetchMonth(then) {
			var from = new Date(current.getFullYear(), current.getMonth(), 1);
			var to = new Date(current.getFullYear(), current.getMonth() + 1, 0);
			var headers = { 'Content-Type': 'application/json' };
			var meta = document.querySelector('meta[name="csrf-token"]');
			if (meta) headers['X-CSRF-Token'] = meta.getAttribute('content');
			fetch('./', {
				method: 'POST',
				headers: headers,
				credentials: 'same-origin',
				body: JSON.stringify({
					module: 'reservation',
					act: 'procReservationGetSlots',
					resource_srl: resource,
					from: ymd(from),
					to: ymd(to)
				})
			})
				.then(function (r) { return r.json(); })
				.then(function (data) {
					slotsByDate = {};
					(data.slots || []).forEach(function (s) {
						(slotsByDate[s.date] = slotsByDate[s.date] || []).push(s);
					});
					then();
				})
				.catch(function () { then(); });
		}

		function render() {
			monthLabel.textContent = current.getFullYear() + '. ' + (current.getMonth() + 1);
			grid.innerHTML = '';
			DOW.forEach(function (d) {
				var el = document.createElement('div');
				el.className = 'rsv-cal-dow';
				el.textContent = d;
				grid.appendChild(el);
			});

			var first = new Date(current.getFullYear(), current.getMonth(), 1);
			var last = new Date(current.getFullYear(), current.getMonth() + 1, 0);
			for (var i = 0; i < first.getDay(); i++) {
				grid.appendChild(document.createElement('div'));
			}
			for (var day = 1; day <= last.getDate(); day++) {
				(function (day) {
					var d = new Date(current.getFullYear(), current.getMonth(), day);
					var key = ymd(d);
					var slots = slotsByDate[key] || [];
					var avail = slots.some(function (s) { return s.available; });

					var btn = document.createElement('button');
					btn.type = 'button';
					btn.className = 'rsv-cal-day' + (avail ? ' is-avail' : ' is-out') + (key === selectedYmd ? ' is-selected' : '');
					btn.textContent = day;
					if (avail) {
						var dot = document.createElement('span');
						dot.className = 'rsv-dot';
						btn.appendChild(dot);
						btn.addEventListener('click', function () { selectDate(key); });
					} else {
						btn.disabled = true;
					}
					grid.appendChild(btn);
				})(day);
			}
		}

		function selectDate(key) {
			selectedYmd = key;
			render();
			var slots = slotsByDate[key] || [];
			timesBox.hidden = false;
			timesDate.textContent = key.slice(0, 4) + '.' + key.slice(4, 6) + '.' + key.slice(6, 8);
			timesGrid.innerHTML = '';
			slots.forEach(function (s) {
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'rsv-time' + (s.available ? '' : ' is-full');
				var label = document.createElement('span');
				label.textContent = s.start;
				btn.appendChild(label);
				var small = document.createElement('small');
				small.textContent = s.available ? ('잔여 ' + s.remain) : '마감';
				btn.appendChild(small);
				if (s.available) {
					btn.addEventListener('click', function () {
						location.href = formUrl + (formUrl.indexOf('?') === -1 ? '?' : '&') + 'slot_srl=' + s.slot_srl;
					});
				} else {
					btn.disabled = true;
				}
				timesGrid.appendChild(btn);
			});
			timesBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		}

		root.querySelectorAll('.rsv-cal-nav').forEach(function (btn) {
			btn.addEventListener('click', function () {
				current.setMonth(current.getMonth() + parseInt(btn.getAttribute('data-nav'), 10));
				selectedYmd = null;
				timesBox.hidden = true;
				fetchMonth(render);
			});
		});

		fetchMonth(render);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
