<?php

/**
 * Preserved for backward compatibility
 *
 * @deprecated
 */
class AdminAdminController extends Admin
{
	/**
	 * Initialization
	 */
	public function init()
	{
		if (!$this->user->isAdmin())
		{
			throw new Zittme\Framework\Exceptions\NotPermitted('admin.msg_is_not_administrator');
		}
	}

	/**
	 * Delete the admin logo.
	 *
	 * @deprecated
	 */
	public function procAdminDeleteLogo()
	{
		$config = ModuleModel::getModuleConfig('admin');
		if (!empty($config->adminLogo))
		{
			Zittme\Framework\Storage::delete(RX_BASEDIR . $config->adminLogo);
			unset($config->adminLogo);
		}

		ModuleController::getInstance()->insertModuleConfig('admin', $config);
		$this->setMessage('success_deleted');

		$returnUrl = Context::get('success_return_url') ? Context::get('success_return_url') : getNotEncodedUrl('', 'module', 'admin', 'act', 'dispAdminSetup');
		$this->setRedirectUrl($returnUrl);
	}

	/**
	 * Aliases for backward compatibility.
	 */
	public function procAdminInsertDefaultDesignInfo()
	{
		$vars = Context::getRequestVars();
		Zittme\Modules\Admin\Controllers\Design::getInstance()->updateDefaultDesignInfo($vars);
		$this->setRedirectUrl(Context::get('error_return_url'));
	}

	public function updateDefaultDesignInfo($vars)
	{
		return Zittme\Modules\Admin\Controllers\Design::getInstance()->updateDefaultDesignInfo($vars);
	}

	public function makeDefaultDesignFile($designInfo)
	{
		return Zittme\Modules\Admin\Controllers\Design::getInstance()->makeDefaultDesignFile($designInfo);
	}

	public function procAdminUpdateFTPInfo()
	{
		throw new Zittme\Framework\Exceptions\FeatureDisabled;
	}

	public function procAdminRemoveFTPInfo()
	{
		throw new Zittme\Framework\Exceptions\FeatureDisabled;
	}

	public function procAdminUpdateConfig()
	{
		return new BaseObject;
	}

	public function procAdminRecompileCacheFile()
	{
		return Zittme\Modules\Admin\Controllers\Maintenance\CacheReset::getInstance()->procAdminRecompileCacheFile();
	}

	public function _insertFavorite($site_srl, $module, $type = 'module')
	{
		return Zittme\Modules\Admin\Models\Favorite::insertFavorite($module, $type);
	}

	public function _deleteFavorite($favoriteSrl)
	{
		return Zittme\Modules\Admin\Models\Favorite::deleteFavorite($favoriteSrl);
	}

	public function _deleteAllFavorite()
	{
		return Zittme\Modules\Admin\Models\Favorite::deleteAllFavorites();
	}

	public function cleanFavorite()
	{
		return Zittme\Modules\Admin\Models\Favorite::deleteInvalidFavorites();
	}
}
