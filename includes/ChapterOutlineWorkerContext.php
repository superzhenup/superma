<?php
declare(strict_types=1);
defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/ChapterOutlineRequestStore.php';
require_once __DIR__ . '/ChapterOutlineTrace.php';

/** Authority obtained only by claiming an authenticated, queued request in CLI. */
final class ChapterOutlineWorkerContext
{
    private ChapterOutlineTrace $trace;
    private bool $receiptSeen = false;

    private function __construct(
        private ChapterOutlineRequestStore $store,
        private array $claimed
    ) {
        $this->trace = new ChapterOutlineTrace($this->requestId());
        $this->trace->authorize($claimed['novel_id'], $claimed['start_chapter'], $claimed['end_chapter']);
        $this->trace->setObserver(function (array $record): void {
            // A normal exit during bootstrap/model work is still a failed worker
            // when no receipt was produced. Preserve the safe shutdown evidence.
            if (($record['event'] ?? null) === 'shutdown' && !$this->receiptSeen
                && ($record['end_reason'] ?? null) !== 'php_fatal'
                && !in_array($record['stage'] ?? null, ['failed', 'rejected'], true)) {
                $record['stage'] = 'failed';
                $record['end_reason'] = 'request_exit';
            }
            $this->store->record($record);
            if (($record['stage'] ?? null) === 'save_receipt') $this->receiptSeen = true;
        });
        // Install both handlers before config.php or application bootstrap can fail.
        $this->trace->registerShutdown();
        register_shutdown_function(function (): void { $this->store->close(); });
        $this->trace->phase('authorized');
    }

    public static function claim(int $novelId, string $requestId): ?self
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('Chapter outline workers require CLI.');
        $store = new ChapterOutlineRequestStore();
        try {
            $claimed = $store->claimQueued($novelId, $requestId);
            if ($claimed === null) {
                $store->close();
                return null;
            }
            return new self($store, $claimed);
        } catch (Throwable $error) {
            $store->close();
            throw $error;
        }
    }

    /** @return array{novel_id:int,start_chapter:int,end_chapter:int} */
    public function input(): array
    {
        return [
            'novel_id' => $this->claimed['novel_id'],
            'start_chapter' => $this->claimed['start_chapter'],
            'end_chapter' => $this->claimed['end_chapter'],
        ];
    }

    public function userId(): int { return $this->claimed['user_id']; }
    public function requestId(): string { return $this->claimed['request_id']; }
    public function store(): ChapterOutlineRequestStore { return $this->store; }
    public function trace(): ChapterOutlineTrace { return $this->trace; }
}
