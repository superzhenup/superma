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

$prev = DB::fetch(
    'SELECT id, chapter_number, title FROM chapters WHERE novel_id=? AND chapter_number=? LIMIT 1',
    [$novel['id'], $chapter['chapter_number'] - 1]
);
$next = DB::fetch(
    'SELECT id, chapter_number, title FROM chapters WHERE novel_id=? AND chapter_number=? LIMIT 1',
    [$novel['id'], $chapter['chapter_number'] + 1]
);

pageHeader("第{$chapter['chapter_number']}章 - {$novel['title']}", 'home');
?>

<!-- Breadcrumb -->
<nav class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="index.php" class="text-muted">书库</a></li>
    <li class="breadcrumb-item"><a href="novel.php?id=<?= $novel['id'] ?>" class="text-muted"><?= h($novel['title']) ?></a></li>
    <li class="breadcrumb-item active text-light">第<?= $chapter['chapter_number'] ?>章</li>
  </ol>
</nav>

<div class="row g-4">
  <!-- Main content -->
  <div class="col-12 col-xl-8">
    <div class="page-card">
      <div class="page-card-header d-flex justify-content-between align-items-center">
        <div>
          <span class="text-muted small">第<?= $chapter['chapter_number'] ?>章</span>
          <h5 class="mb-0 mt-1 text-light" id="chapter-title-display"><?= h($chapter['title']) ?></h5>
        </div>
        <div class="d-flex gap-2">
          <?= statusBadge($chapter['status']) ?>
          <button class="btn btn-sm btn-outline-warning" onclick="toggleEdit()">
            <i class="bi bi-pencil"></i> 编辑
          </button>
          <button class="btn btn-sm btn-outline-info" onclick="showVersionHistory()">
            <i class="bi bi-clock-history"></i> 版本
          </button>
          <?php if ($chapter['status'] !== 'completed' && $chapter['status'] === 'outlined'): ?>
          <button class="btn btn-sm btn-primary" id="btn-rewrite" data-chapter="<?= $id ?>">
            <i class="bi bi-arrow-repeat me-1"></i>重新写作
          </button>
          <?php endif; ?>
        </div>
      </div>

      <!-- Read mode -->
      <div id="read-mode" class="p-4"<?php if (isset($_GET['edit']) && $_GET['edit'] === '1') echo ' style="display:none"'; ?>>
        <?php if ($chapter['content']): ?>
        <div class="chapter-content">
          <?= nl2br(h($chapter['content'])) ?>
        </div>
        <div class="d-flex justify-content-between text-muted small mt-4 pt-3 border-top border-secondary">
          <span><i class="bi bi-file-text me-1"></i><?= number_format($chapter['words']) ?> 字</span>
          <span>更新于 <?= $chapter['updated_at'] ?></span>
        </div>
        <?php else: ?>
        <div class="empty-state py-5">
          <div class="empty-icon"><i class="bi bi-file-earmark-text"></i></div>
          <h6>本章暂无内容</h6>
          <?php if ($chapter['status'] === 'outlined'): ?>
          <p class="text-muted small">大纲已生成，点击「开始写作」让 AI 创作，或点击「手动编辑」自己撰写</p>
          <div class="d-flex gap-2 justify-content-center">
            <button class="btn btn-primary btn-sm" id="btn-rewrite" data-chapter="<?= $id ?>">
              <i class="bi bi-magic me-1"></i>开始写作
            </button>
            <button class="btn btn-outline-warning btn-sm" onclick="toggleEdit()">
              <i class="bi bi-pencil me-1"></i>手动编辑
            </button>
          </div>
          <?php else: ?>
          <p class="text-muted small">尚未生成大纲，可以直接手动撰写本章内容</p>
          <button class="btn btn-warning btn-sm" onclick="toggleEdit()">
            <i class="bi bi-pencil me-1"></i>手动编辑
          </button>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Edit mode -->
      <div id="edit-mode" class="p-4"<?php if (!isset($_GET['edit']) || $_GET['edit'] !== '1') echo ' style="display:none"'; ?>>
        <div class="mb-3">
          <label class="form-label">章节标题</label>
          <input type="text" id="edit-title" class="form-control" value="<?= h($chapter['title']) ?>">
        </div>
        <div class="mb-1">
          <label class="form-label">章节内容</label>
          <textarea id="edit-content" class="form-control chapter-editor" oninput="updateWordCount()"><?= h($chapter['content']) ?></textarea>
          <!-- 字数统计条 -->
          <div class="d-flex justify-content-between align-items-center mt-1">
            <span id="word-count-bar" class="small" style="color:#28a745;">
              当前 <span id="wc-current"><?= number_format((int)$chapter['words']) ?></span> 字 / 目标 <?= number_format((int)$novel['chapter_words']) ?> 字
            </span>
            <button class="btn btn-outline-warning btn-sm" id="btn-compress" onclick="compressChapter(<?= $id ?>)" title="AI压缩到目标字数" style="font-size:.75rem;">
              <i class="bi bi-arrows-angle-contract me-1"></i>AI压缩
            </button>
          </div>
        </div>
        <div class="d-flex gap-2 align-items-center">
          <button class="btn btn-outline-info btn-sm" id="btn-polish" onclick="polishChapter(<?= $id ?>)">
            <i class="bi bi-magic me-1"></i>一键润色
          </button>
          <button class="btn btn-success btn-sm" onclick="saveChapter(<?= $id ?>)">
            <i class="bi bi-check-circle me-1"></i>保存
          </button>
          <div class="ms-auto"></div>
          <button class="btn btn-secondary btn-sm" onclick="toggleEdit()">取消</button>
        </div>
      </div>

      <!-- Navigation -->
      <div class="d-flex justify-content-between align-items-center p-3 border-top border-secondary">
        <?php if ($prev): ?>
        <a href="chapter.php?id=<?= $prev['id'] ?>" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-chevron-left me-1"></i>第<?= $prev['chapter_number'] ?>章
        </a>
        <?php else: ?>
        <span></span>
        <?php endif; ?>
        <a href="novel.php?id=<?= $novel['id'] ?>" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-list-ul me-1"></i>目录
        </a>
        <?php if ($next): ?>
        <a href="chapter.php?id=<?= $next['id'] ?>" class="btn btn-outline-secondary btn-sm">
          第<?= $next['chapter_number'] ?>章<i class="bi bi-chevron-right ms-1"></i>
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
          // 预处理 key_points：数据库里是 JSON，前端用"每行一个"的纯文本编辑
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
          <button type="button" class="btn btn-outline-warning btn-sm" id="btn-regenerate">
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
        <button class="btn btn-sm btn-outline-primary" onclick="runQualityCheck()">
          <i class="bi bi-play-circle me-1"></i>一键检测
        </button>
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

        <!-- 五关详情 -->
        <?php if (!empty($gResults) && is_array($gResults)): ?>
        <div id="gate-details">
          <?php foreach ($gResults as $gate): $gs = $gate['score'] ?? 0; $pass = !empty($gate['status']); ?>
          <div class="d-flex align-items-center mb-2 p-2 rounded" style="background:<?php echo $pass ? 'rgba(40,167,69,.08)' : 'rgba(220,53,69,.08)' ?>;">
            <span class="me-2"><?php echo $pass ? '✅' : '⚠️'; ?></span>
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
          点击「一键检测」运行五关质量流水线<br>
          <span class="opacity-50">结构 · 角色 · 描写 · 爽点 · 连贯性</span>
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
        <span class="text-muted small">正在执行五关检测...</span>
      </div>
    </div>

    <!-- Novel Info -->
    <div class="page-card">
      <div class="page-card-header"><i class="bi bi-book me-2"></i><?= h($novel['title']) ?></div>
      <div class="p-3">
        <div class="small text-muted mb-1">主角</div>
        <div class="small text-light mb-2"><?= h($novel['protagonist_name'] ?: '未设定') ?></div>
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
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">关闭</button>
      </div>
    </div>
  </div>
