<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/data.php';
requireLoginApi();

header('Content-Type: application/json');

$action = $_GET['action'] ?? 'get';
$novelId = isset($_GET['novel_id']) ? (int)$_GET['novel_id'] : 0;

try {
    switch ($action) {
        case 'get':
            echo json_encode([
                'success' => true,
                'data' => getIterativeConfig($novelId),
            ]);
            break;

        case 'update':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('必须使用 POST 方法');
            }

            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                throw new Exception('无效的 JSON 数据');
            }

            $updateResult = updateIterativeConfig($novelId, $input);
            if ($updateResult === true) {
                echo json_encode([
                    'success' => true,
                    'message' => '配置更新成功',
                    'data' => getIterativeConfig($novelId),
                ]);
            } else {
                $errMsg = is_string($updateResult) ? $updateResult : '配置更新失败';
                echo json_encode([
                    'success' => false,
                    'error' => $errMsg,
                    'message' => $errMsg,
                ]);
            }
            break;

        case 'defaults':
            $defs = getDefaultIterativeConfig();
            echo json_encode([
                'success' => true,
                'data' => [
                    'rewrite' => normalizeConfigValues($defs['rewrite'], []),
                    'iterative_refinement' => normalizeConfigValues($defs['iterative_refinement'], []),
                ],
            ]);
            break;

        case 'history':
            echo json_encode([
                'success' => true,
                'data' => getRewriteHistoryStats($novelId),
            ]);
            break;

        case 'recommendations':
            echo json_encode([
                'success' => true,
                'data' => getParameterRecommendations($novelId),
            ]);
            break;

        default:
            throw new Exception('未知的操作：' . $action);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}

/**
 * 获取迭代改进配置
 */
function getIterativeConfig(int $novelId): array
{
    $defaults = getDefaultIterativeConfig();
    $novelSettings = getNovelSettings($novelId);

    return [
        'novel_id' => $novelId,
        'rewrite' => normalizeConfigValues($defaults['rewrite'], $novelSettings['rewrite'] ?? []),
        'iterative_refinement' => normalizeConfigValues(
            $defaults['iterative_refinement'],
            $novelSettings['iterative_refinement'] ?? []
        ),
        'is_customized' => !empty($novelSettings),
    ];
}

function normalizeConfigValues(array $defaults, array $overrides): array
{
    $result = [];
    foreach ($defaults as $key => $def) {
        if (is_array($def) && isset($def['value'])) {
            $result[$key] = array_key_exists($key, $overrides) ? $overrides[$key] : $def['value'];
        } else {
            $result[$key] = array_key_exists($key, $overrides) ? $overrides[$key] : $def;
        }
    }
    foreach ($overrides as $key => $val) {
        if (!isset($result[$key])) {
            $result[$key] = $val;
        }
    }
    return $result;
}

/**
 * 获取默认配置
 */
function getDefaultIterativeConfig(): array
{
    return [
        'rewrite' => [
            'enabled' => [
                'value' => false,
                'type' => 'boolean',
                'label' => '启用自动重写',
                'description' => '章节质量低于阈值时自动进行迭代改进',
                'group' => 'basic',
            ],
            'threshold' => [
                'value' => 70,
                'type' => 'number',
                'label' => '重写触发阈值',
                'description' => '质量分数低于此值时触发重写（范围：50-100）',
                'min' => 50,
                'max' => 100,
                'group' => 'basic',
            ],
            'min_gain' => [
                'value' => 10,
                'type' => 'number',
                'label' => '最低质量提升',
                'description' => '重写后质量分数必须提升此值才会采纳（范围：1-30）',
                'min' => 1,
                'max' => 30,
                'group' => 'basic',
            ],
            'iterative_mode' => [
                'value' => true,
                'type' => 'boolean',
                'label' => '使用迭代模式',
                'description' => '启用多轮迭代改进（最多3轮），比单次重写效果更好但更耗时',
                'group' => 'advanced',
            ],
            'use_critic_agent' => [
                'value' => true,
                'type' => 'boolean',
                'label' => '使用读者视角评估',
                'description' => '整合 CriticAgent 的读者视角评分，多维度评估质量',
                'group' => 'advanced',
            ],
            'style_guard_enabled' => [
                'value' => true,
                'type' => 'boolean',
                'label' => 'AI痕迹检测',
                'description' => '启用 StyleGuard 检测 AI 写作痕迹（如"总的来说""值得注意的是"等套路化表达）',
                'group' => 'advanced',
            ],
            'ai_patterns_check_enabled' => [
                'value' => true,
                'type' => 'boolean',
                'label' => 'AI套路模式检测',
                'description' => '检测常见 AI 写作模式（如过度使用形容词堆砌、机械式过渡等）',
                'group' => 'advanced',
            ],
        ],
        'iterative_refinement' => [
            'max_iterations' => [
                'value' => 3,
                'type' => 'number',
                'label' => '最大迭代次数',
                'description' => '最多进行多少轮迭代改进（范围：1-5）',
                'min' => 1,
                'max' => 5,
                'group' => 'iterations',
            ],
            'min_improvement' => [
                'value' => 5.0,
                'type' => 'number',
                'label' => '最小提升阈值',
                'description' => '单轮迭代必须达到的最小质量提升，否则提前终止（范围：1-20）',
                'min' => 1,
                'max' => 20,
                'step' => 0.5,
                'group' => 'iterations',
            ],
            'target_score' => [
                'value' => 80.0,
                'type' => 'number',
                'label' => '目标质量分数',
                'description' => '达到此分数后停止迭代（范围：60-100）',
                'min' => 60,
                'max' => 100,
                'step' => 0.5,
                'group' => 'iterations',
            ],
            'quality_decline_threshold' => [
                'value' => 3.0,
                'type' => 'number',
                'label' => '质量下降容忍度',
                'description' => '如果单轮改进导致质量下降超过此值，停止迭代（范围：1-10）',
                'min' => 1,
                'max' => 10,
                'step' => 0.5,
                'group' => 'iterations',
            ],
        ],
    ];
}

