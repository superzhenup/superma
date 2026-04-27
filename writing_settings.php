<?php
define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';

// ================================================================
// 写作参数设置页面
// ================================================================
// 所有参数以 key-value 形式存储在 system_settings 表中，
// 前缀为 "ws_"（writing settings），避免与其他全局设置冲突。
// ================================================================

$msg   = '';
$error = '';

// ── 支持的写作参数定义 ──────────────────────────────────────────
// 每个参数包含：
//   key        => system_settings 中的 setting_key
//   label      => 显示名称
//   type       => 表单类型: number | range | select | text
//   default    => 默认值（首次使用时）
//   min/max    => 数值范围
//   step       => 步长
//   unit       => 单位后缀
//   desc       => 参数功能说明（显示在表单下方）
//   section    => 所属分组（用于页面分区显示）
// ────────────────────────────────────────────────────────────────
$paramDefs = [

    // ========== 基础生成参数 ==========
    'ws_chapter_words' => [
        'label'   => '每章目标字数',
        'type'    => 'number',
        'default' => 2000,
        'min'     => 500,
        'max'     => 5000,
        'step'    => 100,
        'unit'    => '字',
        'desc'    => '单章生成的目标字数。网文常见区间：1500-2500字。低于1500节奏过快、缺乏沉浸感；高于3000读者疲劳度上升。',
        'section' => '基础生成参数',
    ],
    'ws_chapter_word_tolerance' => [
        'label'   => '章节字数容差',
        'type'    => 'number',
        'default' => 150,
        'min'     => 50,
        'max'     => 500,
        'step'    => 50,
        'unit'    => '字',
        'desc'    => '允许正文偏离目标字数的范围（±N字）。值越小，字数控制越严格；值越大，AI写作更自然但篇幅更难预测。默认150字约为目标字数的3%-8%。',
        'section' => '基础生成参数',
    ],
    'ws_dynamic_tolerance_ratio' => [
        'label'   => '动态容差比例',
        'type'    => 'number',
        'default' => 10,
        'min'     => 5,
        'max'     => 20,
        'step'    => 1,
        'unit'    => '%',
        'desc'    => '根据目标字数动态计算容差比例。例如：2000字×10%=200字容差。替代固定容差值，使控制比例一致。',
        'section' => '基础生成参数',
    ],
    'ws_min_tolerance' => [
        'label'   => '最小容差字数',
        'type'    => 'number',
        'default' => 100,
        'min'     => 50,
        'max'     => 300,
        'step'    => 50,
        'unit'    => '字',
        'desc'    => '动态容差的下限值。防止目标字数过小时容差太小导致过于严格。',
        'section' => '基础生成参数',
    ],
    'ws_max_tolerance' => [
        'label'   => '最大容差字数',
        'type'    => 'number',
        'default' => 500,
        'min'     => 200,
        'max'     => 1000,
        'step'    => 100,
        'unit'    => '字',
        'desc'    => '动态容差的上限值。防止目标字数过大时容差太大导致控制失效。',
        'section' => '基础生成参数',
    ],
    'ws_outline_batch' => [
        'label'   => '大纲批量生成数',
        'type'    => 'number',
        'default' => 20,
        'min'     => 5,
        'max'     => 50,
        'step'    => 5,
        'unit'    => '章',
        'desc'    => '每次生成大纲时，一次性规划的章节数量。值越大，AI 对长程节奏把控越好，但生成耗时越长。',
        'section' => '基础生成参数',
    ],
    'ws_auto_write_interval' => [
        'label'   => '自动写作间隔',
        'type'    => 'number',
        'default' => 2,
        'min'     => 1,
        'max'     => 60,
        'step'    => 1,
        'unit'    => '秒',
        'desc'    => '连续自动写作时，每章之间的等待间隔。太短可能触发 API 限流；太长拖慢整体进度。',
        'section' => '基础生成参数',
    ],

    // ========== 爽点调度参数 ==========
    'ws_cool_point_density_target' => [
        'label'   => '爽点密度目标值',
        'type'    => 'range',
        'default' => 0.88,
        'min'     => 0.5,
        'max'     => 1.5,
        'step'    => 0.05,
        'unit'    => '个/章',
        'desc'    => '每章平均爽点数量目标。参考值：0.88（《万古神帝》实测密度）。低于0.6读者流失风险高；高于1.2容易审美疲劳。',
        'section' => '爽点调度参数',
    ],
    'ws_cool_point_hunger_threshold' => [
        'label'   => '爽点饥饿阈值',
        'type'    => 'range',
        'default' => 0.6,
        'min'     => 0.3,
        'max'     => 1.0,
        'step'    => 0.05,
        'unit'    => '',
        'desc'    => '某种爽点类型冷却期过多少比例后，可重新参选。值越低，同类型爽点出现越频繁；值越高，类型轮换越均匀。',
        'section' => '爽点调度参数',
    ],
    'ws_double_coolpoint_gap' => [
        'label'   => '双爽点最小间隔',
        'type'    => 'number',
        'default' => 3,
        'min'     => 1,
        'max'     => 10,
        'step'    => 1,
        'unit'    => '章',
        'desc'    => '连续出现"双爽点章"的最小间隔。防止高潮密度过高导致读者疲劳。推荐 2-4 章。',
        'section' => '爽点调度参数',
    ],

    // ========== 章节结构参数 ==========
    'ws_segment_ratio_setup' => [
        'label'   => '铺垫段占比',
        'type'    => 'range',
        'default' => 20,
        'min'     => 10,
        'max'     => 35,
        'step'    => 5,
        'unit'    => '%',
        'desc'    => '每章开头铺垫/引入部分的字数占比。用于交代场景、推进日常、制造悬念。',
        'section' => '章节结构参数',
    ],
    'ws_segment_ratio_rising' => [
        'label'   => '发展段占比',
        'type'    => 'range',
        'default' => 30,
        'min'     => 20,
        'max'     => 40,
        'step'    => 5,
        'unit'    => '%',
        'desc'    => '矛盾升级、冲突酝酿阶段的字数占比。承上启下，逐步拉高读者期待。',
        'section' => '章节结构参数',
    ],
    'ws_segment_ratio_climax' => [
        'label'   => '高潮/爽点释放占比',
        'type'    => 'range',
        'default' => 35,
        'min'     => 20,
        'max'     => 50,
        'step'    => 5,
        'unit'    => '%',
        'desc'    => '爽点爆发、战斗胜利、打脸反转等核心高潮段的字数占比。爽文核心段落，建议不低于25%。',
        'section' => '章节结构参数',
    ],
    'ws_segment_ratio_hook' => [
        'label'   => '钩子收尾占比',
        'type'    => 'range',
        'default' => 15,
        'min'     => 5,
        'max'     => 25,
        'step'    => 5,
        'unit'    => '%',
        'desc'    => '章末钩子/悬念的字数占比。决定读者是否点"下一章"。太短钩子无力，太长拖沓。',
        'section' => '章节结构参数',
    ],

    // ========== 伏笔与记忆参数 ==========
    'ws_foreshadowing_lookback' => [
        'label'   => '伏笔唤醒回溯章数',
        'type'    => 'number',
        'default' => 10,
        'min'     => 3,
        'max'     => 30,
        'step'    => 1,
        'unit'    => '章',
        'desc'    => 'AI 在写章时，会回溯多少章内的已埋设伏笔，从中挑选合适的进行唤醒/回收。',
        'section' => '伏笔与记忆参数',
    ],
    'ws_memory_lookback' => [
        'label'   => '上下文记忆回溯章数',
        'type'    => 'number',
        'default' => 5,
        'min'     => 1,
        'max'     => 15,
        'step'    => 1,
        'unit'    => '章',
        'desc'    => '构建写章 Prompt 时，注入多少章的近期摘要作为上下文。值越大上下文越完整，但 Token 消耗越高。',
        'section' => '伏笔与记忆参数',
    ],
    'ws_embedding_top_k' => [
        'label'   => '语义检索 Top-K',
        'type'    => 'number',
        'default' => 5,
        'min'     => 1,
        'max'     => 20,
        'step'    => 1,
        'unit'    => '条',
        'desc'    => '通过 Embedding 语义检索时，取最相关的多少条记忆原子注入 Prompt。与记忆回溯章数互补。',
        'section' => '伏笔与记忆参数',
    ],

    // ========== AI 生成参数 ==========
    'ws_temperature_outline' => [
        'label'   => '大纲生成 Temperature',
        'type'    => 'range',
        'default' => 0.3,
        'min'     => 0.0,
        'max'     => 1.0,
        'step'    => 0.05,
        'unit'    => '',
        'desc'    => '生成章节大纲时的 AI 温度。低温度（0.2-0.4）输出更稳定、结构更规整；高温度创意更发散。',
        'section' => 'AI 生成参数',
    ],
    'ws_temperature_chapter' => [
        'label'   => '正文生成 Temperature',
        'type'    => 'range',
        'default' => 0.8,
        'min'     => 0.5,
        'max'     => 1.2,
        'step'    => 0.05,
        'unit'    => '',
        'desc'    => '生成章节正文时的 AI 温度。爽文推荐 0.7-0.9：既有创意变化，又不至于放飞自我。',
        'section' => 'AI 生成参数',
    ],
    'ws_max_tokens_outline' => [
        'label'   => '大纲生成 Max Tokens',
        'type'    => 'number',
        'default' => 4096,
        'min'     => 1024,
        'max'     => 8192,
        'step'    => 512,
        'unit'    => 'tokens',
        'desc'    => '生成大纲时允许的最大 Token 数。大纲通常 2000-4000 tokens 足够。',
        'section' => 'AI 生成参数',
    ],
    'ws_max_tokens_chapter' => [
        'label'   => '正文生成 Max Tokens',
        'type'    => 'number',
        'default' => 8192,
        'min'     => 2048,
        'max'     => 16384,
        'step'    => 1024,
        'unit'    => 'tokens',
        'desc'    => '生成正文时允许的最大 Token 数。2000字中文约需 3000-4000 tokens，留足余量防止截断。',
        'section' => 'AI 生成参数',
    ],

    // ========== 质量检查参数 ==========
    'ws_quality_check_enabled' => [
        'label'   => '启用质量检查',
        'type'    => 'select',
        'default' => '1',
        'options' => ['1' => '开启', '0' => '关闭'],
        'desc'    => '每章写完后，是否自动调用 AI 进行质量评分和诊断。开启后可在写作日志中看到评分详情。',
        'section' => '质量检查参数',
    ],
    'ws_quality_min_score' => [
        'label'   => '质量最低分阈值',
        'type'    => 'range',
        'default' => 6.0,
        'min'     => 1.0,
        'max'     => 10.0,
        'step'    => 0.5,
        'unit'    => '分',
        'desc'    => '质量检查评分低于此值时，系统会标记该章为"需优化"，可在写作日志中查看。',
        'section' => '质量检查参数',
    ],
];

