(function(){
  'use strict';

  function getStoryId() { return window.SHORT_STORY_ID || 0; }
  function getModelId() { return window.SHORT_STORY_MODEL_ID || null; }
  function getChapterCount() { return parseInt(window.SHORT_STORY_CHAPTER_COUNT) || 1; }
  function isChapterMode() { return getChapterCount() >= 2; }

  var state = {
    chapters: Array.isArray(window.SHORT_STORY_CHAPTERS) ? window.SHORT_STORY_CHAPTERS.slice() : [],
    currentIdx: -1,
    editMode: isChapterMode() ? 'chapter' : 'full',
    drafts: {}, // Unconfirmed/local text is separate from persisted chapters.
    savedFullContent: null, // null uses the textarea's original server-rendered value.
  };

  // 审计修复 M-3（2026-06-22）：统一 api() 错误处理。
  // 保持 throw 行为（调用方依赖 .catch 恢复按钮状态），但包装网络错误和 非 JSON 错误为友好错误。
  function api(action, data, method) {
    method = method || 'POST';
    var opts = { method: method, headers: jsonHeaders() };
    if (method === 'POST' && data) opts.body = JSON.stringify(data);
    return fetch(apiRouteUrl('short_story', 'action=' + encodeURIComponent(action)), opts)
      .then(function(r){
        if (!r.ok) {
          // HTTP 非 2xx：尝试解析 JSON 错误体，失败则抛通用错误
          return r.json().catch(function(){
            throw new Error('请求失败（HTTP ' + r.status + '）');
          }).then(function(d){
            if (d && d.msg) throw new Error(d.msg);
            throw new Error('请求失败（HTTP ' + r.status + '）');
          });
        }
        return r.json().catch(function(){
          throw new Error('服务器返回了非 JSON 响应');
        });
      });
      // 网络错误由 fetch 自动抛出 TypeError，调用方 .catch 会捕获
  }

  function btnLoading(btn, text) {
    btn.dataset.origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + text;
  }
  function btnRestore(btn) {
    btn.disabled = false;
    btn.innerHTML = btn.dataset.origHtml || '操作';
  }

  function updateWordCount() {
    var ta = document.getElementById('editorContent');
    if (!ta) return;
    var text = ta.value.replace(/\s+/g, '');
    var count = text.length;
    var el = document.getElementById('wordCount');
    if (el) {
      var target = parseInt(el.dataset.target) || 3000;
      el.textContent = '字数：' + count.toLocaleString() + ' / ' + target.toLocaleString();
    }
  }

  window.initShortStoryWordCount = function() {
    var ta = document.getElementById('editorContent');
    if (ta) {
      ta.addEventListener('input', updateWordCount);
      updateWordCount();
    }
    if (isChapterMode()) {
      // 初始进入分章模式：编辑器置空，等待用户点击章节
      ta.value = '';
      ta.placeholder = '点击左侧章节开始编辑,或点击「写本章」生成';
    }
  };

  window.generateBrief = function() {
    if (!allowEditorAction()) return;
    var btn = document.getElementById('btnBrief');
    btnLoading(btn, '生成中...');
    api('generate_brief', { id: getStoryId(), model_id: getModelId() })
      .then(function(d) {
        if (d.ok) {
          location.reload();
        } else {
          alert(d.msg || '生成概要失败');
          btnRestore(btn);
        }
      })
      .catch(function(e){ alert('网络错误：' + e.message); btnRestore(btn); });
  };

  window.generateBeats = function() {
    if (!allowEditorAction()) return;
    var btn = document.getElementById('btnBeats');
    btnLoading(btn, '生成中...');
    api('generate_beats', { id: getStoryId(), model_id: getModelId() })
      .then(function(d) {
        if (d.ok) {
          location.reload();
        } else {
          alert(d.msg || '生成节拍失败');
          btnRestore(btn);
        }
      })
      .catch(function(e){ alert('网络错误：' + e.message); btnRestore(btn); });
  };

  var draftSseController = null;
  var allChaptersStop = false;
  var editorBusy = false;

  function editorNotice(message, warning) {
    var notice = document.getElementById('editorSaveNotice');
    if (!notice) return;
    notice.hidden = !message;
    notice.textContent = message || '';
    notice.className = 'small px-3 py-2 ' + (warning ? 'text-warning' : 'text-success');
  }

  function allowEditorAction() {
    if (!editorBusy) return true;
    editorNotice('请等待当前操作结束，或先停止生成。', true);
    return false;
  }

  function setEditorBusy(busy) {
    editorBusy = busy;
    var save = document.getElementById('btnSave');
    if (save) save.disabled = busy || (isChapterMode() && state.editMode === 'full');
    var ta = document.getElementById('editorContent');
    if (ta) ta.readOnly = busy || (isChapterMode() && state.editMode === 'full');
  }

  function rememberChapterDraft() {
    if (!isChapterMode() || state.editMode !== 'chapter' || state.currentIdx < 0) return;
    var ta = document.getElementById('editorContent');
    if (!ta) return;
    if (ta.value !== (state.chapters[state.currentIdx].content || '')) state.drafts[state.currentIdx] = ta.value;
    else delete state.drafts[state.currentIdx];
  }

  function chapterEditorContent(idx) {
    return Object.prototype.hasOwnProperty.call(state.drafts, idx)
      ? state.drafts[idx] : (state.chapters[idx].content || '');
  }

  function showChapterDraftNotice(idx) {
    editorNotice(Object.prototype.hasOwnProperty.call(state.drafts, idx)
      ? '本章有未确认保存的内容，请核对后点击保存。' : '', true);
  }

  function abortError() {
    var error = new Error('生成已停止');
    error.name = 'AbortError';
    return error;
  }

  // Only an explicit server terminal with a persistence acknowledgement can
  // complete a generation. EOF, transport errors and aborts are never saves.
  async function consumeShortStream(action, body, controller, onContent) {
    var headers = jsonHeaders();
    headers.Accept = 'text/event-stream';
    var resp = await fetch(apiRouteUrl('short_story', 'action=' + action), {
      method: 'POST', headers: headers, body: JSON.stringify(body), signal: controller.signal,
    });
    if (!resp.ok) throw new Error('请求失败（HTTP ' + resp.status + '）');
    if (!resp.body) throw new Error('服务器未返回生成数据');
    var reader = resp.body.getReader();
    var decoder = new TextDecoder('utf-8');
    var buffer = '';
    try {
      while (true) {
        if (controller.signal.aborted) throw abortError();
        var chunk = await reader.read();
        if (controller.signal.aborted) throw abortError();
        if (chunk.done) throw new Error('连接已结束，但未收到生成完成和保存确认');
        buffer += decoder.decode(chunk.value, { stream: true });
        var blocks = buffer.split(/\r?\n\r?\n/);
        buffer = blocks.pop();
        for (var i = 0; i < blocks.length; i++) {
          var payload = blocks[i].split(/\r?\n/).filter(function(line) {
            return line.indexOf('data:') === 0;
          }).map(function(line) { return line.substring(5).trim(); }).join('\n');
          if (!payload) continue;
          var event;
          try { event = JSON.parse(payload); }
          catch (error) { throw new Error('服务器返回了无法识别的生成数据'); }
          if (!event || typeof event !== 'object') throw new Error('服务器返回了无法识别的生成数据');
          if (event.error || event.ok === false) {
            var failure = new Error(event.error || event.msg || '生成失败');
            // The server rejected an incomplete model result: the preview is known truncated text.
            failure.discardPartial = event.incomplete === true;
            throw failure;
          }
          if (typeof event.content === 'string') onContent(event);
          if (event.done === true) {
            if (event.saved !== true) throw new Error(event.msg || '生成已结束，但服务器未确认保存');
            return;
          }
        }
      }
    } finally {
      try { await reader.cancel(); } catch (ignored) {}
    }
  }

  async function runShortGeneration(chapterIndex) {
    if (!allowEditorAction()) return false;
    var chapterMode = chapterIndex !== null;
    var btn = document.getElementById(chapterMode ? 'btnWriteChapter' : 'btnDraft');
    var btnStop = document.getElementById('btnStop');
    var ta = document.getElementById('editorContent');
    var preview = document.getElementById('ssePreview');
    var progress = document.getElementById('sseProgress');
    var bar = document.getElementById('sseBar');
    var wcEl = document.getElementById('sseWordCount');
    var fullContent = '';
    var saved = false;
    var controller = new AbortController();
    draftSseController = controller;
    setEditorBusy(true);
    editorNotice('', false);
    if (btn) btnLoading(btn, '生成中...');
    btnStop.style.display = '';
    ta.style.display = 'none';
    preview.style.display = 'block';
    preview.innerHTML = '';
    progress.style.display = 'block';
    bar.style.width = '0%';
    wcEl.textContent = '0字';
    var body = { id: getStoryId(), model_id: getModelId() };
    if (chapterMode) body.chapter_index = chapterIndex;
    try {
      await consumeShortStream(chapterMode ? 'write_chapter' : 'write_draft', body, controller, function(event) {
        fullContent += event.content;
        preview.innerHTML = escHtml(fullContent) + '<span class="sse-cursor"></span>';
        wcEl.textContent = fullContent.replace(/\s+/g, '').length.toLocaleString() + '字';
        if (event.progress) bar.style.width = Math.min(event.progress, 100) + '%';
        preview.scrollTop = preview.scrollHeight;
      });
      if (!fullContent.trim()) throw new Error('未收到正文，请刷新核对保存结果');
      saved = true;
      if (chapterMode) {
        state.chapters[chapterIndex].content = fullContent;
        state.chapters[chapterIndex].status = 'written';
        delete state.drafts[chapterIndex];
        renderSavedChapter(chapterIndex);
      } else {
        state.savedFullContent = fullContent;
      }
      editorNotice('服务器已确认保存。', false);
      showToast(chapterMode ? '第 ' + (chapterIndex + 1) + ' 章已生成并保存' : '初稿已生成并保存');
    } catch (error) {
      allChaptersStop = true;
      if (error.discardPartial) {
        // A truncated result rejected by the server is never kept as a draft that could be saved over the original.
        fullContent = '';
        editorNotice('生成中断：' + error.message + '。原内容未改动。', true);
      } else {
        if (chapterMode && fullContent) state.drafts[chapterIndex] = fullContent;
        var reason = error.name === 'AbortError' ? '生成已停止' : ('生成中断：' + error.message);
        editorNotice(reason + '。未收到保存确认；已接收内容保留在编辑器，请核对后保存。', true);
      }
    } finally {
      // With zero received text, preserve the editor's existing content.
      if (fullContent) ta.value = fullContent;
      ta.style.display = '';
      preview.style.display = 'none';
      progress.style.display = 'none';
      btnStop.style.display = 'none';
      if (btn) btnRestore(btn);
      draftSseController = null;
      setEditorBusy(false);
      if (chapterMode) showChapterHeader();
      updateWordCount();
    }
    return saved;
  }

  function renderSavedChapter(idx) {
    var item = document.querySelector('.chapter-item[data-idx="' + idx + '"]');
    if (!item) return;
    var chapter = state.chapters[idx];
    var written = !!(chapter.content || '').trim();
    var status = item.querySelector('.ch-status-pending, .ch-status-written, .ch-status-polished');
    if (status) {
      status.textContent = written ? '● 已写' : '○ 待写';
      status.className = written ? 'ch-status-written' : 'ch-status-pending';
    }
    var meta = item.querySelector('.ch-meta');
    if (meta) meta.innerHTML = '<span class="' + (written ? 'ch-status-written' : 'ch-status-pending') + '">'
      + (written ? '● 已写' : '○ 待写') + '</span> · ' + (chapter.content || '').replace(/\s+/g, '').length
      + '/' + (parseInt(chapter.word_budget) || 0) + '字';
  }

  window.writeDraft = function() {
    if (isChapterMode()) {
      editorNotice('分章模式请使用写本章或连续写作。', true);
      return Promise.resolve(false);
    }
    return runShortGeneration(null);
  };

  window.stopDraft = function() {
    allChaptersStop = true;
    if (draftSseController) draftSseController.abort();
  };

  window.saveContent = async function() {
    if (!allowEditorAction()) return false;
    var ta = document.getElementById('editorContent');
    if (!ta) return false;
    if (isChapterMode() && (state.editMode !== 'chapter' || state.currentIdx < 0)) {
      editorNotice('请先选择要保存的当前章节。', true);
      return false;
    }
    var idx = isChapterMode() ? state.currentIdx : null;
    var content = ta.value;
    var body = { id: getStoryId(), content: content, note: '手动保存' };
    if (idx !== null) body.chapter_index = idx;
    setEditorBusy(true);
    try {
      var result = await api(idx === null ? 'save_content' : 'save_chapter_content', body);
      if (!result.ok) throw new Error(result.msg || '保存失败');
      if (idx !== null) {
        state.chapters[idx].content = content;
        state.chapters[idx].status = content.trim() ? 'written' : 'pending';
        delete state.drafts[idx];
        renderSavedChapter(idx);
        showChapterHeader();
      } else {
        state.savedFullContent = content;
      }
      editorNotice('服务器已确认保存。', false);
      showToast(idx === null ? '保存成功' : '本章已保存');
      return true;
    } catch (error) {
      if (idx !== null) state.drafts[idx] = content;
      editorNotice('未确认保存：' + error.message + '。内容仍保留在编辑器。', true);
      return false;
    } finally {
      setEditorBusy(false);
    }
  };

  window.toggleBriefEdit = function() {
    var display = document.getElementById('briefDisplay');
    var edit = document.getElementById('briefEdit');
    var btn = document.getElementById('btnEditBrief');
    if (edit.style.display === 'none') {
      display.style.display = 'none';
      edit.style.display = 'block';
      btn.innerHTML = '<i class="bi bi-x-lg"></i>取消编辑';
    } else {
      cancelBriefEdit();
    }
  };

  window.cancelBriefEdit = function() {
    var display = document.getElementById('briefDisplay');
    var edit = document.getElementById('briefEdit');
    var btn = document.getElementById('btnEditBrief');
    display.style.display = '';
    edit.style.display = 'none';
    btn.innerHTML = '<i class="bi bi-pencil"></i>编辑概要';
  };

  window.saveBriefEdit = function() {
    if (!allowEditorAction()) return;
    var brief = {
      logline: document.getElementById('editLogline').value,
      theme: document.getElementById('editTheme').value,
      protagonist_goal: document.getElementById('editGoal').value,
      obstacle: document.getElementById('editObstacle').value,
      turning_point: document.getElementById('editTurning').value,
      ending_echo: document.getElementById('editEcho').value,
      tone: document.getElementById('editTone').value,
    };
    api('save_brief', { id: getStoryId(), brief: brief })
      .then(function(d) {
        if (d.ok) {
          location.reload();
        } else {
          alert(d.msg || '保存概要失败');
        }
      })
      .catch(function(e){ alert('网络错误：' + e.message); });
  };

  window.toggleBeatsEdit = function() {
    var display = document.getElementById('beatsDisplay');
    var edit = document.getElementById('beatsEdit');
    var btn = document.getElementById('btnEditBeats');
    if (edit.style.display === 'none') {
      display.style.display = 'none';
      edit.style.display = 'block';
      btn.innerHTML = '<i class="bi bi-x-lg"></i>取消编辑';
    } else {
      cancelBeatsEdit();
    }
  };

  window.cancelBeatsEdit = function() {
    var display = document.getElementById('beatsDisplay');
    var edit = document.getElementById('beatsEdit');
    var btn = document.getElementById('btnEditBeats');
    display.style.display = '';
    edit.style.display = 'none';
    btn.innerHTML = '<i class="bi bi-pencil"></i>编辑节拍';
  };

  window.saveBeatsEdit = function() {
    if (!allowEditorAction()) return;
    var fields = document.querySelectorAll('.beat-field');
    var beatsMap = {};
    fields.forEach(function(f) {
      var idx = f.dataset.idx;
      var key = f.dataset.key;
      if (!beatsMap[idx]) beatsMap[idx] = { order: parseInt(idx) + 1 };
      var val = f.value;
      if (key === 'word_budget') val = parseInt(val) || 0;
      beatsMap[idx][key] = val;
    });
    var beats = Object.keys(beatsMap).sort(function(a,b){ return parseInt(a) - parseInt(b); }).map(function(k){ return beatsMap[k]; });

    api('save_beats', { id: getStoryId(), beats: beats })
      .then(function(d) {
        if (d.ok) {
          location.reload();
        } else {
          alert(d.msg || '保存节拍失败');
        }
      })
      .catch(function(e){ alert('网络错误：' + e.message); });
  };

  window.polishShort = async function(mode) {
    if (!allowEditorAction()) return false;
    var idx = isChapterMode() ? state.currentIdx : null;
    if (isChapterMode() && (state.editMode !== 'chapter' || idx < 0)) {
      editorNotice('请先选择要润色的当前章节。', true);
      return false;
    }
    var ta = document.getElementById('editorContent');
    var savedContent = idx === null
      ? (state.savedFullContent === null ? ta.defaultValue : state.savedFullContent)
      : (state.chapters[idx].content || '');
    if (ta.value !== savedContent) {
      editorNotice((idx === null ? '正文' : '当前章节') + '有未保存内容，请先保存后再润色。', true);
      return false;
    }
    var scope = idx === null ? '全文' : '当前章';
    if (!confirm('确认润色' + scope + '？已保存的内容将被替换，并保留修改前版本。')) return false;
    var body = { id: getStoryId(), mode: mode, model_id: getModelId() };
    if (idx !== null) body.chapter_index = idx;
    setEditorBusy(true);
    try {
      var result = await api('polish', body);
      if (!result.ok || !result.data) throw new Error(result.msg || '润色失败');
      if (idx !== null) {
        var chapter = result.data.chapters_json && result.data.chapters_json[idx];
        if (!chapter || typeof chapter.content !== 'string') throw new Error('未收到当前章节的保存结果，请刷新核对');
        state.chapters[idx] = chapter;
        delete state.drafts[idx];
        ta.value = chapter.content;
        renderSavedChapter(idx);
        showChapterHeader();
      } else {
        if (typeof result.data.content !== 'string') throw new Error('未收到正文保存结果，请刷新核对');
        ta.value = result.data.content;
        state.savedFullContent = result.data.content;
      }
      updateWordCount();
      editorNotice(scope + '润色已保存。', false);
      showToast(scope + '润色已保存');
      return true;
    } catch (error) {
      editorNotice('润色未确认成功：' + error.message, true);
      return false;
    } finally {
      setEditorBusy(false);
    }
  };

  window.optimizeTitle = function() {
    api('optimize_title', { id: getStoryId(), model_id: getModelId() })
      .then(function(d) {
        if (d.ok && d.data && d.data.titles) {
          var list = document.getElementById('titleList');
          list.innerHTML = '';
          d.data.titles.forEach(function(t) {
            // 审计修复 M-12（2026-06-17）：用 DOM API 替代 innerHTML+inline onclick，
            // 避免 escAttr(') 被 HTML 解码后绕过引发 DOM XSS。
            // 旧版 escAttr 转义后插入 onclick="applyTitle('&#39;...)"，HTML
            // 解析器会把 &#39; 还原为 '，导致恶意标题可中断 onclick 字符串上下文。
            var div = document.createElement('div');
            div.className = 'd-flex align-items-center justify-content-between p-2 mb-1';
            div.style.cssText = 'background:#12122a;border-radius:8px;';

            var span = document.createElement('span');
            span.className = 'text-light';
            span.textContent = t;
            div.appendChild(span);

            var btn = document.createElement('button');
            btn.className = 'btn btn-outline-primary btn-sm';
            btn.textContent = '采用';
            btn.addEventListener('click', (function(title) {
              return function() { applyTitle(title); };
            })(t));
            div.appendChild(btn);

            list.appendChild(div);
          });
          new bootstrap.Modal(document.getElementById('titleModal')).show();
        } else {
          alert(d.msg || '生成标题失败');
        }
      })
      .catch(function(e){ alert('网络错误：' + e.message); });
  };

  window.applyTitle = function(title) {
    api('save_meta', { id: getStoryId(), title: title })
      .then(function(d) {
        if (d.ok) {
          document.getElementById('storyTitle').textContent = title;
          bootstrap.Modal.getInstance(document.getElementById('titleModal')).hide();
          showToast('标题已更新');
        } else {
          alert(d.msg || '更新标题失败');
        }
      })
      .catch(function(e){ alert('网络错误：' + e.message); });
  };

  window.checkQuality = function() {
    api('quality_check', { id: getStoryId() })
      .then(function(d) {
        if (d.ok && d.data) {
          var area = document.getElementById('qualityArea');
          var r = d.data;
          // 审计修复 P1-7（2026-07-12）：强制类型转换，防止 score 字符串注入 innerHTML
          var score = parseInt(r.score, 10) || 0;
          var html = '<div class="quality-box">';
          html += '<div class="q-score ' + (score >= 60 ? 'q-pass' : 'q-fail') + '">' + score + '分</div>';
          if (r.issues && r.issues.length) {
            r.issues.forEach(function(issue) {
              var color = issue.severity === 'error' ? '#f87171' : issue.severity === 'warning' ? '#fbbf24' : '#9090b8';
              html += '<div class="small mt-1" style="color:' + color + '">· ' + escHtml(issue.message) + '</div>';
            });
          }
          if (r.suggestions && r.suggestions.length) {
            html += '<div class="mt-2 small" style="color:#818cf8">优化建议：</div>';
            r.suggestions.forEach(function(s) {
              html += '<div class="small" style="color:#a5b4fc">→ ' + escHtml(s) + '</div>';
            });
          }
          html += '</div>';
          area.innerHTML = html;
        } else {
          alert(d.msg || '检测失败');
        }
      })
      .catch(function(e){ alert('网络错误：' + e.message); });
  };

  window.exportShort = function() {
    window.open(apiRouteUrl('export_short_story', 'id=' + encodeURIComponent(getStoryId())), '_blank');
  };

  window.showVersionModal = function() {
    new bootstrap.Modal(document.getElementById('versionModal')).show();
  };

  window.rollbackVersion = function(versionId) {
    if (!allowEditorAction()) return;
    if (!confirm('确认回滚到此版本？当前内容将被替换。')) return;
    api('rollback', { id: getStoryId(), version_id: versionId })
      .then(function(d) {
        if (d.ok) {
          location.reload();
        } else {
          alert(d.msg || '回滚失败');
        }
      })
      .catch(function(e){ alert('网络错误：' + e.message); });
  };

  function showToast(msg) {
    var el = document.createElement('div');
    el.className = 'position-fixed bottom-0 end-0 p-3';
    el.style.zIndex = '9999';
    el.innerHTML = '<div class="toast show align-items-center text-bg-success border-0" role="alert">' +
      '<div class="d-flex"><div class="toast-body">' + escHtml(msg) + '</div>' +
      '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>';
    document.body.appendChild(el);
    setTimeout(function(){ el.remove(); }, 3000);
  }

  // 审计修复 M-2（2026-06-22）：统一为正则转义 5 字符，与 app.js/knowledge.js 一致。
  // 原 DOM textContent 方式不转义引号，用于属性上下文有 XSS 风险。
  function escHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(m){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m];
    });
  }
  function escAttr(s) {
    // 审计修复 H7（2026-06-12）：补全 HTML 属性上下文需要的字符转义。
    // 原实现仅处理引号，遗漏 & < > ` 这些可用于跳出属性或注入新属性的字符。
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;')
      .replace(/`/g, '&#96;');
  }

  // ============== 章节模式 ==============

  window.switchEditMode = function(mode) {
    if (!allowEditorAction()) return;
    rememberChapterDraft();
    state.editMode = mode;
    var tabC = document.getElementById('tabChapter');
    var tabF = document.getElementById('tabFull');
    var header = document.getElementById('chapterHeader');
    var ta = document.getElementById('editorContent');
    var btnWriteCh = document.getElementById('btnWriteChapter');
    if (!ta) return;

    var btnSave = document.getElementById('btnSave');
    if (mode === 'chapter') {
      tabC && tabC.classList.add('active');
      tabF && tabF.classList.remove('active');
      if (state.currentIdx >= 0 && state.chapters[state.currentIdx]) {
        ta.value = chapterEditorContent(state.currentIdx);
        showChapterDraftNotice(state.currentIdx);
        ta.readOnly = false;
        showChapterHeader();
        if (btnWriteCh) btnWriteCh.disabled = false;
      } else {
        ta.value = '';
        ta.readOnly = false;
        if (header) header.style.display = 'none';
        if (btnWriteCh) btnWriteCh.disabled = true;
      }
      if (btnSave) btnSave.disabled = false;
    } else {
      tabC && tabC.classList.remove('active');
      tabF && tabF.classList.add('active');
      ta.value = mergeChaptersClient();
      editorNotice('全文预览展示已保存的章节。', false);
      ta.readOnly = true;
      if (header) header.style.display = 'none';
      if (btnWriteCh) btnWriteCh.disabled = true;
      if (btnSave) btnSave.disabled = true;
    }
    updateWordCount();
  };

  function mergeChaptersClient() {
    var parts = [];
    for (var i = 0; i < state.chapters.length; i++) {
      var c = state.chapters[i];
      var t = (c.title || '').trim();
      var cnt = (c.content || '').trim();
      if (cnt === '') continue;
      if (t) parts.push(t);
      parts.push(cnt);
    }
    return parts.join('\n\n');
  }

  function showChapterHeader() {
    var header = document.getElementById('chapterHeader');
    var title = document.getElementById('chapterHeaderTitle');
    var prog = document.getElementById('chapterHeaderProgress');
    var c = state.chapters[state.currentIdx];
    if (!c || !header) return;
    header.style.display = '';
    title.textContent = '第' + (c.order || (state.currentIdx + 1)) + '章 · ' + (c.title || '');
    var current = (c.content || '').replace(/\s+/g, '').length;
    prog.textContent = current.toLocaleString() + ' / ' + (parseInt(c.word_budget) || 0).toLocaleString() + ' 字';
  }

  window.selectChapter = function(idx) {
    if (!allowEditorAction() || !state.chapters[idx]) return;
    rememberChapterDraft();
    state.currentIdx = idx;
    // 高亮
    var items = document.querySelectorAll('.chapter-item');
    items.forEach(function(el) { el.classList.remove('active'); });
    var cur = document.querySelector('.chapter-item[data-idx="' + idx + '"]');
    if (cur) cur.classList.add('active');

    if (state.editMode !== 'chapter') {
      switchEditMode('chapter');
    } else {
      var ta = document.getElementById('editorContent');
      ta.value = chapterEditorContent(idx);
      showChapterDraftNotice(idx);
      ta.readOnly = false;
      showChapterHeader();
      var btnWriteCh = document.getElementById('btnWriteChapter');
      if (btnWriteCh) btnWriteCh.disabled = false;
      updateWordCount();
    }
  };

  window.generateChapters = function() {
    if (!allowEditorAction()) return;
    var btn = document.getElementById('btnChapters');
    if (!btn) return;
    if (btn.disabled) { alert('请先生成「叙事节拍」'); return; }
    btnLoading(btn, '生成中...');
    api('generate_chapters', { id: getStoryId(), model_id: getModelId() })
      .then(function(d) {
        if (d.ok) {
          location.reload();
        } else {
          alert(d.msg || '生成章节大纲失败');
          btnRestore(btn);
        }
      })
      .catch(function(e){ alert('网络错误:' + e.message); btnRestore(btn); });
  };

  window.toggleChaptersEdit = function() {
    var list = document.getElementById('chaptersList');
    var edit = document.getElementById('chaptersEdit');
    var btn = document.getElementById('btnEditChapters');
    if (edit.style.display === 'none') {
      list.style.display = 'none';
      edit.style.display = 'block';
      btn.innerHTML = '<i class="bi bi-x-lg"></i>取消编辑';
    } else {
      cancelChaptersEdit();
    }
  };

  window.cancelChaptersEdit = function() {
    var list = document.getElementById('chaptersList');
    var edit = document.getElementById('chaptersEdit');
    var btn = document.getElementById('btnEditChapters');
    list.style.display = '';
    edit.style.display = 'none';
    btn.innerHTML = '<i class="bi bi-pencil"></i>编辑章节大纲';
  };

  window.saveChaptersEdit = function() {
    if (!allowEditorAction()) return;
    var fields = document.querySelectorAll('.chapter-field');
    var map = {};
    fields.forEach(function(f) {
      var idx = f.dataset.idx;
      var key = f.dataset.key;
      if (!map[idx]) {
        map[idx] = Object.assign({}, state.chapters[idx] || {}, { order: parseInt(idx) + 1 });
      }
      var v = f.value;
      if (key === 'word_budget') {
        v = parseInt(v) || 0;
      } else if (key === 'beat_refs') {
        v = v.split(',').map(function(s){ return parseInt(s.trim()); }).filter(function(n){ return !isNaN(n) && n > 0; });
      }
      map[idx][key] = v;
    });
    var chapters = Object.keys(map).sort(function(a,b){ return parseInt(a) - parseInt(b); }).map(function(k){ return map[k]; });

    api('save_chapters', { id: getStoryId(), chapters: chapters })
      .then(function(d) {
        if (d.ok) {
          location.reload();
        } else {
          alert(d.msg || '保存章节大纲失败');
        }
      })
      .catch(function(e){ alert('网络错误:' + e.message); });
  };

  window.writeCurrentChapter = function() {
    if (state.currentIdx < 0) { alert('请先选择左侧章节'); return; }
    if (state.editMode !== 'chapter') { editorNotice('请先选择要生成的当前章节。', true); return; }
    return runWriteChapter(state.currentIdx);
  };

  window.writeAllChapters = function() {
    if (!allowEditorAction()) return;
    if (!state.chapters.length) { alert('尚未生成章节大纲'); return; }
    if (!confirm('将按顺序为所有未完成章节生成正文,可能耗时较长。继续?')) return;
    allChaptersStop = false;
    (function loop(i) {
      if (allChaptersStop) return;
      while (i < state.chapters.length) {
        var c = state.chapters[i];
        var has = (c.content || '').replace(/\s+/g, '').length > 0;
        if (!has) break;
        i++;
      }
      if (i >= state.chapters.length) {
        showToast('所有章节已写完');
        setTimeout(function(){ location.reload(); }, 800);
        return;
      }
      if (allChaptersStop) return;
      selectChapter(i);
      runWriteChapter(i).then(function(saved) {
        if (saved && !allChaptersStop) loop(i + 1);
      }).catch(function(e) {
        alert('第 ' + (i+1) + ' 章生成失败:' + (e.message || e));
      });
    })(0);
  };

  function runWriteChapter(idx) {
    return runShortGeneration(idx);
  }
})();
