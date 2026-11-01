<?php
define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/author/AuthorProfile.php';

function wsVal(string $key, array $defs, array $vals): string {
    if (isset($vals[$key])) return $vals[$key];
    return (string)($defs[$key]['default'] ?? '');
}

// 当前分页
$activeTab = $_GET['tab'] ?? 'params';

// ========== AI 模型列表（供迭代重写页选择重写模型） ==========
$aiModels = \DB::fetchAll('SELECT id, name, model_name FROM ai_models ORDER BY is_default DESC, id ASC');

// ========== 作者画像相关变量 ==========
$userId = $_SESSION['user_id'] ?? $_SESSION['uid'] ?? null;
$profileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ========== 迭代重写：小说级配置所需的小说列表 ==========
// 审计修复（2026-07-19 M3-NEW3 / H-中10）后，迭代重写配置按小说存储于 iterative_settings，
// 保存（action=update）必须携带有效的 novel_id 并通过归属校验。此处加载当前用户的
// 小说列表供"迭代重写"标签页选择目标小说。
$userNovels = \DB::fetchAll(
    'SELECT id, title FROM novels WHERE user_id = ? ORDER BY updated_at DESC',
    [$userId]
);
$irNovelId = isset($_GET['novel_id']) ? (int)$_GET['novel_id'] : 0;
if ($irNovelId > 0) {
    // 仅允许预选属于当前用户的小说
    $owned = array_filter($userNovels, fn($n) => (int)$n['id'] === $irNovelId);
    if (!$owned) {
        $irNovelId = 0;
    }
}
if ($irNovelId <= 0 && !empty($userNovels)) {
    $irNovelId = (int)$userNovels[0]['id'];
}
$profiles = AuthorProfile::findByUser($userId);

if ($activeTab === 'profile' && ($_GET['action'] ?? '') === 'edit' && $profileId > 0) {
    $profile = AuthorProfile::find($profileId);
    $profileData = $profile ? $profile->toArray() : null;
} else {
    $profileData = null;
}

// ========== 写作参数：从独立配置文件加载 ==========
$paramDefs = require __DIR__ . '/config/writing_params.php';

// 从集中配置同步默认值
$wsDefaults = getWritingDefaults();
foreach ($wsDefaults as $key => $def) {
    if (isset($paramDefs[$key])) {
        $paramDefs[$key]['default'] = $def['default'];
    }
}

// 读取当前值（用于 SSR 初始渲染）
$currentValues = [];
$keys = array_keys($paramDefs);
$placeholders = implode(',', array_fill(0, count($keys), '?'));
$rows = DB::fetchAll(
    "SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($placeholders)",
    $keys
);
foreach ($rows as $r) {
    $currentValues[$r['setting_key']] = $r['setting_value'];
}

// 按 section 分组
$sections = [];
foreach ($paramDefs as $key => $def) {
    $sec = $def['section'];
    if (!isset($sections[$sec])) {
        $sections[$sec] = [];
    }
    $sections[$sec][$key] = $def;
}

pageHeader('写作设置', 'writing_settings');
?>

<style>
    .upload-zone {
        border: 2px dashed #334155;
        border-radius: 1rem;
        padding: 3rem;
        text-align: center;
        cursor: pointer;
        transition: all 0.3s;
    }
    .upload-zone:hover, .upload-zone.dragover {
        border-color: #6366f1;
        background: rgba(99, 102, 241, 0.05);
    }
    .profile-card {
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .profile-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
    }
    .style-tag {
        background: rgba(99, 102, 241, 0.2);
        color: #a5b4fc;
        padding: 0.25rem 0.75rem;
        border-radius: 1rem;
        font-size: 0.875rem;
    }
    .ir-mode-btn {
        border-color: #334155;
        transition: all 0.2s;
        min-height: 100px;
        color: #e2e8f0;
    }
    .ir-mode-btn .fw-semibold {
        color: #f1f5f9 !important;
    }
    .ir-mode-btn .text-muted {
        color: #94a3b8 !important;
    }
    .ir-mode-btn:hover {
        border-color: #6366f1;
        background: rgba(99, 102, 241, 0.05);
    }
    .ir-mode-btn.active {
        border-color: #22c55e;
        background: rgba(34, 197, 94, 0.12);
        box-shadow: 0 0 0 1px #22c55e;
        color: #22c55e;
    }
    .ir-mode-btn.active .fw-semibold {
        color: #22c55e !important;
    }
    .ir-mode-btn.active .text-muted {
        color: #86efac !important;
    }
</style>

<!-- 分页标签 -->
<ul class="nav nav-tabs mb-4" id="settingTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $activeTab === 'params' ? 'active' : '' ?>" id="params-tab" data-bs-toggle="tab" data-bs-target="#params-tab-pane" type="button" role="tab">
            <i class="bi bi-sliders me-1"></i>基础生成参数
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $activeTab === 'iterative' ? 'active' : '' ?>" id="iterative-tab" data-bs-toggle="tab" data-bs-target="#iterative-tab-pane" type="button" role="tab">
            <i class="bi bi-arrow-repeat me-1"></i>迭代重写
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $activeTab === 'profile' ? 'active' : '' ?>" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile-tab-pane" type="button" role="tab">
            <i class="bi bi-person-badge me-1"></i>作者画像
        </button>
    </li>
