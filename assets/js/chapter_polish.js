// 章节页（chapter.php）：质量检测与一键润色。由内联脚本原样外置。
// ========== Phase 4: 质量检测功能 ==========
async function runQualityCheck() {
    const bodyEl = document.getElementById('quality-body');
    const loadEl = document.getElementById('quality-loading');
    if (!bodyEl || !loadEl) return;

    // 显示 loading
    bodyEl.style.display = 'none';
    loadEl.style.display = '';

    try {
        const data = await apiPost('validate_consistency', {
            novel_id: NOVEL_ID,
            chapter_id: CHAPTER_ID
        });

        if (data.error) {
            bodyEl.innerHTML = '<div class="text-center text-danger py-3 small">' + escapeHtml(data.error) + '</div>';
            bodyEl.style.display = '';
            loadEl.style.display = 'none';
            return;
        }

        // 渲染结果
        const score = data.total_score ?? 0;
        const passColor = score >= 80 ? '#28a745' : (score >= 60 ? '#ffc107' : '#dc3545');
        const bgColor  = score >= 80 ? 'rgba(40,167,69,.2)' : (score >= 60 ? 'rgba(255,193,7,.2)' : 'rgba(220,53,69,.2)');

        let html = '';

        // 圆形评分
        html += '<div class="text-center mb-3">';
        html += '<div class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width:80px;height:80px;background:' + bgColor + '">';
        html += '<span style="font-size:1.8rem;font-weight:700;color:' + passColor + '">' + Math.round(score) + '</span>';
        html += '</div>';
        html += '<div class="text-muted small mt-1">综合评分 /100</div>';
        html += '</div>';

        // 五关详情
        if (data.gates && data.gates.length) {
            html += '<div id="gate-details">';
            data.gates.forEach(function(gate) {
                var gs = gate.score || 0;
                var ok  = !!gate.status;
                var bg  = ok ? 'rgba(40,167,69,.08)' : 'rgba(220,53,69,.08)';
                var clr = gs >= 70 ? '#28a745' : '#dc3545';
                html += '<div class="d-flex align-items-center mb-2 p-2 rounded" style="background:' + bg + '">';
                html += '<span class="me-2">' + (ok ? '&#x2705;' : '&#x26A0;&#xFE0F;') + '</span>';
                html += '<small class="flex-grow-1 text-truncate">' + escapeHtml(gate.name) + '</small>';
                html += '<strong class="ms-2 small" style="color:' + clr + '">' + gs + '</strong>';
                html += '<span class="text-muted small ms-1">分</span></div>';

                if (!ok && gate.issues && gate.issues.length) {
                    html += '<div class="small text-danger mb-2 ps-4" style="font-size:.7rem;line-height:1.4;">';
                    gate.issues.slice(0, 3).forEach(function(issue) {
                        html += '<div>' + escapeHtml(issue) + '</div>';
                    });
                    html += '</div>';
                }
            });
            html += '</div>';
        }

        // 汇总
        html += '<div class="text-muted small mt-2">';
        if (data.passes) {
            html += '<span class="text-success">✅ 全部通过：' + escapeHtml(data.summary || '') + '</span>';
        } else {
            html += '<span>' + escapeHtml(data.summary || '') + '</span>';
        }
        html += '</div>';

        bodyEl.innerHTML = html;
        bodyEl.style.display = '';
        loadEl.style.display = 'none';

    } catch(err) {
        bodyEl.innerHTML = '<div class="text-center text-danger py-3 small">检测失败：' + escapeHtml(err.message) + '</div>';
        bodyEl.style.display = '';
        loadEl.style.display = 'none';
    }
}

// 暴露到 window
window.runQualityCheck = runQualityCheck;

// ================================================================
// 写作/润色 Modal 关闭后自动刷新（确保编辑器内容同步）
// ================================================================
(function bindModalRefresh() {
    var modalEl = document.getElementById('rewriteModal');
    if (!modalEl) return;
    modalEl.addEventListener('hidden.bs.modal', function() {
        // 如果内容已更新（润色或写作完成），刷新页面
        if (window._contentUpdated && modalEl.dataset.operation !== 'quality-rewrite') {
            location.reload();
        }
    });
})();
window._contentUpdated = false;

