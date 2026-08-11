<?php

namespace Zittme\Modules\Admin\Controllers;

use Context;
use MenuAdminController;
use MenuAdminModel;
use Zittme\Framework\Cache;
use Zittme\Framework\Exception;
use Zittme\Framework\Storage;
use Zittme\Framework\Exceptions\TargetNotFound;
use Zittme\Modules\Admin\Models\AdminMenu as AdminMenuModel;
use Zittme\Modules\Admin\Models\Favorite as FavoriteModel;
use Zittme\Modules\Admin\Models\Icon as IconModel;

class AdminMenu extends Base
{
	/**
	 * Display Admin Menu configuration page.
	 */
	public function dispAdminSetup()
	{
		$oMenuAdminModel = MenuAdminModel::getInstance();
		$output = $oMenuAdminModel->getMenuByTitle(AdminMenuModel::getAdminMenuName());

		Context::set('menu_srl', $output->menu_srl);
		Context::set('menu_title', $output->title);
		Context::set('admin_logo_url', IconModel::getAdminLogoUrl(false));
		Context::set('admin_logo_dark_url', IconModel::getAdminLogoUrl(true));

		$config = getModel('module')->getModuleConfig('admin');
		Context::set('front_admin_bar', ($config->front_admin_bar ?? 'Y'));
		Context::set('admin_gnb_position', self::getGnbPosition());
		Context::set('admin_logo_text', self::getLogoText());

		$this->setTemplateFile('admin_setup');
	}

	/** 관리자 GNB 를 놓을 수 있는 위치 */
	public const GNB_POSITIONS = ['top', 'left', 'right'];

	/**
	 * Admin GNB position. Falls back to the top bar for missing or invalid values.
	 */
	public static function getGnbPosition(): string
	{
		$config = getModel('module')->getModuleConfig('admin');
		$position = (string)($config->admin_gnb_position ?? 'top');
		return in_array($position, self::GNB_POSITIONS, true) ? $position : 'top';
	}

	/**
	 * 이미지 대신 쓰는 로고 글자. 비어 있으면 기본 로고 이미지를 쓴다.
	 */
	public static function getLogoText(): string
	{
		$config = getModel('module')->getModuleConfig('admin');
		return trim((string)($config->admin_logo_text ?? ''));
	}

	/**
	 * Save the admin GNB position (top / left / right).
	 */
	public function procAdminUpdateGnbPosition()
	{
		// Read raw POST directly: Context::set() overwrites request vars of the
		// same name, and Base::init() sets admin_gnb_position for display before
		// this action runs, clobbering the submitted value.
		$position = (string)($_POST['admin_gnb_position'] ?? '');
		$config = getModel('module')->getModuleConfig('admin') ?: new \stdClass;
		$config->admin_gnb_position = in_array($position, self::GNB_POSITIONS, true) ? $position : 'top';
		getController('module')->insertModuleConfig('admin', $config);

		$this->setMessage('success_updated');
		$this->setRedirectUrl(Context::get('success_return_url') ?:
			getNotEncodedUrl('', 'module', 'admin', 'act', 'dispAdminSetup'));
	}

	/**
	 * Toggle the front admin bar on or off.
	 */
	public function procAdminUpdateFrontBar()
	{
		$config = getModel('module')->getModuleConfig('admin') ?: new \stdClass;
		$config->front_admin_bar = (Context::get('front_admin_bar') === 'Y') ? 'Y' : 'N';
		getController('module')->insertModuleConfig('admin', $config);

		$this->setMessage('success_updated');
		$this->setRedirectUrl(Context::get('success_return_url') ?:
			getNotEncodedUrl('', 'module', 'admin', 'act', 'dispAdminSetup'));
	}

	/**
	 * Save the admin header logo (light / dark).
	 */
	public function procAdminInsertLogo()
	{
		$vars = Context::getRequestVars();

		// 이미지 없이 글자만 쓰는 경우를 위한 값. 이미지가 있으면 이미지가 우선한다.
		// 화면 표시를 위해 Base::init() 이 같은 이름을 Context 에 넣어 두므로
		// getRequestVars() 로는 덮어써진 값이 온다. 제출값은 $_POST 에서 직접 읽는다.
		$config = getModel('module')->getModuleConfig('admin') ?: new \stdClass;
		$config->admin_logo_text = mb_substr(trim(strip_tags((string)($_POST['admin_logo_text'] ?? ''))), 0, 30);
		getController('module')->insertModuleConfig('admin', $config);

		foreach ([false, true] as $dark)
		{
			$field = $dark ? 'admin_logo_dark' : 'admin_logo';

			if (!empty($vars->{'delete_' . $field}) && $vars->{'delete_' . $field} === 'Y')
			{
				IconModel::deleteAdminLogo($dark);
				continue;
			}

			$file = $vars->{$field} ?? null;
			if (is_array($file) && !empty($file['tmp_name']))
			{
				if (!IconModel::saveAdminLogo($file, $dark))
				{
					throw new Exception('msg_invalid_admin_logo');
				}
			}
		}

		$this->setMessage('success_updated');
		$this->setRedirectUrl(Context::get('success_return_url') ?:
			getNotEncodedUrl('', 'module', 'admin', 'act', 'dispAdminSetup'));
	}

	/**
	 * Reset the admin menu to the default configuration.
	 */
	public function procAdminMenuReset()
	{
		$oMenuAdminController = MenuAdminController::getInstance();
		$oMenuAdminModel = MenuAdminModel::getInstance();
		for ($i = 0; $i < 100; $i++)
		{
			$output = $oMenuAdminModel->getMenuByTitle(AdminMenuModel::getAdminMenuName());
			$admin_menu_srl = $output->menu_srl ?? 0;
			if ($admin_menu_srl)
			{
				$output = $oMenuAdminController->deleteMenu($admin_menu_srl);
				if (!$output->toBool())
				{
					return $output;
				}
			}
			else
			{
				break;
			}
		}

		Cache::delete('admin_menu_langs:' . Context::getLangType());
		Storage::deleteDirectory(\RX_BASEDIR . 'files/cache/menu/admin_lang/');

		$this->setRedirectUrl(Context::get('error_return_url'));
	}

	/**
	 * Insert or delete a module as favorite.
	 */
	public function procAdminToggleFavorite()
	{
		// Check if favorite exists.
		$module_name = Context::get('module_name');
		if (!$module_name)
		{
			throw new TargetNotFound();
		}
		$output = FavoriteModel::isFavorite($module_name);
		if(!$output->toBool())
		{
			return $output;
		}

		// Insert or delete.
		if($output->get('result') && $output->get('favoriteSrl'))
		{
			$favorite_srl = $output->get('favoriteSrl');
			$output = FavoriteModel::deleteFavorite($favorite_srl);
			$result = 'off';
		}
		else
		{
			$output = FavoriteModel::insertFavorite($module_name);
			$result = 'on';
		}

		if(!$output->toBool())
		{
			return $output;
		}

		$this->add('result', $result);

		return $this->setRedirectUrl(Context::get('error_return_url'), $output);
	}
}
