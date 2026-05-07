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

// ========== 作者画像相关变量 ==========
$userId = $_SESSION['user_id'] ?? $_SESSION['uid'] ?? null;
$profileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
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
                    <div class="page-card mb-3">
                        <div class="page-card-header">
                            <i class="bi bi-sliders me-2"></i><?= h($secName) ?>
                        </div>
                        <div class="p-3">
                            <?php foreach ($params as $key => $def): ?>
                            <?php $val = wsVal($key, $paramDefs, $currentValues); ?>
                            <div class="mb-4">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label for="<?= h($key) ?>" class="form-label mb-0" style="font-size:.9rem">
                                        <?= h($def['label']) ?>
                                    </label>
                                    <span class="badge bg-secondary" style="font-size:.75rem">
                                        <?= h($val) ?><?= h($def['unit'] ?? '') ?>
                                    </span>
                                </div>

                                <?php if ($def['type'] === 'range'): ?>
                                <input type="range" class="form-range" id="<?= h($key) ?>" name="<?= h($key) ?>"
                                       min="<?= $def['min'] ?>" max="<?= $def['max'] ?>" step="<?= $def['step'] ?>"
                                       value="<?= h($val) ?>"
                                       oninput="this.closest('.mb-4').querySelector('.badge').textContent = this.value + '<?= h($def['unit'] ?? '') ?>'">
                                <?php elseif ($def['type'] === 'number'): ?>
                                <input type="number" class="form-control form-control-sm" id="<?= h($key) ?>" name="<?= h($key) ?>"
                                       min="<?= $def['min'] ?>" max="<?= $def['max'] ?>" step="<?= $def['step'] ?>"
                                       value="<?= h($val) ?>">
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
                            <button type="button" class="btn btn-primary w-100 mb-2" onclick="saveWsParams()">
                                <i class="bi bi-check-lg me-1"></i>保存所有参数
                            </button>
                            <button type="button" class="btn btn-outline-secondary w-100 mb-3" onclick="resetWsParams()">
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
                                   oninput="document.getElementById('ir-threshold-badge').textContent=this.value">
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
                                   oninput="document.getElementById('ir-boost-badge').textContent=this.value">
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
                                   oninput="document.getElementById('ir-max-iter-badge').textContent=this.value">
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
                                   oninput="document.getElementById('ir-min-improve-badge').textContent=parseFloat(this.value).toFixed(1)">
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
                                   oninput="document.getElementById('ir-target-badge').textContent=parseFloat(this.value).toFixed(1)">
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
                                   oninput="document.getElementById('ir-decline-badge').textContent=parseFloat(this.value).toFixed(1)">
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
                        <button type="button" class="btn btn-primary w-100 mb-2" id="ir-save-btn" onclick="saveIterativeSettings()">
                            <i class="bi bi-check-lg me-1"></i>保存迭代重写配置
                        </button>
                        <button type="button" class="btn btn-outline-secondary w-100 mb-3" onclick="resetIterativeSettings()">
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
                     <button class="btn btn-outline-info" onclick="loadDetailedReport(<?= $p['id'] ?>)">
                        <i class="bi bi-bar-chart-steps"></i> 查看详细报告
                    </button>
                    <button class="btn btn-outline-primary" onclick="runAllDimensions(<?= $p['id'] ?>)">
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
                <button class="col-md-3 btn btn-outline-primary" id="btn-writing_habits" onclick="analyzeDimension(<?= $p['id'] ?>, 'writing_habits')">
                    <i class="bi bi-pen"></i> 写作习惯 <span class="badge bg-success ms-1">✓</span>
                </button>
                <button class="col-md-3 btn btn-outline-primary" id="btn-narrative_style" onclick="analyzeDimension(<?= $p['id'] ?>, 'narrative_style')">
                    <i class="bi bi-eye"></i> 叙事手法 <span class="badge bg-success ms-1">✓</span>
                </button>
                <button class="col-md-3 btn btn-outline-primary" id="btn-sentiment" onclick="analyzeDimension(<?= $p['id'] ?>, 'sentiment')">
                    <i class="bi bi-heart"></i> 思想情感 <span class="badge bg-success ms-1">✓</span>
                </button>
                <button class="col-md-3 btn btn-outline-primary" id="btn-creative_identity" onclick="analyzeDimension(<?= $p['id'] ?>, 'creative_identity')">
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
                            <button class="btn btn-primary" id="uploadFileBtn" onclick="uploadWorkFile(<?= $p['id'] ?>)">
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
                            <button class="btn btn-info text-white" onclick="analyzeFromText(<?= $p['id'] ?>)">
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
            <button class="btn btn-primary mt-3" onclick="showCreateProfileModal()">
                <i class="bi bi-plus-lg"></i> 创建第一个画像
            </button>
        </div>
        <?php else: ?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3><i class="bi bi-person-badge"></i> 作者画像</h3>
            <button class="btn btn-primary" onclick="showCreateProfileModal()">
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
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteProfile(<?= $p['id'] ?>)">
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

<script>
function saveWsParams() {
    const container = document.getElementById('ws-params-container');
    const inputs = container.querySelectorAll('input[name], select[name]');
    const body = new FormData();
    body.append('action', 'save');
    body.append('csrf_token', '<?= h($_SESSION['csrf_token'] ?? '') ?>');
    inputs.forEach(el => {
        if (el.type === 'range' || el.type === 'number' || el.type === 'text' || el.tagName === 'SELECT') {
            body.append(el.name, el.value);
        }
    });
    fetch('api/writing_params.php?action=save', { method: 'POST', body })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                showToast(data.message || '保存成功', 'success');
            } else {
                showToast(data.message || data.error || '保存失败', 'danger');
            }
        })
        .catch(function() { showToast('请求失败', 'danger'); });
}

function resetWsParams() {
    if (!confirm('确定要将所有写作参数重置为系统默认值吗？')) return;
    const body = new FormData();
    body.append('action', 'reset');
    body.append('csrf_token', '<?= h($_SESSION['csrf_token'] ?? '') ?>');
    fetch('api/writing_params.php?action=reset', { method: 'POST', body })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(data.message || '已重置', 'success');
                setTimeout(() => location.reload(), 800);
            } else {
                showToast(data.message || '重置失败', 'danger');
            }
        })
        .catch(() => showToast('请求失败', 'danger'));
}

