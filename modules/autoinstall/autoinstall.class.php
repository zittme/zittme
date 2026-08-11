<?php

class Autoinstall extends ModuleObject
{
	/**
	 * Temporary directory path
	 */
	public $tmp_dir = './files/cache/autoinstall/';

	/**
	 * Deprecated tables
	 */
	public static $deprecated_tables = [
		'ai_remote_categories',
		'ai_installed_packages',
		'autoinstall_installed_packages',
		'autoinstall_remote_categories',
	];

	/**
	 * Supported package types
	 */
	public static $package_types = [
		'module',
		'addon',
		'layout',
		'widget',
		'module-skin',
		// widget-skin 은 Package::INSTALL_PATHS 에 설치 규칙이 있는데도 이 목록에서 빠져 있었다.
		// 그 결과 위젯 스킨 자료는 어느 분류 탭에도 잡히지 않고 추천 자료에서만 보였다.
		'widget-skin',
		'editor-skin',
		'editor-component',
		// 테마. 레이아웃·모듈 스킨·위젯을 한 묶음으로 담아 ./themes/{이름}/ 에 설치된다.
		'theme',
	];

	/**
	 * Check update function
	 *
	 * @return bool
	 */
	public function checkUpdate()
	{
		$oDB = DB::getInstance();

		// Delete deprecated tables.
		foreach (self::$deprecated_tables as $table)
		{
			if (!Zittme\Framework\Storage::exists($this->module_path . 'schemas/' . $table . '.xml') && $oDB->isTableExists($table))
			{
				return true;
			}
		}

		// Check if the autoinstall_packages table is the Zittme version.
		if (!$oDB->isColumnExists('autoinstall_packages', 'install_type'))
		{
			return true;
		}

		return false;
	}

	/**
	 * Update function
	 *
	 * @return Object
	 */
	public function moduleUpdate()
	{
		$oDB = DB::getInstance();

		// Delete deprecated tables.
		foreach (self::$deprecated_tables as $table)
		{
			if (!Zittme\Framework\Storage::exists($this->module_path . 'schemas/' . $table . '.xml') && $oDB->isTableExists($table))
			{
				$oDB->dropTable($table);
			}
		}

		// Check if the autoinstall_packages table is the Zittme version.
		if (!$oDB->isColumnExists('autoinstall_packages', 'install_type'))
		{
			$oDB->dropTable('autoinstall_packages');
			$oDB->createTable($this->module_path . 'schemas/autoinstall_packages.xml');
		}
	}
}
