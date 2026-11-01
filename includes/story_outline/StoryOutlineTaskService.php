<?php

declare(strict_types=1);
defined('APP_LOADED') or die('Direct access denied.');
require_once dirname(__DIR__) . '/app_time.php';
require_once __DIR__ . '/StoryOutlineSource.php';
require_once __DIR__ . '/StoryOutlineBudget.php';
require_once dirname(__DIR__) . '/harness/persistence/bootstrap.php';

final class StoryOutlineTaskCanceledException extends RuntimeException {}
final class StoryOutlineTaskLeaseLostException extends RuntimeException {}
final class StoryOutlineTaskDeadlineException extends RuntimeException {}

/**
 * Book-level adapter for the existing Harness persistence tables. Chapter commit
 * ledgers and chapter admission policies do not apply to this workflow.
 * A short novel-row lock serializes admission and publication; no AI work runs
 * inside a transaction. Every worker mutation is fenced by the run lease.
 */
final class StoryOutlineTaskService
{
    public const QUEUE = 'story-outline';
    public const RUN_TYPE = 'story-outline.generation';
    public const STAGES = ['skeleton', 'characters', 'world'];
    private const LIVE = ['queued', 'running', 'retry_wait'];
    private const LEASE_SECONDS = 60;
    private const TASK_SECONDS = 3600;
    private const STAGE_SECONDS = 900;
    private PDO $db;
    private Closure $now;

    public function __construct(PDO $db, ?callable $now = null)
    {
        $this->db = $db;
        $this->now = $now === null ? static fn (): int => time() : Closure::fromCallable($now);
    }

    public function start(int $userId, int $novelId, string $requestKey, string $mode, callable $captureContext): array
    {
        if (!in_array($mode, ['resume', 'regenerate'], true)
            || !preg_match('/^[A-Za-z0-9_-]{8,120}$/D', $requestKey)) {
            throw new InvalidArgumentException('任务参数无效，请刷新页面后重试。');
        }
        $identity = 'story-outline:' . $novelId . ':' . $mode . ':' . hash('sha256', $requestKey);
        // Probe only: all schema changes belong to bin/migrate.php, never a save transaction.
        try {
            $this->db->query('SELECT character_progression FROM story_outlines LIMIT 0');
            $this->db->query('SELECT goal_json, result_json, fence_generation FROM generation_runs LIMIT 0');
            $this->db->query('SELECT output_artifact_id FROM generation_steps LIMIT 0');
            $this->db->query('SELECT inline_json FROM generation_artifacts LIMIT 0');
            $this->db->query('SELECT model_config_json,input_tokens,output_tokens FROM generation_attempts LIMIT 0');
            $this->db->query('SELECT summary,updated_at FROM volume_summaries LIMIT 0');
        } catch (PDOException $e) {
            throw new InvalidArgumentException('数据库结构需要升级，请管理员先运行 php bin/migrate.php --apply。', 0, $e);
        }
        return $this->transaction(function () use ($userId, $novelId, $identity, $requestKey, $mode, $captureContext): array {
            $novel = $this->ownedNovel($userId, $novelId, true);
            $same = $this->one('SELECT * FROM generation_runs WHERE user_id=? AND idempotency_key=?', [$userId, $identity]);
            if ($same !== null) return $this->present($same);
            $live = $this->one("SELECT * FROM generation_runs WHERE novel_id=? AND run_type=? AND state IN ('queued','running','retry_wait') ORDER BY id DESC LIMIT 1 FOR UPDATE", [$novelId, self::RUN_TYPE]);
            if ($live !== null) {
                // A live lease remains authoritative even when the caller clicks again.
                if ($this->timestamp($live['deadline_at']) > $this->time()
                    || ($live['state'] === 'running' && $this->timestamp($live['lease_expires_at']) > $this->time())) {
                    return $this->present($live);
                }
                $this->finishUnleased($live, 'failed', 'deadline_exceeded', '任务已到达时间上限，可以继续未完成阶段。');
            }
            $latest = $this->one('SELECT * FROM generation_runs WHERE novel_id=? AND run_type=? ORDER BY id DESC LIMIT 1', [$novelId, self::RUN_TYPE]);
            $outline = $this->outline($novelId);
            $parent = $mode === 'resume' && $latest !== null && in_array($latest['state'], ['failed', 'canceled'], true) ? $latest : null;
            if ($mode === 'resume' && $parent === null && $outline !== null) {
                return $latest !== null && $latest['state'] === 'succeeded'
                    ? $this->present($latest) : $this->savedResult($outline);
            }
            $sourceHash = $this->sourceRevisionHash($novelId);
            $previous = $parent === null ? null : $this->snapshot((int)$parent['id']);
            $context = $captureContext($novel,$previous['context'] ?? null);
            if (!hash_equals($sourceHash,$this->sourceRevisionHash($novelId))) {
                throw new InvalidArgumentException('小说章节或卷结构正在修改，请稍后重新提交。');
            }
            if (!is_array($context) || empty($context['base_messages'])) {
                throw new InvalidArgumentException('无法构建故事大纲上下文，请检查小说设定。');
            }
            if (empty($context['models'])) throw new InvalidArgumentException('故事大纲任务需要冻结模型配置，请重新生成。');
            $context['models'] = StoryOutlineBudget::normalizeFrozenModels($context['models']);
            $context['target_chapters']=max(1,(int)($novel['target_chapters']??100));
            $baseHash = self::outlineHash($outline);
            $copied = [];
            if ($parent !== null) {
                if (!hash_equals((string)$previous['base_outline_hash'], $baseHash)
                    || !hash_equals(self::hash($previous['context']), self::hash($context))
                    || !hash_equals((string)($previous['source_revision_hash'] ?? ''),$sourceHash)) {
                    throw new InvalidArgumentException('小说设定或故事大纲已修改，请选择重新生成，避免覆盖修改。');
                }
                $context = $previous['context'];
                $copied = $this->completed((int)$parent['id']);
                foreach ($copied as $stage=>$output) {
                    try { StoryOutlineStageGenerator::validateStage($stage,$output,(int)$context['target_chapters']); }
                    catch (UnexpectedValueException $error) { throw new InvalidArgumentException('旧大纲阶段不满足章节覆盖校验，请重新生成。',0,$error); }
                }
            }
            $snapshot = ['context' => $context, 'base_outline_hash' => $baseHash, 'novel_input_hash' => self::novelInputHash($novel), 'source_revision_hash'=>$sourceHash];
            $uid = bin2hex(random_bytes(16));
            $now = $this->date();
            $this->execute("INSERT INTO generation_runs
                (run_uid,user_id,novel_id,queue,run_type,workflow_name,workflow_version,authority_mode,state,
                 parent_run_id,root_run_id,idempotency_key,active_lock_key,max_attempts,available_at,input_hash,
                 context_snapshot_hash,deadline_at,flag_snapshot_json,goal_json,created_at,updated_at,token_budget,cost_budget)
                VALUES (?,?,?,?,?,'story-outline','v2','authority','queued',?,?,?, ?,9,?,?,?,?,?,?,?,?,?,?)",
                [$uid,$userId,$novelId,self::QUEUE,self::RUN_TYPE,$parent['id'] ?? null,
                    $parent === null ? null : ($parent['root_run_id'] ?? $parent['id']), $identity, 'story-outline',
                    $now,self::hash($snapshot),self::hash($context),$this->date($this->time()+self::TASK_SECONDS),
                    '{}',self::json(['stage_count'=>3,'resumed_from'=>$parent['run_uid'] ?? null,'request_key'=>$requestKey,'request_mode'=>$mode]),$now,$now,StoryOutlineBudget::TOKEN_LIMIT,'0.000000']);
            $runId = (int)$this->db->lastInsertId();
            $this->artifact($runId, null, 'story-outline.context', $snapshot);
            foreach (self::STAGES as $ordinal => $stage) {
                $this->execute("INSERT INTO generation_steps (run_id,step_key,ordinal,step_type,state,max_attempts,input_hash,policy_json,created_at,updated_at) VALUES (?,?,?,'story-outline.stage','pending',3,?,'{}',?,?)", [$runId,$stage,$ordinal,self::hash($context),$now,$now]);
                $stepId = (int)$this->db->lastInsertId();
                if (isset($copied[$stage])) {
                    $artifactId = $this->artifact($runId, $stepId, 'story-outline.' . $stage, $copied[$stage]);
                    $this->execute("UPDATE generation_steps SET state='succeeded',progress=100,output_artifact_id=?,output_hash=?,completed_at=? WHERE id=?", [$artifactId,self::hash($copied[$stage]),$now,$stepId]);
                }
            }
            $this->execute('UPDATE generation_runs SET progress=? WHERE id=?', [(int)floor(count($copied)*95/3),$runId]);
            $created = $this->run($uid);
            $this->appendRunEvent($created, 'run.created', 'queued', 'created', false);
            return $this->present($created);
        });
    }

