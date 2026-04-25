/**
 * AI小说创作系统 - 前端脚本
 */

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
    const res  = await fetch(url, {
        method:  'POST',
        headers: {'Content-Type': 'application/json'},
        body:    JSON.stringify(data),
    });
    // 检查 HTTP 状态码
    if (!res.ok) {
        let errMsg = `请求失败 (${res.status})`;
        try {
            const errText = await res.text();
            if (errText) {
                const errJson = JSON.parse(errText);
                errMsg = errJson.msg || errJson.error || errMsg;
            }
        } catch {}
        throw new Error(errMsg);
    }
    // 尝试解析 JSON，增加错误信息便于调试
    const text = await res.text();
    if (!text || !text.trim()) {
        throw new Error('服务器返回了空响应');
    }
    try {
        return JSON.parse(text);
    } catch (e) {
        // 保留原始解析错误信息，便于定位问题
        const preview = text.length > 200 ? text.substring(0, 200) + '...' : text;
        throw new Error(`JSON 解析失败：${e.message}，响应内容：${preview}`);
    }
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
    const data = await apiPost('api/actions.php', {
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
            await apiPost('api/actions.php', {
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

    // ---- 单章写作按钮 ----
    document.querySelectorAll('.btn-write-single').forEach(btn => {
        btn.addEventListener('click', () => {
            const chapterId = parseInt(btn.dataset.chapter);
            writeSingleChapter(NOVEL_ID, chapterId);
        });
    });

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
    if (btnRegenerateStoryOutline) {
        btnRegenerateStoryOutline.addEventListener('click', () => regenerateStoryOutline());
    }

    // ---- 优化大纲逻辑按钮 ----
    const btnOptimizeOutline = document.getElementById('btn-optimize-outline');
    if (btnOptimizeOutline) {
        btnOptimizeOutline.addEventListener('click', () => optimizeOutline());
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

// 每次 SSE 调用生成的章节数（与后端批次大小一致，短连接不超时）
const OUTLINE_CHUNK    = 5;
// 断线后最多自动重连次数
const MAX_RECONNECTS   = 5;
// 断线后等待服务端完成当前批次的时间（ms）
const RECONNECT_DELAY  = 5000;

async function generateOutline() {
    // 防止重复调用
    if (outlineRunning) {
        showToast('大纲生成正在进行中，请稍候...', 'warning');
        return;
    }

    const btnOutline = document.getElementById('btn-outline');
    const outlined   = parseInt(btnOutline.dataset.outlined);
    const target     = parseInt(btnOutline.dataset.target);
    const novelId    = parseInt(btnOutline.dataset.novel);

    let startCh = 1;
    let endCh   = target;

    // 如果所有章节已生成，直接返回
    if (outlined >= target) {
        showToast('所有章节大纲已生成完成', 'success');
        return;
    }

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

    /**
     * 查询数据库中实际已保存的最大章节号
     * 用于断线后精确定位续接点
     */
    async function fetchLastOutlined() {
        try {
            const r = await apiPost('api/actions.php', {
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
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            response = await fetch('api/generate_outline.php', {
                method:  'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
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

        // 心跳检测
        let lastHeartbeat = Date.now();
        const HEARTBEAT_TIMEOUT = 120000; // 120秒无心跳视为断开（思考模型可能长时间无输出）

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
            // 在读取数据前检查心跳超时
            if (Date.now() - lastHeartbeat > HEARTBEAT_TIMEOUT) {
                reader.cancel();
                showToast('连接超时（120秒无响应），请检查网络或重试', 'error');
                return 'dropped';
            }

            let readResult;
            try {
                readResult = await reader.read();
            } catch {
                return 'dropped';   // 读取中途连接断开
            }

            const { value, done } = readResult;

            // 流关闭时，先处理 buf 中残留的数据再判断
            if (done) {
                let gotComplete = false;
                if (buf.trim()) {
                    const residualLines = buf.split('\n');
                    let evName = '', evData = '';
                    for (const rl of residualLines) {
                        const rt = rl.trim();
                        if (rt.startsWith('event: ')) { evName = rt.slice(7).trim(); continue; }
                        if (rt.startsWith('data: ')) { evData = rt; }
                    }
                    if (evData) {
                        const raw = evData.slice(6).trim();
                        if (raw === '[DONE]') { gotComplete = true; }
                        else {
                            try {
                                const d = JSON.parse(raw);
                                if (evName === 'complete') gotComplete = true;
                            } catch {}
                        }
                    }
                }
                return gotComplete ? 'complete' : 'dropped';
            }

            // 收到数据，更新心跳时间
            lastHeartbeat = Date.now();

            buf += decoder.decode(value, { stream: true });
            const events = buf.split('\n\n');
            buf = events.pop();

            for (const eventBlock of events) {
                const lines = eventBlock.split('\n');
                let currentEvent = '';
                let dataLine = '';

                for (const line of lines) {
                    const trimmed = line.trim();
                    if (trimmed.startsWith(':')) continue;
                    if (trimmed.startsWith('event: ')) {
                        currentEvent = trimmed.slice(7).trim();
                        continue;
                    }
                    if (trimmed.startsWith('data: ')) {
                        dataLine = trimmed;
                    }
                }

                // 处理心跳事件
                if (currentEvent === 'heartbeat') {
                    lastHeartbeat = Date.now();
                    continue;
                }

                if (!dataLine) continue;
                const raw = dataLine.slice(6).trim();
                if (raw === '[DONE]') break readerLoop;

                let d;
                try { d = JSON.parse(raw); } catch { continue; }

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

                    case 'fatal_error': {
                        batchLog.style.display = '';
                        const fi = document.createElement('div');
                        fi.className = 'outline-batch-item error';
                        fi.textContent = `严重错误：${d.message || d.msg || '未知'}（${d.file || ''}:${d.line || ''}）`;
                        batchLog.appendChild(fi);
                        showToast(`生成出错：${d.message || d.msg || '未知错误'}`, 'error');
                        return 'complete';   // 严重错误视为本批完成（不再重试），让主循环推进
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

            const result = await runChunk(currentStart, currentEnd);

            if (result === 'aborted') break;

            if (result === 'complete') {
                // ✅ 本段正常完成
                reconnects   = 0;
                currentStart = currentEnd + 1;

                if (currentStart <= endCh && outlineRunning) {
                    label.textContent = `第 ${currentStart}～${currentEnd} 章已完成，稍后继续...`;
                    await new Promise(r => setTimeout(r, 500));
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

    // 加载现有数据
    try {
        const res = await apiPost('api/get_story_outline.php?novel_id=' + novelId, {});
        if (res.success && res.data) {
            document.getElementById('edit-story-arc').value = res.data.story_arc || '';
            document.getElementById('edit-character-arcs').value = res.data.character_arcs || '';
            document.getElementById('edit-world-evolution').value = res.data.world_evolution || '';
        }
    } catch (e) {
        console.error('加载故事大纲失败:', e);
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
        await apiPost('api/actions.php', {
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
    const MAX_RETRIES = 2;  // 每章最多重试2次（含首次），超过标记 skipped
    const RETRY_DELAY = 5000;   // 首次失败后重试间隔
    const CATCHUP_DELAY = 2000; // 补写前等待间隔（让记忆引擎消化刚写完的章节）

    // ============================================================
    // 第一轮：正向写作（outlined 章节），失败则 skipped 跳过
    // ============================================================
    while (!autoWriteStop && myGen === autoWriteGen) {
        // 查询下一章
        let res;
        try {
            res = await apiPost('api/actions.php', {
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

        // 正常模式只返回 outlined 章节，没有则第一轮结束
        if (!res.data.next_chapter) break;

        const { next_chapter, completed_count, outlined_count, skipped_count } = res.data;
        const pct = outlined_count > 0 ? Math.round(completed_count / outlined_count * 100) : 0;

        ui.label.textContent  = `正在写作 第${next_chapter.chapter_number}章《${next_chapter.title}》`;
        ui.bar.style.width    = pct + '%';
        let detailText = `已完成 ${completed_count} / ${outlined_count} 章`;
        if (skipped_count > 0) detailText += ` · 跳过 ${skipped_count} 章`;
        ui.detail.textContent = detailText;

        // 清空流式框，准备显示新章节内容
        ui.stream.textContent = '';
        if (ui.cursor) ui.stream.appendChild(ui.cursor);

        try {
            const writeResult = await streamWriteChapter(
                NOVEL_ID,
                null,
                // onComplete 回调
                (statsText, _chapId, modelUsed) => {
                    ui.detail.textContent = statsText;
                    if (modelUsed) ui.modelLbl.textContent = `模型：${modelUsed}`;
                },
                // 实时 chunk 显示
                ui.stream,
                ui.cursor
            );
            
            // 静默超时/网络断连检测：streamWriteChapter 返回了部分内容但没触发 onComplete
            // 此时后端可能还在处理（ignore_user_abort 保证后端继续运行），等一会儿检查章节是否最终完成
            if (writeResult && writeResult.length > 50) {
                ui.label.textContent = `第${next_chapter.chapter_number}章连接中断，等待后端完成...`;
                let chapterDone = false;
                // 等待后端完成落盘（最多等 180 秒，大模型输出+摘要+记忆引擎可能很慢）
                for (let wait = 0; wait < 36; wait++) {
                    await new Promise(r => setTimeout(r, 5000));
                    try {
                        const statusRes = await apiPost('api/actions.php', {
                            action: 'get_novel_status', novel_id: NOVEL_ID
                        });
                        if (statusRes.ok) {
                            const nc = statusRes.data.next_chapter;
                            // 当前章节已完成：next_chapter 变了，或者当前章不在待写列表中
                            if (!nc || nc.id !== next_chapter.id) {
                                chapterDone = true;
                                ui.detail.textContent = `第${next_chapter.chapter_number}章已完成`;
                                break;
                            }
                        }
                    } catch (e) { /* 忽略查询错误 */ }
                }
                if (!chapterDone) {
                    // 等了3分钟还没完成 → mark_skipped，但先查询确认章节当前状态
                    // 避免后端刚落盘成功但前端还标记为 skipped 的竞争
                    try {
                        const checkRes = await apiPost('api/actions.php', {
                            action: 'get_chapter_status', chapter_id: next_chapter.id
                        });
                        if (checkRes.ok && checkRes.data && checkRes.data.status === 'completed') {
                            chapterDone = true;
                            ui.detail.textContent = `第${next_chapter.chapter_number}章后端已完成`;
                        }
                    } catch (e) { /* 忽略 */ }

                    if (!chapterDone) {
                        showToast(`第${next_chapter.chapter_number}章等待超时，将稍后补写`, 'warning');
                        try {
                            await apiPost('api/actions.php', {
                                action: 'mark_skipped',
                                chapter_id: next_chapter.id,
                                retry_limit: MAX_RETRIES,
                            });
                        } catch (e) { /* 忽略 */ }
                    }
                }
            }
        } catch (err) {
            showToast(`第${next_chapter.chapter_number}章写作出错：${err.message}`, 'error');
            
            if (autoWriteStop || myGen !== autoWriteGen) break;

            // 标记失败 → 后端判断是重试还是跳过
            // 注意：如果后端已完成落盘（status=completed），mark_skipped 不会覆盖
            try {
                const skipRes = await apiPost('api/actions.php', {
                    action: 'mark_skipped',
                    chapter_id: next_chapter.id,
                    retry_limit: MAX_RETRIES,
                });
                if (skipRes.ok && skipRes.data.status === 'completed') {
                    // 后端已完成落盘，前端超时误判为失败 → 跳过，继续下一章
                    showToast(`第${next_chapter.chapter_number}章实际已完成（前端超时误判）`, 'info');
                    ui.label.textContent = `第${next_chapter.chapter_number}章已完成，继续下一章...`;
                } else if (skipRes.ok && skipRes.data.status === 'skipped') {
                    showToast(`第${next_chapter.chapter_number}章跳过，稍后补写`, 'warning');
                    ui.label.textContent = `第${next_chapter.chapter_number}章跳过，继续下一章...`;
                } else {
                    ui.label.textContent = `第${next_chapter.chapter_number}章将重试...`;
                }
            } catch (e) {
                // mark_skipped 失败不阻塞，简单等一下继续
                ui.label.textContent = '5秒后继续...';
            }
            
            await new Promise(r => setTimeout(r, RETRY_DELAY));
            continue;
        }

        if (autoWriteStop || myGen !== autoWriteGen) break;
        const sleepMs = typeof AUTO_WRITE_INTERVAL !== 'undefined' ? AUTO_WRITE_INTERVAL : 1500;
        await new Promise(r => setTimeout(r, sleepMs));
    }

    // ============================================================
    // 第二轮：补写 skipped 章节（利用前后文）
    // 即使被手动停止，也尝试补写已有的 skipped 章节，避免漏章节
    // ============================================================
    let catchupRes;
    try {
        catchupRes = await apiPost('api/actions.php', {
            action: 'get_novel_status', novel_id: NOVEL_ID, mode: 'catchup'
        });
    } catch (e) { catchupRes = { ok: false }; }

    if (catchupRes.ok && catchupRes.data.skipped_count > 0) {
        showToast(`开始补写 ${catchupRes.data.skipped_count} 个跳过的章节...`, 'info');
        ui.label.textContent = '开始补写跳过的章节...';
        await new Promise(r => setTimeout(r, CATCHUP_DELAY));

        while (myGen === autoWriteGen) {
            // 查找下一个 skipped 章节
            let statusRes;
            try {
                statusRes = await apiPost('api/actions.php', {
                    action: 'get_novel_status', novel_id: NOVEL_ID, mode: 'catchup'
                });
            } catch (e) {
                ui.label.textContent = '查询状态失败，正在重试...';
                await new Promise(r => setTimeout(r, 3000));
                continue;
            }

            if (!statusRes.ok || !statusRes.data.next_chapter) break;
            // 只处理 skipped 的章节
            if (statusRes.data.next_chapter.status !== 'skipped') break;

            const sk = statusRes.data.next_chapter;
            const prefix = autoWriteStop ? '[停止前补写]' : '[补写]';
            ui.label.textContent = `${prefix} 第${sk.chapter_number}章《${sk.title}》`;
            ui.stream.textContent = '';
            if (ui.cursor) ui.stream.appendChild(ui.cursor);

            try {
                await streamWriteChapter(
                    NOVEL_ID,
                    sk.id,   // 指定章节ID，直接写 skipped 的章节
                    (statsText, _chapId, modelUsed) => {
                        ui.detail.textContent = prefix + ' ' + statsText;
                        if (modelUsed) ui.modelLbl.textContent = `模型：${modelUsed}`;
                    },
                    ui.stream,
                    ui.cursor
                );
                showToast(`第${sk.chapter_number}章补写成功！`, 'success');
            } catch (err) {
                // 补写也失败 → 重置为 outlined 留给下次重试，而非直接放弃
                showToast(`第${sk.chapter_number}章补写失败：${err.message}`, 'error');
                try {
                    await apiPost('api/actions.php', {
                        action: 'mark_skipped',
                        chapter_id: sk.id,
                        retry_limit: MAX_RETRIES + 1,  // 补写给更多重试机会
                    });
                } catch (e) { /* 静默 */ }
                ui.label.textContent = `第${sk.chapter_number}章补写失败，稍后重试`;
                await new Promise(r => setTimeout(r, 2000));
            }

            // 补写完当前章后，如果用户要求停止，则退出
            if (autoWriteStop || myGen !== autoWriteGen) break;
            const sleepMs = typeof AUTO_WRITE_INTERVAL !== 'undefined' ? AUTO_WRITE_INTERVAL : 1500;
            await new Promise(r => setTimeout(r, sleepMs));
        }
    }
    // end 补写逻辑

    // ============================================================
    // 收尾
    // ============================================================
    if (myGen !== autoWriteGen) return;

    // 最终确认是否全部完成
    try {
        const finalRes = await apiPost('api/actions.php', {
            action: 'get_novel_status', novel_id: NOVEL_ID
        });
        if (finalRes.ok && !finalRes.data.next_chapter) {
            ui.label.textContent = '所有章节写作完成！';
            finalStatus = 'completed';
            const failCount = finalRes.data.failed_count || 0;
            if (failCount > 0) {
                showToast(`写作完成，${failCount} 章需要手动处理`, 'warning');
            } else {
                showToast('全部章节已生成完毕！', 'success');
            }
        } else if (finalRes.ok && (finalRes.data.skipped_count || 0) === 0) {
            ui.label.textContent = '写作已暂停';
        }
    } catch (e) { /* 静默 */ }

    ui.spinner.style.display = 'none';

    await apiPost('api/actions.php', {
        action: 'update_novel_status', novel_id: NOVEL_ID, status: finalStatus
    });

    // 刷新章节列表（不销毁进度面板）
    refreshChapterList();
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

// 局部刷新章节列表（不整页 reload）
async function refreshChapterList() {
    try {
        const res = await fetch(location.href);
        const html = await res.text();
        const parser = new DOMParser();
        const doc    = parser.parseFromString(html, 'text/html');
        const newList = doc.getElementById('tab-chapters');
        const curList = document.getElementById('tab-chapters');
        if (newList && curList) {
            curList.innerHTML = newList.innerHTML;
            // 重新绑定单章写作按钮
            curList.querySelectorAll('.btn-write-single').forEach(btn => {
                btn.addEventListener('click', () => {
                    writeSingleChapter(NOVEL_ID, parseInt(btn.dataset.chapter));
                });
            });
        }
    } catch(e) { /* 静默失败，不影响主流程 */ }
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
    let completed = false;
    let chapterId = null;
    try {
        const result = await streamWriteChapter(NOVEL_ID, null, (statsText, chapId, modelUsed) => {
            completed = true;
            document.getElementById('writeModalSpinner').style.display = 'none';
            document.getElementById('writeModalStats').textContent     = statsText;
            cur.remove();
            if (chapId) {
                chapterId = chapId;
                const v = document.getElementById('writeModalViewBtn');
                v.href = `chapter.php?id=${chapId}`;
                v.style.display = '';
            }
        }, contentEl, cur);
        // 静默超时：有内容但未完成 → 等待后端落盘确认
        if (result && !completed) {
            cur.remove();
            await waitForBackendComplete(null, chapterId);
        }
    } catch (err) {
        contentEl.textContent = '写作失败：' + err.message;
    }
    refreshChapterList();
}

// ============================================================
// 写单章
// ============================================================

async function writeSingleChapter(novelId, chapterId) {
    const { contentEl, cur } = _openWriteModal('正在写作...');
    document.getElementById('writeModalStats').textContent  = '';
    document.getElementById('writeModalViewBtn').style.display = 'none';
    let completed = false;

    try {
        const result = await streamWriteChapter(novelId, chapterId, (statsText, chapId) => {
            completed = true;
            document.getElementById('writeModalStats').textContent  = statsText;
            document.getElementById('writeModalSpinner').style.display = 'none';
            cur.remove();
            if (chapId) {
                const viewBtn = document.getElementById('writeModalViewBtn');
                viewBtn.href = `chapter.php?id=${chapId}`;
                viewBtn.style.display = '';
            }
        }, contentEl, cur);
        // 静默超时：有内容但未完成 → 等待后端落盘确认
        if (result && !completed) {
            cur.remove();
            await waitForBackendComplete(chapterId, null);
        }
    } catch (err) {
        contentEl.textContent = '写作失败：' + err.message;
    }

    refreshChapterList();
}

// ============================================================
// 静默超时后等待后端落盘确认
// SSE 流断开但后端可能仍在处理（摘要/记忆引擎等），轮询确认章节最终状态
// ============================================================
async function waitForBackendComplete(chapterId, fallbackChapterId) {
    const statsEl = document.getElementById('writeModalStats');
    const spinnerEl = document.getElementById('writeModalSpinner');
    const targetId = chapterId || fallbackChapterId;

    // 显示等待状态
    if (spinnerEl) spinnerEl.style.display = '';
    if (statsEl) statsEl.textContent = '⏳ 连接中断，等待后端完成处理...';

    const MAX_WAIT = 180; // 最多等 180 秒
    const POLL_INTERVAL = 5000;
    const maxPolls = Math.ceil(MAX_WAIT / (POLL_INTERVAL / 1000));

    for (let i = 0; i < maxPolls; i++) {
        await new Promise(r => setTimeout(r, POLL_INTERVAL));

        try {
            // 如果有章节ID，直接查该章节状态
            if (targetId) {
                const res = await apiPost('api/actions.php', {
                    action: 'get_chapter_status', chapter_id: targetId
                });
                if (res.ok && res.data) {
                    if (res.data.status === 'completed') {
                        // 后端已完成落盘
                        if (spinnerEl) spinnerEl.style.display = 'none';
                        if (statsEl) statsEl.textContent = `✅ 章节已完成（${res.data.words}字）`;
                        const viewBtn = document.getElementById('writeModalViewBtn');
                        if (viewBtn) {
                            viewBtn.href = `chapter.php?id=${targetId}`;
                            viewBtn.style.display = '';
                        }
                        showToast('章节写作完成！', 'success');
                        return;
                    } else if (res.data.status === 'outlined' || res.data.status === 'skipped') {
                        // 后端也失败了，章节被重置
                        if (spinnerEl) spinnerEl.style.display = 'none';
                        if (statsEl) statsEl.textContent = '⚠️ 写作失败，章节已重置，可重新写作';
                        showToast('写作失败，可重新尝试', 'warning');
                        return;
                    }
                }
            } else {
                // 没有章节ID，通过小说状态间接判断
                const res = await apiPost('api/actions.php', {
                    action: 'get_novel_status', novel_id: NOVEL_ID
                });
                if (res.ok && res.data) {
                    // 如果下一个待写章节变了，说明当前章已完成
                    if (!res.data.next_chapter || res.data.skipped_count === 0) {
                        if (spinnerEl) spinnerEl.style.display = 'none';
                        if (statsEl) statsEl.textContent = '✅ 章节已完成';
                        showToast('章节写作完成！', 'success');
                        return;
                    }
                }
            }
        } catch (e) { /* 查询失败继续等 */ }

        // 更新等待提示
        const elapsed = (i + 1) * (POLL_INTERVAL / 1000);
        if (statsEl) statsEl.textContent = `⏳ 等待后端完成...（${elapsed}s / ${MAX_WAIT}s）`;
    }

    // 超时仍未完成
    if (spinnerEl) spinnerEl.style.display = 'none';
    if (statsEl) statsEl.textContent = '⚠️ 等待超时，请刷新页面查看章节状态';
    showToast('等待后端处理超时，请稍后刷新查看', 'warning');
}

// ============================================================
// 核心：流式写章节（自动选择 SSE 或异步轮询模式）
// ============================================================

// 全局标志：是否使用异步轮询模式（绕过 Nginx 超时）
// 默认使用异步模式：方舟 Coding Plan 等国内 API 有服务端超时限制（~60s），
// SSE 直连模式下 Nginx/FPM 的 fastcgi_read_timeout 也会截断长连接，
// 导致 ERR_INCOMPLETE_CHUNKED_ENCODING。异步 worker 是 PHP CLI 进程，不受这些限制。
let USE_ASYNC_WRITE = true;
// 缓存：exec() 是否被服务器禁用（null=未检测, true=禁用, false=可用）
let EXEC_DISABLED = null;

/**
 * 流式写章节 — 自动选择 SSE 直连或异步轮询模式
 * 优先使用 SSE（实时性更好），如果检测到连接被截断则自动切换到异步模式
 */
async function streamWriteChapter(novelId, chapterId, onComplete, displayEl, cursorEl) {
    if (USE_ASYNC_WRITE) {
        return streamWriteChapterAsync(novelId, chapterId, onComplete, displayEl, cursorEl);
    }
    
    try {
        return await streamWriteChapterSSE(novelId, chapterId, onComplete, displayEl, cursorEl);
    } catch (err) {
        // 如果是网络中断错误，尝试切换到异步模式重试（仅当 exec() 可用时）
        if (err.message && (err.message.includes('network error') || err.message.includes('网络连接中断') || err.message.includes('ERR_INCOMPLETE'))) {
            // 用缓存标志判断 exec() 是否被禁用，避免再次调用 write_start.php 意外启动任务
            if (EXEC_DISABLED) {
                console.warn('[write_chapter] SSE 中断但异步模式不可用，直接抛出错误');
                throw err;
            }
            console.warn('[write_chapter] SSE 模式失败，自动切换到异步轮询模式');
            USE_ASYNC_WRITE = true;
            // SSE 中断时，章节状态可能被设为 writing，需要重置才能被异步 worker 识别
            try {
                await apiPost('api/actions.php', {
                    action: 'reset_writing_chapter', novel_id: novelId, chapter_id: chapterId
                });
            } catch (e) { /* 忽略重置失败，worker 自身也会处理 */ }
            return streamWriteChapterAsync(novelId, chapterId, onComplete, displayEl, cursorEl);
        }
        throw err;
    }
}

// ============================================================
// 异步轮询模式：启动后台写作 → 轮询进度文件 → 实时显示
// 完全绕过 Nginx/FPM 长连接超时限制
// ============================================================
async function streamWriteChapterAsync(novelId, chapterId, onComplete, displayEl, cursorEl) {
    // 1. 启动后台写作任务
    let startRes = await apiPost('api/write_start.php', {
        novel_id: novelId,
        chapter_id: chapterId,
    });
    
    if (!startRes.ok) {
        // 异步模式不可用（exec禁用 或 worker启动失败）→ 缓存标志 + 自动回退到 SSE 直连模式
        if (startRes.fallback_sse) {
            console.warn('[write_chapter] 异步模式不可用，自动切换到 SSE 直连模式');
            // 打印服务器返回的诊断信息，帮助定位 worker 启动失败原因
            if (startRes.debug_info) {
                console.group('[write_chapter] Worker 启动诊断');
                console.log('PHP二进制(原始):', startRes.debug_info.php_binary_original);
                console.log('PHP二进制(解析):', startRes.debug_info.php_binary_resolved);
                console.log('OS平台:', startRes.debug_info.php_os_family);
                console.log('Worker脚本存在:', startRes.debug_info.worker_exists);
                console.log('进度目录可写:', startRes.debug_info.progress_dir_writable);
                console.log('进度文件状态:', startRes.debug_info.progress_file_status ?? '(文件不存在)');
                console.log('日志文件存在:', startRes.debug_info.log_file_exists);
                if (startRes.debug_info.log_file_preview) {
                    console.log('Worker日志预览:', startRes.debug_info.log_file_preview);
                }
                if (startRes.debug_info.wrapper_sh_exists !== undefined) {
                    console.log('Wrapper.sh 存在:', startRes.debug_info.wrapper_sh_exists);
                    console.log('Wrapper.sh 可执行:', startRes.debug_info.wrapper_sh_executable);
                }
                console.groupEnd();
            }
            EXEC_DISABLED  = true;
            USE_ASYNC_WRITE = false;
            return streamWriteChapterSSE(novelId, chapterId, onComplete, displayEl, cursorEl);
        }
        // 如果是"已有任务在运行"的误报，尝试重置僵死状态后重试一次
        if (startRes.msg && startRes.msg.includes('已有写作任务在运行')) {
            console.warn('[write_chapter] 检测到僵死任务，尝试重置后重试');
            try {
                await apiPost('api/actions.php', {
                    action: 'reset_writing_chapter', novel_id: novelId
                });
            } catch (e) { /* 忽略 */ }
            // 等一下让僵死清理生效
            await new Promise(r => setTimeout(r, 2000));
            startRes = await apiPost('api/write_start.php', {
                novel_id: novelId,
                chapter_id: chapterId,
            });
            // 再次检测 fallback_sse
            if (!startRes.ok && startRes.fallback_sse) {
                console.warn('[write_chapter] 异步模式不可用（重试后），自动切换到 SSE 直连模式');
                if (startRes.debug_info) {
                    console.group('[write_chapter] Worker 启动诊断（重试后）');
                    console.log('PHP二进制(解析):', startRes.debug_info.php_binary_resolved);
                    console.log('Worker日志:', startRes.debug_info.log_file_preview || '(空)');
                    console.log('进度文件状态:', startRes.debug_info.progress_file_status ?? '(不存在)');
                    console.groupEnd();
                }
                EXEC_DISABLED  = true;
                USE_ASYNC_WRITE = false;
                return streamWriteChapterSSE(novelId, chapterId, onComplete, displayEl, cursorEl);
            }
        }
        if (!startRes.ok) {
            throw new Error(startRes.msg || '启动写作任务失败');
        }
    }
    
    const taskId = startRes.task_id;
    let fullText = '';
    let lastContentLength = 0;
    let lastMsgIndex = 0;  // 跟踪已处理的消息索引，避免重复显示
    let gotData = false;
    const POLL_INTERVAL = 150; // 0.15秒轮询一次
    const MAX_POLL_TIME = 600000; // 最多轮询 10 分钟
    const startTime = Date.now();
    
    // 调试：每 3 秒打印一次轮询状态（仅首次 45 秒内）
    let lastDebugLog = 0;
    const DEBUG_LOG_WINDOW = 45000;  // 前 45 秒
    console.log(`[write_chapter_async] 开始轮询 task_id=${taskId}, displayEl=${displayEl ? '已传入' : '未传入'}, cursorEl=${cursorEl ? '已传入' : '未传入'}`);
    
    // 2. 轮询进度
    while (Date.now() - startTime < MAX_POLL_TIME) {
        await new Promise(r => setTimeout(r, POLL_INTERVAL));
        
        let pollRes;
        try {
            pollRes = await apiPost('api/write_poll.php', { task_id: taskId });
        } catch (e) { continue; } // 查询失败继续
        
        if (!pollRes.ok) {
            if (pollRes.msg && pollRes.msg.includes('不存在')) break;
            continue;
        }
        
        const { status, content, messages, words, model_used, error, chapter_id } = pollRes;
        
        // 调试：定期打印轮询状态
        const nowTs = Date.now();
        if (nowTs - lastDebugLog > 3000 && nowTs - startTime < DEBUG_LOG_WINDOW) {
            console.log(`[write_chapter_async poll] status=${status} | content_len=${(content||'').length} | msgs_len=${(messages||[]).length} | elapsed=${Math.round((nowTs - startTime)/1000)}s`);
            lastDebugLog = nowTs;
        }
        
        // 更新实时内容
        if (content && content.length > lastContentLength) {
            console.log(`[write_chapter_async] 收到新内容 +${content.length - lastContentLength}字符 → 显示在 displayEl`);
            const newChunk = content.substring(lastContentLength);
            lastContentLength = content.length;
            fullText = content;
            gotData = true;
            
            if (displayEl) {
                // 移除等待提示
                const oldHint = displayEl.querySelector('.ai-waiting-hint');
                if (oldHint) oldHint.remove();
                
                if (cursorEl && cursorEl.parentNode === displayEl) {
                    displayEl.insertBefore(document.createTextNode(newChunk), cursorEl);
                } else {
                    displayEl.textContent = fullText;
                }
                displayEl.scrollTop = displayEl.scrollHeight;
            }
        }
        
        // 处理新消息（模型切换、重试、info 等）— 只处理增量消息
        if (messages && messages.length > lastMsgIndex) {
            const newMessages = messages.slice(lastMsgIndex);
            lastMsgIndex = messages.length;
            console.log('[write_chapter_async] 新消息:', JSON.stringify(newMessages));
            
            for (const msg of newMessages) {
                // 等待提示（AI思考中 / 重试等待）
                if (msg.waiting && displayEl) {
                    const oldHint = displayEl.querySelector('.ai-waiting-hint');
                    if (oldHint) oldHint.remove();
                    const hint = document.createElement('span');
                    hint.className = 'ai-waiting-hint';
                    hint.style.cssText = 'color:var(--text-muted);font-style:italic;font-size:13px;';
                    hint.textContent = `⏳ ${msg.msg || msg.reason || 'AI 思考中…'}`;
                    if (cursorEl && cursorEl.parentNode === displayEl) {
                        displayEl.insertBefore(hint, cursorEl);
                    } else {
                        displayEl.appendChild(hint);
                    }
                }
                
                // 模型尝试/切换信息
                if (msg.info) {
                    showToast(msg.info, 'info');
                }
                
                // 模型切换提示
                if (msg.model) {
                    const modelLabel = msg.model;
                    const attempt = msg.attempt || 1;
                    const thinking = msg.thinking ? '🧠' : '📡';
                    showToast(`${thinking} ${modelLabel} 第${attempt}次尝试`, 'info');
                }
                
                // 警告
                if (msg.warning) {
                    showToast(msg.warning, 'warning');
                }
            }
        }
        
        // 检查状态
        if (status === 'completed' || status === 'done') {
            // 移除等待提示
            if (displayEl) {
                const oldHint = displayEl.querySelector('.ai-waiting-hint');
                if (oldHint) oldHint.remove();
            }
            // 从 messages 中找到 stats 消息
            const statsMsg = messages?.find(m => m.stats) || messages?.[messages.length - 1];
            if (onComplete && statsMsg) {
                onComplete(statsMsg.stats, statsMsg.chapter_id || chapter_id, statsMsg.model_used || model_used);
            } else if (onComplete) {
                const wordsText = words ? `，共 ${words} 字` : '';
                onComplete(`章节完成${wordsText}`, chapter_id, model_used);
            }
            return fullText || content;
        }
        
        if (status === 'error') {
            if (error && error.includes('取消')) {
                throw new Error(error);
            }
            throw new Error(error || '写作失败');
        }
    }
    
    // 超时
    if (gotData && fullText.length > 50) {
        console.warn(`[write_chapter_async] 轮询超时，保留已有${fullText.length}字`);
        return fullText;
    }
    throw new Error('写作超时（10分钟无结果）');
}

// ============================================================
// SSE 直连模式（原始实现）
// ============================================================

/**
 * @param novelId     小说ID
 * @param chapterId   章节ID（null = 自动选下一章）
 * @param onComplete  完成回调 (statsText, chapterId, modelUsed)
 * @param displayEl   实时显示容器（可选）
 * @param cursorEl    光标元素（可选，跟随文字末尾）
 */
async function streamWriteChapterSSE(novelId, chapterId, onComplete, displayEl, cursorEl) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const response = await fetch('api/write_chapter.php', {
        method:  'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
        body:    JSON.stringify({ novel_id: novelId, chapter_id: chapterId }),
    });

    // 检查 HTTP 状态码
    if (!response.ok) {
        let errMsg = `服务器错误 (${response.status})`;
        try {
            const errText = await response.text();
            const errJson = JSON.parse(errText);
            errMsg = errJson.msg || errJson.error || errMsg;
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
        } catch {
            // 响应体不是 JSON（可能是 HTML 登录页/错误页）
            const bodyPreview = body.substring(0, 300).replace(/[\r\n]+/g, ' ');
            errMsg = `SSE 响应 Content-Type 异常 (${ct})：${bodyPreview}`;
        }
        console.error('[write_chapter SSE] Content-Type 异常:', ct, '响应预览:', body.substring(0, 500));
        throw new Error(errMsg);
    }

    const reader  = response.body.getReader();
    const decoder = new TextDecoder();
    let   fullText = '';
    let   buf      = '';
    let   gotData  = false;  // 是否收到过有效数据
    let   gotChunk = false;  // 是否收到过文字 chunk
    
    // 静默检测：记录最后一次收到文字 chunk 的时间
    // 大模型输出慢时可能长时间无新文字，但心跳仍在
    // 只有在既无文字又无心跳的情况下才判断为断连
    let lastChunkTime  = Date.now();  // 最后收到文字的时间
    let lastActiveTime = Date.now();  // 最后收到任何数据/心跳的时间
    const CHUNK_SILENCE_TIMEOUT = 300000;  // 5分钟无新文字视为可能卡死
    const HEARTBEAT_TIMEOUT     = 180000;  // 3分钟无任何数据/心跳视为断连

    let interruptedByNetwork = false;  // 标记是否因网络中断退出

    while (true) {
        let readResult;
        try {
            readResult = await reader.read();
        } catch (readErr) {
            // 网络层错误（ERR_INCOMPLETE_CHUNKED_ENCODING、连接重置等）
            interruptedByNetwork = true;
            // 如果已有输出内容，不要丢弃——后端可能已经完成或部分完成
            if (gotChunk && fullText.length > 50) {
                console.warn(`[write_chapter] 读取中断(${readErr.message})，保留已有${fullText.length}字`);
                break; // 跳出 while，返回已有内容
            }
            // 没有有效内容时才抛出，触发自动切换到异步模式
            throw new Error(`网络连接中断：${readErr.message}`);
        }
        const { value, done } = readResult;
        if (done) break;

        buf += decoder.decode(value, { stream: true });
        const lines = buf.split('\n');
        buf = lines.pop();

        for (const line of lines) {
            // 处理心跳事件
            if (line.startsWith('event: heartbeat')) {
                lastActiveTime = Date.now();
                continue;
            }
            
            if (!line.startsWith('data: ')) continue;
            const payload = line.slice(6).trim();
            if (payload === '[DONE]') break;

            try {
                const d = JSON.parse(payload);
                gotData = true;
                lastActiveTime = Date.now(); // 收到任何数据都更新活跃时间

                if (d.chunk) {
                    fullText += d.chunk;
                    lastChunkTime = Date.now(); // 收到文字更新静默时间
                    gotChunk = true;
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

                // 后端发送的等待状态（AI正在思考中）
                if (d.waiting) {
                    lastActiveTime = Date.now();
                    // 不更新 lastChunkTime，因为只是等待通知
                    // 在显示区域提示用户
                    if (displayEl) {
                        // 移除旧的等待提示
                        const oldHint = displayEl.querySelector('.ai-waiting-hint');
                        if (oldHint) oldHint.remove();
                        // 添加新的等待提示
                        const hint = document.createElement('span');
                        hint.className = 'ai-waiting-hint';
                        hint.style.cssText = 'color:var(--text-muted);font-style:italic;font-size:13px;';
                        hint.textContent = `⏳ ${d.msg || 'AI 思考中…'}`;
                        if (cursorEl && cursorEl.parentNode === displayEl) {
                            displayEl.insertBefore(hint, cursorEl);
                        } else {
                            displayEl.appendChild(hint);
                        }
                    }
                }
                
                // 收到新文字时移除等待提示
                if (d.chunk && displayEl) {
                    const oldHint = displayEl.querySelector('.ai-waiting-hint');
                    if (oldHint) oldHint.remove();
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

                // 后端的字数告警和截断提示 —— 以前被静默吞掉，现在浮出
                // 场景：max_tokens 耗尽被截、字数接近/超过硬上限等
                if (d.warning) {
                    showToast(d.warning, 'warning');
                    console.warn('[write_chapter] ' + d.warning);
                }

                // 后端的提示消息（比如自动调整 max_tokens）
                if (d.info) {
                    console.info('[write_chapter] ' + d.info);
                }

                if (d.stats && onComplete) {
                    onComplete(d.stats, d.chapter_id, d.model_used);
                }
                if (d.error) throw new Error(d.error);

            } catch(e) {
                if (e.message !== 'Unexpected token') throw e;
            }
        }
        
        // 超时检测（区分两种情况）
        const now = Date.now();
        
        // 1. 完全断连：3分钟无任何数据/心跳 → 立即报错
        if (now - lastActiveTime > HEARTBEAT_TIMEOUT) {
            reader.cancel();
            throw new Error('连接超时（3分钟无响应），请检查网络或重试');
        }
        
        // 2. 文字静默：5分钟无新文字但心跳正常 → AI可能卡住了
        //    返回已有内容而不是报错，让上层判断是否续写
        if (gotChunk && now - lastChunkTime > CHUNK_SILENCE_TIMEOUT) {
            console.warn(`[write_chapter] 文字输出静默超时（5分钟无新文字），已收到${fullText.length}字`);
            // 不抛异常，返回已有内容，上层可以决定是否续写
            break;
        }
    }

    // 流结束但没收到任何有效数据 — 可能是后端静默失败
    if (!gotData && !fullText) {
        throw new Error('服务器未返回任何数据，请检查AI模型配置是否正确');
    }

    // 网络中断导致流提前结束，且未收到 onComplete 信号
    // 说明内容不完整，应切换到异步模式重新发起写作
    // （后端虽有 ignore_user_abort，但 Nginx 截断后 PHP 输出写会失败，
    //   摘要生成/落盘可能中断，导致章节卡在 writing 状态）
    if (interruptedByNetwork && fullText.length > 0) {
        console.warn(`[write_chapter] 网络中断，SSE 内容不完整（${fullText.length}字），切换到异步模式`);
        throw new Error(`network error: SSE 连接被截断，已有${fullText.length}字不完整，需异步重试`);
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
        const res = await fetch('api/cancel_write.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action: 'cancel',
                novel_id: NOVEL_ID
            })
        });
        
        const data = await res.json();
        
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
        const res = await fetch('api/cancel_write.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action: 'reset',
                novel_id: NOVEL_ID
            })
        });
        
        const data = await res.json();
        
        if (data.ok) {
            showToast('已重置未完成章节', 'success');
            // 刷新页面
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast('重置失败：' + data.msg, 'error');
        }
    } catch (err) {
        showToast('请求失败：' + err.message, 'error');
    }
}

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
        const res = await fetch('api/validate_consistency.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                novel_id: novelId,
                chapter_number: chapterNumber || 0
            })
        });
        
        const data = await res.json();
        
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
        const res = await fetch('api/cancel_write.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action: 'reset_chapter',
                novel_id: NOVEL_ID,
                chapter_id: chapterId
            })
        });
        
        const data = await res.json();
        
        if (data.ok) {
            showToast('已重置章节', 'success');
            // 刷新章节列表
            if (typeof refreshChapterList === 'function') {
                refreshChapterList();
            }
        } else {
            showToast('重置失败：' + data.msg, 'error');
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

    let maxRetries = 5;
    let retries = 0;
    
    while (retries <= maxRetries) {
        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const response = await fetch('api/supplement_outline.php', {
                method:  'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
                body:    JSON.stringify({ novel_id: novelId }),
            });

            if (!response.ok) {
                throw new Error('服务器错误: ' + response.status);
            }

            const reader  = response.body.getReader();
            const decoder = new TextDecoder();
            let buf = '';
            let isComplete = false;

            readerLoop:
            while (true) {
                const { value, done } = await reader.read();
                if (done) break;

                buf += decoder.decode(value, { stream: true });
                const events = buf.split('\n\n');
                buf = events.pop();

                for (const eventBlock of events) {
                    const lines = eventBlock.split('\n');
                    let currentEvent = '';
                    let dataLine = '';

                    for (const line of lines) {
                        const trimmed = line.trim();
                        if (trimmed.startsWith(':')) continue;
                        if (trimmed.startsWith('event: ')) {
                            currentEvent = trimmed.slice(7).trim();
                            continue;
                        }
                        if (trimmed.startsWith('data: ')) {
                            dataLine = trimmed;
                        }
                    }

                    if (!dataLine) continue;
                    const raw = dataLine.slice(6).trim();
                    if (raw === '[DONE]') break readerLoop;

                    let d;
                    try { d = JSON.parse(raw); } catch { continue; }

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
                            
                            // 有进展时重置重试次数
                            retries = 0;
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
                            isComplete = true;
                            spinner.style.display = 'none';
                            cursor.remove();
                            label.textContent = d.msg;
                            showToast('大纲补写完成！', 'success');
                            setTimeout(() => location.reload(), 1800);
                            return;
                    }
                }
            }

            if (isComplete) return;
            
            // 如果跑到这里说明由于网络中断退出了 reader.read()，主动抛出异常进入重试
            throw new Error('网络连接异常中断');

        } catch (err) {
            retries++;
            if (retries > maxRetries) {
                showToast('多次重试失败，补写中断：' + err.message, 'error');
                label.textContent = '重试失败：' + err.message;
                spinner.style.display = 'none';
                break;
            }
            
            // 如果发生网络异常，延迟后重试
            label.textContent = `网络错误，正在恢复... (第 ${retries}/${maxRetries} 次)`;
            showToast(`网络中断，尝试恢复 (第 ${retries} 次)...`, 'warning');
            
            // 延时重试，越来越慢
            await new Promise(r => setTimeout(r, 2000 * retries));
        }
    }
    
    btnSupp.disabled = false;
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

    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const response = await fetch('api/generate_story_outline.php', {
            method:  'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
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
                    case 'progress':
                        label.textContent = d.msg || '生成中...';
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

                    case 'model_switch':
                        streamBox.textContent = '';
                        streamBox.appendChild(cursor);
                        const hint = document.createTextNode(`[切换到「${d.next_model}」重试...]\n`);
                        // 确保 cursor 在 streamBox 中
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
        showToast('生成出错：' + err.message, 'error');
        label.textContent = '出错：' + err.message;
    } finally {
        btn.disabled = false;
    }
}

// ============================================================
// [v2新增] 生成章节概要
// ============================================================

async function generateChapterSynopsis() {
    const btn = document.getElementById('btn-synopsis');
    if (!btn) return;
    const novelId = parseInt(btn.dataset.novel);
    const outlined = parseInt(btn.dataset.outlined);

    if (!confirm(`确定要为所有已大纲的章节生成概要吗？\n\n这将生成详细的章节写作蓝图，帮助提高小说质量。\n共 ${outlined} 章需要生成概要。`)) {
        return;
    }

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

    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const response = await fetch('api/generate_chapter_synopsis.php', {
            method:  'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
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
                    case 'progress':
                        label.textContent = d.msg || '生成中...';
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

                    case 'model_switch':
                        streamBox.textContent = '';
                        streamBox.appendChild(cursor);
                        const hint = document.createTextNode(`[切换到「${d.next_model}」重试...]\n`);
                        // 确保 cursor 在 streamBox 中
                        if (!streamBox.contains(cursor)) {
                            streamBox.appendChild(cursor);
                        }
                        streamBox.insertBefore(hint, cursor);
                        showToast(`模型切换 → ${d.next_model}`, 'info');
                        break;

                    case 'chapter_done':
                        // 每章完成后添加分隔
                        const divider = document.createTextNode(`\n✓ ${d.msg}\n\n`);
                        // 确保 cursor 在 streamBox 中
                        if (!streamBox.contains(cursor)) {
                            streamBox.appendChild(cursor);
                        }
                        streamBox.insertBefore(divider, cursor);
                        break;

                    case 'error':
                        showToast(d.msg, 'error');
                        break;

                    case 'complete':
                        cursor.remove();
                        label.textContent = d.msg;
                        showToast('章节概要生成完成！', 'success');
                        setTimeout(() => location.reload(), 1500);
                        return;
                }
                currentEvent = '';
            }
        }

    } catch (err) {
        showToast('生成出错：' + err.message, 'error');
        label.textContent = '出错：' + err.message;
    } finally {
        btn.disabled = false;
    }
}


// ================================================================
// 优化大纲逻辑（支持断点续传）
// ================================================================
const OPTIMIZE_MAX_RECONNECTS  = 10;
const OPTIMIZE_RECONNECT_DELAY = 30000;  // 30秒

let optimizeOutlineRunning = false;
let optimizeOutlineController = null;

/**
 * 查询数据库中实际已优化的最大章节号
 */
async function fetchLastOptimized(novelId) {
    try {
        const response = await fetch(`api/get_optimize_progress.php?novel_id=${novelId}`);
        const data = await response.json();
        return data.optimized_chapter || 0;
    } catch {
        return 0;
    }
}

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
    const novelId       = parseInt(btn.dataset.novel);

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
    let reconnects = 0;  // 重连次数

    // 从数据库获取已优化进度
    let startFrom = await fetchLastOptimized(novelId);
    if (startFrom > 0) {
        startFrom = startFrom + 1;  // 从下一章开始
        progressLabel.textContent = `检测到已优化至第 ${startFrom - 1} 章，从第 ${startFrom} 章继续...`;
        showToast(`从第 ${startFrom} 章继续优化`, 'info');
    } else {
        startFrom = 0;  // 从头开始
    }

    optimizeOutlineRunning = true;

    /**
     * 执行一次优化请求
     * 返回值：
     *   'complete'  — 服务端正常发出 complete 事件
     *   'dropped'   — 连接中断
     *   'aborted'   — 用户主动取消
     */
    async function runOptimize(fromChapter) {
        optimizeOutlineController = new AbortController();

        let response;
        try {
            response = await fetch('api/optimize_outline.php', {
                method:  'POST',
                headers: {'Content-Type': 'application/json'},
                body:    JSON.stringify({
                    novel_id:   novelId,
                    start_from: fromChapter,
                }),
                signal: optimizeOutlineController.signal,
            });

            if (!response.ok) {
                const errText = await response.text().catch(() => '');
                console.error('API 错误:', response.status, errText);
                showToast(`服务器错误: ${response.status}`, 'error');
                return 'aborted';
            }
        } catch (fetchErr) {
            if (fetchErr.name === 'AbortError') return 'aborted';
            return 'dropped';
        }

        const reader  = response.body.getReader();
        const decoder = new TextDecoder();
        let buf = '', currentEvent = '';

        // 心跳检测
        let lastHeartbeat = Date.now();
        const HEARTBEAT_TIMEOUT = 300000; // 300秒（5分钟）无心跳视为断开

        while (true) {
            // 在读取数据前检查心跳超时
            if (Date.now() - lastHeartbeat > HEARTBEAT_TIMEOUT) {
                reader.cancel();
                showToast('连接超时（5分钟无响应），AI服务可能响应较慢，请稍后重试', 'error');
                return 'dropped';
            }

            let readResult;
            try {
                readResult = await reader.read();
            } catch {
                return 'dropped';
            }

            const { value, done } = readResult;
            if (done) return 'dropped';  // 流意外关闭

            // 收到数据，更新心跳时间
            lastHeartbeat = Date.now();

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

                // 处理心跳事件
                if (currentEvent === 'heartbeat') {
                    lastHeartbeat = Date.now();
                    currentEvent = '';
                    continue;
                }

                if (!trimmed.startsWith('data: ')) continue;
                const raw = trimmed.slice(6);
                if (raw === '[DONE]') return 'complete';

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
                        // 如果是断点续传，显示提示
                        if (d.resuming) {
                            showToast(`从第 ${d.start_from} 章继续优化...`, 'info');
                        }
                        break;

                    case 'batch_done':
                        totalChanged += (d.changed || 0);
                        statsEl.textContent = '已处理，修改 ' + totalChanged + ' 章';
                        batchLog.style.display = '';
                        const item = document.createElement('div');
                        item.className = 'p-2 border-bottom border-secondary small';
                        item.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>' + (d.msg || '') + '</span>';
                        batchLog.appendChild(item);
                        batchLog.scrollTop = batchLog.scrollHeight;
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
                        return 'complete';
                }
                currentEvent = '';
            }
        }
    }

    // ================================================================
    // 主循环：执行优化，失败时自动恢复
    // ================================================================
    try {
        while (optimizeOutlineRunning) {
            const result = await runOptimize(startFrom);

            if (result === 'aborted') break;

            if (result === 'complete') {
                // 正常完成
                break;
            } else {
                // 连接断开（result === 'dropped'）
                reconnects++;

                progressLabel.textContent = `连接中断（第 ${reconnects}/${OPTIMIZE_MAX_RECONNECTS} 次），正在恢复...`;
                showToast(`连接中断，正在恢复（第 ${reconnects}/${OPTIMIZE_MAX_RECONNECTS} 次）...`, 'info');

                // 等待服务端完成当前批次
                const adjustedDelay = OPTIMIZE_RECONNECT_DELAY * (1 + Math.min(reconnects, 3) * 0.5);
                await new Promise(r => setTimeout(r, adjustedDelay));

                // 查询 DB 获取真实进度
                const lastOptimized = await fetchLastOptimized(novelId);

                if (reconnects >= OPTIMIZE_MAX_RECONNECTS) {
                    if (lastOptimized > 0) {
                        showToast(`检测到已优化至第 ${lastOptimized} 章，继续优化...`, 'info');
                        startFrom = lastOptimized + 1;
                        reconnects = 0;
                    } else {
                        progressLabel.textContent = `连接多次中断（已重试 ${OPTIMIZE_MAX_RECONNECTS} 次），请手动点击继续优化。`;
                        showToast('连接多次中断，但您可以随时点击按钮继续，从上次中断处恢复', 'info');
                        reconnects = 0;
                    }
                } else if (lastOptimized > 0) {
                    startFrom = lastOptimized + 1;
                    reconnects = 0;
                    showToast(`从第 ${startFrom} 章继续优化...`, 'info');
                } else {
                    showToast(`重试优化...`, 'info');
                }

                // 清空流显示区域，准备重试
                streamBox.textContent = '';
                streamBox.appendChild(cursor);
            }
        }

    } catch (err) {
        if (err.name !== 'AbortError') {
            showToast('请求失败：' + err.message, 'error');
            progressLabel.textContent = '出错：' + err.message;
        }
    } finally {
        btn.disabled = false;
        optimizeOutlineRunning = false;
    }
}