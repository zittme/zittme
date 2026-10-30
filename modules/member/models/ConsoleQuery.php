<?php

namespace Zittme\Modules\Member\Models;

use Context;
use MemberModel;
use ModuleModel;
use Zittme\Framework\Cache;
use Zittme\Framework\DB;
use Zittme\Framework\Storage;

/**
 * Reads for the member administration screen.
 *
 * Member lists use "fetch one more" cursor paging and never count rows.
 * The numbers next to the quick views are computed separately and kept
 * for a short time, so opening the screen does not scan the member table.
 */
class ConsoleQuery
{
	public const SORTS = ['join', 'login', 'point', 'nick'];
	public const QUICK = ['all', 'today', 'unauthed', 'limited', 'denied', 'admin', 'dormant'];
	public const STATES = ['ok', 'unauthed', 'limited', 'denied', 'approved'];
	public const SEARCH_FIELDS = ['user_id', 'email_address', 'phone_number', 'user_name', 'nick_name'];
	public const LIST_COUNTS = [30, 50, 100];
	public const MAX_SELECT = 1000;

	/**
	 * Above this many members, numbers that need a full scan are skipped
	 * and the others are kept longer.
	 */
	public const LARGE = 200000;

	protected static $_tables = [];

	/**
	 * Clean up filter values coming from the request.
	 */
	public static function normalizeFilter($in): array
	{
		$in = is_object($in) ? get_object_vars($in) : (is_array($in) ? $in : []);
		$f = [];

		$q = trim(utf8_clean(strval($in['q'] ?? '')));
		if ($q !== '')
		{
			$f['q'] = mb_substr($q, 0, 80);
			$field = strval($in['q_field'] ?? '');
			if (in_array($field, self::SEARCH_FIELDS, true))
			{
				$f['q_field'] = $field;
			}
		}

		$quick = strval($in['quick'] ?? '');
		$f['quick'] = in_array($quick, self::QUICK, true) ? $quick : 'all';

		$group = intval($in['group'] ?? 0);
		if ($group > 0)
		{
			$f['group'] = $group;
		}

		$state = strval($in['state'] ?? '');
		if (in_array($state, self::STATES, true))
		{
			$f['state'] = $state;
		}

		$join = strval($in['join'] ?? '');
		if (in_array($join, ['1', '7', '30'], true))
		{
			$f['join'] = $join;
		}
		$last = strval($in['last'] ?? '');
		if (in_array($last, ['7', '30', '1y'], true))
		{
			$f['last'] = $last;
		}

		foreach (['join_on', 'join_after', 'join_before', 'last_on', 'last_after', 'last_before', 'birthday'] as $key)
		{
			$value = preg_replace('/[^0-9]/', '', strval($in[$key] ?? ''));
			if ($value !== '' && strlen($value) <= 14)
			{
				$f[$key] = $value;
			}
		}
		foreach (['ip', 'last_ip'] as $key)
		{
			$value = preg_replace('/[^0-9a-f.:]/i', '', strval($in[$key] ?? ''));
			if ($value !== '')
			{
				$f[$key] = strtolower($value);
			}
		}
		foreach (['memo', 'extra'] as $key)
		{
			$value = trim(utf8_clean(strval($in[$key] ?? '')));
			if ($value !== '')
			{
				$f[$key] = mb_substr($value, 0, 80);
			}
		}
		foreach (['social', 'mailing'] as $key)
		{
			$value = strval($in[$key] ?? '');
			if ($value === 'Y' || $value === 'N')
			{
				$f[$key] = $value;
			}
		}

		$sort = strval($in['sort'] ?? '');
		$f['sort'] = in_array($sort, self::SORTS, true) ? $sort : 'join';

		$count = intval($in['list_count'] ?? 0);
		$f['list_count'] = in_array($count, self::LIST_COUNTS, true) ? $count : 30;

		return $f;
	}