    public function status(int $userId, int $novelId, ?string $taskId = null): ?array
    {
        $this->ownedNovel($userId, $novelId);
        if ($taskId !== null) {
            $this->validTaskId($taskId);
            $run = $this->one('SELECT * FROM generation_runs WHERE run_uid=? AND novel_id=? AND user_id=? AND run_type=?', [$taskId,$novelId,$userId,self::RUN_TYPE]);
            if ($run === null) throw new InvalidArgumentException('任务不存在或无权访问。');
        } else {
            $run = $this->one('SELECT * FROM generation_runs WHERE novel_id=? AND user_id=? AND run_type=? ORDER BY id DESC LIMIT 1', [$novelId,$userId,self::RUN_TYPE]);
        }
        if ($run !== null) return $this->present($run);
        $outline = $this->outline($novelId);
        return $outline === null ? null : $this->savedResult($outline);
    }

    public function cancel(int $userId, int $novelId, string $taskId): array
    {
        $this->validTaskId($taskId);
        return $this->transaction(function () use ($userId, $novelId, $taskId): array {
            $this->ownedNovel($userId,$novelId,true);
            $run = $this->one('SELECT * FROM generation_runs WHERE run_uid=? AND novel_id=? AND user_id=? AND run_type=? FOR UPDATE', [$taskId,$novelId,$userId,self::RUN_TYPE]);
            if ($run === null) throw new InvalidArgumentException('任务不存在或无权访问。');
            if (in_array($run['state'], self::LIVE, true)) {
                // Release immediately: a canceled worker cannot subsequently publish or save a checkpoint.
                $this->appendRunEvent($run, 'run.cancel_requested', $run['state'], 'cancel_requested', false);
                $this->execute('UPDATE generation_runs SET cancel_requested=1 WHERE id=?', [$run['id']]);
                $this->finishUnleased($run,'canceled','canceled','任务已停止，已完成的阶段可以继续使用。');
                $this->appendRunEvent($run, 'run.canceled', 'canceled', 'cancel_finalized');
            }
            return $this->present($this->run($taskId));
        });
    }

