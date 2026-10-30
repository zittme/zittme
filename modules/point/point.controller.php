<?php
/* Copyright (C) NAVER <http://www.navercorp.com> */
/**
 * @class  pointController
 * @author NAVER (developers@xpressengine.com)
 * @brief Controller class of point modules
 */
class PointController extends Point
{
	/**
	 * Variables for internal reference.
	 */
	protected $_original;

	/**
	 * While above zero, writing and deleting posts, comments and files does not change points.
	 */
	protected static $_trigger_pause = 0;

	/**
	 * @brief Initialization
	 */
	public function init()
	{
	}

	/**
	 * Stop the content triggers from changing points, for content that the site
	 * itself imports (for example a starter kit) rather than a member writing it.
	 *
	 * @return void
	 */
	public static function pauseContentTriggers()
	{
		self::$_trigger_pause++;
	}

	/**
	 * Undo one pauseContentTriggers() call.
	 *
	 * @return void
	 */
	public static function resumeContentTriggers()
	{
		self::$_trigger_pause = max(0, self::$_trigger_pause - 1);
	}

	/**
	 * @brief Membership point application trigger
	 */
	public function triggerInsertMember($obj)
	{
		$member_srl = $obj->member_srl;
		if (!$member_srl)
		{
			return;
		}

		$config = $this->getConfig();
		$diff = intval($config->signup_point);
		if ($diff != 0)
		{
			// New member is not supposed to have points,
			// but we check just in case another module has intervened.
			$cur_point = PointModel::getPoint($member_srl);
			$this->setPoint($member_srl, $cur_point + $diff, 'signup', 'signup');
		}
	}

	/**
	 * @brief A trigger to add points to the member for login
	 */
	public function triggerAfterLogin($obj)
	{
		$member_srl = $obj->member_srl;
		if (!$member_srl)
		{
			return;
		}

		// Points are given only once a day.
		if (substr($obj->last_login, 0, 8) === date('Ymd'))
		{
			return;
		}

		$config = $this->getConfig();
		$diff = intval($config->login_point);
		if ($diff != 0)
		{
			$this->setPoint($member_srl, $diff, 'plus', 'login');
		}
	}

	/**
	 * @brief Member group deletion trigger
	 */
	public function triggerDeleteGroup($obj)
	{
		$group_srl = $obj->group_srl;
		$config = $this->getConfig();

		// Exclude deleted group from point/level/group integration
		if($config->point_group && isset($config->point_group[$group_srl]))
		{
			unset($config->point_group[$group_srl]);
			getController('module')->insertModuleConfig('point', $config);
		}
	}

	/**
	 * @brief A trigger to add points to the member for creating a post
	 */
	public function triggerInsertDocument($obj)
	{
		if (self::$_trigger_pause)
		{
			return;
		}
		$module_srl = $obj->module_srl;
		$member_srl = abs($obj->member_srl);
		if (!$module_srl || !$member_srl)
		{
			return;
		}

		// The fix to disable giving points for saving the document temporarily
		if ($module_srl == $member_srl)
		{
			return;
		}
		if ($obj->status === DocumentModel::getConfigStatus('temp'))
		{
			return;
		}

		$diff = 0;

		// Add points for the document.
		$diff += PointModel::getModulePointConfig($module_srl, 'insert_document');

		// Add points for attached files.
		if ($obj->updated_file_count > 0)
		{
			$upload_point = PointModel::getModulePointConfig($module_srl, 'upload_file');
			$diff += $upload_point * $obj->updated_file_count;
		}

		// Increase the point.
		if ($diff != 0)
		{
			$this->setPoint($member_srl, $diff, 'plus', 'insert_document');
		}

		$this->giveFirstDocumentBonus($member_srl, intval($obj->document_srl));
	}

