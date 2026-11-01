/**
 * AI小说创作系统 - 前端脚本
 */

// ============================================================
// CSRF Token 辅助
// ------------------------------------------------------------
// 从 <meta name="csrf-token"> 读取服务端下发的 token，供所有
// 修改类 API 请求自动携带 X-CSRF-Token 头。后端在 includes/csrf.php
// 中做 hash_equals 校验，失败一律返回 403。
// ============================================================
function getCsrfToken() {
    return window.CSRF_TOKEN || '';
}

// 生成带 CSRF 的 JSON 请求头，被所有 fetch 调用复用
function jsonHeaders() {
    return {
        'Content-Type': 'application/json',
        'X-CSRF-Token': getCsrfToken(),
    };
}

function apiRouteUrl(route, params = '') {
    let qs = '';
    if (params) {
        const raw = String(params);
        qs = raw.startsWith('&') ? raw : '&' + raw.replace(/^\?/, '');
    }
    return 'api/index.php?route=' + encodeURIComponent(route) + qs;
}

// ============================================================
// 安全 HTML 净化
// ============================================================
// 审计修复 M6（2026-06-12）：退化逻辑改进。
// 原先依赖 jsDelivr 上的 DOMPurify；HTTP 公网 IP 站点（非 secure context）
// 访问该 CDN 会被 Chrome Private Network Access 直接拦截，页面出现
// CORS / net::ERR_FAILED。净化改为同源白名单剥离器，不再请求外链脚本。
// 若页面已注入完整 DOMPurify，则仍优先使用它。
const SAFE_HTML_TAG_ALLOWLIST = new Set([
    'A','ABBR','B','BLOCKQUOTE','BR','BUTTON','CAPTION','CODE','COL','COLGROUP',
    'DD','DEL','DETAILS','DFN','DIV','DL','DT','EM','FIGCAPTION','FIGURE',
    'H1','H2','H3','H4','H5','H6','HR','I','IMG','INS','KBD','LI',
    'MARK','OL','P','PRE','Q','S','SAMP','SMALL','SPAN','STRIKE','STRONG',
    'SUB','SUMMARY','SUP','TABLE','TBODY','TD','TH','THEAD','TIME','TR',
    'U','UL','VAR','WBR',
]);
const SAFE_HTML_ATTR_ALLOWLIST = new Set([
    'class','id','href','src','alt','title','width','height','role',
    'aria-label','aria-labelledby','aria-describedby','aria-hidden',
    'aria-expanded','aria-controls','aria-haspopup','aria-current',
    'data-bs-toggle','data-bs-target','data-bs-dismiss','data-bs-placement',
    'data-bs-trigger','data-bs-content','data-bs-original-title',
    'data-id','data-novel-id','data-chapter-id','data-story-id',
    'data-novel','data-chapter','data-chapter-num','data-status','data-has-title',
    'data-ajax-page','data-current-page','data-total-pages','data-total-chapters',
    'data-key','data-value','data-type','data-action','data-index',
    'data-field','data-tab','data-parent','data-name','data-candidate-id',
    'target','rel','type','colspan','rowspan','scope','for',
]);
// Only the authenticated chapter_list response may retain these page actions.
// General rich text must never acquire authority through the event registry.
const CHAPTER_LIST_CSP_ACTIONS = new Map([
    ['novel.export-synopses', { event: 'click', tag: 'A', parameter: 'format' }],
    ['novel.choose-import', { event: 'click', tag: 'BUTTON' }],
    ['novel.import-synopses', { event: 'change', tag: 'INPUT' }],
    ['novel.add-chapters', { event: 'click', tag: 'BUTTON' }],
    ['novel.clear-chapters', { event: 'click', tag: 'BUTTON' }],
    ['novel.reset-chapter', { event: 'click', tag: 'BUTTON', parameter: 'chapterId' }],
]);

function _chapterListActionAllowed(node, attribute, action) {
    const policy = CHAPTER_LIST_CSP_ACTIONS.get(action);
    if (!policy || attribute !== 'data-csp-' + policy.event || node.tagName !== policy.tag) return false;
    if (policy.parameter === 'format') return ['json', 'excel', 'txt'].includes(node.getAttribute('data-format'));
    if (policy.parameter === 'chapterId') {
        const value = node.getAttribute('data-chapter-id') || '';
        return /^[1-9]\d*$/.test(value) && Number.isSafeInteger(Number(value));
    }
    if (policy.tag === 'INPUT') {
        return node.getAttribute('type') === 'file' && node.id === 'import-file-input';
    }
    return true;
}

function _stripUnsafeHtml(html, chapterList = false) {
    if (html == null) return '';
    const s = String(html);
    if (s === '') return '';
    // 构造一个临时的 DocumentFragment 来解析，然后遍历白名单过滤。
    // 注意：template 元素在 IE 不可用，但项目面向现代浏览器。
    const tpl = document.createElement('template');
    tpl.innerHTML = s;
    const frag = tpl.content;
    const walk = (parent) => {
        const nodes = Array.from(parent.childNodes);
        for (const node of nodes) {
            if (node.nodeType === 8 /* COMMENT */) {
                parent.removeChild(node);
                continue;
            }
            if (node.nodeType === 3 /* TEXT */) {
                continue;
            }
            if (node.nodeType !== 1 /* ELEMENT */) {
                parent.removeChild(node);
                continue;
            }
            const tag = node.tagName.toUpperCase();
            const chapterInput = chapterList && tag === 'INPUT'
                && _chapterListActionAllowed(node, 'data-csp-change', node.getAttribute('data-csp-change'));
            if (!SAFE_HTML_TAG_ALLOWLIST.has(tag) && !(chapterList && tag === 'NAV') && !chapterInput) {
                // 保留子节点（剥离标签本身），防止 <script> 之类被执行
                // Sanitize before promotion: this iteration uses a child-node snapshot.
                // Otherwise a disallowed wrapper could smuggle unsanitized descendants.
                walk(node);
                while (node.firstChild) parent.insertBefore(node.firstChild, node);
                parent.removeChild(node);
                continue;
            }
            // 过滤属性：只保留白名单属性；拒绝所有 on* 事件；拒绝 javascript: URI。
            const attrs = Array.from(node.attributes);
            for (const a of attrs) {
                const name = a.name.toLowerCase();
                const val  = a.value || '';
                if (name.startsWith('on')) { node.removeAttribute(a.name); continue; }
                if (name.startsWith('data-csp-')) {
                    if (!chapterList || !_chapterListActionAllowed(node, name, val)) node.removeAttribute(a.name);
                    continue;
                }
                if (chapterList && name === 'data-format') {
                    if (!_chapterListActionAllowed(node, 'data-csp-click', node.getAttribute('data-csp-click'))
                        || node.getAttribute('data-csp-click') !== 'novel.export-synopses') node.removeAttribute(a.name);
                    continue;
                }
                if (chapterInput && (name === 'accept' || name === 'hidden')) continue;
                if (name.startsWith('data-')) {
                    // 仅允许白名单 data-*（见上），其它一律剥离
                    if (!SAFE_HTML_ATTR_ALLOWLIST.has(name)) {
                        node.removeAttribute(a.name);
                    }
                    continue;
                }
                if (!SAFE_HTML_ATTR_ALLOWLIST.has(name)) {
                    node.removeAttribute(a.name);
                    continue;
                }
                if ((name === 'href' || name === 'src') && /^(?:javascript|vbscript):/i.test(val.replace(/[\u0000-\u0020\u007f]/g, ''))) {
                    node.removeAttribute(a.name);
                }
            }
            if (chapterInput) {
                node.hidden = true;
                node.setAttribute('accept', '.json,.csv,.txt');
            }
            // 强制链接新窗口打开并剥离 noopener
            if (tag === 'A' && !chapterList) {
                node.setAttribute('target', '_blank');
                node.setAttribute('rel', 'noopener noreferrer nofollow');
            }
            walk(node);
        }
    };
    walk(frag);
    const div = document.createElement('div');
    div.appendChild(frag.cloneNode(true));
    return div.innerHTML;
}

if (typeof window.DOMPurify === 'undefined') {
    window.DOMPurify = {
        sanitize: function (dirty) {
            return _stripUnsafeHtml(dirty);
        },
    };
}

function safeHtml(html) {
    if (typeof DOMPurify !== 'undefined' && typeof DOMPurify.sanitize === 'function') {
        // DOMPurify normally permits data attributes. Enforce the same action-free
        // boundary even when a full library is supplied by a deployment.
        return _stripUnsafeHtml(DOMPurify.sanitize(html));
    }
    return _stripUnsafeHtml(html);
}

function safeChapterListHtml(html) {
    return _stripUnsafeHtml(html, true);
}

// ============================================================
// 主题切换（亮/暗）
// ============================================================

(function initTheme() {
    const STORAGE_KEY = 'novel-theme';
    const html        = document.documentElement;

    function applyTheme(theme) {
        html.setAttribute('data-theme', theme);
        try { localStorage.setItem(STORAGE_KEY, theme); } catch (_) {}
        const label = document.getElementById('theme-label');
        if (label) label.textContent = theme === 'dark' ? '暗色' : '亮色';
    }

    // 页面加载后绑定按钮（DOMContentLoaded 时可能还没渲染）
    document.addEventListener('DOMContentLoaded', () => {
        const btn = document.getElementById('theme-toggle');
        if (!btn) return;
        // 初始化标签
        let cur = 'dark';
        try { cur = localStorage.getItem(STORAGE_KEY) || 'dark'; } catch (_) {}
        applyTheme(cur);
        btn.addEventListener('click', () => {
            const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            applyTheme(next);
        });
    });
})();

// ============================================================
// 工具函数
// ============================================================

/**
 * 审计修复 E-1（2026-06-22）：统一 API 响应解析与错误处理。
 *
 * 原实现直接 `return res.json()`，存在三类未处理场景：
 *   1. HTTP 4xx/5xx（fetch 不抛错，res.json() 解析 HTML 错误页会抛 SyntaxError）
 *   2. 网络中断（fetch 抛 TypeError，调用方未 try/catch → data 为 undefined）
 *   3. 服务端返回非 JSON（如 502 网关 HTML）
 *
 * 修复策略：上述场景统一返回 {ok:false, msg, error} 安全对象，
 * 与业务错误格式一致，调用方仍可用 `if (data.ok)` 判断，无需逐处 try/catch。
 */
function _normalizeApiData(data) {
    if (!data || typeof data !== 'object' || Array.isArray(data)) return data;

    // 历史接口同时存在 {ok,msg} 与 {success,message} 两种响应契约。
    // 在统一请求层归一化，避免调用方把已成功的请求误判为失败。
    if (!Object.prototype.hasOwnProperty.call(data, 'ok') && Object.prototype.hasOwnProperty.call(data, 'success')) {
        data.ok = data.success === true;
    }
    if (!Object.prototype.hasOwnProperty.call(data, 'success') && Object.prototype.hasOwnProperty.call(data, 'ok')) {
        data.success = data.ok === true;
    }
    if (!data.msg && data.message) data.msg = data.message;
    if (!data.message && data.msg) data.message = data.msg;
    return data;
}

async function _safeJson(res) {
    if (!res.ok) {
        // HTTP 非 2xx：尝试解析 JSON 错误体，失败则返回通用错误
        try {
            const data = _normalizeApiData(await res.json());
            if (data && typeof data === 'object') {
                data.ok = false;
                if (!data.msg) data.msg = data.message || data.error || `请求失败（HTTP ${res.status}）`;
                return data;
            }
            return { ok: false, msg: `请求失败（HTTP ${res.status}）`, error: 'http_error' };
        } catch (_) {
            return { ok: false, msg: `请求失败（HTTP ${res.status}）`, error: 'http_error' };
        }
    }
    try {
        return _normalizeApiData(await res.json());
    } catch (_) {
        return { ok: false, msg: '服务器返回了非 JSON 响应（可能是网关错误）', error: 'invalid_json' };
    }
}

async function apiPost(url, data) {
    if (typeof url === 'string' && !url.includes('/') && !url.includes('.php')) {
        let res;
        try {
            res = await fetch(apiRouteUrl(url), {
                method: 'POST',
                headers: jsonHeaders(),
                body: JSON.stringify(data),
            });
        } catch (e) {
            return { ok: false, msg: '网络请求失败，请检查网络连接', error: 'network_error' };
        }
        return _safeJson(res);
    }
    let res;
    try {
        res = await fetch(url, {
            method:  'POST',
            headers: jsonHeaders(),
            body:    JSON.stringify(data),
        });
    } catch (e) {
        return { ok: false, msg: '网络请求失败，请检查网络连接', error: 'network_error' };
    }
    return _safeJson(res);
}

async function apiGet(route, params) {
    if (typeof params === 'object') {
        params = Object.entries(params).map(([k, v]) => encodeURIComponent(k) + '=' + encodeURIComponent(v)).join('&');
    }
    let res;
    try {
        res = await fetch(apiRouteUrl(route, params || ''), {
            headers: jsonHeaders(),
        });
    } catch (e) {
        return { ok: false, msg: '网络请求失败，请检查网络连接', error: 'network_error' };
    }
    return _safeJson(res);
}

