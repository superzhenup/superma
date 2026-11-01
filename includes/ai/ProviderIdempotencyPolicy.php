<?php
declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

/** Explicit endpoint capabilities, frozen when an AIClient is constructed. */
final readonly class ProviderIdempotencyPolicy
{
    /** @param array<string,string> $endpoints Exact request URL => supported header. */
    public function __construct(private array $endpoints = [])
    {
        if (count($endpoints) > 64) {
            throw new InvalidArgumentException('Provider idempotency endpoint list is too large.');
        }
        foreach ($endpoints as $endpoint => $header) {
            $parts = is_string($endpoint) ? parse_url($endpoint) : false;
            if (!is_array($parts) || strlen($endpoint) > 2048
                || preg_match('/[\x00-\x20\x7f]/', $endpoint) === 1
                || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['query']) || isset($parts['fragment'])
                || empty($parts['host']) || str_contains($parts['host'], '*')
                || empty($parts['path'])
                || !in_array(strtolower($parts['scheme'] ?? ''), ['https', 'http'], true)
                || (strtolower($parts['scheme'] ?? '') === 'http'
                    && !in_array(strtolower(trim($parts['host'], '[]')), ['localhost', '127.0.0.1', '::1'], true))) {
                throw new InvalidArgumentException('Provider idempotency requires an exact HTTPS request endpoint (HTTP only on loopback).');
            }
            // Do not allow arbitrary headers (especially credentials) through this setting.
            if ($header !== 'Idempotency-Key') {
                throw new InvalidArgumentException('Unsupported provider idempotency header.');
            }
        }
    }

    public static function assertOperationKey(?string $operationKey): void
    {
        if ($operationKey !== null
            && (strlen($operationKey) < 1 || strlen($operationKey) > 191
                || preg_match('/^[\x20-\x7E]+$/D', $operationKey) !== 1)) {
            throw new InvalidArgumentException('Provider operation key must be 1..191 printable ASCII bytes.');
        }
    }

    /** @return list<string> Only idempotency metadata, never credentials. */
    public function headers(string $endpoint, string $model, ?string $operationKey): array
    {
        self::assertOperationKey($operationKey);
        if ($operationKey === null || !isset($this->endpoints[$endpoint])) return [];
        // Do not leak internal run/job coordinates; separate models sharing a gateway.
        $key = 'novel-v1-' . hash('sha256', $endpoint . "\0" . $model . "\0" . $operationKey);
        return [$this->endpoints[$endpoint] . ': ' . $key];
    }
}
