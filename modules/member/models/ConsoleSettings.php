<?php

namespace Zittme\Modules\Member\Models;

use BaseObject;
use Context;
use MemberModel;
use ModuleModel;
use Zittme\Framework\Exceptions\InvalidRequest;

/**
 * Member settings and the signup form for the member administration screen.
 *
 * The screen shows the settings by purpose, but every save still goes
 * through the procedure of the old tab that owned the setting. The request
 * is filled with the values the old form showed for every field of that tab,
 * and only the changed fields are replaced. The stored config is therefore
 * the same as saving the old tab with that one change.
 */
class ConsoleSettings
{
	/**
	 * Old tab => procedure that saves it.
	 */
	public const TABS = [
		'default' => 'procMemberAdminInsertDefaultConfig',
		'features' => 'procMemberAdminInsertFeaturesConfig',
		'agreements' => 'procMemberAdminInsertAgreementsConfig',
		'signup' => 'procMemberAdminInsertSignupConfig',
		'login' => 'procMemberAdminInsertLoginConfig',
		'sns' => 'procMemberAdminInsertSnsLoginConfig',
		'identity' => 'procMemberAdminInsertIdentityConfig',
		'design' => 'procMemberAdminInsertDesignConfig',
	];

	public const DEFAULT_KEYS = [
		'member_mid', 'force_mid', 'enable_join', 'enable_join_key', 'enable_confirm', 'authmail_expires', 'authmail_expires_unit',
		'password_strength', 'password_hashing_algorithm', 'password_hashing_work_factor', 'password_hashing_auto_upgrade',
		'password_change_invalidate_other_sessions', 'password_reset_method', 'allow_nickname_change', 'update_nickname_log',
		'nickname_symbols', 'nickname_symbols_allowed_list', 'nickname_spaces', 'allow_duplicate_nickname', 'member_profile_view', 'signup_dup_button',
	];
	public const FEATURE_KEYS = ['scrapped_documents', 'saved_documents', 'my_documents', 'my_comments', 'active_logins', 'nickname_log'];
	public const AGREEMENT_COUNT = 5;
	public const SIGNUP_KEYS = [
		'limit_day', 'limit_day_description', 'emailhost_check', 'special_phone_number', 'special_phone_code',
		'max_auth_sms_count', 'max_auth_sms_count_time', 'redirect_url',
		'phone_number_default_country', 'phone_number_hide_country', 'phone_number_allow_duplicate', 'phone_number_verify_by_sms',
		'profile_image_force_ratio', 'signature_editor_skin', 'signature_max_height', 'signature_html', 'signature_html_retroact',
		'member_allow_fileupload', 'member_max_filesize', 'signup_form',
	];
	public const LOGIN_KEYS = [
		'identifiers', 'change_password_date', 'max_error_count', 'max_error_count_time', 'login_failure_except_ip',
		'login_invalidate_other_sessions', 'after_login_url', 'after_logout_url',
	];
	public const IDENTITY_KEYS = [
		'identity_enable', 'identity_dup_check', 'identity_signup_required', 'identity_find_account', 'identity_provider',
		'identity_adult_age', 'identity_adult_mids', 'identity_age_groups', 'identity_danal_cpid', 'identity_danal_cppwd',
		'identity_danal_charset', 'identity_truora_mode', 'identity_truora_api_key', 'identity_truora_flow_id',
		'identity_truora_countries', 'identity_truora_ttl', 'identity_truora_webhook_secret', 'identity_truora_clear_secrets',
	];
	/**
	 * Request names of the identity form that this screen fills itself.
	 */
	public const IDENTITY_FORM_KNOWN = ['identity_age_group_on', 'identity_age_group_age'];
	public const FORM_SYSTEM = ['module', 'act', 'mid', 'vid', 'ruleset', 'success_return_url', 'error_return_url', 'xe_validator_id', '_rx_csrf_token'];

	protected static $_identity_extra = null;

	public const DESIGN_KEYS = ['layout_srl', 'skin', 'colorset', 'mlayout_srl', 'mskin'];

	/**
	 * Default form items whose title can be changed, as in the old form.
	 */
	public const TITLE_EDIT = ['birthday', 'signature', 'profile_image', 'image_name', 'image_mark'];
	public const FIXED_PUBLIC = ['nick_name'];
	public const FIXED_PRIVATE = ['email_address', 'phone_number', 'password'];
	public const IMAGE_ITEMS = ['profile_image', 'image_name', 'image_mark'];

	/**
	 * Extra variable types offered for a new signup item (file is not
	 * offered by the old form either).
	 */
	public const EXTRA_TYPES = ['text', 'textarea', 'select', 'radio', 'checkbox', 'date', 'tel_v2', 'tel', 'tel_intl', 'tel_intl_v2', 'kr_zip', 'email_address', 'homepage', 'number', 'country', 'language', 'time', 'timezone', 'password'];
	public const CHOICE_TYPES = ['select', 'radio', 'checkbox'];

	/**
	 * Which old tab owns a key.
	 */
	public static function tabOf(string $key): ?string
	{
		if (in_array($key, self::DEFAULT_KEYS, true))
		{
			return 'default';
		}
		if (in_array($key, self::FEATURE_KEYS, true))
		{
			return 'features';
		}
		if (preg_match('/^agreement_([1-9])$/', $key, $m) && intval($m[1]) <= self::AGREEMENT_COUNT)
		{
			return 'agreements';
		}
		if (in_array($key, self::SIGNUP_KEYS, true))
		{
			return 'signup';
		}
		if (in_array($key, self::LOGIN_KEYS, true))
		{
			return 'login';
		}
		if (in_array($key, self::snsKeys(), true))
		{
			return 'sns';
		}
		if (in_array($key, self::IDENTITY_KEYS, true) || isset(self::identityExtraByName()[$key]))
		{
			return 'identity';
		}
		if (in_array($key, self::DESIGN_KEYS, true))
		{
			return 'design';
		}
		if ($key === 'group_image_mark')
		{
			return 'group';
		}
		return null;
	}

	/**
	 * Placeholder sent to the screen in place of a stored secret. Sending it
	 * back unchanged keeps the stored value.
	 */
	const SECRET_MASK = '********';

	/**
	 * Keys that hold a provider secret and are never sent to the screen.
	 */
	public static function secretKeys(): array
	{
		$keys = ['identity_danal_cppwd'];
		foreach (\Zittme\Modules\Member\Social\Base::$supported_services as $service)
		{
			$keys[] = $service . '_client_secret';
		}
		return $keys;
	}

	protected static function maskSecret($value): string
	{
		return strval($value ?? '') !== '' ? self::SECRET_MASK : '';
	}

	/**
	 * Replace the placeholder with the stored secret before the values are
	 * handed to the old save procedures.
	 */
	protected static function restoreSecrets(array $v): array
	{
		$stored = null;
		foreach (self::secretKeys() as $key)
		{
			if (($v[$key] ?? null) !== self::SECRET_MASK)
			{
				continue;
			}
			if ($stored === null)
			{
				$stored = [
					'sns' => self::detachedConfig(\Zittme\Modules\Member\Social\Base::class),
					'identity' => self::detachedConfig(\Zittme\Modules\Member\Identity\Base::class),
				];
			}
			$source = $key === 'identity_danal_cppwd' ? $stored['identity'] : $stored['sns'];
			$v[$key] = self::plain($source->$key ?? '');
		}
		return $v;
	}