</div>

<script>
// HTML转义函数（防止XSS）
function escapeHtml(text) {
    if (!text) return '';
    return String(text).replace(/[&<>"']/g, m => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[m]));
}

// ================================================================
// 最优先：直接在 window 上定义 toggleEdit/saveChapter
// 确保即使后续代码出错，inline onclick 也能找到这两个函数
// ================================================================
window.toggleEdit = function toggleEdit() {
    var r = document.getElementById('read-mode');
    var e = document.getElementById('edit-mode');
    if (!r || !e) return;
    if (r.style.display === 'none') {
        r.style.display = '';
        e.style.display = 'none';
    } else {
        r.style.display = 'none';
        e.style.display = '';
    }
};

window.saveChapter = async function saveChapter(chapterId) {
    var titleEl   = document.getElementById('edit-title');
    var contentEl = document.getElementById('edit-content');
    if (!titleEl || !contentEl) return;
    try {
        var res = await fetch('api/actions.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                action: 'save_chapter',
                chapter_id: chapterId,
                title: titleEl.value,
                content: contentEl.value
            })
        });
        var data = await res.json();
        if (data.ok) {
            location.reload();
        } else {
            alert('保存失败：' + (data.msg || '未知错误'));
        }
    } catch (err) {
        alert('保存失败：' + err.message);
    }
};

// URL 参数 ?edit=1 → 自动进入编辑模式
(function autoEnterEditMode() {
    try {
        var params = new URLSearchParams(window.location.search);
        if (params.get('edit') !== '1') return;
        var run = function() {
            var r = document.getElementById('read-mode');
            var e = document.getElementById('edit-mode');
            if (r && e) {
                r.style.display = 'none';
                e.style.display = '';
                e.scrollIntoView({ behavior: 'smooth', block: 'start' });
                var c = document.getElementById('edit-content');
                if (c) c.focus();
            }
        };
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', run);
        } else {
            run();
        }
    } catch (err) {}
})();

