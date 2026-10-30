<?php
/* Copyright (C) NAVER <http://www.navercorp.com> */
/**
 * @class  memberAdminView
 * @author NAVER (developers@xpressengine.com)
 * member module's admin view class
 */
class MemberAdminView extends Member
{
	/**
	 * Group list
	 *
	 * @var array
	 */
	var $group_list = NULL;

	/**
	 * Selected member info
	 *
	 * @var array
	 */
	var $memberInfo = NULL;

	/**
	 * Member module config.
	 *
	 * @var Object
	 */
	var $memberConfig = NULL;

	/**
	 * initialization
	 *
	 * @return void
	 */
	function init()
	{
		$oMemberModel = getModel('member');
		$this->memberConfig = $oMemberModel->getMemberConfig();
		Context::set('config', $this->memberConfig);
		$oSecurity = new Security();
		$oSecurity->encodeHTML('config.signupForm..');

		// if member_srl exists, set memberInfo
		$member_srl = Context::get('member_srl');
		if($member_srl)
		{
			$this->memberInfo = $oMemberModel->getMemberInfoByMemberSrl($member_srl);
			if(!$this->memberInfo)
			{
				Context::set('member_srl','');
			}
			else
			{
				Context::set('member_info',$this->memberInfo);
			}
		}

		// retrieve group list
		$this->group_list = $oMemberModel->getGroups();
		if ($this->act !== 'dispMemberAdminGroupList')
		{
			foreach ($this->group_list as $group)
			{
				$group->title = Context::replaceUserLang($group->title, true);
			}
		}
		Context::set('group_list', $this->group_list);

		$security = new Security();
		$security->encodeHTML('group_list..', 'config..');

		$this->setTemplatePath($this->module_path.'tpl');
	}

	/**
	 * Open the member administration screen. Old addresses of the member
	 * list, groups, signup form and settings keep working and open the
	 * same place in the new screen.
	 *
	 * @param string $screen
	 * @param array $initial
	 * @return bool false when redirected to the admin address
	 */
	protected function _openConsole($screen, array $initial = array())
	{
		if (Context::get('module') !== 'admin')
		{
			$params = ['', 'module', 'admin'];
			foreach ($_GET as $key => $value)
			{
				if (is_scalar($value) && !in_array($key, ['module', 'mid', 'vid'], true))
				{
					$params[] = $key;
					$params[] = $value;
				}
			}
			$this->setRedirectUrl(getNotEncodedUrl(...$params));
			return false;
		}
		\Zittme\Modules\Member\Controllers\AdminConsole::render($this, $screen, $initial);
		return true;
	}

	/**
	 * Settings screen at one category.
	 *
	 * @param string $category
	 * @param array $initial
	 * @return void
	 */
	protected function _openSettings($category, array $initial = array())
	{
		$requested = strval(Context::get('cat'));
		if (in_array($requested, ['join', 'login', 'social', 'terms', 'profile', 'features', 'design', 'adv'], true))
		{
			$category = $requested;
		}
		$initial['cat'] = $category;
		$this->_openConsole('settings', $initial);
	}

	/**
	 * display member list
	 *
	 * @return void
	 */
	function dispMemberAdminList()
	{
		$initial = \Zittme\Modules\Member\Models\ConsoleQuery::fromLegacyParams(Context::getRequestVars());
		$this->_openConsole('list', $initial);
	}

	/**
	 * Member settings (old "default" tab).
	 *
	 * @return void
	 */
	public function dispMemberAdminConfig()
	{
		$this->_openSettings('join');
	}

	/**
	 * Member settings (old "features" tab).
	 *
	 * @return void
	 */
	public function dispMemberAdminFeaturesConfig()
	{
		$this->_openSettings('features');
	}

	/**
	 * Member settings (old "agreements" tab).
	 *
	 * @return void
	 */
	public function dispMemberAdminAgreementsConfig()
	{
		$this->_openSettings('terms');
	}

	/**
	 * Signup form (old "signup" tab).
	 *
	 * @return void
	 */
	public function dispMemberAdminSignUpConfig()
	{
		$this->_openConsole('form');
	}

	/**
	 * Member settings (old "login" tab).
	 *
	 * @return void
	 */
	public function dispMemberAdminLoginConfig()
	{
		$this->_openSettings('login');
	}

	/**
	 * Member settings (old "social login" tab).
	 *
	 * @return void
	 */
	public function dispMemberAdminSnsLoginConfig()
	{
		$this->_openSettings('social');
	}

