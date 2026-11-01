<?php
define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';

$id      = (int)($_GET['id'] ?? 0);
$chapter = getChapter($id);
if (!$chapter) { header('Location: index.php'); exit; }

$novel   = getNovel($chapter['novel_id']);
if (!$novel) { header('Location: index.php'); exit; }
// 审计修复 P2-14（2026-07-12）：页面级归属权校验，防止越权访问
$novel = requireNovelAccess($novel);

$failedPostprocess = $chapter['status'] === 'completed' && trim((string)$chapter['content']) !== '' ? DB::fetch(
    'SELECT id FROM postprocess_jobs WHERE chapter_id=? AND novel_id=? AND stage="full" AND state="failed"
     AND revision_hash=? AND accepted_candidate_uid <=> ? ORDER BY id DESC LIMIT 1',
    [$id, (int)$novel['id'], hash('sha256', (string)$chapter['content']), $chapter['canonical_candidate_uid'] ?? null]
) : null;

$prev = DB::fetch(
    'SELECT id, chapter_number, title FROM chapters WHERE novel_id=? AND chapter_number=? LIMIT 1',
    [$novel['id'], $chapter['chapter_number'] - 1]
);
$next = DB::fetch(
    'SELECT id, chapter_number, title FROM chapters WHERE novel_id=? AND chapter_number=? LIMIT 1',
    [$novel['id'], $chapter['chapter_number'] + 1]
);

$gmModels = DB::fetchAll(
    "SELECT id, name, model_name, is_default FROM ai_models ORDER BY is_default DESC, id ASC"
);
$gmModelsJson = json_encode(array_map(function($m) {
    return [
        'id'         => (int)$m['id'],
        'name'       => $m['name'],
        'model_name' => $m['model_name'] ?? '',
        'is_default' => (int)($m['is_default'] ?? 0),
    ];
}, $gmModels), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

pageHeader("第{$chapter['chapter_number']}章 - {$novel['title']}", 'home');
?>

<!-- Breadcrumb -->
<nav class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="index.php" class="text-muted">书库</a></li>
    <li class="breadcrumb-item"><a href="novel.php?id=<?= $novel['id'] ?>" class="text-muted"><?= h($novel['title']) ?></a></li>
    <li class="breadcrumb-item active text-light"><?= $chapter['chapter_number'] ?>章</li>
  </ol>
</nav>

<style>
.inline-edit-bar {
    position: absolute;
    left: 12px;
    right: 12px;
    z-index: 100;
    padding: 8px 12px;
    background: linear-gradient(135deg, #1e2230, #242836);
    border: 1px solid #6366f1;
    border-radius: 8px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.5);
    animation: inlineSlideDown 0.2s ease;
    user-select: none;
    -webkit-user-select: none;
}
.inline-edit-bar #inline-edit-instruction::placeholder {
    color: #6c757d;
    font-size: .78rem;
}
@keyframes inlineSlideDown {
    from { opacity: 0; transform: translateY(-6px); }
    to { opacity: 1; transform: translateY(0); }
}
.inline-edit-bar .btn-warning {
    white-space: nowrap;
    font-size: .8rem;
}
</style>

