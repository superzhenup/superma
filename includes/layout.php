<?php
defined('APP_LOADED') or die('Direct access denied.');

/**
 * 静态资源路径解析：优先使用本地 vendor/ 目录，回退到 CDN。
 * 本地文件存在时完全离线可用；不存在时自动降级到 CDN（无感知）。
 *
 * 部署建议：在项目根目录执行 bash download_assets.sh 一键下载本地资源。
 */
function assetUrl(string $localPath, string $cdnUrl): string {
    $base    = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
    $absPath = $base . '/' . ltrim($localPath, '/');
    return file_exists($absPath) ? $localPath : $cdnUrl;
}

function pageHeader(string $title = '', string $activeNav = ''): void {
    $siteTitle = defined('SITE_NAME') ? SITE_NAME : 'AI小说创作系统';
    $pageTitle = $title ? "$title - $siteTitle" : $siteTitle;

    $bsCss   = assetUrl('assets/vendor/bootstrap.min.css',
                        'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css');
    $iconCss = assetUrl('assets/vendor/bootstrap-icons.min.css',
                        'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css');
    ?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($pageTitle) ?></title>
<link rel="stylesheet" href="<?= h($bsCss) ?>">
<link rel="stylesheet" href="<?= h($iconCss) ?>">
<link rel="stylesheet" href="assets/css/style.css">
<!-- 立即读取主题，避免闪烁 -->
<script>
(function(){
  var t = localStorage.getItem('novel-theme') || 'dark';
  document.documentElement.setAttribute('data-theme', t);
})();
</script>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <span class="brand-icon">✦</span>
    <span class="brand-text">AI小说创作</span>
  </div>
  <nav class="sidebar-nav">
    <a href="index.php"    class="nav-item <?= $activeNav==='home'     ? 'active':'' ?>">
      <i class="bi bi-house-door"></i> 我的书库
    </a>
    <a href="create.php"   class="nav-item <?= $activeNav==='create'   ? 'active':'' ?>">
      <i class="bi bi-plus-circle"></i> 新建小说
    </a>
    <a href="settings.php" class="nav-item <?= $activeNav==='settings' ? 'active':'' ?>">
      <i class="bi bi-cpu"></i> 模型设置
    </a>
  </nav>
  <div class="sidebar-footer">
    <small>Powered by Kianxu（搞定AI）</small>
  </div>
</div>

<!-- Main content -->
<div class="main-content">
  <div class="topbar">
    <button class="sidebar-toggle" onclick="document.getElementById('sidebar').classList.toggle('collapsed')">
      <i class="bi bi-list fs-5"></i>
    </button>
    <h5 class="topbar-title mb-0"><?= h($title ?: $siteTitle) ?></h5>

    <!-- 主题切换 -->
    <button class="theme-toggle" id="theme-toggle" title="切换亮/暗主题">
      <i class="bi bi-moon-stars icon-moon"></i>
      <i class="bi bi-sun icon-sun"></i>
      <span class="label" id="theme-label">暗色</span>
    </button>

    <!-- 用户信息 & 退出 -->
    <?php if (!empty($_SESSION['username'])): ?>
    <div class="d-flex align-items-center gap-2 ms-2">
      <span class="text-muted small d-none d-md-inline">
        <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($_SESSION['username']) ?>
      </span>
      <a href="logout.php" class="btn btn-sm btn-outline-secondary py-1 px-2" title="退出登录"
         onclick="return confirm('确定退出登录？')">
        <i class="bi bi-box-arrow-right"></i>
        <span class="d-none d-md-inline ms-1">退出</span>
      </a>
    </div>
    <?php endif; ?>
  </div>
  <div class="content-area">
<?php
}

function pageFooter(): void {
    $bsJs = assetUrl('assets/vendor/bootstrap.bundle.min.js',
                     'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js');
    ?>
  </div><!-- .content-area -->
</div><!-- .main-content -->

<script src="<?= h($bsJs) ?>"></script>
<script src="assets/js/app.js"></script>
<script src="assets/js/app-export-import.js"></script>
</body>
</html>
<?php
}
