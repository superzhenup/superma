<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/ChapterRunService.php';
require_once __DIR__ . '/CommittedCanonRecoveryService.php';
require_once dirname(__DIR__) . '/persistence/RunArtifactRepository.php';
require_once dirname(__DIR__) . '/stages/LegacyWriteEngineStageAdapter.php';

/**
 * Shared production composition boundary for the three Chapter authority
 * transports. Transport callbacks, claim acquisition and lifecycle updates
 * deliberately remain owned by their entry point.
 */
final class ChapterAuthorityExecutionFactory
{
    private HarnessPersistenceDriver $db;
    private ChapterRunService $chapterRuns;
    private LegacyChapterArtifactStore $artifacts;
    private CommittedCanonRecoveryService $committedCanonRecovery;
    private PDO|HarnessPersistenceDriver $modelAccountingConnection;

    public function __construct(
        PDO|HarnessPersistenceDriver $connection,
        int $leaseSeconds = 180
    ) {
        $this->db = HarnessPersistenceValue::driver($connection);
        $this->modelAccountingConnection = $connection;
        $this->chapterRuns = new ChapterRunService(
            new M62ChapterRunCoordinator(
                $connection,
                leaseSeconds: $leaseSeconds
            ),
            leaseSeconds: $leaseSeconds
        );
        $this->artifacts = new LegacyChapterArtifactStore(
            new RunArtifactRepository($connection)
        );
        $this->committedCanonRecovery = new CommittedCanonRecoveryService(
            $connection,
            $this->artifacts
        );
    }

    public function chapterRuns(): ChapterRunService
    {
        return $this->chapterRuns;
    }

    /**
     * Build the standard recovery callback shared by SSE, async Worker and
     * Daemon authority Runs.
     *
     * For a live writing claim, the entry-owned authenticator must return the
     * exact frozen pre-claim base snapshot. Async Worker uses it to authenticate
     * task id, lease owner, lease token and fence generation. SSE/Daemon return
     * their preclaimed snapshot and use the optional verifier for their exact
     * claim. The shared boundary then authenticates that base against the live
     * writing token. Once Canon is committed, only the durable commit receipt
     * recovery service may reconstruct the completed Chapter context; it never
     * depends on a writing-only task/claim record still being present.
     *
     * @param callable(RunContext,array<string,mixed>):array<string,mixed> $baseAuthenticator
     * @param null|callable(RunContext,array<string,mixed>,array<string,mixed>):void $writingVerifier
     * @return Closure(RunContext):array{n:array<string,mixed>,ch:array<string,mixed>}
     */
    public function restoreChapter(
        int $novelId,
        callable $baseAuthenticator,
        ?callable $writingVerifier,
        string $missingMessage,
        string $inactiveMessage
    ): Closure {
        HarnessPersistenceValue::positiveInt(
            $novelId,
            'chapter_authority_restore.novel_id'
        );
        if ($missingMessage === '' || $inactiveMessage === '') {
            throw new InvalidArgumentException(
                'Chapter authority recovery messages cannot be empty.'
            );
        }
        $authenticateBase = Closure::fromCallable($baseAuthenticator);
        $verifyWriting = $writingVerifier === null
            ? null
            : Closure::fromCallable($writingVerifier);

        return function (RunContext $context) use (
            $novelId,
            $authenticateBase,
            $verifyWriting,
            $missingMessage,
            $inactiveMessage
        ): array {
            if ($context->request->novelId !== $novelId
                || $context->request->chapterId === null) {
                throw new StaleRevisionException($missingMessage);
            }

            $novel = $this->db->fetchOne(
                'SELECT * FROM novels WHERE id=? LIMIT 1',
                [$novelId]
            );
            $chapter = $this->db->fetchOne(
                'SELECT ' . WriteEngine::chapterWriteSelectList(['retry_count'])
                    . ' FROM chapters WHERE id=? AND novel_id=? LIMIT 1',
                [$context->request->chapterId, $novelId]
            );
            if (!is_array($novel) || !is_array($chapter)) {
                throw new StaleRevisionException($missingMessage);
            }
            if ((int)($novel['user_id'] ?? 0) !== $context->request->userId) {
                throw new StaleRevisionException(
                    'Chapter authority recovery owner changed.'
                );
            }

            $state = (string)($chapter['status'] ?? '');
            if ($state === 'completed') {
                $chapter = $this->committedCanonRecovery
                    ->recover($context)
                    ->chapter;
                return ['n' => $novel, 'ch' => $chapter];
            }
            if ($state !== 'writing') {
                throw new StaleRevisionException($inactiveMessage);
            }

            $baseSnapshot = $authenticateBase($context, $chapter);
            if (!is_array($baseSnapshot)) {
                throw new StaleRevisionException(
                    'Chapter authority recovery omitted its authenticated base snapshot.'
                );
            }
            $baseSnapshot = WriteEngine::validateChapterBaseSnapshot(
                $baseSnapshot,
                (string)($chapter['claim_token'] ?? '')
            );
            if ($verifyWriting !== null) {
                $verifyWriting($context, $chapter, $baseSnapshot);
            }
            $chapter['_base_snapshot'] = $baseSnapshot;
            return ['n' => $novel, 'ch' => $chapter];
        };
    }

    /**
     * Construct the production legacy Stage adapter with the shared durable
     * Artifact and committed-Canon recovery dependencies.
     *
     * @param callable(RunContext):array{n:array<string,mixed>,ch:array<string,mixed>} $acquireChapter
     * @param callable(RunContext):array{n:array<string,mixed>,ch:array<string,mixed>} $restoreChapter
     * @param array<string,mixed>|null $writingTaskLease
     */
    public function adapter(
        WritePolicySnapshot $policySnapshot,
        HarnessFeatureSnapshot $featureSnapshot,
        ChapterRunInputSnapshot $inputSnapshot,
        LegacyChapterTransport $transport,
        callable $acquireChapter,
        callable $restoreChapter,
        string $postprocessWorkerId,
        ?string $writingTaskId = null,
        ?array $writingTaskLease = null,
        ?callable $legacyCancellationProbe = null,
        ?callable $waitingCallback = null
    ): LegacyWriteEngineStageAdapter {
        return new LegacyWriteEngineStageAdapter(
            policySnapshot: $policySnapshot,
            featureSnapshot: $featureSnapshot,
            inputSnapshot: $inputSnapshot,
            artifactStore: $this->artifacts,
            transport: $transport,
            committedCanonRecovery: $this->committedCanonRecovery,
            acquireChapter: $acquireChapter,
            restoreChapter: $restoreChapter,
            postprocessWorkerId: $postprocessWorkerId,
            writingTaskId: $writingTaskId,
            writingTaskLease: $writingTaskLease,
            legacyCancellationProbe: $legacyCancellationProbe,
            waitingCallback: $waitingCallback,
            modelAccountingConnection: $this->modelAccountingConnection
        );
    }
}
