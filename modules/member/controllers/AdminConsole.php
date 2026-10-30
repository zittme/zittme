<?php

namespace Zittme\Modules\Member\Controllers;

use BaseObject;
use Context;
use MemberController;
use MemberModel;
use ModuleHandler;
use Zittme\Framework\DB;
use Zittme\Framework\Exceptions\InvalidRequest;
use Zittme\Framework\Exceptions\TargetNotFound;
use Zittme\Framework\Session;
use Zittme\Modules\Member\Models\ConsoleGroups;
use Zittme\Modules\Member\Models\ConsoleQuery;
use Zittme\Modules\Member\Models\ConsoleSettings;

/**
 * Member administration screen: list, detail panel and actions on many
 * members, groups, the signup form and the member settings.
 *
 * Group changes go through MemberController::addMemberToGroup() and
 * removeMemberFromGroup() so that the member.addMemberToGroup and
 * member.removeMemberFromGroup triggers run as before. Field changes write
 * the same columns with the same meaning as procMemberAdminInsert and call
 * the member.updateMember triggers.
 */
class AdminConsole extends \Member
{
	/**
	 * Term keys explained in the screen (member.member_admin_term_*).
	 */
	const TERM_KEYS = [
		'member', 'group', 'def_group', 'admin_group', 'point', 'level', 'ledger',
		'unauthed', 'limit', 'denied', 'allow_mail', 'social', 'dormant', 'ext_var',
		'mark', 'uses', 'signup_form', 'required', 'public', 'mail_auth', 'identifier', 'all_sites', 'member_menu', 'who', 'column_name',
	];

	/**
	 * Screens of the member administration (the tabs at the top).
	 */
	const VIEWS = ['list', 'groups', 'form', 'settings'];

	/**
	 * Show the member administration screen from a view action.
	 */
	public static function render(\ModuleObject $view, string $screen, array $initial = []): void
	{
		$boot = self::getBootData($initial, $screen);
		Context::set('member_console_boot', json_encode($boot, \JSON_UNESCAPED_UNICODE | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT));
		$view->setTemplatePath(\RX_BASEDIR . 'modules/member/tpl');
		$view->setTemplateFile('member_console');
	}

