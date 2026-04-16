<?php
define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';

$id    = (int)($_GET['id'] ?? 0);
$novel = getNovel($id);
if (!$novel) { header('Location: index.php'); exit; }

$chapters = getNovelChapters($id);
$models   = DB::fetchAll('SELECT * FROM ai_models ORDER BY is_default DESC, id ASC');
$logs     = DB::fetchAll(
    'SELECT * FROM writing_logs WHERE novel_id=? ORDER BY created_at DESC LIMIT 20',
    [$id]
);

// 性能优化：一次批量查出所有章节的 synopsis，消除 N+1 查询。
// 原代码在 foreach 循环中每章单独 SELECT，100章小说会产生100次额外查询。
$synopsisRows = DB::fetchAll(
    'SELECT chapter_number, synopsis FROM chapter_synopses WHERE novel_id=?',
    [$id]
);
$synopsisMap = array_column($synopsisRows, 'synopsis', 'chapter_number');

$outlined  = count(array_filter($chapters, fn($c) => in_array($c['status'], ['outlined','writing','completed'])));
$completed = count(array_filter($chapters, fn($c) => $c['status'] === 'completed'));
$progress  = $novel['target_chapters'] > 0 ? round($completed / $novel['target_chapters'] * 100) : 0;
$created   = isset($_GET['created']);
$saved     = isset($_GET['saved']);

pageHeader('小说管理 - ' . $novel['title'], 'home');
?>

<?php if ($created): ?>
<div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
  <i class="bi bi-check-circle me-2"></i>小说创建成功！请先生成章节大纲，然后开始写作。
  <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($saved): ?>
<div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
  <i class="bi bi-check-circle me-2"></i>小说设定已保存！
  <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Novel Header Card -->
