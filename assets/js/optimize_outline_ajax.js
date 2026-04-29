// ================================================================
// 优化大纲逻辑（AJAX 轮询版本 - 避免 SSE 超时问题）
// ================================================================

let optimizeOutlineAjaxRunning = false;

// 单批次最大重试次数（网络错误）
const BATCH_MAX_RETRIES = 3;
// 重试基础等待时间（毫秒），指数增长
const BATCH_RETRY_BASE_DELAY = 3000;

/**
 * 调用 AJAX API 处理一批章节（带自动重试）
 * @returns {object|null} API 返回结果，失败返回 null
 */
async function processBatch(novelId, batchIndex, lastOptimized, progressLabel, batchLog, streamBox) {
    for (let retry = 0; retry <= BATCH_MAX_RETRIES; retry++) {
        if (!optimizeOutlineAjaxRunning) return null;

        // 重试时等待（指数退避：3s, 6s, 12s）
        if (retry > 0) {
            const delay = BATCH_RETRY_BASE_DELAY * Math.pow(2, retry - 1);
            progressLabel.textContent = `第 ${batchIndex * 10 + 1}～${(batchIndex + 1) * 10} 章网络错误，${delay / 1000}秒后第 ${retry} 次重试...`;
            await new Promise(resolve => setTimeout(resolve, delay));
            if (!optimizeOutlineAjaxRunning) return null;
        }

        try {
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
                // HTTP 错误（如 502/503），可重试
                if (retry < BATCH_MAX_RETRIES) continue;
                return null;
            }

            const result = await response.json();

            if (result.success) {
                return result;  // 成功
            }

            // 服务端返回失败
            if (result.retryable && retry < BATCH_MAX_RETRIES) {
                console.warn(`批次 ${batchIndex} 网络错误，准备重试:`, result.message);
                continue;  // 可重试的网络错误，等待后重试
            }

            // 不可重试的错误（如 AI 返回格式错误），或重试已耗尽
            if (batchLog) {
                batchLog.style.display = '';
                const item = document.createElement('div');
                item.className = 'p-2 border-bottom border-secondary small';
                const from = result.batch_from || (batchIndex * 10 + 1);
                const to   = result.batch_to   || ((batchIndex + 1) * 10);
                const icon = result.retryable ? 'bi-exclamation-triangle' : 'bi-x-circle';
                const color = result.retryable ? 'text-warning' : 'text-danger';
                item.innerHTML = `<span class="${color}"><i class="bi ${icon} me-1"></i>第 ${from}～${to} 章跳过（${result.message}）</span>`;
                batchLog.appendChild(item);
                batchLog.scrollTop = batchLog.scrollHeight;
            }
            return null;

        } catch (fetchErr) {
            // 网络层面的异常（fetch 本身失败）
            if (retry < BATCH_MAX_RETRIES) {
                console.warn(`批次 ${batchIndex} fetch 失败，准备重试:`, fetchErr.message);
                continue;
            }
            if (batchLog) {
                batchLog.style.display = '';
                const item = document.createElement('div');
                item.className = 'p-2 border-bottom border-secondary small';
                item.innerHTML = `<span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>第 ${batchIndex * 10 + 1}～${(batchIndex + 1) * 10} 章跳过（网络错误：${fetchErr.message}）</span>`;
                batchLog.appendChild(item);
                batchLog.scrollTop = batchLog.scrollHeight;
            }
            return null;
        }
    }
    return null;
}

/**
 * AJAX 轮询版本的优化大纲
 * 优点：单批次失败自动重试/跳过，不会中断整体流程
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
    let totalSkipped = 0;
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
        }

        // 循环处理每一批
        while (hasMore && optimizeOutlineAjaxRunning) {
            progressLabel.textContent = `正在优化第 ${batchIndex * 10 + 1}～${(batchIndex + 1) * 10} 章大纲逻辑...`;

            const result = await processBatch(novelId, batchIndex, lastOptimized, progressLabel, batchLog, streamBox);

            if (!result) {
                // 批次失败（已自动重试耗尽），跳过继续下一批
                totalSkipped++;
                batchIndex++;
                // 检查是否还有更多批次
                // 需要查询 API 确认，暂时递增
                streamBox.textContent = `已跳过 ${totalSkipped} 个批次，继续处理...`;

                // 短暂延迟后继续
                await new Promise(resolve => setTimeout(resolve, 2000));
                continue;
            }

            // 批次成功
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
                const summary = totalSkipped > 0 
                    ? `大纲逻辑优化完成！共修改 ${totalUpdated} 章，跳过 ${totalSkipped} 个批次（可稍后重新优化）`
                    : `所有章节优化完成！共修改 ${totalUpdated} 章`;
                progressLabel.textContent = summary;
                statsEl.textContent = totalSkipped > 0 ? `成功 ${totalUpdated} 章，跳过 ${totalSkipped} 个批次` : `共修改 ${totalUpdated} 章`;
                showToast(totalSkipped > 0 ? summary : '大纲逻辑优化完成，页面即将刷新', totalSkipped > 0 ? 'warning' : 'success');
                setTimeout(() => location.reload(), 3000);
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

