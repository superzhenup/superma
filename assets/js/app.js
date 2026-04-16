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
        btnOptimizeOutline.addEventListener('click', () => optimizeOutlineLogic());
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
const OUTLINE_CHUNK    = 30;
// 断线后最多自动重连次数（增加到10次，给更多恢复机会）
const MAX_RECONNECTS   = 10;
// 断线后等待服务端完成当前批次的时间（ms），增加到30秒确保AI处理完成
const RECONNECT_DELAY  = 30000;

async function generateOutline() {
    const btnOutline = document.getElementById('btn-outline');
    const outlined   = parseInt(btnOutline.dataset.outlined);
    const target     = parseInt(btnOutline.dataset.target);
    const novelId    = parseInt(btnOutline.dataset.novel);

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
            response = await fetch('api/generate_outline.php', {
                method:  'POST',
                headers: {'Content-Type': 'application/json'},
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

        // 清空流式框，准备显示新章节内容
        ui.stream.textContent = '';
        if (ui.cursor) ui.stream.appendChild(ui.cursor);

        try {
            await streamWriteChapter(
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
        } catch (err) {
            showToast('写作出错：' + err.message, 'error');
            ui.label.textContent = '写作出错：' + err.message;
            // 出错后等待一段时间再重试（可能是临时网络问题）
            if (!autoWriteStop && myGen === autoWriteGen) {
                ui.label.textContent = '5秒后自动重试...';
                await new Promise(r => setTimeout(r, 5000));
                continue;
            }
            break;
        }

        if (autoWriteStop || myGen !== autoWriteGen) break;
        await new Promise(r => setTimeout(r, 1500));
    }

    // 只有本代循环才做收尾
    if (myGen !== autoWriteGen) return;

    autoWriteRunning = false;
    setAutoWriteUI(false);
    ui.spinner.style.display = '';

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
    try {
        await streamWriteChapter(NOVEL_ID, null, (statsText, chapterId, modelUsed) => {
            document.getElementById('writeModalSpinner').style.display = 'none';
            document.getElementById('writeModalStats').textContent     = statsText;
            cur.remove();
            if (chapterId) {
                const v = document.getElementById('writeModalViewBtn');
                v.href = `chapter.php?id=${chapterId}`;
                v.style.display = '';
            }
        }, contentEl, cur);
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

    try {
        await streamWriteChapter(novelId, chapterId, (statsText, chapId) => {
            document.getElementById('writeModalStats').textContent  = statsText;
            document.getElementById('writeModalSpinner').style.display = 'none';
            cur.remove();
            if (chapId) {
                const viewBtn = document.getElementById('writeModalViewBtn');
                viewBtn.href = `chapter.php?id=${chapId}`;
                viewBtn.style.display = '';
            }
        }, contentEl, cur);
    } catch (err) {
        contentEl.textContent = '写作失败：' + err.message;
    }

    refreshChapterList();
}

// ============================================================
// 核心：流式写章节
// ============================================================

/**
 * @param novelId     小说ID
 * @param chapterId   章节ID（null = 自动选下一章）
 * @param onComplete  完成回调 (statsText, chapterId, modelUsed)
 * @param displayEl   实时显示容器（可选）
 * @param cursorEl    光标元素（可选，跟随文字末尾）
 */
async function streamWriteChapter(novelId, chapterId, onComplete, displayEl, cursorEl) {
    const response = await fetch('api/write_chapter.php', {
        method:  'POST',
        headers: {'Content-Type': 'application/json'},
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
        } catch {}
        throw new Error(errMsg);
    }

    const reader  = response.body.getReader();
    const decoder = new TextDecoder();
    let   fullText = '';
    let   buf      = '';
    let   gotData  = false;  // 是否收到过有效数据

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
        }
    }

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

    try {
        const response = await fetch('api/supplement_outline.php', {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
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
        const response = await fetch('api/generate_story_outline.php', {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
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
        const response = await fetch('api/generate_chapter_synopsis.php', {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
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
        const response = await fetch('api/optimize_outline.php', {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
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