	/**
	 * Translate the parameters of the old member list address into filter values.
	 *
	 * Unknown values are dropped so that the screen always opens.
	 */
	public static function fromLegacyParams($vars): array
	{
		$vars = is_object($vars) ? get_object_vars($vars) : (is_array($vars) ? $vars : []);
		$f = [];
		foreach (['q', 'q_field', 'quick', 'group', 'state', 'join', 'last', 'join_on', 'join_after', 'join_before', 'last_on', 'last_after', 'last_before', 'ip', 'last_ip', 'birthday', 'memo', 'extra', 'social', 'mailing', 'sort'] as $key)
		{
			if (isset($vars[$key]) && is_scalar($vars[$key]))
			{
				$f[$key] = $vars[$key];
			}
		}

		switch (strval($vars['filter_type'] ?? ''))
		{
			case 'admin':
			case 'super_admin':
				$f['quick'] = 'admin';
				break;
			case 'approved':
			case 'enable':
				$f['state'] = 'approved';
				break;
			case 'denied':
			case 'disable':
				$f['quick'] = 'denied';
				break;
			case 'unauthed':
				$f['quick'] = 'unauthed';
				break;
		}
		if (($vars['is_admin'] ?? '') === 'Y')
		{
			$f['quick'] = 'admin';
		}
		if (($vars['is_denied'] ?? '') === 'Y')
		{
			$f['quick'] = 'denied';
		}
		if (intval($vars['selected_group_srl'] ?? 0) > 0)
		{
			$f['group'] = intval($vars['selected_group_srl']);
		}

		$target = strval($vars['search_target'] ?? '');
		$keyword = trim(strval($vars['search_keyword'] ?? ''));
		if ($target !== '' && $keyword !== '')
		{
			$map = [
				'regdate' => 'join_on', 'regdate_more' => 'join_after', 'regdate_less' => 'join_before',
				'last_login' => 'last_on', 'last_login_more' => 'last_after', 'last_login_less' => 'last_before',
				'ipaddress' => 'ip', 'last_login_ipaddress' => 'last_ip',
				'birthday' => 'birthday', 'extra_vars' => 'extra', 'description' => 'memo',
			];
			if (in_array($target, self::SEARCH_FIELDS, true))
			{
				$f['q'] = $keyword;
				$f['q_field'] = $target;
			}
			elseif (isset($map[$target]))
			{
				$f[$map[$target]] = $keyword;
			}
		}

		$sort_index = strval($vars['sort_index'] ?? '');
		$sort_order = strval($vars['sort_order'] ?? '');
		if ($sort_index === 'last_login')
		{
			$f['sort'] = 'login';
		}
		elseif ($sort_index === 'nick_name' && $sort_order !== 'desc')
		{
			$f['sort'] = 'nick';
		}

		$count = intval($vars['list_count'] ?? 0);
		if (in_array($count, self::LIST_COUNTS, true))
		{
			$f['list_count'] = $count;
		}

		$f = self::normalizeFilter($f);
		$page = intval($vars['page'] ?? 0);
		if ($page > 1)
		{
			$f['page'] = min($page, 100000);
		}
		return $f;
	}

	/**
	 * One page of members.
	 *
	 * @return object {rows, next_cursor, has_more}
	 */
	public static function getList(array $f, string $cursor = '', int $offset = 0): object
	{
		$count = $f['list_count'] ?? 30;
		$sort = $f['sort'] ?? 'join';
		$use_point = $sort === 'point' && self::tableExists('point');
		if ($sort === 'point' && !$use_point)
		{
			$sort = 'join';
		}

		[$where, $params] = self::buildWhere($f);

		$after = self::decodeCursor($cursor);
		if ($after)
		{
			[$cursor_where, $cursor_params] = self::cursorCondition($sort, $after);
			$where[] = $cursor_where;
			$params = array_merge($params, $cursor_params);
		}

		$columns = 'm.member_srl, m.user_id, m.email_address, m.user_name, m.nick_name, m.status, m.denied, m.limit_date, m.is_admin, m.regdate, m.last_login';
		$from = 'member AS m';
		if ($use_point)
		{
			$columns .= ', COALESCE(p.point, 0) AS sort_point';
			$from .= ' LEFT JOIN point AS p ON p.member_srl = m.member_srl';
		}

		switch ($sort)
		{
			case 'login': $order = 'm.last_login DESC, m.member_srl DESC'; break;
			case 'point': $order = 'sort_point DESC, m.member_srl DESC'; break;
			case 'nick': $order = 'm.nick_name ASC, m.member_srl ASC'; break;
			default: $order = 'm.member_srl DESC';
		}

		$sql = 'SELECT ' . $columns . ' FROM ' . $from . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY ' . $order . ' LIMIT ' . ($after ? 0 : max(0, $offset)) . ', ' . ($count + 1);
		$rows = DB::getInstance()->query($sql, $params)->fetchAll();

		$result = new \stdClass;
		$result->has_more = count($rows) > $count;
		$rows = array_slice($rows, 0, $count);
		$result->next_cursor = '';
		if ($result->has_more && $rows)
		{
			$last = end($rows);
			$result->next_cursor = self::encodeCursor($sort, $last);
		}
		$result->rows = self::decorateRows($rows);
		return $result;
	}

