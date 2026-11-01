<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/persistence/HarnessPersistenceSupport.php';
require_once dirname(__DIR__, 2) . '/quality/QualityGateDecision.php';

final class M65CanonicalProjectionException extends RuntimeException
{
}

/**
 * Persistence seam for the M65 projection. Every method is intentionally
 * transaction-neutral: CanonicalCommitService invokes it from inside the
 * ChapterCanonTransition transaction, and this adapter must never commit,
 * roll back, or create a nested transaction.
 */
interface M65CanonicalProjectionStore
{
    /** @return array<string,mixed>|null */
    public function lockBranch(string $branchUid): ?array;

    /** @return array<string,mixed>|null */
    public function lockBranchHead(string $branchUid, int $chapterId): ?array;

    /** @return array<string,mixed>|null */
    public function lockRevision(string $revisionUid): ?array;

    /** @param array<string,mixed> $revision */
    public function insertRevisionIdempotent(array $revision): void;

    public function insertBranchHeadIdempotent(
        string $branchUid,
        int $chapterId,
        string $revisionUid
    ): void;

    public function casBranchHead(
        string $branchUid,
        int $chapterId,
        string $expectedRevisionUid,
        int $expectedGeneration,
        string $revisionUid
    ): bool;

    /** @return array<string,mixed>|null */
    public function lockCanonicalHead(int $chapterId): ?array;

    /** @param array<string,mixed> $head */
    public function insertCanonicalHeadIdempotent(array $head): void;

    /** @param array<string,mixed> $head */
    public function casCanonicalHead(
        string $expectedBranchUid,
        string $expectedRevisionUid,
        int $expectedGeneration,
        array $head
    ): bool;
}

/** Production SQL adapter. Transaction ownership remains with the caller. */
final class DbM65CanonicalProjectionStore implements M65CanonicalProjectionStore
{
    public function lockBranch(string $branchUid): ?array
    {
        $row = DB::fetch(
            "SELECT branch_uid,user_id,novel_id,is_main,state
             FROM story_branches WHERE branch_uid=? LIMIT 1 FOR UPDATE",
            [$branchUid]
        );
        return is_array($row) ? $row : null;
    }

    public function lockBranchHead(string $branchUid, int $chapterId): ?array
    {
        $row = DB::fetch(
            "SELECT branch_uid,chapter_id,revision_uid,head_generation
             FROM chapter_branch_heads
             WHERE branch_uid=? AND chapter_id=? LIMIT 1 FOR UPDATE",
            [$branchUid, $chapterId]
        );
        return is_array($row) ? $row : null;
    }

    public function lockRevision(string $revisionUid): ?array
    {
        $row = DB::fetch(
            "SELECT revision_uid,user_id,novel_id,chapter_id,branch_uid,content,
                    content_hash,parent_revision_uid,merge_source_revision_uid,
                    source_candidate_uid
             FROM chapter_revisions WHERE revision_uid=? LIMIT 1 FOR UPDATE",
            [$revisionUid]
        );
        return is_array($row) ? $row : null;
    }