function showToast(msg, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast-msg toast-${type}`;
    toast.textContent = msg;
    document.body.appendChild(toast);
    setTimeout(() => toast.classList.add('show'), 10);
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// ============================================================
// 全局：删除小说
// ============================================================

window.deleteNovel = async function(novelId) {
    if (!confirm('确定删除这部小说及其所有章节？此操作不可撤销！')) return;
    const data = await apiPost('actions', {
        action: 'delete_novel', novel_id: novelId
    });
    if (data.ok) {
        location.href = 'index.php';
    } else {
        showToast('删除失败：' + (data.msg || '未知错误'), 'error');
    }
};

// ============================================================
// Novel 页面逻辑
// ============================================================

document.addEventListener('DOMContentLoaded', () => {

    // ---- 模型切换 ----
    // 审计修复 F04（2026-11-01）：只有服务端回执确认保存了所选模型才算切换成功；
    // 失败时提示原因并恢复到最后确认的模型。保存期间禁用下拉框，不会出现两个
    // 并发切换请求乱序落库、旧回执覆盖新选择。workshop.php 有同 id 但无 data-novel 的下拉框，不绑定。
    const modelSel = document.getElementById('model-select');
    if (modelSel && modelSel.dataset.novel) {
        let confirmedModel = modelSel.value;
        modelSel.addEventListener('change', async () => {
            const requested = modelSel.value;
            modelSel.disabled = true;
            let res;
            try {
                res = await apiPost('actions', {
                    action:   'update_novel_model',
                    novel_id: NOVEL_ID,
                    model_id: requested || null,
                });
            } catch (e) {
                res = { ok: false, msg: '网络请求失败，请检查网络连接' };
            } finally {
                modelSel.disabled = false;
            }
            const saved = !!res && res.ok === true && !!res.data
                && String(res.data.model_id ?? '') === String(requested || '');
            if (saved) {
                confirmedModel = requested;
                showToast('模型已切换');
            } else {
                modelSel.value = confirmedModel;
                showToast('模型切换失败：' + ((res && res.msg) || '服务器未确认保存'), 'error');
            }
        });
    }

    // ---- 生成全书故事大纲按钮 ----
    const btnStory = document.getElementById('btn-story-outline');
    if (btnStory) {
        btnStory.addEventListener('click', () => generateStoryOutline());
        const btnResumeStory = document.getElementById('btn-resume-story-outline');
        const btnCancelStory = document.getElementById('btn-cancel-story-outline');
        if (btnResumeStory) btnResumeStory.addEventListener('click', () => generateStoryOutline());
        if (btnCancelStory) btnCancelStory.addEventListener('click', () => getStoryOutlineTaskController().cancel());
        recoverStoryOutlineTask();
    }

    // ---- 生成大纲按钮 ----
    const btnOutline = document.getElementById('btn-outline');
    if (btnOutline) {
        btnOutline.addEventListener('click', () => generateOutline());
    }

    // ---- 生成章节概要按钮 ----
    const btnSynopsis = document.getElementById('btn-synopsis');
    if (btnSynopsis) {
        btnSynopsis.addEventListener('click', () => generateChapterSynopsis());
    }

    const btnVolume = document.getElementById('btn-volume-outline');
    if (btnVolume) {
        btnVolume.addEventListener('click', () => generateVolumeOutline());
    }

    // ---- 补写大纲按钮 ----
    const btnSupp = document.getElementById('btn-supplement-outline');
    if (btnSupp) {
        btnSupp.addEventListener('click', () => supplementOutline());
    }

    // ---- 自动写作按钮（startAutoWrite 内部处理 toggle） ----
    const btnAuto = document.getElementById('btn-autowrite');
    if (btnAuto) {
        btnAuto.addEventListener('click', () => startAutoWrite());
    }

    // ---- 写下一章按钮 ----
    const btnNext = document.getElementById('btn-next-chapter');
    if (btnNext) {
        btnNext.addEventListener('click', () => writeNextChapter());
    }

    // ---- 章节列表 AJAX 事件 ----
    bindChapterListEvents();
    if (!window.__chapterListPopstateBound) {
        window.__chapterListPopstateBound = true;
        window.addEventListener('popstate', () => {
            if (!document.getElementById('chapter-list-ajax-root')) return;
            loadChapterList(getChapterPageFromUrl(), false);
        });
    }

    // ---- 取消写作按钮 ----
    const btnCancel = document.getElementById('btn-cancel-write');
    if (btnCancel) {
        btnCancel.addEventListener('click', () => cancelWriting());
    }

    // ---- 重置未完成章节按钮 ----
    const btnReset = document.getElementById('btn-reset-chapters');
    if (btnReset) {
        btnReset.addEventListener('click', () => resetChapters());
    }

    // ---- 编辑故事大纲按钮 ----
    const btnEditStoryOutline = document.getElementById('btn-edit-story-outline');
    if (btnEditStoryOutline) {
        btnEditStoryOutline.addEventListener('click', () => editStoryOutline());
    }

    // ---- 保存故事大纲按钮 ----
    const btnSaveStoryOutline = document.getElementById('btn-save-story-outline');
    if (btnSaveStoryOutline) {
        btnSaveStoryOutline.addEventListener('click', () => saveStoryOutline());
    }

    // ---- 重新生成故事大纲按钮 ----
    const btnRegenerateStoryOutline = document.getElementById('btn-regenerate-story-outline');
    if (btnRegenerateStoryOutline && !btnRegenerateStoryOutline.hasAttribute('onclick')) {
        btnRegenerateStoryOutline.addEventListener('click', () => regenerateStoryOutline());
    }

    // ---- 优化大纲逻辑按钮 ----
    const btnOptimizeOutline = document.getElementById('btn-optimize-outline');
    if (btnOptimizeOutline) {
        btnOptimizeOutline.addEventListener('click', () => {
            if (typeof window.optimizeOutline === 'function') {
                window.optimizeOutline();
            } else {
                optimizeOutlineLogic();
            }
        });
    }

    // ---- 保存章节概要按钮 ----
    const btnSaveChapterSynopsis = document.getElementById('btn-save-chapter-synopsis');
    if (btnSaveChapterSynopsis) {
        btnSaveChapterSynopsis.addEventListener('click', () => saveChapterSynopsis());
    }

    // ---- 编辑章节概要按钮（事件委托） ----
    document.body.addEventListener('click', (e) => {
        const btn = e.target.closest('.btn-edit-synopsis');
        if (btn) {
            const novelId = parseInt(btn.dataset.novel);
            const chapterNumber = parseInt(btn.dataset.chapter);
            editChapterSynopsis(novelId, chapterNumber);
        }
    });

    // ---- 优化章节概要按钮（事件委托） ----
    document.body.addEventListener('click', (e) => {
        const btn = e.target.closest('.btn-optimize-synopsis');
        if (btn) {
            const novelId = parseInt(btn.dataset.novel);
            const chapterNumber = parseInt(btn.dataset.chapter);
            openOptimizeSynopsis(novelId, chapterNumber);
        }
    });

    // ---- 删除单个章节按钮（事件委托，章节列表为 AJAX 局部刷新） ----
    document.body.addEventListener('click', (e) => {
        const btn = e.target.closest('.btn-delete-chapter');
        if (btn) {
            deleteSingleChapter(parseInt(btn.dataset.chapterId), parseInt(btn.dataset.chapterNum));
        }
    });

    // ---- 章节行「重新生成细纲 / 生成章节标题」按钮（事件委托） ----
    // has-title=1 → 重新生成该章细纲（章节概要）；has-title=0 → 生成章节标题
    document.body.addEventListener('click', (e) => {
        const btn = e.target.closest('.btn-regenerate-synopsis');
        if (!btn || btn.disabled) return;
        const chapterId  = parseInt(btn.dataset.chapterId);
        const chapterNum = parseInt(btn.dataset.chapterNum);
        if (btn.dataset.hasTitle === '1') {
            regenerateSingleSynopsis(chapterId, chapterNum, btn);
        } else {
            generateSingleChapterTitle(chapterId, chapterNum, btn);
        }
    });

    // ---- 生成优化按钮 ----
    const btnGenerateOptimize = document.getElementById('btn-generate-optimize');
    if (btnGenerateOptimize) {
        btnGenerateOptimize.addEventListener('click', () => generateOptimizedSynopsis());
    }

    // ---- 确认优化按钮 ----
    const btnConfirmOptimize = document.getElementById('btn-confirm-optimize');
    if (btnConfirmOptimize) {
        btnConfirmOptimize.addEventListener('click', () => confirmOptimizedSynopsis());
    }

    // ---- [v4] 一致性检查按钮 ----
    const btnConsistency = document.getElementById('btn-consistency-check');
    if (btnConsistency) {
        btnConsistency.addEventListener('click', () => runConsistencyCheck());
    }

});

// ============================================================
// 生成大纲（支持大范围自动分段续接 + 断线自动恢复）
// ============================================================

// CHAPTER_OUTLINE_CLIENT_BEGIN
let outlineController = null;
let outlineRunning    = false;

// 每次 SSE 调用请求生成的章节数（前端分段大小）。
// 审计修复 L-1（2026-06-22）：原注释"后端每批5章，此处30章=6批/次"与代码不符
// （OUTLINE_CHUNK=5 而非 30）。后端 generate_outline.php 的批次大小由请求参数
// end_chapter-start_chapter+1 决定，此处即每次请求 5 章。后端内部按弧段切分
// （默认 5 章/弧段），与前端 OUTLINE_CHUNK 对齐。如需调整，需同步后端弧段配置。
const OUTLINE_CHUNK    = 5;
// 断线后只查询保存进度，未确认当前批次完成时不重新提交生成请求。
const MAX_RECONNECTS   = 10;
const RECONNECT_DELAY  = 15000;
const OUTLINE_PROGRESS_TIMEOUT = 15000;
const OUTLINE_CONNECT_TIMEOUT = 30000;
const OUTLINE_STREAM_IDLE_TIMEOUT = 180000;
const OUTLINE_BACKGROUND_POLL_DELAY = 5000;
const OUTLINE_BACKGROUND_WAIT = 7200000;

function waitForOutlineRequest(promise, controller, timeout, message) {
    let timer;
    const deadline = new Promise((resolve, reject) => {
        timer = setTimeout(() => {
            const error = new Error(message);
            error.name = 'OutlineTimeoutError';
            reject(error);
            controller.abort();
        }, timeout);
    });
    return Promise.race([promise, deadline]).finally(() => clearTimeout(timer));
}

// 审计修复（2026-07-19 H-中10 配套）：大纲生成防止重复点击并发启动。
// supplementOutline 已有此防护，generateOutline 缺省。加上 static 互斥锁。
// 注：outlineRunning 已在上方（第474行）声明，此处复用同一变量，避免重复 let 致整脚本解析失败。

async function generateOutline() {
    if (outlineRunning) {
        showToast('大纲生成正在进行中，请等待完成...', 'warning');
        return;
    }
    const btnOutline = document.getElementById('btn-outline');
    if (!btnOutline) return;
    const target     = parseInt(btnOutline.dataset.target, 10);
    const novelId    = parseInt(btnOutline.dataset.novel, 10);
    const wrap      = document.getElementById('outline-progress-wrap');
    const label     = document.getElementById('outline-progress-label');
    const spinner   = document.getElementById('outline-spinner');
    const streamBox = document.getElementById('outline-stream-box');
    const batchLog  = document.getElementById('outline-batch-log');
    const tokenBar  = document.getElementById('outline-token-bar');
    const tokPrompt     = document.getElementById('tok-prompt');
    const tokCompletion = document.getElementById('tok-completion');
    const tokTotal      = document.getElementById('tok-total');

    let cumPrompt = 0, cumCompletion = 0;
    let startCh = 1, endCh = target, currentStart = 1;
    let regenerating = false;
    let batchSavedEnd = 0;
    let batchDisconnectDetail = '';
    let batchRequestId = '';
    let busyRequest = null;
    let requestStatusSupported = false;

    // 点击后立即反馈；进度查询也有期限，不能把按钮锁在一个不可见的请求上。
    outlineRunning = true;
    wrap.style.display     = '';
    btnOutline.disabled    = true;
    label.textContent      = '正在检查已保存的章节细纲...';
    streamBox.textContent  = '';
    batchLog.style.display = 'none';
    batchLog.innerHTML     = '';
    tokenBar.style.display = 'none';
    spinner.style.display  = '';

    const cursor = document.createElement('span');
    cursor.className = 'outline-stream-cursor';
    streamBox.appendChild(cursor);

    async function fetchOutlineProgress(chStart, chEnd, requestId = '') {
        const controller = new AbortController();
        const request = (async () => {
            const response = await fetch(apiRouteUrl('actions'), {
                method: 'POST', headers: jsonHeaders(), cache: 'no-store',
                body: JSON.stringify({ action: 'get_outline_progress', novel_id: novelId,
                    ...(chStart ? { start_chapter: chStart, end_chapter: chEnd } : {}),
                    ...(/^[a-f0-9]{12}$/.test(requestId) ? { request_id: requestId } : {}) }),
                signal: controller.signal,
            });
            let data;
            try { data = await response.json(); }
            catch { throw new Error('无法读取章节细纲进度，请刷新页面确认登录状态后重试'); }
            if (!response.ok || !data || !data.ok || !data.data || !Number.isFinite(Number(data.data.last_outlined))) {
                throw new Error((data && (data.msg || data.message || data.error)) || `查询章节细纲进度失败（HTTP ${response.status}）`);
            }
            if (Object.prototype.hasOwnProperty.call(data.data, 'request')) requestStatusSupported = true;
            return data.data;
        })();
        return waitForOutlineRequest(request, controller, OUTLINE_PROGRESS_TIMEOUT,
            '查询章节细纲进度超时，请稍后重试；尚未提交新的生成请求');
    }

    function requestState(progress, expectedId = '') {
        if (!Object.prototype.hasOwnProperty.call(progress, 'request')) {
            return expectedId && requestStatusSupported ? { request_id: expectedId, state: 'unknown', active: null } : null;
        }
        const request = progress.request;
        if (expectedId && (!request || request.request_id !== expectedId)) {
            // A free stable lock proves inactivity even when the status file is
            // missing or refers to another request. It proves no save receipt.
            return { request_id: expectedId, state: 'unknown', active: request?.active === false ? false : null };
        }
        if (request && typeof request === 'object' && ![true, false, null].includes(request.active)) {
            return { request_id: expectedId || request.request_id || '', state: 'unknown', active: null };
        }
        return request && typeof request === 'object' ? request : null;
    }

    function requestDetail(request) {
        if (!request) return '';
        const states = { queued: '等待后台启动', running: '仍在运行', saved: '已保存', failed: '失败', interrupted: '已中断', unknown: '暂无法确认' };
        const stages = { queued: '等待后台进程', authorized: '准备生成', model_check: '检查模型', context_prepare: '准备上下文',
            memory_lookup: '查询记忆', memory_ready: '上下文就绪', model_request: '等待模型输出',
            model_response: '收到模型响应', parse_result: '解析细纲', persist: '保存章节',
            base_saved: '基础细纲已保存', enhancement_queue: '安排质量增强', save_receipt: '保存已确认',
            response_finished: '生成响应已结束', postprocess: '质量增强', finished: '处理结束',
            rejected: '请求被拒绝', failed: '处理失败' };
        const details = [`后台状态：${states[request.state] || '暂无法确认'}`];
        if (request.stage) {
            const phase = request.stage === 'model_request' && request.first_output_seen === true
                ? (request.output_kind === 'thinking' ? '模型已开始思考，等待完整响应' : '模型已开始输出，等待完整响应')
                : stages[request.stage] || request.stage;
            details.push(`阶段：${phase}`);
        }
        const saved = Number(request.saved), expected = Number(request.expected);
        if (Number.isFinite(saved) && Number.isFinite(expected) && expected > 0) {
            details.push(`本次已保存 ${Math.max(0, saved)}/${expected} 章`);
        }
        if (/^[a-f0-9]{12}$/.test(request.request_id || '')) details.push(`追踪号 ${request.request_id}`);
        return details.join('；');
    }

    async function waitUntilRequestInactive(initialRequest, chStart, chEnd) {
        const requestId = /^[a-f0-9]{12}$/.test(initialRequest?.request_id || '') ? initialRequest.request_id : '';
        let request = initialRequest;
        const background = initialRequest?.execution === 'cli';
        const delay = background ? OUTLINE_BACKGROUND_POLL_DELAY : RECONNECT_DELAY;
        const limit = background ? Math.ceil(OUTLINE_BACKGROUND_WAIT / delay) : MAX_RECONNECTS;
        let unavailable = 0;
        for (let check = 1; check <= limit && outlineRunning; check++) {
            label.textContent = `${requestDetail(request)}。正在核对后台请求状态（第 ${check}/${limit} 次，仅查询）...`;
            await new Promise(resolve => setTimeout(resolve, delay));
            try {
                const progress = await fetchOutlineProgress(chStart, chEnd, requestId);
                request = requestState(progress, requestId);
                // Losing the status endpoint or its record cannot prove that an
                // already observed live request has stopped.
                if (request && request.active === false) return progress;
                unavailable = !request || request.state === 'unknown' || request.active === null ? unavailable + 1 : 0;
            } catch (error) {
                unavailable++;
                label.textContent = `${requestDetail(request)}。暂时无法核对请求状态，稍后继续查询...`;
            }
            if (unavailable >= MAX_RECONNECTS) break;
        }
        throw new Error(`${requestDetail(request)}。尚未确认已有请求结束，已停止自动等待；再次点击将继续核对活跃请求`);
    }

    // Poll only. An exact current-task receipt and the database's contiguous
    // prefix are both required, including when regenerating existing outlines.
    async function waitForBackgroundReceipt(requestId, chStart, chEnd) {
        if (!/^[a-f0-9]{12}$/.test(requestId)) throw new Error('未收到有效后台任务编号，已停止提交，请刷新后核对进度');
        const began = Date.now();
        let unavailable = 0, request = null;
        while (outlineRunning && Date.now() - began < OUTLINE_BACKGROUND_WAIT) {
            let progress;
            try {
                progress = await fetchOutlineProgress(chStart, chEnd, requestId);
                request = requestState(progress, requestId);
            } catch (error) {
                unavailable++;
                label.textContent = '后台任务已提交，暂时无法读取进度，正在继续查询...';
                if (unavailable >= MAX_RECONNECTS) break;
                await new Promise(resolve => setTimeout(resolve, OUTLINE_BACKGROUND_POLL_DELAY));
                continue;
            }
            label.textContent = `${requestDetail(request)}。后台生成中，正在定期查询进度...`;
            streamBox.textContent = '细纲由后台进程生成，保存后自动更新章节列表。';
            const known = request?.request_id === requestId && request.state !== 'unknown' && request.active !== null;
            unavailable = known ? 0 : unavailable + 1;
            if (known) {
                const actualEnd = Number(request.actual_end);
                const taskEnd = Number(request.end_chapter);
                const expected = taskEnd - chStart + 1;
                const validReceipt = request.state === 'saved' && request.start_chapter === chStart
                    && Number.isInteger(taskEnd) && taskEnd >= chStart && taskEnd <= chEnd
                    && request.expected === expected && Number.isInteger(request.saved)
                    && Number.isInteger(actualEnd) && actualEnd >= chStart && actualEnd <= taskEnd
                    && request.saved >= actualEnd - chStart + 1 && request.saved <= expected
                    && (actualEnd < taskEnd || request.receipt_complete === true)
                    && Number(progress.range_last_outlined) >= actualEnd;
                // Keep waiting through legacy enhancement writes before allowing
                // a new batch to enter this novel's single-writer section.
                if (validReceipt && request.active === false) {
                    batchLog.style.display = '';
                    const item = document.createElement('div');
                    item.className = 'outline-batch-item success';
                    item.textContent = `第 ${chStart}～${actualEnd} 章细纲已确认保存`;
                    batchLog.appendChild(item);
                    return actualEnd;
                }
                if (request.active === false) {
                    const reason = request.stage === 'queued'
                        ? '后台进程未能在启动期限内领取任务，请检查 PHP CLI 路径和进程启动权限'
                        : '当前批次未确认本次保存完成，已停止生成';
                    throw new Error(`${requestDetail(request)}；${reason}`);
                }
            }
            if (unavailable >= MAX_RECONNECTS) break;
            await new Promise(resolve => setTimeout(resolve, OUTLINE_BACKGROUND_POLL_DELAY));
        }
        throw new Error(`${requestDetail(request)}。已停止自动等待；后台任务可能仍在运行，再次点击会先核对原任务`);
    }

    /**
     * 读取并处理一段 SSE 流（currentStart ~ currentEnd）
     * 返回值：
     *   'complete'  — 收到结束事件，主循环还需确认保存回执
     *   'dropped'   — 连接中断（网络错误或流异常关闭）
     *   'busy'      — 后台已有请求，本次未启动生成，只等待其结束
     *   'queued'    — 当前批次已提交后台，通过短请求查询保存回执
     *   'aborted'   — 服务端明确失败或用户主动取消
     */
    async function runChunk(chStart, chEnd) {
        outlineController = new AbortController();
        const controller = outlineController;
        const startedAt = Date.now();
        let lastDataAt = null, requestId = '';
        function disconnected(reason) {
            const now = Date.now();
            const elapsed = Math.max(0, Math.floor((now - startedAt) / 1000));
            const idle = lastDataAt === null ? '尚未收到生成数据'
                : `断线时距最后收到数据 ${Math.max(0, Math.floor((now - lastDataAt) / 1000))} 秒（包含心跳等网络数据）`;
            batchDisconnectDetail = [reason, `断线时本批已运行 ${elapsed} 秒`, idle,
                requestId ? `追踪号 ${requestId}` : ''].filter(Boolean).join('；');
            label.textContent = batchDisconnectDetail;
            return 'dropped';
        }
        label.textContent = `正在连接服务器，准备生成第 ${chStart}～${chEnd} 章细纲...`;

        let response;
        try {
            response = await waitForOutlineRequest(fetch(apiRouteUrl('generate_outline'), {
                method:  'POST',
                headers: jsonHeaders(),
                body:    JSON.stringify({
                    novel_id:      novelId,
                    start_chapter: chStart,
                    end_chapter:   chEnd,
                }),
                signal: controller.signal,
            }), controller, OUTLINE_CONNECT_TIMEOUT, '连接生成服务超时，正在核对后台保存进度');
        } catch (fetchErr) {
            if (fetchErr.name === 'AbortError') return 'aborted';
            return disconnected(fetchErr.name === 'OutlineTimeoutError'
                ? '连接生成服务超时（30 秒未收到响应头）' : '网络请求异常，未能建立生成连接');
        }

        // 登录失效、权限或参数错误可能返回 HTTP 200 JSON，不能当作 SSE 一直重连。
        const contentType = response.headers.get('Content-Type') || '';
        if (!response.ok || !/text\/event-stream/i.test(contentType)) {
            let detail = '';
            if (/application\/json/i.test(contentType)) {
                try {
                    const payload = await waitForOutlineRequest(response.json(), controller, OUTLINE_PROGRESS_TIMEOUT,
                        '读取生成服务错误信息超时，请稍后重试');
                    detail = (payload && (payload.msg || payload.message || payload.error)) || '';
                } catch (error) {
                    if (error.name === 'OutlineTimeoutError') throw error;
                    detail = '生成服务返回了无效数据，请稍后重试';
                }
            }
            throw new Error(detail || (!response.ok ? `生成服务错误（HTTP ${response.status}）`
                : '服务器未返回生成数据，请刷新页面确认登录状态后重试'));
        }
        if (!response.body) throw new Error('生成服务未返回数据流，请稍后重试');

        // 连接已建立，立即显示"正在生成"
        label.textContent = `正在生成第 ${chStart}～${chEnd} 章大纲...`;

        const reader  = response.body.getReader();
        const decoder = new TextDecoder();
        let   buf = '', currentEvent = '';

        // 更新顶部标签
        const totalRange = endCh - startCh + 1;
        if (totalRange > OUTLINE_CHUNK) {
            label.textContent =
                `生成第 ${chStart}～${chEnd} 章（共 ${totalRange} 章，` +
                `已完成 ${chStart - startCh}）...`;
        } else {
            label.textContent = `正在生成第 ${chStart}～${chEnd} 章大纲...`;
        }

        readerLoop:
        while (true) {
            let readResult;
            try {
                readResult = await waitForOutlineRequest(reader.read(), controller, OUTLINE_STREAM_IDLE_TIMEOUT,
                    '生成连接长时间没有响应，正在核对后台保存进度');
            } catch (readError) {
                return disconnected(readError.name === 'OutlineTimeoutError'
                    ? '生成数据流静默超时（180 秒未收到数据）' : '生成数据流读取异常，连接或响应已中断');
            }

            const { value, done } = readResult;
            if (value && value.byteLength > 0) lastDataAt = Date.now();
            buf += done ? decoder.decode() : decoder.decode(value, { stream: true });
            // EOF 时也处理最后一条没有换行的事件，避免丢失落库回执。
            if (done && buf) buf += '\n';
            const lines = buf.split('\n');
            buf = lines.pop();

            for (const line of lines) {
                const trimmed = line.trim();

                // SSE 注释行（keepalive / ping），忽略
                if (trimmed.startsWith(':')) { currentEvent = ''; continue; }

                if (trimmed.startsWith('event:')) {
                    currentEvent = trimmed.slice(6).trim();
                    continue;
                }

                if (!trimmed.startsWith('data:')) continue;
                const raw = trimmed.slice(5).trimStart();
                if (raw === '[DONE]') break readerLoop;

                let d;
                try { d = JSON.parse(raw); } catch { currentEvent = ''; continue; }

                switch (currentEvent) {

                    case 'ping':
                    case 'waiting':
                        // 服务端调用 AI API 前的心跳，更新状态文字
                        label.textContent = d.msg || label.textContent;
                        break;

                    case 'thinking': {
                        label.textContent = d.msg || `AI 正在思考第 ${chStart}～${chEnd} 章细纲...`;
                        const thoughtText = d.thinking || d.t;
                        if (thoughtText) {
                            if (!streamBox.contains(cursor)) streamBox.appendChild(cursor);
                            const thought = document.createElement('span');
                            thought.className = 'text-muted';
                            thought.textContent = thoughtText;
                            streamBox.insertBefore(thought, cursor);
                            streamBox.scrollTop = streamBox.scrollHeight;
                        }
                        break;
                    }

                    case 'chunk':
                        if (d.t) {
                            // 确保 cursor 在 streamBox 中，避免 insertBefore 错误
                            if (!streamBox.contains(cursor)) {
                                streamBox.appendChild(cursor);
                            }
                            streamBox.insertBefore(document.createTextNode(d.t), cursor);
                            streamBox.scrollTop = streamBox.scrollHeight;
                        }
                        break;

                    case 'progress':
                        label.textContent = d.msg || '生成中...';
                        streamBox.textContent = '';
                        streamBox.appendChild(cursor);
                        break;

                    case 'model_switch': {
                        streamBox.textContent = '';
                        streamBox.appendChild(cursor);
                        batchLog.style.display = '';
                        const sw = document.createElement('div');
                        sw.className = 'outline-batch-item warning';
                        sw.innerHTML = `<span><i class="bi bi-arrow-repeat me-1"></i>${escHtml(d.msg)}</span>`;
                        batchLog.appendChild(sw);
                        label.textContent = d.msg;
                        showToast(`切换模型：${d.next_model}`, 'info');
                        break;
                    }

                    case 'batch_done': {
                        cumPrompt     += (d.prompt_tokens     || 0);
                        cumCompletion += (d.completion_tokens || 0);
                        // 记录后端已落库的最大章节号：章节在发出本事件前已写入数据库，
                        // 因此即便随后连接在"弧段摘要"阶段静默掉线，也能据此安全推进，
                        // 不再依赖 [DONE] 是否抵达或二次进度查询是否成功。
                        batchSavedEnd = Math.max(batchSavedEnd, parseInt(d.actual_end) || 0);
                        batchSavedEnd = Math.min(batchSavedEnd, chEnd);
                        // MAX(actual_end) 不能跳过批内缺口；只推进连续保存的部分。
                        const gaps = Array.isArray(d.gaps) ? d.gaps.map(Number).filter(n => n >= chStart && n <= batchSavedEnd) : [];
                        if (gaps.length) batchSavedEnd = Math.min(batchSavedEnd, Math.min(...gaps) - 1);
                        tokenBar.style.display = '';
                        tokPrompt.textContent     = fmtNum(cumPrompt);
                        tokCompletion.textContent = fmtNum(cumCompletion);
                        tokTotal.textContent      = fmtNum(cumPrompt + cumCompletion);

                        batchLog.style.display = '';
                        const item = document.createElement('div');
                        item.className = 'outline-batch-item success';
                        item.innerHTML =
                            `<span><i class="bi bi-check-circle me-1"></i>${escHtml(d.msg)}</span>` +
                            `<span class="token-badge">` +
                            `<span>↑ ${fmtNum(d.prompt_tokens)}</span>` +
                            `<span>↓ ${fmtNum(d.completion_tokens)}</span>` +
                            `<span>∑ ${fmtNum(d.total_tokens)}</span>` +
                            `</span>`;
                        batchLog.appendChild(item);
                        batchLog.scrollTop = batchLog.scrollHeight;
                        label.textContent = d.msg;
                        break;
                    }

                    case 'heartbeat':
                        if (d.msg && d.msg !== 'keep-alive') label.textContent = d.msg;
                        break;

                    case 'init':
                        // SSE 连接确认
                        if (typeof d.request_id === 'string') {
                            requestId = d.request_id.slice(0, 100);
                            if (/^[a-f0-9]{12}$/.test(requestId)) batchRequestId = requestId;
                        }
                        break;

                    case 'request_active':
                        requestStatusSupported = true;
                        busyRequest = requestState({ request: d.request }) || { state: 'unknown', active: null };
                        label.textContent = `${d.msg || '已有请求仍在运行'}。${requestDetail(busyRequest)}`;
                        return 'busy';

                    case 'request_queued':
                        requestStatusSupported = true;
                        if (!/^[a-f0-9]{12}$/.test(d.request_id || '') || (batchRequestId && batchRequestId !== d.request_id)) {
                            throw new Error('后台任务编号不一致，已停止提交，请刷新后核对进度');
                        }
                        batchRequestId = d.request_id;
                        label.textContent = d.msg || '后台细纲任务已提交';
                        // The queue acknowledgement remains valid even if the
                        // already closing HTTP reader rejects its cleanup.
                        try { if (typeof reader.cancel === 'function') await reader.cancel(); } catch {}
                        return 'queued';

                    case 'fatal_error': {
                        batchLog.style.display = '';
                        const fei = document.createElement('div');
                        fei.className = 'outline-batch-item error';
                        fei.textContent = `服务端错误: ${d.error || d.msg || '未知错误'}`;
                        batchLog.appendChild(fei);
                        showToast(`服务端错误: ${d.error || d.msg || '未知错误'}`, 'error');
                        spinner.style.display = 'none';
                        label.textContent = '生成失败';
                        return 'aborted';
                    }

                    case 'error': {
                        batchLog.style.display = '';
                        const ei = document.createElement('div');
                        ei.className = 'outline-batch-item error';
                        ei.textContent = d.msg || d.message || d.error || '章节细纲生成失败';
                        batchLog.appendChild(ei);
                        showToast(ei.textContent, 'error');
                        label.textContent = ei.textContent;
                        return 'aborted';
                    }

                    case 'complete':
                        spinner.style.display = 'none';
                        label.textContent = d.msg;
                        return 'complete';   // ✅ 正常完成
                }

                currentEvent = '';
            }
            if (done) return disconnected('生成数据流提前结束，未收到完成回执');
        }

        // [DONE] 仅说明响应结束，主循环仍必须检查落库回执，不能据此跳过失败章节。
        return 'complete';
    }

    // ================================================================
    // 主循环：分段调用 runChunk，失败时自动恢复
    // ================================================================
    try {
        if (!Number.isInteger(novelId) || novelId < 1 || !Number.isInteger(target) || target < 1) {
            throw new Error('小说或目标章节数无效，请刷新页面后重试');
        }
        let progress = await fetchOutlineProgress();
        const activeRequest = requestState(progress);
        if (activeRequest && activeRequest.active !== false) {
            progress = await waitUntilRequestInactive(activeRequest);
        }
        const outlined = Math.max(0, Number(progress.contiguous_outlined ?? progress.last_outlined));
        btnOutline.dataset.outlined = String(outlined);
        if (outlined > 0) {
            const choice = confirm(
                `当前已有 ${outlined} 章大纲。\n` +
                `点击【确定】追加生成第 ${outlined + 1}～${target} 章大纲，\n` +
                `点击【取消】重新生成全部大纲。`
            );
            if (choice) startCh = outlined + 1;
            else regenerating = true;
        }
        if (startCh > endCh) {
            label.textContent = '所有章节大纲已生成';
            showToast('所有章节大纲已生成', 'info');
            return;
        }
        currentStart = startCh;
        while (currentStart <= endCh && outlineRunning) {
            const currentEnd = Math.min(endCh, currentStart + OUTLINE_CHUNK - 1);

            batchSavedEnd = 0;                          // 重置本段落库进度
            batchDisconnectDetail = '';
            batchRequestId = '';
            busyRequest = null;
            const result = await runChunk(currentStart, currentEnd);

            if (result === 'aborted') break;
            if (result === 'busy') {
                const settled = await waitUntilRequestInactive(busyRequest, currentStart, currentEnd);
                // Another request may have completed this exact queried range.
                // Its actual_end could belong to another batch and is never used
                // to skip chapters. Explicit regeneration retains its own intent.
                if (!regenerating && settled.range_complete === true) {
                    currentStart = currentEnd + 1;
                    btnOutline.dataset.outlined = String(currentEnd);
                    if (currentStart <= endCh) await new Promise(resolve => setTimeout(resolve, 800));
                }
                streamBox.textContent = '';
                streamBox.appendChild(cursor);
                continue;
            }
            if (result === 'queued') {
                batchSavedEnd = await waitForBackgroundReceipt(batchRequestId, currentStart, currentEnd);
            }

            // 权威进度推进：只要后端发过 batch_done（章节已落库），无论 [DONE] 是否抵达、
            // 连接是否在弧段摘要阶段静默掉线、二次进度查询是否成功，都按已保存的
            // actual_end 前进。这从根本上消除"章节已保存却仍反复重生成同一批"的死循环，
            // 同时也修正了截断批次（实存 < 请求）会被跳过的问题。
            if (batchSavedEnd >= currentStart) {
                const prevStart = currentStart;
                currentStart = batchSavedEnd + 1;
                btnOutline.dataset.outlined = String(batchSavedEnd);

                if (currentStart <= endCh && outlineRunning) {
                    label.textContent = `第 ${prevStart}～${batchSavedEnd} 章已完成，稍后继续...`;
                    await new Promise(r => setTimeout(r, 800));
                    streamBox.textContent = '';
                    streamBox.appendChild(cursor);
                }
                continue;
            }

            if (result === 'complete') {
                throw new Error(`第 ${currentStart}～${currentEnd} 章未收到保存成功回执，已停止后续生成，请检查章节列表后重试`);
            }

            // 请求断开不等于后台停止。旧版本重复 POST 会覆盖已保存内容，并能无限重试。
            // 重新生成时旧数据不能证明本次成功，因此不以旧章节计数自动续接。
            if (regenerating && !requestStatusSupported) {
                throw new Error(batchDisconnectDetail + '。后台可能仍在保存，请稍后刷新章节列表确认结果');
            }
            let recovered = false;
            let lastRequest = null;
            for (let reconnects = 1; reconnects <= MAX_RECONNECTS && outlineRunning; reconnects++) {
                const detail = requestDetail(lastRequest);
                label.textContent = `${batchDisconnectDetail}。${detail ? detail + '。' : ''}正在核对后台保存进度（第 ${reconnects}/${MAX_RECONNECTS} 次查询）...`;
                await new Promise(resolve => setTimeout(resolve, RECONNECT_DELAY));
                let saved;
                try {
                    saved = await fetchOutlineProgress(currentStart, currentEnd, batchRequestId);
                } catch (progressError) {
                    label.textContent = `${batchDisconnectDetail}。暂时无法核对保存进度（第 ${reconnects}/${MAX_RECONNECTS} 次查询），稍后继续查询...`;
                    continue;
                }
                lastRequest = requestState(saved, batchRequestId);
                // A complete range may include another writer's chapter. The
                // exact failed request takes priority over aggregate row counts.
                if (lastRequest?.active === false && ['failed', 'interrupted'].includes(lastRequest.state)) {
                    throw new Error(`${batchDisconnectDetail}。${requestDetail(lastRequest)}；当前批次未确认本次保存完成，已停止生成`);
                }
                if (batchRequestId && lastRequest?.execution === 'cli' && lastRequest.request_id === batchRequestId) {
                    const savedEnd = await waitForBackgroundReceipt(batchRequestId, currentStart, currentEnd);
                    currentStart = savedEnd + 1;
                    btnOutline.dataset.outlined = String(savedEnd);
                    recovered = true;
                    break;
                }
                const lastSaved = Number(saved.range_last_outlined ?? saved.last_outlined);
                const rangeComplete = saved.range_complete === true
                    || (saved.range_complete === undefined && lastSaved >= currentEnd);
                const unknown = lastRequest && (lastRequest.state === 'unknown' || lastRequest.active === null);
                const regenerationConfirmed = batchRequestId && lastRequest?.request_id === batchRequestId
                    && lastRequest.state === 'saved' && lastRequest.receipt_complete === true && saved.range_complete === true;
                if (rangeComplete && !unknown && (!regenerating || regenerationConfirmed)) {
                    currentStart = currentEnd + 1;
                    btnOutline.dataset.outlined = String(currentStart - 1);
                    recovered = true;
                    break;
                }
            }
            if (!recovered) {
                throw new Error(batchDisconnectDetail + '。' + requestDetail(lastRequest) + '。暂未确认当前批次保存完成，已停止自动等待。后台可能仍在生成，请稍后刷新章节列表再继续');
            }
            streamBox.textContent = '';
            streamBox.appendChild(cursor);
        }

        // 全部完成
        if (outlineRunning && currentStart > endCh) {
            cursor.remove();
            spinner.style.display = 'none';
            const done = Math.min(endCh, currentStart - 1) - startCh + 1;
            label.textContent = `✓ 大纲生成完成！共生成 ${done} 章`;
            showToast('大纲生成完成！', 'success');
            setTimeout(() => location.reload(), 1800);
        }

    } catch (err) {
        if (err.name !== 'AbortError') {
            showToast('生成出错：' + err.message, 'error');
            label.textContent = '出错：' + err.message;
        }
        spinner.style.display = 'none';
    } finally {
        cursor.remove();
        spinner.style.display = 'none';
        outlineRunning      = false;
        btnOutline.disabled = false;
        outlineController = null;
    }
}
// CHAPTER_OUTLINE_CLIENT_END

// ============================================================
// 编辑故事大纲
// ============================================================

async function editStoryOutline() {
    const novelId = parseInt(document.getElementById('btn-edit-story-outline').dataset.novel);
    const modal = new bootstrap.Modal(document.getElementById('storyOutlineModal'));

    document.getElementById('edit-story-arc').value = '';
    document.getElementById('edit-character-arcs').value = '';
    document.getElementById('edit-character-endpoints').value = '';
    document.getElementById('edit-world-evolution').value = '';

    try {
        const data = await apiGet('get_story_outline', { novel_id: novelId });
        if (data.ok && data.data) {
            document.getElementById('edit-story-arc').value = data.data.story_arc || '';
            document.getElementById('edit-character-arcs').value = data.data.character_arcs || '';
            document.getElementById('edit-character-endpoints').value = data.data.character_endpoints || '';
            document.getElementById('edit-world-evolution').value = data.data.world_evolution || '';
        } else if (!data.ok && (data.error || data.msg) && (data.error || data.msg) !== '暂无故事大纲') {
            showToast(data.error || data.msg, 'error');
        }
    } catch (e) {
        console.error('加载故事大纲失败:', e);
        showToast('加载故事大纲失败：' + e.message, 'error');
    }

    modal.show();
}

async function saveStoryOutline() {
    const novelId = parseInt(document.getElementById('edit-novel-id').value);
    const storyArc = document.getElementById('edit-story-arc').value.trim();
    const characterArcs = document.getElementById('edit-character-arcs').value.trim();
    const characterEndpoints = document.getElementById('edit-character-endpoints').value.trim();
    const worldEvolution = document.getElementById('edit-world-evolution').value.trim();

    if (!storyArc) {
        showToast('请填写故事主线', 'error');
        return;
    }

    const btn = document.getElementById('btn-save-story-outline');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>保存中...';

    try {
        const res = await apiPost('update_story_outline', {
            novel_id: novelId,
            story_arc: storyArc,
            character_arcs: characterArcs,
            character_endpoints: characterEndpoints,
            world_evolution: worldEvolution
        });

        if (res.ok) {
            showToast('故事大纲已保存', 'success');
            bootstrap.Modal.getInstance(document.getElementById('storyOutlineModal')).hide();
            // 刷新页面显示更新后的大纲
            location.reload();
        } else {
            showToast(res.error || res.msg || '保存失败', 'error');
        }
    } catch (e) {
        showToast('保存失败: ' + e.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>保存';
    }
}

async function regenerateStoryOutline() {
    const controller = getStoryOutlineTaskController();
    if (controller) await controller.regenerate();
}

// ============================================================
// 编辑章节概要
// ============================================================

async function editChapterSynopsis(novelId, chapterNumber) {
    const modal = new bootstrap.Modal(document.getElementById('chapterSynopsisModal'));
    document.getElementById('edit-synopsis-novel-id').value = novelId;
    document.getElementById('edit-synopsis-chapter').value = chapterNumber;

    // 加载现有数据
    try {
        const res = await apiGet('get_chapter_synopsis', {
            novel_id: novelId,
            chapter_number: chapterNumber
        });
        if (res.ok && res.data) {
            document.getElementById('edit-synopsis-text').value = res.data.synopsis || '';
            document.getElementById('edit-synopsis-pacing').value = res.data.pacing || '';
            document.getElementById('edit-synopsis-cliffhanger').value = res.data.cliffhanger || '';
        } else {
            // 清空表单
            document.getElementById('edit-synopsis-text').value = '';
            document.getElementById('edit-synopsis-pacing').value = '';
            document.getElementById('edit-synopsis-cliffhanger').value = '';
        }
    } catch (e) {
        console.error('加载章节概要失败:', e);
    }

    modal.show();
}

async function saveChapterSynopsis() {
    const novelId = parseInt(document.getElementById('edit-synopsis-novel-id').value);
    const chapterNumber = parseInt(document.getElementById('edit-synopsis-chapter').value);
    const synopsis = document.getElementById('edit-synopsis-text').value.trim();
    const pacing = document.getElementById('edit-synopsis-pacing').value;
    const cliffhanger = document.getElementById('edit-synopsis-cliffhanger').value.trim();

    if (!synopsis) {
        showToast('请填写章节概要', 'error');
        return;
    }

    const btn = document.getElementById('btn-save-chapter-synopsis');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>保存中...';

    try {
        const res = await apiPost('update_chapter_synopsis', {
            novel_id: novelId,
            chapter_number: chapterNumber,
            synopsis: synopsis,
            pacing: pacing,
            cliffhanger: cliffhanger
        });

        if (res.ok) {
            showToast('章节概要已保存', 'success');
            bootstrap.Modal.getInstance(document.getElementById('chapterSynopsisModal')).hide();
            // 刷新页面显示更新后的概要
            location.reload();
        } else {
            showToast(res.error || res.msg || '保存失败', 'error');
        }
    } catch (e) {
        showToast('保存失败: ' + e.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>保存';
    }
}

// ============================================================
// 章节概要优化功能
// ============================================================

// 存储优化后的结果
let optimizedSynopsis = null;

async function openOptimizeSynopsis(novelId, chapterNumber) {
    // 重置状态
    optimizedSynopsis = null;
    document.getElementById('optimize-suggestions').value = '';
    document.getElementById('optimize-result-section').style.display = 'none';
    document.getElementById('btn-confirm-optimize').style.display = 'none';
    document.getElementById('btn-generate-optimize').style.display = 'inline-block';
    
    // 设置隐藏字段
    document.getElementById('optimize-novel-id').value = novelId;
    document.getElementById('optimize-chapter').value = chapterNumber;
    
    // 加载当前章节概要
    try {
        const res = await apiGet('get_chapter_synopsis', {
            novel_id: novelId,
            chapter_number: chapterNumber
        });
        
        if (res.ok && res.data) {
            document.getElementById('optimize-current-synopsis').textContent = res.data.synopsis || '暂无概要';
        } else {
            document.getElementById('optimize-current-synopsis').textContent = '暂无概要';
        }
    } catch (e) {
        console.error('加载章节概要失败:', e);
        document.getElementById('optimize-current-synopsis').textContent = '加载失败';
    }
    
    // 显示模态框
    const modal = new bootstrap.Modal(document.getElementById('optimizeSynopsisModal'));
    modal.show();
}

async function generateOptimizedSynopsis() {
    const novelId = parseInt(document.getElementById('optimize-novel-id').value);
    const chapterNumber = parseInt(document.getElementById('optimize-chapter').value);
    const suggestions = document.getElementById('optimize-suggestions').value.trim();
    
    if (!suggestions) {
        showToast('请输入优化意见', 'error');
        return;
    }
    
    const btn = document.getElementById('btn-generate-optimize');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>生成中...';
    
    try {
        const res = await apiPost('optimize_chapter_synopsis', {
            novel_id: novelId,
            chapter_number: chapterNumber,
            suggestions: suggestions,
            preview_only: true
        });
        
        if (res.ok && res.data) {
            optimizedSynopsis = { ...res.data, source_revision: res.source_revision };
            
            // 显示优化结果
            document.getElementById('optimize-result-synopsis').textContent = res.data.synopsis || '生成失败';
            document.getElementById('optimize-result-section').style.display = 'block';
            document.getElementById('btn-confirm-optimize').style.display = 'inline-block';
            document.getElementById('btn-generate-optimize').style.display = 'none';
            
            showToast('优化完成，请确认是否采用', 'success');
        } else {
            showToast(res.error || res.msg || '优化失败', 'error');
        }
    } catch (e) {
        showToast('优化失败: ' + e.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-magic me-1"></i>生成优化';
    }
}

async function confirmOptimizedSynopsis() {
    if (!optimizedSynopsis) {
        showToast('没有可确认的优化结果', 'error');
        return;
    }
    
    const novelId = parseInt(document.getElementById('optimize-novel-id').value);
    const chapterNumber = parseInt(document.getElementById('optimize-chapter').value);
    
    const btn = document.getElementById('btn-confirm-optimize');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>保存中...';
    
    try {
        const payload = {
            novel_id: novelId,
            chapter_number: chapterNumber,
            synopsis: optimizedSynopsis.synopsis,
            optimization: true,
            source_revision: optimizedSynopsis.source_revision
        };
        for (const field of ['pacing', 'cliffhanger', 'scene_breakdown', 'dialogue_beats', 'sensory_details', 'foreshadowing', 'callbacks']) {
            if (Object.prototype.hasOwnProperty.call(optimizedSynopsis, field)) payload[field] = optimizedSynopsis[field];
        }
        const res = await apiPost('update_chapter_synopsis', payload);
        
        if (res.ok) {
            showToast('优化后的章节概要已保存', 'success');
            bootstrap.Modal.getInstance(document.getElementById('optimizeSynopsisModal')).hide();
            // 刷新页面显示更新后的概要
            location.reload();
        } else {
            showToast(res.error || res.msg || '保存失败', 'error');
        }
    } catch (e) {
        showToast('保存失败: ' + e.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>确认采用';
    }
}

// ============================================================
// [v2新增] 生成全书故事大纲和章节概要的函数在文件末尾定义
// ============================================================

// 数字格式化
function fmtNum(n) {
    return n >= 10000
        ? (n / 10000).toFixed(1) + 'w'
        : n.toLocaleString();
}

// HTML 转义
// 审计修复 M-2（2026-06-22）：统一 escapeHtml 实现，转义 5 字符（& < > " '），
// 既可用于文本节点也可用于属性上下文。原实现仅转义 3 字符，用于属性上下文有 XSS 风险。
function escHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, m => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[m]));
}

// ============================================================
// 自动写作
// ============================================================

// 用 generation token 解决 "暂停后立即重启" 导致两个循环并行的问题
let autoWriteRunning = false;
let autoWriteStop    = false;
let autoWriteGen     = 0;   // 每次启动递增，用于废弃旧循环
let autoWriteStateQueue = Promise.resolve();
// State-changing requests are ordered, so an older completion/cancel cannot land
// after the next generation has already set the novel to writing.
function queueAutoWriteStateChange(operation) {
    const pending = autoWriteStateQueue.then(operation);
    autoWriteStateQueue = pending.catch(() => {});
    return pending;
}
let lastWrittenText  = '';  // 断点续写：保存已生成的内容
let lastChapterNum   = 0;   // 断点续写：当前章节号

// UI 引用（懒初始化）
function _aw() {
    return {
        btnAuto : document.getElementById('btn-autowrite'),
        btnNext : document.getElementById('btn-next-chapter'),
        wrap    : document.getElementById('write-progress-wrap'),
        label   : document.getElementById('write-progress-label'),
        detail  : document.getElementById('write-progress-detail'),
        bar     : document.getElementById('write-progress-bar'),
        spinner : document.getElementById('write-spinner'),
        stream  : document.getElementById('write-stream-box'),
        cursor  : document.getElementById('write-cursor'),
        modelLbl: document.getElementById('write-model-label'),
    };
}

function setAutoWriteUI(running) {
    const { btnAuto } = _aw();
    if (!btnAuto) return;
    if (running) {
        btnAuto.innerHTML      = '<i class="bi bi-pause-fill me-1"></i>暂停写作';
        btnAuto.dataset.status = 'writing';
        btnAuto.classList.remove('btn-primary');
        btnAuto.classList.add('btn-warning');
    } else {
        btnAuto.innerHTML      = '<i class="bi bi-play-fill me-1"></i>自动写作';
        btnAuto.dataset.status = 'idle';
        btnAuto.classList.remove('btn-warning');
        btnAuto.classList.add('btn-primary');
    }
}

async function startAutoWrite() {
    // 如果正在运行 → 暂停
    if (autoWriteRunning) { stopAutoWrite(); return; }

    autoWriteRunning = true;
    autoWriteStop    = false;
    const myGen      = ++autoWriteGen;   // 本次运行的代号
    const isCurrent = () => myGen === autoWriteGen && !autoWriteStop;

    const ui = _aw();
    const missingUi = Object.entries(ui)
        .filter(([key, value]) => key !== 'cursor' && !value)
        .map(([key]) => key);
    if (missingUi.length > 0) {
        autoWriteRunning = false;
        autoWriteStop = true;
        console.error('[autowrite] 写作面板不完整:', missingUi);
        showToast('自动写作面板加载不完整，请刷新页面后重试', 'error');
        return;
    }
    setAutoWriteUI(true);
    ui.wrap.style.display = '';
    ui.stream.textContent = '';
    if (ui.cursor) ui.stream.appendChild(ui.cursor);
    ui.spinner.style.display = '';
    ui.label.textContent = '正在启动自动写作...';

    try {
        const statusRes = await queueAutoWriteStateChange(() => isCurrent() ? apiPost('actions', {
            action: 'update_novel_status', novel_id: NOVEL_ID, status: 'writing'
        }) : null);
        if (!isCurrent()) return;
        if (!statusRes || !statusRes.ok) {
            throw new Error(statusRes?.msg || statusRes?.error || '无法更新小说写作状态');
        }
        const promotedWarnings = Number(statusRes.data?.promoted_quality_warnings || 0);
        if (promotedWarnings > 0) {
            showToast(`已将 ${promotedWarnings} 个历史质检失败章转为提示，将继续向后写作`, 'warning');
        }
    } catch (e) {
        if (!isCurrent()) return;
        showToast('启动写作失败：' + e.message, 'error');
        autoWriteRunning = false;
        setAutoWriteUI(false);
        ui.spinner.style.display = 'none';
        return;
    }

    let finalStatus = 'paused';
    let consecutiveErrors = 0;
    let consecutiveStatusErrors = 0;
    const AUTOWRITE_MAX_ERRORS = 3;
    const AUTOWRITE_MAX_STATUS_ERRORS = 5;

    while (!autoWriteStop && myGen === autoWriteGen) {
        // 查询下一章
        let res;
        try {
            res = await apiPost('actions', {
                action: 'get_novel_status', novel_id: NOVEL_ID
            });
        } catch (e) {
            if (!isCurrent()) break;
            ui.label.textContent = '查询状态失败，正在重试...';
            await new Promise(r => setTimeout(r, 3000));
            continue;
        }

        if (!isCurrent()) break;

        if (!res.ok) {
            consecutiveStatusErrors++;
            const statusError = res.msg || res.error || '未知错误';
            ui.label.textContent = `查询状态失败（${consecutiveStatusErrors}/${AUTOWRITE_MAX_STATUS_ERRORS}）：${statusError}`;
            if (consecutiveStatusErrors >= AUTOWRITE_MAX_STATUS_ERRORS) {
                finalStatus = 'paused';
                showToast('自动写作状态连续查询失败，已暂停：' + statusError, 'error');
                break;
            }
            await new Promise(r => setTimeout(r, 3000));
            continue;
        }
        consecutiveStatusErrors = 0;

        if (!res.data.next_chapter) {
            ui.spinner.style.display = 'none';
            if (res.data.all_done) {
                ui.label.textContent = '所有章节写作完成！';
                finalStatus = 'completed';
                showToast('全部章节已生成完毕！', 'success');
            } else if (res.data.blocked_by_failed && res.data.blocked_by_failed.chapter_number) {
                // 审计修复（2026-08-17 写作逻辑 B0）：进度线被质检 failed 章堵住时，
                // 明确指出该章并给出处理入口，而不是笼统报「没有可写章节」。
                const failedNum = res.data.blocked_by_failed.chapter_number;
                ui.label.textContent = `自动写作已暂停：第${failedNum}章质检未过，请到章节列表该章「保留正文重写」或「清空重写」后继续`;
                finalStatus = 'paused';
                showToast(`第${failedNum}章质检未过，自动写作已暂停`, 'warning');
            } else {
                const manualCount = Number(res.data.manual_backfill_count || 0);
                ui.label.textContent = manualCount > 0
                    ? `自动写作已暂停：${manualCount} 章位于完成进度线之前，请人工选择补写`
                    : '自动写作已暂停：仍有未完成章节，但当前没有可安全自动写作的章节';
                finalStatus = 'paused';
                showToast('检测到需要人工处理的历史章节，已停止自动倒序补写', 'warning');
            }
            break;
        }

        const { next_chapter, completed_count, outlined_count } = res.data;
        const pct = outlined_count > 0 ? Math.round(completed_count / outlined_count * 100) : 0;

        ui.label.textContent  = `正在写作 第${next_chapter.chapter_number}章《${next_chapter.title}》`;
        ui.bar.style.width    = pct + '%';
        ui.detail.textContent = `已完成 ${completed_count} / ${outlined_count} 章`;

        // 新章节开始时清空缓存
        if (lastChapterNum !== next_chapter.chapter_number) {
            lastWrittenText = '';
            lastChapterNum = next_chapter.chapter_number;
            ui.stream.textContent = '';
            if (ui.cursor) ui.stream.appendChild(ui.cursor);
            // 修复（2026-06-18）：新章节开始写作时立即刷新章节列表，
            // 让"写作中"徽章实时显示。异步刷新不阻塞写作流程。
            refreshChapterList();
        } else {
            // 断点续写：恢复已生成的内容
            if (lastWrittenText && ui.stream.textContent.trim() === '') {
                ui.stream.textContent = lastWrittenText;
                if (ui.cursor) ui.stream.appendChild(ui.cursor);
            }
        }

        try {
            if (!isCurrent()) break;
            await streamWriteChapter(
                NOVEL_ID,
                null,
                // onComplete 回调
                async (statsText, chapId, modelUsed) => {
                    if (!isCurrent()) return;
                    ui.detail.textContent = statsText;
                    if (modelUsed) ui.modelLbl.textContent = `模型：${modelUsed}`;
                    // 章节完成，清空缓存
                    lastWrittenText = '';
                    lastChapterNum = 0;
                    // 自动提取章节记忆
                    if (chapId && typeof MemoryUI !== 'undefined' && MemoryUI.autoExtractChapterMemory) {
                        await MemoryUI.autoExtractChapterMemory(chapId);
                    }
                },
                // 实时 chunk 显示
                ui.stream,
                ui.cursor,
                isCurrent
            );
        } catch (err) {
            if (!isCurrent()) break;
            // 保存已生成的内容用于断点续写
            lastWrittenText = ui.stream.textContent || lastWrittenText;
            consecutiveErrors++;
            showToast('写作出错：' + err.message, 'error');
            ui.label.textContent = '写作出错：' + err.message;
            // 熔断：连续失败多为永久性问题（模型未配置/任务冲突），无限重试只会刷请求
            if (consecutiveErrors >= AUTOWRITE_MAX_ERRORS) {
                ui.label.textContent = `连续 ${consecutiveErrors} 次写作失败，已自动暂停：` + err.message;
                ui.spinner.style.display = 'none';
                showToast(`连续 ${AUTOWRITE_MAX_ERRORS} 次失败，自动写作已暂停`, 'error');
                finalStatus = 'paused';
                break;
            }
            // 指数退避重试（5s → 15s → 45s），临时网络问题给恢复时间
            if (!autoWriteStop && myGen === autoWriteGen) {
                const delay = 5000 * Math.pow(3, consecutiveErrors - 1);
                // Keep the actual failure (including its request ID) visible
                // throughout the backoff; the transient toast disappears first.
                ui.label.textContent = `写作出错：${err.message}；${delay / 1000}秒后自动重试（第 ${consecutiveErrors}/${AUTOWRITE_MAX_ERRORS} 次失败，已保留${lastWrittenText.length}字）...`;
                await new Promise(r => setTimeout(r, delay));
                continue;
            }
            break;
        }

        if (!isCurrent()) break;

        consecutiveErrors = 0; // 本章成功，清零熔断计数

        // 每写完一章立即局部刷新章节列表。原先只在整轮循环结束（停止/写完全书）后
        // 刷新一次（见循环外 refreshChapterList），导致连续写作时新完成的章节迟迟不出现。
        await refreshChapterList();

        if (autoWriteStop || myGen !== autoWriteGen) break;
        await new Promise(r => setTimeout(r, 1500));
    }

    // 只有本代循环才做收尾
    if (myGen !== autoWriteGen) return;

    try {
        await queueAutoWriteStateChange(() => isCurrent() ? apiPost('actions', {
            action: 'update_novel_status', novel_id: NOVEL_ID, status: finalStatus
        }) : null);
    } catch (error) {
        if (isCurrent()) showToast('保存写作状态失败：' + error.message, 'error');
    }
    if (!isCurrent()) return;
    autoWriteRunning = false;
    setAutoWriteUI(false);
    ui.spinner.style.display = 'none';

    // 刷新章节列表（不销毁进度面板）
    await refreshChapterList();
}

window.stopAutoWriteLocal = function() {
    ++autoWriteGen;
    autoWriteStop = true;
    autoWriteRunning = false;
    setAutoWriteUI(false);
    const { spinner } = _aw();
    if (spinner) spinner.style.display = 'none';
};

window.stopAutoWrite = function() {
    stopAutoWriteLocal();
    const stoppedGen = autoWriteGen;
    showToast('已停止写作');
    if (typeof NOVEL_ID === 'undefined' || !NOVEL_ID) return;
    queueAutoWriteStateChange(() => apiPost('cancel_write', {
        action: 'cancel',
        novel_id: NOVEL_ID
    })).then((data) => {
        if (stoppedGen === autoWriteGen && data && data.ok && typeof refreshChapterList === 'function') {
            refreshChapterList().catch(() => {});
        }
    }).catch(() => {});
};

// 审计修复 P2-16（2026-07-12）：自动写作期间页面卸载时通知后端暂停
// 避免 novel.status 卡在 'writing'、后台 worker orphan。
// 用 fetch + keepalive 而非 sendBeacon，以便携带 X-CSRF-Token 头。
window.addEventListener('beforeunload', function(e) {
    if (!autoWriteRunning) return;
    if (typeof NOVEL_ID === 'undefined' || !NOVEL_ID) return;
    try {
        fetch(apiRouteUrl('cancel_write'), {
            method: 'POST',
            headers: jsonHeaders(),
            body: JSON.stringify({
                action: 'cancel',
                novel_id: NOVEL_ID
            }),
            keepalive: true,
            credentials: 'same-origin',
        }).catch(() => {});
    } catch (_) {}
    // 部分浏览器会弹出原生确认提示
    e.preventDefault();
    e.returnValue = '';
});

function getChapterPageFromUrl() {
    const params = new URLSearchParams(location.search);
    return Math.max(1, parseInt(params.get('page') || '1', 10) || 1);
}

function getCurrentChapterPage() {
    const root = document.getElementById('chapter-list-ajax-root');
    return Math.max(1, parseInt(root?.dataset.currentPage || getChapterPageFromUrl(), 10) || 1);
}

function bindChapterListEvents(container = document.getElementById('tab-chapters')) {
    if (!container) return;

    container.querySelectorAll('.btn-branch-workbench').forEach(btn => {
        if (btn.dataset.ajaxBound === '1') return;
        btn.dataset.ajaxBound = '1';
        btn.addEventListener('click', () => openBranchWorkbench());
    });

    container.querySelectorAll('.btn-write-single').forEach(btn => {
        if (btn.dataset.ajaxBound === '1') return;
        btn.dataset.ajaxBound = '1';
        btn.addEventListener('click', () => {
            writeSingleChapter(NOVEL_ID, parseInt(btn.dataset.chapter, 10));
        });
    });

    container.querySelectorAll('.btn-candidate-review').forEach(btn => {
        if (btn.dataset.ajaxBound === '1') return;
        btn.dataset.ajaxBound = '1';
        btn.addEventListener('click', () => {
            openCandidateReview(
                parseInt(btn.dataset.chapterId, 10),
                parseInt(btn.dataset.chapterNum, 10)
            );
        });
    });

    container.querySelectorAll('.chapter-list-page-link[data-ajax-page]').forEach(link => {
        if (link.dataset.ajaxBound === '1') return;
        link.dataset.ajaxBound = '1';
        link.addEventListener('click', (event) => {
            event.preventDefault();
            const page = parseInt(link.dataset.ajaxPage, 10) || 1;
            loadChapterList(page, true);
        });
    });
}

const branchWorkbenchState = {
    payload: null,
    branch: null,
    head: null,
    revision: null,
    editorRequest: 0,
};

function ensureBranchWorkbenchModal() {
    let modal = document.getElementById('branchWorkbenchModal');
    if (modal) return modal;
    modal = document.createElement('div');
    modal.id = 'branchWorkbenchModal';
    modal.className = 'modal fade';
    modal.tabIndex = -1;
    modal.setAttribute('aria-hidden', 'true');
    modal.innerHTML = `
      <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content bg-dark text-light">
          <div class="modal-header border-secondary">
            <h5 class="modal-title">分支工作台</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="关闭"></button>
          </div>
          <div class="modal-body">
            <div class="branch-workbench-status text-muted">正在加载…</div>
            <div class="branch-workbench-list d-grid gap-3 mt-3"></div>
            <section class="branch-workbench-editor border border-info rounded p-3 mt-3 d-none">
              <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                <strong class="branch-editor-title">非主线版本</strong>
                <code class="branch-editor-hash small text-muted"></code>
              </div>
              <textarea class="form-control bg-black text-light branch-editor-content"
                        rows="18" maxlength="4194304"
                        aria-label="非主线章节正文"></textarea>
              <div class="d-flex flex-wrap gap-2 mt-2">
                <button type="button" class="btn btn-sm btn-info branch-editor-save">保存为新分支版本</button>
                <button type="button" class="btn btn-sm btn-warning branch-editor-merge">提交人工合并审批</button>
                <button type="button" class="btn btn-sm btn-outline-light branch-editor-close">关闭编辑器</button>
              </div>
              <pre class="branch-memory-preview bg-black bg-opacity-25 rounded p-2 small mt-3 d-none"
                   style="white-space:pre-wrap;max-height:30vh;overflow:auto"></pre>
            </section>
          </div>
        </div>
      </div>`;
    modal.querySelector('.branch-editor-save').addEventListener('click', saveBranchContinuation);
    modal.querySelector('.branch-editor-merge').addEventListener('click', proposeBranchMerge);
    modal.querySelector('.branch-editor-close').addEventListener('click', () => {
        branchWorkbenchState.editorRequest++;
        modal.querySelector('.branch-workbench-editor').classList.add('d-none');
        branchWorkbenchState.branch = null;
        branchWorkbenchState.head = null;
        branchWorkbenchState.revision = null;
    });
    document.body.appendChild(modal);
    return modal;
}

async function branchUiDigest(value) {
    const bytes = new TextEncoder().encode(String(value));
    if (window.crypto?.subtle) {
        const digest = await window.crypto.subtle.digest('SHA-256', bytes);
        return Array.from(new Uint8Array(digest))
            .map(byte => byte.toString(16).padStart(2, '0')).join('');
    }
    // Deterministic compatibility fallback; server-side coordinate checks still
    // reject any accidental token collision with different bytes.
    let left = 2166136261;
    let right = 2246822519;
    for (const byte of bytes) {
        left = Math.imul(left ^ byte, 16777619) >>> 0;
        right = Math.imul(right ^ byte, 3266489917) >>> 0;
    }
    return `${left.toString(16).padStart(8, '0')}${right.toString(16).padStart(8, '0')}`;
}

async function branchUiIdempotencyKey(kind, coordinates) {
    const digest = await branchUiDigest(`${kind}\0${NOVEL_ID}\0${coordinates}`);
    return `branch-ui-${kind}-${digest.slice(0, 64)}`;
}

function branchWorkbenchMainBranch() {
    return (branchWorkbenchState.payload?.branches || []).find(branch => branch.is_main) || null;
}

async function openBranchWorkbench() {
    const modal = ensureBranchWorkbenchModal();
    bootstrap.Modal.getOrCreateInstance(modal).show();
    await loadBranchWorkbench();
}

async function loadBranchWorkbench() {
    const modal = ensureBranchWorkbenchModal();
    const status = modal.querySelector('.branch-workbench-status');
    const list = modal.querySelector('.branch-workbench-list');
    status.textContent = '正在加载分支与不可变版本…';
    list.replaceChildren();
    try {
        const payload = await apiGet('branch_actions', {
            action: 'list',
            novel_id: NOVEL_ID,
        });
        if (!payload.ok) throw new Error(payload.msg || '分支列表加载失败');
        branchWorkbenchState.payload = payload;
        renderBranchWorkbench(modal, payload);
    } catch (error) {
        status.textContent = error.message;
    }
}

function renderBranchWorkbench(modal, payload) {
    const status = modal.querySelector('.branch-workbench-status');
    const list = modal.querySelector('.branch-workbench-list');
    list.replaceChildren();
    const branches = Array.isArray(payload.branches) ? payload.branches : [];
    status.textContent = branches.length
        ? `共 ${branches.length} 条分支。非主线保存不会修改正史，合并必须再次通过硬质检和人工审批。`
        : '当前没有可操作分支；请先完成至少一个已投影的主线章节版本。';

    for (const branch of branches) {
        const card = document.createElement('section');
        card.className = 'border border-secondary rounded p-3';
        const heading = document.createElement('div');
        heading.className = 'd-flex justify-content-between align-items-center gap-2';
        const title = document.createElement('strong');
        title.textContent = `${branch.is_main ? '主线' : '非主线'} · ${branch.name}`;
        const uid = document.createElement('code');
        uid.className = 'small text-muted';
        uid.textContent = String(branch.branch_uid || '').slice(0, 12);
        heading.append(title, uid);
        card.appendChild(heading);

        const heads = Array.isArray(branch.heads) ? branch.heads : [];
        if (!heads.length) {
            const empty = document.createElement('div');
            empty.className = 'small text-muted mt-2';
            empty.textContent = '尚无章节版本。';
            card.appendChild(empty);
        }
        for (const head of heads) {
            const row = document.createElement('div');
            row.className = 'd-flex flex-wrap align-items-center gap-2 mt-2 pt-2 border-top border-secondary';
            const label = document.createElement('span');
            label.className = 'small flex-grow-1';
            label.textContent = `章节 #${head.chapter_id} · 版本 ${String(head.revision_uid || '').slice(0, 12)} · generation ${head.head_generation}`;
            row.appendChild(label);
            if (branch.is_main) {
                const fork = document.createElement('button');
                fork.className = 'btn btn-xs btn-outline-info';
                fork.textContent = '从此版本创建分支';
                fork.addEventListener('click', () => createBranchFork(head, fork));
                row.appendChild(fork);
            } else {
                const edit = document.createElement('button');
                edit.className = 'btn btn-xs btn-outline-info';
                edit.textContent = '编辑续写';
                edit.addEventListener('click', () => openBranchRevisionEditor(branch, head));
                const memory = document.createElement('button');
                memory.className = 'btn btn-xs btn-outline-secondary';
                memory.textContent = '隔离记忆';
                memory.addEventListener('click', () => openBranchMemory(branch, head, memory));
                row.append(edit, memory);
            }
            card.appendChild(row);
        }
        list.appendChild(card);
    }
}

