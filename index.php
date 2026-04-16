<?php
define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';

$novels = DB::fetchAll(
    'SELECT n.*, m.name AS model_name
     FROM novels n LEFT JOIN ai_models m ON n.model_id = m.id
     ORDER BY n.updated_at DESC'
);
$totalNovels   = count($novels);
$writingNovels = count(array_filter($novels, fn($n) => $n['status'] === 'writing'));
$totalWords    = array_sum(array_column($novels, 'total_words'));
$totalModels   = DB::count('ai_models');

pageHeader('我的书库', 'home');
?>

<!-- Stats row -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(99,102,241,.15);color:#6366f1"><i class="bi bi-book"></i></div>
      <div class="stat-body">
        <div class="stat-number"><?= $totalNovels ?></div>
        <div class="stat-label">全部小说</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(16,185,129,.15);color:#10b981"><i class="bi bi-pencil-square"></i></div>
      <div class="stat-body">
        <div class="stat-number"><?= $writingNovels ?></div>
        <div class="stat-label">写作中</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(245,158,11,.15);color:#f59e0b"><i class="bi bi-file-text"></i></div>
      <div class="stat-body">
        <div class="stat-number"><?= number_format($totalWords) ?></div>
        <div class="stat-label">累计字数</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(139,92,246,.15);color:#8b5cf6"><i class="bi bi-cpu"></i></div>
      <div class="stat-body">
        <div class="stat-number"><?= $totalModels ?></div>
        <div class="stat-label">AI模型</div>
      </div>
    </div>
  </div>
</div>

<!-- Header -->
<div class="d-flex align-items-center justify-content-between mb-3">
  <h6 class="mb-0 fw-semibold text-light">书库列表</h6>
  <a href="create.php" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg me-1"></i>新建小说
  </a>
</div>

<?php if (empty($novels)): ?>
<div class="empty-state">
  <div class="empty-icon"><i class="bi bi-book"></i></div>
  <h5>书库空空如也</h5>
  <p class="text-muted">点击下方按钮，开始创作您的第一部AI小说</p>
  <a href="create.php" class="btn btn-primary">
    <i class="bi bi-plus-circle me-1"></i>创建第一本小说
  </a>
</div>
<?php else: ?>
<div class="row g-3">
  <?php foreach ($novels as $novel): ?>
  <?php
    $progress = $novel['target_chapters'] > 0
      ? round($novel['current_chapter'] / $novel['target_chapters'] * 100)
      : 0;
    $color = $novel['cover_color'] ?: '#6366f1';
  ?>
  <div class="col-12 col-md-6 col-xl-4">
    <div class="novel-card" onclick="location.href='novel.php?id=<?= $novel['id'] ?>'">
      <div class="novel-cover" style="background: linear-gradient(135deg, <?= h($color) ?>, <?= h($color) ?>99)">
        <div class="novel-cover-title"><?= h(mb_substr($novel['title'], 0, 4)) ?></div>
      </div>
      <div class="novel-info">
        <div class="d-flex align-items-start justify-content-between mb-1">
          <h6 class="novel-title"><?= h($novel['title']) ?></h6>
          <?= statusBadge($novel['status']) ?>
        </div>
        <div class="novel-meta">
          <span><i class="bi bi-tag me-1"></i><?= h($novel['genre'] ?: '未分类') ?></span>
          <span><i class="bi bi-person me-1"></i><?= h($novel['protagonist_name'] ?: '无主角') ?></span>
        </div>
        <div class="novel-meta mt-1">
          <span><i class="bi bi-file-text me-1"></i><?= number_format($novel['total_words']) ?> 字</span>
          <span><i class="bi bi-cpu me-1"></i><?= h($novel['model_name'] ?? '默认模型') ?></span>
        </div>
        <div class="mt-2">
          <div class="d-flex justify-content-between mb-1">
            <small class="text-muted">章节进度</small>
            <small class="text-muted"><?= $novel['current_chapter'] ?>/<?= $novel['target_chapters'] ?> 章</small>
          </div>
          <div class="progress" style="height:4px">
            <div class="progress-bar" style="width:<?= $progress ?>%;background:<?= h($color) ?>"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php pageFooter(); ?>