	/**
	 * Give the one-time bonus for a member's first published document.
	 */
	public function giveFirstDocumentBonus($member_srl, $document_srl)
	{
		$config = $this->getConfig();
		$bonus = intval($config->first_document_point ?? 0);
		if ($bonus <= 0 || !$member_srl || !Zittme\Modules\Point\Models\Config::isLedgerReady())
		{
			return;
		}

		$idem_key = 'point:first_document:' . $member_srl;
		if (Zittme\Modules\Point\Models\Ledger::peekIdemKey($idem_key))
		{
			return;
		}
		Zittme\Modules\Point\Services\PointService::earn($member_srl, $bonus, 'first_document', [
			'idem_key' => $idem_key,
			'ref_type' => 'document',
			'ref_srl' => $document_srl,
			'apply_limit' => false,
		]);
	}

	/**
	 * Take back the first document bonus when that document is deleted, if configured.
	 */
	public function revertFirstDocumentBonus($member_srl, $document_srl)
	{
		$config = $this->getConfig();
		if (($config->first_document_revert ?? 'N') !== 'Y' || !$member_srl || !$document_srl || !Zittme\Modules\Point\Models\Config::isLedgerReady())
		{
			return;
		}

		$row = Zittme\Modules\Point\Models\Ledger::peekIdemKey('point:first_document:' . $member_srl);
		if (!$row || intval($row->ref_srl) !== intval($document_srl) || intval($row->amount) <= 0)
		{
			return;
		}
		Zittme\Modules\Point\Models\Ledger::write([
			'member_srl' => $member_srl,
			'delta' => -intval($row->amount),
			'type' => 'spend',
			'reason_code' => 'first_document_revert',
			'note' => 'first_document_revert',
			'policy' => Zittme\Modules\Point\Models\Ledger::POLICY_CLAMP,
			'idem_key' => 'point:first_document_revert:' . $member_srl,
			'ref_type' => 'document',
			'ref_srl' => $document_srl,
		]);
	}

	/**
	 * Save information about the original document before updating.
	 */
	public function triggerBeforeUpdateDocument($obj)
	{
		if ($obj->document_srl)
		{
			$this->_original = DocumentModel::getDocument($obj->document_srl);
			if (!$this->_original->isExists())
			{
				$this->_original = null;
			}
		}
	}

	/**
	 * @brief The trigger to give points for normal saving the temporarily saved document
	 * Temporary storage at the point in 1.2.3 changed to avoid payment
	 */
	public function triggerAfterUpdateDocument($obj)
	{
		if (self::$_trigger_pause)
		{
			return;
		}
		if ($this->_original)
		{
			$module_srl = $this->_original->get('module_srl');
			$member_srl = abs($this->_original->get('member_srl'));
		}
		else
		{
			$module_srl = $obj->module_srl;
			$member_srl = $obj->member_srl;
		}

		if (!$module_srl || !$member_srl)
		{
			$this->_original = null;
			return;
		}

		$diff = 0;

		// Add points for the document, only if the document is being updated from TEMP to another status such as PUBLIC.
		$published = false;
		if ($obj->status !== DocumentModel::getConfigStatus('temp') && $this->_original->get('status') === DocumentModel::getConfigStatus('temp'))
		{
			$diff += PointModel::getModulePointConfig($module_srl, 'insert_document');
			$published = true;
		}

		// Add points for attached files.
		if ($obj->updated_file_count > 0)
		{
			$upload_point = PointModel::getModulePointConfig($module_srl, 'upload_file');
			$diff += $upload_point * $obj->updated_file_count;
		}

		// Increase the point.
		if ($diff != 0)
		{
			$this->setPoint($member_srl, $diff, 'plus', 'update_document');
		}
		if ($published)
		{
			$this->giveFirstDocumentBonus($member_srl, intval($obj->document_srl ?: $this->_original->document_srl));
		}

		// Remove the reference to the original document.
		$this->_original = null;
	}

	/**
	 * @brief The trigger which deducts the points related to post comments before deleting the post itself
	 */
	public function triggerBeforeDeleteDocument($obj)
	{

	}