<div class="row g-4">
  <!-- Main content -->
  <div class="col-12 col-xl-8">
    <div class="page-card">
      <div class="page-card-header d-flex justify-content-between align-items-center">
        <div>
          <span class="text-muted small"><?= $chapter['chapter_number'] ?>章</span>
          <h5 class="mb-0 mt-1 text-light" id="chapter-title-display"><?= h($chapter['title']) ?></h5>
        </div>
        <div class="d-flex gap-2">
          <?= statusBadge($chapter['status']) ?>
          <button class="btn btn-sm btn-outline-warning" data-csp-click="chapter.toggle-edit">
            <i class="bi bi-pencil"></i> 编辑
          </button>
          <button class="btn btn-sm btn-outline-info" data-csp-click="chapter.show-versions">
            <i class="bi bi-clock-history"></i> 版本
          </button>
          <?php if ($chapter['status'] !== 'completed' && $chapter['status'] === 'outlined'): ?>
          <button class="btn btn-sm btn-primary" id="btn-rewrite" data-chapter="<?= $id ?>">
            <i class="bi bi-arrow-repeat me-1"></i>重新写作
          </button>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($failedPostprocess): ?>
      <div class="alert alert-warning m-3" role="status">
        <p class="mb-2">本章正文已保存，摘要、人物记忆或约束重建失败。重建完成前，后续章节会暂停写作。</p>
        <button type="button" class="btn btn-sm btn-warning" data-csp-click="chapter.retry-postprocess"
                data-chapter-id="<?= (int)$id ?>" data-job-id="<?= (int)$failedPostprocess['id'] ?>"
                data-content-hash="<?= h(hash('sha256', (string)$chapter['content'])) ?>">重试摘要与记忆重建</button>
        <span class="small ms-2" id="postprocess-retry-status"></span>
      </div>
      <?php endif; ?>

      <!-- Read mode -->
      <div id="read-mode" class="p-4"<?php if (isset($_GET['edit']) && $_GET['edit'] === '1') echo ' style="display:none"'; ?>>
        <?php if ($chapter['content']): ?>
        <div class="chapter-content">
          <?= nl2br(h($chapter['content'])) ?>
        </div>
        <div class="d-flex justify-content-between text-muted small mt-4 pt-3 border-top border-secondary">
          <span><i class="bi bi-file-text me-1"></i><?= number_format($chapter['words']) ?> 字</span>
          <span>更新于<?= h($chapter['updated_at']) ?></span>
        </div>
        <?php else: ?>
        <div class="empty-state py-5">
          <div class="empty-icon"><i class="bi bi-file-earmark-text"></i></div>
          <h6>本章暂无内容</h6>
          <?php if ($chapter['status'] === 'outlined'): ?>
          <p class="text-muted small">大纲已生成，点击「开始写作」让 AI 创作，或点击「手动编辑」自己撰写。</p>
          <div class="d-flex gap-2 justify-content-center">
            <button class="btn btn-primary btn-sm" id="btn-rewrite" data-chapter="<?= $id ?>">
              <i class="bi bi-magic me-1"></i>开始写作
            </button>
            <button class="btn btn-outline-warning btn-sm" data-csp-click="chapter.toggle-edit">
              <i class="bi bi-pencil me-1"></i>手动编辑
            </button>
          </div>
          <?php else: ?>
          <p class="text-muted small">尚未生成大纲，可以直接手动撰写本章内容。</p>
          <button class="btn btn-warning btn-sm" data-csp-click="chapter.toggle-edit">
            <i class="bi bi-pencil me-1"></i>手动编辑
          </button>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Edit mode -->
      <div id="edit-mode" class="p-4"<?php if (!isset($_GET['edit']) || $_GET['edit'] !== '1') echo ' style="display:none"'; ?>>
        <div class="mb-3">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <label class="form-label mb-0" for="edit-title">章节标题</label>
            <button type="button"
                    class="btn btn-outline-info btn-sm"
                    id="btn-optimize-title"
                    data-csp-click="chapter.optimize-title" data-chapter-id="<?= (int)$id ?>"
                    title="根据本章大纲概要、关键情节点、结尾钩子优化原来的标题">
              <i class="bi bi-stars me-1"></i>AI标题优化
            </button>
          </div>
          <input type="text" id="edit-title" class="form-control" value="<?= h($chapter['title']) ?>">
        </div>
        <div class="mb-1" style="position:relative">
          <label class="form-label">章节内容</label>
          <textarea id="edit-content" class="form-control chapter-editor" data-csp-input="chapter.word-count" data-content-hash="<?= h(hash('sha256', (string)($chapter['content'] ?? ''))) ?>"><?= h($chapter['content']) ?></textarea>

          <!-- 选中文字优化工具栏 -->
          <div id="inline-edit-bar" class="inline-edit-bar" style="display:none">
            <div class="d-flex align-items-center gap-2">
              <i class="bi bi-highlighter text-warning small"></i>
              <input type="text" id="inline-edit-instruction"
                     class="form-control form-control-sm"
                     placeholder="输入优化指令（留空默认润色）"
                     style="min-width:220px;background:#2d3239;border-color:#495057;color:#e9ecef;font-size:.8rem">
              <button class="btn btn-warning btn-sm" id="btn-inline-polish" data-csp-click="chapter.polish-selection">
                <i class="bi bi-magic me-1"></i>优化
              </button>
              <button class="btn btn-outline-secondary btn-sm" data-csp-click="chapter.hide-inline-bar">
                <i class="bi bi-x"></i>
              </button>
            </div>
          </div>

          <!-- 字数统计栏 -->
          <div class="d-flex justify-content-between align-items-center mt-1">
            <span id="word-count-bar" class="small" style="color:#28a745;">
              当前 <span id="wc-current"><?= number_format((int)$chapter['words']) ?></span> 字 / 目标 <?= number_format((int)$novel['chapter_words']) ?> 字
            </span>
            <button class="btn btn-outline-warning btn-sm" id="btn-compress" data-csp-click="chapter.compress" data-chapter-id="<?= (int)$id ?>" title="AI压缩到目标字数" style="font-size:.75rem;">
              <i class="bi bi-arrows-angle-contract me-1"></i>AI压缩
            </button>
          </div>
        </div>
        <div class="d-flex gap-2 align-items-center">
          <button class="btn btn-outline-info btn-sm" id="btn-polish" data-csp-click="chapter.polish" data-chapter-id="<?= (int)$id ?>">
            <i class="bi bi-magic me-1"></i>一键润色
          </button>
          <button class="btn btn-success btn-sm" data-csp-click="chapter.save" data-chapter-id="<?= (int)$id ?>">
            <i class="bi bi-check-circle me-1"></i>保存
          </button>
          <div class="ms-auto"></div>
          <button class="btn btn-secondary btn-sm" data-csp-click="chapter.toggle-edit">取消</button>
        </div>
      </div>

      <!-- Navigation -->
      <div class="d-flex justify-content-between align-items-center p-3 border-top border-secondary">
        <?php if ($prev): ?>
        <a href="chapter.php?id=<?= $prev['id'] ?>" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-chevron-left me-1"></i><?= $prev['chapter_number'] ?>章<        </a>
        <?php else: ?>
        <span></span>
        <?php endif; ?>
        <a href="novel.php?id=<?= $novel['id'] ?>" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-list-ul me-1"></i>目录
        </a>
        <?php if ($next): ?>
        <a href="chapter.php?id=<?= $next['id'] ?>&edit=1" class="btn btn-outline-secondary btn-sm">
          <?= $next['chapter_number'] ?>章<i class="bi bi-chevron-right ms-1"></i>
        </a>
        <?php else: ?>
        <span></span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Sidebar: outline & info -->
  <div class="col-12 col-xl-4">
    <!-- Outline -->
    <div class="page-card mb-3">
      <div class="page-card-header"><i class="bi bi-file-earmark-text me-2"></i>本章大纲</div>
      <div class="p-3">
        <?php
          // 预处理 key_points：数据库里是 JSON，前端用“每行一个”的纯文本编辑
          $pts      = $chapter['key_points'] ? (json_decode($chapter['key_points'], true) ?? []) : [];
          $ptsText  = is_array($pts) ? implode("\n", $pts) : '';
        ?>

        <!-- 大纲概要 -->
        <div class="mb-3">
          <label class="small text-muted mb-1 fw-semibold d-block">大纲概要</label>
          <textarea id="outline-outline"
                    class="form-control form-control-sm bg-dark border-secondary text-light"
                    rows="4"
                    placeholder="输入本章大纲概要..."><?= h($chapter['outline']) ?></textarea>
        </div>

        <!-- 关键情节点（每行一个） -->
        <div class="mb-3">
          <label class="small text-muted mb-1 fw-semibold d-block">
            关键情节点 <span class="text-muted" style="font-weight:normal">（每行一个）</span>
          </label>
          <textarea id="outline-keypoints"
                    class="form-control form-control-sm bg-dark border-secondary text-light"
                    rows="4"
                    placeholder="输入关键情节点，每行一个..."><?= h($ptsText) ?></textarea>
        </div>

        <!-- 结尾钩子 -->
        <div class="mb-3">
          <label class="small text-muted mb-1 fw-semibold d-block">结尾钩子</label>
          <textarea id="outline-hook"
                    class="form-control form-control-sm bg-dark border-secondary text-light"
                    rows="2"
                    style="background:rgba(99,102,241,.05) !important;"
                    placeholder="输入结尾钩子..."><?= h($chapter['hook']) ?></textarea>
        </div>

        <!-- 操作按钮 -->
        <div class="d-flex justify-content-between">
          <button type="button" class="btn btn-outline-warning btn-sm" id="btn-regenerate-outline"
                  title="用 AI 重新生成本章大纲概要、关键情节点、结尾钩子，填入上方文本框供检查后保存">
            <i class="bi bi-arrow-repeat me-1"></i>重新生成
          </button>
          <button type="button" class="btn btn-primary btn-sm" id="btn-save-outline">
            <i class="bi bi-check-circle me-1"></i>保存大纲
          </button>
        </div>
      </div>
    </div>

    <!-- 质量检测仪表盘 -->
    <div class="page-card mb-3" id="quality-dashboard">
      <div class="page-card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clipboard-check me-2"></i>质量检测</span>
        <?php if ($chapter['status'] === 'completed' && !empty($chapter['content'])): ?>
        <div class="d-flex gap-2">
          <button class="btn btn-sm btn-outline-primary" data-csp-click="chapter.quality-check">
            <i class="bi bi-play-circle me-1"></i>一键检测
          </button>
          <button class="btn btn-sm btn-warning" id="btn-quality-rewrite" data-csp-click="chapter.quality-rewrite" data-chapter-id="<?= (int)$id ?>"
                  data-source-hash="<?= hash('sha256', (string)$chapter['content']) ?>"
                  title="根据完整质量反馈生成候选，检查并采纳后才替换正文">
            <i class="bi bi-magic me-1"></i>一键改写
          </button>
        </div>
        <?php endif; ?>
      </div>
      <div class="p-3" id="quality-body">
        <?php
          $qScore = $chapter['quality_score'] ?? null;
          $gResults = $chapter['gate_results'] ? json_decode($chapter['gate_results'], true) : null;
        ?>
        <?php if ($qScore !== null): ?>
        <!-- 已有检测结果 -->
        <div class="text-center mb-3">
          <div class="d-inline-flex align-items-center justify-content-center rounded-circle" 
               style="width:80px;height:80px;background:<?php
                 echo $qScore >= 80 ? 'rgba(40,167,69,.2)' : ($qScore >= 60 ? 'rgba(255,193,7,.2)' : 'rgba(220,53,69,.2)');
               ?>">
            <span style="font-size:1.8rem;font-weight:700;<?php
              echo $qScore >= 80 ? 'color:#28a745' : ($qScore >= 60 ? 'color:#ffc107' : 'color:#dc3545');
            ?>"><?= number_format($qScore, 0) ?></span>
          </div>
          <div class="text-muted small mt-1">综合评分 /100</div>
        </div>

        <!-- 完整质检详情 -->
        <?php if (!empty($gResults) && is_array($gResults)): ?>
        <div id="gate-details">
          <?php foreach ($gResults as $gate): $gs = $gate['score'] ?? 0; $pass = !empty($gate['status']); ?>
          <div class="d-flex align-items-center mb-2 p-2 rounded" style="background:<?php echo $pass ? 'rgba(40,167,69,.08)' : 'rgba(220,53,69,.08)' ?>;">
            <span class="me-2"><?php echo $pass ? '✓' : '⚠️'; ?></span>
            <small class="flex-grow-1 text-truncate"><?php echo h($gate['name']); ?></small>
            <strong class="ms-2 small" style="color:<?php echo $gs >= 70 ? '#28a745' : '#dc3545' ?>"><?= $gs ?></strong>
            <span class="text-muted small ms-1">分</span>
          </div>
          <?php if (!$pass && !empty($gate['issues'])): ?>
          <div class="small text-danger mb-2 ps-4" style="font-size:.7rem;line-height:1.4;">
            <?php foreach (array_slice($gate['issues'], 0, 3) as $issue): ?>
            <div><?= h($issue) ?></div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="text-muted small mt-2 text-center" id="quality-summary">
          <?php if ($qScore >= 80): ?><span class="text-success">本章质量优秀</span><?php elseif ($qScore >= 60): ?><span class="text-warning">有改进空间</span><?php else: ?><span class="text-danger">建议修改</span><?php endif; ?>
        </div>
        <?php else: ?>
        <!-- 尚未检测 -->
        <div class="text-center py-3 text-muted small">
          <?php if ($chapter['status'] === 'completed'): ?>
          <i class="bi bi-clipboard-check d-block fs-3 mb-2 opacity-50"></i>
          点击「一键检测」运行完整质量流水线<br>
          <span class="opacity-50">结构 · 角色 · 描写 · 爽点 · 连贯 · 跟纲 · 落盘硬质检</span>
          <?php else: ?>
          <i class="bi bi-hourglass-split d-block fs-3 mb-2 opacity-50"></i>
          完成写作后可进行质量检测
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
      <!-- 检测中 spinner（默认隐藏）-->
      <div class="p-3 text-center" id="quality-loading" style="display:none;">
        <div class="spinner-border spinner-border-sm text-primary me-2"></div>
        <span class="text-muted small">正在执行完整质量检测...</span>
      </div>
    </div>

    <!-- Novel Info -->
    <div class="page-card">
      <div class="page-card-header"><i class="bi bi-book me-2"></i><?= h($novel['title']) ?></div>
      <div class="p-3">
        <div class="small text-muted mb-1">主角</div>
        <div class="small text-light mb-2"><?= h($novel['protagonist_name'] ?: '未设置') ?></div>
        <div class="small text-muted mb-1">写作进度</div>
        <div class="d-flex justify-content-between small mb-1">
          <span class="text-light"><?= $novel['current_chapter'] ?>/<?= $novel['target_chapters'] ?> 章</span>
          <span class="text-muted"><?= number_format($novel['total_words']) ?> 字</span>
        </div>
        <div class="progress" style="height:4px">
          <?php $p = $novel['target_chapters']>0 ? round($novel['current_chapter']/$novel['target_chapters']*100) : 0 ?>
          <div class="progress-bar" style="width:<?= $p ?>%;background:<?= h($novel['cover_color']) ?>"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Version History Modal -->