<div class="novel-header-card mb-4" style="border-left: 4px solid <?= h($novel['cover_color']) ?>">
  <div class="d-flex align-items-start gap-4 flex-wrap">
    <div class="novel-cover-sm" style="background:linear-gradient(135deg,<?= h($novel['cover_color']) ?>,<?= h($novel['cover_color']) ?>99)">
      <?= h(mb_substr($novel['title'], 0, 4)) ?>
    </div>
    <div class="flex-grow-1 min-w-0">
      <div class="d-flex align-items-center gap-2 mb-1">
        <h4 class="mb-0 text-light fw-bold"><?= h($novel['title']) ?></h4>
        <?= statusBadge($novel['status']) ?>
      </div>
      <div class="d-flex gap-3 novel-meta-tags flex-wrap mb-2">
        <span><i class="bi bi-tag me-1"></i><?= h($novel['genre'] ?: '未分类') ?></span>
        <span><i class="bi bi-brush me-1"></i><?= h($novel['writing_style'] ?: '未设定') ?></span>
        <span><i class="bi bi-person me-1"></i><?= h($novel['protagonist_name'] ?: '未设定') ?></span>
        <span><i class="bi bi-calendar me-1"></i><?= substr($novel['created_at'], 0, 10) ?></span>
      </div>
      <div class="d-flex gap-4 text-muted small mb-3 flex-wrap">
        <span class="text-light fw-semibold"><?= number_format($novel['total_words']) ?> <small class="text-muted fw-normal">字</small></span>
        <span class="text-light fw-semibold"><?= $completed ?>/<?= $novel['target_chapters'] ?> <small class="text-muted fw-normal">章</small></span>
        <span class="text-light fw-semibold"><?= $outlined ?> <small class="text-muted fw-normal">章已大纲</small></span>
      </div>
      <div class="progress mb-3" style="height:6px;max-width:400px">
        <div class="progress-bar" style="width:<?= $progress ?>%;background:<?= h($novel['cover_color']) ?>" title="<?= $progress ?>%"></div>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <!-- 生成全书故事大纲 -->
        <button class="btn btn-sm btn-outline-primary" id="btn-story-outline"
                data-novel="<?= $id ?>"
                <?= $novel['has_story_outline'] ? 'disabled title="全书故事大纲已生成"' : '' ?>>
          <i class="bi bi-map me-1"></i><?= $novel['has_story_outline'] ? '已生成故事大纲' : '生成全书故事大纲' ?>
        </button>
        <!-- 生成大纲 -->
        <button class="btn btn-sm btn-outline-info" id="btn-outline"
                data-novel="<?= $id ?>"
                data-outlined="<?= $outlined ?>"
                data-target="<?= $novel['target_chapters'] ?>"
                <?= !$novel['has_story_outline'] ? 'disabled title="请先生成全书故事大纲"' : '' ?>>
          <i class="bi bi-list-ol me-1"></i>生成章节大纲
        </button>
        <!-- 生成章节概要 -->
        <button class="btn btn-sm btn-outline-secondary" id="btn-synopsis"
                data-novel="<?= $id ?>"
                data-outlined="<?= $outlined ?>"
                <?= $outlined === 0 ? 'disabled title="请先生成章节大纲"' : '' ?>>
          <i class="bi bi-file-text me-1"></i>生成章节概要
        </button>
        <!-- 补写大纲 -->
        <button class="btn btn-sm btn-outline-warning" id="btn-supplement-outline"
                data-novel="<?= $id ?>"
                data-outlined="<?= $outlined ?>"
                data-target="<?= $novel['target_chapters'] ?>"
                <?= $outlined === 0 ? 'disabled title="请先生成大纲"' : '' ?>>
          <i class="bi bi-patch-plus me-1"></i>补写缺失大纲
        </button>
        <!-- 优化大纲逻辑 -->
        <button class="btn btn-sm btn-outline-success" id="btn-optimize-outline"
                data-novel="<?= $id ?>"
                <?= (!$novel['has_story_outline'] || $outlined === 0) ? 'disabled title="请先生成全书故事大纲和章节大纲"' : '' ?>>
          <i class="bi bi-lightning-charge me-1"></i>优化大纲逻辑
        </button>
        <!-- 导入章节概要（创建后即可用）-->
        <button class="btn btn-sm btn-outline-primary" id="btn-import-synopsis-top"
                onclick="document.getElementById('import-file-input-top').click()">
          <i class="bi bi-upload me-1"></i>导入章节概要
        </button>
        <input type="file" id="import-file-input-top" accept=".json,.csv,.txt" style="display:none"
               onchange="importSynopses(this.files[0])">
        <!-- 自动写作 -->
        <button class="btn btn-sm btn-primary" id="btn-autowrite"
                data-novel="<?= $id ?>"
                data-status="<?= h($novel['status']) ?>"
                <?= $outlined === 0 ? 'disabled title="请先生成大纲"' : '' ?>>
          <i class="bi bi-play-fill me-1"></i>
          <?= $novel['status'] === 'writing' ? '暂停写作' : '自动写作' ?>
        </button>
        <!-- 写下一章 -->
        <button class="btn btn-sm btn-outline-primary" id="btn-next-chapter"
                data-novel="<?= $id ?>"
                <?= $outlined === 0 ? 'disabled title="请先生成大纲"' : '' ?>>
          <i class="bi bi-skip-forward me-1"></i>写下一章
        </button>
        <!-- 取消写作 -->
        <button class="btn btn-sm btn-outline-warning" id="btn-cancel-write"
                data-novel="<?= $id ?>"
                <?= $novel['status'] !== 'writing' ? 'disabled title="没有正在进行的写作"' : '' ?>>
          <i class="bi bi-x-circle me-1"></i>取消写作
        </button>
        <!-- 重置未完成章节 -->
        <button class="btn btn-sm btn-outline-secondary" id="btn-reset-chapters"
                data-novel="<?= $id ?>"
                <?= $completed === $outlined ? 'disabled title="没有未完成的章节"' : '' ?>>
          <i class="bi bi-arrow-counterclockwise me-1"></i>重置未完成
        </button>
        <!-- [v4] 一致性检查 -->
        <button class="btn btn-sm btn-outline-info" id="btn-consistency-check"
                data-novel="<?= $id ?>"
                <?= $completed === 0 ? 'disabled title="请先完成至少一章"' : '' ?>>
          <i class="bi bi-shield-check me-1"></i>一致性检查
        </button>
        <a href="create.php?edit=<?= $id ?>" class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-pencil me-1"></i>编辑设定
        </a>
        <button class="btn btn-sm btn-outline-danger" onclick="event.stopPropagation(); deleteNovel(<?= $id ?>)">
          <i class="bi bi-trash me-1"></i>删除
        </button>
        <a href="api/export_novel.php?id=<?= $id ?>" class="btn btn-sm btn-outline-success" <?= $completed === 0 ? 'disabled title="暂无已完成的章节"' : '' ?>>
          <i class="bi bi-download me-1"></i>导出小说
        </a>
      </div>
    </div>
    <!-- Model select -->
    <div class="model-switcher">
      <label class="form-label small text-muted mb-1">AI 模型</label>
      <select class="form-select form-select-sm" id="model-select" data-novel="<?= $id ?>">
        <option value="">默认模型</option>
        <?php foreach ($models as $m): ?>
        <option value="<?= $m['id'] ?>" <?= $novel['model_id'] == $m['id'] ? 'selected' : '' ?>>
          <?= h($m['name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
</div>

<!-- Story Outline Card -->
<?php
$storyOutline = DB::fetch('SELECT * FROM story_outlines WHERE novel_id = ?', [$id]);
?>
<div id="story-outline-card" class="mb-3 page-card" <?= !$storyOutline ? 'style="display:none"' : '' ?>>
  <div class="p-3 border-bottom border-secondary d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-2">
      <i class="bi bi-map text-primary fs-5"></i>
      <span class="fw-semibold text-light">全书故事大纲</span>
      <?php if ($storyOutline): ?>
      <span class="badge bg-success ms-2">已生成</span>
      <?php endif; ?>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-sm btn-outline-primary" id="btn-edit-story-outline" data-novel="<?= $id ?>">
        <i class="bi bi-pencil me-1"></i>编辑
      </button>
      <button class="btn btn-sm btn-outline-info" id="btn-regenerate-story-outline" data-novel="<?= $id ?>">
        <i class="bi bi-arrow-clockwise me-1"></i>重新生成
      </button>
    </div>
  </div>
  <div class="p-3" id="story-outline-content">
    <?php if ($storyOutline): ?>
    <div class="mb-3">
      <h6 class="text-muted small mb-2"><i class="bi bi-diagram-3 me-1"></i>故事主线</h6>
      <div class="text-light" style="white-space: pre-wrap; line-height: 1.8;"><?= h($storyOutline['story_arc']) ?></div>
    </div>
    <?php if ($storyOutline['character_arcs']): ?>
    <div class="mb-3">
      <h6 class="text-muted small mb-2"><i class="bi bi-people me-1"></i>人物成长轨迹</h6>
      <div class="text-light" style="white-space: pre-wrap; line-height: 1.8;"><?= h($storyOutline['character_arcs']) ?></div>
    </div>
    <?php endif; ?>
    <?php if ($storyOutline['world_evolution']): ?>
    <div class="mb-3">
      <h6 class="text-muted small mb-2"><i class="bi bi-globe me-1"></i>世界观演变</h6>
      <div class="text-light" style="white-space: pre-wrap; line-height: 1.8;"><?= h($storyOutline['world_evolution']) ?></div>
    </div>
    <?php endif; ?>
    <div class="text-muted small mt-3">
      <i class="bi bi-clock me-1"></i>生成时间: <?= $storyOutline['created_at'] ?>
      <?php if ($storyOutline['updated_at'] !== $storyOutline['created_at']): ?>
      <span class="ms-3"><i class="bi bi-pencil-square me-1"></i>最后编辑: <?= $storyOutline['updated_at'] ?></span>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="text-center text-muted py-4">
      <i class="bi bi-inbox fs-1 d-block mb-2"></i>
      <p>暂无故事大纲，请点击"生成全书故事大纲"按钮</p>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Story Outline Edit Modal -->
<div class="modal fade" id="storyOutlineModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content bg-dark border-secondary">
      <div class="modal-header border-secondary">
        <h5 class="modal-title text-light"><i class="bi bi-pencil-square me-2"></i>编辑故事大纲</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="edit-novel-id" value="<?= $id ?>">
        <div class="mb-3">
          <label class="form-label text-light">故事主线 <span class="text-danger">*</span></label>
          <textarea class="form-control bg-secondary border-secondary text-light" id="edit-story-arc" rows="8" 
                    placeholder="描述整个故事的主线发展..."></textarea>
        </div>
        <div class="mb-3">
          <label class="form-label text-light">人物成长轨迹</label>
          <textarea class="form-control bg-secondary border-secondary text-light" id="edit-character-arcs" rows="4"
                    placeholder="描述主要人物的成长轨迹..."></textarea>
        </div>
        <div class="mb-3">
          <label class="form-label text-light">世界观演变</label>
          <textarea class="form-control bg-secondary border-secondary text-light" id="edit-world-evolution" rows="4"
                    placeholder="描述世界观如何随着故事发展而演变..."></textarea>
        </div>
      </div>
      <div class="modal-footer border-secondary">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
        <button type="button" class="btn btn-primary" id="btn-save-story-outline">
          <i class="bi bi-check-lg me-1"></i>保存
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Auto-write panel (hidden by default) -->
<div id="write-progress-wrap" class="mb-3" style="display:none">
  <div class="page-card">
    <!-- 头部：进度 + 控制 -->
    <div class="p-3 border-bottom border-secondary">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="d-flex align-items-center gap-2">
          <div class="spinner-border spinner-border-sm text-primary" id="write-spinner"></div>
          <span class="fw-semibold text-light" id="write-progress-label">正在写作...</span>
        </div>
        <button class="btn btn-sm btn-outline-danger" id="btn-stop-write" onclick="stopAutoWrite()">
          <i class="bi bi-stop-fill me-1"></i>停止
        </button>
      </div>
      <div class="progress mb-2" style="height:6px">
        <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary"
             id="write-progress-bar" style="width:0%"></div>
      </div>
      <div class="d-flex justify-content-between small">
        <span class="text-muted" id="write-progress-detail"></span>
        <span class="text-muted" id="write-model-label"></span>
      </div>
    </div>
    <!-- 实时流式内容 -->
    <div id="write-stream-box" class="write-stream-box">
      <span class="outline-stream-cursor" id="write-cursor"></span>
    </div>
  </div>
</div>

<!-- Generate outline progress (hidden) -->
<div id="outline-progress-wrap" class="mb-3" style="display:none">
  <div class="page-card">
    <!-- Header -->
    <div class="p-3 border-bottom border-secondary d-flex align-items-center justify-content-between">
      <div class="d-flex align-items-center gap-2">
        <div class="spinner-border spinner-border-sm text-info" id="outline-spinner"></div>
        <span class="fw-semibold text-light" id="outline-progress-label">正在生成大纲...</span>
      </div>
      <div class="d-flex gap-3 small" id="outline-token-bar" style="display:none">
        <span class="text-muted">输入 <span class="text-info fw-semibold" id="tok-prompt">0</span></span>
        <span class="text-muted">输出 <span class="text-success fw-semibold" id="tok-completion">0</span></span>
        <span class="text-muted">合计 <span class="text-warning fw-semibold" id="tok-total">0</span></span>
      </div>
    </div>
    <!-- Streaming raw output -->
    <div id="outline-stream-box" class="outline-stream-box">
      <span class="outline-stream-cursor"></span>
    </div>
    <!-- Batch log -->
    <div id="outline-batch-log" class="p-2 border-top border-secondary" style="display:none">
    </div>
  </div>
</div>

<!-- Story outline progress (hidden) -->
<div id="story-outline-progress-wrap" class="mb-3" style="display:none">
  <div class="page-card">
    <div class="p-3 border-bottom border-secondary d-flex align-items-center justify-content-between">
      <div class="d-flex align-items-center gap-2">
        <div class="spinner-border spinner-border-sm text-primary"></div>
        <span class="fw-semibold text-light" id="story-outline-progress-label">正在生成全书故事大纲...</span>
      </div>
    </div>
    <div id="story-outline-stream-box" class="outline-stream-box">
      <span class="outline-stream-cursor"></span>
    </div>
  </div>
</div>

<!-- Optimize outline progress (hidden) -->
<div id="optimize-outline-progress-wrap" class="mb-3" style="display:none">
  <div class="page-card">
    <div class="p-3 border-bottom border-secondary d-flex align-items-center justify-content-between">
      <div class="d-flex align-items-center gap-2">
        <div class="spinner-border spinner-border-sm text-success"></div>
        <span class="fw-semibold text-light" id="optimize-outline-progress-label">正在优化大纲逻辑...</span>
      </div>
      <span class="text-muted small" id="optimize-outline-stats"></span>
    </div>
    <div id="optimize-outline-stream-box" class="outline-stream-box">
      <span class="outline-stream-cursor"></span>
    </div>
    <div id="optimize-outline-batch-log" class="p-2 border-top border-secondary" style="display:none"></div>
  </div>
</div>

<!-- Chapter synopsis progress (hidden) -->
<div id="synopsis-progress-wrap" class="mb-3" style="display:none">
  <div class="page-card">
    <div class="p-3 border-bottom border-secondary d-flex align-items-center justify-content-between">
      <div class="d-flex align-items-center gap-2">
        <div class="spinner-border spinner-border-sm text-secondary"></div>
        <span class="fw-semibold text-light" id="synopsis-progress-label">正在生成章节概要...</span>
      </div>
    </div>
    <div id="synopsis-stream-box" class="outline-stream-box">
      <span class="outline-stream-cursor"></span>
    </div>
  </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs novel-tabs mb-3" id="novelTabs">
  <li class="nav-item">
    <a class="nav-link active" data-bs-toggle="tab" href="#tab-chapters">
      <i class="bi bi-list-ul me-1"></i>章节列表 <span class="badge bg-secondary ms-1"><?= count($chapters) ?></span>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link" data-bs-toggle="tab" href="#tab-settings">
      <i class="bi bi-gear me-1"></i>小说设定
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link" data-bs-toggle="tab" href="#tab-logs">
      <i class="bi bi-clock-history me-1"></i>操作日志
    </a>
  </li>
</ul>

<div class="tab-content">

  <!-- Chapters Tab -->
  <div class="tab-pane fade show active" id="tab-chapters">
    <?php if (empty($chapters)): ?>
    <div class="empty-state">
      <div class="empty-icon"><i class="bi bi-list-ol"></i></div>
      <h6>暂无章节</h6>
      <p class="text-muted small">点击「生成章节大纲」按钮，AI将自动生成所有章节的大纲</p>
    </div>
    <?php else: ?>
    <div class="page-card">
      <!-- 导出/导入按钮组 -->
      <div class="d-flex justify-content-end align-items-center mb-3 gap-2">
        <div class="btn-group">
          <button type="button" class="btn btn-sm btn-outline-success dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-download me-1"></i>导出章节概要
          </button>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="javascript:void(0)" onclick="exportSynopses('json')">
              <i class="bi bi-filetype-json me-2"></i>导出为JSON
            </a></li>
            <li><a class="dropdown-item" href="javascript:void(0)" onclick="exportSynopses('excel')">
              <i class="bi bi-file-earmark-excel me-2"></i>导出为Excel
            </a></li>
            <li><a class="dropdown-item" href="javascript:void(0)" onclick="exportSynopses('txt')">
              <i class="bi bi-filetype-txt me-2"></i>导出为TXT
            </a></li>
          </ul>
        </div>
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="document.getElementById('import-file-input').click()">
          <i class="bi bi-upload me-1"></i>导入章节概要
        </button>
        <input type="file" id="import-file-input" accept=".json,.csv,.txt" style="display:none" onchange="importSynopses(this.files[0])">
      </div>
      <!-- 章节列表头 -->
      <div class="chapter-list-header">
        <span class="col-num">章节</span>
        <span class="col-title">标题 / 大纲概要</span>
        <span class="col-status">状态</span>
        <span class="col-words">字数</span>
        <span class="col-action">操作</span>
      </div>
      <!-- 章节行 -->
      <?php foreach ($chapters as $ch): ?>
      <?php
        $statusColor = [
          'pending'   => 'var(--text-muted)',
          'outlined'  => 'var(--info)',
          'writing'   => 'var(--warning)',
          'completed' => 'var(--success)',
        ][$ch['status']] ?? 'var(--text-muted)';

        // 性能优化：从预加载的 $synopsisMap 中 O(1) 取值，无需再查数据库
        $synopsisText = $synopsisMap[$ch['chapter_number']] ?? null;
        $synopsis = $synopsisText ? ['synopsis' => $synopsisText] : null;
      ?>
      <div class="chapter-list-row" data-status="<?= h($ch['status']) ?>">
        <div class="col-num">
          <span class="ch-number">第<?= $ch['chapter_number'] ?>章</span>
        </div>
        <div class="col-title">
          <div class="ch-title"><?= h($ch['title'] ?: '（待生成标题）') ?></div>
          <?php if ($ch['outline']): ?>
          <div class="ch-outline"><?= h(mb_substr($ch['outline'], 0, 80)) ?><?= mb_strlen($ch['outline']) > 80 ? '…' : '' ?></div>
          <?php endif; ?>
          <?php if ($synopsis && $synopsis['synopsis']): ?>
          <div class="ch-synopsis mt-1">
            <span class="badge bg-secondary me-1">概要</span>
            <small class="text-muted"><?= h(mb_substr($synopsis['synopsis'], 0, 100)) ?><?= mb_strlen($synopsis['synopsis']) > 100 ? '…' : '' ?></small>
          </div>
          <?php endif; ?>
        </div>
        <div class="col-status"><?= statusBadge($ch['status']) ?></div>
        <div class="col-words">
          <?= $ch['words'] > 0 ? '<span class="ch-words">' . number_format($ch['words']) . '</span>' : '<span class="ch-words-empty">—</span>' ?>
        </div>
        <div class="col-action">
          <?php if ($ch['status'] !== 'pending'): ?>
          <button class="btn btn-xs btn-outline-secondary btn-edit-synopsis"
                  data-novel="<?= $id ?>" data-chapter="<?= $ch['chapter_number'] ?>"
                  title="编辑章节概要">
            <i class="bi bi-file-text"></i>
          </button>
          <?php if ($synopsis && $synopsis['synopsis']): ?>
          <button class="btn btn-xs btn-outline-warning btn-optimize-synopsis"
                  data-novel="<?= $id ?>" data-chapter="<?= $ch['chapter_number'] ?>"
                  title="优化章节概要">
            <i class="bi bi-magic"></i>
          </button>
          <?php endif; ?>
          <?php endif; ?>
          <?php if ($ch['status'] === 'completed'): ?>
          <a href="chapter.php?id=<?= $ch['id'] ?>" class="btn btn-xs btn-outline-info">
            <i class="bi bi-eye"></i> 查看
          </a>
          <?php elseif ($ch['status'] === 'outlined'): ?>
          <button class="btn btn-xs btn-outline-primary btn-write-single"
                  data-novel="<?= $id ?>" data-chapter="<?= $ch['id'] ?>">
            <i class="bi bi-pencil"></i> 写作
          </button>
          <?php elseif ($ch['status'] === 'writing'): ?>
          <button class="btn btn-xs btn-outline-warning" onclick="resetSingleChapter(<?= $ch['id'] ?>)" title="取消并重置">
            <i class="bi bi-x-circle"></i> 取消
          </button>
          <?php else: ?>
          <span class="ch-status-text">待大纲</span>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Settings Tab -->
  <div class="tab-pane fade" id="tab-settings">
    <div class="page-card p-4">
      <div class="row g-3">
        <div class="col-md-6">
          <div class="setting-item">
            <div class="setting-label">主角信息</div>
            <div class="setting-value"><?= nl2br(h($novel['protagonist_info'] ?: '未设定')) ?></div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="setting-item">
            <div class="setting-label">情节设定</div>
            <div class="setting-value"><?= nl2br(h($novel['plot_settings'] ?: '未设定')) ?></div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="setting-item">
            <div class="setting-label">世界观设定</div>
            <div class="setting-value"><?= nl2br(h($novel['world_settings'] ?: '未设定')) ?></div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="setting-item">
            <div class="setting-label">额外设定</div>
            <div class="setting-value"><?= nl2br(h($novel['extra_settings'] ?: '无')) ?></div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="setting-item">
            <div class="setting-label">目标章数</div>
            <div class="setting-value"><?= $novel['target_chapters'] ?> 章</div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="setting-item">
            <div class="setting-label">每章目标字数</div>
            <div class="setting-value"><?= number_format($novel['chapter_words']) ?> 字</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Logs Tab -->
  <div class="tab-pane fade" id="tab-logs">
    <div class="page-card">
      <?php if (empty($logs)): ?>
      <div class="p-4 text-muted text-center">暂无日志</div>
      <?php else: ?>
      <div class="list-group list-group-flush">
        <?php foreach ($logs as $log): ?>
        <div class="list-group-item bg-transparent border-secondary">
          <div class="d-flex justify-content-between">
            <span class="text-light small"><?= h($log['message']) ?></span>
            <span class="text-muted small"><?= $log['created_at'] ?></span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- Chapter Synopsis Edit Modal -->
<div class="modal fade" id="chapterSynopsisModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content bg-dark border-secondary">
      <div class="modal-header border-secondary">
        <h5 class="modal-title text-light"><i class="bi bi-file-text me-2"></i>编辑章节概要</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="edit-synopsis-novel-id" value="<?= $id ?>">
        <input type="hidden" id="edit-synopsis-chapter" value="">
        <div class="mb-3">
          <label class="form-label text-light">章节概要 (200-300字) <span class="text-danger">*</span></label>
          <textarea class="form-control bg-secondary border-secondary text-light" id="edit-synopsis-text" rows="6" 
                    placeholder="描述本章的主要内容、场景、情节发展..."></textarea>
          <div class="form-text text-muted">建议200-300字，包含场景设定、主要情节、人物互动</div>
        </div>
        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label text-light">节奏</label>
            <select class="form-select bg-secondary border-secondary text-light" id="edit-synopsis-pacing">
              <option value="">选择节奏</option>
              <option value="快">快 - 紧张刺激，情节密集</option>
              <option value="中">中 - 张弛有度，节奏适中</option>
              <option value="慢">慢 - 舒缓细腻，注重描写</option>
            </select>
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label text-light">结尾悬念</label>
            <textarea class="form-control bg-secondary border-secondary text-light" id="edit-synopsis-cliffhanger" rows="2"
                      placeholder="本章结尾的悬念或钩子..."></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer border-secondary">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
        <button type="button" class="btn btn-primary" id="btn-save-chapter-synopsis">
          <i class="bi bi-check-lg me-1"></i>保存
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Chapter Synopsis Optimize Modal -->
<div class="modal fade" id="optimizeSynopsisModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content bg-dark border-secondary">
      <div class="modal-header border-secondary">
        <h5 class="modal-title text-light"><i class="bi bi-magic me-2"></i>优化章节概要</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="optimize-novel-id" value="<?= $id ?>">
        <input type="hidden" id="optimize-chapter" value="">
        
        <!-- 当前章节概要 -->
        <div class="mb-3">
          <label class="form-label text-light">当前章节概要</label>
          <div class="p-3 bg-secondary border border-secondary rounded">
            <small class="text-light" id="optimize-current-synopsis"></small>
          </div>
        </div>
        
        <!-- 优化意见输入 -->
        <div class="mb-3">
          <label class="form-label text-light">优化意见 <span class="text-danger">*</span></label>
          <textarea class="form-control bg-secondary border-secondary text-light" id="optimize-suggestions" rows="6" 
                    placeholder="请输入您的优化意见，例如：
- 增加更多人物对话和互动
- 加强场景描写的细节
- 调整情节发展的节奏
- 添加更多冲突和悬念
- 突出主角的心理变化"></textarea>
          <div class="form-text text-muted">请详细描述您希望如何优化章节概要，AI会根据您的意见重新生成</div>
        </div>
        
        <!-- 优化后的结果（初始隐藏） -->
        <div class="mb-3" id="optimize-result-section" style="display:none">
          <label class="form-label text-light">优化后的章节概要</label>
          <div class="p-3 bg-secondary border border-success rounded">
            <small class="text-light" id="optimize-result-synopsis"></small>
          </div>
          <div class="form-text text-success mt-1">
            <i class="bi bi-check-circle me-1"></i>优化完成，请确认是否采用
          </div>
        </div>
      </div>
      <div class="modal-footer border-secondary">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
        <button type="button" class="btn btn-warning" id="btn-generate-optimize">
          <i class="bi bi-magic me-1"></i>生成优化
        </button>
        <button type="button" class="btn btn-success" id="btn-confirm-optimize" style="display:none">
          <i class="bi bi-check-lg me-1"></i>确认采用
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Writing modal (streaming) -->
<div class="modal fade" id="writeModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content bg-dark text-light">
      <div class="modal-header border-secondary">
        <h5 class="modal-title" id="writeModalTitle">正在写作...</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="writeModalContent" class="chapter-content-preview"></div>
        <div id="writeModalSpinner" class="text-center py-3">
          <div class="spinner-border text-primary"></div>
          <div class="mt-2 text-muted small">AI 正在创作中...</div>
        </div>
      </div>
      <div class="modal-footer border-secondary">
        <span class="text-muted small" id="writeModalStats"></span>
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">关闭</button>
        <a href="#" class="btn btn-primary btn-sm" id="writeModalViewBtn" style="display:none">查看完整章节</a>
      </div>
    </div>
  </div>
</div>

<script>
const NOVEL_ID   = <?= $id ?>;
const TARGET_CHS = <?= $novel['target_chapters'] ?>;
</script>

<?php pageFooter(); ?>
