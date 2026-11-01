<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/ReviewAggregator.php';

/**
 * Immutable post-Canon command. The selected Review Draft, committed Canon
 * revision, decision Artifact and exact Reviewer report set must all agree.
 */
final readonly class CommittedReviewEffects
{
    /** @var array<string,mixed> */
    public array $reportsArtifact;
    /** @var array<string,mixed> */
    public array $decisionArtifact;
    /** @var list<string> */
    public array $reportUids;
    /** @var array<string,mixed> */
    public array $metadata;
    public string $reviewResultUid;
    public string $rawDraftHash;
    public string $selectedDraftHash;
    public string $effectUid;

    /**
     * @param array<string,mixed> $reportsArtifact
     * @param array<string,mixed> $decisionArtifact
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public int $novelId,
        public int $chapterId,
        public string $committedRevisionHash,
        array $reportsArtifact,
        public string $reportsArtifactHash,
        array $decisionArtifact,
        public string $decisionArtifactHash,
        array $metadata = []
    ) {
        HarnessPersistenceValue::positiveInt($novelId, 'review_effects.novel_id');
        HarnessPersistenceValue::positiveInt($chapterId, 'review_effects.chapter_id');
        HarnessPersistenceValue::hash(
            $committedRevisionHash,
            'review_effects.committed_revision_hash'
        );
        self::assertArtifactHash(
            $reportsArtifact,
            $reportsArtifactHash,
            'review_effects.reports_artifact_hash'
        );
        self::assertArtifactHash(
            $decisionArtifact,
            $decisionArtifactHash,
            'review_effects.decision_artifact_hash'
        );

        $resultUid = $decisionArtifact['result_uid'] ?? null;
        $rawDraftHash = $decisionArtifact['raw_draft_hash'] ?? null;
        $selectedDraftHash = $decisionArtifact['selected_draft_hash'] ?? null;
        $roundCount = $decisionArtifact['round_count'] ?? null;
        if (($decisionArtifact['schema'] ?? null) !== 'harness.review.decision.v1'
            || !is_string($resultUid)
            || preg_match('/^[a-f0-9]{32}$/D', $resultUid) !== 1
            || !is_string($rawDraftHash)
            || !is_string($selectedDraftHash)
            || !is_int($roundCount)
            || $roundCount < 1
            || $roundCount > 2
            || ($decisionArtifact['disposition'] ?? null) !== 'accepted'
            || ($decisionArtifact['commit_allowed'] ?? null) !== true
            || ($decisionArtifact['bypass_reason'] ?? null) !== null) {
            throw new InvalidArgumentException(
                'Review effects require an enabled, accepted Review decision.'
            );
        }
        HarnessPersistenceValue::hash($rawDraftHash, 'review_effects.raw_draft_hash');
        HarnessPersistenceValue::hash(
            $selectedDraftHash,
            'review_effects.selected_draft_hash'
        );
        if (!hash_equals($selectedDraftHash, $committedRevisionHash)) {
            throw new InvalidArgumentException(
                'Review effects cannot attach evidence to a different committed revision.'
            );
        }

        $reportUids = self::validateReportsArtifact(
            $reportsArtifact,
            $resultUid,
            $rawDraftHash,
            $selectedDraftHash,
            $roundCount
        );
        HarnessSensitiveDataGuard::assertSafeStructure($metadata, 'review_effects.metadata');

        $this->reportsArtifact = $reportsArtifact;
        $this->decisionArtifact = $decisionArtifact;
        $this->reportUids = $reportUids;
        $this->metadata = $metadata;
        $this->reviewResultUid = $resultUid;
        $this->rawDraftHash = $rawDraftHash;
        $this->selectedDraftHash = $selectedDraftHash;
        $this->effectUid = substr(hash(
            'sha256',
            'review-effect-v2' . "\0" . HarnessPersistenceValue::canonicalMysqlJson([
                'novel_id' => $novelId,
                'chapter_id' => $chapterId,
                'review_result_uid' => $resultUid,
                'committed_revision_hash' => $committedRevisionHash,
                'reports_artifact_hash' => $reportsArtifactHash,
                'decision_artifact_hash' => $decisionArtifactHash,
                'report_uids' => $reportUids,
            ])
        ), 0, 32);
    }

    /** @return array<string,mixed> */
    public function commandArtifact(): array
    {
        return $this->identityArtifact('harness.review.effects.command.v1');
    }

    /** @return array<string,mixed> */
    public function appliedArtifact(): array
    {
        return $this->identityArtifact('harness.review.effects.applied.v1') + [
            'status' => 'applied',
        ];
    }

    /** @return array<string,mixed> */
    public function receiptArtifact(string $commandHash): array
    {
        HarnessPersistenceValue::hash($commandHash, 'review_effects.command_hash');
        return $this->identityArtifact('harness.review.effects.receipt.v1') + [
            'command_hash' => $commandHash,
            'status' => 'applied',
        ];
    }

    /** @param array<string,mixed> $payload */
    public function assertCommandArtifact(array $payload): void
    {
        if (HarnessPersistenceValue::canonicalMysqlJson($payload)
            !== HarnessPersistenceValue::canonicalMysqlJson($this->commandArtifact())) {
            throw new HarnessIdempotencyConflictException(
                'Review effect command does not bind the active committed evidence.'
            );
        }
    }

    /** @param array<string,mixed> $payload */
    public function assertReceiptArtifact(array $payload, string $commandHash): void
    {
        if (HarnessPersistenceValue::canonicalMysqlJson($payload)
            !== HarnessPersistenceValue::canonicalMysqlJson(
                $this->receiptArtifact($commandHash)
            )) {
            throw new HarnessIdempotencyConflictException(
                'Review effect receipt does not bind the active committed evidence.'
            );
        }
    }

    /** @return array<string,mixed> */
    private function identityArtifact(string $schema): array
    {
        return [
            'schema' => $schema,
            'effect_uid' => $this->effectUid,
            'review_result_uid' => $this->reviewResultUid,
            'novel_id' => $this->novelId,
            'chapter_id' => $this->chapterId,
            'raw_draft_hash' => $this->rawDraftHash,
            'selected_draft_hash' => $this->selectedDraftHash,
            'committed_revision_hash' => $this->committedRevisionHash,
            'reports_artifact_hash' => $this->reportsArtifactHash,
            'decision_artifact_hash' => $this->decisionArtifactHash,
            'report_uids' => $this->reportUids,
            'metadata' => $this->metadata,
        ];
    }

    /** @param array<string,mixed> $payload */
    private static function assertArtifactHash(
        array $payload,
        string $expectedHash,
        string $field
    ): void {
        HarnessPersistenceValue::hash($expectedHash, $field);
        $actualHash = hash(
            'sha256',
            HarnessPersistenceValue::canonicalMysqlJson($payload)
        );
        if (!hash_equals($expectedHash, $actualHash)) {
            throw new InvalidArgumentException(
                'Review effect Artifact hash does not match its canonical payload.'
            );
        }
    }

    /**
     * @param array<string,mixed> $artifact
     * @return list<string>
     */
    private static function validateReportsArtifact(
        array $artifact,
        string $resultUid,
        string $rawDraftHash,
        string $selectedDraftHash,
        int $roundCount
    ): array {
        $rounds = $artifact['rounds'] ?? null;
        if (($artifact['schema'] ?? null) !== 'harness.review.reports.v1'
            || ($artifact['result_uid'] ?? null) !== $resultUid
            || ($artifact['raw_draft_hash'] ?? null) !== $rawDraftHash
            || !is_array($rounds)
            || count($rounds) !== $roundCount) {
            throw new InvalidArgumentException(
                'Review effects require the exact immutable Reviewer report Artifact.'
            );
        }

        $expectedInputHash = $rawDraftHash;
        $allReportUids = [];
        foreach (array_values($rounds) as $index => $round) {
            if (!is_array($round)
                || ($round['round'] ?? null) !== $index + 1
                || ($round['input_hash'] ?? null) !== $expectedInputHash) {
                throw new InvalidArgumentException(
                    'Review effect report rounds are not contiguous and hash chained.'
                );
            }
            $outputHash = $round['output_hash'] ?? null;
            if (!is_string($outputHash)) {
                throw new InvalidArgumentException(
                    'Review effect report round has no output hash.'
                );
            }
            HarnessPersistenceValue::hash($outputHash, 'review_effects.round.output_hash');

            $reportPayloads = $round['reports'] ?? null;
            if (!is_array($reportPayloads) || $reportPayloads === []) {
                throw new InvalidArgumentException(
                    'Review effect report round has no immutable reports.'
                );
            }
            $reports = [];
            foreach (array_values($reportPayloads) as $reportPayload) {
                if (!is_array($reportPayload)) {
                    throw new InvalidArgumentException('Review effect report is malformed.');
                }
                $report = self::reportFromArtifact($reportPayload, $expectedInputHash);
                $reports[] = $report;
                if (isset($allReportUids[$report->reportUid])) {
                    throw new InvalidArgumentException(
                        'Review effects contain a duplicate immutable report UID.'
                    );
                }
                $allReportUids[$report->reportUid] = true;
            }
            $aggregate = (new ReviewAggregator())->aggregate($reports);
            $expectedRoundUids = $aggregate->reportUids;
            $artifactRoundUids = $round['report_uids'] ?? null;
            if (!is_array($artifactRoundUids)) {
                throw new InvalidArgumentException(
                    'Review effect report round omits aggregate identities.'
                );
            }
            $artifactRoundUids = array_values($artifactRoundUids);
            sort($artifactRoundUids, SORT_STRING);
            if ($artifactRoundUids !== $expectedRoundUids
                || ($round['aggregate_score'] ?? null) !== $aggregate->score
                || ($round['issue_count'] ?? null) !== count($aggregate->issues)
                || HarnessPersistenceValue::canonicalMysqlJson($round['issues'] ?? null)
                    !== HarnessPersistenceValue::canonicalMysqlJson(array_map(
                        static fn (ReviewIssue $issue): array => $issue->toArray(),
                        $aggregate->issues
                    ))) {
                throw new InvalidArgumentException(
                    'Review effect aggregate does not bind the exact report set.'
                );
            }
            $expectedInputHash = $outputHash;
        }
        if (!hash_equals($expectedInputHash, $selectedDraftHash)) {
            throw new InvalidArgumentException(
                'Review effect report chain does not terminate at the committed Draft.'
            );
        }

        $uids = array_keys($allReportUids);
        sort($uids, SORT_STRING);
        return $uids;
    }

    /** @param array<string,mixed> $payload */
    private static function reportFromArtifact(
        array $payload,
        string $expectedDraftHash
    ): ReviewReport {
        $issuePayloads = $payload['issues'] ?? null;
        if (($payload['schema_version'] ?? null) !== 1
            || !is_array($issuePayloads)
            || ($payload['draft_hash'] ?? null) !== $expectedDraftHash
            || !is_string($payload['reviewer'] ?? null)
            || !is_string($payload['version'] ?? null)
            || !is_int($payload['score'] ?? null)) {
            throw new InvalidArgumentException('Review effect report coordinates are malformed.');
        }
        $issues = [];
        foreach (array_values($issuePayloads) as $issuePayload) {
            if (!is_array($issuePayload)
                || !is_string($issuePayload['code'] ?? null)
                || !is_string($issuePayload['severity'] ?? null)
                || !is_int($issuePayload['confidence'] ?? null)
                    && !is_float($issuePayload['confidence'] ?? null)
                || !is_string($issuePayload['evidence'] ?? null)
                || !is_string($issuePayload['suggestion'] ?? null)
                || !is_bool($issuePayload['canon_impact'] ?? null)) {
                throw new InvalidArgumentException('Review effect issue is malformed.');
            }
            $severity = ReviewSeverity::tryFrom($issuePayload['severity']);
            if (!$severity instanceof ReviewSeverity) {
                throw new InvalidArgumentException('Review effect issue severity is unknown.');
            }
            $issue = new ReviewIssue(
                $issuePayload['code'],
                $severity,
                (float)$issuePayload['confidence'],
                $issuePayload['evidence'],
                $issuePayload['suggestion'],
                $issuePayload['canon_impact']
            );
            if (HarnessPersistenceValue::canonicalMysqlJson($issue->toArray())
                !== HarnessPersistenceValue::canonicalMysqlJson($issuePayload)) {
                throw new InvalidArgumentException(
                    'Review effect issue UID does not match its immutable payload.'
                );
            }
            $issues[] = $issue;
        }
        $report = new ReviewReport(
            $payload['reviewer'],
            $payload['version'],
            $expectedDraftHash,
            $issues,
            $payload['score']
        );
        if (HarnessPersistenceValue::canonicalMysqlJson($report->toArray())
            !== HarnessPersistenceValue::canonicalMysqlJson($payload)) {
            throw new InvalidArgumentException(
                'Review effect report UID does not match its immutable payload.'
            );
        }
        return $report;
    }
}