    /** Returns after one claimed task attempt; network work is always outside a transaction. */
    public function runNext(callable $generate, ?string $taskId = null): ?array
    {
        if ($taskId !== null) $this->validTaskId($taskId);
        $lease = $this->claim($taskId);
        if ($lease === null) return null;
        $currentStage = null;
        $stageDeadline = 0;
        $lastRenewed = $this->time();
        $lastPreview = 0;
        $checkpoint = function () use ($lease, &$lastRenewed, &$stageDeadline): void {
            if ($this->time() >= $this->timestamp($lease['deadline_at'])
                || ($stageDeadline > 0 && $this->time() >= $stageDeadline)) {
                throw new StoryOutlineTaskDeadlineException('The persisted task or stage deadline expired.');
            }
            if ($this->time() - $lastRenewed < 5) return;
            $this->renew($lease);
            $lastRenewed = $this->time();
        };
        $preview = function (string $text) use ($lease, &$lastPreview, $checkpoint): void {
            $checkpoint();
            if ($this->time() - $lastPreview < 1) return;
            $lastPreview = $this->time();
            $this->execute('UPDATE generation_runs SET result_json=?,updated_at=? WHERE id=? AND state=\'running\' AND lease_token=? AND fence_generation=? AND lease_expires_at>?',
                [self::json(['preview'=>mb_substr($text,-16000)]),$this->date(),$lease['id'],$lease['lease_token'],$lease['fence_generation'],$this->date()]);
        };
        try {
            $snapshot = $this->snapshot((int)$lease['id']);
            $completed = $this->completed((int)$lease['id']);
            foreach (self::STAGES as $stage) {
                if (isset($completed[$stage])) continue;
                $currentStage = $stage;
                $attempt = $this->beginStage($lease, $stage, $snapshot['context'], $completed);
                $stageDeadline = $attempt['deadline'];
                $checkpoint();
                $attemptContext=$snapshot['context'];
                $attemptContext['attempt_no']=$attempt['attempt_no'];
                $settle=function (?array $usage,?string $finishReason,?Throwable $error) use ($lease,$attempt): void {
                    $this->settleAttempt($lease,$attempt,$usage,$finishReason,$error);
                };
                $attemptError=null;
                try {
                    $output = $generate($stage,$attemptContext,$completed,$checkpoint,$preview,$stageDeadline,$settle);
                    $output = StoryOutlineStageGenerator::validateStage($stage, $output, (int)$snapshot['context']['target_chapters']);
                } catch (Throwable $error) {
                    $attemptError=$error;
                    throw $error;
                } finally {
                    // A crash/adapter failure without observable usage never frees its reservation.
                    $this->settleAttempt($lease,$attempt,null,null,$attemptError);
                }
                $checkpoint();
                $this->saveStage($lease,$stage,$output);
                $completed[$stage] = $output;
                $stageDeadline = 0;
                $lastPreview = 0;
            }
            $currentStage = null;
            $checkpoint();
            $candidate = StoryOutlineStageGenerator::merge($completed,(int)$snapshot['context']['target_chapters']);
            $this->publish($lease,$snapshot,$candidate);
        } catch (StoryOutlineTaskLeaseLostException $e) {
            // A newer worker or cancellation owns the record; stale workers never change its state.
        } catch (Throwable $e) {
            $this->recordFailure($lease,$currentStage,$e);
        }
        return $this->present($this->run($lease['run_uid']));
    }

    public static function outlineHash(?array $outline): string
    {
        if ($outline === null) return self::hash(null);
        $fields = ['id','story_arc','act_division','major_turning_points','character_arcs','character_endpoints','character_progression','world_evolution','recurring_motifs'];
        $values = [];
        foreach ($fields as $field) {
            $value = $outline[$field] ?? null;
            if (in_array($field,['act_division','major_turning_points','character_arcs','character_progression','recurring_motifs'],true) && is_string($value)) {
                $decoded = json_decode($value,true);
                if (json_last_error() === JSON_ERROR_NONE) $value = $decoded;
            }
            $values[$field] = $value;
        }
        return self::hash($values);
    }

    private function claim(?string $taskId): ?array
    {
        return $this->transaction(function () use ($taskId): ?array {
            $params = [self::QUEUE,$this->date(),$this->date()];
            $taskSql = '';
            if ($taskId !== null) { $taskSql = ' AND run_uid=?'; $params[] = $taskId; }
            $run = $this->one("SELECT * FROM generation_runs WHERE queue=? AND ((state IN ('queued','retry_wait') AND available_at<=?) OR (state='running' AND lease_expires_at<=?)){$taskSql} ORDER BY available_at,id LIMIT 1 FOR UPDATE",$params);
            if ($run === null) return null;
            if ((int)$run['cancel_requested'] === 1) {
                $this->finishUnleased($run,'canceled','canceled','任务已停止。');
                $this->appendRunEvent($run, 'run.canceled', 'canceled', 'cancel_finalized');
                return null;
            }
            if ($this->timestamp($run['deadline_at']) <= $this->time() || (int)$run['attempt'] >= (int)$run['max_attempts']) {
                $this->finishUnleased($run,'failed','budget_exhausted','任务已到达重试或时间上限，可以继续未完成阶段。');
                return null;
            }
            $token = bin2hex(random_bytes(16));
            $this->execute("UPDATE generation_runs SET state='running',attempt=attempt+1,fence_generation=fence_generation+1,lease_owner=?,lease_token=?,lease_expires_at=?,started_at=COALESCE(started_at,?),error_code=NULL,error_message=NULL,updated_at=? WHERE id=?",[substr((gethostname() ?: 'worker').':'.getmypid(),0,120),$token,$this->date($this->time()+self::LEASE_SECONDS),$this->date(),$this->date(),$run['id']]);
            $claimed = $this->run($run['run_uid']);
            $this->appendRunEvent($claimed, 'run.claimed', 'running', 'claimed', false, $run['state'] === 'running');
            return $claimed;
        });
    }

