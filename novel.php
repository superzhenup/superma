<?php
// 错误处理：捕获所有致命错误
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && $error['type'] === E_ERROR) {
        // 清除之前的输出
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // 输出错误信息
        echo "<!DOCTYPE html>\n";
        echo "<html><head><meta charset='UTF-8'><title>致命错误</title></head><body>\n";
        echo "<h1>页面加载出错</h1>\n";
        echo "<p>错误类型: Fatal Error</p>\n";
        echo "<p>错误信息: " . htmlspecialchars($error['message']) . "</p>\n";
        echo "<p>文件: " . htmlspecialchars($error['file']) . "</p>\n";
        echo "<p>行号: " . $error['line'] . "</p>\n";
        echo "</body></html>\n";
        exit;
    }
});

// 启用错误报告（日志记录，不显示在页面）
error_reporting(E_ALL);
ini_set('display_errors', 0);

define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/layout.php';

$id    = (int)($_GET['id'] ?? 0);
$zombieRecovered = 0;

// ================================================================
// Watchdog：自动恢复卡死的章节（status=writing 但超过5分钟无更新）
// 原因：SSE 写作中途断连时，后端未妥善处理，导致章节永远卡在"写作中"
// 方案A：页面加载时自动重置，不影响正常写作流程
// ================================================================
if ($id > 0) {
    try {
        $zombieSeconds  = (int)CFG_ZOMBIE_DB;
        $zombieChapters = DB::fetchAll(
            "SELECT id, chapter_number FROM chapters
             WHERE novel_id = ? AND status = 'writing'
             AND updated_at < DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$id, $zombieSeconds]
        );
        $zombieRecovered = count($zombieChapters);
        foreach ($zombieChapters as $zc) {
            // 重置状态为 outlined，同时清零 retry_count（卡死不是写作失败，不应消耗重试次数）
            DB::execute('UPDATE chapters SET status = "outlined", retry_count = 0 WHERE id = ?', [$zc['id']]);
            addLog($id, 'watchdog_recover', "自动恢复卡死章节：第{$zc['chapter_number']}章（超过{$zombieSeconds}秒未更新，retry_count已重置）");
        }
    } catch (Throwable $e) {
        error_log('novel.php Watchdog 失败: ' . $e->getMessage());
        $zombieRecovered = 0;
    }
}

$novel = getNovel($id);

// 如果小说不存在或不属于当前用户，跳转到首页
if (!$novel) {
    header('Location: index.php');
    exit;
}

$allChapters = getNovelChapters($id);
$models      = DB::fetchAll('SELECT * FROM ai_models ORDER BY is_default DESC, id ASC');

// 章节分页：50章/页
$totalChapterCount = count($allChapters);
$perPage           = 50;
$totalPages        = max(1, (int)ceil($totalChapterCount / $perPage));
$currentPage       = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
$chapters          = array_slice($allChapters, ($currentPage - 1) * $perPage, $perPage);