function showToast(message, type) {
    const toast = document.createElement('div');
    toast.className = `toast align-items-center text-bg-${type} border-0 show`;
    toast.setAttribute('role', 'alert');
    toast.innerHTML = `<div class="d-flex"><div class="toast-body">${message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'toast-container position-fixed top-0 end-0 p-3';
        container.style.zIndex = '9999';
        document.body.appendChild(container);
    }
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

function showCreateProfileModal() {
    new bootstrap.Modal(document.getElementById('createProfileModal')).show();
}

document.getElementById('createProfileForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const btn = this.querySelector('button[type="submit"]');
    const origText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>创建中...';
    fetch('api/author_profile.php/create', {
        method: 'POST',
        body: formData
    }).then(res => {
        if (!res.ok) {
            return res.text().then(text => {
                let msg = '创建失败';
                try { msg = JSON.parse(text).message || JSON.parse(text).error || msg; } catch(e2) { msg = text.substring(0, 200); }
                throw new Error(msg);
            });
        }
        return res.json();
    }).then(data => {
        if (data.success) {
            window.location.href = `?tab=profile&action=edit&id=${data.data.id}`;
        } else {
            alert(data.message || data.error || '创建失败');
            btn.disabled = false;
            btn.innerHTML = origText;
        }
    }).catch(err => {
        console.error('创建画像失败:', err);
        alert('创建失败: ' + err.message);
        btn.disabled = false;
        btn.innerHTML = origText;
    });
});

function deleteProfile(id) {
    if (!confirm('确定删除该作者画像？')) return;
    fetch(`api/author_profile.php/profile/${id}`, {
        method: 'DELETE'
    }).then(res => {
        if (!res.ok) {
            return res.text().then(text => {
                let msg = '删除失败';
                try { 
                    let json = JSON.parse(text);
                    msg = json.message || json.error || msg;
                } catch(e2) { 
                    if (text.includes('<br')) {
                        let match = text.match(/<b>([^<]+)<\/b>/);
                        msg = match ? match[1] : msg;
                    } else {
                        msg = text.substring(0, 100);
                    }
                }
                throw new Error(msg);
            });
        }
        return res.json();
    }).then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            alert(data.message || data.error || '删除失败');
        }
    }).catch(err => {
        console.error('删除画像失败:', err);
        alert('删除失败: ' + err.message);
    });
}

document.querySelector('button[onclick*="reset"]')?.addEventListener('click', function(e) {
    e.preventDefault();
    if (!confirm('确定将所有写作参数重置为系统默认值？')) return;
    var form = document.getElementById('ws-form');
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'action';
    input.value = 'reset';
    form.appendChild(input);
    form.submit();
});

document.getElementById('editProfileForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const form = this;
    const profileId = form.querySelector('[name="profile_id"]').value;
    const btn = form.querySelector('button[type="submit"]');
    const origText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>保存中...';
    const data = {};
    new FormData(form).forEach((v, k) => { if (k !== 'profile_id') data[k] = v; });
    fetch(`api/author_profile.php/profile/${profileId}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    }).then(res => {
        if (!res.ok) {
            return res.text().then(text => {
                let msg = '保存失败';
                try { msg = JSON.parse(text).message || JSON.parse(text).error || msg; } catch(e2) { msg = text.substring(0, 200); }
                throw new Error(msg);
            });
        }
        return res.json();
    }).then(data => {
        alert('保存成功');
        btn.disabled = false;
        btn.innerHTML = origText;
    }).catch(err => {
        console.error('保存失败:', err);
        alert('保存失败: ' + err.message);
        btn.disabled = false;
        btn.innerHTML = origText;
    });
});

function uploadWorkFile(profileId) {
    var fileInput = document.getElementById('workFileInput');
    if (!fileInput || !fileInput.files.length) {
        alert('请先选择文件');
        return;
    }
    var file = fileInput.files[0];
    var btn = document.getElementById('uploadFileBtn');
    var origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>上传中...';

    var progressDiv = document.getElementById('uploadProgress');
    var progressBar = document.getElementById('uploadProgressBar');
    var statusText = document.getElementById('uploadStatusText');
    var resultDiv = document.getElementById('uploadResult');
    var resultAlert = document.getElementById('uploadResultAlert');
    progressDiv.style.display = '';
    resultDiv.style.display = 'none';
    progressBar.style.width = '10%';
    progressBar.textContent = '10%';
    statusText.textContent = '正在上传文件...';

    var fd = new FormData();
    fd.append('file', file);
    fd.append('profile_id', profileId);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'api/author_profile.php/upload', true);

    xhr.upload.onprogress = function(e) {
        if (e.lengthComputable) {
            var pct = Math.round(e.loaded / e.total * 70);
            progressBar.style.width = pct + '%';
            progressBar.textContent = pct + '%';
        }
    };

    xhr.onload = function() {
        var data;
        try { data = JSON.parse(xhr.responseText); } catch(e) {
            progressBar.style.width = '100%';
            progressBar.textContent = '100%';
            statusText.textContent = '上传完成，但服务器返回异常';
            resultDiv.style.display = '';
            resultAlert.className = 'alert alert-danger';
            resultAlert.textContent = '服务器返回异常，请重试';
            btn.disabled = false;
            btn.innerHTML = origHtml;
            return;
        }

        if (data.success) {
            progressBar.style.width = '80%';
            progressBar.textContent = '80%';
            statusText.textContent = '文件上传成功！共 ' + (data.chapter_count || 0) + ' 章，' + (data.char_count || 0) + ' 字。正在启动风格分析...';
            startAnalysis(profileId, data.work_id, progressBar, statusText, resultDiv, resultAlert, btn, origHtml);
        } else {
            progressBar.style.width = '100%';
            progressBar.textContent = '100%';
            statusText.textContent = '上传失败';
            resultDiv.style.display = '';
            resultAlert.className = 'alert alert-danger';
            resultAlert.textContent = data.error || data.message || '上传失败';
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
    };

    xhr.onerror = function() {
        statusText.textContent = '网络错误';
        resultDiv.style.display = '';
        resultAlert.className = 'alert alert-danger';
        resultAlert.textContent = '网络请求失败，请重试';
        btn.disabled = false;
        btn.innerHTML = origHtml;
    };

    xhr.send(fd);
}

function analyzeFromText(profileId) {
    var textarea = document.getElementById('workTextInput');
    if (!textarea) return;
    var text = textarea.value.trim();
    if (text.length < 1000) {
        alert('文本太短，至少需要1000字进行分析');
        return;
    }
    var progressDiv = document.getElementById('uploadProgress');
    var progressBar = document.getElementById('uploadProgressBar');
    var statusText = document.getElementById('uploadStatusText');
    var resultDiv = document.getElementById('uploadResult');
    var resultAlert = document.getElementById('uploadResultAlert');
    progressDiv.style.display = '';
    resultDiv.style.display = 'none';
    progressBar.style.width = '20%';
    progressBar.textContent = '20%';
    statusText.textContent = '正在提交文本进行分析...';

    fetch('api/author_profile.php/analyze-text', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ profile_id: profileId, text: text })
    }).then(function(res) {
        if (!res.ok) {
            return res.text().then(function(text) {
                var msg = '分析失败';
                try { msg = JSON.parse(text).error || JSON.parse(text).message || msg; } catch(e2) { msg = text.substring(0, 200); }
                throw new Error(msg);
            });
        }
        return res.json();
    }).then(function(data) {
        if (data.success) {
            progressBar.style.width = '100%';
            progressBar.textContent = '100%';
            progressBar.className = 'progress-bar bg-success';
            statusText.textContent = '风格分析完成！';
            resultDiv.style.display = '';
            resultAlert.className = 'alert alert-success';
            resultAlert.innerHTML = '风格分析完成！<a href="?tab=profile&action=edit&id=' + profileId + '" class="alert-link">点击刷新查看结果</a>';
        } else {
            throw new Error(data.error || data.message || '分析失败');
        }
    }).catch(function(err) {
        progressBar.style.width = '100%';
        progressBar.textContent = '100%';
        progressBar.className = 'progress-bar bg-danger';
        statusText.textContent = '分析失败';
        resultDiv.style.display = '';
        resultAlert.className = 'alert alert-danger';
        resultAlert.textContent = err.message;
    });
}

