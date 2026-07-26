<?php
/**
 * 高阶写作模式 — 4 阶段向导
 *
 * 流程：选题 → 蓝图 → 内容 → 启航 → novel.php
 */
define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/wizard/schema.php';

$userId = (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);

try {
    DB::execute("CREATE TABLE IF NOT EXISTS `novel_wizard_progress` (
        `novel_id` INT UNSIGNED PRIMARY KEY,
        `current_stage` VARCHAR(20) NOT NULL DEFAULT 'topic',
        `completed_stages` JSON DEFAULT NULL,
        `metadata` JSON DEFAULT NULL,
        `started_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `last_active` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    DB::execute("CREATE TABLE IF NOT EXISTS `novel_wizard_chats` (
        `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `novel_id` INT UNSIGNED NOT NULL,
        `stage` VARCHAR(20) NOT NULL,
        `role` ENUM('user','assistant','system') NOT NULL,
        `content` MEDIUMTEXT NOT NULL,
        `model_id` INT UNSIGNED DEFAULT NULL,
        `tokens` INT UNSIGNED DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_novel_stage` (`novel_id`, `stage`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    DB::execute("CREATE TABLE IF NOT EXISTS `volume_outlines` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `novel_id` INT UNSIGNED NOT NULL,
        `volume_index` INT UNSIGNED NOT NULL,
        `title` VARCHAR(200) DEFAULT '',
        `theme` TEXT DEFAULT NULL,
        `chapter_from` INT UNSIGNED DEFAULT 1,
        `chapter_to` INT UNSIGNED DEFAULT 50,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_novel` (`novel_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 兼容旧 install.php 创建的表结构（迁移逻辑见 includes/wizard/schema.php 单一来源）
    wizardMigrateVolumeOutlines();
} catch (Throwable $e) {
    error_log('novel_wizard.php table creation failed: ' . $e->getMessage());
}

wizardEnsureSchema();

$STAGES = wizardStages();

$novelId  = (int)($_GET['id'] ?? 0);
$novel    = null;
$progress = null;

if ($novelId > 0) {
    $novel = getNovel($novelId);
    if (!$novel) { header('Location: start.php'); exit; }
    // 审计修复 P2-14（2026-07-12）：页面级归属权校验，防止越权访问
    $novel = requireNovelAccess($novel, 'start.php');

    $progress = DB::fetch("SELECT * FROM novel_wizard_progress WHERE novel_id = ?", [$novelId]);
    if (!$progress) {
        DB::insert('novel_wizard_progress', [
            'novel_id'      => $novelId,
            'current_stage' => 'topic',
        ]);
        $progress = DB::fetch("SELECT * FROM novel_wizard_progress WHERE novel_id = ?", [$novelId]);
    }
}

$stage = $_GET['stage'] ?? ($progress['current_stage'] ?? 'topic');
if (function_exists('wizardStageRedirect')) {
    $stage = wizardStageRedirect($stage);
}
if (!isset($STAGES[$stage])) $stage = 'topic';

// 中途退出保存进度：记录当前停留的阶段（带 last_active 时间戳），
// 下次进入向导 / start.php「继续创作」横幅都能回到这里
if ($novelId > 0 && $progress && $stage !== ($progress['current_stage'] ?? '')) {
    try {
        DB::update('novel_wizard_progress', ['current_stage' => $stage], 'novel_id = ?', [$novelId]);
        $progress['current_stage'] = $stage;
    } catch (Throwable $e) {
        error_log('novel_wizard.php update current_stage failed: ' . $e->getMessage());
    }
}

$completed = ($progress && $progress['completed_stages'])
    ? (json_decode($progress['completed_stages'], true) ?: [])
    : [];

pageHeader('高阶创作 - ' . $STAGES[$stage]['name'], 'create');
?>

<style>
.wizard-shell { max-width: 1180px; margin: 0 auto; }
.wizard-progress {
  background: #1a1a2e; border: 1px solid #2d2d4e; border-radius: 14px;
  padding: 1rem 1.25rem; margin-bottom: 1.5rem;
}
.stage-list { display: flex; flex-wrap: wrap; gap: .35rem; }
.stage-pill {
  flex: 1 1 0; min-width: 110px;
  background: #0f0f1a; border: 1px solid #2d2d4e;
  border-radius: 10px; padding: .55rem .65rem; cursor: pointer;
  transition: all .2s; display: flex; align-items: center; gap: .5rem;
  color: #a0a0c0; text-decoration: none;
}
.stage-pill:hover { border-color: #6366f1; color: #e0e0f0; }
.stage-pill.active { background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; border-color: transparent; }
.stage-pill.done { color: #22c55e; border-color: rgba(34,197,94,.4); }
.stage-pill .stage-num {
  width: 22px; height: 22px; border-radius: 50%;
  background: rgba(255,255,255,.08); display: flex;
  align-items: center; justify-content: center; font-size: .75rem; flex-shrink: 0;
}
.stage-pill.active .stage-num { background: rgba(255,255,255,.2); }
.stage-pill.done .stage-num { background: #22c55e; color: #fff; }
.stage-pill .stage-name { font-size: .85rem; font-weight: 500; }

.stage-content {
  background: #1a1a2e; border: 1px solid #2d2d4e; border-radius: 14px;
  padding: 1.75rem 2rem; min-height: 400px;
}
.stage-header { margin-bottom: 1.25rem; padding-bottom: .85rem; border-bottom: 1px solid #2d2d4e; }
.stage-header h4 { color: #e0e0f0; margin-bottom: .25rem; }
.stage-header .stage-desc { color: #a0a0c0; font-size: .9rem; }

.stage-footer {
  display: flex; justify-content: space-between; align-items: center;
  margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #2d2d4e;
}
</style>

<div class="wizard-shell">

  <div class="wizard-progress">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <div>
        <strong class="text-light">
          <?php if ($novel): ?>
            《<?= h($novel['title'] ?: '未命名') ?>》
          <?php else: ?>
            新建小说
          <?php endif; ?>
        </strong>
        <span class="text-muted small ms-2">· 高阶创作模式</span>
      </div>
      <div class="text-muted small">
        <?= count($completed) ?>/<?= count($STAGES) ?> 已完成
      </div>
    </div>
    <div class="stage-list">
      <?php $i = 1; foreach ($STAGES as $key => $s):
        $cls = '';
        if ($stage === $key) $cls = 'active';
        elseif (in_array($key, $completed, true)) $cls = 'done';
        $href = $novelId ? "?id={$novelId}&stage={$key}" : ($key === 'topic' ? '?' : '#');
        $disabled = !$novelId && $key !== 'topic';
      ?>
        <?php if ($disabled): ?>
          <span class="stage-pill" style="opacity:.4;cursor:not-allowed" title="先完成选题">
            <span class="stage-num"><?= $i ?></span>
            <span class="stage-name"><?= h($s['name']) ?></span>
          </span>
        <?php else: ?>
          <a href="<?= $href ?>" class="stage-pill <?= $cls ?>">
            <span class="stage-num">
              <?= in_array($key, $completed, true) ? '✓' : $i ?>
            </span>
            <span class="stage-name"><?= h($s['name']) ?></span>
          </a>
        <?php endif; ?>
      <?php $i++; endforeach; ?>
    </div>
  </div>

  <div class="stage-content">
    <div class="stage-header">
      <h4><i class="<?= h($STAGES[$stage]['icon']) ?> me-2 text-info"></i><?= h($STAGES[$stage]['name']) ?></h4>
      <div class="stage-desc"><?= h($STAGES[$stage]['desc']) ?></div>
    </div>

    <?php
    $partialPath = __DIR__ . '/includes/wizard/' . $stage . '.php';
    if (file_exists($partialPath)) {
        require $partialPath;
    } else {
        ?>
        <div class="text-center py-5">
          <i class="bi bi-hourglass-split" style="font-size:3rem;opacity:.3;"></i>
          <h6 class="mt-3 text-muted">即将开放</h6>
          <p class="small text-muted">本阶段功能正在开发中。</p>
        </div>
        <?php
    }
    ?>
  </div>

  <?php
    $keys = array_keys($STAGES);
    $idx = array_search($stage, $keys, true);
    $prevKey = $idx > 0 ? $keys[$idx - 1] : null;
    $nextKey = $idx < count($keys) - 1 ? $keys[$idx + 1] : null;
  ?>
  <div class="stage-footer">
    <div>
      <?php if ($prevKey && $novelId): ?>
        <a href="?id=<?= $novelId ?>&stage=<?= $prevKey ?>" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-chevron-left me-1"></i>上一步：<?= h($STAGES[$prevKey]['name']) ?>
        </a>
      <?php endif; ?>
    </div>
    <div>
      <?php if ($novelId): ?>
        <a href="novel.php?id=<?= $novelId ?>" class="btn btn-outline-secondary btn-sm me-2">
          <i class="bi bi-box-arrow-up-right me-1"></i>退出向导
        </a>
      <?php endif; ?>
      <?php if ($nextKey && $novelId && in_array($stage, $completed, true)): ?>
        <a href="?id=<?= $novelId ?>&stage=<?= $nextKey ?>" class="btn btn-primary btn-sm">
          下一步：<?= h($STAGES[$nextKey]['name']) ?><i class="bi bi-chevron-right ms-1"></i>
        </a>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php pageFooter(); ?>
