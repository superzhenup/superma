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
      <div id="read-mode" class="p-4">
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
          <p class="text-muted small">大纲已生成，点击「写作」按钮开始创作</p>
          <button class="btn btn-primary btn-sm" id="btn-rewrite" data-chapter="<?= $id ?>">
            <i class="bi bi-pencil me-1"></i>开始写作
          </button>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Edit mode -->
      <div id="edit-mode" style="display:none" class="p-4">
        <div class="mb-3">
          <label class="form-label">章节标题</label>
          <input type="text" id="edit-title" class="form-control" value="<?= h($chapter['title']) ?>">
        </div>
        <div class="mb-3">
          <label class="form-label">章节内容</label>
          <textarea id="edit-content" class="form-control chapter-editor"><?= h($chapter['content']) ?></textarea>
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-success btn-sm" onclick="saveChapter(<?= $id ?>)">
            <i class="bi bi-check-circle me-1"></i>保存
          </button>
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
        <?php if ($chapter['outline']): ?>
        <p class="text-muted small"><?= nl2br(h($chapter['outline'])) ?></p>
        <?php else: ?>
        <p class="text-muted small">暂无大纲</p>
        <?php endif; ?>
        <?php if ($chapter['key_points']): ?>
        <?php $pts = json_decode($chapter['key_points'], true) ?? []; ?>
        <?php if ($pts): ?>
        <div class="mt-2">
          <div class="small text-muted mb-1 fw-semibold">关键情节点：</div>
          <?php foreach ($pts as $pt): ?>
          <div class="d-flex gap-2 small text-light mb-1">
            <i class="bi bi-diamond-fill text-primary mt-1 flex-shrink-0" style="font-size:.5rem"></i>
            <?= h($pt) ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <?php if ($chapter['hook']): ?>
        <div class="mt-3 p-2 rounded" style="background:rgba(99,102,241,.1);border:1px solid rgba(99,102,241,.3)">
          <div class="small text-muted mb-1">结尾钩子：</div>
          <div class="small text-light"><?= h($chapter['hook']) ?></div>
        </div>
        <?php endif; ?>
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

document.getElementById('btnRollback').addEventListener('click', async () => {
    if (!selectedVersionId) return;
    if (!confirm('确定要回滚到此版本吗？当前内容将被备份为新版本。')) return;

    const res = await fetch(`api/chapter_versions.php?chapter_id=${CHAPTER_ID}&action=rollback&version_id=${selectedVersionId}`);
    const data = await res.json();
    if (data.ok) {
        alert('回滚成功');
        location.reload();
    } else {
        alert('回滚失败：' + data.msg);
    }
});

function toggleEdit() {
    const r = document.getElementById('read-mode');
    const e = document.getElementById('edit-mode');
    if (r.style.display === 'none') {
        r.style.display = '';
        e.style.display = 'none';
    } else {
        r.style.display = 'none';
        e.style.display = '';
    }
}

async function saveChapter(chapterId) {
    const title   = document.getElementById('edit-title').value;
    const content = document.getElementById('edit-content').value;
    const res = await fetch('api/actions.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action:'save_chapter', chapter_id:chapterId, title, content })
    });
    const data = await res.json();
    if (data.ok) {
        location.reload();
    } else {
        alert('保存失败：' + data.msg);
    }
}

// Rewrite button
document.querySelectorAll('#btn-rewrite').forEach(btn => {
    btn.addEventListener('click', async () => {
        const modal = new bootstrap.Modal(document.getElementById('rewriteModal'));
        modal.show();
        document.getElementById('rewriteContent').textContent = '';
        document.getElementById('rewriteSpinner').style.display = '';
        document.getElementById('rewriteStats').textContent = '';

        try {
            const response = await fetch('api/write_chapter.php', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ novel_id: NOVEL_ID, chapter_id: CHAPTER_ID })
            });
            const reader   = response.body.getReader();
            const decoder  = new TextDecoder();
            const contentEl = document.getElementById('rewriteContent');
            let   fullText  = '';
            document.getElementById('rewriteSpinner').style.display = 'none';

            while (true) {
                const { value, done } = await reader.read();
                if (done) break;
                const text = decoder.decode(value);
                const lines = text.split('\n');
                for (const line of lines) {
                    if (!line.startsWith('data: ')) continue;
                    const payload = line.slice(6);
                    if (payload === '[DONE]') break;
                    try {
                        const d = JSON.parse(payload);
                        if (d.chunk) {
                            fullText += d.chunk;
                            contentEl.textContent = fullText;
                            contentEl.scrollTop = contentEl.scrollHeight;
                        }
                        if (d.stats) {
                            document.getElementById('rewriteStats').textContent = d.stats;
                        }
                    } catch(e) {}
                }
            }
        } catch(err) {
            document.getElementById('rewriteContent').textContent = '写作失败：' + err.message;
        }
    });
});
</script>

<?php pageFooter(); ?>
