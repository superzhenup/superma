<?php
/**
 * Agent指令管理类
 * 
 * 职责: 管理Agent生成的自然语言指令，实现Agent决策到写作流程的直接注入
 * 
 * 核心功能:
 * - 添加Agent指令
 * - 获取当前章节有效的指令
 * - 清理过期指令
 * 
 * @package NovelWritingSystem
 * @version 1.0.0
 */

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/helpers.php';

class AgentDirectives
{
    public static function capturePrompt(array $chapter, array $directives, array $messages): void
    {
        $claim = (string)($chapter['claim_token'] ?? '');
        if ($claim === '') return;
        $text = implode("\n", array_column($messages, 'content'));
        $used = array_values(array_filter($directives,
            static fn($d) => ($d['rendered'] ?? '') !== '' && str_contains($text, $d['rendered'])));
        self::persistUsage((int)$chapter['novel_id'], 'prompt_directives', [
            'chapter_id' => (int)$chapter['id'],
            'claim_token' => $claim,
            'prompt_hash' => hash('sha256', json_encode($messages, JSON_THROW_ON_ERROR)),
            'directives' => $used,
        ]);
    }

    private static function persistUsage(int $novelId, string $action, array $snapshot): void
    {
        if (!DB::insert('agent_action_logs', [
            'novel_id' => $novelId,
            'agent_type' => 'directive_usage',
            'action' => $action,
            'status' => 'success',
            'params' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => date('Y-m-d H:i:s'),
        ])) {
            throw new RuntimeException('Cannot persist Agent directive usage snapshot.');
        }
    }

    public static function bindContentVersion(int $novelId, int $chapterId, string $claim, string $candidateUid, string $revisionHash, int $chapterNumber): void
    {
        $row = DB::fetch(
            "SELECT params FROM agent_action_logs WHERE novel_id=? AND agent_type='directive_usage'
             AND action='prompt_directives' AND JSON_UNQUOTE(JSON_EXTRACT(params, '$.claim_token'))=?
             AND JSON_EXTRACT(params, '$.chapter_id')=? ORDER BY id DESC LIMIT 1",
            [$novelId, $claim, $chapterId]
        );
        $snapshot = $row ? json_decode($row['params'], true, 512, JSON_THROW_ON_ERROR) : ['directives' => []];
        $snapshot['chapter_id'] = $chapterId;
        $snapshot['candidate_uid'] = $candidateUid;
        $snapshot['revision_hash'] = $revisionHash;
        self::persistUsage($novelId, 'content_directives', $snapshot);
        // The Canon transaction replaces only the current-version outcome projection.
        DB::delete('agent_directive_outcomes', 'novel_id=? AND chapter_number=?', [$novelId, $chapterNumber]);
    }

    public static function usedForContentVersion(int $novelId, array $chapter): array
    {
        $candidate = (string)($chapter['canonical_candidate_uid'] ?? '');
        $revision = (string)($chapter['content_revision_hash'] ?? '');
        if ($candidate === '' || $revision === '') return [];
        $row = DB::fetch(
            "SELECT params FROM agent_action_logs WHERE novel_id=? AND agent_type='directive_usage'
             AND action='content_directives' AND JSON_UNQUOTE(JSON_EXTRACT(params, '$.candidate_uid'))=?
             AND JSON_UNQUOTE(JSON_EXTRACT(params, '$.revision_hash'))=?
             AND JSON_EXTRACT(params, '$.chapter_id')=? ORDER BY id DESC LIMIT 1",
            [$novelId, $candidate, $revision, (int)$chapter['id']]
        );
        if (!$row) return [];
        $snapshot = json_decode($row['params'], true, 512, JSON_THROW_ON_ERROR);
        return $snapshot['directives'] ?? [];
    }

