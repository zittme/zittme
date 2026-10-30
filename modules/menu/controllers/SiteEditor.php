<?php

namespace Zittme\Modules\Menu\Controllers;

use BaseObject;
use Context;
use LayoutModel;
use ModuleController;
use ModuleModel;
use Zittme\Framework\Exceptions\InvalidRequest;
use Zittme\Framework\Router;
use Zittme\Modules\Menu\Models\EditorState;
use Zittme\Modules\Menu\Models\Exposure;

/**
 * Site editor: one screen to arrange sitemaps, menus and their design.
 *
 * Moving, adding, removing and design changes go through the existing actions
 * (procMenuAdminMoveItem, procMenuAdminInsertItem, procMenuAdminDeleteItem,
 * module.procModuleAdminSetDesignInfo). The actions here only cover what has
 * no narrow existing action, and each of them writes the same columns with the
 * same meaning as the existing sitemap screen.
 */
class SiteEditor extends \ModuleObject
{
	/**
	 * Language keys handed to the editor script (menu.site_editor_*).
	 */
	const LANG_KEYS = [
		'group_admin', 'add_menu', 'add_sub', 'badge_admin', 'badge_groups', 'badge_guest', 'badge_home', 'badge_member', 'badge_new_window',
		'change', 'close', 'drop_after', 'drop_before', 'drop_end', 'drop_into', 'drop_no', 'drop_out',
		'drop_relink', 'empty_desc', 'empty_title', 'error_generic', 'fold', 'help', 'help_keys', 'help_terms',
		'help_terms_desc', 'help_tour', 'ia_count', 'ia_end_of_sitemap', 'ia_keys', 'ia_link_needed', 'ia_making', 'ia_mid',
		'ia_name_needed', 'ia_note', 'ia_placeholder', 'ia_placeholder_link', 'ia_result', 'ia_under_item', 'key_indent', 'key_order',
		'key_remove', 'key_rename', 'key_undo', 'kind_article', 'kind_board', 'kind_desc_folder', 'kind_desc_link', 'kind_folder',
		'kind_group_link', 'kind_group_module', 'kind_group_page', 'kind_link', 'kind_outside', 'kind_shortcut', 'kind_widget', 'layout_mobile_default',
		'layout_none', 'layout_none_desc', 'layout_pc_same', 'layout_pc_same_desc', 'layout_site_default', 'legacy', 'legacy_design', 'legacy_domains',
		'legacy_layouts', 'legacy_sitemap', 'legacy_title', 'more', 'mv_down', 'mv_in', 'mv_out', 'mv_up', 'name_too_long',
		'no_design_folder', 'no_design_link', 'no_design_missing', 'no_design_shortcut', 'no_result', 'panel_goto_target', 'panel_link_url', 'panel_mid',
		'panel_mid_checking', 'panel_mid_format', 'panel_mid_ok', 'panel_mid_reserved', 'panel_mid_save', 'panel_mid_taken', 'panel_name', 'panel_open_legacy',
		'panel_open_new', 'panel_open_setup', 'panel_target', 'preview_off', 'preview_on', 'pv_mobile', 'pv_open', 'pv_pc',
		'pv_refresh', 'pv_tablet', 'rail_sites', 'remove', 'remove_confirm', 'remove_confirm_children', 'remove_home', 'remove_title',
		'retry', 'row_open_settings', 'row_remove', 'row_rename', 'row_who', 'save_failed', 'saved', 'saving',
		'search', 'site_default', 'site_home', 'site_none', 'site_settings', 'site_slot_unused', 'site_slots_empty', 'site_slots_nolayout',
		'site_slots_title', 'sitemap', 'sitemap_items', 'sitemap_legacy', 'sitemap_slot_count', 'sitemap_slots', 'sitemap_slots_none', 'skin_pc_same',
		'skin_site_default', 'slot_bind', 'slot_bind_title', 'slot_close', 'slot_connect', 'slot_connected', 'slot_empty', 'slot_empty_option',
		'slot_none', 'slot_now', 'slot_replace', 'slot_unbind', 'tab_mobile', 'tab_pc', 'term_article_d', 'term_article_e',
		'term_article_t', 'term_layout_d', 'term_layout_e', 'term_layout_t', 'term_mid_d', 'term_mid_e', 'term_mid_t', 'term_outside_d',
		'term_outside_t', 'term_shortcut_d', 'term_shortcut_t', 'term_sitemap_d', 'term_sitemap_e', 'term_sitemap_t', 'term_skin_d', 'term_skin_e',
		'term_skin_t', 'term_slot_d', 'term_slot_e', 'term_slot_t', 'term_unlinked_d', 'term_unlinked_t', 'term_vis_admin_d', 'term_vis_admin_t',
		'term_vis_groups_d', 'term_vis_groups_e', 'term_vis_groups_t', 'term_vis_member_d', 'term_vis_member_t', 'term_vis_public_d', 'term_vis_public_t', 'term_who_d',
		'term_who_t', 'term_widget_d', 'term_widget_t', 'tip_admin', 'tip_groups', 'tip_guest', 'tip_link', 'tip_member',
		'tip_shortcut', 'title', 'toast_added', 'toast_added_link', 'toast_added_sub', 'toast_cannot_move', 'toast_children_moved', 'toast_close',
		'toast_failed', 'toast_layout', 'toast_mid', 'toast_moved', 'toast_moved_end', 'toast_moved_into', 'toast_moved_out', 'toast_moved_sitemap',
		'toast_nothing_undo', 'toast_relinked', 'toast_relinked_sub', 'toast_removed', 'toast_removed_sub', 'toast_renamed', 'toast_skin', 'toast_slot_bound',
		'toast_slot_bound_sub', 'toast_slot_unbound', 'toast_slot_unbound_last', 'toast_slot_unbound_sub', 'toast_undone', 'toast_undone_sub', 'toast_who_admin', 'toast_who_groups',
		'toast_who_member', 'toast_who_public', 'tour_1_d', 'tour_1_t', 'tour_2_d', 'tour_2_t', 'tour_3_d', 'tour_3_t',
		'tour_next', 'tour_skip', 'tour_start', 'undo', 'undo_add', 'undo_layout', 'undo_mid', 'undo_move',
		'undo_remove', 'undo_rename', 'undo_skin', 'undo_slot', 'undo_who', 'unlinked', 'unlinked_here', 'unlinked_info',
		'unlinked_module_admin', 'unlinked_never', 'unlinked_note', 'unlinked_relink', 'who_admin', 'who_groups', 'who_groups_count', 'who_groups_label',
		'who_groups_pick', 'who_guest_note', 'who_hint', 'who_member', 'who_public',
		'adv', 'chip_default_tip', 'chip_layout', 'chip_mlayout', 'chip_own_layout_tip', 'chip_own_skin_tip', 'chip_skin', 'err_kind_home',
		'goto_that', 'grant_access', 'grant_manager', 'grant_opt_admin', 'grant_opt_groups', 'grant_opt_guest', 'grant_opt_member', 'grant_opt_public',
		'grants_hint', 'grants_linked', 'grants_loading', 'grants_sum', 'grants_title', 'ia_target_label', 'kc_addr_inherit', 'kc_addr_inherit_d',
		'kc_addr_new', 'kc_addr_new_d', 'kc_alias_warn', 'kc_apply', 'kc_back', 'kc_cancel', 'kc_choose', 'kc_confirm',
		'kc_current', 'kc_inherit_hint', 'kc_layout_hint', 'kc_map_new', 'kc_map_old', 'kc_map_title', 'kc_note', 'kc_now',
		'kc_pick_hint', 'kc_pick_none', 'kc_recommended', 'kc_src_new', 'kc_src_pick', 'kc_step1', 'kc_step2', 'kc_step3',
		'kc_step4', 'kc_undo_hint', 'key_multi', 'kind_change', 'no_design_title', 'link_hint', 'link_invalid', 'mobile_layout_note',
		'multi_clear', 'multi_excluded', 'multi_home_skip', 'multi_k', 'multi_mixed', 'multi_remove', 'multi_remove_confirm', 'multi_skin_head',
		'multi_skin_kinds', 'multi_title', 'new_window', 'new_window_off', 'new_window_on', 'own_d', 'own_same_d', 'reset_default',
		'restore_btn', 'restore_desc', 'restore_title', 'row_copy', 'scope_label', 'scope_menu', 'scope_menu_d', 'scope_page',
		'scope_page_d', 'shortcut_pick', 'sm_add', 'sm_add_placeholder', 'sm_delete', 'sm_delete_confirm', 'sm_delete_disabled', 'sm_name',
		'sm_name_needed', 'sm_select_all', 'sm_select_all_hint', 'state_default', 'state_own', 'term_inherit_d', 'term_inherit_e', 'term_inherit_t',
		'term_menu_only_d', 'term_menu_only_t', 'term_page_block_d', 'term_page_block_t', 'toast_bulk_layout', 'toast_bulk_removed', 'toast_bulk_skin', 'toast_bulk_sub',
		'toast_bulk_who', 'toast_copied', 'toast_copied_sub', 'toast_grant', 'toast_kind', 'toast_kind_sub', 'toast_link', 'toast_new_window_off',
		'toast_new_window_on', 'toast_reset_layout', 'toast_reset_layout_sub', 'toast_reset_skin', 'toast_reset_skin_sub', 'toast_restored', 'toast_restored_sub', 'toast_scope_menu',
		'toast_scope_page', 'toast_sm_added', 'toast_sm_added_sub', 'toast_sm_deleted', 'toast_sm_renamed', 'toast_target', 'undo_bulk', 'undo_copy',
		'undo_grant', 'undo_kind', 'undo_link', 'undo_new_window', 'undo_reset', 'undo_restore', 'undo_sm_add', 'undo_sm_delete',
		'undo_sm_rename', 'undo_target', 'grants_readonly', 'scope_page_locked', 'grants_open_setup', 'key_multi_click',
		'sm_move', 'sm_owner', 'toast_sm_moved', 'toast_sm_moved_sub', 'toast_sm_moved_stay', 'undo_sm_move', 'toast_sm_deleted_sub',
		'term_seo_t', 'term_seo_d', 'term_seo_index_t', 'term_seo_index_d', 'term_seo_sitemap_t', 'term_seo_sitemap_d', 'term_seo_sd_t', 'term_seo_sd_d',
		'term_hidden_t', 'term_hidden_d', 'term_hidden_e', 'term_theme_t', 'term_theme_d', 'term_scope_t', 'term_scope_d', 'term_prefix_t', 'term_prefix_d',
		'term_prefix_e', 'term_sitemap_split_t', 'term_sitemap_split_d', 'view_edit', 'view_overview', 'rail_new_site', 'badge_seo_off', 'tip_seo_off',
		'tip_sm_seo_off', 'badge_hidden', 'tip_hidden', 'seo_off_btn', 'seo_on_btn', 'seo_menu_sub', 'hide_btn', 'unhide_btn', 'seo_main', 'seo_state_on',
		'seo_state_partial', 'seo_state_off', 'seo_src_own', 'seo_src_sitemap', 'seo_src_site', 'seo_who_warn', 'seo_who_off', 'seo_detail', 'seo_count', 'seo_index_d',
		'seo_sitemap_d', 'seo_sd_d', 'seo_llms_hint', 'seo_bulk_on', 'seo_bulk_off', 'seo_bulk_default', 'hidden_on', 'hidden_off', 'scope_on_d', 'scope_off_d',
		'scope_other_d', 'sm_seo_label', 'sm_seo_on_d', 'sm_seo_off_d', 'sm_menu_settings', 'toast_seo_off', 'toast_seo_off_sub', 'toast_seo_on', 'toast_seo_on_sub',
		'toast_seo_key', 'toast_seo_reset', 'toast_bulk_seo_on', 'toast_bulk_seo_off', 'toast_bulk_seo_default', 'toast_sm_seo_off', 'toast_sm_seo_off_sub',
		'toast_sm_seo_on', 'toast_sm_seo_on_sub', 'who_seo_offer', 'toast_hidden', 'toast_hidden_sub', 'toast_unhidden', 'undo_seo', 'undo_sm_seo', 'undo_hidden',
		'undo_scope', 'undo_options', 'undo_theme', 'undo_site', 'toast_scope_on', 'toast_scope_off', 'site_theme', 'theme_change', 'theme_scope', 'theme_scope_one',
		'theme_none', 'theme_now', 'theme_skins', 'theme_impact', 'theme_impact_change', 'theme_impact_keep', 'theme_also_own', 'theme_note_default', 'theme_note_site',
		'theme_apply', 'theme_pick', 'theme_shared', 'theme_site_note', 'theme_site_note_own', 'legacy_themes', 'toast_theme', 'toast_theme_sub',
		'toast_theme_sub_keep', 'prefix_label', 'prefix_none', 'prefix_hint', 'prefix_format', 'prefix_sum', 'prefix_sum_none', 'scope_new', 'scope_new_on',
		'scope_new_off', 'toast_scope_new_on', 'toast_scope_new_off', 'toast_prefix', 'toast_prefix_none', 'toast_prefix_sub', 'sitemap_split', 'sitemap_split_label',
		'sitemap_split_on', 'sitemap_split_off', 'toast_split_on', 'toast_split_on_sub', 'toast_split_off', 'toast_split_off_sub', 'toast_added_scope', 'ov_hint',
		'ov_hint_copy', 'ov_back', 'ov_open', 'ov_copy_title', 'ov_pop_title', 'ov_copy_to', 'ov_move_to', 'ov_pop_hint', 'ov_count', 'ov_own', 'ov_prefix',
		'ov_prefix_none', 'toast_ov_copied', 'toast_ov_copied_sub', 'toast_ov_moved', 'toast_ov_moved_sub', 'wz_title', 'wz_s1', 'wz_s1_d', 'wz_s2', 'wz_s2_title',
		'wz_s3', 'wz_s4', 'wz_s4_d', 'wz_side_foot', 'wz_s1_lead', 'wz_domain', 'wz_domain_hint', 'wz_domain_needed', 'wz_domain_format', 'wz_domain_taken', 'wz_name',
		'wz_name_needed', 'wz_prefix_hint', 'wz_scope', 'wz_scope_d', 'wz_s2_lead', 'wz_theme_same', 'wz_theme_same_d', 'wz_s3_lead', 'wz_s3_lead_none',
		'wz_set_company', 'wz_set_company_d', 'wz_set_community', 'wz_set_community_d', 'wz_set_blank', 'wz_set_blank_d', 'wz_set_count', 'wz_tree', 'wz_s4_lead',
		'wz_sum_domain', 'wz_sum_sm', 'wz_sum_sm_slot', 'wz_sum_sm_noslot', 'wz_sum_mod', 'wz_sum_mod_pre', 'wz_sum_scope_on', 'wz_sum_scope_off', 'wz_sum_theme',
		'wz_sum_theme_d', 'wz_sum_theme_same_d', 'wz_prev', 'wz_create', 'wz_sm_main', 'wz_sm_footer', 'st_home', 'st_about', 'st_greeting', 'st_location',
		'st_business', 'st_pr', 'st_notice', 'st_press', 'st_contact', 'st_privacy', 'st_free', 'st_qna', 'st_gallery', 'st_terms', 'toast_site', 'toast_site_sub', 'names_more'
	];

