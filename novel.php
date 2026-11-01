<?php
// 错误处理：捕获所有致命错误
// 审计 2026-06-11 P1：原实现把 $error['message']/['file']/['line'] 直接拼成 HTML 输出，
// 泄露内部路径与代码结构。统一改为与 includes/error_handler.php 一致的收口策略：
// 完整细节只写 error_log，客户端仅看到友好文案 + 可追踪请求号。
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        // 清除之前的输出，避免半截 HTML
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // 追踪号：与 includes/helpers.php 的 error_trace_id() 同语义；
        // 此处独立生成兜底，避免 helpers.php 尚未加载时报未定义函数。
        $rid = '';
        try {
            $rid = bin2hex(random_bytes(6));
        } catch (\Throwable $e) {
            $rid = substr(md5(uniqid('', true)), 0, 12);
        }

        // 完整诊断细节只写服务端日志
        error_log(sprintf(
            '[%s] novel.php fatal: %s in %s:%d',
            $rid,
            $error['message'] ?? '',
            $error['file'] ?? '?',
            $error['line'] ?? 0
        ));

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo "<!DOCTYPE html>\n";
        echo "<html><head><meta charset='UTF-8'><title>服务器错误</title></head><body>\n";
        echo "<h1>服务器内部错误</h1>\n";
        echo "<p>页面加载失败，请稍后重试。</p>\n";
        echo "<p style=\"color:#999;font-size:.9em\">追踪号：" . htmlspecialchars($rid, ENT_QUOTES, 'UTF-8') . "</p>\n";
        echo "</body></html>\n";
        exit;
    }
});

// 禁止向页面输出错误，由已注册的错误处理函数记录
ini_set('display_errors', 0);

define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/write_engine.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/author/AuthorProfile.php';

$id    = (int)($_GET['id'] ?? 0);
$zombieRecovered = 0;

// 所有按 novel_id 执行的恢复、暂停、画像读取都可能产生副作用，必须先
// 完成页面级归属校验，不能仅在页面渲染前补一道迟到的检查。
$novel = getNovel($id);
if (!$novel) {
    header('Location: index.php');
    exit;
}
$novel = requireNovelAccess($novel);

// ================================================================
// Watchdog：统一走 WriteEngine 的 Canon-aware 恢复器。页面层没有 claim token，
// 不能自行把 writing 改成 outlined；共享恢复器会锁行、排除有效 lease，并将
// 孤儿 rewrite claim 恢复为 completed Canon。
// ================================================================
if ($id > 0) {
    try {
        $watchdogRecovered = WriteEngine::recoverStaleWritingChapters($id);
        $zombieRecovered = count($watchdogRecovered);
        foreach ($watchdogRecovered as $recoveredChapter) {
            $chapterNumber = (int)($recoveredChapter['chapter_number'] ?? 0);
            $restoredStatus = (string)($recoveredChapter['restored_status'] ?? 'outlined');
            addLog(
                $id,
                'watchdog_recover',
                "自动恢复卡死章节：第{$chapterNumber}章恢复为{$restoredStatus}"
            );
        }
    } catch (Throwable $e) {
        error_log('novel.php Watchdog 失败: ' . $e->getMessage());
        $zombieRecovered = 0;
    }
}

// 修复（2026-06-18）：页面刷新时校正 writing 状态
// 场景：用户点击"自动写作" → novels.status='writing' → 用户刷新页面（未点暂停）
// 前端 JS 状态丢失，无法恢复对后台 worker 的控制（SSE/轮询连接已断开）
// 修复：刷新时若 status=writing，直接重置为 paused，按钮显示"自动写作"
// 若后台 worker 仍在运行，用户点击"自动写作"后 write_start 会检测到已有任务
// 并返回 resumed=true，前端恢复轮询（见 write_start.php 修复）
if ($novel && ($novel['status'] ?? '') === 'writing') {
    DB::update('novels', ['status' => 'paused'], 'id=?', [$id]);
    $novel['status'] = 'paused';
}

