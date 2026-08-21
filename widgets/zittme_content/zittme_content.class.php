<?php

/**
 * 통합 콘텐츠 위젯 — 위젯 1개가 가로 1줄을 담당한다.
 *
 * 1줄 = 1~4개 열(슬롯)로 나뉘고, 각 슬롯에 게시판을 연결해
 * 목록/웹진/섬네일/매거진/혼합/탭 형태로 보여준다.
 * 이 위젯을 여러 개 쌓으면 메인 페이지가 완성된다.
 *
 * 100% 폭으로 삽입하면 CSS 컨테이너 쿼리로 각 슬롯이 "자기 폭"에 맞춰 형태를 바꾼다.
 * 렌더링은 전부 PHP 에서 조립한다 (템플릿 v1/v2 어느 쪽 함정도 밟지 않기 위해).
 */
class zittme_content extends WidgetHandler
{
	/**
	 * 1줄 조합 프리셋. cols 의 순서가 슬롯 1~4 순서와 일치한다.
	 * style 'comments' 는 목록 형태 + 댓글 소스의 축약이다.
	 */
	protected static $presets = [
		'mg_list' => ['cols' => [
			['width' => 58, 'style' => 'magazine', 'count' => 3, 'more' => 'auto'],
			['width' => 42, 'style' => 'list', 'count' => 8, 'more' => 'auto'],
		]],
		'list_mg' => ['cols' => [
			['width' => 42, 'style' => 'list', 'count' => 8, 'more' => 'auto'],
			['width' => 58, 'style' => 'magazine', 'count' => 3, 'more' => 'auto'],
		]],
		'wz_thumb' => ['cols' => [
			['width' => 50, 'style' => 'webzine', 'count' => 4, 'more' => 'auto'],
			['width' => 50, 'style' => 'thumb', 'count' => 4, 'more' => 'auto'],
		]],
		'list_wz' => ['cols' => [
			['width' => 38, 'style' => 'list', 'count' => 7, 'more' => 'auto'],
			['width' => 62, 'style' => 'webzine', 'count' => 3, 'more' => 'auto'],
		]],
		'wz_wz' => ['cols' => [
			['width' => 50, 'style' => 'webzine', 'count' => 3, 'more' => 'auto'],
			['width' => 50, 'style' => 'webzine', 'count' => 3, 'more' => 'auto'],
		]],
		'list_list' => ['cols' => [
			['width' => 50, 'style' => 'list', 'count' => 8, 'more' => 'auto'],
			['width' => 50, 'style' => 'list', 'count' => 8, 'more' => 'auto'],
		]],
		'tab_list_cmt' => ['cols' => [
			['width' => 40, 'style' => 'tab', 'count' => 8, 'more' => 'auto'],
			['width' => 30, 'style' => 'list', 'count' => 8, 'more' => 'auto'],
			['width' => 30, 'style' => 'comments', 'count' => 8],
		]],
		'list3' => ['cols' => [
			['width' => 34, 'style' => 'list', 'count' => 6, 'more' => 'auto'],
			['width' => 33, 'style' => 'list', 'count' => 6, 'more' => 'auto'],
			['width' => 33, 'style' => 'comments', 'count' => 6],
		]],
		'mg3' => ['cols' => [
			['width' => 34, 'style' => 'magazine', 'count' => 1],
			['width' => 33, 'style' => 'magazine', 'count' => 1],
			['width' => 33, 'style' => 'magazine', 'count' => 1],
		]],
		'mg_thumb' => ['cols' => [
			['width' => 55, 'style' => 'magazine', 'count' => 1],
			['width' => 45, 'style' => 'thumb', 'count' => 3, 'more' => 'auto'],
		]],
		'wz_full' => ['cols' => [
			['width' => 100, 'style' => 'webzine', 'count' => 4, 'more' => 'auto'],
		]],
		'thumb_full' => ['cols' => [
			['width' => 100, 'style' => 'thumb', 'count' => 8, 'more' => 'auto'],
		]],
		'mg_full' => ['cols' => [
			['width' => 100, 'style' => 'magazine', 'count' => 5, 'more' => 'auto'],
		]],
		'mixed_list' => ['cols' => [
			['width' => 62, 'style' => 'mixed', 'count' => 7, 'more' => 'auto'],
			['width' => 38, 'style' => 'list', 'count' => 8, 'more' => 'auto'],
		]],
	];

