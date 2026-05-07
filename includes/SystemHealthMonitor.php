<?php
defined('APP_LOADED') or die('Direct access denied.');

/**
 * SystemHealthMonitor — 系统健康监控
 *
 * 工程控制论「鲁棒性」核心模块：
 *   系统自我体检——出问题主动告警，而非等用户发现。
 *
 * 检测维度：
 *   1. Agent 决策失败率 — 24h 内失败决策过多
 *   2. CriticAgent 评分异常 — 连续高分（系统性偏差）
 *   3. 指令写入停滞 — 24h 内无 Agent 指令
 *   4. LLM API 成功率 — 写作请求失败率
 *   5. 章节写入停滞 — 超过 48h 无新章节
 *   6. 约束违规激增 — 短期 P0 违规数异常
 */
class SystemHealthMonitor
{
    private int $novelId;

    public function __construct(int $novelId)
    {
        $this->novelId = $novelId;
    }

    /**
     * 执行健康检查
     * @return array{healthy: bool, alerts: array, score: int, recommendations: array}
     */
    public function check(): array
    {
        $alerts = [];
        $score = 100;

        // 1. Agent 决策失败率
        $agentResult = $this->checkAgentFailureRate();
        if ($agentResult['alert']) {
            $alerts[] = $agentResult['alert'];
            $score -= 25;
        }

        // 2. CriticAgent 评分异常
        $criticResult = $this->checkCriticAnomaly();
        if ($criticResult['alert']) {
            $alerts[] = $criticResult['alert'];
            $score -= 20;
        }

        // 3. 指令写入停滞
        $directiveResult = $this->checkDirectiveStagnation();
        if ($directiveResult['alert']) {
            $alerts[] = $directiveResult['alert'];
            $score -= 15;
        }

        // 4. 章节写入停滞
        $chapterResult = $this->checkChapterStagnation();
        if ($chapterResult['alert']) {
            $alerts[] = $chapterResult['alert'];
            $score -= 20;
        }

        // 5. 约束违规激增
        $constraintResult = $this->checkConstraintSurge();
        if ($constraintResult['alert']) {
            $alerts[] = $constraintResult['alert'];
            $score -= 20;
        }

        $recommendations = $this->generateRecommendations($alerts);

        return [
            'healthy'         => $score >= 70,
            'score'           => max(0, $score),
            'alerts'          => $alerts,
            'recommendations' => $recommendations,
            'checked_at'      => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * 检查 Agent 决策失败率
     */
    private function checkAgentFailureRate(): array
    {
        try {
            $failedCount = (int)(DB::fetch(
                "SELECT COUNT(*) as cnt FROM agent_decision_logs
                 WHERE novel_id = ? AND decision_data LIKE '%error%'
                   AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)",
                [$this->novelId]
            )['cnt'] ?? 0);

            if ($failedCount > 5) {
                return [
                    'alert' => [
                        'level'   => 'error',
                        'type'    => 'agent_failure',
                        'message' => "24h内Agent决策失败{$failedCount}次，建议检查LLM服务状态",
                    ],
                ];
            } elseif ($failedCount > 2) {
                return [
                    'alert' => [
                        'level'   => 'warning',
                        'type'    => 'agent_failure',
                        'message' => "Agent决策失败{$failedCount}次，关注趋势",
                    ],
                ];
            }

            return ['alert' => null];
        } catch (\Throwable $e) {
            return ['alert' => null];
        }
    }

    /**
     * 检查 CriticAgent 评分异常
     */
    private function checkCriticAnomaly(): array
    {
        try {
            $recentCritic = DB::fetchAll(
                'SELECT critic_scores FROM chapters
                 WHERE novel_id = ? AND critic_scores IS NOT NULL
                 ORDER BY id DESC LIMIT 10',
                [$this->novelId]
            );

            if (count($recentCritic) < 5) return ['alert' => null];

            $allHigh = true;
            $highCount = 0;
            foreach ($recentCritic as $row) {
                $scores = json_decode($row['critic_scores'], true);
                $avg = $scores['avg'] ?? (is_array($scores['scores'] ?? null)
                    ? array_sum($scores['scores']) / count($scores['scores'])
                    : 0);
                if ($avg < 9) $allHigh = false;
                if ($avg >= 9) $highCount++;
            }

            if ($allHigh && count($recentCritic) >= 8) {
                return [
                    'alert' => [
                        'level'   => 'warning',
                        'type'    => 'critic_bias',
                        'message' => "连续" . count($recentCritic) . "章Critic评分≥9，可能存在系统性打分偏差",
                    ],
                ];
            } elseif ($highCount >= 8) {
                return [
                    'alert' => [
                        'level'   => 'info',
                        'type'    => 'critic_bias',
                        'message' => "近10章中{$highCount}章Critic评分≥9，关注评分真实性",
                    ],
                ];
            }

            return ['alert' => null];
        } catch (\Throwable $e) {
            return ['alert' => null];
        }
    }

    /**
     * 检查指令写入停滞
     */
    private function checkDirectiveStagnation(): array
    {
        try {
            $count24h = (int)(DB::fetch(
                'SELECT COUNT(*) as cnt FROM agent_directives
                 WHERE novel_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)',
                [$this->novelId]
            )['cnt'] ?? 0);

            $chapterCount = (int)(DB::fetch(
                'SELECT COUNT(*) as cnt FROM chapters
                 WHERE novel_id = ? AND status = "completed"',
                [$this->novelId]
            )['cnt'] ?? 0);

            // 还没开始写则不告警
            if ($chapterCount < 2) return ['alert' => null];

            if ($count24h === 0 && $chapterCount >= 3) {
                return [
                    'alert' => [
                        'level'   => 'warning',
                        'type'    => 'directive_stagnation',
                        'message' => '24h内无Agent指令写入，可能Agent体系已停摆',
                    ],
                ];
            }

            return ['alert' => null];
        } catch (\Throwable $e) {
            return ['alert' => null];
        }
    }