    public function insertRevisionIdempotent(array $revision): void
    {
        DB::execute(
            "INSERT INTO chapter_revisions
                (revision_uid,user_id,novel_id,chapter_id,branch_uid,content,
                 content_hash,parent_revision_uid,merge_source_revision_uid,
                 source_candidate_uid)
             VALUES (?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE revision_uid=revision_uid",
            [
                $revision['revision_uid'],
                $revision['user_id'],
                $revision['novel_id'],
                $revision['chapter_id'],
                $revision['branch_uid'],
                $revision['content'],
                $revision['content_hash'],
                $revision['parent_revision_uid'],
                $revision['merge_source_revision_uid'],
                $revision['source_candidate_uid'],
            ]
        );
    }

    public function insertBranchHeadIdempotent(
        string $branchUid,
        int $chapterId,
        string $revisionUid
    ): void {
        DB::execute(
            "INSERT INTO chapter_branch_heads
                (branch_uid,chapter_id,revision_uid,head_generation)
             VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE id=id",
            [$branchUid, $chapterId, $revisionUid]
        );
    }

    public function casBranchHead(
        string $branchUid,
        int $chapterId,
        string $expectedRevisionUid,
        int $expectedGeneration,
        string $revisionUid
    ): bool {
        return DB::execute(
            "UPDATE chapter_branch_heads
             SET revision_uid=?,head_generation=head_generation+1,
                 updated_at=UTC_TIMESTAMP(6)
             WHERE branch_uid=? AND chapter_id=? AND revision_uid=?
               AND head_generation=?",
            [
                $revisionUid,
                $branchUid,
                $chapterId,
                $expectedRevisionUid,
                $expectedGeneration,
            ]
        ) === 1;
    }

    public function lockCanonicalHead(int $chapterId): ?array
    {
        $row = DB::fetch(
            "SELECT user_id,novel_id,chapter_id,branch_uid,revision_uid,
                    revision_hash,source_candidate_uid,head_generation
             FROM chapter_canonical_heads
             WHERE chapter_id=? LIMIT 1 FOR UPDATE",
            [$chapterId]
        );
        return is_array($row) ? $row : null;
    }

    public function insertCanonicalHeadIdempotent(array $head): void
    {
        DB::execute(
            "INSERT INTO chapter_canonical_heads
                (user_id,novel_id,chapter_id,branch_uid,revision_uid,
                 revision_hash,source_candidate_uid,head_generation)
             VALUES (?,?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE id=id",
            [
                $head['user_id'],
                $head['novel_id'],
                $head['chapter_id'],
                $head['branch_uid'],
                $head['revision_uid'],
                $head['revision_hash'],
                $head['source_candidate_uid'],
            ]
        );
    }

    public function casCanonicalHead(
        string $expectedBranchUid,
        string $expectedRevisionUid,
        int $expectedGeneration,
        array $head
    ): bool {
        return DB::execute(
            "UPDATE chapter_canonical_heads
             SET branch_uid=?,revision_uid=?,revision_hash=?,source_candidate_uid=?,
                 head_generation=head_generation+1,updated_at=UTC_TIMESTAMP(6)
             WHERE user_id=? AND novel_id=? AND chapter_id=?
               AND branch_uid=? AND revision_uid=? AND head_generation=?",
            [
                $head['branch_uid'],
                $head['revision_uid'],
                $head['revision_hash'],
                $head['source_candidate_uid'],
                $head['user_id'],
                $head['novel_id'],
                $head['chapter_id'],
                $expectedBranchUid,
                $expectedRevisionUid,
                $expectedGeneration,
            ]
        ) === 1;
    }
}

/**
 * Projects an accepted M65 Candidate into immutable Revision/head tables.
 *
 * A Candidate without target_branch_uid is a legacy Candidate and deliberately
 * produces no M65 writes. For branch-aware Candidates every validation and CAS
 * is performed before the surrounding Canon transaction commits, so stale
 * lineage, ownership, content, or head state rolls back the whole promotion.
 */
final class M65CanonicalProjection
{
    public function __construct(
        private readonly M65CanonicalProjectionStore $store = new DbM65CanonicalProjectionStore()
    ) {
    }

    /**
     * @param array<string,mixed> $context ChapterCanonTransition callback context
     * @return array{revision_uid:string,branch_uid:string}|null
     */
    public function project(
        array $context,
        QualityGateDecision $decision,
        bool $approvedOverride = false
    ): ?array {
        $candidate = $context['candidate'] ?? null;
        $result = $context['result'] ?? null;
        if (!is_array($candidate) || !is_array($result)) {
            throw new M65CanonicalProjectionException(
                'M65 Canon projection requires locked Candidate and result context.'
            );
        }
        $targetBranchUid = $candidate['target_branch_uid'] ?? null;
        if ($targetBranchUid === null || $targetBranchUid === '') {
            return null;
        }
        $targetBranchUid = self::uid($targetBranchUid, 'candidate.target_branch_uid');

        self::assertPromotionAuthority($candidate, $decision, $approvedOverride);
        $userId = self::positiveInt($candidate['user_id'] ?? null, 'candidate.user_id');
        $novelId = self::positiveInt($candidate['novel_id'] ?? null, 'candidate.novel_id');
        $chapterId = self::positiveInt($candidate['chapter_id'] ?? null, 'candidate.chapter_id');
        $candidateUid = self::uid($candidate['candidate_uid'] ?? null, 'candidate.candidate_uid');
        $content = self::string($candidate['inline_content'] ?? null, 'candidate.inline_content');
        $contentHash = self::hash($candidate['content_hash'] ?? null, 'candidate.content_hash');
        if (!hash_equals(hash('sha256', $content), $contentHash)
            || !hash_equals($decision->contentHash(), $contentHash)
            || ($result['committed'] ?? null) !== true
            || !is_string($result['accepted_content'] ?? null)
            || !hash_equals($content, $result['accepted_content'])
            || !is_string($result['revision_hash'] ?? null)
            || !hash_equals($contentHash, $result['revision_hash'])) {
            throw new M65CanonicalProjectionException(
                'M65 Candidate content/hash does not match the accepted Canon result.'
            );
        }

        $baseRevisionUid = self::optionalUid(
            $candidate['base_revision_uid'] ?? null,
            'candidate.base_revision_uid'
        );
        $mergeSourceRevisionUid = self::optionalUid(
            $candidate['merge_source_revision_uid'] ?? null,
            'candidate.merge_source_revision_uid'
        );
        $revisionUid = self::revisionUid($candidateUid, $targetBranchUid);

        $branch = $this->store->lockBranch($targetBranchUid);
        if (!is_array($branch)
            || self::positiveInt($branch['user_id'] ?? null, 'branch.user_id') !== $userId
            || self::positiveInt($branch['novel_id'] ?? null, 'branch.novel_id') !== $novelId
            || ($branch['state'] ?? null) !== 'active'
            || !self::databaseBool($branch['is_main'] ?? null)) {
            throw new M65CanonicalProjectionException(
                'M65 Canon target Branch is inactive, non-main, or outside ownership scope.'
            );
        }

        $head = $this->store->lockBranchHead($targetBranchUid, $chapterId);
        $currentRevisionUid = $head === null
            ? null
            : self::uid($head['revision_uid'] ?? null, 'branch_head.revision_uid');
        $idempotentReplay = $currentRevisionUid !== null
            && hash_equals($revisionUid, $currentRevisionUid);
        if (!$idempotentReplay && !self::sameOptionalUid($currentRevisionUid, $baseRevisionUid)) {
            throw new M65CanonicalProjectionException(
                'M65 Candidate base Revision is stale against the target Branch head.'
            );
        }

        if ($baseRevisionUid !== null) {
            $baseRevision = $this->store->lockRevision($baseRevisionUid);
            self::assertRevisionScope(
                $baseRevision,
                $baseRevisionUid,
                $userId,
                $novelId,
                $chapterId,
                $targetBranchUid,
                'base'
            );
            $candidateBaseHash = self::optionalHash(
                $candidate['base_revision_hash'] ?? null,
                'candidate.base_revision_hash'
            );
            if ($candidateBaseHash !== null
                && !hash_equals(
                    self::hash($baseRevision['content_hash'] ?? null, 'base.content_hash'),
                    $candidateBaseHash
                )) {
                throw new M65CanonicalProjectionException(
                    'M65 Candidate base Revision hash is not authentic.'
                );
            }
        }
        if ($mergeSourceRevisionUid !== null) {
            $mergeSource = $this->store->lockRevision($mergeSourceRevisionUid);
            self::assertRevisionScope(
                $mergeSource,
                $mergeSourceRevisionUid,
                $userId,
                $novelId,
                $chapterId,
                null,
                'merge source'
            );
        }

        $revision = [
            'revision_uid' => $revisionUid,
            'user_id' => $userId,
            'novel_id' => $novelId,
            'chapter_id' => $chapterId,
            'branch_uid' => $targetBranchUid,
            'content' => $content,
            'content_hash' => $contentHash,
            'parent_revision_uid' => $baseRevisionUid,
            'merge_source_revision_uid' => $mergeSourceRevisionUid,
            'source_candidate_uid' => $candidateUid,
        ];
        $this->store->insertRevisionIdempotent($revision);
        self::assertExactRevision($this->store->lockRevision($revisionUid), $revision);

        if (!$idempotentReplay) {
            if ($head === null) {
                if ($baseRevisionUid !== null) {
                    throw new M65CanonicalProjectionException(
                        'M65 Branch head disappeared for a non-empty base.'
                    );
                }
                $this->store->insertBranchHeadIdempotent(
                    $targetBranchUid,
                    $chapterId,
                    $revisionUid
                );
            } else {
                $generation = self::positiveInt(
                    $head['head_generation'] ?? null,
                    'branch_head.head_generation'
                );
                if ($baseRevisionUid === null
                    || !$this->store->casBranchHead(
                        $targetBranchUid,
                        $chapterId,
                        $baseRevisionUid,
                        $generation,
                        $revisionUid
                    )) {
                    throw new M65CanonicalProjectionException('M65 Branch head CAS missed.');
                }
            }
            $advanced = $this->store->lockBranchHead($targetBranchUid, $chapterId);
            if (!is_array($advanced)
                || !hash_equals(
                    $revisionUid,
                    self::uid($advanced['revision_uid'] ?? null, 'branch_head.revision_uid')
                )) {
                throw new M65CanonicalProjectionException('M65 Branch head advance was not durable.');
            }
        }

        $canonical = [
            'user_id' => $userId,
            'novel_id' => $novelId,
            'chapter_id' => $chapterId,
            'branch_uid' => $targetBranchUid,
            'revision_uid' => $revisionUid,
            'revision_hash' => $contentHash,
            'source_candidate_uid' => $candidateUid,
        ];
        $canonicalHead = $this->store->lockCanonicalHead($chapterId);
        if ($canonicalHead === null) {
            $this->store->insertCanonicalHeadIdempotent($canonical);
        } elseif (($canonicalHead['revision_uid'] ?? null) !== $revisionUid) {
            $canonicalBranchUid = self::uid(
                $canonicalHead['branch_uid'] ?? null,
                'canonical_head.branch_uid'
            );
            $canonicalRevisionUid = self::uid(
                $canonicalHead['revision_uid'] ?? null,
                'canonical_head.revision_uid'
            );
            $canonicalGeneration = self::positiveInt(
                $canonicalHead['head_generation'] ?? null,
                'canonical_head.head_generation'
            );
            if ($baseRevisionUid === null
                || !hash_equals($targetBranchUid, $canonicalBranchUid)
                || !hash_equals($baseRevisionUid, $canonicalRevisionUid)
                || !$this->store->casCanonicalHead(
                    $canonicalBranchUid,
                    $canonicalRevisionUid,
                    $canonicalGeneration,
                    $canonical
                )) {
                throw new M65CanonicalProjectionException('M65 Canonical head CAS missed.');
            }
        }
        self::assertExactCanonicalHead($this->store->lockCanonicalHead($chapterId), $canonical);

        return ['revision_uid' => $revisionUid, 'branch_uid' => $targetBranchUid];
    }

    /** @param array<string,mixed> $candidate */
    private static function assertPromotionAuthority(
        array $candidate,
        QualityGateDecision $decision,
        bool $approvedOverride
    ): void {
        if (!$approvedOverride) {
            if ($decision->shouldQuarantine()) {
                throw new M65CanonicalProjectionException(
                    'A hard-gate-failed M65 Candidate cannot move a Branch head.'
                );
            }
            return;
        }
        if (($candidate['lifecycle'] ?? null) !== 'approved'
            || !in_array(($candidate['gate_state'] ?? null), ['passed', 'quarantined'], true)
            || !is_string($candidate['validation_json'] ?? null)
            || $candidate['validation_json'] === '') {
            throw new M65CanonicalProjectionException(
                'M65 override is missing an authenticated human approval.'
            );
        }
    }

    /**
     * @param array<string,mixed>|null $row
     */
    private static function assertRevisionScope(
        ?array $row,
        string $revisionUid,
        int $userId,
        int $novelId,
        int $chapterId,
        ?string $branchUid,
        string $label
    ): void {
        if (!is_array($row)
            || ($row['revision_uid'] ?? null) !== $revisionUid
            || self::positiveInt($row['user_id'] ?? null, "{$label}.user_id") !== $userId
            || self::positiveInt($row['novel_id'] ?? null, "{$label}.novel_id") !== $novelId
            || self::positiveInt($row['chapter_id'] ?? null, "{$label}.chapter_id") !== $chapterId
            || ($branchUid !== null && ($row['branch_uid'] ?? null) !== $branchUid)) {
            throw new M65CanonicalProjectionException(
                "M65 {$label} Revision is outside Candidate scope."
            );
        }
    }

    /** @param array<string,mixed>|null $stored @param array<string,mixed> $expected */
    private static function assertExactRevision(?array $stored, array $expected): void
    {
        if (!is_array($stored)) {
            throw new M65CanonicalProjectionException('M65 immutable Revision was not stored.');
        }
        foreach ($expected as $field => $value) {
            $actual = $stored[$field] ?? null;
            if ($field === 'content_hash') {
                if (!is_string($actual) || !hash_equals((string)$value, $actual)) {
                    throw new M65CanonicalProjectionException(
                        'M65 Revision UID resolved to different immutable bytes.'
                    );
                }
            } elseif ($actual !== $value && (string)$actual !== (string)$value) {
                throw new M65CanonicalProjectionException(
                    "M65 Revision UID resolved to different {$field}."
                );
            }
        }
    }

    /** @param array<string,mixed>|null $stored @param array<string,mixed> $expected */
    private static function assertExactCanonicalHead(?array $stored, array $expected): void
    {
        if (!is_array($stored)) {
            throw new M65CanonicalProjectionException('M65 Canonical head was not stored.');
        }
        foreach ($expected as $field => $value) {
            $actual = $stored[$field] ?? null;
            if ($actual !== $value && (string)$actual !== (string)$value) {
                throw new M65CanonicalProjectionException(
                    "M65 Canonical head disagrees on {$field}."
                );
            }
        }
    }

    private static function revisionUid(string $candidateUid, string $branchUid): string
    {
        return substr(hash(
            'sha256',
            'm65-canonical-revision-v1' . "\0" . $candidateUid . "\0" . $branchUid
        ), 0, 32);
    }

    private static function positiveInt(mixed $value, string $field): int
    {
        if (is_int($value) && $value > 0) return $value;
        if (is_string($value) && preg_match('/^[1-9]\d*$/D', $value) === 1) {
            return (int)$value;
        }
        throw new M65CanonicalProjectionException("{$field} must be a positive integer.");
    }

    private static function databaseBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    private static function uid(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            throw new M65CanonicalProjectionException("{$field} must be a lowercase UID.");
        }
        return $value;
    }

    private static function optionalUid(mixed $value, string $field): ?string
    {
        return $value === null || $value === '' ? null : self::uid($value, $field);
    }

    private static function sameOptionalUid(?string $left, ?string $right): bool
    {
        return $left === null || $right === null
            ? $left === $right
            : hash_equals($left, $right);
    }

    private static function hash(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new M65CanonicalProjectionException("{$field} must be a lowercase SHA-256 hash.");
        }
        return $value;
    }

    private static function optionalHash(mixed $value, string $field): ?string
    {
        return $value === null || $value === '' ? null : self::hash($value, $field);
    }

    private static function string(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new M65CanonicalProjectionException("{$field} must be a string.");
        }
        return $value;
    }
}
