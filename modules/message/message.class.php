<?php
/* Copyright (C) NAVER <http://www.navercorp.com> */
/**
 * @class  message
 * @author NAVER (developers@xpressengine.com)
 * @brief high class of message module
 */
class Message extends ModuleObject
{
	/**
	 * @brief Implement if additional tasks are necessary when installing
	 */
	function moduleInstall()
	{
		// New installations use the clean skin. Existing sites keep the skin they had.
		$config = ModuleModel::getModuleConfig('message');
		if (!is_object($config) || empty($config->skin))
		{
			$config = is_object($config) ? $config : new stdClass;
			$config->skin = 'clean';
			$config->mskin = '/USE_RESPONSIVE/';
			ModuleController::getInstance()->insertModuleConfig('message', $config);
		}
	}

	/**
	 * @brief a method to check if successfully installed
	 */
	function checkUpdate()
	{

	}

	/**
	 * @brief Execute update
	 */
	function moduleUpdate()
	{

	}

	/**
	 * @brief Re-generate the cache file
	 */
	function recompileCache()
	{

	}
}
/* End of file message.class.php */
/* Location: ./modules/message/message.class.php */