	/**
	 * Data handed to the screen script.
	 */
	public static function getBootData(array $initial, string $screen = 'list'): array
	{
		Context::loadLang(\RX_BASEDIR . 'modules/member/lang');
		$config = MemberModel::getMemberConfig();
		$screen = in_array($screen, self::VIEWS, true) ? $screen : 'list';

		$groups = [];
		foreach (MemberModel::getGroups() as $group)
		{
			$groups[] = [
				'id' => intval($group->group_srl),
				'title' => html_entity_decode(Context::replaceUserLang(strval($group->title), true)),
				'def' => ($group->is_default ?? 'N') === 'Y',
				'admin' => ($group->is_admin ?? 'N') === 'Y',
			];
		}

		$ui = lang('member.member_admin_ui');
		$ui = $ui instanceof \ArrayObject ? $ui->getArrayCopy() : (is_array($ui) ? $ui : []);
		foreach (['member_admin_ui_groups', 'member_admin_ui_form', 'member_admin_ui_settings'] as $more_key)
		{
			$more = lang('member.' . $more_key);
			$more = $more instanceof \ArrayObject ? $more->getArrayCopy() : (is_array($more) ? $more : []);
			$ui = array_merge($ui, $more);
		}
		$terms = [];
		foreach (self::TERM_KEYS as $key)
		{
			$term = lang('member.member_admin_term_' . $key);
			$term = $term instanceof \ArrayObject ? $term->getArrayCopy() : (is_array($term) ? $term : []);
			if ($term)
			{
				$terms[$key] = $term;
			}
		}

		$point = null;
		if (class_exists('PointModel') && ConsoleQuery::tableExists('point'))
		{
			$point_config = \PointModel::getConfig();
			if (is_object($point_config) && ($point_config->able_module ?? 'N') === 'Y')
			{
				$ledger = class_exists('Zittme\\Modules\\Point\\Services\\PointService') && \Zittme\Modules\Point\Services\PointService::isLedgerReady();
				$point = [
					'ledger' => $ledger,
					'url' => getNotEncodedUrl('', 'module', 'admin', 'act', 'dispPointAdminConfig'),
					'history_url' => $ledger ? getNotEncodedUrl('', 'module', 'admin', 'act', 'dispPointAdminLedger') . '&member_srl=' : getNotEncodedUrl('', 'module', 'admin', 'act', 'dispPointAdminPointList'),
					'policy_url' => getNotEncodedUrl('', 'module', 'admin', 'act', 'dispPointAdminPolicy'),
				];
			}
		}

		$profile_url = getNotEncodedFullUrl('', 'act', 'dispMemberInfo', 'member_srl', 987654321);
		$logged_info = Context::get('logged_info');

		$boot = [
			'view' => $screen,
			'lang' => $ui,
			'terms' => $terms,
			'groups' => $groups,
			'point' => $point,
			'point_admin' => class_exists('PointModel') ? getNotEncodedUrl('', 'module', 'admin', 'act', 'dispPointAdminConfig') : '',
			'initial' => $initial,
			'me' => is_object($logged_info) ? intval($logged_info->member_srl) : 0,
			'sns' => ConsoleQuery::tableExists('member_sns_account'),
			'today' => date('Ymd'),
			'lang_type' => Context::getLangType(),
			'urls' => [
				'list' => getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMemberAdminList'),
				'insert' => getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMemberAdminInsert'),
				'edit' => getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMemberAdminInsert') . '&member_srl=',
				'groups' => getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMemberAdminGroupList'),
				'signup' => getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMemberAdminSignUpConfig'),
				'settings' => getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMemberAdminConfig'),
				'documents' => getNotEncodedUrl('', 'module', 'admin', 'act', 'dispDocumentAdminList', 'search_target', 'member_srl') . '&search_keyword=',
				'profile' => $profile_url,
				'group_members' => getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMemberAdminList') . '&selected_group_srl=',
				'site_editor' => getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMenuAdminSiteEditor'),
				'point_level' => class_exists('PointModel') ? getNotEncodedUrl('', 'module', 'admin', 'act', 'dispPointAdminConfig', 'sec', 'level') : '',
				'filebox' => getNotEncodedUrl('', 'module', 'admin', 'act', 'dispModuleAdminFileBox'),
				'signup_page' => getNotEncodedFullUrl('', 'act', 'dispMemberSignUpForm'),
				'editor_frame' => \RX_BASEURL . 'index.php?module=editor&act=dispEditorFrame&parent_input_id=',
			],
		];

		if ($screen === 'groups')
		{
			$boot['group_data'] = ConsoleGroups::listData();
		}
		elseif ($screen === 'form')
		{
			$boot['form'] = self::formData();
		}
		elseif ($screen === 'settings')
		{
			$counts = ConsoleQuery::getCounts(false);
			$boot['settings'] = [
				'values' => ConsoleSettings::values(),
				'meta' => ConsoleSettings::meta(),
				'members' => intval($counts['all'] ?? 0),
				'estimated' => !empty($counts['estimated']),
			];
		}
		return $boot;
	}

	/**
	 * What the signup form screen needs.
	 */
	protected static function formData(): array
	{
		$values = ConsoleSettings::values();
		$data = ['values' => []];
		foreach (ConsoleSettings::SIGNUP_KEYS as $key)
		{
			$data['values'][$key] = $values[$key];
		}
		$data['values']['identifiers'] = $values['identifiers'];
		$data['values']['identity_enable'] = $values['identity_enable'];
		$meta = ConsoleSettings::meta();
		$data['meta'] = [
			'countries' => $meta['countries'],
			'editor_skins' => $meta['editor_skins'],
			'extra_types' => $meta['extra_types'],
			'choice_types' => $meta['choice_types'],
		];
		return $data;
	}

	/**
	 * Groups with the number of members, again after a change.
	 */
	public function getMemberAdminGroups()
	{
		$this->add('group_data', ConsoleGroups::listData());
	}

	/**
	 * Places where one group is chosen.
	 */
	public function getMemberAdminGroupUsage()
	{
		$group_srl = intval(Context::get('group_srl'));
		$usage = ConsoleGroups::getUsage($group_srl);
		if (!$usage)
		{
			throw new TargetNotFound;
		}
		$this->add('usage', $usage['usage']);
		$this->add('sites', $usage['sites']);
	}

	/**
	 * Images in the file box, to pick a group image mark.
	 */
	public function getMemberAdminFileBox()
	{
		$this->add('filebox', ConsoleGroups::fileBox(intval(Context::get('page'))));
	}

	/**
	 * Settings and the signup form, again after a change.
	 */
	public function getMemberAdminSettings()
	{
		$this->add('values', ConsoleSettings::values());
		$this->add('lists', ConsoleSettings::lists());
	}

