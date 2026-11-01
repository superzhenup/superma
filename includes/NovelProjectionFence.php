<?php
defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/db.php';

/**
 * Serializes Canon changes and their novel-wide derived projections.
 *
 * MySQL named locks are connection scoped rather than transaction scoped.  The
 * first acquisition must therefore happen before a transaction (and before any
 * row lock) so a projection worker can never hold this lock while waiting for a
 * row owned by a mutation that is itself waiting for the named lock.
 *
 * The lock is deliberately novel-wide: memory, Story Bible, arc/volume summaries
 * and constraint state are cumulative projections, so a per-chapter mutex would
 * not protect an edit to chapter N from a stale chapter N+1 projection.
 */
final class NovelProjectionFence
{
    private const LOCK_PREFIX = 'novel_projection_v1_';

    /** @var array<string,array{depth:int,pdo_id:int}> */
    private static array $held = [];

    public static function lockName(int $novelId): string
    {
        if ($novelId <= 0) {
            throw new InvalidArgumentException('Projection fence requires a positive novel id.');
        }
        $name = self::LOCK_PREFIX . $novelId;
        if (strlen($name) > 64) {
            throw new InvalidArgumentException('Projection fence name exceeds MySQL limits.');
        }
        return $name;
    }

    /**
     * @return string Opaque release token (the exact MySQL lock name).
     */
    public static function acquire(int $novelId, int $timeoutSeconds = 30): string
    {
        if ($timeoutSeconds < 0 || $timeoutSeconds > 300) {
            throw new InvalidArgumentException('Projection fence timeout is out of range.');
        }

        $name = self::lockName($novelId);
        $pdo = DB::getPdo();
        $pdoId = spl_object_id($pdo);

        // MySQL 5.7 supports recursive GET_LOCK calls, but tracking recursion in
        // PHP avoids depending on server reference-count details and guarantees
        // that only the outermost scope releases the connection-owned lock.
        if (isset(self::$held[$name])) {
            if (self::$held[$name]['pdo_id'] !== $pdoId) {
                throw new RuntimeException('Projection fence connection changed while held.');
            }
            self::$held[$name]['depth']++;
            return $name;
        }

        if ($pdo->inTransaction()) {
            throw new LogicException(
                'Projection fence must be acquired before opening a transaction or locking rows.'
            );
        }

        $statement = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $statement->execute([$name, $timeoutSeconds]);
        $result = $statement->fetchColumn();
        $statement->closeCursor();
        if ((int)$result !== 1) {
            $reason = $result === null ? 'database_error' : 'timeout';
            throw new RuntimeException("Projection fence acquisition failed: {$reason}.");
        }

        self::$held[$name] = ['depth' => 1, 'pdo_id' => $pdoId];
        return $name;
    }

    public static function release(string $token): void
    {
        if (!isset(self::$held[$token])) {
            throw new LogicException('Projection fence release does not match an owned lock.');
        }

        $pdo = DB::getPdo();
        if (self::$held[$token]['pdo_id'] !== spl_object_id($pdo)) {
            throw new RuntimeException('Projection fence connection changed before release.');
        }
        if (self::$held[$token]['depth'] > 1) {
            self::$held[$token]['depth']--;
            return;
        }

        $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $statement->execute([$token]);
        $result = $statement->fetchColumn();
        $statement->closeCursor();
        if ((int)$result !== 1) {
            throw new RuntimeException('Projection fence release was not acknowledged by MySQL.');
        }
        unset(self::$held[$token]);
    }

    /**
     * Whether this process currently holds any projection fence.
     *
     * DB::query() consults this probe before rebuilding a dropped connection
     * (audit fix 2026-10-31 B1): MySQL named locks are connection scoped, so
     * discarding the singleton PDO would silently release the server-side lock
     * while the holder keeps writing under assumed exclusivity. The reconnect
     * path must fail closed instead of continuing without the lock.
     */
    public static function hasHeld(): bool
    {
        return self::$held !== [];
    }
}
