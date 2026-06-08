<?php
/**
 * Stage 4 — 启航（AI 体检 + 跳转完整编辑页）
 */
defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/context.php';
require_once __DIR__ . '/schema.php';

$progress = DB::fetch("SELECT metadata FROM novel_wizard_progress WHERE novel_id = ?", [$novelId]);
$meta = ($progress && $progress['metadata']) ? (json_decode($progress['metadata'], true) ?: []) : [];
$reviewResult = $meta['review_result'] ?? '';

$characters = DB::fetchAll("SELECT * FROM character_cards WHERE novel_id = ? ORDER BY id", [$novelId]);
$volumes    = DB::fetchAll("SELECT * FROM volume_outlines WHERE novel_id = ? ORDER BY volume_index", [$novelId]);

// 统计卡片
$basicCount = 0;
foreach (['title','genre','target_chapters','extra_settings'] as $f) {
    if (!empty($novel[$f])) $basicCount++;
}
$charCount = count($characters);
$volCount  = count($volumes);

// 结构化字段：文学流派/世界设定/主角设定/核心冲突/叙事结构/叙事方法/叙事视角
$tagFieldsForReview = [
    'narrative_structure' => '叙事结构',
    'narrative_method'    => '叙事方法',
    'narrative_pov'       => '叙事视角',
    'literary_genre'      => '文学流派',
    'world_setting_era'   => '世界设定',
    'novel_types'         => '小说类型',
    'writing_tone'        => '文风',
    'protagonist_traits'  => '主角设定',
    'core_conflicts'      => '核心冲突',
    'appeal_points'       => '爽点',
    'taboos'              => '禁忌',
    'opening_type'        => '开篇类型',
    'protagonist_entrance'=> '主角出场',
];
$tagSummary = [];
foreach ($tagFieldsForReview as $k => $label) {
    if (empty($novel[$k])) continue;
    $v = $novel[$k];
    if (is_string($v) && str_starts_with($v, '[')) {
        $arr = json_decode($v, true);
        if (is_array($arr) && $arr) $v = implode('、', $arr);
    }
    if ($v !== '' && $v !== null && $v !== '[]') {
        $tagSummary[$label] = (string)$v;
    }
}
$settingCount = count($tagSummary);

// 额外设定预览文本
$extraPreview = $meta['extra_preview'] ?? '';
if ($extraPreview === '') {
    $lines = ['─── 向导汇总 ───'];
    foreach ($tagSummary as $lbl => $val) {
        $lines[] = "【{$lbl}】{$val}";
    }
    if (!empty($novel['custom_settings'])) {
        $lines[] = '';
        $lines[] = '─── 自定义设定 ───';
        $lines[] = trim((string)$novel['custom_settings']);
    }
    $extraPreview = implode("\n", $lines);
}

// 体检 chat 历史
$chatHistory = DB::fetchAll(
    "SELECT role, content FROM novel_wizard_chats WHERE novel_id = ? AND stage = 'launch' AND role IN ('user','assistant') ORDER BY id DESC LIMIT 24",
    [$novelId]
);
$chatHistory = array_reverse($chatHistory);
?>

