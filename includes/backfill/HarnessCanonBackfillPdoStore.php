<?php

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/HarnessCanonBackfill.php';

/**
 * MySQL implementation for the historical Canon backfill boundary.
 */
final class HarnessCanonBackfillPdoStore implements HarnessCanonBackfillStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function acquireAdvisoryLock(string $name, int $timeoutSeconds): bool
    {
        $statement = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
        $statement->execute([$name, $timeoutSeconds]);
        $acquired = $statement->fetchColumn();
        $statement->closeCursor();
        return (string)$acquired === '1';
    }

    public function releaseAdvisoryLock(string $name): void
    {
        $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
        $statement->execute([$name]);
        $statement->closeCursor();
    }

    public function fetchRowsAfter(int $resumeCursor, int $limit): array
    {
        if ($resumeCursor < 0 || $limit < 1 || $limit > HarnessCanonBackfillOptions::MAX_BATCH_SIZE + 1) {
            throw new InvalidArgumentException('Unsafe historical backfill range.');
        }

        // LIMIT is interpolated only after the strict integer range check
        // above; native MySQL prepares do not consistently accept LIMIT binds.
        $statement = $this->pdo->prepare(
            'SELECT id, status, content, canonical_state, content_revision_hash,'
            . ' canonicalized_at, write_policy_snapshot_json, canonical_candidate_uid'
            . ' FROM chapters'
            . " WHERE id>? AND canonical_state IN ('none','legacy')"
            . ' ORDER BY id ASC LIMIT ' . $limit
        );
        $statement->execute([$resumeCursor]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();
        return is_array($rows) ? $rows : [];
    }

    public function beginTransaction(): void
    {
        if ($this->pdo->inTransaction() || !$this->pdo->beginTransaction()) {
            throw new RuntimeException('Cannot begin historical Canon backfill transaction.');
        }
    }

    public function commit(): void
    {
        if (!$this->pdo->inTransaction() || !$this->pdo->commit()) {
            throw new RuntimeException('Cannot commit historical Canon backfill transaction.');
        }
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction() && !$this->pdo->rollBack()) {
            throw new RuntimeException('Cannot roll back historical Canon backfill transaction.');
        }
    }

    public function compareAndSetLegacy(array $expectedRow, string $contentHash): bool
    {
        $id = (int)($expectedRow['id'] ?? 0);
        $state = $expectedRow['canonical_state'] ?? null;
        $content = $expectedRow['content'] ?? null;
        $oldRevisionHash = $expectedRow['content_revision_hash'] ?? null;
        if (
            $id < 1
            || !in_array($state, ['none', 'legacy'], true)
            || !is_string($content)
            || $content === ''
            || preg_match('/^[a-f0-9]{64}$/D', $contentHash) !== 1
            || ($oldRevisionHash !== null && !is_string($oldRevisionHash))
        ) {
            throw new InvalidArgumentException('Invalid historical Canon CAS input.');
        }

        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE chapters
   SET canonical_state='legacy', content_revision_hash=?
 WHERE id=?
   AND status='completed'
   AND canonical_state=?
   AND content IS NOT NULL
   AND OCTET_LENGTH(content)=?
   AND SHA2(content, 256)=?
   AND BINARY content = BINARY ?
   AND content_revision_hash <=> ?
   AND canonicalized_at IS NULL
   AND canonical_candidate_uid IS NULL
   AND write_policy_snapshot_json IS NULL
SQL
        );
        $statement->execute([
            $contentHash,
            $id,
            $state,
            strlen($content),
            $contentHash,
            $content,
            $oldRevisionHash,
        ]);
        $affected = $statement->rowCount();
        $statement->closeCursor();
        return $affected === 1;
    }
}