async function createBranchFork(head, button) {
    const name = prompt('请输入新分支名称（不会修改主线）：');
    if (!name || !name.trim()) return;
    button.disabled = true;
    try {
        const idempotencyKey = await branchUiIdempotencyKey(
            'fork',
            `${head.revision_uid}\0${name.trim()}`
        );
        const payload = await apiPost('branch_actions', {
            action: 'create_fork',
            novel_id: NOVEL_ID,
            name: name.trim(),
            source_revision_uid: head.revision_uid,
            idempotency_key: idempotencyKey,
        });
        if (!payload.ok) throw new Error(payload.msg || '创建分支失败');
        showToast('已创建并安全播种非主线分支', 'success');
        await loadBranchWorkbench();
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        button.disabled = false;
    }
}

async function openBranchRevisionEditor(branch, head) {
    const requestId = ++branchWorkbenchState.editorRequest;
    const modal = ensureBranchWorkbenchModal();
    const editor = modal.querySelector('.branch-workbench-editor');
    const textarea = modal.querySelector('.branch-editor-content');
    editor.classList.remove('d-none');
    textarea.disabled = true;
    textarea.value = '';
    modal.querySelector('.branch-editor-title').textContent = `${branch.name} · 章节 #${head.chapter_id}`;
    modal.querySelector('.branch-editor-hash').textContent = String(head.revision_uid).slice(0, 12);
    modal.querySelector('.branch-memory-preview').classList.add('d-none');
    try {
        const payload = await apiGet('branch_actions', {
            action: 'revision',
            novel_id: NOVEL_ID,
            revision_uid: head.revision_uid,
        });
        if (requestId !== branchWorkbenchState.editorRequest) return;
        const revisionIsExact = payload.ok
            && payload.revision?.revision_uid === head.revision_uid
            && payload.revision?.branch_uid === branch.branch_uid
            && payload.revision?.chapter_id === head.chapter_id
            && payload.revision?.novel_id === Number(NOVEL_ID);
        if (!revisionIsExact) {
            throw new Error(payload.msg || '分支版本加载失败');
        }
        branchWorkbenchState.branch = branch;
        branchWorkbenchState.head = head;
        branchWorkbenchState.revision = payload.revision;
        textarea.value = payload.revision.content || '';
        textarea.disabled = false;
        textarea.focus();
    } catch (error) {
        if (requestId !== branchWorkbenchState.editorRequest) return;
        editor.classList.add('d-none');
        showToast(error.message, 'error');
    }
}