</ul>

<div class="tab-content" id="settingTabsContent">
    <!-- ========== 基础生成参数页 ========== -->
    <div class="tab-pane fade <?= $activeTab === 'params' ? 'show active' : '' ?>" id="params-tab-pane" role="tabpanel">
        <div id="ws-params-container">

            <div class="row g-4">
                <div class="col-12 col-lg-8">
                    <?php foreach ($sections as $secName => $params): ?>
                    <div class="page-card mb-3"<?= $secName === '挂机写作调度' ? ' id="ws-section-daemon"' : '' ?>>
                        <div class="page-card-header">
                            <i class="bi bi-sliders me-2"></i><?= h($secName) ?>
                        </div>
                        <div class="p-3">
                            <?php foreach ($params as $key => $def): ?>
                            <?php $val = wsVal($key, $paramDefs, $currentValues); ?>
                            <div class="mb-4<?= !empty($def['deprecated']) ? ' opacity-50' : '' ?>">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label for="<?= h($key) ?>" class="form-label mb-0" style="font-size:.9rem">
                                        <?= h($def['label']) ?>
                                        <?php if (!empty($def['deprecated'])): ?>
                                        <span class="badge bg-warning text-dark ms-1" style="font-size:.7rem">已废弃</span>
                                        <?php endif; ?>
                                    </label>
                                    <span class="badge bg-secondary" style="font-size:.75rem">
                                        <?= h($val) ?><?= h($def['unit'] ?? '') ?>
                                    </span>
                                </div>

                                <?php if ($def['type'] === 'range'): ?>
                                <input type="range" class="form-range" id="<?= h($key) ?>" name="<?= h($key) ?>"
                                       min="<?= $def['min'] ?>" max="<?= $def['max'] ?>" step="<?= $def['step'] ?>"
                                       value="<?= h($val) ?>"
                                       data-csp-input="settings.parameter-badge" data-unit="<?= h($def['unit'] ?? '') ?>">
                                <?php elseif ($def['type'] === 'number'): ?>
                                <input type="number" class="form-control form-control-sm" id="<?= h($key) ?>" name="<?= h($key) ?>"
                                       min="<?= $def['min'] ?>" max="<?= $def['max'] ?>" step="<?= $def['step'] ?>"
                                       value="<?= h($val) ?>"<?= !empty($def['readonly']) ? ' readonly disabled' : '' ?>>
                                <?php elseif ($def['type'] === 'time'): ?>
                                <input type="time" class="form-control form-control-sm" id="<?= h($key) ?>" name="<?= h($key) ?>"
                                       step="60" value="<?= h($val) ?>">
                                <?php elseif ($def['type'] === 'select'): ?>
                                <select class="form-select form-select-sm" id="<?= h($key) ?>" name="<?= h($key) ?>">
                                  <?php foreach ($def['options'] as $optVal => $optLabel): ?>
                                  <option value="<?= h($optVal) ?>" <?= $val === (string)$optVal ? 'selected' : '' ?>><?= h($optLabel) ?></option>
                                  <?php endforeach; ?>
                                </select>
                                <?php else: ?>
                                <input type="text" class="form-control form-control-sm" id="<?= h($key) ?>" name="<?= h($key) ?>"
                                       value="<?= h($val) ?>">
                                <?php endif; ?>

                                <div class="form-text mt-1" style="font-size:.78rem;line-height:1.6">
                                  <?= h($def['desc']) ?>
                                </div>
                                <?php if (($def['action'] ?? '') === 'bump_harness_emergency_epoch'): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger mt-2" data-csp-click="settings.emergency-epoch">
                                    <i class="bi bi-exclamation-octagon me-1"></i>递增紧急停止代际
                                </button>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="col-12 col-lg-4">
                    <div class="page-card mb-3" style="position:sticky;top:1rem">
                        <div class="page-card-header">
                            <i class="bi bi-save me-2"></i>操作
                        </div>
                        <div class="p-3">
                            <button type="button" class="btn btn-primary w-100 mb-2" data-csp-click="settings.save-parameters">
                                <i class="bi bi-check-lg me-1"></i>保存所有参数
                            </button>
                            <button type="button" class="btn btn-outline-secondary w-100 mb-3" data-csp-click="settings.reset-parameters">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>重置为默认值
                            </button>

                            <hr class="border-secondary opacity-25">

                            <div class="small text-muted" style="line-height:1.7">
                                <div class="fw-semibold text-light mb-1"><i class="bi bi-info-circle me-1"></i>参数生效范围</div>
                                <p class="mb-2">所有参数均为<strong>全局默认</strong>，新建小说时会自动套用。已创建的小说不受影响。</p>
                                <p class="mb-2">如需对单部小说做个性化调整，请在小说详情页修改。</p>
                                <div class="fw-semibold text-light mb-1 mt-3"><i class="bi bi-lightbulb me-1"></i>推荐配置思路</div>
                                <ul class="ps-3 mb-0">
                                    <li><strong>快节奏爽文</strong>：爽点密度 1.0+，铺垫占比降至 15%</li>
                                    <li><strong>慢热种田文</strong>：爽点密度 0.6，铺垫占比提到 30%</li>
                                    <li><strong>悬疑推理</strong>：钩子占比提到 25%，伏笔回溯扩至 15 章</li>
                                    <li><strong>降低 Token 消耗</strong>：记忆回溯降至 3 章，语义检索降至 3 条</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="page-card mb-3">
                        <div class="page-card-header">
                            <i class="bi bi-bar-chart me-2"></i>当前参数概览
                        </div>
                        <div class="p-3">
                            <?php
                            $totalParams = count($paramDefs);
                            $customized   = 0;
                            foreach ($paramDefs as $key => $def) {
                                $v = wsVal($key, $paramDefs, $currentValues);
                                if ((string)$v !== (string)$def['default']) {
                                    $customized++;
                                }
                            }
                            ?>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted small">总参数项</span>
                                <span class="fw-semibold"><?= $totalParams ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted small">已自定义</span>
                                <span class="fw-semibold text-info"><?= $customized ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted small">使用默认值</span>
                                <span class="fw-semibold text-muted"><?= $totalParams - $customized ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ========== 迭代重写设置页 ========== -->
    <div class="tab-pane fade <?= $activeTab === 'iterative' ? 'show active' : '' ?>" id="iterative-tab-pane" role="tabpanel">
        <div class="row g-4">
            <div class="col-12 col-lg-8">
                <!-- 目标小说选择（配置按小说独立存储） -->
                <div class="page-card mb-3">
                    <div class="page-card-header">
                        <i class="bi bi-journal-bookmark me-2"></i>目标小说
                    </div>
                    <div class="p-3">
                        <?php if (empty($userNovels)): ?>
                            <div class="alert alert-warning-subtle border-0 small mb-0">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                迭代重写配置按小说独立保存。您还没有创建小说，请先<a href="index.php">创建小说</a>后再配置。
                            </div>
                        <?php else: ?>
                            <label class="form-label small text-muted" for="ir-novel-select">当前配置应用到哪部小说？各小说的迭代重写配置相互独立。</label>
                            <select class="form-select" id="ir-novel-select">
                                <?php foreach ($userNovels as $n): ?>
                                    <option value="<?= (int)$n['id'] ?>" <?= (int)$n['id'] === $irNovelId ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($n['title'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 迭代重写开关 -->
                <div class="page-card mb-3">
                    <div class="page-card-header">
                        <i class="bi bi-power me-2"></i>迭代重写开关
                    </div>
                    <div class="p-3">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div>
                                <div class="fw-semibold">启用自动迭代重写</div>
                                <div class="text-muted small">章节质量低于阈值时自动进行多轮迭代改进</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="ir-enabled"
                                       style="width:3rem;height:1.5rem">
                            </div>
                        </div>
                        <div class="alert alert-secondary-subtle border-0 small mb-0" id="ir-status-info">
                            <i class="bi bi-info-circle me-1"></i>
                            <span id="ir-status-text">加载中...</span>
                        </div>
                    </div>
                </div>

                <!-- 推荐配置模式 -->
                <div class="page-card mb-3">
                    <div class="page-card-header">
                        <i class="bi bi-magic me-2"></i>推荐配置模式
                    </div>
                    <div class="p-3">
                        <div class="text-muted small mb-3">选择一个预设模式，自动配置所有迭代改进参数。也可在下方自定义微调。</div>
                        <div class="row g-2" id="ir-modes">
                            <div class="col-6 col-md-3">
                                <button class="btn btn-outline-light w-100 h-100 text-start p-3 ir-mode-btn" data-mode="conservative">
                                    <div class="fw-semibold mb-1"><i class="bi bi-shield-check me-1 text-success"></i>保守模式</div>
                                    <div class="text-muted" style="font-size:.75rem">最少迭代、低Token消耗。适合预算有限或章节质量本身就不错的情况。</div>
                                </button>
                            </div>
                            <div class="col-6 col-md-3">
                                <button class="btn btn-outline-light w-100 h-100 text-start p-3 ir-mode-btn active" data-mode="balanced">
                                    <div class="fw-semibold mb-1"><i class="bi bi-graph-up me-1 text-primary"></i>均衡模式</div>
                                    <div class="text-muted" style="font-size:.75rem">性价比最优。3轮迭代、适中阈值，大多数网文推荐。</div>
                                </button>
                            </div>
                            <div class="col-6 col-md-3">
                                <button class="btn btn-outline-light w-100 h-100 text-start p-3 ir-mode-btn" data-mode="aggressive">
                                    <div class="fw-semibold mb-1"><i class="bi bi-lightning-charge me-1 text-warning"></i>深度优化</div>
                                    <div class="text-muted" style="font-size:.75rem">5轮迭代、高目标分。适合精品文、编辑推荐稿等对质量要求极高的场景。</div>
                                </button>
                            </div>
                            <div class="col-6 col-md-3">
                                <button class="btn btn-outline-light w-100 h-100 text-start p-3 ir-mode-btn" data-mode="fast">
                                    <div class="fw-semibold mb-1"><i class="bi bi-rocket-takeoff me-1 text-danger"></i>快速提升</div>
                                    <div class="text-muted" style="font-size:.75rem">2轮迭代、低提升门槛。快速见效，适合批量章节优化。</div>
                                </button>
                            </div>
                        </div>
                        <div id="ir-mode-desc" class="mt-3 small text-muted" style="display:none">
                            <i class="bi bi-check-circle me-1 text-success"></i>
                            <span id="ir-mode-desc-text"></span>
                        </div>
                    </div>
                </div>

                <!-- 重写基础配置 -->
                <div class="page-card mb-3">
                    <div class="page-card-header">
                        <i class="bi bi-gear me-2"></i>重写基础配置
                    </div>
                    <div class="p-3">
                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label mb-0" style="font-size:.9rem">重写触发阈值</label>
                                <span class="badge bg-secondary" id="ir-threshold-badge">70</span>
                            </div>
                            <input type="range" class="form-range" id="ir-threshold"
                                   min="50" max="100" step="5" value="70"
                                   data-csp-input="settings.iterative-badge" data-badge-id="ir-threshold-badge">
                            <div class="form-text mt-1" style="font-size:.78rem;line-height:1.6">
                                质量分数低于此值时触发重写。值越高，触发越频繁；值越低，只重写质量很差的章节。
                            </div>
                        </div>
                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label mb-0" style="font-size:.9rem">最低质量提升</label>
                                <span class="badge bg-secondary" id="ir-boost-badge">10</span>
                            </div>
                            <input type="range" class="form-range" id="ir-boost"
                                   min="1" max="30" step="1" value="10"
                                   data-csp-input="settings.iterative-badge" data-badge-id="ir-boost-badge">
                            <div class="form-text mt-1" style="font-size:.78rem;line-height:1.6">
                                重写后质量必须提升此分值才会采纳。值太低会导致无效重写被采纳，值太高会拒绝合理改进。
                            </div>
                        </div>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div>
                                <div class="fw-semibold" style="font-size:.9rem">使用读者视角评估</div>
                                <div class="text-muted small">整合 CriticAgent 五维读者视角评分，多维度评估质量</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="ir-critic"
                                       style="width:3rem;height:1.5rem">
                            </div>
                        </div>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div>
                                <div class="fw-semibold" style="font-size:.9rem">AI痕迹检测</div>
                                <div class="text-muted small">启用 StyleGuard 检测 AI 写作痕迹（如"总的来说""值得注意的是"等套路化表达）</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="ir-style-guard"
                                       style="width:3rem;height:1.5rem">
                            </div>
                        </div>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div>
                                <div class="fw-semibold" style="font-size:.9rem">AI套路模式检测</div>
                                <div class="text-muted small">检测常见 AI 写作模式（如过度使用形容词堆砌、机械式过渡等）</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="ir-ai-patterns"
                                       style="width:3rem;height:1.5rem">
                            </div>
                        </div>
                        <div class="mb-0">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <div class="fw-semibold" style="font-size:.9rem">重写专用模型</div>
                                    <div class="text-muted small">使用与正文写作不同的模型进行重写，消除同一模型的自审盲区</div>
                                </div>
                            </div>
                            <select class="form-select form-select-sm" id="ir-rewrite-model" style="max-width:320px">
                                <option value="0">默认（与正文写作使用相同模型）</option>
                                <?php foreach ($aiModels as $m): ?>
                                <option value="<?= $m['id'] ?>"><?= h($m['name']) ?> (<?= h($m['model_name']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text mt-1" style="font-size:.78rem;line-height:1.6">
                                推荐选择与正文模型不同厂商/版本的模型作为重写模型。确保两个模型在 <a href="settings.php" target="_blank">模型管理</a> 中均已配置可用的 API Key。
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 迭代改进高级参数 -->
                <div class="page-card mb-3">
                    <div class="page-card-header">
                        <i class="bi bi-sliders me-2"></i>迭代改进参数
                    </div>
                    <div class="p-3">
                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label mb-0" style="font-size:.9rem">最大迭代次数</label>
                                <span class="badge bg-secondary" id="ir-max-iter-badge">3</span>
                            </div>
                            <input type="range" class="form-range" id="ir-max-iter"
                                   min="1" max="5" step="1" value="3"
                                   data-csp-input="settings.iterative-badge" data-badge-id="ir-max-iter-badge">
                            <div class="form-text mt-1" style="font-size:.78rem;line-height:1.6">
                                每章最多进行几轮迭代改进。1轮=单次重写，3轮=标准迭代，5轮=深度打磨。每轮都会消耗 AI Token。
                            </div>
                        </div>
                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label mb-0" style="font-size:.9rem">单轮最小提升</label>
                                <span class="badge bg-secondary" id="ir-min-improve-badge">5.0</span>
                            </div>
                            <input type="range" class="form-range" id="ir-min-improve"
                                   min="1" max="20" step="0.5" value="5"
                                   data-csp-input="settings.iterative-badge" data-badge-id="ir-min-improve-badge" data-decimals="1">
                            <div class="form-text mt-1" style="font-size:.78rem;line-height:1.6">
                                单轮迭代必须达到的最小提升分值，否则提前终止。避免无意义的迭代消耗 Token。
                            </div>
                        </div>
                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label mb-0" style="font-size:.9rem">目标质量分数</label>
                                <span class="badge bg-secondary" id="ir-target-badge">80.0</span>
                            </div>
                            <input type="range" class="form-range" id="ir-target"
                                   min="60" max="100" step="0.5" value="80"
                                   data-csp-input="settings.iterative-badge" data-badge-id="ir-target-badge" data-decimals="1">
                            <div class="form-text mt-1" style="font-size:.78rem;line-height:1.6">
                                达到此分数后停止迭代。分数越高追求越极致，但迭代轮次和 Token 消耗也越多。
                            </div>
                        </div>
                        <div class="mb-2">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label mb-0" style="font-size:.9rem">质量下降容忍度</label>
                                <span class="badge bg-secondary" id="ir-decline-badge">3.0</span>
                            </div>
                            <input type="range" class="form-range" id="ir-decline"
                                   min="1" max="10" step="0.5" value="3"
                                   data-csp-input="settings.iterative-badge" data-badge-id="ir-decline-badge" data-decimals="1">
                            <div class="form-text mt-1" style="font-size:.78rem;line-height:1.6">
                                如果改进导致质量下降超过此值，立即停止迭代。防止越改越差。
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-4">
                <!-- 操作栏 -->
                <div class="page-card mb-3" style="position:sticky;top:1rem">
                    <div class="page-card-header">
                        <i class="bi bi-save me-2"></i>操作
                    </div>
                    <div class="p-3">
                        <button type="button" class="btn btn-primary w-100 mb-2" id="ir-save-btn" data-csp-click="settings.save-iterative">
                            <i class="bi bi-check-lg me-1"></i>保存迭代重写配置
                        </button>
                        <button type="button" class="btn btn-outline-secondary w-100 mb-3" data-csp-click="settings.reset-iterative">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>重置为默认值
                        </button>
                        <hr class="border-secondary opacity-25">
                        <div class="small text-muted" style="line-height:1.7">
                            <div class="fw-semibold text-light mb-1"><i class="bi bi-lightbulb me-1"></i>各模式说明</div>
                            <ul class="ps-3 mb-2">
                                <li><strong>保守模式</strong>：1轮迭代，Token 消耗最低</li>
                                <li><strong>均衡模式</strong>：3轮迭代，性价比最优（推荐）</li>
                                <li><strong>深度优化</strong>：5轮迭代，追求极致质量</li>
                                <li><strong>快速提升</strong>：2轮迭代，快速批量优化</li>
                            </ul>
                            <div class="fw-semibold text-light mb-1"><i class="bi bi-info-circle me-1"></i>生效说明</div>
                            <p class="mb-0">此配置为<strong>全局默认</strong>，新建小说时自动套用。对单部小说可在写作页面的「小说设置」中单独覆盖。</p>
                        </div>
                    </div>
                </div>

                <!-- 配置状态 -->
                <div class="page-card mb-3">
                    <div class="page-card-header">
                        <i class="bi bi-bar-chart me-2"></i>配置状态
                    </div>
                    <div class="p-3">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted small">当前模式</span>
                            <span class="fw-semibold" id="ir-current-mode">均衡模式</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted small">自定义参数</span>
                            <span class="fw-semibold text-info" id="ir-custom-count">0</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted small">预计单章消耗</span>
                            <span class="fw-semibold" id="ir-token-est">~3x Token</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ========== 作者画像页 ========== -->
    <div class="tab-pane fade <?= $activeTab === 'profile' ? 'show active' : '' ?>" id="profile-tab-pane" role="tabpanel">
        <?php if ($profileData): ?>
        <?php
        $p = $profileData;
        ?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3><i class="bi bi-pencil-square"></i> 编辑画像：<?= htmlspecialchars($p['profile_name']) ?></h3>
            <a href="?tab=profile" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> 返回列表</a>
        </div>

        <form id="editProfileForm" class="page-card p-4 mb-4">
            <input type="hidden" name="profile_id" value="<?= $p['id'] ?>">
            <h5 class="mb-3"><i class="bi bi-person"></i> 基本信息</h5>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label">画像名称</label>
                    <input type="text" class="form-control" name="profile_name" value="<?= htmlspecialchars($p['profile_name']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">性别</label>
                    <select class="form-select" name="gender">
                        <option value="">未设置</option>
                        <option value="male" <?= ($p['basic_info']['gender'] ?? '') === 'male' ? 'selected' : '' ?>>男</option>
                        <option value="female" <?= ($p['basic_info']['gender'] ?? '') === 'female' ? 'selected' : '' ?>>女</option>
                        <option value="other" <?= ($p['basic_info']['gender'] ?? '') === 'other' ? 'selected' : '' ?>>其他</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">年龄段</label>
                    <input type="text" class="form-control" name="age_range" value="<?= htmlspecialchars($p['basic_info']['age_range'] ?? '') ?>" placeholder="如 25-30">
                </div>
                <div class="col-md-3">
                    <label class="form-label">MBTI</label>
                    <input type="text" class="form-control" name="mbti" value="<?= htmlspecialchars($p['basic_info']['mbti'] ?? '') ?>" placeholder="如 INFP">
                </div>
                <div class="col-md-3">
                    <label class="form-label">星座</label>
                    <input type="text" class="form-control" name="constellation" value="<?= htmlspecialchars($p['basic_info']['constellation'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">职业</label>
                    <input type="text" class="form-control" name="occupation" value="<?= htmlspecialchars($p['basic_info']['occupation'] ?? '') ?>">
                </div>
            </div>

            <h5 class="mb-3"><i class="bi bi-book"></i> 背景信息</h5>
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label">教育背景</label>
                    <textarea class="form-control" name="education_bg" rows="2"><?= htmlspecialchars($p['background']['education_bg'] ?? '') ?></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label">写作经历</label>
                    <textarea class="form-control" name="writing_experience" rows="2"><?= htmlspecialchars($p['background']['writing_experience'] ?? '') ?></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label">影响因素</label>
                    <textarea class="form-control" name="influences" rows="2"><?= htmlspecialchars($p['background']['influences'] ?? '') ?></textarea>
                </div>
            </div>

            <h5 class="mb-3"><i class="bi bi-pen"></i> 写作风格设置</h5>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label">写作习惯</label>
                    <textarea class="form-control" name="writing_habits_prompt" rows="4" placeholder="请描述您的写作习惯，如：偏好短句，段落简洁，对话占比高，喜欢用动作描写推进情节..."><?= htmlspecialchars($p['writing_habits_prompt'] ?? '') ?></textarea>
                    <div class="form-text">描述您在写作中的习惯性做法和偏好</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">叙事手法</label>
                    <textarea class="form-control" name="narrative_style_prompt" rows="4" placeholder="请描述您常用的叙事手法，如：喜欢用倒叙开篇，善用多线并行叙事，注重细节伏笔..."><?= htmlspecialchars($p['narrative_style_prompt'] ?? '') ?></textarea>
                    <div class="form-text">描述您在故事叙述中常用的技巧和方法</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">思想情感</label>
                    <textarea class="form-control" name="sentiment_prompt" rows="4" placeholder="请描述您作品中的思想情感倾向，如：主题偏向成长与救赎，情感表达含蓄内敛，喜欢探讨人性幽暗面..."><?= htmlspecialchars($p['sentiment_prompt'] ?? '') ?></textarea>
                    <div class="form-text">描述您作品中常见的思想主题和情感表达方式</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">创作个性</label>
                    <textarea class="form-control" name="creative_identity_prompt" rows="4" placeholder="请描述您的创作个性，如：文风冷峻克制，擅长黑色幽默，喜欢解构传统叙事，追求语言的诗意..."><?= htmlspecialchars($p['creative_identity_prompt'] ?? '') ?></textarea>
                    <div class="form-text">描述您作为创作者的独特风格和艺术追求</div>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> 保存修改</button>
            </div>
        </form>

        <?php if ($p['analysis_status'] === 'completed'): ?>
        <div class="page-card p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5><i class="bi bi-graph-up"></i> 分析结果</h5>
                <div class="btn-group btn-group-sm" role="group">
                     <button class="btn btn-outline-info" data-csp-click="settings.detailed-report" data-profile-id="<?= (int)$p['id'] ?>">
                        <i class="bi bi-bar-chart-steps"></i> 查看详细报告
                    </button>
                    <button class="btn btn-outline-primary" data-csp-click="settings.all-dimensions" data-profile-id="<?= (int)$p['id'] ?>">
                        <i class="bi bi-lightning-charge"></i> 重新分析全部
                    </button>
                </div>
            </div>

            <div class="mb-4">
                <div class="progress" style="height: 8px;">
                    <div id="analysisProgressBar" class="progress-bar bg-success" style="width: 100%"></div>
                </div>
                <div class="d-flex justify-content-between mt-1 text-sm text-secondary">
                    <span>分析进度</span>
                    <span id="analysisProgressText">4/4 维度已完成</span>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <button class="col-md-3 btn btn-outline-primary" id="btn-writing_habits" data-csp-click="settings.analyze-dimension" data-profile-id="<?= (int)$p['id'] ?>" data-dimension="writing_habits">
                    <i class="bi bi-pen"></i> 写作习惯 <span class="badge bg-success ms-1">✓</span>
                </button>
                <button class="col-md-3 btn btn-outline-primary" id="btn-narrative_style" data-csp-click="settings.analyze-dimension" data-profile-id="<?= (int)$p['id'] ?>" data-dimension="narrative_style">
                    <i class="bi bi-eye"></i> 叙事手法 <span class="badge bg-success ms-1">✓</span>
                </button>
                <button class="col-md-3 btn btn-outline-primary" id="btn-sentiment" data-csp-click="settings.analyze-dimension" data-profile-id="<?= (int)$p['id'] ?>" data-dimension="sentiment">
                    <i class="bi bi-heart"></i> 思想情感 <span class="badge bg-success ms-1">✓</span>
                </button>
                <button class="col-md-3 btn btn-outline-primary" id="btn-creative_identity" data-csp-click="settings.analyze-dimension" data-profile-id="<?= (int)$p['id'] ?>" data-dimension="creative_identity">
                    <i class="bi bi-stars"></i> 创作个性 <span class="badge bg-success ms-1">✓</span>
                </button>
            </div>

            <div id="detailedReportSection" style="display:none;" class="mt-3 pt-3 border-top border-secondary">

            </div>
        </div>
        <?php else: ?>
        <div class="page-card p-4 mb-4">
            <h5 class="mb-3"><i class="bi bi-cloud-upload"></i> 上传作品进行风格分析</h5>
            <p class="text-secondary mb-3">上传您的小说文本文件，系统将自动分析写作风格并生成画像。支持 TXT 格式，建议上传至少3章以上内容以获得更准确的分析结果。</p>

            <div class="row g-3">
                <div class="col-md-6">
                    <div class="card border-primary">
                        <div class="card-body">
                            <h6><i class="bi bi-file-earmark-text"></i> 方式一：上传文件</h6>
                            <div class="mb-3">
                                <input type="file" class="form-control" id="workFileInput" accept=".txt,.docx,.doc" data-profile-id="<?= $p['id'] ?>">
                                <div class="form-text">支持 TXT、DOCX 格式，单文件最大 50MB</div>
                            </div>
                            <button class="btn btn-primary" id="uploadFileBtn" data-csp-click="settings.upload-work" data-profile-id="<?= (int)$p['id'] ?>">
                                <i class="bi bi-upload"></i> 上传并分析
                            </button>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card border-info">
                        <div class="card-body">
                            <h6><i class="bi bi-input-cursor-text"></i> 方式二：粘贴文本</h6>
                            <div class="mb-3">
                                <textarea class="form-control" id="workTextInput" rows="5" placeholder="粘贴小说正文内容（至少1000字）..."></textarea>
                            </div>
                            <button class="btn btn-info text-white" data-csp-click="settings.analyze-text" data-profile-id="<?= (int)$p['id'] ?>">
                                <i class="bi bi-magic"></i> 分析文本
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div id="uploadProgress" class="mt-3" style="display:none;">
                <div class="progress">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" id="uploadProgressBar" style="width: 0%">0%</div>
                </div>
                <div class="small text-secondary mt-1" id="uploadStatusText">准备上传...</div>
            </div>

            <div id="uploadResult" class="mt-3" style="display:none;">
                <div class="alert" id="uploadResultAlert"></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($p['style_guide'])): ?>
        <div class="page-card p-4 mb-4">
            <h5 class="mb-3"><i class="bi bi-journal-richtext"></i> 风格指南</h5>
            <div class="bg-body-tertiary p-3 rounded" style="white-space: pre-wrap; max-height: 400px; overflow-y: auto;"><?= htmlspecialchars($p['style_guide']) ?></div>
        </div>
        <?php endif; ?>

        <?php elseif (empty($profiles)): ?>
        <div class="page-card p-5 text-center">
            <i class="bi bi-person-circle" style="font-size: 4rem; color: #94a3b8;"></i>
            <h4 class="mt-3">暂无作者画像</h4>
            <p class="text-secondary">上传您的作品，系统将自动分析并生成风格画像</p>
            <button class="btn btn-primary mt-3" data-csp-click="settings.create-profile">
                <i class="bi bi-plus-lg"></i> 创建第一个画像
            </button>
        </div>
        <?php else: ?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3><i class="bi bi-person-badge"></i> 作者画像</h3>
            <button class="btn btn-primary" data-csp-click="settings.create-profile">
                <i class="bi bi-plus-lg"></i> 新建画像
            </button>
        </div>

        <div class="row g-4">
            <?php foreach ($profiles as $p): ?>
            <div class="col-md-4">
                <div class="page-card profile-card p-4 h-100">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <h5><?= htmlspecialchars($p['profile_name']) ?></h5>
                        <?php
                        $statusClass = match($p['analysis_status']) {
                            'completed' => 'bg-success',
                            'analyzing' => 'bg-warning',
                            'failed' => 'bg-danger',
                            default => 'bg-secondary'
                        };
                        $statusText = match($p['analysis_status']) {
                            'completed' => '已完成',
                            'analyzing' => '分析中',
                            'failed' => '失败',
                            default => '待分析'
                        };
                        ?>
                        <span class="badge <?= $statusClass ?>"><?= $statusText ?></span>
                    </div>

                    <?php if ($p['analysis_status'] === 'completed' || !empty($p['writing_habits_prompt']) || !empty($p['narrative_style_prompt']) || !empty($p['sentiment_prompt']) || !empty($p['creative_identity_prompt'])): ?>
                    <div class="mb-3">
                        <?php
                        $ciData = $p['creative_identity'] ?? [];
                        $tags = is_array($ciData) ? ($ciData['style_tags'] ?? []) : [];
                        foreach (array_slice($tags, 0, 3) as $tag): ?>
                        <span class="style-tag"><?= htmlspecialchars($tag) ?></span>
                        <?php endforeach; ?>
                        <?php if (!empty($p['creative_identity_prompt'])): ?>
                        <span class="style-tag">自定义风格</span>
                        <?php endif; ?>
                    </div>
                    <div class="text-secondary small mb-2">
                        <i class="bi bi-eye"></i> 叙事手法：
                        <?= htmlspecialchars(mb_substr($p['narrative_style_prompt'] ?? ($p['narrative_style']['narrative_pov_label'] ?? '未设置'), 0, 20)) ?>
                        <?= mb_strlen($p['narrative_style_prompt'] ?? '') > 20 ? '...' : '' ?>
                    </div>
                    <div class="text-secondary small mb-3">
                        <i class="bi bi-heart"></i> 思想情感：
                        <?= htmlspecialchars(mb_substr($p['sentiment_prompt'] ?? ($p['sentiment']['tone_label'] ?? '未设置'), 0, 20)) ?>
                        <?= mb_strlen($p['sentiment_prompt'] ?? '') > 20 ? '...' : '' ?>
                    </div>
                    <?php else: ?>
                    <div class="text-secondary mb-3">
                        <i class="bi bi-hourglass-split"></i> 上传作品即可开始分析
                    </div>
                    <?php endif; ?>

                    <div class="d-flex justify-content-between align-items-center mt-auto">
                        <small class="text-secondary">使用 <?= $p['usage_count'] ?> 次</small>
                        <div>
                            <a href="?tab=profile&action=edit&id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <button class="btn btn-sm btn-outline-danger" data-csp-click="settings.delete-profile" data-profile-id="<?= (int)$p['id'] ?>">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 创建画像弹窗 -->
<div class="modal fade" id="createProfileModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content page-card">
            <div class="modal-header">
                <h5 class="modal-title">新建作者画像</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="createProfileForm">
                    <div class="mb-3">
                        <label class="form-label">画像名称</label>
                        <input type="text" class="form-control" name="profile_name" required placeholder="例如：我的写作风格">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check-lg"></i> 创建画像
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= cspNonce() ?>">
// CSP delegated page actions. Values stay in data attributes, never executable code.
window.AppEvents.register('settings.parameter-badge', function(event, element) { element.closest('.mb-4').querySelector('.badge').textContent = element.value + element.dataset.unit; });
window.AppEvents.register('settings.iterative-badge', function(event, element) { document.getElementById(element.dataset.badgeId).textContent = element.dataset.decimals === '1' ? parseFloat(element.value).toFixed(1) : element.value; });
window.AppEvents.register('settings.emergency-epoch', function(event, element) { bumpHarnessEmergencyEpoch(); });
window.AppEvents.register('settings.save-parameters', function(event, element) { saveWsParams(); });
window.AppEvents.register('settings.reset-parameters', function(event, element) { resetWsParams(); });
window.AppEvents.register('settings.save-iterative', function(event, element) { saveIterativeSettings(); });
window.AppEvents.register('settings.reset-iterative', function(event, element) { resetIterativeSettings(); });
window.AppEvents.register('settings.create-profile', function(event, element) { showCreateProfileModal(); });
window.AppEvents.register('settings.detailed-report', function(event, element) { loadDetailedReport(Number(element.dataset.profileId)); });
window.AppEvents.register('settings.all-dimensions', function(event, element) { runAllDimensions(Number(element.dataset.profileId)); });
window.AppEvents.register('settings.upload-work', function(event, element) { uploadWorkFile(Number(element.dataset.profileId)); });
window.AppEvents.register('settings.analyze-text', function(event, element) { analyzeFromText(Number(element.dataset.profileId)); });
window.AppEvents.register('settings.delete-profile', function(event, element) { deleteProfile(Number(element.dataset.profileId)); });
window.AppEvents.register('settings.analyze-dimension', function(event, element) { analyzeDimension(Number(element.dataset.profileId), element.dataset.dimension); });
// End CSP delegated page actions.
</script>
<script src="assets/js/writing_settings_params.js?v=<?= assetVersion('assets/js/writing_settings_params.js') ?>"></script>
<script src="assets/js/writing_settings_profiles.js?v=<?= assetVersion('assets/js/writing_settings_profiles.js') ?>"></script>
<script src="assets/js/writing_settings_report.js?v=<?= assetVersion('assets/js/writing_settings_report.js') ?>"></script>
<script src="assets/js/writing_settings_iterative.js?v=<?= assetVersion('assets/js/writing_settings_iterative.js') ?>"></script>

<?php pageFooter(); ?>