	public static function snsKeys(): array
	{
		$keys = ['sns_login_enable'];
		foreach (\Zittme\Modules\Member\Social\Base::$supported_services as $service)
		{
			$keys[] = 'sns_' . $service . '_enable';
			$keys[] = $service . '_client_id';
			$keys[] = $service . '_client_secret';
		}
		return $keys;
	}

	/**
	 * Forget the member config kept in this request, so that the next read
	 * sees what was just saved.
	 */
	public static function forgetConfig(): void
	{
		(function() {
			static::$_member_config = null;
		})->bindTo(null, MemberModel::class)();
		\Zittme\Modules\Member\Social\Base::$config = null;
		\Zittme\Modules\Member\Identity\Base::$config = null;
		unset($GLOBALS['__ModuleConfig__']['member']);
		self::$_identity_extra = null;
	}

	/**
	 * Social login or identity config with their defaults, read from a copy.
	 * Their getConfig() fills the defaults into the shared member config, and
	 * the next save of any tab would then store them, which the old screens
	 * only did when that tab was saved.
	 */
	protected static function detachedConfig(string $class)
	{
		$shared = MemberModel::getMemberConfig();
		$copy = unserialize(serialize($shared));
		$set = \Closure::bind(function($value) {
			static::$_member_config = $value;
		}, null, MemberModel::class);
		$previous = $class::$config;
		$set($copy);
		$class::$config = null;
		try
		{
			return $class::getConfig();
		}
		finally
		{
			$set($shared);
			$class::$config = $previous;
		}
	}

	/**
	 * Identity fields of the old identity form that this screen does not
	 * know by name (for example a field added later for a new provider).
	 *
	 * The old form is rendered and read like a browser would send it, so
	 * such a field keeps its value when another identity field is saved,
	 * and the screen can show it with its own label.
	 */
	public static function identityExtra(): array
	{
		if (self::$_identity_extra !== null)
		{
			return self::$_identity_extra;
		}
		$fields = [];
		try
		{
			foreach (self::identityForm() as $name => $field)
			{
				if (in_array($name, self::IDENTITY_KEYS, true) || in_array($name, self::IDENTITY_FORM_KNOWN, true) || in_array($name, self::FORM_SYSTEM, true) || $name === 'identity_adult_mids')
				{
					continue;
				}
				$fields[] = $field;
			}
		}
		catch (\Throwable $e)
		{
			$fields = [];
		}
		return self::$_identity_extra = $fields;
	}

	protected static function identityExtraByName(): array
	{
		$map = [];
		foreach (self::identityExtra() as $field)
		{
			$map[$field['name']] = $field;
		}
		return $map;
	}

	/**
	 * Add the values of fields this screen does not know by name: first the
	 * fields of the old identity form, then any other stored identity_* value.
	 */
	protected static function identityExtraPost(array $post, array $v): array
	{
		foreach (self::identityExtra() as $field)
		{
			$value = $v[$field['name']] ?? $field['value'];
			if ($field['type'] === 'check')
			{
				$post[$field['name']] = $value === 'Y' ? $field['on'] : null;
			}
			elseif ($field['type'] === 'checks')
			{
				$post[$field['name']] = is_array($value) ? array_values($value) : [];
			}
			else
			{
				$post[$field['name']] = strval($value);
			}
		}
		$config = MemberModel::getMemberConfig();
		$handled = array_merge(self::IDENTITY_KEYS, ['identity_age_groups']);
		foreach (get_object_vars($config) as $key => $value)
		{
			if (strncmp($key, 'identity_', 9) === 0 && !in_array($key, $handled, true) && !array_key_exists($key, $post) && (is_scalar($value) || is_array($value)))
			{
				$post[$key] = $value;
			}
		}
		return $post;
	}

	/**
	 * The old identity form, read like a browser: name => field.
	 */
	protected static function identityForm(): array
	{
		$path = \RX_BASEDIR . 'modules/member/tpl';
		if (!file_exists($path . '/identity_config.html'))
		{
			return [];
		}
		$copy = self::detachedConfig(\Zittme\Modules\Member\Identity\Base::class);
		foreach (get_object_vars($copy) as $key => $value)
		{
			if (is_string($value) && strncmp($value, '$user_lang->', 12) !== 0)
			{
				$copy->$key = escape($value, false);
			}
		}
		$keep = [];
		$vars = [
			'config' => $copy,
			'identity_providers' => \Zittme\Modules\Member\Identity\Base::$supported_providers,
			'identity_callback_url' => '',
			'identity_webhook_url' => '',
			'identity_mid_list' => self::midList(),
			'identity_group_list' => MemberModel::getGroups() ?: [],
		];
		foreach ($vars as $key => $value)
		{
			$keep[$key] = Context::get($key);
			Context::set($key, $value);
		}
		try
		{
			$html = \TemplateHandler::getInstance()->compile($path, 'identity_config');
		}
		finally
		{
			foreach ($keep as $key => $value)
			{
				Context::set($key, $value);
			}
		}

		$dom = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$dom->loadHTML('<?xml encoding="utf-8"?>' . strval($html));
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		$xp = new \DOMXPath($dom);
		$form = null;
		foreach ($xp->query('//form') as $candidate)
		{
			foreach ($xp->query('.//input[@name="act"]', $candidate) as $act)
			{
				if ($act->getAttribute('value') === 'procMemberAdminInsertIdentityConfig')
				{
					$form = $candidate;
				}
			}
		}
		if (!$form)
		{
			return [];
		}

		$label = function(\DOMNode $el) use($xp) {
			for ($node = $el->parentNode; $node && $node->nodeType === XML_ELEMENT_NODE; $node = $node->parentNode)
			{
				$class = ' ' . $node->getAttribute('class') . ' ';
				if (strpos($class, ' x_control-group ') !== false)
				{
					$title = $xp->query('.//*[contains(concat(" ", @class, " "), " x_control-label ")]', $node)->item(0);
					$help = $xp->query('.//*[contains(concat(" ", @class, " "), " x_help-block ")]', $node)->item(0);
					return [trim(preg_replace('/\s+/u', ' ', $title ? $title->textContent : '')), trim(preg_replace('/\s+/u', ' ', $help ? $help->textContent : ''))];
				}
			}
			return ['', ''];
		};

		$fields = [];
		foreach ($xp->query('.//input|.//select|.//textarea', $form) as $el)
		{
			$name = $el->getAttribute('name');
			if ($name === '' || $el->hasAttribute('disabled'))
			{
				continue;
			}
			$multi = substr($name, -2) === '[]';
			$base = $multi ? substr($name, 0, -2) : $name;
			if (strpos($base, '[') !== false)
			{
				$base = substr($base, 0, strpos($base, '['));
				$fields[$base] = $fields[$base] ?? ['name' => $base, 'type' => 'complex', 'value' => '', 'label' => '', 'desc' => ''];
				continue;
			}
			$tag = strtolower($el->nodeName);
			$type = strtolower($el->getAttribute('type'));
			if ($tag === 'input' && in_array($type, ['submit', 'button', 'image', 'reset', 'file'], true))
			{
				continue;
			}
			[$title, $desc] = $label($el);
			$text = trim(preg_replace('/\s+/u', ' ', $el->parentNode ? $el->parentNode->textContent : ''));
			if ($tag === 'input' && $type === 'radio')
			{
				$field = $fields[$base] ?? ['name' => $base, 'type' => 'choice', 'value' => '', 'options' => [], 'label' => $title, 'desc' => $desc];
				$field['options'][] = [$el->getAttribute('value'), $text !== '' ? $text : $el->getAttribute('value')];
				if ($el->hasAttribute('checked'))
				{
					$field['value'] = $el->getAttribute('value');
				}
				$fields[$base] = $field;
				continue;
			}
			if ($tag === 'input' && $type === 'checkbox')
			{
				if ($multi)
				{
					$field = $fields[$base] ?? ['name' => $base, 'type' => 'checks', 'value' => [], 'options' => [], 'label' => $title, 'desc' => $desc];
					$field['options'][] = [$el->getAttribute('value'), $text !== '' ? $text : $el->getAttribute('value')];
					if ($el->hasAttribute('checked'))
					{
						$field['value'][] = $el->getAttribute('value');
					}
					$fields[$base] = $field;
				}
				else
				{
					$fields[$base] = ['name' => $base, 'type' => 'check', 'value' => $el->hasAttribute('checked') ? 'Y' : 'N', 'on' => $el->getAttribute('value') ?: 'on', 'label' => $title !== '' ? $title : $text, 'desc' => $title !== '' ? $desc : ''];
				}
				continue;
			}
			if ($tag === 'select')
			{
				$options = [];
				$value = null;
				foreach ($xp->query('.//option', $el) as $option)
				{
					if ($option->hasAttribute('disabled'))
					{
						continue;
					}
					$options[] = [$option->getAttribute('value'), trim($option->textContent)];
					if ($value === null && $option->hasAttribute('selected'))
					{
						$value = $option->getAttribute('value');
					}
				}
				$fields[$base] = ['name' => $base, 'type' => 'choice', 'value' => $value ?? strval($options[0][0] ?? ''), 'options' => $options, 'label' => $title, 'desc' => $desc];
				continue;
			}
			if ($el->hasAttribute('readonly'))
			{
				continue;
			}
			$fields[$base] = [
				'name' => $base,
				'type' => $tag === 'textarea' ? 'textarea' : ($type === 'password' ? 'password' : 'text'),
				'value' => $tag === 'textarea' ? $el->textContent : $el->getAttribute('value'),
				'label' => $title,
				'desc' => $desc,
			];
		}
		return array_filter($fields, function($field) {
			return $field['type'] !== 'complex';
		});
	}

