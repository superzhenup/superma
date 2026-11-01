// 章节页（chapter.php）：重新生成大纲。由内联脚本原样外置。
// ================================================================
// 重新生成功能：AI 重新生成本章「大纲概要 / 关键情节点 / 结尾钩子」并填入文本框，
// 由用户检查后点「保存大纲」落库（非破坏性，保存前可改回）。
// ================================================================
(function bindRegenerate() {
    // 不能用 btn-regenerate：app.js 会把小说页“重新生成整章正文”的处理器绑到这个 id 上。
    var btn = document.getElementById('btn-regenerate-outline');
    if (!btn) return;

    btn.addEventListener('click', async function() {
        var outlineEl = document.getElementById('outline-outline');
        var kpEl      = document.getElementById('outline-keypoints');
        var hookEl    = document.getElementById('outline-hook');
        if (!outlineEl || !kpEl || !hookEl) return;

        // 仅当已有内容时确认，避免误覆盖未保存的手动编辑
        var hasContent = outlineEl.value.trim() || kpEl.value.trim() || hookEl.value.trim();
        if (hasContent && !confirm('重新生成将替换上方「大纲概要 / 关键情节点 / 结尾钩子」的当前内容（保存前可改回）。确定？')) return;

        var origHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>生成中...';

        try {
            var data = await apiPost('actions', {
                action: 'regenerate_chapter_outline',
                chapter_id: CHAPTER_ID
            });

            if (!data.ok) {
                var emsg = data.error || data.msg || '未知错误';
                if (typeof showToast === 'function') showToast('生成失败：' + emsg, 'error');
                else alert('生成失败：' + emsg);
                return;
            }

            var d = data.data || {};
            outlineEl.value = d.outline || '';
            kpEl.value      = Array.isArray(d.key_points) ? d.key_points.join('\n') : '';
            hookEl.value    = d.hook || '';

            if (typeof showToast === 'function') showToast('大纲已重新生成，请检查后点击「保存大纲」', 'success');
        } catch(err) {
            console.error('[重新生成大纲] 失败:', err);
            if (typeof showToast === 'function') showToast('重新生成失败：' + err.message, 'error');
            else alert('重新生成失败：' + err.message);
        } finally {
            btn.innerHTML = origHtml;
            btn.disabled = false;
        }
    });
})();
