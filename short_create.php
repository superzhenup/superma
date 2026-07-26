<?php
define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';

$models = DB::fetchAll('SELECT * FROM ai_models ORDER BY is_default DESC, id ASC');

pageHeader('新建短篇', 'shorts');
?>

<style>
.create-form { max-width: 720px; margin: 0 auto; }
.create-form .form-label { color: #c8c8e0; font-weight: 600; font-size: .88rem; }
.create-form .form-control, .create-form .form-select {
  background: #1a1a2e; border-color: #2d2d4e; color: #e8e8ff;
}
.create-form .form-control:focus, .create-form .form-select:focus {
  border-color: #6366f1; box-shadow: 0 0 0 .15rem rgba(99,102,241,.25);
}
.create-form .form-text { color: #707090; }
</style>

<div class="container py-4">

<div class="d-flex align-items-center mb-4">
  <a href="shorts.php" class="btn btn-outline-secondary btn-sm me-3">
    <i class="bi bi-arrow-left me-1"></i>返回
  </a>
  <h5 class="mb-0 text-light fw-bold"><i class="bi bi-plus-circle me-2"></i>新建短篇小说</h5>
</div>

<form id="createForm" class="create-form" onsubmit="return submitCreate(event)">
  <div class="row g-3">
    <div class="col-md-8">
      <label class="form-label">标题</label>
      <input type="text" name="title" class="form-control" placeholder="给你的短篇起个名字" required>
    </div>
    <div class="col-md-4">
      <label class="form-label">类型</label>
      <input type="text" name="genre" class="form-control" placeholder="如：悬疑、科幻、言情">
    </div>
    <div class="col-12">
      <label class="form-label">主题</label>
      <input type="text" name="theme" class="form-control" placeholder="一句话概括核心主题">
    </div>
    <div class="col-12">
      <label class="form-label">核心前提</label>
      <textarea name="premise" class="form-control" rows="2" placeholder="如果……会怎样？（故事的"what if"）"></textarea>
    </div>
    <div class="col-md-6">
      <label class="form-label">主角设定</label>
      <input type="text" name="protagonist" class="form-control" placeholder="主角是谁？什么性格？">
    </div>
    <div class="col-md-6">
      <label class="form-label">核心冲突</label>
      <input type="text" name="conflict" class="form-control" placeholder="主角面临的最大困境">
    </div>
    <div class="col-md-6">
      <label class="form-label">结局方向</label>
      <input type="text" name="ending_direction" class="form-control" placeholder="期望的结局走向">
    </div>
    <div class="col-md-6">
      <label class="form-label">文风偏好</label>
      <input type="text" name="style" class="form-control" placeholder="如：冷峻、温暖、诗意">
    </div>
    <div class="col-md-3">
      <label class="form-label">目标字数</label>
      <input type="number" name="target_words" id="targetWords" class="form-control" value="3000" min="800" max="120000">
      <div class="form-text">800 - 120000字</div>
    </div>
    <div class="col-md-3">
      <label class="form-label">章节数量</label>
      <input type="number" name="chapter_count" id="chapterCount" class="form-control" value="1" min="1" max="20">
      <div class="form-text" id="perChapterHint">1 = 单篇模式</div>
    </div>
    <div class="col-md-3">
      <label class="form-label">叙事结构</label>
      <select name="structure_type" class="form-select">
        <option value="eight_beat">八拍式（推荐）</option>
        <option value="six_beat">六拍式</option>
        <option value="three_act">三幕式</option>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">AI模型</label>
      <select name="model_id" class="form-select">
        <option value="">默认模型</option>
        <?php foreach ($models as $m): ?>
        <option value="<?= $m['id'] ?>"><?= h($m['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="mt-4 d-flex gap-2">
    <button type="submit" class="btn btn-primary" id="btnCreate">
      <i class="bi bi-check-lg me-1"></i>创建并开始
    </button>
    <a href="shorts.php" class="btn btn-outline-secondary">取消</a>
  </div>
</form>

</div>

<script>
function updatePerChapterHint() {
  const tw = parseInt(document.getElementById('targetWords').value) || 0;
  const cc = parseInt(document.getElementById('chapterCount').value) || 1;
  const hint = document.getElementById('perChapterHint');
  if (cc <= 1) {
    hint.textContent = '1 = 单篇模式';
  } else {
    const per = Math.round(tw / cc);
    hint.textContent = '每章约 ' + per.toLocaleString() + ' 字';
  }
}
document.getElementById('targetWords').addEventListener('input', updatePerChapterHint);
document.getElementById('chapterCount').addEventListener('input', updatePerChapterHint);

async function submitCreate(e) {
  e.preventDefault();
  const form = document.getElementById('createForm');
  const btn = document.getElementById('btnCreate');
  const data = Object.fromEntries(new FormData(form));
  data.target_words = parseInt(data.target_words) || 3000;
  data.chapter_count = parseInt(data.chapter_count) || 1;
  data.model_id = data.model_id || null;

  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>创建中...';
  try {
    const r = await fetch(apiRouteUrl('short_story', 'action=create'), {
      method: 'POST', headers: jsonHeaders(),
      body: JSON.stringify(data)
    });
    const d = await r.json();
    if (d.ok && d.data?.id) {
      location.href = 'short.php?id=' + d.data.id;
    } else {
      alert(d.msg || '创建失败');
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>创建并开始';
    }
  } catch(err) {
    alert('网络错误');
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>创建并开始';
  }
  return false;
}
</script>

<?php pageFooter(); ?>