	protected static $variant = 'default';
	protected static $new_hours = 24;

	/**
	 * 위젯 언어팩. 위젯은 모듈처럼 자동 로드되지 않아 직접 읽어야 한다.
	 */
	protected static function lang(string $code): string
	{
		$text = \Context::getLang($code);
		return is_string($text) && $text !== '' && $text !== $code ? $text : '';
	}

	public function proc($args)
	{
		\Context::loadLang(__DIR__ . '/lang');
		$skin_name = trim((string)($args->skin ?? ''));
		self::$variant = $skin_name === 'heritage_xedition' ? 'heritage_xedition' : ($skin_name === 'heritage_default' ? 'heritage' : 'default');
		$new_hours = (float)($args->new_hours ?? 0);
		self::$new_hours = $new_hours > 0 ? $new_hours : 24;

		$preset = trim((string)($args->preset ?? ''));
		$cols = null;

		if ($preset !== '' && $preset !== 'custom' && isset(self::$presets[$preset]))
		{
			$cols = self::$presets[$preset]['cols'];
		}
		elseif ($preset === 'custom')
		{
			$ratio = trim((string)($args->columns ?? ''));
			$widths = array_values(array_filter(array_map('floatval', explode(':', $ratio)), function($w) { return $w > 0; }));
			if (count($widths) < 1 || count($widths) > 4)
			{
				return self::guideBox();
			}
			$cols = [];
			foreach ($widths as $i => $w)
			{
				$n = $i + 1;
				$style = trim((string)($args->{'slot' . $n . '_style'} ?? ''));
				$cols[] = [
					'width' => $w,
					'style' => $style !== '' ? $style : 'list',
					'count' => 0,
					'more' => 'auto',
				];
			}
		}

		if ($cols === null)
		{
			return self::guideBox();
		}

		foreach ($cols as $i => $col)
		{
			$n = $i + 1;
			$cols[$i]['mids'] = trim((string)($args->{'slot' . $n . '_mids'} ?? ''));
			$title = trim((string)($args->{'slot' . $n . '_title'} ?? ''));
			if ($title !== '')
			{
				$cols[$i]['title'] = $title;
			}
			$count = (int)($args->{'slot' . $n . '_count'} ?? 0);
			if ($count > 0)
			{
				$cols[$i]['count'] = $count;
			}
			elseif (empty($cols[$i]['count']))
			{
				$cols[$i]['count'] = 5;
			}
			if ($cols[$i]['style'] === 'comments')
			{
				$cols[$i]['style'] = 'list';
				$cols[$i]['source'] = 'comment';
				if (empty($cols[$i]['title']))
				{
					$cols[$i]['title'] = self::lang('zc_recent_comments');
				}
				unset($cols[$i]['more']);
			}
		}

		$widths = [];
		foreach ($cols as $col)
		{
			$widths[] = max(5, (float)$col['width']) . 'fr';
		}

		$html = self::styleTag();
		$html .= '<div class="zc zc-sk-' . self::$variant . '"><div class="zc-row" style="grid-template-columns:' . implode(' ', $widths) . '">';
		foreach ($cols as $col)
		{
			$html .= '<div class="zc-cell">' . self::renderBlock($col) . '</div>';
		}
		$html .= '</div></div>';
		return $html;
	}

	/* ------------------------------------------------------------------
	 * 블록 렌더
	 * ---------------------------------------------------------------- */