// 安全查询日志，添加超时保护
// 注意：writing_logs 表没有 message 字段，需要兼容处理
$logs = [];
try {
    $pdo = DB::connect();
    $pdo->setAttribute(PDO::ATTR_TIMEOUT, 5);
    $stmt = $pdo->prepare('SELECT id, novel_id, chapter_id, action, message, created_at FROM writing_logs WHERE novel_id=? ORDER BY created_at DESC LIMIT 50');
    $stmt->execute([$id]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // message 字段直接使用，为空时用 action 兜底
    foreach ($logs as &$log) {
        if (empty($log['message'])) {
            $log['message'] = $log['action'] ?? '未知操作';
        }
    }
    unset($log);
} catch (Throwable $e) {
    // 日志查询失败不影响页面显示，记录错误即可
    error_log('novel.php 日志查询失败: ' . $e->getMessage());
    $logs = [];
}

// 性能优化：一次批量查出所有章节的 synopsis，消除 N+1 查询。
// 兼容旧库缺少 `chapter_synopses` 表时自动降级为空，避免详情页整体报错。
try {
    $synopsisRows = DB::fetchAll(
        'SELECT chapter_number, synopsis FROM chapter_synopses WHERE novel_id=?',
        [$id]
    );
} catch (Throwable $e) {
    error_log('novel.php: 查询章节摘要失败 — ' . $e->getMessage());
    $synopsisRows = [];
}
$synopsisMap = array_column($synopsisRows, 'synopsis', 'chapter_number');

$outlined  = count(array_filter($allChapters, fn($c) => in_array($c['status'], ['outlined','writing','completed'])));
$completed = count(array_filter($allChapters, fn($c) => $c['status'] === 'completed'));
$progress  = $novel['target_chapters'] > 0 ? round($completed / $novel['target_chapters'] * 100) : 0;
$created   = isset($_GET['created']);
$saved     = isset($_GET['saved']);

// 调试：输出内存使用
if (defined('APP_DEBUG') && APP_DEBUG) {
    $memUsed = round(memory_get_usage(true) / 1024 / 1024, 2);
    $memPeak = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
    // 在页面底部输出调试信息
    ob_start();
    ?>
<!-- DEBUG: Memory Usage: <?= $memUsed ?>MB / Peak: <?= $memPeak ?>MB -->
    <?php
    $debugInfo = ob_get_clean();
}

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

<?php if ($zombieRecovered > 0): ?>
<div class="alert alert-warning alert-dismissible fade show mb-3" role="alert">
  <i class="bi bi-exclamation-triangle me-2"></i>
  检测到 <?= $zombieRecovered ?> 个卡死章节已自动恢复（超过5分钟未更新），状态已重置为"待写作"。
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
    <div class="novel-cover-sm" style="<?= !empty($novel['cover_image']) ? '' : 'background:linear-gradient(135deg,' . h($novel['cover_color']) . ',' . h($novel['cover_color']) . '99)' ?>">
      <?php if (!empty($novel['cover_image'])): ?>
      <img src="<?= h($novel['cover_image']) ?>" alt="<?= h($novel['title']) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:inherit">
      <?php else: ?>
      <?= h(mb_substr(htmlspecialchars($novel['title'], ENT_QUOTES, 'UTF-8'), 0, 4)) ?>
      <?php endif; ?>
    </div>
    <div class="flex-grow-1 min-w-0">
      <div class="d-flex align-items-center gap-2 mb-1">
        <h4 class="mb-0 novel-title-text fw-bold"><?= h($novel['title']) ?></h4>
        <?= statusBadge($novel['status']) ?>
      </div>
      <div class="d-flex gap-3 novel-meta-tags flex-wrap mb-2">
        <span><i class="bi bi-tag me-1"></i><?= h($novel['genre'] ?: '未分类') ?></span>
        <span><i class="bi bi-brush me-1"></i><?= h($novel['writing_style'] ?: '未设定') ?></span>
        <span><i class="bi bi-person me-1"></i><?= h($novel['protagonist_name'] ?: '未设定') ?></span>
        <span><i class="bi bi-calendar me-1"></i><?= substr($novel['created_at'], 0, 10) ?></span>
      </div>
      <div class="d-flex gap-4 text-muted small mb-3 flex-wrap">
        <span class="novel-stat-text fw-semibold"><?= number_format($novel['total_words']) ?> <small class="text-muted fw-normal">字</small></span>
        <span class="novel-stat-text fw-semibold"><?= $completed ?>/<?= $novel['target_chapters'] ?> <small class="text-muted fw-normal">章</small></span>
        <span class="novel-stat-text fw-semibold"><?= $outlined ?> <small class="text-muted fw-normal">章已大纲</small></span>
      </div>
      <div class="progress mb-3" style="height:6px;max-width:400px">
        <div class="progress-bar" style="width:<?= $progress ?>%;background:<?= h($novel['cover_color']) ?>" title="<?= $progress ?>%"></div>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <!-- 生成全书故事大纲 -->
        <button class="btn btn-sm btn-outline-primary" id="btn-story-outline"
                data-novel="<?= $id ?>"
                title="生成/重新生成全书故事大纲">
          <i class="bi bi-map me-1"></i>生成全书故事大纲
        </button>
        <!-- 生成大纲 -->
        <button class="btn btn-sm btn-outline-info" id="btn-outline"
                data-novel="<?= $id ?>"
                data-outlined="<?= $outlined ?>"
                data-target="<?= $novel['target_chapters'] ?>"
                title="生成/追加章节大纲">
          <i class="bi bi-list-ol me-1"></i>生成章节细纲
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
          <i class="bi bi-patch-plus me-1"></i>补写缺失细纲
        </button>
        <!-- 优化大纲逻辑 -->
        <button class="btn btn-sm btn-outline-success" id="btn-optimize-outline"
                data-novel="<?= $id ?>"
                <?= (!$novel['has_story_outline'] || $outlined === 0) ? 'disabled title="请先生成全书故事大纲和章节大纲"' : '' ?>>
          <i class="bi bi-lightning-charge me-1"></i>优化章节逻辑
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
// 兼容旧库缺少 `story_outlines` 表时自动降级为空，避免详情页整体报错。
try {
    $storyOutline = DB::fetch('SELECT * FROM story_outlines WHERE novel_id = ?', [$id]);
    // 如果 character_endpoints 为空，尝试从 character_arcs 中提取终点的 end 值
    if ($storyOutline && empty($storyOutline['character_endpoints']) && !empty($storyOutline['character_arcs'])) {
        $storyOutline['character_endpoints'] = extractCharacterEndpoints($storyOutline['character_arcs']);
    }
} catch (Throwable $e) {
    error_log('novel.php: 查询故事大纲失败 — ' . $e->getMessage());
    $storyOutline = null;
}
?>
<div id="story-outline-card" class="mb-3 page-card" <?= !$storyOutline ? 'style="display:none"' : '' ?>>
  <div class="p-3 border-bottom border-secondary d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-2">
      <i class="bi bi-map text-primary fs-5"></i>
      <span class="fw-semibold">全书故事大纲</span>
      <?php if ($storyOutline): ?>
      <span class="badge bg-success ms-2">已生成</span>
      <?php endif; ?>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-sm btn-outline-primary" id="btn-edit-story-outline" data-novel="<?= $id ?>">
        <i class="bi bi-pencil me-1"></i>编辑
      </button>
      <button class="btn btn-sm btn-outline-info" id="btn-regenerate-story-outline" data-novel="<?= $id ?>" onclick="regenerateStoryOutline()">
        <i class="bi bi-arrow-clockwise me-1"></i>重新生成
      </button>
    </div>
  </div>
  <div class="p-3" id="story-outline-content">
    <?php if ($storyOutline): ?>
    <div class="mb-3">
      <h6 class="small mb-2" style="color:var(--bs-body-color);opacity:.7"><i class="bi bi-diagram-3 me-1"></i>故事主线</h6>
      <div style="white-space: pre-wrap; line-height: 1.8;color:var(--bs-body-color)"><?= h($storyOutline['story_arc']) ?></div>
    </div>
    <?php if ($storyOutline['character_arcs']): ?>
    <div class="mb-3">
      <h6 class="small mb-2" style="color:var(--bs-body-color);opacity:.7"><i class="bi bi-people me-1"></i>人物成长轨迹</h6>
      <div style="white-space: pre-wrap; line-height: 1.8;color:var(--bs-body-color)"><?= h(formatCharacterArcsForDisplay($storyOutline['character_arcs'])) ?></div>
    </div>
    <?php endif; ?>
    <?php if ($storyOutline['character_endpoints']): ?>
    <div class="mb-3">
      <h6 class="small mb-2" style="color:var(--bs-body-color);opacity:.7"><i class="bi bi-flag me-1"></i>人物弧线终点</h6>
      <div style="white-space: pre-wrap; line-height: 1.8;color:var(--bs-body-color)"><?= h($storyOutline['character_endpoints']) ?></div>
    </div>
    <?php endif; ?>
    <?php if ($storyOutline['world_evolution']): ?>
    <div class="mb-3">
      <h6 class="small mb-2" style="color:var(--bs-body-color);opacity:.7"><i class="bi bi-globe me-1"></i>世界观演变</h6>
      <div style="white-space: pre-wrap; line-height: 1.8;color:var(--bs-body-color)"><?= h($storyOutline['world_evolution']) ?></div>
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
          <label class="form-label text-light">人物弧线终点</label>
          <textarea class="form-control bg-secondary border-secondary text-light" id="edit-character-endpoints" rows="4"
                    placeholder="描述各人物在故事结局时的最终状态..."></textarea>
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
          <span class="fw-semibold" id="write-progress-label">正在写作...</span>
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
    <!-- 深度思考过程展示（可折叠） -->
    <details id="write-stream-thinking-wrap" class="write-thinking-wrap" style="display:none">
      <summary class="write-thinking-summary">
        <i class="bi bi-cpu me-1"></i>深度思考过程
        <span class="badge bg-secondary ms-2" id="write-stream-thinking-len">0字</span>
      </summary>
      <div id="write-stream-thinking" class="write-thinking-box"></div>
    </details>
    <div id="write-stream-box" class="write-stream-box">
      <span class="outline-stream-cursor" id="write-cursor"></span>
    </div>
  </div>
</div>

<!-- Daemon-write panel -->
<div id="daemon-write-panel" class="mb-3 page-card <?= empty($novel['daemon_write']) ? 'd-none' : '' ?>">
  <div class="p-3 border-bottom border-secondary d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-2">
      <i class="bi bi-robot text-success fs-5"></i>
      <span class="fw-semibold">挂机写作进行中</span>
      <span class="badge bg-success" id="daemon-badge">运行中</span>
    </div>
    <button class="btn btn-sm btn-outline-danger" onclick="DaemonWrite.stop()">
      <i class="bi bi-stop-fill me-1"></i>停止挂机
    </button>
  </div>
  <div class="p-3">
    <!-- 进度条 -->
    <div class="mb-2">
      <div class="d-flex justify-content-between small text-muted mb-1">
        <span id="daemon-progress-label">等待宝塔 Cron 触发...</span>
        <span id="daemon-progress-pct">0%</span>
      </div>
      <div class="progress" style="height:6px">
        <div class="progress-bar bg-success progress-bar-striped progress-bar-animated"
             id="daemon-progress-bar" style="width:0%"></div>
      </div>
    </div>
    <!-- 统计 -->
    <div class="row g-2 small mb-3">
      <div class="col-4 text-center">
        <div class="text-muted">已完成</div>
        <div class="fw-bold text-success fs-5" id="daemon-stat-done">—</div>
      </div>
      <div class="col-4 text-center">
        <div class="text-muted">待写作</div>
        <div class="fw-bold text-warning fs-5" id="daemon-stat-remain">—</div>
      </div>
      <div class="col-4 text-center">
        <div class="text-muted">总字数</div>
        <div class="fw-bold text-info fs-5" id="daemon-stat-words">—</div>
      </div>
    </div>
    <!-- 配置提示 -->
    <div class="alert alert-secondary py-2 px-3 mb-2 small">
      <i class="bi bi-gear me-1"></i>
      <strong>宝塔 Cron 配置：</strong>
      <span class="text-muted">执行周期：每 1 分钟 | 脚本类型：Shell脚本</span><br>
      <code id="daemon-curl-cmd" class="text-info">正在生成命令...</code>
      <button class="btn btn-sm btn-link btn-outline-0 text-muted p-0 ms-2"
              onclick="DaemonWrite.copyCmd()" title="复制命令">
        <i class="bi bi-clipboard"></i>
      </button>
    </div>
    <!-- 最近日志 -->
    <div class="border-top border-secondary pt-2">
      <div class="small text-muted mb-1"><i class="bi bi-journal-text me-1"></i>最近执行记录</div>
      <div id="daemon-logs" class="font-monospace" style="max-height:140px;overflow-y:auto;font-size:12px">
        <span class="text-muted">等待首次执行...</span>
      </div>
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
        <span class="fw-semibold" id="outline-progress-label">正在生成大纲...</span>
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
        <span class="fw-semibold" id="story-outline-progress-label">正在生成全书故事大纲...</span>
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
        <span class="fw-semibold" id="optimize-outline-progress-label">正在优化大纲逻辑...</span>
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
        <span class="fw-semibold" id="synopsis-progress-label">正在生成章节概要...</span>
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
      <i class="bi bi-list-ul me-1"></i>章节列表 <span class="badge bg-secondary ms-1"><?= $totalChapterCount ?></span>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link" data-bs-toggle="tab" href="#tab-memory">
      <i class="bi bi-memory me-1"></i>记忆引擎
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link" data-bs-toggle="tab" href="#tab-settings">
      <i class="bi bi-gear me-1"></i>小说设定
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link" data-bs-toggle="tab" href="#tab-agent">
      <i class="bi bi-cpu me-1"></i>Agent 决策
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
    <?php if (empty($allChapters)): ?>
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
        <button type="button" class="btn btn-sm btn-outline-danger" onclick="clearAllChapters()" title="清空所有章节内容">
          <i class="bi bi-trash"></i>
        </button>
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
          <?php
            $chapterTargetWords = (int)$novel['chapter_words'];
            $isOverLimit = $ch['words'] > 0 && $chapterTargetWords > 0 && $ch['words'] > $chapterTargetWords + 500;
            if ($ch['words'] > 0) {
              $wordStyle = $isOverLimit ? 'color:#ef4444;font-weight:700;' : '';
              echo '<span class="ch-words" style="' . $wordStyle . '" title="' . ($isOverLimit ? "超出目标{$chapterTargetWords}字+500" : "字数正常") . '">' . number_format($ch['words']) . '</span>';
            } else {
              echo '<span class="ch-words-empty">—</span>';
            }
          ?>
        </div>
        <div class="col-action">
          <?php if ($ch['status'] !== 'pending'): ?>
          <a href="chapter.php?id=<?= $ch['id'] ?>&edit=1" class="btn btn-xs btn-outline-secondary" title="编辑章节">
            <i class="bi bi-pencil"></i> 编辑
          </a>
          <?php endif; ?>
          <?php if ($ch['status'] === 'completed'): ?>
          <button class="btn btn-xs btn-outline-info btn-chapter-detail"
                  data-chapter-id="<?= $ch['id'] ?>"
                  data-chapter-num="<?= $ch['chapter_number'] ?>">
            <i class="bi bi-eye"></i> 查看
          </button>
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

    <!-- 章节分页 -->
    <?php if ($totalPages > 1): ?>
    <div class="d-flex justify-content-center align-items-center mt-3 gap-2 flex-wrap">
      <span class="text-muted small me-2">第 <?= $currentPage ?> / <?= $totalPages ?> 页（共 <?= $totalChapterCount ?> 章）</span>
      <nav aria-label="章节分页">
        <ul class="pagination pagination-sm mb-0">
          <?php
            $pageUrl = fn($p) => 'novel.php?id=' . $id . '&page=' . $p;
            // 上一页
            if ($currentPage > 1): ?>
              <li class="page-item">
                <a class="page-link" href="<?= $pageUrl($currentPage - 1) ?>"><i class="bi bi-chevron-left"></i></a>
              </li>
            <?php else: ?>
              <li class="page-item disabled"><span class="page-link"><i class="bi bi-chevron-left"></i></span></li>
            <?php endif;

            // 页码按钮
            $pageStart = max(1, $currentPage - 2);
            $pageEnd   = min($totalPages, $currentPage + 2);
            if ($pageStart > 1): ?>
              <li class="page-item"><a class="page-link" href="<?= $pageUrl(1) ?>">1</a></li>
              <?php if ($pageStart > 2): ?>
                <li class="page-item disabled"><span class="page-link">…</span></li>
              <?php endif;
            endif;
            for ($p = $pageStart; $p <= $pageEnd; $p++):
              if ($p === $currentPage): ?>
                <li class="page-item active"><span class="page-link"><?= $p ?></span></li>
              <?php else: ?>
                <li class="page-item"><a class="page-link" href="<?= $pageUrl($p) ?>"><?= $p ?></a></li>
              <?php endif;
            endfor;
            if ($pageEnd < $totalPages):
              if ($pageEnd < $totalPages - 1): ?>
                <li class="page-item disabled"><span class="page-link">…</span></li>
              <?php endif; ?>
              <li class="page-item"><a class="page-link" href="<?= $pageUrl($totalPages) ?>"><?= $totalPages ?></a></li>
            <?php endif;
            // 下一页
            if ($currentPage < $totalPages): ?>
              <li class="page-item">
                <a class="page-link" href="<?= $pageUrl($currentPage + 1) ?>"><i class="bi bi-chevron-right"></i></a>
              </li>
            <?php else: ?>
              <li class="page-item disabled"><span class="page-link"><i class="bi bi-chevron-right"></i></span></li>
            <?php endif; ?>
        </ul>
      </nav>
    </div>
    <?php endif; ?>
    <!-- /章节分页 -->

    <?php endif; ?>
  </div>

  <!-- Memory Engine Tab -->
  <div class="tab-pane fade" id="tab-memory">
    <div class="page-card p-4">
      <!-- 记忆引擎状态和控制 -->
      <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
          <h5 class="mb-1"><i class="bi bi-memory me-2"></i>Super-Ma 记忆引擎</h5>
          <p class="text-muted small mb-0">四层渐进式记忆Pyramid架构，增强写作一致性</p>
        </div>
        <div class="d-flex gap-2 align-items-center">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="autoExtractToggle" checked>
            <label class="form-check-label text-muted small" for="autoExtractToggle">自动提取</label>
          </div>
          <button class="btn btn-sm btn-outline-primary" id="btn-refresh-memory">
            <i class="bi bi-arrow-clockwise me-1"></i>刷新
          </button>
        </div>
      </div>

      <!-- 统计卡片 -->
      <div class="row g-3 mb-4" id="memory-stats-cards">
        <div class="col-md-3">
          <div class="card bg-secondary border-0 h-100">
            <div class="card-body text-center">
              <div class="fs-2 fw-bold text-primary" id="stat-atoms">0</div>
              <div class="small text-muted">原子记忆</div>
            </div>
          </div>
        </div>
		<div class="col-md-3">
          <div class="card bg-secondary border-0 h-100">
            <div class="card-body text-center">
              <div class="fs-2 fw-bold text-info" id="stat-clusters">场景聚类</div>
              <div class="small text-muted">角色成长记录引擎，让AI能够理解角色的完整成长过程</div>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="card bg-secondary border-0 h-100">
            <div class="card-body text-center">
              <div class="fs-2 fw-bold text-success" id="stat-persona">画像维度</div>
              <div class="small text-muted">记录整本小说的“性格”，保持叙事一致性</div>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="card bg-secondary border-0 h-100">
            <div class="card-body text-center">
              <div class="fs-2 fw-bold text-warning" id="stat-chapters">0</div>
              <div class="small text-muted">已提取章节</div>
            </div>
          </div>
        </div>
      </div>

      <!-- 子标签页 -->
      <ul class="nav nav-pills mb-3" id="memorySubTabs">
        <li class="nav-item">
          <a class="nav-link active" data-bs-toggle="pill" href="#memory-atoms">
            <i class="bi bi-cpu me-1"></i>原子记忆
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link" data-bs-toggle="pill" href="#memory-cards">
            <i class="bi bi-person-lines-fill me-1"></i>角色卡片
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link" data-bs-toggle="pill" href="#memory-persona">
            <i class="bi bi-person-badge me-1"></i>小说画像
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link" data-bs-toggle="pill" href="#memory-search">
            <i class="bi bi-search me-1"></i>记忆检索
          </a>
        </li>
      </ul>

      <div class="tab-content">
        <!-- 原子记忆面板 -->
        <div class="tab-pane fade show active" id="memory-atoms">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="d-flex gap-2">
              <select class="form-select form-select-sm" id="atomTypeFilter" style="width: auto;">
                <option value="">全部类型</option>
                <option value="character_trait">角色特征</option>
                <option value="plot_detail">情节细节</option>
                <option value="world_setting">世界观设定</option>
                <option value="style_preference">风格偏好</option>
                <option value="constraint">约束条件</option>
                <option value="technique">功法/技艺</option>
                <option value="world_state">世界/场景状态</option>
                <option value="cool_point">亮点</option>
              </select>
              <select class="form-select form-select-sm" id="atomChapterFilter" style="width: auto;">
                <option value="">全部章节</option>
                <?php foreach ($allChapters as $ch): ?>
                <option value="<?= $ch['chapter_number'] ?>">第<?= $ch['chapter_number'] ?>章</option>
                <?php endforeach; ?>
              </select>
            </div>
            <button class="btn btn-sm btn-primary" id="btn-add-atom">
              <i class="bi bi-plus-lg me-1"></i>添加记忆
            </button>
          </div>
          <div id="atoms-list" class="memory-list">
            <div class="text-center text-muted py-4">
              <div class="spinner-border spinner-border-sm"></div>
              <div class="mt-2">加载中...</div>
            </div>
          </div>
        </div>

        <!-- 角色卡片面板 -->
        <div class="tab-pane fade" id="memory-cards">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="d-flex gap-2 align-items-center">
              <select class="form-select form-select-sm" id="cardAliveFilter" style="width: auto;">
                <option value="">全部角色</option>
                <option value="1">存活</option>
                <option value="0">已死亡/离场</option>
              </select>
            </div>
            <div class="d-flex gap-2">
              <button class="btn btn-sm btn-outline-success" onclick="MemoryUI.showCardEdit()">
                <i class="bi bi-person-plus me-1"></i>新建角色
              </button>
              <button class="btn btn-sm btn-outline-secondary" onclick="MemoryUI.loadCards()">
                <i class="bi bi-arrow-clockwise me-1"></i>刷新
              </button>
            </div>
          </div>
          <div id="cards-list" class="row g-3">
            <div class="col-12 text-center text-muted py-4">
              <div class="spinner-border spinner-border-sm"></div>
              <div class="mt-2">加载中...</div>
            </div>
          </div>
        </div>

        <!-- 小说画像面板 -->
        <div class="tab-pane fade" id="memory-persona">
          <div id="persona-content">
            <div class="text-center text-muted py-4">
              <div class="spinner-border spinner-border-sm"></div>
              <div class="mt-2">加载中...</div>
            </div>
          </div>
        </div>

        <!-- 记忆检索面板 -->
        <div class="tab-pane fade" id="memory-search">
          <div class="mb-3">
            <div class="input-group">
              <span class="input-group-text bg-secondary border-secondary"><i class="bi bi-search text-muted"></i></span>
              <input type="text" class="form-control"
                     id="memorySearchInput" placeholder="搜索记忆内容...">
              <button class="btn btn-outline-primary" id="btn-search-memory">搜索</button>
            </div>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-4">
              <button class="btn btn-outline-info w-100" id="btn-search-character">
                <i class="bi bi-person me-1"></i>角色记忆
              </button>
            </div>
            <div class="col-md-4">
              <button class="btn btn-outline-warning w-100" id="btn-search-plot">
                <i class="bi bi-bookmark me-1"></i>情节记忆
              </button>
            </div>
            <div class="col-md-4">
              <button class="btn btn-outline-success w-100" id="btn-search-world">
                <i class="bi bi-globe me-1"></i>世界观记忆
              </button>
            </div>
          </div>
          <div id="search-results" class="memory-list">
            <div class="text-center text-muted py-4">
              <i class="bi bi-search fs-1 d-block mb-2"></i>
              <p>输入关键词搜索记忆</p>
            </div>
          </div>
        </div>
      </div>
    </div>
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

  <!-- Agent Decision Tab -->
  <div class="tab-pane fade" id="tab-agent">
    <div class="page-card p-4">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="mb-0"><i class="bi bi-cpu me-2"></i>Agent 决策中心</h5>
        <div class="d-flex gap-2">
          <span class="badge bg-success" id="agent-status-badge">运行中</span>
          <button class="btn btn-sm btn-outline-light" onclick="AgentPanel.refresh()" title="刷新">
            <i class="bi bi-arrow-clockwise"></i>
          </button>
        </div>
      </div>

      <!-- Stats Row -->
      <div class="row g-3 mb-4" id="agent-stats-row">
        <div class="col-md-3">
          <div class="p-3 rounded-3 bg-dark-subtle text-center">
            <div class="fs-4 fw-bold text-primary" id="stat-total-decisions">-</div>
            <small class="text-muted">总决策次数</small>
          </div>
        </div>
        <div class="col-md-3">
          <div class="p-3 rounded-3 bg-dark-subtle text-center">
            <div class="fs-4 fw-bold text-success" id="stat-success-rate">-</div>
            <small class="text-muted">指令有效率</small>
          </div>
        </div>
        <div class="col-md-3">
          <div class="p-3 rounded-3 bg-dark-subtle text-center">
            <div class="fs-4 fw-bold text-info" id="stat-active-directives">-</div>
            <small class="text-muted">活跃指令数</small>
          </div>
        </div>
        <div class="col-md-3">
          <div class="p-3 rounded-3 bg-dark-subtle text-center">
            <div class="fs-4 fw-bold text-warning" id="stat-avg-improvement">-</div>
            <small class="text-muted">平均质量改善</small>
          </div>
        </div>
      </div>

      <!-- Sub Tabs -->
      <ul class="nav nav-pills mb-3" id="agentSubTabs">
        <li class="nav-item">
          <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#agent-timeline" type="button">
            <i class="bi bi-clock-history me-1"></i>决策时间线
          </button>
        </li>
        <li class="nav-item">
          <button class="nav-link" data-bs-toggle="pill" data-bs-target="#agent-directives" type="button">
            <i class="bi bi-chat-right-text me-1"></i>活跃指令
          </button>
        </li>
        <li class="nav-item">
          <button class="nav-link" data-bs-toggle="pill" data-bs-target="#agent-outcomes" type="button">
            <i class="bi bi-graph-up me-1"></i>效果分析
          </button>
        </li>
      </ul>

      <div class="tab-content">
        <!-- Timeline Panel -->
        <div class="tab-pane fade show active" id="agent-timeline">
          <div id="agent-timeline-content">
            <div class="text-center text-muted py-5">
              <div class="spinner-border spinner-border-sm me-2"></div>加载中...
            </div>
          </div>
        </div>

        <!-- Active Directives Panel -->
        <div class="tab-pane fade" id="agent-directives">
          <div id="agent-directives-content">
            <div class="text-center text-muted py-5">
              <div class="spinner-border spinner-border-sm me-2"></div>加载中...
            </div>
          </div>
        </div>

        <!-- Outcomes Analysis Panel -->
        <div class="tab-pane fade" id="agent-outcomes">
          <div id="agent-outcomes-content">
            <div class="text-center text-muted py-5">
              <div class="spinner-border spinner-border-sm me-2"></div>加载中...
            </div>
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
            <span class="small" style="color:var(--text)"><?= h($log['message']) ?></span>
            <span class="small" style="color:var(--text-muted)"><?= $log['created_at'] ?></span>
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
        <!-- 深度思考过程展示（可折叠） -->
        <details id="writeModalThinkingWrap" class="write-thinking-wrap mb-3" style="display:none">
          <summary class="write-thinking-summary">
            <i class="bi bi-cpu me-1"></i>深度思考过程
            <span class="badge bg-secondary ms-2" id="writeModalThinkingLen">0字</span>
          </summary>
          <div id="writeModalThinking" class="write-thinking-box"></div>
        </details>
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

<!-- Chapter Detail Modal -->
<div class="modal fade" id="chapterDetailModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content bg-dark border-secondary">
      <div class="modal-header border-secondary">
        <h5 class="modal-title text-light">
          <i class="bi bi-file-text me-2"></i>第<span id="detail-chapter-num">0</span>章
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="detail-chapter-id" value="">
        
        <!-- 章节标题 -->
        <div class="mb-4">
          <label class="form-label text-light fw-semibold">
            <i class="bi bi-type me-1"></i>章节标题
          </label>
          <div class="input-group">
            <input type="text" class="form-control bg-secondary border-secondary text-light" 
                   id="detail-title" placeholder="输入章节标题...">
            <button class="btn btn-outline-primary" type="button" id="btn-update-title">
              <i class="bi bi-check-lg"></i> 保存标题
            </button>
          </div>
        </div>
        
        <!-- 章节大纲 -->
        <div class="mb-4">
          <label class="form-label text-light fw-semibold">
            <i class="bi bi-list-ol me-1"></i>章节大纲
          </label>
          <textarea class="form-control bg-secondary border-secondary text-light" 
                    id="detail-outline" rows="5" placeholder="输入章节大纲..."></textarea>
          <div class="mt-2">
            <button class="btn btn-outline-primary btn-sm" id="btn-update-outline">
              <i class="bi bi-check-lg me-1"></i>保存大纲
            </button>
          </div>
        </div>
        
        <!-- 章节概要（只读） -->
        <div class="mb-4" id="detail-synopsis-section">
          <label class="form-label text-light fw-semibold">
            <i class="bi bi-card-text me-1"></i>章节概要
          </label>
          <div class="p-3 bg-secondary border border-secondary rounded" style="max-height: 150px; overflow-y: auto;">
            <small class="text-light" id="detail-synopsis">暂无概要</small>
          </div>
        </div>
        
        <!-- 章节内容预览 -->
        <div class="mb-4">
          <label class="form-label text-light fw-semibold">
            <i class="bi bi-file-earmark-text me-1"></i>章节内容
            <span class="badge bg-secondary ms-2" id="detail-words">0 字</span>
          </label>
          <div class="p-3 bg-secondary border border-secondary rounded" 
               style="max-height: 200px; overflow-y: auto; white-space: pre-wrap; font-size: 13px; line-height: 1.8;">
            <span class="text-light" id="detail-content">暂无内容</span>
          </div>
        </div>
        
        <!-- 操作按钮区域 -->
        <div class="border-top border-secondary pt-4">
          <div class="row g-3">
            <!-- 清空章节 -->
            <div class="col-md-4">
              <div class="card bg-secondary border-danger h-100">
                <div class="card-body">
                  <h6 class="card-title text-danger">
                    <i class="bi bi-trash me-1"></i>清空章节
                  </h6>
                  <p class="card-text small text-muted">清空本章内容，保留大纲。状态将重置为"已大纲"。</p>
                  <button class="btn btn-outline-danger btn-sm w-100" id="btn-clear-content">
                    <i class="bi bi-exclamation-triangle me-1"></i>确认清空
                  </button>
                </div>
              </div>
            </div>
            
            <!-- 重新生成 -->
            <div class="col-md-8">
              <div class="card bg-secondary border-warning h-100">
                <div class="card-body">
                  <h6 class="card-title text-warning">
                    <i class="bi bi-arrow-repeat me-1"></i>重新生成
                  </h6>
                  <p class="card-text small text-muted">输入剧情提示，AI将根据提示重新生成本章内容。</p>
                  <div class="input-group">
                    <input type="text" class="form-control bg-dark border-secondary text-light" 
                           id="detail-plot-hint" placeholder="输入剧情提示，例如：增加主角与反派的冲突...">
                    <button class="btn btn-outline-warning" id="btn-regenerate">
                      <i class="bi bi-magic me-1"></i>重新生成
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer border-secondary">
        <a href="#" class="btn btn-outline-info btn-sm" id="detail-view-chapter">
          <i class="bi bi-box-arrow-up-right me-1"></i>查看完整章节
        </a>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
      </div>
    </div>
  </div>
</div>

<script>
const NOVEL_ID   = <?= $id ?>;
const TARGET_CHS = <?= $novel['target_chapters'] ?>;
const AUTO_WRITE_INTERVAL = <?= (int)getSystemSetting('ws_auto_write_interval', 2, 'int') ?> * 1000;

// ================================================================
// Super-Ma 记忆引擎
// ================================================================

// 记忆引擎API封装
const MemoryAPI = {
  async call(action, data = {}) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const response = await fetch('api/memory_actions.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      body: JSON.stringify({ action, novel_id: NOVEL_ID, ...data })
    });
    return response.json();
  }
};