	/**
	 * WHERE clauses for a filter. Every value goes through a placeholder.
	 */
	public static function buildWhere(array $f): array
	{
		$where = [];
		$params = [];
		$today = date('Ymd');

		if (isset($f['q']))
		{
			$q = $f['q'];
			$like = '%' . str_replace(' ', '%', self::escapeLike($q)) . '%';
			$fields = isset($f['q_field']) ? [$f['q_field']] : self::SEARCH_FIELDS;
			$or = [];
			foreach ($fields as $field)
			{
				if ($field === 'phone_number')
				{
					$digits = preg_replace('/[^0-9]/', '', $q);
					if ($digits !== '' && (strlen($digits) >= 3 || isset($f['q_field'])))
					{
						$or[] = 'm.phone_number LIKE ?';
						$params[] = '%' . $digits . '%';
					}
				}
				elseif ($field === 'nick_name')
				{
					$or[] = 'm.nick_name LIKE ?';
					$params[] = $like;
					$html = htmlspecialchars($q, ENT_COMPAT | ENT_HTML401, 'UTF-8', false);
					if ($html !== $q)
					{
						$or[] = 'm.nick_name LIKE ?';
						$params[] = '%' . str_replace(' ', '%', self::escapeLike($html)) . '%';
					}
				}
				else
				{
					$or[] = 'm.' . $field . ' LIKE ?';
					$params[] = $like;
				}
			}
			$where[] = $or ? '(' . implode(' OR ', $or) . ')' : '1 = 0';
		}

		switch ($f['quick'] ?? 'all')
		{
			case 'today':
				$where[] = 'm.regdate >= ?';
				$params[] = $today . '000000';
				break;
			case 'unauthed':
				$where[] = 'm.status = ?';
				$params[] = 'UNAUTHED';
				break;
			case 'limited':
				$where[] = 'm.status = ? AND m.limit_date >= ?';
				$params[] = 'APPROVED';
				$params[] = $today;
				break;
			case 'denied':
				$where[] = 'm.status = ?';
				$params[] = 'DENIED';
				break;
			case 'admin':
				$where[] = 'm.is_admin = ?';
				$params[] = 'Y';
				break;
			case 'dormant':
				$where[] = 'm.last_login < ?';
				$params[] = date('YmdHis', strtotime('-1 year'));
				break;
		}

		if (isset($f['group']))
		{
			$where[] = 'm.member_srl IN (SELECT g.member_srl FROM member_group_member AS g WHERE g.group_srl = ?)';
			$params[] = $f['group'];
		}

		switch ($f['state'] ?? '')
		{
			case 'ok':
				$where[] = "m.status = ? AND (m.limit_date IS NULL OR m.limit_date < ?)";
				$params[] = 'APPROVED';
				$params[] = $today;
				break;
			case 'approved':
				$where[] = 'm.status = ?';
				$params[] = 'APPROVED';
				break;
			case 'unauthed':
				$where[] = 'm.status = ?';
				$params[] = 'UNAUTHED';
				break;
			case 'limited':
				$where[] = 'm.status = ? AND m.limit_date >= ?';
				$params[] = 'APPROVED';
				$params[] = $today;
				break;
			case 'denied':
				$where[] = 'm.status = ?';
				$params[] = 'DENIED';
				break;
		}

		if (isset($f['join']))
		{
			$where[] = 'm.regdate >= ?';
			$params[] = date('Ymd', strtotime('-' . (intval($f['join']) - 1) . ' days')) . '000000';
		}
		if (isset($f['last']))
		{
			$where[] = $f['last'] === '1y' ? 'm.last_login < ?' : 'm.last_login >= ?';
			$params[] = $f['last'] === '1y' ? date('YmdHis', strtotime('-1 year')) : date('YmdHis', strtotime('-' . intval($f['last']) . ' days'));
		}
		foreach (['join' => 'regdate', 'last' => 'last_login'] as $prefix => $column)
		{
			if (isset($f[$prefix . '_on']))
			{
				$where[] = 'm.' . $column . ' LIKE ?';
				$params[] = self::escapeLike($f[$prefix . '_on']) . '%';
			}
			if (isset($f[$prefix . '_after']))
			{
				$where[] = 'm.' . $column . ' >= ?';
				$params[] = str_pad($f[$prefix . '_after'], 14, '0');
			}
			if (isset($f[$prefix . '_before']))
			{
				$where[] = 'm.' . $column . ' <= ?';
				$params[] = str_pad($f[$prefix . '_before'], 14, '9');
			}
		}
		if (isset($f['ip']))
		{
			$where[] = 'm.ipaddress LIKE ?';
			$params[] = self::escapeLike($f['ip']) . '%';
		}
		if (isset($f['last_ip']))
		{
			$where[] = 'm.last_login_ipaddress LIKE ?';
			$params[] = self::escapeLike($f['last_ip']) . '%';
		}
		if (isset($f['birthday']))
		{
			$where[] = 'm.birthday LIKE ?';
			$params[] = '%' . self::escapeLike($f['birthday']) . '%';
		}
		if (isset($f['memo']))
		{
			$where[] = 'm.description LIKE ?';
			$params[] = '%' . self::escapeLike($f['memo']) . '%';
		}
		if (isset($f['extra']))
		{
			$where[] = 'm.extra_vars LIKE ?';
			$params[] = '%' . self::escapeLike($f['extra']) . '%';
		}
		if (isset($f['mailing']))
		{
			$where[] = 'm.allow_mailing = ?';
			$params[] = $f['mailing'];
		}
		if (isset($f['social']) && self::tableExists('member_sns_account'))
		{
			$where[] = 'm.member_srl ' . ($f['social'] === 'Y' ? 'IN' : 'NOT IN') . ' (SELECT s.member_srl FROM member_sns_account AS s)';
		}

		return [$where, $params];
	}