	/**
	 * Module types that can be connected to a menu item when its kind changes.
	 */
	const PAGE_TYPES = ['WIDGET', 'ARTICLE', 'OUTSIDE'];

	/**
	 * Initialization.
	 */
	public function init()
	{
		$this->setTemplatePath($this->module_path . 'tpl');
	}

	/**
	 * The editor screen.
	 */
	public function dispMenuAdminSiteEditor()
	{
		if (Context::get('module') !== 'admin')
		{
			$params = ['', 'module', 'admin'];
			foreach ($_GET as $key => $value)
			{
				if (is_scalar($value) && !in_array($key, ['module', 'mid', 'vid'], true))
				{
					$params[] = $key;
					$params[] = $value;
				}
			}
			$this->setRedirectUrl(getNotEncodedUrl(...$params));
			return;
		}

		Context::loadLang(\RX_BASEDIR . 'modules/menu/lang');
		$site_module_info = Context::get('site_module_info');
		$requested = Context::get('domain_srl');
		$domain_srl = ($requested !== null && $requested !== '') ? intval($requested) : intval($site_module_info->domain_srl ?? 0);
		$state = EditorState::build($domain_srl);

		$strings = [];
		foreach (self::LANG_KEYS as $key)
		{
			$strings[$key] = lang('menu.site_editor_' . $key);
		}

		$boot = [
			'state' => $state,
			'lang' => $strings,
			'lang_type' => Context::getLangType(),
			'urls' => [
				'sitemap' => getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMenuAdminSiteMap'),
				'design' => getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMenuAdminSiteDesign'),
				'layouts' => getNotEncodedUrl('', 'module', 'admin', 'act', 'dispLayoutAdminInstalledList'),
				'layout_modify' => getNotEncodedUrl('', 'module', 'admin', 'act', 'dispLayoutAdminModify', 'layout_srl', ''),
				'domains' => getNotEncodedUrl('', 'module', 'admin', 'act', 'dispAdminConfigGeneral'),
				'modules' => getNotEncodedUrl('', 'module', 'admin', 'act', 'dispModuleAdminContent'),
				'themes' => getNotEncodedUrl('', 'module', 'admin', 'act', 'dispAdminInstalledTheme'),
				'groups' => getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMemberAdminGroupList'),
			],
			'focus_item' => intval(Context::get('menu_item_srl')),
		];
		Context::set('site_editor_boot', json_encode($boot, \JSON_UNESCAPED_UNICODE | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT));
		$this->setTemplateFile('site_editor');
	}

