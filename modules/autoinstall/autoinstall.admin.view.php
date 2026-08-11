<?php

class AutoinstallAdminView extends Autoinstall
{
	/**
	 * initialize
	 *
	 * @return void
	 */
	public function init()
	{
		// Get module configuration
		$config = AutoinstallAdminModel::getAutoInstallAdminModuleConfig();
		$this->config = $config;

		// Update the package list.
		// Refreshed every 4 hours; ?refresh=Y forces an immediate refetch.
		$package_count = Zittme\Modules\Autoinstall\Models\Package::getPackageCount();
		$force_refresh = Context::get('refresh') === 'Y';
		if ($force_refresh || !$package_count || !isset($config->last_update_check) || ($config->last_update_check < time() - 14400))
		{
			$success = Zittme\Modules\Autoinstall\Models\Package::updatePackageList();
			if ($success)
			{
				$config->last_update_check = time();
				ModuleController::getInstance()->insertModuleConfig('autoinstall', $config);
			}
		}

		// zitt.me account link status (shared by store and expert screens)
		Context::set('zittme_account', Zittme\Modules\Autoinstall\Models\Account::get());
		Context::set('zittme_connect_url', Zittme\Modules\Autoinstall\Models\Account::getConnectUrl());

		// Set package types for the view, with appropriate translations.
		$package_types = [];
		foreach (self::$package_types as $type)
		{
			$package_types[$type] = Context::getLang('autoinstall.typename.' . $type);
		}
		Context::set('package_types', $package_types);

		$this->setTemplatePath($this->module_path . 'tpl');
	}

	/**
	 * Display package list
	 *
	 * @return void
	 */
	public function dispAutoinstallAdminIndex()
	{
		$type = trim(Context::get('type') ?? 'featured');
		$page = intval(Context::get('page')) ?: 1;
		$search_keyword = escape(trim(Context::get('search_keyword') ?? ''), false);
		Context::set('type', $type);
		Context::set('page', $page);
		Context::set('search_keyword', $search_keyword);

		// Updates tab: installed packages with a newer release available.
		if ($type === 'updates')
		{
			$output = Zittme\Modules\Autoinstall\Models\Package::searchPackages('all', $search_keyword, 1000, 1);
			$list = [];
			foreach ($output->data ?: [] as $package)
			{
				if ($package->getUpdateInfo()->update_available)
				{
					$list[] = $package;
				}
			}
			Context::set('package_list', $list);
			Context::set('page_navigation', null);
		}
		else
		{
			$output = Zittme\Modules\Autoinstall\Models\Package::searchPackages($type, $search_keyword, 20, $page);
			Context::set('package_list', $output->data);
			Context::set('page_navigation', $output->page_navigation);
		}

		$this->setTemplateFile('index');
	}

	/**
	 * Expert screen. The default view is the portfolio grid, matching zitt.me.
	 */
	public function dispAutoinstallAdminExperts()
	{
		// Tabs: portfolio (default) / expert / project
		$tab = (string)Context::get('tab');
		if ($tab === 'project')
		{
			return $this->dispAutoinstallAdminProjects();
		}
		if ($tab !== 'expert')
		{
			return $this->dispAutoinstallAdminPortfolios();
		}

		$page = max(1, (int)Context::get('page'));
		$field = trim((string)Context::get('field'));
		$keyword = trim((string)Context::get('s'));

		$result = Zittme\Modules\Autoinstall\Models\ExpertHub::getList($page, $field, $keyword);

		Context::set('expert_result', $result);
		Context::set('expert_list', $result->experts ?? []);
		Context::set('expert_fields', $result->fields ?? []);
		Context::set('expert_page', $page);
		Context::set('expert_total_page', (int)($result->total_page ?? 1));
		Context::set('expert_field', $field);
		Context::set('expert_keyword', $keyword);
		Context::set('zittme_account', Zittme\Modules\Autoinstall\Models\Account::get());
		Context::set('zittme_connect_url', Zittme\Modules\Autoinstall\Models\Account::getConnectUrl());

		$this->setTemplateFile('experts');
	}

	/**
	 * Portfolio grid (default expert view).
	 */
	public function dispAutoinstallAdminPortfolios()
	{
		$page = max(1, (int)Context::get('page'));
		$field = trim((string)Context::get('field'));

		$result = Zittme\Modules\Autoinstall\Models\ExpertHub::getPortfolios($page, $field);

		Context::set('expert_result', $result);
		Context::set('portfolio_list', $result->portfolios ?? []);
		Context::set('expert_fields', $result->fields ?? []);
		Context::set('expert_page', $page);
		Context::set('expert_total_page', (int)($result->total_page ?? 1));
		Context::set('expert_field', $field);
		Context::set('zittme_account', Zittme\Modules\Autoinstall\Models\Account::get());
		Context::set('zittme_connect_url', Zittme\Modules\Autoinstall\Models\Account::getConnectUrl());

		$this->setTemplateFile('portfolios');
	}

	/**
	 * Project list. Applying and details are handled on zitt.me.
	 */
	public function dispAutoinstallAdminProjects()
	{
		$page = max(1, (int)Context::get('page'));
		$field = trim((string)Context::get('field'));
		$include_closed = Context::get('closed') === 'Y';

		$result = Zittme\Modules\Autoinstall\Models\ExpertHub::getProjects($page, $field, $include_closed);

		Context::set('expert_result', $result);
		Context::set('project_list', $result->projects ?? []);
		Context::set('expert_fields', $result->fields ?? []);
		Context::set('expert_page', $page);
		Context::set('expert_total_page', (int)($result->total_page ?? 1));
		Context::set('expert_field', $field);
		Context::set('project_closed', $include_closed);
		Context::set('zittme_account', Zittme\Modules\Autoinstall\Models\Account::get());
		Context::set('zittme_connect_url', Zittme\Modules\Autoinstall\Models\Account::getConnectUrl());

		$this->setTemplateFile('projects');
	}

