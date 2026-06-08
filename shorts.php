<?php
define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/ShortStoryService.php';

$userId = (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
$service = new ShortStoryService();
$stories = $service->listStories($userId);

pageHeader('短篇小说', 'shorts');
?>

<style>
.shorts-header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 1.5rem;
}
.shorts-header h5 { color: #e8e8ff; font-weight: 700; margin: 0; }

.short-card {
  background: #1a1a2e; border: 1px solid #2d2d4e; border-radius: 14px;
  padding: 1.2rem 1.4rem; margin-bottom: .8rem;
  transition: all .2s ease; cursor: pointer;
  display: flex; align-items: center; gap: 1rem;
}
.short-card:hover {
  border-color: #6366f1; transform: translateY(-2px);
  box-shadow: 0 8px 24px rgba(99,102,241,.15);
}
.short-card .sc-icon {
  width: 44px; height: 44px; border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.3rem; flex-shrink: 0;
  background: linear-gradient(135deg, #ec4899, #f43f5e); color: #fff;
}
.short-card .sc-body { flex-grow: 1; min-width: 0; }
.short-card .sc-title {
  color: #e8e8ff; font-weight: 600; font-size: .95rem;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.short-card .sc-meta { color: #9090b8; font-size: .78rem; margin-top: .2rem; }
.short-card .sc-actions { display: flex; gap: .4rem; flex-shrink: 0; }

.status-badge {
  font-size: .7rem; padding: .15rem .5rem; border-radius: 8px; font-weight: 600;
}
.status-draft { background: rgba(107,114,128,.2); color: #9ca3af; }
.status-brief_ready { background: rgba(99,102,241,.15); color: #818cf8; }
.status-beats_ready { background: rgba(139,92,246,.15); color: #a78bfa; }
.status-written { background: rgba(16,185,129,.15); color: #34d399; }
.status-polished { background: rgba(245,158,11,.15); color: #fbbf24; }
.status-completed { background: rgba(34,197,94,.15); color: #4ade80; }

.empty-shorts {
  text-align: center; padding: 4rem 1rem;
}
.empty-shorts .empty-icon { font-size: 3rem; color: #4a4a6e; margin-bottom: 1rem; }
.empty-shorts h5 { color: #b8b8d0; }
.empty-shorts p { color: #707090; }
</style>

<div class="container py-4" style="max-width:960px;">

<div class="shorts-header">
  <h5><i class="bi bi-journal-text me-2"></i>短篇小说</h5>
  <a href="short_create.php" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg me-1"></i>新建短篇
  </a>
</div>

<?php if (empty($stories)): ?>
<div class="empty-shorts">
  <div class="empty-icon"><i class="bi bi-journal-text"></i></div>
  <h5>还没有短篇小说</h5>
  <p>点击上方按钮，开始创作你的第一篇短篇小说</p>
  <a href="short_create.php" class="btn btn-outline-primary btn-sm mt-2">
    <i class="bi bi-plus-circle me-1"></i>开始创作
  </a>
</div>
<?php else: ?>
<?php foreach ($stories as $s):
  $statusMap = [
    'draft' => '草稿', 'brief_ready' => '已立项', 'beats_ready' => '已排节拍',
    'written' => '已完稿', 'polished' => '已润色', 'completed' => '已完成',
  ];
  $statusLabel = $statusMap[$s['status']] ?? $s['status'];
  $wordCount = $s['word_count'] ?? 0;
?>
<div class="short-card" onclick="location.href='short.php?id=<?= $s['id'] ?>'">
  <div class="sc-icon"><i class="bi bi-journal-text"></i></div>
  <div class="sc-body">
    <div class="sc-title"><?= h($s['title'] ?: '未命名短篇') ?></div>
    <div class="sc-meta">
      <span class="status-badge status-<?= $s['status'] ?>"><?= $statusLabel ?></span>
      <?php if ($s['genre']): ?><span class="ms-2"><?= h($s['genre']) ?></span><?php endif; ?>
      <span class="ms-2"><?= number_format($wordCount) ?>字 / <?= number_format($s['target_words']) ?>字</span>
      <span class="ms-2"><?= h($s['updated_at']) ?></span>
    </div>
  </div>
  <div class="sc-actions">
    <button class="btn btn-outline-danger btn-sm" onclick="event.stopPropagation();deleteShort(<?= $s['id'] ?>)" title="删除">
      <i class="bi bi-trash3"></i>
    </button>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

</div>

<script>
async function deleteShort(id) {
  if (!confirm('确定删除这篇短篇小说？此操作不可恢复。')) return;
  try {
    const r = await fetch('api/short_story.php?action=delete', {
      method: 'POST', headers: jsonHeaders(),
      body: JSON.stringify({ id })
    });
    const d = await r.json();
    if (d.ok) location.reload();
    else alert(d.msg || '删除失败');
  } catch(e) { alert('网络错误'); }
}
</script>

<?php pageFooter(); ?>
