<?php
/**
 * Stage 3 — 内容（角色卡 + 卷大纲）
 * 左右双面板：左侧 tab 切换角色/卷大纲；右侧固定 AI 助手
 */
defined('APP_LOADED') or die('Direct access denied.');

$characters = DB::fetchAll("SELECT * FROM character_cards WHERE novel_id = ? ORDER BY id", [$novelId]);
$volumes = DB::fetchAll("SELECT * FROM volume_outlines WHERE novel_id = ? ORDER BY volume_index", [$novelId]);

if (empty($volumes)) {
    $targetChapters = (int)($novel['target_chapters'] ?? 100);
    $volCount = max(1, (int)ceil($targetChapters / 50));
    $chPerVol = (int)ceil($targetChapters / $volCount);
    for ($i = 1; $i <= $volCount; $i++) {
        $from = ($i - 1) * $chPerVol + 1;
        $to = min($i * $chPerVol, $targetChapters);
        $volumes[] = [
            'id' => 0,
            'volume_index' => $i,
            'title' => "第{$i}卷",
            'theme' => '',
            'chapter_from' => $from,
            'chapter_to' => $to,
        ];
    }
}

$chatHistory = DB::fetchAll(
    "SELECT role, content FROM novel_wizard_chats WHERE novel_id = ? AND stage = 'content' AND role IN ('user','assistant') ORDER BY id DESC LIMIT 24",
    [$novelId]
);
$chatHistory = array_reverse($chatHistory);

// 统计主角数量（title 含"主角"）
$leadCount = 0;
foreach ($characters as $c) {
    if (mb_strpos((string)($c['title'] ?? ''), '主角') !== false) $leadCount++;
}
?>

<style>
.content-layout {
  display: grid; grid-template-columns: minmax(0,1fr) 360px; gap: 1.25rem;
  align-items: stretch;
}
@media (max-width: 992px) { .content-layout { grid-template-columns: 1fr; } }

