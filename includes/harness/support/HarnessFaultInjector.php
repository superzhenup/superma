<?php
declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

interface HarnessFaultInjector
{
    public function hit(string $point, array $context = []): void;
}

/** Fixed internal points only; no point name is accepted from HTTP or config. */
final class HarnessFaultPoints
{
    public const WRITING_CLAIM_AFTER_CAS = 'writing_task.claim.after_cas';
    public const POSTPROCESS_CLAIM_AFTER_LOCK = 'postprocess.claim.after_lock';
    public const POSTPROCESS_CLAIM_AFTER_CAS = 'postprocess.claim.after_cas';
    public const POSTPROCESS_RETRY_BEFORE_WRITE = 'postprocess.retry.before_write';
    public const AI_SYNC_BEFORE_TRANSPORT = 'ai.sync.before_transport';
    public const AI_SYNC_AFTER_TRANSPORT = 'ai.sync.after_transport';
    public const AI_STREAM_BEFORE_TRANSPORT = 'ai.stream.before_transport';
    public const AI_STREAM_AFTER_EVENT = 'ai.stream.after_event';

    private function __construct()
    {
    }
}