	protected static function renderBlock(array $col): string
	{
		$style = in_array($col['style'] ?? '', ['list', 'webzine', 'thumb', 'magazine', 'mixed', 'tab'], true) ? $col['style'] : 'list';
		$source = ($col['source'] ?? 'document') === 'comment' ? 'comment' : 'document';
		$mids = array_values(array_filter(array_map('trim', explode(',', (string)($col['mids'] ?? '')))));
		$count = min(20, max(1, (int)($col['count'] ?? 5)));

		if (!count($mids))
		{
			return '<div class="zc-block"><div class="zc-empty">' . escape(self::lang('zc_need_mid')) . '</div></div>';
		}

		if ($style === 'tab')
		{
			return self::renderTab($col, $mids, $source, $count);
		}

		$module_map = self::resolveMids($mids);
		if (!count($module_map))
		{
			return '<div class="zc-block"><div class="zc-empty">' . escape(sprintf(self::lang('zc_no_module_named'), implode(', ', $mids))) . '</div></div>';
		}
		$items = $source === 'comment'
			? self::fetchComments(array_keys($module_map), $count)
			: self::fetchDocuments(array_keys($module_map), $count, $style !== 'list');

		$is_all = self::isAllKeyword($mids[0]);
		$title = trim((string)($col['title'] ?? ''));
		if ($title === '')
		{
			if ($is_all || count($module_map) > 1)
			{
				$title = self::lang($source === 'comment' ? 'zc_recent_comments' : 'zc_recent_posts');
			}
			else
			{
				$first = reset($module_map);
				$title = (string)($first->browser_title ?? '');
			}
		}
		$more_url = $is_all ? '' : self::moreUrl($col, $mids[0]);

		$body = self::renderItems($style, $items);
		return self::blockShell($title, $more_url, $body, $style);
	}

	protected static function renderTab(array $col, array $mids, string $source, int $count): string
	{
		$module_map = self::resolveMids($mids);
		if (!count($module_map))
		{
			return '<div class="zc-block"><div class="zc-empty">' . escape(self::lang('zc_no_module')) . '</div></div>';
		}
		$uid = 'zct' . substr(md5(json_encode($mids) . mt_rand()), 0, 8);
		$tabs = '';
		$panes = '';
		$mores = '';
		$module_map = array_slice($module_map, 0, 8, true);
		$i = 0;
		foreach ($module_map as $module_srl => $info)
		{
			$items = $source === 'comment'
				? self::fetchComments([$module_srl], $count)
				: self::fetchDocuments([$module_srl], $count, false);
			$on = $i === 0 ? ' is-on' : '';
			$tabs .= '<button type="button" class="zc-tab' . $on . '" data-zct="' . $uid . '-' . $i . '">' . escape((string)$info->browser_title) . '</button>';
			$panes .= '<div class="zc-pane' . $on . '" id="' . $uid . '-' . $i . '">' . self::renderItems('list', $items) . '</div>';
			// 더보기는 판 아래가 아니라 탭 줄 오른쪽에 붙는다. 활성 탭 것만 보인다
			$mores .= '<a class="zc-more zc-tab-more' . $on . '" data-zctm="' . $uid . '-' . $i . '" href="' . escape(getUrl('', 'mid', $info->mid)) . '">' . escape(self::lang('zc_more')) . '</a>';
			$i++;
		}
		$script = '<script>(function(){var w=document.currentScript.parentNode;w.querySelectorAll(".zc-tab").forEach(function(b){b.addEventListener("click",function(){w.querySelectorAll(".zc-tab").forEach(function(x){x.classList.remove("is-on")});w.querySelectorAll(".zc-pane").forEach(function(x){x.classList.remove("is-on")});b.classList.add("is-on");var p=w.querySelector("#"+b.getAttribute("data-zct"));if(p)p.classList.add("is-on");w.querySelectorAll(".zc-tab-more").forEach(function(m){m.classList.toggle("is-on",m.getAttribute("data-zctm")===b.getAttribute("data-zct"))});});});})();</script>';
		return '<div class="zc-block zc-style-tab"><div class="zc-head zc-head-tabs">' . $tabs . $mores . '</div><div class="zc-body">' . $panes . '</div>' . $script . '</div>';
	}

