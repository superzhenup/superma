<?php
/**
 * 快速验证语义召回 — 只从1章提取知识
 * 
 * GET /api/quick_test_semantic.php?novel_id=1
 * 
 * 从最近完成的1章提取知识 → 写入知识库表 + novel_embeddings
 * 然后验证语义召回是否工作
 */
define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/auth.php';
requireLoginApi();
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/embedding.php';
require_once dirname(__DIR__) . '/includes/memory/MemoryEngine.php';
require_once dirname(__DIR__) . '/includes/memory/EmbeddingProvider.php';
require_once dirname(__DIR__) . '/includes/memory/Vector.php';

header('Content-Type: application/json; charset=utf-8');
set_time_limit(CFG_TIME_SHORT);

$novelId = (int)($_GET['novel_id'] ?? 0);
if (!$novelId) {
    echo json_encode(['ok' => false, 'error' => '缺少 novel_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$steps = [];

// ====== 0. 检查实际表结构（排查ENUM不匹配）======
$tables2check = [
    'novel_characters' => ['role_type', 'role_template', 'gender'],
    'novel_worldbuilding' => ['category'],
    'novel_plots' => ['event_type'],
    'novel_style' => ['category'],
    'memory_atoms' => ['atom_type'],
];
$enumInfo = [];
foreach ($tables2check as $tbl => $cols) {
    foreach ($cols as $col) {
        $colInfo = DB::fetch("SHOW COLUMNS FROM `{$tbl}` WHERE Field=?", [$col]);
        $enumInfo["{$tbl}.{$col}"] = $colInfo['Type'] ?? '?';
    }
}
$steps[] = '📋 表结构: ' . json_encode($enumInfo, JSON_UNESCAPED_UNICODE);

// ====== 1. 修复 ENUM（如果 cool_point 缺失）======
$enumCheck = DB::fetch("SHOW COLUMNS FROM memory_atoms WHERE Field='atom_type'");
$enumStr = $enumCheck['Type'] ?? '';
if (strpos($enumStr, 'cool_point') === false) {
    try {
        DB::query("ALTER TABLE memory_atoms MODIFY COLUMN atom_type ENUM('character_trait','world_setting','plot_detail','style_preference','constraint','technique','world_state','cool_point') NOT NULL");
        $steps[] = '✅ ENUM 已补齐 cool_point';
    } catch (Throwable $e) {
        $steps[] = '❌ ENUM 修复失败: ' . $e->getMessage();
    }
} else {
    $steps[] = '⏭ ENUM 已包含 cool_point，跳过';
}

// ====== 2. 补齐 memory_atoms 缺失的向量 ======
$pendingAtoms = DB::fetchAll(
    'SELECT id, content FROM memory_atoms WHERE novel_id=? AND embedding IS NULL ORDER BY id LIMIT 50',
    [$novelId]
);
if (!empty($pendingAtoms)) {
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
            } catch (Throwable $e) { /* skip */ }
        }
    }
    $steps[] = "✅ 补齐 {$backfilled} 条 atom 向量（共" . count($pendingAtoms) . "条待补）";
} else {
    $steps[] = '⏭ memory_atoms 向量已齐全';
}

// ====== 3. 从最近1章提取知识 ======
$chapter = DB::fetch(
    'SELECT id, chapter_number, content FROM chapters WHERE novel_id=? AND status="completed" ORDER BY chapter_number DESC LIMIT 1',
    [$novelId]
);
if (!$chapter) {
    $steps[] = '❌ 没有已完成的章节';
    echo json_encode(['ok' => false, 'steps' => $steps], JSON_UNESCAPED_UNICODE);
    exit;
}

$steps[] = "📖 开始从第{$chapter['chapter_number']}章提取知识...";