function startAnalysis(profileId, workId, progressBar, statusText, resultDiv, resultAlert, btn, origHtml) {
    progressBar.style.width = '85%';
    progressBar.textContent = '85%';
    statusText.textContent = '正在进行风格分析（可能需要数十秒）...';

    fetch('api/author_profile.php/analyze', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ profile_id: profileId, work_id: workId })
    }).then(function(res) {
        if (!res.ok) {
            return res.text().then(function(text) {
                var msg = '分析失败';
                try { msg = JSON.parse(text).error || JSON.parse(text).message || msg; } catch(e2) { msg = text.substring(0, 200); }
                throw new Error(msg);
            });
        }
        return res.json();
    }).then(function(data) {
        if (data.success) {
            progressBar.style.width = '95%';
            progressBar.textContent = '95%';
            statusText.textContent = '正在加载分析结果...';
            fetchProfileAndPopulatePrompts(profileId, function() {
                progressBar.style.width = '100%';
                progressBar.textContent = '100%';
                progressBar.className = 'progress-bar bg-success';
                statusText.textContent = '风格分析完成！';
                resultDiv.style.display = '';
                resultAlert.className = 'alert alert-success';
                resultAlert.innerHTML = '风格分析完成！<a href="?tab=profile&action=edit&id=' + profileId + '" class="alert-link">点击刷新查看结果</a>';
                btn.disabled = false;
                btn.innerHTML = origHtml;
            });
        } else {
            throw new Error(data.error || data.message || '分析失败');
        }
    }).catch(function(err) {
        progressBar.style.width = '100%';
        progressBar.textContent = '100%';
        progressBar.className = 'progress-bar bg-danger';
        statusText.textContent = '分析失败';
        resultDiv.style.display = '';
        resultAlert.className = 'alert alert-danger';
        resultAlert.textContent = '分析失败: ' + err.message;
        btn.disabled = false;
        btn.innerHTML = origHtml;
    });
}

function fetchProfileAndPopulatePrompts(profileId, callback) {
    fetch('api/author_profile.php/profile/' + profileId)
        .then(function(res) { return res.json(); })
        .then(function(profile) {
            var prompts = [
                'writing_habits_prompt',
                'narrative_style_prompt',
                'sentiment_prompt',
                'creative_identity_prompt'
            ];
            prompts.forEach(function(prompt) {
                var textarea = document.getElementById('textarea-' + prompt.replace('_prompt', ''));
                if (textarea && profile[prompt]) {
                    var value = typeof profile[prompt] === 'object'
                        ? JSON.stringify(profile[prompt], null, 2)
                        : profile[prompt];
                    textarea.value = value;
                }
            });
            if (callback) callback();
        })
        .catch(function(err) {
            console.error('Failed to load profile prompts:', err);
            if (callback) callback();
        });
}

function analyzeDimension(profileId, dimension) {
    const btn = document.getElementById('btn-' + dimension);
    if (btn.classList.contains('running')) return;
    
    btn.classList.add('running');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> 分析中...';

    fetch('api/author_profile.php/analyze-dimension', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ profile_id: profileId, dimension: dimension })
    }).then(res => {
        if (!res.ok) {
            return res.text().then(text => {
                let msg = '分析失败';
                try { msg = JSON.parse(text).error || JSON.parse(text).message || msg; } catch(e2) { msg = text.substring(0, 200); }
                throw new Error(msg);
            });
        }
        return res.json();
    }).then(data => {
        if (data.success) {
            btn.classList.remove('running');
            btn.disabled = false;
            btn.innerHTML = getDimensionLabel(dimension) + ' <span class="badge bg-success ms-1">✓</span>';
            updateProgress(data.progress);
            alert(dimensionLabels[dimension] + ' 分析完成！');
            window.location.reload();
        } else {
            throw new Error(data.error || data.message || '分析失败');
        }
    }).catch(err => {
        btn.classList.remove('running');
        btn.disabled = false;
        btn.innerHTML = getDimensionLabel(dimension) + ' <span class="badge bg-danger ms-1">✗</span>';
        alert('分析失败: ' + err.message);
    });
}

function runAllDimensions(profileId) {
    const dimensions = ['writing_habits', 'narrative_style', 'sentiment', 'creative_identity'];
    let currentIndex = 0;
    
    function runNext() {
        if (currentIndex >= dimensions.length) {
            alert('所有维度分析完成！');
            window.location.reload();
            return;
        }
        
        const dimension = dimensions[currentIndex];
        const btn = document.getElementById('btn-' + dimension);
        btn.classList.add('running');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> 分析中...';

        fetch('api/author_profile.php/analyze-dimension', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ profile_id: profileId, dimension: dimension })
        }).then(res => {
            if (!res.ok) {
                return res.text().then(text => {
                    let msg = '分析失败';
                    try { msg = JSON.parse(text).error || JSON.parse(text).message || msg; } catch(e2) { msg = text.substring(0, 200); }
                    throw new Error(msg);
                });
            }
            return res.json();
        }).then(data => {
            if (data.success) {
                btn.classList.remove('running');
                btn.disabled = false;
                btn.innerHTML = getDimensionLabel(dimension) + ' <span class="badge bg-success ms-1">✓</span>';
                updateProgress(data.progress);
                currentIndex++;
                runNext();
            } else {
                throw new Error(data.error || data.message || '分析失败');
            }
        }).catch(err => {
            btn.classList.remove('running');
            btn.disabled = false;
            btn.innerHTML = getDimensionLabel(dimension) + ' <span class="badge bg-danger ms-1">✗</span>';
            alert('分析失败: ' + err.message);
        });
    }
    
    runNext();
}

function updateProgress(progress) {
    const bar = document.getElementById('analysisProgressBar');
    const text = document.getElementById('analysisProgressText');
    if (bar && text) {
        const pct = Math.round((progress.completed / progress.total) * 100);
        bar.style.width = pct + '%';
        text.textContent = progress.completed + '/' + progress.total + ' 维度已完成';
    }
}

const dimensionLabels = {
    writing_habits: '写作习惯',
    narrative_style: '叙事手法',
    sentiment: '思想情感',
    creative_identity: '创作个性'
};

const LABELS = {
    pov: { first_person: '第一人称', second_person: '第二人称', third_limited: '第三人称限视', third_omniscient: '第三人称全知', multiple: '多视角' },
    tone: { optimistic: '积极乐观', pessimistic: '消极悲观', neutral: '中立客观', bittersweet: '苦乐参半', dark: '暗黑压抑', uplifting: '振奋人心' },
    tension: { peak: '高潮', rising: '上升', neutral: '中性', falling: '下降', low: '低谷' },
    tensionColors: { peak: 'danger', rising: 'warning', neutral: 'secondary', falling: 'info', low: 'primary' },
    emotion: { joy: '喜悦', sadness: '悲伤', anger: '愤怒', fear: '恐惧', surprise: '惊讶', love: '爱', disgust: '厌恶' },
    emotionColors: { joy: 'warning', sadness: 'info', anger: 'danger', fear: 'secondary', surprise: 'success', love: 'danger', disgust: 'dark' },
    beautyFocus: { nature: '自然景物', character: '人物外貌', architecture: '建筑场景', action: '动作场面', emotion: '情感渲染' },
    valueTendency: { success: '成功荣耀', love: '真爱守护', freedom: '自由解放', justice: '正义公道', family: '家族传承' },
    characterArchetype: { hero: '英雄主角', mentor: '导师角色', villain: '反派BOSS', love_interest: '恋人角色', comic_relief: '喜剧角色', lancer: '搭档角色' },
    rhetoric: { metaphor: '暗喻', simile: '明喻', personification: '拟人', hyperbole: '夸张', parallelism: '排比', repetition: '反复' }
};

function getDimensionLabel(dimension) {
    const icons = {
        writing_habits: '<i class="bi bi-pen"></i>',
        narrative_style: '<i class="bi bi-eye"></i>',
        sentiment: '<i class="bi bi-heart"></i>',
        creative_identity: '<i class="bi bi-stars"></i>'
    };
    return icons[dimension] + ' ' + dimensionLabels[dimension];
}