async function saveBranchContinuation(event) {
    const button = event.currentTarget;
    const modal = ensureBranchWorkbenchModal();
    const { branch, head, revision } = branchWorkbenchState;
    const content = modal.querySelector('.branch-editor-content').value;
    if (!branch || !head || !revision || !content.trim()) return;
    button.disabled = true;
    try {
        const idempotencyKey = await branchUiIdempotencyKey(
            'continue',
            `${revision.revision_uid}\0${content}`
        );
        const payload = await apiPost('branch_actions', {
            action: 'continue_branch',
            novel_id: NOVEL_ID,
            branch_uid: branch.branch_uid,
            base_revision_uid: revision.revision_uid,
            expected_head_generation: head.head_generation,
            content,
            idempotency_key: idempotencyKey,
        });
        const promotionIsExact = payload.ok
            && payload.canonical_written === false
            && payload.merge_requires_approval === true
            && payload.revision?.branch_uid === branch.branch_uid
            && payload.revision?.parent_revision_uid === revision.revision_uid
            && payload.head?.branch_uid === branch.branch_uid
            && payload.head?.chapter_id === head.chapter_id
            && payload.head?.revision_uid === payload.revision?.revision_uid
            && payload.head?.head_generation === head.head_generation + 1;
        if (!promotionIsExact) {
            throw new Error(payload.msg || '非主线版本保存失败');
        }
        showToast('已保存新的不可变分支版本；正史未变更', 'success');
        modal.querySelector('.branch-workbench-editor').classList.add('d-none');
        await loadBranchWorkbench();
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        button.disabled = false;
    }
}

