<?php
/**
 * Stage 1 — 选题
 *
 * 纯表单。下一步触发蓝图阶段。
 * 包含三组核心设定：结构与视角、风格与时代、主角与冲突
 */
defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/schema.php';
$lib = wizardTagLibrary();

$cur = [
    'title'   => $novel['title']   ?? '',
    'genre'   => $novel['genre']   ?? '',
    'idea'    => $novel['extra_settings'] ?? $novel['description'] ?? '',
    'target'  => (int)($novel['target_chapters'] ?? 100),
    'words'   => (int)($novel['chapter_word_target'] ?? 2500),
];

$genres = ['玄幻','奇幻','都市','言情','武侠','科幻','悬疑','历史','游戏','体育','军事','灵异','轻小说','二次元','其他'];

function decodeListField($novel, $key) {
    $v = $novel[$key] ?? null;
    if ($v === null || $v === '') return [];
    if (is_array($v)) return $v;
    $arr = json_decode($v, true);
    return is_array($arr) ? $arr : [];
}

$topicTagKeys = [
    '结构与视角' => ['narrative_structure', 'narrative_method', 'narrative_pov'],
    '风格与时代' => ['literary_genre', 'world_setting_era'],
    '主角与冲突' => ['protagonist_traits', 'core_conflicts'],
];
$advancedTagKeys = [
    '类型与文风' => ['novel_types', 'writing_tone'],
    '爽点与禁忌' => ['appeal_points', 'taboos'],
    '开篇设计'   => ['opening_type', 'protagonist_entrance'],
];

$presets = function_exists('wizardPresetPacks') ? wizardPresetPacks() : [];
?>