	/**
	 * State of one site.
	 */
	public function getMenuAdminEditorState()
	{
		$this->add('state', EditorState::build(intval(Context::get('domain_srl'))));
	}

	/**
	 * Check whether an address (mid) can be used, and suggest a free one.
	 */
	public function getMenuAdminEditorCheckMid()
	{
		$target = trim(strval(Context::get('target_mid')));
		$current = trim(strval(Context::get('current_mid')));
		$reason = self::checkMid($target, $current);
		$this->add('reason', $reason);
		$this->add('suggestion', $reason === '' ? $target : self::suggestMid($target));
	}

	/**
	 * Save the name, the address (mid) or who can see a menu item.
	 *
	 * Only the fields that are sent are written. The name and the address are
	 * stored the same way procMenuAdminUpdateItem stores them, and the
	 * visibility is stored in menu_item.group_srls the same way
	 * procMenuAdminUpdateAuth stores it, without touching module permissions.
	 */
	public function procMenuAdminEditorSaveItem()
	{
		$menu_item_srl = intval(Context::get('menu_item_srl'));
		$item = executeQuery('menu.getMenuItem', (object)['menu_item_srl' => $menu_item_srl])->data;
		if (!$item || !$item->menu_item_srl)
		{
			throw new InvalidRequest;
		}

		$menu_name = Context::get('menu_name');
		if ($menu_name !== null)
		{
			$menu_name = trim(strval($menu_name));
			if ($menu_name === '')
			{
				return new BaseObject(-1, lang('menu.site_editor_ia_name_needed'));
			}
			if (mb_strlen($menu_name, 'UTF-8') > 250)
			{
				return new BaseObject(-1, lang('menu.site_editor_name_too_long'));
			}
			$args = new \stdClass;
			$args->menu_item_srl = $menu_item_srl;
			$args->name = preg_match('/^\$user_lang->[a-zA-Z0-9_]+$/', $menu_name) ? $menu_name : escape($menu_name, true, true);
			$output = executeQuery('menu.updateMenuItem', $args);
			if (!$output->toBool())
			{
				return $output;
			}
		}

		if (Context::get('exposure_set') === 'Y')
		{
			$args = new \stdClass;
			$args->menu_item_srl = $menu_item_srl;
			$args->group_srls = self::normalizeGroupSrls(Context::get('exposure'));
			$output = executeQuery('menu.updateMenuItem', $args);
			if (!$output->toBool())
			{
				return $output;
			}
		}

		$is_hidden = Context::get('is_hidden');
		if ($is_hidden !== null)
		{
			if (!EditorState::isHiddenReady())
			{
				return new BaseObject(-1, lang('menu.site_editor_hidden_not_ready'));
			}
			$output = executeQuery('menu.updateMenuItem', (object)['menu_item_srl' => $menu_item_srl, 'is_hidden' => $is_hidden === 'Y' ? 'Y' : 'N']);
			if (!$output->toBool())
			{
				return $output;
			}
		}

		$open_window = Context::get('open_window');
		if ($open_window !== null)
		{
			$args = new \stdClass;
			$args->menu_item_srl = $menu_item_srl;
			$args->open_window = $open_window === 'Y' ? 'Y' : 'N';
			$output = executeQuery('menu.updateMenuItem', $args);
			if (!$output->toBool())
			{
				return $output;
			}
		}

		// The target of a shortcut and the address of an external link are
		// stored in menu_item.url, as procMenuAdminUpdateItem stores them.
		$link_url = Context::get('link_url');
		if ($link_url !== null)
		{
			$link_url = trim(strval($link_url));
			if ($item->is_shortcut !== 'Y' || ModuleModel::getModuleInfoByMid($item->url))
			{
				throw new InvalidRequest;
			}
			if (!self::isLinkUrl($link_url))
			{
				return new BaseObject(-1, lang('menu.site_editor_link_invalid'));
			}
			$output = executeQuery('menu.updateMenuItem', (object)['menu_item_srl' => $menu_item_srl, 'url' => $link_url]);
			if (!$output->toBool())
			{
				return $output;
			}
		}

		$shortcut_target = intval(Context::get('shortcut_target'));
		if ($shortcut_target)
		{
			$target = executeQuery('menu.getMenuItem', (object)['menu_item_srl' => $shortcut_target])->data;
			if ($item->is_shortcut !== 'Y' || !$target || !$target->menu_item_srl || $target->is_shortcut === 'Y' || !ModuleModel::getModuleInfoByMid($target->url))
			{
				throw new InvalidRequest;
			}
			$output = executeQuery('menu.updateMenuItem', (object)['menu_item_srl' => $menu_item_srl, 'url' => $target->url, 'is_shortcut' => 'Y']);
			if (!$output->toBool())
			{
				return $output;
			}
		}

		$new_mid = Context::get('new_mid');
		if ($new_mid !== null && trim(strval($new_mid)) !== $item->url)
		{
			$new_mid = trim(strval($new_mid));
			if ($item->is_shortcut === 'Y')
			{
				throw new InvalidRequest;
			}
			$reason = self::checkMid($new_mid, $item->url);
			if ($reason !== '')
			{
				return new BaseObject(-1, lang('menu.site_editor_panel_mid_' . $reason));
			}
			$module_info = ModuleModel::getModuleInfoByMid($item->url);
			if (!$module_info || !$module_info->module_srl)
			{
				throw new InvalidRequest;
			}
			$module_info->mid = $new_mid;
			$output = ModuleController::getInstance()->updateModule($module_info);
			if (!$output->toBool())
			{
				return $output;
			}
		}

		getAdminController('menu')->makeXmlFile($item->menu_srl);
		$this->add('menu_item_srl', $menu_item_srl);
	}

	/**
	 * Put menu items back into a sitemap.
	 *
	 * Used to undo "remove from menu" and to link a module that is not in any
	 * menu. Each item is inserted the way procMenuAdminInsertItem inserts it,
	 * except that no new module is created: the existing module is linked again.
	 *
	 * The response contains the previous values of every row that was changed,
	 * so that procMenuAdminEditorRevertRows can put them back exactly.
	 */
	public function procMenuAdminEditorRestoreItems()
	{
		$parent_srl = intval(Context::get('parent_srl'));
		$after_srl = intval(Context::get('after_srl'));
		$remove_srl = intval(Context::get('remove_srl'));
		$items = json_decode(strval(Context::get('items')));
		if (!$parent_srl || !is_array($items) || !count($items))
		{
			throw new InvalidRequest;
		}

		$oMenuAdminModel = getAdminModel('menu');
		$oMenuAdminController = getAdminController('menu');
		$parent_item = executeQuery('menu.getMenuItem', (object)['menu_item_srl' => $parent_srl])->data;
		if ($parent_item && $parent_item->menu_item_srl)
		{
			$menu_srl = intval($parent_item->menu_srl);
		}
		else
		{
			$menu = $oMenuAdminModel->getMenu($parent_srl);
			if (!$menu || !$menu->menu_srl)
			{
				throw new InvalidRequest;
			}
			$menu_srl = intval($menu->menu_srl);
			$parent_srl = 0;
		}

		$menu_config = ModuleModel::getModuleConfig('menu');
		$context = (object)[
			'menu_srl' => $menu_srl,
			'unlinked_menu_srl' => intval($menu_config->unlinked_menu_srl ?? 0),
			'touched_menus' => [$menu_srl => true],
			'home_mid' => strval(ModuleModel::getSiteInfo(0)->mid ?? ''),
			'snapshot' => (object)['items' => [], 'removed' => [], 'added' => [], 'modules' => []],
			'keep_srls' => !$remove_srl,
		];

		// An item that is taken out of the "unlinked" sitemap is removed there
		// together with its children. Nothing else in that sitemap is moved.
		if ($remove_srl)
		{
			$remove = executeQuery('menu.getMenuItem', (object)['menu_item_srl' => $remove_srl])->data;
			if (!$remove || !$remove->menu_item_srl || !$context->unlinked_menu_srl || intval($remove->menu_srl) !== $context->unlinked_menu_srl)
			{
				throw new InvalidRequest;
			}
			$this->_removeTree(intval($remove->menu_item_srl), $context);
		}

		// Position of the first item, the same way moveMenuItem makes room.
		$listorder = null;
		if ($after_srl > 0)
		{
			$sibling = executeQuery('menu.getMenuItem', (object)['menu_item_srl' => $after_srl])->data;
			if ($sibling && intval($sibling->menu_srl) === $menu_srl && intval($sibling->parent_srl) === $parent_srl)
			{
				$listorder = intval($sibling->listorder) - 1;
			}
		}
		elseif ($after_srl === 0)
		{
			$max = executeQuery('menu.getMaxListorder', (object)['menu_srl' => $menu_srl, 'parent_srl' => $parent_srl]);
			$listorder = intval($max->data->listorder ?? 0);
		}
		if ($listorder !== null)
		{
			$siblings = executeQueryArray('menu.getMenuItems', (object)['menu_srl' => $menu_srl])->data ?: [];
			foreach ($siblings as $row)
			{
				if (intval($row->parent_srl) === $parent_srl && intval($row->listorder) < $listorder)
				{
					self::_remember($context->snapshot, $row);
				}
			}
			$output = executeQuery('menu.updateMenuItemListorder', (object)['menu_srl' => $menu_srl, 'parent_srl' => $parent_srl, 'listorder' => $listorder]);
			if (!$output->toBool())
			{
				return $output;
			}
		}

		$first_srl = 0;
		foreach ($items as $index => $item)
		{
			$inserted = $this->_restoreItem($item, $parent_srl, ($index === 0) ? $listorder : null, $context);
			if (!$first_srl)
			{
				$first_srl = $inserted;
			}
		}

		\Zittme\Framework\Cache::clearGroup('site_and_module');
		foreach (array_keys($context->touched_menus) as $touched)
		{
			$oMenuAdminController->makeXmlFile($touched);
		}

		$this->add('menu_item_srl', $first_srl);
		$this->add('snapshot', self::_exportSnapshot($context->snapshot->items, $context->snapshot->removed, $context->snapshot->added, $context->snapshot->modules));
	}

