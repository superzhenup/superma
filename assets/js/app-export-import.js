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
    const url = `api/export_chapter_synopses.php?novel_id=${novelId}&format=${format}`;

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

    // 默认增量更新模式
    const importMode = 'incremental';

    // 创建表单数据
    const formData = new FormData();
    formData.append('novel_id', novelId);
    formData.append('file', file);
    formData.append('import_mode', importMode);

    try {
        showToast('正在导入...', 'info');

        const response = await fetch('api/import_chapter_synopses.php', {
            method: 'POST',
            body: formData
        });

        // 先获取响应文本
        const responseText = await response.text();
        console.log('API 响应:', responseText);
        
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
    document.getElementById('import-file-input').value = '';
}