/**
 * 获取小说自定义设置
 */
function getNovelSettings(int $novelId): array
{
    if ($novelId < 0) {
        return [];
    }

    try {
        $rows = DB::fetchAll(
            'SELECT setting_key, setting_value FROM iterative_settings WHERE novel_id = ?',
            [$novelId]
        );

        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = json_decode($row['setting_value'], true);
        }

        return $settings;
    } catch (Exception $e) {
        error_log('获取小说设置失败：' . $e->getMessage());
        return [];
    }
}

/**
 * 更新迭代改进配置
 *
 * @return true|string 成功返回 true，失败返回错误信息字符串
 */
function updateIterativeConfig(int $novelId, array $input)
{
    try {
        DB::beginTransaction();

        // 更新 rewrite 设置
        if (isset($input['rewrite'])) {
            saveSetting($novelId, 'rewrite', $input['rewrite']);
        }

        // 更新 iterative_refinement 设置
        if (isset($input['iterative_refinement'])) {
            saveSetting($novelId, 'iterative_refinement', $input['iterative_refinement']);
        }

        DB::commit();

        // 记录配置变更日志
        try {
            addLog($novelId, 'config', sprintf(
                '更新迭代改进配置：rewrite=%s, iterative_refinement=%s',
                json_encode($input['rewrite'] ?? []),
                json_encode($input['iterative_refinement'] ?? [])
            ));
        } catch (\Throwable $e) {
            error_log('迭代配置日志记录失败：' . $e->getMessage());
        }

        return true;
    } catch (\Throwable $e) {
        try { DB::rollBack(); } catch (\Throwable) {}
        error_log('更新迭代配置失败：' . $e->getMessage());
        return '更新失败：' . $e->getMessage();
    }
}

/**
 * 保存单个设置
 */
