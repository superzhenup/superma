<?php
if (!defined('API_VIA_ROUTER')) { http_response_code(403); exit; }
/**
 * Super-Ma 章节质量检测流水线（五关自动检测）
 *
 * API: POST api/validate_consistency.php
 * 参数: { novel_id, chapter_id }
 * 返回: { passes, total_score, gates: [{name,status,score,issues}], summary }
 *
 * 五关：
 *   第1关 🏗 结构检查 — 字数/黄金三行/结尾钩子
 *   第2关 👥 角色检查 — 主要角色出场率
 *   第3关 📝 描写检查 — 对话密度/段落长度
 *   第4关 💥 爽点检查 — 爽点信号关键词检测
 *   第5关 🔗 连贯性检查 — 前章衔接/伏笔回收
 */

defined('APP_LOADED') || define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
require_once dirname(__DIR__) . '/includes/auth.php';
if (!defined('CLI_MODE')) registerApiErrorHandlers();
if (!defined('CLI_MODE')) requireLoginApi();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/helpers.php';  // 修复（2026-06-18）：countWords() 定义在此
// 修复：质量五关函数下沉到 includes 层，api 与 WriteEngine 都从这里加载
require_once dirname(__DIR__) . '/includes/quality/Gates.php';

// CLI 模式下只加载函数定义，不执行 API 逻辑
if (!defined('CLI_MODE')) {
    header('Content-Type: application/json; charset=utf-8');

    // ---- 解析参数 ----
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $chapterId   = (int)($input['chapter_id'] ?? 0);
    $chapterNum  = (int)($input['chapter_number'] ?? 0);
    $novelId     = (int)($input['novel_id'] ?? 0);

    // 审计 P0（2026-06-12）：归属校验
    $userId = (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
    if ($novelId > 0) checkNovelOwnership($novelId, $userId);
    if ($chapterId > 0) checkChapterOwnership($chapterId, $userId);

    if (!$novelId) {
        echo json_encode(['ok' => false, 'error' => '缺少 novel_id', 'msg' => '缺少 novel_id'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!$chapterId && $chapterNum > 0) {
        $resolved = DB::fetch(
            'SELECT id FROM chapters WHERE novel_id=? AND chapter_number=? LIMIT 1',
            [$novelId, $chapterNum]
        );
        $chapterId = $resolved ? (int)$resolved['id'] : 0;
    }

    if (!$chapterId) {
        $latest = DB::fetch(
            'SELECT id FROM chapters WHERE novel_id=? AND status="completed" ORDER BY chapter_number DESC LIMIT 1',
            [$novelId]
        );
        $chapterId = $latest ? (int)$latest['id'] : 0;
    }

    if (!$chapterId) {
        echo json_encode(['ok' => false, 'error' => '未找到可检测的章节', 'msg' => '未找到可检测的章节'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- 查询章节+小说信息 ----
    $chapter = DB::fetch(
        'SELECT c.*, n.genre, n.chapter_words, n.writing_style 
         FROM chapters c JOIN novels n ON c.novel_id = n.id 
         WHERE c.id = ? AND c.novel_id = ?',
        [$chapterId, $novelId]
    );

    if (!$chapter) {
        echo json_encode(['ok' => false, 'error' => '章节不存在', 'msg' => '章节不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $content = $chapter['content'] ?? '';
    if (empty(trim($content))) {
        echo json_encode(['ok' => false, 'error' => '章节内容为空，无法检测', 'msg' => '章节内容为空，无法检测'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ========== 五关检测流水线 ==========

    $results = [];

    // ---- 第1关：结构检查 ----
    $results[] = checkGate1_Structure($chapter, $content);

    // ---- 第2关：角色检查 ----
    $results[] = checkGate2_Characters($novelId, $content);

    // ---- 第3关：描写检查 ----
    $results[] = checkGate3_Description($chapter['genre'], $content);

    // ---- 第4关：爽点检查 ----
    $results[] = checkGate4_CoolPoint($content, $chapter['outline']);

    // ---- 第5关：连贯性检查 ----
    $results[] = checkGate5_Consistency($chapterId, $novelId, $content);

    // v11: 质量检查开关 — 如果用户在设置中关闭了质量检查，直接返回通过
    $qualityEnabled = (bool)getSystemSetting('ws_quality_check_enabled', true, 'bool');
    if (!$qualityEnabled) {
        echo json_encode([
            'ok'            => true,
            'passes'        => true,
            'total_score'   => 100,
            'gates'         => [],
            'summary'       => '质量检查已关闭',
            'quality_score' => 100,
            'gate_results'  => [],
            'data'          => ['issues' => [], 'warnings' => []],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // ---- 汇总结果 ----
    $allPass = !array_filter($results, fn($r) => !$r['status']);
    $scores  = array_column($results, 'score');
    $avgScore = count($scores) > 0
        ? round(array_sum($scores) / count($scores), 1)
        : 0;

    $allIssues   = [];
    $allWarnings = [];
    foreach ($results as $gate) {
        $chNum = $chapter['chapter_number'] ?? 0;
        $gateName = $gate['name'] ?? '';
        foreach ($gate['issues'] ?? [] as $issue) {
            $item = ['chapter' => $chNum, 'type' => $gateName, 'message' => $issue];
            if (($gate['score'] ?? 100) < 70) {
                $allIssues[] = $item;
            } else {
                $allWarnings[] = $item;
            }
        }
    }

    $response = [
        'ok'            => true,
        'passes'        => $allPass,
        'total_score'   => $avgScore,
        'gates'         => $results,
        'summary'       => generateSummary($results),
        'quality_score' => $avgScore,
        'gate_results'  => $results,
        'data'          => ['issues' => $allIssues, 'warnings' => $allWarnings],
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
