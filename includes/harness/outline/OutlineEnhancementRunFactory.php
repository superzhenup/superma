<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/OutlineEnhancementContracts.php';
require_once __DIR__ . '/OutlineEmergencyEpoch.php';
require_once dirname(__DIR__) . '/HarnessControlPlane.php';

final readonly class OutlineEnhancementEnqueueResult
{
    public function __construct(
        public GenerationRun $run,
        public OutlineEnhancementSnapshot $snapshot
    ) {
    }
}

final class OutlineEnhancementRunFactory
{
    public const QUEUE = 'outline';
    public const RUN_TYPE = 'outline.enhancement';
    public const WORKFLOW = 'outline-enhancement';
    public const WORKFLOW_VERSION = 'v1';
    public const DEADLINE_SECONDS = 3600;
    public const MAX_ATTEMPTS = 3;

    private Closure $now;
    private Closure $emergencyEpoch;

    public function __construct(
        private readonly OutlineEnhancementRunStore $runs,
        private readonly OutlineEnhancementStore $store,
        ?callable $now = null,
        ?callable $emergencyEpoch = null
    ) {
        $this->now = $now === null
            ? static fn (): DateTimeImmutable => new DateTimeImmutable(
                'now',
                new DateTimeZone('UTC')
            )
            : Closure::fromCallable($now);
        $this->emergencyEpoch = $emergencyEpoch === null
            ? static fn (): int => OutlineEmergencyEpoch::current()
            : Closure::fromCallable($emergencyEpoch);
    }

    /** @param list<int> $chapterNumbers */
    public function enqueue(
        int $userId,
        int $novelId,
        int $startChapter,
        int $endChapter,
        array $chapterNumbers,
        string $harnessMode,
        bool $fencingEnforced,
        int $lookback = 5
    ): OutlineEnhancementEnqueueResult {
        $userId = HarnessPersistenceValue::positiveInt($userId, 'user_id');
        $novelId = HarnessPersistenceValue::positiveInt($novelId, 'novel_id');
        $startChapter = HarnessPersistenceValue::positiveInt($startChapter, 'start_chapter');
        $endChapter = HarnessPersistenceValue::positiveInt($endChapter, 'end_chapter');
        if ($endChapter < $startChapter) {
            throw new InvalidArgumentException('end_chapter must not precede start_chapter.');
        }
        $lookback = HarnessPersistenceValue::boundedInt(
            $lookback,
            'outline_lookback',
            1,
            100
        );
        $harnessMode = HarnessControlPlane::normalizeMode($harnessMode);
        if (!HarnessControlPlane::allowsNewHarnessRun($harnessMode)) {
            throw new InvalidArgumentException('Harness mode off must use the legacy outline path.');
        }
        HarnessControlPlane::assertAuthorityIsFenced($harnessMode, $fencingEnforced);

        $normalizedChapters = OutlineEnhancementSnapshot::normalizeChapterNumbers($chapterNumbers);
        foreach ($normalizedChapters as $chapterNumber) {
            if ($chapterNumber < $startChapter || $chapterNumber > $endChapter) {
                throw new InvalidArgumentException(
                    'Outline enhancement chapter set escapes its requested batch range.'
                );
            }
        }
        $snapshot = $this->store->capture($userId, $novelId, $normalizedChapters);
        $emergencyEpoch = $this->emergencyEpochValue();
        $authorityMode = HarnessControlPlane::usesAuthority($harnessMode)
            ? 'authority'
            : 'shadow';
        $goal = [
            'kind' => self::RUN_TYPE,
            'user_id' => $userId,
            'novel_id' => $novelId,
            'start_chapter' => $startChapter,
            'end_chapter' => $endChapter,
            'lookback' => $lookback,
            'chapter_numbers' => $snapshot->chapterNumbers,
            'base_hash' => $snapshot->canonicalHash,
            'base_revision' => $snapshot->revisionHash,
            'emergency_epoch' => $emergencyEpoch,
        ];
        $inputHash = self::frozenInputHash($snapshot, $emergencyEpoch);
        $identityJson = HarnessPersistenceValue::canonicalJson([
            'workflow' => self::WORKFLOW_VERSION,
            'authority_mode' => $authorityMode,
            'user_id' => $userId,
            'novel_id' => $novelId,
            'start_chapter' => $startChapter,
            'end_chapter' => $endChapter,
            'lookback' => $lookback,
            'chapter_numbers' => $snapshot->chapterNumbers,
            'base_hash' => $snapshot->canonicalHash,
            'base_revision' => $snapshot->revisionHash,
            'emergency_epoch' => $emergencyEpoch,
        ]);
        $identityHash = hash('sha256', $identityJson);
        $idempotencyKey = 'outline-enhance:v1:' . $identityHash;

        $existing = $this->runs->findByIdempotency($userId, $idempotencyKey);
        if ($existing !== null) {
            $this->assertExistingRun($existing, $snapshot, $authorityMode, $goal);
            return new OutlineEnhancementEnqueueResult($existing, $snapshot);
        }

        $now = $this->nowValue();
        $draft = new GenerationRunDraft(
            substr(hash('sha256', 'outline-run:v1:' . $identityHash), 0, 32),
            $userId,
            $novelId,
            null,
            self::QUEUE,
            self::RUN_TYPE,
            self::WORKFLOW,
            self::WORKFLOW_VERSION,
            $authorityMode,
            $idempotencyKey,
            null,
            $inputHash,
            [
                'harness_mode' => $harnessMode,
                'fencing_enforced' => $fencingEnforced,
                'emergency_epoch' => $emergencyEpoch,
            ],
            $goal,
            $now,
            self::MAX_ATTEMPTS,
            contextSnapshotHash: $snapshot->revisionHash,
            deadlineAt: $now->modify('+' . self::DEADLINE_SECONDS . ' seconds')
        );

        try {
            $run = $this->runs->createIdempotent($draft);
        } catch (HarnessIdempotencyConflictException $exception) {
            // Two identical API requests may race with different wall-clock
            // scheduling timestamps. The durable semantic identity still wins.
            $run = $this->runs->findByIdempotency($userId, $idempotencyKey);
            if ($run === null) {
                throw $exception;
            }
        }
        $this->assertExistingRun($run, $snapshot, $authorityMode, $goal);
        return new OutlineEnhancementEnqueueResult($run, $snapshot);
    }

