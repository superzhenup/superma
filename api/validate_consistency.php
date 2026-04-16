<?php
/**
 * [v4] 一致性检查 API
 * POST JSON: { novel_id, chapter_number? }
 * 返回：{ ok: true, data: { issues: [], warnings: [] } }
 */
ob_start();
ini_set('display_errors', '0');

define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/ai.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireLoginApi();

header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$novelId = (int)($input['novel_id'] ?? 0);
$checkChapter = (int)($input['chapter_number'] ?? 0);

$novel = DB::fetch('SELECT * FROM novels WHERE id=?', [$novelId]);
if (!$novel) {
    echo json_encode(['ok' => false, 'msg' => '小说不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 获取要检查的章节范围
if ($checkChapter > 0) {
    $chapters = DB::fetchAll(
        'SELECT * FROM chapters WHERE novel_id=? AND chapter_number=? AND status="completed"',
        [$novelId, $checkChapter]
    );
} else {
    // 检查所有已完成章节
    $chapters = DB::fetchAll(
        'SELECT * FROM chapters WHERE novel_id=? AND status="completed" ORDER BY chapter_number DESC LIMIT 10',
        [$novelId]
    );
}

if (empty($chapters)) {
    echo json_encode(['ok' => true, 'data' => ['issues' => [], 'warnings' => ['没有已完成的章节']], JSON_UNESCAPED_UNICODE);
    exit;
}

$allIssues = [];
$allWarnings = [];

foreach ($chapters as $chapter) {
    $chNum = (int)$chapter['chapter_number'];
    
    // 1. 检查人物状态一致性
    $characterIssues = checkCharacterConsistency($novelId, $chapter);
    foreach ($characterIssues as $issue) {
        $allIssues[] = ['chapter' => $chNum, 'type' => '人物不一致', 'message' => $issue];
    }
    
    // 2. 检查情节重复
    $duplicateIssues = checkDuplicatePlots($novelId, $chapter);
    foreach ($duplicateIssues as $issue) {
        $allIssues[] = ['chapter' => $chNum, 'type' => '情节重复', 'message' => $issue];
    }
    
    // 3. 检查伏笔逾期
    $overdueForeshadow = checkOverdueForeshadowing($novelId, $chNum);
    foreach ($overdueForeshadow as $issue) {
        $allWarnings[] = ['chapter' => $chNum, 'type' => '伏笔逾期', 'message' => $issue];
    }
}

// 记录检查结果
DB::insert('consistency_logs', [
    'novel_id'       => $novelId,
    'chapter_number'  => $checkChapter ?: 0,
    'check_type'     => $checkChapter > 0 ? 'single_chapter' : 'batch_check',
    'issues'         => json_encode(['issues' => $allIssues, 'warnings' => $allWarnings], JSON_UNESCAPED_UNICODE),
]);

echo json_encode([
    'ok' => true,
    'data' => [
        'issues'   => $allIssues,
        'warnings' => $allWarnings,
        'checked'   => count($chapters),
    ],
], JSON_UNESCAPED_UNICODE);

/**
 * 检查人物状态一致性
 */
function checkCharacterConsistency(int $novelId, array $chapter): array {
    $issues = [];
    $characterStates = getCharacterStates($novelId);
    
    // 提取章节摘要中的提到的人物
    $text = ($chapter['chapter_summary'] ?: '') . ($chapter['outline'] ?: '');
    if (empty($text)) return [];
    
    // 使用AI检测人物状态冲突
    try {
        $ai = getAIClient($novel['model_id'] ?? null);
        $statesText = '';
        foreach ($characterStates as $name => $state) {
            $statesText .= "{$name}：职务" . ($state['职务'] ?? '未知') . "，处境" . ($state['处境'] ?? '未知') . "\n";
        }
        
        $prompt = <<<EOT
小说当前人物状态：
{$statesText}

第{$chapter['chapter_number']}章摘要和大纲：
{$text}

请检测本章是否存在以下问题（只输出JSON数组，不要有其他文字）：
1. 人物在已记录状态中未出现（新人物）
2. 人物职务与记录矛盾（如：已记录是"学生"但本章称其为"老师"）
3. 人物已死亡但再次出场

输出格式：[{"character": "人物名", "issue": "问题描述"}]
如果没有问题，输出空数组 []
EOT;
        
        $messages = [
            ['role' => 'system', 'content' => '你是一个小说一致性检测助手，只输出纯JSON，不要有任何解释。'],
            ['role' => 'user', 'content' => $prompt]
        ];
        
        $result = $ai->chat($messages, 'structured');
        $parsed = json_decode($result, true);
        
        if (is_array($parsed)) {
            foreach ($parsed as $item) {
                if (!empty($item['issue'])) {
                    $issues[] = $item['character'] . '：' . $item['issue'];
                }
            }
        }
    } catch (Throwable $e) {
        // AI检测失败，跳过
    }
    
    return $issues;
}

/**
 * 检查情节重复
 */
function checkDuplicatePlots(int $novelId, array $chapter): array {
    $issues = [];
    $keyEvents = getKeyEvents($novelId);
    
    if (empty($keyEvents)) return [];
    
    // 使用AI检测相似情节
    try {
        $ai = getAIClient($novel['model_id'] ?? null);
        $eventsText = '';
        foreach ($keyEvents as $e) {
            if ($e['chapter'] < (int)$chapter['chapter_number']) {
                $eventsText .= "第{$e['chapter']}章：{$e['event']}\n";
            }
        }
        
        $prompt = <<<EOT
之前章节已发生的关键事件：
{$eventsText}

当前章节（第{$chapter['chapter_number']}章）大纲：
{$chapter['outline']}

请检测当前大纲是否与上述事件存在相似或重复（只输出JSON数组）。
相似度阈值：70%以上算重复
输出格式：[{"chapter": 章节号, "event": "事件描述", "similarity": 相似度}]
如果没有重复，输出空数组 []
EOT;
        
        $messages = [
            ['role' => 'system', 'content' => '你是一个小说一致性检测助手，只输出纯JSON。'],
            ['role' => 'user', 'content' => $prompt]
        ];
        
        $result = $ai->chat($messages, 'structured');
        $parsed = json_decode($result, true);
        
        if (is_array($parsed)) {
            foreach ($parsed as $item) {
                $issues[] = "与第{$item['chapter']}章事件相似：" . mb_substr($item['event'], 0, 50);
            }
        }
    } catch (Throwable $e) {
        // AI检测失败，跳过
    }
    
    return $issues;
}

/**
 * 检查伏笔逾期
 */
function checkOverdueForeshadowing(int $novelId, int $currentChapter): array {
    $overdue = [];
    $pending = getPendingForeshadowing($novelId);
    
    foreach ($pending as $f) {
        if (!empty($f['deadline'])) {
            $deadline = (int)$f['deadline'];
            if ($currentChapter > $deadline + 5) {
                $overdue[] = "伏笔逾期未回收：{$f['desc']}（应在第{$deadline}章前回收）";
            }
        }
    }
    
    return $overdue;
}