    private function renew(array $lease): void
    {
        $count = $this->execute("UPDATE generation_runs SET lease_expires_at=?,updated_at=? WHERE id=? AND state='running' AND cancel_requested=0 AND lease_token=? AND fence_generation=? AND lease_expires_at>?",[$this->date($this->time()+self::LEASE_SECONDS),$this->date(),$lease['id'],$lease['lease_token'],$lease['fence_generation'],$this->date()]);
        if ($count !== 1) throw new StoryOutlineTaskLeaseLostException('Story outline run lease was lost.');
    }

    private function assertLease(array $lease, bool $checkDeadline = true): array
    {
        $run = $this->one('SELECT * FROM generation_runs WHERE id=? FOR UPDATE',[$lease['id']]);
        if ($run === null || $run['state'] !== 'running' || (int)$run['cancel_requested'] !== 0
            || $run['lease_token'] !== $lease['lease_token'] || (int)$run['fence_generation'] !== (int)$lease['fence_generation']
            || $this->timestamp($run['lease_expires_at']) <= $this->time()) {
            throw new StoryOutlineTaskLeaseLostException('Story outline run lease was lost.');
        }
        if ($checkDeadline && $this->timestamp($run['deadline_at']) <= $this->time()) {
            throw new StoryOutlineTaskDeadlineException('The persisted task deadline expired.');
        }
        return $run;
    }

    private function beginStage(array $lease, string $stage, array $context, array $completed): array
    {
        return $this->transaction(function () use ($lease,$stage,$context,$completed): array {
            $run=$this->assertLease($lease);
            $step = $this->one('SELECT * FROM generation_steps WHERE run_id=? AND step_key=? FOR UPDATE',[$lease['id'],$stage]);
            if ($step === null || (int)$step['attempt'] >= (int)$step['max_attempts']) {
                throw new StoryOutlineTaskDeadlineException('The durable stage attempt budget was exhausted.');
            }
            $policy = self::decode($step['policy_json']);
            $deadline = isset($policy['deadline']) ? (int)$policy['deadline'] : min($this->time()+self::STAGE_SECONDS,$this->timestamp($lease['deadline_at']));
            if ($deadline <= $this->time()) throw new StoryOutlineTaskDeadlineException('The persisted stage deadline expired.');
            $models=$context['models']??[];
            if (!$models || (int)($run['token_budget']??0)<1) throw new StoryOutlineBudgetException('旧任务没有有效 token 预算，请重新生成。');
            $model=$models[min((int)$step['attempt'],count($models)-1)];
            $reservation=StoryOutlineBudget::reservation($model,StoryOutlineStageGenerator::messages($stage,$context,$completed));
            $pending=$this->all("SELECT a.model_config_json FROM generation_attempts a JOIN generation_steps s ON s.id=a.step_id WHERE s.run_id=? AND (a.state='running' OR a.error_code='usage_unknown')",[$run['id']]);
            $tokens=(int)$run['input_tokens']+(int)$run['output_tokens'];
            foreach ($pending as $row) {
                $reserved=self::decode($row['model_config_json'])['reservation'];
                $tokens+=(int)$reserved['input_tokens']+(int)$reserved['output_tokens'];
            }
            if ($tokens+$reservation['input_tokens']+$reservation['output_tokens']>(int)$run['token_budget']) {
                throw new StoryOutlineBudgetException('故事大纲剩余 token 预算不足，已停止自动调用。');
            }
            $this->execute("UPDATE generation_steps SET state='running',attempt=attempt+1,fence_generation=fence_generation+1,lease_token=?,lease_expires_at=?,policy_json=?,started_at=COALESCE(started_at,?),error_code=NULL,error_message=NULL,updated_at=? WHERE id=?",[$lease['lease_token'],$this->date($this->time()+self::LEASE_SECONDS),self::json(['deadline'=>$deadline]),$this->date(),$this->date(),$step['id']]);
            $this->execute('UPDATE generation_runs SET current_step_key=?,result_json=NULL,updated_at=? WHERE id=?',[$stage,$this->date(),$lease['id']]);
            $attemptNo=(int)$step['attempt']+1;
            $this->execute("INSERT INTO generation_attempts (step_id,attempt_no,state,provider,model,model_config_json,started_at) VALUES (?,?,'running',?,?,?,?)",[
                $step['id'],$attemptNo,substr((string)(parse_url((string)$model['api_url'],PHP_URL_HOST)?:'configured'),0,64),$model['model_name'],
                self::json(['model'=>$model,'reservation'=>$reservation,'lease_token'=>$lease['lease_token']]),$this->date()]);
            $attemptId = (int)$this->db->lastInsertId();
            $startedStep = array_merge($step, ['state'=>'running','attempt'=>$attemptNo]);
            $this->appendStepEvent($run, $startedStep, 'step.started', 'running');
            $this->appendAttemptEvent($run, $startedStep, $attemptId, $attemptNo, 'attempt.started', 'running');
            return ['deadline'=>$deadline,'attempt_no'=>$attemptNo,'id'=>$attemptId];
        });
    }