	/**
	 * Condition that continues a list after the given row.
	 */
	protected static function cursorCondition(string $sort, array $after): array
	{
		[$value, $srl] = $after;
		$srl = intval($srl);
		switch ($sort)
		{
			case 'login':
				if ($value === null)
				{
					return ['(m.last_login IS NULL AND m.member_srl < ?)', [$srl]];
				}
				return ['(m.last_login < ? OR (m.last_login = ? AND m.member_srl < ?) OR m.last_login IS NULL)', [strval($value), strval($value), $srl]];
			case 'point':
				return ['(COALESCE(p.point, 0) < ? OR (COALESCE(p.point, 0) = ? AND m.member_srl < ?))', [intval($value), intval($value), $srl]];
			case 'nick':
				return ['(m.nick_name > ? OR (m.nick_name = ? AND m.member_srl > ?))', [strval($value), strval($value), $srl]];
			default:
				return ['m.member_srl < ?', [$srl]];
		}
	}

	protected static function encodeCursor(string $sort, object $row): string
	{
		switch ($sort)
		{
			case 'login': $value = $row->last_login; break;
			case 'point': $value = intval($row->sort_point ?? 0); break;
			case 'nick': $value = $row->nick_name; break;
			default: $value = null;
		}
		return rtrim(strtr(base64_encode(json_encode([$value, intval($row->member_srl)])), '+/', '-_'), '=');
	}

	protected static function decodeCursor(string $cursor): ?array
	{
		if ($cursor === '' || strlen($cursor) > 400)
		{
			return null;
		}
		$data = json_decode(base64_decode(strtr($cursor, '-_', '+/')), true);
		if (!is_array($data) || count($data) !== 2 || intval($data[1]) <= 0)
		{
			return null;
		}
		return [$data[0], intval($data[1])];
	}

	/**
	 * Add groups, points and social accounts to list rows with one query each.
	 */
	protected static function decorateRows(array $rows): array
	{
		$srls = array_map(function($row) { return intval($row->member_srl); }, $rows);
		$groups = self::getGroupsOf($srls);
		$points = self::getPointsOf($srls);
		$social = self::getSocialOf($srls);
		$photos = self::showPhotos();

		$result = [];
		foreach ($rows as $row)
		{
			$srl = intval($row->member_srl);
			$result[] = [
				'id' => $srl,
				'nick' => strval($row->nick_name),
				'name' => strval($row->user_name),
				'user_id' => strval($row->user_id),
				'email' => strval($row->email_address),
				'groups' => $groups[$srl] ?? [],
				'point' => $points[$srl] ?? 0,
				'state' => self::stateOf($row),
				'admin' => $row->is_admin === 'Y',
				'regdate' => substr(strval($row->regdate), 0, 8),
				'last_login' => substr(strval($row->last_login), 0, 12),
				'social' => $social[$srl] ?? [],
				'photo' => $photos ? self::photoOf($srl) : '',
			];
		}
		return $result;
	}

	/**
	 * Whether the administrator chose to see profile photos in the member
	 * screens (member setting member_profile_view).
	 */
	public static function showPhotos(): bool
	{
		return (MemberModel::getMemberConfig()->member_profile_view ?? 'N') === 'Y';
	}

	/**
	 * Profile photo address of a member, or an empty string.
	 */
	public static function photoOf(int $member_srl): string
	{
		$image = MemberModel::getProfileImage($member_srl);
		return (is_object($image) && !empty($image->src)) ? strval($image->src) : '';
	}

	/**
	 * Screen state of a member row: ok, unauthed, limited or denied.
	 */
	public static function stateOf(object $row): string
	{
		if (($row->status ?? '') === 'UNAUTHED')
		{
			return 'unauthed';
		}
		if (($row->status ?? 'APPROVED') !== 'APPROVED' || ($row->denied ?? 'N') === 'Y')
		{
			return 'denied';
		}
		if (!empty($row->limit_date) && substr($row->limit_date, 0, 8) >= date('Ymd'))
		{
			return 'limited';
		}
		return 'ok';
	}

