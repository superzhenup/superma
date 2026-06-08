<?php
/**
 * 重建向量索引API
 * 
 * 支持两种模式：
 * 1. full — 重建知识库（extractFromChapter）+ 补齐 memory_atoms 向量
 * 2. atoms_only — 只补齐 memory_atoms 缺失的向量（快速）
 * 
 * POST JSON: { novel_id: 1, mode: "full"|"atoms_only" }
 * 返回 SSE 流式进度（方便前端显示进度）
 */
define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
requireLoginApi();
requireHttpMethod('POST');
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/embedding.php';
require_once dirname(__DIR__) . '/includes/memory/MemoryEngine.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$novelId = (int)($input['novel_id'] ?? $_POST['novel_id'] ?? 0);
$mode = $input['mode'] ?? $_POST['mode'] ?? 'full';
$outputMode = $input['output_mode'] ?? $_POST['output_mode'] ?? 'sse';

if ($outputMode === 'json') {
    header('Content-Type: application/json; charset=utf-8');
} else {
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
}

function sse(string $event, $data): void {
    global $outputMode;
    if ($outputMode === 'json') return;  // json 模式不输出 SSE
    echo "event: {$event}\ndata: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    ob_flush();
    flush();
}

if (!$novelId) {
    sse('error', ['error' => '缺少小说ID']);
    exit;
}

// 设置较长超时（44章可能需要几分钟）
set_time_limit(CFG_TIME_UNLIMITED);  // 无限制
ini_set('max_execution_time', 0);
ignore_user_abort(true);

$cfg = EmbeddingProvider::getConfig();
if (!$cfg) {
    sse('error', ['error' => '未配置 Embedding 模型，请先在设置页面配置']);
    exit;
}

try {
    $totalExtracted = 0;
    $totalAtoms = 0;
    $errors = [];

    if ($mode === 'full') {
        // ====== 模式1：完整重建知识库 ======
        sse('progress', ['step' => '清除旧数据', 'pct' => 0]);
        DB::query('DELETE FROM novel_embeddings WHERE novel_id = ?', [$novelId]);

        $chapters = DB::fetchAll(
            'SELECT id, chapter_number, content FROM chapters WHERE novel_id = ? AND status = "completed" ORDER BY chapter_number',
            [$novelId]
        );
        $total = count($chapters);
        sse('progress', ['step' => "开始提取 {$total} 章", 'pct' => 0]);

        $kb = new KnowledgeBase($novelId);
        foreach ($chapters as $i => $ch) {
            try {
                $stats = $kb->extractFromChapter((int)$ch['chapter_number'], $ch['content']);
                $extracted = array_sum($stats);
                $totalExtracted += $extracted;
                sse('progress', [
                    'step' => "第{$ch['chapter_number']}章：提取了 {$extracted} 条",
                    'pct' => (int)(($i + 1) / $total * 80),
                    'chapter' => (int)$ch['chapter_number'],
                    'stats' => $stats,
                ]);
            } catch (Throwable $e) {
                error_log("rebuild_embeddings 第{$ch['chapter_number']}章失败: " . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                $errors[] = "第{$ch['chapter_number']}章处理失败";
                sse('progress', [
                    'step' => "第{$ch['chapter_number']}章失败",
                    'pct'  => (int)(($i + 1) / $total * 80),
                ]);
            }
        }
    }

    // ====== 补齐 memory_atoms 缺失的向量 ======
    sse('progress', ['step' => '补齐记忆原子向量...', 'pct' => $mode === 'full' ? 85 : 10]);

    $engine = new MemoryEngine($novelId);
    $report = $engine->ensureEmbeddings(100);
    $totalAtoms = $report['atoms'] ?? 0;

    if (!empty($report['errors'])) {
        $errors = array_merge($errors, $report['errors']);
    }

    // ====== 完成 ======
    $embCount = (int)DB::fetchColumn(
        'SELECT COUNT(*) FROM novel_embeddings WHERE novel_id=? AND embedding_blob IS NOT NULL',
        [$novelId]
    );
    $atomVecCount = (int)DB::fetchColumn(
        'SELECT COUNT(*) FROM memory_atoms WHERE novel_id=? AND embedding IS NOT NULL',
        [$novelId]
    );

    sse('done', [
        'success' => true,
        'mode' => $mode,
        'kb_extracted' => $totalExtracted,
        'atoms_backfilled' => $totalAtoms,
        'final_kb_vectors' => $embCount,
        'final_atom_vectors' => $atomVecCount,
        'errors' => $errors,
        'message' => "完成！知识库向量={$embCount}，记忆原子向量={$atomVecCount}" . ($errors ? '（有部分错误）' : ''),
    ]);

    // JSON 模式：直接输出最终结果
    if ($outputMode === 'json') {
        echo json_encode([
            'success' => true,
            'mode' => $mode,
            'kb_extracted' => $totalExtracted,
            'atoms_backfilled' => $totalAtoms,
            'final_kb_vectors' => $embCount,
            'final_atom_vectors' => $atomVecCount,
            'errors' => $errors,
            'message' => "完成！知识库向量={$embCount}，记忆原子向量={$atomVecCount}" . ($errors ? '（有部分错误）' : ''),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
} catch (Throwable $e) {
    $rid = error_trace_id();
    error_log(sprintf('[%s] rebuild_embeddings: %s in %s:%d', $rid, $e->getMessage(), $e->getFile(), $e->getLine()));
    sse('error', safe_sse_error_payload($e, '重建向量失败，请稍后重试'));
    if ($outputMode === 'json') {
        echo json_encode(safe_api_error_payload($e, '重建向量失败，请稍后重试'), JSON_UNESCAPED_UNICODE);
    }
}