    /**
     * Late responses record token usage for their own attempt even after cancellation or lease replacement.
     * usage_unknown and a later authoritative settlement are two observations
     * of the same attempt, with distinct classification-based Event keys. Keep
     * both immutable facts; never rewrite the earlier Event's payload. Once
     * reconciliation closes usage_unknown, this guard rejects late settlement.
     */
    private function settleAttempt(array $lease,array $attempt,?array $usage,?string $finishReason,?Throwable $error): void
    {
        $this->transaction(function () use ($lease,$attempt,$usage,$finishReason,$error): void {
            $this->one('SELECT id FROM generation_runs WHERE id=? FOR UPDATE',[$lease['id']]);
            $record=$this->one('SELECT * FROM generation_attempts WHERE id=? FOR UPDATE',[$attempt['id']]);
            if ($record===null || ($record['state']!=='running' && $record['error_code']!=='usage_unknown')) return;
            if ($usage===null && $record['error_code']==='usage_unknown') return;
            $config=self::decode($record['model_config_json']);
            if (($config['lease_token']??null)!==$lease['lease_token']) throw new StoryOutlineTaskLeaseLostException('Attempt settlement identity mismatch.');
            $known=is_array($usage) && isset($usage['prompt_tokens'],$usage['completion_tokens'])
                && (int)$usage['prompt_tokens']>=0 && (int)$usage['completion_tokens']>=0
                && ((int)$usage['prompt_tokens']+(int)$usage['completion_tokens']>0);
            $input=$known?(int)$usage['prompt_tokens']:0; $output=$known?(int)$usage['completion_tokens']:0;
            if ($known) StoryOutlineBudget::assertUsage($input,$output);
            $partial=($usage['source']??'')==='provider_partial';
            $settled = $known && !$partial;
            $attemptState = $error === null ? 'completed' : 'failed';
            $classification = $settled
                ? ($error === null ? null : 'provider_or_validation_error')
                : 'usage_unknown';
            $this->execute('UPDATE generation_attempts SET state=?,input_tokens=?,output_tokens=?,cache_tokens=?,finish_reason=?,error_code=?,error_message=?,completed_at=? WHERE id=?',[
                $attemptState,$input,$output,(int)($usage['cache_hit_tokens']??0),$finishReason,
                $classification,$error===null?null:mb_substr(get_class($error),0,500),$this->date(),$record['id']]);
            $step = $this->one('SELECT * FROM generation_steps WHERE id=?', [(int)$record['step_id']]);
            if ($step === null) throw new RuntimeException('Attempt stage disappeared during settlement.');
            $this->appendAttemptEvent(
                $lease,
                $step,
                (int)$record['id'],
                (int)$record['attempt_no'],
                $attemptState === 'completed' ? 'attempt.completed' : 'attempt.failed',
                $attemptState,
                $classification
            );
            if ($known) {
                $inputDelta=max(0,$input-(int)$record['input_tokens']); $outputDelta=max(0,$output-(int)$record['output_tokens']);
                $this->execute('UPDATE generation_runs SET input_tokens=input_tokens+?,output_tokens=output_tokens+? WHERE id=?',[$inputDelta,$outputDelta,$lease['id']]);
            }
        });
    }

    private function saveStage(array $lease,string $stage,array $output): void
    {
        $this->transaction(function () use ($lease,$stage,$output): void {
            $this->assertLease($lease);
            $step = $this->one('SELECT * FROM generation_steps WHERE run_id=? AND step_key=? FOR UPDATE',[$lease['id'],$stage]);
            if ($step === null || $step['state'] !== 'running' || $step['lease_token'] !== $lease['lease_token']) throw new StoryOutlineTaskLeaseLostException('Stage fence was lost.');
            $policy = self::decode($step['policy_json']);
            if ((int)($policy['deadline'] ?? 0) <= $this->time()) throw new StoryOutlineTaskDeadlineException('The persisted stage deadline expired.');
            $artifactId = $this->artifact((int)$lease['id'],(int)$step['id'],'story-outline.'.$stage,$output);
            $this->execute("UPDATE generation_steps SET state='succeeded',progress=100,output_artifact_id=?,output_hash=?,lease_token=NULL,lease_expires_at=NULL,completed_at=?,updated_at=? WHERE id=?",[$artifactId,self::hash($output),$this->date(),$this->date(),$step['id']]);
            $this->appendStepEvent($lease, array_merge($step, ['state'=>'succeeded']), 'step.completed', 'succeeded');
            $count = $this->one("SELECT COUNT(*) AS n FROM generation_steps WHERE run_id=? AND state='succeeded'",[$lease['id']]);
            $this->execute('UPDATE generation_runs SET progress=?,result_json=NULL,updated_at=? WHERE id=?',[(int)floor((int)$count['n']*95/3),$this->date(),$lease['id']]);
        });
    }