	/**
	 * Put back the rows recorded by procMenuAdminEditorRestoreItems.
	 *
	 * Inserted items are deleted, removed items are inserted again with their
	 * original numbers and values, moved positions are restored and modules
	 * get their previous sitemap number back.
	 */
	public function procMenuAdminEditorRevertRows()
	{
		$snapshot = json_decode(strval(Context::get('snapshot')), true);
		if (!is_array($snapshot))
		{
			throw new InvalidRequest;
		}

		$touched = [];
		$oDB = \DB::getInstance();
		$oDB->begin();
		foreach (array_reverse($snapshot['added'] ?? []) as $srl)
		{
			$row = executeQuery('menu.getMenuItem', (object)['menu_item_srl' => intval($srl)])->data;
			if ($row && $row->menu_item_srl)
			{
				executeQuery('menu.deleteMenuItem', (object)['menu_item_srl' => intval($srl)]);
				$touched[intval($row->menu_srl)] = true;
			}
		}
		foreach ($snapshot['removed'] ?? [] as $row)
		{
			if (!is_array($row) || empty($row['menu_item_srl']) || empty($row['menu_srl']) || !\MenuAdminController::isSafeMenuRow($row))
			{
				continue;
			}
			$exists = executeQuery('menu.getMenuItem', (object)['menu_item_srl' => intval($row['menu_item_srl'])])->data;
			if ($exists && $exists->menu_item_srl)
			{
				continue;
			}
			$args = new \stdClass;
			foreach (['menu_item_srl', 'parent_srl', 'menu_srl', 'listorder'] as $key)
			{
				$args->{$key} = intval($row[$key] ?? 0);
			}
			foreach (['name', 'icon', 'class', 'desc', 'url', 'is_shortcut', 'open_window', 'expand', 'normal_btn', 'hover_btn', 'active_btn', 'group_srls', 'regdate'] as $key)
			{
				if (isset($row[$key]))
				{
					$args->{$key} = strval($row[$key]);
				}
			}
			if (($row['is_hidden'] ?? '') === 'Y' && EditorState::isHiddenReady())
			{
				$args->is_hidden = 'Y';
			}
			$output = executeQuery('menu.insertMenuItem', $args);
			if (!$output->toBool())
			{
				$oDB->rollback();
				return $output;
			}
			$touched[$args->menu_srl] = true;
		}
		foreach ($snapshot['items'] ?? [] as $values)
		{
			$srl = intval($values['menu_item_srl'] ?? 0);
			$row = executeQuery('menu.getMenuItem', (object)['menu_item_srl' => intval($srl)])->data;
			if (!$row || !$row->menu_item_srl || !is_array($values))
			{
				continue;
			}
			$output = executeQuery('menu.updateMenuItem', (object)['menu_item_srl' => intval($srl), 'menu_srl' => intval($values['menu_srl']), 'parent_srl' => intval($values['parent_srl'])]);
			if ($output->toBool())
			{
				\DB::getInstance()->query('UPDATE menu_item SET listorder = ? WHERE menu_item_srl = ?', [intval($values['listorder']), intval($srl)]);
			}
			$touched[intval($row->menu_srl)] = true;
			$touched[intval($values['menu_srl'])] = true;
		}
		foreach ($snapshot['modules'] ?? [] as $values)
		{
			if (is_array($values) && !empty($values['module_srl']))
			{
				ModuleController::getInstance()->updateModuleMenuSrl(intval($values['module_srl']), intval($values['menu_srl'] ?? 0), false);
			}
		}
		$oDB->commit();

		\Zittme\Framework\Cache::clearGroup('site_and_module');
		$oMenuAdminController = getAdminController('menu');
		foreach (array_keys($touched) as $menu_srl)
		{
			if ($menu_srl)
			{
				$oMenuAdminController->makeXmlFile($menu_srl);
			}
		}
	}

	/**
	 * Turn recorded values into lists that keep their numbers in the response.
	 *
	 * @param array $items menu_item_srl => values
	 * @param array $removed
	 * @param array $added
	 * @param array $modules module_srl => menu_srl
	 * @return array
	 */
	protected static function _exportSnapshot(array $items, array $removed, array $added, array $modules): array
	{
		$item_list = [];
		foreach ($items as $srl => $values)
		{
			$item_list[] = array_merge(['menu_item_srl' => intval($srl)], $values);
		}
		$module_list = [];
		foreach ($modules as $srl => $menu_srl)
		{
			$module_list[] = ['module_srl' => intval($srl), 'menu_srl' => intval($menu_srl)];
		}
		return ['items' => $item_list, 'removed' => array_values($removed), 'added' => array_values($added), 'modules' => $module_list];
	}

	/**
	 * Remember the position of a row before it is changed.
	 *
	 * @param object $snapshot
	 * @param object $row
	 * @return void
	 */
	protected static function _remember(object $snapshot, object $row): void
	{
		$srl = intval($row->menu_item_srl);
		if (!isset($snapshot->items[$srl]))
		{
			$snapshot->items[$srl] = ['menu_srl' => intval($row->menu_srl), 'parent_srl' => intval($row->parent_srl), 'listorder' => intval($row->listorder)];
		}
	}

	/**
	 * Remember a module's sitemap number before it is changed.
	 *
	 * @param object $snapshot
	 * @param object $module_info
	 * @return void
	 */
	protected static function _rememberModule(object $snapshot, object $module_info): void
	{
		$srl = intval($module_info->module_srl);
		if (!isset($snapshot->modules[$srl]))
		{
			$snapshot->modules[$srl] = intval($module_info->menu_srl);
		}
	}

	/**
	 * Delete a row and its children, remembering them.
	 *
	 * @param int $menu_item_srl
	 * @param object $context
	 * @return void
	 */
	protected function _removeTree(int $menu_item_srl, object $context): void
	{
		$row = executeQuery('menu.getMenuItem', (object)['menu_item_srl' => $menu_item_srl])->data;
		if (!$row || !$row->menu_item_srl)
		{
			return;
		}
		$children = executeQueryArray('menu.getMenuItems', (object)['menu_srl' => $row->menu_srl])->data ?: [];
		foreach ($children as $child)
		{
			if (intval($child->parent_srl) === $menu_item_srl)
			{
				$this->_removeTree(intval($child->menu_item_srl), $context);
			}
		}
		$context->snapshot->removed[] = (array)$row;
		executeQuery('menu.deleteMenuItem', (object)['menu_item_srl' => $menu_item_srl]);
		$context->touched_menus[intval($row->menu_srl)] = true;
	}