	/**
	 * Save changed settings. Each one is saved by the procedure of the old
	 * settings tab that owned it, with the same checks.
	 */
	public function procMemberAdminSaveConfigKeys()
	{
		$changes = json_decode(strval(Context::get('zmc_values')), true);
		if (!is_array($changes) || !$changes)
		{
			throw new InvalidRequest;
		}
		$result = ConsoleSettings::save($changes);
		if ($result instanceof BaseObject)
		{
			return $result;
		}
		$this->add('previous', $result);
		$this->add('values', ConsoleSettings::values());
		$this->add('lists', ConsoleSettings::lists());
	}

	/**
	 * The signup screen of the current member skin, for the preview.
	 * Nothing is saved; scripts are removed.
	 */
	public function getMemberAdminSignupPreview()
	{
		$device = Context::get('device') === 'M' ? 'M' : 'P';
		$config = MemberModel::getMemberConfig();
		$path = self::skinPathFor($device, $config);

		$form_tags = getAdminView('member')->_getMemberInputTag();
		$member_config = clone $config;
		$member_config->agreement = $config->agreements[1]->content ?? '';
		Context::set('member_config', $member_config);
		Context::set('formTags', $form_tags);
		Context::set('email_confirmation_required', $config->enable_confirm);
		$identity = \Zittme\Modules\Member\Identity\Base::getConfig();
		$identity_required = ($identity->identity_enable ?? 'N') === 'Y' && ($identity->identity_signup_required ?? 'N') === 'Y';
		Context::set('identity_signup_required', $identity_required);
		Context::set('identity_verified_info', null);
		Context::set('identity_verify_url', '#');
		Context::set('editor', '<textarea rows="4" style="width:100%" disabled="disabled"></textarea>');
		$identifier = array_first($config->identifiers);
		$identifier_form = new \stdClass;
		$identifier_form->title = lang($identifier);
		$identifier_form->name = $identifier;
		Context::set('identifierForm', $identifier_form);

		$logged_info = Context::get('logged_info');
		$is_logged = Context::get('is_logged');
		Context::set('logged_info', false);
		Context::set('is_logged', false);
		try
		{
			$html = \TemplateHandler::getInstance()->compile($path, 'signup_form');
		}
		finally
		{
			Context::set('logged_info', $logged_info);
			Context::set('is_logged', $is_logged);
		}
		$html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', strval($html));
		$html = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\')#i', '', $html);
		$css = [];
		foreach (Context::getCSSFile(true) as $file)
		{
			$url = strval($file['file'] ?? '');
			if ($url !== '' && strpos($url, 'modules/admin/') === false && strpos($url, 'member_console') === false)
			{
				$css[] = ['url' => $url, 'media' => strval($file['media'] ?? 'all')];
			}
		}
		$this->add('html', $html);
		$this->add('css', $css);
		$this->add('device', $device);
	}

	/**
	 * Skin folder the signup screen uses, without writing the default
	 * design file.
	 */
	protected static function skinPathFor(string $device, $config): string
	{
		$module_path = \RX_BASEDIR . 'modules/member/';
		if ($device === 'M' && ($config->mskin ?? '') !== '/USE_RESPONSIVE/')
		{
			$skin = ($config->mskin ?? '') ?: 'default';
			if ($skin === '/USE_DEFAULT/')
			{
				$skin = \ModuleModel::getModuleDefaultSkin('member', 'M', 0, false) ?: 'default';
			}
			if ($skin !== '/USE_RESPONSIVE/')
			{
				$path = \Zittme\Framework\Theme::resolveSkinPath($module_path, $skin, 'm.skins');
				if (!\Zittme\Framework\Storage::exists($path) && \Zittme\Framework\Theme::split($skin))
				{
					$path = \Zittme\Framework\Theme::resolveSkinPath($module_path, $skin, 'skins');
				}
				if (\Zittme\Framework\Storage::exists($path))
				{
					return $path;
				}
			}
		}
		$skin = ($config->skin ?? '') ?: 'default';
		if ($skin === '/USE_DEFAULT/')
		{
			$skin = \ModuleModel::getModuleDefaultSkin('member', 'P', 0, false) ?: 'default';
		}
		$path = \Zittme\Framework\Theme::resolveSkinPath($module_path, $skin, 'skins');
		if (!\Zittme\Framework\Storage::exists($path))
		{
			$path = $module_path . 'skins/default';
		}
		return $path;
	}

	/**
	 * Nickname change log.
	 */
	public function getMemberAdminNickLog()
	{
		$page = max(1, intval(Context::get('page')));
		$target = strval(Context::get('search_target'));
		$keyword = trim(strval(Context::get('search_keyword')));
		if (!in_array($target, ['before', 'after', 'user_id'], true) || $keyword === '')
		{
			$target = '';
			$keyword = '';
		}
		Context::set('search_target', $target);
		Context::set('search_keyword', $keyword);
		$output = MemberModel::getMemberModifyNicknameLog($page);
		$rows = [];
		foreach ($output->data ?: [] as $row)
		{
			$rows[] = [
				'date' => strval($row->regdate),
				'before' => html_entity_decode(strval($row->before_nick_name), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
				'after' => html_entity_decode(strval($row->after_nick_name), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
				'user_id' => strval($row->user_id ?? ''),
			];
		}
		$this->add('rows', $rows);
		$this->add('page', intval($output->page ?? $page));
		$this->add('total_page', intval($output->total_page ?? 1));
	}

	/**
	 * One page of members for the given conditions.
	 */
	public function getMemberAdminListJson()
	{
		$filter = ConsoleQuery::normalizeFilter(Context::getRequestVars());
		$cursor = strval(Context::get('cursor'));
		$offset = max(0, min(10000000, intval(Context::get('offset'))));
		$result = ConsoleQuery::getList($filter, $cursor, $offset);
		$this->add('rows', $result->rows);
		$this->add('has_more', $result->has_more);
		$this->add('next_cursor', $result->next_cursor);
	}

	/**
	 * Numbers next to the quick views and the groups.
	 */
	public function getMemberAdminQuickCounts()
	{
		$this->add('counts', ConsoleQuery::getCounts(Context::get('refresh') === 'Y', Context::get('recount') === 'Y'));
	}

	/**
	 * Everything about one member for the detail panel.
	 */
	public function getMemberAdminDetail()
	{
		$detail = ConsoleQuery::getDetail(intval(Context::get('member_srl')));
		if (!$detail)
		{
			throw new TargetNotFound;
		}
		$this->add('member', $detail);
	}

	/**
	 * Put members into a group, take them out, or leave only the given groups.
	 *
	 * The management group is never added or removed here, and a removal that
	 * would leave a member without any group is skipped.
	 */
	public function procMemberAdminUpdateMemberGroups()
	{
		$member_srls = self::getSrlList('member_srls');
		$group_srls = self::getSrlList('group_srls');
		$mode = strval(Context::get('mode'));
		if (!$member_srls || !$group_srls || !in_array($mode, ['add', 'remove', 'set'], true) || count($member_srls) > ConsoleQuery::MAX_SELECT)
		{
			throw new InvalidRequest;
		}

		$all_groups = MemberModel::getGroups();
		$admin_groups = [];
		foreach ($all_groups as $group)
		{
			if (($group->is_admin ?? 'N') === 'Y')
			{
				$admin_groups[] = intval($group->group_srl);
			}
		}
		foreach ($group_srls as $group_srl)
		{
			if (!isset($all_groups[$group_srl]) || in_array($group_srl, $admin_groups, true))
			{
				throw new InvalidRequest;
			}
		}

		$member_srls = self::existingMembers($member_srls);
		if (!$member_srls)
		{
			throw new TargetNotFound;
		}

		$before = ConsoleQuery::getGroupsOf($member_srls);
		$changed = $skipped = $already = 0;

		$oDB = DB::getInstance();
		$oDB->begin();
		foreach ($member_srls as $member_srl)
		{
			$current = $before[$member_srl] ?? [];
			$add = $remove = [];
			if ($mode === 'add')
			{
				$add = array_values(array_diff($group_srls, $current));
			}
			elseif ($mode === 'remove')
			{
				$remove = array_values(array_intersect($current, $group_srls));
				if ($remove && !array_diff($current, $remove))
				{
					$skipped++;
					continue;
				}
			}
			else
			{
				$add = array_values(array_diff($group_srls, $current));
				$remove = array_values(array_diff($current, $group_srls, $admin_groups));
			}

			if (!$add && !$remove)
			{
				$already++;
				continue;
			}

			foreach ($add as $group_srl)
			{
				$output = MemberController::addMemberToGroup($member_srl, $group_srl);
				if (!$output->toBool())
				{
					$oDB->rollback();
					return $output;
				}
			}
			foreach ($remove as $group_srl)
			{
				$output = MemberController::removeMemberFromGroup($member_srl, $group_srl);
				if (!$output->toBool())
				{
					$oDB->rollback();
					return $output;
				}
			}
			$changed++;
		}
		$oDB->commit();
		ConsoleQuery::forgetCounts();

		$this->add('before', self::pairs($before, $member_srls, []));
		$this->add('after', self::pairs(ConsoleQuery::getGroupsOf($member_srls), $member_srls, []));
		$this->add('changed', $changed);
		$this->add('skipped', $skipped);
		$this->add('already', $already);
	}

	/**
	 * Save only the fields that are sent: administrator memo, suspension until
	 * a date, login block, and email confirmation.
	 *
	 * Suspending or blocking login ends the member's current logins.
	 */
	public function procMemberAdminUpdateMemberFields()
	{
		$member_srls = self::getSrlList('member_srls');
		if (!$member_srls || count($member_srls) > ConsoleQuery::MAX_SELECT)
		{
			throw new InvalidRequest;
		}

		$set_memo = Context::get('memo_set') === 'Y';
		$set_limit = Context::get('limit_set') === 'Y';
		$status = strval(Context::get('status'));
		$set_status = $status !== '';
		$only_from = strval(Context::get('only_from'));
		if ($set_status && !in_array($status, ['APPROVED', 'DENIED', 'UNAUTHED'], true))
		{
			throw new InvalidRequest;
		}
		if ($only_from !== '' && !in_array($only_from, ['APPROVED', 'DENIED', 'UNAUTHED'], true))
		{
			throw new InvalidRequest;
		}
		if (($set_memo && count($member_srls) !== 1) || (!$set_memo && !$set_limit && !$set_status))
		{
			throw new InvalidRequest;
		}

		$memo = $set_memo ? utf8_clean(strval(Context::get('memo'))) : null;
		$limit_date = null;
		if ($set_limit)
		{
			$limit_date = preg_replace('/[^0-9]/', '', strval(Context::get('limit_date')));
			if ($limit_date !== '' && (strlen($limit_date) !== 8 || !checkdate(intval(substr($limit_date, 4, 2)), intval(substr($limit_date, 6, 2)), intval(substr($limit_date, 0, 4))) || $limit_date < date('Ymd')))
			{
				return new BaseObject(-1, 'member.member_admin_msg_bad_date');
			}
		}
		$limited_reason = Context::get('limited_reason');
		$refused_reason = Context::get('refused_reason');

		$logged_info = Context::get('logged_info');
		$me = is_object($logged_info) ? intval($logged_info->member_srl) : 0;
		$today = date('Ymd');

		$previous = [];
		$changed = $skipped = $revoked = 0;

		$oDB = DB::getInstance();
		$oDB->begin();
		foreach ($member_srls as $member_srl)
		{
			$output = executeQuery('member.getMemberInfoByMemberSrl', ['member_srl' => $member_srl]);
			$row = $output->data;
			if (!$output->toBool() || !$row || !$row->member_srl)
			{
				$skipped++;
				continue;
			}

			$blocks = ($set_limit && $limit_date !== '') || ($set_status && $status !== 'APPROVED');
			if ($blocks && $member_srl === $me)
			{
				$skipped++;
				continue;
			}
			if ($set_status && $only_from !== '' && $row->status !== $only_from)
			{
				$skipped++;
				continue;
			}

			$extra = $row->extra_vars ? unserialize($row->extra_vars) : null;
			$extra = is_object($extra) ? $extra : new \stdClass;
			$previous[$member_srl] = [
				'memo' => strval($row->description ?? ''),
				'limit_date' => strval($row->limit_date ?? ''),
				'limited_reason' => strval($extra->limited_reason ?? ''),
				'status' => strval($row->status),
				'refused_reason' => strval($extra->refused_reason ?? ''),
			];

			$args = new \stdClass;
			$args->member_srl = $member_srl;
			$extra_changed = false;
			if ($set_memo)
			{
				$args->description = $memo;
			}
			if ($set_limit)
			{
				$args->limit_date = $limit_date;
				if ($limited_reason !== null)
				{
					$extra->limited_reason = escape(utf8_clean(strval($limited_reason)));
					$extra_changed = true;
				}
			}
			if ($set_status)
			{
				$args->status = $status;
				$args->denied = $status === 'APPROVED' ? 'N' : 'Y';
				if ($refused_reason !== null)
				{
					$extra->refused_reason = escape(utf8_clean(strval($refused_reason)));
					$extra_changed = true;
				}
			}
			if ($extra_changed)
			{
				foreach (self::NOUSE_EXTRA_VARS as $key)
				{
					unset($extra->$key);
				}
				$args->extra_vars = serialize($extra);
			}

			$trigger_args = clone $row;
			foreach (get_object_vars($args) as $key => $value)
			{
				$trigger_args->$key = $value;
			}
			$output = ModuleHandler::triggerCall('member.updateMember', 'before', $trigger_args);
			if (!$output->toBool())
			{
				$oDB->rollback();
				return $output;
			}
			$output = executeQuery('member.updateMemberAdminFields', $args);
			if (!$output->toBool())
			{
				$oDB->rollback();
				return $output;
			}
			ModuleHandler::triggerCall('member.updateMember', 'after', $trigger_args);
			MemberController::clearMemberCache($member_srl);
			$changed++;

			$now_limited = $set_limit && $limit_date !== '' && $limit_date >= $today;
			if (($set_status && $status === 'DENIED') || $now_limited)
			{
				self::endLogins($member_srl);
				$revoked++;
			}
		}
		$oDB->commit();
		if ($set_limit || $set_status)
		{
			ConsoleQuery::forgetCounts();
		}

		$this->add('previous', self::pairs($previous, array_keys($previous), null));
		$this->add('changed', $changed);
		$this->add('skipped', $skipped);
		$this->add('revoked', $revoked);
	}

	/**
	 * Log a member out of one device, or out of every device and session.
	 */
	public function procMemberAdminRevokeLogins()
	{
		$member_srl = intval(Context::get('member_srl'));
		if ($member_srl <= 0 || !self::existingMembers([$member_srl]))
		{
			throw new TargetNotFound;
		}

		$autologin_id = intval(Context::get('autologin_id'));
		if ($autologin_id > 0)
		{
			$rows = DB::getInstance()->query('SELECT id FROM member_autologin WHERE id = ? AND member_srl = ?', [$autologin_id, $member_srl])->fetchAll();
			if (!$rows)
			{
				throw new TargetNotFound;
			}
			DB::getInstance()->query('DELETE FROM member_autologin WHERE id = ? AND member_srl = ?', [$autologin_id, $member_srl]);
			$this->add('scope', 'device');
			return;
		}

		self::endLogins($member_srl, false);
		$this->add('scope', 'all');
	}

	/**
	 * End every login of a member: open sessions become invalid and the
	 * saved logins (member_autologin) are removed. Pending authentication
	 * mail is removed too, as procMemberAdminInsert does for blocked members.
	 */
	public static function endLogins(int $member_srl, bool $auth_mail = true): void
	{
		if ($member_srl <= 0)
		{
			return;
		}
		$validity_info = Session::getValidityInfo($member_srl);
		$validity_info->invalid_before = time();
		Session::setValidityInfo($member_srl, $validity_info);
		executeQuery('member.deleteAutologin', ['member_srl' => $member_srl]);
		if ($auth_mail)
		{
			executeQuery('member.deleteAuthMail', ['member_srl' => $member_srl]);
		}
	}

	/**
	 * Read a list of numbers sent as an array or as "1,2,3".
	 */
	protected static function getSrlList(string $key): array
	{
		$value = Context::get($key);
		if (!is_array($value))
		{
			$value = preg_split('/[,|@]+/', strval($value));
		}
		$list = [];
		foreach ($value as $item)
		{
			$item = intval($item);
			if ($item > 0)
			{
				$list[$item] = $item;
			}
		}
		return array_values($list);
	}

	/**
	 * Turn a map keyed by member_srl into [member_srl, value] pairs, so that
	 * the keys survive the JSON response.
	 */
	protected static function pairs(array $map, array $member_srls, $default): array
	{
		$result = [];
		foreach ($member_srls as $member_srl)
		{
			$result[] = [intval($member_srl), $map[$member_srl] ?? $default];
		}
		return $result;
	}

	protected static function existingMembers(array $member_srls): array
	{
		if (!$member_srls)
		{
			return [];
		}
		$rows = DB::getInstance()->query('SELECT member_srl FROM member WHERE member_srl IN (' . implode(',', array_fill(0, count($member_srls), '?')) . ')', $member_srls)->fetchAll();
		return array_map(function($row) { return intval($row->member_srl); }, $rows);
	}
}