	protected static function blockShell(string $title, string $more_url, string $body, string $style): string
	{
		$head = '<div class="zc-head"><h3>' . escape($title) . '</h3>';
		if ($more_url !== '')
		{
			$head .= '<a class="zc-more" href="' . escape($more_url) . '">' . escape(self::lang('zc_more'))
				. '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></a>';
		}
		$head .= '</div>';
		return '<div class="zc-block zc-style-' . $style . '">' . $head . '<div class="zc-body">' . $body . '</div></div>';
	}

	protected static function renderItems(string $style, array $items): string
	{
		if (!count($items))
		{
			return '<div class="zc-empty">' . escape(self::lang('zc_no_items')) . '</div>';
		}
		switch ($style)
		{
			case 'webzine':
				$out = '<ul class="zc-webzine">';
				foreach ($items as $it)
				{
					$out .= '<li><a href="' . escape($it['url']) . '">'
						. self::thumbTag($it, 'zc-wz-thumb')
						. '<span class="zc-wz-body"><strong>' . escape($it['title']) . self::badges($it) . '</strong>'
						. ($it['summary'] !== '' ? '<span class="zc-wz-sum">' . escape($it['summary']) . '</span>' : '')
						. '<small>' . escape($it['meta']) . '</small></span></a></li>';
				}
				return $out . '</ul>';
			case 'thumb':
				$out = '<div class="zc-thumbs">';
				foreach ($items as $it)
				{
					$out .= '<a href="' . escape($it['url']) . '">' . self::thumbTag($it, 'zc-th-img')
						. '<strong>' . escape($it['title']) . '</strong><small>' . escape($it['meta']) . '</small></a>';
				}
				return $out . '</div>';
			case 'magazine':
				$out = '<div class="zc-magazine">';
				$i = 0;
				foreach ($items as $it)
				{
					$cls = $i === 0 && count($items) > 1 ? ' is-lead' : '';
					$bg = $it['thumb'] !== '' ? ' style="background-image:url(\'' . escape($it['thumb']) . '\')"' : '';
					$out .= '<a class="zc-mg' . $cls . '" href="' . escape($it['url']) . '"' . $bg . '>'
						. '<span class="zc-mg-copy"><strong>' . escape($it['title']) . '</strong><small>' . escape($it['meta']) . '</small></span></a>';
					$i++;
				}
				return $out . '</div>';
			case 'mixed':
				$lead = array_slice($items, 0, 2);
				$rest = array_slice($items, 2);
				return self::renderItems('webzine', $lead) . self::renderItems('list', $rest);
			case 'list':
			default:
				$out = '<ul class="zc-list">';
				foreach ($items as $it)
				{
					$out .= '<li><a href="' . escape($it['url']) . '"><span class="zc-li-main"><span class="zc-li-title">' . escape($it['title']) . '</span>'
						. self::badges($it)
						. '</span><small>' . escape($it['date']) . '</small></a></li>';
				}
				return $out . '</ul>';
		}
	}

	/* ------------------------------------------------------------------
	 * 데이터 조회
	 * ---------------------------------------------------------------- */

	protected static function resolveMids(array $mids): array
	{
		$map = [];
		foreach ($mids as $mid)
		{
			if (self::isAllKeyword($mid))
			{
				foreach (self::allBoards() as $module_srl => $info)
				{
					$map[$module_srl] = $info;
				}
				continue;
			}
			$info = ModuleModel::getModuleInfoByMid($mid);
			if ($info && !empty($info->module_srl))
			{
				$map[(int)$info->module_srl] = $info;
			}
		}
		return $map;
	}

	/**
	 * 전체 게시판 키워드 판정. 같은 이름의 실제 mid 가 있으면 그 게시판이 우선한다.
	 * '*' 는 mid 로 쓸 수 없는 문자라 항상 전체를 뜻한다.
	 */
	protected static function isAllKeyword(string $mid): bool
	{
		if ($mid === '*')
		{
			return true;
		}
		if (strtolower($mid) !== 'all' && $mid !== '전체')
		{
			return false;
		}
		$info = ModuleModel::getModuleInfoByMid($mid);
		return !($info && !empty($info->module_srl));
	}