	/**
	 * Insert one item and its children.
	 *
	 * @param object $item
	 * @param int $parent_srl
	 * @param int|null $listorder
	 * @param object $context
	 * @return int
	 */
	protected function _restoreItem($item, int $parent_srl, ?int $listorder, object $context): int
	{
		if (!is_object($item))
		{
			return 0;
		}

		$url = trim(strval($item->url ?? ''));
		if (!\MenuAdminController::isSafeMenuUrl($url))
		{
			throw new InvalidRequest;
		}
		$is_shortcut = ($item->is_shortcut ?? 'N') === 'Y' ? 'Y' : 'N';
		$module_info = null;
		if ($is_shortcut === 'N')
		{
			if ($url === '' || strncasecmp('http', $url, 4) === 0)
			{
				throw new InvalidRequest;
			}
			$module_info = ModuleModel::getModuleInfoByMid($url);

			// The sitemap screen parks menu-less modules in the "unlinked" sitemap.
			// Linking the module again takes it out of there.
			if ($context->unlinked_menu_srl && $context->menu_srl !== $context->unlinked_menu_srl)
			{
				$existing = executeQueryArray('menu.getMenuItemByUrl', (object)['url' => $url, 'is_shortcut' => 'N'])->data ?: [];
				foreach ($existing as $row)
				{
					$children = executeQuery('menu.getChildMenuCount', (object)['menu_item_srl' => $row->menu_item_srl]);
					if (intval($row->menu_srl) === $context->unlinked_menu_srl && !intval($children->data->count ?? 0))
					{
						$context->snapshot->removed[] = (array)$row;
						executeQuery('menu.deleteMenuItem', (object)['menu_item_srl' => $row->menu_item_srl]);
						$context->touched_menus[$context->unlinked_menu_srl] = true;
					}
				}
			}
		}

		$name = trim(strval($item->name_key ?? ''));
		if ($name === '')
		{
			$name = $url !== '' ? $url : '-';
		}

		$args = new \stdClass;
		$args->menu_srl = $context->menu_srl;
		$args->parent_srl = $parent_srl;
		$args->menu_item_srl = getNextSequence();

		// An item that was removed gets its old number back, unless the number
		// is in use or was never issued.
		$old_srl = intval($item->id ?? 0);
		if (!empty($context->keep_srls) && $old_srl > 0 && $old_srl < $args->menu_item_srl)
		{
			$taken = executeQuery('menu.getMenuItem', (object)['menu_item_srl' => $old_srl])->data;
			if (!$taken || !$taken->menu_item_srl)
			{
				$args->menu_item_srl = $old_srl;
			}
		}
		$args->name = preg_match('/^\$user_lang->[a-zA-Z0-9_]+$/', $name) ? $name : escape($name, false, true);
		$args->url = $url;
		$args->is_shortcut = $is_shortcut;
		$args->open_window = ($item->open_window ?? 'N') === 'Y' ? 'Y' : 'N';
		$args->expand = ($item->expand ?? 'N') === 'Y' ? 'Y' : 'N';
		$args->group_srls = self::normalizeGroupSrls($item->group_srls ?? '');
		$args->icon = escape(trim(strval($item->icon ?? '')), false);
		$args->class = trim(preg_replace('/[^a-z0-9\x20_-]/', '', strval($item->class ?? '')));
		$args->desc = escape(trim(strval($item->desc ?? '')), false, true);
		if (($item->is_hidden ?? 'N') === 'Y' && EditorState::isHiddenReady())
		{
			$args->is_hidden = 'Y';
		}
		$args->listorder = $listorder ?? (-1 * $args->menu_item_srl);
		$output = executeQuery('menu.insertMenuItem', $args);
		if (!$output->toBool())
		{
			throw new \Zittme\Framework\Exception($output->getMessage());
		}
		$context->snapshot->added[] = intval($args->menu_item_srl);

		if ($module_info && $module_info->module_srl)
		{
			self::_rememberModule($context->snapshot, $module_info);
			ModuleController::getInstance()->updateModuleMenuSrl(intval($module_info->module_srl), $context->menu_srl, false);
			if ($url === $context->home_mid)
			{
				getAdminController('menu')->makeHomemenuCacheFile($context->menu_srl);
			}
		}

		foreach (($item->children ?? []) as $child)
		{
			$this->_restoreItem($child, intval($args->menu_item_srl), null, $context);
		}
		return intval($args->menu_item_srl);
	}

	/**
	 * Connect a sitemap to a menu slot of a layout, or empty the slot.
	 *
	 * The value is stored in layouts.extra_vars exactly as the layout settings
	 * screen stores it, together with menu_name_list.
	 */
	public function procMenuAdminEditorBindSlot()
	{
		$layout_srl = intval(Context::get('layout_srl'));
		$output = self::_bindSlot($layout_srl, trim(strval(Context::get('slot'))), intval(Context::get('menu_srl')));
		if (!$output->toBool())
		{
			return $output;
		}
		$this->add('layout_srl', $layout_srl);
	}

	/**
	 * Store a sitemap in a menu slot of a layout instance.
	 *
	 * @param int $layout_srl
	 * @param string $slot
	 * @param int $menu_srl
	 * @return BaseObject
	 */
	protected static function _bindSlot(int $layout_srl, string $slot, int $menu_srl): BaseObject
	{
		$raw = LayoutModel::getLayoutRawData($layout_srl);
		$layout_info = LayoutModel::getLayout($layout_srl);
		if (!$raw || !$layout_info || !$slot || !isset($layout_info->menu->{$slot}))
		{
			throw new InvalidRequest;
		}

		$oMenuAdminModel = getAdminModel('menu');
		if ($menu_srl > 0 && !$oMenuAdminModel->getMenu($menu_srl))
		{
			throw new InvalidRequest;
		}
		if ($menu_srl < -1)
		{
			throw new InvalidRequest;
		}

		$extra_vars = $raw->extra_vars ? unserialize($raw->extra_vars) : null;
		if (!is_object($extra_vars))
		{
			$extra_vars = new \stdClass;
		}
		$extra_vars->{$slot} = $menu_srl ? strval($menu_srl) : '0';

		$menu_name_list = [];
		foreach (get_object_vars($layout_info->menu) as $menu_id => $val)
		{
			$value = intval($extra_vars->{$menu_id} ?? 0);
			if (!$value)
			{
				continue;
			}
			if ($value === -1)
			{
				$start_module = ModuleModel::getSiteInfo(0);
				$output = executeQuery('menu.getMenuItemByUrl', (object)['url' => $start_module->mid ?? '', 'site_srl' => 0]);
				$value = intval($output->data->menu_srl ?? 0);
				if (!$value)
				{
					continue;
				}
			}
			$menu = $oMenuAdminModel->getMenu($value);
			$menu_name_list[$value] = $menu->title ?? null;
		}
		$extra_vars->menu_name_list = $menu_name_list;

		$args = new \stdClass;
		$args->layout_srl = $layout_srl;
		$args->extra_vars = serialize($extra_vars);
		return getAdminController('layout')->updateLayout($args);
	}

	/**
	 * Add a sitemap or change its title.
	 *
	 * A new sitemap is added the way procMenuAdminInsert adds it, and the title
	 * is changed the way procMenuAdminUpdate changes it.
	 */
	public function procMenuAdminEditorSaveSitemap()
	{
		$menu_srl = intval(Context::get('menu_srl'));
		$title = trim(strval(Context::get('title')));
		if ($title === '')
		{
			return new BaseObject(-1, lang('menu.site_editor_sm_name_needed'));
		}

		$oMenuAdminController = getAdminController('menu');
		if (!$menu_srl)
		{
			$output = $oMenuAdminController->addMenu($title, 0);
			if (!$output->toBool())
			{
				return $output;
			}
			$new_srl = intval($output->get('menuSrl'));
			$domain_srl = intval(Context::get('domain_srl'));
			if ($domain_srl)
			{
				$output = self::_setSitemapDomain($new_srl, $domain_srl);
				if (!$output->toBool())
				{
					return $output;
				}
			}
			$this->add('menu_srl', $new_srl);
			return;
		}

		$menu = $this->_getEditableMenu($menu_srl);
		$output = executeQuery('menu.updateMenu', (object)['menu_srl' => $menu->menu_srl, 'title' => escape($title, true, true)]);
		if (!$output->toBool())
		{
			return $output;
		}
		$this->add('menu_srl', intval($menu->menu_srl));
	}