// ── 从集中配置同步默认值（Phase 3） ──────────────────────────────
$wsDefaults = getWritingDefaults();
foreach ($wsDefaults as $key => $def) {
    if (isset($paramDefs[$key])) {
        $paramDefs[$key]['default'] = $def['default'];
    }
}

// ── 读取当前值 ──────────────────────────────────────────────────
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

// ── 处理 POST 保存 ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $savedCount = 0;
        foreach ($paramDefs as $key => $def) {
            $rawValue = $_POST[$key] ?? null;
            if ($rawValue === null) {
                continue;
            }
            // 根据类型做基础校验
            if ($def['type'] === 'number') {
                $val = max($def['min'], min($def['max'], (int)$rawValue));
            } elseif ($def['type'] === 'range') {
                $val = max($def['min'], min($def['max'], (float)$rawValue));
            } elseif ($def['type'] === 'select') {
                $val = isset($def['options'][$rawValue]) ? $rawValue : $def['default'];
            } else {
                $val = trim($rawValue);
            }

            if (isset($currentValues[$key])) {
                DB::update('system_settings',
                    ['setting_value' => (string)$val],
                    'setting_key=?', [$key]
                );
            } else {
                DB::insert('system_settings', [
                    'setting_key'   => $key,
                    'setting_value' => (string)$val,
                ]);
            }
            $currentValues[$key] = (string)$val;
            $savedCount++;
        }
        $msg = "已保存 {$savedCount} 项写作参数。";
    } elseif ($action === 'reset') {
        // 重置为默认值
        foreach ($paramDefs as $key => $def) {
            $val = (string)$def['default'];
            if (isset($currentValues[$key])) {
                DB::update('system_settings',
                    ['setting_value' => $val],
                    'setting_key=?', [$key]
                );
            } else {
                DB::insert('system_settings', [
                    'setting_key'   => $key,
                    'setting_value' => $val,
                ]);
            }
            $currentValues[$key] = $val;
        }
        $msg = '所有写作参数已重置为系统默认值。';
    }
}