	public static function getGroupsOf(array $srls): array
	{
		$srls = array_values(array_unique(array_filter(array_map('intval', $srls))));
		if (!$srls)
		{
			return [];
		}
		$order = [];
		foreach (array_values(MemberModel::getGroups()) as $i => $group)
		{
			$order[intval($group->group_srl)] = $i;
		}
		$rows = DB::getInstance()->query('SELECT member_srl, group_srl FROM member_group_member WHERE member_srl IN (' . implode(',', array_fill(0, count($srls), '?')) . ')', $srls)->fetchAll();
		$result = [];
		foreach ($rows as $row)
		{
			$srl = intval($row->member_srl);
			$group_srl = intval($row->group_srl);
			if (isset($order[$group_srl]) && !in_array($group_srl, $result[$srl] ?? [], true))
			{
				$result[$srl][] = $group_srl;
			}
		}
		foreach ($result as $srl => $list)
		{
			usort($list, function($a, $b) use($order) { return $order[$a] <=> $order[$b]; });
			$result[$srl] = $list;
		}
		return $result;
	}

	protected static function getPointsOf(array $srls): array
	{
		if (!$srls || !self::tableExists('point'))
		{
			return [];
		}
		$rows = DB::getInstance()->query('SELECT member_srl, point FROM point WHERE member_srl IN (' . implode(',', array_fill(0, count($srls), '?')) . ')', $srls)->fetchAll();
		$result = [];
		foreach ($rows as $row)
		{
			$result[intval($row->member_srl)] = intval($row->point);
		}
		return $result;
	}

	protected static function getSocialOf(array $srls): array
	{
		if (!$srls || !self::tableExists('member_sns_account'))
		{
			return [];
		}
		$rows = DB::getInstance()->query('SELECT member_srl, sns_type FROM member_sns_account WHERE member_srl IN (' . implode(',', array_fill(0, count($srls), '?')) . ')', $srls)->fetchAll();
		$result = [];
		foreach ($rows as $row)
		{
			$result[intval($row->member_srl)][] = strval($row->sns_type);
		}
		return $result;
	}

	/**
	 * Numbers for the quick views and groups, kept for a short time.
	 *
	 * On a large table nothing is counted while a screen loads. The numbers of
	 * the last count are kept until $recount is true, and a table that was never
	 * counted gives null for every number except the estimate.
	 */
	public static function getCounts(bool $refresh = false, bool $recount = false): array
	{
		$key = 'member:console_counts';
		$use_cache = Cache::getDriverName() !== 'dummy';
		$file = \RX_BASEDIR . 'files/cache/member/console_counts.php';

		if (!$recount)
		{
			$cached = $use_cache ? Cache::get($key) : Storage::readPHPData($file);
			if (is_array($cached) && isset($cached['expires']) && is_array($cached['data'] ?? null))
			{
				if (!empty($cached['data']['estimated']) || (!$refresh && $cached['expires'] > time()))
				{
					return $cached['data'];
				}
			}
		}

		$estimate = self::estimateRows('member');
		$large = $estimate >= self::LARGE;
		if ($large && !$recount)
		{
			return [
				'estimated' => true, 'all' => $estimate, 'today' => null, 'unauthed' => null, 'denied' => null,
				'admin' => null, 'limited' => null, 'dormant' => null, 'groups' => null,
			];
		}
		$today = date('Ymd');
		$db = DB::getInstance();
		$one = function(string $sql, array $params = []) use($db) {
			$rows = $db->query($sql, $params)->fetchAll();
			return intval($rows[0]->cnt ?? 0);
		};

		$data = ['estimated' => $large];
		$data['all'] = $large ? $estimate : $one('SELECT COUNT(*) AS cnt FROM member');
		$data['today'] = $one('SELECT COUNT(*) AS cnt FROM member WHERE regdate >= ?', [$today . '000000']);
		$data['unauthed'] = $one('SELECT COUNT(*) AS cnt FROM member WHERE status = ?', ['UNAUTHED']);
		$data['denied'] = $one('SELECT COUNT(*) AS cnt FROM member WHERE status = ?', ['DENIED']);
		$data['admin'] = $one('SELECT COUNT(*) AS cnt FROM member WHERE is_admin = ?', ['Y']);
		$data['limited'] = $large ? null : $one('SELECT COUNT(*) AS cnt FROM member WHERE status = ? AND limit_date >= ?', ['APPROVED', $today]);
		$data['dormant'] = $one('SELECT COUNT(*) AS cnt FROM member WHERE last_login < ?', [date('YmdHis', strtotime('-1 year'))]);

		$groups = [];
		foreach ($db->query('SELECT group_srl, COUNT(*) AS cnt FROM member_group_member GROUP BY group_srl')->fetchAll() as $row)
		{
			$groups[] = [intval($row->group_srl), intval($row->cnt)];
		}
		$data['groups'] = $groups;

		$entry = ['expires' => time() + ($large ? 86400 : 60), 'data' => $data];
		if ($use_cache)
		{
			Cache::set($key, $entry, $large ? 0 : 60);
		}
		else
		{
			Storage::writePHPData($file, $entry);
		}
		return $data;
	}

