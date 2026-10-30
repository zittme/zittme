<?php

/**
 * Bring existing point balances into the ledger, in batches.
 *
 * Usage:
 *   php index.php point.migrate [batch_size] [max_batches]
 *   php index.php point.migrate reconcile [batch_size] [max_batches]
 *
 * The job can be stopped at any time and started again; it resumes from
 * where it left off and never writes the same opening row twice.
 * A reconcile pass always starts from the first member.
 */
if (!defined('RX_VERSION'))
{
	exit;
}

$exit_status = 0;
$params = array_values($args ?? []);
$reconcile = isset($params[0]) && $params[0] === 'reconcile';
if ($reconcile)
{
	array_shift($params);
}
$size = max(1, min(Zittme\Modules\Point\Models\Migration::MAX_BATCH, intval($params[0] ?? 0) ?: Zittme\Modules\Point\Models\Migration::DEFAULT_BATCH));
$max_batches = intval($params[1] ?? 0);

if (!Zittme\Modules\Point\Models\Config::isLedgerReady())
{
	echo "The point ledger is not installed yet. Run the module update first." . PHP_EOL;
	exit(1);
}

if ($reconcile)
{
	Zittme\Modules\Point\Models\Migration::startReconcile();
}

$batches = 0;
$started = microtime(true);
do
{
	$result = $reconcile
		? Zittme\Modules\Point\Models\Migration::runReconcileBatch($size)
		: Zittme\Modules\Point\Models\Migration::runBatch($size);
	if (!empty($result->error))
	{
		echo 'Error: ' . lang($result->error) . PHP_EOL;
		$exit_status = 1;
		break;
	}
	$batches++;
	printf("[%s] batch %d: %d members, %d rows written, cursor %d%s\n", $result->kind, $batches, $result->processed, $result->inserted, $result->cursor, $result->done ? ' (done)' : '');
	if ($result->done || ($max_batches > 0 && $batches >= $max_batches))
	{
		break;
	}
	usleep(50000);
}
while (true);

printf("Finished in %.1f seconds.\n", microtime(true) - $started);
exit($exit_status);