// 记忆引擎UI控制器
const MemoryUI = {
  init() {
    this.bindEvents();
    this.loadStats();
    this.loadAtoms();
    this.loadAutoExtractSetting();
  },

  bindEvents() {
    // 刷新按钮
    document.getElementById('btn-refresh-memory')?.addEventListener('click', () => {
      this.loadStats();
      this.loadAtoms();
    });

    // 自动提取开关
    document.getElementById('autoExtractToggle')?.addEventListener('change', (e) => {
      this.saveAutoExtractSetting(e.target.checked);
    });

    // 原子记忆筛选
    document.getElementById('atomTypeFilter')?.addEventListener('change', () => this.loadAtoms());
    document.getElementById('atomChapterFilter')?.addEventListener('change', () => this.loadAtoms());

    // 添加原子记忆
    document.getElementById('btn-add-atom')?.addEventListener('click', () => this.showAddAtomModal());

    // 场景聚类功能已整合到原子记忆，移除相关事件监听器

    // 记忆检索
    document.getElementById('btn-search-memory')?.addEventListener('click', () => this.searchMemory());
    document.getElementById('memorySearchInput')?.addEventListener('keypress', (e) => {
      if (e.key === 'Enter') this.searchMemory();
    });

    // 快捷检索按钮
    document.getElementById('btn-search-character')?.addEventListener('click', () => this.quickSearch('character'));
    document.getElementById('btn-search-plot')?.addEventListener('click', () => this.quickSearch('plot'));
    document.getElementById('btn-search-world')?.addEventListener('click', () => this.quickSearch('world'));

    // 子标签页切换时加载数据
    document.querySelectorAll('#memorySubTabs a[data-bs-toggle="pill"]').forEach(tab => {
      tab.addEventListener('shown.bs.tab', (e) => {
        const target = e.target.getAttribute('href');
        if (target === '#memory-cards')    this.loadCards();
        // 场景聚类功能已整合到原子记忆，不再需要加载
        if (target === '#memory-persona')  this.loadPersona();
      });
    });

    // 角色卡片存活过滤
    document.getElementById('cardAliveFilter')?.addEventListener('change', () => this.loadCards());
  },

  // 加载角色卡片列表
  async loadCards() {
    const aliveVal = document.getElementById('cardAliveFilter')?.value;
    const container = document.getElementById('cards-list');
    if (!container) return;

    container.innerHTML = `<div class="col-12 text-center text-muted py-4"><div class="spinner-border spinner-border-sm"></div><div class="mt-2">加载中...</div></div>`;

    try {
      const params = {};
      if (aliveVal === '1')  params.only_alive = true;
      if (aliveVal === '0')  params.only_dead  = true;
      const res = await MemoryAPI.call('get_cards', params);

      if (!res.ok) {
        container.innerHTML = `<div class="col-12 text-center text-danger py-4"><p>加载失败：${res.msg || '未知错误'}</p></div>`;
        return;
      }

      let cards = res.data || [];
      // 前端二次过滤（get_cards 后端只支持 onlyAlive=true，死亡需前端过滤）
      if (aliveVal === '0') cards = cards.filter(c => !c.alive);
      else if (aliveVal === '1') cards = cards.filter(c => c.alive);

      if (cards.length === 0) {
        container.innerHTML = `<div class="col-12 text-center text-muted py-4"><i class="bi bi-person-x fs-1 d-block mb-2"></i><p>暂无角色卡片</p><small>完成章节写作后将自动生成角色卡片</small></div>`;
        return;
      }

      container.innerHTML = cards.map(c => this.renderCardItem(c)).join('');
    } catch(e) {
      container.innerHTML = `<div class="col-12 text-center text-danger py-4"><p>加载失败：${e.message}</p></div>`;
    }
  },

  // 渲染单张角色卡片
  renderCardItem(card) {
    const aliveLabel = card.alive
      ? `<span class="badge bg-success bg-opacity-25 text-success"><i class="bi bi-heart-fill me-1"></i>存活</span>`
      : `<span class="badge bg-danger  bg-opacity-25 text-danger"><i class="bi bi-heartbreak me-1"></i>死亡/离场</span>`;

    const attrs = card.attributes && Object.keys(card.attributes).length > 0
      ? Object.entries(card.attributes).map(([k, v]) =>
          `<span class="badge bg-dark text-light me-1 mb-1">${this.escapeHtml(k)}：${this.escapeHtml(String(v))}</span>`
        ).join('')
      : '<span class="text-muted small">暂无属性</span>';

    const title  = card.title  ? `<span class="text-muted small ms-2">${this.escapeHtml(card.title)}</span>`  : '';
    const status = card.status ? `<div class="text-muted small mt-1"><i class="bi bi-info-circle me-1"></i>${this.escapeHtml(card.status)}</div>` : '';
    const lastCh = card.last_updated_chapter ? `<span class="text-muted small">最近更新：第${card.last_updated_chapter}章</span>` : '';

    return `
      <div class="col-md-6 col-lg-4">
        <div class="card bg-secondary border-0 h-100">
          <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <div>
                <span class="fw-bold text-light">${this.escapeHtml(card.name)}</span>${title}
                ${status}
              </div>
              ${aliveLabel}
            </div>
            <div class="mb-2">${attrs}</div>
            <div class="d-flex justify-content-between align-items-center mt-2">
              ${lastCh}
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-warning" onclick="MemoryUI.showCardEdit(${card.id})" title="编辑">
                  <i class="bi bi-pencil"></i>
                </button>
                <button class="btn btn-outline-info" onclick="MemoryUI.showCardHistory(${card.id}, '${this.escapeHtml(card.name)}')" title="历史">
                  <i class="bi bi-clock-history"></i>
                </button>
                <button class="btn btn-outline-danger" onclick="MemoryUI.deleteCard(${card.id}, '${this.escapeHtml(card.name)}')" title="删除">
                  <i class="bi bi-trash"></i>
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    `;
  },

  // 显示角色编辑 Modal（新建 cardId=null，编辑传 id）
  async showCardEdit(cardId = null) {
    let card = { id: null, name: '', title: '', status: '', alive: true, attributes: {} };

    if (cardId) {
      const res = await MemoryAPI.call('get_card', { card_id: cardId });
      if (!res.ok || !res.data) { alert('获取角色数据失败'); return; }
      card = res.data;
    }

    // 把 attributes 渲染为 key=value 每行一条的文本
    const attrsText = Object.entries(card.attributes || {})
      .map(([k, v]) => `${k}=${v}`).join('\n');

    const modalId = 'cardEditModal';
    document.getElementById(modalId)?.remove();

    const html = `
      <div class="modal fade" id="${modalId}" tabindex="-1">
        <div class="modal-dialog">
          <div class="modal-content bg-dark text-light">
            <div class="modal-header border-secondary">
              <h5 class="modal-title">
                <i class="bi bi-person-gear me-2"></i>${cardId ? '编辑角色' : '新建角色'}
              </h5>
              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <div class="mb-3">
                <label class="form-label text-muted small">角色名称 <span class="text-danger">*</span></label>
                <input type="text" class="form-control bg-secondary border-secondary text-light"
                       id="cedit_name" value="${this.escapeHtml(card.name)}"
                       ${cardId ? 'readonly' : ''} placeholder="输入角色名称">
                ${cardId ? '<div class="form-text text-muted">角色名为唯一标识，不可修改</div>' : ''}
              </div>
              <div class="mb-3">
                <label class="form-label text-muted small">头衔 / 称号</label>
                <input type="text" class="form-control bg-secondary border-secondary text-light"
                       id="cedit_title" value="${this.escapeHtml(card.title || '')}" placeholder="如：圣女、魔王">
              </div>
              <div class="mb-3">
                <label class="form-label text-muted small">当前状态描述</label>
                <input type="text" class="form-control bg-secondary border-secondary text-light"
                       id="cedit_status" value="${this.escapeHtml(card.status || '')}" placeholder="如：重伤未愈、在旅途中">
              </div>
              <div class="mb-3">
                <label class="form-label text-muted small">存活状态</label>
                <select class="form-select bg-secondary border-secondary text-light" id="cedit_alive">
                  <option value="1" ${card.alive ? 'selected' : ''}>存活</option>
                  <option value="0" ${!card.alive ? 'selected' : ''}>死亡 / 离场</option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label text-muted small">
                  属性键值对
                  <span class="text-muted ms-1">（每行一条，格式：属性名=值）</span>
                </label>
                <textarea class="form-control bg-secondary border-secondary text-light font-monospace"
                          id="cedit_attrs" rows="6"
                          placeholder="等级=50&#10;门派=天剑宗&#10;武器=青霄剑">${this.escapeHtml(attrsText)}</textarea>
              </div>
              <div class="mb-3">
                <label class="form-label text-muted small">记录到第几章（用于历史溯源）</label>
                <input type="number" class="form-control bg-secondary border-secondary text-light"
                       id="cedit_chapter" value="0" min="0">
              </div>
            </div>
            <div class="modal-footer border-secondary">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
              <button type="button" class="btn btn-warning" onclick="MemoryUI.saveCard(${cardId ?? 'null'})">
                <i class="bi bi-check-lg me-1"></i>保存
              </button>
            </div>
          </div>
        </div>
      </div>`;

    document.body.insertAdjacentHTML('beforeend', html);
    new bootstrap.Modal(document.getElementById(modalId)).show();
  },

  // 保存角色卡片（新建 or 编辑）
  async saveCard(cardId) {
    const name    = document.getElementById('cedit_name')?.value.trim();
    const title   = document.getElementById('cedit_title')?.value.trim();
    const status  = document.getElementById('cedit_status')?.value.trim();
    const alive   = document.getElementById('cedit_alive')?.value === '1';
    const chapter = parseInt(document.getElementById('cedit_chapter')?.value) || 0;
    const rawAttrs = document.getElementById('cedit_attrs')?.value.trim();

    if (!name) { alert('角色名称不能为空'); return; }

    // 解析属性文本 → 对象
    const attributes = {};
    if (rawAttrs) {
      rawAttrs.split('\n').forEach(line => {
        const eq = line.indexOf('=');
        if (eq > 0) {
          const k = line.slice(0, eq).trim();
          const v = line.slice(eq + 1).trim();
          if (k) attributes[k] = v;
        }
      });
    }

    const data = { alive };
    if (title  !== '') data.title  = title;
    if (status !== '') data.status = status;
    data.attributes = attributes;

    const btn = document.querySelector('#cardEditModal .btn-warning');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> 保存中...'; }

    try {
      const res = await MemoryAPI.call('upsert_card', {
        name,
        data,
        chapter_number: chapter
      });

      if (!res.ok) {
        alert('保存失败：' + (res.msg || '未知错误'));
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>保存'; }
        return;
      }

      bootstrap.Modal.getInstance(document.getElementById('cardEditModal'))?.hide();
      this.loadCards();
    } catch(e) {
      alert('保存失败：' + e.message);
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>保存'; }
    }
  },

  // 删除角色卡片
  async deleteCard(cardId, name) {
    if (!confirm(`确定删除角色「${name}」？此操作将同时删除其所有变更历史，不可恢复。`)) return;

    try {
      const res = await MemoryAPI.call('delete_card', { card_id: cardId });
      if (!res.ok) { alert('删除失败：' + (res.msg || '未知错误')); return; }
      this.loadCards();
    } catch(e) {
      alert('删除失败：' + e.message);
    }
  },

  // 查看角色变更历史
  async showCardHistory(cardId, name) {
    const res = await MemoryAPI.call('get_card_history', { card_id: cardId });
    const history = (res.ok && res.data) ? res.data : [];

    const rows = history.length > 0
      ? history.map(h => `
          <tr>
            <td class="text-muted">第${h.chapter_number}章</td>
            <td><span class="badge bg-secondary">${this.escapeHtml(h.field_name)}</span></td>
            <td class="text-muted small">${h.old_value != null ? this.escapeHtml(String(h.old_value)) : '—'}</td>
            <td class="text-light small">${h.new_value != null ? this.escapeHtml(String(h.new_value)) : '—'}</td>
          </tr>`).join('')
      : `<tr><td colspan="4" class="text-center text-muted py-3">暂无变更记录</td></tr>`;

    const html = `
      <div class="modal fade" id="cardHistoryModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
          <div class="modal-content bg-dark text-light">
            <div class="modal-header border-secondary">
              <h5 class="modal-title"><i class="bi bi-clock-history me-2"></i>${this.escapeHtml(name)} 变更历史</h5>
              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <table class="table table-dark table-sm table-hover">
                <thead><tr><th>章节</th><th>字段</th><th>原值</th><th>新值</th></tr></thead>
                <tbody>${rows}</tbody>
              </table>
            </div>
          </div>
        </div>
      </div>`;

    document.getElementById('cardHistoryModal')?.remove();
    document.body.insertAdjacentHTML('beforeend', html);
    new bootstrap.Modal(document.getElementById('cardHistoryModal')).show();
  },

  // 加载统计信息
  async loadStats() {
    try {
      const res = await MemoryAPI.call('get_stats');
      if (res.ok) {
        document.getElementById('stat-atoms').textContent = res.data.atoms || 0;
        document.getElementById('stat-chapters').textContent = res.data.chapters || 0;
      }
    } catch(e) {
      console.warn('记忆统计加载失败:', e);
    }
  },

  // 加载原子记忆列表
  async loadAtoms(offset = 0) {
    const atomType = document.getElementById('atomTypeFilter')?.value || null;
    const sourceChapter = document.getElementById('atomChapterFilter')?.value || null;
    const pageSize = 50;
    
    const container = document.getElementById('atoms-list');
    if (!container) return;

    if (offset === 0) {
      container.innerHTML = `<div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm"></div><div class="mt-2">加载中...</div></div>`;
    }

    try {
      const res = await MemoryAPI.call('get_atoms', {
        atom_type: atomType || undefined,
        source_chapter: sourceChapter ? parseInt(sourceChapter) : undefined,
        limit: pageSize + 1,
        offset: offset
      });

      if (!res.ok) {
        container.innerHTML = `<div class="text-center text-danger py-4"><i class="bi bi-exclamation-circle fs-1 d-block mb-2"></i><p>加载失败：${res.msg || '未知错误'}</p></div>`;
        return;
      }

      const hasMore = res.data && res.data.length > pageSize;
      const items = hasMore ? res.data.slice(0, pageSize) : (res.data || []);

      if (offset === 0) {
        if (items.length > 0) {
          container.innerHTML = items.map(atom => this.renderAtomItem(atom)).join('');
        } else {
          container.innerHTML = `
            <div class="text-center text-muted py-4">
              <i class="bi bi-inbox fs-1 d-block mb-2"></i>
              <p>暂无原子记忆</p>
              <small>完成章节写作后将自动提取记忆</small>
            </div>
          `;
          return;
        }
      } else {
        // 移除旧的"加载更多"按钮
        container.querySelector('.load-more-btn')?.remove();
        container.insertAdjacentHTML('beforeend', items.map(atom => this.renderAtomItem(atom)).join(''));
      }

      // 如果还有更多，加"加载更多"按钮
      if (hasMore) {
        container.insertAdjacentHTML('beforeend', `
          <div class="text-center mt-2 load-more-btn">
            <button class="btn btn-sm btn-outline-secondary" onclick="MemoryUI.loadAtoms(${offset + pageSize})">
              加载更多
            </button>
          </div>
        `);
      }
    } catch(e) {
      container.innerHTML = `<div class="text-center text-danger py-4"><i class="bi bi-exclamation-circle fs-1 d-block mb-2"></i><p>加载失败：${e.message}</p></div>`;
    }
  },

  // 渲染原子记忆项
  renderAtomItem(atom) {
    const typeLabels = {
      'character_trait':  { label: '角色特征',     color: 'primary',   icon: 'person' },
      'plot_detail':      { label: '情节细节',     color: 'warning',   icon: 'bookmark' },
      'world_setting':    { label: '世界观设定',   color: 'success',   icon: 'globe' },
      'style_preference': { label: '风格偏好',     color: 'info',      icon: 'brush' },
      'constraint':       { label: '约束条件',     color: 'danger',    icon: 'shield' },
      'technique':        { label: '功法/技艺',    color: 'purple',    icon: 'lightning' },
      'world_state':      { label: '世界/场景状态', color: 'teal',     icon: 'map' },
      'cool_point':       { label: '亮点',         color: 'orange',    icon: 'star' },
    };
    const typeInfo = typeLabels[atom.atom_type] || { label: '未知', color: 'secondary', icon: 'question' };
    
    // 检查是否为关键事件
    const isKeyEvent = atom.metadata && (atom.metadata.is_key_event === 1 || atom.metadata.is_key_event === '1' || atom.metadata.is_key_event === true);
    const confidencePercent = Math.round(atom.confidence * 100);
    
    return `
      <div class="memory-item card bg-secondary border-0 mb-2${isKeyEvent ? ' border-warning' : ''}">
        <div class="card-body p-3">
          <div class="d-flex justify-content-between align-items-start">
            <div class="flex-grow-1">
              <div class="d-flex align-items-center gap-2 mb-2">
                <span class="badge bg-${typeInfo.color} bg-opacity-25 text-${typeInfo.color}">
                  <i class="bi bi-${typeInfo.icon} me-1"></i>${typeInfo.label}
                </span>
                ${isKeyEvent ? '<span class="badge bg-warning bg-opacity-25 text-warning"><i class="bi bi-star-fill me-1"></i>关键事件</span>' : ''}
                ${atom.source_chapter ? `<span class="badge bg-secondary">第${atom.source_chapter}章</span>` : ''}
                <span class="badge bg-dark">置信度 ${confidencePercent}%</span>
              </div>
              <div class="text-light small" style="line-height: 1.6;">${this.escapeHtml(atom.content)}</div>
            </div>
            <div class="d-flex gap-1">
              <button class="btn btn-sm btn-outline-primary" onclick="MemoryUI.showEditAtomModal(${atom.id}, '${this.escapeHtml(atom.atom_type)}', '${this.escapeHtml(atom.content.replace(/'/g, "\\'"))}')" title="编辑">
                <i class="bi bi-pencil"></i>
              </button>
              <button class="btn btn-sm btn-outline-danger" onclick="MemoryUI.deleteAtom(${atom.id})" title="删除">
                <i class="bi bi-trash"></i>
              </button>
            </div>
          </div>
        </div>
      </div>
    `;
  },

  // 删除原子记忆
  async deleteAtom(atomId) {
    if (!confirm('确定要删除这条记忆吗？')) return;
    const res = await MemoryAPI.call('delete_atom', { atom_id: atomId });
    if (res.ok) {
      this.loadAtoms();
      this.loadStats();
    } else {
      alert(res.msg || '删除失败');
    }
  },

  // 加载场景聚类列表
  async loadClusters() {
    const clusterType = document.getElementById('clusterTypeFilter')?.value || null;
    const container = document.getElementById('clusters-list');
    if (!container) return;

    try {
      const res = await MemoryAPI.call('get_clusters', {
        cluster_type: clusterType || undefined
      });

      if (!res.ok) {
        container.innerHTML = `<div class="text-center text-danger py-4"><p>加载失败：${res.msg || '未知错误'}</p></div>`;
        return;
      }

      if (res.data && res.data.length > 0) {
        container.innerHTML = res.data.map(cluster => this.renderClusterItem(cluster)).join('');
      } else {
        container.innerHTML = `
          <div class="text-center text-muted py-4">
            <i class="bi bi-diagram-3 fs-1 d-block mb-2"></i>
            <p>暂无场景聚类</p>
            <small>系统将自动创建场景聚类</small>
          </div>
        `;
      }
    } catch(e) {
      container.innerHTML = `<div class="text-center text-danger py-4"><p>加载失败：${e.message}</p></div>`;
    }
  },

  // 渲染场景聚类项
  renderClusterItem(cluster) {
    const typeLabels = {
      'character_arc': { label: '角色弧线', color: 'primary', icon: 'person-lines-fill' },
      'plot_arc': { label: '情节弧线', color: 'warning', icon: 'book' },
      'world_evolution': { label: '世界观演变', color: 'success', icon: 'globe2' },
      'theme': { label: '主题', color: 'info', icon: 'tag' }
    };
    const typeInfo = typeLabels[cluster.cluster_type] || { label: '未知', color: 'secondary', icon: 'question' };
    
    return `
      <div class="memory-item card bg-secondary border-0 mb-2">
        <div class="card-body p-3">
          <div class="d-flex justify-content-between align-items-start">
            <div class="flex-grow-1">
              <div class="d-flex align-items-center gap-2 mb-2">
                <span class="badge bg-${typeInfo.color} bg-opacity-25 text-${typeInfo.color}">
                  <i class="bi bi-${typeInfo.icon} me-1"></i>${typeInfo.label}
                </span>
                ${cluster.chapter_range ? `<span class="badge bg-secondary">${cluster.chapter_range}</span>` : ''}
              </div>
              <h6 class="text-light mb-1">${this.escapeHtml(cluster.name)}</h6>
              ${cluster.description ? `<div class="text-muted small">${this.escapeHtml(cluster.description)}</div>` : ''}
            </div>
            <button class="btn btn-sm btn-outline-danger ms-2" onclick="MemoryUI.deleteCluster(${cluster.id})" title="删除">
              <i class="bi bi-trash"></i>
            </button>
          </div>
        </div>
      </div>
    `;
  },

  // 删除场景聚类
  async deleteCluster(clusterId) {
    if (!confirm('确定要删除这个场景聚类吗？')) return;
    const res = await MemoryAPI.call('delete_cluster', { cluster_id: clusterId });
    if (res.ok) {
      this.loadClusters();
      this.loadStats();
    } else {
      alert(res.msg || '删除失败');
    }
  },

  // 加载小说画像
  async loadPersona() {
    const res = await MemoryAPI.call('get_persona');
    const container = document.getElementById('persona-content');
    if (!container) return;

    if (res.ok && res.data) {
      const persona = res.data;
      container.innerHTML = `
        <div class="row g-3">
          ${this.renderPersonaSection('写作风格', persona.writing_style, 'brush', 'primary')}
          ${this.renderPersonaSection('叙事技巧', persona.narrative_techniques, 'journal-text', 'info')}
          ${this.renderPersonaSection('主题偏好', persona.theme_preferences, 'tags', 'warning')}
          ${this.renderPersonaSection('角色原型', persona.character_archetypes, 'people', 'success')}
          ${this.renderPersonaSection('世界观构建模式', persona.world_building_patterns, 'globe', 'secondary')}
          ${this.renderPersonaSection('语调一致性', persona.tone_consistency, 'chat-quote', 'danger')}
        </div>
        <div class="mt-3 text-end">
          <button class="btn btn-sm btn-outline-primary" onclick="MemoryUI.editPersona()">
            <i class="bi bi-pencil me-1"></i>编辑画像
          </button>
        </div>
      `;
    } else {
      container.innerHTML = `
        <div class="text-center text-muted py-4">
          <i class="bi bi-person-badge fs-1 d-block mb-2"></i>
          <p>小说画像将随写作进度自动生成</p>
        </div>
      `;
    }
  },

  // 渲染画像部分
  renderPersonaSection(title, content, icon, color) {
    return `
      <div class="col-md-6">
        <div class="card bg-secondary border-0 h-100">
          <div class="card-body">
            <h6 class="card-title text-${color} mb-2">
              <i class="bi bi-${icon} me-1"></i>${title}
            </h6>
            <div class="small text-light" style="line-height: 1.6;">
              ${content ? this.escapeHtml(content) : '<span class="text-muted">待生成</span>'}
            </div>
          </div>
        </div>
      </div>
    `;
  },

  // 搜索记忆
  async searchMemory() {
    const keyword = document.getElementById('memorySearchInput')?.value?.trim();
    if (!keyword) return;

    const res = await MemoryAPI.call('search_atoms', { keyword });
    const container = document.getElementById('search-results');
    if (!container) return;

    if (res.ok && res.data.length > 0) {
      container.innerHTML = res.data.map(atom => this.renderAtomItem(atom)).join('');
    } else {
      container.innerHTML = `
        <div class="text-center text-muted py-4">
          <i class="bi bi-search fs-1 d-block mb-2"></i>
          <p>未找到相关记忆</p>
        </div>
      `;
    }
  },

  // 快捷检索
  async quickSearch(type) {
    const res = await MemoryAPI.call(`get_${type}_memory`, { keyword: 'all' });
    const container = document.getElementById('search-results');
    if (!container) return;

    if (res.ok && res.data && res.data.length > 0) {
      container.innerHTML = res.data.map(atom => this.renderAtomItem(atom)).join('');
    } else {
      container.innerHTML = `
        <div class="text-center text-muted py-4">
          <i class="bi bi-inbox fs-1 d-block mb-2"></i>
          <p>暂无${type === 'character' ? '角色' : type === 'plot' ? '情节' : '世界观'}记忆</p>
        </div>
      `;
    }
  },

  // 显示添加原子记忆对话框
  showAddAtomModal() {
    const html = `
      <div class="modal fade" id="addAtomModal" tabindex="-1">
        <div class="modal-dialog">
          <div class="modal-content bg-dark border-secondary">
            <div class="modal-header border-secondary">
              <h5 class="modal-title text-light"><i class="bi bi-plus-circle me-2"></i>添加原子记忆</h5>
              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <div class="mb-3">
                <label class="form-label text-light">记忆类型</label>
                <select class="form-select bg-secondary border-secondary text-light" id="newAtomType">
                  <option value="character_trait">角色特征</option>
                  <option value="plot_detail">情节细节</option>
                  <option value="world_setting">世界观设定</option>
                  <option value="style_preference">风格偏好</option>
                  <option value="constraint">约束条件</option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label text-light">记忆内容</label>
                <textarea class="form-control bg-secondary border-secondary text-light" id="newAtomContent" rows="3" placeholder="输入记忆内容..."></textarea>
              </div>
              <div class="mb-3">
                <label class="form-label text-light">来源章节（可选）</label>
                <select class="form-select bg-secondary border-secondary text-light" id="newAtomChapter">
                  <option value="">无</option>
                  <?php foreach ($allChapters as $ch): ?>
                  <option value="<?= $ch['chapter_number'] ?>">第<?= $ch['chapter_number'] ?>章</option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="modal-footer border-secondary">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
              <button type="button" class="btn btn-primary" onclick="MemoryUI.addAtom()">添加</button>
            </div>
          </div>
        </div>
      </div>
    `;
    
    // 移除已存在的模态框
    document.getElementById('addAtomModal')?.remove();
    document.body.insertAdjacentHTML('beforeend', html);
    
    const modal = new bootstrap.Modal(document.getElementById('addAtomModal'));
    modal.show();
  },

  // 添加原子记忆
  async addAtom() {
    const atomType = document.getElementById('newAtomType')?.value;
    const content = document.getElementById('newAtomContent')?.value?.trim();
    const sourceChapter = document.getElementById('newAtomChapter')?.value;

    if (!content) {
      alert('请输入记忆内容');
      return;
    }

    const res = await MemoryAPI.call('add_atom', {
      atom_type: atomType,
      content,
      source_chapter: sourceChapter ? parseInt(sourceChapter) : null
    });

    if (res.ok) {
      bootstrap.Modal.getInstance(document.getElementById('addAtomModal'))?.hide();
      this.loadAtoms();
      this.loadStats();
    } else {
      alert(res.msg || '添加失败');
    }
  },

  // 显示编辑原子记忆对话框
  showEditAtomModal(atomId, atomType, content) {
    const typeOptions = ['character_trait', 'world_setting', 'plot_detail', 'style_preference', 'constraint']
      .map(t => `<option value="${t}" ${t === atomType ? 'selected' : ''}>${t === 'character_trait' ? '角色特征' : t === 'world_setting' ? '世界观' : t === 'plot_detail' ? '情节细节' : t === 'style_preference' ? '风格偏好' : '约束'}</option>`).join('');

    const html = `
      <div class="modal fade" id="editAtomModal" tabindex="-1">
        <div class="modal-dialog">
          <div class="modal-content bg-dark border-secondary">
            <div class="modal-header border-secondary">
              <h5 class="modal-title text-light"><i class="bi bi-pencil me-2"></i>编辑原子记忆</h5>
              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <input type="hidden" id="editAtomId" value="${atomId}">
              <div class="mb-3">
                <label class="form-label text-light">类型</label>
                <select class="form-select bg-secondary border-secondary text-light" id="editAtomType">
                  ${typeOptions}
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label text-light">内容</label>
                <textarea class="form-control bg-secondary border-secondary text-light" id="editAtomContent" rows="4">${this.escapeHtml(content)}</textarea>
              </div>
            </div>
            <div class="modal-footer border-secondary">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
              <button type="button" class="btn btn-primary" onclick="MemoryUI.updateAtom()">保存</button>
            </div>
          </div>
        </div>
      </div>
    `;

    document.getElementById('editAtomModal')?.remove();
    document.body.insertAdjacentHTML('beforeend', html);

    const modal = new bootstrap.Modal(document.getElementById('editAtomModal'));
    modal.show();
  },

  // 更新原子记忆
  async updateAtom() {
    const atomId = document.getElementById('editAtomId')?.value;
    const atomType = document.getElementById('editAtomType')?.value;
    const content = document.getElementById('editAtomContent')?.value?.trim();

    if (!content) {
      alert('请输入记忆内容');
      return;
    }

    const res = await MemoryAPI.call('update_atom', {
      atom_id: parseInt(atomId),
      atom_type: atomType,
      content
    });

    if (res.ok) {
      bootstrap.Modal.getInstance(document.getElementById('editAtomModal'))?.hide();
      this.loadAtoms();
      this.loadStats();
    } else {
      alert(res.msg || '更新失败');
    }
  },

  // 显示添加聚类对话框
  showAddClusterModal() {
    const html = `
      <div class="modal fade" id="addClusterModal" tabindex="-1">
        <div class="modal-dialog">
          <div class="modal-content bg-dark border-secondary">
            <div class="modal-header border-secondary">
              <h5 class="modal-title text-light"><i class="bi bi-diagram-3 me-2"></i>创建场景聚类</h5>
              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <div class="mb-3">
                <label class="form-label text-light">聚类类型</label>
                <select class="form-select bg-secondary border-secondary text-light" id="newClusterType">
                  <option value="character_arc">角色弧线</option>
                  <option value="plot_arc">情节弧线</option>
                  <option value="world_evolution">世界观演变</option>
                  <option value="theme">主题</option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label text-light">名称</label>
                <input type="text" class="form-control bg-secondary border-secondary text-light" id="newClusterName" placeholder="输入聚类名称...">
              </div>
              <div class="mb-3">
                <label class="form-label text-light">描述（可选）</label>
                <textarea class="form-control bg-secondary border-secondary text-light" id="newClusterDesc" rows="2" placeholder="输入描述..."></textarea>
              </div>
              <div class="mb-3">
                <label class="form-label text-light">章节范围（可选）</label>
                <input type="text" class="form-control bg-secondary border-secondary text-light" id="newClusterRange" placeholder="例如：第1-5章">
              </div>
            </div>
            <div class="modal-footer border-secondary">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
              <button type="button" class="btn btn-primary" onclick="MemoryUI.addCluster()">创建</button>
            </div>
          </div>
        </div>
      </div>
    `;
    
    document.getElementById('addClusterModal')?.remove();
    document.body.insertAdjacentHTML('beforeend', html);
    
    const modal = new bootstrap.Modal(document.getElementById('addClusterModal'));
    modal.show();
  },

  // 创建聚类
  async addCluster() {
    const clusterType = document.getElementById('newClusterType')?.value;
    const name = document.getElementById('newClusterName')?.value?.trim();
    const description = document.getElementById('newClusterDesc')?.value?.trim();
    const chapterRange = document.getElementById('newClusterRange')?.value?.trim();

    if (!name) {
      alert('请输入聚类名称');
      return;
    }

    const res = await MemoryAPI.call('create_cluster', {
      cluster_type: clusterType,
      name,
      description: description || null,
      chapter_range: chapterRange || null
    });

    if (res.ok) {
      bootstrap.Modal.getInstance(document.getElementById('addClusterModal'))?.hide();
      this.loadClusters();
      this.loadStats();
    } else {
      alert(res.msg || '创建失败');
    }
  },

  // 编辑画像
  editPersona() {
    alert('画像编辑功能开发中...');
  },

  // 加载自动提取设置
  loadAutoExtractSetting() {
    const saved = localStorage.getItem(`novel_${NOVEL_ID}_auto_extract`);
    const toggle = document.getElementById('autoExtractToggle');
    if (toggle) {
      toggle.checked = saved !== 'false'; // 默认开启
    }
  },

  // 保存自动提取设置
  saveAutoExtractSetting(enabled) {
    localStorage.setItem(`novel_${NOVEL_ID}_auto_extract`, enabled);
  },

  // 检查是否启用自动提取
  isAutoExtractEnabled() {
    return document.getElementById('autoExtractToggle')?.checked ?? true;
  },

  // 自动提取章节记忆（在章节完成时调用）
  async autoExtractChapterMemory(chapterId) {
    if (!this.isAutoExtractEnabled()) return;
    
    const res = await MemoryAPI.call('extract_chapter', { chapter_id: chapterId });
    if (res.ok) {
      console.log('记忆提取完成:', res.msg);
      this.loadStats();
      // 如果当前在记忆引擎标签页，刷新原子记忆列表
      if (document.querySelector('#tab-memory.show')) {
        this.loadAtoms();
      }
    }
  },

  // HTML转义
  escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }
};