// ================================================================
// 以下代码如果出错，不影响上面已经暴露的编辑功能
// ================================================================
const CHAPTER_ID = <?= $id ?>;
const NOVEL_ID   = <?= $novel['id'] ?>;
let selectedVersionId = null;

// ========== 版本历史功能 ==========
async function showVersionHistory() {
    const res = await fetch(`api/chapter_versions.php?chapter_id=${CHAPTER_ID}&action=list`);
    const data = await res.json();
    if (!data.ok) { alert(data.msg); return; }

    const listEl = document.getElementById('versionList');
    listEl.innerHTML = '';

    // 当前版本
    listEl.innerHTML += `
        <div class="list-group-item list-group-item-action bg-primary text-white border-secondary">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <strong>当前版本</strong>
                    <span class="badge bg-light text-dark ms-2">${data.data.chapter.current_words}字</span>
                </div>
                <small class="text-white-50">最新</small>
            </div>
            <small class="text-white-50">${data.data.chapter.current_content}</small>
        </div>
    `;

    // 历史版本
    if (data.data.versions.length === 0) {
        listEl.innerHTML += '<div class="text-center text-muted py-3">暂无历史版本</div>';
    } else {
        data.data.versions.forEach(v => {
            listEl.innerHTML += `
                <div class="list-group-item list-group-item-action bg-dark text-light border-secondary" 
                     style="cursor:pointer" onclick="previewVersion(${v.id})">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong>v${v.version}</strong>
                            <span class="badge bg-secondary ms-2">${v.words}字</span>
                        </div>
                        <small class="text-muted">${v.created_at}</small>
                    </div>
                </div>
            `;
        });
    }

    const modal = new bootstrap.Modal(document.getElementById('versionModal'));
    modal.show();
}

async function previewVersion(versionId) {
    const res = await fetch(`api/chapter_versions.php?chapter_id=${CHAPTER_ID}&action=preview&version_id=${versionId}`);
    const data = await res.json();
    if (!data.ok) { alert(data.msg); return; }

    document.getElementById('versionPreviewContent').textContent = data.data.content;
    selectedVersionId = versionId;

    const modal = new bootstrap.Modal(document.getElementById('versionPreviewModal'));
    modal.show();
}

// 版本历史函数也暴露到 window，供 inline onclick 使用
window.showVersionHistory = showVersionHistory;
window.previewVersion     = previewVersion;

// btnRollback 绑定（容错）
(function bindRollback() {
    const btn = document.getElementById('btnRollback');
    if (!btn) return;
    btn.addEventListener('click', async () => {
        if (!selectedVersionId) return;
        if (!confirm('确定要回滚到此版本吗？当前内容将被备份为新版本。')) return;

        try {
            const res = await fetch(`api/chapter_versions.php?chapter_id=${CHAPTER_ID}&action=rollback&version_id=${selectedVersionId}`);
            const data = await res.json();
            if (data.ok) {
                alert('回滚成功');
                location.reload();
            } else {
                alert('回滚失败：' + data.msg);
            }
        } catch (err) {
            alert('回滚失败：' + err.message);
        }
    });
})();

// Rewrite button（开始写作 / 重新写作）
(function bindRewrite() {
    document.querySelectorAll('#btn-rewrite').forEach(btn => {
        btn.addEventListener('click', async () => {
            const modalEl = document.getElementById('rewriteModal');
            if (!modalEl) { alert('缺少写作对话框'); return; }
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
            const contentEl = document.getElementById('rewriteContent');
            const spinnerEl = document.getElementById('rewriteSpinner');
            const statsEl   = document.getElementById('rewriteStats');
            if (contentEl) contentEl.textContent = '';
            if (spinnerEl) spinnerEl.style.display = '';
            if (statsEl)   statsEl.textContent = '';

            try {
                const response = await fetch('api/write_chapter.php', {
                    method: 'POST',
                    headers: {'Content-Type':'application/json'},
                    body: JSON.stringify({ novel_id: NOVEL_ID, chapter_id: CHAPTER_ID })
                });
                const reader   = response.body.getReader();
                const decoder  = new TextDecoder();
                let   fullText = '';
                let   sseBuf   = '';
                if (spinnerEl) spinnerEl.style.display = 'none';

                while (true) {
                    const { value, done } = await reader.read();
                    if (done) break;
                    sseBuf += decoder.decode(value, { stream: true });
                    const events = sseBuf.split('\n\n');
                    sseBuf = events.pop();
                    for (const eventBlock of events) {
                        let dataLine = '';
                        for (const ln of eventBlock.split('\n')) {
                            if (ln.startsWith('data: ')) dataLine = ln;
                        }
                        if (!dataLine) continue;
                        const payload = dataLine.slice(6).trim();
                        if (payload === '[DONE]') break;
                        try {
                            const d = JSON.parse(payload);
                            if (d.chunk && contentEl) {
                                fullText += d.chunk;
                                contentEl.textContent = fullText;
                                contentEl.scrollTop = contentEl.scrollHeight;
                            }
                            if (d.stats && statsEl) {
                                statsEl.textContent = d.stats;
                            }
                        } catch(e) {}
                    }
                }
            } catch(err) {
                if (contentEl) contentEl.textContent = '写作失败：' + err.message;
            }
        });
    });
})();