    /**
     * 添加Agent指令
     * 
     * @param int $novelId 小说ID
     * @param int $applyFrom 起始章节号
     * @param string $type 指令类型: quality/strategy/optimization
     * @param string $directive 自然语言指令内容
     * @param int $applyRange 生效范围（从applyFrom开始往后多少章）
     * @param int|null $expiresInHours 过期小时数（可选）
     * @return bool 是否添加成功
     */
    public static function add(
        int $novelId,
        int $applyFrom,
        string $type,
        string $directive,
        int $applyRange = 3,
        ?int $expiresInHours = null,
        string $source = 'system'
    ): bool {
        try {
            $applyTo = $applyFrom + $applyRange - 1;
            $expiresAt = $expiresInHours
                ? date('Y-m-d H:i:s', time() + $expiresInHours * 3600)
                : null;

            // v2 去重：同类型、同生效范围、指令文本完全一致时视为同一条，刷新窗口。
            // 审计修复 M3（2026-09-27）：原实现取前 80 字符做 LIKE 前缀指纹，两条
            // 前 80 字相同、后文不同（如多项弱维清单首行恰好相同但弱维列表不同）
            // 的指令会命中 UPDATE 分支——仅延长旧指令的 apply_to/expires_at，新指令
            // 文本永不落库，消费端看到的仍是上一章的旧建议。改为全文精确比对：
            // 字节级相同的指令才去重续期，不同指令各自落库获得生效机会；指令
            // 膨胀由消费端的 maxActive 同类型限流兜底，不依赖指纹吞并。
            // 审计修复 P3（2026-07-12）：转义 LIKE 通配符（现已改为等值比对，无通配符）
            // P2-4 修复（2026-08-09）：原查询只过滤 is_active=1，未检查 expires_at。
            // 已过期但 is_active 仍为 1 的指令会被命中，add() 走 UPDATE 分支：
            // 若新调用未传 expiresInHours，过期指令的 expires_at 不会被刷新，
            // 指令保持过期状态——"复活"被静默吞掉。增加 expires_at 条件后，
            // 过期指令不再命中指纹去重，add() 会 INSERT 新行实现真正的复活。
            $now = date('Y-m-d H:i:s');
            // 审计修复（2026-08-17 P3）修复7：指纹查询带出 apply_to 列。原 SELECT 只取 id，
            // 命中后无条件 $update['apply_to'] = $applyTo——新值小于等于旧值时会缩短指令
            // 生效窗口（或产生无谓写库）。改为取新旧较大值，仅当新值更大才写该键。
            $existing = DB::fetch(
                "SELECT id, apply_to FROM agent_directives
                 WHERE novel_id=? AND type=? AND is_active=1
                   AND apply_from <= ? AND apply_to >= ?
                   AND directive = ?
                   AND (expires_at IS NULL OR expires_at > ?)
                 LIMIT 1",
                [$novelId, $type, $applyFrom, $applyFrom, $directive, $now]
            );
            if ($existing) {
                // 审计修复（2026-08-17 P3）修复7：apply_to 只增不减；相等则不写该键，
                // 避免无谓写库。expires_at 仍按下方既有规则刷新。
                $update = [];
                if ($applyTo > (int)$existing['apply_to']) {
                    $update['apply_to'] = max((int)$existing['apply_to'], $applyTo);
                }
                // 修复：原代码无条件覆盖 expires_at，当新调用未传 expiresInHours（null）时
                // 会把已有指令从"限时"变成"永不过期"，生命周期被意外延长。
                // 改为仅在新过期时间非空时才刷新该字段。
                if ($expiresAt !== null) {
                    $update['expires_at'] = $expiresAt;
                }
                if (!empty($update)) {
                    DB::update('agent_directives', $update, 'id=?', [$existing['id']]);
                }
                return true;
            }

            DB::insert('agent_directives', [
                'novel_id' => $novelId,
                'apply_from' => $applyFrom,
                'apply_to' => $applyTo,
                'type' => $type,
                'directive' => $directive,
                'expires_at' => $expiresAt,
                'is_active' => 1,
            ]);

            // 同时记录到 agent_action_logs（用于决策时间线显示）
            self::logAction($novelId, $type, $source, $directive, $applyFrom, $applyTo);

            return true;
        } catch (\Throwable $e) {
            error_log("添加Agent指令失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 统计某章生效范围内、指定类型的活跃指令数（用于生产端限流）
     */
    public static function countActiveOfType(int $novelId, int $chapterNumber, string $type): int
    {
        try {
            $now = date('Y-m-d H:i:s');
            $row = DB::fetch(
                'SELECT COUNT(*) AS c FROM agent_directives
                 WHERE novel_id=? AND type=? AND is_active=1
                   AND apply_from <= ? AND apply_to >= ?
                   AND (expires_at IS NULL OR expires_at > ?)',
                [$novelId, $type, $chapterNumber, $chapterNumber, $now]
            );
            return (int)($row['c'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 带上限的添加：同类活跃指令已达 $maxActive 则跳过（防生产端洪水）。
     * urgent 类型不限流。
     */
    public static function addCapped(
        int $novelId,
        int $applyFrom,
        string $type,
        string $directive,
        int $applyRange = 3,
        ?int $expiresInHours = null,
        int $maxActive = 2,
        string $source = 'system'
    ): bool {
        if ($type !== 'urgent' && self::countActiveOfType($novelId, $applyFrom, $type) >= $maxActive) {
            return false;
        }
        return self::add($novelId, $applyFrom, $type, $directive, $applyRange, $expiresInHours, $source);
    }

    /**
     * P1-5 优化：批量添加 Agent 指令
     *
     * 供 postProcess 中各任务收集后一次性写入，减少 DB 往返。
     * 内部按 (type, 指纹) 去重，避免同批次重复指令。
     *
     * @param int   $novelId    小说ID
     * @param array $directives 指令数组，每个元素格式：
     *                          ['apply_from'=>int, 'type'=>string, 'directive'=>string,
     *                           'apply_range'=>int, 'expires_in_hours'=>?int, 'source'=>string]
     * @return int 实际写入条数
     */
    public static function addBatch(int $novelId, array $directives): int
    {
        if (empty($directives)) return 0;

        // 批次内去重：按 (type, 指纹) 唯一化
        $seen = [];
        $unique = [];
        foreach ($directives as $d) {
            $type = (string)($d['type'] ?? 'quality');
            $directive = (string)($d['directive'] ?? '');
            if ($directive === '') continue;
            $fingerprint = mb_substr($directive, 0, 80);
            $key = $type . ':' . $fingerprint;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $unique[] = $d;
        }

        $written = 0;
        foreach ($unique as $d) {
            $ok = self::add(
                $novelId,
                (int)($d['apply_from'] ?? 0),
                (string)($d['type'] ?? 'quality'),
                (string)($d['directive'] ?? ''),
                (int)($d['apply_range'] ?? 3),
                isset($d['expires_in_hours']) ? (int)$d['expires_in_hours'] : null,
                (string)($d['source'] ?? 'system')
            );
            if ($ok) $written++;
        }
        return $written;
    }

    /**
     * 记录动作日志到 agent_action_logs
     */
    private static function logAction(
        int $novelId,
        string $type,
        string $source,
        string $directive,
        int $applyFrom,
        int $applyTo
    ): void {
        try {
            $actionMap = [
                'quality' => '质量改进指令',
                'strategy' => '策略调整指令',
                'optimization' => '优化指令',
                'urgent' => '紧急修复指令',
                'global' => '全局指令',
            ];

            DB::insert('agent_action_logs', [
                'novel_id' => $novelId,
                'agent_type' => $source,
                'action' => $actionMap[$type] ?? "{$type}指令",
                'status' => 'success',
                'params' => json_encode([
                    'type' => $type,
                    'apply_from' => $applyFrom,
                    'apply_to' => $applyTo,
                    'directive_preview' => mb_substr($directive, 0, 100),
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log("AgentDirectives日志记录失败: " . $e->getMessage());
        }
    }
    
    /**
     * 获取当前章节有效的指令
     * 
     * @param int $novelId 小说ID
     * @param int $chapterNumber 当前章节号
     * @return array 有效指令列表
     */
    public static function active(int $novelId, int $chapterNumber): array
    {
        try {
            $now = date('Y-m-d H:i:s');
            
            $directives = DB::fetchAll(
                'SELECT id, type, directive, created_at 
                 FROM agent_directives 
                 WHERE novel_id = ? 
                   AND apply_from <= ? 
                   AND apply_to >= ?
                   AND is_active = 1
                   AND (expires_at IS NULL OR expires_at > ?)
                 ORDER BY created_at DESC',
                [$novelId, $chapterNumber, $chapterNumber, $now]
            );
            
            return $directives ?: [];
        } catch (\Throwable $e) {
            error_log("获取Agent指令失败: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取所有活跃指令（用于调试）
     * 
     * @param int $novelId 小说ID
     * @return array 所有活跃指令
     */
    public static function allActive(int $novelId): array
    {
        try {
            $now = date('Y-m-d H:i:s');
            
            return DB::fetchAll(
                'SELECT * FROM agent_directives 
                 WHERE novel_id = ? 
                   AND is_active = 1
                   AND (expires_at IS NULL OR expires_at > ?)
                 ORDER BY apply_from, created_at DESC',
                [$novelId, $now]
            ) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    /**
     * 停用指令
     *
     * @param int $directiveId 指令ID
     * @param int|null $novelId 小说ID；传入时限定 novel_id 防止越权（审计修复 P2-6 2026-07-01）
     * @return bool 是否停用成功
     */
    public static function deactivate(int $directiveId, ?int $novelId = null): bool
    {
        try {
            if ($novelId !== null) {
                return DB::update(
                    'agent_directives',
                    ['is_active' => 0],
                    'id = ? AND novel_id = ?',
                    [$directiveId, $novelId]
                );
            }
            return DB::update(
                'agent_directives',
                ['is_active' => 0],
                'id = ?',
                [$directiveId]
            );
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    /**
     * 清理过期指令
     * 
     * @param int $novelId 小说ID
     * @param int $olderThanDays 清理多少天前的指令
     * @return int 清理的数量
     */
    public static function cleanup(int $novelId, int $olderThanDays = 30): int
    {
        try {
            $cutoff = date('Y-m-d H:i:s', time() - $olderThanDays * 86400);
            
            $deleted = DB::delete(
                'agent_directives',
                'novel_id = ? AND (expires_at < ? OR (created_at < ? AND is_active = 0))',
                [$novelId, $cutoff, $cutoff]
            );
            
            return $deleted;
        } catch (\Throwable $e) {
            return 0;
        }
    }
    
    /**
     * 获取指令统计信息
     * 
     * @param int $novelId 小说ID
     * @return array 统计信息
     */
    public static function getStats(int $novelId): array
    {
        try {
            $now = date('Y-m-d H:i:s');
            
            $stats = DB::fetch(
                'SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN is_active = 1 AND (expires_at IS NULL OR expires_at > ?) THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN type = "urgent" THEN 1 ELSE 0 END) as urgent_count,
                    SUM(CASE WHEN type = "quality" THEN 1 ELSE 0 END) as quality_count,
                    SUM(CASE WHEN type = "strategy" THEN 1 ELSE 0 END) as strategy_count,
                    SUM(CASE WHEN type = "optimization" THEN 1 ELSE 0 END) as optimization_count,
                    SUM(CASE WHEN type = "global" THEN 1 ELSE 0 END) as global_count
                 FROM agent_directives 
                 WHERE novel_id = ?',
                [$now, $novelId]
            );
            
            return $stats ?: [
                'total' => 0,
                'active' => 0,
                'urgent_count' => 0,
                'quality_count' => 0,
                'strategy_count' => 0,
                'optimization_count' => 0,
                'global_count' => 0,
            ];
        } catch (\Throwable $e) {
            return [
                'total' => 0,
                'active' => 0,
                'urgent_count' => 0,
                'quality_count' => 0,
                'strategy_count' => 0,
                'optimization_count' => 0,
                'global_count' => 0,
            ];
        }
    }
    
    /**
     * 记录指令效果反馈
     * 
     * 在章节完成后评估当前有效的Agent指令对质量的影响。
     * 
     * @param int $novelId 小说ID
     * @param int $chapterNumber 当前章节号
     * @return array{recorded: int, outcomes: array} 记录数及详情
     */
    public static function recordOutcomes(int $novelId, int $chapterNumber): array
    {
        $recorded = 0;
        $outcomes = [];
        try {
            // 1. 获取当前章节的 quality_score / tokens_used / duration_ms
            $chapter = DB::fetch(
                'SELECT id, canonical_candidate_uid, content_revision_hash, quality_score, tokens_used, duration_ms FROM chapters
                 WHERE novel_id = ? AND chapter_number = ? AND status = "completed"',
                [$novelId, $chapterNumber]
            );
            if (empty($chapter)) {
                return ['recorded' => 0, 'outcomes' => []];
            }
            $currentQuality = $chapter['quality_score'] !== null ? (float)$chapter['quality_score'] : null;
            $tokensUsed = (int)($chapter['tokens_used'] ?? 0);
            $durationMs = (int)($chapter['duration_ms'] ?? 0);
            
            if ($currentQuality === null) {
            return ['recorded' => 0, 'outcomes' => []];
        }
        
        // 审计修复（2026-07-19 H-中3）：聚合先于 LIMIT 1 行，AVG 覆盖全部更早章节；
        // 200章后"前5章均值"被远古章节锚定，污染 quality_change 归因 → 指令效果评估、
        // 权重调整、OptimizationAgent ROI 奖励全部计税错。改为子查询。
        $baseline = DB::fetch(
            'SELECT AVG(t.q) as avg_q FROM (
                SELECT quality_score as q FROM chapters
                WHERE novel_id=? AND chapter_number < ? AND quality_score IS NOT NULL AND status="completed"
                ORDER BY chapter_number DESC LIMIT 5
            ) t',
            [$novelId, $chapterNumber]
        );
        $qualityBefore = $baseline && $baseline['avg_q'] !== null ? round((float)$baseline['avg_q'], 1) : null;
            
            // 如果没有前面的章节作为基线，不记录效果反馈（第一章没有对比）
            if ($qualityBefore === null) {
                return ['recorded' => 0, 'outcomes' => []];
            }
            
            // 3. 获取本章有效的指令
            $activeDirectives = self::usedForContentVersion($novelId, $chapter);

            // 审计修复（2026-08-16 批次二）：归因纠偏。
            // 原 quality_change 会记到本章全部活跃指令头上——多指令并存时该数值
            // 是混合效果，无法区分谁在起作用；据此批量停用会误杀有效指令。
            // 规则：仅当本章唯一活跃指令时，quality_change 才可归因到该指令并
            // 参与停用判定；多指令章仍记录 outcome（供统计页展示），不作为停用依据。
            $soloAttribution = count($activeDirectives) === 1;

            // N+1 修复：循环前一次预查本章已记录的指令 outcome，构建 HashSet，
            // 幂等语义与原逐条 SELECT（novel_id+directive_id+chapter_number）一致。
            $existingOutcomeIds = [];
            if ($activeDirectives) {
                $directiveIds = array_map(static fn($d) => (int)$d['id'], $activeDirectives);
                $placeholders = implode(',', array_fill(0, count($directiveIds), '?'));
                $existingRows = DB::fetchAll(
                    "SELECT directive_id FROM agent_directive_outcomes
                     WHERE novel_id = ? AND chapter_number = ? AND directive_id IN ($placeholders)",
                    array_merge([$novelId, $chapterNumber], $directiveIds)
                );
                foreach ($existingRows as $row) {
                    $existingOutcomeIds[(int)$row['directive_id']] = true;
                }
            }

            foreach ($activeDirectives as $d) {
                // 已评估过则跳过（幂等，HashSet 预查见上）
                if (isset($existingOutcomeIds[(int)$d['id']])) continue;
                
                $qualityChange = round($currentQuality - $qualityBefore, 1);
                DB::insert('agent_directive_outcomes', [
                    'novel_id' => $novelId,
                    'directive_id' => (int)$d['id'],
                    'chapter_number' => $chapterNumber,
                    'quality_before' => $qualityBefore,
                    'quality_after' => $currentQuality,
                    'quality_change' => $qualityChange,
                    'tokens_used' => $tokensUsed,
                    'duration_ms' => $durationMs,
                ]);
                self::persistUsage($novelId, 'directive_outcome', [
                    'chapter_id' => (int)$chapter['id'],
                    'candidate_uid' => $chapter['canonical_candidate_uid'],
                    'revision_hash' => $chapter['content_revision_hash'],
                    'directive_id' => (int)$d['id'],
                    'directive_version' => $d['version'],
                    'quality_change' => $qualityChange,
                ]);
                $outcomes[] = [
                    'directive_id' => (int)$d['id'],
                    'type' => $d['type'],
                    'quality_change' => $qualityChange,
                ];
                $recorded++;

                // 审计修复（2026-08-09 R11）：反馈环补"纠错"动作。
                // 批次二加固（2026-08-16）：停用判定只统计"独占章"（该指令是当章
                // 唯一活跃指令）的 outcome——同章多指令的 quality_change 是混合
                // 结果，计入会误杀有效指令。独占章的识别无需新列：recordOutcomes
                // 每章为全部活跃指令各写一行 outcome，故"当章无其他指令的 outcome
                // 行"即为独占章。
                if ($soloAttribution && $qualityChange < 0 && $d['type'] !== 'urgent') {
                    $recent = DB::fetchAll(
                        'SELECT o.quality_change FROM agent_directive_outcomes o
                         WHERE o.novel_id = ? AND o.directive_id = ? AND o.chapter_number <= ?
                           AND NOT EXISTS (
                               SELECT 1 FROM agent_directive_outcomes o2
                               WHERE o2.novel_id = o.novel_id
                                 AND o2.chapter_number = o.chapter_number
                                 AND o2.directive_id != o.directive_id
                           )
                         ORDER BY o.chapter_number DESC LIMIT 2',
                        [$novelId, (int)$d['id'], $chapterNumber]
                    );
                    if (count($recent) >= 2
                        && (float)$recent[0]['quality_change'] < 0
                        && (float)$recent[1]['quality_change'] < 0) {
                        $live = DB::fetch('SELECT type, directive FROM agent_directives WHERE id=? AND novel_id=?', [(int)$d['id'], $novelId]);
                        if (!$live || !hash_equals((string)$d['version'], hash('sha256', $live['type'] . "\n" . $live['directive']))) {
                            continue;
                        }
                        self::deactivate((int)$d['id'], $novelId);
                        error_log("Agent指令 #{$d['id']} 连续2个独占章负收益已自动停用（novel {$novelId}）");
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("记录Agent指令效果失败: " . $e->getMessage());
        }
        return ['recorded' => $recorded, 'outcomes' => $outcomes];
    }
    
    /**
     * 查询指令效果历史
     * 
     * @param int $novelId 小说ID
     * @param array $options 查询选项: limit, type, directive_id, min_change
     * @return array 效果记录列表
     */
    public static function getOutcomes(int $novelId, array $options = []): array
    {
        try {
            $limit = max(1, min(200, (int)($options['limit'] ?? 50)));
            $where = 'WHERE o.novel_id = ?';
            $params = [$novelId];
            
            if (!empty($options['directive_id'])) {
                $where .= ' AND o.directive_id = ?';
                $params[] = (int)$options['directive_id'];
            }
            if (!empty($options['type'])) {
                $where .= ' AND d.type = ?';
                $params[] = $options['type'];
            }
            if (isset($options['min_change'])) {
                $where .= ' AND o.quality_change >= ?';
                $params[] = (float)$options['min_change'];
            }
            
            return DB::fetchAll(
                "SELECT o.*, d.type as directive_type, d.directive 
                 FROM agent_directive_outcomes o 
                 LEFT JOIN agent_directives d ON o.directive_id = d.id 
                 {$where} ORDER BY o.evaluated_at DESC LIMIT ?",
                array_merge($params, [$limit])
            ) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    /**
     * 获取指令效果聚合统计
     * 
     * @param int $novelId 小说ID
     * @return array{by_type: array, top_effective: array, top_harmful: array}
     */
    public static function getOutcomeStats(int $novelId, int $beforeChapter = PHP_INT_MAX): array
    {
        try {
            // 按类型统计
            $byType = DB::fetchAll(
                'SELECT d.type, 
                    COUNT(*) as outcome_count,
                    AVG(o.quality_change) as avg_change,
                    SUM(CASE WHEN o.quality_change > 0 THEN 1 ELSE 0 END) as improved,
                    SUM(CASE WHEN o.quality_change < 0 THEN 1 ELSE 0 END) as declined
                 FROM agent_directive_outcomes o
                 JOIN agent_directives d ON o.directive_id = d.id
                 WHERE o.novel_id = ? AND o.chapter_number < ?
                 GROUP BY d.type',
                [$novelId, $beforeChapter]
            ) ?: [];
            
            // 最有效的指令（改善最大）
            $topEffective = DB::fetchAll(
                'SELECT o.*, d.type, d.directive 
                 FROM agent_directive_outcomes o
                 JOIN agent_directives d ON o.directive_id = d.id
                 WHERE o.novel_id = ? AND o.quality_change > 0 AND o.chapter_number < ?
                 ORDER BY o.quality_change DESC LIMIT 5',
                [$novelId, $beforeChapter]
            ) ?: [];
            
            // 最有副作用的指令（与上两段同加目标章上界，防止未来章 outcome 泄入写前统计）
            $topHarmful = DB::fetchAll(
                'SELECT o.*, d.type, d.directive
                 FROM agent_directive_outcomes o
                 JOIN agent_directives d ON o.directive_id = d.id
                 WHERE o.novel_id = ? AND o.quality_change < 0 AND o.chapter_number < ?
                 ORDER BY o.quality_change ASC LIMIT 5',
                [$novelId, $beforeChapter]
            ) ?: [];
            
            return [
                'by_type' => $byType,
                'top_effective' => $topEffective,
                'top_harmful' => $topHarmful,
            ];
        } catch (\Throwable $e) {
            return ['by_type' => [], 'top_effective' => [], 'top_harmful' => []];
        }
    }
}
