<?php

namespace Zittme\Modules\Admin\Models;

use Context;
use FileHandler;
use Zittme\Framework\Config;
use MemberModel;
use MenuAdminModel;
use ModuleModel;

class AdminMenu
{
	public const ADMIN_MENU_NAME = '__ADMINMENU_V17__';

	public const DEFAULT_MENU_STRUCTURE = [
		'dashboard' => [],
		'menu' => [
			'menu.siteMap',
			'menu.siteDesign',
		],
		'user' => [
			'member.userList',
			'member.userSetting',
			'member.userGroup',
			'point.point',
		],
		'content' => [
			'board.board',
			'pagemaker.pagemaker',
			'page.page',
			'document.document',
			'comment.comment',
			'file.file',
			'poll.poll',
			'editor.editor',
			'spamfilter.spamFilter',
			'trash.trash',
		],
		'configuration' => [
			'admin.adminConfigurationGeneral',
			'admin.adminMenuSetup',
			'module.filebox',
		],
		'advanced' => [
			// Store (autoinstall.easyInstall) moved to a dedicated header button.
			// Themes are listed above layouts.
			'admin.installedTheme',
			'layout.installedLayout',
			'module.installedModule',
			'addon.installedAddon',
			'widget.installedWidget',
			'module.multilingual',
			'importer.importer',
			'rss.rss',
		],
	];

	public static function getAdminMenuName()
	{
		return self::ADMIN_MENU_NAME;
	}

	public static function getAdminMenuLang()
	{
		static $lang = null;

		if ($lang === null)
		{
			$lang = \Zittme\Framework\Cache::get('admin_menu_langs:' . Context::getLangType());
		}

		if ($lang === null || !is_array($lang))
		{
			$lang = [];
			$installed_module_list = ModuleModel::getModulesXmlInfo();
			foreach ($installed_module_list as $value)
			{
				$moduleActionInfo = ModuleModel::getModuleActionXml($value->module);
				if (isset($moduleActionInfo->menu) && is_object($moduleActionInfo->menu))
				{
					foreach ($moduleActionInfo->menu as $key2 => $value2)
					{
						$lang[$key2] = $value2->title;
					}
				}
			}

			\Zittme\Framework\Cache::set('admin_menu_langs:' . Context::getLangType(), $lang, 0, true);
		}

		return $lang;
	}

	public static function checkAdminMenu()
	{
		if (!Context::isInstalled())
		{
			return;
		}

		$oMenuAdminModel = MenuAdminModel::getInstance();
		$output = $oMenuAdminModel->getMenuByTitle(self::ADMIN_MENU_NAME);

		if (!$output->menu_srl)
		{
			self::createXeAdminMenu();
			$output = $oMenuAdminModel->getMenuByTitle(self::ADMIN_MENU_NAME);
		}
		else
		{
			if (!is_readable(FileHandler::getRealPath($output->php_file)))
			{
				$oMenuAdminController = getAdminController('menu');
				$oMenuAdminController->makeXmlFile($output->menu_srl);
			}
			Context::set('admin_menu_srl', $output->menu_srl);
		}

		self::_deleteOldAdminMenu();
		self::_deleteRetiredAdminMenu();

		$returnObj = new \stdClass;
		$returnObj->menu_srl = $output->menu_srl;
		$returnObj->php_file = FileHandler::getRealPath($output->php_file);

		return $returnObj;
	}