$boundProfile = null;
if (!empty($novel['author_profile_id'])) {
    $boundProfile = AuthorProfile::find((int)$novel['author_profile_id']);
}

// [v42] 性能优化（审计 2026-06-10 P1-A）：本页只需要章节号/状态做计数与下拉，
// 原先无分页地全量拉取所有章节（含正文 LONGTEXT），千章书一次数十 MB。
// 改为一条轻查询 + 分页查询，整本书正文不再进入页面渲染路径。
$chapterIndex = DB::fetchAll(
    'SELECT chapter_number, status FROM chapters WHERE novel_id=? ORDER BY chapter_number ASC',
    [$id]
);
$models = DB::fetchAll('SELECT * FROM ai_models ORDER BY is_default DESC, id ASC');

// 章节分页：50章/页
$totalChapterCount = count($chapterIndex);
$perPage           = 50;
$totalPages        = max(1, (int)ceil($totalChapterCount / $perPage));
$currentPage       = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
$chapters          = getNovelChapters($id, $currentPage, $perPage);

// 质检警告原因摘要（gate_results.hard_fail_reason）。质检未过现仅提示，
// completed 章与旧版 failed 章都要显示警告，因此批量查询当前页全部章节。
$qualityWarningChapterIds = array_map(static fn($c) => (int)$c['id'], $chapters);
$hardFailMap = getFailedChapterReasons($id, $qualityWarningChapterIds);

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

