<?php
/**
 * 拆书分析 - AI 小说拆解工具
 * 分步分析(SSE流式) → 可编辑结果 → 一键改写
 */
define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';

$models = DB::fetchAll('SELECT * FROM ai_models ORDER BY is_default DESC, id ASC');

pageHeader('拆书分析', 'analyze');
?>

<style>
.analyze-container { max-width: 1200px; margin: 0 auto; }
.step-bar {
    display: flex; align-items: center; gap: 0;
    margin-bottom: 1.5rem; padding: 0.75rem 1rem;
    background: var(--card-bg); border-radius: 12px;
    border: 1px solid var(--border-color);
}
.step-num {
    width: 36px; height: 36px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 14px; flex-shrink: 0;
    background: var(--bg-secondary); color: var(--text-muted);
    border: 2px solid var(--border-color); transition: all .3s;
}
.step-num.active { background: var(--accent); color: #fff; border-color: var(--accent); }
.step-num.done { background: #10b981; color: #fff; border-color: #10b981; }
.step-line { flex: 1; height: 2px; background: var(--border-color); margin: 0 8px; }
.step-text { font-size: 12px; color: var(--text-muted); text-align: center; margin-top: 2px; }

.a-card {
    background: var(--card-bg); border-radius: 12px;
    border: 1px solid var(--border-color); padding: 1.5rem;
    margin-bottom: 1rem;
}
.a-card h6 { color: var(--text-primary); margin-bottom: 1rem; font-weight: 600; }

.upload-zone {
    border: 2px dashed var(--border-color); border-radius: 12px;
    padding: 1.5rem; text-align: center; cursor: pointer;
    transition: all .3s; background: var(--bg-secondary);
}
.upload-zone:hover { border-color: var(--accent); background: rgba(99,102,241,0.05); }
.upload-zone i { font-size: 2rem; color: var(--text-muted); }

.step-btn {
    display: flex; align-items: center; gap: 10px;
    padding: 12px 16px; border-radius: 10px; cursor: pointer;
    background: var(--bg-secondary); border: 1px solid var(--border-color);
    color: var(--text-primary); transition: all .2s; width: 100%;
    text-align: left; margin-bottom: 6px;
}
.step-btn:hover { border-color: var(--accent); transform: translateX(4px); }
.step-btn.running { border-color: #f59e0b; animation: pulse-border 1.5s infinite; }
.step-btn.done { border-color: #10b981; background: rgba(16,185,129,0.05); }
@keyframes pulse-border { 0%,100%{ border-color: #f59e0b; } 50%{ border-color: transparent; } }
.step-btn .si { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
.step-btn .st { font-weight: 600; font-size: 14px; }
.step-btn .sd { font-size: 12px; color: var(--text-muted); }
.step-btn .badge-sm { font-size: 11px; margin-left: auto; }

.char-ct { font-size: 12px; color: var(--text-muted); text-align: right; margin-top: 4px; }
.char-ct.warn { color: #f59e0b; }
.char-ct.over { color: #ef4444; }

/* 结果区 */
.result-section { border: 1px solid var(--border-color); border-radius: 10px; margin-bottom: 12px; overflow: hidden; }
.result-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 14px; background: var(--bg-secondary);
    border-bottom: 1px solid var(--border-color); cursor: pointer;
}
.result-header h6 { margin: 0; font-size: 14px; font-weight: 600; }
.result-body { padding: 12px; }
.result-body textarea {
    width: 100%; min-height: 150px; border: none; background: transparent;
    color: var(--text-primary); font-size: 14px; line-height: 1.7;
    resize: vertical; outline: none; font-family: inherit;
}
/* 流式输出区 */
.stream-output {
    width: 100%; min-height: 150px; padding: 12px;
    color: var(--text-primary); font-size: 14px; line-height: 1.7;
    white-space: pre-wrap; word-break: break-word;
}
.stream-cursor {
    display: inline-block; width: 2px; height: 1em;
    background: var(--accent); animation: blink 1s infinite;
    vertical-align: text-bottom; margin-left: 2px;
}
@keyframes blink { 0%,50%{ opacity:1; } 51%,100%{ opacity:0; } }

/* 改写 */
.rewrite-field { margin-bottom: 12px; }
.rewrite-field label { font-size: 13px; font-weight: 600; color: var(--text-secondary); margin-bottom: 4px; display: block; }

.history-item {
    display: flex; align-items: center; justify-content: space-between;
    padding: 1rem; background: var(--card-bg); border-radius: 10px;
    border: 1px solid var(--border-color); margin-bottom: 0.5rem;
    cursor: pointer; transition: all .2s;
}
.history-item:hover { border-color: var(--accent); }
</style>

<div class="analyze-container">
    <div class="step-bar">
        <div style="text-align:center"><div class="step-num active" id="sn1">1</div><div class="step-text">输入信息</div></div>
        <div class="step-line"></div>
        <div style="text-align:center"><div class="step-num" id="sn2">2</div><div class="step-text">分步分析</div></div>
        <div class="step-line"></div>
        <div style="text-align:center"><div class="step-num" id="sn3">3</div><div class="step-text">改写新建</div></div>
    </div>

    <!-- ===== 第1步：输入 ===== -->
    <div id="phase1">
        <div class="row">
            <div class="col-lg-5">
                <div class="a-card">
                    <h6><i class="bi bi-book me-2"></i>小说基础信息</h6>
                    <div class="mb-3">
                        <label class="form-label text-secondary small">书名 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="bookTitle" placeholder="例：华娱：当过楚霸王吗，你就演武将">
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-secondary small">作者</label>
                        <input type="text" class="form-control" id="bookAuthor" placeholder="可选">
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-secondary small">类型</label>
                        <select class="form-select" id="bookGenre">
                            <option value="">自动识别</option>
                            <option value="玄幻修仙">玄幻修仙</option>
                            <option value="都市言情">都市言情</option>
                            <option value="科幻末世">科幻末世</option>
                            <option value="历史穿越">历史穿越</option>
                            <option value="武侠仙侠">武侠仙侠</option>
                            <option value="悬疑推理">悬疑推理</option>
                            <option value="奇幻冒险">奇幻冒险</option>
                            <option value="军事战争">军事战争</option>
                            <option value="游戏竞技">游戏竞技</option>
                            <option value="其他">其他</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-secondary small">AI 模型</label>
                        <select class="form-select" id="bookModel">
                            <option value="">默认模型</option>
                            <?php foreach ($models as $m): ?>
                            <option value="<?= $m['id'] ?>" <?= $m['is_default'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($m['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="a-card" style="min-height:300px">
                    <h6><i class="bi bi-file-text me-2"></i>章节内容</h6>
                    <div class="upload-zone" id="uploadZone">
                        <i class="bi bi-cloud-upload"></i>
                        <p class="mb-0 mt-1" style="color:var(--text-muted)">拖拽 TXT 到此处，或 <span class="text-primary">点击上传</span></p>
                        <small style="color:var(--text-muted)">支持 .txt，最大 10MB</small>
                        <input type="file" id="txtFileInput" accept=".txt,.text" style="display:none">
                    </div>
                    <div class="text-center text-muted my-2" style="font-size:13px">—— 或者粘贴内容 ——</div>
                    <textarea class="form-control" id="bookChapters" rows="10"
                        placeholder="粘贴小说章节内容...&#10;建议粘贴前3-10章（约1-3万字）"></textarea>
                    <div class="char-ct" id="charCounter">0 / 60,000 字</div>
                    <div id="uploadedFileInfo" style="display:none" class="alert alert-success py-2 mt-2 mb-0">
                        <i class="bi bi-file-check me-1"></i><span id="uploadedFileName"></span>
                        <button type="button" class="btn-close float-end p-0" style="font-size:10px" onclick="clearUploadedFile()"></button>
                    </div>
                </div>
            </div>
        </div>
        <div class="text-center mt-3">
            <button class="btn btn-primary btn-lg px-5" onclick="goPhase2()">
                <i class="bi bi-arrow-right me-2"></i>开始拆书分析
            </button>
        </div>
    </div>

    <!-- ===== 第2步：分步分析 ===== -->
    <div id="phase2" style="display:none">
        <div class="row">
            <div class="col-lg-4">
                <div class="a-card">
                    <h6><i class="bi bi-list-check me-2"></i>分析步骤</h6>
                    <div id="stepButtons">
                        <button class="step-btn" onclick="runStep('characters')" id="btn-characters">
                            <div class="si" style="background:rgba(99,102,241,.15);color:#6366f1">👤</div>
                            <div><div class="st">核心人设</div><div class="sd">主角、女主、配角设定</div></div>
                        </button>
                        <button class="step-btn" onclick="runStep('worldview')" id="btn-worldview">
                            <div class="si" style="background:rgba(16,185,129,.15);color:#10b981">🌍</div>
                            <div><div class="st">世界观设定</div><div class="sd">背景、金手指、势力</div></div>
                        </button>
                        <button class="step-btn" onclick="runStep('storyline')" id="btn-storyline">
                            <div class="si" style="background:rgba(245,158,11,.15);color:#f59e0b">📖</div>
                            <div><div class="st">故事线大纲</div><div class="sd">分幕剧情、转折、伏笔</div></div>
                        </button>
                        <button class="step-btn" onclick="runStep('emotion')" id="btn-emotion">
                            <div class="si" style="background:rgba(239,68,68,.15);color:#ef4444">📈</div>
                            <div><div class="st">情绪曲线</div><div class="sd">节奏、钩子、爽点公式</div></div>
                        </button>
                        <button class="step-btn" onclick="runStep('explosive')" id="btn-explosive">
                            <div class="si" style="background:rgba(168,85,247,.15);color:#a855f7">🔥</div>
                            <div><div class="st">爆款分析</div><div class="sd">社会情绪、代入感</div></div>
                        </button>
                        <button class="step-btn" onclick="runStep('summary')" id="btn-summary">
                            <div class="si" style="background:rgba(6,182,212,.15);color:#06b6d4">💡</div>
                            <div><div class="st">总结公式</div><div class="sd">爆款公式提炼</div></div>
                        </button>
                    </div>
                    <div class="d-grid gap-2 mt-3">
                        <button class="btn btn-primary" onclick="runAllSteps()" id="btnRunAll">
                            <i class="bi bi-lightning-charge me-1"></i>一键全部分析
                        </button>
                        <button class="btn btn-success" onclick="goPhase3()" id="btnGoPhase3" disabled>
                            <i class="bi bi-arrow-right me-1"></i>下一步：一键改写
                        </button>
                        <button class="btn btn-outline-secondary btn-sm" onclick="goPhase1()">
                            <i class="bi bi-arrow-left me-1"></i>返回修改输入
                        </button>
                    </div>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="a-card">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h6 class="mb-0"><i class="bi bi-pencil-square me-2"></i>分析结果 <small class="text-muted">（可直接编辑）</small></h6>
                        <button class="btn btn-sm btn-outline-primary" onclick="saveAnalysis()">
                            <i class="bi bi-save me-1"></i>保存
                        </button>
                    </div>
                    <div id="resultSections">
                        <div class="text-center text-muted py-5">
                            <p style="font-size:2rem">👈</p>
                            <p>点击左侧按钮开始分析</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== 第3步：改写 ===== -->
    <div id="phase3" style="display:none">
        <div class="row">
            <div class="col-lg-6">
                <div class="a-card">
                    <h6><i class="bi bi-magic me-2"></i>AI 开始改写 <small class="text-muted">（可编辑）</small></h6>
                    <div id="rewriteFields">
                        <div class="text-center text-muted py-4">
                            <button class="btn btn-primary" onclick="doRewrite()" id="btnRewrite">
                                <i class="bi bi-stars me-1"></i>一键改写为小说设定
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="a-card">
                    <h6><i class="bi bi-book-half me-2"></i>新建小说预览</h6>
                    <div id="novelPreview" class="text-center text-muted py-4">改写完成后预览</div>
                    <div class="d-flex gap-2 mt-3" id="novelActions" style="display:none">
                        <button class="btn btn-primary flex-fill" onclick="createNovel()">
                            <i class="bi bi-plus-circle me-1"></i>确认并新建小说
                        </button>
                        <button class="btn btn-outline-secondary" onclick="goPhase2()">
                            <i class="bi bi-arrow-left me-1"></i>返回修改
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 历史 -->
    <div class="mt-4">
        <h6 class="text-secondary mb-3"><i class="bi bi-clock-history me-2"></i>历史分析</h6>
        <div id="analysisHistory"></div>
        <div id="historyEmpty" class="text-center text-muted py-4">暂无历史分析记录</div>
    </div>
</div>

<script>
const STEPS = ['characters','worldview','storyline','emotion','explosive','summary'];
const STEP_NAMES = {
    characters:'核心人设', worldview:'世界观设定', storyline:'故事线大纲',
    emotion:'情绪曲线', explosive:'爆款分析', summary:'总结公式'
};
const STEP_ICONS = { characters:'👤', worldview:'🌍', storyline:'📖', emotion:'📈', explosive:'🔥', summary:'💡' };

let stepResults = {};
let stepRunning = {};
let currentAbort = null; // 用于取消当前 SSE 请求
let rewriteData = null;

// ========== 阶段切换 ==========
function setPhase(n) {
    ['phase1','phase2','phase3'].forEach((id,i) => {
        document.getElementById(id).style.display = (i+1===n) ? '' : 'none';
    });
    ['sn1','sn2','sn3'].forEach((id,i) => {
        const el = document.getElementById(id);
        el.classList.remove('active','done');
        if (i+1 < n) el.classList.add('done');
        else if (i+1 === n) el.classList.add('active');
    });
    window.scrollTo({top:0, behavior:'smooth'});
}
function goPhase1() { setPhase(1); }
function goPhase2() {
    if (!document.getElementById('bookTitle').value.trim()) { showToast('请输入书名','error'); return; }
    if (!document.getElementById('bookChapters').value.trim()) { showToast('请粘贴或上传章节内容','error'); return; }
    setPhase(2);
    updateResultUI();
}
function goPhase3() {
    if (!Object.keys(stepResults).length) { showToast('请先至少分析一个维度','error'); return; }
    setPhase(3);
}

// ========== 文件上传 ==========
const uploadZone = document.getElementById('uploadZone');
const txtFileInput = document.getElementById('txtFileInput');
uploadZone.addEventListener('click', () => txtFileInput.click());
uploadZone.addEventListener('dragover', e => { e.preventDefault(); uploadZone.classList.add('dragover'); });
uploadZone.addEventListener('dragleave', () => uploadZone.classList.remove('dragover'));
uploadZone.addEventListener('drop', e => { e.preventDefault(); uploadZone.classList.remove('dragover'); if(e.dataTransfer.files.length) handleFile(e.dataTransfer.files[0]); });
txtFileInput.addEventListener('change', () => { if(txtFileInput.files.length) handleFile(txtFileInput.files[0]); });

async function handleFile(file) {
    if (!file.name.match(/\.(txt|text)$/i)) { showToast('仅支持 .txt','error'); return; }
    if (file.size > 10*1024*1024) { showToast('文件超过 10MB','error'); return; }
    const fd = new FormData(); fd.append('txt_file', file);
    try {
        const res = await fetch('api/analyze_book.php?action=upload_txt', {method:'POST', body:fd});
        const data = await res.json();
        if (data.success) {
            document.getElementById('bookChapters').value = data.content;
            document.getElementById('uploadedFileName').textContent = `${file.name}（${(data.char_count/10000).toFixed(1)}万字）`;
            document.getElementById('uploadedFileInfo').style.display = '';
            updateCharCounter();
            showToast(`已加载：${file.name}`,'success');
        } else showToast(data.error||'上传失败','error');
    } catch(e) { showToast('上传失败','error'); }
}
function clearUploadedFile() {
    document.getElementById('bookChapters').value = '';
    document.getElementById('uploadedFileInfo').style.display = 'none';
    txtFileInput.value = ''; updateCharCounter();
}

document.getElementById('bookChapters').addEventListener('input', updateCharCounter);
function updateCharCounter() {
    const len = (document.getElementById('bookChapters').value||'').length;
    const ct = document.getElementById('charCounter');
    ct.textContent = `${len.toLocaleString()} / 60,000 字`;
    ct.className = 'char-ct' + (len>60000?' over':len>45000?' warn':'');
}

// ========== 分步分析 (SSE 流式) ==========
async function runStep(step) {
    if (stepRunning[step]) return;
    stepRunning[step] = true;
    const btn = document.getElementById('btn-' + step);
    btn.classList.add('running');
    btn.classList.remove('done');

    // 为该步骤创建/显示实时输出区
    ensureResultSection(step, true);

    // 收集数据
    const body = JSON.stringify({
        title: document.getElementById('bookTitle').value.trim(),
        author: document.getElementById('bookAuthor').value.trim(),
        genre: document.getElementById('bookGenre').value,
        chapters: document.getElementById('bookChapters').value.trim(),
        step: step,
        model_id: document.getElementById('bookModel').value || null,
        prev_result: Object.values(stepResults).join('\n\n').substring(0, 3000),
    });

    currentAbort = new AbortController();
    let fullContent = '';

    try {
        const response = await fetch('api/analyze_book.php?action=analyze_step', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: body,
            signal: currentAbort.signal,
        });

        if (!response.ok) {
            throw new Error(`服务器错误 (${response.status})`);
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buf = '', currentEvent = '';

        while (true) {
            const {value, done} = await reader.read();
            if (done) break;

            buf += decoder.decode(value, {stream: true});
            const lines = buf.split('\n');
            buf = lines.pop();

            for (const line of lines) {
                const trimmed = line.trim();
                if (trimmed.startsWith('event: ')) {
                    currentEvent = trimmed.slice(7).trim();
                    continue;
                }
                if (!trimmed.startsWith('data: ')) continue;

                let d;
                try { d = JSON.parse(trimmed.slice(6)); } catch { currentEvent = ''; continue; }

                switch (currentEvent) {
                    case 'model':
                        // 可以在状态栏显示模型名
                        break;
                    case 'status':
                        appendStreamText(step, '\n[' + d.message + ']\n');
                        break;
                    case 'warning':
                        showToast(d.message, 'warning');
                        break;
                    case 'chunk':
                        if (d.t) {
                            fullContent += d.t;
                            appendStreamText(step, d.t);
                        }
                        break;
                    case 'done':
                        // 分析完成
                        break;
                    case 'error':
                        showToast(d.message, 'error');
                        break;
                }
                currentEvent = '';
            }
        }
    } catch(e) {
        if (e.name !== 'AbortError') {
            showToast('请求失败：' + e.message, 'error');
        }
    }

    currentAbort = null;
    stepRunning[step] = false;
    btn.classList.remove('running');

    if (fullContent) {
        stepResults[step] = fullContent;
        btn.classList.add('done');
        showToast(`${STEP_NAMES[step]} 分析完成`, 'success');
        // 将流式输出区转为可编辑 textarea
        finalizeResultSection(step, fullContent);
    } else {
        // 失败，移除输出区
        removeResultSection(step);
    }

    updateGoPhase3Btn();
}

// 实时追加文字到流式输出区
function appendStreamText(step, text) {
    const el = document.getElementById('stream-' + step);
    if (!el) return;
    // 在 cursor 前插入文字
    const cursor = el.querySelector('.stream-cursor');
    if (cursor) {
        cursor.before(document.createTextNode(text));
    } else {
        el.appendChild(document.createTextNode(text));
    }
    el.scrollTop = el.scrollHeight;
}

// 确保结果区存在
function ensureResultSection(step, streaming) {
    let section = document.getElementById('section-' + step);
    const container = document.getElementById('resultSections');

    // 移除占位符
    const placeholder = container.querySelector('.text-center');
    if (placeholder) placeholder.remove();

    if (!section) {
        section = document.createElement('div');
        section.className = 'result-section';
        section.id = 'section-' + step;
        // 按顺序插入
        const order = STEPS.indexOf(step);
        let inserted = false;
        for (let i = order + 1; i < STEPS.length; i++) {
            const next = document.getElementById('section-' + STEPS[i]);
            if (next) { container.insertBefore(section, next); inserted = true; break; }
        }
        if (!inserted) container.appendChild(section);
    }

    if (streaming) {
        section.innerHTML = `
            <div class="result-header">
                <h6>${STEP_ICONS[step]} ${STEP_NAMES[step]} <span class="badge bg-warning text-dark" style="font-size:11px">分析中...</span></h6>
                <button class="btn btn-sm btn-outline-danger" onclick="cancelStep('${step}')"><i class="bi bi-stop"></i></button>
            </div>
            <div class="stream-output" id="stream-${step}"><span class="stream-cursor"></span></div>
        `;
    }
}

// 流式完成 → 转为可编辑 textarea
function finalizeResultSection(step, content) {
    const section = document.getElementById('section-' + step);
    if (!section) return;
    section.innerHTML = `
        <div class="result-header" onclick="toggleSectionBody('${step}')">
            <h6>${STEP_ICONS[step]} ${STEP_NAMES[step]} <span class="badge bg-success" style="font-size:11px">✓</span></h6>
            <i class="bi bi-chevron-down"></i>
        </div>
        <div class="result-body">
            <textarea id="result-${step}" onchange="stepResults['${step}']=this.value" rows="8">${escHtml(content)}</textarea>
        </div>
    `;
}

function removeResultSection(step) {
    const section = document.getElementById('section-' + step);
    if (section) section.remove();
    // 如果没有任何结果了，显示占位
    const container = document.getElementById('resultSections');
    if (!container.children.length) {
        container.innerHTML = '<div class="text-center text-muted py-5"><p style="font-size:2rem">👈</p><p>点击左侧按钮开始分析</p></div>';
    }
}

function toggleSectionBody(step) {
    const section = document.getElementById('section-' + step);
    if (!section) return;
    const body = section.querySelector('.result-body');
    if (body) body.style.display = body.style.display === 'none' ? '' : 'none';
}

function cancelStep(step) {
    if (currentAbort) { currentAbort.abort(); currentAbort = null; }
    stepRunning[step] = false;
    const btn = document.getElementById('btn-' + step);
    if (btn) btn.classList.remove('running');
    removeResultSection(step);
}

function updateResultUI() {
    // 重建所有已完成步骤的结果区
    const container = document.getElementById('resultSections');
    container.innerHTML = '';
    let hasAny = false;
    for (const step of STEPS) {
        if (!stepResults[step]) continue;
        hasAny = true;
        ensureResultSection(step, false);
        finalizeResultSection(step, stepResults[step]);
    }
    if (!hasAny) {
        container.innerHTML = '<div class="text-center text-muted py-5"><p style="font-size:2rem">👈</p><p>点击左侧按钮开始分析</p></div>';
    }
}

function updateGoPhase3Btn() {
    document.getElementById('btnGoPhase3').disabled = !Object.keys(stepResults).length;
}

async function runAllSteps() {
    const btn = document.getElementById('btnRunAll');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>分析中...';
    for (const step of STEPS) {
        if (stepResults[step]) continue;
        await runStep(step);
        if (!stepResults[step]) break;
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-lightning-charge me-1"></i>一键全部分析';
}

// ========== 一键改写 ==========
async function doRewrite() {
    // 弹出输入书名和主角名的对话框
    const origTitle = document.getElementById('bookTitle').value.trim();
    const modalHtml = `
    <div class="modal fade" id="rewriteModal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background:var(--bg-card);border:1px solid var(--border)">
          <div class="modal-header border-0 pb-0">
            <h5 class="modal-title" style="color:var(--text)"><i class="bi bi-pencil-square me-2"></i>改写设置</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label" style="color:var(--text)">新书书名</label>
              <input type="text" class="form-control" id="rewriteTitle" value="${escHtml(origTitle)}" placeholder="输入新书书名">
            </div>
            <div class="mb-3">
              <label class="form-label" style="color:var(--text)">主角姓名</label>
              <input type="text" class="form-control" id="rewriteProtagonist" placeholder="输入主角的名字">
            </div>
            <div class="alert alert-info py-2 mb-0" style="font-size:13px">
              <i class="bi bi-info-circle me-1"></i>其他角色名字将自动随机改名，符合男/女姓名规则
            </div>
          </div>
          <div class="modal-footer border-0 pt-0">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
            <button type="button" class="btn btn-primary" id="rewriteConfirmBtn">
              <i class="bi bi-stars me-1"></i>开始改写
            </button>
          </div>
        </div>
      </div>
    </div>`;
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    const modalEl = document.getElementById('rewriteModal');
    const modal = new bootstrap.Modal(modalEl);

    // 等待用户确认
    const confirmed = await new Promise(resolve => {
        document.getElementById('rewriteConfirmBtn').onclick = () => {
            const t = document.getElementById('rewriteTitle').value.trim();
            const p = document.getElementById('rewriteProtagonist').value.trim();
            if (!t) { showToast('请输入书名','error'); return; }
            if (!p) { showToast('请输入主角姓名','error'); return; }
            modal.hide();
            resolve({title: t, protagonist: p});
        };
        modalEl.addEventListener('hidden.bs.modal', () => {
            modalEl.remove();
            resolve(null);
        }, {once: true});
        modal.show();
    });

    if (!confirmed) return;

    const btn = document.getElementById('btnRewrite');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>AI 改写中...';

    const analysis = Object.entries(stepResults).map(([k,v]) => `## ${STEP_NAMES[k]}\n${v}`).join('\n\n');

    try {
        const res = await apiPost('api/analyze_book.php?action=rewrite', {
            title: confirmed.title,
            protagonist_name: confirmed.protagonist,
            analysis: analysis,
            model_id: document.getElementById('bookModel').value || null,
        });
        if (res.success && res.fields) {
            rewriteData = res.fields;
            renderRewriteFields(res.fields);
            renderNovelPreview(res.fields);
        } else if (res.success && !res.fields) {
            showToast('AI 返回格式异常，请重试','error');
        } else {
            showToast(res.error || '改写失败','error');
        }
    } catch(e) { showToast('请求失败','error'); }

    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-stars me-1"></i>一键改写为小说设定';
}

function renderRewriteFields(fields) {
    const defs = [
        {key:'title',label:'书名',type:'input'},
        {key:'genre',label:'类型',type:'input'},
        {key:'protagonist_name',label:'主角姓名',type:'input'},
        {key:'protagonist_info',label:'主角信息',type:'textarea',rows:4},
        {key:'world_settings',label:'世界观设定',type:'textarea',rows:4},
        {key:'plot_settings',label:'情节设定',type:'textarea',rows:4},
        {key:'writing_style',label:'写作风格',type:'input'},
        {key:'extra_settings',label:'额外设定',type:'textarea',rows:4},
    ];
    let html = '';
    for (const f of defs) {
        const val = escHtml(fields[f.key]||'');
        html += f.type==='textarea'
            ? `<div class="rewrite-field"><label>${f.label}</label><textarea class="form-control" rows="${f.rows}" id="rw-${f.key}" onchange="onRewriteEdit('${f.key}')">${val}</textarea></div>`
            : `<div class="rewrite-field"><label>${f.label}</label><input class="form-control" id="rw-${f.key}" value="${val}" onchange="onRewriteEdit('${f.key}')"></div>`;
    }
    document.getElementById('rewriteFields').innerHTML = html;
}

function onRewriteEdit(key) {
    if (!rewriteData) return;
    const el = document.getElementById('rw-'+key);
    if (el) rewriteData[key] = el.value;
    renderNovelPreview(rewriteData);
}

function renderNovelPreview(fields) {
    if (!fields) return;
    document.getElementById('novelPreview').innerHTML = `
        <div style="text-align:left">
            <h5 style="color:var(--accent);margin-bottom:12px">${escHtml(fields.title||'未命名')}</h5>
            <p><strong>类型：</strong>${escHtml(fields.genre||'-')}</p>
            <p><strong>主角：</strong>${escHtml(fields.protagonist_name||'-')} — ${escHtml((fields.protagonist_info||'').substring(0,100))}...</p>
            <p><strong>世界观：</strong>${escHtml((fields.world_settings||'').substring(0,150))}...</p>
            <p><strong>情节：</strong>${escHtml((fields.plot_settings||'').substring(0,150))}...</p>
            <p><strong>风格：</strong>${escHtml(fields.writing_style||'-')}</p>
            ${fields.extra_settings ? `<p><strong>额外设定：</strong>${escHtml((fields.extra_settings||'').substring(0,150))}...</p>` : ''}
        </div>`;
    document.getElementById('novelActions').style.display = '';
}

// ========== 新建小说 ==========
function createNovel() {
    if (!rewriteData) { showToast('请先改写','error'); return; }
    const fields = {
        title: rewriteData.title||'',
        genre: rewriteData.genre||'',
        writing_style: rewriteData.writing_style||'',
        protagonist_name: rewriteData.protagonist_name||'',
        protagonist_info: rewriteData.protagonist_info||'',
        plot_settings: rewriteData.plot_settings||'',
        world_settings: rewriteData.world_settings||'',
        extra_settings: rewriteData.extra_settings||'',
        target_chapters:'100', chapter_words:'2000',
        model_id: document.getElementById('bookModel').value||'',
    };
    const json = JSON.stringify(fields);
    const b64 = btoa(unescape(encodeURIComponent(json)));
    window.location.href = 'create.php?prefill=' + encodeURIComponent(b64);
}

// ========== 保存/历史 ==========
async function saveAnalysis() {
    const title = document.getElementById('bookTitle').value.trim();
    const content = Object.entries(stepResults).map(([k,v]) => `## ${STEP_NAMES[k]}\n${v}`).join('\n\n');
    if (!title||!content) { showToast('没有可保存的内容','error'); return; }
    try {
        const res = await apiPost('api/analyze_book.php?action=save', {
            title, content,
            author: document.getElementById('bookAuthor').value.trim(),
            genre: document.getElementById('bookGenre').value,
            source_text: document.getElementById('bookChapters').value.trim(),
        });
        if (res.success) { showToast('已保存','success'); loadHistory(); }
        else showToast(res.error||'保存失败','error');
    } catch(e) { showToast('保存失败','error'); }
}

async function loadHistory() {
    try {
        const res = await apiPost('api/analyze_book.php?action=list', {});
        if (res.success && res.data && res.data.length) {
            document.getElementById('historyEmpty').style.display = 'none';
            document.getElementById('analysisHistory').innerHTML = res.data.map(item => `
                <div class="history-item" onclick="viewHistory(${item.id})">
                    <div><div style="font-weight:600">${escHtml(item.title)}</div>
                    <div style="font-size:12px;color:var(--text-muted)">${item.author?escHtml(item.author)+' · ':''}${item.genre?escHtml(item.genre)+' · ':''}${item.created_at}</div></div>
                    <button class="btn btn-sm btn-outline-danger" onclick="event.stopPropagation();deleteHistory(${item.id})"><i class="bi bi-trash"></i></button>
                </div>`).join('');
        } else {
            document.getElementById('historyEmpty').style.display = '';
            document.getElementById('analysisHistory').innerHTML = '';
        }
    } catch(e) {}
}

async function viewHistory(id) {
    try {
        const res = await apiPost('api/analyze_book.php?action=get&id='+id, {});
        if (res.success && res.data) {
            stepResults = {};
            const content = res.data.content || '';
            const sections = content.split(/^## /m);
            for (const sec of sections) {
                if (!sec.trim()) continue;
                for (const step of STEPS) {
                    if (sec.startsWith(STEP_NAMES[step])) {
                        stepResults[step] = sec.replace(/^.*?\n/,'').trim();
                        break;
                    }
                }
            }
            document.getElementById('bookTitle').value = res.data.title||'';
            document.getElementById('bookAuthor').value = res.data.author||'';
            document.getElementById('bookGenre').value = res.data.genre||'';
            goPhase2();
            updateResultUI();
            updateGoPhase3Btn();
        }
    } catch(e) { showToast('加载失败','error'); }
}

async function deleteHistory(id) {
    if (!confirm('确定删除？')) return;
    try { await apiPost('api/analyze_book.php?action=delete',{id}); showToast('已删除','success'); loadHistory(); } catch(e) {}
}

function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

document.addEventListener('DOMContentLoaded', () => { loadHistory(); updateCharCounter(); });
</script>

<?php pageFooter(); ?>