	/**
	 * Regenerate xe admin default menu
	 * @return void
	 */
	public static function createXeAdminMenu()
	{
		//insert menu
		$args = new \stdClass;
		$args->title = self::ADMIN_MENU_NAME;
		$menu_srl = $args->menu_srl = getNextSequence();
		$args->listorder = $args->menu_srl * -1;
		$output = executeQuery('menu.insertMenu', $args);
		Context::set('admin_menu_srl', $menu_srl);
		unset($args);

		// gnb item create
		foreach (array_keys(self::DEFAULT_MENU_STRUCTURE) as $value)
		{
			//insert menu item
			$args = new \stdClass;
			$args->menu_srl = $menu_srl;
			$args->menu_item_srl = getNextSequence();
			$args->name = '{$lang->menu_gnb[\'' . $value . '\']}';
			if($value == 'dashboard')
			{
				$args->url = getUrl(['module' => 'admin']);
			}
			else
			{
				$args->url = '#';
			}
			$args->listorder = -1 * $args->menu_item_srl;
			$output = executeQuery('menu.insertMenuItem', $args);
		}

		$oMenuAdminModel = getAdminModel('menu');
		$output = $oMenuAdminModel->getMenuItems($menu_srl, 0, ['menu_item_srl', 'name']);
		if (is_array($output->data))
		{
			foreach ($output->data as $value)
			{
				preg_match('/\{\$lang->menu_gnb\[(.*?)\]\}/i', $value->name, $m);
				$gnbDBList[$m[1]] = $value->menu_item_srl;
			}
		}

		$output = MemberModel::getAdminGroup(['group_srl']);
		$admin_group_srl = $output->group_srl;

		// gnb common argument setting
		$args = new \stdClass;
		$args->menu_srl = $menu_srl;
		$args->open_window = 'N';
		$args->expand = 'N';
		$args->normal_btn = '';
		$args->hover_btn = '';
		$args->active_btn = '';
		$args->group_srls = $admin_group_srl;

		$moduleActionInfo = array();
		foreach (self::DEFAULT_MENU_STRUCTURE as $key => $items)
		{
			if (!$items)
			{
				continue;
			}

			foreach ($items as $item)
			{
				list($module_name, $menu_name) = explode('.', $item);
				if (!isset($moduleActionInfo[$module_name]))
				{
					$moduleActionInfo[$module_name] = ModuleModel::getModuleActionXml($module_name);
				}

				$args->menu_item_srl = getNextSequence();
				$args->parent_srl = $gnbDBList["'" . $key . "'"];
				$args->name = '{$lang->menu_gnb_sub[\'' . $menu_name . '\']}';
				$args->url = getUrl('', 'module', 'admin', 'act', $moduleActionInfo[$module_name]->menu->{$menu_name}->index);
				$args->listorder = -1 * $args->menu_item_srl;
				$output = executeQuery('menu.insertMenuItem', $args);
			}
		}

		$oMenuAdminConroller = getAdminController('menu');
		$oMenuAdminConroller->makeXmlFile($menu_srl);

		// does not recreate lang cache sometimes
		FileHandler::RemoveFilesInDir('./files/cache/lang');
		FileHandler::RemoveFilesInDir('./files/cache/menu/admin_lang');
	}

	/**
	 * Return parent old menu key by child menu
	 *
	 * @return string
	 */
	protected static function _getOldGnbKey($menuName)
	{
		switch($menuName)
		{
			case 'siteMap':
				return 'menu';
				break;
			case 'userList':
			case 'userSetting':
			case 'userGroup':
			case 'point':
				return 'user';
				break;
			case 'document':
			case 'comment':
			case 'file':
			case 'poll':
			case 'rss':
			case 'multilingual':
			case 'importer':
			case 'trash':
				return 'content';
				break;
			case 'easyInstall':
			case 'installedTheme':
			case 'installedLayout':
			case 'installedModule':
			case 'installedWidget':
			case 'installedAddon':
			case 'editor':
			case 'spamFilter':
				return 'extensions';
				break;
			case 'adminConfigurationGeneral':
			case 'adminConfigurationFtp':
			case 'adminMenuSetup':
			case 'fileUpload':
			case 'filebox':
				return 'configuration';
				break;
			default:
				return 'user_added_menu';
		}
	}

	/**
	 * Delete old admin menu
	 */
	/**
	 * Menu items that used to be created by default and no longer should be.
	 *
	 * Removing an entry from DEFAULT_MENU_STRUCTURE only stops it being created on
	 * a fresh install; existing sites keep the row in menu_item. Listing the key
	 * here removes it once from sites that already have it.
	 *
	 * Keyed by the menu_gnb_sub key, valued by why it was retired.
	 */
	const RETIRED_MENU_ITEMS = [
		// Moved to the store button in the header
		'easyInstall',
	];

