// ================================================================
// 优化大纲逻辑（AJAX 轮询版本 - 避免 SSE 超时问题）
// ================================================================

let optimizeOutlineAjaxRunning = false;

/**
 * AJAX 轮询版本的优化大纲
 * 优点：完全避免 SSE 超时问题，更稳定可靠
 */
async function optimizeOutlineLogicAjax() {
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
    streamBox.textContent = '正在初始化优化任务...';
    batchLog.style.display = 'none';
    batchLog.innerHTML    = '';
    progressLabel.textContent = '正在分析大纲逻辑...';
    statsEl.textContent   = '';

    let totalUpdated = 0;
    let batchIndex = 0;
    let hasMore = true;

    optimizeOutlineAjaxRunning = true;

    try {
        // 从数据库获取已优化进度
        const lastOptimized = await fetchLastOptimized(novelId);
        if (lastOptimized > 0) {
            batchIndex = Math.floor(lastOptimized / 10);  // 每批10章
            progressLabel.textContent = `检测到已优化至第 ${lastOptimized} 章，从第 ${lastOptimized + 1} 章继续...`;
            showToast(`从第 ${lastOptimized + 1} 章继续优化`, 'info');
        } else {
            batchIndex = 0;
        }

        // 循环处理每一批
        while (hasMore && optimizeOutlineAjaxRunning) {
            progressLabel.textContent = `正在优化第 ${batchIndex * 10 + 1}～${(batchIndex + 1) * 10} 章大纲逻辑...`;

            // 调用 AJAX API 处理一批
            const response = await fetch('api/optimize_outline_ajax.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    novel_id: novelId,
                    batch_index: batchIndex,
                    start_from: lastOptimized
                })
            });

            if (!response.ok) {
                throw new Error(`服务器错误: ${response.status}`);
            }

            const result = await response.json();

            if (!result.success) {
                throw new Error(result.message || '优化失败');
            }

            // 更新进度显示
            const progress = result.progress;
            progressLabel.textContent = result.message;
            statsEl.textContent = `进度: ${progress.current}/${progress.total} 章 (${progress.percent}%)`;

            // 显示批次结果
            if (result.batch_result) {
                batchLog.style.display = '';
                const item = document.createElement('div');
                item.className = 'p-2 border-bottom border-secondary small';
                
                const changedCount = result.batch_result.changed ? result.batch_result.changed.length : 0;
                item.innerHTML = `<span class="text-success"><i class="bi bi-check-circle me-1"></i>第 ${result.batch_result.from}～${result.batch_result.to} 章优化完成，修改了 ${changedCount} 章</span>`;
                batchLog.appendChild(item);
                batchLog.scrollTop = batchLog.scrollHeight;
                
                totalUpdated += result.batch_result.updated || 0;
            }

            // 更新流显示区域
            streamBox.textContent = `已优化 ${progress.current}/${progress.total} 章 (${progress.percent}%)`;

            // 检查是否完成
            if (result.completed) {
                progressLabel.textContent = '所有章节优化完成！';
                statsEl.textContent = `共修改 ${totalUpdated} 章`;
                showToast('大纲逻辑优化完成，页面即将刷新', 'success');
                setTimeout(() => location.reload(), 2000);
                break;
            }

            // 继续下一批
            batchIndex = result.next_batch;
            hasMore = result.has_more;

            // 短暂延迟，避免请求过快
            if (hasMore) {
                await new Promise(resolve => setTimeout(resolve, 500));
            }
        }

    } catch (err) {
        console.error('优化大纲出错:', err);
        showToast('优化失败：' + err.message, 'error');
        progressLabel.textContent = '出错：' + err.message;
    } finally {
        btn.disabled = false;
        optimizeOutlineAjaxRunning = false;
    }
}

/**
 * 取消优化大纲（AJAX 版本）
 */
function cancelOptimizeOutlineAjax() {
    optimizeOutlineAjaxRunning = false;
    showToast('已取消优化大纲', 'info');
}

// 导出函数
window.optimizeOutlineLogicAjax = optimizeOutlineLogicAjax;
window.cancelOptimizeOutlineAjax = cancelOptimizeOutlineAjax;