function toggleDimensionEdit(dimension) {
    const viewDiv = document.getElementById(dimension + '_view');
    const editDiv = document.getElementById(dimension + '_edit');
    if (viewDiv && editDiv) {
        const isEditing = editDiv.style.display !== 'none';
        viewDiv.style.display = isEditing ? '' : 'none';
        editDiv.style.display = isEditing ? 'none' : '';
    }
}

function saveDimension(profileId, dimension) {
    const textarea = document.getElementById('textarea-' + dimension);
    if (!textarea) return;
    
    const inputValue = textarea.value.trim();
    if (!inputValue) {
        alert('请输入内容');
        return;
    }

    let resultData;
    let isJson = false;
    try {
        resultData = JSON.parse(inputValue);
        isJson = true;
    } catch(e) {
        resultData = inputValue;
    }

    const promptFieldMap = {
        'writing_habits': 'writing_habits_prompt',
        'narrative_style': 'narrative_style_prompt',
        'sentiment': 'sentiment_prompt',
        'creative_identity': 'creative_identity_prompt'
    };
    const promptField = promptFieldMap[dimension];

    Promise.all([
        fetch(`api/author_profile.php/dimension/${dimension}/${profileId}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ result: resultData })
        }).then(res => {
            if (!res.ok) {
                return res.text().then(text => {
                    let msg = '分析数据保存失败';
                    try { msg = JSON.parse(text).error || JSON.parse(text).message || msg; } catch(e2) { msg = text.substring(0, 200); }
                    throw new Error(msg);
                });
            }
            return res.json();
        }),
        fetch(`api/author_profile.php/profile/${profileId}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ [promptField]: inputValue })
        }).then(res => {
            if (!res.ok) {
                return res.text().then(text => {
                    let msg = '提示词保存失败';
                    try { msg = JSON.parse(text).error || JSON.parse(text).message || msg; } catch(e2) { msg = text.substring(0, 200); }
                    throw new Error(msg);
                });
            }
            return res.json();
        })
    ]).then(function(results) {
        alert(dimensionLabels[dimension] + ' 保存成功！');
        toggleDimensionEdit(dimension);
        window.location.reload();
    }).catch(function(err) {
        alert('保存失败: ' + err.message);
    });
}

function loadDetailedReport(profileId) {
    var section = document.getElementById('detailedReportSection');
    if (section.style.display !== 'none' && section.innerHTML) {
        section.style.display = 'none';
        return;
    }

    if (section.innerHTML) {
        section.style.display = '';
        return;
    }

    section.style.display = '';
    section.innerHTML = '<div class="text-center py-4"><span class="spinner-border spinner-border-sm me-2"></span>正在加载详细报告...</div>';

    fetch('api/author_profile.php/detailed-report/' + profileId)
        .then(function(res) {
            if (!res.ok) {
                return res.text().then(function(t) {
                    var msg = '加载失败';
                    try { msg = JSON.parse(t).error || msg; } catch(e) { msg = t.substring(0, 200); }
                    throw new Error(msg);
                });
            }
            return res.json();
        })
        .then(function(report) {
            if (!report.success) {
                throw new Error(report.error || '加载失败');
            }
            renderDetailedReport(section, report);
        })
        .catch(function(err) {
            section.innerHTML = '<div class="alert alert-danger">加载详细报告失败: ' + err.message + '</div>';
        });
}

function renderDetailedReport(container, report) {
    var dims = report.dimensions || {};
    var chapters = report.chapter_stats || [];
    var dimOrder = ['writing_habits', 'narrative_style', 'sentiment', 'creative_identity'];
    var dimIcons = { writing_habits: 'bi-pen', narrative_style: 'bi-eye', sentiment: 'bi-heart', creative_identity: 'bi-stars' };
    var dimColors = { writing_habits: 'primary', narrative_style: 'success', sentiment: 'danger', creative_identity: 'warning' };

    var html = '';
    html += '<div class="d-flex justify-content-between align-items-center mb-3">';
    html += '<h6><i class="bi bi-file-earmark-bar-graph me-2"></i>详细分析报告</h6>';
    html += '<small class="text-secondary">总' + (report.total_chapters || 0) + '章 · ' + ((report.total_chars || 0) / 10000).toFixed(1) + '万字</small>';
    html += '</div>';

    html += '<ul class="nav nav-pills mb-3" id="reportTabs" role="tablist">';
    html += '<li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-overview" type="button">概览</button></li>';
    for (var i = 0; i < dimOrder.length; i++) {
        var dim = dimOrder[i];
        html += '<li class="nav-item"><button class="nav-link ' + (dim in dims ? '' : 'disabled') + '" data-bs-toggle="tab" data-bs-target="#tab-' + dim + '" type="button">' + dimensionLabels[dim] + '</button></li>';
    }
    html += '<li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-chapters" type="button">章节特征</button></li>';
    html += '</ul>';

    html += '<div class="tab-content">';

    // Overview tab
    html += '<div class="tab-pane fade show active" id="tab-overview">';
    html += renderOverviewTab(dims);
    html += '</div>';

    // Dimension tabs
    for (var i = 0; i < dimOrder.length; i++) {
        var dim = dimOrder[i];
        html += '<div class="tab-pane fade" id="tab-' + dim + '">';
        if (dim in dims) {
            html += renderDimensionDetail(dim, dims[dim], dimensionLabels[dim], dimIcons[dim], dimColors[dim]);
        } else {
            html += '<p class="text-secondary">该维度暂无分析数据</p>';
        }
        html += '</div>';
    }

    // Chapter tab
    html += '<div class="tab-pane fade" id="tab-chapters">';
    html += renderChapterStats(chapters);
    html += '</div>';

    html += '</div>';

    container.innerHTML = html;
}

function renderOverviewTab(dims) {
    var items = [
        { key: 'writing_habits', label: '写作习惯', icon: 'bi-pen', color: 'primary', extract: null },
        { key: 'narrative_style', label: '叙事手法', icon: 'bi-eye', color: 'success', extract: null },
        { key: 'sentiment', label: '思想情感', icon: 'bi-heart', color: 'danger', extract: null },
        { key: 'creative_identity', label: '创作个性', icon: 'bi-stars', color: 'warning', extract: null },
    ];

    var html = '<div class="row g-3">';
    for (var i = 0; i < items.length; i++) {
        var item = items[i];
        var dim = dims[item.key];
        html += '<div class="col-md-6"><div class="card border-' + item.color + ' h-100">';
        html += '<div class="card-header bg-' + item.color + ' bg-opacity-10 py-2">';
        html += '<i class="bi ' + item.icon + ' me-1"></i><strong>' + item.label + '</strong>';
        if (dim && dim.confidence !== undefined) {
            html += '<span class="badge bg-' + item.color + ' float-end">置信度 ' + Math.round(dim.confidence * 100) + '%</span>';
        }
        html += '</div>';
        html += '<div class="card-body py-2 small">';
        if (dim) {
            html += renderDimensionSummary(item.key, dim);
        } else {
            html += '<span class="text-secondary">暂无数据</span>';
        }
        html += '</div></div></div>';
    }
    html += '</div>';
    return html;
}