	/**
	 * Forget the kept numbers after a change that moves members between views.
	 */
	public static function forgetCounts(): void
	{
		$file = \RX_BASEDIR . 'files/cache/member/console_counts.php';
		$use_cache = Cache::getDriverName() !== 'dummy';
		$kept = $use_cache ? Cache::get('member:console_counts') : Storage::readPHPData($file);
		if (is_array($kept) && !empty($kept['data']['estimated']))
		{
			return;
		}
		if ($use_cache)
		{
			Cache::delete('member:console_counts');
		}
		if (Storage::exists($file))
		{
			Storage::delete($file);
		}
	}

	/**
	 * Row estimate from the table statistics. Cheap on any size of table.
	 */
	public static function estimateRows(string $table): int
	{
		try
		{
			$prefix = strval(config('db.master.prefix') ?: '');
			$stmt = DB::getInstance()->getHandle()->prepare('SELECT TABLE_ROWS AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
			$stmt->execute([$prefix . $table]);
			$rows = $stmt->fetchAll(\PDO::FETCH_OBJ);
			return intval($rows[0]->cnt ?? 0);
		}
		catch (\Throwable $e)
		{
			return 0;
		}
	}

	/**
	 * Everything the detail panel shows about one member.
	 */
	public static function getDetail(int $member_srl): ?array
	{
		$output = executeQuery('member.getMemberInfoByMemberSrl', ['member_srl' => $member_srl]);
		$row = $output->data;
		if (!$output->toBool() || !$row || !$row->member_srl)
		{
			return null;
		}

		$config = MemberModel::getMemberConfig();
		$extra = $row->extra_vars ? unserialize($row->extra_vars) : null;
		$extra = is_object($extra) ? $extra : new \stdClass;

		$detail = [
			'id' => intval($row->member_srl),
			'nick' => strval($row->nick_name),
			'name' => strval($row->user_name),
			'user_id' => strval($row->user_id),
			'email' => strval($row->email_address),
			'no_password' => empty($row->password),
			'photo' => self::showPhotos() ? self::photoOf(intval($row->member_srl)) : '',
			'state' => self::stateOf($row),
			'status' => strval($row->status),
			'admin' => $row->is_admin === 'Y',
			'limit_date' => !empty($row->limit_date) && substr($row->limit_date, 0, 8) >= date('Ymd') ? substr($row->limit_date, 0, 8) : '',
			'limited_reason' => strval($extra->limited_reason ?? ''),
			'refused_reason' => strval($extra->refused_reason ?? ''),
			'memo' => strval($row->description ?? ''),
			'regdate' => strval($row->regdate),
			'ipaddress' => strval($row->ipaddress),
			'last_login' => strval($row->last_login),
			'last_login_ip' => strval($row->last_login_ipaddress),
			'allow_mailing' => $row->allow_mailing === 'Y',
			'groups' => self::getGroupsOf([$member_srl])[$member_srl] ?? [],
			'fields' => self::getFields($row, $extra, $config),
			'social' => [],
			'agreements' => self::getAgreements($member_srl, $config),
			'identity' => null,
			'self' => is_object(Context::get('logged_info')) && intval(Context::get('logged_info')->member_srl) === intval($row->member_srl),
		];

		if (self::tableExists('member_sns_account'))
		{
			foreach (DB::getInstance()->query('SELECT sns_type, regdate FROM member_sns_account WHERE member_srl = ?', [$member_srl])->fetchAll() as $sns)
			{
				$detail['social'][] = ['type' => strval($sns->sns_type), 'regdate' => strval($sns->regdate)];
			}
		}
		if (self::tableExists('member_identity'))
		{
			$identity = DB::getInstance()->query('SELECT provider, regdate FROM member_identity WHERE member_srl = ?', [$member_srl])->fetchAll();
			if ($identity)
			{
				$detail['identity'] = ['regdate' => strval($identity[0]->regdate)];
			}
		}

		$detail['activity'] = self::getActivity($member_srl);
		$detail['logins'] = self::getLogins($member_srl);
		$detail['point'] = self::getPointSummary($member_srl);

		$trigger = new \stdClass;
		$trigger->member_srl = $member_srl;
		$trigger->items = [];
		\ModuleHandler::triggerCall('member.getAdminMemberSummary', 'after', $trigger);
		$items = [];
		foreach (is_array($trigger->items) ? $trigger->items : [] as $item)
		{
			$item = (array)$item;
			if (isset($item['label']) && isset($item['value']))
			{
				$items[] = ['label' => strval($item['label']), 'value' => strval($item['value']), 'url' => strval($item['url'] ?? '')];
			}
		}
		$detail['summary'] = $items;

		return $detail;
	}

	/**
	 * Values of the signup form items, in the order of the signup form.
	 */
	protected static function getFields(object $row, object $extra, object $config): array
	{
		$skip = ['password', 'find_account_question', 'profile_image', 'image_name', 'image_mark', 'signature'];
		$ext_forms = [];
		foreach (MemberModel::getJoinFormList() ?: [] as $form)
		{
			$ext_forms[intval($form->member_join_form_srl)] = $form;
		}

		$fields = [];
		foreach (is_array($config->signupForm ?? null) ? $config->signupForm : [] as $form)
		{
			if (empty($form->isUse) || in_array($form->name, $skip, true))
			{
				continue;
			}
			if (!empty($form->isDefaultForm))
			{
				$value = $row->{$form->name} ?? '';
				if ($form->name === 'phone_number' && $value !== '')
				{
					$value = self::formatPhone(strval($value), strval($row->phone_country ?? ''), $config);
				}
				elseif ($form->name === 'birthday' && preg_match('/^(\d{4})(\d{2})(\d{2})$/', strval($value), $m))
				{
					$value = $m[1] . '.' . $m[2] . '.' . $m[3];
				}
				$label = lang($form->name);
				if (!is_string($label) || $label === '' || $label === $form->name)
				{
					$label = strval($form->title ?? $form->name);
				}
				$fields[] = ['key' => $form->name, 'label' => html_entity_decode(strip_tags($label)), 'value' => strval($value), 'ext' => false];
			}
			else
			{
				$value = $extra->{$form->name} ?? '';
				if (is_array($value) || is_object($value))
				{
					$value = implode(', ', array_filter(array_map('strval', (array)$value), 'strlen'));
				}
				$value = strval($value);
				if (strpos($value, '|@|') !== false)
				{
					$value = implode(', ', array_filter(explode('|@|', $value), 'strlen'));
				}
				$form_info = $ext_forms[intval($form->member_join_form_srl ?? 0)] ?? null;
				if ($form_info && ($form_info->column_type ?? '') === 'date' && preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m))
				{
					$value = $m[1] . '.' . $m[2] . '.' . $m[3];
				}
				$fields[] = ['key' => $form->name, 'label' => Context::replaceUserLang(strval($form->title ?? $form->name), true), 'value' => html_entity_decode(strip_tags($value)), 'ext' => true];
			}
		}
		return $fields;
	}

