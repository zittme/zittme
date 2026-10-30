<?php
/* Copyright (C) NAVER <http://www.navercorp.com> */
/**
 * @class  messageAdminController
 * @author NAVER (developers@xpressengine.com)
 * @brief admin controller class of message module
 */
class MessageAdminController extends Message
{
	/**
	 * @brief Initialization
	 */
	function init()
	{
	}

	/**
	 * @brief Configuration
	 */
	function procMessageAdminInsertConfig()
	{
		// Get information
		$args = Context::gets('skin', 'mskin', 'colorset', 'mcolorset');
		$vars = Context::gets('accent_color', 'show_logo', 'logo_url', 'contact_url', 'maintenance_message', 'show_powered');
		$args->accent_color = MessageView::normalizeColor(strval($vars->accent_color ?? ''));
		$args->show_logo = ($vars->show_logo ?? 'Y') === 'N' ? 'N' : 'Y';
		$args->logo_url = MessageView::sanitizeLink(strval($vars->logo_url ?? ''), false);
		$args->contact_url = MessageView::sanitizeLink(strval($vars->contact_url ?? ''));
		$args->maintenance_message = mb_substr(trim(strip_tags(strval($vars->maintenance_message ?? ''))), 0, 1000);
		$args->show_powered = ($vars->show_powered ?? 'N') === 'Y' ? 'Y' : 'N';
		// Create a module Controller object
		$oModuleController = getController('module');
		$output = $oModuleController->insertModuleConfig('message',$args);
		if(!$output->toBool()) return $output;

		$this->setMessage('success_updated');

		$returnUrl = Context::get('success_return_url') ? Context::get('success_return_url') : getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMessageAdminConfig');
		$this->setRedirectUrl($returnUrl);
	}
}
/* End of file message.admin.controller.php */
/* Location: ./modules/message/message.admin.controller.php */