async function proposeBranchMerge(event) {
    const button = event.currentTarget;
    const modal = ensureBranchWorkbenchModal();
    const { revision } = branchWorkbenchState;
    const main = branchWorkbenchMainBranch();
    const content = modal.querySelector('.branch-editor-content').value;
    if (!revision || !main || !content.trim()) {
        showToast('缺少当前分支版本或主线坐标，无法提交合并', 'error');
        return;
    }
    if (!confirm('提交后仍需通过硬质检和人工审批；现在提交合并候选吗？')) return;
    button.disabled = true;
    try {
        const payload = await apiPost('branch_actions', {
            action: 'merge_propose',
            novel_id: NOVEL_ID,
            source_revision_uid: revision.revision_uid,
            target_branch_uid: main.branch_uid,
            content,
        });
        if (!payload.ok) throw new Error(payload.msg || '合并候选提交失败');
        if (payload.candidate?.state === 'quarantined') {
            showToast('合并候选未通过硬质检，已隔离且未修改主线', 'warning');
        } else {
            showToast('合并候选已创建，等待人工审批', 'success');
        }
        await refreshChapterList();
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        button.disabled = false;
    }
}

async function openBranchMemory(branch, head, button) {
    button.disabled = true;
    try {
        const payload = await apiGet('branch_actions', {
            action: 'memory_context',
            novel_id: NOVEL_ID,
            branch_uid: branch.branch_uid,
            record_types: '',
            limit: 16,
        });
        if (!payload.ok) throw new Error(payload.msg || '分支记忆加载失败');
        const modal = ensureBranchWorkbenchModal();
        await openBranchRevisionEditor(branch, head);
        const preview = modal.querySelector('.branch-memory-preview');
        preview.textContent = JSON.stringify(payload.context || {}, null, 2);
        preview.classList.remove('d-none');
        if (confirm('是否写入一条新的隔离分支记忆？')) {
            await writeBranchMemory(branch, head);
        }
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        button.disabled = false;
    }
}

async function writeBranchMemory(branch, head) {
    const memoryKey = prompt('记忆键（例如 character.protagonist.goal）：');
    if (!memoryKey || !memoryKey.trim()) return;
    const recordType = prompt('记录类型（例如 character_state）：');
    if (!recordType || !recordType.trim()) return;
    const rawPayload = prompt('JSON payload：', '{}');
    if (rawPayload === null) return;
    let memoryPayload;
    try {
        memoryPayload = JSON.parse(rawPayload);
    } catch (_) {
        showToast('记忆 payload 必须是合法 JSON', 'error');
        return;
    }
    if (memoryPayload === null || typeof memoryPayload !== 'object') {
        showToast('记忆 payload 必须是 JSON 对象或数组', 'error');
        return;
    }
    const payload = await apiPost('branch_actions', {
        action: 'memory_write',
        novel_id: NOVEL_ID,
        branch_uid: branch.branch_uid,
        memory_key: memoryKey.trim(),
        record_type: recordType.trim(),
        payload: memoryPayload,
        source_revision_uid: head.revision_uid,
    });
    if (!payload.ok) throw new Error(payload.msg || '分支记忆写入失败');
    showToast('隔离分支记忆已保存，不会污染主线记忆', 'success');
}

function ensureCandidateReviewModal() {
    let modal = document.getElementById('candidateReviewModal');
    if (modal) return modal;
    modal = document.createElement('div');
    modal.id = 'candidateReviewModal';
    modal.className = 'modal fade';
    modal.tabIndex = -1;
    modal.setAttribute('aria-hidden', 'true');
    modal.innerHTML = `
      <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content bg-dark text-light">
          <div class="modal-header border-secondary">
            <h5 class="modal-title">隔离候选稿</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="关闭"></button>
          </div>
          <div class="modal-body">
            <div class="candidate-review-status text-muted">正在加载…</div>
            <div class="candidate-review-list d-grid gap-3 mt-3"></div>
          </div>
        </div>
      </div>`;
    document.body.appendChild(modal);
    return modal;
}

function candidateApprovalFor(candidate, state = 'open') {
    return (candidate.approvals || []).find(item =>
        item.state === state
        && (candidate.source !== 'branch_merge' || item.action_type === 'branch_merge')
    ) || null;
}

function renderCandidateReview(modal, payload, chapterNum) {
    const status = modal.querySelector('.candidate-review-status');
    const list = modal.querySelector('.candidate-review-list');
    list.replaceChildren();
    const candidates = (payload.candidates || []).filter(candidate =>
        candidate.lifecycle === 'quarantined'
        || candidate.lifecycle === 'approved'
        || (candidate.source === 'branch_merge'
            && candidate.lifecycle === 'draft'
            && candidateApprovalFor(candidate))
    );
    status.textContent = candidates.length
        ? `第${chapterNum}章共有 ${candidates.length} 个待处理候选；这些内容尚未进入正史或记忆。`
        : '当前没有待处理的隔离候选稿。';

    for (const candidate of candidates) {
        const card = document.createElement('section');
        card.className = 'border border-secondary rounded p-3';

        const heading = document.createElement('div');
        heading.className = 'd-flex justify-content-between align-items-center gap-2 mb-2';
        const title = document.createElement('strong');
        const candidateStateLabel = candidate.lifecycle === 'approved'
            ? '已批准待提交'
            : (candidate.source === 'branch_merge' ? '分支合并待审批' : '质检隔离');
        title.textContent = `${candidateStateLabel} · ${candidate.words} 字`;
        const hash = document.createElement('code');
        hash.className = 'small text-muted';
        hash.textContent = String(candidate.content_hash || '').slice(0, 12);
        heading.append(title, hash);

        const reason = document.createElement('div');
        reason.className = 'alert alert-warning py-2 small';
        reason.textContent = candidate.quarantine_reason
            || (candidate.source === 'branch_merge'
                ? '分支合并已通过硬质检，等待人工批准。'
                : '质量门未提供可读原因');

        const preview = document.createElement('pre');
        preview.className = 'candidate-content-preview bg-black bg-opacity-25 rounded p-3 small';
        preview.style.whiteSpace = 'pre-wrap';
        preview.style.maxHeight = '45vh';
        preview.style.overflow = 'auto';
        preview.textContent = candidate.content || '';

        const actions = document.createElement('div');
        actions.className = 'd-flex flex-wrap gap-2 mt-2';
        const approval = candidateApprovalFor(candidate);
        if (approval) {
            const approve = document.createElement('button');
            approve.className = 'btn btn-sm btn-success';
            approve.textContent = '人工批准';
            approve.addEventListener('click', () => decideCandidateApproval(
                approval.approval_uid, 'approved', payload.chapter.id, chapterNum, approve
            ));
            const reject = document.createElement('button');
            reject.className = 'btn btn-sm btn-outline-secondary';
            reject.textContent = '拒绝候选';
            reject.addEventListener('click', () => decideCandidateApproval(
                approval.approval_uid, 'rejected', payload.chapter.id, chapterNum, reject
            ));
            actions.append(approve, reject);
        }
        const approvedCommitApproval = candidate.lifecycle === 'approved'
            ? candidateApprovalFor(candidate, 'approved')
            : null;
        if (approvedCommitApproval) {
            const retryCommit = document.createElement('button');
            retryCommit.className = 'btn btn-sm btn-warning';
            retryCommit.textContent = '重试提交正史';
            retryCommit.addEventListener('click', () => retryApprovedCandidateCommit(
                approvedCommitApproval.approval_uid,
                payload.chapter.id,
                chapterNum,
                retryCommit
            ));
            actions.append(retryCommit);
        }
        if (candidate.lifecycle === 'quarantined' && candidate.source !== 'branch_merge') {
            const retry = document.createElement('button');
            retry.className = 'btn btn-sm btn-outline-warning';
            retry.textContent = '封存并重写本章';
            retry.addEventListener('click', () => retryCandidate(candidate.candidate_uid, retry));
            actions.append(retry);
        }
        card.append(heading, reason, preview, actions);
        list.appendChild(card);
    }
}

async function openCandidateReview(chapterId, chapterNum) {
    const modal = ensureCandidateReviewModal();
    modal.querySelector('.modal-title').textContent = `第${chapterNum}章 · 隔离候选稿`;
    modal.querySelector('.candidate-review-status').textContent = '正在加载…';
    modal.querySelector('.candidate-review-list').replaceChildren();
    bootstrap.Modal.getOrCreateInstance(modal).show();
    try {
        const payload = await apiGet('chapter_candidates', { chapter_id: chapterId });
        if (!payload.ok) throw new Error(payload.msg || '候选稿加载失败');
        renderCandidateReview(modal, payload, chapterNum);
    } catch (error) {
        modal.querySelector('.candidate-review-status').textContent = error.message;
    }
}

async function decideCandidateApproval(approvalUid, decision, chapterId, chapterNum, button) {
    button.disabled = true;
    try {
        const payload = await apiPost('approval_decide', {
            approval_uid: approvalUid,
            decision,
            idempotency_key: `candidate-ui-${approvalUid}-${decision}`,
        });
        if (!payload.ok) throw new Error(payload.msg || '审批失败');
        if (payload.stale) {
            showToast('章节正史已变化，本次审批已安全标记为过期', 'warning');
        } else {
            showToast(decision === 'approved' ? '候选稿已批准' : '候选稿已拒绝', 'success');
        }
        await openCandidateReview(chapterId, chapterNum);
        await refreshChapterList();
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        button.disabled = false;
    }
}

async function retryApprovedCandidateCommit(approvalUid, chapterId, chapterNum, button) {
    button.disabled = true;
    try {
        const payload = await apiPost('approval_commit', { approval_uid: approvalUid });
        if (!payload.ok || !payload.commit?.committed) {
            throw new Error(payload.msg || '候选稿提交重试失败');
        }
        showToast('已将批准的候选稿提交到正史', 'success');
        await openCandidateReview(chapterId, chapterNum);
        await refreshChapterList();
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        button.disabled = false;
    }
}

async function retryCandidate(candidateUid, button) {
    if (!confirm('封存该隔离稿并只重写当前章节？不会跳到下一章。')) return;
    button.disabled = true;
    try {
        const payload = await apiPost('candidate_retry', { candidate_uid: candidateUid });
        if (!payload.ok) throw new Error(payload.msg || '候选重试失败');
        bootstrap.Modal.getOrCreateInstance(ensureCandidateReviewModal()).hide();
        await refreshChapterList();
        await writeSingleChapter(payload.novel_id, payload.chapter_id);
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        button.disabled = false;
    }
}

async function loadChapterList(page = getCurrentChapterPage(), pushState = true) {
    const tab = document.getElementById('tab-chapters');
    if (!tab || typeof NOVEL_ID === 'undefined') return false;

    const safePage = Math.max(1, parseInt(page, 10) || 1);
    const root = document.getElementById('chapter-list-ajax-root');
    if (root) root.style.opacity = '.55';

    try {
        const data = await apiGet('chapter_list', { novel_id: NOVEL_ID, page: safePage });
        if (!data.ok) {
            throw new Error(data.msg || data.error || '章节列表加载失败');
        }

        tab.innerHTML = safeChapterListHtml(data.html || '');
        bindChapterListEvents(tab);

        if (pushState) {
            const nextUrl = new URL(location.href);
            nextUrl.searchParams.set('page', data.page || safePage);
            history.pushState({ chapterPage: data.page || safePage }, '', nextUrl.toString());
        }

        // 刷新章节列表后，重新加载 Token 总消耗统计
        if (typeof loadNovelTotalTokens === 'function') {
            await loadNovelTotalTokens();
        }

        return true;
    } catch(e) {
        showToast('章节列表加载失败：' + e.message, 'error');
        if (root) root.style.opacity = '';
        return false;
    }
}

// 局部刷新章节列表（AJAX，无整页 reload）
async function refreshChapterList() {
    await loadChapterList(getCurrentChapterPage(), false);
}

// ============================================================
// 写下一章
// ============================================================

function _openWriteModal(title) {
    const modal = new bootstrap.Modal(document.getElementById('writeModal'));
    modal.show();
    const contentEl = document.getElementById('writeModalContent');
    contentEl.textContent = '';
    // 添加光标
    const cur = document.createElement('span');
    cur.className = 'outline-stream-cursor';
    contentEl.appendChild(cur);
    document.getElementById('writeModalSpinner').style.display = '';
    document.getElementById('writeModalStats').textContent     = '';
    document.getElementById('writeModalViewBtn').style.display = 'none';
    document.getElementById('writeModalTitle').textContent     = title || '正在写作...';
    return { contentEl, cur };
}

async function writeNextChapter() {
    const { contentEl, cur } = _openWriteModal('正在写作下一章...');
    try {
        await streamWriteChapter(NOVEL_ID, null, async (statsText, chapterId, modelUsed) => {
            document.getElementById('writeModalSpinner').style.display = 'none';
            document.getElementById('writeModalStats').textContent     = statsText;
            cur.remove();
            if (chapterId) {
                const v = document.getElementById('writeModalViewBtn');
                v.href = `chapter.php?id=${chapterId}`;
                v.style.display = '';
                // 自动提取章节记忆
                if (typeof MemoryUI !== 'undefined' && MemoryUI.autoExtractChapterMemory) {
                    await MemoryUI.autoExtractChapterMemory(chapterId);
                }
            }
        }, contentEl, cur);
    } catch (err) {
        contentEl.textContent = '写作失败：' + err.message;
    }
    await refreshChapterList();
}

// ============================================================
// 写单章
// ============================================================

async function writeSingleChapter(novelId, chapterId) {
    const { contentEl, cur } = _openWriteModal('正在写作...');
    document.getElementById('writeModalStats').textContent  = '';
    document.getElementById('writeModalViewBtn').style.display = 'none';

    try {
        await streamWriteChapter(novelId, chapterId, async (statsText, chapId) => {
            document.getElementById('writeModalStats').textContent  = statsText;
            document.getElementById('writeModalSpinner').style.display = 'none';
            cur.remove();
            if (chapId) {
                const viewBtn = document.getElementById('writeModalViewBtn');
                viewBtn.href = `chapter.php?id=${chapId}`;
                viewBtn.style.display = '';
                // 自动提取章节记忆
                if (typeof MemoryUI !== 'undefined' && MemoryUI.autoExtractChapterMemory) {
                    await MemoryUI.autoExtractChapterMemory(chapId);
                }
            }
        }, contentEl, cur);
    } catch (err) {
        contentEl.textContent = '写作失败：' + err.message;
    }

    await refreshChapterList();
}

// ============================================================
// 删除单个章节（整行删除：正文/细纲/概要/历史版本）
// ============================================================

async function deleteSingleChapter(chapterId, chapterNum) {
    if (!chapterId) return;
    const label = chapterNum ? `第${chapterNum}章` : '该章节';
    if (!confirm(`确定删除${label}？\n正文、细纲、概要和历史版本将一并删除，不可恢复。\n（删除后章节号会留下空洞，可用「补写缺失细纲」重新生成）`)) return;
    try {
        const res = await apiPost('actions', { action: 'delete_chapter', chapter_id: chapterId });
        if (res.ok) {
            showToast(res.msg || `${label}已删除`, 'success');
            await refreshChapterList();
        } else {
            showToast(res.error || res.msg || '删除失败', 'error');
        }
    } catch (err) {
        showToast('删除失败：' + err.message, 'error');
    }
}

// ============================================================
// 章节行内操作：生成标题 / 重新生成单章细纲
// ============================================================

async function retryChapterPostprocess(button) {
    if (!button || button.disabled) return;
    button.disabled = true;
    const status = document.getElementById('postprocess-retry-status');
    try {
        const result = await apiPost('retry_chapter_postprocess', {
            chapter_id: Number(button.dataset.chapterId), job_id: Number(button.dataset.jobId),
            expected_content_hash: button.dataset.contentHash,
        });
        if (!result?.ok) throw new Error(result?.msg || result?.error || '无法安排重建');
        const message = result.msg || '重建任务已排队，后台完成后即可继续写作';
        if (status) status.textContent = message;
        button.textContent = result.data?.state === 'done' ? '重建已完成' : '重建已排队';
        showToast(message, 'success');
    } catch (error) {
        button.disabled = false;
        if (status) status.textContent = error.message;
        showToast(error.message, 'error');
    }
}

async function generateSingleChapterTitle(chapterId, chapterNum, btn) {
    if (!chapterId) return;
    btn.disabled = true;
    try {
        const res = await apiPost('actions', { action: 'generate_chapter_title', chapter_id: chapterId });
        if (res.ok) {
            const title = res.data && res.data.title ? `《${res.data.title}》` : '';
            showToast(`第${chapterNum}章标题已生成${title}`, 'success');
            await refreshChapterList();
        } else {
            showToast(res.error || res.msg || '标题生成失败', 'error');
        }
    } catch (err) {
        showToast('标题生成失败：' + err.message, 'error');
    } finally {
        btn.disabled = false;
    }
}

// 重新生成单章细纲：走批量概要端点的单章模式（chapter_ids + force），消费 SSE 直至完成
async function regenerateSingleSynopsis(chapterId, chapterNum, btn) {
    if (!chapterId) return;
    if (!confirm(`确定重新生成第${chapterNum}章的细纲（章节概要）吗？\n现有概要将被覆盖。`)) return;
    btn.disabled = true;
    showToast(`正在重新生成第${chapterNum}章细纲...`, 'info');
    try {
        const response = await fetch(apiRouteUrl('generate_chapter_synopsis'), {
            method:  'POST',
            headers: jsonHeaders(),
            body:    JSON.stringify({ novel_id: NOVEL_ID, chapter_ids: [chapterId], force: true }),
        });
        if (!response.ok) throw new Error('服务器错误: ' + response.status);

        const reader  = response.body.getReader();
        const decoder = new TextDecoder();
        let buf = '', currentEvent = '', doneOk = false, lastErr = '';
        while (true) {
            const { value, done } = await reader.read();
            if (done) break;
            buf += decoder.decode(value, { stream: true });
            const lines = buf.split('\n');
            buf = lines.pop();
            for (const line of lines) {
                const trimmed = line.trim();
                if (trimmed.startsWith(':')) continue;
                if (trimmed.startsWith('event: ')) { currentEvent = trimmed.slice(7).trim(); continue; }
                if (!trimmed.startsWith('data: ')) continue;
                const raw = trimmed.slice(6);
                if (raw === '[DONE]') continue;
                let d;
                try { d = JSON.parse(raw); } catch { continue; }
                if (currentEvent === 'chapter_done') doneOk = true;
                else if (currentEvent === 'error' || currentEvent === 'conflict') lastErr = d.msg || d.error || '生成失败';
            }
        }
        if (doneOk) {
            showToast(`第${chapterNum}章细纲已重新生成`, 'success');
            await refreshChapterList();
        } else {
            showToast(lastErr || '细纲生成失败，请稍后重试', 'error');
        }
    } catch (err) {
        showToast('细纲生成失败：' + err.message, 'error');
    } finally {
        btn.disabled = false;
    }
}

// ============================================================
// 核心：流式写章节（优先异步任务模式，回退 SSE 直连）
// ============================================================

/**
 * @param novelId     小说ID
 * @param chapterId   章节ID（null = 自动选下一章）
 * @param onComplete  完成回调 (statsText, chapterId, modelUsed)
 * @param displayEl   实时显示容器（可选）
 * @param cursorEl    光标元素（可选，跟随文字末尾）
 */
async function streamWriteChapter(novelId, chapterId, onComplete, displayEl, cursorEl, isCurrent = () => true) {
    if (!isCurrent()) return '';
    renderWritePostprocessProgress({ status: 'writing' }, displayEl);
    try {
        return await _streamWriteAsync(novelId, chapterId, onComplete, displayEl, cursorEl, isCurrent);
    } catch (asyncErr) {
        if (!isCurrent()) return '';
        if (asyncErr._fallbackSse) {
            console.info('[streamWriteChapter] 异步模式不可用，回退到 SSE 直连模式');
            return await _streamWriteSse(novelId, chapterId, onComplete, displayEl, cursorEl, isCurrent);
        }
        throw asyncErr;
    }
}

function renderWritePostprocessProgress(response, displayEl) {
    if (!displayEl) return;
    const status = response.status;
    const saved = response.content_saved === true
        || ['saved', 'postprocessing', 'done', 'completed'].includes(status);
    let panel = displayEl._postprocessProgressEl;
    if (!saved) {
        if (panel) panel.style.display = 'none';
        return;
    }

    const steps = Array.isArray(response.postprocess_subtasks)
        ? response.postprocess_subtasks.filter(step => step && typeof step === 'object') : [];
    let headline = '正文已保存，正在处理摘要与记忆…';
    if (status === 'done' || status === 'completed') {
        headline = response.postprocess_failed ? '正文已保存，后处理失败，请检查后重试'
            : response.postprocess_pending ? '正文已保存，后处理已进入自动重试队列'
                : '正文已保存，后处理已完成';
    } else if (status === 'error' || response.postprocess_failed) {
        headline = '正文已保存，后处理失败，请检查后重试';
    } else if (steps.length > 0) {
        const running = steps.filter(step => step.state === 'running').length;
        headline = running > 0 ? `正文已保存，${running} 个后处理任务正在计算…`
            : '正文已保存，正在完成后处理…';
    }

    const label = displayEl === document.getElementById('write-stream-box')
        ? document.getElementById('write-progress-label')
        : displayEl === document.getElementById('writeModalContent')
            ? document.getElementById('writeModalStats') : null;
    if (label) label.textContent = headline;
    if (!panel && displayEl.parentNode) {
        panel = document.createElement('div');
        panel.className = 'small text-muted mt-2 mb-2';
        panel.style.whiteSpace = 'pre-line';
        panel.setAttribute('aria-live', 'polite');
        displayEl.parentNode.appendChild(panel);
        displayEl._postprocessProgressEl = panel;
    }
    if (!panel) return;

    const names = { knowledge: '知识库整理', critic: '质量分析' };
    const states = { queued: '排队中', running: '计算中', retry: '等待重试', failed: '失败',
        skipped: '已跳过', stale: '正文已变化，已取消', canceled: '已取消' };
    const rows = steps.map(step => {
        const state = step.state === 'done'
            ? (step.applied_at ? '已合并' : '已计算，待合并') : (states[step.state] || '等待处理');
        const duration = Number(step.duration_ms);
        const elapsed = Number.isFinite(duration) && duration > 0 ? ` · ${(duration / 1000).toFixed(1)} 秒` : '';
        const attempt = Number(step.attempt);
        const retry = Number.isSafeInteger(attempt) && attempt > 1 ? ` · 第 ${attempt} 次尝试` : '';
        const error = typeof step.last_error === 'string' && step.last_error ? ` · ${step.last_error}` : '';
        return `${names[step.task_type] || '后处理任务'}：${state}${elapsed}${retry}${error}`;
    });
    panel.style.display = '';
    // Provider diagnostics are text, never HTML. A computed result is not an
    // applied result and never permits the next chapter to start by itself.
    panel.textContent = rows.length > 0 ? `${headline}\n${rows.join('\n')}` : headline;
}