	/**
	 * Project submission form, posted under the linked account.
	 */
	public function dispAutoinstallAdminProjectWrite()
	{
		$account = Zittme\Modules\Autoinstall\Models\Account::get();
		$result = Zittme\Modules\Autoinstall\Models\ExpertHub::getProjects(1, '');

		Context::set('zittme_account', $account);
		Context::set('zittme_connect_url', Zittme\Modules\Autoinstall\Models\Account::getConnectUrl());
		Context::set('expert_fields', $result->fields ?? []);

		// Core editor. Attachments upload here first and transfer on submit.
		$editor_target_srl = getNextSequence();
		$editor_option = new stdClass;
		$editor_option->primary_key_name = 'project_srl';
		$editor_option->content_key_name = 'content';
		// Core file upload does not route in admin screens (no mid), so
		// attachments use the dedicated field below and go straight to zitt.me.
		$editor_option->allow_fileupload = false;
		$editor_option->enable_autosave = false;
		$editor_option->enable_default_component = true;
		$editor_option->enable_component = true;
		$editor_option->disable_html = false;
		$editor_option->height = 360;
		Context::set('editor', EditorModel::getEditor($editor_target_srl, $editor_option));
		Context::set('editor_target_srl', $editor_target_srl);

		$this->setTemplateFile('project_write');
	}

	/**
	 * Portfolio detail with a link to the author profile.
	 */
	public function dispAutoinstallAdminPortfolio()
	{
		$portfolio = Zittme\Modules\Autoinstall\Models\ExpertHub::getPortfolio((int)Context::get('portfolio_srl'));
		if (!$portfolio)
		{
			throw new Zittme\Framework\Exceptions\TargetNotFound;
		}

		Context::set('portfolio', $portfolio);
		Context::set('zittme_account', Zittme\Modules\Autoinstall\Models\Account::get());
		Context::set('zittme_connect_url', Zittme\Modules\Autoinstall\Models\Account::getConnectUrl());

		$this->setTemplateFile('portfolio');
	}

	/**
	 * Expert profile with portfolios.
	 */
	public function dispAutoinstallAdminExpertProfile()
	{
		$profile_srl = (int)Context::get('profile_srl');
		$profile = Zittme\Modules\Autoinstall\Models\ExpertHub::getProfile($profile_srl);
		if (!$profile)
		{
			throw new Zittme\Framework\Exceptions\TargetNotFound;
		}

		Context::set('expert', $profile);
		Context::set('zittme_account', Zittme\Modules\Autoinstall\Models\Account::get());
		Context::set('zittme_connect_url', Zittme\Modules\Autoinstall\Models\Account::getConnectUrl());

		$this->setTemplateFile('expert_profile');
	}

	/**
	 * Return point after zitt.me approval. Exchanges the code for a token
	 * server-to-server. Must be a disp action because entry is a GET link.
	 */
	public function dispAutoinstallAdminConnectCallback()
	{
		$code = (string)Context::get('code');
		$state = (string)Context::get('state');
		$ok = Zittme\Modules\Autoinstall\Models\Account::exchangeCode($code, $state);

		$this->setMessage($ok ? 'success_updated' : 'msg_autoinstall_connect_failed');
		// Return to the screen (store/expert) that started the link
		$this->setRedirectUrl(getNotEncodedUrl('', 'module', 'admin', 'act',
			Zittme\Modules\Autoinstall\Models\Account::getOriginAct()));
	}

	/**
	 * Disconnect the zitt.me account.
	 */
	public function dispAutoinstallAdminDisconnect()
	{
		Zittme\Modules\Autoinstall\Models\Account::clear();

		$this->setMessage('success_updated');
		// Return to the screen that requested the disconnect
		$act = Context::get('from') === 'experts' ? 'dispAutoinstallAdminExperts' : 'dispAutoinstallAdminIndex';
		$this->setRedirectUrl(getNotEncodedUrl('', 'module', 'admin', 'act', $act));
	}

	/**
	 * Redirect to the store/expert site with automatic login. The ticket is
	 * issued server-to-server, so the token is never exposed to the browser.
	 */
	public function dispAutoinstallAdminEnterService()
	{
		$to = Context::get('to') === 'expert' ? 'expert' : 'store';
		$this->setRedirectUrl(Zittme\Modules\Autoinstall\Models\Account::getEnterUrl($to));
	}

	/**
	 * Display package detail
	 *
	 * @return void
	 */
	public function dispAutoinstallAdminPackageDetail()
	{
		$package_srl = intval(Context::get('package_srl'));
		if (!$package_srl)
		{
			throw new Zittme\Framework\Exceptions\InvalidRequest;
		}

		$package = Zittme\Modules\Autoinstall\Models\Package::getPackageDetail($package_srl);
		if (!$package)
		{
			throw new Zittme\Framework\Exceptions\TargetNotFound;
		}

		Context::set('package', $package);
		Context::set('type', $package->type);
		Context::set('update_info', $package->getUpdateInfo());

		$this->setTemplateFile('package_detail');
	}
}