    private function publish(array $lease,array $snapshot,array $candidate): void
    {
        $this->transaction(function () use ($lease,$snapshot,$candidate): void {
            // Match chapter-writing's chapter-before-novel order. Lock the source
            // rows/ranges until commit so an edit cannot slip past this comparison.
            $sourceHash = $this->sourceRevisionHash((int)$lease['novel_id'],true);
            $novel = $this->ownedNovel((int)$lease['user_id'],(int)$lease['novel_id'],true);
            $this->assertLease($lease);
            $old = $this->outline((int)$lease['novel_id'],true);
            if (!hash_equals((string)$snapshot['base_outline_hash'],self::outlineHash($old))
                || !hash_equals((string)$snapshot['novel_input_hash'],self::novelInputHash($novel))
                || !hash_equals((string)($snapshot['source_revision_hash']??''),$sourceHash)) {
                throw new InvalidArgumentException('生成期间小说设定或故事大纲已修改；已保留当前内容，请选择重新生成。');
            }
            $columns = ['story_arc','act_division','major_turning_points','character_arcs','character_endpoints','character_progression','world_evolution','recurring_motifs'];
            $values = [];
            foreach ($columns as $field) {
                $value = $candidate[$field] ?? null;
                $values[] = in_array($field,['act_division','major_turning_points','character_arcs','character_progression','recurring_motifs'],true) && $value !== null ? self::json($value) : $value;
            }
            if ($old === null) {
                $this->execute('INSERT INTO story_outlines (novel_id,'.implode(',',$columns).') VALUES (?,?,?,?,?,?,?,?,?)',array_merge([(int)$lease['novel_id']],$values));
            } else {
                $this->execute('UPDATE story_outlines SET '.implode(',',array_map(static fn (string $column): string => $column.'=?',$columns)).' WHERE novel_id=?',array_merge($values,[(int)$lease['novel_id']]));
            }
            $this->execute('UPDATE novels SET has_story_outline=1 WHERE id=?',[$lease['novel_id']]);
            $saved = $this->outline((int)$lease['novel_id']);
            $this->execute("UPDATE generation_runs SET state='succeeded',progress=100,current_step_key='publish',result_json=?,active_lock_key=NULL,lease_owner=NULL,lease_token=NULL,lease_expires_at=NULL,error_code=NULL,error_message=NULL,completed_at=?,updated_at=? WHERE id=?",[self::json(['outline_id'=>(int)$saved['id'],'outline_hash'=>self::outlineHash($saved),'source_revision_hash'=>$sourceHash]),$this->date(),$this->date(),$lease['id']]);
            $this->appendRunEvent($lease, 'run.completed', 'succeeded', 'published');
        });
    }

    private function recordFailure(array $lease,?string $stage,Throwable $error): void
    {
        error_log('[story-outline '.$lease['run_uid'].'] '.get_class($error).': '.$error->getMessage());
        try {
            $this->transaction(function () use ($lease,$stage,$error): void {
                $run = $this->assertLease($lease, false);
                $step = $stage === null ? null : $this->one('SELECT * FROM generation_steps WHERE run_id=? AND step_key=? FOR UPDATE',[$lease['id'],$stage]);
                $policy = self::decode($step['policy_json'] ?? null);
                $retry = !($error instanceof InvalidArgumentException) && !($error instanceof StoryOutlineTaskDeadlineException) && !($error instanceof StoryOutlineBudgetException)
                    && (int)$run['attempt'] < (int)$run['max_attempts'] && $this->time()+10 < $this->timestamp($run['deadline_at'])
                    && ($step === null || ((int)$step['attempt'] < (int)$step['max_attempts'] && $this->time()+10 < (int)($policy['deadline'] ?? 0)));
                $message = $error instanceof InvalidArgumentException || $error instanceof StoryOutlineBudgetException ? $error->getMessage()
                    : ($retry ? '本阶段连接中断，正在恢复；已完成阶段已保存。' : '本阶段未完成，已保存其他阶段。点击继续生成可从此处恢复。');
                $state = $retry ? 'retry_wait' : 'failed';
                if ($step !== null) {
                    $this->execute('UPDATE generation_steps SET state=?,lease_token=NULL,lease_expires_at=NULL,error_code=?,error_message=?,retry_at=?,updated_at=? WHERE id=?',[$state,'stage_interrupted',mb_substr($message,0,500),$retry?$this->date($this->time()+5):null,$this->date(),$step['id']]);
                    $this->appendStepEvent($run, array_merge($step, ['state'=>$state]), 'step.failed', $state);
                }
                $this->execute('UPDATE generation_runs SET state=?,active_lock_key=?,lease_owner=NULL,lease_token=NULL,lease_expires_at=NULL,error_code=?,error_message=?,available_at=?,completed_at=?,updated_at=? WHERE id=?',[$state,$retry?'story-outline':null,$error instanceof InvalidArgumentException?'input_conflict':($error instanceof StoryOutlineBudgetException?'budget_exhausted':'stage_interrupted'),mb_substr($message,0,500),$this->date($this->time()+5),$retry?null:$this->date(),$this->date(),$lease['id']]);
                $this->appendRunEvent(
                    $run,
                    $retry ? 'run.retry_scheduled' : 'run.failed',
                    $state,
                    $retry ? 'retry_scheduled' : 'stage_failed'
                );
            });
        } catch (StoryOutlineTaskLeaseLostException $lost) {
            // The replacement worker is responsible for the task now.
        }
    }

    private function completed(int $runId): array
    {
        $rows = $this->all("SELECT s.step_key,s.output_hash,a.content_hash,a.inline_json FROM generation_steps s JOIN generation_artifacts a ON a.id=s.output_artifact_id AND a.run_id=s.run_id AND a.step_id=s.id WHERE s.run_id=? AND s.state='succeeded' ORDER BY s.ordinal",[$runId]);
        $result = [];
        foreach ($rows as $row) {
            $output = self::decode($row['inline_json']);
            $hash = self::hash($output);
            if (!in_array($row['step_key'],self::STAGES,true) || !hash_equals($row['content_hash'],$hash) || !hash_equals($row['output_hash'],$hash)) throw new RuntimeException('Saved story outline checkpoint failed integrity validation.');
            $result[$row['step_key']] = StoryOutlineStageGenerator::validateStage($row['step_key'],$output);
        }
        return $result;
    }