try {
    $kb = new KnowledgeBase($novelId);
    $stats = $kb->extractFromChapter((int)$chapter['chapter_number'], $chapter['content']);
    $extracted = array_filter($stats);
    if (empty($extracted)) {
        $steps[] = '⚠️ AI提取结果为空（可能是API问题），尝试用已有数据直接生成向量';
    } else {
        $steps[] = "✅ 提取完成：角色{$stats['characters']}个，世界观{$stats['worldbuilding']}个，情节{$stats['plots']}个，风格{$stats['styles']}个";
    }
} catch (Throwable $e) {
    $steps[] = '❌ extractFromChapter 失败: ' . $e->getMessage();
    $steps[] = '⚠️ 尝试备用方案：直接从 novels 表全局设定生成向量...';
    
    // 备用方案：把 novels 表的全局设定直接向量化写入 novel_embeddings
    // source_id 用 0，因为全局设定没有对应的源记录ID
    try {
        $novel = DB::fetch('SELECT * FROM novels WHERE id=?', [$novelId]);
        if ($novel) {
            // 先清除备用方案可能写入的旧数据（source_id=0 且 source_type 非 chapter）
            DB::query("DELETE FROM novel_embeddings WHERE novel_id=? AND source_id=0 AND source_type IN ('worldbuilding','plot','style','character')", [$novelId]);
            
            $sid = 0;
            if (!empty($novel['world_settings'])) {
                $emb = EmbeddingProvider::embed($novel['world_settings']);
                if ($emb && !empty($emb['vec'])) {
                    DB::query(
                        "REPLACE INTO novel_embeddings (novel_id, source_type, source_id, content, embedding_blob, embedding_model) VALUES (?, ?, ?, ?, ?, ?)",
                        [$novelId, 'worldbuilding', $sid, $novel['world_settings'], Vector::pack($emb['vec']), $emb['model'] ?? '']
                    );
                    $steps[] = '✅ 已将全局世界观设定写入向量';
                }
            }
            if (!empty($novel['plot_settings'])) {
                $emb = EmbeddingProvider::embed($novel['plot_settings']);
                if ($emb && !empty($emb['vec'])) {
                    DB::query(
                        "REPLACE INTO novel_embeddings (novel_id, source_type, source_id, content, embedding_blob, embedding_model) VALUES (?, ?, ?, ?, ?, ?)",
                        [$novelId, 'plot', $sid, $novel['plot_settings'], Vector::pack($emb['vec']), $emb['model'] ?? '']
                    );
                    $steps[] = '✅ 已将全局情节设定写入向量';
                }
            }
            if (!empty($novel['writing_style'])) {
                $emb = EmbeddingProvider::embed($novel['writing_style']);
                if ($emb && !empty($emb['vec'])) {
                    DB::query(
                        "REPLACE INTO novel_embeddings (novel_id, source_type, source_id, content, embedding_blob, embedding_model) VALUES (?, ?, ?, ?, ?, ?)",
                        [$novelId, 'style', $sid, $novel['writing_style'], Vector::pack($emb['vec']), $emb['model'] ?? '']
                    );
                    $steps[] = '✅ 已将写作风格写入向量';
                }
            }
            if (!empty($novel['protagonist_info'])) {
                $emb = EmbeddingProvider::embed("角色名：{$novel['protagonist_name']}\n{$novel['protagonist_info']}");
                if ($emb && !empty($emb['vec'])) {
                    DB::query(
                        "REPLACE INTO novel_embeddings (novel_id, source_type, source_id, content, embedding_blob, embedding_model) VALUES (?, ?, ?, ?, ?, ?)",
                        [$novelId, 'character', $sid, "{$novel['protagonist_name']}: {$novel['protagonist_info']}", Vector::pack($emb['vec']), $emb['model'] ?? '']
                    );
                    $steps[] = '✅ 已将主角信息写入向量';
                }
            }
        }
    } catch (Throwable $e2) {
        $steps[] = '❌ 备用方案也失败: ' . $e2->getMessage();
    }
}

// ====== 4. 验证最终状态 ======
$embCount = (int)DB::fetchColumn("SELECT COUNT(*) FROM novel_embeddings WHERE novel_id=? AND embedding_blob IS NOT NULL", [$novelId]);
$atomVec = (int)DB::fetchColumn("SELECT COUNT(*) FROM memory_atoms WHERE novel_id=? AND embedding IS NOT NULL", [$novelId]);
$charCount = (int)DB::fetchColumn("SELECT COUNT(*) FROM novel_characters WHERE novel_id=?", [$novelId]);

$steps[] = "📊 最终状态：novel_embeddings={$embCount}条, memory_atoms向量={$atomVec}条, 角色={$charCount}个";

