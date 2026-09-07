<?php
/**
 * Run with:  SMM_KEY=your-key php examples/quickstart.php
 *
 * Nothing here places an order - it only reads. Uncomment the add() block when you are
 * ready to spend real balance.
 */
require __DIR__ . '/../vendor/autoload.php';   // or require the two files in src/ directly

use Tglux\Smm\ApiException;
use Tglux\Smm\Client;

$key = getenv('SMM_KEY') ?: '';
$smm = new Client($key);                       // defaults to https://smmtglux.com/api/v2

try {
    $balance = $smm->balance();
    printf("Balance: %s %s\n\n", $balance['balance'], $balance['currency']);

    $services = $smm->services();
    printf("%d services available. The five cheapest:\n", count($services));

    usort($services, static fn ($a, $b) => (float)$a['rate'] <=> (float)$b['rate']);
    foreach (array_slice($services, 0, 5) as $s) {
        printf(
            "  #%-6d %-52s %8s / 1000   min %s\n",
            $s['service'],
            mb_strimwidth($s['name'], 0, 52, '…'),
            $s['rate'],
            $s['min']
        );
    }

    // ---- placing and following an order -------------------------------------------
    // $order = $smm->add(1704, 'https://t.me/yourchannel/12', 1000);
    // printf("\nOrder %d placed\n", $order);
    //
    // $st = $smm->status($order);
    // printf("status=%s charge=%s remains=%s\n", $st['status'], $st['charge'], $st['remains']);
    //
    // One request for many orders, rather than one request each:
    // foreach ($smm->multiStatus([$order, 123, 456]) as $id => $row) {
    //     printf("  %s -> %s\n", $id, $row['error'] ?? $row['status']);
    // }
} catch (ApiException $e) {
    fwrite(STDERR, 'API error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
