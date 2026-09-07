<?php

declare(strict_types=1);

namespace Tglux\Smm;

/**
 * A client for the standard SMM "API v2" that most social-media-marketing panels expose.
 *
 * It defaults to https://smmtglux.com but the protocol is the same everywhere, so point it at
 * whichever panel you buy from:
 *
 *     $smm = new Client('YOUR-API-KEY');                          // TGLUX
 *     $smm = new Client('YOUR-API-KEY', 'https://other.panel/api/v2');
 *
 * Every call POSTs `key` and `action` as form fields and returns decoded JSON. There are no
 * dependencies beyond ext-curl and ext-json.
 */
final class Client
{
    public const DEFAULT_ENDPOINT = 'https://smmtglux.com/api/v2';

    private string $key;
    private string $endpoint;
    private int $timeout;
    private ?\Closure $transport;

    /**
     * @param string        $key       API key from the panel's API page.
     * @param string        $endpoint  Full URL of the v2 endpoint.
     * @param int           $timeout   Seconds to wait for a response.
     * @param callable|null $transport Optional fn(string $url, array $fields, int $timeout): string,
     *                                 for tests or for routing through your own HTTP stack.
     */
    public function __construct(
        string $key,
        string $endpoint = self::DEFAULT_ENDPOINT,
        int $timeout = 30,
        ?callable $transport = null
    ) {
        $key = trim($key);
        if ($key === '') {
            throw new \InvalidArgumentException('An API key is required.');
        }
        $this->key       = $key;
        $this->endpoint  = rtrim($endpoint, '/');
        $this->timeout   = max(1, $timeout);
        $this->transport = $transport === null ? null : \Closure::fromCallable($transport);
    }

    // ---------------------------------------------------------------- catalogue

    /**
     * The whole service list: id, name, category, rate per 1000, min/max and the
     * dripfeed / refill / cancel flags.
     *
     * @return array<int, array<string, mixed>>
     */
    public function services(): array
    {
        /** @var array<int, array<string, mixed>> $r */
        $r = $this->call('services');
        return $r;
    }

    // ------------------------------------------------------------------- orders

    /**
     * Place an order. Returns the order id.
     *
     * $extra accepts the optional dripfeed fields the panel supports:
     *   'runs'     => how many times to run
     *   'interval' => minutes between runs
     *
     * @param array<string, scalar> $extra
     */
    public function add(int $service, string $link, int $quantity, array $extra = []): int
    {
        $res = $this->call('add', $extra + [
            'service'  => $service,
            'link'     => $link,
            'quantity' => $quantity,
        ]);
        if (!isset($res['order'])) {
            throw new ApiException('The panel accepted the request but returned no order id.');
        }
        return (int)$res['order'];
    }

    /**
     * One order's status: charge, start_count, status, remains, currency.
     *
     * @return array<string, mixed>
     */
    public function status(int $order): array
    {
        /** @var array<string, mixed> $r */
        $r = $this->call('status', ['order' => $order]);
        return $r;
    }

    /**
     * Up to 100 orders in one request, keyed by order id.
     *
     * Prefer this over a loop: the panel rate-limits by key (120 requests a minute on TGLUX),
     * so a hundred separate status calls will start failing while one call will not. Entries
     * for unknown ids come back as ['error' => 'Incorrect order ID'] rather than throwing -
     * one bad id in a batch should not lose you the other ninety-nine.
     *
     * @param  int[] $orders
     * @return array<string, array<string, mixed>>
     */
    public function multiStatus(array $orders): array
    {
        /** @var array<string, array<string, mixed>> $r */
        $r = $this->call('status', ['orders' => $this->idList($orders)]);
        return $r;
    }

    // ------------------------------------------------------------------ refills

    /** Request a refill for one order. Returns the refill id. */
    public function refill(int $order): int
    {
        $res = $this->call('refill', ['order' => $order]);
        if (!isset($res['refill'])) {
            throw new ApiException('The panel returned no refill id.');
        }
        return (int)$res['refill'];
    }

