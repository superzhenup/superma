<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/persistence/HarnessPersistenceSupport.php';
require_once dirname(__DIR__) . '/persistence/HarnessSensitiveDataGuard.php';

/**
 * Canonical, sensitive-data-safe JSON helpers for durable runtime values.
 *
 * Runtime state deliberately accepts only JSON scalars and arrays. Objects,
 * resources and non-finite floats are rejected before a checkpoint is written.
 */
final class RuntimeData
{
    public const DEFAULT_MAX_BYTES = 262144;

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    public static function normalizeArray(
        array $value,
        string $path,
        int $maximumBytes = self::DEFAULT_MAX_BYTES
    ): array {
        if ($maximumBytes < 1) {
            throw new InvalidArgumentException('Runtime JSON maximum size must be positive.');
        }

        HarnessSensitiveDataGuard::assertSafeStructure($value, $path);
        // Runtime checkpoints live in a native MySQL JSON column. Normalize
        // integral floats before hashing/size checks so a restart observes the
        // same durable value that was authenticated before persistence.
        $json = HarnessPersistenceValue::canonicalMysqlJson($value);
        if (strlen($json) > $maximumBytes) {
            throw new InvalidArgumentException('Runtime JSON value exceeds its durable size limit.');
        }
        HarnessSensitiveDataGuard::assertSafeText($json, $path);

        $normalized = HarnessPersistenceValue::decodeJsonArray($json, $path);
        HarnessSensitiveDataGuard::assertSafeStructure($normalized, $path);
        return $normalized;
    }

    /** @param array<mixed> $value */
    public static function canonicalJson(array $value, string $path): string
    {
        return HarnessPersistenceValue::canonicalMysqlJson(
            self::normalizeArray($value, $path)
        );
    }

    /** @param array<mixed> $value */
    public static function hashArray(array $value, string $path): string
    {
        return hash('sha256', self::canonicalJson($value, $path));
    }

    private function __construct()
    {
    }
}
