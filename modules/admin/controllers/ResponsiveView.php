<?php

namespace Zittme\Modules\Admin\Controllers;

use Context;
use DB;
use Mobile;
use Zittme\Framework\Cache;
use Zittme\Framework\Exception;

/**
 * Bulk switching of modules to the responsive view.
 *
 * Turning the responsive view on globally deliberately leaves existing modules
 * alone — silently changing how a running site renders is not acceptable. This
 * screen is the explicit way to migrate them, and it shows what will change
 * before anything is written.
 */
class ResponsiveView extends Base
{
	/**
	 * Show which modules would change, grouped by their current setting.
	 */
	public function dispAdminResponsiveBulkApply()
	{
		if (!Mobile::isResponsiveViewEnabled())
		{
			throw new Exception('msg_responsive_view_not_enabled');
		}

		Context::set('module_groups', self::getModuleGroups());
		$this->setTemplateFile('responsive_bulk_apply');
	}

	/**
	 * Apply the responsive view to the selected groups.
	 */
	public function procAdminApplyResponsiveView()
	{
		if (!Mobile::isResponsiveViewEnabled())
		{
			throw new Exception('msg_responsive_view_not_enabled');
		}

		// Only the two known source states may be selected. Anything else is
		// ignored rather than passed through to the query.
		$selected = (array)(Context::get('targets') ?: []);
		$allowed = [Mobile::VIEW_MOBILE, Mobile::VIEW_NONE];
		$targets = array_values(array_intersect($allowed, array_map('strval', $selected)));

		if (!count($targets))
		{
			throw new Exception('msg_responsive_bulk_no_selection');
		}

		$oDB = DB::getInstance();
		$placeholders = implode(',', array_fill(0, count($targets), '?'));
		$params = $targets;
		array_unshift($params, Mobile::VIEW_RESPONSIVE);

		$stmt = $oDB->query(
			sprintf('UPDATE `modules` SET `use_mobile` = ? WHERE `use_mobile` IN (%s)', $placeholders),
			$params
		);
		$count = $stmt ? $stmt->rowCount() : 0;

		// Module info is cached per module, so the cache has to go or the old
		// view mode keeps being served.
		Cache::clearGroup('site_and_module');

		$this->setMessage(sprintf(lang('admin.msg_responsive_bulk_applied'), $count));
		$this->setRedirectUrl(Context::get('success_return_url') ?:
			getNotEncodedUrl('', 'module', 'admin', 'act', 'dispAdminConfigAdvanced'));
	}

	/**
	 * Count modules by their current view mode.
	 *
	 * @return array
	 */
	protected static function getModuleGroups(): array
	{
		$oDB = DB::getInstance();
		$stmt = $oDB->query('SELECT `use_mobile` AS mode, COUNT(*) AS count FROM `modules` GROUP BY `use_mobile`');
		$rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_OBJ) : [];

		$groups = [];
		foreach ($rows as $row)
		{
			$mode = (string)$row->mode;
			// Only the two states that are worth migrating are offered.
			if ($mode !== Mobile::VIEW_MOBILE && $mode !== Mobile::VIEW_NONE)
			{
				continue;
			}
			$groups[] = (object)[
				'mode' => $mode,
				'label' => Mobile::getViewModeLabel($mode),
				'count' => (int)$row->count,
			];
		}
		return $groups;
	}
}