<div class="modal fade" id="versionModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content bg-dark text-light">
      <div class="modal-header border-secondary">
        <h5 class="modal-title"><i class="bi bi-clock-history me-2"></i>版本历史</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="versionList" class="list-group"></div>
      </div>
    </div>
  </div>
</div>

<!-- Version Preview Modal -->
<div class="modal fade" id="versionPreviewModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content bg-dark text-light">
      <div class="modal-header border-secondary">
        <h5 class="modal-title">版本预览</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <pre id="versionPreviewContent" class="text-light" style="white-space:pre-wrap;word-break:break-all;"></pre>
      </div>
      <div class="modal-footer border-secondary">
        <button class="btn btn-danger btn-sm" id="btnRollback">
          <i class="bi bi-arrow-counterclockwise me-1"></i>回滚到此版本
        </button>
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">关闭</button>
      </div>
    </div>
  </div>
</div>

<!-- Rewrite modal -->
<div class="modal fade" id="rewriteModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content bg-dark text-light">
      <div class="modal-header border-secondary">
        <h5 class="modal-title">AI 正在写作第<?= $chapter['chapter_number'] ?>章...</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="rewriteContent" class="chapter-content-preview"></div>
        <div id="rewriteSpinner" class="text-center py-3">
          <div class="spinner-border text-primary"></div>
        </div>
      </div>
      <div class="modal-footer border-secondary">
        <span class="text-muted small" id="rewriteStats"></span>
        <div id="quality-rewrite-actions" hidden>
          <button type="button" class="btn btn-primary btn-sm" id="btn-quality-adopt" data-csp-click="chapter.quality-adopt" hidden disabled>采纳改写</button>
          <button type="button" class="btn btn-outline-warning btn-sm" id="btn-quality-again" data-csp-click="chapter.quality-again" hidden disabled>再次改写</button>
          <button type="button" class="btn btn-outline-info btn-sm" id="btn-quality-resume" data-csp-click="chapter.quality-resume" hidden disabled>继续查询</button>
        </div>
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">关闭</button>
      </div>
    </div>
  </div>
