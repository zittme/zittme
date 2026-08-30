<?php

/**
 * 통합 커머스 위젯 — 위젯 1개가 가로 1줄을 담당한다.
 *
 * 1줄 = 1~4개 열(슬롯)로 나뉘고, 각 슬롯에 쇼핑몰(mid)을 연결해
 * 격자/가로넘김/목록/큰카드/탭 형태로 상품을 보여준다.
 *
 * 커머스 모듈이 없는 설치본에서는 아무것도 그리지 않는다.
 */
class zittme_commerce extends WidgetHandler
{
	/**
	 * 1줄 조합 프리셋. cols 의 순서가 슬롯 1~4 순서와 일치한다.
	 */
	protected static $presets = [
		// count 는 칸 수와 같게 둔다. 개수를 늘리면 그만큼 아랫줄이 생긴다
		'grid4' => ['cols' => [
			['width' => 100, 'style' => 'grid', 'count' => 4, 'cols' => 4, 'more' => 'auto'],
		]],
		'grid3' => ['cols' => [
			['width' => 100, 'style' => 'grid', 'count' => 3, 'cols' => 3, 'more' => 'auto'],
		]],
		'carousel' => ['cols' => [
			['width' => 100, 'style' => 'carousel', 'count' => 12, 'more' => 'auto'],
		]],
		'feature_grid' => ['cols' => [
			['width' => 42, 'style' => 'feature', 'count' => 1],
			['width' => 58, 'style' => 'grid', 'count' => 4, 'cols' => 2, 'more' => 'auto'],
		]],
		'grid_list' => ['cols' => [
			['width' => 62, 'style' => 'grid', 'count' => 4, 'cols' => 2, 'more' => 'auto'],
			['width' => 38, 'style' => 'list', 'count' => 5, 'more' => 'auto'],
		]],
		'list_list' => ['cols' => [
			['width' => 50, 'style' => 'list', 'count' => 5, 'more' => 'auto'],
			['width' => 50, 'style' => 'list', 'count' => 5, 'more' => 'auto'],
		]],
		'tab_grid' => ['cols' => [
			['width' => 45, 'style' => 'tab', 'count' => 4],
			['width' => 55, 'style' => 'grid', 'count' => 4, 'cols' => 2, 'more' => 'auto'],
		]],
		'grid3col' => ['cols' => [
			['width' => 34, 'style' => 'grid', 'count' => 2, 'cols' => 1, 'more' => 'auto'],
			['width' => 33, 'style' => 'grid', 'count' => 2, 'cols' => 1, 'more' => 'auto'],
			['width' => 33, 'style' => 'grid', 'count' => 2, 'cols' => 1, 'more' => 'auto'],
		]],
	];

	protected static $variant = 'default';
	protected static $new_hours = 72;
	protected static $show_price = true;
	protected static $show_badge = true;
	protected static $show_soldout = true;
	protected static $carousel_auto = false;
	protected static $carousel_speed = 'normal';

	/**
	 * 위젯 언어팩. 위젯은 모듈처럼 자동 로드되지 않아 직접 읽어야 한다.
	 */
	protected static function lang(string $code): string
	{
		$text = \Context::getLang($code);
		return is_string($text) && $text !== '' && $text !== $code ? $text : '';
	}

	/**
	 * 사용자 정의 다국어 코드('$user_lang->코드')를 현재 언어 값으로 바꾼다.
	 *
	 * 코어는 완성된 HTML 을 한 번 훑어 치환하지만, escape 로 내보내면 '->' 가
	 * '&gt;' 가 되어 그 정규식에 걸리지 않는다. 그래서 내보내기 전에 미리 바꾼다.
	 */
	protected static function userLang($value): string
	{
		return \Context::replaceUserLang((string)$value);
	}

	/**
	 * 커머스가 설치돼 있는지. 없으면 위젯은 아무것도 그리지 않는다.
	 */
	protected static function hasCommerce(): bool
	{
		return class_exists('\Zittme\Modules\Commerce\Models\Item')
			|| file_exists(\RX_BASEDIR . 'modules/commerce/models/Item.php');
	}

