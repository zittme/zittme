<?php

namespace Zittme\Modules\Autoinstall\Controllers;

use Context;
use Zittme\Framework\Exception;
use Zittme\Framework\Exceptions\InvalidRequest;
use Zittme\Framework\Exceptions\TargetNotFound;
use Zittme\Framework\Storage;
use Zittme\Modules\Autoinstall\Models\Kit\Exporter;
use Zittme\Modules\Autoinstall\Models\Kit\Job;
use Zittme\Modules\Autoinstall\Models\Kit\KitInstaller;
use Zittme\Modules\Autoinstall\Models\Kit\Manifest;
use Zittme\Modules\Autoinstall\Models\Kit\Remover;
use Zittme\Modules\Autoinstall\Models\Kit\Store;

/**
 * Starter kits: store list, preview, install, removal and export.
 */
class Kit extends \ModuleObject
{
	public function init()
	{
		$this->setTemplatePath($this->module_path . 'tpl');
		Context::set('zittme_account', \Zittme\Modules\Autoinstall\Models\Account::get());
		Context::set('zittme_connect_url', \Zittme\Modules\Autoinstall\Models\Account::getConnectUrl());
	}

	protected function getJob(): Job
	{
		$job = Job::get((int)Context::get('job_srl'));
		if (!$job)
		{
			throw new TargetNotFound;
		}
		return $job;
	}

	public function dispAutoinstallAdminKits()
	{
		Job::ensureTables();
		$refresh = Context::get('refresh') === 'Y';
		$kits = Store::getKits($refresh);
		$jobs = Job::getList();
		$installed = [];
		foreach ($jobs as $job)
		{
			if (in_array($job->status, [Job::STATUS_DONE, Job::STATUS_RUNNING, Job::STATUS_FAILED, Job::STATUS_REMOVING], true))
			{
				$installed[$job->kit_id] = $job;
			}
		}
		Context::set('kit_list', $kits);
		Context::set('kit_jobs', $jobs);
		Context::set('kit_installed', $installed);
		Context::set('kit_upload_allowed', true);
		$this->setTemplateFile('kit_list');
	}

	/**
	 * Download a kit from the store and open a preview job.
	 */
	public function procAutoinstallAdminKitFetch()
	{
		$package_srl = (int)Context::get('package_srl');
		$package = Store::getDetail($package_srl, true);
		if (!$package || $package->type !== 'kit')
		{
			throw new Exception('msg_autoinstall_package_not_found');
		}
		if (($package->extra_vars->distribution ?? 'FREE') === 'PAID' && $package->install_type !== 'paid_licensed')
		{
			throw new Exception('msg_kit_license_required');
		}
		Job::protectWorkBase();
		$zip = Job::getWorkBase() . 'incoming/' . \Zittme\Framework\Security::getRandom(24, 'alnum') . '.zip';
		$error = Store::downloadKit($package, $zip);
		if ($error)
		{
			throw new Exception($error);
		}
		$result = KitInstaller::prepare($zip, ['package_srl' => $package_srl, 'source' => 'store', 'member_srl' => $this->user->member_srl]);
		Storage::delete($zip);
		if (!$result instanceof Job)
		{
			throw new Exception($result);
		}
		$this->add('job_srl', $result->job_srl);
		$this->setRedirectUrl(getNotEncodedUrl('', 'module', 'admin', 'act', 'dispAutoinstallAdminKitPreview', 'job_srl', $result->job_srl));
	}

	public function procAutoinstallAdminKitUpload()
	{
		$file = $_FILES['kit_file'] ?? null;
		if (!$file || !is_uploaded_file($file['tmp_name'] ?? '') || ($file['error'] ?? 1) !== UPLOAD_ERR_OK)
		{
			throw new Exception('msg_kit_upload_failed');
		}
		if (strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'zip')
		{
			throw new Exception('msg_kit_zip_invalid');
		}
		Job::protectWorkBase();
		$zip = Job::getWorkBase() . 'incoming/' . \Zittme\Framework\Security::getRandom(24, 'alnum') . '.zip';
		Storage::createDirectory(dirname($zip));
		if (!Storage::moveUploadedFile($file['tmp_name'], $zip))
		{
			throw new Exception('msg_kit_upload_failed');
		}
		$result = KitInstaller::prepare($zip, ['package_srl' => 0, 'source' => 'upload', 'member_srl' => $this->user->member_srl]);
		Storage::delete($zip);
		if (!$result instanceof Job)
		{
			throw new Exception($result);
		}
		$this->setRedirectUrl(getNotEncodedUrl('', 'module', 'admin', 'act', 'dispAutoinstallAdminKitPreview', 'job_srl', $result->job_srl));
	}

	public function dispAutoinstallAdminKitPreview()
	{
		$job = $this->getJob();
		if ($job->status !== Job::STATUS_READY)
		{
			$this->setRedirectUrl(getNotEncodedUrl('', 'module', 'admin', 'act', 'dispAutoinstallAdminKitJob', 'job_srl', $job->job_srl));
			return;
		}
		if (!$job->hasWorkFiles())
		{
			throw new Exception('msg_kit_work_missing');
		}
		Context::set('kit_job', $job);
		Context::set('kit_preview', KitInstaller::preview($job));
		$this->setTemplateFile('kit_preview');
	}

	public function procAutoinstallAdminKitStart()
	{
		$job = $this->getJob();
		if ($job->status !== Job::STATUS_READY)
		{
			throw new InvalidRequest;
		}
		$preview = KitInstaller::preview($job);
		if ($preview->blockers)
		{
			throw new Exception('msg_kit_blocked');
		}
		$job->options->set_front = Context::get('set_front') === 'Y';
		$job->options->enable_locales = Context::get('enable_locales') === 'Y';
		$job->options->apply_site_skins = Context::get('apply_site_skins') === 'Y';
		$job->status = Job::STATUS_RUNNING;
		$job->save();
		$this->add('job_srl', $job->job_srl);
	}