<style>
.launch-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: .75rem; margin-bottom: 1rem; }
@media (max-width: 768px) { .launch-stats { grid-template-columns: repeat(2, 1fr); } }
.stat-card {
  background: #0f0f1a; border: 1px solid #2d2d4e; border-radius: 12px;
  padding: 1rem; text-align: center;
}
.stat-card .stat-num { font-size: 1.8rem; font-weight: 700; color: #e0e0ff; }
.stat-card .stat-lbl { font-size: .82rem; color: #9090b8; margin-top: .25rem; }

.launch-grid { display: grid; grid-template-columns: minmax(0,1fr) 360px; gap: 1rem; align-items: stretch; }
@media (max-width: 992px) { .launch-grid { grid-template-columns: 1fr; } }

.panel {
  background: #0f0f1a; border: 1px solid #2d2d4e; border-radius: 12px;
  display: flex; flex-direction: column; min-height: 400px;
}
.panel-head {
  padding: .7rem 1rem; border-bottom: 1px solid #2d2d4e;
  display: flex; justify-content: space-between; align-items: center;
}
.panel-head h6 { margin: 0; color: #e0e0f0; font-size: .95rem; font-weight: 600; }
.panel-body { padding: .85rem 1rem; flex: 1; overflow-y: auto; }

.review-section { margin-bottom: .65rem; }
.review-section .field-label { color: #9090b8; font-size: .78rem; margin-bottom: .2rem; }
.review-section .field-chips { display: flex; flex-wrap: wrap; gap: .35rem; }
.review-chip {
  display: inline-block; padding: .15rem .55rem; font-size: .78rem;
  background: rgba(99,102,241,.18); color: #c4b5fd; border-radius: 6px;
}
.review-head-tag {
  display: inline-block; padding: .15rem .55rem; font-size: .78rem;
  background: rgba(34,197,94,.18); color: #86efac; border-radius: 6px;
  margin-bottom: .5rem;
}
.review-empty { color: #707095; font-size: .85rem; text-align: center; padding: 2rem 0; }
.review-stream { white-space: pre-wrap; color: #d0d0f0; font-size: .85rem; line-height: 1.7; }

/* chat 右侧 */
.chat-body { flex: 1; overflow-y: auto; padding: .85rem 1rem; }
.chat-body .chat-empty { color: #707095; font-size: .82rem; line-height: 1.85; text-align: center; padding: 1rem 0; }
.chat-body .chat-empty .ex { display: block; color: #6366f1; }
.chat-body .chat-empty .note { color: #707095; margin-top: .5rem; font-size: .78rem; }
.chat-msg { margin-bottom: .55rem; }
.chat-msg.user { text-align: right; }
.chat-msg .msg-bubble {
  display: inline-block; max-width: 90%; padding: .45rem .7rem; border-radius: 10px;
  font-size: .82rem; line-height: 1.55; word-break: break-word;
}
.chat-msg.user .msg-bubble { background: rgba(99,102,241,.2); color: #c0c0ff; }
.chat-msg.assistant .msg-bubble { background: rgba(34,197,94,.1); color: #b0d0b0; }
.chat-foot { border-top: 1px solid #2d2d4e; padding: .6rem .85rem; }
.chat-input-row { display: flex; gap: .4rem; }
.chat-input-row textarea {
  flex: 1; background: #0f0f1a; border: 1px solid #2d2d4e; border-radius: 8px;
  padding: .4rem .6rem; color: #d0d0f0; resize: none; min-height: 50px; max-height: 110px;
  font-size: .85rem;
}
.chat-input-row textarea:focus { outline: none; border-color: #6366f1; }
.chat-input-row .btn-send {
  background: #6366f1; color: #fff; border: none; padding: .4rem .7rem;
  border-radius: 8px; cursor: pointer;
}
.chat-status { color: #707095; font-size: .75rem; margin-top: .35rem; }

.extra-wrap { margin-top: 1rem; background: #0f0f1a; border: 1px solid #2d2d4e; border-radius: 12px; }
.extra-head {
  padding: .7rem 1rem; border-bottom: 1px solid #2d2d4e;
  display: flex; justify-content: space-between; align-items: center;
}
.extra-head h6 { margin: 0; color: #e0e0f0; font-size: .92rem; font-weight: 600; }
.extra-body { padding: .85rem 1rem; }
.extra-body textarea {
  width: 100%; background: #0a0a18; border: 1px solid #2d2d4e; border-radius: 8px;
  color: #d0d0f0; padding: .65rem .8rem; font-size: .85rem; line-height: 1.7;
  min-height: 180px; resize: vertical; font-family: ui-monospace, Menlo, Consolas, monospace;
}
.extra-tip { margin-top: .5rem; font-size: .78rem; color: #707095; }

.launch-footer {
  display: flex; justify-content: space-between; align-items: center;
  margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid #2d2d4e;
}
.btn-final {
  background: linear-gradient(135deg, #16a34a, #15803d); color: #fff; border: none;
  padding: .65rem 1.5rem; border-radius: 10px; font-size: .95rem; font-weight: 500;
  display: inline-flex; align-items: center; gap: .4rem; cursor: pointer;
}
.btn-final:hover { transform: translateY(-1px); }

/* 启航阶段自带底部导航，隐藏外层 stage-footer */
.wizard-shell > .stage-footer { display: none; }

/* 采纳建议预览弹窗 */
#adopt-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.6); z-index:3000; align-items:center; justify-content:center; }
#adopt-overlay .adopt-card { background:#15152a; border:1px solid #2d2d4e; border-radius:14px; width:min(680px,92%); max-height:84vh; display:flex; flex-direction:column; }
.adopt-hd, .adopt-ft { padding:.8rem 1.1rem; display:flex; justify-content:space-between; align-items:center; }
.adopt-hd { border-bottom:1px solid #2d2d4e; }
.adopt-ft { border-top:1px solid #2d2d4e; }
.adopt-bd { padding:.4rem 1.1rem; overflow-y:auto; }
.adopt-item { display:flex; gap:.55rem; padding:.6rem .15rem; border-bottom:1px dashed #2d2d4e; cursor:pointer; }
.adopt-item:last-child { border-bottom:none; }
.adopt-item input[type=checkbox] { margin-top:.3rem; flex-shrink:0; }
.adopt-item .ai-label { color:#c4b5fd; font-weight:600; font-size:.85rem; }
.adopt-diff { font-size:.82rem; line-height:1.55; margin-top:.15rem; }
.adopt-diff .old { color:#e08aa8; text-decoration:line-through; opacity:.8; white-space:pre-wrap; word-break:break-word; }
.adopt-diff .new { color:#86efac; white-space:pre-wrap; word-break:break-word; }
.adopt-empty { color:#9090b8; text-align:center; padding:1.5rem 0; font-size:.88rem; }
</style>

<!-- 顶部统计 -->
<div class="launch-stats">
  <div class="stat-card"><div class="stat-num"><?= (int)$basicCount ?></div><div class="stat-lbl">基础信息</div></div>
  <div class="stat-card"><div class="stat-num"><?= (int)$charCount ?></div><div class="stat-lbl">角色</div></div>
  <div class="stat-card"><div class="stat-num"><?= (int)$volCount ?></div><div class="stat-lbl">卷数</div></div>
  <div class="stat-card"><div class="stat-num"><?= (int)$settingCount ?></div><div class="stat-lbl">设定项</div></div>
</div>

<!-- 左右双栏 -->
<div class="launch-grid">
  <!-- 左：AI 体检 -->
  <div class="panel">
    <div class="panel-head">
      <h6><i class="bi bi-shield-check text-info me-1"></i>AI 一致性体检</h6>
      <div class="d-flex gap-2">
        <button class="btn btn-sm btn-success" id="btn-adopt" style="<?= $reviewResult ? '' : 'display:none;' ?>" title="把体检的优化建议结构化后预览、确认再应用到设定">
          <i class="bi bi-magic me-1"></i>采纳建议
        </button>
        <button class="btn btn-sm btn-outline-secondary" id="btn-review">
          <i class="bi bi-arrow-repeat me-1"></i><span id="btn-review-text"><?= $reviewResult ? '重新体检' : '开始体检' ?></span>
        </button>
      </div>
    </div>
    <div class="panel-body">
      <?php if (!empty($tagSummary)): ?>
      <?php if (!empty($tagSummary['叙事视角'])): ?>
      <div class="review-head-tag"><?= h($tagSummary['叙事视角']) ?></div>
      <?php endif; ?>
      <?php foreach (['文学流派','世界设定','主角设定','核心冲突','叙事结构','叙事方法','小说类型','文风','爽点','禁忌','开篇类型','主角出场'] as $key):
        if (empty($tagSummary[$key])) continue;
        $vals = preg_split('/[、,，]/u', $tagSummary[$key]);
      ?>
      <div class="review-section">
        <div class="field-label"><?= h($key) ?>：</div>
        <div class="field-chips">
          <?php foreach ($vals as $vv): if (trim($vv)==='') continue; ?>
          <span class="review-chip"><?= h(trim($vv)) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php else: ?>
      <div class="review-empty">尚未填写任何标签设定</div>
      <?php endif; ?>

      <?php if ($reviewResult): ?>
      <hr style="border-color:#2d2d4e;margin:1rem 0;">
      <div class="review-stream" id="review-stream"><?= h($reviewResult) ?></div>
      <?php else: ?>
      <div class="review-stream" id="review-stream" style="display:none;"></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- 右：沟通修改 chat -->
  <div class="panel">
    <div class="panel-head">
      <h6><i class="bi bi-stars text-info me-1"></i>数字精灵 · 沟通修改</h6>
    </div>
    <div class="chat-body" id="chat-messages">
      <?php if (empty($chatHistory)): ?>
      <div class="chat-empty">
        如果体检发现问题，可以告诉我你的想法：
        <span class="ex">"我觉得峰回路加进三角恋会不合理"</span>
        <span class="ex">"主角性格再凶残点"</span>
        <span class="ex">"加一个反派"</span>
        <div class="note">需要修改具体设定调回到对应阶段。</div>
      </div>
      <?php else: ?>
      <?php foreach ($chatHistory as $msg): ?>
      <div class="chat-msg <?= h($msg['role']) ?>">
        <div class="msg-bubble"><?= nl2br(h($msg['content'])) ?></div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <div class="chat-foot">
      <div class="chat-input-row">
        <textarea id="chat-input" rows="2" placeholder="对当前设定提建议或质疑...&#10;Ctrl+Enter 发送"></textarea>
        <button class="btn-send" id="btn-chat-send" title="发送"><i class="bi bi-send"></i></button>
      </div>
      <div class="chat-status" id="chat-status">就绪</div>
    </div>
  </div>
</div>

<!-- 额外设定预览 -->
<div class="extra-wrap">
  <div class="extra-head">
    <h6><i class="bi bi-pencil-square text-info me-1"></i>「额外设定」预览（将写入 create.php 编辑页）</h6>
    <div class="d-flex gap-2">
      <button class="btn btn-sm btn-outline-secondary" id="btn-extra-reset">
        <i class="bi bi-arrow-counterclockwise me-1"></i>重置
      </button>
      <button class="btn btn-sm btn-primary" id="btn-extra-save">
        <i class="bi bi-save me-1"></i>保存草稿
      </button>
    </div>
  </div>
  <div class="extra-body">
    <textarea id="extra-text" spellcheck="false"><?= h($extraPreview) ?></textarea>
    <div class="extra-tip">
      <i class="bi bi-info-circle me-1"></i>这段文字会作为「额外设定」直接写入 create.php 的对应字段，里面包含所有向导收集的标签。可以自由修改。
    </div>
  </div>
</div>

<!-- 底部 -->
<div class="launch-footer">
  <a href="?id=<?= (int)$novelId ?>&stage=content" class="btn btn-outline-secondary">
    <i class="bi bi-chevron-left me-1"></i>返回上一步
  </a>
  <button class="btn-final" id="btn-final-confirm">
    <i class="bi bi-check-circle"></i>全部确认，跳转完整编辑页
  </button>
</div>

<!-- 采纳建议预览弹窗 -->
<div id="adopt-overlay">
  <div class="adopt-card">
    <div class="adopt-hd">
      <h6 class="m-0 text-light"><i class="bi bi-magic me-1 text-info"></i>采纳体检建议（预览，确认后才写入）</h6>
      <button type="button" class="btn-close btn-close-white" id="adopt-close" aria-label="关闭"></button>
    </div>
    <div class="adopt-bd" id="adopt-body"></div>
    <div class="adopt-ft">
      <span class="small text-muted" id="adopt-hint"></span>
      <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-secondary" id="adopt-cancel">取消</button>
        <button class="btn btn-sm btn-success" id="adopt-apply"><i class="bi bi-check-lg me-1"></i>确认应用所选</button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var novelId = <?= (int)$novelId ?>;
  var streaming = false;
  var DEFAULT_PREVIEW = <?= json_encode($extraPreview, JSON_UNESCAPED_UNICODE) ?>;

  function escHtml(s){
    return (s||'').replace(/[&<>]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;'}[c];});
  }
  function scrollChat(){
    var box = document.getElementById('chat-messages');
    if (box) box.scrollTop = box.scrollHeight;
  }
  scrollChat();

  // AI 一致性体检
  var btnReview = document.getElementById('btn-review');
  var btnText   = document.getElementById('btn-review-text');
  var streamBox = document.getElementById('review-stream');

  function consumeSse(resp, onChunk, onEnd, onErr){
    if (!resp.body) { onErr('浏览器不支持流式响应'); return; }
    var ctype = (resp.headers.get('Content-Type')||'').toLowerCase();
    if (ctype.indexOf('text/event-stream') < 0) {
      resp.text().then(function(t){
        var m = t;
        try { var j = JSON.parse(t); m = j.error || j.msg || t; } catch(_){}
        onErr(m);
      });
      return;
    }
    var reader = resp.body.getReader(), dec = new TextDecoder('utf-8'), buf = '';
    function processEvt(evt){
      var data = [];
      evt.split(/\r?\n/).forEach(function(l){ if (l.indexOf('data:')===0) data.push(l.slice(5).replace(/^\s/,'')); });
      if (!data.length) return;
      var payload = data.join('\n').trim();
      if (!payload || payload === '[DONE]') return;
      try {
        var d = JSON.parse(payload);
        if (d.error) { onErr(d.error); return; }
        if (d.ready || d.done) return;
        if (typeof d.content === 'string' && d.content !== '') onChunk(d.content);
      } catch(e){}
    }
    function read(){
      return reader.read().then(function(res){
        if (res.value) buf += dec.decode(res.value, {stream: !res.done});
        var parts = buf.split(/\r?\n\r?\n/);
        buf = parts.pop();
        parts.forEach(processEvt);
        if (res.done) { if (buf.trim()) processEvt(buf); onEnd(); return; }
        return read();
      });
    }
    return read();
  }

  btnReview.addEventListener('click', function(){
    if (streaming) return;
    streaming = true;
    btnReview.disabled = true;
    btnText.textContent = '体检中…';
    streamBox.style.display = '';
    streamBox.textContent = '正在体检中，请稍候…';
    var full = '', first = true;
    fetch('api/wizard.php?action=review_check', {
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-Token': window.CSRF_TOKEN||''},
      body: JSON.stringify({novel_id: novelId})
    })
    .then(function(r){
      return consumeSse(r,
        function(c){ if (first){ streamBox.textContent=''; first=false; } full+=c; streamBox.textContent = full; },
        function(){ streaming=false; btnReview.disabled=false; btnText.textContent='重新体检'; var ba=document.getElementById('btn-adopt'); if (ba && full.trim()) ba.style.display=''; },
        function(err){ streamBox.textContent = '⚠️ ' + err; streaming=false; btnReview.disabled=false; btnText.textContent='重新体检'; }
      );
    })
    .catch(function(err){ streamBox.textContent = '⚠️ ' + err.message; streaming=false; btnReview.disabled=false; btnText.textContent='重新体检'; });
  });

  // 沟通修改 chat
  var chatBox = document.getElementById('chat-messages');
  var chatInput = document.getElementById('chat-input');
  var chatSend = document.getElementById('btn-chat-send');
  var chatStatus = document.getElementById('chat-status');
  function setStatus(t){ chatStatus.textContent = t || '就绪'; }

  function addMsg(role, text){
    var ph = chatBox.querySelector('.chat-empty'); if (ph) ph.remove();
    var div = document.createElement('div');
    div.className = 'chat-msg ' + role;
    var b = document.createElement('div'); b.className = 'msg-bubble';
    b.innerHTML = escHtml(text||'').replace(/\n/g,'<br>');
    div.appendChild(b);
    chatBox.appendChild(div); scrollChat();
    return b;
  }

  function sendChat(msg){
    if (streaming || !msg.trim()) return;
    addMsg('user', msg);
    chatInput.value = '';
    streaming = true; chatSend.disabled = true; setStatus('生成中…');
    var bubble = addMsg('assistant','');
    bubble.innerHTML = '<span class="text-muted"><i class="bi bi-hourglass-split"></i> 思考中…</span>';
    var full = '', first = true;
    fetch('api/wizard.php?action=chat', {
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-Token': window.CSRF_TOKEN||''},
      body: JSON.stringify({novel_id: novelId, stage: 'launch', message: msg})
    })
    .then(function(r){
      return consumeSse(r,
        function(c){ if (first){ bubble.innerHTML=''; first=false; } full+=c; bubble.innerHTML = escHtml(full).replace(/\n/g,'<br>'); scrollChat(); },
        function(){ streaming=false; chatSend.disabled=false; setStatus('就绪'); },
        function(err){ bubble.innerHTML = '⚠️ ' + escHtml(err); streaming=false; chatSend.disabled=false; setStatus('错误'); }
      );
    })
    .catch(function(err){ bubble.innerHTML = '⚠️ ' + escHtml(err.message); streaming=false; chatSend.disabled=false; setStatus('错误'); });
  }

  chatSend.addEventListener('click', function(){ sendChat(chatInput.value); });
  chatInput.addEventListener('keydown', function(e){
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); sendChat(chatInput.value); }
  });

  // 额外设定预览
  var extraText = document.getElementById('extra-text');
  document.getElementById('btn-extra-reset').addEventListener('click', function(){
    if (!confirm('确定重置为向导默认汇总？当前修改会丢失。')) return;
    extraText.value = DEFAULT_PREVIEW;
  });
  document.getElementById('btn-extra-save').addEventListener('click', function(){
    saveExtra(true);
  });
  function saveExtra(showAlert){
    return fetch('api/wizard.php?action=save_extra', {
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-Token': window.CSRF_TOKEN||''},
      body: JSON.stringify({novel_id: novelId, extra_preview: extraText.value})
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (d.ok) { if (showAlert) alert('已保存草稿'); }
      else { alert(d.msg || d.error || '保存失败'); throw new Error('fail'); }
    });
  }

  // ── 额外设定自动保存（中途退出/切走/关闭页面不丢，与蓝图阶段一致）──
  var extraDirty = false, extraSaving = false, extraTimer = null;
  var extraLast = extraText ? extraText.value : '';
  function autoSaveExtra(useBeacon){
    if (!extraText) return;
    var val = extraText.value;
    if (val === extraLast && !extraDirty) return;
    if (useBeacon) {
      // 页面切走/关闭：用 keepalive 请求确保发出（移动端比 beforeunload 更可靠）
      try {
        fetch('api/wizard.php?action=save_extra', {
          method: 'POST', keepalive: true,
          headers: {'Content-Type':'application/json','X-CSRF-Token': window.CSRF_TOKEN||''},
          body: JSON.stringify({novel_id: novelId, extra_preview: val})
        });
      } catch (_) {}
      extraLast = val; extraDirty = false;
      return;
    }
    if (extraSaving) return;
    extraSaving = true;
    fetch('api/wizard.php?action=save_extra', {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-CSRF-Token': window.CSRF_TOKEN||''},
      body: JSON.stringify({novel_id: novelId, extra_preview: val})
    }).then(function(r){ return r.json(); })
      .then(function(){ extraSaving = false; extraLast = val; extraDirty = false; })
      .catch(function(){ extraSaving = false; });
  }
  if (extraText) {
    extraText.addEventListener('input', function(){
      extraDirty = true;
      if (extraTimer) clearTimeout(extraTimer);
      extraTimer = setTimeout(function(){ autoSaveExtra(false); }, 1500); // 停顿 1.5s 自动存
    });
  }
  setInterval(function(){ if (extraDirty && !extraSaving) autoSaveExtra(false); }, 30000); // 30s 兜底
  document.addEventListener('visibilitychange', function(){
    if (document.visibilityState === 'hidden') autoSaveExtra(true);
  });
  window.addEventListener('beforeunload', function(){ if (extraDirty) autoSaveExtra(true); });

  // 全部确认
  document.getElementById('btn-final-confirm').addEventListener('click', function(){
    var btn = this; btn.disabled = true;
    saveExtra(false).then(function(){
      return fetch('api/wizard.php?action=save_stage', {
        method:'POST',
        headers:{'Content-Type':'application/json','X-CSRF-Token': window.CSRF_TOKEN||''},
        body: JSON.stringify({novel_id: novelId, stage: 'launch'})
      }).then(function(r){ return r.json(); });
    }).then(function(d){
      if (d && d.ok !== false) location.href = 'create.php?id=' + novelId;
      else { alert((d && (d.msg||d.error)) || '操作失败'); btn.disabled = false; }
    }).catch(function(){ btn.disabled = false; });
  });

  // ── 采纳建议：结构化补丁 → 预览 → 确认应用 ──
  var btnAdopt     = document.getElementById('btn-adopt');
  var adoptOverlay = document.getElementById('adopt-overlay');
  var adoptBody    = document.getElementById('adopt-body');
  var adoptHint    = document.getElementById('adopt-hint');
  var adoptApply   = document.getElementById('adopt-apply');
  var adoptChanges = [];

  function closeAdopt(){ if (adoptOverlay) adoptOverlay.style.display = 'none'; }
  function openAdopt(){ if (adoptOverlay) adoptOverlay.style.display = 'flex'; }

  function fmtVal(v){ return Array.isArray(v) ? v.join('、') : (v === null || v === undefined ? '' : String(v)); }
  function charSummary(o){
    if (!o) return '';
    var p = [];
    if (o.role) p.push('类型：' + o.role);
    if (o.personality) p.push('性格：' + o.personality);
    if (o.background) p.push('背景：' + o.background);
    if (o.appearance) p.push('外貌：' + o.appearance);
    return p.join('；');
  }
  function volSummary(o){
    if (!o) return '';
    var p = [];
    if (o.title) p.push('标题：' + o.title);
    if (o.theme) p.push('主题：' + o.theme);
    if (o.chapter_from || o.chapter_to) p.push('章节：' + (o.chapter_from || '?') + '-' + (o.chapter_to || '?'));
    return p.join('；');
  }
  function renderChange(c){
    var oldStr = '', newStr = '';
    if (c.kind === 'novel') { oldStr = fmtVal(c.old); newStr = fmtVal(c.new); }
    else if (c.kind === 'character') { oldStr = c.op === 'add' ? '' : charSummary(c.old); newStr = charSummary(c.new); }
    else if (c.kind === 'volume') { oldStr = c.op === 'add' ? '' : volSummary(c.old); newStr = volSummary(c.new); }
    return '<label class="adopt-item">'
      + '<input type="checkbox" data-i="' + c.i + '" checked>'
      + '<div style="flex:1;min-width:0">'
      + '<div class="ai-label">' + escHtml(c.label) + '</div>'
      + '<div class="adopt-diff">'
      + (oldStr ? '<div class="old">原：' + escHtml(oldStr) + '</div>' : '')
      + '<div class="new">新：' + escHtml(newStr) + '</div>'
      + '</div></div></label>';
  }

  function requestPatch(){
    if (streaming) return;
    streaming = true; btnAdopt.disabled = true;
    var orig = btnAdopt.innerHTML;
    btnAdopt.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>分析中…';
    fetch('api/wizard.php?action=review_patch', {
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-Token': window.CSRF_TOKEN||''},
      body: JSON.stringify({novel_id: novelId})
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
      streaming = false; btnAdopt.disabled = false; btnAdopt.innerHTML = orig;
      if (!d || !d.ok) { alert((d && (d.msg||d.error)) || '生成失败'); return; }
      adoptChanges = d.changes || [];
      if (!adoptChanges.length) {
        adoptBody.innerHTML = '<div class="adopt-empty">' + escHtml(d.msg || 'AI 认为当前设定无需改动') + '</div>';
        adoptHint.textContent = '';
        adoptApply.style.display = 'none';
      } else {
        adoptBody.innerHTML = adoptChanges.map(renderChange).join('');
        adoptHint.textContent = '共 ' + adoptChanges.length + ' 项，默认全选';
        adoptApply.style.display = '';
      }
      openAdopt();
    })
    .catch(function(err){ streaming = false; btnAdopt.disabled = false; btnAdopt.innerHTML = orig; alert('请求失败：' + err.message); });
  }

  if (btnAdopt) btnAdopt.addEventListener('click', requestPatch);
  var elClose = document.getElementById('adopt-close');
  var elCancel = document.getElementById('adopt-cancel');
  if (elClose) elClose.addEventListener('click', closeAdopt);
  if (elCancel) elCancel.addEventListener('click', closeAdopt);
  if (adoptOverlay) adoptOverlay.addEventListener('click', function(e){ if (e.target === adoptOverlay) closeAdopt(); });

  if (adoptApply) adoptApply.addEventListener('click', function(){
    var boxes = adoptBody.querySelectorAll('input[type=checkbox]');
    var selected = [];
    boxes.forEach(function(b){
      if (b.checked) { var c = adoptChanges[parseInt(b.getAttribute('data-i'), 10)]; if (c) selected.push(c); }
    });
    if (!selected.length) { alert('请至少勾选一项'); return; }
    adoptApply.disabled = true;
    var orig = adoptApply.innerHTML;
    adoptApply.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>应用中…';
    fetch('api/wizard.php?action=apply_review', {
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-Token': window.CSRF_TOKEN||''},
      body: JSON.stringify({novel_id: novelId, changes: selected})
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
      adoptApply.disabled = false; adoptApply.innerHTML = orig;
      if (d && d.ok) { closeAdopt(); alert('已应用 ' + (d.applied || selected.length) + ' 项改动'); location.reload(); }
      else { alert((d && (d.msg||d.error)) || '应用失败'); }
    })
    .catch(function(err){ adoptApply.disabled = false; adoptApply.innerHTML = orig; alert('应用失败：' + err.message); });
  });

})();
</script>