	/**
	 * Remove retired default menu items, once per item.
	 *
	 * Deliberately not repeated on every request: an administrator is free to add
	 * any of these back through the admin screen settings, and re-deleting it on the next page
	 * load would fight them.
	 *
	 * @return void
	 */
	protected static function _deleteRetiredAdminMenu()
	{
		$done = (array)(Config::get('admin.retired_menu_items') ?: []);
		$todo = array_diff(self::RETIRED_MENU_ITEMS, $done);
		if (!count($todo))
		{
			return;
		}

		$oMenuAdminModel = getAdminModel('menu');
		$output = $oMenuAdminModel->getMenuByTitle(self::ADMIN_MENU_NAME);
		$menu_srl = $output->menu_srl ?? 0;
		if (!$menu_srl)
		{
			return;
		}

		$items = $oMenuAdminModel->getMenuItems($menu_srl);
		$deleted = false;
		if (is_array($items->data))
		{
			foreach ($items->data as $item)
			{
				preg_match('/menu_gnb_sub\[\'([^\']+)\'\]/', $item->name, $m);
				if (!isset($m[1]) || !in_array($m[1], $todo, true))
				{
					continue;
				}
				$args = new \stdClass;
				$args->menu_item_srl = $item->menu_item_srl;
				executeQuery('menu.deleteMenuItem', $args);
				$deleted = true;
			}
		}

		Config::set('admin.retired_menu_items', array_values(array_unique(array_merge($done, self::RETIRED_MENU_ITEMS))));
		Config::save();

		if ($deleted)
		{
			$oMenuAdminController = getAdminController('menu');
			$oMenuAdminController->makeXmlFile($menu_srl);
			FileHandler::RemoveFilesInDir('./files/cache/menu/admin_lang');
		}
	}

	/**
	 * Default menu items added after initial release.
	 *
	 * 'module.menuName' => parent group key
	 */
	/**
	 * 'module.menuName' => sibling key to insert directly above.
	 *
	 * Siblings are used instead of group keys because group names differ by
	 * installation age ('advanced' vs 'extensions', see _getOldGnbKey), while
	 * the sibling item exists in either case.
	 */
	const ADDED_MENU_ITEMS = [
		'admin.installedTheme' => 'installedLayout',
		// Insert pagemaker right below board (= above page).
		'pagemaker.pagemaker' => 'page',
	];

	/**
	 * Insert late-added default menu items, once. The admin menu is stored in
	 * the menu_item table, so adding to the constant alone does not affect
	 * existing installations. Runs only on module update so that items the
	 * admin deliberately removed do not keep coming back.
	 *
	 * @return void
	 */
	/**
	 * Check whether any late-added default menu items are still missing.
	 * Used to decide whether to show the module update notice.
	 *
	 * @return bool
	 */
	public static function hasNewAdminMenu(): bool
	{
		if (!Context::isInstalled())
		{
			return false;
		}

		$done = (array)(Config::get('admin.added_menu_items') ?: []);
		return count(array_diff(array_keys(self::ADDED_MENU_ITEMS), $done)) > 0;
	}

