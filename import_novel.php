<?php
/**
 * 导入小说续写模式
 *
 * 5 步流程：选择文件 → 预览数据 → 导入中 → AI分析 → 完成
 * Excel 在浏览器本地解析（SheetJS），分批上传到服务器
 */
define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';

$csrfToken = function_exists('csrf_token') ? csrf_token() : '';
pageHeader('导入小说续写', 'create');
?>

<script>
window.__xlsxReady = new Promise(function(resolve, reject){
  var srcs = [
    'assets/dist/xlsx.full.min.js',
    'https://cdn.jsdelivr.net/npm/xlsx@0.20.3/dist/xlsx.full.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.20.3/xlsx.full.min.js',
    'https://unpkg.com/xlsx@0.20.3/dist/xlsx.full.min.js',
  ];
  var i = 0;
  function tryNext() {
    if (typeof XLSX !== 'undefined') { resolve(XLSX); return; }
    if (i >= srcs.length) { reject(new Error('SheetJS 加载失败，请检查网络')); return; }
    var s = document.createElement('script');
    s.src = srcs[i++];
    s.onload = function(){ if (typeof XLSX !== 'undefined') resolve(XLSX); else tryNext(); };
    s.onerror = function(){ tryNext(); };
    document.head.appendChild(s);
  }
  tryNext();
});
</script>

