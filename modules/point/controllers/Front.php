<?php

namespace Zittme\Modules\Point\Controllers;

use Context;
use Zittme\Framework\Exceptions\InvalidRequest;
use Zittme\Framework\Exceptions\MustLogin;
use Zittme\Modules\Point\Models\Config;
use Zittme\Modules\Point\Models\Ledger;

/**
 * Member-facing point pages.
 */
class Front extends \Point
{
	public const LIST_COUNT = 20;

	public function init()
	{
	}

	/**
	 * My points: balance, level, expiring soon and history.
	 */
	public function dispPointMyPoint()
	{
		$config = Config::get();
		if (!Config::isMyPageEnabled($config))
		{
			throw new InvalidRequest;
		}
		$logged_info = Context::get('logged_info');
		if (!Context::get('is_logged') || !is_object($logged_info))
		{
			throw new MustLogin;
		}

		$oMemberView = \MemberView::getInstance();
		if (!$oMemberView->checkMidAndRedirect())
		{
			$this->setRedirectUrl($oMemberView->getRedirectUrl());
			return;
		}

		$member_srl = intval($logged_info->member_srl);
		$balance = (int)\PointModel::getPoint($member_srl);
		$level = \PointModel::getLevel($balance, $config->level_step ?? []);
		$max_level = intval($config->max_level ?? 30);
		$next_point = null;
		if ($level < $max_level && isset($config->level_step[$level + 1]))
		{
			$next_point = intval($config->level_step[$level + 1]);
		}
		$current_floor = intval($config->level_step[$level] ?? 0);

		$filter = [];
		$direction = (string)Context::get('direction');
		if (in_array($direction, ['plus', 'minus'], true))
		{
			$filter['direction'] = $direction;
		}
		$page = max(1, min(100000, intval(Context::get('page'))));
		$ready = Config::isLedgerReady($config);
		$history = $ready ? Ledger::getMemberHistory($member_srl, $filter, $page, self::LIST_COUNT) : (object)['items' => [], 'page' => 1, 'has_next' => false];

		$type_labels = Admin::langList('point.ledger_type_list');
		$rows = [];
		foreach ($history->items as $row)
		{
			if ($row->type === 'archive')
			{
				$label = lang('point.my_archived');
			}
			elseif ($row->type === 'migrate' || $row->type === 'reconcile')
			{
				$label = lang('point.my_opening_balance');
			}
			else
			{
				$reason = Admin::reasonLabel((string)$row->reason_code);
				$label = ($reason !== '' && $reason !== 'legacy') ? $reason : (is_array($type_labels) ? ($type_labels[$row->type] ?? $row->type) : $row->type);
			}
			$rows[] = (object)[
				'date' => Admin::formatDate($row->regdate, 'Y-m-d H:i'),
				'label' => $label,
				'memo' => ($row->type === 'admin_add' || $row->type === 'admin_take') ? (string)$row->memo : '',
				'amount' => intval($row->amount),
				'amount_label' => ($row->amount > 0 ? '+' : '') . number_format($row->amount),
				'balance_label' => number_format($row->balance_after),
				'expires' => $row->expires_at ? Admin::formatDate($row->expires_at, 'Y-m-d') : '',
				'capped' => $row->status === 'capped',
			];
		}

		$expiring = 0;
		if ($ready && Config::isExpireEnabled($config))
		{
			$expiring = Ledger::getExpiringSoon($member_srl, date('YmdHis', strtotime('+30 days')));
		}

		$level_icon = sprintf('%smodules/point/icons/%s/%d.%s', \RX_BASEURL, $config->level_icon ?? 'default', $level, $config->level_icon_type ?? 'gif');

		Context::set('my_point', (object)[
			'point_name' => $config->point_name ?: 'point',
			'balance' => $balance,
			'balance_label' => number_format($balance),
			'level' => $level,
			'level_icon' => $level_icon,
			'next_point' => $next_point,
			'next_gap_label' => $next_point !== null ? number_format(max(0, $next_point - $balance)) : '',
			'progress' => ($next_point !== null && $next_point > $current_floor) ? max(0, min(100, round(($balance - $current_floor) * 100 / ($next_point - $current_floor)))) : 100,
			'expiring_label' => number_format($expiring),
			'expire_enabled' => Config::isExpireEnabled($config),
			'expire_months' => intval($config->expire_months ?? 0),
			'limit_enabled' => Config::isLimitEnabled($config),
			'limit_daily' => intval($config->limit_daily ?? 0),
			'limit_monthly' => intval($config->limit_monthly ?? 0),
			'rows' => $rows,
			'page' => $history->page,
			'has_next' => $history->has_next,
			'direction' => $filter['direction'] ?? '',
		]);

		Context::loadFile([$this->module_path . 'views/front/mypoint.css', '', '', 100]);
		$this->setTemplatePath($this->module_path . 'views/front/');
		$this->setTemplateFile('mypoint');
	}
}