// ================================================================
// 保存大纲 / 关键情节点 / 结尾钩子
// ================================================================
(function bindSaveOutline() {
    const btn = document.getElementById('btn-save-outline');
    if (!btn) return;
    btn.addEventListener('click', async () => {
        const outlineEl = document.getElementById('outline-outline');
        const kpEl      = document.getElementById('outline-keypoints');
        const hookEl    = document.getElementById('outline-hook');
        if (!outlineEl || !kpEl || !hookEl) return;

        const origHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>保存中...';

        try {
            // 关键情节点：按行拆分，去空行去空白
            const keyPoints = kpEl.value.split('\n')
                .map(s => s.trim())
                .filter(s => s.length > 0);

            const res = await fetch('api/actions.php', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({
                    action:      'save_chapter_outline',
                    chapter_id:  CHAPTER_ID,
                    outline:     outlineEl.value,
                    key_points:  keyPoints,
                    hook:        hookEl.value
                })
            });
            const data = await res.json();
            if (data.ok) {
                btn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>已保存';
                btn.classList.remove('btn-primary');
                btn.classList.add('btn-success');
                setTimeout(() => {
                    btn.innerHTML = origHtml;
                    btn.classList.remove('btn-success');
                    btn.classList.add('btn-primary');
                    btn.disabled = false;
                }, 1500);
            } else {
                alert('保存失败：' + (data.msg || '未知错误'));
                btn.innerHTML = origHtml;
                btn.disabled = false;
            }
        } catch (err) {
            alert('保存失败：' + err.message);
            btn.innerHTML = origHtml;
            btn.disabled = false;
        }
    });
})();

// ========== Phase 4: 质量检测功能 ==========
async function runQualityCheck() {
    const bodyEl = document.getElementById('quality-body');
    const loadEl = document.getElementById('quality-loading');
    if (!bodyEl || !loadEl) return;

    // 显示 loading
    bodyEl.style.display = 'none';
    loadEl.style.display = '';

    try {
        const res = await fetch('api/validate_consistency.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ novel_id: NOVEL_ID, chapter_id: CHAPTER_ID })
        });
        const data = await res.json();

        if (data.error) {
            bodyEl.innerHTML = '<div class="text-center text-danger py-3 small">' + escapeHtml(data.error) + '</div>';
            bodyEl.style.display = '';
            loadEl.style.display = 'none';
            return;
        }

        // 渲染结果
        const score = data.total_score ?? 0;
        const passColor = score >= 80 ? '#28a745' : (score >= 60 ? '#ffc107' : '#dc3545');
        const bgColor  = score >= 80 ? 'rgba(40,167,69,.2)' : (score >= 60 ? 'rgba(255,193,7,.2)' : 'rgba(220,53,69,.2)');

        let html = '';

        // 圆形评分
        html += '<div class="text-center mb-3">';
        html += '<div class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width:80px;height:80px;background:' + bgColor + '">';
        html += '<span style="font-size:1.8rem;font-weight:700;color:' + passColor + '">' + Math.round(score) + '</span>';
        html += '</div>';
        html += '<div class="text-muted small mt-1">综合评分 /100</div>';
        html += '</div>';

        // 五关详情
        if (data.gates && data.gates.length) {
            html += '<div id="gate-details">';
            data.gates.forEach(function(gate) {
                var gs = gate.score || 0;
                var ok  = !!gate.status;
                var bg  = ok ? 'rgba(40,167,69,.08)' : 'rgba(220,53,69,.08)';
                var clr = gs >= 70 ? '#28a745' : '#dc3545';
                html += '<div class="d-flex align-items-center mb-2 p-2 rounded" style="background:' + bg + '">';
                html += '<span class="me-2">' + (ok ? '&#x2705;' : '&#x26A0;&#xFE0F;') + '</span>';
                html += '<small class="flex-grow-1 text-truncate">' + escapeHtml(gate.name) + '</small>';
                html += '<strong class="ms-2 small" style="color:' + clr + '">' + gs + '</strong>';
                html += '<span class="text-muted small ms-1">分</span></div>';

                if (!ok && gate.issues && gate.issues.length) {
                    html += '<div class="small text-danger mb-2 ps-4" style="font-size:.7rem;line-height:1.4;">';
                    gate.issues.slice(0, 3).forEach(function(issue) {
                        html += '<div>' + escapeHtml(issue) + '</div>';
                    });
                    html += '</div>';
                }
            });
            html += '</div>';
        }

        // 汇总
        html += '<div class="text-muted small mt-2">';
        if (data.passes) {
            html += '<span class="text-success">✅ 全部通过！' + escapeHtml(data.summary || '') + '</span>';
        } else {
            html += '<span>' + escapeHtml(data.summary || '') + '</span>';
        }
        html += '</div>';

        bodyEl.innerHTML = html;
        bodyEl.style.display = '';
        loadEl.style.display = 'none';

    } catch(err) {
        bodyEl.innerHTML = '<div class="text-center text-danger py-3 small">检测失败：' + escapeHtml(err.message) + '</div>';
        bodyEl.style.display = '';
        loadEl.style.display = 'none';
    }
}

