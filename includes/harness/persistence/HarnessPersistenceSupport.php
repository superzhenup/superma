<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

class HarnessPersistenceException extends RuntimeException
{
}

final class HarnessIdempotencyConflictException extends HarnessPersistenceException
{
}

final class HarnessLeaseLostException extends HarnessPersistenceException
{
}

final class HarnessStateTransitionException extends HarnessPersistenceException
{
}

final class HarnessSensitiveArtifactException extends HarnessPersistenceException
{
}

/**
 * Small transaction/query boundary used by all M62 repositories.
 *
 * Production wraps a PDO connection. Tests may provide a deterministic
 * in-memory driver without loading config.php or connecting to MySQL.
 */
interface HarnessPersistenceDriver
{
    /**
     * The PDO implementation may replay an operation after an exact MySQL
     * deadlock when it owns the top-level transaction. Callbacks must therefore
     * keep non-transactional external side effects outside this boundary.
     */
    public function transactional(Closure $operation): mixed;

    /** @param array<int|string,mixed> $params @return array<string,mixed>|null */
    public function fetchOne(string $sql, array $params = []): ?array;

    /** @param array<int|string,mixed> $params @return list<array<string,mixed>> */
    public function fetchAll(string $sql, array $params = []): array;

    /** @param array<int|string,mixed> $params */
    public function execute(string $sql, array $params = []): int;
}

final class PdoHarnessPersistenceDriver implements HarnessPersistenceDriver
{
    private const MYSQL_DEADLOCK_MAX_ATTEMPTS = 3;

    private int $savepointSequence = 0;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Budget reservations must commit independently before any provider I/O. */
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function transactional(Closure $operation): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            // InnoDB may choose either participant as the victim of a valid
            // lock-order cycle. A 1213 guarantees that this transaction was
            // rolled back, so a bounded replay of the whole DB-only operation
            // is safe. Never replay nested/savepoint work: a MySQL deadlock can
            // invalidate its caller's entire outer transaction.
            for ($attempt = 1; $attempt <= self::MYSQL_DEADLOCK_MAX_ATTEMPTS; ++$attempt) {
                if (!$this->pdo->beginTransaction()) {
                    throw new HarnessPersistenceException(
                        'Could not begin Harness persistence transaction.'
                    );
                }

                try {
                    $result = $operation();
                    if (!$this->pdo->commit()) {
                        throw new HarnessPersistenceException(
                            'Could not commit Harness persistence transaction.'
                        );
                    }
                    return $result;
                } catch (Throwable $exception) {
                    if ($this->pdo->inTransaction()) {
                        try {
                            if (!$this->pdo->rollBack()) {
                                throw new RuntimeException('PDO rollBack() returned false.');
                            }
                        } catch (Throwable $rollbackFailure) {
                            throw new HarnessPersistenceException(
                                'Harness transaction failed and could not be rolled back: '
                                    . $rollbackFailure->getMessage(),
                                0,
                                $exception
                            );
                        }
                    }
                    if ($attempt < self::MYSQL_DEADLOCK_MAX_ATTEMPTS
                        && self::isMysqlDeadlock($exception)) {
                        usleep($attempt * 1_000);
                        continue;
                    }
                    throw $exception;
                }
            }

            throw new LogicException('Harness transaction retry loop did not terminate.');
        }

        // Repositories are intentionally composable. When a repository method is
        // called inside an existing application transaction, a savepoint prevents
        // callers from catching an inner failure and later committing its partial
        // writes (for example, an advanced event sequence without its event row).
        $savepoint = 'harness_sp_' . (++$this->savepointSequence);
        $this->executeTransactionControl('SAVEPOINT ' . $savepoint);
        try {
            $result = $operation();
            $this->executeTransactionControl('RELEASE SAVEPOINT ' . $savepoint);
            return $result;
        } catch (Throwable $exception) {
            // InnoDB has already rolled back the entire transaction for 1213;
            // touching the now-invalid savepoint can mask the vendor error.
            // Let the outer owning driver replay the complete transaction.
            if (self::isMysqlDeadlock($exception)) {
                throw $exception;
            }
            if ($this->pdo->inTransaction()) {
                try {
                    $this->executeTransactionControl('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $this->executeTransactionControl('RELEASE SAVEPOINT ' . $savepoint);
                } catch (Throwable $rollbackFailure) {
                    throw new HarnessPersistenceException(
                        'Harness nested transaction failed and its savepoint could not be rolled back: '
                            . $rollbackFailure->getMessage(),
                        0,
                        $exception
                    );
                }
            }
            throw $exception;
        }
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $statement = $this->prepareAndExecute($sql, $params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $statement = $this->prepareAndExecute($sql, $params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            throw new HarnessPersistenceException('Harness query did not return an array result.');
        }
        return $rows;
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->prepareAndExecute($sql, $params)->rowCount();
    }

    /** @param array<int|string,mixed> $params */
    private function prepareAndExecute(string $sql, array $params): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if (!$statement instanceof PDOStatement || !$statement->execute($params)) {
            throw new HarnessPersistenceException('Harness PDO statement execution failed.');
        }
        return $statement;
    }

    private function executeTransactionControl(string $sql): void
    {
        if ($this->pdo->exec($sql) === false) {
            throw new HarnessPersistenceException('Harness transaction control statement failed.');
        }
    }

    private static function isMysqlDeadlock(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if (!$current instanceof PDOException) {
                continue;
            }
            $errorInfo = $current->errorInfo;
            if (is_array($errorInfo)
                && strtoupper((string)($errorInfo[0] ?? '')) === '40001'
                && (int)($errorInfo[1] ?? 0) === 1213) {
                return true;
            }
        }
        return false;
    }
}

