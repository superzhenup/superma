<?php
declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/db.php';

interface HarnessEmergencyEpochStore
{
    public function begin(): void;

    /** Return the canonical stored value while holding its row/gap lock. */
    public function readForUpdate(): ?string;

    public function insertInitial(string $value): bool;

    public function compareAndSet(string $expected, string $replacement): bool;

    public function commit(): void;

    public function rollBack(): void;
}

interface HarnessEmergencyIncidentStore extends HarnessEmergencyEpochStore
{
    public function readIncidentForUpdate(string $scopeFingerprint): ?string;

    public function insertIncident(string $scopeFingerprint, string $value): bool;

    public function compareAndSetIncident(
        string $scopeFingerprint,
        string $expected,
        string $replacement
    ): bool;
}

final class DbHarnessEmergencyEpochStore implements HarnessEmergencyIncidentStore
{
    private PDO $pdo;

    public function begin(): void
    {
        $this->pdo = DB::connect();
        if ($this->pdo->inTransaction()) {
            throw new LogicException('Emergency epoch bump requires an independent transaction.');
        }
        $this->pdo->beginTransaction();
    }

    public function readForUpdate(): ?string
    {
        $row = DB::fetch(
            'SELECT setting_value FROM system_settings WHERE setting_key=? FOR UPDATE',
            [HarnessEmergencyStop::SETTING_KEY]
        );
        if (!is_array($row)) return null;
        return is_string($row['setting_value'] ?? null)
            ? $row['setting_value']
            : null;
    }

    public function insertInitial(string $value): bool
    {
        return DB::execute(
            'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)',
            [HarnessEmergencyStop::SETTING_KEY, $value]
        ) === 1;
    }

    public function compareAndSet(string $expected, string $replacement): bool
    {
        return DB::execute(
            'UPDATE system_settings SET setting_value=? '
            . 'WHERE setting_key=? AND setting_value=?',
            [$replacement, HarnessEmergencyStop::SETTING_KEY, $expected]
        ) === 1;
    }

    public function readIncidentForUpdate(string $scopeFingerprint): ?string
    {
        $row = DB::fetch(
            'SELECT setting_value FROM system_settings WHERE setting_key=? FOR UPDATE',
            [HarnessEmergencyStop::incidentSettingKey($scopeFingerprint)]
        );
        if (!is_array($row)) {
            return null;
        }
        return is_string($row['setting_value'] ?? null)
            ? $row['setting_value']
            : null;
    }

    public function insertIncident(string $scopeFingerprint, string $value): bool
    {
        return DB::execute(
            'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)',
            [HarnessEmergencyStop::incidentSettingKey($scopeFingerprint), $value]
        ) === 1;
    }

    public function compareAndSetIncident(
        string $scopeFingerprint,
        string $expected,
        string $replacement
    ): bool {
        return DB::execute(
            'UPDATE system_settings SET setting_value=? '
                . 'WHERE setting_key=? AND setting_value=?',
            [
                $replacement,
                HarnessEmergencyStop::incidentSettingKey($scopeFingerprint),
                $expected,
            ]
        ) === 1;
    }

    public function commit(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Emergency epoch transaction disappeared before commit.');
        }
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}

/**
 * The only application writer for the emergency-stop epoch.
 *
 * A row lock serializes cooperating bumpers and a value CAS makes any legacy
 * or non-cooperating concurrent writer fail closed. There is deliberately no
 * set/reset/decrement API, preventing a 0 -> 1 -> 0 ABA from re-authorizing a
 * worker that captured epoch 0.
 */
final class HarnessEmergencyStop
{
    public const SETTING_KEY = 'ws_harness_emergency_stop_epoch';
    public const INCIDENT_SETTING_PREFIX = 'ws_harness_stop_incident_';
    public const MAX_EPOCH = 2147483647;

    /** @return array{previous_epoch:int,current_epoch:int} */
    public static function bump(?HarnessEmergencyEpochStore $store = null): array
    {
        $store ??= new DbHarnessEmergencyEpochStore();
        $store->begin();
        try {
            [$previous, $current] = self::advanceLockedEpoch($store);
            $store->commit();
        } catch (Throwable $exception) {
            $store->rollBack();
            throw $exception;
        }

        self::clearCaches();
        return [
            'previous_epoch' => $previous,
            'current_epoch' => $current,
        ];
    }

