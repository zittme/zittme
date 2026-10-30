<?php
/* Copyright (C) NAVER <http://www.navercorp.com> */
/**
 * @class  point
 * @author NAVER (developers@xpressengine.com)
 * @brief The parent class of the point module
 */
class Point extends ModuleObject
{
	/**
	 * Cache for the point module's own configuration.
	 */
	protected static $_config = null;

	/**
	 * Triggers to insert.
	 */
	protected static $_insert_triggers = array(
		array('member.insertMember', 'after', 'controller', 'triggerInsertMember'),
		array('member.doLogin', 'after', 'controller', 'triggerAfterLogin'),
		array('member.deleteGroup', 'after', 'controller', 'triggerDeleteGroup'),
		array('document.insertDocument', 'after', 'controller', 'triggerInsertDocument'),
		array('document.updateDocument', 'before', 'controller', 'triggerBeforeUpdateDocument'),
		array('document.updateDocument', 'after', 'controller', 'triggerAfterUpdateDocument'),
		array('document.deleteDocument', 'after', 'controller', 'triggerDeleteDocument'),
		array('document.moveDocumentToTrash', 'after', 'controller', 'triggerTrashDocument'),
		array('comment.insertComment', 'after', 'controller', 'triggerInsertComment'),
		array('comment.updateComment', 'after', 'controller', 'triggerUpdateComment'),
		array('comment.deleteComment', 'after', 'controller', 'triggerDeleteComment'),
		array('comment.moveCommentToTrash', 'after', 'controller', 'triggerTrashComment'),
		array('file.deleteFile', 'after', 'controller', 'triggerDeleteFile'),
		array('file.downloadFile', 'before', 'controller', 'triggerBeforeDownloadFile'),
		array('file.downloadFile', 'after', 'controller', 'triggerDownloadFile'),
		array('document.updateReadedCount', 'after', 'controller', 'triggerUpdateReadedCount'),
		array('document.updateVotedCount', 'after', 'controller', 'triggerUpdateVotedCount'),
		array('document.updateVotedCountCancel', 'after', 'controller', 'triggerUpdateVotedCount'),
		array('comment.updateVotedCount', 'after', 'controller', 'triggerUpdateVotedCount'),
		array('comment.updateVotedCountCancel', 'after', 'controller', 'triggerUpdateVotedCount'),
		array('module.procModuleAdminCopyModule', 'after', 'controller', 'triggerCopyModule'),
		array('module.dispAdditionSetup', 'after', 'view', 'triggerDispPointAdditionSetup'),
	);

	/**
	 * Triggers to delete.
	 */
	protected static $_delete_triggers = array(
		array('document.updateDocument', 'before', 'controller', 'triggerUpdateDocument'),
		array('document.deleteDocument', 'before', 'controller', 'triggerBeforeDeleteDocument'),
		array('file.insertFile', 'after', 'controller', 'triggerInsertFile'),
	);

	/**
	 * @brief Shortcut to getting module configuration
	 */
	public static function getConfig()
	{
		if (self::$_config === null)
		{
			self::$_config = ModuleModel::getModuleConfig('point');
		}
		return self::$_config;
	}

	/**
	 * Forget the configuration cached by getConfig().
	 */
	public static function resetConfigCache()
	{
		self::$_config = null;
	}

	/**
	 * Tables added by the ledger.
	 */
	protected static $_ledger_tables = array('point_ledger', 'point_daily');

	/**
	 * Check whether the ledger tables still need to be created.
	 *
	 * @return bool
	 */
	public function checkLedgerTables()
	{
		$oDB = DB::getInstance();
		foreach (self::$_ledger_tables as $table_name)
		{
			if (!$oDB->isTableExists($table_name))
			{
				return true;
			}
		}
		return false;
	}

	/**
	 * Create the empty ledger tables.
	 *
	 * Recording does not start here. It starts only when an administrator turns
	 * it on (ledger_ready), so updating the module does not change what is stored.
	 * The schemas live in a subdirectory so that the module is not reported as
	 * needing installation while the tables do not exist yet.
	 *
	 * @return BaseObject
	 */
	public function installLedgerTables()
	{
		$oDB = DB::getInstance();
		foreach (self::$_ledger_tables as $table_name)
		{
			if (!$oDB->isTableExists($table_name))
			{
				$output = $oDB->createTable(\RX_BASEDIR . 'modules/point/schemas/ledger/' . $table_name . '.xml');
				if (!$output->toBool())
				{
					return $output;
				}
			}
		}
		return new BaseObject;
	}

	/**
	 * Start or stop recording point changes in the ledger.
	 *
	 * @param bool $enabled
	 * @return BaseObject
	 */
	public function setLedgerRecording($enabled)
	{
		if ($enabled)
		{
			$output = $this->installLedgerTables();
			if (!$output->toBool())
			{
				return $output;
			}
		}

		$config = ModuleModel::getModuleConfig('point');
		$config = is_object($config) ? $config : new stdClass;
		$value = $enabled ? 'Y' : 'N';
		if (($config->ledger_ready ?? 'N') === $value)
		{
			return new BaseObject;
		}
		$config->ledger_ready = $value;
		$output = getController('module')->insertModuleConfig('point', $config);
		self::$_config = null;
		return $output;
	}