final class HarnessPersistenceValue
{
    private const MYSQL_DATE_PATTERN = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/D';

    public static function driver(PDO|HarnessPersistenceDriver $connection): HarnessPersistenceDriver
    {
        return $connection instanceof PDO
            ? new PdoHarnessPersistenceDriver($connection)
            : $connection;
    }

    public static function positiveInt(mixed $value, string $field): int
    {
        $parsed = self::integer($value, $field);
        if ($parsed <= 0) {
            throw new InvalidArgumentException("{$field} must be a positive integer.");
        }
        return $parsed;
    }

    public static function optionalPositiveInt(mixed $value, string $field): ?int
    {
        return $value === null || $value === '' ? null : self::positiveInt($value, $field);
    }

    public static function optionalNonNegativeInt(mixed $value, string $field): ?int
    {
        return $value === null || $value === '' ? null : self::nonNegativeInt($value, $field);
    }

    public static function nonNegativeInt(mixed $value, string $field): int
    {
        $parsed = self::integer($value, $field);
        if ($parsed < 0) {
            throw new InvalidArgumentException("{$field} must be a non-negative integer.");
        }
        return $parsed;
    }

    public static function boundedInt(mixed $value, string $field, int $minimum, int $maximum): int
    {
        $parsed = self::integer($value, $field);
        if ($parsed < $minimum || $parsed > $maximum) {
            throw new InvalidArgumentException("{$field} must be between {$minimum} and {$maximum}.");
        }
        return $parsed;
    }

