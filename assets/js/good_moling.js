(function(){
  'use strict';

  var busy = false;
  var sseController = null;

  function getCsrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
  }

  function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function renderMarkdownLite(text) {
    var html = escHtml(text);
    html = html.replace(/&lt;&lt;&lt;REWRITE&gt;&gt;&gt;/g, '<div class="gm-patch-start">📝 改写内容</div>');
    html = html.replace(/&lt;&lt;&lt;END&gt;&gt;&gt;/g, '');
    html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    html = html.replace(/^### (.+)$/gm, '<h6 class="text-info mt-2 mb-1">$1</h6>');
    html = html.replace(/^## (.+)$/gm, '<h6 class="text-warning mt-2 mb-1">$1</h6>');
    html = html.replace(/^# (.+)$/gm, '<h6 class="text-primary mt-2 mb-1">$1</h6>');
    html = html.replace(/^- (.+)$/gm, '<div class="ps-2">• $1</div>');
    html = html.replace(/^\d+\. (.+)$/gm, '<div class="ps-2">$1</div>');
    html = html.replace(/\n/g, '<br>');
    return html;
  }

  function getChapterContent() {
    var el = document.getElementById('edit-content');
    if (el) return el.value || '';
    var detailContent = document.getElementById('detail-content');
    if (detailContent) return detailContent.textContent || '';
    return '';
  }

  function getChapterTitle() {
    var el = document.getElementById('edit-title');
    if (el) return el.value || '';
    var detailTitle = document.getElementById('detail-title');
    if (detailTitle) return detailTitle.value || '';
    return '';
  }

  function getChapterId() {
    return window.GM_CHAPTER_ID || 0;
  }

  function getNovelId() {
    return window.GM_NOVEL_ID || 0;
  }

  function getModelId() {
    var sel = document.getElementById('gm-model-select');
    if (sel) {
      var v = parseInt(sel.value);
      return v > 0 ? v : null;
    }
    var novelSel = document.getElementById('model-select');
    if (novelSel) {
      var v2 = parseInt(novelSel.value);
      return v2 > 0 ? v2 : null;
    }
    return null;
  }

  function getEditorSelection() {
    var el = document.getElementById('edit-content');
    if (el) {
      var start = el.selectionStart;
      var end = el.selectionEnd;
      if (typeof start === 'number' && typeof end === 'number' && end > start) {
        return el.value.substring(start, end);
      }
    }
    var sel = window.getSelection?.().toString() || '';
    return sel.trim();
  }

  function scrollChatBottom() {
    var list = document.getElementById('gm-chat-list');
    if (list) list.scrollTop = list.scrollHeight;
  }

  function showEmpty(show) {
    var el = document.getElementById('gm-chat-empty');
    if (el) el.style.display = show ? '' : 'none';
  }

  function setStatus(text, isError) {
    var el = document.getElementById('gm-status');
    if (!el) return;
    el.textContent = text;
    el.className = 'small ' + (isError ? 'text-danger' : 'text-muted');
  }

  function appendMessage(role, content) {
    var list = document.getElementById('gm-chat-list');
    if (!list) return { bubble: null, wrap: null };
    showEmpty(false);

    var wrap = document.createElement('div');
    wrap.className = 'gm-msg ' + (role === 'user' ? 'gm-msg-user' : 'gm-msg-ai');

    var bubble = document.createElement('div');
    bubble.className = 'gm-bubble ' + (role === 'user' ? 'gm-bubble-user' : 'gm-bubble-ai');

    if (role === 'user') {
      bubble.textContent = content;
    } else {
      bubble.innerHTML = content ? renderMarkdownLite(content) : '';
    }

    wrap.appendChild(bubble);

    if (role === 'assistant' && content) {
      var actions = document.createElement('div');
      actions.className = 'gm-msg-actions';

      var copyBtn = document.createElement('button');
      copyBtn.className = 'btn btn-sm btn-outline-secondary gm-copy-btn';
      copyBtn.innerHTML = '<i class="bi bi-clipboard"></i> 复制';
      copyBtn.onclick = function() {
        var rawText = content;
        rawText = rawText.replace(/<<<REWRITE>>>/g, '');
        rawText = rawText.replace(/<<<END>>>/g, '');
        navigator.clipboard.writeText(rawText).then(function() {
          copyBtn.innerHTML = '<i class="bi bi-check"></i> 已复制';
          setTimeout(function() { copyBtn.innerHTML = '<i class="bi bi-clipboard"></i> 复制'; }, 1500);
        });
      };
      actions.appendChild(copyBtn);

      var hasRewriteTag = content.indexOf('<<<REWRITE>>>') !== -1;

      var applyBtn = document.createElement('button');
      applyBtn.className = 'btn btn-sm ' + (hasRewriteTag ? 'btn-outline-warning' : 'btn-outline-info') + ' gm-apply-btn';
      applyBtn.innerHTML = hasRewriteTag
        ? '<i class="bi bi-pencil-square"></i> 应用到正文'
        : '<i class="bi bi-plus-circle"></i> 追加到正文';
      applyBtn.onclick = function() {
        if (hasRewriteTag) {
          applyPatch(content);
        } else {
          applyRawContent(content);
        }
      };
      actions.appendChild(applyBtn);

      wrap.appendChild(actions);
    }

    list.appendChild(wrap);
    scrollChatBottom();
    return { bubble: bubble, wrap: wrap };
  }

  function applyPatch(aiContent) {
    var match = aiContent.match(/<<<REWRITE>>>([\s\S]*?)<<<END>>>/);
    if (!match) {
      alert('未找到可应用的改写内容');
      return;
    }

    var patchText = match[1].trim();
    if (!patchText) {
      alert('改写内容为空');
      return;
    }

    var el = document.getElementById('edit-content');
    if (!el) {
      navigator.clipboard.writeText(patchText);
      alert('当前页面没有编辑器，内容已复制到剪贴板');
      return;
    }

    var currentContent = el.value;
    var patchLen = patchText.length;
    var contentLen = currentContent.length;
    var similarity = contentLen > 0 ? computeSimilarity(currentContent, patchText) : 0;

    var start = el.selectionStart;
    var end = el.selectionEnd;
    var hasSelection = typeof start === 'number' && typeof end === 'number' && end > start;

    if (hasSelection) {
      if (!confirm('将替换当前选中的文本（' + (end - start) + '字 → ' + patchLen + '字），是否继续？')) return;
      el.value = currentContent.substring(0, start) + patchText + currentContent.substring(end);
      el.selectionStart = start;
      el.selectionEnd = start + patchLen;
    } else if (similarity > 0.4 && patchLen > contentLen * 0.5) {
      if (!confirm('检测到改写内容与正文高度相似（相似度' + Math.round(similarity * 100) + '%），将替换整章正文（' + contentLen + '字 → ' + patchLen + '字），是否继续？')) return;
      el.value = patchText;
      el.selectionStart = 0;
      el.selectionEnd = patchLen;
    } else {
      if (!confirm('将内容追加到正文末尾（+' + patchLen + '字），是否继续？')) return;
      var separator = currentContent && !currentContent.endsWith('\n') ? '\n' : '';
      el.value = currentContent + separator + patchText;
      el.scrollTop = el.scrollHeight;
    }

    el.dispatchEvent(new Event('input'));
    if (typeof updateWordCount === 'function') updateWordCount();

    if (typeof toggleEdit === 'function') toggleEdit(true);

    var toast = document.createElement('div');
    toast.className = 'position-fixed bottom-0 end-0 m-3 alert alert-success';
    toast.style.zIndex = '9999';
    toast.innerHTML = '<i class="bi bi-check-circle me-1"></i>已应用到正文';
    document.body.appendChild(toast);
    setTimeout(function() { toast.remove(); }, 2000);
  }

  function computeSimilarity(a, b) {
    if (!a || !b) return 0;
    var aSub = a.length > 500 ? a.substring(0, 500) : a;
    var bSub = b.length > 500 ? b.substring(0, 500) : b;
    var aWords = aSub.split(/[\s，。！？、；：""''（）\n]+/);
    var bWords = bSub.split(/[\s，。！？、；：""''（）\n]+/);
    var bSet = {};
    for (var i = 0; i < bWords.length; i++) { if (bWords[i]) bSet[bWords[i]] = true; }
    var common = 0;
    for (var j = 0; j < aWords.length; j++) { if (aWords[j] && bSet[aWords[j]]) common++; }
    return (2 * common) / (aWords.length + bWords.length);
  }

  function send(actionType) {
    if (busy) { setStatus('请等待上一条回复完成', true); return; }

    var inputEl = document.getElementById('gm-chat-input');
    var message = inputEl ? inputEl.value.trim() : '';
    if (!message && !actionType) { inputEl && inputEl.focus(); return; }

    var chapterContent = getChapterContent();
    if (!actionType || actionType === 'general_chat') {
      // ok
    } else if (!chapterContent && ['analyze_chapter','polish_chapter','continue_write',
        'strengthen_conflict','check_logic','optimize_character','extract_highlights',
        'generate_title','suggest_revision','rewrite'].indexOf(actionType) !== -1) {
      setStatus('请先输入章节内容', true);
      return;
    }

    busy = true;
    var sendBtn = document.getElementById('gm-send-btn');
    if (sendBtn) sendBtn.disabled = true;
    setStatus('Good Moling 思考中…');

    var presetLabels = {
      analyze_chapter: '【分析章节】', polish_chapter: '【优化文风】',
      continue_write: '【续写一段】', generate_outline: '【生成大纲】',
      strengthen_conflict: '【加强冲突】', check_logic: '【检查逻辑】',
      optimize_character: '【优化角色】', extract_highlights: '【提炼爽点】',
      generate_title: '【生成标题】', suggest_revision: '【修改建议】',
      rewrite: '【改写选段】'
    };

    var userText = message || presetLabels[actionType] || '【指令】';
    appendMessage('user', userText);
    if (inputEl) inputEl.value = '';

    var result = appendMessage('assistant', '');
    var aiBubble = result.bubble;
    if (aiBubble) {
      aiBubble.innerHTML = '<span class="gm-thinking"><span class="gm-dots"><span></span><span></span><span></span></span><span class="gm-thinking-label">Good Moling 正在思考…</span></span>';
    }

    var accumulated = '';
    var hasError = false;

    var body = JSON.stringify({
      chapter_id: getChapterId(),
      message: message,
      action_type: actionType || 'general_chat',
      model_id: getModelId(),
      context: { content: true, outline: true },
      selection: getEditorSelection()
    });

    sseController = new AbortController();

    fetch('/api/index.php?route=good_moling&action=chat', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': getCsrf(),
        'Accept': 'text/event-stream'
      },
      body: body,
      signal: sseController.signal
    }).then(function(resp) {
      if (!resp.ok) {
        return resp.text().then(function(t) {
          throw new Error('HTTP ' + resp.status + ': ' + t.substring(0, 200));
        });
      }

      var reader = resp.body.getReader();
      var decoder = new TextDecoder();
      var buffer = '';

      function processChunk() {
        return reader.read().then(function(result) {
          if (result.done) {
            if (accumulated) {
              aiBubble.innerHTML = renderMarkdownLite(accumulated);
              addBubbleActions(aiBubble, accumulated);
            }
            return;
          }

          buffer += decoder.decode(result.value, { stream: true });
          var lines = buffer.split('\n');
          buffer = lines.pop();

          for (var i = 0; i < lines.length; i++) {
            var line = lines[i].trim();
            if (line === '' || line.charAt(0) === ':') continue;

            if (line.indexOf('data: ') === 0) {
              var jsonStr = line.substring(6);
              try {
                var data = JSON.parse(jsonStr);

                if (data.error) {
                  hasError = true;
                  aiBubble.innerHTML = renderMarkdownLite(accumulated) +
                    '<div class="text-danger small mt-1">⚠ ' + escHtml(data.error) + '</div>';
                } else if (data.done) {
                  if (accumulated) {
                    aiBubble.innerHTML = renderMarkdownLite(accumulated);
                    addBubbleActions(aiBubble, accumulated);
                  }
                  setStatus(hasError ? 'AI 异常结束' : '完成');
                } else if (data.text) {
                  accumulated += data.text;
                  aiBubble.innerHTML = renderMarkdownLite(accumulated);
                  scrollChatBottom();
                }
              } catch (e) {
                // skip
              }
            }
          }

          return processChunk();
        });
      }

      return processChunk();
    }).catch(function(e) {
      if (e.name === 'AbortError') {
        setStatus('已停止');
        if (accumulated) {
          aiBubble.innerHTML = renderMarkdownLite(accumulated);
          addBubbleActions(aiBubble, accumulated);
        }
        return;
      }
      hasError = true;
      aiBubble.innerHTML += '<div class="text-danger small mt-1">⚠ 错误：' + escHtml(e.message) + '</div>';
      setStatus('失败', true);
    }).finally(function() {
      busy = false;
      if (sendBtn) sendBtn.disabled = false;
      sseController = null;
      if (!hasError && !accumulated) {
        setStatus('就绪');
      }
    });
  }

  function addBubbleActions(bubble, content) {
    var wrap = bubble.parentElement;
    if (!wrap || wrap.querySelector('.gm-msg-actions')) return;

    var actions = document.createElement('div');
    actions.className = 'gm-msg-actions';

    var copyBtn = document.createElement('button');
    copyBtn.className = 'btn btn-sm btn-outline-secondary gm-copy-btn';
    copyBtn.innerHTML = '<i class="bi bi-clipboard"></i> 复制';
    copyBtn.onclick = function() {
      var rawText = content.replace(/<<<REWRITE>>>/g, '').replace(/<<<END>>>/g, '');
      navigator.clipboard.writeText(rawText).then(function() {
        copyBtn.innerHTML = '<i class="bi bi-check"></i> 已复制';
        setTimeout(function() { copyBtn.innerHTML = '<i class="bi bi-clipboard"></i> 复制'; }, 1500);
      });
    };
    actions.appendChild(copyBtn);

    var hasRewriteTag = content.indexOf('<<<REWRITE>>>') !== -1;

    var applyBtn = document.createElement('button');
    applyBtn.className = 'btn btn-sm ' + (hasRewriteTag ? 'btn-outline-warning' : 'btn-outline-info') + ' gm-apply-btn';
    applyBtn.innerHTML = hasRewriteTag
      ? '<i class="bi bi-pencil-square"></i> 应用到正文'
      : '<i class="bi bi-plus-circle"></i> 追加到正文';
    applyBtn.onclick = function() {
      if (hasRewriteTag) {
        applyPatch(content);
      } else {
        applyRawContent(content);
      }
    };
    actions.appendChild(applyBtn);

    wrap.appendChild(actions);
  }

  function applyRawContent(rawContent) {
    var el = document.getElementById('edit-content');
    if (!el) {
      navigator.clipboard.writeText(rawContent);
      alert('当前页面没有编辑器，内容已复制到剪贴板');
      return;
    }

    var currentContent = el.value;
    var len = rawContent.length;

    if (!confirm('将内容追加到正文末尾（+' + len + '字），是否继续？')) return;

    var separator = currentContent && !currentContent.endsWith('\n') ? '\n' : '';
    el.value = currentContent + separator + rawContent;
    el.scrollTop = el.scrollHeight;

    el.dispatchEvent(new Event('input'));
    if (typeof updateWordCount === 'function') updateWordCount();
    if (typeof toggleEdit === 'function') toggleEdit(true);

    var toast = document.createElement('div');
    toast.className = 'position-fixed bottom-0 end-0 m-3 alert alert-success';
    toast.style.zIndex = '9999';
    toast.innerHTML = '<i class="bi bi-check-circle me-1"></i>已追加到正文';
    document.body.appendChild(toast);
    setTimeout(function() { toast.remove(); }, 2000);
  }

  function stopGeneration() {
    if (sseController) {
      sseController.abort();
      sseController = null;
    }
  }

  function clearChat() {
    if (!confirm('清空对话历史？')) return;

    var list = document.getElementById('gm-chat-list');
    if (list) {
      var empty = document.getElementById('gm-chat-empty');
      list.innerHTML = '';
      if (empty) list.appendChild(empty);
      showEmpty(true);
    }

    fetch('/api/index.php?route=good_moling&action=clear', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrf() },
      body: JSON.stringify({ chapter_id: getChapterId() })
    }).catch(function() {});

    setStatus('已清空');
  }

  function populateModelSelect(models) {
    var sel = document.getElementById('gm-model-select');
    if (!sel) return;
    sel.innerHTML = '<option value="">默认模型</option>';
    models.forEach(function(m) {
      var opt = document.createElement('option');
      opt.value = m.id;
      opt.textContent = m.name;
      if (m.is_default) opt.selected = true;
      sel.appendChild(opt);
    });
  }

  function loadModels() {
    var sel = document.getElementById('gm-model-select');
    if (!sel) return;

    if (window.GM_MODELS && window.GM_MODELS.length > 0) {
      populateModelSelect(window.GM_MODELS);
      return;
    }

    fetch('/api/index.php?route=good_moling&action=list_models').then(function(r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    }).then(function(d) {
      if (!d.ok || !d.models) {
        sel.innerHTML = '<option value="">加载失败</option>';
        return;
      }
      populateModelSelect(d.models);
    }).catch(function(e) {
      sel.innerHTML = '<option value="">加载失败</option>';
      console.error('loadModels error:', e);
    });
  }

  function init() {
    var sendBtn = document.getElementById('gm-send-btn');
    var inputEl = document.getElementById('gm-chat-input');
    var stopBtn = document.getElementById('gm-stop-btn');
    var clearBtn = document.getElementById('gm-clear-btn');

    if (sendBtn) {
      sendBtn.addEventListener('click', function() { send(''); });
    }

    if (inputEl) {
      inputEl.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
          e.preventDefault();
          send('');
        }
      });
    }

    if (stopBtn) {
      stopBtn.addEventListener('click', stopGeneration);
    }

    if (clearBtn) {
      clearBtn.addEventListener('click', clearChat);
    }

    document.querySelectorAll('.gm-preset-btn').forEach(function(btn) {
      btn.addEventListener('click', function() {
        var preset = btn.dataset.preset;
        if (preset === 'rewrite') {
          var sel = getEditorSelection();
          if (!sel) {
            setStatus('请先选中要改写的段落', true);
            return;
          }
          setStatus('已选中 ' + sel.length + ' 字，准备改写…');
        }
        send(preset);
      });
    });

    loadModels();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.goodMolingSend = send;
  window.goodMolingStop = stopGeneration;
  window.goodMolingClear = clearChat;
})();