$outlined  = count(array_filter($chapterIndex, fn($c) => in_array($c['status'], ['outlined','writing','completed'])));
$completed = count(array_filter($chapterIndex, fn($c) => $c['status'] === 'completed'));
$progress  = $novel['target_chapters'] > 0 ? round($completed / $novel['target_chapters'] * 100) : 0;
$volumeCount = 0;
try {
    $volumeCount = (int)DB::count('volume_outlines', 'novel_id=?', [$id]);
} catch (Throwable $e) {
    $volumeCount = 0;
}
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
        <?php if ($boundProfile): 
            $pData = $boundProfile->toArray(); 
            $pName = $pData['profile_name'] ?? '画像';
            $pStatus = $pData['analysis_status'] ?? 'pending';
        ?>
        <span class="text-info"><i class="bi bi-person-badge me-1"></i><?= h($pName) ?> <?= $pStatus === 'completed' ? '✅' : '⏳' ?></span>
        <?php endif; ?>
        <span><i class="bi bi-person me-1"></i><?= h($novel['protagonist_name'] ?: '未设定') ?></span>
        <span><i class="bi bi-calendar me-1"></i><?= h(formatAppTime($novel['created_at'], 'Y-m-d')) ?></span>
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
                data-completed="<?= $completed ?>"
                title="生成故事大纲；已有任务时恢复进度，已有结果时直接读取">
          <i class="bi bi-map me-1"></i>生成全书故事大纲
        </button>
        <!-- 生成卷纲（中层规划，写入 volume_goals） -->
        <button class="btn btn-sm btn-outline-primary" id="btn-volume-outline"
                data-novel="<?= $id ?>"
                data-target="<?= (int)$novel['target_chapters'] ?>"
                data-volumes="<?= $volumeCount ?>"
                data-has-story="<?= !empty($novel['has_story_outline']) ? '1' : '0' ?>"
                <?= empty($novel['has_story_outline']) ? 'disabled title="请先生成全书故事大纲"' : ((int)$novel['target_chapters'] < 30 ? 'disabled title="目标章节少于30章，无需卷纲"' : '') ?>>
          <i class="bi bi-collection me-1"></i><?= $volumeCount > 0 ? '重新生成卷纲' : '生成卷纲' ?>
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
                <?= (empty($novel['has_story_outline']) || $outlined === 0) ? 'disabled title="请先生成全书故事大纲和章节大纲"' : '' ?>>
          <i class="bi bi-file-text me-1"></i>生成章节概要
        </button>
		
        <!-- 补写大纲 -->
        <button class="btn btn-sm btn-outline-warning" id="btn-supplement-outline"
                data-novel="<?= $id ?>"
                data-outlined="<?= $outlined ?>"
                data-target="<?= $novel['target_chapters'] ?>"
                <?= $outlined === 0 ? 'disabled title="请先生成大纲"' : '' ?>
                <?= $outlined >= $novel['target_chapters'] ? 'disabled title="所有章节大纲已完整，无需补写"' : '' ?>>
          <i class="bi bi-patch-plus me-1"></i>补写缺失细纲
        </button>
        <!-- 优化大纲逻辑 -->
        <button class="btn btn-sm btn-outline-success" id="btn-optimize-outline"
                data-novel="<?= $id ?>"
                <?= (!$novel['has_story_outline'] || $outlined === 0) ? 'disabled title="请先生成全书故事大纲和章节大纲"' : '' ?>>
          <i class="bi bi-lightning-charge me-1"></i>优化章节逻辑
        </button>
        <a href="assist.php?id=<?= $id ?>" class="btn btn-sm btn-outline-info">
          <i class="bi bi-stars me-1"></i>智能创作辅助系统
        </a>
        <!-- 导入章节概要（创建后即可用）-->
        <button class="btn btn-sm btn-outline-primary" id="btn-import-synopsis-top"
                data-csp-click="novel.choose-import-top">
          <i class="bi bi-upload me-1"></i>导入章节概要
        </button>
        <input type="file" id="import-file-input-top" accept=".json,.csv,.txt" style="display:none"
               data-csp-change="novel.import-synopses">
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
        <!-- [v41] 全书体检报告 -->
        <button class="btn btn-sm btn-outline-info" id="btn-fullbook-audit"
                data-bs-toggle="modal" data-bs-target="#fullbookAuditModal">
          <i class="bi bi-clipboard2-pulse me-1"></i>全书体检
        </button>
        <a href="create.php?edit=<?= $id ?>" class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-pencil me-1"></i>编辑设定
        </a>
        <button class="btn btn-sm btn-outline-danger" data-csp-click="novel.delete" data-novel-id="<?= (int)$id ?>">
          <i class="bi bi-trash me-1"></i>删除
        </button>
        <a href="api/index.php?route=export_novel&amp;id=<?= $id ?>" class="btn btn-sm btn-outline-success" <?= $completed === 0 ? 'disabled title="暂无已完成的章节"' : '' ?>>
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
      <button class="btn btn-sm btn-outline-info" id="btn-regenerate-story-outline" data-novel="<?= $id ?>" data-csp-click="novel.regenerate-story-outline">
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
      <i class="bi bi-clock me-1"></i>生成时间: <?= h(formatAppTime($storyOutline['created_at'])) ?>
      <?php if ($storyOutline['updated_at'] !== $storyOutline['created_at']): ?>
      <span class="ms-3"><i class="bi bi-pencil-square me-1"></i>最后编辑: <?= h(formatAppTime($storyOutline['updated_at'])) ?></span>
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
        <button class="btn btn-sm btn-outline-danger" id="btn-stop-write" data-csp-click="novel.stop-auto-write">
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
    <button class="btn btn-sm btn-outline-danger" data-csp-click="novel.daemon-stop">
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
              data-csp-click="novel.daemon-copy-command" title="复制命令">
        <i class="bi bi-clipboard"></i>
      </button>
      <button class="btn btn-sm btn-link btn-outline-0 text-warning p-0 ms-1"
              data-csp-click="novel.daemon-rotate-token" title="轮换令牌（旧令牌立即失效）">
        <i class="bi bi-arrow-repeat"></i>轮换令牌
      </button>
      <div class="mt-1 text-muted">
        <i class="bi bi-clock me-1"></i><span id="daemon-schedule-summary">运行时间将在首次状态刷新后显示</span>
        · <a href="writing_settings.php?tab=params#ws-section-daemon" class="link-info">修改运行时间</a>
      </div>
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
        <div id="story-outline-progress-spinner" class="spinner-border spinner-border-sm text-primary"></div>
        <span class="fw-semibold" id="story-outline-progress-label">正在生成全书故事大纲...</span>
      </div>
      <div class="d-flex gap-2">
        <button type="button" id="btn-resume-story-outline" class="btn btn-sm btn-outline-primary" hidden>继续未完成任务</button>
        <button type="button" id="btn-restart-story-outline" class="btn btn-sm btn-outline-info" hidden>重新生成</button>
        <button type="button" id="btn-cancel-story-outline" class="btn btn-sm btn-outline-secondary" hidden>停止任务</button>
      </div>
    </div>
    <div class="px-3 pt-2 small text-muted" id="story-outline-task-detail" aria-live="polite"></div>
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