</div>

<script src="assets/js/chapter-quality-rewrite.js?v=<?= assetVersion('assets/js/chapter-quality-rewrite.js') ?>"></script>
<script nonce="<?= cspNonce() ?>">
// CSP delegated page actions. Values stay in data attributes, never executable code.
window.AppEvents.register('chapter.toggle-edit', function(event, element) { toggleEdit(); });
window.AppEvents.register('chapter.show-versions', function(event, element) { showVersionHistory(); });
window.AppEvents.register('chapter.polish-selection', function(event, element) { polishSelection(); });
window.AppEvents.register('chapter.hide-inline-bar', function(event, element) { hideInlineBar(); });
window.AppEvents.register('chapter.quality-check', function(event, element) { runQualityCheck(); });
window.AppEvents.register('chapter.word-count', function(event, element) { updateWordCount(); });
window.AppEvents.register('chapter.optimize-title', function(event, element) { optimizeChapterTitle(Number(element.dataset.chapterId)); });
window.AppEvents.register('chapter.compress', function(event, element) { compressChapter(Number(element.dataset.chapterId)); });
window.AppEvents.register('chapter.polish', function(event, element) { polishChapter(Number(element.dataset.chapterId)); });
window.AppEvents.register('chapter.save', function(event, element) { saveChapter(Number(element.dataset.chapterId)); });
window.AppEvents.register('chapter.retry-postprocess', function(event, element) { retryChapterPostprocess(element); });
window.AppEvents.register('chapter.quality-rewrite', function(event, element) { polishChapter(Number(element.dataset.chapterId), true); });
window.AppEvents.register('chapter.quality-adopt', function() { window.ChapterQualityRewrite.adopt(); });
window.AppEvents.register('chapter.quality-again', function() { window.ChapterQualityRewrite.again(); });
window.AppEvents.register('chapter.quality-resume', function() { window.ChapterQualityRewrite.resume(); });
window.AppEvents.register('chapter.preview-version', function(event, element) { previewVersion(Number(element.dataset.versionId)); });
// End CSP delegated page actions.