	/**
	 * Text as the old form showed it in an input (and sent it back).
	 */
	protected static function plain($value): string
	{
		if ($value === null || is_array($value) || is_object($value))
		{
			return '';
		}
		$value = strval($value);
		if (strncmp($value, '$user_lang->', 12) === 0)
		{
			return $value;
		}
		return html_entity_decode($value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
	}

	protected static function yn($value): string
	{
		return $value === 'Y' ? 'Y' : 'N';
	}

	/**
	 * Every value of the screen, in the form the old screens showed them.
	 */
	public static function values(): array
	{
		$config = MemberModel::getMemberConfig();
		$v = [];

		// default
		$v['member_mid'] = self::plain($config->mid ?? 'member');
		$v['force_mid'] = !empty($config->force_mid) ? 'Y' : 'N';
		$v['enable_join'] = $config->enable_join === 'Y' ? 'Y' : (empty($config->enable_join_key) ? 'N' : 'KEY');
		$v['enable_join_key'] = self::plain($config->enable_join_key ?? '');
		$v['enable_confirm'] = self::yn($config->enable_confirm);
		$v['authmail_expires'] = intval($config->authmail_expires ?: 1);
		$v['authmail_expires_unit'] = in_array(intval($config->authmail_expires_unit), [86400, 3600, 60, 1], true) ? intval($config->authmail_expires_unit) : 86400;
		$v['member_profile_view'] = self::yn($config->member_profile_view);
		$v['signup_dup_button'] = self::yn($config->signup_dup_button ?? 'N');
		$v['allow_nickname_change'] = ($config->allow_nickname_change ?? 'Y') !== 'N' ? 'Y' : 'N';
		$v['update_nickname_log'] = self::yn($config->update_nickname_log);
		$v['nickname_symbols'] = in_array($config->nickname_symbols, ['Y', 'N', 'LIST'], true) ? $config->nickname_symbols : 'Y';
		$v['nickname_symbols_allowed_list'] = self::plain($config->nickname_symbols_allowed_list ?: '');
		$v['nickname_spaces'] = self::yn($config->nickname_spaces ?? 'N');
		$v['allow_duplicate_nickname'] = self::yn($config->allow_duplicate_nickname ?? 'N');
		$v['password_strength'] = in_array($config->password_strength, ['low', 'normal', 'high'], true) ? $config->password_strength : 'normal';
		$algos = self::passwordAlgorithms();
		$algo = $config->password_hashing_algorithm;
		if (!isset($algos[$algo]) || $algos[$algo] === false)
		{
			$algo = '';
			foreach ($algos as $key => $usable)
			{
				if ($usable !== false)
				{
					$algo = $key;
					break;
				}
			}
		}
		$v['password_hashing_algorithm'] = $algo;
		$factor = intval($config->password_hashing_work_factor);
		$v['password_hashing_work_factor'] = ($factor >= 4 && $factor <= 16) ? $factor : 4;
		$v['password_hashing_auto_upgrade'] = self::yn($config->password_hashing_auto_upgrade);
		$v['password_change_invalidate_other_sessions'] = self::yn($config->password_change_invalidate_other_sessions);
		$v['password_reset_method'] = intval($config->password_reset_method ?? 1) === 2 ? 2 : 1;

		// features
		$features = is_array($config->features ?? null) ? $config->features : [];
		foreach (self::FEATURE_KEYS as $key)
		{
			$v[$key] = ($features[$key] ?? true) !== false ? 'Y' : 'N';
		}
		if ($config->update_nickname_log !== 'Y')
		{
			$v['nickname_log'] = 'N';
		}

		// agreements
		for ($i = 1; $i <= self::AGREEMENT_COUNT; $i++)
		{
			$agreement = $config->agreements[$i] ?? null;
			$type = is_object($agreement) ? strval($agreement->type ?? '') : '';
			$v['agreement_' . $i] = [
				'title' => is_object($agreement) ? self::plain($agreement->title ?? '') : '',
				'content' => is_object($agreement) ? strval($agreement->content ?? '') : '',
				'type' => in_array($type, ['required', 'optional', 'disabled'], true) ? $type : 'disabled',
			];
		}

		// signup
		$v['limit_day'] = intval($config->limit_day);
		$v['limit_day_description'] = self::plain($config->limit_day_description ?? '');
		$v['emailhost_check'] = $config->emailhost_check === 'prohibited' ? 'prohibited' : 'allowed';
		$v['special_phone_number'] = self::plain($config->special_phone_number ?? '');
		$v['special_phone_code'] = self::plain($config->special_phone_code ?? '');
		$v['max_auth_sms_count'] = intval(($config->max_auth_sms_count ?? 0) ?: 5);
		$v['max_auth_sms_count_time'] = intval(($config->max_auth_sms_count_time ?? 0) ?: 600);
		$v['redirect_url'] = self::plain($config->redirect_url ?? '');
		$countries = self::countryCodes();
		$country = strval($config->phone_number_default_country ?? '');
		$matched = '';
		foreach ($countries as $code => $calling)
		{
			if ($country !== '' && ($code === $country || $calling === $country))
			{
				$matched = $code;
				break;
			}
		}
		$v['phone_number_default_country'] = $matched !== '' ? $matched : strval(array_key_first($countries) ?? '');
		$v['phone_number_hide_country'] = self::yn($config->phone_number_hide_country);
		$v['phone_number_allow_duplicate'] = self::yn($config->phone_number_allow_duplicate);
		$v['phone_number_verify_by_sms'] = self::yn($config->phone_number_verify_by_sms);
		$v['profile_image_force_ratio'] = ($config->profile_image_force_ratio ?? 'Y') === 'N' ? 'N' : 'Y';
		$editor_skins = self::editorSkins();
		$v['signature_editor_skin'] = in_array($config->signature_editor_skin, $editor_skins, true) ? $config->signature_editor_skin : strval($editor_skins[0] ?? '');
		$v['signature_max_height'] = intval($config->signature_max_height ?? 100);
		$v['signature_html'] = $config->signature_html === 'N' ? 'N' : 'Y';
		$v['signature_html_retroact'] = self::yn($config->signature_html_retroact);
		$v['member_allow_fileupload'] = self::yn($config->member_allow_fileupload);
		$v['member_max_filesize'] = intval($config->member_max_filesize);
		$v['signup_form'] = self::formItems($config);

		// login
		$identifiers = is_array($config->identifiers) ? $config->identifiers : ['user_id', 'email_address'];
		$v['identifiers'] = array_values(array_filter(['user_id', 'email_address', 'phone_number'], function($key) use($identifiers) {
			return in_array($key, $identifiers, true);
		}));
		$v['change_password_date'] = intval($config->change_password_date);
		$v['max_error_count'] = intval($config->max_error_count);
		$v['max_error_count_time'] = intval($config->max_error_count_time);
		$v['login_failure_except_ip'] = implode("\n", is_array($config->login_failure_except_ip) ? $config->login_failure_except_ip : []);
		$v['login_invalidate_other_sessions'] = self::yn($config->login_invalidate_other_sessions);
		$v['after_login_url'] = self::plain($config->after_login_url ?? '');
		$v['after_logout_url'] = self::plain($config->after_logout_url ?? '');

		// sns
		$sns = self::detachedConfig(\Zittme\Modules\Member\Social\Base::class);
		$v['sns_login_enable'] = self::yn($sns->sns_login_enable);
		foreach (\Zittme\Modules\Member\Social\Base::$supported_services as $service)
		{
			$v['sns_' . $service . '_enable'] = self::yn($sns->{'sns_' . $service . '_enable'});
			$v[$service . '_client_id'] = self::plain($sns->{$service . '_client_id'});
			$v[$service . '_client_secret'] = self::maskSecret($sns->{$service . '_client_secret'});
		}

		// identity
		$id = self::detachedConfig(\Zittme\Modules\Member\Identity\Base::class);
		$v['identity_enable'] = self::yn($id->identity_enable);
		$v['identity_dup_check'] = self::yn($id->identity_dup_check);
		$v['identity_signup_required'] = self::yn($id->identity_signup_required);
		$v['identity_find_account'] = self::yn($id->identity_find_account);
		$providers = \Zittme\Modules\Member\Identity\Base::$supported_providers;
		$v['identity_provider'] = in_array($id->identity_provider, $providers, true) ? $id->identity_provider : strval($providers[0] ?? '');
		$v['identity_adult_age'] = intval($id->identity_adult_age);
		$mids = array_keys(self::midList());
		$v['identity_adult_mids'] = array_values(array_filter(array_map('strval', $id->identity_adult_mids), function($mid) use($mids) {
			return in_array($mid, $mids, true);
		}));
		$age_groups = [];
		$group_srls = array_map('intval', array_keys(MemberModel::getGroups() ?: []));
		foreach ($id->identity_age_groups as $group_srl => $age)
		{
			if (in_array(intval($group_srl), $group_srls, true))
			{
				$age_groups[] = [intval($group_srl), intval($age)];
			}
		}
		$v['identity_age_groups'] = $age_groups;
		$v['identity_danal_cpid'] = self::plain($id->identity_danal_cpid);
		$v['identity_danal_cppwd'] = self::maskSecret($id->identity_danal_cppwd);
		$v['identity_danal_charset'] = $id->identity_danal_charset === 'EUC-KR' ? 'EUC-KR' : 'UTF-8';
		$v['identity_truora_mode'] = $id->identity_truora_mode === 'mock' ? 'mock' : 'live';
		$v['identity_truora_api_key'] = '';
		$v['identity_truora_flow_id'] = self::plain($id->identity_truora_flow_id);
		$v['identity_truora_countries'] = self::plain($id->identity_truora_countries);
		$v['identity_truora_ttl'] = intval($id->identity_truora_ttl);
		$v['identity_truora_webhook_secret'] = '';
		$v['identity_truora_clear_secrets'] = 'N';
		$v['identity_truora_api_key_saved'] = strval($id->identity_truora_api_key ?? '') !== '' ? 'Y' : 'N';
		$v['identity_truora_webhook_secret_saved'] = strval($id->identity_truora_webhook_secret ?? '') !== '' ? 'Y' : 'N';
		$extra = self::identityExtra();
		$v['identity_extra'] = $extra;
		foreach ($extra as $field)
		{
			$v[$field['name']] = $field['value'];
		}

		// design
		$v['layout_srl'] = intval($config->layout_srl);
		$v['mlayout_srl'] = intval($config->mlayout_srl);
		$v['skin'] = strval($config->skin ?: 'default');
		$v['mskin'] = strval($config->mskin ?: 'default');
		$colorsets = self::colorsetsOf($v['skin']);
		$v['colorset'] = in_array(strval($config->colorset), array_column($colorsets, 'name'), true) ? strval($config->colorset) : '';

		// group screen
		$v['group_image_mark'] = $config->group_image_mark === 'Y' ? 'Y' : 'N';

		return $v;
	}

	/**
	 * Signup form items in their order, with what the screen needs.
	 */
	public static function formItems($config): array
	{
		$join_forms = MemberModel::getJoinFormList() ?: [];
		$items = [];
		foreach ($config->signupForm as $item)
		{
			if ($item->name === 'find_account_question')
			{
				continue;
			}
			$row = [
				'name' => strval($item->name),
				'ext' => empty($item->isDefaultForm),
				'title' => self::plain(Context::replaceUserLang(strval($item->title ?? $item->name), true)),
				'use' => !empty($item->isIdentifier) || !empty($item->mustRequired) || !empty($item->isUse),
				'required' => !empty($item->isIdentifier) || !empty($item->mustRequired) || (!empty($item->isUse) && !empty($item->required)),
				'public' => ($item->isPublic ?? 'Y') === 'Y',
				'lock' => !empty($item->isIdentifier) || !empty($item->mustRequired),
				'identifier' => !empty($item->isIdentifier),
			];
			if (!$row['ext'])
			{
				if (in_array($item->name, self::TITLE_EDIT, true))
				{
					$row['title_raw'] = self::plain($item->title ?? '');
					$row['title_custom'] = !empty($item->isCustomTitle);
				}
				if (!empty($item->imageType))
				{
					$row['image'] = [
						'max_width' => strval($item->max_width ?? ''),
						'max_height' => strval($item->max_height ?? ''),
						'max_filesize' => strval($item->max_filesize ?? ''),
					];
				}
			}
			else
			{
				$srl = intval($item->member_join_form_srl ?? 0);
				$form = $join_forms[$srl] ?? null;
				$options = [];
				$default_value = '';
				if ($form)
				{
					if (is_array($form->options ?? null))
					{
						$options = array_values(array_map('strval', $form->options));
						$default_value = strval($form->default_value ?? '');
					}
					elseif (isset($form->options) && $form->options !== '' && $form->options !== null)
					{
						$decoded = json_decode(strval($form->options), true);
						$options = is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
						$default_value = strval($form->default_value ?? '');
					}
					elseif (is_string($form->default_value ?? null) && preg_match('/^a:\d+:\{i:/', $form->default_value))
					{
						$decoded = unserialize($form->default_value, ['allowed_classes' => false]);
						$options = is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
					}
					else
					{
						$default_value = strval($form->default_value ?? '');
					}
				}
				$row['srl'] = $srl;
				$row['column_name'] = strval($form->column_name ?? $item->name);
				$row['column_type'] = strval($form->column_type ?? ($item->type ?? 'text'));
				$row['column_title'] = self::plain($form->column_title ?? ($item->title ?? ''));
				$row['default_value'] = $default_value;
				$row['options'] = $options;
				$row['description'] = self::plain($form->description ?? ($item->description ?? ''));
				$row['missing'] = !$form;
				$row['title'] = self::plain(Context::replaceUserLang(strval($form->column_title ?? ($item->title ?? $item->name)), true));
			}
			$items[] = $row;
		}
		return $items;
	}

	/**
	 * Lists and choices the screen needs next to the values.
	 */
	public static function meta(): array
	{
		$meta = [];
		$algos = [];
		foreach (self::passwordAlgorithms() as $key => $usable)
		{
			$algos[] = ['value' => $key, 'label' => $usable ?: $key, 'usable' => $usable !== false];
		}
		$meta['password_algos'] = $algos;

		$countries = [];
		$ko = Context::getLangType() === 'ko';
		foreach (\Zittme\Framework\i18n::listCountries($ko ? \Zittme\Framework\i18n::SORT_NAME_KOREAN : \Zittme\Framework\i18n::SORT_NAME_ENGLISH) as $country)
		{
			if ($country->calling_code)
			{
				$countries[] = ['value' => $country->iso_3166_1_alpha3, 'label' => ($ko ? $country->name_korean : $country->name_english) . ' (+' . $country->calling_code . ')'];
			}
		}
		$meta['countries'] = $countries;
		$meta['editor_skins'] = self::editorSkins();

		$meta['layouts'] = self::layoutOptions('P');
		$meta['mlayouts'] = self::layoutOptions('M');
		$meta['skins'] = self::skinOptions('skins');
		$meta['mskins'] = self::skinOptions('m.skins');

		$mids = [];
		foreach (self::midList() as $mid => $info)
		{
			$mids[] = ['value' => $mid, 'label' => html_entity_decode(Context::replaceUserLang(strval($info->browser_title ?? $mid), true), \ENT_QUOTES, 'UTF-8')];
		}
		$meta['mids'] = $mids;
		$meta['providers'] = \Zittme\Modules\Member\Identity\Base::$supported_providers;
		$meta['services'] = \Zittme\Modules\Member\Social\Base::$supported_services;
		$callbacks = [];
		$callback_base = \Zittme\Modules\Member\Social\Drivers\Base::buildCallbackUrl('');
		foreach (\Zittme\Modules\Member\Social\Base::$supported_services as $service)
		{
			$callbacks[$service] = $callback_base . $service;
		}
		$meta['sns_callbacks'] = $callbacks;
		$meta['identity_callback'] = \Zittme\Modules\Member\Identity\Drivers\Base::buildActionUrl('procMemberIdentityCallback');
		$meta['identity_webhook'] = class_exists('Zittme\\Modules\\Member\\Identity\\Drivers\\Truora') ? \Zittme\Modules\Member\Identity\Drivers\Base::buildActionUrl('procMemberIdentityWebhook') : '';

		$meta['lists'] = self::lists();
		$meta['site_url'] = self::memberBaseUrl();
		$meta['extra_types'] = self::EXTRA_TYPES;
		$meta['choice_types'] = self::CHOICE_TYPES;
		return $meta;
	}

	/**
	 * Denied IDs, denied nicknames and managed email hosts.
	 */
	public static function lists(): array
	{
		$config = MemberModel::getMemberConfig();
		$use_user_id = false;
		foreach ($config->signupForm as $item)
		{
			if ($item->name === 'user_id')
			{
				$use_user_id = !empty($item->isUse);
				break;
			}
		}
		$ids = [];
		if ($use_user_id)
		{
			foreach (MemberModel::getDeniedIDs() ?: [] as $row)
			{
				$ids[] = strval($row->user_id);
			}
		}
		$nicks = [];
		foreach (MemberModel::getDeniedNickNames() ?: [] as $row)
		{
			$nicks[] = strval($row->nick_name);
		}
		$hosts = [];
		foreach (MemberModel::getManagedEmailHosts() ?: [] as $row)
		{
			$hosts[] = strval($row->email_host);
		}
		return ['use_user_id' => $use_user_id, 'denied_ids' => $ids, 'denied_nicks' => $nicks, 'email_hosts' => $hosts];
	}

	protected static function memberBaseUrl(): string
	{
		$site_module_info = Context::get('site_module_info');
		$base = (($site_module_info->security ?? '') === 'always' ? 'https://' : 'http://') . ($site_module_info->domain ?? '') . \RX_BASEURL;
		return config('url.rewrite') ? $base : $base . 'index.php?mid=';
	}

	public static function passwordAlgorithms(): array
	{
		$column_info = \Zittme\Framework\DB::getInstance()->getColumnInfo('member', 'password');
		$maxlength = intval($column_info->size ?? 0);
		$algos = \Zittme\Framework\Password::getSupportedAlgorithms();
		if ($maxlength < 128 && isset($algos['argon2id']))
		{
			$algos['argon2id'] = false;
		}
		if ($maxlength < 128 && isset($algos['sha512']))
		{
			$algos['sha512'] = false;
		}
		if ($maxlength < 64 && isset($algos['sha256']))
		{
			$algos['sha256'] = false;
		}
		return $algos;
	}

	/**
	 * Country => calling code, in the order of the old select box.
	 */
	protected static function countryCodes(): array
	{
		$list = [];
		$ko = Context::getLangType() === 'ko';
		foreach (\Zittme\Framework\i18n::listCountries($ko ? \Zittme\Framework\i18n::SORT_NAME_KOREAN : \Zittme\Framework\i18n::SORT_NAME_ENGLISH) as $country)
		{
			if ($country->calling_code)
			{
				$list[strval($country->iso_3166_1_alpha3)] = strval($country->calling_code);
			}
		}
		return $list;
	}

	protected static function editorSkins(): array
	{
		return array_values(array_map('strval', \EditorModel::getEditorSkinList() ?: []));
	}

	protected static function midList(): array
	{
		static $list = null;
		if ($list === null)
		{
			$list = ModuleModel::getMidList(null, ['mid', 'module', 'browser_title']) ?: [];
		}
		return $list;
	}

	protected static function layoutOptions(string $type): array
	{
		$options = [];
		foreach (\LayoutModel::getLayoutList(0, $type) ?: [] as $layout)
		{
			$title = html_entity_decode(Context::replaceUserLang(strval($layout->title ?? ''), true), \ENT_QUOTES, 'UTF-8');
			$options[] = ['value' => intval($layout->layout_srl), 'label' => $title !== '' ? $title : strval($layout->layout)];
		}
		return $options;
	}

	/**
	 * Skins of the member module without writing the default design file
	 * (ModuleModel::getSkins would write it when the default is missing).
	 */
	public static function skinOptions(string $dir): array
	{
		$path = \RX_BASEDIR . 'modules/member';
		$options = [];
		$names = \FileHandler::readDir($path . '/' . $dir . '/') ?: [];
		natcasesort($names);
		foreach ($names as $name)
		{
			if (!is_dir($path . '/' . $dir . '/' . $name))
			{
				continue;
			}
			$info = ModuleModel::loadSkinInfo($path, $name, $dir);
			$options[] = ['value' => $name, 'label' => trim(strval($info->title ?? '')) ?: $name, 'colorsets' => self::colorsetList($info)];
		}
		foreach (\Zittme\Framework\Theme::getModuleSkins('member', $dir) as $name => $info)
		{
			$label = trim(strval($info->title ?? '')) ?: $name;
			if (!empty($info->theme_title))
			{
				$label = $info->theme_title . ' · ' . $label;
			}
			$options[] = ['value' => $name, 'label' => $label, 'colorsets' => self::colorsetList($info)];
		}
		$default = ModuleModel::getModuleDefaultSkin('member', $dir === 'skins' ? 'P' : 'M', 0, false);
		$default_label = '';
		foreach ($options as $option)
		{
			if ($option['value'] === $default)
			{
				$default_label = $option['label'];
			}
		}
		array_unshift($options, ['value' => '/USE_DEFAULT/', 'label' => '', 'default_of' => $default_label ?: strval($default), 'colorsets' => []]);
		if ($dir === 'm.skins')
		{
			array_splice($options, 1, 0, [['value' => '/USE_RESPONSIVE/', 'label' => '', 'colorsets' => []]]);
		}
		return $options;
	}

	protected static function colorsetList($info): array
	{
		$list = [];
		foreach ((array)($info->colorset ?? []) as $colorset)
		{
			if (is_object($colorset) && isset($colorset->name))
			{
				$list[] = ['name' => strval($colorset->name), 'title' => trim(strval($colorset->title ?? '')) ?: strval($colorset->name)];
			}
		}
		return $list;
	}

	public static function colorsetsOf(string $skin): array
	{
		if ($skin === '' || $skin[0] === '/')
		{
			return [];
		}
		$info = ModuleModel::loadSkinInfo(\RX_BASEDIR . 'modules/member', $skin);
		return self::colorsetList($info);
	}

	/**
	 * Check and clean one changed value. Throws on a value the screen could
	 * never send.
	 */
	public static function normalize(string $key, $value, array $current)
	{
		$yn = ['force_mid', 'enable_confirm', 'member_profile_view', 'allow_nickname_change', 'update_nickname_log', 'nickname_spaces',
			'allow_duplicate_nickname', 'signup_dup_button', 'password_hashing_auto_upgrade', 'password_change_invalidate_other_sessions',
			'phone_number_hide_country', 'phone_number_allow_duplicate', 'phone_number_verify_by_sms', 'profile_image_force_ratio',
			'signature_html', 'signature_html_retroact', 'member_allow_fileupload', 'login_invalidate_other_sessions',
			'sns_login_enable', 'identity_enable', 'identity_dup_check', 'identity_signup_required', 'identity_find_account',
			'identity_truora_clear_secrets', 'group_image_mark'];
		$yn = array_merge($yn, self::FEATURE_KEYS);
		foreach (\Zittme\Modules\Member\Social\Base::$supported_services as $service)
		{
			$yn[] = 'sns_' . $service . '_enable';
		}
		if (in_array($key, $yn, true))
		{
			if (!in_array($value, ['Y', 'N'], true))
			{
				throw new InvalidRequest;
			}
			return $value;
		}

		$ints = [
			'authmail_expires' => 1, 'limit_day' => 0, 'max_auth_sms_count' => 0, 'max_auth_sms_count_time' => 0,
			'signature_max_height' => 0, 'member_max_filesize' => 0, 'change_password_date' => 0, 'max_error_count' => 0,
			'max_error_count_time' => 0, 'identity_adult_age' => 1, 'identity_truora_ttl' => 5, 'layout_srl' => -1, 'mlayout_srl' => -2,
		];
		if (isset($ints[$key]))
		{
			if (!is_scalar($value) || !preg_match('/^-?\d{1,9}$/', trim(strval($value))))
			{
				throw new InvalidRequest;
			}
			return max($ints[$key], intval($value));
		}

		$enums = [
			'enable_join' => ['Y', 'N', 'KEY'],
			'authmail_expires_unit' => [86400, 3600, 60, 1],
			'nickname_symbols' => ['Y', 'N', 'LIST'],
			'password_strength' => ['low', 'normal', 'high'],
			'password_hashing_work_factor' => range(4, 16),
			'password_reset_method' => [1, 2],
			'emailhost_check' => ['allowed', 'prohibited'],
			'identity_danal_charset' => ['UTF-8', 'EUC-KR'],
			'identity_truora_mode' => ['live', 'mock'],
		];
		if (isset($enums[$key]))
		{
			foreach ($enums[$key] as $allowed)
			{
				if (strval($allowed) === strval($value))
				{
					return $allowed;
				}
			}
			throw new InvalidRequest;
		}

		switch ($key)
		{
			case 'password_hashing_algorithm':
				$algos = self::passwordAlgorithms();
				if (!is_string($value) || !isset($algos[$value]) || $algos[$value] === false)
				{
					throw new InvalidRequest;
				}
				return $value;
			case 'phone_number_default_country':
				if (!is_string($value) || !isset(self::countryCodes()[$value]))
				{
					throw new InvalidRequest;
				}
				return $value;
			case 'signature_editor_skin':
				if (!is_string($value) || !in_array($value, self::editorSkins(), true))
				{
					throw new InvalidRequest;
				}
				return $value;
			case 'identity_provider':
				if (!is_string($value) || !in_array($value, \Zittme\Modules\Member\Identity\Base::$supported_providers, true))
				{
					throw new InvalidRequest;
				}
				return $value;
			case 'identifiers':
				if (!is_array($value))
				{
					throw new InvalidRequest;
				}
				return array_values(array_filter(['user_id', 'email_address', 'phone_number'], function($item) use($value) {
					return in_array($item, $value, true);
				}));
			case 'identity_adult_mids':
				if (!is_array($value))
				{
					throw new InvalidRequest;
				}
				$mids = array_keys(self::midList());
				return array_values(array_filter(array_map('strval', $value), function($mid) use($mids) {
					return in_array($mid, $mids, true);
				}));
			case 'identity_age_groups':
				if (!is_array($value))
				{
					throw new InvalidRequest;
				}
				$groups = MemberModel::getGroups() ?: [];
				$result = [];
				foreach ($value as $pair)
				{
					if (!is_array($pair) || count($pair) !== 2)
					{
						throw new InvalidRequest;
					}
					$group_srl = intval($pair[0]);
					$age = intval($pair[1]);
					if (!isset($groups[$group_srl]) || $age < 1 || $age > 99)
					{
						throw new InvalidRequest;
					}
					$result[] = [$group_srl, $age];
				}
				return $result;
			case 'skin':
			case 'mskin':
				$options = array_column(self::skinOptions($key === 'skin' ? 'skins' : 'm.skins'), 'value');
				if (!is_string($value) || (!in_array($value, $options, true) && $value !== $current[$key]))
				{
					throw new InvalidRequest;
				}
				return $value;
			case 'colorset':
				return is_string($value) ? preg_replace('/[^a-zA-Z0-9_.-]/', '', $value) : '';
			case 'signup_form':
				return self::normalizeForm($value, $current['signup_form']);
		}

		$extra = self::identityExtraByName();
		if (isset($extra[$key]))
		{
			$field = $extra[$key];
			if ($field['type'] === 'check')
			{
				if (!in_array($value, ['Y', 'N'], true))
				{
					throw new InvalidRequest;
				}
				return $value;
			}
			if ($field['type'] === 'checks')
			{
				$allowed = array_column($field['options'], 0);
				return array_values(array_filter(is_array($value) ? array_map('strval', $value) : [], function($item) use($allowed) {
					return in_array($item, $allowed, true);
				}));
			}
			if ($field['type'] === 'choice' && !in_array(strval($value), array_column($field['options'], 0), true))
			{
				throw new InvalidRequest;
			}
			if (!is_scalar($value))
			{
				throw new InvalidRequest;
			}
			return utf8_clean(strval($value));
		}

		if (preg_match('/^agreement_\d$/', $key))
		{
			if (!is_array($value) || !isset($value['title'], $value['content'], $value['type']) || !in_array($value['type'], ['required', 'optional', 'disabled'], true))
			{
				throw new InvalidRequest;
			}
			return ['title' => strval($value['title']), 'content' => strval($value['content']), 'type' => $value['type']];
		}

		if (!is_scalar($value))
		{
			throw new InvalidRequest;
		}
		return utf8_clean(strval($value));
	}

	/**
	 * The signup form sent by the screen must have the same items as now.
	 */
	protected static function normalizeForm($value, array $current): array
	{
		if (!is_array($value))
		{
			throw new InvalidRequest;
		}
		$by_name = [];
		foreach ($current as $item)
		{
			$by_name[$item['name']] = $item;
		}
		$result = [];
		foreach ($value as $item)
		{
			$name = is_array($item) ? strval($item['name'] ?? '') : '';
			if (!isset($by_name[$name]) || isset($result[$name]))
			{
				throw new InvalidRequest;
			}
			$base = $by_name[$name];
			$row = $base;
			$row['use'] = $base['lock'] ? true : !empty($item['use']);
			$row['required'] = $base['lock'] ? true : ($row['use'] && !empty($item['required']));
			$row['public'] = !empty($item['public']);
			if (isset($base['title_raw']) && isset($item['title_raw']))
			{
				$row['title_raw'] = utf8_clean(strval($item['title_raw']));
			}
			if (isset($base['image']) && isset($item['image']) && is_array($item['image']))
			{
				foreach (['max_width', 'max_height', 'max_filesize'] as $size)
				{
					$size_value = trim(strval($item['image'][$size] ?? ''));
					$row['image'][$size] = preg_match('/^\d{0,9}$/', $size_value) ? $size_value : $base['image'][$size];
				}
			}
			$result[$name] = $row;
		}
		if (count($result) !== count($by_name))
		{
			throw new InvalidRequest;
		}
		return array_values($result);
	}

	/**
	 * Save changed values. Each tab is saved by its old procedure with the
	 * full set of values the old form would have sent.
	 *
	 * @return BaseObject|array previous values of the changed keys
	 */
	public static function save(array $changes)
	{
		$current = self::values();
		$by_tab = [];
		$previous = [];
		foreach ($changes as $key => $value)
		{
			$key = strval($key);
			$tab = self::tabOf($key);
			if ($tab === null || in_array($key, ['identity_truora_api_key_saved', 'identity_truora_webhook_secret_saved'], true))
			{
				throw new InvalidRequest;
			}
			$by_tab[$tab][$key] = self::normalize($key, $value, $current);
			$previous[$key] = $current[$key];
		}

		foreach ($by_tab as $tab => $tab_changes)
		{
			$values = array_merge($current, $tab_changes);
			if ($tab === 'group')
			{
				$args = new \stdClass;
				$args->group_image_mark = $values['group_image_mark'];
				$output = \ModuleController::getInstance()->updateModuleConfig('member', $args);
			}
			else
			{
				$output = self::saveTab($tab, $current, $values);
			}
			self::forgetConfig();
			if ($output instanceof BaseObject && !$output->toBool())
			{
				return $output;
			}
			$current = self::values();
		}

		foreach (['identity_truora_api_key', 'identity_truora_webhook_secret'] as $secret)
		{
			if (isset($previous[$secret]))
			{
				unset($previous[$secret]);
			}
		}
		return $previous;
	}

	/**
	 * Save one tab with its old procedure, but keep every stored value that the
	 * change itself did not alter.
	 *
	 * The old procedure writes every field of its tab, which also stores
	 * defaults for fields that were never saved. The tab is therefore saved once
	 * with the current values and once with the changed values, and only what
	 * differs between the two is applied to the config that was stored before.
	 *
	 * @return BaseObject|null
	 */
	protected static function saveTab(string $tab, array $current, array $values)
	{
		$stored = self::storedConfig();
		$baseline = self::callTab($tab, self::post($tab, $current));
		self::forgetConfig();
		if (($baseline instanceof BaseObject && !$baseline->toBool()) || !$stored)
		{
			return self::callTab($tab, self::post($tab, $values));
		}

		$unchanged = self::storedConfig();
		$output = self::callTab($tab, self::post($tab, $values));
		self::forgetConfig();
		$changed = self::storedConfig();
		$controller = \ModuleController::getInstance();
		if (($output instanceof BaseObject && !$output->toBool()) || !$changed)
		{
			$controller->insertModuleConfig('member', $stored);
			return $output;
		}

		$final = self::mergeChanged($stored, $unchanged, $changed);
		$controller->insertModuleConfig('member', $final);
		return $output;
	}

	/**
	 * The member config as stored, without any cache.
	 */
	protected static function storedConfig()
	{
		$row = executeQuery('module.getModuleConfig', (object)['module' => 'member'])->data;
		$config = isset($row->config) && $row->config ? unserialize($row->config) : null;
		return is_object($config) ? $config : null;
	}

	/**
	 * Take from $changed only what differs from $unchanged, and keep $stored
	 * for everything else. Arrays and objects are compared item by item.
	 */
	protected static function mergeChanged($stored, $unchanged, $changed)
	{
		static $absent = null;
		$absent = $absent ?: new \stdClass;
		return self::mergeItem($stored, $unchanged, $changed, $absent);
	}

	protected static function mergeItem($stored, $unchanged, $changed, $absent)
	{
		if ($unchanged !== $absent && $changed !== $absent && serialize($unchanged) === serialize($changed))
		{
			return $stored;
		}
		$is_object = is_object($stored) && is_object($unchanged) && is_object($changed);
		$is_array = is_array($stored) && is_array($unchanged) && is_array($changed);
		if ($is_object || $is_array)
		{
			$result = $is_object ? clone $stored : $stored;
			$get = function($container, $key) use ($absent, $is_object) {
				if ($is_object)
				{
					return property_exists($container, $key) ? $container->$key : $absent;
				}
				return array_key_exists($key, $container) ? $container[$key] : $absent;
			};
			$keys = array_unique(array_merge(
				array_keys($is_object ? get_object_vars($unchanged) : $unchanged),
				array_keys($is_object ? get_object_vars($changed) : $changed)
			), \SORT_REGULAR);
			foreach ($keys as $key)
			{
				$value = $get($changed, $key);
				if ($value === $absent)
				{
					if ($is_object)
					{
						unset($result->$key);
					}
					else
					{
						unset($result[$key]);
					}
					continue;
				}
				$merged = self::mergeItem($get($stored, $key), $get($unchanged, $key), $value, $absent);
				if ($merged === $absent)
				{
					continue;
				}
				if ($is_object)
				{
					$result->$key = $merged;
				}
				else
				{
					$result[$key] = $merged;
				}
			}
			return $result;
		}
		return $changed;
	}

	/**
	 * Request values the old form of a tab would send.
	 */
	public static function post(string $tab, array $v): array
	{
		$v = self::restoreSecrets($v);
		switch ($tab)
		{
			case 'default':
				$post = [];
				foreach (self::DEFAULT_KEYS as $key)
				{
					$post[$key] = $v[$key];
				}
				$post['force_mid'] = $v['force_mid'] === 'Y' ? 'Y' : null;
				$post['nickname_spaces'] = $v['nickname_spaces'] === 'Y' ? 'Y' : null;
				return $post;

			case 'features':
				$post = [];
				foreach (self::FEATURE_KEYS as $key)
				{
					$post[$key] = $v[$key];
				}
				if ($v['update_nickname_log'] !== 'Y')
				{
					$post['nickname_log'] = null;
				}
				return $post;

			case 'agreements':
				$post = [];
				for ($i = 1; $i <= self::AGREEMENT_COUNT; $i++)
				{
					$agreement = $v['agreement_' . $i];
					$post['agreement_' . $i . '_title'] = $agreement['title'];
					$post['agreement_' . $i . '_content'] = $agreement['content'];
					$post['agreement_' . $i . '_type'] = $agreement['type'];
				}
				return $post;

			case 'signup':
				return self::postSignup($v);

			case 'login':
				return [
					'identifiers' => $v['identifiers'],
					'change_password_date' => $v['change_password_date'],
					'max_error_count' => $v['max_error_count'],
					'max_error_count_time' => $v['max_error_count_time'],
					'login_failure_except_ip' => $v['login_failure_except_ip'],
					'login_invalidate_other_sessions' => $v['login_invalidate_other_sessions'],
					'after_login_url' => $v['after_login_url'],
					'after_logout_url' => $v['after_logout_url'],
				];

			case 'sns':
				$post = [];
				foreach (self::snsKeys() as $key)
				{
					$post[$key] = $v[$key];
				}
				return $post;

			case 'identity':
				$on = [];
				$ages = [];
				foreach (MemberModel::getGroups() ?: [] as $group_srl => $group)
				{
					$ages[intval($group_srl)] = 19;
				}
				foreach ($v['identity_age_groups'] as $pair)
				{
					$on[] = $pair[0];
					$ages[$pair[0]] = $pair[1];
				}
				$post = [
					'identity_enable' => $v['identity_enable'],
					'identity_dup_check' => $v['identity_dup_check'],
					'identity_signup_required' => $v['identity_signup_required'],
					'identity_find_account' => $v['identity_find_account'],
					'identity_provider' => $v['identity_provider'],
					'identity_adult_age' => $v['identity_adult_age'],
					'identity_adult_mids' => $v['identity_adult_mids'],
					'identity_age_group_on' => $on,
					'identity_age_group_age' => $ages,
					'identity_danal_cpid' => $v['identity_danal_cpid'],
					'identity_danal_cppwd' => $v['identity_danal_cppwd'],
					'identity_danal_charset' => $v['identity_danal_charset'],
					'identity_truora_mode' => $v['identity_truora_mode'],
					'identity_truora_api_key' => $v['identity_truora_api_key'],
					'identity_truora_flow_id' => $v['identity_truora_flow_id'],
					'identity_truora_countries' => $v['identity_truora_countries'],
					'identity_truora_ttl' => $v['identity_truora_ttl'],
					'identity_truora_webhook_secret' => $v['identity_truora_webhook_secret'],
					'identity_truora_clear_secrets' => $v['identity_truora_clear_secrets'] === 'Y' ? 'Y' : null,
				];
				return self::identityExtraPost($post, $v);

			case 'design':
				$colorsets = array_column(self::colorsetsOf($v['skin']), 'name');
				return [
					'layout_srl' => $v['layout_srl'],
					'skin' => $v['skin'],
					'colorset' => in_array($v['colorset'], $colorsets, true) ? $v['colorset'] : null,
					'mlayout_srl' => $v['mlayout_srl'],
					'mskin' => $v['mskin'],
				];
		}
		throw new InvalidRequest;
	}

	/**
	 * The signup tab sends its own settings and the whole signup form table.
	 */
	protected static function postSignup(array $v): array
	{
		$post = [
			'limit_day' => $v['limit_day'],
			'limit_day_description' => $v['limit_day_description'],
			'emailhost_check' => $v['emailhost_check'],
			'special_phone_number' => $v['special_phone_number'],
			'special_phone_code' => $v['special_phone_code'],
			'max_auth_sms_count' => $v['max_auth_sms_count'],
			'max_auth_sms_count_time' => $v['max_auth_sms_count_time'],
			'redirect_url' => $v['redirect_url'],
			'phone_number_default_country' => $v['phone_number_default_country'],
			'phone_number_hide_country' => $v['phone_number_hide_country'],
			'phone_number_allow_duplicate' => $v['phone_number_allow_duplicate'],
			'phone_number_verify_by_sms' => $v['phone_number_verify_by_sms'],
			'profile_image_force_ratio' => $v['profile_image_force_ratio'],
			'signature_editor_skin' => $v['signature_editor_skin'],
			'sel_editor_colorset' => null,
			'signature_max_height' => $v['signature_max_height'],
			'signature_html' => $v['signature_html'],
			'signature_html_retroact' => $v['signature_html_retroact'] === 'Y' ? 'Y' : null,
			'member_allow_fileupload' => $v['member_allow_fileupload'],
			'member_max_filesize' => $v['member_max_filesize'],
		];
		foreach (['profile_image', 'image_name', 'image_mark'] as $image)
		{
			foreach (['max_width', 'max_height', 'max_filesize'] as $size)
			{
				$post[$image . '_' . $size] = null;
			}
		}

		$list_order = [];
		$usable = [];
		foreach ($v['signup_form'] as $item)
		{
			$name = $item['name'];
			$list_order[] = $name;
			if ($item['use'])
			{
				$usable[] = $name;
				$post[$name] = $item['required'] ? 'required' : 'option';
			}
			else
			{
				$post[$name] = null;
			}

			$public_key = 'is_' . $name . '_public';
			$post[$public_key] = null;
			if (!in_array($name, self::FIXED_PRIVATE, true) && !in_array($name, self::FIXED_PUBLIC, true) && $item['use'] && $item['public'])
			{
				$post[$public_key] = 'Y';
			}

			if ($item['ext'])
			{
				$post[$name . '_member_join_form_srl'] = $item['srl'];
				continue;
			}
			if (isset($item['title_raw']))
			{
				$post[$name . '_title_edit'] = $item['title_raw'];
			}
			if (isset($item['image']))
			{
				foreach (['max_width', 'max_height', 'max_filesize'] as $size)
				{
					$post[$name . '_' . $size] = $item['image'][$size];
				}
			}
		}
		$post['list_order'] = $list_order;
		$post['usable_list'] = $usable;
		return $post;
	}

	/**
	 * Run the old procedure of a tab with the given request values.
	 */
	public static function callTab(string $tab, array $post)
	{
		$proc = self::TABS[$tab] ?? null;
		if (!$proc)
		{
			throw new InvalidRequest;
		}
		$as_form = function($value) use(&$as_form) {
			if (is_array($value))
			{
				return array_map($as_form, $value);
			}
			return ($value === null || is_string($value)) ? $value : strval($value);
		};
		foreach ($post as $key => $value)
		{
			Context::set($key, $as_form($value), true);
		}
		$controller = getAdminController('member');
		try
		{
			$output = $controller->{$proc}();
		}
		finally
		{
			foreach ($post as $key => $value)
			{
				Context::set($key, null, true);
			}
		}
		return $output;
	}
}