async function _streamWriteAsync(novelId, chapterId, onComplete, displayEl, cursorEl, isCurrent = () => true) {
    let startRes;
    try {
        startRes = await queueAutoWriteStateChange(() => isCurrent() ? apiPost('write_start', {
            novel_id: novelId, chapter_id: chapterId
        }) : null);
    } catch (e) {
        // 网络层失败：write_start 都连不上时 SSE 直连大概率也不通。
        // 不再盲目回退（回退会绕过服务端并发守卫造成双写），交给上层重试。
        throw new Error('启动写作任务失败：' + e.message);
    }

    if (!isCurrent()) return '';
    if (!startRes.ok) {
        const err = new Error(startRes.error || startRes.msg || '启动写作任务失败');
        // 只有服务端明确声明 fallback_sse（进程受限/启动失败）才回退 SSE 直连；
        // "已有写作任务在运行中"等业务拒绝必须原样上抛，否则会绕过并发守卫。
        err._fallbackSse = (startRes.fallback_sse === true);
        throw err;
    }

    // 修复（2026-06-18）：write_start 返回 resumed=true 表示恢复了已有任务
    // 此时 lastContentLen 应从当前 content 长度开始，避免把已有内容当新内容重复显示
    const taskId = startRes.task_id;
    if (typeof taskId !== 'string' || taskId.trim() === '') {
        throw new Error('服务器未返回有效的写作任务ID，请取消残留任务后重试');
    }
    const isResumed = startRes.resumed === true;
    const harnessEventCursorEnabled = startRes.harness_event_cursor === true;
    const POLL_INTERVAL_FAST = 300;
    const POLL_INTERVAL_SLOW = 1500;
    // 修复（2026-06-18）：增加轮询容错——网络抖动不应导致整章放弃
    // 原 5 次 × 3 秒 = 15 秒就放弃，worker 还在后台跑，造成前后端脱节
    // 现 10 次 × 2 秒 = 20 秒，且网络恢复后立即重置计数
    const POLL_MAX_FAILURES = 10;
    let pollInterval = POLL_INTERVAL_FAST;
    let lastContentLen = 0;
    let contentRevision = 0;
    let eventCursor = 0;
    let lastPollTime = Date.now();
    let pollFailures = 0;

    const pollRequest = () => harnessEventCursorEnabled
        ? { task_id: taskId, event_cursor: eventCursor, event_limit: 50 }
        : { task_id: taskId };
    const consumeHarnessEvents = (response) => {
        const stream = response && response.event_stream;
        if (!stream || stream.status !== 'available') return;
        const nextCursor = Number(stream.next_cursor);
        if (!Number.isSafeInteger(nextCursor) || nextCursor < eventCursor) {
            console.warn('[streamWriteChapter] 忽略无效 Harness event cursor');
            return;
        }
        eventCursor = nextCursor;
        if (!Array.isArray(stream.events)) return;
        for (const event of stream.events) {
            // Typed events are exposed to optional UI/telemetry consumers. They
            // never replace the legacy progress payload during the P1 shadow phase.
            window.dispatchEvent(new CustomEvent('harness:write-event', {
                detail: Object.freeze({ taskId, runUid: stream.run_uid || null, event })
            }));
        }
    };

    // 恢复任务时，先做一次轮询获取当前已有内容，设置 lastContentLen 基线
    if (isResumed) {
        try {
            const initPoll = await apiPost('write_poll', pollRequest());
            if (!isCurrent()) return '';
            consumeHarnessEvents(initPoll);
            if (initPoll.ok) renderWritePostprocessProgress(initPoll, displayEl);
            contentRevision = Number(initPoll.content_revision || 0);
            if (initPoll.ok && initPoll.content) {
                lastContentLen = initPoll.content.length;
                // 恢复已有内容到显示区
                if (displayEl && initPoll.content.length > 0) {
                    displayEl.textContent = initPoll.content;
                    if (cursorEl) {
                        displayEl.appendChild(cursorEl);
                    }
                    displayEl.scrollTop = displayEl.scrollHeight;
                }
            }
        } catch (e) {
            // 恢复轮询失败不致命，继续正常轮询流程
            console.warn('[streamWriteChapter] 恢复任务初始轮询失败:', e.message);
        }
    }

    while (true) {
        if (!isCurrent()) return '';
        let pollRes;
        try {
            pollRes = await apiPost('write_poll', pollRequest());
        } catch (e) {
            if (!isCurrent()) return '';
            // 网络抖动容忍：连续失败达上限才判死，避免一次瞬时失败就放弃整章
            // （worker 还在跑，放弃后重启会与其冲突）
            if (++pollFailures >= POLL_MAX_FAILURES) {
                throw new Error('轮询进度连续失败（' + pollFailures + '次）：' + e.message);
            }
            await new Promise(r => setTimeout(r, 2000));
            continue;
        }

        if (!isCurrent()) return '';
        if (!pollRes.ok) {
            if (++pollFailures >= POLL_MAX_FAILURES) {
                throw new Error(pollRes.msg || '轮询进度失败');
            }
            await new Promise(r => setTimeout(r, 2000));
            continue;
        }
        pollFailures = 0;
        consumeHarnessEvents(pollRes);
        renderWritePostprocessProgress(pollRes, displayEl);

        const {
            status, content, messages, words, model_used, chapter_id, error, progress, content_revision,
            postprocess_pending, postprocess_failed, canceled_but_saved,
            quality_warning, quality_warning_reason
        } = pollRes;

        const nextContentRevision = Number(content_revision || 0);
        // 只有 worker 明确递增正文版本时才清空显示区。轮询侧文件锁竞争、缓存抖动
        // 或短暂读到空快照都不代表正文被重置，不能擦掉已经展示的内容。
        if (nextContentRevision !== contentRevision) {
            contentRevision = nextContentRevision;
            lastContentLen = 0;
            if (displayEl) {
                displayEl.textContent = '';
                if (cursorEl) displayEl.appendChild(cursorEl);
            }
        }

        const isWaiting = messages && messages.length > 0 && messages[messages.length - 1].waiting;
        if (isWaiting) {
            pollInterval = POLL_INTERVAL_SLOW;
        } else if (content && content.length > lastContentLen) {
            pollInterval = POLL_INTERVAL_FAST;
        }

        if (displayEl && content && content.length > lastContentLen) {
            const newPart = content.substring(lastContentLen);
            lastContentLen = content.length;
            if (cursorEl && cursorEl.parentNode === displayEl) {
                displayEl.insertBefore(document.createTextNode(newPart), cursorEl);
            } else {
                displayEl.textContent = content;
            }
            displayEl.scrollTop = displayEl.scrollHeight;
        }

        if (messages && messages.length > 0) {
            const lastMsg = messages[messages.length - 1];
            if (lastMsg.waiting || lastMsg.info) {
                // progress label update handled by caller
            }
        }

        if (status === 'done' || status === 'completed') {
            // 审计修复（2026-08-17 写作逻辑 D2）：取消与落盘竞态——正文已提交时如实
            // 提示「本章已保存，自动写已停」，而不是让用户以为取消丢章。
            if (canceled_but_saved) {
                showToast('本章已在取消前保存完成，自动写已停（不回滚正文）', 'warning');
            } else if (postprocess_failed) {
                showToast('正文已安全保存，但后处理自动重试已耗尽，请运行维护任务检查', 'error');
            } else if (postprocess_pending) {
                showToast('正文已安全保存，后处理已进入自动重试队列', 'info');
            }
            if (quality_warning) {
                const reason = quality_warning_reason ? `：${quality_warning_reason}` : '';
                showToast(`本章质检未过，正文已保存，自动写作将继续${reason}`, 'warning');
            }
            if (onComplete) {
                const statsText = words ? `${words} 字` : '写作完成';
                onComplete(statsText, chapter_id, model_used);
            }
            return content || '';
        }

        if (status === 'error') {
            throw new Error(error || '写作过程中发生错误');
        }

        await new Promise(r => setTimeout(r, pollInterval));
    }
}