// ====== 5. 模拟语义召回测试 ======
if ($embCount > 0 || $atomVec > 0) {
    try {
        // 用真实查询文本：取最近章节的标题+大纲
        $testChapter = DB::fetch(
            'SELECT title, outline FROM chapters WHERE novel_id=? AND status="completed" ORDER BY chapter_number DESC LIMIT 1',
            [$novelId]
        );
        $queryText = '测试查询';
        if ($testChapter) {
            $queryText = trim(($testChapter['title'] ?? '') . '：' . ($testChapter['outline'] ?? ''));
            if (empty($queryText)) $queryText = '测试查询';
        }
        $steps[] = '🔍 语义召回测试查询: ' . mb_substr($queryText, 0, 60) . '...';

        $engine = new MemoryEngine($novelId);
        $testChapterNum = (int)($testChapter ? DB::fetchColumn('SELECT chapter_number FROM chapters WHERE novel_id=? AND status="completed" ORDER BY chapter_number DESC LIMIT 1', [$novelId]) : 1);
        $steps[] = "📝 模拟写第" . ($testChapterNum + 1) . "章（beforeChapter={$testChapterNum}）";

        $ctx = $engine->getPromptContext($testChapterNum + 1, $queryText, 6000, 20, 8);
        $hitCount = count($ctx['semantic_hits'] ?? []);
        $steps[] = $hitCount > 0 
            ? "🎉 语义召回测试成功！命中 {$hitCount} 条" 
            : '⚠️ 语义召回执行了但未命中';
        if (!empty($ctx['debug']['semantic_error'])) {
            $steps[] = '⚠️ semantic_error: ' . $ctx['debug']['semantic_error'];
        }
        if (!empty($ctx['debug']['dropped'])) {
            $steps[] = '⚠️ budget裁剪: ' . implode('; ', $ctx['debug']['dropped']);
        }
        $steps[] = '📊 budget: used=' . ($ctx['debug']['budget_used'] ?? '?') . '/total=' . ($ctx['debug']['budget_total'] ?? '?');
        // 额外 debug：直接调 semanticSearch 看中间结果
        try {
            $ref = new ReflectionMethod($engine, 'semanticSearch');
            $ref->setAccessible(true);
            $rawHits = $ref->invoke($engine, $queryText, 8, $testChapterNum + 1, true);
            $steps[] = '🔧 semanticSearch 直接调用返回: ' . count($rawHits) . ' 条';
            if (!empty($rawHits)) {
                $sample = array_slice($rawHits, 0, 3);
                foreach ($sample as $i => $h) {
                    $steps[] = "  hit#{$i}: source={$h['source']} type={$h['type']} score={$h['score']} via={$h['via']} content=" . mb_substr($h['content'], 0, 40);
                }
            }
        } catch (Throwable $e3) {
            $steps[] = '🔧 semanticSearch 直接调用失败: ' . $e3->getMessage();
        }

        // 诊断：手动计算 top 相似度分数（不带阈值）
        $qEmb = EmbeddingProvider::embed($queryText);
        if ($qEmb && !empty($qEmb['vec'])) {
            $topScores = [];

            // 检查 KB 向量
            $kbCandidates = DB::fetchAll(
                "SELECT source_id AS id, source_type, content, embedding_blob AS `blob`
                 FROM novel_embeddings WHERE novel_id=? AND source_type IN ('character','worldbuilding','plot','style')",
                [$novelId]
            );
            if (!empty($kbCandidates)) {
                $allScored = [];
                foreach ($kbCandidates as $row) {
                    if (empty($row['blob'])) continue;
                    try {
                        $vec = Vector::unpack($row['blob']);
                        $score = Vector::cosine($qEmb['vec'], $vec);
                        $allScored[] = ['type' => $row['source_type'], 'score' => $score, 'content' => mb_substr($row['content'], 0, 30)];
                    } catch (Throwable $e) { continue; }
                }
                usort($allScored, fn($a, $b) => $b['score'] <=> $a['score']);
                $top5 = array_slice($allScored, 0, 5);
                foreach ($top5 as $i => $s) {
                    $topScores[] = "KB#{$i}: {$s['type']} score={$s['score']} 「{$s['content']}...」";
                }
            }

            // 检查 atom 向量
            $atomCandidates = DB::fetchAll(
                "SELECT id, atom_type, content, embedding AS `blob` FROM memory_atoms WHERE novel_id=? AND embedding IS NOT NULL",
                [$novelId]
            );
            if (!empty($atomCandidates)) {
                $allScored = [];
                foreach ($atomCandidates as $row) {
                    if (empty($row['blob'])) continue;
                    try {
                        $vec = Vector::unpack($row['blob']);
                        $score = Vector::cosine($qEmb['vec'], $vec);
                        $allScored[] = ['type' => $row['atom_type'], 'score' => $score, 'content' => mb_substr($row['content'], 0, 30)];
                    } catch (Throwable $e) { continue; }
                }
                usort($allScored, fn($a, $b) => $b['score'] <=> $a['score']);
                $top5 = array_slice($allScored, 0, 5);
                foreach ($top5 as $i => $s) {
                    $topScores[] = "Atom#{$i}: {$s['type']} score={$s['score']} 「{$s['content']}...」";
                }
            }

            if (!empty($topScores)) {
                $steps[] = '📊 相似度诊断（Top5）：' . implode(' | ', $topScores);
            } else {
                $steps[] = '❌ 无法计算任何相似度（向量数据可能损坏）';
            }
        }
    } catch (Throwable $e) {
        $steps[] = '❌ 语义召回测试失败: ' . $e->getMessage();
    }
} else {
    $steps[] = '❌ 没有任何向量数据，语义召回无法工作';
}

$steps[] = $embCount > 0 || $atomVec > 0
    ? '✅ 下次写章节时语义召回应该生效了！如需更多数据，运行 rebuild_embeddings.php'
    : '❌ 仍无向量数据，请检查 extractFromChapter 的 AI 调用是否成功';

echo json_encode(['ok' => true, 'steps' => $steps], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
