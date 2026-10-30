<?php

namespace Zittme\Modules\Point\Models;

use Context;
use Zittme\Framework\DB;
use Zittme\Modules\Point\Controllers\Admin;

/**
 * Read helpers shared by the point screens and the member detail panel.
 */
class AdminData
{
	/**
	 * Short member cards for lists: name, groups and level.
	 *
	 * @return array<int, array>
	 */
	public static function memberCards(array $member_srls, bool $with_groups = true): array
	{
		$member_srls = array_values(array_unique(array_filter(array_map('intval', $member_srls))));
		if (!$member_srls)
		{
			return [];
		}
		$oDB = DB::getInstance();
		$marks = implode(',', array_fill(0, count($member_srls), '?'));
		$rows = $oDB->query('SELECT member_srl, user_id, user_name, nick_name FROM member WHERE member_srl IN (' . $marks . ')', $member_srls)->fetchAll();
		$points = [];
		foreach ($oDB->query('SELECT member_srl, point FROM point WHERE member_srl IN (' . $marks . ')', $member_srls)->fetchAll() as $row)
		{
			$points[intval($row->member_srl)] = intval($row->point);
		}
		$groups = [];
		if ($with_groups)
		{
			$titles = self::groupTitles();
			foreach ($oDB->query('SELECT member_srl, group_srl FROM member_group_member WHERE member_srl IN (' . $marks . ')', $member_srls)->fetchAll() as $row)
			{
				if (isset($titles[intval($row->group_srl)]))
				{
					$groups[intval($row->member_srl)][] = $titles[intval($row->group_srl)];
				}
			}
		}
		$config = Config::get();
		$cards = [];
		foreach ($rows as $row)
		{
			$srl = intval($row->member_srl);
			$point = $points[$srl] ?? 0;
			$level = \PointModel::getLevel($point, $config->level_step ?? []);
			$nick = (string)$row->nick_name;
			$cards[$srl] = [
				'srl' => $srl,
				'nick' => $nick,
				'name' => (string)$row->user_name,
				'uid' => (string)$row->user_id,
				'initial' => mb_substr($nick !== '' ? $nick : (string)$row->user_id, 0, 1),
				'ava' => $srl % 8,
				'point' => $point,
				'level' => $level,
				'icon' => self::levelIcon($level, $config),
				'groups' => $groups[$srl] ?? [],
			];
		}
		return $cards;
	}

	/**
	 * Group titles by number, with user language codes resolved.
	 *
	 * @return array<int, string>
	 */
	public static function groupTitles(): array
	{
		static $titles = null;
		if ($titles === null)
		{
			$titles = [];
			foreach (\MemberModel::getGroups() ?: [] as $group)
			{
				$titles[intval($group->group_srl)] = Context::replaceUserLang((string)$group->title);
			}
		}
		return $titles;
	}

	public static function levelIcon(int $level, ?object $config = null): string
	{
		$config = $config ?: Config::get();
		if ($level < 0)
		{
			$level = 0;
		}
		return sprintf('%smodules/point/icons/%s/%d.%s', \RX_BASEURL, basename((string)($config->level_icon ?? 'default')) ?: 'default', $level, $config->level_icon_type ?? 'gif');
	}

	/**
	 * Point data for one member: balance, level and the last few history lines.
	 *
	 * Used by the point screen panel and by the member detail panel.
	 */
	public static function memberPoint(int $member_srl, int $recent = 5): ?array
	{
		$cards = self::memberCards([$member_srl]);
		if (!isset($cards[$member_srl]))
		{
			return null;
		}
		$config = Config::get();
		$balance = (int)\PointModel::getPoint($member_srl, true);
		$level = \PointModel::getLevel($balance, $config->level_step ?? []);
		$ready = Config::isLedgerReady($config);
		$rows = [];
		if ($ready && $recent > 0)
		{
			$page = Ledger::page(['member_srl' => $member_srl], '', $recent);
			$rows = self::decorateRows($page->items, false);
		}
		$card = $cards[$member_srl];
		$card['point'] = $balance;
		$card['level'] = $level;
		$card['icon'] = self::levelIcon($level, $config);
		return [
			'member' => $card,
			'balance' => $balance,
			'level' => $level,
			'ledger_ready' => $ready,
			'recent' => $rows,
		];
	}