// 暴露到 window
window.runQualityCheck = runQualityCheck;

// ================================================================
// 写作/润色 Modal 关闭后自动刷新（确保编辑器内容同步）
// ================================================================
(function bindModalRefresh() {
    var modalEl = document.getElementById('rewriteModal');
    if (!modalEl) return;
    modalEl.addEventListener('hidden.bs.modal', function() {
        // 如果内容已更新（润色或写作完成），刷新页面
        if (window._contentUpdated) {
            location.reload();
        }
    });
})();
window._contentUpdated = false;

// ================================================================
// 一键润色功能（流式 SSE + 质量检测反馈驱动）
// ================================================================
window.polishChapter = async function polishChapter(chapterId) {
    var contentEl = document.getElementById('edit-content');
    if (!contentEl || !contentEl.value.trim()) {
        alert('章节内容为空，无法润色');
        return;
    }
    if (!confirm('确认要 AI 润色当前章节吗？\n\n系统将先自动运行质量检测，根据⚠️问题定向润色。\n润色结果将替换现有内容（可通过版本历史回滚）。')) return;

    // 禁用润色按钮
    var btn = document.getElementById('btn-polish');
    var origBtnHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>检测中...';
    }

    // ---- 第1步：自动运行质量检测 ----
    var qualityFeedback = '';
    var qualityScore = 0;
    try {
        var checkRes = await fetch('api/validate_consistency.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ novel_id: NOVEL_ID, chapter_id: chapterId })
        });
        var checkData = await checkRes.json();

        if (checkData.gates && checkData.gates.length > 0) {
            qualityScore = checkData.total_score || 0;
            var feedbackLines = [];

            for (var g = 0; g < checkData.gates.length; g++) {
                var gate = checkData.gates[g];
                var gateName = gate.name || '未知';
                var passed = !!gate.status;
                var issues = gate.issues || [];

                if (!passed) {
                    // ⚠️ 未通过的关卡：所有 issues 都是改进项
                    for (var j = 0; j < issues.length; j++) {
                        feedbackLines.push('- [' + gateName + '] ' + issues[j]);
                    }
                } else {
                    // 通过但含⚠️的 issues
                    for (var j = 0; j < issues.length; j++) {
                        if (/⚠️|❌|偏低|过低|超长|偏长|未达标|过于平淡/.test(issues[j])) {
                            feedbackLines.push('- [' + gateName + '] ' + issues[j]);
                        }
                    }
                }
            }

            qualityFeedback = feedbackLines.join('\n');

            // 同步更新质量检测仪表盘的 UI
            updateQualityDashboard(checkData);

            if (btn) btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>润色中...';
        }
    } catch(err) {
        // 质量检测失败不影响润色，继续走通用润色
        console.warn('质量检测失败，使用通用润色：', err);
        if (btn) btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>润色中...';
    }

    // ---- 第2步：打开写作 Modal 显示流式润色结果 ----
    var modalEl = document.getElementById('rewriteModal');
    if (!modalEl) { alert('缺少写作对话框'); return; }
    var modal = new bootstrap.Modal(modalEl);
    modal.show();

    // 修改 modal 标题为润色模式
    var modalTitle = modalEl.querySelector('.modal-title');
    var origTitle = modalTitle ? modalTitle.textContent : '';
    if (modalTitle) {
        modalTitle.textContent = qualityFeedback
            ? 'AI 润色中（基于质量检测反馈）...'
            : 'AI 润色中...';
    }

    var streamContent = document.getElementById('rewriteContent');
    var spinnerEl     = document.getElementById('rewriteSpinner');
    var statsEl       = document.getElementById('rewriteStats');
    if (streamContent) streamContent.textContent = '';
    if (spinnerEl)     spinnerEl.style.display = '';
    if (statsEl)       statsEl.textContent = qualityFeedback
        ? '质量评分 ' + Math.round(qualityScore) + '/100，发现 ' + qualityFeedback.split('\n').length + ' 个改进项'
        : '';

    var _polishDone = false;

    try {
        var response = await fetch('api/polish_chapter.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                chapter_id: chapterId,
                quality_feedback: qualityFeedback
            })
        });
        var reader  = response.body.getReader();
        var decoder = new TextDecoder();
        var fullText = '';
        var sseBuf2 = '';
        if (spinnerEl) spinnerEl.style.display = 'none';

        while (true) {
            var result = await reader.read();
            if (result.done) break;
            sseBuf2 += decoder.decode(result.value, { stream: true });
            var events2 = sseBuf2.split('\n\n');
            sseBuf2 = events2.pop();
            for (var ei2 = 0; ei2 < events2.length; ei2++) {
                var dataLine2 = '';
                var elines2 = events2[ei2].split('\n');
                for (var li2 = 0; li2 < elines2.length; li2++) {
                    if (elines2[li2].startsWith('data: ')) dataLine2 = elines2[li2];
                }
                if (!dataLine2) continue;
                var payload2 = dataLine2.slice(6).trim();
                if (payload2 === '[DONE]') { _polishDone = true; break; }
                try {
                    var d = JSON.parse(payload2);
                    if (d.chunk && streamContent) {
                        fullText += d.chunk;
                        streamContent.textContent = fullText;
                        streamContent.scrollTop = streamContent.scrollHeight;
                    }
                    if (d.stats && statsEl) {
                        statsEl.textContent = d.stats;
                    }
                    if (d.error) {
                        alert('润色失败：' + d.error);
                    }
                } catch(e) {}
            }
        }

        // 润色完成后提示用户
        if (_polishDone && statsEl) {
            statsEl.textContent += '（关闭对话框后将刷新页面）';
            window._contentUpdated = true;
        }
    } catch(err) {
        if (streamContent) streamContent.textContent = '润色失败：' + err.message;
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = origBtnHtml; }
        // 恢复 modal 标题
        if (modalTitle) modalTitle.textContent = origTitle;
    }
};

