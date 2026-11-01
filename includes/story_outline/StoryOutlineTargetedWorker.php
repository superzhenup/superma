<?php
declare(strict_types=1);
defined('APP_LOADED') or die('Direct access denied.');

/** Bounds a detached worker to one task; task mutations stay in TaskService. */
final class StoryOutlineTargetedWorker
{
    public function __construct(private PDO $pdo, private string $taskId)
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $taskId) !== 1) {
            throw new InvalidArgumentException('Invalid targeted story task.');
        }
    }

    public function shouldContinue(): bool
    {
        // Use the same database UTC clock as persisted leases/deadlines. A
        // successful shell launch must never become a permanent empty daemon.
        $statement = $this->pdo->prepare("SELECT state, cancel_requested, attempt, max_attempts,
            deadline_at > UTC_TIMESTAMP() AS within_deadline,
            lease_expires_at > UTC_TIMESTAMP() AS active_lease
            FROM generation_runs WHERE run_uid=? AND queue='story-outline'
            AND run_type='story-outline.generation'");
        $statement->execute([$this->taskId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (bool)$row['cancel_requested'] || !(bool)$row['within_deadline']
            || (int)$row['attempt'] >= (int)$row['max_attempts']) return false;
        if (in_array($row['state'], ['queued', 'retry_wait'], true)) return true;
        // An expired lease may be reclaimed. Another live worker owns its task.
        return $row['state'] === 'running' && !(bool)$row['active_lease'];
    }
}
