// 章节页（chapter.php）：版本历史、回滚与重写。由内联脚本原样外置。
let selectedVersionId = null;

// ========== 版本历史功能 ==========
async function showVersionHistory() {
    const data = await apiGet('chapter_versions', { chapter_id: CHAPTER_ID, action: 'list' });
    if (!data.ok) { alert(data.msg); return; }

    const listEl = document.getElementById('versionList');
    listEl.innerHTML = '';

    // 当前版本
    listEl.innerHTML += `
        <div class="list-group-item list-group-item-action bg-primary text-white border-secondary">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <strong>当前版本</strong>
                    <span class="badge bg-light text-dark ms-2">${data.data.chapter.current_words}字</span>
                </div>
                <small class="text-white-50">最新</small>
            </div>
            <small class="text-white-50">${escapeHtml(data.data.chapter.current_content)}</small>
        </div>
    `;

    // 历史版本
    if (data.data.versions.length === 0) {
        listEl.innerHTML += '<div class="text-center text-muted py-3">暂无历史版本</div>';
    } else {
        data.data.versions.forEach(v => {
            listEl.innerHTML += `
                <div class="list-group-item list-group-item-action bg-dark text-light border-secondary" 
                     style="cursor:pointer" data-csp-click="chapter.preview-version" data-version-id="${Number(v.id)}">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong>v${v.version}</strong>
                            <span class="badge bg-secondary ms-2">${v.words}字</span>
                        </div>
                        <small class="text-muted">${v.created_at}</small>
                    </div>
                </div>
            `;
        });
    }

    const modal = new bootstrap.Modal(document.getElementById('versionModal'));
    modal.show();
}

async function previewVersion(versionId) {
    const data = await apiGet('chapter_versions', {
        chapter_id: CHAPTER_ID,
        action: 'preview',
        version_id: versionId
    });
    if (!data.ok) { alert(data.msg); return; }

    document.getElementById('versionPreviewContent').textContent = data.data.content;
    selectedVersionId = versionId;

    const modal = new bootstrap.Modal(document.getElementById('versionPreviewModal'));
    modal.show();
}

// 版本历史函数也暴露到 window，供 inline onclick 使用
window.showVersionHistory = showVersionHistory;
window.previewVersion     = previewVersion;

// btnRollback 绑定（容错）
(function bindRollback() {
    const btn = document.getElementById('btnRollback');
    if (!btn) return;
    btn.addEventListener('click', async () => {
        if (!selectedVersionId) return;
        if (window.ChapterQualityRewrite && window.ChapterQualityRewrite.blockIfUnsaved('回滚')) return;
        if (!confirm('确定要回滚到此版本吗？当前内容将被备份为新版本。')) return;

        try {
            const data = await apiPost('chapter_versions', {
                chapter_id: CHAPTER_ID,
                action: 'rollback',
                version_id: selectedVersionId
            });
            if (data.ok) {
                alert('回滚成功');
                location.reload();
            } else {
                alert('回滚失败：' + data.msg);
            }
        } catch (err) {
            alert('回滚失败：' + err.message);
        }
    });
})();

// Rewrite button（开始写作 / 重新写作）
(function bindRewrite() {
    document.querySelectorAll('#btn-rewrite').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (window.ChapterQualityRewrite && window.ChapterQualityRewrite.blockIfUnsaved('重新写作')) return;
            if (window.ChapterQualityRewrite && !window.ChapterQualityRewrite.prepareOtherOperation()) return;
            const modalEl = document.getElementById('rewriteModal');
            if (!modalEl) { alert('缺少写作对话框'); return; }
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
            const contentEl = document.getElementById('rewriteContent');
            const spinnerEl = document.getElementById('rewriteSpinner');
            const statsEl   = document.getElementById('rewriteStats');
            if (contentEl) contentEl.textContent = '正在初始化写作引擎...';
            if (spinnerEl) spinnerEl.style.display = '';
            if (statsEl)   statsEl.textContent = '';

            try {
                if (typeof streamWriteChapter !== 'function') {
                    throw new Error('写作客户端未加载，请刷新页面后重试');
                }
                if (contentEl) contentEl.textContent = '';
                const cursorEl = document.createElement('span');
                cursorEl.className = 'outline-stream-cursor';
                if (contentEl) contentEl.appendChild(cursorEl);
                const generated = await streamWriteChapter(
                    NOVEL_ID,
                    CHAPTER_ID,
                    (statsText) => {
                        if (statsEl) statsEl.textContent = statsText;
                        if (spinnerEl) spinnerEl.style.display = 'none';
                    },
                    contentEl,
                    cursorEl
                );
                if (generated && generated.length > 0) {
                    window._contentUpdated = true;
                } else if (contentEl) {
                    contentEl.textContent = '⚠️ 未收到生成内容，请检查 AI 模型配置和 API 连接，然后重试。';
                    if (statsEl) statsEl.textContent = '';
                }
            } catch(err) {
                console.error('[SSE-写入] 连接异常:', err);
                if (contentEl) contentEl.textContent = '写作失败：' + err.message;
                if (spinnerEl) spinnerEl.style.display = 'none';
            }
        });
    });
})();