/**
 * 同步更新质量检测仪表盘 UI（润色前自动检测后调用）
 */
function updateQualityDashboard(data) {
    var bodyEl = document.getElementById('quality-body');
    var loadEl = document.getElementById('quality-loading');
    if (!bodyEl) return;

    var score = data.total_score ?? 0;
    var passColor = score >= 80 ? '#28a745' : (score >= 60 ? '#ffc107' : '#dc3545');
    var bgColor  = score >= 80 ? 'rgba(40,167,69,.2)' : (score >= 60 ? 'rgba(255,193,7,.2)' : 'rgba(220,53,69,.2)');

    var html = '';

    // 圆形评分
    html += '<div class="text-center mb-3">';
    html += '<div class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width:80px;height:80px;background:' + bgColor + '">';
    html += '<span style="font-size:1.8rem;font-weight:700;color:' + passColor + '">' + Math.round(score) + '</span>';
    html += '</div>';
    html += '<div class="text-muted small mt-1">综合评分 /100</div>';
    html += '</div>';

    // 五关详情
    if (data.gates && data.gates.length) {
        html += '<div id="gate-details">';
        data.gates.forEach(function(gate) {
            var gs = gate.score || 0;
            var ok  = !!gate.status;
            var bg  = ok ? 'rgba(40,167,69,.08)' : 'rgba(220,53,69,.08)';
            var clr = gs >= 70 ? '#28a745' : '#dc3545';
            html += '<div class="d-flex align-items-center mb-2 p-2 rounded" style="background:' + bg + '">';
            html += '<span class="me-2">' + (ok ? '&#x2705;' : '&#x26A0;&#xFE0F;') + '</span>';
            html += '<small class="flex-grow-1 text-truncate">' + escapeHtml(gate.name) + '</small>';
            html += '<strong class="ms-2 small" style="color:' + clr + '">' + gs + '</strong>';
            html += '<span class="text-muted small ms-1">分</span></div>';

            if (!ok && gate.issues && gate.issues.length) {
                html += '<div class="small text-danger mb-2 ps-4" style="font-size:.7rem;line-height:1.4;">';
                gate.issues.slice(0, 3).forEach(function(issue) {
                    html += '<div>' + escapeHtml(issue) + '</div>';
                });
                html += '</div>';
            }
        });
        html += '</div>';
    }

    // 汇总
    html += '<div class="text-muted small mt-2">';
    if (data.passes) {
        html += '<span class="text-success">&#x2705; 全部通过！' + escapeHtml(data.summary || '') + '</span>';
    } else {
        html += '<span>' + escapeHtml(data.summary || '') + '</span>';
    }
    html += '</div>';

    bodyEl.innerHTML = html;
    bodyEl.style.display = '';
    if (loadEl) loadEl.style.display = 'none';
}
window.updateQualityDashboard = updateQualityDashboard;