	/**
	 * @brief A trigger to deduct points for deleting the post
	 */
	public function triggerDeleteDocument($obj)
	{
		if (self::$_trigger_pause)
		{
			return;
		}
		$module_srl = $obj->module_srl;
		$member_srl = abs($obj->member_srl);
		if (!$module_srl || !$member_srl)
		{
			return;
		}
		if ($obj->isEmptyTrash)
		{
			return;
		}

		if ($obj->status !== DocumentModel::getConfigStatus('temp'))
		{
			$this->revertFirstDocumentBonus($member_srl, intval($obj->document_srl));
		}

		// Return if disabled
		$revert = PointModel::getModulePointConfig($module_srl, 'insert_document_revert_on_delete');
		if ($revert === false)
		{
			return;
		}

		// The fix to disable giving points for saving the document temporarily
		if ($module_srl == $member_srl)
		{
			return;
		}
		if ($obj->status === DocumentModel::getConfigStatus('temp'))
		{
			return;
		}

		// Subtract points for the document.
		$diff = PointModel::getModulePointConfig($module_srl, 'insert_document');

		// Decrease the point.
		if ($diff != 0)
		{
			$this->setPoint($member_srl, $diff, 'minus', 'delete_document');
		}
	}

	/**
	 * @brief A trigger to deduct points when a document is moved to Trash
	 */
	public function triggerTrashDocument($obj)
	{
		return $this->triggerDeleteDocument($obj);
	}

	/**
	 * @brief A trigger which gives points for entering a comment
	 */
	public function triggerInsertComment($obj, $mode = 'insert')
	{
		if (self::$_trigger_pause)
		{
			return;
		}
		$module_srl = $obj->module_srl;
		$member_srl = abs($obj->member_srl);
		if (!$module_srl || !$member_srl)
		{
			return;
		}

		// Abort if the comment and the document have the same author.
		$oDocument = DocumentModel::getDocument($obj->document_srl);
		if (!$oDocument->isExists() || abs($oDocument->get('member_srl')) == $member_srl)
		{
			return;
		}

		// Abort if the document is older than a configured limit.
		$config = $this->getConfig();
		$time_limit = $config->insert_comment_limit ?: $config->no_point_date;
		if ($time_limit > 0 && ztime($oDocument->get('regdate')) < RX_TIME - ($time_limit * 86400))
		{
			return;
		}

		$diff = 0;

		// Add points for the comment.
		if ($mode === 'insert')
		{
			$diff += PointModel::getModulePointConfig($module_srl, 'insert_comment');
		}

		// Add points for attached files.
		if ($obj->updated_file_count > 0)
		{
			$upload_point = PointModel::getModulePointConfig($module_srl, 'upload_file');
			$diff += $upload_point * $obj->updated_file_count;
		}

		// Increase the point.
		if ($diff != 0)
		{
			$this->setPoint($member_srl, $diff, 'plus', 'insert_comment');
		}
	}

	/**
	 * @brief A trigger which gives points for uploaded file changes to a comment
	 */
	public function triggerUpdateComment($obj)
	{
		$this->triggerInsertComment($obj, 'update');
	}

	/**
	 * @brief A trigger which gives points for deleting a comment
	 */
	public function triggerDeleteComment($obj)
	{
		if (self::$_trigger_pause)
		{
			return;
		}
		$module_srl = $obj->module_srl;
		$member_srl = abs($obj->member_srl);
		if (!$module_srl || !$member_srl)
		{
			return;
		}
		if ($obj->isMoveToTrash)
		{
			return;
		}

		// Return if disabled
		$revert = PointModel::getModulePointConfig($module_srl, 'insert_comment_revert_on_delete');
		if ($revert === false)
		{
			return;
		}

		// Abort if the comment and the document have the same author.
		$oDocument = DocumentModel::getDocument($obj->document_srl);
		if (!$oDocument->isExists() || abs($oDocument->get('member_srl')) == $member_srl)
		{
			return;
		}

		// Abort if the document is older than a configured limit.
		$config = $this->getConfig();
		$time_limit = $config->insert_comment_limit ?: $config->no_point_date;
		if ($time_limit > 0 && ztime($oDocument->get('regdate')) < RX_TIME - ($time_limit * 86400))
		{
			return;
		}

		// Calculate points based on the module_srl of the document to which this comment belongs
		$module_srl = $oDocument->get('module_srl');
		$diff = PointModel::getModulePointConfig($module_srl, 'insert_comment');

		// Decrease the point.
		if ($diff != 0)
		{
			$this->setPoint($member_srl, $diff, 'minus', 'delete_comment');
		}
	}