	/**
	 * Turn ledger rows into what the screen shows.
	 */
	public static function decorateRows(array $rows, bool $with_members = true): array
	{
		if (!$rows)
		{
			return [];
		}
		$member_srls = [];
		$admin_srls = [];
		$document_srls = [];
		foreach ($rows as $row)
		{
			$member_srls[] = $row->member_srl;
			if ($row->actor_srl)
			{
				$member_srls[] = $row->actor_srl;
			}
			if ($row->type === 'admin_add' && $row->reason_code !== 'admin_revert')
			{
				$admin_srls[] = $row->ledger_srl;
			}
			if ($row->ref_type === 'document' && $row->ref_srl)
			{
				$document_srls[] = intval($row->ref_srl);
			}
		}
		$cards = $with_members ? self::memberCards($member_srls, false) : [];
		$reverted = Ledger::findReverts($admin_srls);
		$places = self::documentPlaces($document_srls);
		$this_year = date('Y');

		$result = [];
		foreach ($rows as $row)
		{
			$kind = Ledger::kindOf($row);
			$card = $cards[intval($row->member_srl)] ?? null;
			$actor = $row->actor_srl ? ($cards[intval($row->actor_srl)] ?? null) : null;
			$regdate = (string)$row->regdate;
			$time = ztime($regdate);
			$result[] = [
				'srl' => intval($row->ledger_srl),
				'at' => date(substr($regdate, 0, 4) === $this_year ? 'm.d H:i' : 'Y.m.d H:i', $time),
				'regdate' => $regdate,
				'member' => $card ? ['srl' => $card['srl'], 'nick' => $card['nick'], 'initial' => $card['initial'], 'ava' => $card['ava']] : ['srl' => intval($row->member_srl), 'nick' => '', 'initial' => '', 'ava' => intval($row->member_srl) % 8],
				'kind' => $kind,
				'why' => self::why($row),
				'where' => ($row->ref_type === 'document' && isset($places[intval($row->ref_srl)])) ? $places[intval($row->ref_srl)] : '',
				'amount' => intval($row->amount),
				'balance' => intval($row->balance_after),
				'by' => intval($row->actor_srl) > 0 ? 'admin' : (self::isMemberAction($row) ? 'member' : 'auto'),
				'actor' => $actor ? $actor['nick'] : '',
				'can_revert' => $row->type === 'admin_add' && $row->reason_code !== 'admin_revert' && intval($row->amount) > 0 && !isset($reverted[intval($row->ledger_srl)]),
				'reverted' => isset($reverted[intval($row->ledger_srl)]),
				'archived' => $row->type === 'archive',
			];
		}
		return $result;
	}

	/**
	 * The "why" text of one row.
	 */
	public static function why(object $row): string
	{
		$reason = (string)$row->reason_code;
		$memo = trim((string)$row->memo);
		if ($reason === 'admin_revert')
		{
			return self::text('pa_why_revert') . ($memo !== '' ? ' · ' . $memo : '');
		}
		if ($row->type === 'admin_add' || $row->type === 'admin_take')
		{
			return self::text($row->type === 'admin_add' ? 'pa_why_admin_add' : 'pa_why_admin_take') . ($memo !== '' ? ' · ' . $memo : '');
		}
		if ($row->type === 'migrate')
		{
			return self::text('pa_why_migrate');
		}
		if ($row->type === 'reconcile')
		{
			return self::text($reason === 'recalc' ? 'pa_why_recalc' : 'pa_why_reconcile');
		}
		if ($row->type === 'archive')
		{
			return sprintf(self::text('pa_why_archive'), number_format(intval($memo)));
		}
		$label = self::text('pa_reason_' . $reason, '');
		if ($label === '' && substr($reason, -7) === '_cancel')
		{
			$base = self::text('pa_reason_' . substr($reason, 0, -7), '');
			if ($base !== '')
			{
				$label = sprintf(self::text('pa_why_cancel'), $base);
			}
		}
		if ($label === '')
		{
			$label = Admin::reasonLabel($reason);
			if (preg_match('/^(.+) · [a-z0-9_:]+$/', $label, $m))
			{
				$label = $m[1];
			}
		}
		if (ctype_digit($memo))
		{
			$memo = '';
		}
		if ($reason === 'legacy' && $memo !== '')
		{
			return $memo;
		}
		return $label . ($memo !== '' && $reason !== 'legacy' ? ' · ' . $memo : '');
	}