	/**
	 * Delete a sitemap that has no menu items.
	 */
	public function procMenuAdminEditorDeleteSitemap()
	{
		$menu = $this->_getEditableMenu(intval(Context::get('menu_srl')));
		$rows = executeQueryArray('menu.getMenuItems', (object)['menu_srl' => $menu->menu_srl])->data ?: [];
		if (count($rows))
		{
			return new BaseObject(-1, lang('menu.site_editor_sm_delete_disabled'));
		}

		// Empty the layout menu slots that show this sitemap.
		$unbound = [];
		$layouts = executeQueryArray('layout.getLayoutList', (object)['layout_type' => ['P', 'M']])->data ?: [];
		foreach ($layouts as $row)
		{
			$layout_info = LayoutModel::getLayout($row->layout_srl);
			foreach ((array)($layout_info->menu ?? []) as $slot => $slot_info)
			{
				if (intval($slot_info->menu_srl ?? 0) === intval($menu->menu_srl))
				{
					$output = self::_bindSlot(intval($row->layout_srl), strval($slot), 0);
					if (!$output->toBool())
					{
						return $output;
					}
					$unbound[] = ['layout_srl' => intval($row->layout_srl), 'slot' => strval($slot)];
				}
			}
		}

		$domains = EditorState::getSitemapDomains();
		$output = getAdminController('menu')->deleteMenu($menu->menu_srl, false);
		if (!$output->toBool())
		{
			return $output;
		}
		if (isset($domains[intval($menu->menu_srl)]))
		{
			self::_setSitemapDomain(intval($menu->menu_srl), 0);
		}
		$this->add('title_key', $menu->title);
		$this->add('domain_srl', intval($domains[intval($menu->menu_srl)] ?? 0));
		$this->add('unbound', $unbound);
	}

	/**
	 * Set the site a sitemap belongs to.
	 *
	 * A sitemap without a stored site belongs to the default site, so moving
	 * a sitemap to the default site removes the stored value.
	 */
	public function procMenuAdminEditorSetSitemapSite()
	{
		$menu = $this->_getEditableMenu(intval(Context::get('menu_srl')));
		$domain_srl = intval(Context::get('domain_srl'));
		$output = self::_setSitemapDomain(intval($menu->menu_srl), $domain_srl);
		if (!$output->toBool())
		{
			return $output;
		}
		$this->add('menu_srl', intval($menu->menu_srl));
	}

	/**
	 * Store or remove the site of a sitemap in the menu module settings.
	 *
	 * @param int $menu_srl
	 * @param int $domain_srl 0 or the default site removes the stored value
	 * @return BaseObject
	 */
	protected static function _setSitemapDomain(int $menu_srl, int $domain_srl): BaseObject
	{
		if ($domain_srl)
		{
			$domain = executeQuery('module.getDomainInfo', (object)['domain_srl' => $domain_srl])->data;
			if (!$domain || !isset($domain->domain_srl))
			{
				throw new InvalidRequest;
			}
			if ($domain->is_default_domain === 'Y')
			{
				$domain_srl = 0;
			}
		}

		$config = ModuleModel::getModuleConfig('menu');
		$config = is_object($config) ? clone $config : new \stdClass;
		Exposure::flushSitemapCache();
		$map = EditorState::getSitemapDomains();
		if ($domain_srl)
		{
			$map[$menu_srl] = $domain_srl;
		}
		else
		{
			unset($map[$menu_srl]);
		}
		if (count($map))
		{
			$config->sitemap_domains = $map;
		}
		else
		{
			unset($config->sitemap_domains);
		}
		return ModuleController::getInstance()->insertModuleConfig('menu', $config);
	}

	/**
	 * Get the permissions of a module, as the module permission screen shows them.
	 */
	public function getMenuAdminEditorGrants()
	{
		$module_info = ModuleModel::getModuleInfoByModuleSrl(intval(Context::get('module_srl')));
		if (!$module_info || !$module_info->module_srl)
		{
			throw new InvalidRequest;
		}

		Context::loadLang(\RX_BASEDIR . 'modules/module/lang');
		$rows = executeQueryArray('module.getModuleGrants', (object)['module_srl' => $module_info->module_srl])->data ?: [];
		$current = [];
		foreach ($rows as $row)
		{
			$current[$row->name][] = strval(intval($row->group_srl));
		}

		$list = [];
		foreach (self::_getGrantSpec($module_info->module) as $name => $spec)
		{
			$list[] = (object)[
				'name' => $name,
				'title' => $spec->title,
				'default' => $spec->default,
				'rows' => $current[$name] ?? [],
			];
		}
		$this->add('module_srl', intval($module_info->module_srl));
		$this->add('grants', $list);
	}

	/**
	 * Information needed before changing the kind of a menu item.
	 */
	public function getMenuAdminEditorKindCheck()
	{
		$item = $this->_getModuleItem(intval(Context::get('menu_item_srl')));
		$module_info = ModuleModel::getModuleInfoByMid($item->url);
		if (!$module_info || !$module_info->module_srl)
		{
			throw new InvalidRequest;
		}

		$this->add('is_home', self::_isIndexModule(intval($module_info->module_srl)));
		$this->add('archive_mid', self::archiveMid($module_info->mid, 'old'));
		$this->add('new_mid', self::suggestMid($module_info->mid));
		$this->add('alias_count', self::_countAliases(intval($module_info->module_srl)));
	}

	/**
	 * Connect a different module to a menu item.
	 *
	 * The kind of an existing module is never changed. Depending on the mode:
	 *  - inherit: the current module gets an archive address (A_old1) and a new
	 *    module of the chosen kind is created with the current address (A).
	 *  - new: a new module is created with a new address, the current module
	 *    keeps its address.
	 *  - pick: a module that is not in any menu is connected instead.
	 * The module that leaves the menu keeps its content, settings and
	 * permissions, and only loses its menu link.
	 */
	public function procMenuAdminEditorChangeKind()
	{
		$item = $this->_getModuleItem(intval(Context::get('menu_item_srl')));
		$mode = strval(Context::get('mode'));
		$old = ModuleModel::getModuleInfoByMid($item->url);
		if (!$old || !$old->module_srl || !in_array($mode, ['inherit', 'new', 'pick'], true))
		{
			throw new InvalidRequest;
		}
		if (self::_isIndexModule(intval($old->module_srl)))
		{
			return new BaseObject(-1, lang('menu.site_editor_err_kind_home'));
		}

		$oDB = \DB::getInstance();
		$oDB->begin();
		try
		{
			$touched = [intval($item->menu_srl) => true];
			$removed = [];
			$module_menus = [intval($old->module_srl) => intval($old->menu_srl)];
			if ($mode === 'pick')
			{
				$picked = ModuleModel::getModuleInfoByModuleSrl(intval(Context::get('module_srl')));
				if (!$picked || !$picked->module_srl || intval($picked->module_srl) === intval($old->module_srl))
				{
					throw new InvalidRequest;
				}
				$module_menus[intval($picked->module_srl)] = intval($picked->menu_srl);
				$this->_takeOutOfUnlinked($picked->mid, $touched, $removed);
				$new_mid = $picked->mid;
				$new_srl = intval($picked->module_srl);
				$archived_mid = $old->mid;
			}
			else
			{
				$type = strval(Context::get('module_type'));
				if (!self::_isAddableType($type))
				{
					throw new InvalidRequest;
				}
				if ($mode === 'inherit')
				{
					$archived_mid = self::archiveMid($old->mid, 'old');
					if ($archived_mid === '')
					{
						throw new InvalidRequest;
					}
					$this->_renameModule($old, $archived_mid, 0);
					$new_mid = $item->url;
				}
				else
				{
					$archived_mid = $old->mid;
					$new_mid = self::suggestMid($old->mid);
					if ($new_mid === '')
					{
						throw new InvalidRequest;
					}
				}
				$new_srl = $this->_createModule($type, $new_mid, $old, $item);
				self::_copyGrants(intval($old->module_srl), $new_srl, $type);
			}

			ModuleController::getInstance()->updateModuleMenuSrl(intval($old->module_srl), 0, false);
			ModuleController::getInstance()->updateModuleMenuSrl($new_srl, intval($item->menu_srl), false);
			if ($new_mid !== $item->url)
			{
				$this->_pointItemsTo($item, $new_mid, $touched);
			}
		}
		catch (\Throwable $e)
		{
			$oDB->rollback();
			\Zittme\Framework\Cache::clearGroup('site_and_module');
			ModuleModel::$_mid_map = ModuleModel::$_module_srl_map = [];
			if ($e instanceof \Zittme\Framework\Exception)
			{
				throw $e;
			}
			return new BaseObject(-1, $e->getMessage());
		}
		$oDB->commit();

		$this->_afterModuleSwap($touched);
		$this->add('menu_item_srl', intval($item->menu_item_srl));
		$this->add('new_mid', $new_mid);
		$this->add('new_module_srl', $new_srl);
		$this->add('archived_mid', $archived_mid);
		$this->add('archived_module_srl', intval($old->module_srl));
		$this->add('snapshot', self::_exportSnapshot([], $removed, [], $module_menus));
	}