	/**
	 * Member settings (old "identity verification" tab).
	 *
	 * @return void
	 */
	public function dispMemberAdminIdentityConfig()
	{
		$this->_openSettings('social', ['anchor' => 'identity']);
	}

	/**
	 * Member settings (old "design" tab).
	 *
	 * @return void
	 */
	public function dispMemberAdminDesignConfig()
	{
		$this->_openSettings('design');
	}

	/**
	 * default configuration for member management
	 *
	 * @return void
	 */
	function dispMemberAdminConfigOLD()
	{
		$oModuleModel = getModel('module');
		$oMemberModel = getModel('member');

		// Get a layout list
		$oLayoutModel = getModel('layout');
		$layout_list = $oLayoutModel->getLayoutList();

		Context::set('layout_list', $layout_list);

		$mlayout_list = $oLayoutModel->getLayoutList(0, 'M');

		Context::set('mlayout_list', $mlayout_list);

		// list of skins for member module
		$skin_list = $oModuleModel->getSkins($this->module_path);
		Context::set('skin_list', $skin_list);

		// list of skins for member module
		$mskin_list = $oModuleModel->getSkins($this->module_path, 'm.skins');
		Context::set('mskin_list', $mskin_list);

		// retrieve skins of editor
		$oEditorModel = getModel('editor');
		Context::set('editor_skin_list', $oEditorModel->getEditorSkinList());

		// get an editor
		$option->skin = $oEditorModel->getEditorConfig()->editor_skin;
		$option->primary_key_name = 'temp_srl';
		$option->content_key_name = 'agreement';
		$option->allow_fileupload = false;
		$option->enable_autosave = false;
		$option->enable_default_component = true;
		$option->enable_component = true;
		$option->resizable = true;
		$option->height = 300;
		$editor = $oEditorModel->getEditor(0, $option);
		Context::set('editor', $editor);

		$signupForm = $config->signupForm;
		foreach($signupForm as $val)
		{
			if($val->name == 'user_id')
			{
				$userIdInfo = $val;
				break;
			}
		}

		if($userIdInfo->isUse)
		{
			// get denied ID list
			Context::set('useUserID', 1);
			$denied_list = $oMemberModel->getDeniedIDs();
			Context::set('deniedIDs', $denied_list);
		}

		// get denied NickName List
		$deniedNickNames = $oMemberModel->getDeniedNickNames();
		Context::set('deniedNickNames', $deniedNickNames);

		$security = new Security();
		$security->encodeHTML('config..');

		$this->setTemplateFile('member_config');
	}

	/**
	 * display member information
	 *
	 * @return void
	 */
	function dispMemberAdminInfo()
	{
		$oMemberModel = getModel('member');
		$oModuleModel = getModel('module');

		$member_config = $oModuleModel->getModuleConfig('member');
		Context::set('member_config', $member_config);
		$extendForm = $oMemberModel->getCombineJoinForm($this->memberInfo);
		Context::set('extend_form_list', $extendForm);

		$memberInfo = Context::get('member_info');
		if(!is_object($memberInfo) || !$memberInfo->member_srl)
		{
			throw new Zittme\Framework\Exceptions\TargetNotFound();
		}
		$memberInfo = get_object_vars($memberInfo);
		if (!is_array($memberInfo['group_list'])) $memberInfo['group_list'] = array();
		Context::set('memberInfo', $memberInfo);

		$disableColumns = array('password', 'find_account_question', 'find_account_answer');
		Context::set('disableColumns', $disableColumns);

		// 본인인증 정보 (있으면 상세에 표시)
		Context::set('identity_record', \Zittme\Modules\Member\Identity\IdentityModel::getInstance()->getByMemberSrl((int)$memberInfo['member_srl']));

		$security = new Security();
		$security->encodeHTML('member_config..');
		$security->encodeHTML('extend_form_list...');

		$oMemberView = getView('member');

		$oMemberView->_getDisplayedMemberInfo($this->memberInfo, $extendForm, $member_config);

		$this->setTemplateFile('member_info');
	}

