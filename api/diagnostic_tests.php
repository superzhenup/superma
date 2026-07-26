<?php
/**
 * 系统诊断测试用例集合
 *
 * 由 api/system_diagnostic.php 加载，提供 runAllTests() 函数。
 * 测试分类：memory / foreshadowing / quality / writing / progress
 *
 * 每个测试通过 runTest() 注册到 $diagnostic 结构，测试函数返回：
 *   ['status' => 'pass|warning|fail|critical', 'message' => '...', 'details' => [...], 'duration' => ms]
 */

// 本文件是 api/system_diagnostic.php 的函数库（仅定义 runAllTests），不是可直接访问的端点。
// 直接 HTTP 命中时 APP_LOADED 未定义，立即拒绝，避免裸暴露在 api/ 下。
defined('APP_LOADED') or die('Direct access denied.');

if (!function_exists('runAllTests')) {

    function runAllTests(int $novelId, array $novel, string $testType, array &$diagnostic): void
    {
        $runAll = ($testType === 'all');

        // ── 1. 记忆引擎 ──────────────────────────────────────────
        if ($runAll || $testType === 'memory') {
            runTest('memory', 'memory_engine_loadable', function () use ($novelId) {
                $start = microtime(true);
                $file = dirname(__DIR__) . '/includes/memory/MemoryEngine.php';
                if (!file_exists($file)) {
                    return ['status' => 'fail', 'message' => 'MemoryEngine.php 文件不存在'];
                }
                require_once $file;
                if (!class_exists('MemoryEngine')) {
                    return ['status' => 'fail', 'message' => 'MemoryEngine 类未定义'];
                }
                $engine = new MemoryEngine($novelId);
                return [
                    'status'  => 'pass',
                    'message' => 'MemoryEngine 实例化成功',
                    'duration' => (int)((microtime(true) - $start) * 1000),
                ];
            }, $diagnostic);

            runTest('memory', 'novel_state_exists', function () use ($novelId) {
                $row = DB::fetch('SELECT * FROM novel_state WHERE novel_id=?', [$novelId]);
                if (!$row) {
                    return ['status' => 'warning', 'message' => 'novel_state 无数据（首次写作后自动生成）'];
                }
                return [
                    'status'  => 'pass',
                    'message' => 'novel_state 已初始化',
                    'details' => ['story_momentum' => $row['story_momentum'] ?? null],
                ];
            }, $diagnostic);

            runTest('memory', 'character_cards_count', function () use ($novelId) {
                $count = (int)(DB::fetch('SELECT COUNT(*) c FROM character_cards WHERE novel_id=?', [$novelId])['c'] ?? 0);
                if ($count === 0) {
                    return ['status' => 'warning', 'message' => '无人物卡片（写作首章后自动抽取）'];
                }
                return [
                    'status'  => 'pass',
                    'message' => "已抽取 {$count} 张人物卡片",
                    'details' => ['count' => $count],
                ];
            }, $diagnostic);

            runTest('memory', 'embeddings_count', function () use ($novelId) {
                $count = (int)(DB::fetch('SELECT COUNT(*) c FROM novel_embeddings WHERE novel_id=?', [$novelId])['c'] ?? 0);
                if ($count === 0) {
                    return ['status' => 'warning', 'message' => '无向量索引（写作后自动构建）'];
                }
                return [
                    'status'  => 'pass',
                    'message' => "已索引 {$count} 条向量",
                    'details' => ['count' => $count],
                ];
            }, $diagnostic);
        }

        // ── 2. 伏笔系统 ──────────────────────────────────────────
        if ($runAll || $testType === 'foreshadowing') {
            runTest('foreshadowing', 'foreshadowing_repo_loadable', function () {
                $file = dirname(__DIR__) . '/includes/memory/ForeshadowingRepo.php';
                if (!file_exists($file)) {
                    return ['status' => 'fail', 'message' => 'ForeshadowingRepo.php 不存在'];
                }
                require_once $file;
                if (!class_exists('ForeshadowingRepo')) {
                    return ['status' => 'fail', 'message' => 'ForeshadowingRepo 类未定义'];
                }
                return ['status' => 'pass', 'message' => 'ForeshadowingRepo 可加载'];
            }, $diagnostic);

            runTest('foreshadowing', 'foreshadowing_stats', function () use ($novelId) {
                $row = DB::fetch(
                    'SELECT
                        COUNT(*) AS total,
                        SUM(CASE WHEN status="planted" THEN 1 ELSE 0 END) AS planted,
                        SUM(CASE WHEN status="resolved" THEN 1 ELSE 0 END) AS resolved
                     FROM foreshadowing_items WHERE novel_id=?',
                    [$novelId]
                );
                $total = (int)($row['total'] ?? 0);
                if ($total === 0) {
                    return ['status' => 'warning', 'message' => '无伏笔数据（写作后自动抽取）'];
                }
                $resolved = (int)($row['resolved'] ?? 0);
                $rate = $total > 0 ? round($resolved / $total * 100, 1) : 0;
                $status = $rate >= 50 ? 'pass' : 'warning';
                return [
                    'status'  => $status,
                    'message' => "伏笔 {$total} 条，已回收 {$resolved} 条（{$rate}%）",
                    'details' => ['total' => $total, 'resolved' => $resolved, 'rate' => $rate],
                ];
            }, $diagnostic);
        }

        // ── 3. 质量检测 ──────────────────────────────────────────
        if ($runAll || $testType === 'quality') {
            runTest('quality', 'gates_loadable', function () {
                $file = dirname(__DIR__) . '/includes/quality/Gates.php';
                if (!file_exists($file)) {
                    return ['status' => 'fail', 'message' => 'quality/Gates.php 不存在'];
                }
                require_once $file;
                return ['status' => 'pass', 'message' => '五关检测 Gates.php 可加载'];
            }, $diagnostic);

            runTest('quality', 'recent_chapter_quality', function () use ($novelId) {
                $row = DB::fetch(
                    'SELECT chapter_number, quality_score, gate_results
                     FROM chapters WHERE novel_id=? AND quality_score IS NOT NULL
                     ORDER BY chapter_number DESC LIMIT 1',
                    [$novelId]
                );
                if (!$row) {
                    return ['status' => 'warning', 'message' => '暂无质量评分数据'];
                }
                $score = (float)($row['quality_score'] ?? 0);
                $status = $score >= 7.0 ? 'pass' : ($score >= 5.0 ? 'warning' : 'fail');
                return [
                    'status'  => $status,
                    'message' => "第 {$row['chapter_number']} 章评分 {$score}/10",
                    'details' => ['chapter' => $row['chapter_number'], 'score' => $score],
                ];
            }, $diagnostic);

            runTest('quality', 'critic_scores', function () use ($novelId) {
                $row = DB::fetch(
                    'SELECT chapter_number, critic_scores
                     FROM chapters WHERE novel_id=? AND critic_scores IS NOT NULL
                     ORDER BY chapter_number DESC LIMIT 1',
                    [$novelId]
                );
                if (!$row) {
                    return ['status' => 'warning', 'message' => '暂无 CriticAgent 评分'];
                }
                return [
                    'status'  => 'pass',
                    'message' => "第 {$row['chapter_number']} 章已有 CriticAgent 评分",
                ];
            }, $diagnostic);
        }

        // ── 4. 写作引擎 ──────────────────────────────────────────
        if ($runAll || $testType === 'writing') {
            runTest('writing', 'write_engine_loadable', function () {
                $file = dirname(__DIR__) . '/includes/write_engine.php';
                if (!file_exists($file)) {
                    return ['status' => 'fail', 'message' => 'write_engine.php 不存在'];
                }
                require_once $file;
                if (!class_exists('WriteEngine')) {
                    return ['status' => 'fail', 'message' => 'WriteEngine 类未定义'];
                }
                return ['status' => 'pass', 'message' => 'WriteEngine 可加载'];
            }, $diagnostic);

            runTest('writing', 'chapter_status', function () use ($novelId) {
                $row = DB::fetch(
                    'SELECT COUNT(*) AS total,
                     SUM(CASE WHEN status="completed" THEN 1 ELSE 0 END) AS completed,
                     SUM(CASE WHEN status="writing" THEN 1 ELSE 0 END) AS writing
                     FROM chapters WHERE novel_id=?',
                    [$novelId]
                );
                $total = (int)($row['total'] ?? 0);
                if ($total === 0) {
                    return ['status' => 'warning', 'message' => '暂无章节'];
                }
                $completed = (int)($row['completed'] ?? 0);
                return [
                    'status'  => 'pass',
                    'message' => "共 {$total} 章，已完成 {$completed} 章",
                    'details' => ['total' => $total, 'completed' => $completed],
                ];
            }, $diagnostic);

            runTest('writing', 'writing_logs', function () use ($novelId) {
                $count = (int)(DB::fetch(
                    'SELECT COUNT(*) c FROM writing_logs WHERE novel_id=?',
                    [$novelId]
                )['c'] ?? 0);
                if ($count === 0) {
                    return ['status' => 'warning', 'message' => '无写作日志'];
                }
                return ['status' => 'pass', 'message' => "已记录 {$count} 条写作日志"];
            }, $diagnostic);
        }

        // ── 5. 进度感知 ──────────────────────────────────────────
        if ($runAll || $testType === 'progress') {
            runTest('progress', 'novel_bible_exists', function () use ($novelId) {
                $row = DB::fetch('SELECT updated_chapter, updated_at FROM novel_bible WHERE novel_id=?', [$novelId]);
                if (!$row) {
                    return ['status' => 'warning', 'message' => 'novel_bible 未生成'];
                }
                return [
                    'status'  => 'pass',
                    'message' => "novel_bible 已更新至第 {$row['updated_chapter']} 章",
                ];
            }, $diagnostic);

            runTest('progress', 'arc_summaries', function () use ($novelId) {
                $count = (int)(DB::fetch(
                    'SELECT COUNT(*) c FROM arc_summaries WHERE novel_id=?',
                    [$novelId]
                )['c'] ?? 0);
                if ($count === 0) {
                    return ['status' => 'warning', 'message' => '无弧段摘要（每10章自动生成）'];
                }
                return ['status' => 'pass', 'message' => "已生成 {$count} 条弧段摘要"];
            }, $diagnostic);

            runTest('progress', 'chapter_synopses', function () use ($novelId) {
                $row = DB::fetch(
                    'SELECT COUNT(*) AS total,
                     SUM(CASE WHEN synopsis IS NOT NULL AND synopsis<>"" THEN 1 ELSE 0 END) AS filled
                     FROM chapters WHERE novel_id=?',
                    [$novelId]
                );
                $total = (int)($row['total'] ?? 0);
                $filled = (int)($row['filled'] ?? 0);
                if ($total === 0) {
                    return ['status' => 'warning', 'message' => '暂无章节'];
                }
                $rate = round($filled / $total * 100, 1);
                $status = $rate >= 80 ? 'pass' : 'warning';
                return [
                    'status'  => $status,
                    'message' => "章节摘要填充率 {$filled}/{$total}（{$rate}%）",
                ];
            }, $diagnostic);
        }
    }

}