    private function snapshot(int $runId): array
    {
        $row = $this->one("SELECT inline_json,content_hash FROM generation_artifacts WHERE run_id=? AND logical_key='story-outline.context' AND revision_no=1",[$runId]);
        if ($row === null) throw new RuntimeException('Story outline context snapshot is missing.');
        $snapshot = self::decode($row['inline_json']);
        if (!hash_equals($row['content_hash'],self::hash($snapshot))) throw new RuntimeException('Story outline context snapshot is corrupt.');
        // Validate the original artifact first, then omit retired pricing in its in-memory view.
        if (isset($snapshot['context']['models'])) {
            $snapshot['context']['models'] = StoryOutlineBudget::normalizeFrozenModels($snapshot['context']['models']);
        }
        return $snapshot;
    }

    private function artifact(int $runId,?int $stepId,string $key,array $value): int
    {
        $json = self::json($value);
        $this->execute("INSERT INTO generation_artifacts (artifact_uid,run_id,step_id,artifact_type,logical_key,revision_no,content_hash,mime_type,encoding,storage_kind,inline_json,size_bytes,sensitivity,created_at) VALUES (?,?,?,'story-outline.checkpoint',?,1,?,'application/json','utf-8','inline',?,?,'internal',?)",[bin2hex(random_bytes(16)),$runId,$stepId,$key,self::hash($value),$json,strlen($json),$this->date()]);
        return (int)$this->db->lastInsertId();
    }

    private function present(array $run): array
    {
        $steps = $this->all('SELECT step_key,state FROM generation_steps WHERE run_id=? ORDER BY ordinal',[$run['id']]);
        $completed = array_values(array_map(static fn (array $step): string => $step['step_key'],array_filter($steps,static fn (array $step): bool => $step['state']==='succeeded')));
        $result = self::decode($run['result_json']);
        $goal = self::decode($run['goal_json']);
        $workerNeeded = in_array($run['state'],['queued','retry_wait'],true) ? $this->timestamp($run['available_at']) <= $this->time()
            : ($run['state']==='running' && $this->timestamp($run['lease_expires_at']) <= $this->time());
        return ['task_id'=>$run['run_uid'],'state'=>$run['state'],'stage'=>$run['current_step_key'],
            'completed_stages'=>$completed,'total_stages'=>3,'progress'=>(int)$run['progress'],'preview'=>(string)($result['preview']??''),
            'error'=>$run['error_message'],'error_code'=>$run['error_code'],
            'can_resume'=>in_array($run['state'],['failed','canceled'],true) && $run['error_code']!=='input_conflict',
            'result_ready'=>$run['state']==='succeeded','updated_at_display'=>formatAppTime($run['updated_at']),
            'worker_needed'=>$workerNeeded,'worker_waiting'=>$workerNeeded && $this->time()-$this->timestamp($run['updated_at'])>30,
            'cancel_requested'=>(bool)$run['cancel_requested'],'resumed_from'=>$goal['resumed_from']??null,
            'request_key'=>$goal['request_key']??null,'request_mode'=>$goal['request_mode']??null];
    }

    private function savedResult(array $outline): array
    {
        return ['task_id'=>null,'state'=>'succeeded','stage'=>'publish','completed_stages'=>self::STAGES,'total_stages'=>3,
            'progress'=>100,'preview'=>'','error'=>null,'error_code'=>null,'can_resume'=>false,'result_ready'=>true,
            'updated_at_display'=>formatAppTime($outline['updated_at']??null),'worker_needed'=>false,'worker_waiting'=>false];
    }

    private function finishUnleased(array $run,string $state,string $code,string $message): void
    {
        $this->execute('UPDATE generation_runs SET state=?,active_lock_key=NULL,lease_owner=NULL,lease_token=NULL,lease_expires_at=NULL,error_code=?,error_message=?,completed_at=?,updated_at=? WHERE id=?',[$state,$code,$message,$this->date(),$this->date(),$run['id']]);
        if ($state === 'failed') {
            $this->appendRunEvent($run, 'run.failed', 'failed', $code);
        }
    }

    private function appendStepEvent(
        array $run,
        array $step,
        string $eventType,
        string $state
    ): void {
        $attempt = (int)($step['attempt'] ?? 0);
        (new RunEventStore($this->db))->append(new GenerationEventDraft(
            (int)$run['id'],
            (int)$step['id'],
            null,
            $eventType,
            1,
            $eventType === 'step.failed'
                ? GenerationEventSeverity::WARNING
                : GenerationEventSeverity::INFO,
            'story-outline:' . $eventType . ':' . (int)$step['id'] . ':' . $attempt
                // A retryable interruption can later expire before another
                // attempt begins. Both outcomes are immutable observations.
                . ($eventType === 'step.failed' && $state === 'failed' ? ':failed' : ''),
            'story_outline',
            null,
            null,
            true,
            [
                'stage_name' => (string)$step['step_key'],
                'ordinal' => (int)$step['ordinal'],
                'attempt' => $attempt,
                'state' => $state,
            ]
        ));
    }

    private function appendAttemptEvent(
        array $run,
        array $step,
        int $attemptId,
        int $attemptNo,
        string $eventType,
        string $state,
        ?string $classification = null
    ): void {
        $payload = ['state'=>$state, 'attempt_no'=>$attemptNo];
        if ($classification !== null) $payload['classification'] = $classification;
        (new RunEventStore($this->db))->append(new GenerationEventDraft(
            (int)$run['id'],
            (int)$step['id'],
            $attemptId,
            $eventType,
            1,
            $eventType === 'attempt.failed'
                ? GenerationEventSeverity::WARNING
                : GenerationEventSeverity::INFO,
            'story-outline:' . $eventType . ':' . $attemptId . ':'
                . ($classification ?? 'settled'),
            'story_outline',
            null,
            null,
            false,
            $payload
        ));
    }

