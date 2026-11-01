<?php
declare(strict_types=1);
defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/ConstraintConfig.php';
require_once __DIR__ . '/ConstraintStateDB.php';
require_once __DIR__ . '/ConstraintStateUpdater.php';
require_once dirname(__DIR__) . '/NovelProjectionFence.php';

/** Reconcile cumulative constraints with the current, ordered body revisions. */
final class ConstraintProjectionService
{
    public static function refresh(int $novelId, ?callable $heartbeat = null): void
    {
        if (!ConstraintConfig::isEnabled()) return;
        $fence = NovelProjectionFence::acquire($novelId);
        $pdo = DB::getPdo();
        $ownTx = !$pdo->inTransaction();
        $savepoint = 'constraint_projection_' . bin2hex(random_bytes(6));
        $started = false;
        try {
            // Workflow heartbeats can advance in-memory lease identities. Never
            // enlist those updates in a transaction which we may later roll back.
            if ($ownTx && $heartbeat !== null) $heartbeat();
            if ($ownTx) {
                if (!$pdo->beginTransaction()) throw new RuntimeException('Cannot start constraint rebuild.');
            } else {
                $pdo->exec('SAVEPOINT ' . $savepoint);
            }
            $started = true;
            $rows = DB::fetchAll(
                'SELECT id, chapter_number, cool_point_type, hook_type,
                        COALESCE(content_revision_hash, SHA2(content,256)) AS body_hash
                 FROM chapters WHERE novel_id=? AND status="completed"
                   AND content IS NOT NULL AND content<>"" ORDER BY chapter_number ASC',
                [$novelId]
            );
            $manifest = [];
            foreach ($rows as $row) {
                $manifest[] = [
                    'id' => (int)$row['id'], 'chapter' => (int)$row['chapter_number'],
                    'hash' => (string)$row['body_hash'],
                    'cool' => $row['cool_point_type'], 'hook' => $row['hook_type'],
                ];
            }
            $configuration = hash('sha256', json_encode(
                ['version' => 1, 'banned_words' => ConstraintConfig::bannedWords()], JSON_THROW_ON_ERROR
            ));
            $state = new ConstraintStateDB($novelId, true);
            $previous = $state->get('projection', 'source_revisions', null);
            $unchanged = is_array($previous)
                && ($previous['configuration'] ?? null) === $configuration
                && ($previous['chapters'] ?? null) === $manifest;
            if (!$unchanged) {
                // Only an exact prefix permits incremental append. Deleted,
                // edited, backfilled or legacy unversioned history is rebuilt.
                $append = is_array($previous)
                    && ($previous['configuration'] ?? null) === $configuration
                    && is_array($previous['chapters'] ?? null)
                    && count($manifest) === count($previous['chapters']) + 1
                    && array_slice($manifest, 0, -1) === $previous['chapters'];
                if (!$append) {
                    DB::execute(
                        'DELETE FROM constraint_state WHERE novel_id=? AND (
                            (state_type="pacing" AND state_key IN ("conflict_history","last_climax_chapter","last_chapter_function"))
                            OR (state_type="style" AND state_key="banned_word_usage")
                            OR (state_type="plot" AND state_key IN ("coincidence_count","processed_chapters","last_processed_chapter"))
                            OR (state_type="character" AND state_key="protagonist_power")
                            OR (state_type="projection" AND state_key="source_revisions"))', [$novelId]
                    );
                }
                $rebuildRows = $append ? array_slice($rows, -1) : $rows;
                foreach ($rebuildRows as $row) {
                    $chapter = DB::fetch(
                        'SELECT * FROM chapters WHERE id=? AND novel_id=? AND status="completed" FOR UPDATE',
                        [(int)$row['id'], $novelId]
                    );
                    if (!is_array($chapter)
                        || !hash_equals((string)$row['body_hash'], hash('sha256', (string)$chapter['content']))) {
                        throw new RuntimeException('Constraint rebuild source revision changed.');
                    }
                    (new ConstraintStateUpdater($novelId, $chapter, (string)$chapter['content']))->updateAll();
                }
                $state->set('projection', 'source_revisions', [
                    'configuration' => $configuration, 'chapters' => $manifest,
                ]);
            }
            // Cards may have been edited/replayed without changing body hashes.
            // Refresh their derived power anchor even on an unchanged manifest.
            $lastChapter = $rows === [] ? ['chapter_number' => 0] : $rows[count($rows) - 1];
            (new ConstraintStateUpdater($novelId, $lastChapter, ''))->updateProtagonistPowerState();
            if ($ownTx) {
                if (!$pdo->commit()) throw new RuntimeException('Cannot commit constraint rebuild.');
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            $started = false;
            if ($ownTx && $heartbeat !== null) $heartbeat();
        } catch (Throwable $error) {
            if ($started && $pdo->inTransaction()) {
                if ($ownTx) $pdo->rollBack();
                else {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                }
            }
            throw $error;
        } finally {
            NovelProjectionFence::release($fence);
        }
    }
}