// 页面加载完成后初始化记忆引擎
document.addEventListener('DOMContentLoaded', () => {
  MemoryUI.init();
  AgentPanel.init();

  // ================================================================
  // 章节列表按钮绑定（查看 / 写作 / 详情Modal操作）
  // ================================================================

  // 「查看」按钮 → 打开章节详情 Modal
  document.querySelectorAll('.btn-chapter-detail').forEach(btn => {
    btn.addEventListener('click', function() {
      var chapterId  = this.dataset.chapterId;
      var chapterNum = this.dataset.chapterNum;
      if (!chapterId) return;

      document.getElementById('detail-chapter-id').value = chapterId;
      document.getElementById('detail-chapter-num').textContent = chapterNum;

      // 加载章节详情
      fetch('api/actions.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'get_chapter_detail', chapter_id: parseInt(chapterId) })
      })
      .then(r => r.json())
      .then(data => {
        if (data.ok && data.chapter) {
          var ch = data.chapter;
          document.getElementById('detail-title').value = ch.title || '';
          document.getElementById('detail-outline').value = ch.outline || '';
          document.getElementById('detail-content').textContent = ch.content || '暂无内容';
          document.getElementById('detail-words').textContent = (ch.words || 0) + ' 字';
          document.getElementById('detail-synopsis').textContent = ch.synopsis || '暂无概要';
        } else {
          document.getElementById('detail-content').textContent = '加载失败：' + (data.msg || '未知错误');
        }
      })
      .catch(err => {
        document.getElementById('detail-content').textContent = '网络错误：' + err.message;
      });

      var modal = new bootstrap.Modal(document.getElementById('chapterDetailModal'));
      modal.show();
    });
  });

  // 「写作」按钮 → 单章流式写作
  document.querySelectorAll('.btn-write-single').forEach(btn => {
    btn.addEventListener('click', function() {
      var novelId   = this.dataset.novel;
      var chapterId = this.dataset.chapter;
      if (!novelId || !chapterId) return;

      var modalEl = document.getElementById('writeModal');
      if (!modalEl) { alert('缺少写作对话框'); return; }
      var modal = new bootstrap.Modal(modalEl);
      modal.show();

      var contentEl = document.getElementById('writeModalContent');
      var statsEl   = document.getElementById('writeModalStats');
      if (contentEl) contentEl.textContent = '';
      if (statsEl)   statsEl.textContent = '';

      fetch('api/write_chapter.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ novel_id: parseInt(novelId), chapter_id: parseInt(chapterId) })
      })
      .then(response => {
        var reader  = response.body.getReader();
        var decoder = new TextDecoder();
        var fullText = '';
        var gotContent = false;

        function read() {
          reader.read().then(function(result) {
            if (result.done) {
              if (gotContent && fullText.length > 50) {
                // SSE 流正常结束但可能后端后处理还在进行，等一下确认
                if (statsEl) statsEl.textContent = '章节写作完成，等待后端处理...';
                setTimeout(function() { window._novelContentUpdated = true; location.reload(); }, 3000);
              } else {
                if (statsEl) statsEl.textContent += '（关闭对话框后将刷新页面）';
                window._novelContentUpdated = true;
              }
              return;
            }
            var text = decoder.decode(result.value);
            var lines = text.split('\n');
            for (var i = 0; i < lines.length; i++) {
              var line = lines[i];
              if (!line.startsWith('data: ')) continue;
              var payload = line.slice(6);
              if (payload === '[DONE]') {
                if (statsEl) statsEl.textContent += '（关闭对话框后将刷新页面）';
                window._novelContentUpdated = true;
                return;
              }
              try {
                var d = JSON.parse(payload);
                if (d.chunk && contentEl) {
                  fullText += d.chunk;
                  gotContent = true;
                  contentEl.textContent = fullText;
                  contentEl.scrollTop = contentEl.scrollHeight;
                }
                if (d.stats && statsEl) {
                  statsEl.textContent = d.stats;
                  gotContent = true;
                }
              } catch(e) {}
            }
            read();
          }).catch(function(readErr) {
            // 网络层错误（ERR_INCOMPLETE_CHUNKED_ENCODING 等）
            // 后端 ignore_user_abort(true) 会继续落盘，等几秒刷新确认
            if (gotContent && fullText.length > 50) {
              if (statsEl) statsEl.textContent = '连接中断，后端可能仍在处理中，3秒后刷新查看...';
              setTimeout(function() { window._novelContentUpdated = true; location.reload(); }, 3000);
            } else {
              if (contentEl) contentEl.textContent = '写作失败：网络连接中断（' + readErr.message + '）';
            }
          });
        }
        read();
      })
      .catch(err => {
        if (contentEl) contentEl.textContent = '写作失败：' + err.message;
      });
    });
  });

  // 详情Modal → 「查看完整章节」跳转
  var detailViewBtn = document.getElementById('detail-view-chapter');
  if (detailViewBtn) {
    detailViewBtn.addEventListener('click', function(e) {
      e.preventDefault();
      var chapterId = document.getElementById('detail-chapter-id')?.value;
      if (chapterId) {
        window.location.href = 'chapter.php?id=' + chapterId;
      }
    });
  }

  // 详情Modal → 保存标题
  var btnUpdateTitle = document.getElementById('btn-update-title');
  if (btnUpdateTitle) {
    btnUpdateTitle.addEventListener('click', async function() {
      var chapterId = parseInt(document.getElementById('detail-chapter-id')?.value || 0);
      var title = document.getElementById('detail-title')?.value || '';
      if (!chapterId) return;
      try {
        var res = await fetch('api/actions.php', {
          method: 'POST',
          headers: {'Content-Type':'application/json'},
          body: JSON.stringify({ action: 'save_chapter', chapter_id: chapterId, title: title })
        });
        var data = await res.json();
        if (data.ok) { alert('标题已保存'); location.reload(); }
        else { alert('保存失败：' + (data.msg || '')); }
      } catch(err) { alert('保存失败：' + err.message); }
    });
  }

  // 详情Modal → 保存大纲
  var btnUpdateOutline = document.getElementById('btn-update-outline');
  if (btnUpdateOutline) {
    btnUpdateOutline.addEventListener('click', async function() {
      var chapterId = parseInt(document.getElementById('detail-chapter-id')?.value || 0);
      var outline = document.getElementById('detail-outline')?.value || '';
      if (!chapterId) return;
      try {
        var res = await fetch('api/actions.php', {
          method: 'POST',
          headers: {'Content-Type':'application/json'},
          body: JSON.stringify({ action: 'save_chapter_outline', chapter_id: chapterId, outline: outline, key_points: [], hook: '' })
        });
        var data = await res.json();
        if (data.ok) alert('大纲已保存');
        else alert('保存失败：' + (data.msg || ''));
      } catch(err) { alert('保存失败：' + err.message); }
    });
  }

  // 详情Modal → 清空章节
  var btnClearContent = document.getElementById('btn-clear-content');
  if (btnClearContent) {
    btnClearContent.addEventListener('click', async function() {
      if (!confirm('确定要清空本章内容吗？此操作不可撤销。')) return;
      var chapterId = parseInt(document.getElementById('detail-chapter-id')?.value || 0);
      if (!chapterId) return;
      try {
        var res = await fetch('api/actions.php', {
          method: 'POST',
          headers: {'Content-Type':'application/json'},
          body: JSON.stringify({ action: 'save_chapter', chapter_id: chapterId, content: '' })
        });
        var data = await res.json();
        if (data.ok) { alert('章节已清空'); location.reload(); }
        else alert('清空失败：' + (data.msg || ''));
      } catch(err) { alert('清空失败：' + err.message); }
    });
  }

  // 详情Modal → 重新生成
  var btnRegenerate = document.getElementById('btn-regenerate');
  if (btnRegenerate) {
    btnRegenerate.addEventListener('click', async function() {
      if (!confirm('确定要重新生成本章吗？现有内容将被替换。')) return;
      var chapterId = parseInt(document.getElementById('detail-chapter-id')?.value || 0);
      var plotHint = document.getElementById('detail-plot-hint')?.value || '';
      if (!chapterId) return;

      // 关闭详情 Modal
      var detailModal = bootstrap.Modal.getInstance(document.getElementById('chapterDetailModal'));
      if (detailModal) detailModal.hide();

      // 打开写作 Modal
      var modalEl = document.getElementById('writeModal');
      if (!modalEl) { alert('缺少写作对话框'); return; }
      var modal = new bootstrap.Modal(modalEl);
      modal.show();
      var contentEl = document.getElementById('writeModalContent');
      var statsEl   = document.getElementById('writeModalStats');
      if (contentEl) contentEl.textContent = '';
      if (statsEl)   statsEl.textContent = plotHint ? '剧情提示：' + plotHint : '';

      // 先重置章节状态
      await fetch('api/actions.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'reset_chapter', chapter_id: chapterId })
      });

      // 调用写作 API
      fetch('api/write_chapter.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ novel_id: NOVEL_ID, chapter_id: chapterId, plot_hint: plotHint })
      })
      .then(response => {
        var reader  = response.body.getReader();
        var decoder = new TextDecoder();
        var fullText = '';
        var gotContent = false;
        function read() {
          reader.read().then(function(result) {
            if (result.done) {
              if (gotContent && fullText.length > 50) {
                if (statsEl) statsEl.textContent = '章节写作完成，等待后端处理...';
                setTimeout(function() { window._novelContentUpdated = true; location.reload(); }, 3000);
              } else {
                if (statsEl) statsEl.textContent += '（关闭对话框后将刷新页面）';
                window._novelContentUpdated = true;
              }
              return;
            }
            var text = decoder.decode(result.value);
            var lines = text.split('\n');
            for (var i = 0; i < lines.length; i++) {
              var line = lines[i];
              if (!line.startsWith('data: ')) continue;
              var payload = line.slice(6);
              if (payload === '[DONE]') {
                if (statsEl) statsEl.textContent += '（关闭对话框后将刷新页面）';
                window._novelContentUpdated = true;
                return;
              }
              try {
                var d = JSON.parse(payload);
                if (d.chunk && contentEl) {
                  fullText += d.chunk;
                  gotContent = true;
                  contentEl.textContent = fullText;
                  contentEl.scrollTop = contentEl.scrollHeight;
                }
                if (d.stats && statsEl) statsEl.textContent = d.stats;
              } catch(e) {}
            }
            read();
          }).catch(function(readErr) {
            if (gotContent && fullText.length > 50) {
              if (statsEl) statsEl.textContent = '连接中断，后端可能仍在处理中，3秒后刷新查看...';
              setTimeout(function() { window._novelContentUpdated = true; location.reload(); }, 3000);
            } else {
              if (contentEl) contentEl.textContent = '重新生成失败：网络连接中断（' + readErr.message + '）';
            }
          });
        }
        read();
      })
      .catch(err => {
        if (contentEl) contentEl.textContent = '重新生成失败：' + err.message;
      });
    });
  }

  // 写作 Modal 关闭后刷新（如果内容已更新）
  var writeModalEl = document.getElementById('writeModal');
  if (writeModalEl) {
    writeModalEl.addEventListener('hidden.bs.modal', function() {
      if (window._novelContentUpdated) {
        location.reload();
      }
    });
  }

  // 取消写作（重置章节状态）
  window.resetSingleChapter = async function(chapterId) {
    if (!confirm('确定要取消当前写作吗？章节状态将重置为"待写作"。')) return;
    try {
      var res = await fetch('api/actions.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'reset_chapter', chapter_id: chapterId })
      });
      var data = await res.json();
      if (data.ok) { location.reload(); }
      else { alert('操作失败：' + (data.msg || '')); }
    } catch(err) { alert('操作失败：' + err.message); }
  };
});

