<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/HarnessPersistenceSupport.php';

/**
 * Reject credential material and provider-private reasoning before it crosses
 * any durable Harness persistence boundary.
 *
 * Ordinary prose is deliberately allowed. In particular, the word
 * "reasoning" in novel text is not sensitive by itself; only structured keys,
 * protocol labels/tags and credential-shaped values are rejected.
 */
final class HarnessSensitiveDataGuard
{
    /** @var list<string> */
    private const CREDENTIAL_KEY_FRAGMENTS = [
        'apikey',
        'authorization',
        'authheader',
        'cookie',
        'credential',
        'password',
        'passwd',
        'privatekey',
        'secretkey',
        'accesskey',
        'xamzsignature',
        'xgoogsignature',
    ];

    /** @var list<string> */
    private const CREDENTIAL_KEY_SUFFIXES = [
        'token',
        'secret',
    ];

    /** @var list<string> */
    private const HIDDEN_REASONING_KEYS = [
        'reasoning',
        'thinking',
        'chainofthought',
        'hiddenreasoning',
        'internalreasoning',
        'reasoningcontent',
        'reasoningtrace',
        'rawreasoning',
    ];

    /** @param array<mixed> $value */
    public static function assertSafeStructure(array $value, string $path): void
    {
        foreach ($value as $key => $item) {
            $keyName = is_string($key) ? $key : (string)$key;
            if (is_string($key)) {
                self::assertSafeKey($keyName, "{$path}.{$keyName}");
            }
            if (is_array($item)) {
                self::assertSafeStructure($item, "{$path}.{$keyName}");
            } elseif (is_string($item)) {
                self::assertSafeText($item, "{$path}.{$keyName}");
            }
        }
    }

    public static function assertSafeKey(string $key, string $path): void
    {
        $normalized = self::normalizeKey($key);
        if ($normalized === '') {
            return;
        }
        if (self::isCredentialKey($normalized)) {
            throw new HarnessSensitiveArtifactException(
                "Persisted Harness data contains a forbidden credential field at {$path}."
            );
        }
        if (self::isHiddenReasoningKey($normalized)) {
            throw new HarnessSensitiveArtifactException(
                "Persisted Harness data contains hidden reasoning at {$path}."
            );
        }
    }

    public static function assertSafeLabel(string $value, string $field): void
    {
        $normalized = self::normalizeKey($value);
        if ($normalized !== ''
            && (self::isCredentialKey($normalized) || self::isHiddenReasoningKey($normalized))) {
            throw new HarnessSensitiveArtifactException(
                "Persisted Harness label {$field} identifies sensitive data."
            );
        }
    }

    public static function assertSafeText(string $value, string $field): void
    {
        $patterns = [
            '/(?:^|\R)\s*(?:Proxy-)?Authorization\s*:/i',
            '/(?:^|\R)\s*(?:Cookie|Set-Cookie|X-Api-Key|Api-Key)\s*:/i',
            '/(?:^|\R)\s*(?:chain[ _-]*of[ _-]*thought|hidden[ _-]*reasoning|internal[ _-]*reasoning|reasoning[ _-]*(?:content|trace))\s*[:=]/i',
            '/<(?:thinking|reasoning)>/i',
            '/-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----/',
            '/\bsk-[A-Za-z0-9_-]{16,}\b/',
            '/\bAKIA[0-9A-Z]{16}\b/',
            '/\bAIza[0-9A-Za-z_-]{30,}\b/',
            '/\bgh[pousr]_[A-Za-z0-9]{20,}\b/',
            '/\bxox(?:a|b|p|r|s)-[A-Za-z0-9-]{16,}\b/',
            '/\bBearer\s+[A-Za-z0-9._~+\/-]{12,}={0,2}\b/i',
            '/(?:api[ _-]*key|access[ _-]*token|refresh[ _-]*token|client[ _-]*secret|password|x-amz-signature)["\']?\s*[:=]\s*["\']?[^\s"\';,]{8,}/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                throw new HarnessSensitiveArtifactException(
                    "Persisted Harness field {$field} contains credential or hidden-reasoning material."
                );
            }
        }
    }

    public static function assertSafeStorageUri(string $uri): void
    {
        $parts = parse_url($uri);
        if ($parts === false) {
            throw new InvalidArgumentException('storage_uri is malformed.');
        }
        if (($parts['user'] ?? '') !== '' || ($parts['pass'] ?? '') !== '') {
            throw new HarnessSensitiveArtifactException(
                'Artifact storage URI contains userinfo credentials.'
            );
        }

        foreach (['query', 'fragment'] as $component) {
            $encoded = $parts[$component] ?? null;
            if (!is_string($encoded) || $encoded === '') {
                continue;
            }
            foreach (preg_split('/[&;]/', $encoded) ?: [] as $pair) {
                $rawName = explode('=', $pair, 2)[0];
                $name = rawurldecode(str_replace('+', ' ', $rawName));
                $normalized = self::normalizeKey($name);
                if ($normalized === '') {
                    continue;
                }
                if (self::isCredentialKey($normalized)
                    || in_array($normalized, ['sig', 'signature', 'keypairid'], true)) {
                    throw new HarnessSensitiveArtifactException(
                        "Artifact storage URI contains credential query field {$name}."
                    );
                }
            }
        }
    }

    private static function normalizeKey(string $key): string
    {
        return strtolower((string)preg_replace('/[^a-z0-9]+/i', '', $key));
    }

    private static function isCredentialKey(string $normalized): bool
    {
        foreach (self::CREDENTIAL_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }
        foreach (self::CREDENTIAL_KEY_SUFFIXES as $suffix) {
            if (str_ends_with($normalized, $suffix)) {
                return true;
            }
        }
        return false;
    }

    private static function isHiddenReasoningKey(string $normalized): bool
    {
        if (in_array($normalized, self::HIDDEN_REASONING_KEYS, true)) {
            return true;
        }
        foreach (self::HIDDEN_REASONING_KEYS as $forbidden) {
            // Bare "reasoning" and "thinking" are exact protocol keys only:
            // reasoning_effort and ordinary prose metadata are not hidden CoT.
            if (!in_array($forbidden, ['reasoning', 'thinking'], true)
                && str_contains($normalized, $forbidden)) {
                return true;
            }
        }
        return false;
    }

    private function __construct()
    {
    }
}