    public static function bool(mixed $value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === '0') {
            return false;
        }
        if ($value === 1 || $value === '1') {
            return true;
        }
        throw new InvalidArgumentException("{$field} must be boolean/0/1.");
    }

    public static function string(
        mixed $value,
        string $field,
        int $minimumLength = 1,
        int $maximumLength = 255
    ): string {
        if (!is_string($value)
            || strlen($value) < $minimumLength
            || strlen($value) > $maximumLength
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException("{$field} is malformed.");
        }
        return $value;
    }

    public static function optionalString(
        mixed $value,
        string $field,
        int $maximumLength = 255
    ): ?string {
        return $value === null || $value === ''
            ? null
            : self::string($value, $field, 1, $maximumLength);
    }

    public static function asciiString(
        mixed $value,
        string $field,
        int $minimumLength = 1,
        int $maximumLength = 255
    ): string {
        $string = self::string($value, $field, $minimumLength, $maximumLength);
        if (preg_match('/^[\x20-\x7E]+$/D', $string) !== 1) {
            throw new InvalidArgumentException("{$field} must contain printable ASCII only.");
        }
        return $string;
    }

    public static function optionalAsciiString(
        mixed $value,
        string $field,
        int $maximumLength = 255
    ): ?string {
        return $value === null || $value === ''
            ? null
            : self::asciiString($value, $field, 1, $maximumLength);
    }

    public static function identifier(mixed $value, string $field, int $maximumLength = 64): string
    {
        $identifier = self::string($value, $field, 1, $maximumLength);
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:@\/-]*$/D', $identifier) !== 1) {
            throw new InvalidArgumentException("{$field} is not a canonical identifier.");
        }
        return $identifier;
    }

    public static function uid(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            throw new InvalidArgumentException("{$field} must be 32 lowercase hexadecimal characters.");
        }
        return $value;
    }

    public static function hash(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new InvalidArgumentException("{$field} must be a lowercase SHA-256 hash.");
        }
        return $value;
    }

    public static function optionalHash(mixed $value, string $field): ?string
    {
        return $value === null || $value === '' ? null : self::hash($value, $field);
    }

    public static function decimal(mixed $value, string $field): string
    {
        if (is_int($value)) {
            $value = (string)$value;
        }
        if (!is_string($value)
            || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?$/D', $value) !== 1) {
            throw new InvalidArgumentException("{$field} must be a non-negative decimal string.");
        }
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }
        return $value === '' ? '0' : $value;
    }

    public static function mysqlDate(DateTimeInterface $value): string
    {
        return DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
    }

    public static function optionalMysqlDate(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match(self::MYSQL_DATE_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException("{$field} is not a MySQL UTC timestamp.");
        }
        return $value;
    }

    public static function canonicalJson(mixed $value): string
    {
        return json_encode(
            self::normalizeJson($value),
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Canonical bytes for values persisted in a native MySQL JSON column.
     *
     * MySQL 5.7 does not preserve the lexical distinction between integral
     * JSON numbers such as `1.0`/`-0.0` and `1`/`0`.  Any durable checksum or
     * idempotency comparison must therefore bind to the representation that a
     * subsequent SELECT can reproduce.  Keep canonicalJson() type-preserving
     * for non-database protocol identities and opt into this method only at a
     * native JSON persistence boundary.
     */
    public static function canonicalMysqlJson(mixed $value): string
    {
        return self::canonicalJson(self::normalizeMysqlJsonNumbers($value));
    }

    /**
     * WritePolicySnapshot carries its own authenticated checksum. Generic M62
     * payloads do not, so they retain the canonical-payload SHA-256 identity.
     * This lets M63 repositories compose with the frozen P0 policy contract
     * without weakening older DTOs.
     *
     * @param array<mixed> $snapshot
     */
    public static function policySnapshotHash(array $snapshot, string $canonicalJson): string
    {
        $embedded = $snapshot['checksum'] ?? null;
        if ($embedded !== null) {
            return self::hash($embedded, 'policy_snapshot.checksum');
        }
        return hash('sha256', $canonicalJson);
    }

    /** @return array<mixed> */
    public static function decodeJsonArray(mixed $value, string $field): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException("{$field} must contain a JSON array/object.");
        }
        try {
            $decoded = json_decode($value, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException("{$field} contains malformed JSON.", 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new InvalidArgumentException("{$field} must decode to an array/object.");
        }
        return $decoded;
    }

    /** @param array<string,mixed> $row @param list<string> $fields */
    public static function requireFields(array $row, array $fields, string $record): void
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $row)) {
                throw new InvalidArgumentException("{$record} row is missing {$field}.");
            }
        }
    }

    private static function integer(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT);
            if (is_int($parsed)) {
                return $parsed;
            }
        }
        throw new InvalidArgumentException("{$field} must be a canonical integer.");
    }

    private static function normalizeJson(mixed $value): mixed
    {
        if ($value === null || is_string($value) || is_int($value) || is_bool($value)) {
            return $value;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException('JSON numbers must be finite.');
            }
            return $value;
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('Harness JSON values must be scalar or arrays.');
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'normalizeJson'], $value);
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) && !is_int($key)) {
                throw new InvalidArgumentException('Harness JSON object keys must be strings.');
            }
            $canonicalKey = (string)$key;
            if (array_key_exists($canonicalKey, $normalized)) {
                throw new InvalidArgumentException('Harness JSON object has colliding keys.');
            }
            $normalized[$canonicalKey] = self::normalizeJson($item);
        }
        ksort($normalized, SORT_STRING);
        return $normalized;
    }

    private static function normalizeMysqlJsonNumbers(mixed $value): mixed
    {
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException('JSON numbers must be finite.');
            }
            if ($value === 0.0) {
                return 0;
            }
            // (float) PHP_INT_MAX rounds to the first out-of-range value on a
            // 64-bit runtime, so the strict upper bound avoids an overflowing
            // float-to-int cast while retaining every exactly representable
            // in-range integral float.
            if (floor($value) === $value
                && $value >= (float) PHP_INT_MIN
                && $value < (float) PHP_INT_MAX) {
                return (int) $value;
            }
            return $value;
        }
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::normalizeMysqlJsonNumbers($item);
        }
        return $value;
    }
}
