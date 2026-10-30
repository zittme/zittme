<?php

/**
 * Add new point history lines to the summary tables.
 *
 * Does nothing unless summary numbers are turned on in the point settings.
 * Suitable for an hourly cron job when the site has no task queue:
 *   php index.php point.stats [max_rows]
 */
if (!defined('RX_VERSION'))
{
	exit;
}

$params = array_values($args ?? []);
$max_rows = max(1000, intval($params[0] ?? 0) ?: Zittme\Modules\Point\Models\Stats::RUN_ROWS);
if (!Zittme\Modules\Point\Models\Config::isStatsEnabled())
{
	echo "Summary numbers are off." . PHP_EOL;
	exit(0);
}
for ($i = 0; $i < 1000; $i++)
{
	$result = Zittme\Modules\Point\Models\Stats::run($max_rows);
	echo json_encode($result) . PHP_EOL;
	if ($result->done && $result->balance_ready)
	{
		break;
	}
	if ($result->balance_ready && !$result->processed)
	{
		break;
	}
}
exit(0);