// ── 按 section 分组 ─────────────────────────────────────────────
$sections = [];
foreach ($paramDefs as $key => $def) {
    $sec = $def['section'];
    if (!isset($sections[$sec])) {
        $sections[$sec] = [];
    }
    $sections[$sec][$key] = $def;
}

// ── 辅助函数：获取当前值（含默认值回退） ─────────────────────────
function wsVal(string $key, array $defs, array $vals): string {
    if (isset($vals[$key])) return $vals[$key];
    return (string)($defs[$key]['default'] ?? '');
}

pageHeader('写作参数设置', 'writing_settings');
?>

<?php if ($msg): ?>
<div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
  <i class="bi bi-check-circle me-2"></i><?= h($msg) ?>
  <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
  <i class="bi bi-exclamation-triangle me-2"></i><?= h($error) ?>
  <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<form method="post" id="ws-form">
  <input type="hidden" name="action" value="save">

  <div class="row g-4">
    <!-- 左侧：参数表单 -->
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

    <!-- 右侧：操作按钮 + 说明 -->
    <div class="col-12 col-lg-4">
      <div class="page-card mb-3" style="position:sticky;top:1rem">
        <div class="page-card-header">
          <i class="bi bi-save me-2"></i>操作
        </div>
        <div class="p-3">
          <button type="submit" class="btn btn-primary w-100 mb-2">
            <i class="bi bi-check-lg me-1"></i>保存所有参数
          </button>
          <button type="submit" class="btn btn-outline-secondary w-100 mb-3"
                  onclick="document.getElementById('ws-form-action').value='reset'">
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

      <!-- 参数统计卡片 -->
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
</form>

<script>
// 重置按钮需要把 action 改成 reset
document.querySelector('button[onclick*="reset"]').addEventListener('click', function(e) {
    e.preventDefault();
    if (!confirm('确定将所有写作参数重置为系统默认值？已保存的自定义配置将丢失。')) return;
    var form = document.getElementById('ws-form');
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'action';
    input.value = 'reset';
    form.appendChild(input);
    form.submit();
});
</script>

<?php pageFooter(); ?>