const CHAPTER_ID = <?= $id ?>;
const NOVEL_ID   = <?= $novel['id'] ?>;
window.GM_CHAPTER_ID = <?= $id ?>;
window.GM_NOVEL_ID = <?= $novel['id'] ?>;
window.GM_MODELS = <?= $gmModelsJson ?>;
// 外置脚本（assets/js/chapter_*.js）使用的本书单章目标字数。
const CHAPTER_TARGET_WORDS = <?= (int)$novel['chapter_words'] ?>;
</script>
<script src="assets/js/chapter_editor.js?v=<?= assetVersion('assets/js/chapter_editor.js') ?>"></script>
<script src="assets/js/chapter_versions.js?v=<?= assetVersion('assets/js/chapter_versions.js') ?>"></script>
<script src="assets/js/chapter_outline.js?v=<?= assetVersion('assets/js/chapter_outline.js') ?>"></script>
<script src="assets/js/chapter_polish.js?v=<?= assetVersion('assets/js/chapter_polish.js') ?>"></script>
<script src="assets/js/chapter_word_count.js?v=<?= assetVersion('assets/js/chapter_word_count.js') ?>"></script>
<script src="assets/js/chapter_compress.js?v=<?= assetVersion('assets/js/chapter_compress.js') ?>"></script>
<script src="assets/js/chapter_regenerate.js?v=<?= assetVersion('assets/js/chapter_regenerate.js') ?>"></script>
<script src="assets/js/chapter_selection.js?v=<?= assetVersion('assets/js/chapter_selection.js') ?>"></script>

