<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/../persistence/HarnessPersistenceSupport.php';
require_once __DIR__ . '/../persistence/HarnessSensitiveDataGuard.php';

enum ApprovalActionType: string
{
    case OVERWRITE_CHAPTER = 'overwrite_chapter';
    case FORCE_COMMIT = 'force_commit';
    case CHARACTER_STATE = 'character_state';
    case WORLD_RULE = 'world_rule';
    case BULK_REWRITE = 'bulk_rewrite';
    case ROLLBACK = 'rollback';
    case BRANCH_MERGE = 'branch_merge';
}

enum ApprovalRiskLevel: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
    case CRITICAL = 'critical';
}

enum ApprovalRequestState: string
{
    case OPEN = 'open';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case STALE = 'stale';
    case CANCELED = 'canceled';

    public function isTerminal(): bool
    {
        return $this !== self::OPEN;
    }
}

final readonly class ApprovalRequestDraft
{
    public string $approvalUid;
    public int $userId;
    public int $novelId;
    public int $chapterId;
    public int $runId;
    public int $candidateId;
    public string $idempotencyKey;
    public ApprovalActionType $actionType;
    public ApprovalRiskLevel $riskLevel;
    public ?string $baseRevisionHash;
    public string $policySnapshotJson;
    public string $policySnapshotHash;
    public ?int $summaryArtifactId;
    public string $requestedBy;
    public ?DateTimeImmutable $expiresAt;

    /** @param array<mixed> $policySnapshot */
    public function __construct(
        string $approvalUid,
        int $userId,
        int $novelId,
        int $chapterId,
        int $runId,
        int $candidateId,
        string $idempotencyKey,
        ApprovalActionType $actionType,
        ApprovalRiskLevel $riskLevel,
        ?string $baseRevisionHash,
        array $policySnapshot,
        ?int $summaryArtifactId,
        string $requestedBy,
        ?DateTimeImmutable $expiresAt
    ) {
        $this->approvalUid = HarnessPersistenceValue::uid($approvalUid, 'approval_uid');
        $this->userId = HarnessPersistenceValue::positiveInt($userId, 'user_id');
        $this->novelId = HarnessPersistenceValue::positiveInt($novelId, 'novel_id');
        $this->chapterId = HarnessPersistenceValue::positiveInt($chapterId, 'chapter_id');
        $this->runId = HarnessPersistenceValue::positiveInt($runId, 'run_id');
        $this->candidateId = HarnessPersistenceValue::positiveInt($candidateId, 'candidate_id');
        $this->idempotencyKey = HarnessPersistenceValue::asciiString(
            $idempotencyKey,
            'idempotency_key',
            1,
            191
        );
        HarnessSensitiveDataGuard::assertSafeLabel($this->idempotencyKey, 'idempotency_key');
        $this->actionType = $actionType;
        $this->riskLevel = $riskLevel;
        $this->baseRevisionHash = HarnessPersistenceValue::optionalHash(
            $baseRevisionHash,
            'base_revision_hash'
        );
        HarnessSensitiveDataGuard::assertSafeStructure($policySnapshot, 'approval.policy_snapshot');
        $this->policySnapshotJson = HarnessPersistenceValue::canonicalMysqlJson(
            $policySnapshot
        );
        HarnessSensitiveDataGuard::assertSafeText(
            $this->policySnapshotJson,
            'approval.policy_snapshot'
        );
        $this->policySnapshotHash = HarnessPersistenceValue::policySnapshotHash(
            $policySnapshot,
            $this->policySnapshotJson
        );
        $this->summaryArtifactId = HarnessPersistenceValue::optionalPositiveInt(
            $summaryArtifactId,
            'summary_artifact_id'
        );
        $this->requestedBy = HarnessPersistenceValue::asciiString(
            $requestedBy,
            'requested_by',
            1,
            120
        );
        HarnessSensitiveDataGuard::assertSafeLabel($this->requestedBy, 'requested_by');
        $this->expiresAt = $expiresAt;
    }

    /** @param array<string,mixed> $row */
    public function assertIdempotentRow(array $row): void
    {
        $request = ApprovalRequest::fromRow($row);
        $expiresAt = $this->expiresAt === null
            ? null
            : HarnessPersistenceValue::mysqlDate($this->expiresAt);
        if ($request->approvalUid !== $this->approvalUid
            || $request->userId !== $this->userId
            || $request->novelId !== $this->novelId
            || $request->chapterId !== $this->chapterId
            || $request->runId !== $this->runId
            || $request->candidateId !== $this->candidateId
            || $request->idempotencyKey !== $this->idempotencyKey
            || $request->actionType !== $this->actionType
            || $request->riskLevel !== $this->riskLevel
            || $request->baseRevisionHash !== $this->baseRevisionHash
            || !hash_equals($request->policySnapshotJson, $this->policySnapshotJson)
            || !hash_equals($request->policySnapshotHash, $this->policySnapshotHash)
            || $request->summaryArtifactId !== $this->summaryArtifactId
            || $request->requestedBy !== $this->requestedBy
            || $request->expiresAt !== $expiresAt) {
            throw new HarnessIdempotencyConflictException(
                'Approval request uniqueness resolved to different immutable coordinates.'
            );
        }
    }
}

