<?php

class AutoinstallAdminController extends Autoinstall
{
	/**
	 * 작업 의뢰 등록 — 폼 값을 그대로 zitt.me 에 넘긴다.
	 * 인증은 계정 연결 토큰으로 하며, 토큰은 서버 밖으로 나가지 않는다.
	 */
	public function procAutoinstallAdminCreateProject()
	{
		$vars = Context::getRequestVars();
		$fields = Context::get('fields');

		// 첨부는 이 사이트를 거치지 않고 zitt.me 로 바로 옮긴 뒤 본문 아래에 붙인다
		$attachments = [];
		foreach ((array)($_FILES['attachments']['name'] ?? []) as $i => $name)
		{
			$attachments[] = [
				'name' => $name,
				'tmp_name' => $_FILES['attachments']['tmp_name'][$i] ?? '',
				'error' => $_FILES['attachments']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
			];
		}
		$content = Zittme\Modules\Autoinstall\Models\ExpertHub::transferAttachments(
			(string)($vars->content ?? ''), $attachments
		);

		$result = Zittme\Modules\Autoinstall\Models\ExpertHub::createProject([
			'title' => (string)($vars->title ?? ''),
			'summary' => (string)($vars->summary ?? ''),
			'content' => $content,
			'fields' => is_array($fields) ? implode(',', $fields) : (string)$fields,
			'budget_min' => (string)($vars->budget_min ?? ''),
			'budget_max' => (string)($vars->budget_max ?? ''),
			'deadline' => (string)($vars->deadline ?? ''),
			'client_type' => (string)($vars->client_type ?? 'personal'),
			'contact_type' => (string)($vars->contact_type ?? ''),
			'contact_value' => (string)($vars->contact_value ?? ''),
			'agree_privacy' => (string)($vars->agree_privacy ?? ''),
		]);

		if (empty($result['ok']))
		{
			// 실패 사유는 zitt.me 가 돌려준 코드 그대로 안내한다
			$messages = [
				'not_connected' => 'msg_autoinstall_connect_required',
				'contact_required' => 'msg_project_contact_required',
				'agree_required' => 'msg_project_agree_required',
				'title_required' => 'msg_project_title_required',
			];
			$key = $messages[$result['error'] ?? ''] ?? 'msg_project_create_failed';
			throw new Zittme\Framework\Exception($key);
		}

		$this->setMessage('success_registed');
		$this->setRedirectUrl(getNotEncodedUrl('', 'module', 'admin', 'act', 'dispAutoinstallAdminExperts', 'tab', 'project'));
	}


	/**
	 * zitt.me 스토어 정보 전송 동의 저장. 동의하지 않으면 토큰·도메인을 보내지 않는다.
	 */
	public function procAutoinstallAdminSetConsent()
	{
		$agreed = Context::get('consent') === 'Y';
		Zittme\Modules\Autoinstall\Models\Account::setConsent($agreed);

		$this->setMessage('success_updated');
		$this->setRedirectUrl(Context::get('success_return_url') ?: getNotEncodedUrl('', 'module', 'admin', 'act', 'dispAutoinstallAdminIndex'));
	}

	/**
	 * Download package
	 *
	 * @return void
	 */
	/**
	 * 유료 자료 라이선스 검증 — 설치 직전에 zitt.me 에 이 사이트 도메인으로 다시 묻는다.
	 *
	 * 캐시를 버리고 새로 조회해, 화면이 오래됐어도 지금 상태로 판정한다.
	 * 결과(가려진 키·통과한 도메인·활성화일)를 그대로 돌려줘 사용자가 확인할 수 있게 한다.
	 */
	public function procAutoinstallAdminVerifyLicense()
	{
		$package_srl = intval(Context::get('package_srl'));
		if (!$package_srl)
		{
			return new BaseObject(-1, 'msg_autoinstall_invalid_package_srl');
		}
		if (!Zittme\Modules\Autoinstall\Models\Account::hasConsent())
		{
			return new BaseObject(-1, 'msg_autoinstall_paid_consent_notice');
		}
		Zittme\Framework\Cache::delete(sprintf('autoinstall:package_detail:%d', $package_srl));
		$package = Zittme\Modules\Autoinstall\Models\Package::getPackageDetail($package_srl);
		if (!$package)
		{
			return new BaseObject(-1, 'msg_autoinstall_package_not_found');
		}

		$is_paid = ($package->extra_vars->distribution ?? 'FREE') === 'PAID';
		$licensed = $package->install_type === 'paid_licensed';
		$this->add('paid', $is_paid);
		$this->add('licensed', $licensed);
		$this->add('checked_domains', Zittme\Modules\Autoinstall\Models\Account::getSiteDomains());
		if ($is_paid && !$licensed)
		{
			return new BaseObject(-1, 'msg_autoinstall_license_invalid');
		}
		$license = $package->extra_vars->license_info ?? null;
		$this->add('key_masked', (string)($license->key_masked ?? ''));
		$this->add('domain', (string)($license->domain ?? ''));
		$this->add('active_date', (string)($license->active_date ?? ''));
	}