	/**
	 * display member insert form
	 *
	 * @return void
	 */
	function dispMemberAdminInsert()
	{
		$oMemberModel = getModel('member');
		$member_config = $this->memberConfig;

		if($member_info = Context::get('member_info'))
		{
			$member_info->signature = $oMemberModel->getSignature($this->memberInfo->member_srl);
		}
		else
		{
			$member_info = new stdClass;
		}

		Context::set('member_info', $member_info);

		$formTags = $this->_getMemberInputTag($member_info, true);
		Context::set('formTags', $formTags);

		// Editor of the module set for signing by calling getEditor
		foreach($formTags as $formTag)
		{
			if($formTag->name == 'signature')
			{
				$option = new stdClass;
				$option->primary_key_name = 'member_srl';
				$option->content_key_name = 'signature';
				$option->allow_html = $member_config->signature_html !== 'N';
				$option->allow_fileupload = $member_config->member_allow_fileupload === 'Y';
				$option->enable_autosave = false;
				$option->enable_default_component = true;
				$option->enable_component = false;
				$option->resizable = false;
				$option->disable_html = true;
				$option->height = 200;
				$option->editor_toolbar = 'simple';
				$option->editor_toolbar_hide = 'Y';
				$option->editor_skin = $member_config->signature_editor_skin;
				$option->sel_editor_colorset = $member_config->sel_editor_colorset;
				if (!$option->allow_html)
				{
					$option->editor_skin = 'textarea';
				}
				if ($option->allow_fileupload)
				{
					$option->module_srl = MemberView::getInstance()->getMemberModuleSrl();
					$option->upload_target_type = 'sig';
				}
				if ($member_config->member_max_filesize)
				{
					$option->allowed_filesize = $member_config->member_max_filesize * 1024;
				}

				Context::set('editor', getModel('editor')->getEditor($member_info->member_srl, $option));
			}
		}

		if ($member_info->limit_date < date('Ymd'))
		{
			$member_info->limit_date = '';
		}

		if (Context::get('member_srl'))
		{
			Context::setBrowserTitle(lang('member.msg_update_member'));
		}
		else
		{
			Context::setBrowserTitle(lang('member.msg_new_member'));
		}
		$this->setTemplateFile('insert_member');
	}