// ================================================================
// 字数统计实时更新（编辑模式下）
// ================================================================
window.updateWordCount = function updateWordCount() {
    var contentEl = document.getElementById('edit-content');
    var barEl     = document.getElementById('word-count-bar');
    var currentEl = document.getElementById('wc-current');
    if (!contentEl || !barEl || !currentEl) return;

    // 计算字数（中文按字符计，英文按单词计，简化处理）
    var text = contentEl.value || '';
    var wc = text.replace(/\s+/g, '').length;  // 去空格后统计
    currentEl.textContent = wc.toLocaleString();

    // 目标字数
    var target = <?= (int)$novel['chapter_words'] ?>;
    var overLimit = target > 0 && wc > target + 500;

    if (overLimit) {
        barEl.style.color = '#dc3545';  // 红色
        barEl.style.fontWeight = '700';
        barEl.title = '超出目标' + target + '字+500';
    } else {
        barEl.style.color = '#28a745';  // 绿色
        barEl.style.fontWeight = '400';
        barEl.title = '字数正常';
    }
};

// 页面加载时初始化字数显示
(function initWordCount() {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', window.updateWordCount);
    } else {
        window.updateWordCount();
    }
})();

// ================================================================
// AI压缩功能（流式 SSE，实时更新 textarea）
// ================================================================
window.compressChapter = async function compressChapter(chapterId) {
    var contentEl = document.getElementById('edit-content');
    if (!contentEl || !contentEl.value.trim()) {
        alert('章节内容为空，无法压缩');
        return;
    }

    var targetWords = <?= (int)$novel['chapter_words'] ?>;
    if (!confirm('确认要 AI 压缩当前章节到目标字数 ' + targetWords + ' 字吗？\n\n压缩结果将替换现有内容（可通过版本历史回滚）。')) return;

    var btn = document.getElementById('btn-compress');
    var origBtnHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>压缩中...';
    }

    // 打开 Modal 显示流式预览
    var modalEl = document.getElementById('rewriteModal');
    if (!modalEl) { alert('缺少写作对话框'); return; }
    var modal = new bootstrap.Modal(modalEl);
    modal.show();

    var modalTitle = modalEl.querySelector('.modal-title');
    var origTitle = modalTitle ? modalTitle.textContent : '';
    if (modalTitle) modalTitle.textContent = 'AI 压缩中...';

    var streamContent = document.getElementById('rewriteContent');
    var spinnerEl     = document.getElementById('rewriteSpinner');
    var statsEl       = document.getElementById('rewriteStats');
    if (streamContent) streamContent.textContent = '';
    if (spinnerEl)     spinnerEl.style.display = '';
    if (statsEl)       statsEl.textContent = '目标字数：' + targetWords + ' 字，实时更新编辑框...';

    var _compressDone = false;
    var _compressedContent = '';

    try {
        var response = await fetch('api/compress_chapter.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                chapter_id: chapterId,
                target_words: targetWords
            })
        });
        var reader  = response.body.getReader();
        var decoder = new TextDecoder();
        var fullText = '';
        var sseBuffer = '';          // SSE 分片缓冲区
        if (spinnerEl) spinnerEl.style.display = 'none';

        while (true) {
            var result = await reader.read();
            if (result.done) break;
            sseBuffer += decoder.decode(result.value, { stream: true });

            // 按 SSE 标准双换行分割事件块
            var events = sseBuffer.split('\n\n');
            sseBuffer = events.pop(); // 最后一段可能不完整，留到下次

            for (var ei = 0; ei < events.length; ei++) {
                var eventBlock = events[ei];
                // 取 data: 行（一个事件块可能含多行，取最后一条 data:）
                var dataLine = '';
                var eventLines = eventBlock.split('\n');
                for (var li = 0; li < eventLines.length; li++) {
                    if (eventLines[li].startsWith('data: ')) dataLine = eventLines[li];
                }
                if (!dataLine) continue;
                var payload = dataLine.slice(6).trim();
                if (payload === '[DONE]') { _compressDone = true; break; }
                try {
                    var d = JSON.parse(payload);
                    if (d.debug && statsEl) {
                        statsEl.textContent = d.debug;
                    }
                    if (d.chunk) {
                        fullText += d.chunk;
                    }
                    if (d.content) {
                        // 非流式模式：一次性收到完整内容
                        fullText = d.content;
                    }
                    // 实时同步到预览区
                    if (fullText && streamContent) {
                        streamContent.textContent = fullText;
                        streamContent.scrollTop = streamContent.scrollHeight;
                    }
                    if (d.stats && statsEl) {
                        statsEl.textContent = d.stats;
                    }
                    if (d.error) {
                        if (statsEl) statsEl.textContent = '❌ ' + d.error;
                        _compressDone = false;
                    }
                } catch(e) {}
            }
        }

        if (_compressDone && fullText && fullText.trim().length > 0) {
            if (contentEl) contentEl.value = fullText;
            if (typeof updateWordCount === 'function') updateWordCount();
            if (statsEl) statsEl.textContent = '✓ 压缩完成！已更新编辑框，请点击「保存」持久化';
            if (modalTitle) modalTitle.textContent = '压缩完成';
            window._contentUpdated = true;
        } else if (!fullText || fullText.trim().length === 0) {
            if (statsEl) statsEl.textContent = '❌ 压缩失败：LLM 未返回有效内容，请检查 API 配置';
        }
    } catch(err) {
        if (streamContent) streamContent.textContent = '压缩失败：' + err.message;
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = origBtnHtml; }
    }
};