	protected static function allBoards(): array
	{
		static $boards = null;
		if ($boards !== null)
		{
			return $boards;
		}
		$boards = [];
		$obj = new stdClass;
		$obj->module = 'board';
		$output = executeQueryArray('module.getMidList', $obj);
		if ($output->toBool() && is_array($output->data))
		{
			foreach ($output->data as $info)
			{
				if (!empty($info->module_srl))
				{
					$boards[(int)$info->module_srl] = $info;
				}
			}
		}
		return $boards;
	}

	protected static function fetchDocuments(array $module_srls, int $count, bool $need_thumb): array
	{
		$obj = new stdClass;
		$obj->module_srl = implode(',', $module_srls);
		$obj->list_count = $count;
		$obj->sort_index = 'documents.list_order';
		$obj->order_type = 'asc';
		$obj->statusList = ['PUBLIC'];
		$output = executeQueryArray('widgets.content.getNewestDocuments', $obj, ['document_srl']);
		if (!$output->toBool() || empty($output->data))
		{
			return [];
		}
		$srls = [];
		foreach ($output->data as $row)
		{
			$srls[] = (int)$row->document_srl;
		}
		$docs = getModel('document')->getDocuments($srls, false, false);
		$items = [];
		foreach ($srls as $srl)
		{
			$oDocument = $docs[$srl] ?? null;
			if (!$oDocument || !$oDocument->isExists() || $oDocument->isSecret())
			{
				continue;
			}
			$items[] = [
				'title' => $oDocument->getTitleText(60),
				'url' => $oDocument->getPermanentUrl(),
				'thumb' => $need_thumb ? (string)$oDocument->getThumbnail(560, 380, 'crop') : '',
				'summary' => $need_thumb ? trim((string)$oDocument->getSummary(80)) : '',
				'date' => $oDocument->getRegdate('m.d'),
				'is_new' => $oDocument->getRegdateTime() > time() - 3600 * self::$new_hours,
				'comment_count' => (int)$oDocument->get('comment_count'),
				'meta' => $oDocument->get('nick_name') . ' · ' . $oDocument->getRegdate('m.d'),
			];
		}
		return $items;
	}

	protected static function fetchComments(array $module_srls, int $count): array
	{
		$obj = new stdClass;
		$obj->module_srl = implode(',', $module_srls);
		$obj->list_count = $count;
		$obj->sort_index = 'list_order';
		$obj->is_secret = 'N';
		$obj->statusList = [1];
		$comments = getModel('comment')->getNewestCommentList($obj);
		if (!is_array($comments))
		{
			return [];
		}
		$items = [];
		foreach ($comments as $oComment)
		{
			$items[] = [
				'title' => trim((string)$oComment->getSummary(60)),
				'url' => (string)$oComment->getPermanentUrl(),
				'thumb' => '',
				'summary' => '',
				'date' => $oComment->getRegdate('m.d'),
				'is_new' => method_exists($oComment, 'getRegdateTime') ? $oComment->getRegdateTime() > time() - 3600 * self::$new_hours : false,
				'comment_count' => 0,
				'meta' => $oComment->get('nick_name') . ' · ' . $oComment->getRegdate('m.d'),
			];
		}
		return $items;
	}

	protected static function moreUrl(array $col, string $first_mid): string
	{
		$more = trim((string)($col['more'] ?? ''));
		if ($more === '')
		{
			return '';
		}
		if ($more === 'auto')
		{
			return getUrl('', 'mid', $first_mid);
		}
		return $more;
	}