	/**
	 * Get tags by the member info type
	 *
	 * @param object $memberInfo
	 * @param boolean $isAdmin (true : admin, false : not admin)
	 *
	 * @return array
	 */
	function _getMemberInputTag($memberInfo = null, $isAdmin = false)
	{
		$extend_form_list = MemberModel::getCombineJoinForm($memberInfo);
		$security = new Security($extend_form_list);
		$security->encodeHTML('..column_title', '..description', '..default_value', '..options.');

		if ($memberInfo)
		{
			$memberInfo = get_object_vars($memberInfo);
			$isSignup = false;
		}
		else
		{
			$memberInfo = array();
			$isSignup = true;
		}

		$member_config = $this->memberConfig;
		if(!$this->memberConfig)
		{
			$member_config = $this->memberConfig = MemberModel::getMemberConfig();
		}
		$identifiers = $member_config->identifiers ?? [$member_config->identifier];
		$identifiers = array_intersect($identifiers, ['user_id', 'email_address']);

		global $lang;
		$formTags = array();

		foreach($member_config->signupForm as $formInfo)
		{
			if(!$formInfo->isUse || ($formInfo->name == 'password' && !$isAdmin))
			{
				continue;
			}
			if($formInfo->name === array_first($identifiers) && !$isAdmin)
			{
				continue;
			}

			$formTag = new stdClass();
			$inputTag = '';
			$formTag->title = $formInfo->title;
			if($isAdmin)
			{
				if($formInfo->mustRequired || $formInfo->required) $formTag->title = '<em style="color:red">*</em> '.$formTag->title;
			}
			else
			{
				if ($formInfo->required && $formInfo->name != 'password') $formTag->title = '<em style="color:red">*</em> '.$formTag->title;
			}
			$formTag->name = $formInfo->name;

			// Default input fields
			if($formInfo->isDefaultForm)
			{
				if($formInfo->imageType)
				{
					$formTag->type = 'image';
					if($formInfo->name == 'profile_image')
					{
						$target = $memberInfo['profile_image'];
						$functionName = 'doDeleteProfileImage';
					}
					else if($formInfo->name == 'image_name')
					{
						$target = $memberInfo['image_name'];
						$functionName = 'doDeleteImageName';
					}
					else if($formInfo->name == 'image_mark')
					{
						$target = $memberInfo['image_mark'];
						$functionName = 'doDeleteImageMark';
					}

					if(!empty($target->src))
					{
						$inputTag = sprintf('<input type="hidden" name="__%s_exist" value="true" /><span id="%s"><img src="%s" alt="%s" /> <button type="button" onclick="%s(%d);return false;">%s</button></span>',
							$formInfo->name,
							$formInfo->name.'tag',
							$target->src,
							$formInfo->title,
							$functionName,
							$memberInfo['member_srl'],
							$lang->cmd_delete);
					}
					else
					{
						$inputTag = sprintf('<input type="hidden" name="__%s_exist" value="false" />', $formInfo->name);
					}

					$max_filesize = min(FileHandler::returnBytes(ini_get('upload_max_filesize')), FileHandler::returnBytes(ini_get('post_max_size')));
					if (isset($member_config->{$formInfo->name.'_max_filesize'}))
					{
						$max_filesize = min($max_filesize, $member_config->{$formInfo->name.'_max_filesize'} * 1024);
					}
					$inputTag .= sprintf('<input type="file" name="%s" id="%s" value="" accept="image/*" data-max-filesize="%d" data-max-filesize-error="%s" /><p class="help-block">%s: %s, %s: %dpx, %s: %dpx</p>',
						$formInfo->name,
						$formInfo->name,
						$max_filesize,
						escape(lang('file.allowed_filesize_exceeded')),
						lang('file.allowed_filesize'),
						FileHandler::filesize($max_filesize),
						$lang->{$formInfo->name.'_max_width'},
						$member_config->{$formInfo->name.'_max_width'},
						$lang->{$formInfo->name.'_max_height'},
						$member_config->{$formInfo->name.'_max_height'});
					}//end imageType
					else if($formInfo->name == 'birthday')
					{
						$formTag->type = 'date';
						$inputTag = sprintf('<input type="hidden" name="birthday" id="date_birthday" value="%s" />' .
							'<input type="date" placeholder="YYYY-MM-DD" name="birthday_ui" class="inputDate" id="birthday" value="%s" ' .
							'min="' . date('Y-m-d',strtotime('-200 years')) . '"  max="' . date('Y-m-d',strtotime('+10 years')) . '" ' .
							'onchange="jQuery(\'#date_birthday\').val(this.value.replace(/-/g,\'\'));" readonly="readonly" /> ' .
							'<input type="button" value="%s" class="x_btn dateRemover" />',
							$memberInfo['birthday'],
							$memberInfo['birthday'] ? sprintf('%s-%s-%s', substr($memberInfo['birthday'], 0, 4), substr($memberInfo['birthday'], 4, 2), substr($memberInfo['birthday'], 6, 2)) : '',
							$lang->cmd_delete);
					}
					else if($formInfo->name == 'find_account_question')
					{
						continue;
					}
					else if($formInfo->name == 'email_address')
					{
						if(isset($member_config->enable_confirm) && $member_config->enable_confirm === 'Y' && !$isAdmin && !$isSignup)
						{
							$readonly = 'readonly="readonly" ';
						}
						else
						{
							$readonly = '';
						}
						$formTag->type = 'email';
						$inputTag = '<input type="email" name="email_address" id="email_address" value="'.$memberInfo['email_address'].'" ' . $readonly . '/>';
					}
					else if($formInfo->name == 'phone_number')
					{
						$formTag->type = 'phone';
						$match_country = $memberInfo['phone_country'];
						if(!$match_country && $member_config->phone_number_default_country)
						{
							$match_country = $member_config->phone_number_default_country;
						}
						if($match_country && !preg_match('/^[A-Z]{3}$/', $match_country))
						{
							$match_country = Zittme\Framework\i18n::getCountryCodeByCallingCode($match_country);
						}
						if(!$match_country && Context::get('lang_type') === 'ko')
						{
							$match_country = 'KOR';
						}
						if($member_config->phone_number_hide_country !== 'Y')
						{
							$inputTag = '<select name="phone_country" id="phone_country" class="phone_country">';
							$country_list = Zittme\Framework\i18n::listCountries(Context::get('lang_type') === 'ko' ? Zittme\Framework\i18n::SORT_NAME_KOREAN : Zittme\Framework\i18n::SORT_NAME_ENGLISH);
							foreach($country_list as $country)
							{
								if($country->calling_code)
								{
									$inputTag .= '<option value="' . $country->iso_3166_1_alpha3 . '"' . ($country->iso_3166_1_alpha3 === $match_country ? ' selected="selected"' : '') . '>';
									$inputTag .= escape(Context::get('lang_type') === 'ko' ? $country->name_korean : $country->name_english) . ' (+' . $country->calling_code . ')</option>';
								}
							}
							$inputTag .= '</select>' . "\n";
						}
						if($memberInfo['phone_number'])
						{
							if($match_country === 'KOR')
							{
								$phone_number = Zittme\Framework\Korea::formatPhoneNumber($memberInfo['phone_number']);
							}
							else
							{
								$phone_number = $memberInfo['phone_number'];
							}
						}
						else
						{
							$phone_number = '';
						}
						$inputTag .= '<input type="tel" name="phone_number" id="phone_number" class="phone_number" value="'.$phone_number .'" />';
						if($member_config->phone_number_verify_by_sms === 'Y')
						{
							$inputTag .= "\n" . '<button type="button" class="btn verifySMS" style="display:none">' . lang('member.verify_by_sms') . '</button>';
							$inputTag .= "\n" . '<div class="verifySMS_input_area" style="display:none">';
							$inputTag .= '<input type="number" class="verifySMS_input_number" />';
							$inputTag .= '<button type="button" class="btn verifySMS_input_button">' . lang('member.verify_by_sms_confirm') . '</button>';
							$inputTag .= '</div>';
						}
					}
					else if($formInfo->name == 'homepage' || $formInfo->name === 'blog')
					{
						$formTag->type = 'url';
						$input = new Zittme\Modules\Extravar\Models\Value(0, 1, '', 'url');
						$input->parent_type = 'member';
						$input->input_name = $formInfo->name;
						$input->input_id = $formInfo->name;
						$input->value = $memberInfo[$formInfo->name] ?? '';
						$inputTag = $input->getFormHTML();
					}
					else if($formInfo->name == 'password')
					{
						$formTag->type = 'password';
						$input = new Zittme\Modules\Extravar\Models\Value(0, 1, '', 'password');
						$input->parent_type = 'member';
						$input->input_name = $formInfo->name;
						$input->input_id = $formInfo->name;
						$input->value = '';
						$inputTag = $input->getFormHTML();
					}
					else
					{
						if($formInfo->name === 'nick_name' && ($member_config->allow_nickname_change ?? 'Y') === 'N' && !$isAdmin && !$isSignup)
						{
							$readonly = 'Y';
						}
						else
						{
							$readonly = 'N';
						}
						$formTag->type = 'text';
						$input = new Zittme\Modules\Extravar\Models\Value(0, 1, '', 'text');
						$input->parent_type = 'member';
						$input->input_name = $formInfo->name;
						$input->input_id = $formInfo->name;
						$input->value = $memberInfo[$formInfo->name] ?? '';
						$input->is_readonly = $readonly;
						$inputTag = $input->getFormHTML();
					}
				}

				// User-defined input fields
				else
				{
					$extendForm = $extend_form_list[$formInfo->member_join_form_srl] ?? null;
					if (!$extendForm || empty($extendForm->column_type))
					{
						continue;
					}
					$formTag->type = $extendForm->column_type;
					$input = new Zittme\Modules\Extravar\Models\Value(0, 1, '', $extendForm->column_type);
					$input->parent_type = 'member';
					$input->input_name = $extendForm->column_name;
					$input->input_id = $extendForm->column_name;
					$input->value = $extendForm->value ?? '';
					$input->default = $extendForm->default_value ?? null;
					$input->options = $extendForm->options ?? null;
					if ($extendForm->column_type === 'tel' || $extendForm->column_type === 'tel_intl')
					{
						$input->style = 'width:33.3px';
					}
					$inputTag = $input->getFormHTML();

					if (!empty($extendForm->description))
					{
						$inputTag = vsprintf('%s<p class="help-block">%s</p>', [
							$inputTag,
							$extendForm->description,
						]);
					}
				}

				$formTag->inputTag = $inputTag;
				$formTags[] = $formTag;
		}
		return $formTags;
	}

	/**
	 * display group list
	 *
	 * @return void
	 */
	function dispMemberAdminGroupList()
	{
		$this->_openConsole('groups', ['group' => intval(Context::get('group_srl'))]);
	}

	/**
	 * Signup form with one extra item open (or a new one).
	 *
	 * @return void
	 */
	function dispMemberAdminInsertJoinForm()
	{
		$this->_openConsole('form', ['join_form' => intval(Context::get('member_join_form_srl'))]);
	}

	/**
	 * Nickname change log, in the advanced settings.
	 *
	 * @return void
	 */
	function dispMemberAdminNickNameLog()
	{
		$this->_openSettings('adv', [
			'anchor' => 'nick_log',
			'nick_page' => max(1, intval(Context::get('page'))),
			'nick_target' => strval(Context::get('search_target') ?? ''),
			'nick_keyword' => strval(Context::get('search_keyword') ?? ''),
		]);
	}
}
/* End of file member.admin.view.php */
/* Location: ./modules/member/member.admin.view.php */