    /**
     * Atomically latch one integrity incident and bump at most once while that
     * scope remains active. A healthy applied report must call clearIncident()
     * before a later recurrence can authorize another bump.
     *
     * @param list<string> $breaches
     * @return array{applied:bool,deduplicated:bool,previous_epoch:int,current_epoch:int,breaches:list<string>}
     */
    public static function bumpOnce(
        string $scopeFingerprint,
        array $breaches,
        ?HarnessEmergencyIncidentStore $store = null
    ): array {
        self::assertFingerprint($scopeFingerprint);
        $breaches = self::normalizeBreaches($breaches);
        if ($breaches === []) {
            throw new InvalidArgumentException(
                'An automatic stop incident requires at least one verified breach.'
            );
        }
        $store ??= new DbHarnessEmergencyEpochStore();
        $store->begin();
        try {
            // Every incident mutation locks the already-migrated singleton
            // epoch first. This gives bumpOnce/clearIncident one global InnoDB
            // lock order and prevents the classic cycle where two callers each
            // hold a missing incident-key gap lock and then contend on epoch.
            $storedEpoch = $store->readForUpdate();
            $lockedEpoch = self::requireLockedEpoch($storedEpoch);
            $storedDisposition = $store->readIncidentForUpdate($scopeFingerprint);
            if ($storedDisposition !== null) {
                $disposition = self::parseDisposition($storedDisposition);
                self::assertDispositionCoveredByEpoch($disposition, $lockedEpoch);
                if ($disposition['state'] === 'active') {
                    $store->commit();
                    return [
                        'applied' => false,
                        'deduplicated' => true,
                        'previous_epoch' => $disposition['previous_epoch'],
                        'current_epoch' => $disposition['current_epoch'],
                        'breaches' => $disposition['breaches'],
                    ];
                }
            }

            [$previous, $current] = self::advanceLockedEpochFromStored(
                $store,
                $storedEpoch
            );
            $replacement = self::dispositionJson(
                'active',
                $breaches,
                $previous,
                $current
            );
            $written = $storedDisposition === null
                ? $store->insertIncident($scopeFingerprint, $replacement)
                : $store->compareAndSetIncident(
                    $scopeFingerprint,
                    $storedDisposition,
                    $replacement
                );
            if (!$written) {
                throw new RuntimeException(
                    'Automatic stop incident latch changed outside the atomic protocol.'
                );
            }
            $store->commit();
        } catch (Throwable $exception) {
            $store->rollBack();
            throw $exception;
        }

        self::clearCaches();
        return [
            'applied' => true,
            'deduplicated' => false,
            'previous_epoch' => $previous,
            'current_epoch' => $current,
            'breaches' => $breaches,
        ];
    }

    /** @return array{cleared:bool,previous_state:string|null} */
    public static function clearIncident(
        string $scopeFingerprint,
        ?HarnessEmergencyIncidentStore $store = null
    ): array {
        self::assertFingerprint($scopeFingerprint);
        $store ??= new DbHarnessEmergencyEpochStore();
        $store->begin();
        try {
            // Keep the same epoch -> incident order as bumpOnce().  A healthy
            // re-arm can therefore never deadlock with a concurrent breach.
            $storedEpoch = $store->readForUpdate();
            $lockedEpoch = self::requireLockedEpoch($storedEpoch);
            $stored = $store->readIncidentForUpdate($scopeFingerprint);
            if ($stored === null) {
                $store->commit();
                return ['cleared' => false, 'previous_state' => null];
            }
            $disposition = self::parseDisposition($stored);
            self::assertDispositionCoveredByEpoch($disposition, $lockedEpoch);
            if ($disposition['state'] === 'cleared') {
                $store->commit();
                return ['cleared' => false, 'previous_state' => 'cleared'];
            }
            $replacement = self::dispositionJson(
                'cleared',
                $disposition['breaches'],
                $disposition['previous_epoch'],
                $disposition['current_epoch']
            );
            if (!$store->compareAndSetIncident(
                $scopeFingerprint,
                $stored,
                $replacement
            )) {
                throw new RuntimeException(
                    'Automatic stop incident clear lost its atomic latch.'
                );
            }
            $store->commit();
            return ['cleared' => true, 'previous_state' => 'active'];
        } catch (Throwable $exception) {
            $store->rollBack();
            throw $exception;
        }
    }

