// 章节页（chapter.php）：选中文字优化。由内联脚本原样外置。
// ========== 选中文字优化功能 ==========
(function() {
    var contentEl = document.getElementById('edit-content');
    var barEl = document.getElementById('inline-edit-bar');
    var instructionEl = document.getElementById('inline-edit-instruction');
    var polishBtn = document.getElementById('btn-inline-polish');

    if (!contentEl || !barEl) return;

    var lastSelection = { start: 0, end: 0, text: '' };

    function getSelection() {
        var start = contentEl.selectionStart;
        var end = contentEl.selectionEnd;
        if (start === end) return null;
        var text = contentEl.value.substring(start, end);
        if (text.trim().length < 10) return null;
        return { start: start, end: end, text: text };
    }

    function getContext(sel, chars) {
        var before = contentEl.value.substring(Math.max(0, sel.start - chars), sel.start);
        var after = contentEl.value.substring(sel.end, Math.min(contentEl.value.length, sel.end + chars));
        return { before: before, after: after };
    }

    function calcBarPosition(sel) {
        var textBefore = contentEl.value.substring(0, sel.end);
        var textareaStyle = window.getComputedStyle(contentEl);
        var lineHeight = parseFloat(textareaStyle.lineHeight) || 20;
        var paddingTop = parseFloat(textareaStyle.paddingTop) || 0;

        var width = contentEl.clientWidth;
        var cols = contentEl.cols || 80;
        var charWidth = width / cols;

        var totalLines = 1;
        var lines = textBefore.split('\n');
        for (var i = 0; i < lines.length; i++) {
            var lineChars = lines[i].length;
            var wrappedLines = Math.max(1, Math.ceil(lineChars * charWidth / width));
            totalLines += (i === lines.length - 1) ? wrappedLines : 1;
        }

        return paddingTop + (totalLines * lineHeight);
    }

    function showBar() {
        var sel = getSelection();
        if (!sel) {
            sel = lastSelection;
            if (!sel || !sel.text) return;
            sel.start = lastSelection.start;
            sel.end = lastSelection.end;
        }
        lastSelection = sel;
        barEl.style.display = '';
        barEl.style.top = calcBarPosition(sel) + 'px';
        instructionEl.value = '';
    }

    function hideBar() {
        barEl.style.display = 'none';
        instructionEl.value = '';
        lastSelection = { start: 0, end: 0, text: '' };
    }

    window.hideInlineBar = hideBar;

    contentEl.addEventListener('mouseup', function() {
        setTimeout(function() {
            var sel = getSelection();
            if (sel) {
                showBar();
            } else {
                if (!lastSelection.text) return;
                if (document.activeElement !== contentEl && !barEl.contains(document.activeElement)) {
                    hideBar();
                }
            }
        }, 10);
    });

    contentEl.addEventListener('keyup', function(e) {
        if (e.key === 'Shift' || e.key === 'Control' || e.key === 'Alt' || e.key === 'Meta') return;
        var sel = getSelection();
        if (sel) {
            showBar();
        } else {
            if (!lastSelection.text) return;
            if (!barEl.contains(document.activeElement)) {
                hideBar();
            }
        }
    });

    instructionEl.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            polishSelection();
        }
        if (e.key === 'Escape') {
            hideBar();
            contentEl.focus();
        }
    });

    window.polishSelection = async function() {
        var sel = lastSelection;
        if (!sel || !sel.text) return;

        var instruction = instructionEl.value.trim();
        var context = getContext(sel, 200);

        var origBtnHtml = polishBtn.innerHTML;
        polishBtn.disabled = true;
        polishBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>优化中...';

        try {
            var data = await apiPost('polish_selection', {
                chapter_id: CHAPTER_ID,
                selected_text: sel.text,
                instruction: instruction,
                context_before: context.before,
                context_after: context.after
            });

            if (!data.success) {
                alert('优化失败：' + (data.error || '未知错误'));
                return;
            }

            var optimized = data.optimized_text;
            var full = contentEl.value;
            contentEl.value = full.substring(0, sel.start) + optimized + full.substring(sel.end);

            if (typeof updateWordCount === 'function') updateWordCount();

            hideBar();
            contentEl.focus();
            contentEl.setSelectionRange(sel.start, sel.start + optimized.length);

        } catch (err) {
            alert('网络错误：' + err.message);
        } finally {
            polishBtn.disabled = false;
            polishBtn.innerHTML = origBtnHtml;
        }
    };

    // 点击 textarea 其他位置：重新选中时更新，否则关闭
    contentEl.addEventListener('click', function() {
        if (lastSelection.text) {
            setTimeout(function() {
                var sel = getSelection();
                if (!sel) {
                    hideBar();
                } else if (sel.start !== lastSelection.start || sel.end !== lastSelection.end) {
                    showBar();
                }
            }, 10);
        }
    });

    // 点击工具栏外部关闭
    document.addEventListener('click', function(e) {
        if (barEl.style.display === 'none') return;
        if (!barEl.contains(e.target) && e.target !== contentEl) {
            hideBar();
        }
    });
})();
