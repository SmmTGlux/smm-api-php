# smm-api-php

A tiny PHP client for the standard **SMM API v2** that social media marketing panels expose.
No dependencies, no framework, one class.

```bash
composer require smmtglux/smm-api
```

```php
use Tglux\Smm\Client;

$smm = new Client('YOUR-API-KEY');            // https://smmtglux.com by default
$smm = new Client('YOUR-API-KEY', 'https://another.panel/api/v2');   // or any other panel

$balance  = $smm->balance();                  // ['balance' => '25.0', 'currency' => 'USD']
$services = $smm->services();                 // the whole catalogue
$order    = $smm->add(1706, 'https://t.me/yourchannel/12', 1000);
$status   = $smm->status($order);             // ['status' => 'In progress', 'remains' => '1000', ...]
```

## Why this exists

The SMM v2 protocol has one property that catches everybody the first time:

> **Every response is HTTP 200.** A failure comes back as a normal JSON body,
> `{"error": "Incorrect order ID"}`.

So `if ($http === 200)` tells you nothing, and a naive integration silently treats
`{"error": "Invalid API key"}` as a successful order. This client turns those into an
`ApiException` and leaves everything else as plain arrays.

It handles the two other places the protocol is easy to get wrong:

- **A per-item error inside a batch is data, not a failure.** `multiStatus([1, 2])` where 2 is
  a bad id returns both rows — one with a status, one with an `error` key. Throwing away
  ninety-nine good rows because of one bad id is not what you want.
- **Money is kept as a string.** Panels store money at seven decimal places; casting `"0.0000001"`
  to a float and back is how a rounding bug gets into the one place you least want one.

## API

| method | does |
|---|---|
| `services()` | the whole catalogue: id, name, category, rate per 1000, min/max, dripfeed/refill/cancel flags |
| `add($service, $link, $quantity, $extra = [])` | place an order, returns the id. `$extra` takes `runs` and `interval` for dripfeed |
| `status($order)` | charge, start_count, status, remains, currency |
| `multiStatus($orders)` | up to 100 orders in **one** request, keyed by id |
| `refill($order)` / `multiRefill($orders)` | request a refill |
| `refillStatus($id)` / `multiRefillStatus($ids)` | how a refill is going |
| `cancel($orders)` | ask to cancel up to 100 orders |
| `balance()` | your balance and its currency |

Use the batch calls. Panels rate-limit per key — 120 requests a minute on TGLUX — so a hundred
`status()` calls in a loop will start failing where one `multiStatus()` will not.

## Notes worth reading once

**`cancel()` is a request, not a refund.** Whether an order can still be cancelled is the
upstream provider's decision. A `1` in the response means *asked*; poll `status()` for the
outcome.

**Redirects are disabled on purpose.** The API key travels in the POST body, so following a
redirect would hand your key to whatever host the redirect points at.

**Bring your own HTTP stack** if you want to — the fourth constructor argument takes a
`callable(string $url, array $fields, int $timeout): string`, which is also how the test suite
runs offline:

```php
$smm = new Client($key, Client::DEFAULT_ENDPOINT, 30, function ($url, $fields, $timeout) {
    return $myPsr18Client->send(/* ... */)->getBody()->getContents();
});
```

## Tests

```bash
php tests/run.php      # 18 cases, no network, no dependencies
```

## Requirements

PHP 8.1+, ext-curl, ext-json.

## Where to get a key

Any panel speaking SMM API v2 works. The default endpoint is
[TGLUX](https://smmtglux.com) — a wholesale Telegram services provider (post views, reactions,
members, story views, search-ranking placement) whose API this client was written against and
tested on; keys are on the [API page](https://smmtglux.com/api) after signing in, and the
[full API documentation](https://smmtglux.com/api) lists every action and its parameters.

## Licence

MIT.