<!-- Volume outline progress (hidden) -->
<div id="volume-outline-progress-wrap" class="mb-3" style="display:none">
  <div class="page-card">
    <div class="p-3 border-bottom border-secondary d-flex align-items-center justify-content-between">
      <div class="d-flex align-items-center gap-2">
        <div class="spinner-border spinner-border-sm text-primary"></div>
        <span class="fw-semibold" id="volume-outline-progress-label">正在生成卷纲...</span>
      </div>
    </div>
    <div id="volume-outline-stream-box" class="outline-stream-box">
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
    <a class="nav-link" data-bs-toggle="tab" href="#tab-emotion" id="tab-emotion-trigger">
      <i class="bi bi-graph-up me-1"></i>健康监控
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
    <?php include __DIR__ . '/includes/chapter_list_partial.php'; ?>
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
          <a href="knowledge.php?novel_id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-journal-bookmark me-1"></i>打开知识库
          </a>
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
                <?php foreach ($chapterIndex as $ch): ?>
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
              <button class="btn btn-sm btn-outline-success" data-csp-click="novel.new-card">
                <i class="bi bi-person-plus me-1"></i>新建角色
              </button>
              <button class="btn btn-sm btn-outline-secondary" data-csp-click="novel.load-cards">
                <i class="bi bi-arrow-clockwise me-1"></i>刷新
              </button>
              <a href="character_network.php?novel_id=<?= $id ?>" class="btn btn-sm btn-outline-info">
                <i class="bi bi-diagram-3 me-1"></i>查看关系网络
              </a>
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
            <div class="setting-value">
              <span id="display-target-chapters"><?= $novel['target_chapters'] ?> 章</span>
              <button class="btn btn-link btn-sm p-0 ms-2" data-csp-click="novel.edit-target" title="修改目标章数">
                <i class="bi bi-pencil small"></i>
              </button>
            </div>
            <div id="edit-target-chapters" class="d-none mt-2">
              <input type="number" id="input-target-chapters" class="form-control form-control-sm d-inline-block" style="width:100px" value="<?= $novel['target_chapters'] ?>" min="1" max="10000">
              <button class="btn btn-sm btn-primary ms-2" data-csp-click="novel.save-target">保存</button>
              <button class="btn btn-sm btn-outline-secondary ms-1" data-csp-click="novel.cancel-target">取消</button>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="setting-item">
            <div class="setting-label">每章目标字数</div>
            <div class="setting-value">
              <span id="display-chapter-words"><?= number_format($novel['chapter_words']) ?> 字</span>
              <button class="btn btn-link btn-sm p-0 ms-2" data-csp-click="novel.edit-words" title="修改每章字数">
                <i class="bi bi-pencil small"></i>
              </button>
            </div>
            <div id="edit-chapter-words" class="d-none mt-2">
              <input type="number" id="input-chapter-words" class="form-control form-control-sm d-inline-block" style="width:100px" value="<?= $novel['chapter_words'] ?>" min="500" max="20000" step="100">
              <button class="btn btn-sm btn-primary ms-2" data-csp-click="novel.save-words">保存</button>
              <button class="btn btn-sm btn-outline-secondary ms-1" data-csp-click="novel.cancel-words">取消</button>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="setting-item">
            <div class="setting-label">目标读者</div>
            <div class="setting-value"><?= h(getReaderProfile($novel['target_reader'] ?? 'general')['label']) ?></div>
          </div>
        </div>
      </div>
      <?php if (($novel['target_reader'] ?? 'general') !== 'general'): ?>
      <div class="alert alert-info mt-3 mb-0 py-2 px-3 small">
        <i class="bi bi-info-circle me-1"></i>
        读者偏好：<?= h(getReaderProfile($novel['target_reader'] ?? 'general')['prompt_hint']) ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Agent Decision Tab -->
  <div class="tab-pane fade" id="tab-agent">
    <div class="page-card p-4">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="mb-0"><i class="bi bi-cpu me-2"></i>Agent 决策中心</h5>
        <div class="d-flex gap-2">
          <span class="badge bg-success" id="agent-status-badge">运行中</span>
          <button class="btn btn-sm btn-outline-light" data-csp-click="novel.refresh-agents" title="刷新">
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

  <!-- Health Dashboard Tab (v1.10.3 工程控制论) -->
  <div class="tab-pane fade" id="tab-emotion">
    <div class="page-card p-4">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="mb-0"><i class="bi bi-heart-pulse me-2"></i>全书健康度仪表板</h5>
        <div>
          <span class="badge me-2" id="health-score-badge" style="font-size:1rem">--/100</span>
          <span class="text-muted small" id="health-alert-count"></span>
        </div>
      </div>

      <!-- 第一行：情绪曲线 + 质量曲线 -->
      <div class="row mb-3">
        <div class="col-md-6">
          <h6 class="text-muted mb-2"><i class="bi bi-graph-up me-1"></i>情绪分数曲线</h6>
          <div style="position:relative;height:280px;">
            <canvas id="emotion-canvas"></canvas>
          </div>
        </div>
        <div class="col-md-6">
          <h6 class="text-muted mb-2"><i class="bi bi-award me-1"></i>质量分数曲线</h6>
          <div style="position:relative;height:280px;">
            <canvas id="quality-canvas"></canvas>
          </div>
        </div>
      </div>

      <!-- 第二行：爽点分布 + 钩子分布 -->
      <div class="row mb-3">
        <div class="col-md-6">
          <h6 class="text-muted mb-2"><i class="bi bi-lightning-charge me-1"></i>爽点类型分布</h6>
          <div style="position:relative;height:220px;">
            <canvas id="coolpoint-canvas"></canvas>
          </div>
          <div class="text-center small text-muted mt-1" id="coolpoint-density-text"></div>
        </div>
        <div class="col-md-6">
          <h6 class="text-muted mb-2"><i class="bi bi-link-45deg me-1"></i>钩子类型分布</h6>
          <div style="position:relative;height:220px;">
            <canvas id="hook-canvas"></canvas>
          </div>
          <!-- v41 钩子回收健康 -->
          <div id="hook-health" class="small mt-2"></div>
        </div>
      </div>

      <!-- 第三行：角色出场 + 伏笔健康 -->
      <div class="row mb-3">
        <div class="col-md-6">
          <h6 class="text-muted mb-2"><i class="bi bi-people me-1"></i>角色出场状态</h6>
          <div id="character-status-list" class="list-group list-group-flush" style="max-height:220px;overflow-y:auto">
            <div class="text-center text-muted py-3 small">加载中...</div>
          </div>
        </div>
        <div class="col-md-6">
          <h6 class="text-muted mb-2"><i class="bi bi-bookmark-star me-1"></i>待回收伏笔</h6>
          <div id="foreshadow-status-list" class="list-group list-group-flush" style="max-height:220px;overflow-y:auto">
            <div class="text-center text-muted py-3 small">加载中...</div>
          </div>
        </div>
      </div>

      <!-- 系统健康告警 -->
      <div id="health-alerts-section" class="mt-3" style="display:none">
        <h6 class="text-muted mb-2"><i class="bi bi-exclamation-triangle me-1"></i>系统健康告警</h6>
        <div id="health-alerts-list" class="list-group list-group-flush"></div>
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
            <span class="small" style="color:var(--text-muted)"><?= h(formatAppTime($log['created_at'])) ?></span>
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

        <!-- 人工评分 (v1.10.3) -->
        <div class="mb-4" id="human-critic-section">
          <label class="form-label text-light fw-semibold">
            <i class="bi bi-star me-1"></i>人工评分 <small class="text-muted">（可选，用于校准CriticAgent）</small>
          </label>
          <div class="row g-2" id="human-critic-dims">
            <div class="col"><label class="form-label small text-muted mb-1">爽感</label><input type="range" class="form-range" min="1" max="10" value="5" data-dim="thrill" id="hc-thrill"><span class="small text-light" id="hc-thrill-v">5</span></div>
            <div class="col"><label class="form-label small text-muted mb-1">代入</label><input type="range" class="form-range" min="1" max="10" value="5" data-dim="immersion" id="hc-immersion"><span class="small text-light" id="hc-immersion-v">5</span></div>
            <div class="col"><label class="form-label small text-muted mb-1">节奏</label><input type="range" class="form-range" min="1" max="10" value="5" data-dim="pacing" id="hc-pacing"><span class="small text-light" id="hc-pacing-v">5</span></div>
            <div class="col"><label class="form-label small text-muted mb-1">新鲜</label><input type="range" class="form-range" min="1" max="10" value="5" data-dim="freshness" id="hc-freshness"><span class="small text-light" id="hc-freshness-v">5</span></div>
            <div class="col"><label class="form-label small text-muted mb-1">追读</label><input type="range" class="form-range" min="1" max="10" value="5" data-dim="read_next" id="hc-read_next"><span class="small text-light" id="hc-read_next-v">5</span></div>
          </div>
          <div class="d-flex gap-2 mt-2">
            <button class="btn btn-outline-primary btn-sm" id="btn-save-human-critic">
              <i class="bi bi-check-lg me-1"></i>保存评分
            </button>
            <span class="small text-muted align-self-center" id="human-critic-status"></span>
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