	/**
	 * @brief A trigger to deduct points when a comment is moved to Trash
	 */
	public function triggerTrashComment($obj)
	{
		return $this->triggerDeleteComment($obj);
	}

	/**
	 * @brief Add the file registration trigger
	 * To prevent taking points for invalid file registration this method wlil return a null object
	 */
	public function triggerInsertFile($obj)
	{

	}

	/**
	 * @brief A trigger to give points for deleting a file
	 * Remove points only in case an invalid file is being deleted
	 */
	public function triggerDeleteFile($obj)
	{
		if (self::$_trigger_pause)
		{
			return;
		}
		$module_srl = $obj->module_srl;
		$member_srl = abs($obj->member_srl);
		if (!$module_srl || !$member_srl || $obj->isvalid !== 'Y')
		{
			return;
		}

		// Return if disabled
		$revert = PointModel::getModulePointConfig($module_srl, 'upload_file_revert_on_delete');
		if ($revert === false)
		{
			return;
		}

		// Subtract points for the file.
		$diff = PointModel::getModulePointConfig($module_srl, 'upload_file');

		// Update the point.
		if ($diff != 0)
		{
			$this->setPoint($member_srl, $diff, 'minus', 'delete_file');
		}
	}

	/**
	 * @brief The trigger called before a file is downloaded
	 */
	public function triggerBeforeDownloadFile($obj)
	{
		$logged_info = Context::get('logged_info');
		$logged_member_srl = $logged_info ? $logged_info->member_srl : 0;
		$author_member_srl = abs($obj->member_srl);
		$module_srl = $obj->module_srl;
		if ($logged_member_srl && $logged_member_srl == $author_member_srl)
		{
			return;
		}

		$point = PointModel::getModulePointConfig($module_srl, 'download_file');
		if (!$point)
		{
			return;
		}

		// Get current points.
		$cur_point = $logged_member_srl ? PointModel::getPoint($logged_member_srl) : 0;

		// If the user (member or guest) does not have enough points, deny access.
		$config = $this->getConfig();
		if ($config->disable_download == 'Y' && $cur_point + $point < 0)
		{
			return new BaseObject(-1, 'msg_cannot_download');
		}

		// Points will be adjusted after downloading (triggerDownloadFile).
	}

	/**
	 * @brief The trigger to give or take points for downloading the file
	 */
	public function triggerDownloadFile($obj)
	{
		$logged_info = Context::get('logged_info');
		$logged_member_srl = $logged_info ? $logged_info->member_srl : 0;
		$author_member_srl = abs($obj->member_srl);
		$module_srl = $obj->module_srl;
		if ($logged_member_srl && $logged_member_srl == $author_member_srl)
		{
			return;
		}

		// Adjust points of the downloader.
		if ($logged_member_srl)
		{
			$point = PointModel::getModulePointConfig($module_srl, 'download_file');
			if ($point)
			{
				$this->setPoint($logged_member_srl, $point, 'plus', 'download_file');
			}
		}

		// Adjust points of the uploader.
		if ($author_member_srl)
		{
			$point = PointModel::getModulePointConfig($module_srl, 'download_file_author');
			if ($point)
			{
				$this->setPoint($author_member_srl, $point, 'plus', 'download_file_author');
			}
		}
	}