	/**
	 * Put an archived module back into the address of a menu item.
	 *
	 * The module currently at the address gets an archive address (A_new1, or
	 * the given one) and leaves the menu, and the archived module takes the
	 * address back and is linked to the same menu item.
	 */
	public function procMenuAdminEditorRevertKind()
	{
		$item = $this->_getModuleItem(intval(Context::get('menu_item_srl')));
		$current = ModuleModel::getModuleInfoByMid($item->url);
		$archived = ModuleModel::getModuleInfoByModuleSrl(intval(Context::get('module_srl')));
		if (!$current || !$current->module_srl || !$archived || !$archived->module_srl || intval($current->module_srl) === intval($archived->module_srl))
		{
			throw new InvalidRequest;
		}
		if (self::_isIndexModule(intval($current->module_srl)))
		{
			return new BaseObject(-1, lang('menu.site_editor_err_kind_home'));
		}

		$park_mid = trim(strval(Context::get('park_mid')));
		if ($park_mid !== '' && self::checkMid($park_mid) !== '')
		{
			$park_mid = '';
		}
		if ($park_mid === '')
		{
			$park_mid = self::archiveMid($current->mid, 'new');
		}
		if ($park_mid === '')
		{
			throw new InvalidRequest;
		}

		$address = $item->url;
		$archived_mid = $archived->mid;
		$oDB = \DB::getInstance();
		$oDB->begin();
		try
		{
			$touched = [intval($item->menu_srl) => true];
			$removed = [];
			$module_menus = [intval($current->module_srl) => intval($current->menu_srl), intval($archived->module_srl) => intval($archived->menu_srl)];
			$this->_takeOutOfUnlinked($archived->mid, $touched, $removed);
			$this->_renameModule($current, $park_mid, 0);
			$archived = ModuleModel::getModuleInfoByModuleSrl(intval($archived->module_srl));
			$this->_renameModule($archived, $address, intval($item->menu_srl));
		}
		catch (\Throwable $e)
		{
			$oDB->rollback();
			\Zittme\Framework\Cache::clearGroup('site_and_module');
			ModuleModel::$_mid_map = ModuleModel::$_module_srl_map = [];
			if ($e instanceof \Zittme\Framework\Exception)
			{
				throw $e;
			}
			return new BaseObject(-1, $e->getMessage());
		}
		$oDB->commit();

		$this->_afterModuleSwap($touched);
		$this->add('menu_item_srl', intval($item->menu_item_srl));
		$this->add('parked_mid', $park_mid);
		$this->add('parked_module_srl', intval($current->module_srl));
		$this->add('restored_mid', $address);
		$this->add('restored_from_mid', $archived_mid);
		$this->add('snapshot', self::_exportSnapshot([], $removed, [], $module_menus));
	}

	/**
	 * Get a sitemap that can be edited here.
	 *
	 * @param int $menu_srl
	 * @return object
	 */
	protected function _getEditableMenu(int $menu_srl): object
	{
		$menu = getAdminModel('menu')->getMenu($menu_srl);
		$menu_config = ModuleModel::getModuleConfig('menu');
		if (!$menu || !$menu->menu_srl || $menu->title === getClass('admin')->getAdminMenuName() || intval($menu->menu_srl) === intval($menu_config->unlinked_menu_srl ?? 0))
		{
			throw new InvalidRequest;
		}
		return $menu;
	}

	/**
	 * Get a menu item that is linked to a module.
	 *
	 * @param int $menu_item_srl
	 * @return object
	 */
	protected function _getModuleItem(int $menu_item_srl): object
	{
		$item = executeQuery('menu.getMenuItem', (object)['menu_item_srl' => $menu_item_srl])->data;
		if (!$item || !$item->menu_item_srl || $item->is_shortcut === 'Y' || strval($item->url) === '' || strncasecmp('http', $item->url, 4) === 0)
		{
			throw new InvalidRequest;
		}
		return $item;
	}

	/**
	 * Check whether a type can be added in the sitemap.
	 *
	 * @param string $type
	 * @return bool
	 */
	protected static function _isAddableType(string $type): bool
	{
		$list = getAdminModel('menu')->getModuleListInSitemap(0);
		return $type !== '' && isset($list[$type]);
	}

	/**
	 * Check whether a module is the first page of any site.
	 *
	 * @param int $module_srl
	 * @return bool
	 */
	protected static function _isIndexModule(int $module_srl): bool
	{
		$output = executeQueryArray('module.getDomains', (object)['list_count' => 1000, 'page' => 1]);
		foreach ($output->data ?: [] as $domain)
		{
			if (intval($domain->index_module_srl) === $module_srl)
			{
				return true;
			}
		}
		return false;
	}

	/**
	 * Count document aliases of a module.
	 *
	 * @param int $module_srl
	 * @return int
	 */
	protected static function _countAliases(int $module_srl): int
	{
		$stmt = \DB::getInstance()->query('SELECT COUNT(*) AS cnt FROM document_aliases WHERE module_srl = ?', [$module_srl]);
		$rows = $stmt ? $stmt->fetchAll() : [];
		$row = $rows[0] ?? null;
		return intval(is_object($row) ? ($row->cnt ?? 0) : ($row['cnt'] ?? 0));
	}

	/**
	 * Change the address of a module without touching menu items.
	 *
	 * @param object $module_info
	 * @param string $mid
	 * @param int $menu_srl
	 * @return void
	 */
	protected function _renameModule(object $module_info, string $mid, int $menu_srl): void
	{
		$module_info = clone $module_info;
		$module_info->mid = $mid;
		$module_info->menu_srl = $menu_srl;
		$module_info->isMenuCreate = false;
		$output = ModuleController::getInstance()->updateModule($module_info);
		if (!$output->toBool())
		{
			throw new \Zittme\Framework\Exception($output->getMessage());
		}
		ModuleModel::$_mid_map = ModuleModel::$_module_srl_map = [];
	}

	/**
	 * Create a module for a menu item, the way procMenuAdminInsertItem does,
	 * taking over the layout, the mobile view and the opening site of the
	 * module that was there before.
	 *
	 * @param string $type
	 * @param string $mid
	 * @param object $old
	 * @param object $item
	 * @return int
	 */
	protected function _createModule(string $type, string $mid, object $old, object $item): int
	{
		$args = new \stdClass;
		if (in_array($type, self::PAGE_TYPES, true))
		{
			$args->module = 'page';
			$args->page_type = $type;
		}
		else
		{
			$args->module = $type;
		}
		$args->mid = $mid;
		$args->browser_title = $item->name;
		$args->menu_srl = intval($item->menu_srl);
		$args->layout_srl = intval($old->layout_srl);
		$args->mlayout_srl = intval($old->mlayout_srl);
		if (isset($old->use_mobile) && $old->use_mobile !== '')
		{
			$args->use_mobile = $old->use_mobile;
		}
		$args->domain_srl = intval($old->domain_srl ?? -1);
		$args->skin = '/USE_DEFAULT/';
		$args->mskin = ModuleModel::getModuleDefaultSkin($args->module, 'M', 0, false) ?: '/USE_DEFAULT/';
		$args->is_skin_fix = 'N';
		$args->is_mskin_fix = 'N';

		$extra = ModuleModel::getModuleExtraVars(intval($old->module_srl));
		$robots_tag = strval($extra[$old->module_srl]->robots_tag ?? '');
		if ($robots_tag !== '')
		{
			$args->robots_tag = $robots_tag;
		}

		$output = ModuleController::getInstance()->insertModule($args);
		if (!$output->toBool())
		{
			throw new \Zittme\Framework\Exception($output->getMessage());
		}
		ModuleModel::$_mid_map = ModuleModel::$_module_srl_map = [];
		return intval($output->get('module_srl'));
	}