function renderDimensionSummary(dim, data) {
    var lines = [];
    switch (dim) {
        case 'writing_habits':
            if (data.sentence_length_avg) lines.push('平均句长：' + data.sentence_length_avg + '字');
            if (data.paragraph_length_avg) lines.push('平均段长：' + data.paragraph_length_avg + '字');
            if (data.word_complexity) lines.push('词汇复杂度：' + data.word_complexity);
            if (data.uniqueness_score) lines.push('独特性评分：' + Math.round(data.uniqueness_score * 100) + '%');
            if (data.use_dialogue > 0) lines.push('对话密度：' + (data.use_dialogue * 100).toFixed(2) + '%');
            if (data.metaphor_frequency) lines.push('比喻频率：' + data.metaphor_frequency);
            var rd = data.rhetorical_devices;
            if (rd && Object.keys(rd).length > 0) {
                var rdList = [];
                for (var k in rd) { rdList.push((LABELS.rhetoric[k] || k) + '(' + rd[k] + ')'); }
                lines.push('修辞：' + rdList.join(', '));
            }
            break;

        case 'narrative_style':
            if (data.narrative_pov) lines.push('视角：' + (LABELS.pov[data.narrative_pov] || data.narrative_pov));
            if (data.pacing_type) lines.push('节奏：' + data.pacing_type);
            if (data.chapter_structure) lines.push('结构：' + data.chapter_structure);
            if (data.cliffhanger_usage > 0) lines.push('悬念钩子：' + Math.round(data.cliffhanger_usage * 100) + '%');
            if (data.description_density) lines.push('描写密度：' + data.description_density);
            if (data.tension_curve && data.tension_curve.pattern) lines.push('张力模式：' + data.tension_curve.pattern);
            break;

        case 'sentiment':
            if (data.overall_tone) lines.push('整体基调：' + (LABELS.tone[data.overall_tone] || data.overall_tone));
            if (data.emotion_intensity) lines.push('情感强度：' + data.emotion_intensity);
            if (data.depth_level) lines.push('思想深度：' + data.depth_level);
            var themes = data.themes;
            if (themes && themes.length > 0) lines.push('主题：' + themes.slice(0, 4).join('、'));
            if (data.violence_level) lines.push('暴力程度：' + data.violence_level);
            if (data.moral_framework) lines.push('道德框架：' + data.moral_framework);
            break;

        case 'creative_identity':
            if (data.writing_voice) lines.push('文风：' + data.writing_voice);
            var gp = data.genre_preferences;
            if (gp && gp.length > 0) lines.push('题材偏好：' + gp.join('、'));
            var st = data.style_tags;
            if (st && st.length > 0) lines.push('风格标签：' + st.join('、'));
            var pp = data.plot_preferences;
            if (pp && pp.length > 0) lines.push('情节偏好：' + pp.join('、'));
            if (data.editing_style) lines.push('编辑风格：' + data.editing_style);
            if (data.planning_style) lines.push('创作规划：' + data.planning_style);
            break;
    }
    return lines.length > 0 ? lines.join('<br>') : '<span class="text-secondary">数据不足</span>';
}

