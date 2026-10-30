<?php

/**
 * Run the optional point ledger housekeeping (expiry, archiving, limit counters).
 *
 * Does nothing unless the matching options are turned on in the point settings.
 * Suitable for a daily cron job:
 *   php index.php point.maintenance [max_ranges]
 */
if (!defined('RX_VERSION'))
{
	exit;
}

$params = array_values($args ?? []);
$max_ranges = max(1, intval($params[0] ?? 0) ?: 50);
$results = Zittme\Modules\Point\Models\Maintenance::run($max_ranges);
if (!$results)
{
	echo "Nothing to do." . PHP_EOL;
}
foreach ($results as $job => $result)
{
	echo $job . ': ' . json_encode($result) . PHP_EOL;
}
exit(0);