    /**
     * Refill up to 100 orders. Each entry is ['order' => id, 'refill' => id|['error' => ...]].
     *
     * @param  int[] $orders
     * @return array<int, array<string, mixed>>
     */
    public function multiRefill(array $orders): array
    {
        /** @var array<int, array<string, mixed>> $r */
        $r = $this->call('refill', ['orders' => $this->idList($orders)]);
        return $r;
    }

    /** Status of one refill request. */
    public function refillStatus(int $refill): string
    {
        $res = $this->call('refill_status', ['refill' => $refill]);
        return (string)($res['status'] ?? '');
    }

    /**
     * @param  int[] $refills
     * @return array<int, array<string, mixed>>
     */
    public function multiRefillStatus(array $refills): array
    {
        /** @var array<int, array<string, mixed>> $r */
        $r = $this->call('refill_status', ['refills' => $this->idList($refills)]);
        return $r;
    }

    // ------------------------------------------------------------------- cancel

    /**
     * Ask to cancel up to 100 orders. Each entry is ['order' => id, 'cancel' => 1|['error' => ...]].
     *
     * Note this is a REQUEST. Whether an order can still be cancelled is the provider's
     * decision, so a 1 here means "asked", not "refunded" - poll status() for the outcome.
     *
     * @param  int[] $orders
     * @return array<int, array<string, mixed>>
     */
    public function cancel(array $orders): array
    {
        /** @var array<int, array<string, mixed>> $r */
        $r = $this->call('cancel', ['orders' => $this->idList($orders)]);
        return $r;
    }

    // ------------------------------------------------------------------ account

    /**
     * ['balance' => '12.34', 'currency' => 'USD'].
     *
     * The balance is a STRING on purpose - the panel keeps money at seven decimal places and
     * casting it to a float here would quietly round money in the one place you least want it.
     *
     * @return array<string, string>
     */
    public function balance(): array
    {
        /** @var array<string, string> $r */
        $r = $this->call('balance');
        return $r;
    }

    // ---------------------------------------------------------------- internals

    /**
     * @param  array<string, scalar> $params
     * @return array<mixed>
     */
    public function call(string $action, array $params = []): array
    {
        $fields = ['key' => $this->key, 'action' => $action] + $params;
        $raw    = $this->transport !== null
            ? ($this->transport)($this->endpoint, $fields, $this->timeout)
            : $this->post($fields);

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new ApiException('The panel returned something that is not JSON: ' . $this->snippet($raw));
        }
        // A failure is a 200 with {"error": ...}. Only treat it as one when `error` is the
        // whole answer - a per-item error inside a batch is data, not a failed request.
        if (isset($data['error']) && count($data) === 1) {
            throw new ApiException((string)$data['error']);
        }
        return $data;
    }

    /** @param array<string, scalar> $fields */
    private function post(array $fields): string
    {
        $ch = curl_init($this->endpoint);
        if ($ch === false) {
            throw new ApiException('Could not initialise cURL.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_USERAGENT      => 'smm-api-php/1.0 (+https://github.com/SmmTGlux/smm-api-php)',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            // The key travels in the body, so a redirect to another host would hand it over.
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new ApiException('Request failed: ' . $err);
        }
        curl_close($ch);
        return (string)$body;
    }

    /** @param int[] $ids */
    private function idList(array $ids): string
    {
        $clean = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $clean[] = $id;
            }
        }
        $clean = array_values(array_unique($clean));
        if ($clean === []) {
            throw new \InvalidArgumentException('At least one id is required.');
        }
        if (count($clean) > 100) {
            throw new \InvalidArgumentException('The API accepts at most 100 ids per request, got ' . count($clean) . '.');
        }
        return implode(',', $clean);
    }

    private function snippet(string $raw): string
    {
        $raw = trim(preg_replace('/\s+/', ' ', $raw) ?? '');
        return strlen($raw) > 200 ? substr($raw, 0, 200) . '…' : $raw;
    }
}
