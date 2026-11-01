// 章节页（chapter.php）：编辑模式、保存与标题优化。由内联脚本原样外置。
// HTML转义函数（防止XSS）
function escapeHtml(text) {
    if (!text) return '';
    return String(text).replace(/[&<>"']/g, m => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[m]));
}

// ================================================================
// 最优先：直接在 window 上定义 toggleEdit/saveChapter
// 确保即使后续代码出错，委托事件仍能找到这两个函数
// ================================================================
window.toggleEdit = function toggleEdit(forceEdit) {
    var r = document.getElementById('read-mode');
    var e = document.getElementById('edit-mode');
    if (!r || !e) return;
    var shouldEdit = forceEdit === true || r.style.display !== 'none';
    if (shouldEdit) {
        r.style.display = 'none';
        e.style.display = '';
    } else {
        r.style.display = '';
        e.style.display = 'none';
    }
};

window.saveChapter = async function saveChapter(chapterId) {
    var titleEl   = document.getElementById('edit-title');
    var contentEl = document.getElementById('edit-content');
    if (!titleEl || !contentEl) return;
    // 这里只保存标题和正文，成功后整页刷新：大纲栏未保存的修改会随刷新丢失，先问一句。
    var pendingOutline = window.ChapterQualityRewrite
        ? window.ChapterQualityRewrite.unsavedFields().filter(function(id) { return id.indexOf('outline-') === 0; })
        : [];
    if (pendingOutline.length && !confirm('大纲概要 / 关键情节点 / 结尾钩子有未保存的修改。保存正文后页面会刷新，这些修改会丢失。\n\n仍要继续保存正文吗？')) return;
    try {
        var data = await apiPost('actions', {
            action: 'save_chapter',
            chapter_id: chapterId,
            title: titleEl.value,
            content: contentEl.value,
            expected_content_hash: contentEl.dataset.contentHash || ''
        });
        if (data.ok) {
            location.reload();
        } else {
            alert('保存失败：' + (data.msg || '未知错误'));
        }
    } catch (err) {
        alert('保存失败：' + err.message);
    }
};

window.optimizeChapterTitle = async function optimizeChapterTitle(chapterId) {
    var titleEl = document.getElementById('edit-title');
    var outlineEl = document.getElementById('outline-outline');
    var keyPointsEl = document.getElementById('outline-keypoints');
    var hookEl = document.getElementById('outline-hook');
    var btn = document.getElementById('btn-optimize-title');

    if (!titleEl) return;

    var payload = {
        chapter_id: chapterId,
        current_title: titleEl.value || '',
        outline: outlineEl ? outlineEl.value : '',
        key_points: keyPointsEl ? keyPointsEl.value : '',
        hook: hookEl ? hookEl.value : ''
    };

    if (!payload.current_title.trim() && !payload.outline.trim() && !payload.key_points.trim() && !payload.hook.trim()) {
        alert('请先填写原标题或本章大纲信息');
        return;
    }

    var originalHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>优化中...';
    }

    try {
        var data = await apiPost('optimize_chapter_title', payload);
        if (!data.ok) {
            throw new Error(data.msg || data.error || '标题优化失败');
        }

        var title = (data.data && data.data.title) ? data.data.title : '';
        if (!title) {
            throw new Error('AI没有返回可用标题');
        }

        titleEl.value = title;
        var displayEl = document.getElementById('chapter-title-display');
        if (displayEl) {
            displayEl.textContent = title;
        }
        titleEl.focus();
        titleEl.select();
    } catch (err) {
        alert('标题优化失败：' + err.message);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    }
};

// URL 参数 ?edit=1 时自动进入编辑模式
(function autoEnterEditMode() {
    try {
        var params = new URLSearchParams(window.location.search);
        if (params.get('edit') !== '1') return;
        var run = function() {
            var r = document.getElementById('read-mode');
            var e = document.getElementById('edit-mode');
            if (r && e) {
                r.style.display = 'none';
                e.style.display = '';
                e.scrollIntoView({ behavior: 'smooth', block: 'start' });
                var c = document.getElementById('edit-content');
                if (c) c.focus();
            }
        };
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', run);
        } else {
            run();
        }
    } catch (err) {}
})();