// ================================================================
// 挂机写作控制器
// ================================================================
const DaemonWrite = {
  _pollTimer: null,
  _token: null,   // 缓存 token，避免重复请求

  // 获取 daemon token（从后端取一次后缓存）
  async _getToken() {
    if (this._token) return this._token;
    try {
      const res = await fetch('api/daemon_write.php', { cache: 'no-store' });
      const d   = await res.json();
      if (d.token) {
        this._token = d.token;
        return this._token;
      }
    } catch(e) {}
    return null;
  },

  // 切换启用/停用
  async toggle() {
    const btn     = document.getElementById('btn-daemon-write');
    const enabled = btn?.dataset.enabled === '1';
    if (enabled) {
      await this.stop();
    } else {
      // 启用前先检查是否有其他书籍开着挂机
      const token = await this._getToken();
      if (token) {
        try {
          const res = await fetch(`api/daemon_write.php?token=${token}&action=status`);
          const d   = await res.json();
          if (d.ok && d.data && d.data.id && d.data.id != NOVEL_ID && d.data.daemon_write == 1) {
            if (!confirm(`《${d.data.title}》正在挂机写作中，确定切换到当前书籍吗？（将自动停止原书籍的挂机）`)) return;
          }
        } catch(e) {}
      }
      await this.start();
    }
  },

  // 启用挂机写作
  async start() {
    const token = await this._getToken();
    if (!token) { alert('获取挂机令牌失败，请刷新页面重试'); return; }

    try {
      const res = await fetch(`api/daemon_write.php?token=${token}&novel_id=${NOVEL_ID}&action=enable`);
      const d   = await res.json();
      if (!d.ok) { alert('启用失败：' + d.msg); return; }

      // 如果切换了其他书籍，给出提示
      if (d.switched_from) {
        showToast(`已自动关闭《${d.switched_from.title}》的挂机，切换到当前书籍`, 'warning');
      }

      // 更新按钮状态
      const btn = document.getElementById('btn-daemon-write');
      if (btn) {
        btn.dataset.enabled = '1';
        btn.className = btn.className.replace('btn-outline-success','btn-success');
        btn.innerHTML = '<i class="bi bi-robot me-1"></i>挂机中';
      }
      document.getElementById('daemon-write-panel')?.classList.remove('d-none');

      // 生成 Cron 命令
      this._updateCurlCmd(token);

      // 开始轮询状态
      this._startPoll(token);

      showToast('挂机写作已启用，等待宝塔 Cron 触发（每分钟一次）', 'success');
    } catch(e) {
      alert('操作失败：' + e.message);
    }
  },

  // 停用挂机写作
  async stop() {
    if (!confirm('确定停止挂机写作？当前正在写作的章节会在本次完成后停止。')) return;
    const token = await this._getToken();
    if (!token) { alert('获取令牌失败'); return; }

    try {
      const res = await fetch(`api/daemon_write.php?token=${token}&novel_id=${NOVEL_ID}&action=disable`);
      const d   = await res.json();
      if (!d.ok) { alert('停用失败：' + d.msg); return; }

      // 更新按钮
      const btn = document.getElementById('btn-daemon-write');
      if (btn) {
        btn.dataset.enabled = '0';
        btn.className = btn.className.replace('btn-success','btn-outline-success');
        btn.innerHTML = '<i class="bi bi-robot me-1"></i>挂机写作';
      }
      document.getElementById('daemon-write-panel')?.classList.add('d-none');
      this._stopPoll();
      showToast('挂机写作已停止', 'info');
    } catch(e) {
      alert('操作失败：' + e.message);
    }
  },

  // 更新 Cron 命令显示（不含 novel_id，全局唯一）
  _updateCurlCmd(token) {
    const origin = location.origin + location.pathname.replace(/\/[^\/]*$/, '');
    const cmd    = `curl -s "${origin}/api/daemon_write.php?token=${token}"`;
    const el = document.getElementById('daemon-curl-cmd');
    if (el) el.textContent = cmd;
  },

  // 复制 Cron 命令
  async copyCmd() {
    const el = document.getElementById('daemon-curl-cmd');
    if (!el) return;
    try {
      await navigator.clipboard.writeText(el.textContent);
      showToast('命令已复制到剪贴板', 'success');
    } catch(e) {
      prompt('请手动复制以下命令：', el.textContent);
    }
  },

  // 开始轮询状态（每 30 秒刷新一次）
  _startPoll(token) {
    this._stopPoll();
    this._updateCurlCmd(token);
    this._poll(token);
    this._pollTimer = setInterval(() => this._poll(token), 30000);
  },

  _stopPoll() {
    if (this._pollTimer) {
      clearInterval(this._pollTimer);
      this._pollTimer = null;
    }
  },

  async _poll(token) {
    try {
      const res = await fetch(`api/daemon_write.php?token=${token}&novel_id=${NOVEL_ID}&action=status`);
      const d   = await res.json();
      if (!d.ok || !d.data) return;
      this._renderStatus(d.data);
    } catch(e) {}
  },

  _renderStatus(data) {
    const completed = data.completed ?? 0;
    const outlined  = data.outlined  ?? 0;
    const remain    = outlined - completed;
    const pct       = outlined > 0 ? Math.round(completed / outlined * 100) : 0;
    const words     = data.total_words ?? 0;
    const locked    = data.locked ?? false;

    const el = (id) => document.getElementById(id);
    if (el('daemon-stat-done'))   el('daemon-stat-done').textContent   = completed;
    if (el('daemon-stat-remain')) el('daemon-stat-remain').textContent = remain > 0 ? remain : 0;
    if (el('daemon-stat-words'))  el('daemon-stat-words').textContent  = words >= 10000 ? (words/10000).toFixed(1)+'万' : words;
    if (el('daemon-progress-bar')) el('daemon-progress-bar').style.width = pct + '%';
    if (el('daemon-progress-pct')) el('daemon-progress-pct').textContent = pct + '%';

    const label = el('daemon-progress-label');
    if (label) {
      if (data.daemon_write == 0) {
        label.textContent = '挂机已完成或已停用';
      } else if (locked) {
        label.textContent = '正在写作中...（宝塔 Cron 已触发）';
      } else {
        label.textContent = `等待下次触发（已完成 ${completed}/${outlined} 章）`;
      }
    }

    const badge = el('daemon-badge');
    if (badge) {
      if (data.daemon_write == 0) {
        badge.className = 'badge bg-secondary';
        badge.textContent = '已完成';
      } else if (locked) {
        badge.className = 'badge bg-warning text-dark';
        badge.textContent = '写作中';
      } else {
        badge.className = 'badge bg-success';
        badge.textContent = '运行中';
      }
    }

    // 渲染日志
    const logsEl = el('daemon-logs');
    if (logsEl && Array.isArray(data.logs) && data.logs.length > 0) {
      logsEl.innerHTML = data.logs.map(log => {
        const isErr  = log.action?.includes('error') || log.action?.includes('fail');
        const isDone = log.action?.includes('done') || log.action?.includes('complete');
        const cls = isErr ? 'text-danger' : isDone ? 'text-success' : 'text-muted';
        return `<div class="${cls}">[${(log.created_at||'').slice(11,19)}] ${this._esc(log.message||log.action)}</div>`;
      }).join('');
      logsEl.scrollTop = logsEl.scrollHeight;
    }

    // 如果挂机已关闭，停止轮询
    if (!data.daemon_write) {
      this._stopPoll();
    }
  },

  _esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  },

  // 页面加载时初始化（如果已是挂机状态则自动开始轮询）
  async init() {
    const btn = document.getElementById('btn-daemon-write');
    if (!btn || btn.dataset.enabled !== '1') return;

    const token = await this._getToken();
    if (!token) return;
    this._updateCurlCmd(token);
    this._startPoll(token);
  },
};