<!-- Add Chapters Modal -->
<div class="modal fade" id="addChaptersModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content bg-dark border-secondary">
      <div class="modal-header border-secondary">
        <h5 class="modal-title text-light"><i class="bi bi-plus-circle me-2"></i>添加章节</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label text-light fw-semibold">章节数量</label>
          <input type="number" class="form-control bg-secondary border-secondary text-light" id="add-chapters-count" min="1" max="200" value="10" placeholder="输入要添加的章节数">
          <div class="form-text text-muted">支持 1 - 200 章</div>
        </div>
        <div class="text-muted small mb-2" id="add-chapters-hint">
          将从第 <?= (int)(DB::fetch('SELECT COALESCE(MAX(chapter_number), 0) AS m FROM chapters WHERE novel_id=?', [$id])['m'] ?? 0) + 1 ?> 章开始添加
        </div>
      </div>
      <div class="modal-footer border-secondary">
        <button type="button" class="btn btn-outline-warning" id="btn-add-chapters-auto">
          <i class="bi bi-robot me-1"></i>自动生成
        </button>
        <button type="button" class="btn btn-primary" id="btn-add-chapters-manual">
          <i class="bi bi-pencil me-1"></i>手动编辑
        </button>
      </div>
    </div>
  </div>
</div>