<style>
.import-wrap { max-width: 860px; margin: 0 auto; padding: 1.5rem 1rem; }
.step-bar { display: flex; gap: 0; margin-bottom: 2rem; }
.step-item { flex: 1; text-align: center; position: relative; }
.step-item::before {
  content: ''; position: absolute; top: 20px; left: 50%; right: -50%;
  height: 2px; background: #2d2d4e; z-index: 0;
}
.step-item:last-child::before { display: none; }
.step-dot {
  width: 40px; height: 40px; border-radius: 50%; border: 2px solid #2d2d4e;
  background: #0f0f1a; display: inline-flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: .9rem; position: relative; z-index: 1; transition: all .3s;
}
.step-item.done .step-dot { background: #22c55e; border-color: #22c55e; color: #fff; }
.step-item.active .step-dot { background: #6366f1; border-color: #6366f1; color: #fff; }
.step-label { font-size: .75rem; color: #a0a0c0; margin-top: .3rem; }

.drop-zone {
  border: 2px dashed #3b3b6e; border-radius: 16px; padding: 3rem 1rem;
  text-align: center; cursor: pointer; transition: all .25s; background: #12122a;
}
.drop-zone:hover, .drop-zone.drag-over { border-color: #6366f1; background: rgba(99,102,241,.08); }
.drop-zone .icon { font-size: 3rem; color: #6366f1; opacity: .7; }

.preview-table { font-size: .8rem; }
.preview-table th { background: #1e1e3a; position: sticky; top: 0; }
.preview-table td { max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; vertical-align: middle; }

.batch-log { max-height: 160px; overflow-y: auto; font-size: .78rem; font-family: monospace; background: #0a0a15; border-radius: 8px; padding: .75rem; }

.task-card {
  border: 1px solid #2d2d4e; border-radius: 12px; padding: 1rem;
  cursor: pointer; transition: all .2s; user-select: none;
}
.task-card:hover { border-color: #6366f1; }
.task-card.selected { border-color: #6366f1; background: rgba(99,102,241,.08); }
.task-card.disabled { opacity: .4; pointer-events: none; }
</style>

<div class="import-wrap">

<div class="step-bar" id="stepBar">
  <div class="step-item active" id="step1"><div class="step-dot">1</div><div class="step-label">选择文件</div></div>
  <div class="step-item"        id="step2"><div class="step-dot">2</div><div class="step-label">预览数据</div></div>
  <div class="step-item"        id="step3"><div class="step-dot">3</div><div class="step-label">导入中</div></div>
  <div class="step-item"        id="step4"><div class="step-dot">4</div><div class="step-label">AI分析</div></div>
  <div class="step-item"        id="step5"><div class="step-dot">5</div><div class="step-label">完成</div></div>
</div>

<div id="panelStep1">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-file-earmark-excel text-success me-2"></i>导入 Excel 小说</h4>
    <a href="api/<?= rawurlencode('小说导入模板.xlsx') ?>" download="小说导入模板.xlsx" class="btn btn-outline-success btn-sm">
      <i class="bi bi-download me-1"></i>下载模板
    </a>
  </div>

  <div class="alert alert-info small mb-3 p-2">
    <i class="bi bi-info-circle me-1"></i>
    支持 <strong>.xlsx / .xls</strong> 格式 · 文件在浏览器本地解析，不会整体上传 ·
    大文件自动分批，支持断点续传 ·
    <strong>首次使用建议先下载模板</strong>
  </div>

  <div class="drop-zone" id="dropZone">
    <div class="icon"><i class="bi bi-cloud-upload"></i></div>
    <div class="mt-2 fw-bold">拖放 Excel 文件到这里</div>
    <div class="text-muted small mt-1">或点击选择文件</div>
    <input type="file" id="fileInput" accept=".xlsx,.xls" style="display:none">
  </div>

  <div class="text-center mt-3 text-muted small">
    Excel 格式：Sheet1 章节内容（章节号/标题/正文/大纲/节奏/状态），Sheet2 小说信息，Sheet3 人物卡（选填）
  </div>

  <div id="step1Progress" class="mt-3" style="display:none;">
    <div class="text-muted small mb-1" id="step1ProgressMsg">正在解析 Excel…</div>
    <div class="progress" style="height:6px;"><div class="progress-bar bg-info progress-bar-striped progress-bar-animated" style="width:100%"></div></div>
  </div>
</div>

<div id="panelStep2" style="display:none;">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-table text-info me-2"></i>数据预览</h4>
    <button class="btn btn-sm btn-outline-secondary" id="btnReselect"><i class="bi bi-arrow-left me-1"></i>重新选择</button>
  </div>

  <div class="card bg-dark border-secondary mb-3">
    <div class="card-header py-2 small fw-bold"><i class="bi bi-book me-1"></i>小说信息</div>
    <div class="card-body py-2" id="novelMetaPreview">
      <div class="text-muted small">未检测到小说信息，将使用默认值</div>
    </div>
  </div>

  <div class="card bg-dark border-secondary mb-3">
    <div class="card-header py-2 d-flex justify-content-between align-items-center">
      <span class="small fw-bold"><i class="bi bi-list-ol me-1"></i>章节内容（前5章预览）</span>
      <span id="chapterCountBadge" class="badge bg-primary">0 章</span>
    </div>
    <div class="card-body p-0" style="overflow-x:auto;max-height:240px;overflow-y:auto;">
      <table class="table table-dark table-hover preview-table mb-0">
        <thead><tr><th>#</th><th>标题</th><th>正文（前60字）</th><th>大纲</th><th>节奏</th><th>状态</th></tr></thead>
        <tbody id="chapterPreviewBody"></tbody>
      </table>
    </div>
  </div>

  <div class="card bg-dark border-secondary mb-3" id="charPreviewCard" style="display:none;">
    <div class="card-header py-2 d-flex justify-content-between align-items-center">
      <span class="small fw-bold"><i class="bi bi-people me-1"></i>人物卡</span>
      <span id="charCountBadge" class="badge bg-secondary">0 人</span>
    </div>
    <div class="card-body p-0" style="overflow-x:auto;max-height:160px;overflow-y:auto;">
      <table class="table table-dark table-hover preview-table mb-0">
        <thead><tr><th>姓名</th><th>定位</th><th>性别</th><th>描述</th></tr></thead>
        <tbody id="charPreviewBody"></tbody>
      </table>
    </div>
  </div>

  <div class="text-end">
    <button class="btn btn-primary px-4" id="btnStartImport">
      <i class="bi bi-play-fill me-1"></i>开始导入
    </button>
  </div>
</div>

<div id="panelStep3" style="display:none;">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-gear-wide-connected text-warning me-2"></i>正在导入</h4>
    <span id="importStatusBadge" class="badge bg-warning text-dark">导入中…</span>
  </div>
  <div class="mb-3">
    <div class="d-flex justify-content-between small text-muted mb-1">
      <span id="importProgressText">0 / 0 章</span>
      <span id="importBatchText">批次 0/0</span>
    </div>
    <div class="progress mb-2" style="height:10px;">
      <div class="progress-bar bg-success progress-bar-striped progress-bar-animated" id="importProgressBar" style="width:0%"></div>
    </div>
    <div class="d-flex gap-3 small text-muted">
      <span>已导入：<strong id="importedWords">0</strong> 字</span>
      <span id="resumeHint" class="text-info" style="display:none;"><i class="bi bi-arrow-repeat me-1"></i>断点续传中</span>
    </div>
  </div>
  <div class="batch-log" id="batchLog">等待导入开始…</div>
</div>

<div id="panelStep4" style="display:none;">
  <div class="mb-3">
    <h4 class="mb-1"><i class="bi bi-stars text-primary me-2"></i>导入完成！选择 AI 分析项目</h4>
    <p class="text-muted small mb-0">AI 分析是<strong class="text-warning">可选项</strong>，也可以跳过，之后在小说设置中手动触发。</p>
  </div>

  <div class="row g-2 mb-3" id="importStats">
    <div class="col-6">
      <div class="border border-secondary rounded p-2 text-center">
        <div class="fs-4 fw-bold text-success" id="statChapters">0</div>
        <div class="small text-muted">章</div>
      </div>
    </div>
    <div class="col-6">
      <div class="border border-secondary rounded p-2 text-center">
        <div class="fs-4 fw-bold text-info" id="statWords">0</div>
        <div class="small text-muted">字</div>
      </div>
    </div>
  </div>

  <div class="d-flex flex-column gap-2 mb-3" id="taskCards">
    <div class="task-card selected" data-task="story_outline" id="taskStoryOutline">
      <div class="fw-bold small"><i class="bi bi-diagram-3 text-primary me-1"></i>生成全书故事大纲</div>
      <div class="text-muted small mt-1">分析所有章节脉络，生成人物弧线/世界演变/故事主线</div>
    </div>
    <div class="task-card" data-task="extract_chars" id="taskExtractChars">
      <div class="fw-bold small"><i class="bi bi-person-badge text-info me-1"></i>从正文提取人物卡</div>
      <div class="text-muted small mt-1">识别主要角色，自动填充姓名/性格/背景</div>
    </div>
    <div class="task-card" data-task="fill_outlines" id="taskFillOutlines">
      <div class="fw-bold small"><i class="bi bi-pencil-square text-warning me-1"></i>补全缺失章节大纲</div>
      <div class="text-muted small mt-1">为没有大纲的章节自动生成摘要</div>
    </div>
  </div>

  <div class="d-flex gap-2 justify-content-end">
    <button class="btn btn-outline-secondary" id="btnSkipAnalysis">跳过，直接进入书库</button>
    <button class="btn btn-primary" id="btnStartAnalysis"><i class="bi bi-stars me-1"></i>确认分析</button>
  </div>
</div>

<div id="panelStep5" style="display:none;" class="text-center py-4">
  <div style="font-size:4rem;">🎉</div>
  <h4 class="mt-2">导入完成！</h4>
  <p class="text-muted" id="doneMsg">小说已成功导入书库，可以开始续写了。</p>
  <div class="d-flex gap-2 justify-content-center mt-3">
    <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-house me-1"></i>返回书库</a>
    <a href="#" class="btn btn-primary" id="btnGoNovel"><i class="bi bi-pencil me-1"></i>进入小说</a>
  </div>
</div>

</div>

<script>
const CSRF_TOKEN = window.CSRF_TOKEN || '';
const BATCH_SIZE = 50;
// 本页脚本位于 pageFooter/app.js 之前，使用与 apiRouteUrl 等价的相对入口，
// 既支持子目录部署，也避免初始化时 helper 尚未加载。
const API = 'api/index.php?route=import_novel_excel';

let parsedData = { novelMeta: {}, chapters: [], characters: [] };
let importState = {
  sessionKey: '', novelId: 0, totalBatches: 0,
  completedBatches: [], importedChapters: 0, totalWords: 0, finalizeData: null,
};

function esc(s){ return (s||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]); }
function fmtNum(n){ return Number(n).toLocaleString('zh-CN'); }
function log(msg, type='info') {
  const el = document.getElementById('batchLog');
  const color = type==='ok'?'#22c55e': type==='err'?'#ef4444': '#a0a0c0';
  el.innerHTML += `<div style="color:${color}">[${new Date().toLocaleTimeString()}] ${esc(msg)}</div>`;
  el.scrollTop = el.scrollHeight;
}

async function post(action, body, timeoutMs = 60000) {
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), timeoutMs);
  let res;
  try {
    res = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type':'application/json', 'X-CSRF-Token': CSRF_TOKEN },
      body: JSON.stringify({ action, ...body }),
      signal: ctrl.signal,
    });
  } catch (e) {
    clearTimeout(t);
    if (e.name === 'AbortError') throw new Error('请求超时');
    throw new Error('网络异常：' + e.message);
  }
  clearTimeout(t);
  const text = await res.text();
  try { return JSON.parse(text); }
  catch (e) {
    const snippet = text ? text.replace(/<[^>]+>/g,'').slice(0,200) : '';
    throw new Error('HTTP ' + res.status + (snippet ? ' · ' + snippet : ''));
  }
}

let currentStep = 1;
function gotoStep(n) {
  currentStep = n;
  for (let i = 1; i <= 5; i++) {
    const p = document.getElementById('panelStep' + i);
    const s = document.getElementById('step' + i);
    if (p) p.style.display = (i === n) ? '' : 'none';
    if (s) {
      s.classList.remove('active','done');
      if (i < n) s.classList.add('done');
      else if (i === n) s.classList.add('active');
    }
  }
}

const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');

dropZone.addEventListener('click', () => fileInput.click());
dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
dropZone.addEventListener('drop', e => {
  e.preventDefault(); dropZone.classList.remove('drag-over');
  const f = e.dataTransfer.files[0];
  if (f) parseExcel(f);
});
fileInput.addEventListener('change', () => { if (fileInput.files[0]) parseExcel(fileInput.files[0]); });

async function parseExcel(file) {
  const progWrap = document.getElementById('step1Progress');
  const progMsg  = document.getElementById('step1ProgressMsg');
  progWrap.style.display = '';
  progMsg.textContent = '正在加载 Excel 解析器…';

  try {
    await Promise.race([
      window.__xlsxReady,
      new Promise((_, rej) => setTimeout(() => rej(new Error('SheetJS 加载超时')), 15000))
    ]);
    if (typeof XLSX === 'undefined') throw new Error('XLSX 未就绪');

    progMsg.textContent = '正在读取 ' + file.name + '…';
    const buf = await file.arrayBuffer();
    progMsg.textContent = '正在解析…';
    const wb = XLSX.read(buf, { type: 'array', cellDates: false });

    const meta = {};
    const fieldMap = {
      '书名':'title','类型':'genre','简介':'description',
      '主角姓名':'protagonist_name','目标章节数':'target_chapters',
      '写作风格':'writing_style',
    };
    const ws2Name = wb.SheetNames.find(n => n.includes('小说') || n.toLowerCase().includes('info') || wb.SheetNames.indexOf(n)===1);
    if (ws2Name) {
      const ws2 = wb.Sheets[ws2Name];
      const rows2 = XLSX.utils.sheet_to_json(ws2, { header: 1, defval: '' });
      rows2.slice(1).forEach(row => {
        const field = String(row[0]||'').replace(/\*/g,'').trim();
        const val = String(row[2]||row[1]||'').trim();
        if (fieldMap[field] && val) meta[fieldMap[field]] = val;
      });
    }
    parsedData.novelMeta = meta;

    progMsg.textContent = '正在解析章节数据…';
    const ws1Name = wb.SheetNames.find(n => n.includes('章节') || n.toLowerCase().includes('chapter') || wb.SheetNames.indexOf(n)===0);
    const ws1 = wb.Sheets[ws1Name || wb.SheetNames[0]];
    const rows1 = XLSX.utils.sheet_to_json(ws1, { header: 1, defval: '' });
    const chapters = [];
    for (let i = 1; i < rows1.length; i++) {
      const r = rows1[i];
      const num = parseInt(r[0]);
      if (!num || isNaN(num)) continue;
      chapters.push({
        chapter_number: num,
        title: String(r[1]||'').trim() || '第'+num+'章',
        content: String(r[2]||'').trim(),
        outline: String(r[3]||'').trim(),
        pacing: ['快','中','慢'].includes(String(r[4]||'').trim()) ? String(r[4]).trim() : '中',
        status: String(r[5]||'').trim() === 'pending' ? 'pending' : 'completed',
      });
    }
    chapters.sort((a,b) => a.chapter_number - b.chapter_number);
    parsedData.chapters = chapters;

    const ws3Name = wb.SheetNames.find(n => n.includes('人物') || n.toLowerCase().includes('char') || wb.SheetNames.indexOf(n)===2);
    const characters = [];
    if (ws3Name) {
      const ws3 = wb.Sheets[ws3Name];
      const rows3 = XLSX.utils.sheet_to_json(ws3, { header: 1, defval: '' });
      for (let i = 1; i < rows3.length; i++) {
        const r = rows3[i];
        const name = String(r[0]||'').trim();
        if (!name) continue;
        characters.push({
          name, role: String(r[1]||'').trim(),
          gender: String(r[2]||'').trim(),
          description: String(r[3]||'').trim(),
        });
      }
    }
    parsedData.characters = characters;

    progWrap.style.display = 'none';
    showPreview(chapters, characters, meta);

  } catch (e) {
    progMsg.textContent = '解析失败：' + e.message;
    progMsg.style.color = '#ef4444';
  }
}

function showPreview(chapters, characters, meta) {
  gotoStep(2);

  if (Object.keys(meta).length > 0) {
    document.getElementById('novelMetaPreview').innerHTML = Object.entries(meta).map(([k,v]) =>
      '<div class="small"><strong>' + esc(k) + '：</strong>' + esc(v) + '</div>'
    ).join('');
  }

  document.getElementById('chapterCountBadge').textContent = chapters.length + ' 章';
  document.getElementById('chapterPreviewBody').innerHTML = chapters.slice(0,5).map(c =>
    '<tr><td>' + c.chapter_number + '</td><td>' + esc(c.title) + '</td>' +
    '<td class="text-muted">' + esc((c.content||'').slice(0,60)) + '</td>' +
    '<td class="text-muted">' + esc((c.outline||'').slice(0,40)) + '</td>' +
    '<td><span class="badge bg-secondary">' + esc(c.pacing) + '</span></td>' +
    '<td><span class="badge ' + (c.status==='completed'?'bg-success':'bg-warning text-dark') + '">' + esc(c.status) + '</span></td></tr>'
  ).join('');

  if (characters.length > 0) {
    document.getElementById('charPreviewCard').style.display = '';
    document.getElementById('charCountBadge').textContent = characters.length + ' 人';
    document.getElementById('charPreviewBody').innerHTML = characters.slice(0,5).map(c =>
      '<tr><td><strong>' + esc(c.name) + '</strong></td><td>' + esc(c.role) + '</td>' +
      '<td>' + esc(c.gender) + '</td><td class="text-muted">' + esc((c.description||'').slice(0,40)) + '</td></tr>'
    ).join('');
  }
}

document.getElementById('btnReselect').onclick = () => {
  parsedData = { novelMeta: {}, chapters: [], characters: [] };
  fileInput.value = '';
  gotoStep(1);
};

document.getElementById('btnStartImport').onclick = () => startImport();

async function startImport() {
  const { chapters, novelMeta, characters } = parsedData;
  if (chapters.length === 0) { alert('没有解析到章节数据'); return; }

  const sessionKey = 'imp_' + Date.now() + '_' + Math.random().toString(36).slice(2,8);
  gotoStep(3);

  const batchCount = Math.ceil(chapters.length / BATCH_SIZE);
  document.getElementById('batchLog').innerHTML = '';

  log('会话 ' + sessionKey + ' · ' + chapters.length + ' 章 · ' + batchCount + ' 批');

  let initRes;
  try {
    initRes = await post('init', {
      session_key: sessionKey,
      novel_meta: { ...novelMeta, target_chapters: novelMeta.target_chapters || chapters.length },
      total_chapters: chapters.length,
      total_batches: batchCount,
    }, 30000);
  } catch (e) {
    log('初始化异常：' + e.message, 'err');
    document.getElementById('importStatusBadge').textContent = '初始化失败';
    document.getElementById('importStatusBadge').className = 'badge bg-danger';
    return;
  }
  if (!initRes || !initRes.ok) {
    log('初始化失败：' + (initRes && initRes.msg ? initRes.msg : '空响应'), 'err');
    return;
  }
  log('初始化成功 · novel_id=' + (initRes.novel_id || '?'), 'ok');

  importState.sessionKey = sessionKey;
  importState.novelId = initRes.novel_id;
  importState.totalBatches = batchCount;
  importState.completedBatches = initRes.completed_batches || [];
  importState.importedChapters = initRes.imported_chapters || 0;

  try { localStorage.setItem('novel_import_session', JSON.stringify({ sessionKey, novelId: importState.novelId })); } catch (_) {}

  const tStart = Date.now();
  for (let bi = 0; bi < batchCount; bi++) {
    if (importState.completedBatches.includes(bi)) {
      log('批次 ' + (bi+1) + '/' + batchCount + ' 跳过（已完成）');
      continue;
    }

    const batch = chapters.slice(bi * BATCH_SIZE, (bi+1) * BATCH_SIZE);
    let ok = false, retries = 0;
    while (!ok && retries < 3) {
      try {
        const r = await post('batch', { session_key: sessionKey, batch_index: bi, chapters: batch });
        if (r.ok && !r.skipped) {
          importState.importedChapters = r.imported_chapters;
          importState.totalWords = r.total_words;
          importState.completedBatches.push(bi);
          ok = true;
          log('批次 ' + (bi+1) + '/' + batchCount + ' · +' + r.imported + ' 章 · 共 ' + fmtNum(r.total_words) + ' 字', 'ok');
        } else if (r.skipped) {
          ok = true;
        } else {
          throw new Error(r.msg || '服务器错误');
        }
      } catch(e) {
        retries++;
        log('批次 ' + (bi+1) + ' 失败（第' + retries + '次）：' + e.message, 'err');
        if (retries < 3) await new Promise(r => setTimeout(r, 2000 * retries));
      }
    }
    if (!ok) { log('批次 ' + (bi+1) + ' 最终失败', 'err'); break; }

    const pct = Math.round((bi+1) / batchCount * 100);
    document.getElementById('importProgressBar').style.width = pct + '%';
    document.getElementById('importProgressText').textContent = importState.importedChapters + ' / ' + chapters.length + ' 章';
    document.getElementById('importBatchText').textContent = '批次 ' + (bi+1) + '/' + batchCount;
    document.getElementById('importedWords').textContent = fmtNum(importState.totalWords);
  }

  if (parsedData.characters.length > 0) {
    log('导入人物卡 ' + parsedData.characters.length + ' 人…');
    try {
      await post('import_chars', { novel_id: importState.novelId, characters: parsedData.characters }, 30000);
      log('人物卡导入完成', 'ok');
    } catch (e) {
      log('人物卡导入失败(不影响主流程)：' + e.message, 'err');
    }
  }

  log('正在汇总…');
  let finRes;
  try {
    finRes = await post('finalize', { session_key: sessionKey }, 30000);
  } catch (e) {
    log('汇总异常：' + e.message, 'err');
    return;
  }
  if (!finRes || !finRes.ok) {
    log('汇总失败：' + (finRes && finRes.msg ? finRes.msg : '空响应'), 'err');
    return;
  }

  importState.finalizeData = finRes;
  log('完成！共 ' + finRes.actual_chapters + ' 章 · ' + fmtNum(finRes.actual_words) + ' 字', 'ok');
  document.getElementById('importStatusBadge').textContent = '导入完成';
  document.getElementById('importStatusBadge').className = 'badge bg-success';

  try { localStorage.removeItem('novel_import_session'); } catch (_) {}
  setTimeout(() => showAnalysisStep(finRes), 800);
}

function showAnalysisStep(finRes) {
  gotoStep(4);
  document.getElementById('statChapters').textContent = fmtNum(finRes.actual_chapters);
  document.getElementById('statWords').textContent = fmtNum(finRes.actual_words);
}

document.querySelectorAll('.task-card:not(.disabled)').forEach(card => {
  card.addEventListener('click', () => { card.classList.toggle('selected'); });
});

function getSelectedTasks() {
  return [...document.querySelectorAll('.task-card.selected:not(.disabled)')].map(c => c.dataset.task);
}

document.getElementById('btnSkipAnalysis').onclick = () => gotoFinish(false);

document.getElementById('btnStartAnalysis').onclick = async () => {
  const tasks = getSelectedTasks();
  if (tasks.length === 0) { alert('请至少选择一个分析项，或点击"跳过"'); return; }

  const btn = document.getElementById('btnStartAnalysis');
  btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>启动中…';

  try {
    await post('run_analysis', {
      novel_id: importState.novelId,
      tasks: tasks,
    }, 120000);
    gotoFinish(true, tasks);
  } catch (e) {
    alert('分析启动失败：' + e.message);
    btn.disabled = false; btn.innerHTML = '<i class="bi bi-stars me-1"></i>确认分析';
  }
};

function gotoFinish(withAI, tasks) {
  gotoStep(5);
  const novelId = importState.novelId;
  document.getElementById('btnGoNovel').href = 'novel.php?id=' + novelId;

  if (withAI && tasks && tasks.length > 0) {
    const taskNames = { story_outline:'全书故事大纲', extract_chars:'人物卡提取', fill_outlines:'章节大纲补全' };
    const names = tasks.map(t => taskNames[t]||t).join('、');
    document.getElementById('doneMsg').innerHTML =
      '小说已导入书库，AI 分析任务已启动。<br>请进入小说查看 <strong class="text-primary">' + esc(names) + '</strong> 结果。';
  } else {
    document.getElementById('doneMsg').textContent = '小说已成功导入书库，可以开始续写了。';
  }
}

(function checkResume() {
  let stored = null;
  try { stored = localStorage.getItem('novel_import_session'); } catch (_) {}
  if (!stored) return;
  try {
    const s = JSON.parse(stored);
    if (s.sessionKey && s.novelId) {
      const bar = document.createElement('div');
      bar.className = 'alert alert-info d-flex justify-content-between align-items-center small p-2 mb-3';
      bar.innerHTML = '<span><i class="bi bi-arrow-repeat me-1"></i>检测到上次未完成的导入（小说ID ' + s.novelId + '），可继续导入</span>' +
        '<div class="d-flex gap-2"><button class="btn btn-sm btn-info" id="btnResume">继续</button>' +
        '<button class="btn btn-sm btn-outline-secondary" id="btnCancelResume">忽略</button></div>';
      document.querySelector('.import-wrap').prepend(bar);
      document.getElementById('btnResume').onclick = () => {
        importState.sessionKey = s.sessionKey;
        importState.novelId = s.novelId;
        bar.remove();
        alert('请重新选择同一个 Excel 文件，系统将自动跳过已完成的批次');
      };
      document.getElementById('btnCancelResume').onclick = () => {
        try { localStorage.removeItem('novel_import_session'); } catch (_) {} bar.remove();
      };
    }
  } catch(e) {}
})();
</script>

<?php pageFooter(); ?>
