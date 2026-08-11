<?php

namespace Zittme\Modules\Admin\Controllers;

use Zittme\Framework\DB;
use Zittme\Modules\Admin\Models\AdminMenu as AdminMenuModel;
use Zittme\Modules\Admin\Models\Favorite as FavoriteModel;

class Install extends Base
{
	/**
	 * Install module
	 *
	 * @return void
	 */
	public function moduleInstall()
	{

	}

	/**
	 * Check if update is necessary
	 *
	 * @return bool
	 */
	public function checkUpdate()
	{
		$oDB = DB::getInstance();
		if (!$oDB->isColumnExists('admin_favorite', 'type'))
		{
			return true;
		}

		// 나중에 생긴 기본 메뉴 항목이 아직 안 붙었는지 확인한다.
		// 관리자 메뉴는 menu_item 테이블에 저장되므로 상수에 더하는 것만으로는
		// 이미 설치된 사이트에 나타나지 않는다.
		if (AdminMenuModel::hasNewAdminMenu())
		{
			return true;
		}

		return false;
	}

	/**
	 * Update module
	 *
	 * @return void
	 */
	public function moduleUpdate()
	{
		$oDB = DB::getInstance();
		if (!$oDB->isColumnExists('admin_favorite', 'type'))
		{
			$output = FavoriteModel::getFavorites();
			$favorites = $output->get('favorites');

			$oDB->dropColumn('admin_favorite', 'admin_favorite_srl');
			$oDB->addColumn('admin_favorite', 'admin_favorite_srl', 'number', null, 0);
			$oDB->addColumn('admin_favorite', 'type', 'varchar', 30, 'module');
			if (is_array($favorites))
			{
				$oAdminAdminController = getAdminController('admin');
				$oAdminAdminController->_deleteAllFavorite();
				foreach($favorites as $value)
				{
					$oAdminAdminController->_insertFavorite(0, $value->module);
				}
			}
		}

		// 나중에 생긴 기본 메뉴 항목을 붙인다.
		AdminMenuModel::addNewAdminMenu();
	}
}