async function _streamWriteSse(novelId, chapterId, onComplete, displayEl, cursorEl, isCurrent = () => true) {
    if (!isCurrent()) return '';
    const SILENCE_TIMEOUT = (typeof CFG_FRONTEND_SILENCE_TIMEOUT !== 'undefined' ? CFG_FRONTEND_SILENCE_TIMEOUT : 180) * 1000;
    const CONNECT_TIMEOUT = (typeof CFG_FRONTEND_CONNECT_TIMEOUT !== 'undefined' ? CFG_FRONTEND_CONNECT_TIMEOUT : 30) * 1000;
    const abortController = new AbortController();
    let silenceTimer = null;
    let connectTimer = null;
    let lastDataTime = Date.now();

    const resetSilenceTimer = () => {
        lastDataTime = Date.now();
        if (silenceTimer) clearTimeout(silenceTimer);
        silenceTimer = setTimeout(() => {
            const elapsed = Math.round((Date.now() - lastDataTime) / 1000);
            console.warn(`[streamWriteChapter] AI 静默超时 (${elapsed}s)，主动断开连接`);
            abortController.abort();
        }, SILENCE_TIMEOUT);
    };

    const clearAllTimers = () => {
        if (silenceTimer) clearTimeout(silenceTimer);
        if (connectTimer) clearTimeout(connectTimer);
        silenceTimer = null;
        connectTimer = null;
    };

    connectTimer = setTimeout(() => {
        console.warn('[streamWriteChapter] 连接超时 (30s)，主动断开');
        abortController.abort();
    }, CONNECT_TIMEOUT);

    let response;
    try {
        response = await fetch(apiRouteUrl('write_chapter'), {
            method:  'POST',
            headers: jsonHeaders(),
            body:    JSON.stringify({ novel_id: novelId, chapter_id: chapterId }),
            signal:  abortController.signal,
        });
    } catch (fetchErr) {
        clearAllTimers();
        if (fetchErr.name === 'AbortError') {
            throw new Error('连接超时，请检查网络或尝试使用响应更快的AI模型');
        }
        // 友好化网络错误消息
        if (fetchErr.message === 'network error' || fetchErr.message.includes('network')) {
            throw new Error('网络连接中断，请检查网络后重试');
        }
        if (fetchErr.message.includes('Failed to fetch')) {
            throw new Error('无法连接到服务器，请检查网络或刷新页面后重试');
        }
        throw fetchErr;
    }

    if (connectTimer) clearTimeout(connectTimer);
    if (!isCurrent()) { clearAllTimers(); abortController.abort(); return ''; }
    resetSilenceTimer();

    // 检查 HTTP 状态码
    if (!response.ok) {
        let errMsg = `服务器错误 (${response.status})`;
        let errBody = '';
        try {
            errBody = await response.text();
            console.error('[write_chapter.php] HTTP', response.status, '响应体:', errBody);
            try {
                const errJson = JSON.parse(errBody);
                errMsg = errJson.msg || errJson.error || errMsg;
                // CSRF 失效专门识别,触发前端自动刷新 token
                if (errJson.error === 'csrf_invalid' || /CSRF/i.test(errJson.msg || '')) {
                    // 尝试从当前页面 URL 重新拉取 meta token
                    try {
                        const htmlRes = await fetch(location.href, { cache: 'no-store' });
                        const html    = await htmlRes.text();
                        const m       = html.match(/window\.CSRF_TOKEN\s*=\s*['"]([a-f0-9]+)['"]/);
                        if (m && m[1]) {
                            window.CSRF_TOKEN = m[1];
                            console.info('[CSRF] token 已刷新，将在下一次请求携带新值');
                            errMsg = 'CSRF 已自动刷新，正在重试';
                        }
                    } catch (refreshErr) {
                        console.warn('[CSRF] 刷新 token 失败:', refreshErr);
                    }
                }
            } catch {
                // 非 JSON 响应:把前 200 字符附上便于排查
                if (errBody) errMsg += ' | ' + errBody.substring(0, 200);
            }
        } catch {}
        if (response.status === 500 && errBody.trim() === '') {
            errMsg = '写作后台初始化失败（HTTP 500，响应为空），请查看服务器 PHP 错误日志';
        }
        clearAllTimers();
        throw new Error(errMsg);
    }

    // 检查 Content-Type，防止非 SSE 响应（如登录重定向返回 HTML）
    const ct = response.headers.get('Content-Type') || '';
    if (!ct.includes('text/event-stream') && !ct.includes('text/plain')) {
        const body = await response.text().catch(() => '');
        let errMsg = '服务器返回了非预期的响应';
        try {
            const errJson = JSON.parse(body);
            errMsg = errJson.msg || errJson.error || errMsg;
        } catch {}
        throw new Error(errMsg);
    }

    const reader  = response.body.getReader();
    const decoder = new TextDecoder();
    let   fullText = '';
    let   buf      = '';
    let   currentEvent = '';  // 跟踪 SSE 事件类型
    let   gotData  = false;  // 是否收到过有效数据

    try {
        while (true) {
            const { value, done } = await reader.read();
            if (!isCurrent()) { clearAllTimers(); abortController.abort(); return ''; }
            if (done) break;

            resetSilenceTimer();

            buf += decoder.decode(value, { stream: true });
            const lines = buf.split('\n');
            buf = lines.pop();

            for (const line of lines) {
                const trimmed = line.trim();

                // SSE 注释行（keepalive），忽略
                if (trimmed.startsWith(':')) {
                    currentEvent = '';
                    resetSilenceTimer();
                    continue;
                }

                // SSE 事件类型行
                if (trimmed.startsWith('event: ')) {
                    currentEvent = trimmed.slice(7).trim();
                    continue;
                }

                // 只处理 data: 行
                if (!trimmed.startsWith('data: ')) continue;
                const payload = trimmed.slice(6).trim();
                if (payload === '[DONE]') break;

                try {
                    const d = JSON.parse(payload);

                    // 忽略心跳和思考事件（不影响内容显示）
                    if (currentEvent === 'heartbeat' || currentEvent === 'thinking') {
                        currentEvent = '';
                        resetSilenceTimer();
                        continue;
                    }

                    gotData = true;

                    if (d.reset_content) {
                        fullText = '';
                        if (displayEl) {
                            displayEl.textContent = '';
                            if (cursorEl) displayEl.appendChild(cursorEl);
                        }
                    }

                    if (d.chunk) {
                        fullText += d.chunk;
                        if (displayEl) {
                            // 把文字插到光标之前，保持光标在末尾
                            if (cursorEl && cursorEl.parentNode === displayEl) {
                                displayEl.insertBefore(document.createTextNode(d.chunk), cursorEl);
                            } else {
                                displayEl.textContent = fullText;
                            }
                            displayEl.scrollTop = displayEl.scrollHeight;
                        }
                    }

                    if (d.model_switch) {
                        fullText = '';
                        if (displayEl) {
                            displayEl.textContent = '';
                            if (cursorEl) displayEl.appendChild(cursorEl);
                            // 插入切换提示
                        const hint = document.createTextNode(`[切换到「${d.next_model}」重新生成...]\n`);
                        displayEl.insertBefore(hint, cursorEl || null);
                    }
                    showToast(`模型切换 → ${d.next_model}`, 'info');
                }

                if (d.stats && onComplete) {
                    onComplete(d.stats, d.chapter_id, d.model_used);
                }
                if (d.quality_warning) {
                    const reason = d.quality_warning_reason ? `：${d.quality_warning_reason}` : '';
                    showToast(`本章质检未过，正文已保存，自动写作将继续${reason}`, 'warning');
                }
                if (d.error) throw new Error(d.error);

            } catch(e) {
                if (e.message !== 'Unexpected token') throw e;
            }

            currentEvent = '';
        }
    }
    } catch (readErr) {
        clearAllTimers();
        if (!isCurrent()) return '';
        // 即使出错也返回已生成的内容
        if (fullText) {
            displayEl._partialContent = fullText;
        }
        if (readErr.name === 'AbortError') {
            const silenceSec = SILENCE_TIMEOUT / 1000;
            throw new Error(`AI 响应超时（${silenceSec}秒无输出），已保留已生成内容`);
        }
        throw readErr;
    }

    clearAllTimers();

    // 流结束但没收到任何有效数据 — 可能是后端静默失败
    if (!gotData && !fullText) {
        throw new Error('服务器未返回任何数据，请检查AI模型配置是否正确');
    }

    return fullText;
}

// ============================================================
// Toast 样式 (injected)
// ============================================================

(function() {
    const style = document.createElement('style');
    style.textContent = `
    .toast-msg {
        position: fixed;
        bottom: 24px;
        right: 24px;
        background: #1e1e30;
        border: 1px solid #2d2d4e;
        color: #e0e0f0;
        padding: 10px 18px;
        border-radius: 8px;
        font-size: 13px;
        z-index: 9999;
        opacity: 0;
        transform: translateY(8px);
        transition: opacity .25s, transform .25s;
        max-width: 320px;
    }
    .toast-msg.show { opacity: 1; transform: translateY(0); }
    .toast-success  { border-left: 3px solid #10b981; }
    .toast-error    { border-left: 3px solid #ef4444; color: #fca5a5; }
    .toast-info     { border-left: 3px solid #3b82f6; }
    `;
    document.head.appendChild(style);
})();

// ============================================================
// 取消写作
// ============================================================

async function cancelWriting() {
    if (!confirm('确定要取消正在进行的写作吗？\n\n取消后，正在生成的内容将被清空。')) {
        return;
    }
    
    stopAutoWriteLocal();
    const stoppedGen = autoWriteGen;
    try {
        const data = await queueAutoWriteStateChange(() => apiPost('cancel_write', {
            action: 'cancel',
            novel_id: NOVEL_ID
        }));
        if (stoppedGen !== autoWriteGen) return;
        
        if (data.ok) {
            showToast('已取消写作', 'success');
            // 刷新页面
            setTimeout(() => { if (stoppedGen === autoWriteGen) location.reload(); }, 1000);
        } else {
            showToast('取消失败：' + data.msg, 'error');
        }
    } catch (err) {
        showToast('请求失败：' + err.message, 'error');
    }
}

// ============================================================
// 重置未完成章节
// ============================================================

async function resetChapters() {
    if (!confirm('确定要重置所有未完成的章节吗？\n\n这将清空所有未完成章节的内容，恢复到"已大纲"状态。')) {
        return;
    }
    
    stopAutoWriteLocal();
    const stoppedGen = autoWriteGen;
    try {
        const data = await queueAutoWriteStateChange(() => apiPost('cancel_write', {
            action: 'reset',
            novel_id: NOVEL_ID
        }));
        if (stoppedGen !== autoWriteGen) return;
        
        if (data.ok) {
            showToast('已重置未完成章节', 'success');
            setTimeout(() => { if (stoppedGen === autoWriteGen) location.reload(); }, 1000);
        } else {
            showToast('重置失败：' + data.msg, 'error');
        }
    } catch (err) {
        showToast('请求失败：' + err.message, 'error');
    }
}

// ============================================================
// 清空所有章节
// ============================================================

window.clearAllChapters = async function() {
    if (!confirm('确定要清空所有章节内容吗？\n\n此操作将删除所有章节、版本历史、写作日志等数据，并将小说状态重置为"草稿"。此操作不可撤销！')) {
        return;
    }

    if (!confirm('再次确认：这将删除该小说下的所有章节数据，是否继续？')) {
        return;
    }

    try {
        const res = await apiPost('actions', {
            action: 'clear_all_chapters',
            novel_id: NOVEL_ID,
        });

        if (res.ok) {
            showToast('已清空所有章节', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast('清空失败：' + (res.msg || ''), 'error');
        }
    } catch (err) {
        showToast('请求失败：' + err.message, 'error');
    }
};

// ============================================================
// [v4] 一致性检查
// ============================================================

async function runConsistencyCheck() {
    const btn = document.getElementById('btn-consistency-check');
    if (!btn) return;
    
    const novelId = parseInt(btn.dataset.novel);
    const chapterNumber = parseInt(btn.dataset.chapter);
    
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> 检查中...';
    
    try {
        const data = await apiPost('validate_consistency', {
            novel_id: novelId,
            chapter_number: chapterNumber || 0
        });
        
        if (!data.ok) {
            showToast('检查失败：' + data.msg, 'error');
            return;
        }
        
        const { issues, warnings } = data.data;
        
        if (issues.length === 0 && warnings.length === 0) {
            showToast('一致性检查通过，未发现问题', 'success');
            return;
        }
        
        // 显示检查结果
        let html = '<div class="consistency-report">';
        
        if (issues.length > 0) {
            html += '<h6 class="text-danger">⚠️ 发现问题 (' + issues.length + ')</h6>';
            html += '<ul class="list-group mb-3">';
            issues.forEach(issue => {
                // 审计修复 H5（2026-06-12）：所有动态字段进入 innerHTML 前必须 escHtml。
                html += `<li class="list-group-item list-group-item-danger">
                    <strong>第${escHtml(issue.chapter)}章 · ${escHtml(issue.type)}</strong>
                    <p class="mb-0 mt-1">${escHtml(issue.message)}</p>
                </li>`;
            });
            html += '</ul>';
        }
        
        if (warnings.length > 0) {
            html += '<h6 class="text-warning">⚡️ 建议关注 (' + warnings.length + ')</h6>';
            html += '<ul class="list-group">';
            warnings.forEach(warn => {
                html += `<li class="list-group-item list-group-item-warning">
                    <strong>第${escHtml(warn.chapter)}章 · ${escHtml(warn.type)}</strong>
                    <p class="mb-0 mt-1">${escHtml(warn.message)}</p>
                </li>`;
            });
            html += '</ul>';
        }
        
        html += '</div>';
        
        // 显示模态框
        const modal = document.createElement('div');
        modal.className = 'modal fade';
        modal.innerHTML = safeHtml(`
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">一致性检查报告</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        ${html}
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
                    </div>
                </div>
            </div>
        `);
        document.body.appendChild(modal);
        new bootstrap.Modal(modal).show();
        
        // 模态框关闭后移除DOM
        modal.addEventListener('hidden.bs.modal', () => modal.remove());
        
        if (issues.length > 0) {
            showToast('发现 ' + issues.length + ' 个一致性问题', 'error');
        } else {
            showToast('检查完成，有 ' + warnings.length + ' 个建议', 'warning');
        }
        
    } catch (err) {
        showToast('检查失败：' + err.message, 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = '一致性检查';
    }
}

// ============================================================
// 重置单个章节
// ============================================================

// 唯一实现（审计 P3-D 收敛，2026-06-11）：走 actions.reset_chapter——
// 写作中会先发取消信号再清空正文；失败章则直接清空回已大纲。
// 重置前自动做版本备份并刷新小说统计；残留的 write_cancel flag 由
// WriteEngine::resolveChapter 在下次写作启动时自动清理。
window.resetSingleChapter = async function(chapterId) {
    if (!confirm('确定要取消并重置这个章节吗？\n\n章节内容将被清空，恢复到"已大纲"状态（原内容自动存入历史版本）。')) {
        return;
    }

    stopAutoWriteLocal();
    const stoppedGen = autoWriteGen;
    try {
        const data = await queueAutoWriteStateChange(() => apiPost('actions', {
            action: 'reset_chapter',
            chapter_id: chapterId
        }));
        if (stoppedGen !== autoWriteGen) return;

        if (data.ok) {
            showToast(data.msg || '已重置章节', 'success');
            // 刷新章节列表
            if (typeof refreshChapterList === 'function') {
                await refreshChapterList();
            }
        } else {
            showToast('重置失败：' + (data.error || data.msg || '未知错误'), 'error');
            // 审计整改 C1：章节状态已变化（刚开始写作 / 刚写完）时刷新列表，显示真实状态
            if (data.code === 'CHAPTER_CONFLICT' && typeof refreshChapterList === 'function') {
                await refreshChapterList();
            }
        }
    } catch (err) {
        showToast('请求失败：' + err.message, 'error');
    }
};

// ============================================================
// 补写缺失大纲
// ============================================================

async function supplementOutline() {
    const btnSupp = document.getElementById('btn-supplement-outline');
    if (!btnSupp) return;
    if (outlineRunning) { showToast('大纲生成正在进行中，请等待完成', 'warning'); return; }
    outlineRunning = true;
    const novelId = parseInt(btnSupp.dataset.novel);

    // ---- UI 元素（复用大纲生成面板） ----
    const wrap      = document.getElementById('outline-progress-wrap');
    const label     = document.getElementById('outline-progress-label');
    const spinner   = document.getElementById('outline-spinner');
    const streamBox = document.getElementById('outline-stream-box');
    const batchLog  = document.getElementById('outline-batch-log');
    const tokenBar  = document.getElementById('outline-token-bar');
    const tokPrompt     = document.getElementById('tok-prompt');
    const tokCompletion = document.getElementById('tok-completion');
    const tokTotal      = document.getElementById('tok-total');

    let cumPrompt = 0, cumCompletion = 0;

    wrap.style.display     = '';
    btnSupp.disabled       = true;
    streamBox.textContent  = '';
    batchLog.style.display = 'none';
    batchLog.innerHTML     = '';
    tokenBar.style.display = 'none';
    spinner.style.display  = '';
    label.textContent      = '正在扫描缺失大纲...';

    const cursor = document.createElement('span');
    cursor.className = 'outline-stream-cursor';
    streamBox.appendChild(cursor);

    try {
        const response = await fetch(apiRouteUrl('supplement_outline'), {
            method:  'POST',
            headers: jsonHeaders(),
            body:    JSON.stringify({ novel_id: novelId }),
        });

        if (!response.ok) {
            showToast('服务器错误: ' + response.status, 'error');
            return;
        }

        const reader  = response.body.getReader();
        const decoder = new TextDecoder();
        let buf = '', currentEvent = '';

        while (true) {
            const { value, done } = await reader.read();
            if (done) break;

            buf += decoder.decode(value, { stream: true });
            const lines = buf.split('\n');
            buf = lines.pop();

            for (const line of lines) {
                const trimmed = line.trim();
                if (trimmed.startsWith(':')) { currentEvent = ''; continue; }
                if (trimmed.startsWith('event: ')) {
                    currentEvent = trimmed.slice(7).trim();
                    continue;
                }
                if (!trimmed.startsWith('data: ')) continue;
                const raw = trimmed.slice(6);
                if (raw === '[DONE]') break;

                let d;
                try { d = JSON.parse(raw); } catch { currentEvent = ''; continue; }

                switch (currentEvent) {
                    case 'scan_result':
                        label.textContent = d.msg || '扫描完成';
                        break;

                    case 'progress':
                        label.textContent = d.msg || '补写中...';
                        streamBox.textContent = '';
                        streamBox.appendChild(cursor);
                        break;

                    case 'chunk':
                        if (d.t) {
                            // 确保 cursor 在 streamBox 中，避免 insertBefore 错误
                            if (!streamBox.contains(cursor)) {
                                streamBox.appendChild(cursor);
                            }
                            streamBox.insertBefore(document.createTextNode(d.t), cursor);
                            streamBox.scrollTop = streamBox.scrollHeight;
                        }
                        break;

                    case 'model_switch': {
                        streamBox.textContent = '';
                        streamBox.appendChild(cursor);
                        batchLog.style.display = '';
                        const sw = document.createElement('div');
                        sw.className = 'outline-batch-item warning';
                        sw.innerHTML = `<span><i class="bi bi-arrow-repeat me-1"></i>${escHtml(d.msg)}</span>`;
                        batchLog.appendChild(sw);
                        label.textContent = d.msg;
                        showToast(`切换模型：${d.next_model}`, 'info');
                        break;
                    }

                    case 'batch_done': {
                        cumPrompt     += (d.prompt_tokens     || 0);
                        cumCompletion += (d.completion_tokens || 0);
                        tokenBar.style.display = '';
                        tokPrompt.textContent     = fmtNum(cumPrompt);
                        tokCompletion.textContent = fmtNum(cumCompletion);
                        tokTotal.textContent      = fmtNum(cumPrompt + cumCompletion);

                        batchLog.style.display = '';
                        const item = document.createElement('div');
                        item.className = 'outline-batch-item success';
                        item.innerHTML =
                            `<span><i class="bi bi-check-circle me-1"></i>${escHtml(d.msg)}</span>` +
                            `<span class="token-badge">` +
                            `<span>↑ ${fmtNum(d.prompt_tokens)}</span>` +
                            `<span>↓ ${fmtNum(d.completion_tokens)}</span>` +
                            `<span>∑ ${fmtNum(d.total_tokens)}</span>` +
                            `</span>`;
                        batchLog.appendChild(item);
                        batchLog.scrollTop = batchLog.scrollHeight;
                        label.textContent = d.msg;
                        break;
                    }

                    case 'error': {
                        batchLog.style.display = '';
                        const ei = document.createElement('div');
                        ei.className = 'outline-batch-item error';
                        ei.textContent = d.msg;
                        batchLog.appendChild(ei);
                        showToast(d.msg, 'error');
                        break;
                    }

                    case 'complete':
                        spinner.style.display = 'none';
                        cursor.remove();
                        label.textContent = d.msg;
                        showToast('大纲补写完成！', 'success');
                        setTimeout(() => location.reload(), 1800);
                        return;
                }
                currentEvent = '';
            }
        }

        // 流结束但没收到 complete
        spinner.style.display = 'none';
        cursor.remove();
        label.textContent = '补写流程已结束';
        setTimeout(() => location.reload(), 1500);

    } catch (err) {
        showToast('补写出错：' + err.message, 'error');
        label.textContent = '出错：' + err.message;
        spinner.style.display = 'none';
    } finally {
        btnSupp.disabled = false;
        outlineRunning = false;
    }
}

// ============================================================
// [v2新增] 生成全书故事大纲
// ============================================================

// STORY_OUTLINE_TASK_CLIENT_BEGIN
// Generation belongs to a durable server task. Transport retries only read its state.
class StoryOutlineTaskController {
    constructor(novelId) {
        this.novelId = novelId;
        this.task = null;
        this.timer = null;
        this.busy = false;
        this.refreshOnSuccess = false;
        this.reloaded = false;
        this.networkFailures = 0;
        this.epoch = 0;
        this.pendingRequests = {};
        this.submitting = false;
        const restart = this.element('btn-restart-story-outline');
        if (restart) restart.addEventListener('click', () => this.regenerate());
    }

    element(id) { return document.getElementById(id); }

    setBusy(busy) {
        this.busy = busy;
        for (const id of ['btn-story-outline', 'btn-regenerate-story-outline', 'btn-resume-story-outline', 'btn-restart-story-outline']) {
            const button = this.element(id);
            if (button) button.disabled = busy;
        }
    }

    setLabel(message) {
        const wrap = this.element('story-outline-progress-wrap');
        const label = this.element('story-outline-progress-label');
        const finished = this.task && this.task.state === 'succeeded';
        if (wrap) wrap.style.display = finished ? 'none' : '';
        if (label) label.textContent = finished ? '' : message;
    }

    active(task) {
        return task && ['pending', 'queued', 'running', 'retry_wait'].includes(task.state);
    }

    canWakeWorker(task) {
        return this.active(task) && task.worker_needed === true && task.worker_waiting === true
            && !task.cancel_requested && typeof task.task_id === 'string' && task.task_id !== ''
            && ['resume', 'regenerate'].includes(task.request_mode)
            && typeof task.request_key === 'string' && /^[a-zA-Z0-9_-]{8,120}$/.test(task.request_key);
    }

    requestKey(mode) {
        const storageKey = `story-outline-pending:${this.novelId}:${mode}`;
        let key = this.pendingRequests[mode];
        try { key = key || localStorage.getItem(storageKey); } catch (_) {}
        if (!key || !/^[a-zA-Z0-9_-]{8,100}$/.test(key)) {
            key = typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
                ? crypto.randomUUID() : 'story_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2);
        }
        this.pendingRequests[mode] = key;
        try { localStorage.setItem(storageKey, key); } catch (_) {}
        return key;
    }

    acknowledge(mode, requestKey) {
        if (this.pendingRequests[mode] === requestKey) delete this.pendingRequests[mode];
        try {
            const storageKey = `story-outline-pending:${this.novelId}:${mode}`;
            if (localStorage.getItem(storageKey) === requestKey) localStorage.removeItem(storageKey);
        } catch (_) {}
    }

    async request(route, body = null, taskId = null) {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 20000);
        let query = body ? '' : 'novel_id=' + encodeURIComponent(this.novelId);
        if (!body && taskId) query += '&task_id=' + encodeURIComponent(taskId);
        try {
            const response = await fetch(apiRouteUrl(route, query), {
                method: body ? 'POST' : 'GET',
                headers: body ? jsonHeaders() : { Accept: 'application/json' },
                ...(body ? { body: JSON.stringify(body) } : {}),
                cache: 'no-store',
                signal: controller.signal,
            });
            let data;
            try { data = await response.json(); } catch (_) {
                const error = new Error(`服务器返回了无效响应（HTTP ${response.status}）`);
                error.status = response.status;
                throw error;
            }
            if (!response.ok || !data || data.success !== true) {
                const error = new Error((data && (data.error || data.message || data.msg)) || `请求失败（HTTP ${response.status}）`);
                error.status = response.status;
                throw error;
            }
            return data;
        } finally {
            clearTimeout(timeout);
        }
    }

    stopPolling() {
        if (this.timer !== null) clearTimeout(this.timer);
        this.timer = null;
    }

    schedule(delay = 1000) {
        this.stopPolling();
        this.timer = setTimeout(() => { this.timer = null; this.poll(); }, delay);
    }

    render(task) {
        this.task = task;
        const active = this.active(task);
        this.setBusy(active);
        const spinner = this.element('story-outline-progress-spinner');
        const cancel = this.element('btn-cancel-story-outline');
        const resume = this.element('btn-resume-story-outline');
        const restart = this.element('btn-restart-story-outline');
        if (spinner) spinner.style.display = active ? '' : 'none';
        if (cancel) { cancel.hidden = !active || !task.task_id; cancel.disabled = false; }
        const canWake = this.canWakeWorker(task) && !this.submitting;
        if (resume) {
            resume.hidden = !canWake && (!task.can_resume || active);
            resume.disabled = this.submitting || (active && !canWake);
            resume.textContent = canWake ? '重新唤起后台' : '继续未完成任务';
        }
        if (restart) restart.hidden = !['failed', 'canceled', 'conflicted'].includes(task.state);
        const total = Number(task.total_stages) || 3;
        const completed = Math.max(0, Math.min(total, Array.isArray(task.completed_stages)
            ? task.completed_stages.length : Number(task.completed_stages) || 0));
        const messages = {
            pending: '任务已保存，等待后台开始',
            queued: '任务已保存，等待后台开始',
            running: `正在继续生成故事大纲（已完成 ${completed}/${total} 个阶段）`,
            retry_wait: `当前阶段等待重试（已完成 ${completed}/${total} 个阶段）`,
            failed: '生成已暂停，已完成的阶段已保留',
            canceled: '任务已停止，已完成的阶段已保留',
            conflicted: '小说设定或大纲已被修改，请重新生成',
        };
        this.setLabel(active && task.worker_waiting
            ? `任务等待后台接手（已完成 ${completed}/${total} 个阶段）`
            : messages[task.state] || '正在读取任务状态');
        const detail = this.element('story-outline-task-detail');
        if (detail) {
            const launch = active && task.worker_needed && task.worker_launch;
            const launchMessage = launch && typeof launch.message === 'string' ? launch.message : '';
            detail.textContent = [task.error, task.updated_at_display ? '更新时间：' + task.updated_at_display : '',
                launchMessage ? '后台启动：' + launchMessage : '',
                active && task.worker_waiting
                    ? this.canWakeWorker(task)
                        ? '任务等待后台接手已超过30秒；可点击“重新唤起后台”重试同一任务。'
                        : '任务等待后台接手已超过30秒；请检查服务器后台进程是否正在运行。'
                    : active && task.worker_needed ? '任务已入队，等待后台进程接手。' : '',
                active ? '关闭或刷新页面后可继续查看进度。' : ''].filter(Boolean).join('　');
        }
        const preview = this.element('story-outline-stream-box');
        if (preview && typeof task.preview === 'string' && preview.textContent !== task.preview) {
            preview.textContent = task.preview;
            preview.scrollTop = preview.scrollHeight;
        }
    }

    async accept(task, epoch) {
        if (epoch !== this.epoch) return;
        if (!task) {
            this.stopPolling();
            this.setBusy(false);
            if (this.refreshOnSuccess) this.setLabel('未找到可恢复的任务，请点击生成按钮重试');
            return;
        }
        if (typeof task !== 'object' || typeof task.state !== 'string') throw new Error('服务器未返回有效的任务状态');
        if (['resume', 'regenerate'].includes(task.request_mode) && typeof task.request_key === 'string') {
            this.acknowledge(task.request_mode, task.request_key);
        }
        this.render(task);
        if (this.active(task)) {
            this.refreshOnSuccess = true;
            this.schedule();
            return;
        }
        this.stopPolling();
        if (task.state !== 'succeeded' || !task.result_ready) return;
        // Confirm publication by reading the saved result; a broken connection never starts generation.
        const result = await this.request('get_story_outline');
        if (!result.data || typeof result.data !== 'object') throw new Error('故事大纲结果尚未读取成功');
        if (epoch !== this.epoch) return;
        if (this.refreshOnSuccess && !this.reloaded) {
            this.reloaded = true;
            showToast('全书故事大纲已保存，正在读取结果', 'success');
            location.reload();
        }
    }

    networkError(error) {
        const permanent = [400, 401, 403, 404, 422].includes(error.status);
        const resume = this.element('btn-resume-story-outline');
        this.setBusy(false);
        if (resume) {
            const canWake = this.canWakeWorker(this.task);
            resume.hidden = this.active(this.task) && !canWake;
            resume.disabled = this.submitting;
            resume.textContent = canWake ? '重新唤起后台' : '继续未完成任务';
        }
        if (permanent) {
            this.stopPolling();
            this.setLabel('读取任务失败：' + error.message);
            return;
        }
        const delay = [2000, 4000, 8000, 15000][Math.min(this.networkFailures++, 3)];
        this.setLabel('暂时无法读取进度，正在恢复查询；已保存的任务进度会保留');
        this.schedule(delay);
    }

    async poll() {
        const epoch = this.epoch;
        try {
            const data = await this.request('story_outline_status', null, this.task && this.task.task_id);
            if (epoch !== this.epoch) return;
            if (!Object.prototype.hasOwnProperty.call(data, 'task')) throw new Error('服务器未返回任务状态');
            await this.accept(data.task, epoch);
            this.networkFailures = 0;
        } catch (error) {
            if (epoch === this.epoch) this.networkError(error);
        }
    }

    async regenerate() {
        if (this.busy || !confirm('确定要从头重新生成故事大纲吗？\n\n原大纲会保留到新大纲完整生成并保存成功。若只想继续中断的任务，请使用“继续未完成任务”。')) return;
        await this.start('regenerate');
    }

    async wakeWorker() {
        if (this.submitting || !this.canWakeWorker(this.task)) return;
        const original = this.task;
        const epoch = ++this.epoch;
        this.stopPolling();
        this.submitting = true;
        this.setBusy(true);
        const cancel = this.element('btn-cancel-story-outline');
        if (cancel) cancel.disabled = true;
        this.setLabel('正在重新唤起已保存的任务...');
        try {
            const data = await this.request('generate_story_outline', {
                novel_id: this.novelId, request_key: original.request_key, mode: original.request_mode,
            });
            if (epoch !== this.epoch) return;
            if (!data.task || data.task.task_id !== original.task_id) {
                throw new Error('后台返回的任务身份不一致，请刷新核对原任务进度');
            }
            this.networkFailures = 0;
            await this.accept(data.task, epoch);
        } catch (error) {
            if (epoch !== this.epoch) return;
            // A lost wake acknowledgment never creates a successor or resubmits automatically.
            this.networkError(error);
            showToast('暂时无法确认后台接手：' + error.message + '。正在继续查询原任务。', 'warning');
        } finally {
            if (epoch === this.epoch) {
                this.submitting = false;
                if (cancel) cancel.disabled = false;
                if (this.task) this.render(this.task);
            }
        }
    }

    async start(mode = 'resume') {
        if (this.busy || this.submitting || this.active(this.task)) return;
        this.stopPolling();
        const epoch = ++this.epoch;
        this.task = null;
        this.setBusy(true);
        this.refreshOnSuccess = true;
        this.setLabel(mode === 'regenerate' ? '正在提交重新生成任务...' : '正在查找并恢复未完成任务...');
        const requestKey = this.requestKey(mode);
        try {
            const data = await this.request('generate_story_outline', {
                novel_id: this.novelId, request_key: requestKey, mode,
            });
            if (epoch !== this.epoch) return;
            if (!data.task || typeof data.task.state !== 'string') throw new Error('服务器未返回任务状态');
            this.acknowledge(mode, requestKey);
            this.networkFailures = 0;
            await this.accept(data.task, epoch);
        } catch (error) {
            if (epoch !== this.epoch) return;
            // The POST may have succeeded. Keep its key and only query until the user retries.
            this.networkError(error);
            showToast('任务提交结果尚未确认：' + error.message + '。再次继续会恢复原任务。', 'warning');
        } finally {
            if (epoch === this.epoch && !this.active(this.task)) this.setBusy(false);
        }
    }

    async cancel() {
        if (this.submitting || !this.task || !this.task.task_id || !this.active(this.task)) return;
        const button = this.element('btn-cancel-story-outline');
        if (button) button.disabled = true;
        try {
            const data = await this.request('story_outline_cancel', {
                novel_id: this.novelId, task_id: this.task.task_id,
            });
            if (data.task) await this.accept(data.task, this.epoch);
            else this.schedule(500);
            showToast('停止请求已提交，已完成的阶段会保留', 'info');
        } catch (error) {
            showToast('暂时无法确认停止结果：' + error.message, 'warning');
            this.schedule(2000);
        } finally {
            if (button) button.disabled = false;
        }
    }
}

let storyOutlineTaskController = null;
function getStoryOutlineTaskController() {
    const button = document.getElementById('btn-story-outline');
    if (!button) return null;
    if (!storyOutlineTaskController) storyOutlineTaskController = new StoryOutlineTaskController(parseInt(button.dataset.novel, 10));
    return storyOutlineTaskController;
}
async function recoverStoryOutlineTask() {
    const controller = getStoryOutlineTaskController();
    if (controller) await controller.poll();
}
async function generateStoryOutline(mode = 'resume') {
    const controller = getStoryOutlineTaskController();
    if (controller) {
        if (mode === 'resume' && controller.canWakeWorker(controller.task)) await controller.wakeWorker();
        else await controller.start(mode);
    }
}
// STORY_OUTLINE_TASK_CLIENT_END

// ============================================================
// [v2新增] 生成章节概要
// ============================================================

window.generateStoryOutline = generateStoryOutline;
window.regenerateStoryOutline = regenerateStoryOutline;

async function generateChapterSynopsis() {
    const btn = document.getElementById('btn-synopsis');
    if (!btn || btn.disabled) return;
    const novelId = parseInt(btn.dataset.novel);
    const outlined = parseInt(btn.dataset.outlined);
    if (!confirm(`确定要为所有已大纲的章节生成概要吗？\n\n这将生成详细的章节写作蓝图，帮助提高小说质量。\n共 ${outlined} 章需要生成概要。`)) return;

    const wrap = document.getElementById('synopsis-progress-wrap');
    const label = document.getElementById('synopsis-progress-label');
    const streamBox = document.getElementById('synopsis-stream-box');
    wrap.style.display = '';
    btn.disabled = true;
    streamBox.textContent = '';
    const cursor = document.createElement('span');
    cursor.className = 'outline-stream-cursor';
    streamBox.appendChild(cursor);
    let reader;
    let terminal = false;
    try {
        // The server may continue after disconnect. Never create a second model
        // request merely because the transport failed; publication also checks its source.
        const response = await fetch(apiRouteUrl('generate_chapter_synopsis'), {
            method: 'POST', headers: jsonHeaders(), body: JSON.stringify({ novel_id: novelId }),
        });
        if (!response.ok) throw new Error('服务器错误: ' + response.status);
        reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buf = '', currentEvent = '';
        const append = (text) => {
            if (!streamBox.contains(cursor)) streamBox.appendChild(cursor);
            streamBox.insertBefore(document.createTextNode(text), cursor);
            streamBox.scrollTop = streamBox.scrollHeight;
        };
        while (true) {
            const { value, done } = await reader.read();
            if (done) throw new Error('连接已中断，后台可能仍在处理。请稍后刷新章节列表查看结果');
            buf += decoder.decode(value, { stream: true });
            const lines = buf.split('\n');
            buf = lines.pop();
            for (const line of lines) {
                const trimmed = line.trim();
                if (trimmed.startsWith(':')) continue;
                if (trimmed.startsWith('event: ')) { currentEvent = trimmed.slice(7).trim(); continue; }
                if (!trimmed.startsWith('data: ')) continue;
                const raw = trimmed.slice(6);
                if (raw === '[DONE]') throw new Error('未收到概要处理结果，请刷新章节列表确认');
                let d;
                try { d = JSON.parse(raw); } catch { currentEvent = ''; continue; }
                switch (currentEvent) {
                    case 'progress': label.textContent = d.msg || '生成中...'; break;
                    case 'chunk': if (d.t) append(d.t); break;
                    case 'model_switch':
                        streamBox.textContent = '';
                        append(`[切换到「${d.next_model}」重试...]\n`);
                        showToast(`模型切换 → ${d.next_model}`, 'info');
                        break;
                    case 'chapter_done': append(`\n✓ ${d.msg}\n\n`); break;
                    case 'error':
                    case 'conflict':
                        append(`\n${d.msg || '该章未保存'}\n`);
                        showToast(d.msg || '该章未保存', currentEvent === 'conflict' ? 'warning' : 'error');
                        break;
                    case 'complete': {
                        terminal = true;
                        const succeeded = Number(d.succeeded ?? d.total_chapters ?? 0);
                        const clean = d.status === 'completed' && succeeded > 0;
                        label.textContent = d.msg || `已保存 ${succeeded} 章概要`;
                        showToast(label.textContent, clean ? 'success' : succeeded > 0 ? 'warning' : 'error');
                        // Retain failure/conflict details on screen for targeted retry.
                        if (succeeded > 0) await refreshChapterList();
                        return;
                    }
                    case 'fatal_error':
                        terminal = true;
                        label.textContent = d.msg || '概要生成过程发生内部错误';
                        showToast(label.textContent, 'error');
                        return;
                }
                currentEvent = '';
            }
        }
    } catch (err) {
        label.textContent = terminal ? '刷新章节列表失败：' + err.message
            : '连接已结束，后台可能仍在处理。请稍后刷新章节列表查看结果；不会自动重复生成。';
        showToast(terminal ? label.textContent : label.textContent + ' ' + err.message, 'error');
    } finally {
        cursor.remove();
        btn.disabled = false;
        if (reader) reader.cancel().catch(() => {});
    }
}
window.generateVolumeOutline = generateVolumeOutline;

async function generateVolumeOutline() {
    const btn = document.getElementById('btn-volume-outline');
    if (!btn || btn.disabled) return;

    const novelId = parseInt(btn.dataset.novel, 10);
    const volumes = parseInt(btn.dataset.volumes || '0', 10);
    const hasStory = btn.dataset.hasStory === '1';
    const target = parseInt(btn.dataset.target || '0', 10);

    if (!hasStory) {
        showToast('请先生成全书故事大纲', 'error');
        return;
    }
    if (target < 30) {
        showToast('目标章节少于30章，无需生成卷纲', 'info');
        return;
    }

    let force = false;
    if (volumes > 0) {
        if (!confirm(`已有 ${volumes} 卷纲。重新生成会覆盖现有卷主题/目标（含 volume_goals）。确定继续？`)) {
            return;
        }
        force = true;
    } else if (!confirm('确定生成卷级大纲吗？这将按全书故事大纲划分卷主题、核心冲突和本卷写作目标。')) {
        return;
    }

    const wrap = document.getElementById('volume-outline-progress-wrap');
    const label = document.getElementById('volume-outline-progress-label');
    const streamBox = document.getElementById('volume-outline-stream-box');
    if (!wrap || !label || !streamBox) return;

    wrap.style.display = '';
    btn.disabled = true;
    streamBox.textContent = '';

    const cursor = document.createElement('span');
    cursor.className = 'outline-stream-cursor';
    streamBox.appendChild(cursor);

    try {
        const response = await fetch(apiRouteUrl('generate_volume_outline'), {
            method: 'POST',
            headers: jsonHeaders(),
            body: JSON.stringify({ novel_id: novelId, force }),
        });
        if (!response.ok) {
            showToast('服务器错误: ' + response.status, 'error');
            btn.disabled = false;
            return;
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buf = '', currentEvent = '';

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            buf += decoder.decode(value, { stream: true });
            const parts = buf.split('\n\n');
            buf = parts.pop() || '';
            for (const block of parts) {
                const lines = block.split('\n');
                let dataLine = '';
                for (const line of lines) {
                    if (line.startsWith('event:')) currentEvent = line.slice(6).trim();
                    if (line.startsWith('data:')) dataLine += line.slice(5).trim();
                }
                if (!dataLine) continue;
                let d = {};
                try { d = JSON.parse(dataLine); } catch { continue; }

                switch (currentEvent) {
                    case 'progress':
                        label.textContent = d.msg || '生成中...';
                        break;
                    case 'chunk':
                        if (d.t) {
                            if (!streamBox.contains(cursor)) streamBox.appendChild(cursor);
                            streamBox.insertBefore(document.createTextNode(d.t), cursor);
                            streamBox.scrollTop = streamBox.scrollHeight;
                        }
                        break;
                    case 'model_switch':
                        showToast(`模型切换 → ${d.next_model}`, 'info');
                        break;
                    case 'error':
                        showToast(d.msg || '卷纲生成失败', 'error');
                        label.textContent = d.msg || '生成失败';
                        btn.disabled = false;
                        return;
                    case 'complete':
                        cursor.remove();
                        label.textContent = d.msg || '卷纲生成完成';
                        showToast('卷纲生成完成！', 'success');
                        setTimeout(() => location.reload(), 1500);
                        return;
                }
                currentEvent = '';
            }
        }
    } catch (err) {
        showToast('生成出错：' + err.message, 'error');
        label.textContent = '出错：' + err.message;
    }

    btn.disabled = false;
}


// ============================================================
// 章节详情弹窗
// ============================================================

// 打开弹窗时加载章节数据
document.body.addEventListener('click', async (e) => {
    const btn = e.target.closest('.btn-chapter-detail');
    if (!btn) return;

    const chapterId  = parseInt(btn.dataset.chapterId);
    const chapterNum = parseInt(btn.dataset.chapterNum);

    // 设置基本信息
    document.getElementById('detail-chapter-id').value  = chapterId;
    document.getElementById('detail-chapter-num').textContent = chapterNum;

    // 清空表单
    document.getElementById('detail-title').value    = '';
    document.getElementById('detail-outline').value  = '';
    document.getElementById('detail-synopsis').textContent = '加载中...';
    document.getElementById('detail-content').textContent  = '加载中...';
    document.getElementById('detail-words').textContent    = '0 字';
    document.getElementById('detail-plot-hint').value = '';

    const modal = new bootstrap.Modal(document.getElementById('chapterDetailModal'));
    modal.show();

    // 加载章节详情
    try {
        const res = await apiPost('chapter_actions', {
            action: 'get_detail',
            chapter_id: chapterId,
        });

        if (res.ok && res.data) {
            const d = res.data;
            document.getElementById('detail-title').value    = d.title || '';
            document.getElementById('detail-outline').value  = d.outline || '';
            document.getElementById('detail-synopsis').textContent = d.synopsis || '暂无概要';
            document.getElementById('detail-content').textContent  = d.content || '暂无内容';
            document.getElementById('detail-words').textContent    = (d.word_count || 0) + ' 字';
            // 更新查看链接
            document.getElementById('detail-view-chapter').href = 'chapter.php?id=' + chapterId;
        } else {
            showToast(res.msg || '加载失败', 'error');
        }
    } catch (err) {
        showToast('加载失败：' + err.message, 'error');
    }
});

// 保存标题
document.getElementById('btn-update-title')?.addEventListener('click', async function() {
    const chapterId = parseInt(document.getElementById('detail-chapter-id').value);
    const title     = document.getElementById('detail-title').value.trim();

    if (!title) {
        showToast('标题不能为空', 'error');
        return;
    }

    this.disabled = true;
    this.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    try {
        const res = await apiPost('chapter_actions', {
            action: 'update_title',
            chapter_id: chapterId,
            title: title,
        });

        if (res.ok) {
            showToast('标题已更新', 'success');
            await refreshChapterList();
        } else {
            showToast(res.msg || '更新失败', 'error');
        }
    } catch (err) {
        showToast('更新失败：' + err.message, 'error');
    } finally {
        this.disabled = false;
        this.innerHTML = '<i class="bi bi-check-lg"></i> 保存标题';
    }
});

// 保存大纲
document.getElementById('btn-update-outline')?.addEventListener('click', async function() {
    const chapterId = parseInt(document.getElementById('detail-chapter-id').value);
    const outline   = document.getElementById('detail-outline').value.trim();

    this.disabled = true;
    this.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    try {
        const res = await apiPost('chapter_actions', {
            action: 'update_outline',
            chapter_id: chapterId,
            outline: outline,
        });

        if (res.ok) {
            showToast('大纲已更新', 'success');
        } else {
            showToast(res.msg || '更新失败', 'error');
        }
    } catch (err) {
        showToast('更新失败：' + err.message, 'error');
    } finally {
        this.disabled = false;
        this.innerHTML = '<i class="bi bi-check-lg me-1"></i>保存大纲';
    }
});

// 清空章节内容
document.getElementById('btn-clear-content')?.addEventListener('click', async function() {
    const chapterId = parseInt(document.getElementById('detail-chapter-id').value);
    const title     = document.getElementById('detail-title').value;

    if (!confirm(`确定要清空「${title}」的章节内容吗？\n\n清空后内容将被删除，状态重置为"已大纲"。`)) {
        return;
    }

    this.disabled = true;
    this.innerHTML = '<span class="spinner-border spinner-border-sm"></span> 清空中...';

    try {
        const res = await apiPost('chapter_actions', {
            action: 'clear_content',
            chapter_id: chapterId,
        });

        if (res.ok) {
            showToast('章节内容已清空', 'success');
            document.getElementById('detail-content').textContent = '暂无内容';
            document.getElementById('detail-words').textContent   = '0 字';
            await refreshChapterList();
        } else {
            showToast(res.msg || '清空失败', 'error');
        }
    } catch (err) {
        showToast('清空失败：' + err.message, 'error');
    } finally {
        this.disabled = false;
        this.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>确认清空';
    }
});

// 重新生成章节
document.getElementById('btn-regenerate')?.addEventListener('click', async function() {
    const chapterId = parseInt(document.getElementById('detail-chapter-id').value);
    const plotHint  = document.getElementById('detail-plot-hint').value.trim();

    if (!confirm('确定要重新生成本章内容吗？\n\n当前内容将被替换。')) {
        return;
    }

    const btn       = this;
    const hintInput = document.getElementById('detail-plot-hint');
    btn.disabled    = true;
    hintInput.disabled = true;
    btn.innerHTML   = '<span class="spinner-border spinner-border-sm"></span> 生成中...';

    // 关闭详情弹窗，打开写作弹窗显示流式内容
    const detailModal = bootstrap.Modal.getInstance(document.getElementById('chapterDetailModal'));
    if (detailModal) detailModal.hide();

    const { contentEl, cur } = _openWriteModal('正在重新生成章节...');

    try {
        const response = await fetch(apiRouteUrl('chapter_actions'), {
            method: 'POST',
            headers: jsonHeaders(),
            body: JSON.stringify({
                action: 'regenerate',
                chapter_id: chapterId,
                plot_hint: plotHint,
            }),
        });

        if (!response.ok) {
            const errData = await response.json().catch(() => ({}));
            throw new Error(errData.msg || `服务器错误 (${response.status})`);
        }

        const ct = response.headers.get('Content-Type') || '';
        if (ct.includes('text/event-stream')) {
            // SSE 流式响应
            const reader  = response.body.getReader();
            const decoder = new TextDecoder();
            let buf = '';

            while (true) {
                const { value, done } = await reader.read();
                if (done) break;

                buf += decoder.decode(value, { stream: true });
                const lines = buf.split('\n');
                buf = lines.pop();

                for (const line of lines) {
                    if (!line.startsWith('data: ')) continue;
                    const payload = line.slice(6).trim();
                    if (payload === '[DONE]') break;

                    try {
                        const d = JSON.parse(payload);
                        if (d.chunk) {
                            if (cur && cur.parentNode === contentEl) {
                                contentEl.insertBefore(document.createTextNode(d.chunk), cur);
                            } else {
                                contentEl.textContent += d.chunk;
                            }
                            contentEl.scrollTop = contentEl.scrollHeight;
                        }
                        if (d.model_switch) {
                            contentEl.textContent = '';
                            if (cur) contentEl.appendChild(cur);
                            const hint = document.createTextNode(`[切换到「${d.next_model}」重新生成...]\n`);
                            contentEl.insertBefore(hint, cur || null);
                            showToast(`模型切换 → ${d.next_model}`, 'info');
                        }
                        if (d.stats) {
                            document.getElementById('writeModalSpinner').style.display = 'none';
                            document.getElementById('writeModalStats').textContent = d.stats;
                            if (cur) cur.remove();
                            if (d.chapter_id) {
                                const v = document.getElementById('writeModalViewBtn');
                                v.href = `chapter.php?id=${d.chapter_id}`;
                                v.style.display = '';
                            }
                        }
                        if (d.error) throw new Error(d.error);
                    } catch (e) { console.error('[regenerateSingleSynopsis] SSE stream error:', e); }
                }
            }
        } else {
            // 普通 JSON 响应
            const data = await response.json();
            if (data.ok) {
                document.getElementById('writeModalSpinner').style.display = 'none';
                document.getElementById('writeModalStats').textContent = '重新生成完成';
                if (cur) cur.remove();
                showToast('章节已重新生成', 'success');
            } else {
                throw new Error(data.msg || '重新生成失败');
            }
        }

        await refreshChapterList();
    } catch (err) {
        contentEl.textContent = '重新生成失败：' + err.message;
        showToast('重新生成失败：' + err.message, 'error');
    } finally {
        btn.disabled       = false;
        hintInput.disabled = false;
        btn.innerHTML      = '<i class="bi bi-magic me-1"></i>重新生成';
    }
});

// ================================================================
// 优化大纲逻辑
// ================================================================
async function optimizeOutlineLogic() {
    const confirmed = confirm(
        '大纲逻辑优化将：\n' +
        '· 根据全书故事大纲检查所有未完成章节的大纲逻辑\n' +
        '· 已完成/正在写作的章节不会被改动\n' +
        '· 修复情节重复、逻辑断裂、与主线矛盾等问题\n' +
        '· 同步更新弧段故事线摘要\n\n' +
        '处理过程可能需要几分钟，是否继续？'
    );
    if (!confirmed) return;

    const progressWrap  = document.getElementById('optimize-outline-progress-wrap');
    const progressLabel = document.getElementById('optimize-outline-progress-label');
    const statsEl       = document.getElementById('optimize-outline-stats');
    const streamBox     = document.getElementById('optimize-outline-stream-box');
    const batchLog      = document.getElementById('optimize-outline-batch-log');
    const btn           = document.getElementById('btn-optimize-outline');

    btn.disabled          = true;
    progressWrap.style.display = '';
    streamBox.textContent = '';
    batchLog.style.display = 'none';
    batchLog.innerHTML    = '';
    progressLabel.textContent = '正在分析大纲逻辑...';
    statsEl.textContent   = '';

    const cursor = document.createElement('span');
    cursor.className = 'outline-stream-cursor';
    streamBox.appendChild(cursor);

    let totalChanged = 0;

    try {
        // 审计修复（2026-08-02 H12）：SSE 版优化此前无 AbortController，取消按钮
        // 引用的 optimizeOutlineController 从未定义 → 用户无法中断长时优化流。
        // 此处创建并暴露给 cancelOptimizeOutline() 使用。
        const optimizeAbort = new AbortController();
        window.optimizeOutlineController = optimizeAbort;

        const response = await fetch(apiRouteUrl('optimize_outline'), {
            method:  'POST',
            headers: jsonHeaders(),
            body:    JSON.stringify({ novel_id: NOVEL_ID }),
            signal:  optimizeAbort.signal,
        });

        if (!response.ok) {
            showToast('服务器错误: ' + response.status, 'error');
            btn.disabled = false;
            return;
        }

        const reader  = response.body.getReader();
        const decoder = new TextDecoder();
        let buf = '', currentEvent = '';

        while (true) {
            const { value, done } = await reader.read();
            if (done) break;

            buf += decoder.decode(value, { stream: true });
            const lines = buf.split('\n');
            buf = lines.pop();

            for (const line of lines) {
                const trimmed = line.trim();
                if (trimmed.startsWith(':')) { currentEvent = ''; continue; }
                if (trimmed.startsWith('event: ')) {
                    currentEvent = trimmed.slice(7).trim();
                    continue;
                }
                if (!trimmed.startsWith('data: ')) continue;
                const raw = trimmed.slice(6);
                if (raw === '[DONE]') break;

                let d;
                try { d = JSON.parse(raw); } catch { currentEvent = ''; continue; }

                switch (currentEvent) {

                    case 'chunk':
                        if (d.t) {
                            if (!streamBox.contains(cursor)) streamBox.appendChild(cursor);
                            streamBox.insertBefore(document.createTextNode(d.t), cursor);
                            streamBox.scrollTop = streamBox.scrollHeight;
                        }
                        break;

                    case 'progress':
                        progressLabel.textContent = d.msg || '优化中...';
                        break;

                    case 'quality_issue': {
                        progressLabel.textContent = d.msg || '检测到细纲逻辑问题，正在返修...';
                        batchLog.style.display = '';
                        const item = document.createElement('div');
                        item.className = 'p-2 border-bottom border-secondary small text-warning';
                        item.textContent = (d.msg || '细纲质量检查发现问题') + (d.summary ? '\n' + d.summary : '');
                        batchLog.appendChild(item);
                        batchLog.scrollTop = batchLog.scrollHeight;
                        break;
                    }

                    case 'quality_pass': {
                        batchLog.style.display = '';
                        const item = document.createElement('div');
                        item.className = 'p-2 border-bottom border-secondary small text-info';
                        item.textContent = d.msg || '细纲质量检查通过';
                        batchLog.appendChild(item);
                        batchLog.scrollTop = batchLog.scrollHeight;
                        break;
                    }

                    case 'batch_done':
                        totalChanged += (d.changed || 0);
                        statsEl.textContent = '已处理，修改 ' + totalChanged + ' 章';
                        batchLog.style.display = '';
                        const item = document.createElement('div');
                        item.className = 'p-2 border-bottom border-secondary small';
                        item.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>' + escHtml(d.msg || '') + '</span>';
                        batchLog.appendChild(item);
                        break;

                    case 'arc_saved':
                        // 弧段摘要保存，静默处理
                        break;

                    case 'model_switch':
                        progressLabel.textContent = d.msg || '切换模型重试...';
                        showToast('模型切换 → ' + d.next_model, 'info');
                        break;

                    case 'error':
                        showToast(d.msg || '优化失败', 'error');
                        progressLabel.textContent = '遇到错误：' + (d.msg || '');
                        break;

                    case 'complete':
                        cursor.remove();
                        progressLabel.textContent = d.msg || '优化完成！';
                        statsEl.textContent = '共修改 ' + (d.updated || totalChanged) + ' 章';
                        showToast('大纲逻辑优化完成，页面即将刷新', 'success');
                        setTimeout(() => location.reload(), 2000);
                        return;
                }
                currentEvent = '';
            }
        }

    } catch (err) {
        if (err && err.name === 'AbortError') {
            showToast('优化已取消', 'info');
            progressLabel.textContent = '优化已取消';
        } else {
            showToast('请求失败：' + err.message, 'error');
            progressLabel.textContent = '出错：' + err.message;
        }
    } finally {
        window.optimizeOutlineController = null;
        btn.disabled = false;
    }
}