    private function appendRunEvent(
        array $run,
        string $eventType,
        string $state,
        string $reason,
        bool $clientVisible = true,
        bool $reclaimed = false
    ): void {
        $payload = ['state' => $state, 'progress' => (int)($run['progress'] ?? 0), 'reason' => $reason];
        if ($eventType === 'run.cancel_requested') {
            $payload['cancel_requested'] = true;
        }
        if ($eventType === 'run.claimed') {
            $payload += ['attempt' => (int)$run['attempt'], 'fence_generation' => (int)$run['fence_generation'],
                'lease_owner' => (string)$run['lease_owner'], 'reclaimed' => $reclaimed,
                // Exhausted Story Outline tasks create a successor Run rather
                // than borrowing the last-attempt recovery claim of M62.
                'recovery' => false];
        }
        (new RunEventStore($this->db))->append(new GenerationEventDraft(
            (int)$run['id'],
            null,
            null,
            $eventType,
            1,
            $eventType === 'run.failed' ? GenerationEventSeverity::ERROR
                : ($eventType === 'run.canceled' ? GenerationEventSeverity::WARNING
                    : GenerationEventSeverity::INFO),
            // The durable identity is the event type plus the Run lease
            // coordinates; the free-form reason (including error codes) is
            // payload evidence and must never define or fork the Event key.
            'story-outline:' . $eventType . ':' . (int)($run['fence_generation'] ?? 0)
                . ':' . (int)($run['attempt'] ?? 0),
            'story_outline',
            null,
            null,
            $clientVisible,
            $payload
        ));
    }

    private function ownedNovel(int $userId,int $novelId,bool $lock=false): array
    {
        $novel = $this->one('SELECT * FROM novels WHERE id=? AND user_id=?'.($lock?' FOR UPDATE':''),[$novelId,$userId]);
        if ($novel === null) throw new InvalidArgumentException('小说不存在或无权访问。');
        return $novel;
    }

    private function outline(int $novelId,bool $lock=false): ?array { return $this->one('SELECT * FROM story_outlines WHERE novel_id=?'.($lock?' FOR UPDATE':''),[$novelId]); }
    private function run(string $uid): array { return $this->one('SELECT * FROM generation_runs WHERE run_uid=? AND run_type=?',[$uid,self::RUN_TYPE]) ?? throw new RuntimeException('Story outline task disappeared.'); }
    private function validTaskId(string $id): void { if (!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new InvalidArgumentException('任务编号无效。'); }
    private function time(): int { return (int)($this->now)(); }
    private function date(?int $time=null): string { return gmdate('Y-m-d H:i:s',$time??$this->time()); }
    private function timestamp(?string $date): int { return $date===null?0:(new DateTimeImmutable($date,new DateTimeZone('UTC')))->getTimestamp(); }
    private static function decode(?string $json): array { return $json===null?[]:json_decode($json,true,512,JSON_THROW_ON_ERROR); }
    private static function json(mixed $value): string { return json_encode(self::canonical($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
    private static function hash(mixed $value): string { return hash('sha256',self::json($value)); }
    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value,SORT_STRING);
        foreach ($value as $key=>$item) $value[$key]=self::canonical($item);
        return $value;
    }
    private static function novelInputHash(array $novel): string
    {
        $fields=['title','genre','writing_style','protagonist_name','protagonist_info','plot_settings','world_settings','extra_settings','target_chapters','target_reader','narrative_structure','narrative_method','narrative_pov','literary_genre','world_setting_era','novel_types','writing_tone','protagonist_traits','core_conflicts','appeal_points','taboos','opening_type','protagonist_entrance','custom_settings'];
        return self::hash(array_intersect_key($novel,array_flip($fields)));
    }
    /** Internal publisher/followup guard; callers must check task ownership first. */
    public function sourceRevisionHash(int $novelId,bool $lock=false): string
    {
        $suffix=$lock?' FOR UPDATE':'';
        $source=StoryOutlineSource::capture($this->db,$novelId,$lock);
        $rows=$this->all('SELECT * FROM volume_outlines WHERE novel_id=? ORDER BY id'.$suffix,[$novelId]);
        $volumes=[];
        foreach ($rows as $row) {
            // Derived key_events/status change during followup and are not prompt inputs.
            $volumes[]=['id'=>(int)$row['id'],'number'=>(int)($row['volume_number']??$row['volume_index']??0),
                'title'=>$row['title']??'','from'=>(int)($row['chapter_from']??$row['start_chapter']??0),
                'to'=>(int)($row['chapter_to']??$row['end_chapter']??0)];
        }
        return self::hash(['source'=>$source,'volumes'=>$volumes]);
    }
    private function execute(string $sql,array $params=[]): int { $stmt=$this->db->prepare($sql); $stmt->execute($params); return $stmt->rowCount(); }
    private function one(string $sql,array $params=[]): ?array { $stmt=$this->db->prepare($sql); $stmt->execute($params); return $stmt->fetch(PDO::FETCH_ASSOC)?:null; }
    private function all(string $sql,array $params=[]): array { $stmt=$this->db->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_ASSOC); }
    private function transaction(callable $callback): mixed
    {
        if ($this->db->inTransaction()) throw new LogicException('Story outline service requires its own short transaction.');
        $this->db->beginTransaction();
        try { $result=$callback(); $this->db->commit(); return $result; }
        catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
}
