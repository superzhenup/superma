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
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
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
// 主题切换（亮/暗）
// ============================================================

(function initTheme() {
    const STORAGE_KEY = 'novel-theme';
    const html        = document.documentElement;

    function applyTheme(theme) {
        html.setAttribute('data-theme', theme);
        localStorage.setItem(STORAGE_KEY, theme);
        const label = document.getElementById('theme-label');
        if (label) label.textContent = theme === 'dark' ? '暗色' : '亮色';
    }

    // 页面加载后绑定按钮（DOMContentLoaded 时可能还没渲染）
    document.addEventListener('DOMContentLoaded', () => {
        const btn = document.getElementById('theme-toggle');
        if (!btn) return;
        // 初始化标签
        const cur = localStorage.getItem(STORAGE_KEY) || 'dark';
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

async function apiPost(url, data) {
    if (typeof url === 'string' && !url.includes('/') && !url.includes('.php')) {
        const res = await fetch(apiRouteUrl(url), {
            method: 'POST',
            headers: jsonHeaders(),
            body: JSON.stringify(data),
        });
        return res.json();
    }
    const res  = await fetch(url, {
        method:  'POST',
        headers: jsonHeaders(),
        body:    JSON.stringify(data),
    });
    return res.json();
}

async function apiGet(route, params) {
    if (typeof params === 'object') {
        params = Object.entries(params).map(([k, v]) => encodeURIComponent(k) + '=' + encodeURIComponent(v)).join('&');
    }
    const res = await fetch(apiRouteUrl(route, params || ''), {
        headers: jsonHeaders(),
    });
    return res.json();
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
        alert('删除失败：' + data.msg);
    }
};

// ============================================================
// Novel 页面逻辑
// ============================================================

document.addEventListener('DOMContentLoaded', () => {

    // ---- 模型切换 ----
    const modelSel = document.getElementById('model-select');
    if (modelSel) {
        modelSel.addEventListener('change', async () => {
            await apiPost('actions', {
                action:   'update_novel_model',
                novel_id: NOVEL_ID,
                model_id: modelSel.value || null,
            });
            showToast('模型已切换');
        });
    }

    // ---- 生成全书故事大纲按钮 ----
    const btnStory = document.getElementById('btn-story-outline');
    if (btnStory) {
        btnStory.addEventListener('click', () => generateStoryOutline());
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

let outlineController = null;
let outlineRunning    = false;

// 每次 SSE 调用最多生成的章节数（后端每批5章，此处30章=6批/次）
const OUTLINE_CHUNK    = 5;
// 断线后最多自动重连次数（增加到10次，给更多恢复机会）
const MAX_RECONNECTS   = 10;
// 断线后等待服务端完成当前批次的时间（ms），增加到30秒确保AI处理完成
const RECONNECT_DELAY  = 30000;

async function generateOutline() {
    const btnOutline = document.getElementById('btn-outline');
    const target     = parseInt(btnOutline.dataset.target);
    const novelId    = parseInt(btnOutline.dataset.novel);

    let outlined = parseInt(btnOutline.dataset.outlined);

    try {
        const r = await apiPost('actions', {
            action:   'get_outline_progress',
            novel_id: novelId,
        });
        if (r.ok && r.data && r.data.last_outlined > outlined) {
            outlined = r.data.last_outlined;
            btnOutline.dataset.outlined = String(outlined);
        }
    } catch {}

    let startCh = 1;
    let endCh   = target;

    if (outlined > 0) {
        const choice = confirm(
            `当前已有 ${outlined} 章大纲。\n` +
            `点击【确定】追加生成第 ${outlined + 1}～${target} 章大纲，\n` +
            `点击【取消】重新生成全部大纲。`
        );
        if (choice) { startCh = outlined + 1; }
    }
    if (startCh > endCh) { showToast('所有章节大纲已生成', 'info'); return; }

    // ---- UI 元素 ----
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
    btnOutline.disabled    = true;
    streamBox.textContent  = '';
    batchLog.style.display = 'none';
    batchLog.innerHTML     = '';
    tokenBar.style.display = 'none';
    spinner.style.display  = '';

    const cursor = document.createElement('span');
    cursor.className = 'outline-stream-cursor';
    streamBox.appendChild(cursor);

    outlineRunning = true;

    let currentStart = startCh;
    let reconnects   = 0;   // 断线重连计数
    let batchSavedEnd = 0;  // 后端 batch_done 上报的本段已落库最大章节号（权威进度信号）

    /**
     * 查询数据库中实际已保存的最大章节号
     * 用于断线后精确定位续接点
     */
    async function fetchLastOutlined() {
        try {
            const r = await apiPost('actions', {
                action:   'get_outline_progress',
                novel_id: novelId,
            });
            return (r.ok && r.data) ? (r.data.last_outlined || 0) : 0;
        } catch { return 0; }
    }

    /**
     * 读取并处理一段 SSE 流（currentStart ~ currentEnd）
     * 返回值：
     *   'complete'  — 服务端正常发出 complete 事件，本段全部完成
     *   'dropped'   — 连接中断（网络错误或流异常关闭）
     *   'aborted'   — 用户主动取消
     */
    async function runChunk(chStart, chEnd) {
        outlineController = new AbortController();

        let response;
        try {
            response = await fetch(apiRouteUrl('generate_outline'), {
                method:  'POST',
                headers: jsonHeaders(),
                body:    JSON.stringify({
                    novel_id:      novelId,
                    start_chapter: chStart,
                    end_chapter:   chEnd,
                }),
                signal: outlineController.signal,
            });

            // 检查 HTTP 状态码
            if (!response.ok) {
                const errText = await response.text().catch(() => '');
                console.error('API 错误:', response.status, errText);
                showToast(`服务器错误: ${response.status}`, 'error');
                return 'aborted';
            }
        } catch (fetchErr) {
            if (fetchErr.name === 'AbortError') return 'aborted';
            return 'dropped';   // 网络错误：连接建立失败
        }

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
                readResult = await reader.read();
            } catch {
                return 'dropped';   // 读取中途连接断开
            }

            const { value, done } = readResult;
            if (done) return 'dropped';   // 流意外关闭（无 complete 事件）

            buf += decoder.decode(value, { stream: true });
            const lines = buf.split('\n');
            buf = lines.pop();

            for (const line of lines) {
                const trimmed = line.trim();

                // SSE 注释行（keepalive / ping），忽略
                if (trimmed.startsWith(':')) { currentEvent = ''; continue; }

                if (trimmed.startsWith('event: ')) {
                    currentEvent = trimmed.slice(7).trim();
                    continue;
                }

                if (!trimmed.startsWith('data: ')) continue;
                const raw = trimmed.slice(6);
                if (raw === '[DONE]') break readerLoop;

                let d;
                try { d = JSON.parse(raw); } catch { currentEvent = ''; continue; }

                switch (currentEvent) {

                    case 'ping':
                        // 服务端调用 AI API 前的心跳，更新状态文字
                        label.textContent = d.msg || label.textContent;
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
                        // 心跳事件，仅保持连接活跃
                        break;

                    case 'init':
                        // SSE 连接确认
                        break;

                    case 'fatal_error': {
                        batchLog.style.display = '';
                        const fei = document.createElement('div');
                        fei.className = 'outline-batch-item error';
                        fei.textContent = `服务端错误: ${d.message || d.msg || '未知错误'}`;
                        batchLog.appendChild(fei);
                        showToast(`服务端错误: ${d.message || d.msg || '未知错误'}`, 'error');
                        spinner.style.display = 'none';
                        label.textContent = '生成失败';
                        return 'aborted';
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
                        label.textContent = d.msg;
                        return 'complete';   // ✅ 正常完成
                }

                currentEvent = '';
            }
        }

        // [DONE] 收到但没有 complete 事件 —— 视为正常完成
        return 'complete';
    }

    // ================================================================
    // 主循环：分段调用 runChunk，失败时自动恢复
    // ================================================================
    try {
        while (currentStart <= endCh && outlineRunning) {
            const currentEnd = Math.min(endCh, currentStart + OUTLINE_CHUNK - 1);

            batchSavedEnd = 0;                          // 重置本段落库进度
            const result = await runChunk(currentStart, currentEnd);

            if (result === 'aborted') break;

            // 权威进度推进：只要后端发过 batch_done（章节已落库），无论 [DONE] 是否抵达、
            // 连接是否在弧段摘要阶段静默掉线、二次进度查询是否成功，都按已保存的
            // actual_end 前进。这从根本上消除"章节已保存却仍反复重生成同一批"的死循环，
            // 同时也修正了截断批次（实存 < 请求）会被跳过的问题。
            if (batchSavedEnd >= currentStart) {
                reconnects = 0;
                const prevStart = currentStart;
                currentStart = batchSavedEnd + 1;

                if (currentStart <= endCh && outlineRunning) {
                    label.textContent = `第 ${prevStart}～${batchSavedEnd} 章已完成，稍后继续...`;
                    await new Promise(r => setTimeout(r, 800));
                    streamBox.textContent = '';
                    streamBox.appendChild(cursor);
                }
                continue;
            }

            if (result === 'complete') {
                // 收到 [DONE] 但本段未落库（如解析失败已上报 error）——跳过该段避免卡死（保持原行为）
                reconnects   = 0;
                currentStart = currentEnd + 1;

                if (currentStart <= endCh && outlineRunning) {
                    label.textContent = `第 ${currentStart - OUTLINE_CHUNK}～${currentEnd} 章已完成，稍后继续...`;
                    await new Promise(r => setTimeout(r, 800));
                    streamBox.textContent = '';
                    streamBox.appendChild(cursor);
                }

            } else {
                // ⚡ 连接断开（result === 'dropped'）
                reconnects++;

                // 每次重试失败都刷新页面上的重试次数显示
                label.textContent = `连接中断（第 ${reconnects}/${MAX_RECONNECTS} 次），正在恢复...`;
                showToast(`连接中断，正在恢复（第 ${reconnects}/${MAX_RECONNECTS} 次）...`, 'info');

                // 多次断开后逐渐增加等待时间，避免一直频繁重试
                const adjustedDelay = RECONNECT_DELAY * (1 + Math.min(reconnects, 3) * 0.5);

                // 等待服务端完成当前批次（ignore_user_abort 保证数据会被保存）
                await new Promise(r => setTimeout(r, adjustedDelay));

                // 查询 DB 获取真实进度，从断点续接
                const lastSaved = await fetchLastOutlined();

                // 超过最大重试次数时，给用户手动继续的选项
                if (reconnects >= MAX_RECONNECTS) {
                    // 最后一次尝试：查询实际进度，如果有任何进展就继续
                    if (lastSaved >= currentStart) {
                        showToast('检测到之前已有进度，继续生成...', 'info');
                        if (lastSaved >= currentEnd) {
                            // 本段已完成，直接推进
                            currentStart = currentEnd + 1;
                        } else {
                            // 部分完成，从上次保存处+1继续
                            currentStart = lastSaved + 1;
                        }
                        reconnects = 0; // 重置计数器
                    } else {
                        // 确实没有任何进展，给用户继续的机会
                        label.textContent = `连接多次中断（已重试 ${MAX_RECONNECTS} 次），请手动点击继续生成。`;
                        showToast('连接多次中断，但您可以随时点击按钮继续，从上次中断处恢复', 'info');

                        // 尝试查询当前进度，并给用户继续的选项
                        const actualProgress = await fetchLastOutlined();
                        if (actualProgress >= startCh) {
                            // 数据库中已有进度，从最新进度继续
                            currentStart = actualProgress + 1;
                            reconnects = 0;
                            showToast(`从数据库中的第 ${currentStart} 章继续生成...`, 'info');
                        } else {
                            // 真的没有进度，重置并再试一次（可能是网络问题）
                            reconnects = 0;
                        }
                    }
                } else if (lastSaved >= currentEnd) {
                    // 整段已完成，直接推进
                    currentStart = currentEnd + 1;
                    reconnects   = 0;
                    showToast('恢复成功，继续生成...', 'info');
                } else if (lastSaved >= currentStart) {
                    // 部分完成，从上次保存处+1继续
                    currentStart = lastSaved + 1;
                    reconnects   = 0;
                    showToast(`从第 ${currentStart} 章继续...`, 'info');
                } else {
                    // 没有进度（可能连接建立就失败了），从相同位置重试
                    showToast(`重试第 ${currentStart}～${currentEnd} 章...`, 'info');
                }

                streamBox.textContent = '';
                streamBox.appendChild(cursor);
            }
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
        outlineRunning      = false;
        btnOutline.disabled = false;
    }
}

// ============================================================
// 编辑故事大纲
// ============================================================

async function editStoryOutline() {
    const novelId = parseInt(document.getElementById('btn-edit-story-outline').dataset.novel);
    const modal = new bootstrap.Modal(document.getElementById('storyOutlineModal'));

    document.getElementById('edit-story-arc').value = '';
    document.getElementById('edit-character-arcs').value = '';
    document.getElementById('edit-world-evolution').value = '';

    try {
        const res = await fetch(apiRouteUrl('get_story_outline', 'novel_id=' + encodeURIComponent(novelId)), {
            method: 'GET',
            headers: jsonHeaders()
        });
        const text = await res.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (parseErr) {
            console.error('故事大纲响应非JSON:', text.substring(0, 200));
            showToast('加载故事大纲失败：服务器返回了非预期内容', 'error');
            modal.show();
            return;
        }
        if (data.success && data.data) {
            document.getElementById('edit-story-arc').value = data.data.story_arc || '';
            document.getElementById('edit-character-arcs').value = data.data.character_arcs || '';
            document.getElementById('edit-world-evolution').value = data.data.world_evolution || '';
        } else if (!data.success && data.message && data.message !== '暂无故事大纲') {
            showToast(data.message, 'error');
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
    const worldEvolution = document.getElementById('edit-world-evolution').value.trim();

    if (!storyArc) {
        showToast('请填写故事主线', 'error');
        return;
    }

    const btn = document.getElementById('btn-save-story-outline');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>保存中...';

    try {
        const res = await apiPost('api/update_story_outline.php', {
            novel_id: novelId,
            story_arc: storyArc,
            character_arcs: characterArcs,
            world_evolution: worldEvolution
        });

        if (res.success) {
            showToast('故事大纲已保存', 'success');
            bootstrap.Modal.getInstance(document.getElementById('storyOutlineModal')).hide();
            // 刷新页面显示更新后的大纲
            location.reload();
        } else {
            showToast(res.error || '保存失败', 'error');
        }
    } catch (e) {
        showToast('保存失败: ' + e.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>保存';
    }
}

async function regenerateStoryOutline() {
    if (!confirm('确定要重新生成故事大纲吗？这将覆盖现有的大纲内容。')) {
        return;
    }

    // 直接调用生成故事大纲的函数
    await generateStoryOutline();
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
        const res = await apiPost(`api/get_chapter_synopsis.php?novel_id=${novelId}&chapter_number=${chapterNumber}`, {});
        if (res.success && res.data) {
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
        const res = await apiPost('api/update_chapter_synopsis.php', {
            novel_id: novelId,
            chapter_number: chapterNumber,
            synopsis: synopsis,
            pacing: pacing,
            cliffhanger: cliffhanger
        });

        if (res.success) {
            showToast('章节概要已保存', 'success');
            bootstrap.Modal.getInstance(document.getElementById('chapterSynopsisModal')).hide();
            // 刷新页面显示更新后的概要
            location.reload();
        } else {
            showToast(res.error || '保存失败', 'error');
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
        const res = await apiPost('api/get_chapter_synopsis.php', {
            novel_id: novelId,
            chapter_number: chapterNumber
        });
        
        if (res.success && res.data) {
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
        const res = await apiPost('api/optimize_chapter_synopsis.php', {
            novel_id: novelId,
            chapter_number: chapterNumber,
            suggestions: suggestions
        });
        
        if (res.success && res.data) {
            optimizedSynopsis = res.data;
            
            // 显示优化结果
            document.getElementById('optimize-result-synopsis').textContent = res.data.synopsis || '生成失败';
            document.getElementById('optimize-result-section').style.display = 'block';
            document.getElementById('btn-confirm-optimize').style.display = 'inline-block';
            document.getElementById('btn-generate-optimize').style.display = 'none';
            
            showToast('优化完成，请确认是否采用', 'success');
        } else {
            showToast(res.error || '优化失败', 'error');
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
        const res = await apiPost('api/update_chapter_synopsis.php', {
            novel_id: novelId,
            chapter_number: chapterNumber,
            synopsis: optimizedSynopsis.synopsis,
            pacing: optimizedSynopsis.pacing || '',
            cliffhanger: optimizedSynopsis.cliffhanger || ''
        });
        
        if (res.success) {
            showToast('优化后的章节概要已保存', 'success');
            bootstrap.Modal.getInstance(document.getElementById('optimizeSynopsisModal')).hide();
            // 刷新页面显示更新后的概要
            location.reload();
        } else {
            showToast(res.error || '保存失败', 'error');
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
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ============================================================
// 自动写作
// ============================================================

// 用 generation token 解决 "暂停后立即重启" 导致两个循环并行的问题
let autoWriteRunning = false;
let autoWriteStop    = false;
let autoWriteGen     = 0;   // 每次启动递增，用于废弃旧循环
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

    const ui = _aw();
    setAutoWriteUI(true);
    ui.wrap.style.display = '';
    ui.stream.textContent = '';
    if (ui.cursor) ui.stream.appendChild(ui.cursor);
    ui.spinner.style.display = '';
    ui.label.textContent = '正在启动自动写作...';

    try {
        await apiPost('actions', {
            action: 'update_novel_status', novel_id: NOVEL_ID, status: 'writing'
        });
    } catch (e) {
        showToast('启动写作失败：' + e.message, 'error');
        autoWriteRunning = false;
        setAutoWriteUI(false);
        ui.spinner.style.display = 'none';
        return;
    }

    let finalStatus = 'paused';

    while (!autoWriteStop && myGen === autoWriteGen) {
        // 查询下一章
        let res;
        try {
            res = await apiPost('actions', {
                action: 'get_novel_status', novel_id: NOVEL_ID
            });
        } catch (e) {
            ui.label.textContent = '查询状态失败，正在重试...';
            await new Promise(r => setTimeout(r, 3000));
            continue;
        }

        if (!res.ok) {
            ui.label.textContent = '查询状态失败：' + (res.msg || '未知错误');
            await new Promise(r => setTimeout(r, 3000));
            continue;
        }

        if (!res.data.next_chapter) {
            ui.label.textContent = '所有章节写作完成！';
            ui.spinner.style.display = 'none';
            finalStatus = 'completed';
            showToast('全部章节已生成完毕！', 'success');
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
        } else {
            // 断点续写：恢复已生成的内容
            if (lastWrittenText && ui.stream.textContent.trim() === '') {
                ui.stream.textContent = lastWrittenText;
                if (ui.cursor) ui.stream.appendChild(ui.cursor);
            }
        }

        try {
            await streamWriteChapter(
                NOVEL_ID,
                null,
                // onComplete 回调
                async (statsText, chapId, modelUsed) => {
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
                ui.cursor
            );
        } catch (err) {
            // 保存已生成的内容用于断点续写
            lastWrittenText = ui.stream.textContent || lastWrittenText;
            showToast('写作出错：' + err.message, 'error');
            ui.label.textContent = '写作出错：' + err.message;
            // 出错后等待一段时间再重试（可能是临时网络问题）
            if (!autoWriteStop && myGen === autoWriteGen) {
                ui.label.textContent = `5秒后自动重试（已保留${lastWrittenText.length}字）...`;
                await new Promise(r => setTimeout(r, 5000));
                continue;
            }
            break;
        }

        // 每写完一章立即局部刷新章节列表。原先只在整轮循环结束（停止/写完全书）后
        // 刷新一次（见循环外 refreshChapterList），导致连续写作时新完成的章节迟迟不出现。
        await refreshChapterList();

        if (autoWriteStop || myGen !== autoWriteGen) break;
        await new Promise(r => setTimeout(r, 1500));
    }

    // 只有本代循环才做收尾
    if (myGen !== autoWriteGen) return;

    autoWriteRunning = false;
    setAutoWriteUI(false);
    ui.spinner.style.display = '';

    await apiPost('actions', {
        action: 'update_novel_status', novel_id: NOVEL_ID, status: finalStatus
    });

    // 刷新章节列表（不销毁进度面板）
    await refreshChapterList();
}

window.stopAutoWrite = function() {
    autoWriteStop = true;
    // 立即解除 running 状态，让用户能马上重新启动
    autoWriteRunning = false;
    setAutoWriteUI(false);
    showToast('写作已暂停');
    const { spinner } = _aw();
    if (spinner) spinner.style.display = 'none';
};

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

    container.querySelectorAll('.btn-write-single').forEach(btn => {
        if (btn.dataset.ajaxBound === '1') return;
        btn.dataset.ajaxBound = '1';
        btn.addEventListener('click', () => {
            writeSingleChapter(NOVEL_ID, parseInt(btn.dataset.chapter, 10));
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

async function loadChapterList(page = getCurrentChapterPage(), pushState = true) {
    const tab = document.getElementById('tab-chapters');
    if (!tab || typeof NOVEL_ID === 'undefined') return false;

    const safePage = Math.max(1, parseInt(page, 10) || 1);
    const root = document.getElementById('chapter-list-ajax-root');
    if (root) root.style.opacity = '.55';

    try {
        const url = new URL('api/chapter_list.php', location.href);
        url.searchParams.set('novel_id', NOVEL_ID);
        url.searchParams.set('page', safePage);

        const res = await fetch(url.toString(), { cache: 'no-store' });
        const data = await res.json();
        if (!data.ok) {
            throw new Error(data.msg || data.error || '章节列表加载失败');
        }

        tab.innerHTML = data.html || '';
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
// 核心：流式写章节（优先异步任务模式，回退 SSE 直连）
// ============================================================

/**
 * @param novelId     小说ID
 * @param chapterId   章节ID（null = 自动选下一章）
 * @param onComplete  完成回调 (statsText, chapterId, modelUsed)
 * @param displayEl   实时显示容器（可选）
 * @param cursorEl    光标元素（可选，跟随文字末尾）
 */
async function streamWriteChapter(novelId, chapterId, onComplete, displayEl, cursorEl) {
    try {
        return await _streamWriteAsync(novelId, chapterId, onComplete, displayEl, cursorEl);
    } catch (asyncErr) {
        if (asyncErr._fallbackSse) {
            console.info('[streamWriteChapter] 异步模式不可用，回退到 SSE 直连模式');
            return await _streamWriteSse(novelId, chapterId, onComplete, displayEl, cursorEl);
        }
        throw asyncErr;
    }
}

async function _streamWriteAsync(novelId, chapterId, onComplete, displayEl, cursorEl) {
    let startRes;
    try {
        startRes = await apiPost('write_start', {
            novel_id: novelId, chapter_id: chapterId
        });
        if (!startRes.ok) {
            const err = new Error(startRes.error || startRes.msg || '启动失败');
            err._fallbackSse = true;
            throw err;
        }
    } catch (e) {
        if (!e._fallbackSse) {
            const err = new Error('异步启动失败：' + e.message);
            err._fallbackSse = true;
            throw err;
        }
        throw e;
    }

    if (!startRes.ok) {
        const err = new Error(startRes.error || startRes.msg || '启动写作任务失败');
        if (startRes.fallback_sse) {
            err._fallbackSse = true;
        }
        throw err;
    }

    const taskId = startRes.task_id;
    const POLL_INTERVAL_FAST = 300;
    const POLL_INTERVAL_SLOW = 1500;
    let pollInterval = POLL_INTERVAL_FAST;
    let lastContentLen = 0;
    let lastPollTime = Date.now();

    while (true) {
        let pollRes;
        try {
            pollRes = await apiPost('api/write_poll.php', { task_id: taskId });
        } catch (e) {
            await new Promise(r => setTimeout(r, 3000));
            continue;
        }

        if (!pollRes.ok) {
            throw new Error(pollRes.msg || '轮询进度失败');
        }

        const { status, content, messages, words, model_used, chapter_id, error, progress } = pollRes;

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

async function _streamWriteSse(novelId, chapterId, onComplete, displayEl, cursorEl) {
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
                        const m       = html.match(/<meta\s+name=["']csrf-token["']\s+content=["']([a-f0-9]+)["']/i);
                        if (m && m[1]) {
                            const metaEl = document.querySelector('meta[name="csrf-token"]');
                            if (metaEl) {
                                metaEl.setAttribute('content', m[1]);
                                console.info('[CSRF] token 已刷新，将在下一次请求携带新值');
                                errMsg = 'CSRF 已自动刷新，正在重试';
                            }
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
                if (d.error) throw new Error(d.error);

            } catch(e) {
                if (e.message !== 'Unexpected token') throw e;
            }

            currentEvent = '';
        }
    }
    } catch (readErr) {
        clearAllTimers();
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
    
    try {
        const data = await apiPost('cancel_write', {
            action: 'cancel',
            novel_id: NOVEL_ID
        });
        
        if (data.ok) {
            showToast('已取消写作', 'success');
            // 停止自动写作
            if (typeof stopAutoWrite === 'function') {
                stopAutoWrite();
            }
            // 刷新页面
            setTimeout(() => location.reload(), 1000);
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
    
    try {
        const data = await apiPost('cancel_write', {
            action: 'reset',
            novel_id: NOVEL_ID
        });
        
        if (data.ok) {
            showToast('已重置未完成章节', 'success');
            setTimeout(() => location.reload(), 1000);
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
                html += `<li class="list-group-item list-group-item-danger">
                    <strong>第${issue.chapter}章 · ${issue.type}</strong>
                    <p class="mb-0 mt-1">${issue.message}</p>
                </li>`;
            });
            html += '</ul>';
        }
        
        if (warnings.length > 0) {
            html += '<h6 class="text-warning">⚡️ 建议关注 (' + warnings.length + ')</h6>';
            html += '<ul class="list-group">';
            warnings.forEach(warn => {
                html += `<li class="list-group-item list-group-item-warning">
                    <strong>第${warn.chapter}章 · ${warn.type}</strong>
                    <p class="mb-0 mt-1">${warn.message}</p>
                </li>`;
            });
            html += '</ul>';
        }
        
        html += '</div>';
        
        // 显示模态框
        const modal = document.createElement('div');
        modal.className = 'modal fade';
        modal.innerHTML = `
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
        `;
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

window.resetSingleChapter = async function(chapterId) {
    if (!confirm('确定要重置这个章节吗？\n\n章节内容将被清空，恢复到"已大纲"状态。')) {
        return;
    }

    try {
        const data = await apiPost('cancel_write', {
            action: 'reset_chapter',
            novel_id: NOVEL_ID,
            chapter_id: chapterId
        });

        if (data.ok) {
            showToast('已重置章节', 'success');
            // 刷新章节列表
            if (typeof refreshChapterList === 'function') {
                await refreshChapterList();
            }
        } else {
            showToast('重置失败：' + (data.msg || '未知错误'), 'error');
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
    }
}

// ============================================================
// [v2新增] 生成全书故事大纲
// ============================================================

async function generateStoryOutline() {
    const btn = document.getElementById('btn-story-outline');
    if (!btn) return;
    const novelId = parseInt(btn.dataset.novel);

    if (!confirm('确定要生成全书故事大纲吗？\n\n这将建立全局故事框架，帮助后续章节生成更加连贯。\n生成后可以在"小说设定"标签页查看。')) {
        return;
    }

    // 防止重复点击
    if (btn.disabled) return;

    // UI 元素
    const wrap      = document.getElementById('story-outline-progress-wrap');
    const label     = document.getElementById('story-outline-progress-label');
    const streamBox = document.getElementById('story-outline-stream-box');

    wrap.style.display    = '';
    btn.disabled          = true;
    streamBox.textContent = '';

    const cursor = document.createElement('span');
    cursor.className = 'outline-stream-cursor';
    streamBox.appendChild(cursor);

    const MAX_RETRIES = 3;  // 最大重试次数

    for (let attempt = 0; attempt < MAX_RETRIES; attempt++) {
        try {
            const controller = new AbortController();

            const response = await fetch(apiRouteUrl('generate_story_outline'), {
                method:  'POST',
                headers: jsonHeaders(),
                body:    JSON.stringify({ novel_id: novelId }),
                signal:  controller.signal,
            });

            if (!response.ok) {
                showToast('服务器错误: ' + response.status, 'error');
                return;
            }

            const reader  = response.body.getReader();
            const decoder = new TextDecoder();
            let buf = '', currentEvent = '';

            while (true) {
                let readResult;
                try {
                    readResult = await reader.read();
                } catch (readErr) {
                    // 连接中途断开
                    if (attempt < MAX_RETRIES - 1) {
                        label.textContent = `连接中断，正在重新连接（第 ${attempt + 1}/${MAX_RETRIES - 1} 次）...`;
                        showToast(`连接中断，正在恢复（第 ${attempt + 1}/${MAX_RETRIES - 1} 次）...`, 'info');
                        // 指数退避等待
                        await new Promise(r => setTimeout(r, 2000 * Math.pow(1.5, attempt)));
                        streamBox.textContent = '';
                        streamBox.appendChild(cursor);
                        break;  // 跳出内层while，继续外层for重试
                    }
                    throw readErr;
                }

                const { value, done } = readResult;
                if (done) {
                    // 流意外关闭但没有complete事件
                    if (attempt < MAX_RETRIES - 1) {
                        label.textContent = `流中断，正在重新连接...`;
                        showToast('生成过程意外中断，正在尝试恢复...', 'warning');
                        await new Promise(r => setTimeout(r, 2000 * Math.pow(1.5, attempt)));
                        streamBox.textContent = '';
                        streamBox.appendChild(cursor);
                        break;
                    }
                    throw new Error('数据流意外结束，请检查网络后重试');
                }

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
                        case 'progress':
                            label.textContent = d.msg || '生成中...';
                            break;

                        case 'thinking':
                            // 思考过程，显示在streamBox中
                            streamBox.textContent = '[AI思考中...]';
                            streamBox.appendChild(cursor);
                            break;

                        case 'chunk':
                            if (d.t) {
                                // 确保 cursor 在 streamBox 中
                                if (!streamBox.contains(cursor)) {
                                    streamBox.appendChild(cursor);
                                }
                                streamBox.insertBefore(document.createTextNode(d.t), cursor);
                                streamBox.scrollTop = streamBox.scrollHeight;
                            }
                            break;

                        case 'model_switch':
                            streamBox.textContent = '';
                            streamBox.appendChild(cursor);
                            const hint = document.createTextNode(`[切换到「${d.next_model}」重试...]\n`);
                            if (!streamBox.contains(cursor)) {
                                streamBox.appendChild(cursor);
                            }
                            streamBox.insertBefore(hint, cursor);
                            showToast(`模型切换 → ${d.next_model}`, 'info');
                            break;

                        case 'error':
                            showToast(d.msg, 'error');
                            label.textContent = '生成失败';
                            setTimeout(() => {
                                wrap.style.display = 'none';
                                btn.disabled = false;
                            }, 2000);
                            return;

                        case 'complete':
                            cursor.remove();
                            label.textContent = d.msg;
                            showToast('全书故事大纲生成完成！', 'success');
                            setTimeout(() => location.reload(), 1500);
                            return;
                    }
                    currentEvent = '';
                }
            }

        } catch (err) {
            // 最后一次尝试也失败了
            if (attempt >= MAX_RETRIES - 1) {
                showToast('生成出错：' + err.message, 'error');
                label.textContent = '出错：' + err.message;
            }
            // 否则继续下一次重试
        }
    }

    // 所有重试都失败后的最终清理
    btn.disabled = false;
}

// ============================================================
// [v2新增] 生成章节概要
// ============================================================

window.generateStoryOutline = generateStoryOutline;
window.regenerateStoryOutline = regenerateStoryOutline;

async function generateChapterSynopsis() {
    const btn = document.getElementById('btn-synopsis');
    if (!btn) return;
    const novelId = parseInt(btn.dataset.novel);
    const outlined = parseInt(btn.dataset.outlined);

    if (!confirm(`确定要为所有已大纲的章节生成概要吗？\n\n这将生成详细的章节写作蓝图，帮助提高小说质量。\n共 ${outlined} 章需要生成概要。`)) {
        return;
    }

    // 防止重复点击
    if (btn.disabled) return;

    // UI 元素
    const wrap      = document.getElementById('synopsis-progress-wrap');
    const label     = document.getElementById('synopsis-progress-label');
    const streamBox = document.getElementById('synopsis-stream-box');

    wrap.style.display    = '';
    btn.disabled          = true;
    streamBox.textContent = '';

    const cursor = document.createElement('span');
    cursor.className = 'outline-stream-cursor';
    streamBox.appendChild(cursor);

    const MAX_RETRIES = 3;

    for (let attempt = 0; attempt < MAX_RETRIES; attempt++) {
        try {
            const controller = new AbortController();

            const response = await fetch(apiRouteUrl('generate_chapter_synopsis'), {
                method:  'POST',
                headers: jsonHeaders(),
                body:    JSON.stringify({ novel_id: novelId }),
                signal:  controller.signal,
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
                let readResult;
                try {
                    readResult = await reader.read();
                } catch (readErr) {
                    // 连接中途断开
                    if (attempt < MAX_RETRIES - 1) {
                        label.textContent = `连接中断，正在重新连接（第 ${attempt + 1}/${MAX_RETRIES - 1} 次）...`;
                        showToast(`连接中断，正在恢复（第 ${attempt + 1}/${MAX_RETRIES - 1} 次）...`, 'info');
                        await new Promise(r => setTimeout(r, 2000 * Math.pow(1.5, attempt)));
                        streamBox.textContent = '';
                        streamBox.appendChild(cursor);
                        break;
                    }
                    throw readErr;
                }

                const { value, done } = readResult;
                if (done) {
                    if (attempt < MAX_RETRIES - 1) {
                        label.textContent = `流中断，正在重新连接...`;
                        showToast('概要生成过程意外中断，正在尝试恢复...', 'warning');
                        await new Promise(r => setTimeout(r, 2000 * Math.pow(1.5, attempt)));
                        streamBox.textContent = '';
                        streamBox.appendChild(cursor);
                        break;
                    }
                    throw new Error('数据流意外结束，请检查网络后重试');
                }

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
                        case 'progress':
                            label.textContent = d.msg || '生成中...';
                            break;

                        case 'chunk':
                            if (d.t) {
                                if (!streamBox.contains(cursor)) {
                                    streamBox.appendChild(cursor);
                                }
                                streamBox.insertBefore(document.createTextNode(d.t), cursor);
                                streamBox.scrollTop = streamBox.scrollHeight;
                            }
                            break;

                        case 'model_switch':
                            streamBox.textContent = '';
                            streamBox.appendChild(cursor);
                            const hint = document.createTextNode(`[切换到「${d.next_model}」重试...]\n`);
                            if (!streamBox.contains(cursor)) {
                                streamBox.appendChild(cursor);
                            }
                            streamBox.insertBefore(hint, cursor);
                            showToast(`模型切换 → ${d.next_model}`, 'info');
                            break;

                        case 'chapter_done': {
                            const divider = document.createTextNode(`\n✓ ${d.msg}\n\n`);
                            if (!streamBox.contains(cursor)) {
                                streamBox.appendChild(cursor);
                            }
                            streamBox.insertBefore(divider, cursor);
                            break;
                        }

                        case 'error':
                            showToast(d.msg, 'error');
                            break;

                        case 'complete':
                            cursor.remove();
                            label.textContent = d.msg;
                            showToast('章节概要生成完成！', 'success');
                            setTimeout(() => location.reload(), 1500);
                            return;

                        case 'fatal_error':
                            showToast(d.msg || '概要生成过程发生内部错误', 'error');
                            label.textContent = '生成失败';
                            btn.disabled = false;
                            return;
                    }
                    currentEvent = '';
                }
            }

        } catch (err) {
            if (attempt >= MAX_RETRIES - 1) {
                showToast('生成出错：' + err.message, 'error');
                label.textContent = '出错：' + err.message;
            }
        }
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
        const res = await apiPost('api/chapter_actions.php', {
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
        const res = await apiPost('api/chapter_actions.php', {
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
        const res = await apiPost('api/chapter_actions.php', {
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
        const res = await apiPost('api/chapter_actions.php', {
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
                    } catch {}
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
        '· 根据全书故事大纲检查所有章节大纲的逻辑性\n' +
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
        const response = await fetch(apiRouteUrl('optimize_outline'), {
            method:  'POST',
            headers: jsonHeaders(),
            body:    JSON.stringify({ novel_id: NOVEL_ID }),
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
                        item.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>' + (d.msg || '') + '</span>';
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
        showToast('请求失败：' + err.message, 'error');
        progressLabel.textContent = '出错：' + err.message;
    } finally {
        btn.disabled = false;
    }
}
