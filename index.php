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
  <div class="btn-group">
    <button class="btn btn-outline-success btn-sm" onclick="showExportModal()">
      <i class="bi bi-download me-1"></i>导出
    </button>
    <button class="btn btn-outline-info btn-sm" onclick="showImportModal()">
      <i class="bi bi-upload me-1"></i>导入
    </button>
    <a href="workshop.php" class="btn btn-outline-primary btn-sm">
      <i class="bi bi-lightbulb me-1"></i>创意工坊
    </a>
    <a href="create.php" class="btn btn-primary btn-sm">
      <i class="bi bi-plus-lg me-1"></i>新建小说
    </a>
  </div>
</div>

<?php if (empty($novels)): ?>
<div class="empty-state">
  <div class="empty-icon"><i class="bi bi-book"></i></div>
  <h5>书库空空如也</h5>
  <p class="text-muted">点击下方按钮，开始创作您的第一部AI小说</p>
  <div class="d-flex gap-2 justify-content-center">
    <a href="workshop.php" class="btn btn-outline-primary">
      <i class="bi bi-lightbulb me-1"></i>创意工坊
    </a>
    <a href="create.php" class="btn btn-primary">
      <i class="bi bi-plus-circle me-1"></i>创建小说
    </a>
  </div>
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
        <div class="novel-cover-title"><?= h(safe_substr($novel['title'], 0, 4)) ?></div>
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

<!-- 导出弹窗 -->
<div class="modal fade" id="exportModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content bg-dark text-light">
      <div class="modal-header border-secondary">
        <h5 class="modal-title"><i class="bi bi-download me-2"></i>导出小说数据</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">导出范围</label>
          <select class="form-select bg-dark text-light border-secondary" id="export-scope">
            <option value="all">全部小说（<?= $totalNovels ?>本）</option>
            <?php foreach ($novels as $n): ?>
            <option value="<?= $n['id'] ?>"><?= h($n['title']) ?>（<?= $n['current_chapter'] ?>章）</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" id="export-include-content" checked>
          <label class="form-check-label" for="export-include-content">包含正文内容（文件较大）</label>
        </div>
        <div class="text-muted small">
          <i class="bi bi-info-circle me-1"></i>
          导出为JSON格式，包含小说信息、章节、概要、大纲、人物卡片、伏笔等全部数据
        </div>
      </div>
      <div class="modal-footer border-secondary">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">取消</button>
        <button type="button" class="btn btn-success btn-sm" onclick="doExport()">
          <i class="bi bi-download me-1"></i>开始导出
        </button>
      </div>
    </div>
  </div>
</div>

<!-- 导入弹窗 -->
<div class="modal fade" id="importModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content bg-dark text-light">
      <div class="modal-header border-secondary">
        <h5 class="modal-title"><i class="bi bi-upload me-2"></i>导入小说数据</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">选择JSON文件</label>
          <input type="file" class="form-control bg-dark text-light border-secondary" id="import-file" accept=".json">
        </div>
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" id="import-skip-content">
          <label class="form-check-label" for="import-skip-content">跳过正文（只导入大纲和概要，速度更快）</label>
        </div>
        <div class="text-muted small">
          <i class="bi bi-info-circle me-1"></i>
          支持单本/多本JSON格式，导入时自动分配新ID，不会覆盖已有数据。同名小说会自动添加后缀。
        </div>
      </div>
      <div class="modal-footer border-secondary">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">取消</button>
        <button type="button" class="btn btn-info btn-sm" onclick="doImport()">
          <i class="bi bi-upload me-1"></i>开始导入
        </button>
      </div>
    </div>
  </div>
</div>

<script>
function showExportModal() {
  new bootstrap.Modal(document.getElementById('exportModal')).show();
}

function showImportModal() {
  new bootstrap.Modal(document.getElementById('importModal')).show();
}

// 导出
function doExport() {
  const scope = document.getElementById('export-scope').value;
  const includeContent = document.getElementById('export-include-content').checked;
  let url = 'api/novel_export.php?format=json';
  if (scope !== 'all') {
    url += '&novel_id=' + scope;
  }
  if (!includeContent) {
    // 添加参数告诉API跳过正文
    url += '&skip_content=1';
  }

  // 直接下载
  const link = document.createElement('a');
  link.href = url;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);

  bootstrap.Modal.getInstance(document.getElementById('exportModal')).hide();
  showToast('正在导出...', 'info');
}

// 导入
async function doImport() {
  const fileInput = document.getElementById('import-file');
  const skipContent = document.getElementById('import-skip-content').checked;

  if (!fileInput.files.length) {
    showToast('请先选择JSON文件', 'error');
    return;
  }

  const formData = new FormData();
  formData.append('file', fileInput.files[0]);
  formData.append('import_mode', 'create');
  formData.append('skip_content', skipContent ? '1' : '0');

  try {
    showToast('正在导入，请稍候...', 'info');

    const response = await fetch('api/novel_import.php', {
      method: 'POST',
      body: formData
    });

    const text = await response.text();
    let result;
    try {
      result = JSON.parse(text);
    } catch (e) {
      showToast('导入失败：返回数据格式错误', 'error');
      console.error('JSON解析失败:', text);
      return;
    }

    if (result.success) {
      let msg = `导入成功！共导入 ${result.imported_count} 本小说`;
      msg += `\n章节：${result.total_chapters} 章`;
      msg += `\n人物：${result.total_chars} 个`;
      if (result.error_count > 0) {
        msg += `\n失败：${result.error_count} 本`;
      }
      showToast(msg, 'success');
      bootstrap.Modal.getInstance(document.getElementById('importModal')).hide();

      // 刷新页面
      setTimeout(() => location.reload(), 1500);
    } else {
      showToast('导入失败：' + (result.error || '未知错误'), 'error');
    }
  } catch (err) {
    showToast('导入失败：' + err.message, 'error');
  }
}
</script>