    /** @param array<string,mixed> $goal */
    private function assertExistingRun(
        GenerationRun $run,
        OutlineEnhancementSnapshot $snapshot,
        string $authorityMode,
        array $goal
    ): void {
        $storedGoal = HarnessPersistenceValue::decodeJsonArray($run->goalJson, 'goal_json');
        $matches = $run->userId === $snapshot->userId
            && $run->novelId === $snapshot->novelId
            && $run->queue === self::QUEUE
            && $run->runType === self::RUN_TYPE
            && $run->workflowName === self::WORKFLOW
            && $run->workflowVersion === self::WORKFLOW_VERSION
            && $run->authorityMode === $authorityMode
            && hash_equals(
                $run->inputHash,
                self::frozenInputHash(
                    $snapshot,
                    OutlineEmergencyEpoch::normalize($goal['emergency_epoch'] ?? null)
                )
            )
            && $run->contextSnapshotHash !== null
            && hash_equals($run->contextSnapshotHash, $snapshot->revisionHash)
            && HarnessPersistenceValue::canonicalJson($storedGoal)
                === HarnessPersistenceValue::canonicalJson($goal);
        if (!$matches) {
            throw new HarnessIdempotencyConflictException(
                'Outline enhancement idempotency resolved to a different base snapshot.'
            );
        }
    }

    private function nowValue(): DateTimeImmutable
    {
        $now = ($this->now)();
        if (!$now instanceof DateTimeImmutable) {
            throw new RuntimeException('Outline enhancement clock must return DateTimeImmutable.');
        }
        return $now->setTimezone(new DateTimeZone('UTC'));
    }

    public static function frozenInputHash(
        OutlineEnhancementSnapshot $snapshot,
        int $emergencyEpoch
    ): string {
        return hash('sha256', HarnessPersistenceValue::canonicalJson([
            'schema' => 'harness.outline_frozen_input.v2',
            'base_hash' => $snapshot->canonicalHash,
            'base_revision' => $snapshot->revisionHash,
            'emergency_epoch' => OutlineEmergencyEpoch::normalize($emergencyEpoch),
        ]));
    }

    private function emergencyEpochValue(): int
    {
        try {
            return OutlineEmergencyEpoch::normalize(($this->emergencyEpoch)());
        } catch (OutlineEnhancementEmergencyStopException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new OutlineEnhancementEmergencyStopException(
                'Outline emergency epoch capture failed closed.',
                0,
                $exception
            );
        }
    }
}