final readonly class ApprovalDecision
{
    public ApprovalRequestState $state;
    public string $idempotencyKey;
    public string $decidedBy;
    public ?string $reason;
    public DateTimeImmutable $decidedAt;
    public string $payloadHash;

    public function __construct(
        ApprovalRequestState $state,
        string $idempotencyKey,
        string $decidedBy,
        ?string $reason,
        DateTimeImmutable $decidedAt
    ) {
        if (!in_array($state, [
            ApprovalRequestState::APPROVED,
            ApprovalRequestState::REJECTED,
            ApprovalRequestState::CANCELED,
        ], true)) {
            throw new InvalidArgumentException(
                'A caller may decide approved, rejected, or canceled; stale is derived by the repository.'
            );
        }
        $this->state = $state;
        $this->idempotencyKey = HarnessPersistenceValue::asciiString(
            $idempotencyKey,
            'decision_idempotency_key',
            1,
            191
        );
        HarnessSensitiveDataGuard::assertSafeLabel(
            $this->idempotencyKey,
            'decision_idempotency_key'
        );
        $this->decidedBy = HarnessPersistenceValue::asciiString(
            $decidedBy,
            'decided_by',
            1,
            120
        );
        HarnessSensitiveDataGuard::assertSafeLabel($this->decidedBy, 'decided_by');
        $this->reason = HarnessPersistenceValue::optionalString(
            $reason,
            'decision_reason',
            500
        );
        if ($this->reason !== null) {
            HarnessSensitiveDataGuard::assertSafeText($this->reason, 'decision_reason');
        }
        $this->decidedAt = $decidedAt;
        $this->payloadHash = hash('sha256', HarnessPersistenceValue::canonicalJson([
            'decided_by' => $this->decidedBy,
            'decision_reason' => $this->reason,
            'requested_state' => $this->state->value,
        ]));
    }
}

