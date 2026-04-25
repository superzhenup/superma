<?php
/**
 * 语义召回诊断工具
 * 检查所有语义召回依赖的条件，一步定位问题
 * 
 * 使用方式：GET /api/diagnose_embedding.php?novel_id=1
 * 部署后请删除此文件
 */
define('APP_LOADED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/error_handler.php';
registerApiErrorHandlers();
require_once dirname(__DIR__) . '/includes/auth.php';
requireLoginApi();
require_once dirname(__DIR__) . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');

$novelId = (int)($_GET['novel_id'] ?? 0);
$checks = [];

// ====== 检查1: system_settings.global_embedding_model_id ======
$settingRow = DB::fetch("SELECT setting_value FROM system_settings WHERE setting_key='global_embedding_model_id'");
$settingValue = $settingRow ? $settingRow['setting_value'] : null;
$checks[] = [
    'step' => '1. global_embedding_model_id 配置',
    'status' => $settingValue ? 'OK' : 'FAIL',
    'value' => $settingValue ?? '(空)',
    'fix'   => $settingValue ? null : '在 settings.php 页面配置 Embedding 模型，或执行: INSERT INTO system_settings (setting_key, setting_value) VALUES ("global_embedding_model_id", "1");',
];

// ====== 检查2: ai_models 表对应记录 can_embed ======
if ($settingValue) {
    $model = DB::fetch('SELECT id, name, can_embed, api_key, api_url, embedding_model_name, embedding_dim FROM ai_models WHERE id=?', [(int)$settingValue]);
    if (!$model) {
        $checks[] = [
            'step' => '2. ai_models 记录',
            'status' => 'FAIL',
            'value' => "id={$settingValue} 的记录不存在",
            'fix'   => '在 settings.php 页面重新添加 embedding 模型',
        ];
    } else {
        $checks[] = [
            'step' => '2. ai_models 记录',
            'status' => ($model['can_embed'] == 1 && !empty($model['api_key'])) ? 'OK' : 'FAIL',
            'value' => "id={$model['id']} name={$model['name']} can_embed={$model['can_embed']} api_key=" . (empty($model['api_key']) ? '(空)' : '已配置') . " api_url={$model['api_url']} embedding_model={$model['embedding_model_name']} dim={$model['embedding_dim']}",
            'fix'   => ($model['can_embed'] != 1) ? '执行: UPDATE ai_models SET can_embed=1 WHERE id=' . $model['id'] : (empty($model['api_key']) ? 'api_key 未配置' : null),
        ];
    }
}

// ====== 检查2.5: 列出所有 ai_models，便于排查自动修复匹配 ======
$allModels = DB::fetchAll('SELECT id, name, can_embed, api_url, embedding_enabled, embedding_model_name FROM ai_models ORDER BY id');
$checks[] = [
    'step' => '2.5. 所有 ai_models 列表',
    'status' => 'INFO',
    'value' => array_map(function($m) {
        $isArk = stripos($m['api_url'], 'ark.cn-beijing.volces.com') !== false;
        return "id={$m['id']} name={$m['name']} can_embed={$m['can_embed']} embedding_enabled={$m['embedding_enabled']} isArk=" . ($isArk ? 'YES' : 'NO') . " api_url={$m['api_url']}";
    }, $allModels),
    'fix'   => null,
];

// ====== 检查3: EmbeddingProvider::getConfig() ======
require_once dirname(__DIR__) . '/includes/memory/EmbeddingProvider.php';
$cfg = EmbeddingProvider::getConfig();
$checks[] = [
    'step' => '3. EmbeddingProvider::getConfig()',
    'status' => $cfg ? 'OK' : 'FAIL',
    'value' => $cfg ? json_encode($cfg, JSON_UNESCAPED_UNICODE) : '返回 null',
    'fix'   => $cfg ? null : '检查前两步是否通过',
];

// ====== 检查4: API 实际调用测试 ======
if ($cfg) {
    require_once dirname(__DIR__) . '/includes/memory/Vector.php';
    $testResult = EmbeddingProvider::embed('测试文本');
    $checks[] = [
        'step' => '4. Embedding API 实际调用',
        'status' => $testResult ? 'OK' : 'FAIL',
        'value' => $testResult ? "成功 dim={$testResult['dim']} model={$testResult['model']}" : '调用失败',
        'fix'   => $testResult ? null : '检查 api_key 和 api_url 是否正确，以及模型名称是否有效',
    ];
}

// ====== 检查5: novel_embeddings 数据量 ======
if ($novelId > 0) {
    $embStats = DB::fetch(
        'SELECT COUNT(*) AS total, SUM(CASE WHEN embedding_blob IS NOT NULL THEN 1 ELSE 0 END) AS with_vec FROM novel_embeddings WHERE novel_id=?',
        [$novelId]
    );
    $checks[] = [
        'step' => '5. novel_embeddings 数据',
        'status' => ((int)$embStats['with_vec'] > 0) ? 'OK' : 'WARN',
        'value' => "total={$embStats['total']}, with_vec={$embStats['with_vec']}",
        'fix'   => ((int)$embStats['with_vec'] > 0) ? null : '知识库向量数据为空！需要写完章节后自动提取，或调用 POST /api/rebuild_embeddings.php {novel_id:' . $novelId . '} 重建',
    ];

    // ====== 检查6: memory_atoms 数据量 ======
    $atomStats = DB::fetch(
        'SELECT COUNT(*) AS total, SUM(CASE WHEN embedding IS NOT NULL THEN 1 ELSE 0 END) AS with_vec FROM memory_atoms WHERE novel_id=?',
        [$novelId]
    );
    $checks[] = [
        'step' => '6. memory_atoms 数据',
        'status' => ((int)$atomStats['with_vec'] > 0) ? 'OK' : 'WARN',
        'value' => "total={$atomStats['total']}, with_vec={$atomStats['with_vec']}",
        'fix'   => ((int)$atomStats['with_vec'] > 0) ? null : '记忆原子无向量！需要写完章节后 ensureEmbeddings 自动补齐，或手动触发',
    ];

    // ====== 检查7: 知识库基础表数据 ======
    $charCount = (int)DB::fetchColumn("SELECT COUNT(*) FROM novel_characters WHERE novel_id=?", [$novelId]);
    $worldCount = (int)DB::fetchColumn("SELECT COUNT(*) FROM novel_worldbuilding WHERE novel_id=?", [$novelId]);
    $plotCount = (int)DB::fetchColumn("SELECT COUNT(*) FROM novel_plots WHERE novel_id=?", [$novelId]);
    $styleCount = (int)DB::fetchColumn("SELECT COUNT(*) FROM novel_style WHERE novel_id=?", [$novelId]);
    $checks[] = [
        'step' => '7. 知识库基础表',
        'status' => ($charCount + $worldCount + $plotCount + $styleCount > 0) ? 'OK' : 'WARN',
        'value' => "characters={$charCount}, worldbuilding={$worldCount}, plots={$plotCount}, styles={$styleCount}",
        'fix'   => ($charCount + $worldCount + $plotCount + $styleCount > 0) ? null : '知识库表全为空！说明 extractFromChapter 从未成功执行，或章节尚未写完',
    ];

    // ====== 检查8: 已完成章节数 ======
    $completedChapters = (int)DB::fetchColumn("SELECT COUNT(*) FROM chapters WHERE novel_id=? AND status='completed'", [$novelId]);
    $checks[] = [
        'step' => '8. 已完成章节',
        'status' => ($completedChapters > 0) ? 'OK' : 'WARN',
        'value' => "completed_chapters={$completedChapters}",
        'fix'   => ($completedChapters > 0) ? null : '还没有完成的章节，语义召回需要至少1章完成后才有数据',
    ];
}

// ====== 总结 ======
$failCount = count(array_filter($checks, fn($c) => $c['status'] === 'FAIL'));
$warnCount = count(array_filter($checks, fn($c) => $c['status'] === 'WARN'));

echo json_encode([
    'ok' => true,
    'summary' => "FAIL:{$failCount} WARN:{$warnCount} OK:" . (count($checks) - $failCount - $warnCount),
    'verdict' => $failCount > 0 
        ? '语义召回无法启用：存在关键配置缺失' 
        : ($warnCount > 0 
            ? '语义召回配置OK但数据不足，需先写完章节' 
            : '语义召回应该正常工作'),
    'checks' => $checks,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