// 页面加载完成后初始化挂机控制器
document.addEventListener('DOMContentLoaded', () => DaemonWrite.init());

// ── 封面管理弹窗 ──────────────────────────────────────────────
function showCoverModal(novelId) {
    var existing = document.getElementById('coverManageModal');
    if (existing) existing.remove();

    var currentCover = <?= json_encode($novel['cover_image'] ?? '') ?>;
    var coverColor = <?= json_encode($novel['cover_color'] ?? '#6366f1') ?>;
    var title = <?= json_encode($novel['title']) ?>;

    var previewHtml = currentCover
        ? '<img src="' + currentCover + '?t=' + Date.now() + '" style="width:100%;height:100%;object-fit:cover;border-radius:8px">'
        : '<div style="width:100%;height:100%;background:linear-gradient(135deg,' + coverColor + ',' + coverColor + '99);border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:2rem;font-weight:700">' + title.substring(0, 4) + '</div>';

    var modal = document.createElement('div');
    modal.id = 'coverManageModal';
    modal.className = 'modal fade';
    modal.innerHTML = `
    <div class="modal-dialog">
      <div class="modal-content bg-dark text-light">
        <div class="modal-header border-secondary">
          <h5 class="modal-title"><i class="bi bi-image me-2"></i>管理封面 - ${title}</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div style="width:200px;height:267px;margin:0 auto 16px;overflow:hidden;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,.3)" id="cover-modal-preview">${previewHtml}</div>

          <div class="mb-3">
            <label class="form-label small text-muted">上传封面图片</label>
            <div class="d-flex gap-2">
              <input type="file" class="form-control form-control-sm" id="cover-modal-file" accept="image/jpeg,image/png,image/webp" style="max-width:280px">
              <button type="button" class="btn btn-sm btn-outline-primary" onclick="uploadCoverFromModal(${novelId})"><i class="bi bi-upload me-1"></i>上传</button>
            </div>
            <div class="form-text">推荐分辨率 1086×1448，支持 JPG/PNG/WebP</div>
          </div>

          <div class="mb-3">
            <label class="form-label small text-muted">AI 生成封面（gpt-image-2）</label>
            <div class="input-group input-group-sm">
              <input type="text" class="form-control" id="cover-modal-keyword" placeholder="输入封面描述关键词">
              <button type="button" class="btn btn-outline-info" onclick="generateCoverFromModal(${novelId})"><i class="bi bi-stars me-1"></i>生成</button>
            </div>
          </div>

          <div id="cover-modal-status" class="small" style="display:none"></div>

          ${currentCover ? '<button type="button" class="btn btn-sm btn-outline-danger mt-2" onclick="deleteCoverFromModal(' + novelId + ')"><i class="bi bi-trash me-1"></i>移除当前封面</button>' : ''}
        </div>
      </div>
    </div>`;
    document.body.appendChild(modal);
    new bootstrap.Modal(modal).show();
}

