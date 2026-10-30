<?php

if (PHP_SAPI !== 'cli')
{
	exit;
}

$options = getopt('', ['id:', 'version:', 'title-ko:', 'title-en:', 'desc-ko::', 'desc-en::', 'author::', 'mids::', 'menus::', 'layouts::', 'module-data::', 'locales::', 'no-documents', 'no-media', 'host::', 'out:']);
foreach (['id', 'version', 'title-ko', 'title-en', 'out'] as $required)
{
	if (empty($options[$required]))
	{
		fwrite(STDERR, "usage: php export_kit.php --id=<id> --version=<x.y.z> --title-ko=<..> --title-en=<..> [--mids=a,b] [--menus=1,2] [--layouts=1] [--module-data=reservation,branch] [--locales=ko,en] [--no-documents] [--no-media] [--host=example.com] --out=<file.zip>\n");
		exit(2);
	}
}

define('__XE__', true);
$_SERVER['HTTP_HOST'] = $options['host'] ?? 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once dirname(__DIR__, 3) . '/config/config.inc.php';
Context::init();

use Zittme\Modules\Autoinstall\Models\Kit\Exporter;

$list = function ($key) use ($options) {
	return array_values(array_filter(array_map('trim', explode(',', (string)($options[$key] ?? ''))), 'strlen'));
};

if (!Zittme\Modules\Autoinstall\Models\Kit\Manifest::isValidId((string)$options['id']))
{
	fwrite(STDERR, lang('autoinstall.msg_kit_id_rule') . "\n");
	exit(2);
}

$choices = Exporter::getChoices();
$module_srls = [];
$default_data = [];
$mids = $list('mids');
foreach ($choices->modules as $row)
{
	if (!$mids || in_array($row['mid'], $mids, true))
	{
		$module_srls[] = (int)$row['module_srl'];
		if (isset($choices->importers[$row['module']]) && !in_array($row['module'], $default_data, true))
		{
			$default_data[] = $row['module'];
		}
	}
}

$opt = (object)[
	'id' => (string)$options['id'],
	'version' => (string)$options['version'],
	'title' => (object)['ko' => (string)$options['title-ko'], 'en' => (string)$options['title-en']],
	'description' => (object)['ko' => (string)($options['desc-ko'] ?? ''), 'en' => (string)($options['desc-en'] ?? '')],
	'author' => trim((string)($options['author'] ?? '')) ?: trim(strip_tags((string)Context::getSiteTitle())),
	'module_srls' => $module_srls,
	'menu_srls' => array_map('intval', $list('menus')),
	'layout_srls' => array_map('intval', $list('layouts')),
	'include_documents' => !isset($options['no-documents']),
	'include_media' => !isset($options['no-media']),
	'module_data' => $list('module-data') ?: $default_data,
	'locales' => $list('locales') ?: $choices->locales,
];

$exporter = Exporter::start($opt);
do
{
	$state = $exporter->run();
	fwrite(STDOUT, $state['step'] . ($state['total'] ? ' ' . $state['done'] . '/' . $state['total'] : '') . "\n");
}
while ($state['step'] !== 'done');

if (file_exists((string)$options['out']))
{
	@unlink((string)$options['out']);
}
if (!copy($exporter->getZipPath(), (string)$options['out']))
{
	fwrite(STDERR, "cannot write " . $options['out'] . "\n");
	$exporter->cleanup();
	exit(1);
}
fwrite(STDOUT, 'kit: ' . $options['out'] . ' ' . filesize((string)$options['out']) . " bytes\n");
fwrite(STDOUT, json_encode($exporter->state->manifest_summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
$exporter->cleanup();