<script src="assets/dist/chart.umd.min.js"></script>
<script nonce="<?= cspNonce() ?>">
// CSP delegated page actions. Values stay in data attributes, never executable code.
window.AppEvents.register('novel.daemon-toggle', function(event, element) { DaemonWrite.toggle(); });
window.AppEvents.register('novel.regenerate-story-outline', function(event, element) { regenerateStoryOutline(); });
window.AppEvents.register('novel.stop-auto-write', function(event, element) { stopAutoWrite(); });
window.AppEvents.register('novel.daemon-stop', function(event, element) { DaemonWrite.stop(); });
window.AppEvents.register('novel.daemon-copy-command', function(event, element) { DaemonWrite.copyCmd(); });
window.AppEvents.register('novel.daemon-rotate-token', function(event, element) { DaemonWrite.rotateToken(); });
window.AppEvents.register('novel.new-card', function(event, element) { MemoryUI.showCardEdit(); });
window.AppEvents.register('novel.load-cards', function(event, element) { MemoryUI.loadCards(); });
window.AppEvents.register('novel.edit-target', function(event, element) { editTargetChapters(); });
window.AppEvents.register('novel.save-target', function(event, element) { saveTargetChapters(); });
window.AppEvents.register('novel.cancel-target', function(event, element) { cancelEditTargetChapters(); });
window.AppEvents.register('novel.edit-words', function(event, element) { editChapterWords(); });
window.AppEvents.register('novel.save-words', function(event, element) { saveChapterWords(); });
window.AppEvents.register('novel.cancel-words', function(event, element) { cancelEditChapterWords(); });
window.AppEvents.register('novel.refresh-agents', function(event, element) { AgentPanel.refresh(); });
window.AppEvents.register('novel.edit-persona', function(event, element) { MemoryUI.editPersona(); });
window.AppEvents.register('novel.add-atom', function(event, element) { MemoryUI.addAtom(); });
window.AppEvents.register('novel.update-atom', function(event, element) { MemoryUI.updateAtom(); });
window.AppEvents.register('novel.choose-import-top', function(event, element) { document.getElementById('import-file-input-top').click(); });
window.AppEvents.register('novel.import-synopses', function(event, element) { importSynopses(element.files[0]); });
window.AppEvents.register('novel.delete', function(event, element) { event.stopPropagation(); deleteNovel(Number(element.dataset.novelId)); });
window.AppEvents.register('novel.edit-card', function(event, element) { MemoryUI.showCardEdit(Number(element.dataset.cardId)); });
window.AppEvents.register('novel.card-history', function(event, element) { MemoryUI.showCardHistory(Number(element.dataset.cardId), element.dataset.cardName); });
window.AppEvents.register('novel.delete-card', function(event, element) { MemoryUI.deleteCard(Number(element.dataset.cardId), element.dataset.cardName); });
window.AppEvents.register('novel.save-card', function(event, element) { MemoryUI.saveCard(element.dataset.cardId === '' ? null : Number(element.dataset.cardId)); });
window.AppEvents.register('novel.more-atoms', function(event, element) { MemoryUI.loadAtoms(Number(element.dataset.offset)); });
window.AppEvents.register('novel.edit-atom', function(event, element) { MemoryUI.showEditAtomModal(Number(element.dataset.atomId), element.dataset.atomType, element.dataset.atomContent); });
window.AppEvents.register('novel.delete-atom', function(event, element) { MemoryUI.deleteAtom(Number(element.dataset.atomId)); });
window.AppEvents.register('novel.upload-cover', function(event, element) { uploadCoverFromModal(Number(element.dataset.novelId)); });
window.AppEvents.register('novel.generate-cover', function(event, element) { generateCoverFromModal(Number(element.dataset.novelId)); });
window.AppEvents.register('novel.delete-cover', function(event, element) { deleteCoverFromModal(Number(element.dataset.novelId)); });
window.AppEvents.register('novel.deactivate-directive', function(event, element) { AgentPanel.deactivateDirective(Number(element.dataset.directiveId)); });
window.AppEvents.register('novel.export-synopses', function(event, element) { exportSynopses(element.dataset.format); return false; });
window.AppEvents.register('novel.choose-import', function(event, element) { document.getElementById('import-file-input').click(); });
window.AppEvents.register('novel.add-chapters', function(event, element) { openAddChaptersModal(); });
window.AppEvents.register('novel.clear-chapters', function(event, element) { clearAllChapters(); });
window.AppEvents.register('novel.reset-chapter', function(event, element) { resetSingleChapter(Number(element.dataset.chapterId)); });
// End CSP delegated page actions.