.content-left { min-width: 0; }
.content-tabs { display: flex; gap: .5rem; margin-bottom: .5rem; }
.content-tab {
  background: #1a1a2e; border: 1px solid #2d2d4e; border-radius: 10px;
  padding: .45rem 1rem; font-size: .9rem; color: #c0c0e0; cursor: pointer;
  display: inline-flex; align-items: center; gap: .4rem; transition: all .15s;
}
.content-tab:hover { border-color: #6366f1; }
.content-tab.active { background: linear-gradient(135deg, #6366f1, #8b5cf6); color:#fff; border-color: transparent; }
.content-tab .badge { background: rgba(255,255,255,.18); padding: 0 .4rem; border-radius: 6px; font-size: .75rem; }

.tab-toolbar {
  display: flex; justify-content: space-between; align-items: center;
  margin: .5rem 0 .75rem; gap: .5rem; flex-wrap: wrap;
}
.tab-toolbar .toolbar-tip { color: #a0a0c0; font-size: .85rem; }
.tab-toolbar .toolbar-actions { display: flex; gap: .5rem; }

.char-empty {
  text-align: center; padding: 2.5rem 1rem; color: #707095;
}
.char-empty .empty-icon { font-size: 3rem; opacity: .35; }
.char-empty .empty-text { margin-top: .5rem; font-size: .9rem; }

.char-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px,1fr)); gap: .75rem; }
.char-card {
  background: #0f0f1a; border: 1px solid #2d2d4e; border-radius: 12px;
  padding: .85rem; position: relative; transition: border-color .15s;
}
.char-card:hover { border-color: #6366f1; }
.char-card .char-head { display: flex; justify-content: space-between; align-items: flex-start; gap: .5rem; }
.char-card .char-name { font-weight: 600; color: #e0e0ff; font-size: .95rem; }
.char-card .char-role {
  display: inline-block; font-size: .72rem; padding: .1rem .5rem;
  border-radius: 6px; margin-top: .2rem;
  background: rgba(139,92,246,.18); color: #c4b5fd;
}
.char-card .char-role.lead { background: rgba(34,197,94,.18); color: #86efac; }
.char-card .char-role.villain { background: rgba(239,68,68,.18); color: #fca5a5; }
.char-card .char-attrs { font-size: .78rem; color: #9090b8; margin-top: .5rem; line-height: 1.55; }
.char-card .char-attrs > div { margin-bottom: .25rem; }
.char-card .char-attrs b { color: #b0b0d0; font-weight: 500; }
.char-card .char-actions { display: flex; gap: .25rem; }
.char-card .char-actions .btn { padding: .15rem .4rem; font-size: .75rem; }

.warn-bar {
  background: rgba(234,179,8,.12); border: 1px solid rgba(234,179,8,.35);
  border-radius: 10px; padding: .65rem 1rem; color: #fde68a; font-size: .88rem;
  margin: 1rem 0; text-align: center;
}
.next-launch-wrap { display: flex; justify-content: center; margin-top: .5rem; }
.btn-launch {
  background: linear-gradient(135deg, #16a34a, #15803d); color: #fff; border: none;
  padding: .65rem 1.5rem; border-radius: 10px; font-size: .95rem; font-weight: 500;
  display: inline-flex; align-items: center; gap: .4rem; cursor: pointer;
  transition: transform .1s, opacity .15s;
}
.btn-launch:hover { transform: translateY(-1px); }
.btn-launch:disabled { opacity: .5; cursor: not-allowed; transform: none; }

.vol-row {
  background: #0f0f1a; border: 1px solid #2d2d4e; border-radius: 10px;
  padding: .75rem; margin-bottom: .6rem;
}
.vol-row input.vol-title, .vol-row textarea.vol-theme {
  background: transparent; border: none; color: #d0d0f0; width: 100%; resize: vertical;
}
.vol-row input:focus, .vol-row textarea:focus { outline: none; }
.vol-row .vol-range { white-space: nowrap; flex-shrink: 0; }
.vol-row .vol-range input.vol-from, .vol-row .vol-range input.vol-to {
  width: 46px; background: #1a1a2e; border: 1px solid #2d2d4e; border-radius: 6px;
  color: #d0d0f0; text-align: center; font-size: .8rem; padding: 1px 2px;
  -moz-appearance: textfield;
}
.vol-row .vol-del {
  flex-shrink: 0; background: transparent; border: none; color: #ef4444;
  cursor: pointer; padding: 2px 7px; border-radius: 6px; line-height: 1;
}
.vol-row .vol-del:hover { background: rgba(239,68,68,.12); }

/* 右侧 chat */
.content-right {
  background: #1a1a2e; border: 1px solid #2d2d4e; border-radius: 14px;
  display: flex; flex-direction: column; min-height: 520px;
  position: sticky; top: 1rem; align-self: stretch;
}
.chat-head {
  padding: .75rem 1rem; border-bottom: 1px solid #2d2d4e;
  font-weight: 600; color: #e0e0f0; font-size: .9rem;
}
.chat-head .chat-sub { color: #9090b8; font-weight: 400; font-size: .8rem; margin-left: .35rem; }
.chat-body {
  flex: 1; overflow-y: auto; padding: .75rem 1rem; min-height: 200px;
}
.chat-body .chat-empty { color: #707095; font-size: .82rem; line-height: 1.85; text-align: center; padding: 1rem 0; }
.chat-body .chat-empty .ex { display: block; color: #6366f1; }
.chat-msg { margin-bottom: .55rem; }
.chat-msg.user { text-align: right; }
.chat-msg .msg-bubble {
  display: inline-block; max-width: 90%; padding: .45rem .7rem;
  border-radius: 10px; font-size: .82rem; line-height: 1.55;
  word-break: break-word;
}
.chat-msg.user .msg-bubble { background: rgba(99,102,241,.2); color: #c0c0ff; }
.chat-msg.assistant .msg-bubble { background: rgba(34,197,94,.1); color: #b0d0b0; }
.chat-foot {
  border-top: 1px solid #2d2d4e; padding: .65rem .85rem;
}
.chat-input-row { display: flex; gap: .4rem; }
.chat-input-row textarea {
  flex: 1; background: #0f0f1a; border: 1px solid #2d2d4e; border-radius: 8px;
  padding: .4rem .6rem; color: #d0d0f0; resize: none; min-height: 38px; max-height: 110px;
}
.chat-input-row textarea:focus { outline: none; border-color: #6366f1; }
.chat-input-row .btn-send {
  background: #6366f1; color: #fff; border: none; padding: .4rem .7rem;
  border-radius: 8px; cursor: pointer;
}
.chat-input-row .btn-send:disabled { opacity: .5; cursor: not-allowed; }
.chat-status { color: #707095; font-size: .75rem; margin-top: .35rem; }

/* Modal */
.cm-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,.6); z-index: 2000;
  display: none; align-items: center; justify-content: center;
}
.cm-overlay.show { display: flex; }
.cm-dialog {
  background: #1a1a2e; border: 1px solid #2d2d4e; border-radius: 12px;
  width: 480px; max-width: 92vw; max-height: 90vh; overflow-y: auto;
  padding: 0; box-shadow: 0 10px 40px rgba(0,0,0,.6);
}
.cm-head {
  display: flex; justify-content: space-between; align-items: center;
  padding: .85rem 1.1rem; border-bottom: 1px solid #2d2d4e;
}
.cm-head h6 { color: #e0e0f0; margin: 0; font-size: 1rem; font-weight: 600; }
.cm-head .cm-close {
  background: transparent; border: none; color: #a0a0c0; font-size: 1.3rem;
  cursor: pointer; line-height: 1;
}
.cm-body { padding: 1rem 1.1rem; }
.cm-field { margin-bottom: .85rem; }
.cm-field label { display: block; color: #c0c0e0; font-size: .85rem; margin-bottom: .3rem; }
.cm-field label .req { color: #f87171; margin-left: 2px; }
.cm-field input, .cm-field textarea, .cm-field select {
  width: 100%; background: #0f0f1a; border: 1px solid #2d2d4e; border-radius: 8px;
  color: #d0d0f0; padding: .45rem .6rem; font-size: .88rem;
}
.cm-field textarea { resize: vertical; min-height: 70px; }
.cm-field input:focus, .cm-field textarea:focus, .cm-field select:focus {
  outline: none; border-color: #6366f1;
}
.cm-foot {
  display: flex; justify-content: flex-end; gap: .5rem;
  padding: .75rem 1.1rem; border-top: 1px solid #2d2d4e;
}
</style>

<div class="content-layout">
  <!-- 左侧主面板 -->
  <div class="content-left">

    <div class="content-tabs">
      <button type="button" class="content-tab active" data-tab="chars">
        <i class="bi bi-people"></i>角色 <span class="badge" id="char-count"><?= count($characters) ?></span>
      </button>
      <button type="button" class="content-tab" data-tab="vols">
        <i class="bi bi-journals"></i>卷大纲 <span class="badge"><?= count($volumes) ?></span>
      </button>
    </div>

    <!-- 角色面板 -->
    <div id="pane-chars">
      <div class="tab-toolbar">
        <div class="toolbar-tip">至少 1 个主角才能下一步</div>
        <div class="toolbar-actions">
          <button class="btn btn-sm btn-outline-info" id="btn-ai-gen-char">
            <i class="bi bi-stars me-1"></i>AI 生成
          </button>
          <button class="btn btn-sm btn-primary" id="btn-add-char">
            <i class="bi bi-plus-lg me-1"></i>添加角色
          </button>
        </div>
      </div>

      <div id="char-list-wrap">
        <?php if (empty($characters)): ?>
        <div class="char-empty">
          <i class="bi bi-person-circle empty-icon"></i>
          <div class="empty-text">还没有角色。点 AI 生成或手动添加。</div>
        </div>
        <?php else: ?>
        <div class="char-grid">
          <?php foreach ($characters as $c):
            $attrs = !empty($c['attributes']) ? (json_decode($c['attributes'], true) ?: []) : [];
            $role = (string)($c['title'] ?? ($attrs['角色类型'] ?? '普通配角'));
            $personality = (string)($attrs['性格'] ?? $attrs['描述'] ?? '');
            $background = (string)($attrs['背景'] ?? '');
            $appearance = (string)($attrs['外貌'] ?? '');
            $roleClass = '';
            if (mb_strpos($role,'主角')!==false) $roleClass='lead';
            elseif (mb_strpos($role,'反派')!==false||mb_strpos($role,'死敌')!==false) $roleClass='villain';
          ?>
          <div class="char-card" data-id="<?= (int)$c['id'] ?>"
               data-name="<?= h($c['name']) ?>"
               data-role="<?= h($role) ?>"
               data-personality="<?= h($personality) ?>"
               data-background="<?= h($background) ?>"
               data-appearance="<?= h($appearance) ?>">
            <div class="char-head">
              <div>
                <div class="char-name"><?= h($c['name']) ?></div>
                <span class="char-role <?= $roleClass ?>"><?= h($role) ?></span>
              </div>
              <div class="char-actions">
                <button class="btn btn-outline-info btn-edit-char" title="编辑"><i class="bi bi-pencil"></i></button>
                <button class="btn btn-outline-danger btn-del-char" title="删除"><i class="bi bi-trash"></i></button>
              </div>
            </div>
            <?php if ($personality || $background || $appearance): ?>
            <div class="char-attrs">
              <?php if ($personality): ?><div><b>性格：</b><?= h(mb_substr($personality,0,40)) ?></div><?php endif; ?>
              <?php if ($background): ?><div><b>背景：</b><?= h(mb_substr($background,0,40)) ?></div><?php endif; ?>
              <?php if ($appearance): ?><div><b>外貌：</b><?= h(mb_substr($appearance,0,40)) ?></div><?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <div class="warn-bar" id="warn-need-lead" style="<?= $leadCount > 0 ? 'display:none;' : '' ?>">
        <i class="bi bi-exclamation-triangle me-1"></i>至少需要 <b>1</b> 个<b>主角</b>，才能进入下一步。
      </div>

      <div class="next-launch-wrap">
        <button class="btn-launch" id="btn-next-launch" <?= $leadCount > 0 ? '' : 'disabled' ?>>
          <i class="bi bi-check-circle"></i>确认内容，进入启航
        </button>
      </div>
    </div>

    <!-- 卷大纲面板 -->
    <div id="pane-vols" style="display:none;">
      <div class="tab-toolbar">
        <div class="toolbar-tip">编辑各卷标题 / 主题 / 章节范围，可增删分卷</div>
        <div class="toolbar-actions">
          <button class="btn btn-sm btn-outline-info" id="btn-add-vol">
            <i class="bi bi-plus-lg me-1"></i>添加分卷
          </button>
          <button class="btn btn-sm btn-outline-secondary" id="btn-save-vols">
            <i class="bi bi-save me-1"></i>保存卷大纲
          </button>
        </div>
      </div>
      <div id="vol-list">
        <?php foreach ($volumes as $v): ?>
        <div class="vol-row" data-index="<?= (int)$v['volume_index'] ?>">
          <div class="d-flex gap-2 align-items-center mb-1">
            <span class="vol-ord text-muted small">第<?= (int)$v['volume_index'] ?>卷</span>
            <input type="text" class="vol-title form-control form-control-sm" value="<?= h($v['title']) ?>" placeholder="卷名">
            <span class="vol-range text-muted small">第<input type="number" class="vol-from" min="1" value="<?= (int)$v['chapter_from'] ?>">-<input type="number" class="vol-to" min="1" value="<?= (int)$v['chapter_to'] ?>">章</span>
            <button type="button" class="vol-del" title="删除本卷"><i class="bi bi-trash"></i></button>
          </div>
          <textarea class="vol-theme form-control form-control-sm" rows="2" placeholder="本卷主题/关键事件..."><?= h($v['theme']) ?></textarea>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>

  <!-- 右侧 chat 助手 -->
  <div class="content-right">
    <div class="chat-head">
      <i class="bi bi-stars text-info me-1"></i>数字精灵<span class="chat-sub">角色 / 大纲 助手</span>
    </div>
    <div class="chat-body" id="chat-messages">
      <?php if (empty($chatHistory)): ?>
      <div class="chat-empty">
        问我关于角色或大纲的任何问题：
        <span class="ex">"帮我设计主角的死敌"</span>
        <span class="ex">"第二卷节奏会不会太平"</span>
      </div>
      <?php else: ?>
      <?php foreach ($chatHistory as $msg): ?>
      <div class="chat-msg <?= h($msg['role']) ?>" data-role="<?= h($msg['role']) ?>">
        <div class="msg-bubble" data-raw="<?= h($msg['content']) ?>"><?= nl2br(h($msg['content'])) ?></div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <div class="chat-foot">
      <div class="chat-input-row">
        <textarea id="chat-input" rows="1" placeholder="向精灵提问..."></textarea>
        <button class="btn-send" id="btn-chat-send" title="发送"><i class="bi bi-send"></i></button>
      </div>
      <div class="chat-status" id="chat-status">就绪</div>
    </div>
  </div>
</div>

<!-- 添加 / 编辑角色 Modal -->
<div class="cm-overlay" id="char-modal">
  <div class="cm-dialog">
    <div class="cm-head">
      <h6 id="cm-title">添加角色</h6>
      <button type="button" class="cm-close" data-close>&times;</button>
    </div>
    <div class="cm-body">
      <input type="hidden" id="f-id" value="">
      <div class="cm-field">
        <label>姓名 <span class="req">*</span></label>
        <input type="text" id="f-name" maxlength="80" autocomplete="off">
      </div>
      <div class="cm-field">
        <label>角色类型</label>
        <select id="f-role">
          <option>主角</option>
          <option>女主</option>
          <option>男主</option>
          <option>核心配角</option>
          <option selected>普通配角</option>
          <option>反派</option>
          <option>死敌</option>
          <option>NPC</option>
        </select>
      </div>
      <div class="cm-field">
        <label>性格</label>
        <textarea id="f-personality" rows="2"></textarea>
      </div>
      <div class="cm-field">
        <label>背景</label>
        <textarea id="f-background" rows="2"></textarea>
      </div>
      <div class="cm-field">
        <label>外貌</label>
        <textarea id="f-appearance" rows="2" placeholder="（可选）"></textarea>
      </div>
    </div>
    <div class="cm-foot">
      <button type="button" class="btn btn-sm btn-secondary" data-close>取消</button>
      <button type="button" class="btn btn-sm btn-primary" id="btn-save-char">保存</button>
    </div>
  </div>
</div>

<script>
(function(){
  var novelId = <?= (int)$novelId ?>;
  var streaming = false;

  // ── Tab 切换 ──
  document.querySelectorAll('.content-tab').forEach(function(t){
    t.addEventListener('click', function(){
      document.querySelectorAll('.content-tab').forEach(function(x){ x.classList.remove('active'); });
      t.classList.add('active');
      var k = t.dataset.tab;
      document.getElementById('pane-chars').style.display = (k==='chars'?'':'none');
      document.getElementById('pane-vols').style.display  = (k==='vols' ?'':'none');
    });
  });

  // ── Modal 控制 ──
  var modal = document.getElementById('char-modal');
  function openModal(data){
    document.getElementById('cm-title').textContent = data && data.id ? '编辑角色' : '添加角色';
    document.getElementById('f-id').value          = data && data.id ? data.id : '';
    document.getElementById('f-name').value        = data && data.name ? data.name : '';
    document.getElementById('f-role').value        = data && data.role ? data.role : '普通配角';
    document.getElementById('f-personality').value = data && data.personality ? data.personality : '';
    document.getElementById('f-background').value  = data && data.background ? data.background : '';
    document.getElementById('f-appearance').value  = data && data.appearance ? data.appearance : '';
    modal.classList.add('show');
    setTimeout(function(){ document.getElementById('f-name').focus(); }, 50);
  }
  function closeModal(){ modal.classList.remove('show'); }
  modal.querySelectorAll('[data-close]').forEach(function(b){ b.addEventListener('click', closeModal); });
  modal.addEventListener('click', function(e){ if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && modal.classList.contains('show')) closeModal(); });

  document.getElementById('btn-add-char').addEventListener('click', function(){ openModal(null); });

  document.querySelectorAll('.btn-edit-char').forEach(function(b){
    b.addEventListener('click', function(){
      var card = b.closest('.char-card');
      openModal({
        id: card.dataset.id,
        name: card.dataset.name,
        role: card.dataset.role,
        personality: card.dataset.personality,
        background: card.dataset.background,
        appearance: card.dataset.appearance,
      });
    });
  });

  document.querySelectorAll('.btn-del-char').forEach(function(b){
    b.addEventListener('click', function(){
      if (!confirm('确定删除此角色？')) return;
      var id = b.closest('.char-card').dataset.id;
      fetch(apiRouteUrl('wizard', 'action=delete_char'), {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-CSRF-Token': window.CSRF_TOKEN||''},
        body: JSON.stringify({novel_id: novelId, char_id: id})
      })
      .then(function(r){ return r.json(); })
      .then(function(d){ if (d.ok) location.reload(); else alert(d.msg || d.error || '删除失败'); });
    });
  });

  document.getElementById('btn-save-char').addEventListener('click', function(){
    var name = document.getElementById('f-name').value.trim();
    if (!name) { alert('请填写姓名'); return; }
    var payload = {
      novel_id: novelId,
      char_id: parseInt(document.getElementById('f-id').value) || 0,
      name: name,
      title: document.getElementById('f-role').value,
      personality: document.getElementById('f-personality').value.trim(),
      background: document.getElementById('f-background').value.trim(),
      appearance: document.getElementById('f-appearance').value.trim(),
    };
    fetch(apiRouteUrl('wizard', 'action=save_char'), {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-CSRF-Token': window.CSRF_TOKEN||''},
      body: JSON.stringify(payload)
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (d.ok) { closeModal(); location.reload(); }
      else alert(d.msg || d.error || '保存失败');
    })
    .catch(function(err){ alert('网络错误：' + err.message); });
  });

  // ── 卷大纲保存 ──
  function collectVolumes(){
    var arr = [];
    document.querySelectorAll('.vol-row').forEach(function(row){
      arr.push({
        volume_index: parseInt(row.dataset.index) || 0,
        title: row.querySelector('.vol-title').value,
        theme: row.querySelector('.vol-theme').value,
        chapter_from: parseInt(row.querySelector('.vol-from').value) || 1,
        chapter_to:   parseInt(row.querySelector('.vol-to').value)   || 50,
      });
    });
    return arr;
  }
  function saveVolumes(cb){
    fetch(apiRouteUrl('wizard', 'action=save_volumes'), {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-CSRF-Token': window.CSRF_TOKEN||''},
      body: JSON.stringify({novel_id: novelId, volumes: collectVolumes()})
    })
    .then(function(r){ return r.json(); })
    .then(function(d){ if (d.ok) { if (cb) cb(); } else alert(d.msg || d.error || '保存失败'); });
  }
  document.getElementById('btn-save-vols').addEventListener('click', function(){
    saveVolumes(function(){ alert('卷大纲已保存'); });
  });

  // ── 进入启航 ──
  document.getElementById('btn-next-launch').addEventListener('click', function(){
    if (this.disabled) return;
    saveVolumes(function(){
      fetch(apiRouteUrl('wizard', 'action=save_stage'), {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-CSRF-Token': window.CSRF_TOKEN||''},
        body: JSON.stringify({novel_id: novelId, stage: 'content'})
      })
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (d.ok) location.href = 'novel_wizard.php?id=' + novelId + '&stage=launch';
        else alert(d.msg || d.error || '操作失败');
      });
    });
  });

  // ── 卷大纲自动保存（中途退出/切走/关闭页面不丢，与蓝图阶段一致）──
  var volDirty = false, volSaving = false, volTimer = null;
  function autoSaveVolumes(useBeacon){
    if (!volDirty) return; // 只有真正改过卷标题/主题才保存，避免无谓写库
    var payload = JSON.stringify({novel_id: novelId, volumes: collectVolumes()});
    if (useBeacon) {
      try {
        fetch(apiRouteUrl('wizard', 'action=save_volumes'), {
          method: 'POST', keepalive: true,
          headers: {'Content-Type':'application/json','X-CSRF-Token': window.CSRF_TOKEN||''},
          body: payload
        });
      } catch (_) {}
      volDirty = false;
      return;
    }
    if (volSaving) return;
    volSaving = true;
    fetch(apiRouteUrl('wizard', 'action=save_volumes'), {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-CSRF-Token': window.CSRF_TOKEN||''},
      body: payload
    }).then(function(r){ return r.json(); })
      .then(function(){ volSaving = false; volDirty = false; })
      .catch(function(){ volSaving = false; });
  }
  // 分卷增删改：用事件委托，动态新增的行也能即时自动保存
  var volList = document.getElementById('vol-list');
  function markVolDirty(immediate){
    volDirty = true;
    if (volTimer) clearTimeout(volTimer);
    volTimer = setTimeout(function(){ autoSaveVolumes(false); }, immediate ? 0 : 1500); // 停顿 1.5s 自动存
  }
  function renumberVolumes(){
    volList.querySelectorAll('.vol-row').forEach(function(row, i){
      var n = i + 1;
      row.dataset.index = n;
      var ord = row.querySelector('.vol-ord');
      if (ord) ord.textContent = '第' + n + '卷';
    });
  }
  function makeVolRow(n, from, to){
    var row = document.createElement('div');
    row.className = 'vol-row';
    row.dataset.index = n;
    row.innerHTML =
      '<div class="d-flex gap-2 align-items-center mb-1">' +
        '<span class="vol-ord text-muted small">第' + n + '卷</span>' +
        '<input type="text" class="vol-title form-control form-control-sm" placeholder="卷名">' +
        '<span class="vol-range text-muted small">第<input type="number" class="vol-from" min="1" value="' + from + '">-' +
        '<input type="number" class="vol-to" min="1" value="' + to + '">章</span>' +
        '<button type="button" class="vol-del" title="删除本卷"><i class="bi bi-trash"></i></button>' +
      '</div>' +
      '<textarea class="vol-theme form-control form-control-sm" rows="2" placeholder="本卷主题/关键事件..."></textarea>';
    return row;
  }
  // 编辑：标题 / 主题 / 章节范围任一变化 → 标脏自动存
  volList.addEventListener('input', function(e){
    if (e.target.matches('.vol-title, .vol-theme, .vol-from, .vol-to')) markVolDirty(false);
  });
  // 删除分卷（至少保留 1 卷；后端保存为「全删再插入」，删除立即落库）
  volList.addEventListener('click', function(e){
    if (!e.target.closest('.vol-del')) return;
    if (volList.querySelectorAll('.vol-row').length <= 1) { alert('至少保留 1 个分卷'); return; }
    var row = e.target.closest('.vol-row');
    if (!row || !confirm('确定删除该分卷？')) return;
    row.remove();
    renumberVolumes();
    markVolDirty(true);
  });
  // 添加分卷（章节范围默认接在最后一卷之后）
  var addVolBtn = document.getElementById('btn-add-vol');
  if (addVolBtn) addVolBtn.addEventListener('click', function(){
    var rows = volList.querySelectorAll('.vol-row');
    var lastTo = rows.length ? (parseInt(rows[rows.length - 1].querySelector('.vol-to').value) || 0) : 0;
    var from = lastTo + 1, to = from + 49;
    var row = makeVolRow(rows.length + 1, from, to);
    volList.appendChild(row);
    renumberVolumes();
    row.querySelector('.vol-title').focus();
    markVolDirty(false);
  });
  setInterval(function(){ if (volDirty && !volSaving) autoSaveVolumes(false); }, 30000); // 30s 兜底
  document.addEventListener('visibilitychange', function(){
    if (document.visibilityState === 'hidden') autoSaveVolumes(true);
  });
  window.addEventListener('beforeunload', function(){ if (volDirty) autoSaveVolumes(true); });

  // ── 右侧 chat (SSE) ──
  var chatBox = document.getElementById('chat-messages');
  var chatInput = document.getElementById('chat-input');
  var chatSend = document.getElementById('btn-chat-send');
  var chatStatus = document.getElementById('chat-status');

  function setStatus(t){ chatStatus.textContent = t || '就绪'; }
  function scrollChat(){ chatBox.scrollTop = chatBox.scrollHeight; }

  function escHtml(s){
    return (s||'').replace(/[&<>]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;'}[c]; });
  }

  // 解析 AI 输出里的【角色 N】块
  function parseCharacters(text){
    if (!text) return [];
    var fieldMap = {'姓名':'name','名字':'name','名称':'name','角色类型':'role','定位':'role','类型':'role','身份':'role','性格':'personality','性格特点':'personality','背景':'background','背景故事':'background','身世':'background','外貌':'appearance','外形':'appearance','长相':'appearance','描述':'personality'};
    var blocks = text.split(/(?=【\s*角色\s*\d+|^#{1,3}\s*角色\s*\d+)/m);
    var out = [];
    blocks.forEach(function(blk){
      var headM = blk.match(/^(?:【\s*角色\s*(\d+)\s*】|#{1,3}\s*角色\s*(\d+)[：:]?)/);
      if (!headM) return;
      var body = blk.slice(headM[0].length);
      var rec = { name:'', role:'普通配角', personality:'', background:'', appearance:'' };
      var lines = body.split(/\n/);
      var cur = null;
      lines.forEach(function(line){
        if (/^\s*【\s*角色/.test(line) || /^#{1,3}\s*角色/.test(line)) return;
        var fm = line.match(/^[\s\-\*•·\d\.]*\**\s*(姓名|名字|名称|角色类型|定位|类型|身份|性格特点|性格|背景故事|背景|身世|外貌|外形|长相|描述)\**\s*[：:]\s*(.*)$/);
        if (fm) {
          cur = fieldMap[fm[1]] || null;
          if (cur) rec[cur] = fm[2].trim();
        } else if (cur && line.trim()) {
          rec[cur] += (rec[cur] ? '\n' : '') + line.replace(/^[\s\-\*•·]+/, '').trim();
        }
      });
      if (rec.name) out.push(rec);
    });
    return out;
  }

  function renderAssistantBubble(bubble, fullText){
    bubble.innerHTML = '';
    var textWrap = document.createElement('div');
    textWrap.style.whiteSpace = 'pre-wrap';
    textWrap.style.wordBreak = 'break-word';
    textWrap.innerHTML = escHtml(fullText);
    bubble.appendChild(textWrap);

    var chars = parseCharacters(fullText);
    if (!chars.length) return;
    var actions = document.createElement('div');
    actions.style.marginTop = '.5rem';
    actions.style.display = 'flex';
    actions.style.flexWrap = 'wrap';
    actions.style.gap = '.35rem';
    chars.forEach(function(c, i){
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn btn-sm btn-outline-success';
      btn.style.fontSize = '.72rem';
      btn.style.padding = '.15rem .55rem';
      btn.innerHTML = '<i class="bi bi-plus-circle"></i> 添加「' + escHtml(c.name) + '」';
      btn.dataset.payload = JSON.stringify(c);
      btn.addEventListener('click', function(){ addCharFromAI(JSON.parse(btn.dataset.payload), btn); });
      actions.appendChild(btn);
    });
    bubble.appendChild(actions);
  }

  function addCharFromAI(c, btn){
    if (btn.dataset.added === '1') return;
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass"></i> 添加中…';
    fetch(apiRouteUrl('wizard', 'action=save_char'), {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-CSRF-Token': window.CSRF_TOKEN||''},
      body: JSON.stringify({
        novel_id: novelId,
        name: c.name,
        title: c.role || '普通配角',
        personality: c.personality || '',
        background: c.background || '',
        appearance: c.appearance || ''
      })
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (d.ok) {
        btn.classList.remove('btn-outline-success');
        btn.classList.add('btn-success');
        btn.innerHTML = '<i class="bi bi-check2"></i> 已添加「' + escHtml(c.name) + '」';
        btn.dataset.added = '1';
        if (!window.__char_refresh_pending) {
          window.__char_refresh_pending = true;
          setTimeout(function(){ location.reload(); }, 1200);
        }
      } else {
        btn.disabled = false;
        btn.classList.remove('btn-outline-success');
        btn.classList.add('btn-outline-danger');
        btn.innerHTML = '<i class="bi bi-x-circle"></i> ' + (d.msg || d.error || '添加失败');
      }
    })
    .catch(function(err){
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-x-circle"></i> 网络错误';
    });
  }

  function addChatMsg(role, text){
    var ph = chatBox.querySelector('.chat-empty'); if (ph) ph.remove();
    var div = document.createElement('div');
    div.className = 'chat-msg ' + role;
    var bubble = document.createElement('div');
    bubble.className = 'msg-bubble';
    div.appendChild(bubble);
    chatBox.appendChild(div);
    if (role === 'assistant' && text) {
      renderAssistantBubble(bubble, text);
    } else {
      bubble.innerHTML = escHtml(text||'').replace(/\n/g,'<br>');
    }
    scrollChat();
    return bubble;
  }

  function sendChat(msg){
    if (streaming || !msg.trim()) return;
    if (!novelId || novelId <= 0) { addChatMsg('assistant','⚠️ 缺少 novel_id，请回选题阶段重新提交'); return; }
    addChatMsg('user', msg);
    chatInput.value = '';
    chatInput.style.height = 'auto';
    streaming = true; chatSend.disabled = true;
    setStatus('生成中…');

    var bubble = addChatMsg('assistant','');
    bubble.innerHTML = '<span class="text-muted"><i class="bi bi-hourglass-split"></i> 思考中…</span>';
    var fullText = '', firstChunk = true;

    fetch(apiRouteUrl('wizard', 'action=chat'), {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-CSRF-Token': window.CSRF_TOKEN||''},
      body: JSON.stringify({novel_id: novelId, stage: 'content', message: msg})
    })
    .then(function(r){
      if (!r.body) throw new Error('浏览器不支持流式响应');
      var ctype = (r.headers.get('Content-Type')||'').toLowerCase();
      if (ctype.indexOf('text/event-stream') < 0) {
        return r.text().then(function(t){
          var m = t;
          try { var j = JSON.parse(t); m = j.error || j.msg || t; } catch(_){}
          bubble.innerHTML = '⚠️ ' + escHtml(m);
          streaming = false; chatSend.disabled = false; setStatus('错误');
        });
      }
      var reader = r.body.getReader(), dec = new TextDecoder('utf-8'), buf = '';
      function processEvt(evt){
        var data = [];
        evt.split(/\r?\n/).forEach(function(l){ if (l.indexOf('data:')===0) data.push(l.slice(5).replace(/^\s/,'')); });
        if (!data.length) return;
        var payload = data.join('\n').trim();
        if (!payload || payload === '[DONE]') return;
        try {
          var d = JSON.parse(payload);
          if (d.error) { bubble.innerHTML = '⚠️ ' + escHtml(d.error); return; }
          if (d.ready || d.done) return;
          if (typeof d.content === 'string' && d.content !== '') {
            if (firstChunk) { bubble.innerHTML = ''; firstChunk = false; }
            fullText += d.content;
            renderAssistantBubble(bubble, fullText);
            scrollChat();
          }
        } catch(e){}
      }
      function read(){
        return reader.read().then(function(res){
          if (res.value) buf += dec.decode(res.value, {stream: !res.done});
          var parts = buf.split(/\r?\n\r?\n/);
          buf = parts.pop();
          parts.forEach(processEvt);
          if (res.done) {
            var tail = dec.decode();
            if (tail) buf += tail;
            if (buf.trim()) processEvt(buf);
            streaming = false; chatSend.disabled = false; setStatus('就绪');
            return;
          }
          return read();
        });
      }
      return read();
    })
    .catch(function(err){
      bubble.innerHTML = '⚠️ ' + escHtml(err.message);
      streaming = false; chatSend.disabled = false; setStatus('错误');
    });
  }

  chatSend.addEventListener('click', function(){ sendChat(chatInput.value); });
  chatInput.addEventListener('keydown', function(e){
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendChat(chatInput.value); }
  });
  chatInput.addEventListener('input', function(){
    chatInput.style.height = 'auto';
    chatInput.style.height = Math.min(chatInput.scrollHeight, 110) + 'px';
  });

  // ── AI 生成角色 ──
  document.getElementById('btn-ai-gen-char').addEventListener('click', function(){
    sendChat('请基于已有设定，帮我生成一组核心角色（主角 1 个 + 反派 1 个 + 关键配角 1-2 个）。每个角色严格按照下面的格式输出，不要使用 markdown 加粗：\n\n【角色 1】\n- 姓名：xxx\n- 角色类型：主角\n- 性格：xxx\n- 背景：xxx\n- 外貌：xxx\n\n【角色 2】\n- 姓名：xxx\n- 角色类型：反派\n- 性格：xxx\n- 背景：xxx\n- 外貌：xxx\n\n（依次类推，每个角色单独一块）');
  });

  // 升级历史 assistant 消息：把含【角色 N】的旧消息重新渲染并挂"添加"按钮
  document.querySelectorAll('.chat-msg.assistant .msg-bubble[data-raw]').forEach(function(b){
    var raw = b.getAttribute('data-raw') || '';
    if (raw && /【\s*角色\s*\d+|^#{1,3}\s*角色\s*\d+/m.test(raw)) {
      renderAssistantBubble(b, raw);
    }
  });

})();
</script>