/**
 * Effect implementations receive a stable effectUid and MUST make the target
 * mutation idempotent by that UID. Delivery is at-least-once until the durable
 * receipt exists, including a crash after the mutation but before the receipt.
 */
interface ReviewEffectApplier
{
    public function applyAfterCommit(CommittedReviewEffects $effects): void;
}

final class CallableReviewEffectApplier implements ReviewEffectApplier
{
    private Closure $effect;

    /** @param callable(CommittedReviewEffects):void $effect */
    public function __construct(callable $effect)
    {
        $this->effect = Closure::fromCallable($effect);
    }

    public function applyAfterCommit(CommittedReviewEffects $effects): void
    {
        ($this->effect)($effects);
    }
}

final class NoopReviewEffectApplier implements ReviewEffectApplier
{
    public function applyAfterCommit(CommittedReviewEffects $effects): void
    {
        unset($effects);
    }
}

/** Immutable JSON ledger row backed by a durable Artifact in production. */
final readonly class ReviewEffectLedgerRecord
{
    /** @var array<string,mixed> */
    public array $payload;
    /** @var array<string,mixed> */
    public array $reference;

    /** @param array<string,mixed> $payload @param array<string,mixed> $reference */
    public function __construct(
        array $payload,
        public string $contentHash,
        array $reference = []
    ) {
        HarnessPersistenceValue::hash($contentHash, 'review_effect_ledger.content_hash');
        if (!hash_equals(
            $contentHash,
            hash('sha256', HarnessPersistenceValue::canonicalMysqlJson($payload))
        )) {
            throw new InvalidArgumentException(
                'Review effect ledger hash does not match its canonical payload.'
            );
        }
        HarnessSensitiveDataGuard::assertSafeStructure(
            $reference,
            'review_effect_ledger.reference'
        );
        $this->payload = $payload;
        $this->reference = $reference;
    }
}