    public static function isProtectedSetting(string $key): bool
    {
        return hash_equals(self::SETTING_KEY, $key)
            || str_starts_with($key, self::INCIDENT_SETTING_PREFIX);
    }

    public static function incidentSettingKey(string $scopeFingerprint): string
    {
        self::assertFingerprint($scopeFingerprint);
        return self::INCIDENT_SETTING_PREFIX . $scopeFingerprint;
    }

    /**
     * Content-address one automatic-stop scope. Null coordinates are durable
     * wildcards; admission checks derive every exact/wildcard combination for
     * the Run they are about to create or claim.
     */
    public static function scopeFingerprint(
        ?int $novelId,
        ?string $workflowName,
        ?string $workflowVersion
    ): string {
        if ($novelId !== null && $novelId <= 0) {
            throw new InvalidArgumentException(
                'Automatic stop scope novel_id must be positive or null.'
            );
        }
        self::assertOptionalScopeIdentifier($workflowName, 'workflow_name', 64);
        self::assertOptionalScopeIdentifier($workflowVersion, 'workflow_version', 32);
        return hash('sha256', json_encode([
            'schema' => 'harness.automatic_stop.scope.v1',
            'novel_id' => $novelId,
            'workflow_name' => $workflowName,
            'workflow_version' => $workflowVersion,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    public static function matchingScopeFingerprints(
        int $novelId,
        string $workflowName,
        string $workflowVersion
    ): array {
        // Validate the exact Run scope before deriving wildcard coordinates.
        self::scopeFingerprint($novelId, $workflowName, $workflowVersion);
        $fingerprints = [];
        foreach ([$novelId, null] as $candidateNovelId) {
            foreach ([$workflowName, null] as $candidateWorkflowName) {
                foreach ([$workflowVersion, null] as $candidateWorkflowVersion) {
                    $fingerprint = self::scopeFingerprint(
                        $candidateNovelId,
                        $candidateWorkflowName,
                        $candidateWorkflowVersion
                    );
                    $fingerprints[$fingerprint] = $fingerprint;
                }
            }
        }
        sort($fingerprints, SORT_STRING);
        return array_values($fingerprints);
    }

    public static function normalizeEpoch(mixed $value): int
    {
        if (is_int($value)) {
            if ($value < 0 || $value > self::MAX_EPOCH) {
                throw new RuntimeException('Emergency epoch is out of range.');
            }
            return $value;
        }
        if (!is_string($value)) {
            throw new RuntimeException('Emergency epoch is not canonical.');
        }
        return self::parseStoredEpoch($value);
    }

    /**
     * @return array{state:string,breaches:list<string>,previous_epoch:int,current_epoch:int}
     */
    public static function incidentDisposition(string $value, int $lockedEpoch): array
    {
        $lockedEpoch = self::normalizeEpoch($lockedEpoch);
        $disposition = self::parseDisposition($value);
        self::assertDispositionCoveredByEpoch($disposition, $lockedEpoch);
        return $disposition;
    }

    /** @return array{0:int,1:int} */
    private static function advanceLockedEpoch(HarnessEmergencyEpochStore $store): array
    {
        $stored = $store->readForUpdate();
        return self::advanceLockedEpochFromStored($store, $stored);
    }

    /** @return array{0:int,1:int} */
    private static function advanceLockedEpochFromStored(
        HarnessEmergencyEpochStore $store,
        ?string $stored
    ): array {
        if ($stored === null) {
            $previous = 0;
            $current = 1;
            if (!$store->insertInitial((string)$current)) {
                throw new RuntimeException('Emergency epoch initial insert lost its lock.');
            }
            return [$previous, $current];
        }

        $previous = self::parseStoredEpoch($stored);
        if ($previous >= self::MAX_EPOCH) {
            throw new OverflowException('Emergency epoch is exhausted and cannot be reused.');
        }
        $current = $previous + 1;
        if (!$store->compareAndSet($stored, (string)$current)) {
            throw new RuntimeException('Emergency epoch changed outside the atomic bump protocol.');
        }
        return [$previous, $current];
    }

    private static function requireLockedEpoch(?string $stored): int
    {
        if ($stored === null) {
            throw new RuntimeException(
                'Automatic stop incident protocol requires the migrated emergency epoch row.'
            );
        }
        return self::parseStoredEpoch($stored);
    }

    /**
     * @param array{state:string,breaches:list<string>,previous_epoch:int,current_epoch:int} $disposition
     */
    private static function assertDispositionCoveredByEpoch(
        array $disposition,
        int $lockedEpoch
    ): void {
        if ($lockedEpoch < $disposition['current_epoch']) {
            throw new RuntimeException(
                'Stored automatic stop disposition is not covered by the monotonic epoch.'
            );
        }
    }

    private static function assertFingerprint(string $fingerprint): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new InvalidArgumentException(
                'Automatic stop scope fingerprint must be lowercase SHA-256.'
            );
        }
    }

    private static function assertOptionalScopeIdentifier(
        ?string $value,
        string $field,
        int $maximumLength
    ): void {
        if ($value === null) {
            return;
        }
        if (strlen($value) > $maximumLength
            || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:@\/-]*$/D', $value) !== 1) {
            throw new InvalidArgumentException(
                "Automatic stop scope {$field} is malformed."
            );
        }
    }

    /** @param list<string> $breaches @return list<string> */
    private static function normalizeBreaches(array $breaches): array
    {
        foreach ($breaches as $breach) {
            if (!is_string($breach)
                || preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $breach) !== 1) {
                throw new InvalidArgumentException(
                    'Automatic stop breach identifiers are invalid.'
                );
            }
        }
        sort($breaches, SORT_STRING);
        return array_values(array_unique($breaches));
    }