	protected static function isMemberAction(object $row): bool
	{
		if ($row->type !== 'spend' && $row->type !== 'restore')
		{
			return false;
		}
		$reason = (string)$row->reason_code;
		$core = ['download_file', 'read_document', 'delete_document', 'delete_comment', 'delete_file', 'first_document_revert', 'legacy'];
		return !in_array($reason, $core, true) && substr($reason, -7) !== '_cancel' && strpos($reason, 'vote') === false && strpos($reason, 'blame') === false;
	}

	/**
	 * Board name and site of documents.
	 *
	 * @return array<int, string>
	 */
	protected static function documentPlaces(array $document_srls): array
	{
		$document_srls = array_values(array_unique(array_filter($document_srls)));
		if (!$document_srls)
		{
			return [];
		}
		$oDB = DB::getInstance();
		$rows = $oDB->query('SELECT document_srl, module_srl FROM documents WHERE document_srl IN (' . implode(',', array_fill(0, count($document_srls), '?')) . ')', $document_srls)->fetchAll();
		$modules = self::modulePlaces(array_column($rows, 'module_srl'));
		$result = [];
		foreach ($rows as $row)
		{
			if (isset($modules[intval($row->module_srl)]))
			{
				$result[intval($row->document_srl)] = $modules[intval($row->module_srl)];
			}
		}
		return $result;
	}

	/**
	 * "Board · domain" for module numbers.
	 *
	 * @return array<int, string>
	 */
	public static function modulePlaces(array $module_srls): array
	{
		$module_srls = array_values(array_unique(array_filter(array_map('intval', $module_srls))));
		if (!$module_srls)
		{
			return [];
		}
		$oDB = DB::getInstance();
		$rows = $oDB->query('SELECT module_srl, browser_title, domain_srl FROM modules WHERE module_srl IN (' . implode(',', array_fill(0, count($module_srls), '?')) . ')', $module_srls)->fetchAll();
		$domains = self::domains();
		$result = [];
		foreach ($rows as $row)
		{
			$title = Context::replaceUserLang((string)$row->browser_title);
			$domain = $domains[intval($row->domain_srl)] ?? '';
			$result[intval($row->module_srl)] = $title . ($domain !== '' ? ' · ' . $domain : '');
		}
		return $result;
	}

	/**
	 * Domain names by number.
	 *
	 * @return array<int, string>
	 */
	public static function domains(): array
	{
		static $domains = null;
		if ($domains === null)
		{
			$domains = [];
			if (DB::getInstance()->isTableExists('domains'))
			{
				foreach (DB::getInstance()->query('SELECT domain_srl, domain, is_default_domain FROM domains')->fetchAll() as $row)
				{
					$domains[intval($row->domain_srl)] = (string)$row->domain;
					if ($row->is_default_domain === 'Y')
					{
						$domains[-1] = (string)$row->domain;
					}
				}
			}
		}
		return $domains;
	}

	/**
	 * A point module language string, or the fallback when it is missing.
	 */
	public static function text(string $key, ?string $fallback = null): string
	{
		$value = lang('point.' . $key);
		if (!is_string($value) || $value === $key || $value === 'point.' . $key)
		{
			return $fallback ?? $key;
		}
		return $value;
	}
}