interface ReviewEffectLedger
{
    public function find(string $logicalKey, string $artifactType): ?ReviewEffectLedgerRecord;

    /** @param array<string,mixed> $payload */
    public function storeIdempotent(
        string $logicalKey,
        string $artifactType,
        array $payload
    ): ReviewEffectLedgerRecord;
}

/** The production default: materialize an immutable, idempotent applied event. */
final class ArtifactReviewEffectApplier implements ReviewEffectApplier
{
    public function __construct(private readonly ReviewEffectLedger $ledger)
    {
    }

    public function applyAfterCommit(CommittedReviewEffects $effects): void
    {
        $record = $this->ledger->storeIdempotent(
            'review.effects.applied',
            'review.effects.applied',
            $effects->appliedArtifact()
        );
        if (HarnessPersistenceValue::canonicalMysqlJson($record->payload)
            !== HarnessPersistenceValue::canonicalMysqlJson($effects->appliedArtifact())) {
            throw new HarnessIdempotencyConflictException(
                'Applied Review effect does not bind the active command.'
            );
        }
    }
}

final readonly class ReviewEffectDispatchResult
{
    public function __construct(
        public string $effectUid,
        public bool $recoveredReceipt,
        public ReviewEffectLedgerRecord $command,
        public ReviewEffectLedgerRecord $receipt
    ) {
    }
}

