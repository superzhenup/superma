// 章节页（chapter.php）：保存大纲。由内联脚本原样外置。
// ================================================================
// 保存大纲 / 关键情节点 / 结尾钩子
// ================================================================
(function bindSaveOutline() {
    const btn = document.getElementById('btn-save-outline');
    if (!btn) return;
    btn.addEventListener('click', async () => {
        const outlineEl = document.getElementById('outline-outline');
        const kpEl      = document.getElementById('outline-keypoints');
        const hookEl    = document.getElementById('outline-hook');
        if (!outlineEl || !kpEl || !hookEl) return;

        const origHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>保存中...';

        try {
            // 关键情节点：按行拆分，去空行去空格
            const keyPoints = kpEl.value.split('\n')
                .map(s => s.trim())
                .filter(s => s.length > 0);

            const data = await apiPost('actions', {
                action:      'save_chapter_outline',
                chapter_id:  CHAPTER_ID,
                outline:     outlineEl.value,
                key_points:  keyPoints,
                hook:        hookEl.value
            });
            if (data.ok) {
                btn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>已保存';
                btn.classList.remove('btn-primary');
                btn.classList.add('btn-success');
                setTimeout(() => {
                    btn.innerHTML = origHtml;
                    btn.classList.remove('btn-success');
                    btn.classList.add('btn-primary');
                    btn.disabled = false;
                }, 1500);
            } else {
                alert('保存失败：' + (data.msg || '未知错误'));
                btn.innerHTML = origHtml;
                btn.disabled = false;
            }
        } catch (err) {
            alert('保存失败：' + err.message);
            btn.innerHTML = origHtml;
            btn.disabled = false;
        }
    });
})();