function renderDimensionDetail(dim, data, name, icon, color) {
    var html = '';

    // Confidence bar
    if (data.confidence !== undefined) {
        html += '<div class="mb-3">';
        html += '<small class="text-secondary">分析置信度</small>';
        html += '<div class="progress" style="height:6px;"><div class="progress-bar bg-' + color + '" style="width:' + Math.round(data.confidence * 100) + '%"></div></div>';
        html += '<small class="text-secondary float-end">' + Math.round(data.confidence * 100) + '%</small>';
        html += '</div>';
    }

    // Writing Habits detail
    if (dim === 'writing_habits') {
        html += '<div class="row g-3">';

        if (data.sentence_length_avg || data.paragraph_length_avg) {
            html += '<div class="col-md-6"><div class="card bg-body-tertiary"><div class="card-body py-2">';
            html += '<small class="text-secondary">句子与段落</small>';
            html += '<div class="d-flex justify-content-between mt-1">';
            html += '<span>平均句长</span><strong>' + (data.sentence_length_avg || '-') + '字</strong>';
            html += '</div>';
            html += '<div class="d-flex justify-content-between">';
            html += '<span>平均段长</span><strong>' + (data.paragraph_length_avg || '-') + '字</strong>';
            html += '</div>';
            if (data.sentence_patterns) {
                var sp = data.sentence_patterns;
                html += '<div class="mt-2">';
                html += '<small class="text-secondary">句式占比</small>';
                html += renderMiniBar('短句', sp.short_ratio || 0, 'info');
                html += renderMiniBar('长句', sp.long_ratio || 0, 'warning');
                html += renderMiniBar('陈述', sp.declarative_ratio || 0, 'primary');
                html += renderMiniBar('感叹', sp.exclamatory_ratio || 0, 'danger');
                html += renderMiniBar('疑问', sp.interrogative_ratio || 0, 'success');
                html += '</div>';
            }
            html += '</div></div></div>';
        }

        html += '<div class="col-md-6"><div class="card bg-body-tertiary"><div class="card-body py-2">';
        html += '<small class="text-secondary">用词特征</small>';
        html += '<div class="d-flex justify-content-between mt-1"><span>词汇复杂度</span><strong>' + (data.word_complexity || '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>独特性评分</span><strong>' + (data.uniqueness_score ? Math.round(data.uniqueness_score * 100) + '%' : '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>对话密度</span><strong>' + (data.use_dialogue > 0 ? (data.use_dialogue * 100).toFixed(2) + '%' : '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>被动语态</span><strong>' + (data.use_passive > 0 ? data.use_passive.toFixed(2) + '‰' : '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>比喻频率</span><strong>' + (data.metaphor_frequency || '-') + '</strong></div>';
        html += '</div></div></div>';

        var vp = data.vocabulary_preference;
        if (vp && Object.keys(vp).length > 0) {
            html += '<div class="col-12"><div class="card bg-body-tertiary"><div class="card-body py-2">';
            html += '<small class="text-secondary">高频词汇 Top ' + Math.min(15, Object.keys(vp).length) + '</small>';
            html += '<div class="mt-1">';
            var vpEntries = Object.entries(vp).sort(function(a, b) { return b[1] - a[1]; }).slice(0, 15);
            var maxVp = vpEntries.length > 0 ? vpEntries[0][1] : 1;
            for (var vi = 0; vi < vpEntries.length; vi++) {
                var pct = Math.round(vpEntries[vi][1] / maxVp * 100);
                html += '<span class="badge bg-secondary me-1 mb-1" style="opacity:' + (0.4 + pct / 250) + '">' + vpEntries[vi][0] + ' ×' + vpEntries[vi][1] + '</span>';
            }
            html += '</div></div></div></div>';
        }

        var rd = data.rhetorical_devices;
        if (rd && Object.keys(rd).length > 0) {
            html += '<div class="col-12"><div class="card bg-body-tertiary"><div class="card-body py-2">';
            html += '<small class="text-secondary">修辞手法分布</small>';
            html += '<div class="row mt-1">';
            var rdMax = Math.max.apply(null, Object.values(rd));
            for (var rk in rd) {
                var rpct = rdMax > 0 ? Math.round(rd[rk] / rdMax * 100) : 0;
                html += '<div class="col-4"><div class="d-flex justify-content-between small"><span>' + (LABELS.rhetoric[rk] || rk) + '</span><span>' + rd[rk] + '</span></div>';
                html += '<div class="progress mb-1" style="height:4px"><div class="progress-bar bg-info" style="width:' + rpct + '%"></div></div></div>';
            }
            html += '</div></div></div></div>';
        }
        html += '</div>';
    }

    // Narrative Style detail
    if (dim === 'narrative_style') {
        html += '<div class="row g-3">';
        html += '<div class="col-md-6"><div class="card bg-body-tertiary"><div class="card-body py-2">';
        html += '<small class="text-secondary">叙事基础</small>';
        html += '<div class="d-flex justify-content-between mt-1"><span>叙事视角</span><strong>' + (data.narrative_pov || '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>节奏类型</span><strong>' + (data.pacing_type || '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>章节结构</span><strong>' + (data.chapter_structure || '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>视角切换</span><strong>' + (data.pov_switch_frequency || '-') + '</strong></div>';
        html += '</div></div></div>';

        html += '<div class="col-md-6"><div class="card bg-body-tertiary"><div class="card-body py-2">';
        html += '<small class="text-secondary">叙事技法</small>';
        html += '<div class="d-flex justify-content-between mt-1"><span>悬念钩子率</span><strong>' + (data.cliffhanger_usage ? Math.round(data.cliffhanger_usage * 100) + '%' : '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>内心独白密度</span><strong>' + (data.interior_monologue ? data.interior_monologue.toFixed(2) + '‰' : '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>描写密度</span><strong>' + (data.description_density || '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>场景过渡</span><strong>' + (data.scene_transition_style || '-') + '</strong></div>';
        html += '</div></div></div>';

        var tc = data.tension_curve;
        if (tc && tc.distribution) {
            html += '<div class="col-12"><div class="card bg-body-tertiary"><div class="card-body py-2">';
            html += '<small class="text-secondary">张力曲线 · 模式：' + (tc.pattern || '-') + '</small>';
            html += '<div class="mt-2">';
            var tensionOrder = ['peak', 'rising', 'neutral', 'falling', 'low'];
            var tcMax = Math.max.apply(null, Object.values(tc.distribution));
            for (var ti = 0; ti < tensionOrder.length; ti++) {
                var tk = tensionOrder[ti];
                var tv = tc.distribution[tk] || 0;
                var tpct = tcMax > 0 ? Math.round(tv / tcMax * 100) : 0;
                html += '<div class="d-flex align-items-center mb-1">';
                html += '<span class="small me-2" style="width:40px">' + (LABELS.tension[tk] || tk) + '</span>';
                html += '<div class="flex-grow-1"><div class="progress" style="height:6px"><div class="progress-bar bg-' + (LABELS.tensionColors[tk] || 'secondary') + '" style="width:' + tpct + '%"></div></div></div>';
                html += '<span class="small ms-2" style="width:30px">' + tv + '</span>';
                html += '</div>';
            }
            html += '</div></div></div></div>';
        }
        html += '</div>';
    }

    // Sentiment detail
    if (dim === 'sentiment') {
        html += '<div class="row g-3">';
        html += '<div class="col-md-6"><div class="card bg-body-tertiary"><div class="card-body py-2">';
        html += '<small class="text-secondary">情感基调</small>';
        html += '<div class="d-flex justify-content-between mt-1"><span>整体基调</span><strong>' + (data.overall_tone || '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>情感强度</span><strong>' + (data.emotion_intensity || '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>思想深度</span><strong>' + (data.depth_level || '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>主题复杂度</span><strong>' + (data.thematic_complexity ? Math.round(data.thematic_complexity * 100) + '%' : '-') + '</strong></div>';
        html += '</div></div></div>';

        html += '<div class="col-md-6"><div class="card bg-body-tertiary"><div class="card-body py-2">';
        html += '<small class="text-secondary">价值与风格</small>';
        html += '<div class="d-flex justify-content-between mt-1"><span>道德框架</span><strong>' + (data.moral_framework || '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>暴力程度</span><strong>' + (data.violence_level || '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>审美风格</span><strong>' + (data.aesthetic_style || '-') + '</strong></div>';
        html += '</div></div></div>';

        var er = data.emotional_range;
        if (er && Object.keys(er).length > 0) {
            html += '<div class="col-12"><div class="card bg-body-tertiary"><div class="card-body py-2">';
            html += '<small class="text-secondary">情感分布</small>';
            html += '<div class="mt-2">';
            var erSorted = Object.entries(er).sort(function(a, b) { return b[1] - a[1]; });
            var erMax = erSorted.length > 0 ? erSorted[0][1] : 1;
            for (var ei = 0; ei < erSorted.length; ei++) {
                var epct = Math.round(erSorted[ei][1] / erMax * 100);
                html += '<div class="d-flex align-items-center mb-1">';
                html += '<span class="small me-2" style="width:50px">' + (LABELS.emotion[erSorted[ei][0]] || erSorted[ei][0]) + '</span>';
                html += '<div class="flex-grow-1"><div class="progress" style="height:6px"><div class="progress-bar bg-' + (LABELS.emotionColors[erSorted[ei][0]] || 'secondary') + '" style="width:' + epct + '%"></div></div></div>';
                html += '<span class="small ms-2" style="width:40px">' + erSorted[ei][1].toFixed(1) + '</span>';
                html += '</div>';
            }
            html += '</div></div></div></div>';
        }

        var bdf = data.beauty_description_focus;
        if (bdf && bdf.length > 0) {
            html += '<div class="col-12"><div class="card bg-body-tertiary"><div class="card-body py-2">';
            html += '<small class="text-secondary">描写侧重点</small>';
            html += '<div class="mt-1">';
            for (var bi = 0; bi < bdf.length; bi++) {
                html += '<span class="badge bg-info me-1 mb-1">' + (LABELS.beautyFocus[bdf[bi]] || bdf[bi]) + '</span>';
            }
            html += '</div></div></div></div>';
        }

        var vt = data.values_tendency;
        if (vt && vt.length > 0) {
            html += '<div class="col-12"><div class="card bg-body-tertiary"><div class="card-body py-2">';
            html += '<small class="text-secondary">价值倾向（按权重排序）</small>';
            html += '<div class="mt-1">';
            for (var vi2 = 0; vi2 < vt.length; vi2++) {
                html += '<span class="badge bg-success me-1 mb-1">' + (LABELS.valueTendency[vt[vi2]] || vt[vi2]) + '</span>';
            }
            html += '</div></div></div></div>';
        }
        html += '</div>';
    }

    // Creative Identity detail
    if (dim === 'creative_identity') {
        html += '<div class="row g-3">';

        html += '<div class="col-12"><div class="card bg-body-tertiary"><div class="card-body py-2">';
        html += '<small class="text-secondary">文风语音</small>';
        html += '<p class="mt-1 mb-0">' + (data.writing_voice || '未分析') + '</p>';
        html += '</div></div></div>';

        var gp = data.genre_preferences;
        if (gp && gp.length > 0) {
            html += '<div class="col-6"><div class="card bg-body-tertiary"><div class="card-body py-2">';
            html += '<small class="text-secondary">题材偏好</small>';
            html += '<div class="mt-1">';
            for (var gi = 0; gi < gp.length; gi++) {
                html += '<span class="badge bg-primary me-1 mb-1">' + gp[gi] + '</span>';
            }
            html += '</div></div></div></div>';
        }

        var st = data.style_tags;
        if (st && st.length > 0) {
            html += '<div class="col-6"><div class="card bg-body-tertiary"><div class="card-body py-2">';
            html += '<small class="text-secondary">风格标签</small>';
            html += '<div class="mt-1">';
            for (var si = 0; si < st.length; si++) {
                html += '<span class="style-tag me-1 mb-1">' + st[si] + '</span>';
            }
            html += '</div></div></div></div>';
        }

        var pp = data.plot_preferences;
        if (pp && pp.length > 0) {
            html += '<div class="col-6"><div class="card bg-body-tertiary"><div class="card-body py-2">';
            html += '<small class="text-secondary">情节偏好</small>';
            html += '<div class="mt-1">';
            for (var pi = 0; pi < pp.length; pi++) {
                html += '<span class="badge bg-warning text-dark me-1 mb-1">' + pp[pi] + '</span>';
            }
            html += '</div></div></div></div>';
        }

        var ca = data.character_archetype_favorites;
        if (ca && ca.length > 0) {
            html += '<div class="col-6"><div class="card bg-body-tertiary"><div class="card-body py-2">';
            html += '<small class="text-secondary">角色原型偏好</small>';
            html += '<div class="mt-1">';
            for (var ci = 0; ci < ca.length; ci++) {
                html += '<span class="badge bg-success me-1 mb-1">' + (LABELS.characterArchetype[ca[ci]] || ca[ci]) + '</span>';
            }
            html += '</div></div></div></div>';
        }

        html += '<div class="col-md-6"><div class="card bg-body-tertiary"><div class="card-body py-2">';
        html += '<small class="text-secondary">创作流程</small>';
        html += '<div class="d-flex justify-content-between mt-1"><span>编辑风格</span><strong>' + (data.editing_style || '-') + '</strong></div>';
        html += '<div class="d-flex justify-content-between"><span>创作规划</span><strong>' + (data.planning_style || '-') + '</strong></div>';
        html += '</div></div></div>';

        var ut = data.unique_techniques;
        if (ut && ut.length > 0) {
            html += '<div class="col-md-6"><div class="card bg-body-tertiary"><div class="card-body py-2">';
            html += '<small class="text-secondary">独特技法</small>';
            html += '<div class="mt-1">';
            for (var ui = 0; ui < ut.length; ui++) {
                html += '<span class="badge bg-info me-1 mb-1">' + ut[ui] + '</span>';
            }
            html += '</div></div></div></div>';
        }

        var tm = data.trademark_elements;
        if (tm && tm.length > 0) {
            html += '<div class="col-12"><div class="card bg-body-tertiary"><div class="card-body py-2">';
            html += '<small class="text-secondary">标志性元素</small>';
            html += '<div class="mt-1">';
            for (var tmi = 0; tmi < tm.length; tmi++) {
                html += '<span class="style-tag me-1 mb-1">' + tm[tmi] + '</span>';
            }
            html += '</div></div></div></div>';
        }

        var sp = data.signature_phrases;
        if (sp && sp.length > 0) {
            html += '<div class="col-12"><div class="card bg-body-tertiary"><div class="card-body py-2">';
            html += '<small class="text-secondary">标志句式</small>';
            html += '<div class="mt-1 text-muted small">';
            for (var spi = 0; spi < Math.min(sp.length, 10); spi++) {
                html += '<code class="me-2 mb-1 d-inline-block">' + sp[spi] + '</code>';
            }
            html += '</div></div></div></div>';
        }

        html += '</div>';
    }

    return html;
}

function renderMiniBar(label, ratio, color) {
    var pct = Math.round(ratio * 100);
    return '<div class="d-flex align-items-center mb-1 small">' +
        '<span style="width:36px">' + label + '</span>' +
        '<div class="flex-grow-1 mx-1"><div class="progress" style="height:4px"><div class="progress-bar bg-' + color + '" style="width:' + pct + '%"></div></div></div>' +
        '<span style="width:30px;text-align:right">' + pct + '%</span>' +
        '</div>';
}

function renderChapterStats(chapters) {
    if (!chapters || chapters.length === 0) {
        return '<p class="text-secondary">暂无章节数据</p>';
    }

    var charCounts = chapters.map(function(c) { return c.char_count; });
    var totalChars = charCounts.reduce(function(a, b) { return a + b; }, 0);
    var avgChars = Math.round(totalChars / chapters.length);
    var maxChars = Math.max.apply(null, charCounts);
    var minChars = Math.min.apply(null, charCounts);

    var html = '';

    html += '<div class="row g-3 mb-3">';
    html += '<div class="col-md-3"><div class="card bg-body-tertiary text-center py-2"><small class="text-secondary">总章节</small><h5 class="mb-0">' + chapters.length + '</h5></div></div>';
    html += '<div class="col-md-3"><div class="card bg-body-tertiary text-center py-2"><small class="text-secondary">总字数</small><h5 class="mb-0">' + (totalChars / 10000).toFixed(2) + '万</h5></div></div>';
    html += '<div class="col-md-3"><div class="card bg-body-tertiary text-center py-2"><small class="text-secondary">平均字数/章</small><h5 class="mb-0">' + avgChars + '</h5></div></div>';
    html += '<div class="col-md-3"><div class="card bg-body-tertiary text-center py-2"><small class="text-secondary">字数区间</small><h5 class="mb-0">' + minChars + '~' + maxChars + '</h5></div></div>';
    html += '</div>';

    html += '<div class="mb-3"><small class="text-secondary">各章字数分布</small></div>';
    html += '<div class="mb-3" style="max-height:200px;overflow-y:auto">';
    for (var i = 0; i < chapters.length; i++) {
        var ch = chapters[i];
        var barPct = maxChars > 0 ? Math.round(ch.char_count / maxChars * 100) : 0;
        var barColor = ch.char_count > avgChars * 1.5 ? 'warning' : ch.char_count < avgChars * 0.5 ? 'danger' : 'primary';
        html += '<div class="d-flex align-items-center mb-1 small">';
        html += '<span style="width:60px;text-align:right" class="me-2 text-secondary">第' + ch.number + '章</span>';
        html += '<div class="flex-grow-1"><div class="progress" style="height:8px"><div class="progress-bar bg-' + barColor + '" style="width:' + barPct + '%"></div></div></div>';
        html += '<span class="ms-2" style="width:60px">' + ch.char_count + '字</span>';
        html += '<span class="ms-1 text-secondary" style="width:50px">' + (ch.title || '') + '</span>';
        html += '</div>';
    }
    html += '</div>';

    var hasDialogue = chapters.some(function(c) { return c.dialogue_density > 0; });
    if (hasDialogue) {
        html += '<div class="mb-3"><small class="text-secondary mt-2">各章对话密度对比</small></div>';
        html += '<div class="mb-3" style="max-height:200px;overflow-y:auto">';
        var maxDd = Math.max.apply(null, chapters.map(function(c) { return c.dialogue_density; }));
        for (var j = 0; j < chapters.length; j++) {
            var ch2 = chapters[j];
            var ddPct = maxDd > 0 ? Math.round(ch2.dialogue_density / maxDd * 100) : 0;
            html += '<div class="d-flex align-items-center mb-1 small">';
            html += '<span style="width:60px;text-align:right" class="me-2 text-secondary">第' + ch2.number + '章</span>';
            html += '<div class="flex-grow-1"><div class="progress" style="height:6px"><div class="progress-bar bg-success" style="width:' + ddPct + '%"></div></div></div>';
            html += '<span class="ms-2" style="width:60px">' + (ch2.dialogue_count || 0) + '处</span>';
            html += '</div>';
        }
        html += '</div>';
    }

    return html;
}

// ========== 迭代重写配置 ==========
(function() {
    var IR_MODES = {
        conservative: {
            label: '保守模式',
            config: {
                rewrite: { enabled: true, threshold: 70, min_gain: 10, iterative_mode: true, use_critic_agent: false },
                iterative_refinement: { max_iterations: 1, min_improvement: 5, target_score: 70, quality_decline_threshold: 5 }
            },
            token: '~1x Token'
        },
        balanced: {
            label: '均衡模式',
            config: {
                rewrite: { enabled: true, threshold: 70, min_gain: 8, iterative_mode: true, use_critic_agent: true },
                iterative_refinement: { max_iterations: 3, min_improvement: 5, target_score: 80, quality_decline_threshold: 3 }
            },
            token: '~3x Token'
        },
        aggressive: {
            label: '深度优化',
            config: {
                rewrite: { enabled: true, threshold: 75, min_gain: 5, iterative_mode: true, use_critic_agent: true },
                iterative_refinement: { max_iterations: 5, min_improvement: 3, target_score: 90, quality_decline_threshold: 2 }
            },
            token: '~5x Token'
        },
        fast: {
            label: '快速提升',
            config: {
                rewrite: { enabled: true, threshold: 65, min_gain: 3, iterative_mode: true, use_critic_agent: true },
                iterative_refinement: { max_iterations: 2, min_improvement: 3, target_score: 75, quality_decline_threshold: 5 }
            },
            token: '~2x Token'
        }
    };

    function cfgToForm(cfg) {
        var rw = cfg.rewrite || {};
        var ir = cfg.iterative_refinement || {};
        document.getElementById('ir-enabled').checked = !!rw.enabled;
        document.getElementById('ir-threshold').value = rw.threshold || 70;
        document.getElementById('ir-threshold-badge').textContent = rw.threshold || 70;
        document.getElementById('ir-boost').value = rw.min_gain || 10;
        document.getElementById('ir-boost-badge').textContent = rw.min_gain || 10;
        document.getElementById('ir-critic').checked = !!rw.use_critic_agent;
        document.getElementById('ir-style-guard').checked = rw.style_guard_enabled !== false;
        document.getElementById('ir-ai-patterns').checked = rw.ai_patterns_check_enabled !== false;
        document.getElementById('ir-max-iter').value = ir.max_iterations || 3;
        document.getElementById('ir-max-iter-badge').textContent = ir.max_iterations || 3;
        document.getElementById('ir-min-improve').value = ir.min_improvement || 5;
        document.getElementById('ir-min-improve-badge').textContent = (ir.min_improvement || 5).toFixed(1);
        document.getElementById('ir-target').value = ir.target_score || 80;
        document.getElementById('ir-target-badge').textContent = (ir.target_score || 80).toFixed(1);
        document.getElementById('ir-decline').value = ir.quality_decline_threshold || 3;
        document.getElementById('ir-decline-badge').textContent = (ir.quality_decline_threshold || 3).toFixed(1);
        updateStatusInfo(rw.enabled);
        detectMode(cfg);
    }

    function formToCfg() {
        return {
            rewrite: {
                enabled: document.getElementById('ir-enabled').checked,
                threshold: parseInt(document.getElementById('ir-threshold').value),
                min_gain: parseInt(document.getElementById('ir-boost').value),
                iterative_mode: true,
                use_critic_agent: document.getElementById('ir-critic').checked,
                style_guard_enabled: document.getElementById('ir-style-guard').checked,
                ai_patterns_check_enabled: document.getElementById('ir-ai-patterns').checked
            },
            iterative_refinement: {
                max_iterations: parseInt(document.getElementById('ir-max-iter').value),
                min_improvement: parseFloat(document.getElementById('ir-min-improve').value),
                target_score: parseFloat(document.getElementById('ir-target').value),
                quality_decline_threshold: parseFloat(document.getElementById('ir-decline').value)
            }
        };
    }

    function updateStatusInfo(enabled) {
        var el = document.getElementById('ir-status-text');
        var info = document.getElementById('ir-status-info');
        if (enabled) {
            el.textContent = '迭代重写已开启 — 写作完成后将自动检测质量并进行迭代改进';
            info.className = 'alert alert-success-subtle border-0 small mb-0';
        } else {
            el.textContent = '迭代重写已关闭 — 写作完成后不会自动进行质量改进';
            info.className = 'alert alert-secondary-subtle border-0 small mb-0';
        }
    }

    function detectMode(cfg) {
        var modeBtns = document.querySelectorAll('.ir-mode-btn');
        var currentMode = 'custom';
        for (var m in IR_MODES) {
            var preset = IR_MODES[m].config;
            if (cfg.rewrite.threshold === preset.rewrite.threshold &&
                cfg.rewrite.min_gain === preset.rewrite.min_gain &&
                cfg.rewrite.use_critic_agent === preset.rewrite.use_critic_agent &&
                cfg.iterative_refinement.max_iterations === preset.iterative_refinement.max_iterations &&
                cfg.iterative_refinement.min_improvement === preset.iterative_refinement.min_improvement &&
                cfg.iterative_refinement.target_score === preset.iterative_refinement.target_score &&
                cfg.iterative_refinement.quality_decline_threshold === preset.iterative_refinement.quality_decline_threshold) {
                currentMode = m;
                break;
            }
        }
        modeBtns.forEach(function(btn) {
            btn.classList.toggle('active', btn.dataset.mode === currentMode);
        });
        var maxIter = cfg.iterative_refinement.max_iterations || 3;
        document.getElementById('ir-current-mode').textContent =
            currentMode === 'custom' ? '自定义' : IR_MODES[currentMode].label;
        document.getElementById('ir-token-est').textContent =
            currentMode === 'custom' ? '~' + maxIter + 'x Token' : IR_MODES[currentMode].token;
        var customCount = 0;
        if (currentMode !== 'custom') {
            document.getElementById('ir-custom-count').textContent = '0';
        }
    }

    function applyMode(mode) {
        if (!IR_MODES[mode]) return;
        cfgToForm(IR_MODES[mode].config);
        var desc = document.getElementById('ir-mode-desc');
        desc.style.display = '';
        document.getElementById('ir-mode-desc-text').textContent =
            '已切换到' + IR_MODES[mode].label + '，所有参数已自动配置。点击「保存」使其生效。';
    }

    document.querySelectorAll('.ir-mode-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            applyMode(this.dataset.mode);
        });
    });

    document.getElementById('ir-enabled').addEventListener('change', function() {
        updateStatusInfo(this.checked);
    });

    function loadIterativeSettings() {
        fetch('api/iterative_config.php?action=get')
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success) {
                    cfgToForm(data.data);
                }
            })
            .catch(function(err) {
                console.error('加载迭代配置失败:', err);
            });
    }

    window.saveIterativeSettings = function() {
        var btn = document.getElementById('ir-save-btn');
        var origHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>保存中...';
        var cfg = formToCfg();
        fetch('api/iterative_config.php?action=update', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(cfg)
        }).then(function(res) { return res.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
            if (data.success) {
                var toast = document.createElement('div');
                toast.className = 'alert alert-success position-fixed top-0 start-50 translate-middle-x mt-3 shadow';
                toast.style.zIndex = '9999';
                toast.innerHTML = '<i class="bi bi-check-circle me-2"></i>迭代重写配置已保存';
                document.body.appendChild(toast);
                setTimeout(function() { toast.remove(); }, 2500);
                if (data.data) cfgToForm(data.data);
            } else {
                alert('保存失败：' + (data.error || data.message || '未知错误'));
            }
        }).catch(function(err) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
            alert('保存失败：' + err.message);
        });
    };

    window.resetIterativeSettings = function() {
        if (!confirm('确定将迭代重写配置重置为默认值？')) return;
        fetch('api/iterative_config.php?action=defaults')
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success) {
                    cfgToForm(data.data);
                    var toast = document.createElement('div');
                    toast.className = 'alert alert-info position-fixed top-0 start-50 translate-middle-x mt-3 shadow';
                    toast.style.zIndex = '9999';
                    toast.innerHTML = '<i class="bi bi-arrow-counterclockwise me-2"></i>已重置为默认值，请点击「保存」使其生效';
                    document.body.appendChild(toast);
                    setTimeout(function() { toast.remove(); }, 2500);
                }
            })
            .catch(function(err) {
                alert('重置失败：' + err.message);
            });
    };

    var iterativeTab = document.getElementById('iterative-tab');
    if (iterativeTab) {
        iterativeTab.addEventListener('shown.bs.tab', function() {
            loadIterativeSettings();
        });
    }
    if (document.getElementById('iterative-tab-pane')?.classList.contains('show')) {
        loadIterativeSettings();
    }
})();
</script>

<?php pageFooter(); ?>