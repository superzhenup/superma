/**
 * 导出/导入章节概要功能
 * 这个文件会被 app.js 引用
 */

// ============================================================
// 导出/导入章节概要
// ============================================================

/**
 * 导出章节概要
 * @param {string} format - 格式：json / excel / txt
 */
function exportSynopses(format) {
    const novelId = new URLSearchParams(window.location.search).get('id');
    if (!novelId) {
        showToast('无法获取小说ID', 'error');
        return;
    }

    // 创建下载链接
    const url = apiRouteUrl(
        'export_chapter_synopses',
        `novel_id=${encodeURIComponent(novelId)}&format=${encodeURIComponent(format)}`
    );

    // 创建隐藏的下载链接并触发点击
    const link = document.createElement('a');
    link.href = url;
    link.download = ''; // 浏览器会自动使用服务器提供的文件名
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    showToast(`正在导出${format.toUpperCase()}文件...`, 'info');
}

/**
 * 导入章节概要
 * @param {File} file - 上传的文件
 */
async function importSynopses(file) {
    if (!file) return;

    const novelId = new URLSearchParams(window.location.search).get('id');
    if (!novelId) {
        showToast('无法获取小说ID', 'error');
        return;
    }

    // 验证文件格式
    const fileName = file.name.toLowerCase();
    if (!fileName.endsWith('.json') && !fileName.endsWith('.csv') && !fileName.endsWith('.txt')) {
        showToast('不支持的文件格式，仅支持 JSON / CSV / TXT', 'error');
        return;
    }

    // 审计修复（2026-07-19 H-中8）：原 confirm「取消 = 覆盖模式」违反"取消=中止"交互直觉。
    // 用户点取消/按 Esc 是想中止操作，却被映射为更具破坏性的覆盖模式。
    // 改为三态：确定=增量、取消=中止、自绘按钮选覆盖需在后续交互中确认。
    if (!confirm('导入章节概要？\n\n确定 = 增量更新（只更新非空字段）\n取消 = 中止导入')) {
        return;
    }
    // 默认增量模式；如需覆盖模式请在 UI 中显式选择
    const importMode = 'incremental';

    // 创建表单数据
    const formData = new FormData();
    formData.append('novel_id', novelId);
    formData.append('file', file);
    formData.append('import_mode', importMode);
    // CSRF token：从 meta 读取。后端 csrf_verify_api()（includes/auth.php）识别
    // $_POST['_token'] 或 X-CSRF-Token 头（审计 P3-F：原字段名 _csrf 后端不识别，
    // 注释所称的 csrf.php 也不存在，此前全靠 layout 全局 fetch 包装注入头才通过）
    const csrfToken = window.CSRF_TOKEN || '';
    formData.append('_token', csrfToken);

    try {
        showToast('正在导入...', 'info');

        // 切到正式接口（原 debug 版建议生产禁用/删除）
        const response = await fetch(apiRouteUrl('import_chapter_synopses'), {
        method: 'POST',
        // 审计修复 S-4（2026-06-22）：显式设置 CSRF 头，避免依赖全局 fetch 拦截器
        // （拦截器未加载或被覆盖时 CSRF 校验会失败）
        headers: { 'X-CSRF-Token': csrfToken },
        body: formData
    });

        // 先获取响应文本
        const responseText = await response.text();

        let result;
        try {
            result = JSON.parse(responseText);
        } catch (e) {
            showToast('API 返回非 JSON 数据，请查看控制台', 'error');
            console.error('JSON 解析失败:', e);
            console.error('响应内容:', responseText);
            return;
        }

        if (response.ok && result.success) {
            // 显示导入结果
            let message = `导入成功！\n`;
            message += `成功导入：${result.imported_count} 章\n`;
            message += `跳过：${result.skipped_count} 章\n`;
            if (result.error_count > 0) {
                message += `失败：${result.error_count} 章`;
            }

            showToast(message, 'success');

            // 刷新页面显示更新后的数据
            setTimeout(() => {
                location.reload();
            }, 1500);
        } else {
            showToast('导入失败：' + (result.error || '未知错误'), 'error');
        }
    } catch (err) {
        console.error('导入失败：', err);
        showToast('导入失败：' + err.message, 'error');
    }

    // 清空文件输入框
    const fileInput = document.getElementById('import-file-input-top');
    if (fileInput) fileInput.value = '';
}