const NOVEL_ID   = <?= $id ?>;
const TARGET_CHS = <?= (int)$novel['target_chapters'] ?>;
const AUTO_WRITE_INTERVAL = <?= (int)getSystemSetting('ws_auto_write_interval', 2, 'int') ?> * 1000;
const OUTLINE_BATCH_SIZE = <?= (int)getSystemSetting('ws_outline_batch', 5, 'int') ?>;
const OUTLINE_BATCH_SIZE_1M = <?= (int)getSystemSetting('ws_outline_batch_1m', 30, 'int') ?>;
// 外置脚本（assets/js/novel_*.js）需要的服务端数据统一在这里输出；
// JSON_HEX_* 会把尖括号、& 和引号转成 \uXXXX，书名等用户数据无法提前结束本脚本。
const NOVEL_PAGE_DATA = <?= json_encode([
    'coverImage'     => (string)($novel['cover_image'] ?? ''),
    'coverColor'     => (string)($novel['cover_color'] ?? '#6366f1'),
    'title'          => (string)($novel['title'] ?? ''),
    'chapterNumbers' => array_map(static fn($ch) => (int)$ch['chapter_number'], $chapterIndex),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
</script>
<script src="assets/js/novel_settings.js?v=<?= assetVersion('assets/js/novel_settings.js') ?>"></script>
<script src="assets/js/novel_memory.js?v=<?= assetVersion('assets/js/novel_memory.js') ?>"></script>
<script src="assets/js/novel_page_init.js?v=<?= assetVersion('assets/js/novel_page_init.js') ?>"></script>
<script src="assets/js/novel_daemon.js?v=<?= assetVersion('assets/js/novel_daemon.js') ?>"></script>
<script src="assets/js/novel_cover.js?v=<?= assetVersion('assets/js/novel_cover.js') ?>"></script>
<script src="assets/js/novel_agent_panel.js?v=<?= assetVersion('assets/js/novel_agent_panel.js') ?>"></script>

<script src="assets/js/novel_health.js?v=<?= assetVersion('assets/js/novel_health.js') ?>"></script>

<!-- [v41] 全书体检报告 Modal -->
<div class="modal fade" id="fullbookAuditModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content bg-dark text-light border-secondary">
      <div class="modal-header border-secondary">
        <h5 class="modal-title"><i class="bi bi-clipboard2-pulse me-2 text-info"></i>全书一致性体检报告</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="fullbook-audit-body">
        <div class="text-center text-muted py-4">加载中…</div>
      </div>
      <div class="modal-footer border-secondary">
        <span class="text-muted small me-auto">每 50 章自动生成，存最近 10 份</span>
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">关闭</button>
      </div>
    </div>
  </div>
</div>
<script src="assets/js/novel_fullbook_audit.js?v=<?= assetVersion('assets/js/novel_fullbook_audit.js') ?>"></script>

<?php
// 审计修复 M-06（2026-06-12）：调试内存信息改用 error_log
if (defined('APP_DEBUG') && APP_DEBUG) {
    $memUsed = round(memory_get_usage(true) / 1024 / 1024, 2);
    $memPeak = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
    error_log("[novel.php] Final Memory Usage: {$memUsed}MB / Peak: {$memPeak}MB");
}

?>

<?php
pageFooter();

// 确保所有缓冲区都刷新
while (ob_get_level() > 0) {
    ob_end_flush();
}
flush();
?>