	public function procAutoinstallAdminDownloadPackage()
	{
		// Validate package status
		$package_srl = intval(Context::get('package_srl'));
		$mode = Context::get('mode') === 'update' ? 'update' : 'install';
		$package = $this->_validatePackageSrl($package_srl, $mode);
		if ($package instanceof BaseObject && !$package->toBool())
		{
			return $package;
		}

		// Download package
		$output = Zittme\Modules\Autoinstall\Models\Installer::downloadPackage($package);
		if (!$output->toBool())
		{
			$output->setMessage(nl2br($output->getMessage()));
			return $output;
		}
	}

	/**
	 * Install package
	 */
	public function procAutoinstallAdminInstallPackage()
	{
		// Validate package status
		$package_srl = intval(Context::get('package_srl'));
		$mode = Context::get('mode') === 'update' ? 'update' : 'install';
		$package = $this->_validatePackageSrl($package_srl, $mode);
		if ($package instanceof BaseObject && !$package->toBool())
		{
			return $package;
		}

		// Suspend session
		Zittme\Framework\Session::close();

		// Install package
		try
		{
			$output = Zittme\Modules\Autoinstall\Models\Installer::installPackage($package, $mode);
			if (!$output->toBool())
			{
				Zittme\Framework\Session::start();
				$output->setMessage(nl2br($output->getMessage()));
				return $output;
			}
		}
		catch (\Throwable $e)
		{
			Zittme\Framework\Session::start();
			return new BaseObject(-1, $e->getMessage());
		}

		// Resume session
		Zittme\Framework\Session::start();
	}

	/**
	 * Post-install cleanup
	 */
	public function procAutoinstallAdminPostInstallPackage()
	{
		// Validate package status
		$package_srl = intval(Context::get('package_srl'));
		$package = $this->_validatePackageSrl($package_srl, 'none');
		if ($package instanceof BaseObject && !$package->toBool())
		{
			return $package;
		}

		// If it's not a module, skip the post-install cleanup
		if ($package->type !== 'module')
		{
			return new BaseObject();
		}

		// Find the module name.
		$module_name = $package->getName();
		if (!$module_name)
		{
			return new BaseObject(-1, 'msg_autoinstall_invalid_module_install_path');
		}

		// Install and update the module.
		try
		{
			Context::set('module_name', $module_name);
			$oInstallAdminController = InstallAdminController::getInstance();
			$oInstallAdminController->procInstallAdminInstall();
			$module_class = ModuleModel::getModuleInstallClass($module_name);
			if ($module_class && method_exists($module_class, 'checkUpdate'))
			{
				if ($module_class->checkUpdate())
				{
					$output = $oInstallAdminController->procInstallAdminUpdate();
					if ($output instanceof BaseObject && !$output->toBool())
					{
						return $output;
					}
				}
			}
		}
		catch (\Throwable $e)
		{
			return new BaseObject(-1, $e->getMessage());
		}
	}

	/**
	 * Uninstall package
	 *
	 * @return void
	 */
	public function procAutoinstallAdminUninstallPackage()
	{
		// Validate package status
		$package_srl = intval(Context::get('package_srl'));
		$package = $this->_validatePackageSrl($package_srl, 'uninstall');
		if ($package instanceof BaseObject && !$package->toBool())
		{
			return $package;
		}

		// Call the uninstall method if it exists.
		if ($package->type === 'module')
		{
			try
			{
				$module_class = ModuleModel::getModuleInstallClass($package->getName());
				if ($module_class && method_exists($module_class, 'moduleUninstall'))
				{
					$module_class->moduleUninstall();
				}
			}
			catch (\Throwable $e)
			{
				return new BaseObject(-1, $e->getMessage());
			}
		}

		// Uninstall package
		$output = Zittme\Modules\Autoinstall\Models\Installer::uninstallPackage($package);
		if (!$output->toBool())
		{
			$output->setMessage(nl2br($output->getMessage()));
			return $output;
		}

		$this->setMessage('msg_autoinstall_success_uninstalled');
	}

	/**
	 * Validate package_srl and installation mode
	 *
	 * @param int $package_srl
	 * @param string $mode
	 * @return Zittme\Modules\Autoinstall\Models\Package|BaseObject
	 */
	protected function _validatePackageSrl(int $package_srl, string $mode): object
	{
		if ($package_srl <= 0)
		{
			return new BaseObject(-1, 'msg_autoinstall_invalid_package_srl');
		}

		$package = Zittme\Modules\Autoinstall\Models\Package::getPackageDetail($package_srl);
		if (!$package)
		{
			return new BaseObject(-1, 'msg_autoinstall_package_not_found');
		}
		if (!$package->isInstallable())
		{
			return new BaseObject(-1, 'msg_autoinstall_package_not_installable');
		}
		if ($mode === 'none')
		{
			return $package;
		}
		if ($mode === 'install' && $package->isInstalled())
		{
			return new BaseObject(-1, 'msg_autoinstall_package_already_installed');
		}
		if ($mode === 'update' && !$package->isInstalled())
		{
			return new BaseObject(-1, 'msg_autoinstall_package_not_installed');
		}
		if ($mode === 'uninstall' && !$package->isInstalled())
		{
			return new BaseObject(-1, 'msg_autoinstall_package_not_installed');
		}

		return $package;
	}
}
