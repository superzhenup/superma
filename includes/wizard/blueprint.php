<?php
/**
 * Stage 2 — 蓝图（策划 + 世界观）
 *
 * AI 同时生成策划文档和世界观文档，用户可通过对话调整
 */
defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/context.php';

$progress = DB::fetch("SELECT metadata FROM novel_wizard_progress WHERE novel_id = ?", [$novelId]);
$meta = $progress ? (json_decode($progress['metadata'] ?? '{}', true) ?: []) : [];
$planningDoc = $meta['planning_doc'] ?? $novel['plot_settings'] ?? '';
$worldDoc = $meta['world_doc'] ?? $novel['world_settings'] ?? '';

$chatHistory = DB::fetchAll(
    "SELECT role, content FROM novel_wizard_chats WHERE novel_id = ? AND stage = 'blueprint' AND role IN ('user','assistant') ORDER BY id DESC LIMIT 24",
    [$novelId]
);
$chatHistory = array_reverse($chatHistory);
?>

<style>
.blueprint-panels { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
@media (max-width: 768px) { .blueprint-panels { grid-template-columns: 1fr; } }
.doc-panel {
  background: #0f0f1a; border: 1px solid #2d2d4e; border-radius: 12px;
  padding: 1rem; min-height: 200px;
}
.doc-panel h6 { color: #a0a0c0; font-size: .85rem; margin-bottom: .5rem; }
.doc-panel textarea {
  width: 100%; min-height: 180px; background: transparent; border: none;
  color: #d0d0f0; font-size: .9rem; line-height: 1.7; resize: vertical;
}
.doc-panel textarea:focus { outline: none; }

.chat-panel {
  background: #0f0f1a; border: 1px solid #2d2d4e; border-radius: 12px;
  padding: 1rem; margin-top: 1rem;
}
.chat-messages { max-height: 300px; overflow-y: auto; margin-bottom: .75rem; }
.chat-msg { margin-bottom: .6rem; }
.chat-msg.user { text-align: right; }
.chat-msg .msg-bubble {
  display: inline-block; max-width: 80%; padding: .5rem .8rem;
  border-radius: 10px; font-size: .85rem; line-height: 1.5;
}
.chat-msg.user .msg-bubble { background: rgba(99,102,241,.2); color: #c0c0ff; }
.chat-msg.assistant .msg-bubble { background: rgba(34,197,94,.1); color: #b0d0b0; }
.chat-input-row { display: flex; gap: .5rem; }
</style>

<div class="blueprint-panels">
  <div class="doc-panel">
    <h6><i class="bi bi-journal-text me-1"></i>策划文档</h6>
    <textarea id="planning-doc" placeholder="点击下方「AI 生成蓝图」自动填充..."><?= h($planningDoc) ?></textarea>
  </div>
  <div class="doc-panel">
    <h6><i class="bi bi-globe me-1"></i>世界观文档</h6>
    <textarea id="world-doc" placeholder="点击下方「AI 生成蓝图」自动填充..."><?= h($worldDoc) ?></textarea>
  </div>
</div>

<div class="chat-panel">
  <h6 class="mb-2"><i class="bi bi-chat-dots me-1"></i>数字精灵助手</h6>
  <div class="chat-messages" id="chat-messages">
    <?php foreach ($chatHistory as $msg):
      $shown = (string)$msg['content'];
      if ($msg['role'] === 'assistant') {
          $shown = preg_replace('/<<<APPLY:[\s\S]*?<<<END>>>/u', '〔已写入对应文档〕', $shown);
          $shown = preg_replace('/<<<APPLY:[^>]*>>>|<<<END>>>/u', '', $shown);
      }
    ?>
    <div class="chat-msg <?= h($msg['role']) ?>">
      <div class="msg-bubble"><?= nl2br(h($shown)) ?></div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($chatHistory)): ?>
    <div class="text-muted small text-center py-3">
      点击「AI 生成蓝图」开始，或直接输入问题与精灵对话
    </div>
    <?php endif; ?>
  </div>
  <div class="chat-input-row">
    <input type="text" id="chat-input" class="form-control form-control-sm bg-dark text-light border-secondary"
           placeholder="输入问题或修改意见...">
    <button class="btn btn-sm btn-primary" id="btn-chat-send">
      <i class="bi bi-send"></i>
    </button>
  </div>
</div>

<div class="d-flex justify-content-between mt-3 align-items-center">
  <button class="btn btn-outline-info btn-sm" id="btn-generate-blueprint">
    <i class="bi bi-magic me-1"></i>AI 生成蓝图
  </button>
  <div class="d-flex gap-2 align-items-center">
    <span id="autosave-status" class="small text-muted"></span>
    <button class="btn btn-outline-secondary btn-sm" id="btn-save-blueprint">
      <i class="bi bi-save me-1"></i>保存
    </button>
    <button class="btn btn-primary btn-sm" id="btn-next-content">
      保存并进入内容阶段<i class="bi bi-chevron-right ms-1"></i>
    </button>
  </div>
</div>

<script>
(function(){
  var novelId = <?= (int)$novelId ?>;
  var planningEl = document.getElementById('planning-doc');
  var worldEl = document.getElementById('world-doc');
  var statusEl = document.getElementById('autosave-status');
  var generateBtn = document.getElementById('btn-generate-blueprint');
  var streaming = false;

  function scrollChat(){
    var box = document.getElementById('chat-messages');
    if (box) box.scrollTop = box.scrollHeight;
  }
  scrollChat();

  // 过滤聊天显示中的 APPLY 标记块
  function cleanForChat(text){
    return text
      .replace(/<<<APPLY:[\s\S]*?<<<END>>>/g, '〔已写入对应文档〕')
      .replace(/<<<APPLY:[^>]*>>>/g, '')
      .replace(/<<<END>>>/g, '');
  }

  // 从累积文本中提取已完成的 APPLY 块（必须有 END 才算完整）
  function extractApplyBlocks(text){
    var re = /<<<APPLY:([a-zA-Z0-9_:]+)>>>([\s\S]*?)<<<END>>>/g;
    var out = [], m;
    while ((m = re.exec(text)) !== null) {
      out.push({ target: m[1].trim(), content: m[2].trim() });
    }
    return out;
  }

  // 审计 P0：聊天文本（用户输入 / AI 输出 / 服务端错误）一律先做 HTML 转义再写入 innerHTML，
  // 避免 <、>、引号等被当作标签解析造成 XSS。换行转 <br> 在转义之后进行。
  function escHtml(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function addChatMsg(role, text){
    var box = document.getElementById('chat-messages');
    // 清掉 placeholder
    var ph = box.querySelector('.text-muted.text-center');
    if (ph) ph.remove();
    var div = document.createElement('div');
    div.className = 'chat-msg ' + role;
    div.innerHTML = '<div class="msg-bubble">' + escHtml(text || '').replace(/\n/g,'<br>') + '</div>';
    box.appendChild(div);
    scrollChat();
    return div.querySelector('.msg-bubble');
  }

  function sendChat(message){
    if (streaming) return;
    if (!message.trim()) return;
    if (!novelId || novelId <= 0) {
      addChatMsg('assistant', '⚠️ 当前页面没有 novel_id，无法对话。请回到「选题」阶段重新提交，再进入「蓝图」。');
      return;
    }
    addChatMsg('user', message);
    document.getElementById('chat-input').value = '';

    streaming = true;
    if (generateBtn) generateBtn.disabled = true;

    var msgDiv = addChatMsg('assistant', '');
    msgDiv.innerHTML = '<span class="text-muted"><i class="bi bi-hourglass-split"></i> 数字精灵思考中…</span>';

    var fullText = '';
    var rawAccum = '';   // 后端发来的所有原始字节（诊断用）
    var appliedTargets = {};
    var firstChunk = true;
    var contentReceived = false;
    var noChunkTimer = null;

    function render(){
      var blocks = extractApplyBlocks(fullText);
      blocks.forEach(function(b){
        var key = b.target + '|' + b.content.length;
        if (appliedTargets[b.target] === key) return;
        appliedTargets[b.target] = key;
        if (b.target === 'planning' && planningEl) {
          planningEl.value = b.content;
          markDirty(false);
        } else if (b.target === 'world' && worldEl) {
          worldEl.value = b.content;
          markDirty(false);
        }
      });
      msgDiv.innerHTML = escHtml(cleanForChat(fullText)).replace(/\n/g,'<br>') || '…';
      scrollChat();
    }

    function finish(err){
      streaming = false;
      if (noChunkTimer) { clearTimeout(noChunkTimer); noChunkTimer = null; }
      if (generateBtn) generateBtn.disabled = false;
      if (err) {
        msgDiv.innerHTML = '⚠️ ' + escHtml(err);
      } else if (!contentReceived) {
        // 流结束但没收到任何内容 — 把原始响应贴出来便于排查
        var hint = rawAccum.trim();
        if (!hint) hint = '（后端无任何响应。请检查：1) 是否已在【模型设置】中配置可用 AI 模型；2) 服务器 error_log）';
        msgDiv.innerHTML = '⚠️ AI 没有返回内容。<br><pre style="white-space:pre-wrap;max-height:200px;overflow:auto;background:#0a0a18;padding:.5rem;border-radius:6px;color:#ffa;font-size:.75rem;">'
          + hint.replace(/[&<>]/g, function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;'}[c];})
          + '</pre>';
      } else {
        saveBlueprint();
      }
    }

    function resetIdleTimer(){
      if (noChunkTimer) clearTimeout(noChunkTimer);
      noChunkTimer = setTimeout(function(){
        if (streaming && !contentReceived) {
          msgDiv.innerHTML = '<span class="text-muted"><i class="bi bi-hourglass-split"></i> 数字精灵思考中…（已等待 45 秒，AI 仍在响应中，请耐心等待或检查模型配置）</span>';
        }
      }, 45000);
    }
    resetIdleTimer();

    fetch('api/wizard.php?action=chat', {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-CSRF-Token': window.CSRF_TOKEN||''},
      body: JSON.stringify({novel_id: novelId, stage: 'blueprint', message: message})
    })
    .then(function(r){
      if (!r.body || !r.body.getReader) throw new Error('浏览器不支持流式响应');
      var ctype = (r.headers.get('Content-Type') || '').toLowerCase();
      var isSse = ctype.indexOf('text/event-stream') >= 0;

      // 后端如果是 JSON 错误（非 SSE），整段读取
      if (!isSse) {
        return r.text().then(function(txt){
          rawAccum = txt;
          var msg = txt;
          try { var j = JSON.parse(txt); msg = j.error || j.msg || txt; } catch(_){}
          finish('服务端错误（HTTP ' + r.status + '）：' + msg);
        });
      }
      if (!r.ok) throw new Error('HTTP ' + r.status);

      var reader = r.body.getReader();
      var decoder = new TextDecoder('utf-8');
      var buffer = '';

      function processEvent(eventText){
        var dataLines = [];
        eventText.split(/\r?\n/).forEach(function(line){
          if (line.indexOf('data:') === 0) {
            dataLines.push(line.slice(5).replace(/^\s/, ''));
          }
        });
        if (!dataLines.length) return;
        var payload = dataLines.join('\n').trim();
        if (!payload || payload === '[DONE]') return;
        try {
          var d = JSON.parse(payload);
          console.debug('[wizard SSE]', d);
          if (d.error) { finish(d.error); return; }
          if (d.ready || d.done) return;
          if (typeof d.content === 'string' && d.content !== '') {
            if (firstChunk) { msgDiv.innerHTML = ''; firstChunk = false; }
            contentReceived = true;
            fullText += d.content;
            render();
            resetIdleTimer();
          }
          if (d.apply && d.apply.target) {
            if (d.apply.target === 'planning' && planningEl) planningEl.value = d.apply.content;
            if (d.apply.target === 'world' && worldEl) worldEl.value = d.apply.content;
            markDirty(false);
            contentReceived = true;
          }
        } catch(e){
          console.warn('SSE parse failed:', payload, e);
        }
      }

      function read(){
        return reader.read().then(function(result){
          if (result.value) {
            var s = decoder.decode(result.value, {stream: !result.done});
            buffer += s;
            rawAccum += s;
          }
          var parts = buffer.split(/\r?\n\r?\n/);
          buffer = parts.pop();
          parts.forEach(processEvent);
          if (result.done) {
            // flush 解码器尾巴
            var tail = decoder.decode();
            if (tail) { buffer += tail; rawAccum += tail; }
            if (buffer.trim()) processEvent(buffer);
            finish(null);
            return;
          }
          return read();
        });
      }
      return read();
    })
    .catch(function(err){ finish('请求失败：' + err.message); });
  }

  document.getElementById('btn-chat-send').addEventListener('click', function(){
    sendChat(document.getElementById('chat-input').value);
  });
  document.getElementById('chat-input').addEventListener('keydown', function(e){
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendChat(this.value); }
  });

  generateBtn.addEventListener('click', function(){
    sendChat('请同时生成策划文档和世界观文档');
  });

  // ─────────────────────────────────────────
  // 自动保存
  // ─────────────────────────────────────────
  var dirty = false;
  var saving = false;
  var saveTimer = null;
  var lastSnapshot = (planningEl ? planningEl.value : '') + '\0' + (worldEl ? worldEl.value : '');

  function setStatus(text, cls){
    if (!statusEl) return;
    statusEl.className = 'small ' + (cls || 'text-muted');
    statusEl.textContent = text || '';
  }

  function markDirty(isDirty){
    dirty = !!isDirty;
    if (dirty) setStatus('未保存…', 'text-warning');
  }

  function snapshot(){
    return (planningEl ? planningEl.value : '') + '\0' + (worldEl ? worldEl.value : '');
  }

  function saveBlueprint(callback){
    if (saving) { if (callback) setTimeout(function(){ saveBlueprint(callback); }, 400); return; }
    var snap = snapshot();
    if (snap === lastSnapshot && !dirty && !callback) return;
    saving = true;
    setStatus('保存中…', 'text-info');
    fetch('api/wizard.php?action=save_doc', {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-CSRF-Token': window.CSRF_TOKEN||''},
      body: JSON.stringify({
        novel_id: novelId,
        planning_doc: planningEl ? planningEl.value : '',
        world_doc: worldEl ? worldEl.value : ''
      })
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
      saving = false;
      if (data.ok) {
        lastSnapshot = snap;
        dirty = false;
        var t = new Date();
        var hh = String(t.getHours()).padStart(2,'0');
        var mm = String(t.getMinutes()).padStart(2,'0');
        var ss = String(t.getSeconds()).padStart(2,'0');
        setStatus('已自动保存 ' + hh+':'+mm+':'+ss, 'text-success');
        if (callback) callback();
      } else {
        setStatus('保存失败：' + (data.msg || ''), 'text-danger');
      }
    })
    .catch(function(err){
      saving = false;
      setStatus('保存失败：' + err.message, 'text-danger');
    });
  }

  function scheduleAutoSave(){
    markDirty(true);
    if (saveTimer) clearTimeout(saveTimer);
    saveTimer = setTimeout(function(){ saveBlueprint(); }, 1500);
  }

  if (planningEl) planningEl.addEventListener('input', scheduleAutoSave);
  if (worldEl) worldEl.addEventListener('input', scheduleAutoSave);

  // 每 30 秒兜底保存一次（防止用户长时间不动但有未保存）
  setInterval(function(){
    if (dirty && !saving) saveBlueprint();
  }, 30000);

  // 切换/隐藏页面时立即保存（不依赖 beforeunload，移动端更可靠）
  document.addEventListener('visibilitychange', function(){
    if (document.visibilityState === 'hidden' && dirty && !saving) {
      try {
        fetch('api/wizard.php?action=save_doc', {
          method: 'POST',
          keepalive: true,
          headers: {'Content-Type':'application/json','X-CSRF-Token': window.CSRF_TOKEN||''},
          body: JSON.stringify({
            novel_id: novelId,
            planning_doc: planningEl ? planningEl.value : '',
            world_doc: worldEl ? worldEl.value : ''
          })
        });
      } catch(_){}
    }
  });

  // 关闭/刷新前给个二次确认
  window.addEventListener('beforeunload', function(e){
    if (dirty) {
      e.preventDefault();
      e.returnValue = '蓝图尚未保存完成，确定离开吗？';
      return e.returnValue;
    }
  });

  document.getElementById('btn-save-blueprint').addEventListener('click', function(){
    saveBlueprint(function(){ setStatus('已保存', 'text-success'); });
  });

  document.getElementById('btn-next-content').addEventListener('click', function(){
    saveBlueprint(function(){
      fetch('api/wizard.php?action=save_stage', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-CSRF-Token': window.CSRF_TOKEN||''},
        body: JSON.stringify({novel_id: novelId, stage: 'blueprint'})
      })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (data.ok) location.href = 'novel_wizard.php?id=' + novelId + '&stage=content';
        else alert(data.msg || '操作失败');
      });
    });
  });
})();
</script>
