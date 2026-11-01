// 章节页（chapter.php）：AI 压缩。由内联脚本原样外置。
// ================================================================
// AI压缩功能（流式 SSE，实时更新 textarea）
// ================================================================
window.compressChapter = async function compressChapter(chapterId) {
    if (window.ChapterQualityRewrite && window.ChapterQualityRewrite.blockIfUnsaved('AI 压缩')) return;
    if (window.ChapterQualityRewrite && !window.ChapterQualityRewrite.prepareOtherOperation()) return;
    var contentEl = document.getElementById('edit-content');
    if (!contentEl || !contentEl.value.trim()) {
        alert('章节内容为空，无法压缩');
        return;
    }

    var targetWords = CHAPTER_TARGET_WORDS;
    if (!confirm('确认用 AI 压缩当前章节到目标字数 ' + targetWords + ' 字吗？\n\n压缩结果将替换现有内容（可通过版本历史回滚）。')) return;
    // 压缩期间对话框可以关掉继续编辑；完成时编辑框若已变化，就不能再用压缩结果覆盖它。
    var startValue = contentEl.value;

    var btn = document.getElementById('btn-compress');
    var origBtnHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>压缩中...';
    }

    // 打开 Modal 显示流式预览
    var modalEl = document.getElementById('rewriteModal');
    if (!modalEl) { alert('缺少写作对话框'); return; }
    var modal = new bootstrap.Modal(modalEl);
    modal.show();

    var modalTitle = modalEl.querySelector('.modal-title');
    var origTitle = modalTitle ? modalTitle.textContent : '';
    if (modalTitle) modalTitle.textContent = 'AI 压缩中...';

    var streamContent = document.getElementById('rewriteContent');
    var spinnerEl     = document.getElementById('rewriteSpinner');
    var statsEl       = document.getElementById('rewriteStats');
    if (streamContent) streamContent.textContent = '';
    if (spinnerEl)     spinnerEl.style.display = '';
    if (statsEl)       statsEl.textContent = '目标字数：' + targetWords + ' 字，下方实时预览...';

    var _compressDone = false;
    // 审计整改 A2：只有收到本次请求的保存回执（saved + content_hash）才算已保存；
    // 编辑框和它的版本哈希只按回执更新，不再凭“收到了正文”推断保存成功。
    var savedReceipt = null;
    var finalHash = '';
    var warnings = [];
    var lastStats = '';
    var streamStarted = false;

    // 服务器已保存，但这一页不能直接显示结果（编辑框被改过 / 连接提前中断）时的统一提示。
    function reportSavedButNotShown(reason) {
        var tail = warnings.length ? '；' + warnings.join('；') : '';
        if (contentEl && contentEl.value !== startValue) {
            if (statsEl) statsEl.textContent = '压缩结果已保存到服务器，但压缩期间编辑框被修改过，未覆盖你的修改' + tail;
            alert('压缩结果已保存到服务器，但压缩期间编辑框被修改过，为避免覆盖没有替换编辑框内容。\n\n'
                + '请先复制你的修改，再刷新页面查看压缩结果（压缩前的原文在版本历史中）。');
        } else {
            if (statsEl) statsEl.textContent = '压缩结果已保存到服务器' + (reason ? '（' + reason + '）' : '') + tail + '，关闭对话框后将刷新页面查看';
            window._contentUpdated = true;
        }
    }

    try {
        console.log('[SSE-压缩] 开始连接 compress_chapter.php (chapter_id=' + chapterId + ', target=' + targetWords + ')');
        var response = await fetch(apiRouteUrl('compress_chapter'), {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || ''},
            body: JSON.stringify({
                chapter_id: chapterId,
                target_words: targetWords
            })
        });
        console.log('[SSE-压缩] HTTP 状态', response.status, response.ok);
        if (!response.ok) {
            var errText = await response.text();
            throw new Error('HTTP ' + response.status + ': ' + errText.slice(0, 200));
        }
        if (!response.body) {
            throw new Error('浏览器不支持流式读取（ReadableStream）');
        }
        var reader  = response.body.getReader();
        streamStarted = true;
        var decoder = new TextDecoder();
        var fullText = '';
        var sseBuffer = '';          // SSE 分片缓冲
        var firstEvent = false;
        var hasError = false;

        while (true) {
            var result = await reader.read();
            if (result.done) { console.log('[SSE-压缩] 流结束'); break; }
            sseBuffer += decoder.decode(result.value, { stream: true });

            // 按 SSE 标准双换行分割事件块
            var events = sseBuffer.split('\n\n');
            sseBuffer = events.pop(); // 最后一段可能不完整，留到下次
            for (var ei = 0; ei < events.length; ei++) {
                var eventBlock = events[ei];
                // 找 data: 行（一个事件块可能含多行，取最后一个 data:）
                var dataLine = '';
                var eventLines = eventBlock.split('\n');
                for (var li = 0; li < eventLines.length; li++) {
                    if (eventLines[li].startsWith('data: ')) dataLine = eventLines[li];
                }
                if (!dataLine) continue;
                var payload = dataLine.slice(6).trim();
                if (payload === '[DONE]') { console.log('[SSE-压缩] 收到 [DONE]'); if (!hasError) _compressDone = true; break; }
                try {
                    var d = JSON.parse(payload);
                    if (!firstEvent) { if (spinnerEl) spinnerEl.style.display = 'none'; firstEvent = true; }
                    if (d.error) {
                        console.error('[SSE-压缩] 服务端错误', d.error);
                        hasError = true;
                        if (statsEl) statsEl.textContent = '错误：' + d.error;
                        _compressDone = false;
                    }
                    if (d.saved && d.content_hash) {
                        savedReceipt = { hash: String(d.content_hash), words: Number(d.words) || 0 };
                        if (statsEl) statsEl.textContent = '压缩结果已保存（' + savedReceipt.words + ' 字），正在刷新记忆...';
                    }
                    if (d.warning) {
                        console.warn('[SSE-压缩] 警告:', d.warning);
                        warnings.push(String(d.warning));
                        if (statsEl) statsEl.textContent = '⚠️ ' + d.warning;
                    }
                    if (d.model_switch) {
                        console.log('[SSE-压缩] 模型切换:', d.to, d.reason);
                        // 服务端换模型时从空缓冲重来，预览也要清空，避免两个模型的片段拼在一起。
                        fullText = '';
                        if (streamContent) streamContent.textContent = '';
                        if (statsEl) statsEl.textContent = '🔄 切换到模型 ' + d.to + (d.reason ? ' (' + d.reason + ')' : '');
                    }
                    if (d.status === 'compressing' && statsEl) {
                        statsEl.textContent = '开始压缩：' + d.from_words + ' 字 → 目标 ' + d.target_words + ' 字...';
                    }
                    if (d.chunk && !hasError) {
                        fullText += d.chunk;
                    }
                    if (d.content && !hasError) {
                        // 结束事件：服务端已保存的完整正文及其版本哈希
                        fullText = d.content;
                        finalHash = String(d.content_hash || '');
                    }
                    // 实时同步到预览区
                    if (fullText && streamContent && !hasError) {
                        streamContent.textContent = fullText;
                        streamContent.scrollTop = streamContent.scrollHeight;
                    }
                    if (d.stats && statsEl && !hasError) {
                        lastStats = String(d.stats);
                        statsEl.textContent = d.stats;
                    }
                    if (d.version_saved) {
                        console.log('[SSE-压缩] 版本已备份 v' + d.version, d.words + '字');
                    }
                } catch(e) { console.warn('[SSE-压缩] JSON解析失败:', payload.slice(0, 100)); }
            }
        }

        var receiptMatches = savedReceipt && _compressDone && fullText.trim().length > 0 && finalHash === savedReceipt.hash;
        if (receiptMatches && contentEl && contentEl.value === startValue) {
            // 编辑框、未保存检测基线和版本哈希都与服务器刚保存的版本对齐。
            contentEl.value = fullText;
            contentEl.defaultValue = fullText;
            contentEl.dataset.contentHash = savedReceipt.hash;
            if (typeof updateWordCount === 'function') updateWordCount();
            if (statsEl) statsEl.textContent = (lastStats || '压缩完成') + '，已保存到服务器'
                + (warnings.length ? '；' + warnings.join('；') : '') + '（关闭对话框后将刷新页面）';
            if (modalTitle) modalTitle.textContent = '压缩完成';
            window._contentUpdated = true;
        } else if (savedReceipt) {
            reportSavedButNotShown(receiptMatches ? '' : '连接在完成前中断');
        } else if (!hasError) {
            // 没有保存回执：不能凭收到的正文推断已保存，也不改动编辑框。
            if (statsEl) statsEl.textContent = '没有收到保存回执，无法确认压缩结果是否已保存；编辑框未改动。'
                + '请刷新页面核对服务器上的正文（压缩前的原文在版本历史中）';
        }
    } catch(err) {
        console.error('[SSE-压缩] 连接异常:', err);
        if (spinnerEl) spinnerEl.style.display = 'none';
        if (savedReceipt) {
            reportSavedButNotShown('连接中断：' + err.message);
        } else if (streamContent) {
            streamContent.textContent = '压缩失败：' + err.message
                + (streamStarted ? '。连接在保存前后中断，无法确认是否已保存；编辑框未改动，请刷新页面核对' : '');
        }
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = origBtnHtml; }
    }
};
