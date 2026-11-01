<?php

declare(strict_types=1);

defined('API_VIA_ROUTER') or die('Direct access denied.');

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
require_once dirname(__DIR__) . '/includes/harness/candidates/CandidateUiPolicy.php';
require_once dirname(__DIR__) . '/includes/harness/branches/bootstrap.php';
require_once dirname(__DIR__) . '/includes/quality/WritePolicySnapshotFactory.php';

requireLoginApi();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $rawInput = (string)file_get_contents('php://input');
    if (strlen($rawInput) > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('Branch request exceeds the 5 MiB safety limit.');
    }
    $input = $rawInput === '' ? [] : json_decode($rawInput, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($input) || ($input !== [] && array_is_list($input))) {
        throw new InvalidArgumentException('Request JSON must be an object.');
    }
    if ($method === 'GET' && $rawInput !== '') {
        throw new InvalidArgumentException('Branch GET requests must not contain a body.');
    }
    branchApiAssertRoute($input['route'] ?? null);
    $actionValue = $_GET['action'] ?? ($input['action'] ?? '');
    if (!is_string($actionValue)) {
        throw new InvalidArgumentException('action must be a string.');
    }
    $action = trim($actionValue);
    if (!in_array($action, [
        'list',
        'revision',
        'create_fork',
        'continue_branch',
        'memory_context',
        'memory_write',
        'merge_propose',
    ], true)) {
        throw new InvalidArgumentException('Unknown Branch action.');
    }
    branchApiAssertSameCoordinate($_GET['action'] ?? null, $input['action'] ?? null, 'action');

    $queryNovelId = array_key_exists('novel_id', $_GET)
        ? branchApiPositiveInt($_GET['novel_id'], 'novel_id')
        : null;
    $bodyNovelId = array_key_exists('novel_id', $input)
        ? branchApiPositiveInt($input['novel_id'], 'novel_id')
        : null;
    branchApiAssertSameCoordinate($queryNovelId, $bodyNovelId, 'novel_id');
    $novelId = $queryNovelId ?? $bodyNovelId;
    if (!is_int($novelId)) {
        throw new InvalidArgumentException('novel_id is required.');
    }
    $userId = currentUserId();
    // checkNovelOwnership() can auto-claim legacy NULL owners. This endpoint
    // also serves GET reads, so use a strictly read-only exact-scope check and
    // never let a CSRF-exempt read mutate ownership.
    branchApiAssertOwnedNovel($novelId, $userId);
    CandidateUiPolicy::assertEnabledForNovel($novelId);

    $pdo = DB::getPdo();
    $branches = new M65BranchRepository($pdo);
    $memory = new M66BranchMemoryRepository($pdo);

    if ($action === 'list') {
        branchApiRequireMethod($method, 'GET');
        branchApiAssertGetFields(['route', 'action', 'novel_id']);
        echo branchApiEncodeResponse([
            'ok' => true,
            'novel_id' => $novelId,
            'branches' => branchApiList($userId, $novelId),
        ]);
        exit;
    }

    if ($action === 'memory_context') {
        branchApiRequireMethod($method, 'GET');
        branchApiAssertGetFields([
            'route', 'action', 'novel_id', 'branch_uid', 'record_types', 'limit',
        ]);
        $branchUid = branchApiUid($_GET['branch_uid'] ?? null, 'branch_uid');
        $recordTypesRaw = $_GET['record_types'] ?? [];
        if (is_string($recordTypesRaw)) {
            $recordTypesRaw = $recordTypesRaw === ''
                ? []
                : array_values(array_filter(array_map('trim', explode(',', $recordTypesRaw))));
        }
        if (!is_array($recordTypesRaw) || !array_is_list($recordTypesRaw)) {
            throw new InvalidArgumentException('record_types must be a list or comma-separated string.');
        }
        if (count($recordTypesRaw) > 50) {
            throw new InvalidArgumentException('record_types accepts at most 50 entries.');
        }
        $limit = branchApiPositiveInt($_GET['limit'] ?? 16, 'limit');
        if ($limit > 16) {
            throw new InvalidArgumentException(
                'Branch memory API limit must be between 1 and 16 records.'
            );
        }
        $snapshot = (new BranchMemoryContextProvider($memory))->load(
            new BranchMemoryScope($userId, $novelId, $branchUid),
            $recordTypesRaw,
            $limit
        );
        echo branchApiEncodeResponse(['ok' => true, 'context' => $snapshot->toArray()]);
        exit;
    }

    if ($action === 'revision') {
        branchApiRequireMethod($method, 'GET');
        branchApiAssertGetFields([
            'route', 'action', 'novel_id', 'revision_uid',
        ]);
        $revisionUid = branchApiUid($_GET['revision_uid'] ?? null, 'revision_uid');
        $revision = $branches->findRevision($revisionUid);
        $branch = $revision instanceof ChapterRevisionRecord
            ? $branches->findBranch($revision->branchUid)
            : null;
        if (!$revision instanceof ChapterRevisionRecord
            || !$branch instanceof StoryBranchRecord
            || $revision->userId !== $userId
            || $revision->novelId !== $novelId
            || $branch->userId !== $userId
            || $branch->novelId !== $novelId) {
            throw new BranchApiScopeException(
                'Revision is outside the exact owned Branch scope.'
            );
        }
        echo branchApiEncodeResponse([
            'ok' => true,
            'revision' => branchApiRevisionRecord($revision),
        ]);
        exit;
    }

    branchApiRequireMethod($method, 'POST');
    branchApiAssertGetFields(['route', 'action', 'novel_id']);

    if ($action === 'create_fork') {
        branchApiAssertFields($input, [
            'route', 'action', 'novel_id', 'name',
            'source_revision_uid', 'idempotency_key',
        ]);
        $nameValue = $input['name'] ?? null;
        if (!is_string($nameValue) || preg_match('//u', $nameValue) !== 1) {
            throw new InvalidArgumentException('Branch name must be valid UTF-8 text.');
        }
        $name = trim($nameValue);
        $nameLength = function_exists('mb_strlen')
            ? mb_strlen($name, 'UTF-8')
            : strlen($name);
        if ($name === '' || $nameLength > 100 || strlen($name) > 100
            || preg_match('/^\s*$/u', $name) === 1
            || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            throw new InvalidArgumentException(
                'Branch name must be 1-100 UTF-8 bytes without control characters.'
            );
        }
        $sourceRevisionUid = branchApiUid(
            $input['source_revision_uid'] ?? null,
            'source_revision_uid'
        );
        $idempotencyValue = $input['idempotency_key'] ?? null;
        if (!is_string($idempotencyValue)) {
            throw new InvalidArgumentException('idempotency_key must be a string.');
        }
        $idempotencyKey = trim($idempotencyValue);
        if (preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException(
                'idempotency_key must be 8-128 safe token characters.'
            );
        }
        $sourceRevision = $branches->findRevision($sourceRevisionUid);
        if (!$sourceRevision instanceof ChapterRevisionRecord
            || $sourceRevision->userId !== $userId
            || $sourceRevision->novelId !== $novelId) {
            throw new BranchApiScopeException(
                'Fork source Revision is outside the owned Novel.'
            );
        }
        $sourceBranch = $branches->findBranch($sourceRevision->branchUid);
        if (!$sourceBranch instanceof StoryBranchRecord
            || $sourceBranch->userId !== $userId
            || $sourceBranch->novelId !== $novelId
            || $sourceBranch->state !== StoryBranchState::ACTIVE) {
            throw new BranchApiConflictException(
                'Fork source Branch is stale or outside scope.'
            );
        }
        $branchUid = substr(hash('sha256', implode("\0", [
            'branch-api-create-v1',
            (string)$userId,
            (string)$novelId,
            $idempotencyKey,
        ])), 0, 32);
        $fork = (new BranchForkService($branches))->createSeededFork(new StoryBranchRecord(
            1,
            $branchUid,
            $userId,
            $novelId,
            $name,
            false,
            StoryBranchState::ACTIVE,
            $sourceBranch->branchUid,
            $sourceRevisionUid
        ), $sourceRevision);
        echo branchApiEncodeResponse([
            'ok' => true,
            'branch' => branchApiBranchRecord($fork->branch, [[
                'chapter_id' => $fork->head->chapterId,
                'revision_uid' => $fork->head->revisionUid,
                'head_generation' => $fork->head->headGeneration,
                'seeded_from_revision_uid' => $sourceRevisionUid,
            ]]),
        ]);
        exit;
    }

    if ($action === 'continue_branch') {
        branchApiAssertFields($input, [
            'route', 'action', 'novel_id', 'branch_uid', 'base_revision_uid',
            'expected_head_generation', 'content', 'idempotency_key',
        ]);
        $branchUid = branchApiUid($input['branch_uid'] ?? null, 'branch_uid');
        $baseRevisionUid = branchApiUid(
            $input['base_revision_uid'] ?? null,
            'base_revision_uid'
        );
        if (!is_int($input['expected_head_generation'] ?? null)) {
            throw new InvalidArgumentException(
                'expected_head_generation must be a positive JSON integer.'
            );
        }
        $expectedHeadGeneration = branchApiPositiveInt(
            $input['expected_head_generation'],
            'expected_head_generation'
        );
        $content = $input['content'] ?? null;
        if (!is_string($content) || trim($content) === ''
            || strlen($content) > 4 * 1024 * 1024) {
            throw new InvalidArgumentException(
                'Branch continuation content must be non-empty and at most 4 MiB.'
            );
        }
        $idempotencyValue = $input['idempotency_key'] ?? null;
        if (!is_string($idempotencyValue)) {
            throw new InvalidArgumentException('idempotency_key must be a string.');
        }
        $idempotencyKey = trim($idempotencyValue);
        if (preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException(
                'idempotency_key must be 8-128 safe token characters.'
            );
        }
        $branch = $branches->findBranch($branchUid);
        $baseRevision = $branches->findRevision($baseRevisionUid);
        if (!$branch instanceof StoryBranchRecord
            || !$baseRevision instanceof ChapterRevisionRecord
            || $branch->userId !== $userId
            || $branch->novelId !== $novelId
            || $branch->isMain
            || $branch->state !== StoryBranchState::ACTIVE
            || $baseRevision->userId !== $userId
            || $baseRevision->novelId !== $novelId
            || $baseRevision->branchUid !== $branchUid) {
            throw new BranchApiScopeException(
                'Continuation Branch or base Revision is outside the exact owned scope.'
            );
        }
        $continued = (new BranchContinuationService($pdo, $branches))->continue(
            $branch,
            $baseRevision,
            $expectedHeadGeneration,
            $content,
            $idempotencyKey
        );
        echo branchApiEncodeResponse([
            'ok' => true,
            'candidate_uid' => $continued->candidateUid,
            'revision' => branchApiRevisionRecord($continued->revision),
            'head' => [
                'branch_uid' => $continued->head->branchUid,
                'chapter_id' => $continued->head->chapterId,
                'revision_uid' => $continued->head->revisionUid,
                'head_generation' => $continued->head->headGeneration,
            ],
            'canonical_written' => false,
            'merge_requires_approval' => true,
            'replayed' => $continued->replayed,
        ]);
        exit;
    }

    if ($action === 'memory_write') {
        branchApiAssertFields($input, [
            'route', 'action', 'novel_id', 'branch_uid', 'memory_key',
            'record_type', 'payload', 'source_revision_uid', 'expected_version',
        ]);
        $branchUid = branchApiUid($input['branch_uid'] ?? null, 'branch_uid');
        $payload = $input['payload'] ?? null;
        if (!is_array($payload)) {
            throw new InvalidArgumentException('payload must be a JSON object or list.');
        }
        $expectedVersion = null;
        if (array_key_exists('expected_version', $input)) {
            if (!is_int($input['expected_version']) || $input['expected_version'] <= 0) {
                throw new InvalidArgumentException(
                    'expected_version must be a positive JSON integer.'
                );
            }
            $expectedVersion = $input['expected_version'];
        }
        $sourceRevisionUid = array_key_exists('source_revision_uid', $input)
            && $input['source_revision_uid'] !== null
            ? branchApiUid($input['source_revision_uid'], 'source_revision_uid')
            : null;
        $memoryKey = $input['memory_key'] ?? null;
        $recordType = $input['record_type'] ?? null;
        if (!is_string($memoryKey) || !is_string($recordType)) {
            throw new InvalidArgumentException(
                'memory_key and record_type must be strings.'
            );
        }
        $stored = $memory->write(new BranchMemoryMutation(
            new BranchMemoryScope($userId, $novelId, $branchUid),
            $memoryKey,
            $recordType,
            $payload,
            $sourceRevisionUid,
            $expectedVersion
        ));
        echo branchApiEncodeResponse([
            'ok' => true,
            'memory' => branchApiMemoryRecord($stored),
        ]);
        exit;
    }

    branchApiAssertFields($input, [
        'route', 'action', 'novel_id', 'source_revision_uid',
        'target_branch_uid', 'content',
    ]);
    $sourceRevisionUid = branchApiUid(
        $input['source_revision_uid'] ?? null,
        'source_revision_uid'
    );
    $targetBranchUid = branchApiUid(
        $input['target_branch_uid'] ?? null,
        'target_branch_uid'
    );
    $content = $input['content'] ?? null;
    if (!is_string($content) || trim($content) === '') {
        throw new InvalidArgumentException('Merged content is required.');
    }
    if (strlen($content) > 4 * 1024 * 1024) {
        throw new InvalidArgumentException('Merged content exceeds the 4 MiB safety limit.');
    }

    $sourceRevision = $branches->findRevision($sourceRevisionUid);
    $sourceBranch = $sourceRevision instanceof ChapterRevisionRecord
        ? $branches->findBranch($sourceRevision->branchUid)
        : null;
    $targetBranch = $branches->findBranch($targetBranchUid);
    if (!$sourceRevision instanceof ChapterRevisionRecord
        || !$sourceBranch instanceof StoryBranchRecord
        || !$targetBranch instanceof StoryBranchRecord
        || $sourceRevision->userId !== $userId
        || $sourceRevision->novelId !== $novelId
        || $sourceBranch->userId !== $userId
        || $sourceBranch->novelId !== $novelId
        || $targetBranch->userId !== $userId
        || $targetBranch->novelId !== $novelId
        || !$targetBranch->isMain) {
        throw new BranchApiScopeException(
            'Merge Branch or Revision is outside the exact owned scope.'
        );
    }
    $targetHead = $branches->head($targetBranchUid, $sourceRevision->chapterId);
    $targetRevision = $targetHead->revisionUid === null
        ? null
        : $branches->findRevision($targetHead->revisionUid);
    if ($targetHead->revisionUid !== null
        && !$targetRevision instanceof ChapterRevisionRecord) {
        throw new BranchApiConflictException(
            'Merge target head points to an unavailable Revision.'
        );
    }
    $chapter = DB::fetch(
        // novels 表的每章目标字数列是 chapter_words（无 target_words 列），
        // 与 M65MergeCandidateSink::lockCurrentHeads 的口径一致。
        'SELECT c.*, n.chapter_words AS novel_target_words '
        . 'FROM chapters c INNER JOIN novels n ON n.id=c.novel_id '
        . 'WHERE c.id=? AND c.novel_id=? AND n.user_id=? LIMIT 1',
        [$sourceRevision->chapterId, $novelId, $userId]
    );
    if (!is_array($chapter)) {
        throw new BranchApiScopeException(
            'Merge Chapter is outside the exact owned scope.'
        );
    }
    $targetWords = (int)($chapter['novel_target_words'] ?? 0);
    if ($targetWords <= 0) {
        throw new BranchApiConflictException(
            'Novel target_words is invalid for a frozen merge policy.'
        );
    }
    $policy = WritePolicySnapshotFactory::captureForChapter($novelId, $targetWords);
    $service = new BranchMergeService(
        $branches,
        new ProductionBranchMergeHardGate($policy, $chapter),
        new M65MergeCandidateSink(
            $pdo,
            static fn(int $requestedNovelId, int $requestedTargetWords): WritePolicySnapshot =>
                $requestedNovelId === $novelId && $requestedTargetWords === $targetWords
                    ? $policy
                    : throw new BranchApiConflictException(
                        'Merge policy coordinates changed before persistence.'
                    )
        )
    );
    $proposal = $service->propose(
        $sourceBranch,
        $targetBranch,
        $sourceRevision,
        $targetRevision,
        $content
    );
    $approval = DB::fetch(
        'SELECT ar.approval_uid,ar.state,ar.expires_at,'
        . 'cc.lifecycle AS candidate_lifecycle,cc.gate_state AS candidate_gate_state '
        . 'FROM approval_requests ar INNER JOIN chapter_candidates cc ON cc.id=ar.candidate_id '
        . 'WHERE ar.user_id=? AND ar.novel_id=? AND cc.candidate_uid=? '
        . "AND ar.action_type='branch_merge' LIMIT 1",
        [$userId, $novelId, $proposal->candidateUid]
    );
    echo branchApiEncodeResponse([
        'ok' => true,
        'candidate' => [
            'candidate_uid' => $proposal->candidateUid,
            'state' => branchApiMergeCandidateState($proposal, $approval),
            'lifecycle' => is_array($approval)
                ? (string)$approval['candidate_lifecycle']
                : 'quarantined',
            'gate_state' => is_array($approval)
                ? (string)$approval['candidate_gate_state']
                : 'quarantined',
            'content_hash' => $proposal->contentHash,
            'target_branch_uid' => $proposal->targetBranchUid,
            'base_revision_uid' => $proposal->baseRevisionUid,
            'merge_source_revision_uid' => $proposal->mergeSourceRevisionUid,
            'requires_approval' => true,
            'findings' => $proposal->findings,
            'policy_snapshot_hash' => $policy->checksum(),
        ],
        'approval' => is_array($approval) ? [
            'approval_uid' => (string)$approval['approval_uid'],
            'state' => (string)$approval['state'],
            'expires_at' => $approval['expires_at'],
        ] : null,
    ]);
} catch (Throwable $exception) {
    $status = match (true) {
        $exception instanceof CandidateUiDisabledException => 403,
        $exception instanceof InvalidArgumentException,
        $exception instanceof JsonException => 400,
        $exception instanceof BranchMemoryScopeException => 404,
        $exception instanceof BranchApiScopeException => 404,
        $exception instanceof BranchApiResponseBudgetException => 413,
        $exception instanceof BranchApiConflictException,
        $exception instanceof BranchMemoryCasMissException,
        $exception instanceof BranchHeadCasMissException,
        is_a($exception, 'HarnessIdempotencyConflictException'),
        is_a($exception, 'HarnessLeaseLostException'),
        is_a($exception, 'HarnessStateTransitionException') => 409,
        default => 500,
    };
    http_response_code($status);
    $publicMessage = match ($status) {
        400 => 'Branch request is invalid.',
        403 => 'Branch features are not enabled for this Novel.',
        404 => 'Branch resource was not found in the authenticated scope.',
        413 => 'Branch response exceeds the safe preview budget.',
        409 => 'Branch operation conflicted with current durable state.',
        default => 'Branch operation failed.',
    };
    $code = match ($status) {
        400 => 'invalid_branch_request',
        403 => 'branch_feature_disabled',
        404 => 'branch_not_found',
        413 => 'branch_response_too_large',
        409 => 'branch_state_conflict',
        default => 'internal_error',
    };
    echo json_encode(
        safe_api_error_payload($exception, $publicMessage, $code),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}

function branchApiRequireMethod(string $actual, string $expected): void
{
    if ($actual !== $expected) {
        throw new InvalidArgumentException("Branch action requires {$expected}.");
    }
}

final class BranchApiScopeException extends RuntimeException
{
}

final class BranchApiResponseBudgetException extends RuntimeException
{
}

final class BranchApiConflictException extends RuntimeException
{
}

/** @param list<string> $allowed */
function branchApiAssertGetFields(array $allowed): void
{
    foreach (array_keys($_GET) as $field) {
        if (!is_string($field) || !in_array($field, $allowed, true)) {
            throw new InvalidArgumentException('Branch query contains unknown fields.');
        }
    }
}

function branchApiAssertRoute(mixed $route): void
{
    if ($route !== null && (!is_string($route) || !hash_equals('branch_actions', $route))) {
        throw new InvalidArgumentException('Branch body route does not match this endpoint.');
    }
}

function branchApiAssertSameCoordinate(mixed $query, mixed $body, string $field): void
{
    if ($query !== null && $body !== null && $query !== $body) {
        throw new InvalidArgumentException("Branch {$field} coordinates disagree.");
    }
}

/** @param array<string,mixed> $payload */
function branchApiEncodeResponse(array $payload): string
{
    $json = json_encode(
        $payload,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if (strlen($json) > 5 * 1024 * 1024) {
        throw new BranchApiResponseBudgetException(
            'Branch response exceeded the 5 MiB preview budget.'
        );
    }
    return $json;
}

function branchApiAssertOwnedNovel(int $novelId, int $userId): void
{
    $owned = DB::fetch(
        'SELECT id FROM novels WHERE id=? AND user_id=? LIMIT 1',
        [$novelId, $userId]
    );
    if (!is_array($owned) || (int)($owned['id'] ?? 0) !== $novelId) {
        throw new BranchApiScopeException(
            'Branch Novel does not exist inside the authenticated user scope.'
        );
    }
}

/** @param array<string,mixed> $input @param list<string> $allowed */
function branchApiAssertFields(array $input, array $allowed): void
{
    $unknown = array_values(array_diff(array_keys($input), $allowed));
    if ($unknown !== []) {
        throw new InvalidArgumentException(
            'Branch request contains unknown fields: ' . implode(', ', $unknown)
        );
    }
}

function branchApiUid(mixed $value, string $field): string
{
    $uid = is_string($value) ? trim($value) : '';
    if (preg_match('/^[a-f0-9]{32}$/D', $uid) !== 1) {
        throw new InvalidArgumentException("{$field} must be a lowercase 32-character UID.");
    }
    return $uid;
}

function branchApiPositiveInt(mixed $value, string $field): int
{
    if (is_int($value)) {
        $integer = $value;
    } elseif (is_string($value)
        && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
        $integer = (int)$value;
        if ((string)$integer !== $value) {
            throw new InvalidArgumentException("{$field} is outside the supported integer range.");
        }
    } else {
        throw new InvalidArgumentException("{$field} must be a positive integer.");
    }
    if ($integer <= 0) {
        throw new InvalidArgumentException("{$field} must be a positive integer.");
    }
    return $integer;
}

function branchApiMergeCandidateState(
    MergeCandidateProposal $proposal,
    mixed $approval
): string {
    if ($proposal->state === MergeCandidateState::QUARANTINED) {
        if (is_array($approval)) {
            throw new RuntimeException(
                'Quarantined merge Candidate unexpectedly resolved to an Approval.'
            );
        }
        return 'quarantined';
    }
    if (!is_array($approval)
        || ($approval['candidate_gate_state'] ?? null) !== 'passed') {
        throw new RuntimeException(
            'Passed merge Candidate is missing its exact durable Approval state.'
        );
    }

    $lifecycle = (string)($approval['candidate_lifecycle'] ?? '');
    $approvalState = (string)($approval['state'] ?? '');
    $state = match ($lifecycle) {
        'draft' => match ($approvalState) {
            'open' => 'waiting_approval',
            'canceled' => 'canceled',
            default => null,
        },
        'approved' => $approvalState === 'approved' ? 'approved' : null,
        'committed' => $approvalState === 'approved' ? 'committed' : null,
        'rejected' => $approvalState === 'rejected' ? 'rejected' : null,
        'stale' => $approvalState === 'stale' ? 'stale' : null,
        default => null,
    };
    if ($state === null) {
        throw new RuntimeException(
            'Merge Candidate lifecycle and Approval state are inconsistent.'
        );
    }
    return $state;
}

/** @return list<array<string,mixed>> */
function branchApiList(int $userId, int $novelId): array
{
    $rows = DB::fetchAll(
        'SELECT branch_uid,name,is_main,state,forked_from_branch_uid,'
        . 'forked_from_revision_uid,created_at,updated_at '
        . 'FROM story_branches WHERE user_id=? AND novel_id=? '
        . 'ORDER BY is_main DESC,created_at ASC,id ASC LIMIT 257',
        [$userId, $novelId]
    ) ?: [];
    if (count($rows) > 256) {
        throw new BranchApiResponseBudgetException(
            'Branch list exceeds the 256-row preview budget.'
        );
    }
    $headRows = DB::fetchAll(
        'SELECT h.branch_uid,h.chapter_id,h.revision_uid,h.head_generation,h.updated_at '
        . 'FROM chapter_branch_heads h INNER JOIN story_branches sb '
        . 'ON sb.branch_uid=h.branch_uid '
        . 'WHERE sb.user_id=? AND sb.novel_id=? '
        . 'ORDER BY h.branch_uid,h.chapter_id LIMIT 4097',
        [$userId, $novelId]
    ) ?: [];
    if (count($headRows) > 4096) {
        throw new BranchApiResponseBudgetException(
            'Branch heads exceed the 4096-row preview budget.'
        );
    }
    $heads = [];
    foreach ($headRows as $head) {
        $heads[(string)$head['branch_uid']][] = [
            'chapter_id' => (int)$head['chapter_id'],
            'revision_uid' => (string)$head['revision_uid'],
            'head_generation' => (int)$head['head_generation'],
            'updated_at' => (string)$head['updated_at'],
        ];
    }
    return array_map(static fn(array $row): array => [
        'branch_uid' => (string)$row['branch_uid'],
        'name' => (string)$row['name'],
        'is_main' => (bool)$row['is_main'],
        'state' => (string)$row['state'],
        'forked_from_branch_uid' => $row['forked_from_branch_uid'],
        'forked_from_revision_uid' => $row['forked_from_revision_uid'],
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
        'heads' => $heads[(string)$row['branch_uid']] ?? [],
    ], $rows);
}

/** @param list<array<string,mixed>> $heads @return array<string,mixed> */
function branchApiBranchRecord(StoryBranchRecord $branch, array $heads): array
{
    return [
        'branch_uid' => $branch->branchUid,
        'name' => $branch->name,
        'is_main' => $branch->isMain,
        'state' => $branch->state->value,
        'forked_from_branch_uid' => $branch->forkedFromBranchUid,
        'forked_from_revision_uid' => $branch->forkedFromRevisionUid,
        'heads' => $heads,
    ];
}

/** @return array<string,mixed> */
function branchApiMemoryRecord(BranchMemoryRecord $record): array
{
    return [
        'memory_uid' => $record->memoryUid,
        'scope' => $record->scope->toArray(),
        'memory_key' => $record->memoryKey,
        'record_type' => $record->recordType,
        'payload' => $record->payload,
        'payload_hash' => $record->payloadHash,
        'source_revision_uid' => $record->sourceRevisionUid,
        'record_version' => $record->recordVersion,
        'created_at' => $record->createdAt,
        'updated_at' => $record->updatedAt,
    ];
}

/** @return array<string,mixed> */
function branchApiRevisionRecord(ChapterRevisionRecord $revision): array
{
    return [
        'revision_uid' => $revision->revisionUid,
        'user_id' => $revision->userId,
        'novel_id' => $revision->novelId,
        'chapter_id' => $revision->chapterId,
        'branch_uid' => $revision->branchUid,
        'content' => $revision->content,
        'content_hash' => $revision->contentHash,
        'parent_revision_uid' => $revision->parentRevisionUid,
        'merge_source_revision_uid' => $revision->mergeSourceRevisionUid,
        'source_candidate_uid' => $revision->sourceCandidateUid,
    ];
}