<style>
.topic-section { margin-bottom: 1.5rem; }
.topic-section-title {
  font-weight: 600; color: #c0c0e0; font-size: .95rem;
  margin-bottom: .75rem; padding-bottom: .4rem;
  border-bottom: 1px solid #2d2d4e;
}
.tag-row { margin-bottom: .85rem; }
.tag-label { font-size: .85rem; color: #a0a0c0; margin-bottom: .35rem; display: block; }
.tag-options { display: flex; flex-wrap: wrap; gap: .4rem; align-items: center; }
.opt-chip {
  display: inline-flex; align-items: center; cursor: pointer;
}
.opt-chip input { display: none; }
.opt-chip span {
  background: #1e1e3a; border: 1px solid #3a3a5e; border-radius: 8px;
  padding: .25rem .65rem; font-size: .8rem; color: #b0b0d0;
  transition: all .15s;
}
.opt-chip input:checked + span {
  background: rgba(99,102,241,.25); border-color: #6366f1; color: #e0e0ff;
}
.opt-chip:hover span { border-color: #6366f1; }
.custom-chip { font-style: italic; }
.preset-bar { display: flex; flex-wrap: wrap; gap: .5rem; }
.preset-btn {
  background: #1e1e3a; border: 1px solid #3a3a5e; border-radius: 10px;
  padding: .4rem .8rem; font-size: .82rem; color: #c0c0e0;
  cursor: pointer; transition: all .15s;
}
.preset-btn:hover { border-color: #6366f1; background: rgba(99,102,241,.15); }
</style>

<form id="wizard-topic-form" autocomplete="off">
  <input type="hidden" name="novel_id" value="<?= (int)$novelId ?>">

  <div class="topic-section">
    <div class="topic-section-title">基础信息</div>

    <div class="mb-3">
      <label class="form-label text-light fw-semibold">作品名 <span class="text-danger">*</span></label>
      <input type="text" name="title" class="form-control bg-dark text-light border-secondary"
             value="<?= h($cur['title']) ?>" maxlength="80" required
             placeholder="先取个工作名，后面随时改">
    </div>

    <div class="mb-3">
      <label class="form-label text-light fw-semibold">类型 <span class="text-danger">*</span></label>
      <?php
        $genreInList = in_array($cur['genre'], $genres, true);
        $isCustomGenre = (!$genreInList && $cur['genre'] !== '');
      ?>
      <div class="d-flex flex-wrap gap-2 align-items-center" id="genre-chips">
        <?php foreach ($genres as $g): ?>
        <label class="opt-chip">
          <input type="radio" name="genre" value="<?= h($g) ?>" <?= $cur['genre'] === $g ? 'checked' : '' ?>>
          <span><?= h($g) ?></span>
        </label>
        <?php endforeach; ?>
        <label class="opt-chip">
          <input type="radio" name="genre" value="__custom__" id="genre-custom-radio"
                 <?= $isCustomGenre ? 'checked' : '' ?>>
          <span class="custom-chip"><i class="bi bi-pencil"></i> 自定义</span>
        </label>
        <input type="text" id="genre-custom-input"
               class="form-control form-control-sm bg-dark text-light border-secondary"
               placeholder="输入自定义类型..." maxlength="40"
               value="<?= $isCustomGenre ? h($cur['genre']) : '' ?>"
               style="max-width:200px;<?= $isCustomGenre ? '' : 'display:none;' ?>">
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <label class="form-label text-light fw-semibold">目标章节数 <span class="text-danger">*</span></label>
        <input type="number" name="target_chapters"
               class="form-control bg-dark text-light border-secondary"
               value="<?= $cur['target'] > 0 ? $cur['target'] : 100 ?>"
               min="10" max="2000" required>
        <div class="small text-muted mt-1">小说总章节数（10-2000）</div>
      </div>
      <div class="col-md-6">
        <label class="form-label text-light fw-semibold">单章目标字数</label>
        <input type="number" name="chapter_word_target"
               class="form-control bg-dark text-light border-secondary"
               value="<?= $cur['words'] > 0 ? $cur['words'] : 2500 ?>"
               min="800" max="10000">
        <div class="small text-muted mt-1">默认 2500（800-10000）</div>
      </div>
    </div>

    <div class="mb-2">
      <label class="form-label text-light fw-semibold">核心想法 / 一句话简介 <span class="text-danger">*</span></label>
      <textarea name="idea" class="form-control bg-dark text-light border-secondary" rows="6"
                maxlength="2000" required
                placeholder="详细描述：主角是谁、核心冲突、独特卖点、想要的氛围...&#10;&#10;例：一个不会魔法的农场少年偶然继承了灭世魔王的遗志，在被全世界追杀的途中收集失落的契约碎片..."><?= h($cur['idea']) ?></textarea>
      <div class="d-flex justify-content-between mt-1">
        <small class="text-muted">⚡ 写得越具体，AI 给的策划越有针对性</small>
        <small class="text-muted"><span id="idea-count">0</span>/2000</small>
      </div>
    </div>
  </div>

  <?php foreach ($topicTagKeys as $groupName => $keys): ?>
  <div class="topic-section">
    <div class="topic-section-title"><?= h($groupName) ?></div>
    <?php foreach ($keys as $k):
      $cfg = $lib[$k]; $multi = !empty($cfg['multi']);
      $current = decodeListField($novel, $k);
      $singleCurrent = ($multi || empty($novel[$k])) ? '' : (string)$novel[$k];
      $allowCustom = !empty($cfg['allow_custom']);
    ?>
    <div class="tag-row" data-key="<?= h($k) ?>" data-multi="<?= $multi ? '1' : '0' ?>">
      <label class="tag-label">
        <?= h($cfg['label']) ?>
        <span class="text-muted small">（<?= $multi ? '多选' : '单选' ?>）</span>
      </label>
      <div class="tag-options">
        <?php foreach ($cfg['options'] as $opt):
          $isOn = $multi ? in_array($opt, $current, true) : ($singleCurrent === $opt);
        ?>
        <label class="opt-chip">
          <input type="<?= $multi ? 'checkbox' : 'radio' ?>"
                 name="<?= h($k) ?><?= $multi ? '[]' : '' ?>"
                 value="<?= h($opt) ?>" <?= $isOn ? 'checked' : '' ?>>
          <span><?= h($opt) ?></span>
        </label>
        <?php endforeach; ?>
        <?php if ($allowCustom):
          $customValues = '';
          $hasCustomCheck = false;
          if ($multi) {
              $extras = array_diff($current, $cfg['options']);
              if (!empty($extras)) { $customValues = implode('、', $extras); $hasCustomCheck = true; }
          } else {
              if ($singleCurrent && !in_array($singleCurrent, $cfg['options'], true)) {
                  $customValues = $singleCurrent; $hasCustomCheck = true;
              }
          }
        ?>
        <label class="opt-chip">
          <input type="<?= $multi ? 'checkbox' : 'radio' ?>"
                 name="<?= h($k) ?><?= $multi ? '[]' : '' ?>"
                 value="__custom__" <?= $hasCustomCheck ? 'checked' : '' ?>>
          <span class="custom-chip"><i class="bi bi-pencil"></i> 自定义</span>
        </label>
        <input type="text" class="form-control form-control-sm custom-input bg-dark text-light border-secondary"
               name="<?= h($k) ?>_custom"
               placeholder="<?= $multi ? '多个用顿号分隔' : '输入自定义值...' ?>"
               value="<?= h($customValues) ?>"
               style="<?= $hasCustomCheck ? '' : 'display:none;' ?>max-width:260px;margin-left:.5rem;">
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>

  <div class="topic-section" id="advanced-section">
    <div class="topic-section-title d-flex justify-content-between align-items-center"
         style="cursor:pointer;user-select:none;" id="advanced-toggle">
      <span><i class="bi bi-chevron-right me-1" id="advanced-chevron"></i>进阶设定（可选）
        <small class="text-muted ms-2" style="font-weight:normal;">类型/文风/爽点/禁忌/开篇</small>
      </span>
      <small class="text-muted">点击展开</small>
    </div>

    <div id="advanced-body" style="display:none;">
      <?php if (!empty($presets)): ?>
      <div class="preset-bar mb-3">
        <span class="text-muted small me-2">快速预设：</span>
        <?php foreach ($presets as $pk => $pv): ?>
        <button type="button" class="preset-btn" data-preset="<?= h($pk) ?>"><?= h($pv['name']) ?></button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php foreach ($advancedTagKeys as $groupName => $keys): ?>
      <div class="mb-3">
        <div class="small fw-semibold text-muted mb-2"><?= h($groupName) ?></div>
        <?php foreach ($keys as $k):
          $cfg = $lib[$k]; $multi = !empty($cfg['multi']);
          $current = decodeListField($novel, $k);
          $singleCurrent = ($multi || empty($novel[$k])) ? '' : (string)$novel[$k];
          $allowCustom = !empty($cfg['allow_custom']);
        ?>
        <div class="tag-row" data-key="<?= h($k) ?>" data-multi="<?= $multi ? '1' : '0' ?>">
          <label class="tag-label"><?= h($cfg['label']) ?> <span class="text-muted small">（<?= $multi ? '多选' : '单选' ?>）</span></label>
          <div class="tag-options">
            <?php foreach ($cfg['options'] as $opt):
              $isOn = $multi ? in_array($opt, $current, true) : ($singleCurrent === $opt);
            ?>
            <label class="opt-chip">
              <input type="<?= $multi ? 'checkbox' : 'radio' ?>"
                     name="<?= h($k) ?><?= $multi ? '[]' : '' ?>"
                     value="<?= h($opt) ?>" <?= $isOn ? 'checked' : '' ?>>
              <span><?= h($opt) ?></span>
            </label>
            <?php endforeach; ?>
            <?php if ($allowCustom): ?>
            <label class="opt-chip">
              <input type="<?= $multi ? 'checkbox' : 'radio' ?>"
                     name="<?= h($k) ?><?= $multi ? '[]' : '' ?>"
                     value="__custom__">
              <span class="custom-chip"><i class="bi bi-pencil"></i> 自定义</span>
            </label>
            <input type="text" class="form-control form-control-sm custom-input bg-dark text-light border-secondary"
                   name="<?= h($k) ?>_custom"
                   placeholder="<?= $multi ? '多个用顿号分隔' : '输入自定义值...' ?>"
                   style="display:none;max-width:260px;margin-left:.5rem;">
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>

      <div class="mb-3">
        <label class="form-label text-light">自定义设定</label>
        <textarea name="custom_settings" class="form-control bg-dark text-light border-secondary" rows="3"
                  maxlength="2000"
                  placeholder="其他补充要求..."><?= h($novel['custom_settings'] ?? '') ?></textarea>
      </div>
    </div>
  </div>

  <div class="d-flex justify-content-end gap-2 mt-3 align-items-center">
    <span id="topic-autosave-status" class="small text-muted"></span>
    <button type="submit" class="btn btn-primary">
      <i class="bi bi-check-lg me-1"></i>保存并进入蓝图阶段
    </button>
  </div>
</form>

<script>
(function(){
  var ideaEl = document.querySelector('textarea[name=idea]');
  var countEl = document.getElementById('idea-count');
  if (ideaEl && countEl) {
    function updateCount(){ countEl.textContent = ideaEl.value.length; }
    ideaEl.addEventListener('input', updateCount);
    updateCount();
  }

  var customRadio = document.getElementById('genre-custom-radio');
  var customInput = document.getElementById('genre-custom-input');
  if (customRadio && customInput) {
    document.querySelectorAll('input[name=genre]').forEach(function(r){
      r.addEventListener('change', function(){
        customInput.style.display = this.value === '__custom__' ? '' : 'none';
      });
    });
  }

  document.querySelectorAll('.custom-input').forEach(function(inp){
    var chip = inp.previousElementSibling;
    if (chip) {
      var cb = chip.querySelector('input');
      if (cb) {
        cb.addEventListener('change', function(){ inp.style.display = this.checked ? '' : 'none'; });
      }
    }
  });

  var advToggle = document.getElementById('advanced-toggle');
  var advBody = document.getElementById('advanced-body');
  var advChevron = document.getElementById('advanced-chevron');
  if (advToggle && advBody) {
    advToggle.addEventListener('click', function(){
      var open = advBody.style.display !== 'none';
      advBody.style.display = open ? 'none' : '';
      if (advChevron) advChevron.className = open ? 'bi bi-chevron-right me-1' : 'bi bi-chevron-down me-1';
    });
  }

  var presets = <?= json_encode($presets, JSON_UNESCAPED_UNICODE) ?>;
  document.querySelectorAll('.preset-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var key = this.dataset.preset;
      var p = presets[key];
      if (!p || !p.tags) return;
      Object.keys(p.tags).forEach(function(field){
        var vals = Array.isArray(p.tags[field]) ? p.tags[field] : [p.tags[field]];
        var inputs = document.querySelectorAll('[name="'+field+'"], [name="'+field+'[]"]');
        inputs.forEach(function(inp){
          if (inp.type === 'radio') {
            inp.checked = vals.includes(inp.value);
          } else if (inp.type === 'checkbox') {
            inp.checked = vals.includes(inp.value);
          }
        });
      });
      scheduleDraft(); // 程序改选中态不触发 change，手动标脏
    });
  });

  var form = document.getElementById('wizard-topic-form');
  var novelId = <?= (int)$novelId ?>;

  // 收集整张表单 → JSON 对象（提交 / 草稿自动保存共用）。
  // 多选组 name="key[]" 聚合成数组；全不勾也带空数组，否则后端无法保存“清空”。
  function collectTopicData(){
    var fd = new FormData(form);
    var genre = fd.get('genre');
    if (genre === '__custom__') {
      var custom = document.getElementById('genre-custom-input');
      if (custom && custom.value.trim()) fd.set('genre', custom.value.trim());
    }
    document.querySelectorAll('.custom-input').forEach(function(inp){
      if (inp.style.display === 'none') return;
      var chipInp = inp.previousElementSibling ? inp.previousElementSibling.querySelector('input') : null;
      if (chipInp && chipInp.checked && inp.value.trim()) {
        var name = inp.name.replace('_custom','');
        fd.append(name + '_custom_value', inp.value.trim());
      }
    });
    var obj = {};
    fd.forEach(function(v,k){
      if (k.slice(-2) === '[]') {
        var key = k.slice(0,-2);
        if (!Array.isArray(obj[key])) obj[key] = [];
        obj[key].push(v);
      } else {
        obj[k] = v;
      }
    });
    document.querySelectorAll('.tag-row[data-multi="1"]').forEach(function(row){
      var key = row.dataset.key;
      if (key && !Array.isArray(obj[key])) obj[key] = [];
    });
    obj.novel_id = novelId;
    return obj;
  }

  // ── 草稿自动保存：中途退出不丢进度 ──
  var draftStatus = document.getElementById('topic-autosave-status');
  var draftDirty = false, draftSaving = false, draftTimer = null, submitting = false;
  var lastDraftSnap = form ? JSON.stringify(collectTopicData()) : '';

  function setDraftStatus(text, cls){
    if (!draftStatus) return;
    draftStatus.className = 'small ' + (cls || 'text-muted');
    draftStatus.textContent = text || '';
  }

  function adoptNovelId(nid){
    if (!nid || novelId > 0) return;
    novelId = nid;
    var hidden = form.querySelector('input[name=novel_id]');
    if (hidden) hidden.value = nid;
    try { history.replaceState(null, '', 'novel_wizard.php?id=' + nid); } catch(_){}
    lastDraftSnap = JSON.stringify(collectTopicData()); // novel_id 变了，刷新快照基线
  }

  function saveDraft(useBeacon){
    if (submitting) return;
    var data = collectTopicData();
    var snap = JSON.stringify(data);
    if (snap === lastDraftSnap) { draftDirty = false; return; }
    if (useBeacon) {
      // 页面即将隐藏/关闭：keepalive 请求不等响应。还没拿到 novel_id 且已有请求
      // 在途时跳过，避免并发创建两条草稿。
      if (draftSaving && novelId <= 0) return;
      try {
        fetch(apiRouteUrl('wizard', 'action=save_topic_draft'), {
          method: 'POST', keepalive: true,
          headers: {'Content-Type':'application/json','X-CSRF-Token': window.CSRF_TOKEN||''},
          body: snap
        });
      } catch(_){}
      lastDraftSnap = snap; draftDirty = false;
      return;
    }
    if (draftSaving) return;
    draftSaving = true;
    setDraftStatus('草稿保存中…', 'text-info');
    fetch(apiRouteUrl('wizard', 'action=save_topic_draft'), {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-CSRF-Token': window.CSRF_TOKEN||''},
      body: snap
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
      draftSaving = false;
      if (d && d.ok) {
        lastDraftSnap = snap; draftDirty = false;
        if (d.novel_id > 0) adoptNovelId(d.novel_id);
        if (d.skipped) { setDraftStatus(''); return; }
        var t = new Date();
        var pad = function(n){ return String(n).padStart(2,'0'); };
        setDraftStatus('草稿已保存 ' + pad(t.getHours()) + ':' + pad(t.getMinutes()) + ':' + pad(t.getSeconds()), 'text-success');
      } else {
        var em = (d && (d.msg || d.error)) || '';
        if (d && (d.error === 'session_expired' || /未登录|登录已过期|请先登录/.test(em))) {
          // 会话失效：后台自动保存不强制跳转（避免打断编辑），提示用户重新登录。
          setDraftStatus('登录状态已失效，请在新标签页重新登录后回来继续（不会丢失已保存草稿）', 'text-danger');
        } else {
          setDraftStatus('草稿保存失败：' + em, 'text-danger');
        }
      }
    })
    .catch(function(){
      draftSaving = false;
      setDraftStatus('草稿保存失败（网络错误）', 'text-danger');
    });
  }

  function scheduleDraft(){
    if (submitting) return;
    draftDirty = true;
    setDraftStatus('未保存…', 'text-warning');
    if (draftTimer) clearTimeout(draftTimer);
    draftTimer = setTimeout(function(){ saveDraft(false); }, 1500); // 停顿 1.5s 自动存
  }

  if (form) {
    form.addEventListener('input', scheduleDraft);
    form.addEventListener('change', scheduleDraft);
    setInterval(function(){ if (draftDirty && !draftSaving) saveDraft(false); }, 30000); // 30s 兜底
    document.addEventListener('visibilitychange', function(){
      if (document.visibilityState === 'hidden' && draftDirty) saveDraft(true);
    });
    window.addEventListener('beforeunload', function(){ if (draftDirty) saveDraft(true); });

    form.addEventListener('submit', function(e){
      e.preventDefault();
      submitting = true;
      if (draftTimer) clearTimeout(draftTimer);

      fetch(apiRouteUrl('wizard', 'action=save_topic'), {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-CSRF-Token': window.CSRF_TOKEN||''},
        body: JSON.stringify(collectTopicData())
      })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (data.ok) {
          var nid = data.novel_id || novelId;
          draftDirty = false; // 已正式保存，退出时不再触发草稿请求
          location.href = 'novel_wizard.php?id=' + nid + '&stage=blueprint';
        } else {
          submitting = false;
          var m = (data && (data.msg || data.error)) || '';
          // 会话失效（轮换竞态已修；此处兜底 idle/absolute 超时）：引导重新登录。
          // 草稿在编辑过程中已自动保存到服务端，重新登录后回到向导可继续。
          if (data && (data.error === 'session_expired' || /未登录|登录已过期|请先登录/.test(m))) {
            alert('登录状态已失效，请重新登录后继续（草稿已自动保存，登录后可接着编辑）。');
            location.href = 'login.php';
            return;
          }
          alert(m || '保存失败');
        }
      })
      .catch(function(err){ submitting = false; alert('网络错误：' + err.message); });
    });
  }
})();
</script>