function uploadCoverFromModal(novelId) {
    var fileInput = document.getElementById('cover-modal-file');
    if (!fileInput.files || !fileInput.files[0]) { alert('请先选择图片'); return; }

    var status = document.getElementById('cover-modal-status');
    status.style.display = '';
    status.className = 'small text-info';
    status.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>上传中...';

    var fd = new FormData();
    fd.append('action', 'upload');
    fd.append('novel_id', novelId);
    fd.append('cover_file', fileInput.files[0]);

    fetch('api/cover_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            status.className = 'small text-success';
            status.innerHTML = '<i class="bi bi-check-circle me-1"></i>' + data.msg;
            document.getElementById('cover-modal-preview').innerHTML = '<img src="' + data.path + '?t=' + Date.now() + '" style="width:100%;height:100%;object-fit:cover;border-radius:8px">';
            // 更新主页面封面
            var mainCover = document.querySelector('.novel-cover-sm');
            if (mainCover) {
                mainCover.style.background = 'none';
                mainCover.innerHTML = '<img src="' + data.path + '?t=' + Date.now() + '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:inherit">';
            }
        } else {
            status.className = 'small text-danger';
            status.innerHTML = '<i class="bi bi-x-circle me-1"></i>' + (data.msg || '上传失败');
        }
    })
    .catch(err => {
        status.className = 'small text-danger';
        status.innerHTML = '<i class="bi bi-x-circle me-1"></i>' + err.message;
    });
}

function generateCoverFromModal(novelId) {
    var keyword = document.getElementById('cover-modal-keyword').value.trim();
    if (!keyword) { alert('请输入封面描述关键词'); return; }

    var status = document.getElementById('cover-modal-status');
    status.style.display = '';
    status.className = 'small text-info';
    status.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>正在生成封面，请稍候（约30秒）...';

    fetch('api/cover_actions.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'generate', novel_id: novelId, keyword: keyword })
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            status.className = 'small text-success';
            status.innerHTML = '<i class="bi bi-check-circle me-1"></i>' + data.msg;
            document.getElementById('cover-modal-preview').innerHTML = '<img src="' + data.path + '?t=' + Date.now() + '" style="width:100%;height:100%;object-fit:cover;border-radius:8px">';
            var mainCover = document.querySelector('.novel-cover-sm');
            if (mainCover) {
                mainCover.style.background = 'none';
                mainCover.innerHTML = '<img src="' + data.path + '?t=' + Date.now() + '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:inherit">';
            }
        } else {
            status.className = 'small text-danger';
            status.innerHTML = '<i class="bi bi-x-circle me-1"></i>' + (data.msg || '生成失败');
        }
    })
    .catch(err => {
        status.className = 'small text-danger';
        status.innerHTML = '<i class="bi bi-x-circle me-1"></i>' + err.message;
    });
}

function deleteCoverFromModal(novelId) {
    if (!confirm('确定移除当前封面图片？')) return;

    var status = document.getElementById('cover-modal-status');
    status.style.display = '';
    status.className = 'small text-info';
    status.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>删除中...';

    fetch('api/cover_actions.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'delete', novel_id: novelId })
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            status.className = 'small text-success';
            status.innerHTML = '<i class="bi bi-check-circle me-1"></i>封面已移除';
            var coverColor = <?= json_encode($novel['cover_color'] ?? '#6366f1') ?>;
            var title = <?= json_encode($novel['title']) ?>;
            document.getElementById('cover-modal-preview').innerHTML = '<div style="width:100%;height:100%;background:linear-gradient(135deg,' + coverColor + ',' + coverColor + '99);border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:2rem;font-weight:700">' + title.substring(0, 4) + '</div>';
            var mainCover = document.querySelector('.novel-cover-sm');
            if (mainCover) {
                mainCover.style.background = 'linear-gradient(135deg,' + coverColor + ',' + coverColor + '99)';
                mainCover.innerHTML = title.substring(0, 4);
            }
        } else {
            status.className = 'small text-danger';
            status.innerHTML = '<i class="bi bi-x-circle me-1"></i>' + (data.msg || '删除失败');
        }
    })
    .catch(err => {
        status.className = 'small text-danger';
        status.innerHTML = '<i class="bi bi-x-circle me-1"></i>' + err.message;
    });
}

