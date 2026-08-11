<?php

namespace Zittme\Modules\Admin\Controllers;

use Context;
use Zittme\Framework\Themes\Applier;
use Zittme\Framework\Themes\Installer;

/**
 * Installed theme management: list, detail, and removal.
 * Applying a theme is done per site in the design settings.
 *
 * @see docs/THEME-PACKAGE.md
 */
class Theme extends Base
{
	/**
	 * Installed theme list.
	 */
	public function dispAdminInstalledTheme()
	{
		$themes = [];
		foreach (\Zittme\Framework\Theme::getList() as $name => $info)
		{
			// Usage is queried together to disable the delete button when in use.
			$info->usage = Applier::findUsage($name);
			$themes[$name] = $info;
		}

		Context::set('theme_list', $themes);
		Context::set('theme_count', count($themes));
		$this->setTemplateFile('installed_theme');
	}

	/**
	 * Theme list for the design settings panel (ajax), including components.
	 */
	public function getAdminThemeList()
	{
		$applied = \Zittme\Framework\Theme::getAppliedTheme();

		$themes = [];
		foreach (\Zittme\Framework\Theme::getList() as $name => $info)
		{
			$components = [];
			foreach ($info->components as $component)
			{
				$components[] = [
					'type' => $component->type,
					'name' => $component->name,
					'target' => $component->target,
					'mobile' => $component->mobile,
					'guide' => $component->guide,
				];
			}

			$themes[] = [
				'name' => $name,
				'title' => $info->title,
				'description' => $info->description,
				'version' => $info->version,
				'author' => $info->author[0]->name ?? '',
				'license' => $info->license,
				'supported' => $info->supported,
				'view_mode' => $info->apply->view_mode,
				'components' => $components,
			];
		}

		$this->add('themes', $themes);
		$this->add('applied', $applied->name ?? '');
	}

	/**
	 * Apply a theme from the design settings panel (ajax).
	 * This panel applies globally (site_srl 0), and so does the theme.
	 */
	public function procAdminApplyThemeDesign()
	{
		$name = trim(Context::get('theme_name') ?? '');

		// Nothing to do if no theme was selected.
		if ($name === '')
		{
			return;
		}

		$output = Applier::apply($name, 0);
		if (!$output->ok)
		{
			throw new \Zittme\Framework\Exception($output->message ?: 'msg_theme_not_found');
		}

		$this->add('applied', $output->applied);
	}

	/**
	 * Theme apply screen. The target site must be chosen explicitly
	 * so that other sites on the same installation are not affected.
	 */
	public function dispAdminApplyTheme()
	{
		$name = trim(Context::get('theme_name') ?? '');
		$info = \Zittme\Framework\Theme::getInfo($name);
		if (!$info)
		{
			throw new \Zittme\Framework\Exception('msg_theme_not_found');
		}

		$domain_srl = intval(Context::get('domain_srl'));

		Context::set('theme_name', $name);
		Context::set('theme', $info);
		Context::set('domain_srl', $domain_srl);
		// Domain list for choosing the target site.
		$domains = \ModuleModel::getAllDomains(0, 1);
		Context::set('domain_list', $domains->data ?: []);
		// Show what will change before applying.
		Context::set('changes', Applier::preview($name, $domain_srl));
		$this->setTemplateFile('apply_theme');
	}

	/**
	 * Apply a theme.
	 */
	public function procAdminApplyTheme()
	{
		$name = trim(Context::get('theme_name') ?? '');
		$domain_srl = intval(Context::get('domain_srl'));
		$exclude = (array)(Context::get('exclude') ?: []);

		$output = Applier::apply($name, $domain_srl, $exclude);
		if (!$output->ok)
		{
			throw new \Zittme\Framework\Exception($output->message ?: 'msg_theme_not_found');
		}

		$this->setMessage('success_updated');
		$this->setRedirectUrl(getNotEncodedUrl('', 'module', 'admin',
			'act', 'dispAdminApplyTheme', 'theme_name', $name, 'domain_srl', $domain_srl));
	}

	/**
	 * Revert to the state before the theme was applied.
	 */
	public function procAdminRevertTheme()
	{
		$name = trim(Context::get('theme_name') ?? '');
		$domain_srl = intval(Context::get('domain_srl'));

		$output = Applier::revert($name, $domain_srl);
		if (!$output->ok)
		{
			throw new \Zittme\Framework\Exception($output->message ?: 'msg_theme_no_backup');
		}

		$this->setMessage('success_updated');
		$this->setRedirectUrl(getNotEncodedUrl('', 'module', 'admin',
			'act', 'dispAdminApplyTheme', 'theme_name', $name, 'domain_srl', $domain_srl));
	}

	/**
	 * Remove a theme.
	 */
	public function procAdminDeleteTheme()
	{
		$name = trim(Context::get('theme_name') ?? '');
		$output = Installer::remove($name);
		if (!$output->ok)
		{
			throw new \Zittme\Framework\Exception($output->message ?: 'msg_theme_delete_failed');
		}

		$this->setMessage('success_deleted');
		$this->setRedirectUrl(getNotEncodedUrl('', 'module', 'admin', 'act', 'dispAdminInstalledTheme'));
	}
}
