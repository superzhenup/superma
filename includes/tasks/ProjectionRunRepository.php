<?php
defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/db.php';

final class ProjectionRunRepository
{
    public static function isCompleted(int $chapterId, string $revisionHash, string $stage = 'full'): bool
    {
        self::assertIdentity($chapterId, $revisionHash, $stage);
        return (int)DB::fetchColumn(
            'SELECT COUNT(*) FROM chapter_projection_runs WHERE chapter_id=? AND revision_hash=? AND stage=?',
            [$chapterId, $revisionHash, $stage]
        ) > 0;
    }

    public static function markCompleted(
        int $novelId,
        int $chapterId,
        string $revisionHash,
        string $stage = 'full',
        ?string $payloadHash = null
    ): void {
        self::assertIdentity($chapterId, $revisionHash, $stage);
        if ($novelId <= 0) throw new InvalidArgumentException('Invalid novel id.');
        if ($payloadHash !== null && !preg_match('/^[a-f0-9]{64}$/', $payloadHash)) {
            throw new InvalidArgumentException('Invalid projection payload hash.');
        }

        DB::execute(
            'INSERT INTO chapter_projection_runs
                (novel_id, chapter_id, revision_hash, stage, payload_hash, completed_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE payload_hash=COALESCE(payload_hash, VALUES(payload_hash))',
            [$novelId, $chapterId, $revisionHash, $stage, $payloadHash]
        );
    }

    private static function assertIdentity(int $chapterId, string $revisionHash, string $stage): void
    {
        if ($chapterId <= 0 || !preg_match('/^[a-f0-9]{64}$/', $revisionHash)) {
            throw new InvalidArgumentException('Invalid projection identity.');
        }
        if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $stage)) {
            throw new InvalidArgumentException('Invalid projection stage.');
        }
    }
}
