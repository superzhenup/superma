<?php
declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

/** Version 68 changes legacy task DATETIME semantics to UTC. */
final class LeaseClockMigration
{
    public static function assertDrained(PDO $pdo): void
    {
        // Old queued/retry/leased rows can contain local wall times, while
        // initial postprocess availability was UTC. Never guess their offset.
        // The release maintenance window must drain old workers before upgrade.
        $queues = [
            'writing_tasks' => "state IN ('queued','preparing','generating','validating','content_saved','postprocessing')",
            'postprocess_jobs' => "state IN ('queued','retry','running')",
            'drama_tasks' => "status IN ('pending','running')",
        ];
        foreach ($queues as $table => $condition) {
            $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
            $exists->execute([$table]);
            $present = (int)$exists->fetchColumn() > 0;
            $exists->closeCursor();
            if (!$present) continue;
            $statement = $pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE {$condition}");
            $count = (int)$statement->fetchColumn();
            $statement->closeCursor();
            if ($count !== 0) {
                throw new RuntimeException("UTC lease migration v68 blocked: {$table} has {$count} nonterminal tasks. Drain with the old version, stop all workers, then retry; do not rewrite live lease timestamps.");
            }
        }
    }
}