// ================================================================
// 一键润色功能（流式 SSE + 质量检测反馈驱动）
// ================================================================
window.polishChapter = async function polishChapter(chapterId, qualityRewrite) {
    qualityRewrite = qualityRewrite === true;
    if (qualityRewrite) return window.ChapterQualityRewrite.start(Number(chapterId));
    if (window.ChapterQualityRewrite && window.ChapterQualityRewrite.blockIfUnsaved('AI 润色')) return;
    if (window.ChapterQualityRewrite && !window.ChapterQualityRewrite.prepareOtherOperation()) return;
    var contentEl = document.getElementById('edit-content');
    if (!contentEl || !contentEl.value.trim()) {
        alert('章节内容为空，无法' + (qualityRewrite ? '改写' : '润色'));
        return;
    }
    var confirmText = qualityRewrite
        ? '确认根据全部质量检测反馈改写当前章节吗？\n\n将处理结构、角色、描写、爽点、连贯、跟纲和落盘硬质检问题。\n改写结果会替换现有正文，原文已保留在版本历史中。'
        : '确认用 AI 润色当前章节吗？\n\n系统将先自动运行质量检测，根据⚠️问题定向润色。\n润色结果将替换现有内容（可通过版本历史回滚）。';
    if (!confirm(confirmText)) return;

    // 禁用当前操作按钮
    var btn = document.getElementById(qualityRewrite ? 'btn-quality-rewrite' : 'btn-polish');
    var origBtnHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>检测中...';
    }

    // ---- 第一步：自动运行质量检测 ----
    var qualityFeedback = '';
    var qualityScore = 0;
    try {
        var checkData = await apiPost('validate_consistency', {
            novel_id: NOVEL_ID,
            chapter_id: chapterId
        });

        if (checkData.gates && checkData.gates.length > 0) {
            qualityScore = checkData.total_score || 0;
            var feedbackLines = [];

            for (var g = 0; g < checkData.gates.length; g++) {
                var gate = checkData.gates[g];
                var gateName = gate.name || '未知';
                var passed = !!gate.status;
                var issues = gate.issues || [];

                if (!passed) {
                    // ⚠️ 未通过的关卡：所有 issues 都是改进项
                    for (var j = 0; j < issues.length; j++) {
                        feedbackLines.push('- [' + gateName + '] ' + issues[j]);
                    }
                } else {
                    // 通过但含⚠️的 issues
                    for (var j = 0; j < issues.length; j++) {
                        if (/⚠️|❌|偏低|过低|超长|偏长|未达标|过于平淡/.test(issues[j])) {
                            feedbackLines.push('- [' + gateName + '] ' + issues[j]);
                        }
                    }
                }
            }

            qualityFeedback = feedbackLines.join('\n');

            // 同步更新质量检测仪表盘 UI
            updateQualityDashboard(checkData);

            if (btn) btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + (qualityRewrite ? '改写中...' : '润色中...');
        }
    } catch(err) {
        // 质量检测失败不影响润色，继续走通用润色
        console.warn('质量检测失败，使用通用润色：', err);
        if (btn) btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + (qualityRewrite ? '改写中...' : '润色中...');
    }

    // ---- 第二步：打开写作 Modal 显示流式润色结果 ----
    var modalEl = document.getElementById('rewriteModal');
    if (!modalEl) { alert('缺少写作对话框'); return; }
    var modal = new bootstrap.Modal(modalEl);
    modal.show();

    // 修改 modal 标题为润色模式
    var modalTitle = modalEl.querySelector('.modal-title');
    var origTitle = modalTitle ? modalTitle.textContent : '';
    if (modalTitle) {
        modalTitle.textContent = qualityRewrite
            ? 'AI 一键改写中（全量质检反馈驱动）...'
            : (qualityFeedback ? 'AI 润色中（基于质量检测反馈）...' : 'AI 润色中...');
    }

    var streamContent = document.getElementById('rewriteContent');
    var spinnerEl     = document.getElementById('rewriteSpinner');
    var statsEl       = document.getElementById('rewriteStats');
    if (streamContent) streamContent.textContent = '';
    if (spinnerEl)     spinnerEl.style.display = '';
    if (statsEl)       statsEl.textContent = qualityFeedback
        ? '质量评分 ' + Math.round(qualityScore) + '/100，发现 ' + qualityFeedback.split('\n').length + ' 个改进项'
        : '';

    var _polishDone = false;
    var _polishFailed = false;

    try {
        var response = await fetch(apiRouteUrl('polish_chapter'), {
            method: 'POST',
            headers: {'Content-Type':'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || ''},
            body: JSON.stringify({
                chapter_id: chapterId,
                quality_feedback: qualityFeedback,
                mode: qualityRewrite ? 'quality_rewrite' : 'polish'
            })
        });
        if (!response.ok) {
            var errText = await response.text();
            throw new Error('HTTP ' + response.status + ': ' + errText.slice(0, 200));
        }
        if (!response.body) {
            throw new Error('浏览器不支持流式读取（ReadableStream）');
        }
        var reader  = response.body.getReader();
        var decoder = new TextDecoder();
        var fullText = '';
        var sseBuf2 = '';
        if (spinnerEl) spinnerEl.style.display = 'none';

        polishReadLoop: while (true) {
            var result = await reader.read();
            if (result.done) break;
            sseBuf2 += decoder.decode(result.value, { stream: true });
            var events2 = sseBuf2.split('\n\n');
            sseBuf2 = events2.pop();
            for (var ei2 = 0; ei2 < events2.length; ei2++) {
                var dataLine2 = '';
                var elines2 = events2[ei2].split('\n');
                for (var li2 = 0; li2 < elines2.length; li2++) {
                    if (elines2[li2].startsWith('data: ')) dataLine2 = elines2[li2];
                }
                if (!dataLine2) continue;
                var payload2 = dataLine2.slice(6).trim();
                if (payload2 === '[DONE]') { _polishDone = !_polishFailed; break polishReadLoop; }
                try {
                    var d = JSON.parse(payload2);
                    if (d.chunk && streamContent) {
                        fullText += d.chunk;
                        streamContent.textContent = fullText;
                        streamContent.scrollTop = streamContent.scrollHeight;
                    }
                    if (d.stats && statsEl) {
                        statsEl.textContent = d.stats;
                    }
                    if (d.quality_result) {
                        updateQualityDashboard({
                            total_score: d.after_score || 0,
                            gates: d.gates || [],
                            passes: !!d.passes,
                            summary: d.summary || ''
                        });
                        if (statsEl) {
                            var before = d.before_score == null ? '--' : Math.round(d.before_score);
                            statsEl.textContent = '复检完成：' + before + ' → ' + Math.round(d.after_score || 0) + ' 分';
                        }
                    }
                    if (d.warning && statsEl) {
                        statsEl.textContent = d.warning;
                    }
                    if (d.error) {
                        _polishFailed = true;
                        alert((qualityRewrite ? '改写' : '润色') + '失败：' + d.error);
                    }
                } catch(e) {}
            }
        }

        // 润色完成后提示用户
        if (_polishDone && fullText.trim()) {
            if (statsEl) statsEl.textContent += '（关闭对话框后将刷新页面）';
            window._contentUpdated = true;
        } else if (!_polishFailed) {
            throw new Error('响应提前结束，尚未确认正文保存；请刷新核对后再操作');
        }
    } catch(err) {
        if (streamContent) streamContent.textContent = (qualityRewrite ? '改写' : '润色') + '失败：' + err.message;
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = origBtnHtml; }
        if (spinnerEl) spinnerEl.style.display = 'none';
        // 恢复 modal 标题
        if (modalTitle) modalTitle.textContent = origTitle;
    }
};