	public function proc($args)
	{
		if (!self::hasCommerce())
		{
			return '';
		}
		require_once \RX_BASEDIR . 'modules/commerce/helpers.php';
		\Context::loadLang(__DIR__ . '/lang');
		\Context::loadLang(\RX_BASEDIR . 'modules/commerce/lang');

		$skin_name = trim((string)($args->skin ?? ''));
		self::$variant = $skin_name === 'heritage_xedition' ? 'heritage_xedition' : ($skin_name === 'heritage_default' ? 'heritage' : 'default');
		$new_hours = (float)($args->new_hours ?? 0);
		self::$new_hours = $new_hours > 0 ? $new_hours : 72;
		self::$show_price = trim((string)($args->show_price ?? 'Y')) !== 'N';
		self::$show_badge = trim((string)($args->show_badge ?? 'Y')) !== 'N';
		self::$show_soldout = trim((string)($args->show_soldout ?? 'Y')) !== 'N';
		self::$carousel_auto = trim((string)($args->carousel_auto ?? 'N')) === 'Y';
		$speed = trim((string)($args->carousel_speed ?? 'normal'));
		self::$carousel_speed = in_array($speed, ['slow', 'fast'], true) ? $speed : 'normal';

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
					'style' => $style !== '' ? $style : 'grid',
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
			$cols[$i]['mid'] = trim((string)($args->{'slot' . $n . '_mid'} ?? ''));
			$title = trim((string)($args->{'slot' . $n . '_title'} ?? ''));
			if ($title !== '')
			{
				$cols[$i]['title'] = $title;
			}
			$source = trim((string)($args->{'slot' . $n . '_source'} ?? ''));
			$cols[$i]['source'] = $source !== '' ? $source : 'newest';
			$cols[$i]['key'] = trim((string)($args->{'slot' . $n . '_key'} ?? ''));
			$count = (int)($args->{'slot' . $n . '_count'} ?? 0);
			if ($count > 0)
			{
				$cols[$i]['count'] = $count;
			}
			elseif (empty($cols[$i]['count']))
			{
				$cols[$i]['count'] = 4;
			}
		}

		$widths = [];
		foreach ($cols as $col)
		{
			$widths[] = max(5, (float)$col['width']) . 'fr';
		}