	/**
	 * Show or hide the member menu link to the "My points" page.
	 *
	 * @param bool $enabled
	 * @return void
	 */
	public function syncMemberMenuTrigger($enabled)
	{
		$oModuleModel = getModel('module');
		$oModuleController = getController('module');
		$exists = $oModuleModel->getTrigger('moduleHandler.init', 'point', 'controller', 'triggerAddMemberMenu', 'after');
		if ($enabled && !$exists)
		{
			$oModuleController->insertTrigger('moduleHandler.init', 'point', 'controller', 'triggerAddMemberMenu', 'after');
		}
		elseif (!$enabled && $exists)
		{
			$oModuleController->deleteTrigger('moduleHandler.init', 'point', 'controller', 'triggerAddMemberMenu', 'after');
		}
	}

	/**
	 * Check triggers.
	 *
	 * @return bool
	 */
	public function checkTriggers()
	{
		$oModuleModel = getModel('module');
		foreach (self::$_insert_triggers as $trigger)
		{
			if (!$oModuleModel->getTrigger($trigger[0], $this->module, $trigger[2], $trigger[3], $trigger[1]))
			{
				return true;
			}
		}
		foreach (self::$_delete_triggers as $trigger)
		{
			if ($oModuleModel->getTrigger($trigger[0], $this->module, $trigger[2], $trigger[3], $trigger[1]))
			{
				return true;
			}
		}
		return false;
	}

	/**
	 * Register triggers.
	 *
	 * @return object
	 */
	public function registerTriggers()
	{
		$oModuleModel = getModel('module');
		$oModuleController = getController('module');
		foreach (self::$_insert_triggers as $trigger)
		{
			if (!$oModuleModel->getTrigger($trigger[0], $this->module, $trigger[2], $trigger[3], $trigger[1]))
			{
				$oModuleController->insertTrigger($trigger[0], $this->module, $trigger[2], $trigger[3], $trigger[1]);
			}
		}
		foreach (self::$_delete_triggers as $trigger)
		{
			if ($oModuleModel->getTrigger($trigger[0], $this->module, $trigger[2], $trigger[3], $trigger[1]))
			{
				$oModuleController->deleteTrigger($trigger[0], $this->module, $trigger[2], $trigger[3], $trigger[1]);
			}
		}
	}

	/**
	 * @brief Additional tasks required to accomplish during the installation
	 */
	function moduleInstall()
	{
		// Define default configuration.
		$config = new stdClass;
		$config->able_module = 'N';
		$config->point_name = 'point';
		$config->level_icon = 'default';
		$config->disable_read_document = 'N';
		$config->disable_download = 'N';
		$config->group_reset = 'Y';
		$config->group_ratchet = 'N';
		$config->max_level = 30;
		for ($i = 1; $i <= 30; $i++)
		{
			$config->level_step[$i] = pow($i, 2) * 90;
		}

		// Define default points.
		$config->signup_point = 10;
		$config->login_point = 5;
		$config->insert_document = 10;
		$config->insert_comment = 5;
		$config->upload_file = 5;
		$config->download_file = -5;
		$config->read_document = 0;
		$config->voted = 0;
		$config->blamed = 0;
		$config->voted_comment = 0;
		$config->blamed_comment = 0;
		$config->first_document_point = 0;
		$config->first_document_revert = 'N';

		// Save module config. Point history stays off until an administrator turns it on.
		$this->installLedgerTables();
		$config->ledger_ready = 'N';
		getController('module')->insertModuleConfig('point', $config);

		// Create a directory to store points information.
		FileHandler::makeDir('./files/member_extra_info/point');
	}

	/**
	 * @brief A method to check if the installation has been successful
	 */
	function checkUpdate()
	{
		if ($this->checkLedgerTables())
		{
			return true;
		}
		$config = $this->getConfig();
		if ($config->able_module === 'Y')
		{
			return $this->checkTriggers();
		}
		return false;
	}

	/**
	 * @brief Execute update
	 */
	function moduleUpdate()
	{
		$output = $this->installLedgerTables();
		if (!$output->toBool())
		{
			return $output;
		}

		$config = $this->getConfig();
		if (is_object($config) && ($config->able_module ?? 'N') === 'Y')
		{
			$this->registerTriggers();
			$this->syncMemberMenuTrigger(($config->mypage_enabled ?? 'N') === 'Y');
		}
		return new BaseObject;
	}

	/**
	 * @brief Re-create the cache file
	 */
	function recompileCache()
	{

	}
}
/* End of file point.class.php */
/* Location: ./modules/point/point.class.php */