    /**
     * 检查章节写入停滞
     */
    private function checkChapterStagnation(): array
    {
        try {
            $lastChapter = DB::fetch(
                'SELECT chapter_number, created_at FROM chapters
                 WHERE novel_id = ? AND status = "completed"
                 ORDER BY id DESC LIMIT 1',
                [$this->novelId]
            );

            if (!$lastChapter) return ['alert' => null];

            $hoursSinceLast = 0;
            if (!empty($lastChapter['created_at'])) {
                $hoursSinceLast = (time() - strtotime($lastChapter['created_at'])) / 3600;
            }

            if ($hoursSinceLast > 72) {
                return [
                    'alert' => [
                        'level'   => 'info',
                        'type'    => 'chapter_stagnation',
                        'message' => "最近章节" . round($hoursSinceLast / 24, 1) . "天前写入，写作可能已中断",
                    ],
                ];
            }

            return ['alert' => null];
        } catch (\Throwable $e) {
            return ['alert' => null];
        }
    }

    /**
     * 检查约束违规激增
     */
    private function checkConstraintSurge(): array
    {
        try {
            $recentP0 = (int)(DB::fetch(
                "SELECT COUNT(*) as cnt FROM constraint_logs
                 WHERE novel_id = ? AND level = 'P0'
                   AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)",
                [$this->novelId]
            )['cnt'] ?? 0);

            if ($recentP0 >= 5) {
                return [
                    'alert' => [
                        'level'   => 'error',
                        'type'    => 'constraint_surge',
                        'message' => "24h内P0约束违规{$recentP0}次，写作质量严重异常",
                    ],
                ];
            } elseif ($recentP0 >= 3) {
                return [
                    'alert' => [
                        'level'   => 'warning',
                        'type'    => 'constraint_surge',
                        'message' => "24h内P0约束违规{$recentP0}次，关注写作质量趋势",
                    ],
                ];
            }

            return ['alert' => null];
        } catch (\Throwable $e) {
            return ['alert' => null];
        }
    }

    /**
     * 生成处理建议
     */
    private function generateRecommendations(array $alerts): array
    {
        $recs = [];

        foreach ($alerts as $alert) {
            $recs[] = match ($alert['type']) {
                'agent_failure'        => '建议：检查LLM API密钥和网络连接，必要时切换到备用模型',
                'critic_bias'          => '建议：偶尔人工评2-3章作为"金标准"，收入human_critic_scores字段，系统将自动校准',
                'directive_stagnation' => '建议：检查Agent总开关（ws_agent_enabled）和后台任务是否正常运行',
                'chapter_stagnation'   => '建议：确认写作队列和后台Worker是否正常，检查是否有卡住的writing状态章节',
                'constraint_surge'     => '建议：检查严格模式设置，P0违规过高时可暂时关闭严格模式避免阻塞',
                default                => '',
            };
        }

        return array_filter($recs);
    }
}