	protected static function badges(array $it): string
	{
		$out = '';
		$cnt = (int)($it['comment_count'] ?? 0);
		if ($cnt > 0)
		{
			$out .= '<em class="zc-li-cnt">' . (self::$variant === 'heritage' ? '[' . $cnt . ']' : '+' . $cnt) . '</em>';
		}
		if (!empty($it['is_new']))
		{
			$out .= '<em class="zc-new" aria-label="' . escape(self::lang('zc_new')) . '">N</em>';
		}
		return $out;
	}

	protected static function thumbTag(array $it, string $cls): string
	{
		if ($it['thumb'] !== '')
		{
			return '<span class="' . $cls . '" style="background-image:url(\'' . escape($it['thumb']) . '\')"></span>';
		}
		return '<span class="' . $cls . ' is-none"><svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M21 19V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2zM8.5 11l2.5 3 3.5-4.5L19 16H5z"/></svg></span>';
	}

	protected static function guideBox(): string
	{
		return self::styleTag() . '<div class="zc"><div class="zc-block"><div class="zc-empty" style="padding:40px 20px">'
			. '<b style="display:block;margin-bottom:8px;font-size:15px">' . escape(self::lang('zc_guide_title')) . '</b>'
			. escape(self::lang('zc_guide_1')) . '<br />'
			. escape(self::lang('zc_guide_2')) . '<br />'
			. escape(self::lang('zc_guide_3'))
			. '</div></div></div>';
	}

