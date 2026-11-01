<?php
declare(strict_types=1);
defined('APP_LOADED') or die('Direct access denied.');
require_once __DIR__ . '/StoryOutlineTaskService.php';

/** Retryable bookkeeping after the outline and successful task are committed. */
final class StoryOutlinePostPublish
{
    /** Retry committed bookkeeping from the queue consumer, never a status GET. */
    public static function recoverPending(PDO $db): void
    {
        $statement=$db->prepare("SELECT run_uid FROM generation_runs WHERE run_type=? AND state='succeeded' AND JSON_EXTRACT(result_json,'$.followup_complete') IS NULL ORDER BY completed_at,id LIMIT 10");
        $statement->execute([StoryOutlineTaskService::RUN_TYPE]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $taskId) self::tryApply($db,(string)$taskId);
    }

    public static function tryApply(PDO $db, string $taskId): void
    {
        try {
            self::apply($db, $taskId);
        } catch (Throwable $error) {
            // Never change a published task back to failed. The queue consumer
            // retries this transaction without another AI call.
            error_log('[story-outline followup ' . $taskId . '] ' . $error->getMessage());
        }
    }

    public static function apply(PDO $db, string $taskId): void
    {
        if ($db->inTransaction()) throw new LogicException('Followup requires its own transaction.');
        $query = static function (string $sql, array $params = []) use ($db): PDOStatement {
            $statement = $db->prepare($sql);
            $statement->execute($params);
            return $statement;
        };
        $run = $query("SELECT id,novel_id FROM generation_runs WHERE run_uid=? AND run_type=? AND state='succeeded'",
            [$taskId, StoryOutlineTaskService::RUN_TYPE])->fetch(PDO::FETCH_ASSOC);
        if (!$run) return;
        $db->beginTransaction();
        try {
            // Follow the same source -> novel -> run lock order as publication.
            $sourceHash = (new StoryOutlineTaskService($db))->sourceRevisionHash((int)$run['novel_id'], true);
            $query('SELECT id FROM novels WHERE id=? FOR UPDATE', [$run['novel_id']]);
            $locked = $query('SELECT result_json FROM generation_runs WHERE id=? FOR UPDATE', [$run['id']])->fetch(PDO::FETCH_ASSOC);
            if (!$locked) { $db->rollBack(); return; }
            $receipt = json_decode($locked['result_json'] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
            if (!empty($receipt['followup_complete'])) { $db->commit(); return; }
            $outline = $query('SELECT * FROM story_outlines WHERE novel_id=? FOR UPDATE', [$run['novel_id']])->fetch(PDO::FETCH_ASSOC);
            $matches = $outline
                && hash_equals((string)($receipt['outline_hash'] ?? ''), StoryOutlineTaskService::outlineHash($outline))
                && hash_equals((string)($receipt['source_revision_hash'] ?? ''), $sourceHash);
            if ($matches) {
                $turnings = json_decode($outline['major_turning_points'] ?? '[]', true) ?: [];
                $volumes = $query('SELECT * FROM volume_outlines WHERE novel_id=? ORDER BY id FOR UPDATE', [$run['novel_id']])->fetchAll(PDO::FETCH_ASSOC);
                foreach ($volumes as $volume) {
                    $from = (int)($volume['chapter_from'] ?? $volume['start_chapter'] ?? 0);
                    $to = (int)($volume['chapter_to'] ?? $volume['end_chapter'] ?? 0);
                    if ($from < 1 || $to < $from) continue;
                    $events = json_decode($volume['key_events'] ?? '[]', true);
                    $events = is_array($events) ? $events : [];
                    foreach ($turnings as $turning) {
                        $chapter = (int)($turning['chapter'] ?? 0);
                        if ($chapter >= $from && $chapter <= $to && !empty($turning['event'])) {
                            $event = '第' . $chapter . '章：' . $turning['event'];
                            if (!in_array($event, $events, true)) $events[] = $event;
                        }
                    }
                    $sets = [];
                    $params = [];
                    if (array_key_exists('key_events', $volume)) {
                        $sets[] = 'key_events=?';
                        $params[] = json_encode(array_values($events), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    }
                    if (array_key_exists('status', $volume)) $sets[] = "status='generated'";
                    if ($sets) {
                        $params[] = $volume['id'];
                        $query('UPDATE volume_outlines SET ' . implode(',', $sets) . ' WHERE id=?', $params);
                    }
                }
                $query("INSERT INTO writing_logs (novel_id,action,message,created_at) VALUES (?,'story_outline',?,UTC_TIMESTAMP())",
                    [$run['novel_id'], '生成全书故事大纲（任务 ' . $taskId . '）']);
            }
            $receipt['followup_complete'] = true;
            $receipt['followup_outcome'] = $matches ? 'applied' : 'source_changed';
            $query('UPDATE generation_runs SET result_json=? WHERE id=?',
                [json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $run['id']]);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }
}