	public static function addNewAdminMenu()
	{
		$done = (array)(Config::get('admin.added_menu_items') ?: []);
		$todo = array_diff(array_keys(self::ADDED_MENU_ITEMS), $done);
		if (!count($todo))
		{
			return;
		}

		$oMenuAdminModel = getAdminModel('menu');
		$output = $oMenuAdminModel->getMenuByTitle(self::ADMIN_MENU_NAME);
		$menu_srl = $output->menu_srl ?? 0;
		if (!$menu_srl)
		{
			return;
		}

		$items = $oMenuAdminModel->getMenuItems($menu_srl);
		if (!is_array($items->data))
		{
			return;
		}

		// Find the sibling among existing child items.
		$siblings = [];
		$existing = [];
		foreach ($items->data as $item)
		{
			if (preg_match('/menu_gnb_sub\[\'([^\']+)\'\]/', $item->name, $m))
			{
				$siblings[$m[1]] = $item;
				$existing[] = $m[1];
			}
		}

		$added = [];
		foreach ($todo as $key)
		{
			list($module_name, $menu_name) = explode('.', $key);
			$sibling_key = self::ADDED_MENU_ITEMS[$key];

			// Already present: mark as handled so it is not retried.
			if (in_array($menu_name, $existing, true))
			{
				$added[] = $key;
				continue;
			}

			// Sibling not found: do not mark as handled, so it can be retried later.
			if (empty($siblings[$sibling_key]))
			{
				continue;
			}

			$action_info = ModuleModel::getModuleActionXml($module_name);
			$index_act = $action_info->menu->{$menu_name}->index ?? '';
			if (!$index_act)
			{
				continue;
			}

			$sibling = $siblings[$sibling_key];
			$args = new \stdClass;
			$args->menu_srl = $menu_srl;
			$args->menu_item_srl = getNextSequence();
			$args->parent_srl = $sibling->parent_srl;
			$args->name = '{$lang->menu_gnb_sub[\'' . $menu_name . '\']}';
			$args->url = getUrl('', 'module', 'admin', 'act', $index_act);
			// Higher listorder sorts first; one above the sibling places it directly on top.
			$args->listorder = intval($sibling->listorder) + 1;
			if (executeQuery('menu.insertMenuItem', $args)->toBool())
			{
				$added[] = $key;
			}
		}

		if (!count($added))
		{
			return;
		}

		Config::set('admin.added_menu_items', array_values(array_unique(array_merge($done, $added))));
		Config::save();

		if ($added)
		{
			$oMenuAdminController = getAdminController('menu');
			$oMenuAdminController->makeXmlFile($menu_srl);
			FileHandler::RemoveFilesInDir('./files/cache/menu/admin_lang');
		}
	}

	protected static function _deleteOldAdminMenu()
	{
		$oMenuAdminModel = getAdminModel('menu');

		$output = $oMenuAdminModel->getMenuByTitle(self::ADMIN_MENU_NAME);
		$newAdminmenuSrl = $output->menu_srl;
		$output = $oMenuAdminModel->getMenuItems($newAdminmenuSrl, 0);
		$newAdminParentMenuList = array();
		if (is_array($output->data))
		{
			foreach ($output->data as $value)
			{
				$tmp = explode('\'', $value->name);
				$newAdminParentMenuList[$tmp[1]] = $value;
			}
		}
		unset($output);

		// old admin menu
		$output = $oMenuAdminModel->getMenuByTitle('__XE_ADMIN__');
		$menu_srl = $output->menu_srl ?? 0;

		$oMenuAdminController = getAdminController('menu');
		if ($menu_srl)
		{
			$output = $oMenuAdminModel->getMenuItems($menu_srl);
			if (is_array($output->data))
			{
				$parentMenu = array();
				foreach ($output->data as $menu_item)
				{
					if ($menu_item->parent_srl == 0)
					{
						$tmp = explode('\'', $menu_item->name);
						$parentMenuKey = $tmp[1];
						$parentMenu[$menu_item->menu_item_srl] = $parentMenuKey;
					}
				}

				$isUserAddedMenuMoved = FALSE;
				foreach ($output->data as $menu_item)
				{
					if ($menu_item->parent_srl != 0)
					{
						$tmp = explode('\'', $menu_item->name);
						$menuKey = $tmp[1];

						$result = self::_getOldGnbKey($menuKey);
						if ($result === 'user_added_menu')
						{
							if ($parentMenu[$menu_item->parent_srl] == 'extensions')
							{
								$newParentItem = $newAdminParentMenuList['advanced'];
							}
							else
							{
								$newParentItem = $newAdminParentMenuList[$parentMenu[$menu_item->parent_srl]];
							}
							$menu_item->menu_srl = $newParentItem->menu_srl;
							$menu_item->parent_srl = $newParentItem->menu_item_srl;

							$output = executeQuery('menu.updateMenuItem', $menu_item);
							$isUserAddedMenuMoved = true;
						}
					}
				}

				if ($isUserAddedMenuMoved)
				{
					$oMenuAdminController->makeXmlFile($newAdminmenuSrl);
				}
			}
		}

		// all old admin menu delete
		$output = $oMenuAdminModel->getMenuListByTitle('__XE_ADMIN__');
		if (is_array($output))
		{
			foreach ($output as $value)
			{
				$oMenuAdminController->deleteMenu($value->menu_srl);
			}
		}
	}
}
