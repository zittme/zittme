<?php

namespace Zittme\Modules\Autoinstall\Models\Kit;

/**
 * Implemented by modules (Zittme\Modules\<Module>\Kit\Importer) that can put their own data into a starter kit.
 */
interface KitImporter
{
	/**
	 * @return array ['title' => string, 'tables' => string[]]
	 */
	public function getKitInfo(): array;

	/**
	 * Collect the module's data for a kit. The returned value is saved as data/module-<module>.json.
	 */
	public function exportKit(ExportContext $ctx): array;

	/**
	 * Import one slice of data. Return true when everything has been imported.
	 */
	public function importKit(array $data, ImportContext $ctx): bool;
}
