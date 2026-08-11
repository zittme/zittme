<?php

namespace Zittme\Modules\Admin\Controllers;

use Context;
use DB;
use Zittme\Framework\Config;
use Zittme\Framework\Security;
use Zittme\Framework\Exceptions\NotPermitted;
use Zittme\Modules\Admin\Models\AdminMenu as AdminMenuModel;
use Zittme\Modules\Admin\Models\Favorite as FavoriteModel;
use Zittme\Modules\Admin\Models\Icon as IconModel;

class Base extends \ModuleObject
{
	/**
	 * Initilization
	 *
	 * @return void
	 */
	public function init()
	{
		// Only allow administrators.
		if (!$this->user->isAdmin())
		{
			throw new NotPermitted('admin.msg_is_not_administrator');
		}

		// Set the default URL.
		Context::set('xe_default_url', Context::getDefaultUrl());

		self::setChromeVariables();

		// Set the layout and template path.
		$this->setTemplatePath($this->module_path . 'tpl');
		$this->setLayoutPath($this->getTemplatePath());
		$this->setLayoutFile('layout.html');

		// Check system configuration.
		$this->checkSystemConfiguration();

		// Load the admin menu.
		$this->loadAdminMenu();
	}

	/**
	 * 관리 화면 껍데기(헤더·메뉴 배치)가 쓰는 값.
	 *
	 * 로고는 라이트·다크 두 벌을 함께 넘겨 CSS 로 전환한다. 첫 렌더부터 올바른
	 * 쪽이 그려지도록 하기 위함이다.
	 *
	 * @return void
	 */
	public static function setChromeVariables()
	{
		Context::set('admin_logo_url', IconModel::getAdminLogoUrl(false));
		Context::set('admin_logo_dark_url', IconModel::getAdminLogoUrl(true));
		Context::set('admin_logo_text', AdminMenu::getLogoText());
		Context::set('admin_gnb_position', AdminMenu::getGnbPosition());
	}

	/**
	 * check system configuration.
	 *
	 * @return void
	 */
	public function checkSystemConfiguration()
	{
		$changed = false;

		// Check encryption keys.
		if (config('crypto.encryption_key') === null)
		{
			config('crypto.encryption_key', Security::getRandom(64, 'alnum'));
			$changed = true;
		}
		if (config('crypto.authentication_key') === null)
		{
			config('crypto.authentication_key', Security::getRandom(64, 'alnum'));
			$changed = true;
		}
		if (config('crypto.session_key') === null)
		{
			config('crypto.session_key', Security::getRandom(64, 'alnum'));
			$changed = true;
		}
		if (config('file.folder_structure') === null)
		{
			config('file.folder_structure', 1);
			$changed = true;
		}

		// Save new configuration.
		if ($changed)
		{
			Config::save();
		}
	}

	/**
	 * Load the admin menu.
	 *
	 * @return void
	 */
	public function loadAdminMenu($module = 'admin')
	{
		global $lang;

		// 다른 모듈의 관리 화면(게시판 관리 등)은 이 컨트롤러의 init() 을 거치지 않는다.
		// 헤더가 쓰는 값은 여기서 다시 채워 어느 관리 화면에서나 같은 로고·메뉴 배치가 나오게 한다.
		self::setChromeVariables();

		// Check is_shortcut column
		$oDB = DB::getInstance();
		if (!$oDB->isColumnExists('menu_item', 'is_shortcut'))
		{
			return;
		}

		$lang->menu_gnb_sub = AdminMenuModel::getAdminMenuLang();
		$result = AdminMenuModel::checkAdminMenu();
		include $result->php_file;

		// get current menu's subMenuTitle
		$moduleActionInfo = \ModuleModel::getModuleActionXml($module);
		$moduleMenus = isset($moduleActionInfo->menu) ? (array)$moduleActionInfo->menu : [];
		$currentAct = Context::get('act');
		$subMenuTitle = '';

		foreach($moduleMenus as $value)
		{
			if(is_array($value->acts) && in_array($currentAct, $value->acts))
			{
				$subMenuTitle = $value->title;
				break;
			}
		}
		if (!$subMenuTitle && $currentAct && count($moduleMenus))
		{
			$subMenuTitle = array_first($moduleMenus)->title;
		}
		if (!$subMenuTitle)
		{
			if ($currentAct)
			{
				$moduleInfo = \ModuleModel::getModuleInfoXml($module);
				$subMenuTitle = $moduleInfo->title ?? 'Dashboard';
			}
			else
			{
				$subMenuTitle = 'Dashboard';
			}
		}

		// get current menu's srl(=parentSrl)
		$parentSrl = 0;
		foreach ((array)$menu->list as $parentKey => $parentMenu)
		{
			if (!is_array($parentMenu['list']) || !count($parentMenu['list']))
			{
				continue;
			}
			if ($parentMenu['href'] == '#' && count($parentMenu['list']))
			{
				$firstChild = current($parentMenu['list']);
				$menu->list[$parentKey]['href'] = $firstChild['href'];
			}
			if ($currentAct)
			{
				foreach ($parentMenu['list'] as $childMenu)
				{
					if (preg_match('/\b' . preg_quote($currentAct, '/') . '$/', $childMenu['href']))
					{
						$parentSrl = $childMenu['parent_srl'];
					}
				}
			}
		}

		// Get list of favorite
		$output = FavoriteModel::getFavorites(true);
		Context::set('favorite_list', $output->get('favoriteList'));

		Context::set('subMenuTitle', $subMenuTitle);
		Context::set('gnbUrlList', $menu->list);
		Context::set('parentSrl', $parentSrl);
		Context::set('gnb_title_info', $gnbTitleInfo ?? null);
		Context::addBrowserTitle($subMenuTitle);
	}

	/**
	 * Alias for backward compatibility.
	 *
	 * @deprecated
	 */
	public static function getAdminMenuName()
	{
		return AdminMenuModel::getAdminMenuName();
	}

	/**
	 * Alias for backward compatibility.
	 *
	 * @deprecated
	 */
	public static function getAdminMenuLang()
	{
		return AdminMenuModel::getAdminMenuLang();
	}
}
