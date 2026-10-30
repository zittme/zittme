@php
$mp = $my_point;
$mp_filters = ['' => $lang->total, 'plus' => $lang->point_direction_plus, 'minus' => $lang->point_direction_minus];
$mp_prev = $mp->page > 1 ? getUrl('', 'act', 'dispPointMyPoint', 'direction', $mp->direction, 'page', $mp->page - 1) : '';
$mp_next = $mp->has_next ? getUrl('', 'act', 'dispPointMyPoint', 'direction', $mp->direction, 'page', $mp->page + 1) : '';
$mp_next_text = $mp->next_point !== null ? sprintf($lang->my_point_next_level, $mp->level + 1, $mp->next_gap_label, $mp->point_name) : $lang->my_point_max_level;
@endphp
<section class="ptmy" aria-labelledby="ptmy-title">
	<header class="ptmy-head">
		<h1 id="ptmy-title">{{ $lang->my_point_title }}</h1>
		<p>{{ $lang->my_point_desc }}</p>
	</header>

	<div class="ptmy-summary">
		<div class="ptmy-balance">
			<span>{{ $lang->point_current_balance }}</span>
			<strong>{{ $mp->balance_label }}<small>{{ $mp->point_name }}</small></strong>
		</div>
		<div class="ptmy-level">
			<div class="ptmy-level-top">
				<img src="{{ $mp->level_icon }}" alt="" width="20" height="20" />
				<b>{{ sprintf($lang->my_point_level, $mp->level) }}</b>
			</div>
			<div class="ptmy-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $mp->progress }}" aria-label="{{ $lang->my_point_progress }}"><i style="width:{{ $mp->progress }}%"></i></div>
			<p>{{ $mp_next_text }}</p>
		</div>
	</div>

	@if ($mp->expire_enabled || $mp->limit_enabled)
	<ul class="ptmy-notes">
		@if ($mp->expire_enabled)
		<li>{{ sprintf($lang->my_point_expire_rule, $mp->expire_months) }} <b>{{ sprintf($lang->my_point_expiring, $mp->expiring_label, $mp->point_name) }}</b></li>
		@endif
		@if ($mp->limit_enabled && $mp->limit_daily)
		<li>{{ sprintf($lang->my_point_limit_daily, number_format($mp->limit_daily), $mp->point_name) }}</li>
		@endif
		@if ($mp->limit_enabled && $mp->limit_monthly)
		<li>{{ sprintf($lang->my_point_limit_monthly, number_format($mp->limit_monthly), $mp->point_name) }}</li>
		@endif
	</ul>
	@endif

	<div class="ptmy-list-head">
		<h2>{{ $lang->my_point_history }}</h2>
		<nav class="ptmy-filter" aria-label="{{ $lang->point_filter_direction }}">
			@foreach ($mp_filters as $mp_key => $mp_label)
			<a href="{{ getUrl('', 'act', 'dispPointMyPoint', 'direction', $mp_key) }}" @class(['is-active' => $mp->direction === $mp_key])>{{ $mp_label }}</a>
			@endforeach
		</nav>
	</div>

	@if (count($mp->rows))
	<ol class="ptmy-list">
		@foreach ($mp->rows as $mp_row)
		<li>
			<div class="ptmy-item-main">
				<b>{{ $mp_row->label }}</b>
				@if ($mp_row->memo)
				<span>{{ $mp_row->memo }}</span>
				@endif
				<time>{{ $mp_row->date }}</time>
				@if ($mp_row->expires)
				<time class="ptmy-exp">{{ $lang->point_expires_on }} {{ $mp_row->expires }}</time>
				@endif
			</div>
			<div class="ptmy-item-num">
				@if ($mp_row->capped && !$mp_row->amount)
				<em class="ptmy-capped">{{ $lang->my_point_capped }}</em>
				@else
				<strong @class(['is-plus' => $mp_row->amount > 0, 'is-minus' => $mp_row->amount < 0])>{{ $mp_row->amount_label }}</strong>
				@endif
				<span>{{ $mp_row->balance_label }}</span>
			</div>
		</li>
		@endforeach
	</ol>
	@else
	<p class="ptmy-empty">{{ $lang->my_point_empty }}</p>
	@endif

	@if ($mp_prev || $mp_next)
	<nav class="ptmy-pager" aria-label="pagination">
		@if ($mp_prev)
		<a href="{{ $mp_prev }}">{{ $lang->cmd_prev }}</a>
		@endif
		<span>{{ $mp->page }}</span>
		@if ($mp_next)
		<a href="{{ $mp_next }}">{{ $lang->cmd_next }}</a>
		@endif
	</nav>
	@endif
</section>