/**
 * Durable command -> idempotent application -> durable receipt protocol.
 * A null command is the explicit frozen-off/no-Review path and has no writes.
 */
final class DurableReviewEffectDispatcher
{
    public function __construct(private readonly ReviewEffectApplier $applier)
    {
    }

    public function dispatchAfterCommit(
        ?CommittedReviewEffects $effects,
        ReviewEffectLedger $ledger
    ): ?ReviewEffectDispatchResult {
        if ($effects === null) {
            return null;
        }

        $command = $ledger->storeIdempotent(
            'review.effects.command',
            'review.effects.command',
            $effects->commandArtifact()
        );
        $effects->assertCommandArtifact($command->payload);

        $existingReceipt = $ledger->find(
            'review.effects.receipt',
            'review.effects.receipt'
        );
        if ($existingReceipt !== null) {
            $effects->assertReceiptArtifact(
                $existingReceipt->payload,
                $command->contentHash
            );
            return new ReviewEffectDispatchResult(
                $effects->effectUid,
                true,
                $command,
                $existingReceipt
            );
        }

        $this->applier->applyAfterCommit($effects);
        $receipt = $ledger->storeIdempotent(
            'review.effects.receipt',
            'review.effects.receipt',
            $effects->receiptArtifact($command->contentHash)
        );
        $effects->assertReceiptArtifact($receipt->payload, $command->contentHash);
        return new ReviewEffectDispatchResult(
            $effects->effectUid,
            false,
            $command,
            $receipt
        );
    }
}