// ================================================================
// Agent 决策面板
// ================================================================

const AgentPanel = {
  _data: null,

  // ── 初始化 ──────────────────────────────────────────────────
  init() {
    // 监听 Agent 决策 Tab 切换
    const agentTab = document.querySelector('a[href="#tab-agent"]');
    if (agentTab) {
      agentTab.addEventListener('shown.bs.tab', () => {
        if (!this._data) this.loadAll();
      });
    }

    // 监听子标签页切换（不需要重新加载数据，只做渲染）
    document.querySelectorAll('#agentSubTabs [data-bs-toggle="pill"]').forEach(tab => {
      tab.addEventListener('shown.bs.tab', (e) => {
        if (!this._data) return;
        const target = e.target.getAttribute('data-bs-target');
        if (target === '#agent-timeline') this.renderTimeline();
        if (target === '#agent-directives') this.renderDirectives();
        if (target === '#agent-outcomes') this.renderOutcomes();
      });
    });
  },

  // ── 加载全部数据 ────────────────────────────────────────────
  async loadAll() {
    this.showLoading();
    try {
      const res = await fetch('api/agent_status.php?novel_id=' + NOVEL_ID);
      const data = await res.json();
      if (!data.ok) {
        this.showError(data.msg || '加载失败');
        return;
      }
      this._data = data;
      this.updateStats(data.stats);
      this.renderTimeline();
    } catch (e) {
      this.showError(e.message);
    }
  },

  // ── 刷新 ────────────────────────────────────────────────────
  async refresh() {
    this._data = null;
    await this.loadAll();
  },

  // ── 加载中态 ────────────────────────────────────────────────
  showLoading() {
    ['agent-timeline-content','agent-directives-content','agent-outcomes-content'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.innerHTML = '<div class="text-center text-muted py-5"><div class="spinner-border spinner-border-sm me-2"></div>加载中...</div>';
    });
    // 重置状态徽章为加载态
    const badge = document.getElementById('agent-status-badge');
    if (badge) { badge.className = 'badge bg-light text-dark'; badge.textContent = '加载中...'; }
  },

  showError(msg) {
    ['agent-timeline-content'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.innerHTML = '<div class="text-center text-danger py-5"><p>' + this._esc(msg) + '</p></div>';
    });
    // 更新状态徽章
    const badge = document.getElementById('agent-status-badge');
    if (badge) { badge.className = 'badge bg-danger'; badge.textContent = '连接失败'; }
  },

  // ── 更新统计卡片 ────────────────────────────────────────────
  updateStats(s) {
    document.getElementById('stat-total-decisions').textContent = s.total_decisions ?? '-';
    document.getElementById('stat-success-rate').textContent = (s.success_rate ?? '-') + '%';
    document.getElementById('stat-active-directives').textContent = s.active_directives ?? '-';
    document.getElementById('stat-avg-improvement').textContent = (s.avg_improvement ?? 0) >= 0
      ? '+' + ((s.avg_improvement ?? 0) * 100).toFixed(1) + '%'
      : ((s.avg_improvement ?? 0) * 100).toFixed(1) + '%';

    // 更新 Agent 运行状态徽章
    const badge = document.getElementById('agent-status-badge');
    if (badge) {
      if ((s.active_directives ?? 0) > 0) {
        badge.className = 'badge bg-success';
        badge.textContent = '运行中';
      } else {
        badge.className = 'badge bg-secondary';
        badge.textContent = '待命中';
      }
    }
  },

  // ── 决策时间线渲染 ──────────────────────────────────────────
  renderTimeline() {
    const container = document.getElementById('agent-timeline-content');
    if (!container) return;
    const items = this._data?.timeline || [];
    if (!items.length) {
      container.innerHTML = '<div class="text-center text-muted py-5"><i class="bi bi-clock-history fs-1 d-block mb-2"></i><p>暂无决策记录</p><small>Agent 将在章节写作过程中自动产生决策</small></div>';
      return;
    }

    const typeIcons = {
      'writing_strategy': 'bi bi-pencil-square',
      'quality_monitor':  'bi bi-shield-check',
      'optimization':     'bi bi-lightning-charge'
    };
    const typeColors = {
      'writing_strategy': '#6366f1',
      'quality_monitor':  '#10b981',
      'optimization':     '#f59e0b'
    };
    const typeLabels = {
      'writing_strategy': '策略',
      'quality_monitor':  '质量',
      'optimization':     '优化'
    };

    const statusIcons = {
      'success':  'bi bi-check-circle text-success',
      'failed':   'bi bi-x-circle text-danger',
      'skipped':  'bi bi-dash-circle text-muted',
      'decided':  'bi bi-lightbulb text-warning'
    };

    const html = items.map((item, i) => {
      const icon = typeIcons[item.agent_type] || 'bi bi-cpu';
      const color = typeColors[item.agent_type] || '#6b7280';
      const label = typeLabels[item.agent_type] || item.agent_type;
      const statusIcon = statusIcons[item.status] || 'bi bi-dot';
      const time = (item.created_at || '').replace(' ', String.fromCharCode(160)).slice(5, 16);
      const detailStr = item.detail
        ? JSON.stringify(item.detail, null, 1).replace(/[{}"]/g, '').replace(/\n\s*/g, ' ').substring(0, 100)
        : '';
      const actionName = item.action || '未知操作';

      return `
        <div class="d-flex align-items-start py-2 ${i > 0 ? 'border-top border-secondary' : ''}">
          <div class="me-2 rounded-circle d-flex align-items-center justify-content-center"
               style="width:28px;height:28px;background:${color}22;flex-shrink:0">
            <i class="${icon}" style="color:${color};font-size:14px"></i>
          </div>
          <div class="flex-grow-1 min-w-0">
            <div class="d-flex justify-content-between align-items-center">
              <small class="fw-bold text-light">${this._esc(actionName)}</small>
              <small class="text-muted ms-2 flex-shrink-0">${time}</small>
            </div>
            <div class="d-flex align-items-center gap-1 mt-1">
              <span class="badge bg-secondary bg-opacity-25 text-muted" style="font-size:10px">${label}</span>
              <i class="${statusIcon}" style="font-size:12px"></i>
              ${detailStr ? '<small class="text-muted text-truncate d-inline-block" style="max-width:300px">' + this._esc(detailStr) + '</small>' : ''}
            </div>
          </div>
        </div>`;
    }).join('');

    container.innerHTML = html;
  },

  // ── 活跃指令渲染 ────────────────────────────────────────────
  renderDirectives() {
    const container = document.getElementById('agent-directives-content');
    if (!container) return;
    const items = this._data?.directives || [];
    if (!items.length) {
      container.innerHTML = '<div class="text-center text-muted py-5"><i class="bi bi-chat-right-text fs-1 d-block mb-2"></i><p>暂无活跃指令</p><small>Agent 会在写作过程中自动注入优化指令</small></div>';
      return;
    }

    const typeColors = {
      'quality':       'badge bg-success bg-opacity-25 text-success',
      'strategy':      'badge bg-primary bg-opacity-25 text-primary',
      'optimization':  'badge bg-warning bg-opacity-25 text-warning'
    };

    const html = items.map((d, i) => {
      const typeBadge = typeColors[d.type] || 'badge bg-secondary';
      const createdAt = (d.created_at || '').replace(' ', ' ').slice(0, 16);
      const expiresAt = d.expires_at ? (d.expires_at || '').replace(' ', ' ').slice(0, 16) : null;

      return `
        <div class="card bg-secondary border-0 mb-2">
          <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <span class="${typeBadge}">${d.type}</span>
              <small class="text-muted">${createdAt}</small>
            </div>
            <div class="text-light small mb-2">${this._esc(d.directive)}</div>
            <div class="d-flex justify-content-between align-items-center">
              <small class="text-muted">
                <i class="bi bi-book me-1"></i>第${d.apply_from}-${d.apply_to}章
              </small>
              ${expiresAt ? '<small class="text-muted"><i class="bi bi-clock me-1"></i>过期: ' + expiresAt + '</small>' : '<small class="text-success"><i class="bi bi-infinity me-1"></i>永不过期</small>'}
              <button class="btn btn-sm btn-outline-danger" onclick="AgentPanel.deactivateDirective(${d.id})" title="停用">
                <i class="bi bi-x-lg"></i>
              </button>
            </div>
          </div>
        </div>`;
    }).join('');

    container.innerHTML = html;
  },

  // ── 效果分析渲染 ────────────────────────────────────────────
  renderOutcomes() {
    const container = document.getElementById('agent-outcomes-content');
    if (!container) return;
    const outcomes = this._data?.outcomes;
    if (!outcomes) {
      container.innerHTML = '<div class="text-center text-muted py-5">暂无评估数据</div>';
      return;
    }

    let html = '';

    // 按类型统计表
    const byType = outcomes.by_type || [];
    if (byType.length > 0) {
      html += '<h6 class="text-muted mb-3"><i class="bi bi-pie-chart me-2"></i>按指令类型统计</h6>';
      html += '<div class="table-responsive mb-4"><table class="table table-sm table-dark table-borderless"><thead><tr><th class="text-muted">类型</th><th class="text-muted text-end">评估次数</th><th class="text-muted text-end">平均改善</th><th class="text-muted text-end">改善率</th></tr></thead><tbody>';
      byType.forEach(row => {
        const total = parseInt(row.outcome_count) || 0;
        const improved = parseInt(row.improved) || 0;
        const rate = total > 0 ? Math.round((improved / total) * 100) : 0;
        const avg = row.avg_change !== null ? parseFloat(row.avg_change).toFixed(1) : '0.0';
        const avgClass = parseFloat(avg) > 0 ? 'text-success' : parseFloat(avg) < 0 ? 'text-danger' : 'text-muted';
        html += `<tr>
          <td><span class="badge bg-secondary bg-opacity-25">${this._esc(row.type)}</span></td>
          <td class="text-end">${total}</td>
          <td class="text-end ${avgClass}">${avg >= 0 ? '+' : ''}${avg}</td>
          <td class="text-end">${rate}%</td>
        </tr>`;
      });
      html += '</tbody></table></div>';
    }

    // 最有效 TOP5
    const topEff = outcomes.top_effective || [];
    if (topEff.length > 0) {
      html += '<h6 class="text-muted mb-3"><i class="bi bi-graph-up-arrow me-2 text-success"></i>改善最大的指令</h6>';
      html += '<div class="mb-4">';
      topEff.forEach((item, i) => {
        html += `
          <div class="d-flex justify-content-between align-items-center py-2 ${i > 0 ? 'border-top border-secondary' : ''}">
            <div class="small min-w-0 me-2">
              <span class="badge bg-secondary bg-opacity-25">${this._esc(item.type)}</span>
              <span class="text-light ms-1 text-truncate d-inline-block" style="max-width:350px">${this._esc(item.directive || '')}</span>
            </div>
            <span class="small text-success fw-bold flex-shrink-0">+${parseFloat(item.quality_change).toFixed(1)}</span>
          </div>`;
      });
      html += '</div>';
    }

    // 最有副作用 TOP5
    const topHarm = outcomes.top_harmful || [];
    if (topHarm.length > 0) {
      html += '<h6 class="text-muted mb-3"><i class="bi bi-graph-down-arrow me-2 text-danger"></i>需要关注的指令（质量下降）</h6>';
      html += '<div>';
      topHarm.forEach((item, i) => {
        html += `
          <div class="d-flex justify-content-between align-items-center py-2 ${i > 0 ? 'border-top border-secondary' : ''}">
            <div class="small min-w-0 me-2">
              <span class="badge bg-secondary bg-opacity-25">${this._esc(item.type)}</span>
              <span class="text-light ms-1 text-truncate d-inline-block" style="max-width:350px">${this._esc(item.directive || '')}</span>
            </div>
            <span class="small text-danger fw-bold flex-shrink-0">${parseFloat(item.quality_change).toFixed(1)}</span>
          </div>`;
      });
      html += '</div>';
    }

    if (!byType.length && !topEff.length && !topHarm.length) {
      html = '<div class="text-center text-muted py-5"><i class="bi bi-graph-up fs-1 d-block mb-2"></i><p>暂无效果数据</p><small>Agent 指令效果将在章节完成后自动评估</small></div>';
    }

    container.innerHTML = html;
  },

  // ── 停用指令 ────────────────────────────────────────────────
  async deactivateDirective(id) {
    if (!confirm('确定停用该指令？')) return;
    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
      const res = await fetch('api/agent_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({ action: 'deactivate', novel_id: NOVEL_ID, directive_id: id })
      });
      const data = await res.json();
      if (data.ok) {
        this.refresh();
      } else {
        alert(data.msg || '停用失败');
      }
    } catch (e) {
      alert('停用失败: ' + e.message);
    }
  },

  // ── 工具函数 ────────────────────────────────────────────────
  _esc(s) {
    const div = document.createElement('div');
    div.textContent = String(s || '');
    return div.innerHTML;
  }
};
</script>

<?php
// 调试：输出实际加载行数和内存使用
if (defined('APP_DEBUG') && APP_DEBUG && isset($debugInfo)) {
    $memUsed = round(memory_get_usage(true) / 1024 / 1024, 2);
    $memPeak = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
    echo '<!-- DEBUG: Final Memory Usage: ' . $memUsed . 'MB / Peak: ' . $memPeak . 'MB -->' . "\n";
}

pageFooter();

// 确保所有缓冲区都刷新
while (ob_get_level() > 0) {
    ob_end_flush();
}
flush();
?>