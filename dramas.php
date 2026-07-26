<?php
/**
 * 漫剧项目列表页 - 侧边栏「漫剧制作」总入口
 * 列出当前用户名下全部漫剧项目，点击进入对应项目工作流（drama.php?novel_id=N）
 */
define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/data.php';
require_once __DIR__ . '/includes/drama/DramaService.php';

$currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
$projects = DramaService::listProjectsByUser($currentUserId);

$totalProjects = count($projects);
$completed     = count(array_filter($projects, fn($p) => $p['status'] === 'completed'));
$inProgress    = $totalProjects - $completed;
$totalEpisodes = array_sum(array_map(fn($p) => (int)$p['episode_count'], $projects));

// 项目阶段中文标签（drama_projects.status 枚举）
$stageLabels = [
    'draft'      => '草稿',
    'assets'     => '资产生成',
    'storyboard' => '分镜脚本',
    'imaging'    => '分镜图',
    'video'      => '视频生成',
    'composing'  => '合成中',
    'completed'  => '已完成',
];
$stageColors = [
    'draft'      => '#6b7280',
    'assets'     => '#6366f1',
    'storyboard' => '#0ea5e9',
    'imaging'    => '#f59e0b',
    'video'      => '#ec4899',
    'composing'  => '#8b5cf6',
    'completed'  => '#10b981',
];

pageHeader('漫剧项目', 'dramas');
?>

<!-- Stats row -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(236,72,153,.15);color:#ec4899"><i class="bi bi-film"></i></div>
      <div class="stat-body">
        <div class="stat-number"><?= $totalProjects ?></div>
        <div class="stat-label">漫剧项目</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(245,158,11,.15);color:#f59e0b"><i class="bi bi-camera-reels"></i></div>
      <div class="stat-body">
        <div class="stat-number"><?= $inProgress ?></div>
        <div class="stat-label">制作中</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(16,185,129,.15);color:#10b981"><i class="bi bi-check-circle"></i></div>
      <div class="stat-body">
        <div class="stat-number"><?= $completed ?></div>
        <div class="stat-label">已完结</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(139,92,246,.15);color:#8b5cf6"><i class="bi bi-collection-play"></i></div>
      <div class="stat-body">
        <div class="stat-number"><?= $totalEpisodes ?></div>
        <div class="stat-label">剧集总数</div>
      </div>
    </div>
  </div>
</div>

<!-- Header -->
<div class="d-flex align-items-center justify-content-between mb-3">
  <h6 class="mb-0 fw-semibold text-light">漫剧项目列表</h6>
  <div class="btn-group">
    <a href="index.php" class="btn btn-outline-info btn-sm" title="从书库选择小说开启漫剧制作">
      <i class="bi bi-book me-1"></i>从书库进入
    </a>
  </div>
</div>

<?php if (empty($projects)): ?>
<div class="empty-state">
  <div class="empty-icon"><i class="bi bi-film"></i></div>
  <h5>还没有漫剧项目</h5>
  <p class="text-muted">在书库中选择一部小说，点击「漫剧制作」按钮即可自动创建项目</p>
  <div class="d-flex gap-2 justify-content-center">
    <a href="index.php" class="btn btn-primary">
      <i class="bi bi-book me-1"></i>前往书库
    </a>
  </div>
</div>
<?php else: ?>
<div class="row g-3">
  <?php foreach ($projects as $p):
    $stage   = $p['status'] ?? 'draft';
    $label   = $stageLabels[$stage] ?? '未知';
    $color   = $stageColors[$stage] ?? '#6b7280';
    $updated = !empty($p['updated_at']) ? substr((string)$p['updated_at'], 0, 16) : '-';
  ?>
  <div class="col-12 col-md-6 col-xl-4">
    <div class="page-card" onclick="location.href='drama.php?novel_id=<?= (int)$p['novel_id'] ?>'">
      <div class="page-card-header d-flex align-items-center justify-content-between">
        <span class="text-truncate">
          <i class="bi bi-film me-2"></i><?= h($p['title'] ?: $p['novel_title'] ?: '未命名项目') ?>
        </span>
        <span class="badge" style="background:<?= h($color) ?>"><?= h($label) ?></span>
      </div>
      <div class="p-3">
        <div class="d-flex align-items-center mb-2 text-muted small">
          <i class="bi bi-book me-1"></i>
          <span class="text-truncate"><?= h($p['novel_title'] ?: '小说已删除') ?></span>
        </div>
        <div class="d-flex flex-wrap gap-3 text-muted small">
          <span><i class="bi bi-collection-play me-1"></i><?= (int)$p['episode_count'] ?> 集</span>
          <span><i class="bi bi-aspect-ratio me-1"></i><?= h($p['image_size'] ?: '1280x720') ?></span>
          <span><i class="bi bi-clock me-1"></i><?= h($updated) ?></span>
        </div>
        <div class="d-flex justify-content-end mt-2">
          <a href="drama.php?novel_id=<?= (int)$p['novel_id'] ?>" class="btn btn-outline-primary btn-sm" onclick="event.stopPropagation()">
            <i class="bi bi-box-arrow-in-right me-1"></i>进入项目
          </a>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php pageFooter(); ?>