	protected static function formatPhone(string $number, string $country, object $config): string
	{
		try
		{
			if (($config->phone_number_hide_country ?? 'N') !== 'Y' && $country !== '')
			{
				return \Zittme\Framework\i18n::formatPhoneNumber($number, $country);
			}
			if ($country === 'KOR' || $country === '82' || ($config->phone_number_default_country ?? '') === 'KOR')
			{
				return \Zittme\Framework\Korea::formatPhoneNumber($number);
			}
		}
		catch (\Throwable $e)
		{
		}
		return $number;
	}

	protected static function getAgreements(int $member_srl, object $config): array
	{
		$titles = [];
		foreach (is_array($config->agreements ?? null) ? $config->agreements : [] as $i => $agreement)
		{
			$titles[intval($i)] = Context::replaceUserLang(strval($agreement->title ?? ''), true);
		}
		if (!$titles || !self::tableExists('member_agreed'))
		{
			return [];
		}
		$result = [];
		foreach (DB::getInstance()->query('SELECT agreement_sequence, agreed, regdate FROM member_agreed WHERE member_srl = ? ORDER BY id ASC', [$member_srl])->fetchAll() as $row)
		{
			$seq = intval($row->agreement_sequence);
			if (isset($titles[$seq]) && $titles[$seq] !== '')
			{
				$result[$seq] = ['title' => html_entity_decode($titles[$seq]), 'agreed' => $row->agreed === 'Y', 'regdate' => strval($row->regdate)];
			}
		}
		return array_values($result);
	}

