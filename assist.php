<?php
define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/data.php';

$novelId = (int)($_GET['id'] ?? 0);
$chapterId = (int)($_GET['chapter_id'] ?? 0);
$novel = getNovel($novelId);

if (!$novel) {
    header('Location: index.php');
    exit;
}

pageHeader('智能创作辅助系统 - ' . $novel['title'], 'home');
?>

<style>
.assist-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(320px,.85fr);gap:1rem}
.assist-panel{background:var(--bs-body-bg);border:1px solid rgba(148,163,184,.18);border-radius:8px;padding:1rem}
.assist-panel-title{display:flex;align-items:center;gap:.5rem;font-weight:700;margin-bottom:.75rem}
.assist-muted{color:var(--bs-secondary-color);font-size:.86rem}
.assist-chip{display:inline-flex;align-items:center;gap:.3rem;border:1px solid rgba(148,163,184,.24);border-radius:999px;padding:.18rem .55rem;font-size:.78rem;color:var(--bs-secondary-color);margin:.12rem .18rem .12rem 0}
.assist-block{border-top:1px solid rgba(148,163,184,.14);padding:.75rem 0}
.assist-block:first-child{border-top:0;padding-top:0}
.assist-item{background:rgba(148,163,184,.08);border-radius:8px;padding:.65rem .75rem;margin:.5rem 0}
.assist-item-label{font-weight:650;font-size:.86rem}
.assist-item-text{font-size:.9rem;white-space:pre-wrap;margin-top:.25rem}
.assist-risk{border-radius:8px;padding:.7rem .8rem;margin:.55rem 0;border-left:3px solid var(--bs-info)}
.assist-risk.danger{border-left-color:var(--bs-danger);background:rgba(220,53,69,.08)}
.assist-risk.warning{border-left-color:var(--bs-warning);background:rgba(255,193,7,.08)}
.assist-risk.success{border-left-color:var(--bs-success);background:rgba(25,135,84,.08)}
.assist-risk.info{background:rgba(13,202,240,.08)}
.assist-textarea{min-height:130px;resize:vertical}
.assist-progress-log{max-height:220px;overflow:auto;white-space:pre-wrap;font-size:.84rem}
@media (max-width: 992px){.assist-grid{grid-template-columns:1fr}}
</style>

<div id="creative-assist-root"
     data-novel-id="<?= $novelId ?>"
     data-chapter-id="<?= $chapterId ?: '' ?>">
  <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
    <div>
      <div class="assist-muted mb-1">
        <a href="novel.php?id=<?= $novelId ?>" class="text-decoration-none">
          <i class="bi bi-arrow-left me-1"></i><?= h($novel['title']) ?>
        </a>
      </div>
      <h3 class="mb-1">智能创作辅助系统</h3>
      <div class="assist-muted">聚合本章目标、AI 上下文、风险预警和临时写作指令。</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <button class="btn btn-outline-secondary btn-sm" id="assist-refresh">
        <i class="bi bi-arrow-clockwise me-1"></i>刷新
      </button>
      <button class="btn btn-outline-info btn-sm" id="assist-quality">
        <i class="bi bi-clipboard-check me-1"></i>质量检测
      </button>
      <button class="btn btn-primary btn-sm" id="assist-start-write">
        <i class="bi bi-stars me-1"></i>开始创作
      </button>
      <a class="btn btn-outline-light btn-sm disabled" id="assist-view-chapter" href="#">
        <i class="bi bi-box-arrow-up-right me-1"></i>查看章节
      </a>
    </div>
  </div>

  <div class="alert alert-secondary py-2 small" id="assist-status">
    <i class="bi bi-hourglass-split me-1"></i>正在加载创作上下文...
  </div>

  <div class="assist-grid">
    <div class="d-flex flex-column gap-3">
      <section class="assist-panel">
        <div class="assist-panel-title"><i class="bi bi-bullseye text-primary"></i>本章写作目标</div>
        <div id="assist-target" class="assist-muted">加载中...</div>
      </section>

      <section class="assist-panel">
        <div class="assist-panel-title"><i class="bi bi-layers text-info"></i>AI 上下文预览</div>
        <div id="assist-context" class="assist-muted">加载中...</div>
      </section>

      <section class="assist-panel" id="assist-result-panel" style="display:none">
        <div class="assist-panel-title"><i class="bi bi-award text-success"></i>写作结果报告</div>
        <div id="assist-quality-result"></div>
      </section>
    </div>

    <div class="d-flex flex-column gap-3">
      <section class="assist-panel">
        <div class="assist-panel-title"><i class="bi bi-exclamation-triangle text-warning"></i>风险预警</div>
        <div id="assist-risks" class="assist-muted">加载中...</div>
      </section>

      <section class="assist-panel">
        <div class="assist-panel-title"><i class="bi bi-pencil-square text-success"></i>临时写作指令</div>
        <textarea class="form-control bg-dark text-light border-secondary assist-textarea"
                  id="assist-directive"
                  placeholder="只影响本章。例如：本章节奏加快，增加主角压迫感，结尾必须留下强悬念。"></textarea>
        <div class="d-flex justify-content-between align-items-center mt-2">
          <span class="assist-muted" id="assist-directive-hint">保存后会作为本章一次性 Agent 指令。</span>
          <button class="btn btn-outline-success btn-sm" id="assist-save-directive">
            <i class="bi bi-check-lg me-1"></i>保存指令
          </button>
        </div>
      </section>

      <section class="assist-panel">
        <div class="assist-panel-title"><i class="bi bi-broadcast text-danger"></i>创作进度</div>
        <div class="progress mb-2" style="height:6px">
          <div class="progress-bar" id="assist-write-bar" style="width:0%"></div>
        </div>
        <div id="assist-write-log" class="assist-progress-log assist-muted">尚未开始。</div>
      </section>
    </div>
  </div>
</div>

<script src="assets/js/assist.js"></script>
<?php pageFooter(); ?>