	/**
	 * @brief Give points for hits increase
	 * Run it even if there are no points
	 */
	public function triggerUpdateReadedCount($obj)
	{
		$logged_info = Context::get('logged_info');
		$logged_member_srl = $logged_info ? $logged_info->member_srl : 0;
		$author_member_srl = abs($obj->get('member_srl'));
		$module_srl = $obj->get('module_srl');
		if ($logged_member_srl && $logged_member_srl == $author_member_srl)
		{
			return;
		}

		// Load configuration for reader and author points.
		$reader_point = PointModel::getModulePointConfig($module_srl, 'read_document');
		$author_point = PointModel::getModulePointConfig($module_srl, 'read_document_author');
		if (!$reader_point && !$author_point)
		{
			return;
		}

		// If the reader has already read this document, do not adjust points again.
		if ($logged_member_srl)
		{
			$args = new stdClass;
			$args->member_srl = $logged_member_srl;
			$args->document_srl = $obj->document_srl;
			$output = executeQuery('document.getDocumentReadedLogInfo', $args);
			if ($output->data->count)
			{
				return;
			}
		}

		// Give no points if the document is older than a configured limit.
		$regdate = ztime($obj->get('regdate'));
		$config = $this->getConfig();
		if ($config->read_document_limit > 0 && $regdate < RX_TIME - ($config->read_document_limit * 86400))
		{
			$reader_point = 0;
		}
		if ($config->read_document_author_limit > 0 && $regdate < RX_TIME - ($config->read_document_author_limit * 86400))
		{
			$author_point = 0;
		}

		// Give no points if the document is a notice and an exception has been configured.
		if ($obj->get('is_notice') === 'Y')
		{
			if ($config->read_document_except_notice)
			{
				$reader_point = 0;
			}
			if ($config->read_document_author_except_notice)
			{
				$author_point = 0;
			}
		}

		// Adjust points of the reader.
		if ($reader_point)
		{
			// Get current points.
			$cur_point = $logged_member_srl ? PointModel::getPoint($logged_member_srl) : 0;

			// If the reader does not have enough points, deny access.
			if ($cur_point + $reader_point < 0 && $config->disable_read_document == 'Y')
			{
				if (!$logged_member_srl && $config->disable_read_document_except_robots == 'Y' && isCrawler())
				{
					// pass
				}
				else
				{
					$message = sprintf(lang('msg_disallow_by_point'), abs($reader_point), $cur_point);
					$obj->add('content', $message);
					$GLOBALS['XE_EXTRA_VARS'][$obj->document_srl] = array();
					return new BaseObject(-1, $message);
				}
			}

			// Record the fact that this member has already read this document.
			if ($logged_member_srl)
			{
				$args = new stdClass();
				$args->member_srl = $logged_member_srl;
				$args->document_srl = $obj->document_srl;
				$output = executeQuery('document.insertDocumentReadedLog', $args);
				$this->setPoint($logged_member_srl, $reader_point, 'plus', 'read_document');
			}
		}

		// Adjust points of the person who wrote the document.
		if ($author_point && $author_member_srl)
		{
			$this->setPoint($author_member_srl, $author_point, 'plus', 'read_document_author');
		}
	}

	/**
	 * @brief Points for voting up or down
	 */
	public function triggerUpdateVotedCount($obj)
	{
		$logged_info = Context::get('logged_info');
		$logged_member_srl = $logged_info ? $logged_info->member_srl : 0;
		$target_member_srl = abs($obj->member_srl);
		if ($logged_member_srl && $logged_member_srl == $target_member_srl)
		{
			return;
		}

		// Document or comment?
		$is_comment = isset($obj->comment_srl) && $obj->comment_srl;

		// Give no points if the document or comment is older than a configured limit.
		$config = $this->getConfig();
		if ($is_comment)
		{
			$regdate = ztime(CommentModel::getComment($obj->comment_srl)->get('regdate'));
			$logged_config_key = ($obj->point > 0) ? 'voter_comment_limit' : 'blamer_comment_limit';
			$target_config_key = ($obj->point > 0) ? 'voted_comment_limit' : 'blamed_comment_limit';
		}
		else
		{
			$regdate = ztime(DocumentModel::getDocument($obj->document_srl)->get('regdate'));
			$logged_config_key = ($obj->point > 0) ? 'voter_limit' : 'blamer_limit';
			$target_config_key = ($obj->point > 0) ? 'voted_limit' : 'blamed_limit';
		}
		$logged_enabled = !($config->$logged_config_key > 0 && $regdate < RX_TIME - ($config->$logged_config_key * 86400));
		$target_enabled = !($config->$target_config_key > 0 && $regdate < RX_TIME - ($config->$target_config_key * 86400));

		// Adjust points of the voter.
		if ($logged_member_srl && $logged_enabled)
		{
			$config_key = ($obj->point > 0) ? ($is_comment ? 'voter_comment' : 'voter') : ($is_comment ? 'blamer_comment' : 'blamer');
			$point = PointModel::getModulePointConfig($obj->module_srl, $config_key);
			if ($point)
			{
				$note = ($obj->point > 0) ? ($is_comment ? 'voter_comment' : 'voter_document') : ($is_comment ? 'blamer_comment' : 'blamer_document');
				if (isset($obj->cancel) && $obj->cancel)
				{
					$point = -1 * $point;
					$note .= '_cancel';
				}
				$this->setPoint($logged_member_srl, $point, 'plus', $note);
			}
		}

		// Adjust points of the person who wrote the document or comment.
		if ($target_member_srl && $target_enabled)
		{
			$config_key = ($obj->point > 0) ? ($is_comment ? 'voted_comment' : 'voted') : ($is_comment ? 'blamed_comment' : 'blamed');
			$point = PointModel::getModulePointConfig($obj->module_srl, $config_key);
			if ($point)
			{
				$note = ($obj->point > 0) ? ($is_comment ? 'voted_comment' : 'voted_document') : ($is_comment ? 'blamed_comment' : 'blamed_document');
				if (isset($obj->cancel) && $obj->cancel)
				{
					$point = -1 * $point;
					$note .= '_cancel';
				}
				$this->setPoint($target_member_srl, $point, 'plus', $note);
			}
		}
	}

