<?php
/**
 * 开始创作 — 选择写作模式入口页
 *
 * 四种写作模式：
 *   - 高阶写作模式（novel_wizard.php）— 引导式分阶段创作
 *   - 创意工坊（workshop.php）— AI 生成创意框架
 *   - 传统新建（create.php）— 直接填表新建
 *   - 导入续写（import_novel.php）— 导入旧稿 AI 续写
 */
define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/writing_modes.php';

$userId = (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);

$hasOngoingWizard = false;
$ongoingNovel = null;
$progressPercent = 0;
try {
    // 单用户系统：novels 表没有 user_id 列（见 api/wizard.php assertNovelOwned 注释），
    // 旧查询 WHERE n.user_id 直接抛错被吞，导致「继续创作」横幅从不显示
    $row = DB::fetch(
        "SELECT w.novel_id, w.current_stage, w.completed_stages, w.last_active, n.title
         FROM novel_wizard_progress w
         JOIN novels n ON w.novel_id = n.id
         WHERE w.current_stage <> 'completed'
         ORDER BY w.last_active DESC LIMIT 1"
    );
    if ($row && ($row['current_stage'] ?? '') !== 'completed') {
        $hasOngoingWizard = true;
        $ongoingNovel = $row;
        $completedArr = $row['completed_stages']
            ? (json_decode($row['completed_stages'], true) ?: []) : [];
        $newStages = ['topic','blueprint','content','launch'];
        $done = count(array_intersect($newStages, $completedArr));
        $progressPercent = (int)round($done / count($newStages) * 100);
    }
} catch (Throwable $e) { error_log('[start.php] 向导进度查询失败: ' . $e->getMessage()); }

$modes = getWritingModes();

pageHeader('开始创作', 'create');
?>

<style>
.start-hero {
  background: linear-gradient(135deg, rgba(99,102,241,.18), rgba(139,92,246,.10));
  border: 1px solid #3a3a6e;
  border-radius: 20px;
  padding: 2.8rem 2rem 2.2rem;
  margin-bottom: 2rem;
  text-align: center;
  position: relative;
  overflow: hidden;
}
.start-hero::after {
  content: '';
  position: absolute; inset: 0;
  background: radial-gradient(ellipse at 50% 0%, rgba(139,92,246,.12) 0%, transparent 70%);
  pointer-events: none;
}
.start-hero h2 {
  background: linear-gradient(135deg, #a5b4fc, #c4b5fd);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  font-weight: 800;
  font-size: 1.9rem;
  margin-bottom: .4rem;
}
.start-hero p { font-size: 1rem; }

.entry-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 1rem;
}
@media (max-width: 900px) {
  .entry-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 560px) {
  .entry-grid { grid-template-columns: 1fr; }
}

.entry-card {
  background: #1a1a2e;
  border: 1px solid #2d2d4e;
  border-radius: 18px;
  padding: 1.8rem 1.4rem 1.5rem;
  transition: all .25s ease;
  cursor: pointer;
  position: relative;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}
.entry-card::before {
  content: '';
  position: absolute; inset: 0;
  opacity: 0;
  transition: opacity .25s;
  pointer-events: none;
}
.entry-card:hover {
  border-color: #6366f1;
  transform: translateY(-5px);
  box-shadow: 0 16px 40px rgba(99,102,241,.22);
}
.entry-card:hover::before { opacity: 1; }

.entry-card.card-wizard::before   { background: linear-gradient(160deg, rgba(99,102,241,.10), rgba(139,92,246,.05)); }
.entry-card.card-workshop::before { background: linear-gradient(160deg, rgba(245,158,11,.10), rgba(217,119,6,.05)); }
.entry-card.card-classic::before  { background: linear-gradient(160deg, rgba(20,184,166,.10), rgba(13,148,136,.05)); }
.entry-card.card-import::before   { background: linear-gradient(160deg, rgba(34,197,94,.10),  rgba(22,163,74,.05)); }
.entry-card.card-short::before    { background: linear-gradient(160deg, rgba(236,72,153,.10), rgba(244,63,94,.05)); }