	protected static function styleTag(): string
	{
		static $printed = false;
		if ($printed)
		{
			return '';
		}
		$printed = true;
		return '<style>'
			. '.zc{--zc-brand:var(--hr-brand,#2677e3);--zc-ink:var(--hr-ink,#191f28);--zc-sub:var(--hr-muted,#6b7684);--zc-line:var(--hr-line,#e5e8eb);'
			. '--zc-card:#fff;--zc-divider:#f4f6f9;--zc-fill:#f2f4f7;--zc-dim:#98a1ad;--zc-icon:#c6ccd4;--zc-radius:14px;'
			. "font-family:'Pretendard Variable',Pretendard,-apple-system,BlinkMacSystemFont,system-ui,sans-serif;word-break:keep-all;display:flex;flex-direction:column;gap:18px;}"
			. '.zc+.zc{margin-top:18px;}'
			. '.zc-row{display:grid;gap:18px;align-items:stretch;}'
			. '.zc-cell{container-type:inline-size;min-width:0;}'
			. '.zc-block{display:flex;flex-direction:column;height:100%;border:1px solid var(--zc-line);border-radius:var(--zc-radius);background:var(--zc-card);padding:18px 20px;box-sizing:border-box;}'
			. '.zc-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px;padding-bottom:11px;border-bottom:1px solid var(--zc-line);}'
			. '.zc-head h3{margin:0;font-size:16px;font-weight:800;color:var(--zc-ink);letter-spacing:-.01em;}'
			. '.zc-more{display:inline-flex;align-items:center;gap:3px;font-size:12.5px;font-weight:600;color:var(--zc-sub);text-decoration:none;}'
			. '.zc-more:hover{color:var(--zc-brand);}'
			. '.zc-tab-more{display:none;margin-left:auto;}'
			. '.zc-tab-more.is-on{display:inline-flex;}'
			. '.zc-body{flex:1;display:flex;flex-direction:column;min-height:0;}'
			. '.zc-empty{padding:24px 0;text-align:center;font-size:13px;color:var(--zc-sub);}'
			. '.zc-list{list-style:none;margin:0;padding:0;flex:1;}'
			. '.zc-list li+li{border-top:1px solid var(--zc-divider);}'
			. '.zc-list a{display:flex;align-items:center;gap:8px;padding:8px 2px;text-decoration:none;color:var(--zc-ink);font-size:14px;}'
			. '.zc-list a:hover .zc-li-title{color:var(--zc-brand);}'
			. '.zc-li-main{flex:1;min-width:0;display:flex;align-items:center;}'
			. '.zc-li-title{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}'
			. '.zc-li-cnt{flex:0 0 auto;margin-left:5px;font-style:normal;font-size:12px;font-weight:700;color:var(--zc-brand);}'
			. '.zc-new{flex:0 0 auto;display:inline-block;margin-left:5px;width:14px;height:14px;line-height:14px;border-radius:3px;background:var(--zc-brand);color:#fff;font-style:normal;font-size:10px;font-weight:800;text-align:center;}'
			. '.zc-wz-body strong .zc-li-cnt,.zc-wz-body strong .zc-new{display:inline-block;vertical-align:1px;}'
			. '.zc-sk-heritage .zc-new{background:linear-gradient(#fdb14a,#f08000);box-shadow:inset 0 1px 0 rgba(255,255,255,.45);vertical-align:1px;}'
			. '.zc-sk-heritage .zc-li-cnt{color:var(--hr-brand,#2677e3);}'
			// heritage_xedition: 박스를 걷어내고 타이틀만 게시판 상단 헤더처럼(구분선 + 포인트색 짧은 라인).
			// 새 글 표시는 글자 없는 작은 사각형 하나로 줄인다
			. '.zc-sk-heritage_xedition .zc-block{border:0;border-radius:0;background:transparent;padding:0;}'
			// 탭형 머리에는 포인트 라인을 겹치지 않는다. 활성 탭 밑줄이 그 역할을 한다
			. '.zc-sk-heritage_xedition .zc-head:not(.zc-head-tabs){position:relative;padding-bottom:14px;margin-bottom:14px;border-bottom:1px solid var(--zc-line);}'
			. '.zc-sk-heritage_xedition .zc-head:not(.zc-head-tabs)::after{content:"";position:absolute;left:0;bottom:-1px;width:46px;height:3px;background:var(--zc-brand);}'
			. '.zc-sk-heritage_xedition .zc-head-tabs{margin-bottom:14px;}'
			. '.zc-sk-heritage_xedition .zc-tab{border-bottom-width:3px;}'
			. '.zc-sk-heritage_xedition .zc-new{width:7px;height:7px;border-radius:0;background:var(--zc-brand);font-size:0;line-height:0;overflow:hidden;vertical-align:5px;}'
			. '.zc-sk-heritage_xedition .zc-li-cnt{color:var(--hr-brand,#cda25a);}'
			// heritage_xedition 은 직사각 테마: 썸네일·매거진 타일도 각지게
			. '.zc-sk-heritage_xedition .zc-wz-thumb,.zc-sk-heritage_xedition .zc-th-img,.zc-sk-heritage_xedition .zc-mg{border-radius:0;}'
			. '.zc-list small{flex:0 0 auto;font-size:12px;color:var(--zc-sub);}'
			. '.zc-webzine{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:12px;flex:1;}'
			. '.zc-webzine a{display:flex;gap:14px;text-decoration:none;color:var(--zc-ink);}'
			. '.zc-wz-thumb{flex:0 0 116px;height:82px;border-radius:10px;background:var(--zc-fill) center/cover no-repeat;display:flex;align-items:center;justify-content:center;}'
			. '.zc-wz-thumb.is-none svg{width:26px;height:26px;fill:var(--zc-icon);}'
			. '.zc-wz-body{flex:1;min-width:0;display:flex;flex-direction:column;gap:4px;}'
			. '.zc-wz-body strong{font-size:14.5px;font-weight:700;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}'
			. '.zc-webzine a:hover strong{color:var(--zc-brand);}'
			. '.zc-wz-sum{font-size:12.5px;color:var(--zc-sub);line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}'
			. '.zc-wz-body small{font-size:11.5px;color:var(--zc-dim);}'
			. '.zc-thumbs{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:14px;flex:1;}'
			. '.zc-thumbs a{display:flex;flex-direction:column;gap:7px;text-decoration:none;color:var(--zc-ink);}'
			. '.zc-th-img{aspect-ratio:4/3;border-radius:10px;background:var(--zc-fill) center/cover no-repeat;display:flex;align-items:center;justify-content:center;transition:transform .15s;}'
			. '.zc-thumbs a:hover .zc-th-img{transform:translateY(-2px);}'
			. '.zc-th-img.is-none svg{width:30px;height:30px;fill:var(--zc-icon);}'
			. '.zc-thumbs strong{font-size:13.5px;font-weight:700;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}'
			. '.zc-thumbs a:hover strong{color:var(--zc-brand);}'
			. '.zc-thumbs small{font-size:11.5px;color:var(--zc-dim);}'
			. '.zc-magazine{display:grid;gap:12px;flex:1;grid-template-columns:1fr 1fr;grid-auto-rows:minmax(120px,1fr);}'
			. '.zc-mg{position:relative;display:flex;align-items:flex-end;border-radius:12px;overflow:hidden;background:#232b40 center/cover no-repeat;text-decoration:none;min-height:120px;}'
			. '.zc-mg.is-lead{grid-column:1/-1;min-height:220px;}'
			. '.zc-mg::after{content:"";position:absolute;inset:0;background:linear-gradient(180deg,transparent 30%,rgba(8,10,16,.72));}'
			. '.zc-mg-copy{position:relative;z-index:1;padding:14px 16px;color:#fff;display:flex;flex-direction:column;gap:3px;}'
			. '.zc-mg-copy strong{font-size:15px;font-weight:800;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}'
			. '.zc-mg.is-lead .zc-mg-copy strong{font-size:19px;}'
			. '.zc-mg-copy small{font-size:11.5px;opacity:.75;}'
			. '.zc-head-tabs{justify-content:flex-start;gap:2px;border-bottom:1px solid var(--zc-line);padding-bottom:0;margin-bottom:12px;}'
			. '.zc-tab{border:0;background:none;padding:9px 13px;font-size:14.5px;font-weight:700;color:var(--zc-sub);cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;font-family:inherit;}'
			. '.zc-tab.is-on{color:var(--zc-brand);border-bottom-color:var(--zc-brand);}'
			. '.zc-pane{display:none;flex-direction:column;flex:1;}'
			. '.zc-pane.is-on{display:flex;}'
			. '.zc-more-inline{margin-top:10px;align-self:flex-end;font-size:12.5px;font-weight:600;color:var(--zc-sub);text-decoration:none;}'
			. '.zc-more-inline:hover{color:var(--zc-brand);}'
			. '@container (max-width: 480px){.zc-thumbs{grid-template-columns:repeat(2,1fr);}.zc-wz-thumb{flex-basis:92px;height:66px;}.zc-mg.is-lead{min-height:170px;}}'
			. '@container (max-width: 340px){.zc-webzine a{flex-direction:column;}.zc-wz-thumb{width:100%;flex-basis:auto;height:140px;}.zc-thumbs{grid-template-columns:1fr;}.zc-magazine{grid-template-columns:1fr;}}'
			. '@media (max-width: 960px){.zc-row{grid-template-columns:1fr 1fr !important;}}'
			. '@media (max-width: 640px){.zc-row{grid-template-columns:1fr !important;}}'
			// 밝기는 레이아웃(data-theme)과 코어(color_scheme)가 정한다. 기기 설정은 코어가 읽어 옮겨 준다
			. self::darkRules(':root[data-theme="dark"] .zc,body.color_scheme_dark .zc')
			. '</style>';
	}

	/**
	 * 어두운 화면에서 쓸 토큰.
	 *
	 * @param string $selector
	 * @return string
	 */
	protected static function darkRules(string $selector): string
	{
		// 테마 변수를 끌어 쓰지 않는다. 테마가 [data-theme] 로만 색을 바꾸면
		// 기기 설정만 어두운 화면에서 글자색이 밝은 값 그대로 남아 배경에 묻힌다
		return $selector . '{--zc-ink:#e8ebf0;--zc-sub:#9aa3b2;--zc-line:#2a3040;'
			. '--zc-card:#161b26;--zc-divider:#232936;--zc-fill:#232936;--zc-dim:#7b8494;--zc-icon:#4a5364;}';
	}
}