	/**
	 * @brief Copy point settings when copying module
	 */
	public function triggerCopyModule($obj)
	{
		$pointConfig = ModuleModel::getModulePartConfig('point', $obj->originModuleSrl);
		if (is_object($pointConfig))
		{
			$pointConfig = get_object_vars($pointConfig);
		}

		$oModuleController = getController('module');
		if(is_array($obj->moduleSrlList))
		{
			foreach($obj->moduleSrlList AS $key=>$moduleSrl)
			{
				$oModuleController->insertModulePartConfig('point', $moduleSrl, $pointConfig);
			}
		}
	}

	/**
	 * Add the "My points" link to the member menu.
	 */
	public function triggerAddMemberMenu($obj)
	{
		if (Context::get('is_logged') && Zittme\Modules\Point\Models\Config::isMyPageEnabled())
		{
			MemberController::addMemberMenu('dispPointMyPoint', 'point.cmd_view_my_point');
		}
	}

	/**
	 * Set or update points for a member.
	 *
	 * Kept for compatibility. New code should use
	 * Zittme\Modules\Point\Services\PointService, which refuses to spend
	 * more than the balance and supports idempotency keys.
	 *
	 * @param int $member_srl
	 * @param int $point
	 * @param string $mode
	 * @param string $note
	 * @return BaseObject
	 */
	public static function setPoint($member_srl, $point, $mode = 'update', $note = '')
	{
		$member_srl = abs($member_srl);
		$point = intval($point);
		if(!in_array($mode, ['add', 'plus', 'minus', 'update', 'signup']))
		{
			$mode = 'update';
		}

		[$reason_code, $memo] = Zittme\Modules\Point\Models\Ledger::splitNote((string)$note);
		$request = [
			'member_srl' => $member_srl,
			'note' => $note,
			'reason_code' => $reason_code,
			'memo' => $memo,
			'policy' => Zittme\Modules\Point\Models\Ledger::POLICY_CLAMP,
			'type' => '',
		];

		switch ($mode)
		{
			case 'add':
			case 'plus':
				$request['delta'] = $point;
				$request['trigger_mode'] = $point < 0 ? 'minus' : 'add';
				$request['apply_limit'] = $note !== 'admin_update';
				break;
			case 'minus':
				$request['delta'] = -$point;
				$request['trigger_mode'] = $point < 0 ? 'add' : 'minus';
				$request['apply_limit'] = $note !== 'admin_update';
				break;
			default:
				$request['target'] = $point;
				$request['trigger_mode'] = $mode;
				break;
		}

		if ($note === 'admin_update')
		{
			$request['type_plus'] = 'admin_add';
			$request['type_minus'] = 'admin_take';
			$logged_info = Context::get('logged_info');
			$request['actor_srl'] = is_object($logged_info) ? intval($logged_info->member_srl) : 0;
		}

		return Zittme\Modules\Point\Models\Ledger::write($request);
	}
}
/* End of file point.controller.php */
/* Location: ./modules/point/point.controller.php */