// ================================================================
// 重新生成功能（结合大纲概要、关键情节点、结尾钩子）
// ================================================================
(function bindRegenerate() {
    var btn = document.getElementById('btn-regenerate');
    if (!btn) return;

    btn.addEventListener('click', async function() {
        var outlineEl = document.getElementById('outline-outline');
        var kpEl      = document.getElementById('outline-keypoints');
        var hookEl    = document.getElementById('outline-hook');
        if (!outlineEl || !kpEl || !hookEl) return;

        var outlineVal = outlineEl.value.trim();
        var kpVal      = kpEl.value.trim();
        if (!outlineVal && !kpVal) {
            alert('请先填写大纲概要或关键情节点');
            return;
        }

        if (!confirm('确认要根据当前大纲重新生成章节内容吗？现有内容将被替换（可通过版本历史回滚）。')) return;

        var origHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>准备中...';

        try {
            // 1. 先保存大纲并标记需要重新生成
            var keyPoints = kpEl.value.split('\n')
                .map(function(s) { return s.trim(); })
                .filter(function(s) { return s.length > 0; });

            var res = await fetch('api/actions.php', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({
                    action: 'regenerate_chapter',
                    chapter_id: CHAPTER_ID,
                    outline: outlineVal,
                    key_points: keyPoints,
                    hook: hookEl.value.trim()
                })
            });
            var data = await res.json();

            if (!data.ok) {
                alert('操作失败：' + (data.msg || '未知错误'));
                btn.innerHTML = origHtml;
                btn.disabled = false;
                return;
            }

            // 2. 重置章节状态为 outlined（让 write_chapter.php 能重新写入）
            await fetch('api/actions.php', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({
                    action: 'reset_chapter',
                    chapter_id: CHAPTER_ID
                })
            });

            // 3. 调用流式写作 API
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>生成中...';

            var modalEl = document.getElementById('rewriteModal');
            if (!modalEl) { alert('缺少写作对话框'); return; }
            var modal = new bootstrap.Modal(modalEl);
            modal.show();
            var contentEl = document.getElementById('rewriteContent');
            var spinnerEl = document.getElementById('rewriteSpinner');
            var statsEl   = document.getElementById('rewriteStats');
            if (contentEl) contentEl.textContent = '';
            if (spinnerEl) spinnerEl.style.display = '';
            if (statsEl)   statsEl.textContent = '';

            var response = await fetch('api/write_chapter.php', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ novel_id: NOVEL_ID, chapter_id: CHAPTER_ID })
            });
            var reader  = response.body.getReader();
            var decoder = new TextDecoder();
            var fullText = '';
            var sseBuf3 = '';
            if (spinnerEl) spinnerEl.style.display = 'none';

            while (true) {
                var result = await reader.read();
                if (result.done) break;
                sseBuf3 += decoder.decode(result.value, { stream: true });
                var events3 = sseBuf3.split('\n\n');
                sseBuf3 = events3.pop();
                for (var ei3 = 0; ei3 < events3.length; ei3++) {
                    var dataLine3 = '';
                    var elines3 = events3[ei3].split('\n');
                    for (var li3 = 0; li3 < elines3.length; li3++) {
                        if (elines3[li3].startsWith('data: ')) dataLine3 = elines3[li3];
                    }
                    if (!dataLine3) continue;
                    var payload3 = dataLine3.slice(6).trim();
                    if (payload3 === '[DONE]') { window._contentUpdated = true; break; }
                    try {
                        var d = JSON.parse(payload3);
                        if (d.chunk && contentEl) {
                            fullText += d.chunk;
                            contentEl.textContent = fullText;
                            contentEl.scrollTop = contentEl.scrollHeight;
                        }
                        if (d.stats && statsEl) {
                            statsEl.textContent = d.stats;
                        }
                    } catch(e) {}
                }
            }
        } catch(err) {
            alert('重新生成失败：' + err.message);
        } finally {
            btn.innerHTML = origHtml;
            btn.disabled = false;
        }
    });
})();
</script>

<?php pageFooter(); ?>
