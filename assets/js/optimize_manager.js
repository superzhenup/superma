/**
 * 优化大纲统一入口
 * 根据配置自动选择 SSE 或 AJAX 方案
 */

// 从服务器获取配置
let optimizeMode = 'ajax';  // 默认 AJAX 方案

/**
 * 初始化优化配置
 */
async function initOptimizeConfig() {
    try {
        const response = await fetch('api/get_optimize_config.php');
        const config = await response.json();
        optimizeMode = config.mode || 'ajax';
        console.log('优化方案:', optimizeMode);
    } catch (err) {
        console.warn('获取优化配置失败，使用默认 AJAX 方案');
        optimizeMode = 'ajax';
    }
}

/**
 * 统一优化大纲入口
 * 根据配置自动选择 SSE 或 AJAX 方案
 */
async function optimizeOutline() {
    if (optimizeMode === 'ajax') {
        // 使用 AJAX 轮询方案
        if (typeof optimizeOutlineLogicAjax === 'function') {
            return await optimizeOutlineLogicAjax();
        } else {
            console.error('AJAX 优化函数未加载，请确保已引入 optimize_outline_ajax.js');
            showToast('系统配置错误，请刷新页面重试', 'error');
        }
    } else {
        // 使用 SSE 流式方案
        if (typeof optimizeOutlineLogic === 'function') {
            return await optimizeOutlineLogic();
        } else {
            console.error('SSE 优化函数未加载');
            showToast('系统配置错误，请刷新页面重试', 'error');
        }
    }
}

/**
 * 取消优化大纲
 */
function cancelOptimizeOutline() {
    if (optimizeMode === 'ajax') {
        if (typeof cancelOptimizeOutlineAjax === 'function') {
            cancelOptimizeOutlineAjax();
        }
    } else {
        if (typeof optimizeOutlineController !== 'undefined' && optimizeOutlineController) {
            optimizeOutlineController.abort();
        }
    }
}

// 页面加载时初始化配置
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initOptimizeConfig);
} else {
    initOptimizeConfig();
}

// 导出函数
window.optimizeOutline = optimizeOutline;
window.cancelOptimizeOutline = cancelOptimizeOutline;