    /**
     * @return array{state:string,breaches:list<string>,previous_epoch:int,current_epoch:int}
     */
    private static function parseDisposition(string $value): array
    {
        try {
            $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Stored automatic stop disposition is malformed.',
                0,
                $exception
            );
        }
        if (!is_array($decoded)
            || array_keys($decoded) !== [
                'schema',
                'state',
                'breaches',
                'previous_epoch',
                'current_epoch',
            ]
            || ($decoded['schema'] ?? null) !== 'harness.automatic_stop.incident.v1'
            || !in_array($decoded['state'] ?? null, ['active', 'cleared'], true)
            || !is_array($decoded['breaches'] ?? null)
            || !is_int($decoded['previous_epoch'] ?? null)
            || !is_int($decoded['current_epoch'] ?? null)) {
            throw new RuntimeException(
                'Stored automatic stop disposition has an invalid contract.'
            );
        }
        $breaches = self::normalizeBreaches($decoded['breaches']);
        if ($breaches === []) {
            throw new RuntimeException(
                'Stored automatic stop disposition has no verified breach.'
            );
        }
        $previous = $decoded['previous_epoch'];
        $current = $decoded['current_epoch'];
        if ($previous < 0 || $current !== $previous + 1 || $current > self::MAX_EPOCH) {
            throw new RuntimeException(
                'Stored automatic stop disposition has invalid epoch coordinates.'
            );
        }
        $canonical = self::dispositionJson(
            $decoded['state'],
            $breaches,
            $previous,
            $current
        );
        if (!hash_equals($canonical, $value)) {
            throw new RuntimeException(
                'Stored automatic stop disposition is not canonical.'
            );
        }
        return [
            'state' => $decoded['state'],
            'breaches' => $breaches,
            'previous_epoch' => $previous,
            'current_epoch' => $current,
        ];
    }

    /** @param list<string> $breaches */
    private static function dispositionJson(
        string $state,
        array $breaches,
        int $previousEpoch,
        int $currentEpoch
    ): string {
        return json_encode([
            'schema' => 'harness.automatic_stop.incident.v1',
            'state' => $state,
            'breaches' => self::normalizeBreaches($breaches),
            'previous_epoch' => $previousEpoch,
            'current_epoch' => $currentEpoch,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function parseStoredEpoch(string $value): int
    {
        if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new RuntimeException('Stored emergency epoch is not canonical; bump refused.');
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => self::MAX_EPOCH],
        ]);
        if (!is_int($parsed) || (string)$parsed !== $value) {
            throw new RuntimeException('Stored emergency epoch is out of range; bump refused.');
        }
        return $parsed;
    }

    private static function clearCaches(): void
    {
        if (class_exists('ConfigCenter', false)
            && method_exists('ConfigCenter', 'clearCache')) {
            ConfigCenter::clearCache(self::SETTING_KEY);
        } elseif (function_exists('clearSystemSettingsCache')) {
            clearSystemSettingsCache();
        }
    }

    private function __construct()
    {
    }
}