	/**
	 * Copy the permissions that the new kind also has.
	 *
	 * @param int $from_srl
	 * @param int $to_srl
	 * @param string $type
	 * @return void
	 */
	protected static function _copyGrants(int $from_srl, int $to_srl, string $type): void
	{
		$module = in_array($type, self::PAGE_TYPES, true) ? 'page' : $type;
		$spec = self::_getGrantSpec($module);
		$rows = executeQueryArray('module.getModuleGrants', (object)['module_srl' => $from_srl])->data ?: [];
		$copy = [];
		foreach ($rows as $row)
		{
			if (isset($spec[$row->name]))
			{
				$copy[$row->name][] = strval(intval($row->group_srl));
			}
		}
		if (count($copy))
		{
			$output = self::_replaceGrants($to_srl, $copy);
			if (!$output->toBool())
			{
				throw new \Zittme\Framework\Exception($output->getMessage());
			}
		}
	}

	/**
	 * Replace permission rows of some names.
	 *
	 * @param int $module_srl
	 * @param array $changes name => list of group_srl strings
	 * @return BaseObject
	 */
	protected static function _replaceGrants(int $module_srl, array $changes): BaseObject
	{
		$rows = executeQueryArray('module.getModuleGrants', (object)['module_srl' => $module_srl])->data ?: [];
		$merged = [];
		foreach ($rows as $row)
		{
			if (!array_key_exists($row->name, $changes))
			{
				$merged[] = [$row->name, strval(intval($row->group_srl))];
			}
		}
		foreach ($changes as $name => $values)
		{
			foreach ($values as $value)
			{
				$merged[] = [$name, $value];
			}
		}

		$oDB = \DB::getInstance();
		$oDB->begin();
		$output = executeQuery('module.deleteModuleGrants', (object)['module_srl' => $module_srl]);
		if (!$output->toBool())
		{
			$oDB->rollback();
			return $output;
		}
		foreach ($merged as $pair)
		{
			$output = executeQuery('module.insertModuleGrant', (object)['module_srl' => $module_srl, 'name' => $pair[0], 'group_srl' => $pair[1]]);
			if (!$output->toBool())
			{
				$oDB->rollback();
				return $output;
			}
		}
		$oDB->commit();
		\Zittme\Framework\Cache::delete("site_and_module:module_grants:$module_srl");
		\Zittme\Framework\Cache::clearGroup('site_and_module');
		return new BaseObject;
	}

	/**
	 * Permission names of a module with their titles and defaults,
	 * in the same order as the module permission screen.
	 *
	 * @param string $module
	 * @return array
	 */
	protected static function _getGrantSpec(string $module): array
	{
		$spec = [];
		$spec['access'] = (object)['title' => lang('menu.site_editor_grant_access'), 'default' => 'guest'];
		$xml_info = ModuleModel::getModuleActionXml($module);
		foreach ((array)($xml_info->grant ?? []) as $name => $grant)
		{
			$default = strval($grant->default ?? '') ?: 'guest';
			$spec[$name] = (object)['title' => strval($grant->title ?? $name), 'default' => $default === 'root' ? 'manager' : $default];
		}
		$spec['manager'] = (object)['title' => lang('menu.site_editor_grant_manager'), 'default' => 'manager'];
		return $spec;
	}

	/**
	 * Remove the placeholder items that the sitemap screen made for a module
	 * in the "unlinked" sitemap, and make sure the module is not used in any
	 * other menu.
	 *
	 * @param string $mid
	 * @param array $touched
	 * @return void
	 */
	protected function _takeOutOfUnlinked(string $mid, array &$touched, array &$removed): void
	{
		$unlinked_menu_srl = intval(ModuleModel::getModuleConfig('menu')->unlinked_menu_srl ?? 0);
		$rows = executeQueryArray('menu.getMenuItemByUrl', (object)['url' => $mid, 'is_shortcut' => 'N'])->data ?: [];
		foreach ($rows as $row)
		{
			$children = executeQuery('menu.getChildMenuCount', (object)['menu_item_srl' => $row->menu_item_srl]);
			if (!$unlinked_menu_srl || intval($row->menu_srl) !== $unlinked_menu_srl || intval($children->data->count ?? 0))
			{
				throw new \Zittme\Framework\Exception(lang('menu.site_editor_err_linked'));
			}
		}
		foreach ($rows as $row)
		{
			$removed[] = (array)$row;
			executeQuery('menu.deleteMenuItem', (object)['menu_item_srl' => $row->menu_item_srl]);
			$touched[$unlinked_menu_srl] = true;
		}
	}

	/**
	 * Point a menu item, and the shortcuts that point to it, to another address.
	 *
	 * @param object $item
	 * @param string $mid
	 * @param array $touched
	 * @return void
	 */
	protected function _pointItemsTo(object $item, string $mid, array &$touched): void
	{
		$output = executeQuery('menu.updateMenuItem', (object)['menu_item_srl' => $item->menu_item_srl, 'url' => $mid]);
		if (!$output->toBool())
		{
			throw new \Zittme\Framework\Exception($output->getMessage());
		}
		$shortcuts = executeQueryArray('menu.getMenuItemByUrl', (object)['url' => $item->url, 'is_shortcut' => 'Y'])->data ?: [];
		foreach ($shortcuts as $row)
		{
			executeQuery('menu.updateMenuItem', (object)['menu_item_srl' => $row->menu_item_srl, 'url' => $mid]);
			$touched[intval($row->menu_srl)] = true;
		}
	}

	/**
	 * Clear caches after modules were swapped in a menu.
	 *
	 * @param array $touched
	 * @return void
	 */
	protected function _afterModuleSwap(array $touched): void
	{
		\Zittme\Framework\Cache::clearGroup('site_and_module');
		ModuleModel::$_mid_map = ModuleModel::$_module_srl_map = [];
		$oMenuAdminController = getAdminController('menu');
		foreach (array_keys($touched) as $menu_srl)
		{
			if ($menu_srl)
			{
				$oMenuAdminController->makeXmlFile($menu_srl);
			}
		}
	}

	/**
	 * Check an external link address the way procMenuAdminUpdateItem does.
	 *
	 * @param string $url
	 * @return bool
	 */
	public static function isLinkUrl(string $url): bool
	{
		return $url !== '' && \MenuAdminController::isSafeMenuUrl($url) && (bool)preg_match('!^([a-z]+:(//|[a-z0-9_-])|\.{0,2}/)!i', $url) && !preg_match('!^(javascript|vbscript|data):!i', $url);
	}

	/**
	 * Make an archive address such as notice_old1 that is free to use.
	 *
	 * @param string $mid
	 * @param string $tag
	 * @return string
	 */
	public static function archiveMid(string $mid, string $tag): string
	{
		for ($i = 1; $i < 1000; $i++)
		{
			$suffix = '_' . $tag . $i;
			$candidate = substr($mid, 0, 40 - strlen($suffix)) . $suffix;
			if (self::checkMid($candidate) === '')
			{
				return $candidate;
			}
		}
		return '';
	}

	/**
	 * Check an address (mid).
	 *
	 * @param string $mid
	 * @param string $current
	 * @return string Empty when usable, otherwise format, reserved or taken
	 */
	public static function checkMid(string $mid, string $current = ''): string
	{
		if ($mid !== '' && $mid === $current)
		{
			return '';
		}
		if (!preg_match('/^[a-z][a-z0-9_]+$/i', $mid) || strlen($mid) > 40)
		{
			return 'format';
		}
		$module_info = ModuleModel::getModuleInfoByMid($mid);
		if ($module_info && $module_info->module_srl)
		{
			return 'taken';
		}
		if (Router::isReservedPrefix($mid) || ModuleModel::isIDExists($mid))
		{
			return 'reserved';
		}
		return '';
	}

	/**
	 * Suggest a free address based on the given one.
	 *
	 * @param string $mid
	 * @return string
	 */
	public static function suggestMid(string $mid): string
	{
		$base = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', $mid));
		$base = trim($base, '_');
		if ($base === '' || !preg_match('/^[a-z]/', $base))
		{
			$base = 'menu' . ($base !== '' ? '_' . $base : '');
		}
		$base = substr($base, 0, 36);
		if (self::checkMid($base) === '')
		{
			return $base;
		}
		for ($i = 2; $i < 1000; $i++)
		{
			if (self::checkMid($base . $i) === '')
			{
				return $base . $i;
			}
		}
		return '';
	}

	/**
	 * Normalize menu visibility the same way procMenuAdminUpdateAuth does.
	 *
	 * @param mixed $value
	 * @return string
	 */
	public static function normalizeGroupSrls($value): string
	{
		if (is_array($value))
		{
			$value = implode(',', $value);
		}
		$value = trim(strval($value));
		if ($value === '')
		{
			return '';
		}
		if (in_array($value, ['-1', '-3', '-4'], true))
		{
			return $value;
		}
		$list = array_values(array_filter(array_map('intval', explode(',', $value)), function($srl) {
			return $srl > 0;
		}));
		return implode(',', $list);
	}
}