.entry-card .icon-wrap {
  width: 58px; height: 58px; border-radius: 16px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.8rem; margin-bottom: 1rem; flex-shrink: 0;
}
.icon-wizard   { background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; }
.icon-workshop { background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; }
.icon-classic  { background: linear-gradient(135deg, #14b8a6, #0d9488); color: #fff; }
.icon-import   { background: linear-gradient(135deg, #22c55e, #16a34a); color: #fff; }
.icon-short   { background: linear-gradient(135deg, #ec4899, #f43f5e); color: #fff; }

.entry-card h5 { color: #e8e8ff; font-weight: 700; font-size: 1rem; margin-bottom: .2rem; }
.entry-card .subtitle { color: #9090b8; font-size: .82rem; margin-bottom: .9rem; }
.entry-card .feature-list {
  font-size: .8rem; color: #b8b8d0;
  padding-left: .95rem; margin: 0;
  flex-grow: 1;
}
.entry-card .feature-list li { margin-bottom: .3rem; line-height: 1.45; }

.entry-card .recommend-badge {
  position: absolute; top: 14px; right: 14px;
  background: linear-gradient(135deg, #f59e0b, #d97706);
  color: #fff; font-size: .68rem; font-weight: 700;
  padding: .15rem .55rem; border-radius: 10px;
  letter-spacing: .02em;
}

.entry-card .cta {
  margin-top: 1.2rem;
  display: flex; align-items: center; justify-content: space-between;
  font-weight: 600; font-size: .85rem;
  border-top: 1px solid #2a2a4a;
  padding-top: .9rem;
}
.card-wizard   .cta { color: #818cf8; }
.card-workshop .cta { color: #fbbf24; }
.card-classic  .cta { color: #2dd4bf; }
.card-import   .cta { color: #4ade80; }
.card-short    .cta { color: #ec4899; }
.entry-card .cta i { transition: transform .2s; }
.entry-card:hover .cta i { transform: translateX(5px); }

.resume-banner {
  background: linear-gradient(135deg, rgba(34,197,94,.12), rgba(22,163,74,.05));
  border: 1px solid #16a34a;
  border-radius: 14px;
  padding: 1rem 1.25rem;
  margin-bottom: 1.5rem;
  display: flex; align-items: center; gap: 1rem;
}
</style>

<div class="container py-4" style="max-width:1280px;">

<?php if ($hasOngoingWizard && $ongoingNovel):
    $stageNames = ['topic'=>'立项','blueprint'=>'蓝图','content'=>'内容','launch'=>'启航'];
    $stageName = $stageNames[$ongoingNovel['current_stage']] ?? $ongoingNovel['current_stage'];
?>
<div class="resume-banner">
  <i class="bi bi-bookmark-star text-success" style="font-size:1.5rem;"></i>
  <div class="flex-grow-1">
    <div class="fw-bold d-flex align-items-center gap-2">
      继续上次的高阶创作
      <span class="badge bg-success"><?= $progressPercent ?>% 完成</span>
    </div>
    <div class="small text-muted">
      《<?= h($ongoingNovel['title']) ?>》 · 当前阶段：<strong><?= h($stageName) ?></strong>
      · 最近活动：<?= h($ongoingNovel['last_active']) ?>
    </div>
    <div class="progress mt-1" style="height:4px;background:#2d2d4e;">
      <div class="progress-bar bg-success" style="width:<?= $progressPercent ?>%"></div>
    </div>
  </div>
  <a href="novel_wizard.php?id=<?= (int)$ongoingNovel['novel_id'] ?>" class="btn btn-success btn-sm">
    <i class="bi bi-play-fill me-1"></i>继续创作
  </a>
</div>
<?php endif; ?>

<div class="start-hero">
  <h2><i class="bi bi-pen-fill me-2" style="font-size:1.5rem;opacity:.8;"></i>开始你的下一本小说</h2>
  <p class="text-muted mb-0">选择适合你的创作方式，AI 全程陪你写</p>
</div>

<div class="entry-grid">
<?php foreach ($modes as $mode): ?>
  <div class="entry-card <?= h($mode['card_class']) ?>"
       onclick="location.href='<?= h($mode['entry_url']) ?>'">
    <?php if (!empty($mode['recommended'])): ?>
    <span class="recommend-badge">⭐ 推荐</span>
    <?php endif; ?>
    <div class="icon-wrap <?= h($mode['icon_class']) ?>">
      <i class="<?= h($mode['icon']) ?>"></i>
    </div>
    <h5><?= h($mode['mode_name']) ?></h5>
    <div class="subtitle"><?= h($mode['description']) ?></div>
    <ul class="feature-list">
      <?php foreach ($mode['features'] as $feat): ?>
      <li><?= h($feat) ?></li>
      <?php endforeach; ?>
    </ul>
    <div class="cta">
      <span>开始创作 <small class="opacity-50 ms-1">~<?= h($mode['estimated_time']) ?></small></span>
      <i class="bi bi-arrow-right"></i>
    </div>
  </div>
<?php endforeach; ?>
</div>

<div class="text-center mt-4">
  <a href="index.php" class="text-muted text-decoration-none small">
    <i class="bi bi-arrow-left me-1"></i>返回书库
  </a>
</div>

</div>

<?php pageFooter(); ?>