	public function procAutoinstallAdminKitRun()
	{
		$job = $this->getJob();
		if (!in_array($job->status, [Job::STATUS_RUNNING, Job::STATUS_FAILED], true))
		{
			$this->add('progress', KitInstaller::progress($job));
			return;
		}
		if ($job->status === Job::STATUS_FAILED && Context::get('resume') !== 'Y')
		{
			$this->add('progress', KitInstaller::progress($job));
			return;
		}
		@set_time_limit(60);
		$this->add('progress', KitInstaller::run($job));
	}

	public function dispAutoinstallAdminKitJob()
	{
		$job = $this->getJob();
		Context::set('kit_job', $job);
		Context::set('kit_progress', KitInstaller::progress($job));
		Context::set('kit_counts_remove', Remover::summary($job, false));
		Context::set('kit_counts_rollback', Remover::summary($job, true));
		Context::set('kit_steps', KitInstaller::STEPS);
		$this->setTemplateFile('kit_job');
	}

	public function procAutoinstallAdminKitRemove()
	{
		$job = $this->getJob();
		$full = Context::get('mode') === 'rollback';
		if (in_array($job->status, [Job::STATUS_READY, Job::STATUS_ROLLEDBACK], true) || ($job->status === Job::STATUS_REMOVED && !$full))
		{
			throw new InvalidRequest;
		}
		@set_time_limit(60);
		$this->add('result', Remover::run($job, $full));
	}

	public function procAutoinstallAdminKitDiscard()
	{
		$job = $this->getJob();
		if (!in_array($job->status, [Job::STATUS_READY, Job::STATUS_REMOVED, Job::STATUS_ROLLEDBACK], true) && count($job->getItems()))
		{
			throw new InvalidRequest;
		}
		$job->delete();
		$this->setRedirectUrl(getNotEncodedUrl('', 'module', 'admin', 'act', 'dispAutoinstallAdminKits'));
	}

	public function dispAutoinstallAdminKitExport()
	{
		Context::set('kit_choices', Exporter::getChoices());
		$this->setTemplateFile('kit_export');
	}

	public function procAutoinstallAdminKitExportStart()
	{
		$vars = Context::getRequestVars();
		$id = strtolower(trim((string)($vars->kit_id ?? '')));
		$version = trim((string)($vars->kit_version ?? ''));
		if (!Manifest::isValidId($id))
		{
			throw new Exception('msg_kit_id_rule');
		}
		if (!preg_match('/^\d+\.\d+\.\d+$/', $version))
		{
			throw new Exception('msg_kit_export_invalid');
		}
		$title_ko = trim((string)($vars->title_ko ?? ''));
		$title_en = trim((string)($vars->title_en ?? ''));
		if ($title_ko === '' || $title_en === '')
		{
			throw new Exception('msg_kit_export_invalid');
		}
		$ints = function ($value) {
			return array_values(array_filter(array_map('intval', is_array($value) ? $value : explode(',', (string)$value))));
		};
		$strings = function ($value, $pattern) {
			$out = [];
			foreach (is_array($value) ? $value : explode(',', (string)$value) as $v)
			{
				$v = trim((string)$v);
				if (preg_match($pattern, $v))
				{
					$out[] = $v;
				}
			}
			return $out;
		};
		$options = (object)[
			'id' => $id,
			'version' => $version,
			'title' => (object)['ko' => $title_ko, 'en' => $title_en],
			'description' => (object)['ko' => trim((string)($vars->description_ko ?? '')), 'en' => trim((string)($vars->description_en ?? ''))],
			'author' => trim((string)($vars->author ?? '')) ?: trim(strip_tags((string)Context::getSiteTitle())),
			'module_srls' => $ints($vars->module_srls ?? []),
			'menu_srls' => $ints($vars->menu_srls ?? []),
			'layout_srls' => $ints($vars->layout_srls ?? []),
			'include_documents' => ($vars->include_documents ?? '') === 'Y',
			'include_doc_fields' => ($vars->include_doc_fields ?? '') === 'Y',
			'include_comments' => ($vars->include_comments ?? '') === 'Y',
			'include_media' => ($vars->include_media ?? '') === 'Y',
			'module_data' => $strings($vars->module_data ?? [], '/^[a-z0-9_]+$/'),
			'locales' => $strings($vars->locales ?? [], '/^[a-zA-Z\-]{2,8}$/'),
		];
		if (!$options->module_srls)
		{
			throw new Exception('msg_kit_export_no_modules');
		}
		$exporter = Exporter::start($options);
		$this->add('token', $exporter->getToken());
	}

	public function procAutoinstallAdminKitExportRun()
	{
		@set_time_limit(120);
		$exporter = new Exporter((string)Context::get('token'));
		if (!$exporter->state)
		{
			throw new TargetNotFound;
		}
		$this->add('export', $exporter->run());
	}

	public function dispAutoinstallAdminKitExportDownload()
	{
		$exporter = new Exporter((string)Context::get('token'));
		$path = $exporter->getZipPath();
		if (!$path || !Storage::isFile($path))
		{
			throw new TargetNotFound;
		}
		while (ob_get_level())
		{
			ob_end_clean();
		}
		header('Content-Type: application/zip');
		header('Content-Length: ' . filesize($path));
		header('Content-Disposition: attachment; filename="' . basename($path) . '"');
		header('Cache-Control: private, no-store');
		header('X-Content-Type-Options: nosniff');
		ignore_user_abort(true);
		readfile($path);
		Storage::deleteDirectory($exporter->getDir());
		exit;
	}
}
