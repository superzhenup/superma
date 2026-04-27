<?php
defined('APP_LOADED') or die('Direct access denied.');

/**
 * 静态资源路径解析函数（已弃用）
 * 当前版本强制使用CDN加载Bootstrap资源，确保最新版本和最佳性能。
 * 如需恢复本地资源支持，可取消注释以下函数并修改pageHeader/pageFooter中的引用。
 */
// function assetUrl(string $localPath, string $cdnUrl): string {
//     $base    = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
//     $absPath = $base . '/' . ltrim($localPath, '/');
//     return file_exists($absPath) ? $localPath : $cdnUrl;
// }

function pageHeader(string $title = '', string $activeNav = ''): void {
    $siteTitle = defined('SITE_NAME') ? SITE_NAME : 'AI小说创作系统';
    $pageTitle = $title ? "$title - $siteTitle" : $siteTitle;
    // 确保 session 中有 CSRF token
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    ?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="dark">
<head>
<meta charset="UTF-8">  
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
<title><?= h($pageTitle) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
<link rel="stylesheet" href="assets/css/style.css">
<!-- 立即读取主题，避免闪烁 -->
<script>
(function(){
  var t = localStorage.getItem('novel-theme') || 'dark';
  document.documentElement.setAttribute('data-theme', t);
})();
</script>
<!-- 全局 CSRF 拦截：为同源的写请求自动附加 X-CSRF-Token 头 -->
<script>
(function(){
  var metaEl = document.querySelector('meta[name="csrf-token"]');
  var CSRF_TOKEN = metaEl ? metaEl.getAttribute('content') : '';
  if (!CSRF_TOKEN) return;
  // 暴露给业务代码（需要显式读取时使用）
  window.CSRF_TOKEN = CSRF_TOKEN;

  var SAFE_METHODS = { GET:1, HEAD:1, OPTIONS:1 };
  var origFetch = window.fetch.bind(window);

  window.fetch = function(input, init){
    init = init || {};
    var method = (init.method || (input && input.method) || 'GET').toUpperCase();
    if (SAFE_METHODS[method]) return origFetch(input, init);

    // 仅对同源请求注入（跨域请求不动，避免破坏第三方 API 调用）
    var url = typeof input === 'string' ? input : (input && input.url) || '';
    try {
      var u = new URL(url, window.location.href);
      if (u.origin !== window.location.origin) return origFetch(input, init);
    } catch(e) { /* 相对路径视为同源，继续注入 */ }

    // 合并 headers
    var headers = new Headers(init.headers || {});
    if (!headers.has('X-CSRF-Token')) headers.set('X-CSRF-Token', CSRF_TOKEN);
    init.headers = headers;
    return origFetch(input, init);
  };

  // 兼容老式 XHR（如 FormData 上传场景）
  var origOpen = XMLHttpRequest.prototype.open;
  var origSend = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open = function(method, url){
    this.__csrfMethod = (method || 'GET').toUpperCase();
    try {
      var u = new URL(url, window.location.href);
      this.__csrfSameOrigin = (u.origin === window.location.origin);
    } catch(e) { this.__csrfSameOrigin = true; }
    return origOpen.apply(this, arguments);
  };
  XMLHttpRequest.prototype.send = function(body){
    if (!SAFE_METHODS[this.__csrfMethod] && this.__csrfSameOrigin) {
      try { this.setRequestHeader('X-CSRF-Token', CSRF_TOKEN); } catch(e){}
    }
    return origSend.apply(this, arguments);
  };
})();
</script>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <span class="brand-icon">✦</span>
    <span class="brand-text">Super Ma AI小说创作系统</span>
  </div>
  <nav class="sidebar-nav">
    <a href="index.php"    class="nav-item <?= $activeNav==='home'     ? 'active':'' ?>">
      <i class="bi bi-house-door"></i> 我的书库
    </a>
    <a href="workshop.php" class="nav-item <?= $activeNav==='workshop' ? 'active':'' ?>">
      <i class="bi bi-lightbulb"></i> 创意工坊
    </a>
    <a href="analyze.php"  class="nav-item <?= $activeNav==='analyze'  ? 'active':'' ?>">
      <i class="bi bi-search-heart"></i> 拆书分析
    </a>
    <a href="create.php"   class="nav-item <?= $activeNav==='create'   ? 'active':'' ?>">
      <i class="bi bi-plus-circle"></i> 新建小说
    </a>
    <a href="settings.php" class="nav-item <?= $activeNav==='settings' ? 'active':'' ?>">
      <i class="bi bi-cpu"></i> 模型设置
    </a>
    <a href="writing_settings.php" class="nav-item <?= $activeNav==='writing_settings' ? 'active':'' ?>">
      <i class="bi bi-sliders"></i> 写作参数
    </a>
  </nav>
  <div class="sidebar-footer">
    <small>Super Ma Pro Agents v1.3</small>
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
    ?>
  </div><!-- .content-area -->
</div><!-- .main-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>
<script src="assets/js/app-export-import.js"></script>
<!-- 优化大纲 AJAX 方案 -->
<script src="assets/js/optimize_outline_ajax.js"></script>
<script src="assets/js/optimize_manager.js"></script>
</body>
</html>
<?php
}