<!-- Good Moling（墨灵）智能创作助手 -->
<style>
.gm-panel .offcanvas-body { display:flex; flex-direction:column; padding:0!important; min-height:0; }
.gm-preset-bar { padding:10px 14px; border-bottom:1px solid #2d2d4e; background:#12122a; }
.gm-preset-bar .btn { font-size:.75rem; padding:3px 8px; }
.gm-chat-list { flex:1; overflow-y:auto; padding:12px 14px; background:#0f0f1a; }
.gm-msg { margin-bottom:12px; }
.gm-msg-user { text-align:right; }
.gm-bubble { display:inline-block; max-width:90%; padding:8px 12px; border-radius:12px; font-size:.9rem; line-height:1.7; word-break:break-word; text-align:left; }
.gm-bubble-user { background:#6366f1; color:#fff; border-bottom-right-radius:4px; }
.gm-bubble-ai { background:#1e2230; color:#e8e8ff; border:1px solid #2d2d4e; border-bottom-left-radius:4px; }
.gm-msg-actions { margin-top:4px; display:flex; gap:6px; }
.gm-msg-actions .btn { font-size:.7rem; padding:2px 8px; }
.gm-thinking { display:inline-flex; align-items:center; gap:8px; color:#adb5bd; }
.gm-dots span { display:inline-block; width:6px; height:6px; border-radius:50%; background:#6366f1; animation:gm-blink 1.2s infinite; }
.gm-dots span:nth-child(2) { animation-delay:.2s; }
.gm-dots span:nth-child(3) { animation-delay:.4s; }
@keyframes gm-blink { 0%,80%,100%{opacity:.3} 40%{opacity:1} }
.gm-thinking-label { font-size:.85rem; }
.gm-patch-start { background:#2d2440; border-left:3px solid #ffc107; padding:4px 10px; margin:6px 0; border-radius:0 6px 6px 0; font-weight:600; color:#ffc107; }
.gm-input-bar { padding:10px 14px; border-top:1px solid #2d2d4e; background:#1a1a2e; }
.gm-input-bar textarea { resize:none; }
.gm-ctx-bar { padding:6px 14px; border-bottom:1px solid #2d2d4e; background:#12122a; font-size:.75rem; }
.gm-ctx-bar label { color:#adb5bd; cursor:pointer; }
.gm-fab { position:fixed; bottom:24px; right:24px; z-index:1040; width:80px; height:80px; border:none; background:transparent; cursor:pointer; padding:0; outline:none; }
.gm-spirit { position:relative; width:80px; height:80px; display:flex; align-items:center; justify-content:center; }
.gm-spirit-body { width:56px; height:56px; border-radius:50% 50% 50% 50% / 60% 60% 40% 40%; background:linear-gradient(135deg,#6366f1,#8b5cf6,#a78bfa); position:relative; box-shadow:0 0 20px rgba(99,102,241,.5),0 0 40px rgba(139,92,246,.3); animation:gm-float 3s ease-in-out infinite; }
.gm-spirit-body::before { content:''; position:absolute; top:8px; left:10px; width:12px; height:14px; background:rgba(255,255,255,.9); border-radius:50%; box-shadow:20px 0 0 rgba(255,255,255,.9); }
.gm-spirit-body::after { content:''; position:absolute; top:12px; left:14px; width:5px; height:6px; background:#1a1a2e; border-radius:50%; box-shadow:20px 0 0 #1a1a2e; }
.gm-spirit-mouth { position:absolute; bottom:16px; left:50%; transform:translateX(-50%); width:10px; height:5px; border-bottom:2px solid rgba(255,255,255,.7); border-radius:0 0 50% 50%; }
.gm-spirit-ink { position:absolute; bottom:-2px; left:50%; transform:translateX(-50%); width:8px; height:8px; background:radial-gradient(circle,#6366f1,transparent); border-radius:50%; animation:gm-ink-drip 2.5s ease-in-out infinite; opacity:.6; }
.gm-spirit-glow { position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); width:80px; height:80px; border-radius:50%; background:radial-gradient(circle,rgba(99,102,241,.25),transparent 70%); animation:gm-glow-pulse 3s ease-in-out infinite; pointer-events:none; }
.gm-spirit-label { position:absolute; bottom:-20px; left:50%; transform:translateX(-50%); font-size:.7rem; color:#a78bfa; white-space:nowrap; text-shadow:0 0 8px rgba(99,102,241,.6); font-weight:600; letter-spacing:1px; }
.gm-fab:hover .gm-spirit-body { box-shadow:0 0 28px rgba(99,102,241,.7),0 0 56px rgba(139,92,246,.4); animation:gm-float-hover 1.5s ease-in-out infinite; }
.gm-fab:hover .gm-spirit-label { color:#c4b5fd; }
@keyframes gm-float { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-6px)} }
@keyframes gm-float-hover { 0%,100%{transform:translateY(0) scale(1.05)} 50%{transform:translateY(-8px) scale(1.05)} }
@keyframes gm-glow-pulse { 0%,100%{opacity:.5;transform:translate(-50%,-50%) scale(1)} 50%{opacity:1;transform:translate(-50%,-50%) scale(1.15)} }
@keyframes gm-ink-drip { 0%,100%{transform:translateX(-50%) translateY(0);opacity:.6} 50%{transform:translateX(-50%) translateY(4px);opacity:.2} }
</style>

<button class="gm-fab" data-bs-toggle="offcanvas" data-bs-target="#gmPanel" title="Good Moling（墨灵）">
  <div class="gm-spirit">
    <div class="gm-spirit-glow"></div>
    <div class="gm-spirit-body">
      <div class="gm-spirit-mouth"></div>
      <div class="gm-spirit-ink"></div>
    </div>
    <span class="gm-spirit-label">墨灵</span>
  </div>
</button>

<div class="offcanvas offcanvas-end bg-dark text-light gm-panel" tabindex="-1" id="gmPanel" style="width:460px;max-width:100vw;">
  <div class="offcanvas-header border-bottom border-secondary pb-0">
    <div class="w-100">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="offcanvas-title mb-0"><i class="bi bi-stars me-2 text-primary"></i>Good Moling（墨灵）</h5>
        <div class="d-flex gap-2">
          <button class="btn btn-sm btn-outline-secondary" id="gm-clear-btn" title="清空对话">
            <i class="bi bi-trash"></i>
          </button>
          <button class="btn btn-sm btn-outline-secondary" id="gm-stop-btn" title="停止生成" style="display:none">
            <i class="bi bi-stop-circle"></i>
          </button>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
        </div>
      </div>
      <div class="d-flex align-items-center gap-2 mb-2">
        <label class="small text-muted mb-0" style="min-width:36px;">模型</label>
        <select id="gm-model-select" class="form-select form-select-sm bg-dark text-light border-secondary">
          <option value="">加载中...</option>
        </select>
      </div>
    </div>
  </div>

  <div class="gm-preset-bar">
    <div class="d-flex flex-wrap gap-2">
      <button class="btn btn-sm btn-outline-info gm-preset-btn" data-preset="analyze_chapter"><i class="bi bi-clipboard-check me-1"></i>分析章节</button>
      <button class="btn btn-sm btn-outline-info gm-preset-btn" data-preset="polish_chapter"><i class="bi bi-magic me-1"></i>优化文风</button>
      <button class="btn btn-sm btn-outline-info gm-preset-btn" data-preset="continue_write"><i class="bi bi-arrow-right-circle me-1"></i>续写一段</button>
      <button class="btn btn-sm btn-outline-info gm-preset-btn" data-preset="generate_outline"><i class="bi bi-signpost-split me-1"></i>下章大纲</button>
      <button class="btn btn-sm btn-outline-warning gm-preset-btn" data-preset="strengthen_conflict"><i class="bi bi-lightning me-1"></i>加强冲突</button>
      <button class="btn btn-sm btn-outline-warning gm-preset-btn" data-preset="check_logic"><i class="bi bi-shield-check me-1"></i>检查逻辑</button>
      <button class="btn btn-sm btn-outline-warning gm-preset-btn" data-preset="optimize_character"><i class="bi bi-person-lines-fill me-1"></i>优化角色</button>
      <button class="btn btn-sm btn-outline-info gm-preset-btn" data-preset="extract_highlights"><i class="bi bi-star me-1"></i>提炼爽点</button>
      <button class="btn btn-sm btn-outline-info gm-preset-btn" data-preset="generate_title"><i class="bi bi-type me-1"></i>生成标题</button>
      <button class="btn btn-sm btn-outline-warning gm-preset-btn" data-preset="suggest_revision"><i class="bi bi-pencil-square me-1"></i>修改建议</button>
      <button class="btn btn-sm btn-outline-warning gm-preset-btn" data-preset="rewrite"><i class="bi bi-highlighter me-1"></i>改写选段</button>
    </div>
  </div>

  <div class="gm-ctx-bar">
    <div class="d-flex flex-wrap gap-3">
      <label><input type="checkbox" class="gm-ctx-cb" data-ctx="content" checked> 本章正文</label>
      <label><input type="checkbox" class="gm-ctx-cb" data-ctx="outline" checked> 本章大纲</label>
    </div>
  </div>

  <div class="gm-chat-list" id="gm-chat-list">
    <div class="text-center text-muted small py-4" id="gm-chat-empty">
      <i class="bi bi-chat-quote" style="font-size:32px;opacity:.4"></i>
      <p class="mt-2 mb-0">提问、要求改写，或点上方快捷指令开始</p>
    </div>
  </div>

  <div class="gm-input-bar">
    <div class="position-relative">
      <textarea id="gm-chat-input" class="form-control bg-dark text-light border-secondary" rows="3"
                placeholder="问问题，或直接说“把开头改紧凑一些”..." style="resize:none;padding-right:52px;"></textarea>
      <button id="gm-send-btn" class="btn btn-primary btn-sm position-absolute" style="right:6px;bottom:6px;">
        <i class="bi bi-send"></i>
      </button>
    </div>
    <div class="d-flex justify-content-between align-items-center mt-1">
      <span id="gm-status" class="small text-muted">就绪</span>
      <span class="small text-muted"><kbd>Ctrl</kbd>+<kbd>Enter</kbd> 发送</span>
    </div>
  </div>
</div>

<script src="assets/js/good_moling.js"></script>

<?php pageFooter(); ?>
