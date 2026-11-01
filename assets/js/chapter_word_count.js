// 章节页（chapter.php）：字数统计。由内联脚本原样外置。
// ================================================================
// 字数统计实时更新（编辑模式下）
// ================================================================
function countChapterWords(text) {
    text = String(text || '');
    // 与 includes/helpers.php::countWords() 保持一致：
    // 中文按单字计，非中文部分按英文单词计，忽略空白、标点和纯数字。
    var chinese = text.match(/[\u4e00-\u9fa5]/g);
    var nonChinese = text.replace(/[\u4e00-\u9fa5]/g, ' ');
    var english = nonChinese.match(/[A-Za-z]+(?:['-][A-Za-z]+)*/g);
    return (chinese ? chinese.length : 0) + (english ? english.length : 0);
}

window.updateWordCount = function updateWordCount() {
    var contentEl = document.getElementById('edit-content');
    var barEl     = document.getElementById('word-count-bar');
    var currentEl = document.getElementById('wc-current');
    if (!contentEl || !barEl || !currentEl) return;

    var text = contentEl.value || '';
    var wc = countChapterWords(text);
    currentEl.textContent = wc.toLocaleString();

    // 目标字数
    var target = CHAPTER_TARGET_WORDS;
    var overLimit = target > 0 && wc > target + 500;

    if (overLimit) {
        barEl.style.color = '#dc3545';  // 红色
        barEl.style.fontWeight = '700';
        barEl.title = '已超过目标字数 500 字以上';
    } else {
        barEl.style.color = '#28a745';  // 绿色
        barEl.style.fontWeight = '400';
        barEl.title = '字数正常';
    }
};

// 页面加载时初始化字数显示
(function initWordCount() {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', window.updateWordCount);
    } else {
        window.updateWordCount();
    }
})();