/**
 * 同步更新质量检测仪表盘 UI（润色前自动检测后调用）
 */
function updateQualityDashboard(data) {
    var bodyEl = document.getElementById('quality-body');
    var loadEl = document.getElementById('quality-loading');
    if (!bodyEl) return;

    var score = data.total_score ?? 0;
    var passColor = score >= 80 ? '#28a745' : (score >= 60 ? '#ffc107' : '#dc3545');
    var bgColor  = score >= 80 ? 'rgba(40,167,69,.2)' : (score >= 60 ? 'rgba(255,193,7,.2)' : 'rgba(220,53,69,.2)');

    var html = '';

    // 圆形评分
    html += '<div class="text-center mb-3">';
    html += '<div class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width:80px;height:80px;background:' + bgColor + '">';
    html += '<span style="font-size:1.8rem;font-weight:700;color:' + passColor + '">' + Math.round(score) + '</span>';
    html += '</div>';
    html += '<div class="text-muted small mt-1">综合评分 /100</div>';
    html += '</div>';

    // 五关详情
    if (data.gates && data.gates.length) {
        html += '<div id="gate-details">';
        data.gates.forEach(function(gate) {
            var gs = gate.score || 0;
            var ok  = !!gate.status;
            var bg  = ok ? 'rgba(40,167,69,.08)' : 'rgba(220,53,69,.08)';
            var clr = gs >= 70 ? '#28a745' : '#dc3545';
            html += '<div class="d-flex align-items-center mb-2 p-2 rounded" style="background:' + bg + '">';
            html += '<span class="me-2">' + (ok ? '&#x2705;' : '&#x26A0;&#xFE0F;') + '</span>';
            html += '<small class="flex-grow-1 text-truncate">' + escapeHtml(gate.name) + '</small>';
            html += '<strong class="ms-2 small" style="color:' + clr + '">' + gs + '</strong>';
            html += '<span class="text-muted small ms-1">分</span></div>';

            if (!ok && gate.issues && gate.issues.length) {
                html += '<div class="small text-danger mb-2 ps-4" style="font-size:.7rem;line-height:1.4;">';
                gate.issues.slice(0, 3).forEach(function(issue) {
                    html += '<div>' + escapeHtml(issue) + '</div>';
                });
                html += '</div>';
            }
        });
        html += '</div>';
    }

    // 汇总
    html += '<div class="text-muted small mt-2">';
    if (data.passes) {
        html += '<span class="text-success">&#x2705; 全部通过：' + escapeHtml(data.summary || '') + '</span>';
    } else {
        html += '<span>' + escapeHtml(data.summary || '') + '</span>';
    }
    html += '</div>';

    bodyEl.innerHTML = html;
    bodyEl.style.display = '';
    if (loadEl) loadEl.style.display = 'none';
}
window.updateQualityDashboard = updateQualityDashboard;