	protected static function getActivity(int $member_srl): array
	{
		$db = DB::getInstance();
		$result = ['documents' => 0, 'comments' => 0, 'recent' => [], 'nick_log' => []];

		if (self::tableExists('documents'))
		{
			$result['documents'] = \DocumentModel::getDocumentCountByMemberSrl($member_srl);
			foreach ($db->query('SELECT document_srl, module_srl, title, regdate FROM documents WHERE member_srl = ? ORDER BY document_srl DESC LIMIT 5', [$member_srl])->fetchAll() as $doc)
			{
				$result['recent'][] = self::activityItem('document', intval($doc->module_srl), html_entity_decode(strip_tags(strval($doc->title))), strval($doc->regdate), getNotEncodedFullUrl('', 'document_srl', $doc->document_srl));
			}
		}
		if (self::tableExists('comments'))
		{
			$result['comments'] = \CommentModel::getCommentCountByMemberSrl($member_srl);
			foreach ($db->query('SELECT comment_srl, document_srl, module_srl, content, regdate FROM comments WHERE member_srl = ? ORDER BY comment_srl DESC LIMIT 5', [$member_srl])->fetchAll() as $comment)
			{
				$text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(strval($comment->content)))));
				$result['recent'][] = self::activityItem('comment', intval($comment->module_srl), cut_str($text, 60), strval($comment->regdate), getNotEncodedFullUrl('', 'document_srl', $comment->document_srl) . '#comment_' . intval($comment->comment_srl));
			}
		}
		usort($result['recent'], function($a, $b) { return strcmp($b['date'], $a['date']); });
		$result['recent'] = array_slice($result['recent'], 0, 5);

		if (self::tableExists('member_nickname_log'))
		{
			foreach ($db->query('SELECT before_nick_name, after_nick_name, regdate FROM member_nickname_log WHERE member_srl = ? ORDER BY regdate DESC LIMIT 5', [$member_srl])->fetchAll() as $log)
			{
				$result['nick_log'][] = ['before' => strval($log->before_nick_name), 'after' => strval($log->after_nick_name), 'date' => strval($log->regdate)];
			}
		}
		return $result;
	}

	protected static function activityItem(string $kind, int $module_srl, string $title, string $date, string $url): array
	{
		static $modules = [];
		static $domains = [];
		if (!isset($modules[$module_srl]))
		{
			$info = $module_srl ? ModuleModel::getModuleInfoByModuleSrl($module_srl) : null;
			$domain = '';
			if ($info && !empty($info->domain_srl))
			{
				if (!isset($domains[$info->domain_srl]))
				{
					$site = ModuleModel::getSiteInfo($info->domain_srl);
					$domains[$info->domain_srl] = $site ? strval($site->domain) : '';
				}
				$domain = $domains[$info->domain_srl];
			}
			$modules[$module_srl] = [
				'where' => $info ? html_entity_decode(Context::replaceUserLang(strval($info->browser_title ?: $info->mid), true)) : '',
				'site' => $domain,
			];
		}
		return ['kind' => $kind, 'title' => $title, 'date' => $date, 'url' => $url, 'where' => $modules[$module_srl]['where'], 'site' => $modules[$module_srl]['site']];
	}

	protected static function getLogins(int $member_srl): array
	{
		$db = DB::getInstance();
		$result = ['devices' => [], 'failures' => []];
		foreach ($db->query('SELECT id, ipaddress, last_ipaddress, regdate, last_visit, user_agent FROM member_autologin WHERE member_srl = ? ORDER BY id DESC LIMIT 20', [$member_srl])->fetchAll() as $row)
		{
			$ua = \Zittme\Framework\UA::getBrowserInfo(strval($row->user_agent));
			$result['devices'][] = [
				'id' => intval($row->id),
				'kind' => $ua->is_tablet ? 'tablet' : ($ua->is_mobile ? 'phone' : 'pc'),
				'browser' => strval($ua->browser ?? ''),
				'os' => strval($ua->os ?? ''),
				'ip' => strval($row->last_ipaddress ?: $row->ipaddress),
				'last' => strval($row->last_visit ?: $row->regdate),
			];
		}
		if (self::tableExists('member_count_history'))
		{
			$rows = $db->query('SELECT content FROM member_count_history WHERE member_srl = ?', [$member_srl])->fetchAll();
			$content = $rows ? @unserialize(strval($rows[0]->content)) : null;
			if (is_array($content))
			{
				foreach (array_slice(array_reverse($content), 0, 5) as $item)
				{
					if (is_array($item) && count($item) >= 3)
					{
						$result['failures'][] = ['ip' => strval($item[0]), 'message' => strval($item[1]), 'time' => date('YmdHis', intval($item[2]))];
					}
				}
			}
		}
		return $result;
	}

	/**
	 * Balance, level and, when point history is on, the last five lines.
	 */
	public static function getPointSummary(int $member_srl): ?array
	{
		if (!class_exists('PointModel') || !self::tableExists('point'))
		{
			return null;
		}
		$config = \PointModel::getConfig();
		if (!is_object($config) || ($config->able_module ?? 'N') !== 'Y')
		{
			return null;
		}
		$point = intval(\PointModel::getPoint($member_srl));
		$summary = [
			'value' => $point,
			'level' => intval(\PointModel::getLevel($point, $config->level_step ?? [])),
			'ledger' => false,
			'recent' => [],
		];
		$service = 'Zittme\\Modules\\Point\\Services\\PointService';
		if (class_exists($service) && method_exists($service, 'recent') && $service::isLedgerReady())
		{
			$summary['ledger'] = true;
			foreach ($service::recent($member_srl, 5) as $item)
			{
				$summary['recent'][] = [
					'amount' => intval($item['amount'] ?? 0),
					'reason' => strval($item['label'] ?? ''),
					'memo' => strval($item['memo'] ?? ''),
					'date' => strval($item['date'] ?? ''),
					'by_admin' => !empty($item['is_admin']),
				];
			}
		}
		return $summary;
	}

	public static function tableExists(string $table): bool
	{
		if (!isset(self::$_tables[$table]))
		{
			self::$_tables[$table] = DB::getInstance()->isTableExists($table);
		}
		return self::$_tables[$table];
	}

	protected static function escapeLike(string $value): string
	{
		return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
	}
}
