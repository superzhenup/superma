<?php
declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

/**
 * 写入者排空口径的唯一定义（审计修复 F02/F06，2026-11-01）。
 *
 * 数据库恢复（BackupManager）和外部归档快照（HarnessArchiveSnapshot）都要证明“没有在途写入者”。
 * 两处曾各自维护一份表清单，Schema 72 新增 postprocess_subtasks 时两处都漏了。新增任何
 * 会被 worker 认领并写库的队列表，只在这里登记一次。
 */
final class WriterDrainCensus
{
    /** table => active-row predicate: non-terminal, or still holding a lease. */
    public const ACTIVE_ROWS = [
        'generation_runs' => "state IN ('queued','running','retry_wait','waiting_approval') OR lease_expires_at>UTC_TIMESTAMP(6)",
        'writing_tasks' => "state IN ('queued','preparing','generating','validating','content_saved','postprocessing')",
        'postprocess_jobs' => "state IN ('queued','retry','running')",
        'postprocess_subtasks' => "state IN ('queued','retry','running')",
        'jobs' => "state IN ('queued','running','retry_wait')",
        'drama_tasks' => "status IN ('pending','running')",
    ];

    /** First schema version that creates the table; absent from the census before it. */
    public const SINCE_SCHEMA = ['postprocess_subtasks' => 72];

    public static function countSql(string $table): string
    {
        if (!isset(self::ACTIVE_ROWS[$table])) {
            throw new InvalidArgumentException('Unknown writer table: ' . $table);
        }
        return "SELECT COUNT(*) FROM {$table} WHERE " . self::ACTIVE_ROWS[$table];
    }

    /** @return list<string> census tables that must exist at the recorded schema */
    public static function tablesFor(int $schema): array
    {
        return array_values(array_filter(
            array_keys(self::ACTIVE_ROWS),
            static fn (string $table): bool => $schema >= (self::SINCE_SCHEMA[$table] ?? 0)
        ));
    }
}