		$html = self::styleTag();
		$html .= '<div class="zm zm-sk-' . self::$variant . '"><div class="zm-row" style="grid-template-columns:' . implode(' ', $widths) . '">';
		foreach ($cols as $col)
		{
			$html .= '<div class="zm-cell">' . self::renderBlock($col) . '</div>';
		}
		$html .= '</div></div>';
		return $html;
	}

	protected static function renderBlock(array $col): string
	{
		$style = in_array($col['style'] ?? '', ['grid', 'carousel', 'list', 'feature', 'tab'], true) ? $col['style'] : 'grid';
		$count = min(24, max(1, (int)($col['count'] ?? 4)));

		$shop = self::resolveShop((string)($col['mid'] ?? ''));
		if (!$shop)
		{
			return '<div class="zm-block"><div class="zm-empty">' . escape(self::lang('zx_no_shop')) . '</div></div>';
		}

		if ($style === 'tab')
		{
			return self::renderTab($col, $shop, $count);
		}

		$items = self::fetchItems($col['source'] ?? 'newest', (string)($col['key'] ?? ''), $count);
		$title = trim((string)($col['title'] ?? ''));
		if ($title === '')
		{
			$title = self::sourceTitle((string)($col['source'] ?? 'newest'), (string)($col['key'] ?? ''), $shop);
		}
		$more_url = self::moreUrl($col, $shop);

		$body = self::renderItems($style, $items, $shop, (int)($col['cols'] ?? 0));
		return self::blockShell($title, $more_url, $body, $style);
	}

	/**
	 * 대상 번호에 분류 번호를 쉼표로 적으면 그 분류만 탭으로 세운다.
	 */
	protected static function renderTab(array $col, object $shop, int $count): string
	{
		$cats = self::categories();
		$only = array_values(array_filter(array_map('intval', explode(',', (string)($col['key'] ?? '')))));
		if (count($only))
		{
			$cats = array_values(array_filter($cats, function($c) use ($only) { return in_array((int)$c->category_srl, $only, true); }));
		}
		$cats = array_slice($cats, 0, 8);
		if (!count($cats))
		{
			return '<div class="zm-block"><div class="zm-empty">' . escape(self::lang('zx_no_category')) . '</div></div>';
		}

		$uid = 'zmt' . substr(md5($shop->mid . $col['key'] . mt_rand()), 0, 8);
		$tabs = '';
		$panes = '';
		$mores = '';
		$i = 0;
		foreach ($cats as $cat)
		{
			$items = self::fetchItems('category', (string)$cat->category_srl, $count);
			$on = $i === 0 ? ' is-on' : '';
			$tabs .= '<button type="button" class="zm-tab' . $on . '" data-zmt="' . $uid . '-' . $i . '">' . escape(self::userLang($cat->title)) . '</button>';
			$panes .= '<div class="zm-pane' . $on . '" id="' . $uid . '-' . $i . '">' . self::renderItems('grid', $items, $shop, 2) . '</div>';
			// 더보기는 판 아래가 아니라 탭 줄 오른쪽에 붙는다. 활성 탭 것만 보인다
			$mores .= '<a class="zm-more zm-tab-more' . $on . '" data-zmtm="' . $uid . '-' . $i . '" href="' . escape(getUrl('', 'mid', $shop->mid, 'v', 'list', 'category', $cat->category_srl)) . '">' . escape(self::lang('zx_more')) . '</a>';
			$i++;
		}
		$script = '<script>(function(){var w=document.currentScript.parentNode;w.querySelectorAll(".zm-tab").forEach(function(b){b.addEventListener("click",function(){w.querySelectorAll(".zm-tab").forEach(function(x){x.classList.remove("is-on")});w.querySelectorAll(".zm-pane").forEach(function(x){x.classList.remove("is-on")});b.classList.add("is-on");var p=w.querySelector("#"+b.getAttribute("data-zmt"));if(p)p.classList.add("is-on");w.querySelectorAll(".zm-tab-more").forEach(function(m){m.classList.toggle("is-on",m.getAttribute("data-zmtm")===b.getAttribute("data-zmt"))});});});})();</script>';
		return '<div class="zm-block zm-style-tab"><div class="zm-head zm-head-tabs">' . $tabs . $mores . '</div><div class="zm-body">' . $panes . '</div>' . $script . '</div>';
	}

	protected static function blockShell(string $title, string $more_url, string $body, string $style): string
	{
		$head = '<div class="zm-head"><h3>' . escape($title) . '</h3>';
		if ($more_url !== '')
		{
			$head .= '<a class="zm-more" href="' . escape($more_url) . '">' . escape(self::lang('zx_more'))
				. '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></a>';
		}
		$head .= '</div>';
		return '<div class="zm-block zm-style-' . $style . '">' . $head . '<div class="zm-body">' . $body . '</div></div>';
	}

	protected static function renderItems(string $style, array $items, object $shop, int $grid_cols = 0): string
	{
		if (!count($items))
		{
			return '<div class="zm-empty">' . escape(self::lang('zx_no_items')) . '</div>';
		}
		switch ($style)
		{
			case 'carousel':
				$auto = self::$carousel_auto && count($items) > 2;
				$out = '<div class="zm-carousel' . ($auto ? ' is-auto sp-' . self::$carousel_speed : '') . '"><div class="zm-carousel-track">';
				foreach ($items as $it)
				{
					$out .= self::card($it, $shop);
				}
				if ($auto)
				{
					foreach ($items as $it)
					{
						$out .= self::card($it, $shop);
					}
				}
				$out .= '</div></div>';
				return $out;
			case 'list':
				$out = '<ul class="zm-list">';
				foreach ($items as $it)
				{
					$out .= '<li><a href="' . escape(self::itemUrl($it, $shop)) . '">'
						. self::thumbTag($it, 'zm-li-thumb')
						. '<span class="zm-li-body"><strong>' . escape(self::userLang($it->item_name)) . '</strong>'
						. self::priceTag($it)
						. '</span></a></li>';
				}
				return $out . '</ul>';
			case 'feature':
				$out = '<div class="zm-feature">';
				foreach ($items as $it)
				{
					$out .= '<a class="zm-feature-item" href="' . escape(self::itemUrl($it, $shop)) . '">'
						. self::thumbTag($it, 'zm-feature-thumb')
						. self::soldoutCover($it)
						. '<span class="zm-feature-body">' . self::badges($it)
						. '<strong>' . escape(self::userLang($it->item_name)) . '</strong>'
						. (trim((string)($it->summary ?? '')) !== '' ? '<span class="zm-feature-sum">' . escape(self::userLang($it->summary)) . '</span>' : '')
						. self::priceTag($it) . '</span></a>';
				}
				return $out . '</div>';
			case 'grid':
			default:
				$style_attr = $grid_cols > 0 ? ' style="grid-template-columns:repeat(' . $grid_cols . ',minmax(0,1fr))"' : '';
				$out = '<div class="zm-grid"' . $style_attr . '>';
				foreach ($items as $it)
				{
					$out .= self::card($it, $shop);
				}
				return $out . '</div>';
		}
	}

	protected static function card(object $it, object $shop): string
	{
		return '<a class="zm-card" href="' . escape(self::itemUrl($it, $shop)) . '">'
			. self::thumbTag($it, 'zm-card-thumb')
			. self::soldoutCover($it)
			. '<span class="zm-card-body">' . self::badges($it)
			. '<span class="zm-card-name">' . escape(self::userLang($it->item_name)) . '</span>'
			. self::priceTag($it) . '</span></a>';
	}

	protected static function itemUrl(object $it, object $shop): string
	{
		return getUrl('', 'mid', $shop->mid, 'act', 'dispCommerceItem', 'item_srl', (int)$it->item_srl);
	}

	protected static function soldoutCover(object $it): string
	{
		if (($it->status ?? '') !== 'soldout')
		{
			return '';
		}
		return '<span class="zm-soldout">' . escape(self::lang('zx_soldout')) . '</span>';
	}

	protected static function priceTag(object $it): string
	{
		if (!self::$show_price)
		{
			return '';
		}
		$price = (int)($it->price ?? 0);
		$sale = (int)($it->sale_price ?? 0);
		if ($sale > 0 && $sale < $price)
		{
			return '<span class="zm-price"><s>' . escape(shop_money($price)) . '</s><strong>' . escape(shop_money($sale)) . '</strong></span>';
		}
		return '<span class="zm-price"><strong>' . escape(shop_money($price > 0 ? $price : $sale)) . '</strong></span>';
	}

	protected static function badges(object $it): string
	{
		if (!self::$show_badge)
		{
			return '';
		}
		$out = '';
		if (($it->is_recommend ?? 'N') === 'Y')
		{
			$out .= '<span class="zm-badge">' . escape(self::lang('zx_badge_recommend')) . '</span>';
		}
		foreach ((array)($it->badge_list ?? []) as $badge)
		{
			$style = '';
			if (!empty($badge->bg_color))
			{
				$style .= 'background:' . preg_replace('/[^#0-9a-zA-Z(),.% ]/', '', (string)$badge->bg_color) . ';';
			}
			if (!empty($badge->color))
			{
				$style .= 'color:' . preg_replace('/[^#0-9a-zA-Z(),.% ]/', '', (string)$badge->color) . ';';
			}
			$out .= '<span class="zm-badge" style="' . escape($style) . '">' . escape(self::userLang($badge->title)) . '</span>';
		}
		if (self::isNew($it))
		{
			$out .= '<span class="zm-badge">NEW</span>';
		}
		$sale = (int)($it->sale_price ?? 0);
		$price = (int)($it->price ?? 0);
		if ($sale > 0 && $price > 0 && $sale < $price)
		{
			$out .= '<span class="zm-badge">' . floor((($price - $sale) / $price) * 100) . '%</span>';
		}
		return $out !== '' ? '<span class="zm-badges">' . $out . '</span>' : '';
	}

	protected static function isNew(object $it): bool
	{
		if (($it->is_new ?? 'N') === 'Y')
		{
			return true;
		}
		$regdate = (string)($it->regdate ?? '');
		if (strlen($regdate) < 8)
		{
			return false;
		}
		return ztime($regdate) > time() - 3600 * self::$new_hours;
	}

	protected static function thumbTag(object $it, string $cls): string
	{
		$thumb = trim((string)($it->thumb ?? ''));
		if ($thumb !== '')
		{
			return '<span class="' . $cls . '" style="background-image:url(\'' . escape($thumb) . '\')"></span>';
		}
		return '<span class="' . $cls . ' is-none"><svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M21 19V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2zM8.5 11l2.5 3 3.5-4.5L19 16H5z"/></svg></span>';
	}

	/**
	 * mid 를 비우면 처음 만든 커머스 모듈을 쓴다.
	 */
	protected static function resolveShop(string $mid): ?object
	{
		if ($mid !== '')
		{
			$info = \ModuleModel::getModuleInfoByMid($mid);
			return ($info && ($info->module ?? '') === 'commerce') ? $info : null;
		}
		static $first = false;
		if ($first !== false)
		{
			return $first;
		}
		$first = null;
		$output = executeQueryArray('module.getMidList', (object)['module' => 'commerce']);
		if ($output->toBool() && is_array($output->data) && count($output->data))
		{
			$first = $output->data[0];
		}
		return $first;
	}

	protected static function categories(): array
	{
		static $cats = null;
		if ($cats !== null)
		{
			return $cats;
		}
		$cats = [];
		$output = executeQueryArray('commerce.getCategoryList', (object)['is_active' => 'Y']);
		if ($output->toBool() && is_array($output->data))
		{
			foreach ($output->data as $row)
			{
				if (!empty($row->category_srl) && (int)($row->parent_srl ?? 0) === 0)
				{
					$cats[] = $row;
				}
			}
		}
		return $cats;
	}

	/**
	 * 뽑을 기준별 상품 목록.
	 *
	 * @param string $source newest|popular|sale|recommend|category|promotion|items
	 * @param string $key 분류·기획전 번호 또는 상품 번호 목록
	 * @param int $count
	 * @return array
	 */
	protected static function fetchItems(string $source, string $key, int $count): array
	{
		$status = self::$show_soldout ? ['sale', 'soldout'] : ['sale'];

		if ($source === 'items')
		{
			$srls = array_values(array_filter(array_map('intval', explode(',', $key))));
			$items = [];
			foreach (array_slice($srls, 0, $count) as $srl)
			{
				$row = \Zittme\Modules\Commerce\Models\Item::get($srl);
				if ($row && in_array((string)$row->status, $status, true))
				{
					$items[] = $row;
				}
			}
			return self::decorate($items);
		}

		if ($source === 'promotion')
		{
			$promo_srl = (int)$key;
			if ($promo_srl <= 0)
			{
				$promo = \Zittme\Modules\Commerce\Models\Promotion::get(0, $key);
				$promo_srl = (int)($promo->promo_srl ?? 0);
			}
			if ($promo_srl <= 0)
			{
				return [];
			}
			$rows = \Zittme\Modules\Commerce\Models\Promotion::itemsOf($promo_srl);
			$rows = array_values(array_filter($rows, function($row) use ($status) { return in_array((string)($row->status ?? ''), $status, true); }));
			return self::decorate(array_slice($rows, 0, $count));
		}

		$obj = new stdClass;
		$obj->status_list = $status;
		$obj->list_count = $count;
		$obj->page = 1;

		switch ($source)
		{
			case 'popular':
				$obj->sort_index = 'buy_count';
				$obj->order_type = 'desc';
				break;
			case 'recommend':
				$obj->is_recommend = 'Y';
				$obj->sort_index = 'list_order';
				$obj->order_type = 'asc';
				break;
			case 'category':
				$obj->category_srl = (int)$key;
				$obj->sort_index = 'list_order';
				$obj->order_type = 'asc';
				break;
			case 'sale':
				// 할인 여부는 정가와 판매가를 견줘야 해서 질의로 못 거른다. 넉넉히 가져와 골라낸다
				$obj->list_count = max(60, $count * 6);
				$obj->sort_index = 'regdate';
				$obj->order_type = 'desc';
				break;
			case 'newest':
			default:
				$obj->sort_index = 'regdate';
				$obj->order_type = 'desc';
				break;
		}

		$output = executeQueryArray('commerce.getItemList', $obj);
		$rows = ($output->toBool() && is_array($output->data)) ? $output->data : [];

		if ($source === 'sale')
		{
			$rows = array_values(array_filter($rows, function($row) {
				return (int)($row->sale_price ?? 0) > 0 && (int)$row->sale_price < (int)($row->price ?? 0);
			}));
			$rows = array_slice($rows, 0, $count);
		}

		return self::decorate($rows);
	}

	protected static function decorate(array $rows): array
	{
		if (!count($rows) || !class_exists('\Zittme\Modules\Commerce\Models\Badge'))
		{
			return $rows;
		}
		$map = \Zittme\Modules\Commerce\Models\Badge::getMap(true);
		foreach ($rows as $row)
		{
			if (is_object($row))
			{
				$row->badge_list = \Zittme\Modules\Commerce\Models\Badge::ofItem($row, $map);
			}
		}
		return $rows;
	}

	protected static function sourceTitle(string $source, string $key, object $shop): string
	{
		switch ($source)
		{
			case 'popular':
				return self::lang('shop_home_popular') ?: self::lang('zx_popular');
			case 'sale':
				return self::lang('shop_home_sale') ?: self::lang('zx_sale');
			case 'recommend':
				return self::lang('shop_home_recommend') ?: self::lang('zx_recommend');
			case 'category':
				foreach (self::categories() as $cat)
				{
					if ((int)$cat->category_srl === (int)$key)
					{
						return self::userLang($cat->title);
					}
				}
				return (string)($shop->browser_title ?? '');
			case 'promotion':
				$promo = (int)$key > 0
					? \Zittme\Modules\Commerce\Models\Promotion::get((int)$key)
					: \Zittme\Modules\Commerce\Models\Promotion::get(0, $key);
				return self::userLang($promo->title ?? $shop->browser_title ?? '');
			case 'items':
				return (string)($shop->browser_title ?? '');
			case 'newest':
			default:
				return self::lang('shop_home_new') ?: self::lang('zx_newest');
		}
	}

	protected static function moreUrl(array $col, object $shop): string
	{
		$more = trim((string)($col['more'] ?? ''));
		if ($more === '')
		{
			return '';
		}
		$source = (string)($col['source'] ?? 'newest');
		$key = (string)($col['key'] ?? '');
		if ($source === 'category' && (int)$key > 0)
		{
			return getUrl('', 'mid', $shop->mid, 'v', 'list', 'category', (int)$key);
		}
		if ($source === 'promotion')
		{
			$promo = (int)$key > 0
				? \Zittme\Modules\Commerce\Models\Promotion::get((int)$key)
				: \Zittme\Modules\Commerce\Models\Promotion::get(0, $key);
			if ($promo && !empty($promo->slug))
			{
				return getUrl('', 'mid', $shop->mid, 'v', 'promo', 'p', $promo->slug);
			}
			return getUrl('', 'mid', $shop->mid, 'v', 'list');
		}
		if (in_array($source, ['newest', 'popular', 'sale', 'recommend'], true))
		{
			$f = $source === 'newest' ? 'new' : $source;
			return getUrl('', 'mid', $shop->mid, 'v', 'list', 'f', $f);
		}
		return getUrl('', 'mid', $shop->mid, 'v', 'list');
	}

	protected static function guideBox(): string
	{
		return self::styleTag() . '<div class="zm"><div class="zm-block"><div class="zm-empty" style="padding:40px 20px">'
			. '<b style="display:block;margin-bottom:8px;font-size:15px">' . escape(self::lang('zx_guide_title')) . '</b>'
			. escape(self::lang('zx_guide_1')) . '<br />'
			. escape(self::lang('zx_guide_2')) . '<br />'
			. escape(self::lang('zx_guide_3'))
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
			. '.zm{--zm-brand:var(--hr-brand,#2677e3);--zm-brand-soft:var(--hr-brand-soft,rgba(38,119,227,.08));'
			. '--zm-ink:var(--hr-ink,#191f28);--zm-sub:var(--hr-muted,#6b7684);--zm-line:var(--hr-line,#e5e8eb);'
			. '--zm-card:#fff;--zm-divider:#f4f6f9;--zm-fill:#f2f4f7;--zm-dim:#98a1ad;--zm-icon:#c6ccd4;--zm-radius:14px;'
			. "font-family:'Pretendard Variable',Pretendard,-apple-system,BlinkMacSystemFont,system-ui,sans-serif;word-break:keep-all;display:flex;flex-direction:column;gap:18px;}"
			. '.zm+.zm{margin-top:18px;}'
			. '.zm-row{display:grid;gap:18px;align-items:stretch;}'
			. '.zm-cell{container-type:inline-size;min-width:0;}'
			. '.zm-block{display:flex;flex-direction:column;height:100%;border:1px solid var(--zm-line);border-radius:var(--zm-radius);background:var(--zm-card);padding:18px 20px;box-sizing:border-box;}'
			. '.zm-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px;padding-bottom:11px;border-bottom:1px solid var(--zm-line);}'
			. '.zm-head h3{margin:0;font-size:16px;font-weight:800;color:var(--zm-ink);letter-spacing:-.01em;}'
			. '.zm-more{display:inline-flex;align-items:center;gap:3px;font-size:12.5px;font-weight:600;color:var(--zm-sub);text-decoration:none;}'
			. '.zm-more:hover{color:var(--zm-brand);}'
			. '.zm-tab-more{display:none;margin-left:auto;}'
			. '.zm-tab-more.is-on{display:inline-flex;}'
			. '.zm-body{flex:1;display:flex;flex-direction:column;min-height:0;}'
			. '.zm-empty{padding:24px 0;text-align:center;font-size:13px;color:var(--zm-sub);}'
			. '.zm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:16px;flex:1;align-content:start;}'
			. '.zm-card{position:relative;display:flex;flex-direction:column;gap:8px;text-decoration:none;color:var(--zm-ink);}'
			. '.zm-card-thumb{aspect-ratio:1/1;border-radius:10px;background:var(--zm-fill) center/cover no-repeat;display:flex;align-items:center;justify-content:center;transition:transform .15s;}'
			. '.zm-card:hover .zm-card-thumb{transform:translateY(-2px);}'
			. '.zm-card-thumb.is-none svg{width:32px;height:32px;fill:var(--zm-icon);}'
			. '.zm-card-body{display:flex;flex-direction:column;gap:5px;min-width:0;}'
			. '.zm-card-name{font-size:13.5px;font-weight:600;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}'
			. '.zm-card:hover .zm-card-name{color:var(--zm-brand);}'
			// 뱃지·가격·품절 표시는 커머스 스킨(.shp-badge / .shp-price / .shp-soldout-cover)과 같은 값을 쓴다
			. '.zm-badges{display:flex;flex-wrap:wrap;gap:4px;}'
			// 뱃지·가격·품절은 커머스 스킨(.shp-badge / .shp-price / .shp-soldout-cover)과 같은 값을 쓴다
			. '.zm-badge{display:inline-block;padding:2px 7px;border-radius:999px;background:var(--zm-brand-soft);color:var(--zm-brand);font-size:11px;font-weight:700;line-height:1.5;}'
			. '.zm-sk-heritage .zm-badge{border-radius:3px;}'
			. '.zm-sk-heritage_xedition .zm-badge{border-radius:0;}'
			// heritage_xedition: 박스를 걷어내고 타이틀만 게시판 상단 헤더처럼
			. '.zm-sk-heritage_xedition .zm-block{border:0;border-radius:0;background:transparent;padding:0;}'
			// 탭형 머리에는 포인트 라인을 겹치지 않는다. 활성 탭 밑줄이 그 역할을 한다
			. '.zm-sk-heritage_xedition .zm-head:not(.zm-head-tabs){position:relative;padding-bottom:14px;margin-bottom:14px;border-bottom:1px solid var(--zm-line);}'
			. '.zm-sk-heritage_xedition .zm-head:not(.zm-head-tabs)::after{content:"";position:absolute;left:0;bottom:-1px;width:46px;height:3px;background:var(--zm-brand);}'
			. '.zm-sk-heritage_xedition .zm-head-tabs{margin-bottom:14px;}'
			. '.zm-sk-heritage_xedition .zm-tab{border-bottom-width:3px;}'
			// heritage_xedition 은 직사각 테마: 썸네일·품절 오버레이도 각지게
			. '.zm-sk-heritage_xedition .zm-card-thumb,.zm-sk-heritage_xedition .zm-li-thumb,.zm-sk-heritage_xedition .zm-feature-thumb,.zm-sk-heritage_xedition .zm-soldout{border-radius:0;}'
			. '.zm-price{display:flex;align-items:baseline;flex-wrap:wrap;gap:6px;}'
			. '.zm-price s{font-size:13px;font-weight:500;color:#9aa1ab;}'
			. '.zm-price strong{font-size:16px;font-weight:800;color:var(--zm-ink);}'
			. '.zm-soldout{position:absolute;left:0;right:0;top:0;aspect-ratio:1/1;border-radius:10px;display:grid;place-items:center;background:rgba(255,255,255,.55);color:var(--zm-sub);font-size:15px;font-weight:800;}'
			. '.zm-carousel{overflow-x:auto;overflow-y:hidden;scrollbar-width:thin;padding-bottom:6px;flex:1;}'
			. '.zm-carousel-track{display:flex;gap:16px;}'
			. '.zm-carousel-track .zm-card{flex:0 0 178px;}'
			. '.zm-carousel.is-auto{overflow:hidden;}'
			. '.zm-carousel.is-auto .zm-carousel-track{width:max-content;animation:zmMarquee linear infinite;}'
			. '.zm-carousel.is-auto.sp-slow .zm-carousel-track{animation-duration:60s;}'
			. '.zm-carousel.is-auto.sp-normal .zm-carousel-track{animation-duration:40s;}'
			. '.zm-carousel.is-auto.sp-fast .zm-carousel-track{animation-duration:22s;}'
			. '.zm-carousel.is-auto:hover .zm-carousel-track,.zm-carousel.is-auto:focus-within .zm-carousel-track{animation-play-state:paused;}'
			. '@keyframes zmMarquee{from{transform:translateX(0);}to{transform:translateX(-50%);}}'
			. '@media (prefers-reduced-motion: reduce){.zm-carousel.is-auto{overflow-x:auto;}.zm-carousel.is-auto .zm-carousel-track{animation:none;width:auto;}}'
			. '.zm-list{list-style:none;margin:0;padding:0;flex:1;}'
			. '.zm-list li+li{border-top:1px solid var(--zm-divider);}'
			. '.zm-list a{display:flex;align-items:center;gap:12px;padding:9px 2px;text-decoration:none;color:var(--zm-ink);}'
			. '.zm-li-thumb{flex:0 0 58px;height:58px;border-radius:8px;background:var(--zm-fill) center/cover no-repeat;display:flex;align-items:center;justify-content:center;}'
			. '.zm-li-thumb.is-none svg{width:20px;height:20px;fill:var(--zm-icon);}'
			. '.zm-li-body{flex:1;min-width:0;display:flex;flex-direction:column;gap:4px;}'
			. '.zm-li-body strong{font-size:13.5px;font-weight:600;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}'
			. '.zm-list a:hover strong{color:var(--zm-brand);}'
			. '.zm-li-body .zm-price strong{font-size:13.5px;font-weight:800;}'
			. '.zm-feature{display:flex;flex-direction:column;flex:1;}'
			. '.zm-feature-item{position:relative;display:flex;flex-direction:column;gap:10px;flex:1;text-decoration:none;color:var(--zm-ink);}'
			. '.zm-feature-thumb{flex:1;min-height:220px;border-radius:12px;background:var(--zm-fill) center/cover no-repeat;display:flex;align-items:center;justify-content:center;}'
			. '.zm-feature-thumb.is-none svg{width:44px;height:44px;fill:var(--zm-icon);}'
			. '.zm-feature-body{display:flex;flex-direction:column;gap:6px;}'
			. '.zm-feature-body strong{font-size:16px;font-weight:800;line-height:1.35;}'
			. '.zm-feature-item:hover strong{color:var(--zm-brand);}'
			. '.zm-feature-sum{font-size:12.5px;color:var(--zm-sub);line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}'
			. '.zm-feature .zm-soldout{aspect-ratio:auto;bottom:78px;border-radius:12px;}'
			. '.zm-head-tabs{justify-content:flex-start;gap:2px;border-bottom:1px solid var(--zm-line);padding-bottom:0;margin-bottom:12px;flex-wrap:wrap;}'
			. '.zm-tab{border:0;background:none;padding:9px 13px;font-size:14.5px;font-weight:700;color:var(--zm-sub);cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;font-family:inherit;}'
			. '.zm-tab.is-on{color:var(--zm-brand);border-bottom-color:var(--zm-brand);}'
			. '.zm-pane{display:none;flex-direction:column;flex:1;}'
			. '.zm-pane.is-on{display:flex;}'
			. '.zm-more-inline{margin-top:10px;align-self:flex-end;font-size:12.5px;font-weight:600;color:var(--zm-sub);text-decoration:none;}'
			. '.zm-more-inline:hover{color:var(--zm-brand);}'
			. '@container (max-width: 480px){.zm-grid{grid-template-columns:repeat(2,minmax(0,1fr)) !important;}.zm-feature-thumb{min-height:170px;}}'
			. '@container (max-width: 300px){.zm-grid{grid-template-columns:1fr !important;}.zm-list a{gap:9px;}}'
			. '@media (max-width: 960px){.zm-row{grid-template-columns:1fr 1fr !important;}}'
			. '@media (max-width: 640px){.zm-row{grid-template-columns:1fr !important;}}'
			// 밝기는 레이아웃(data-theme)과 코어(color_scheme)가 정한다. 기기 설정은 코어가 읽어 옮겨 준다
			. self::darkRules(':root[data-theme="dark"] .zm,body.color_scheme_dark .zm')
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
		return $selector . '{--zm-ink:#e8ebf0;--zm-sub:#9aa3b2;--zm-line:#2a3040;'
			. '--zm-brand-soft:var(--hr-brand-soft,rgba(38,119,227,.18));'
			. '--zm-card:#161b26;--zm-divider:#232936;--zm-fill:#232936;--zm-dim:#7b8494;--zm-icon:#4a5364;}'
			. $selector . ' .zm-soldout{background:rgba(0,0,0,.55);}';
	}
}