final readonly class ApprovalRequest
{
    public function __construct(
        public int $id,
        public string $approvalUid,
        public int $userId,
        public int $novelId,
        public int $chapterId,
        public ?int $runId,
        public int $candidateId,
        public string $idempotencyKey,
        public ApprovalActionType $actionType,
        public ApprovalRiskLevel $riskLevel,
        public ApprovalRequestState $state,
        public ?string $baseRevisionHash,
        public string $policySnapshotJson,
        public string $policySnapshotHash,
        public ?int $summaryArtifactId,
        public string $requestedBy,
        public ?string $decidedBy,
        public ?string $decisionReason,
        public ?string $decisionIdempotencyKey,
        public ?string $decisionPayloadHash,
        public ?string $expiresAt,
        public string $createdAt,
        public ?string $decidedAt,
        public string $updatedAt
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        HarnessPersistenceValue::requireFields($row, [
            'id', 'approval_uid', 'user_id', 'novel_id', 'chapter_id', 'run_id',
            'candidate_id', 'idempotency_key', 'action_type', 'risk_level', 'state',
            'base_revision_hash', 'policy_snapshot_json', 'policy_snapshot_hash',
            'summary_artifact_id', 'requested_by', 'decided_by', 'decision_reason',
            'decision_idempotency_key', 'decision_payload_hash', 'expires_at',
            'created_at', 'decided_at', 'updated_at',
        ], 'approval_request');

        $action = is_string($row['action_type'])
            ? ApprovalActionType::tryFrom($row['action_type'])
            : null;
        $risk = is_string($row['risk_level'])
            ? ApprovalRiskLevel::tryFrom($row['risk_level'])
            : null;
        $state = is_string($row['state'])
            ? ApprovalRequestState::tryFrom($row['state'])
            : null;
        if (!$action instanceof ApprovalActionType
            || !$risk instanceof ApprovalRiskLevel
            || !$state instanceof ApprovalRequestState) {
            throw new InvalidArgumentException('Approval request contains an unknown enum value.');
        }

        $policy = HarnessPersistenceValue::decodeJsonArray(
            $row['policy_snapshot_json'],
            'policy_snapshot_json'
        );
        HarnessSensitiveDataGuard::assertSafeStructure($policy, 'approval.policy_snapshot');
        $policyJson = HarnessPersistenceValue::canonicalMysqlJson($policy);
        HarnessSensitiveDataGuard::assertSafeText($policyJson, 'approval.policy_snapshot');
        $policyHash = HarnessPersistenceValue::hash(
            $row['policy_snapshot_hash'],
            'policy_snapshot_hash'
        );
        if (!hash_equals(
            HarnessPersistenceValue::policySnapshotHash($policy, $policyJson),
            $policyHash
        )) {
            throw new HarnessPersistenceException('Approval policy snapshot hash is invalid.');
        }

        $requestedBy = HarnessPersistenceValue::asciiString(
            $row['requested_by'],
            'requested_by',
            1,
            120
        );
        $decidedBy = HarnessPersistenceValue::optionalAsciiString(
            $row['decided_by'],
            'decided_by',
            120
        );
        $decisionReason = HarnessPersistenceValue::optionalString(
            $row['decision_reason'],
            'decision_reason',
            500
        );
        HarnessSensitiveDataGuard::assertSafeLabel($requestedBy, 'requested_by');
        if ($decidedBy !== null) {
            HarnessSensitiveDataGuard::assertSafeLabel($decidedBy, 'decided_by');
        }
        if ($decisionReason !== null) {
            HarnessSensitiveDataGuard::assertSafeText($decisionReason, 'decision_reason');
        }

        $decisionKey = HarnessPersistenceValue::optionalAsciiString(
            $row['decision_idempotency_key'],
            'decision_idempotency_key',
            191
        );
        $decisionHash = HarnessPersistenceValue::optionalHash(
            $row['decision_payload_hash'],
            'decision_payload_hash'
        );
        if ($state === ApprovalRequestState::OPEN) {
            if ($decidedBy !== null || $decisionReason !== null || $decisionKey !== null
                || $decisionHash !== null || $row['decided_at'] !== null) {
                throw new HarnessPersistenceException('Open approval contains terminal decision fields.');
            }
        } elseif ($decidedBy === null || $decisionKey === null || $decisionHash === null
            || $row['decided_at'] === null) {
            throw new HarnessPersistenceException('Terminal approval is missing decision provenance.');
        }

        return new self(
            HarnessPersistenceValue::positiveInt($row['id'], 'id'),
            HarnessPersistenceValue::uid($row['approval_uid'], 'approval_uid'),
            HarnessPersistenceValue::positiveInt($row['user_id'], 'user_id'),
            HarnessPersistenceValue::positiveInt($row['novel_id'], 'novel_id'),
            HarnessPersistenceValue::positiveInt($row['chapter_id'], 'chapter_id'),
            HarnessPersistenceValue::optionalPositiveInt($row['run_id'], 'run_id'),
            HarnessPersistenceValue::positiveInt($row['candidate_id'], 'candidate_id'),
            HarnessPersistenceValue::asciiString($row['idempotency_key'], 'idempotency_key', 1, 191),
            $action,
            $risk,
            $state,
            HarnessPersistenceValue::optionalHash($row['base_revision_hash'], 'base_revision_hash'),
            $policyJson,
            $policyHash,
            HarnessPersistenceValue::optionalPositiveInt($row['summary_artifact_id'], 'summary_artifact_id'),
            $requestedBy,
            $decidedBy,
            $decisionReason,
            $decisionKey,
            $decisionHash,
            HarnessPersistenceValue::optionalMysqlDate($row['expires_at'], 'expires_at'),
            HarnessPersistenceValue::optionalMysqlDate($row['created_at'], 'created_at')
                ?? throw new InvalidArgumentException('approval_request.created_at is required.'),
            HarnessPersistenceValue::optionalMysqlDate($row['decided_at'], 'decided_at'),
            HarnessPersistenceValue::optionalMysqlDate($row['updated_at'], 'updated_at')
                ?? throw new InvalidArgumentException('approval_request.updated_at is required.')
        );
    }
}
