<?php
/**
 * Dependency-free test runner:  php tests/run.php
 *
 * Every case drives the client through an injected transport, so the suite is offline and
 * deterministic. The point is the decoding rules, which are where an SMM v2 client actually
 * goes wrong: errors arrive as HTTP 200, and a per-item error inside a batch is data rather
 * than a failed request.
 */

declare(strict_types=1);

require __DIR__ . '/../src/ApiException.php';
require __DIR__ . '/../src/Client.php';

use Tglux\Smm\ApiException;
use Tglux\Smm\Client;

$pass = 0; $fail = 0; $sent = [];

function ok(string $what, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok   $what\n"; }
    else       { $fail++; echo "  FAIL $what\n"; }
}
function throws(string $what, callable $fn, string $needle): void {
    global $pass, $fail;
    try { $fn(); $fail++; echo "  FAIL $what (no exception)\n"; }
    catch (Throwable $e) {
        if (str_contains($e->getMessage(), $needle)) { $pass++; echo "  ok   $what\n"; }
        else { $fail++; echo "  FAIL $what (got: {$e->getMessage()})\n"; }
    }
}
/** @param string|callable $reply */
function client($reply): Client {
    global $sent;
    return new Client('k', 'https://example.test/api/v2', 30, function ($url, $fields, $t) use ($reply, &$sent) {
        $sent[] = $fields;
        return is_callable($reply) ? $reply($fields) : $reply;
    });
}

echo "requests\n";
$sent = [];
client('{"balance":"12.3","currency":"USD"}')->balance();
ok('key and action are always sent', ($sent[0]['key'] ?? null) === 'k' && ($sent[0]['action'] ?? null) === 'balance');

$sent = [];
client('{"order":9}')->add(7, 'https://t.me/x/1', 100, ['runs' => 3, 'interval' => 60]);
ok('add passes service/link/quantity',
    $sent[0]['service'] === 7 && $sent[0]['link'] === 'https://t.me/x/1' && $sent[0]['quantity'] === 100);
ok('add passes dripfeed options', ($sent[0]['runs'] ?? null) === 3 && ($sent[0]['interval'] ?? null) === 60);
ok('caller cannot override the key',
    client('{"order":1}') && (function () { global $sent; $sent = [];
        (new Client('real', 'https://example.test', 30, function ($u, $f) use (&$sent) { $sent[] = $f; return '{"order":1}'; }))
            ->call('add', ['key' => 'forged']);
        return $sent[0]['key'] === 'real'; })());

echo "\nerrors are HTTP 200 bodies\n";
throws('an error body throws', fn () => client('{"error":"Invalid API key"}')->balance(), 'Invalid API key');
throws('rate limiting throws',  fn () => client('{"error":"Rate limit exceeded. Slow down."}')->services(), 'Rate limit');
throws('non-JSON throws',       fn () => client('<html>502 Bad Gateway</html>')->services(), 'not JSON');
throws('non-JSON quotes the body', fn () => client('<html>502 Bad Gateway</html>')->services(), '502 Bad Gateway');
throws('add without an order id throws', fn () => client('{}')->add(1, 'x', 1), 'no order id');

echo "\nbatches: a per-item error is data, not a failure\n";
$multi = client('{"1":{"status":"Completed","charge":"1.5","start_count":"0","remains":"0","currency":"USD"},'
              . '"2":{"error":"Incorrect order ID"}}')->multiStatus([1, 2]);
ok('good rows decode',            ($multi['1']['status'] ?? null) === 'Completed');
ok('bad rows come back as data',  ($multi['2']['error'] ?? null) === 'Incorrect order ID');
$cancel = client('[{"order":1,"cancel":1},{"order":2,"cancel":{"error":"Incorrect order ID"}}]')->cancel([1, 2]);
ok('cancel returns a list',       count($cancel) === 2 && $cancel[0]['cancel'] === 1);

echo "\nid lists\n";
$sent = [];
client('{}')->cancel([3, 1, 3, 0, -5, 2]);
ok('duplicates and non-positives are dropped', $sent[0]['orders'] === '3,1,2');
throws('empty list is refused', fn () => client('{}')->cancel([]), 'At least one id');
throws('over 100 ids is refused', fn () => client('{}')->cancel(range(1, 101)), 'at most 100');

echo "\nmoney stays a string\n";
$b = client('{"balance":"0.0000001","currency":"USD"}')->balance();
ok('seven decimals survive', $b['balance'] === '0.0000001');

echo "\nconstructor\n";
throws('empty key is refused', fn () => new Client('  '), 'API key is required');
ok('endpoint default is TGLUX', Client::DEFAULT_ENDPOINT === 'https://smmtglux.com/api/v2');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