function saveSetting(int $novelId, string $key, array $values): void
{
    $settingValue = [];

    foreach ($values as $settingKey => $value) {
        if (is_array($value) && isset($value['value'])) {
            $settingValue[$settingKey] = $value['value'];
        } else {
            $settingValue[$settingKey] = $value;
        }
    }

    $jsonValue = json_encode($settingValue, JSON_UNESCAPED_UNICODE);

    // 检查是否存在
    $existing = DB::fetch(
        'SELECT id FROM iterative_settings WHERE novel_id = ? AND setting_key = ?',
        [$novelId, $key]
    );

    if ($existing) {
        DB::update('iterative_settings', [
            'setting_value' => $jsonValue,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id=?', [$existing['id']]);
    } else {
        DB::insert('iterative_settings', [
            'novel_id' => $novelId,
            'setting_key' => $key,
            'setting_value' => $jsonValue,
            'description' => $key === 'rewrite' ? '章节重写配置' : '迭代改进配置',
            'is_system' => 1,
        ]);
    }

    if ($key === 'rewrite') {
        $rewriteEnabled = !empty($settingValue['enabled']);
        $existingSS = DB::fetch(
            'SELECT setting_key FROM system_settings WHERE setting_key=?',
            ['ws_rewrite_enabled']
        );
        if ($existingSS) {
            DB::update('system_settings', [
                'setting_value' => $rewriteEnabled ? '1' : '0',
            ], 'setting_key=?', ['ws_rewrite_enabled']);
        } else {
            DB::insert('system_settings', [
                'setting_key' => 'ws_rewrite_enabled',
                'setting_value' => $rewriteEnabled ? '1' : '0',
            ]);
        }

        $criticEnabled = !empty($settingValue['use_critic_agent']);
        $existingCritic = DB::fetch(
            'SELECT setting_key FROM system_settings WHERE setting_key=?',
            ['ws_critic_enabled']
        );
        if ($existingCritic) {
            DB::update('system_settings', [
                'setting_value' => $criticEnabled ? '1' : '0',
            ], 'setting_key=?', ['ws_critic_enabled']);
        } else {
            DB::insert('system_settings', [
                'setting_key' => 'ws_critic_enabled',
                'setting_value' => $criticEnabled ? '1' : '0',
            ]);
        }

        // StyleGuard 开关
        $styleGuardEnabled = !empty($settingValue['style_guard_enabled']);
        $existingStyleGuard = DB::fetch(
            'SELECT setting_key FROM system_settings WHERE setting_key=?',
            ['ws_style_guard_enabled']
        );
        if ($existingStyleGuard) {
            DB::update('system_settings', [
                'setting_value' => $styleGuardEnabled ? '1' : '0',
            ], 'setting_key=?', ['ws_style_guard_enabled']);
        } else {
            DB::insert('system_settings', [
                'setting_key' => 'ws_style_guard_enabled',
                'setting_value' => $styleGuardEnabled ? '1' : '0',
            ]);
        }

        // AI套路模式检测开关
        $aiPatternsEnabled = !empty($settingValue['ai_patterns_check_enabled']);
        $existingAiPatterns = DB::fetch(
            'SELECT setting_key FROM system_settings WHERE setting_key=?',
            ['ws_ai_patterns_check_enabled']
        );
        if ($existingAiPatterns) {
            DB::update('system_settings', [
                'setting_value' => $aiPatternsEnabled ? '1' : '0',
            ], 'setting_key=?', ['ws_ai_patterns_check_enabled']);
        } else {
            DB::insert('system_settings', [
                'setting_key' => 'ws_ai_patterns_check_enabled',
                'setting_value' => $aiPatternsEnabled ? '1' : '0',
            ]);
        }
    }
}

/**
 * 获取重写历史统计
 */
function getRewriteHistoryStats(int $novelId): array
{
    if ($novelId <= 0) {
        return ['error' => '需要提供有效的小说ID'];
    }

    try {
        // 获取所有重写过的章节
        $chapters = DB::fetchAll(
            'SELECT id, chapter_number, title, quality_score, iterations_used,
                    total_improvement, iterative_history, created_at
             FROM chapters
             WHERE novel_id = ? AND rewritten = 1
             ORDER BY chapter_number DESC',
            [$novelId]
        );

        if (empty($chapters)) {
            return [
                'total_chapters_rewritten' => 0,
                'stats' => null,
                'recommendations' => [],
            ];
        }

        // 计算统计数据
        $stats = calculateRewriteStats($chapters);

        // 生成优化建议
        $recommendations = generateOptimizationRecommendations($stats);

        return [
            'total_chapters_rewritten' => count($chapters),
            'stats' => $stats,
            'recommendations' => $recommendations,
            'recent_rewrites' => array_map(function ($ch) {
                return [
                    'chapter_number' => $ch['chapter_number'],
                    'title' => $ch['title'],
                    'final_score' => $ch['quality_score'],
                    'iterations_used' => $ch['iterations_used'] ?? 1,
                    'total_improvement' => $ch['total_improvement'] ?? 0,
                ];
            }, array_slice($chapters, 0, 10)),
        ];
    } catch (Exception $e) {
        error_log('获取重写历史失败：' . $e->getMessage());
        return ['error' => '获取数据失败：' . $e->getMessage()];
    }
}

/**
 * 计算重写统计
 */
function calculateRewriteStats(array $chapters): array
{
    $iterations = array_column($chapters, 'iterations_used');
    $improvements = array_column($chapters, 'total_improvement');
    $scores = array_column($chapters, 'quality_score');

    $singleRewrite = count(array_filter($iterations, fn($i) => $i == 1));
    $iterativeRewrite = count($chapters) - $singleRewrite;

    return [
        'total_chapters' => count($chapters),
        'single_rewrite_count' => $singleRewrite,
        'iterative_rewrite_count' => $iterativeRewrite,
        'iterations' => [
            'avg' => count($iterations) > 0 ? round(array_sum($iterations) / count($iterations), 2) : 0,
            'max' => max($iterations ?: [1]),
            'min' => min($iterations ?: [1]),
        ],
        'improvement' => [
            'avg' => count($improvements) > 0 ? round(array_sum($improvements) / count($improvements), 2) : 0,
            'max' => max($improvements ?: [0]),
            'min' => min($improvements ?: [0]),
            'total' => array_sum($improvements),
        ],
        'final_scores' => [
            'avg' => count($scores) > 0 ? round(array_sum($scores) / count($scores), 2) : 0,
            'above_80' => count(array_filter($scores, fn($s) => $s >= 80)),
            'below_70' => count(array_filter($scores, fn($s) => $s < 70)),
        ],
    ];
}

/**
 * 生成优化建议
 */
function generateOptimizationRecommendations(array $stats): array
{
    $recommendations = [];

    // 基于迭代次数的建议
    if ($stats['iterative_rewrite_count'] > $stats['single_rewrite_count']) {
        $recommendations[] = [
            'type' => 'success',
            'category' => 'mode',
            'message' => '迭代模式使用率较高，说明章节质量问题需要多轮改进',
            'suggestion' => '建议保持当前配置，可以考虑适当提高 max_iterations 到 4',
        ];
    }

    // 基于平均提升的建议
    if ($stats['improvement']['avg'] < 5) {
        $recommendations[] = [
            'type' => 'warning',
            'category' => 'threshold',
            'message' => "平均提升分数较低（{$stats['improvement']['avg']}分）",
            'suggestion' => '建议降低 min_gain 阈值到 3-5，或检查问题识别的准确性',
        ];
    } elseif ($stats['improvement']['avg'] >= 10) {
        $recommendations[] = [
            'type' => 'success',
            'category' => 'performance',
            'message' => "平均提升分数优秀（{$stats['improvement']['avg']}分）",
            'suggestion' => '迭代策略效果良好，可以考虑推广到其他小说',
        ];
    }

    // 基于最终分数的建议
    if ($stats['final_scores']['below_70'] > $stats['total_chapters'] * 0.3) {
        $recommendations[] = [
            'type' => 'error',
            'category' => 'target',
            'message' => "{$stats['final_scores']['below_70']}个章节最终分数仍低于70分",
            'suggestion' => '建议降低 target_score 阈值到 70，或检查章节本身的问题',
        ];
    }

    // 基于平均迭代次数的建议
    if ($stats['iterations']['avg'] > 2.5) {
        $recommendations[] = [
            'type' => 'info',
            'category' => 'iterations',
            'message' => "平均迭代次数较高（{$stats['iterations']['avg']}次）",
            'suggestion' => '建议优化每轮迭代的改进策略，避免无效迭代',
        ];
    }

    return $recommendations;
}

/**
 * 获取参数优化建议
 */
function getParameterRecommendations(int $novelId): array
{
    $stats = getRewriteHistoryStats($novelId);

    if (isset($stats['error'])) {
        return [
            'has_data' => false,
            'message' => '没有足够的重写历史数据，无法生成建议',
            'recommendations' => [],
        ];
    }

    $recommendations = [];

    // 分析当前配置的表现
    if ($stats['stats']['improvement']['avg'] < 5) {
        $recommendations[] = [
            'priority' => 'high',
            'parameter' => 'min_gain',
            'current' => '10',
            'suggested' => '5',
            'reason' => '平均提升较低，可能因为提升总是达不到阈值而被拒绝',
        ];
    }

    if ($stats['stats']['iterations']['avg'] > 2) {
        $recommendations[] = [
            'priority' => 'medium',
            'parameter' => 'max_iterations',
            'current' => '3',
            'suggested' => '2',
            'reason' => '平均迭代次数偏高，说明后续迭代收益有限',
        ];
    }

    if ($stats['stats']['final_scores']['below_70'] > 0) {
        $recommendations[] = [
            'priority' => 'medium',
            'parameter' => 'target_score',
            'current' => '80',
            'suggested' => '75',
            'reason' => '部分章节即使经过迭代也无法达到80分目标',
        ];
    }

    return [
        'has_data' => true,
        'total_chapters_analyzed' => $stats['total_chapters_rewritten'],
        'recommendations' => $recommendations,
    ];
}
