/**
 * 관리 홈 카운터 차트 — 순 방문자 / 페이지 뷰 (주간 비교)
 *
 * 레거시 jqPlot(232KB) 대신 의존성 없는 인라인 SVG 로 직접 그린다.
 * 설계:
 *  - 형태: 그룹 세로 막대 (요일별 지난주 vs 이번주)
 *  - 색: 강조(emphasis) — 이번주는 브랜드 컬러, 지난주는 맥락용 중립 회색
 *  - 막대 상단만 4px 라운드, 인접 막대 사이 2px 표면 여백
 *  - 그리드는 hairline 1px 실선, 축은 후퇴시킴
 *  - 2계열이므로 범례 상시 표시 + 막대별 호버 툴팁
 *  - 다크 모드는 자동 반전이 아니라 전용 색을 지정한다
 */
(function () {
	'use strict';

	var PALETTE = {
		light: { last: '#6b7280', now: '#2677e3' },
		dark:  { last: '#7c8698', now: '#3a86ff' }
	};

	function isDark() {
		var t = document.documentElement.getAttribute('data-zm-theme');
		if (t === 'dark') return true;
		if (t === 'light') return false;
		return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
	}

	function cssVar(name, fallback) {
		var v = getComputedStyle(document.documentElement).getPropertyValue(name);
		return (v && v.trim()) || fallback;
	}

	function obj2Array(o) {
		var a = [];
		for (var k in o) { if (o.hasOwnProperty(k)) a.push(Number(o[k]) || 0); }
		return a;
	}

	function fmt(n) { return Number(n).toLocaleString(); }

	// y축 눈금: 0 을 포함하고 사람이 읽기 좋은 간격으로 올림.
	// 방문자·페이지뷰는 정수이므로 step 은 1 미만이 될 수 없다
	// (그러지 않으면 값이 작을 때 0.5 같은 소수 눈금이 나온다).
	function niceScale(max) {
		if (max <= 0) return { max: 2, step: 1 };
		var raw = max / 2;
		var mag = Math.pow(10, Math.floor(Math.log(raw) / Math.LN10));
		var norm = raw / mag;
		var step = (norm <= 1 ? 1 : norm <= 2 ? 2 : norm <= 5 ? 5 : 10) * mag;
		step = Math.max(1, Math.round(step));
		// 눈금은 0 / step / 2×step 세 개만 그리므로 상한을 2×step 으로 맞춘다.
		// (상한을 따로 올림하면 중간 눈금이 max/2 가 되어 소수로 떨어질 수 있다)
		while (step * 2 < max) step *= 2;
		return { max: step * 2, step: step };
	}

	function el(tag, attrs) {
		var n = document.createElementNS('http://www.w3.org/2000/svg', tag);
		for (var k in attrs) { if (attrs.hasOwnProperty(k)) n.setAttribute(k, attrs[k]); }
		return n;
	}

	// 상단만 둥근 막대 (베이스라인 쪽은 각지게)
	function barPath(x, y, w, h, r) {
		r = Math.min(r, w / 2, h);
		if (h <= 0) return '';
		return 'M' + x + ',' + (y + h) +
			'L' + x + ',' + (y + r) +
			'Q' + x + ',' + y + ' ' + (x + r) + ',' + y +
			'L' + (x + w - r) + ',' + y +
			'Q' + (x + w) + ',' + y + ' ' + (x + w) + ',' + (y + r) +
			'L' + (x + w) + ',' + (y + h) + 'Z';
	}

	function draw(containerId, lastWeek, thisWeek) {
		var host = document.getElementById(containerId);
		if (!host) return;

		var C = isDark() ? PALETTE.dark : PALETTE.light;
		var lineSoft = cssVar('--zm-line-soft', '#f0f3f8');
		var lineBase = cssVar('--zm-line', '#e5e9f0');
		var inkFaint = cssVar('--zm-ink-faint', '#9aa2af');
		var inkSoft = cssVar('--zm-ink-soft', '#6b7280');

		var days = [xe.lang.sun, xe.lang.mon, xe.lang.tue, xe.lang.wed, xe.lang.thu, xe.lang.fri, xe.lang.sat];

		host.textContent = '';
		host.className = (host.className || '').replace(/\bzm-chart\b/g, '') + ' zm-chart';

		// 범례 — 2계열이므로 항상 표시 (색만으로 식별하게 두지 않는다)
		var legend = document.createElement('div');
		legend.className = 'zm-chart-legend';
		[[C.last, xe.lang.last_week], [C.now, xe.lang.this_week]].forEach(function (s) {
			var item = document.createElement('span');
			item.className = 'zm-chart-legend-item';
			var dot = document.createElement('i');
			dot.style.background = s[0];
			item.appendChild(dot);
			item.appendChild(document.createTextNode(s[1]));
			legend.appendChild(item);
		});
		host.appendChild(legend);

		var W = host.clientWidth || 320;
		var H = 126;
		var padL = 36, padR = 6, padT = 10, padB = 22;
		var plotW = Math.max(60, W - padL - padR);
		var plotH = H - padT - padB;

		var peak = Math.max.apply(null, lastWeek.concat(thisWeek).concat([0]));
		var sc = niceScale(peak);

		var svg = el('svg', {
			viewBox: '0 0 ' + W + ' ' + H,
			width: '100%', height: H, role: 'img'
		});

		var y = function (v) { return padT + plotH - (v / sc.max) * plotH; };

		// 그리드 + y축 라벨 — 3단계만 (과밀 방지)
		[0, sc.max / 2, sc.max].forEach(function (v) {
			svg.appendChild(el('line', {
				x1: padL, x2: padL + plotW, y1: y(v), y2: y(v),
				stroke: v === 0 ? lineBase : lineSoft, 'stroke-width': 1, 'shape-rendering': 'crispEdges'
			}));
			var t = el('text', { x: padL - 8, y: y(v) + 3.5, 'text-anchor': 'end', fill: inkFaint, 'font-size': 10 });
			t.textContent = fmt(v);
			svg.appendChild(t);
		});

		var band = plotW / days.length;
		var gap = 2;                                        // 인접 막대 사이 표면 여백
		var barW = Math.min(14, Math.max(4, (band - 14 - gap) / 2));

		days.forEach(function (label, i) {
			var cx = padL + band * i + band / 2;
			var slots = [
				[lastWeek[i] || 0, C.last, cx - barW - gap / 2, xe.lang.last_week],
				[thisWeek[i] || 0, C.now,  cx + gap / 2,        xe.lang.this_week]
			];
			slots.forEach(function (b) {
				var v = b[0], h = (v / sc.max) * plotH;
				var g = el('g', { class: 'zm-bar' });
				// 값이 0 이어도 호버되도록 투명 히트영역
				g.appendChild(el('rect', { x: b[2], y: padT, width: barW, height: plotH, fill: 'transparent' }));
				if (h > 0) {
					g.appendChild(el('path', { d: barPath(b[2], y(v), barW, Math.max(h, 2), 4), fill: b[1] }));
				}
				var title = el('title');
				title.textContent = label + ' · ' + b[3] + ' ' + fmt(v);
				g.appendChild(title);
				svg.appendChild(g);
			});

			var xt = el('text', { x: cx, y: H - 6, 'text-anchor': 'middle', fill: inkSoft, 'font-size': 11 });
			xt.textContent = label;
			svg.appendChild(xt);
		});

		host.appendChild(svg);
	}

	var cache = {};
	function render(id) { if (cache[id]) draw(id, cache[id][0], cache[id][1]); }
	function renderAll() { render('visitors'); render('page_views'); }

	jQuery(function ($) {
		function load(action, id) {
			$.exec_json(action, {}, function (res) {
				cache[id] = [obj2Array(res.last_week.list), obj2Array(res.this_week.list)];
				render(id);
			});
		}
		load('counter.getWeeklyUniqueVisitor', 'visitors');
		load('counter.getWeeklyPageView', 'page_views');

		var t;
		$(window).on('resize', function () {
			clearTimeout(t);
			t = setTimeout(renderAll, 150);
		});

		// 테마 전환에 반응 (다크는 자동 반전이 아니라 전용 색을 쓴다)
		if (window.matchMedia) {
			var mq = window.matchMedia('(prefers-color-scheme: dark)');
			if (mq.addEventListener) mq.addEventListener('change', renderAll);
			else if (mq.addListener) mq.addListener(renderAll);
		}
		if (window.MutationObserver) {
			new MutationObserver(renderAll).observe(document.documentElement,
				{ attributes: true, attributeFilter: ['data-zm-theme'] });
		}
	});
})();
