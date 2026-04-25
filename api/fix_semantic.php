<?php
/**
 * 语义召回一键修复
 * 
 * 诊断 + 修复一步到位。适合部署后运行一次。
 * 
 * GET /api/fix_semantic.php?novel_id=1&dry_run=1   — 只看不动
 * GET /api/fix_semantic.php?novel_id=1              — 执行修复
 */
define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/auth.php';
requireLoginApi();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/memory/EmbeddingProvider.php';
require_once dirname(__DIR__) . '/includes/memory/Vector.php';

header('Content-Type: application/json; charset=utf-8');

$novelId = (int)($_GET['novel_id'] ?? 0);
$dryRun  = isset($_GET['dry_run']);

if (!$novelId) {
    echo json_encode(['ok' => false, 'error' => '缺少 novel_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = ['novel_id' => $novelId, 'dry_run' => $dryRun, 'actions' => []];

// ====== 修复1: ENUM 补齐 cool_point ======
$enumCheck = DB::fetch("SHOW COLUMNS FROM memory_atoms WHERE Field='atom_type'");
$enumStr = $enumCheck['Type'] ?? '';
$result['enum_current'] = $enumStr;

if (strpos($enumStr, 'cool_point') === false) {
    $alterSql = "ALTER TABLE memory_atoms MODIFY COLUMN atom_type ENUM('character_trait','world_setting','plot_detail','style_preference','constraint','technique','world_state','cool_point') NOT NULL";
    $result['actions'][] = [
        'fix' => 'ENUM 补齐 cool_point',
        'sql' => $alterSql,
        'applied' => !$dryRun,
    ];
    if (!$dryRun) {
        try {
            DB::query($alterSql);
            $result['actions'][count($result['actions'])-1]['ok'] = true;
        } catch (Throwable $e) {
            $result['actions'][count($result['actions'])-1]['ok'] = false;
            $result['actions'][count($result['actions'])-1]['error'] = $e->getMessage();
        }
    }
} else {
    $result['actions'][] = ['fix' => 'ENUM 已包含 cool_point', 'applied' => false, 'skip' => true];
}

// ====== 修复2: 补齐 memory_atoms 缺失向量 ======
$pendingAtoms = DB::fetchAll(
    'SELECT id, content FROM memory_atoms WHERE novel_id=? AND embedding IS NULL ORDER BY id LIMIT 100',
    [$novelId]
);
$pendingCount = count($pendingAtoms);
$result['pending_atoms'] = $pendingCount;

if ($pendingCount > 0 && !$dryRun) {
    set_time_limit(CFG_TIME_MEDIUM);
    $texts = array_column($pendingAtoms, 'content');
    $embs = EmbeddingProvider::embedBatch($texts);
    $backfilled = 0;
    if (is_array($embs)) {
        foreach ($pendingAtoms as $i => $p) {
            $emb = $embs[$i] ?? null;
            if (!$emb || empty($emb['vec'])) continue;
            try {
                $blob = Vector::pack($emb['vec']);
                DB::update('memory_atoms', [
                    'embedding' => $blob,
                    'embedding_model' => $emb['model'] ?? '',
                    'embedding_updated_at' => date('Y-m-d H:i:s'),
                ], 'id=? AND novel_id=?', [(int)$p['id'], $novelId]);
                $backfilled++;
            } catch (Throwable $e) {
                // skip
            }
        }
    }
    $result['actions'][] = ['fix' => '补齐 memory_atoms 向量', 'backfilled' => $backfilled, 'applied' => true];
} elseif ($pendingCount > 0 && $dryRun) {
    $result['actions'][] = ['fix' => '补齐 memory_atoms 向量', 'pending' => $pendingCount, 'applied' => false];
} else {
    $result['actions'][] = ['fix' => 'memory_atoms 向量已齐全', 'skip' => true];
}

// ====== 修复3: 知识库数据为空则标记需要 rebuild ======
$kbCount = (int)DB::fetchColumn("SELECT COUNT(*) FROM novel_embeddings WHERE novel_id=?", [$novelId]);
$completedCh = (int)DB::fetchColumn("SELECT COUNT(*) FROM chapters WHERE novel_id=? AND status='completed'", [$novelId]);

if ($kbCount === 0 && $completedCh > 0) {
    $result['actions'][] = [
        'fix' => '知识库向量为空，需要运行 rebuild_embeddings',
        'novel_id' => $novelId,
        'completed_chapters' => $completedCh,
        'how' => '调用 POST /api/rebuild_embeddings.php {"novel_id":' . $novelId . ',"mode":"full"}',
        'applied' => false,
        'manual' => true,
    ];
} else {
    $result['actions'][] = [
        'fix' => '知识库向量状态',
        'kb_vectors' => $kbCount,
        'skip' => $kbCount > 0,
    ];
}

// ====== 最终状态 ======
$finalAtomVec = (int)DB::fetchColumn("SELECT COUNT(*) FROM memory_atoms WHERE novel_id=? AND embedding IS NOT NULL", [$novelId]);
$finalKbVec = (int)DB::fetchColumn("SELECT COUNT(*) FROM novel_embeddings WHERE novel_id=? AND embedding_blob IS NOT NULL", [$novelId]);
$finalAtomTotal = (int)DB::fetchColumn("SELECT COUNT(*) FROM memory_atoms WHERE novel_id=?", [$novelId]);

$result['final_state'] = [
    'atoms_total' => $finalAtomTotal,
    'atoms_with_vec' => $finalAtomVec,
    'kb_vectors' => $finalKbVec,
    'semantic_recall_ready' => ($finalAtomVec > 0 || $finalKbVec > 0),
];
$result['next_step'] = $finalKbVec === 0
    ? '运行 rebuild_embeddings.php 来从 ' . $completedCh . ' 章中提取知识库数据'
    : '语义召回应该已可用，请尝试写下一章验证';

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
