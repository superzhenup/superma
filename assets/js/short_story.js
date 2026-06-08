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
  };

  function api(action, data, method) {
    method = method || 'POST';
    var opts = { method: method, headers: jsonHeaders() };
    if (method === 'POST' && data) opts.body = JSON.stringify(data);
    return fetch('/api/index.php?route=short_story&action=' + action, opts)
      .then(function(r){
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      });
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

  window.writeDraft = function() {
    var btn = document.getElementById('btnDraft');
    var btnStop = document.getElementById('btnStop');
    var ta = document.getElementById('editorContent');
    var preview = document.getElementById('ssePreview');
    var progress = document.getElementById('sseProgress');
    var bar = document.getElementById('sseBar');
    var wcEl = document.getElementById('sseWordCount');

    btnLoading(btn, '生成中...');
    btnStop.style.display = '';
    ta.style.display = 'none';
    preview.style.display = 'block';
    preview.innerHTML = '';
    progress.style.display = 'block';
    bar.style.width = '0%';
    wcEl.textContent = '0字';

    draftSseController = new AbortController();

    var body = JSON.stringify({ id: getStoryId(), model_id: getModelId() });
    var headers = jsonHeaders();
    headers['Accept'] = 'text/event-stream';

    fetch('/api/index.php?route=short_story&action=write_draft', {
      method: 'POST',
      headers: headers,
      body: body,
      signal: draftSseController.signal,
    }).then(function(resp) {
      if (!resp.ok) {
        return resp.text().then(function(t) {
          throw new Error('HTTP ' + resp.status + ': ' + t);
        });
      }
      var reader = resp.body.getReader();
      var decoder = new TextDecoder('utf-8');
      var buf = '';
      var fullContent = '';

      function read() {
        reader.read().then(function(result) {
          if (result.done) {
            finishDraft(fullContent);
            return;
          }
          buf += decoder.decode(result.value, { stream: true });
          var blocks = buf.split('\n\n');
          buf = blocks.pop();

          for (var i = 0; i < blocks.length; i++) {
            var b = blocks[i].trim();
            if (!b || b.charAt(0) === ':') continue;
            var payload = '';
            var lines = b.split('\n');
            for (var j = 0; j < lines.length; j++) {
              if (lines[j].indexOf('data:') === 0) {
                payload += lines[j].substring(5).trim();
              }
            }
            if (!payload) continue;
            var d;
            try { d = JSON.parse(payload); } catch(e) { continue; }

            if (d.error) {
              alert(d.error);
              resetDraftUI();
              return;
            }
            if (d.done) {
              finishDraft(fullContent, d.saved);
              return;
            }
            if (d.content) {
              fullContent += d.content;
              preview.innerHTML = escHtml(fullContent) + '<span class="sse-cursor"></span>';
              if (d.wordCount) wcEl.textContent = d.wordCount.toLocaleString() + '字';
              if (d.progress) bar.style.width = Math.min(d.progress, 100) + '%';
              preview.scrollTop = preview.scrollHeight;
            }
          }
          read();
        }).catch(function(e) {
          if (e.name === 'AbortError') {
            if (fullContent) {
              finishDraft(fullContent, false);
            } else {
              resetDraftUI();
            }
          } else {
            alert('读取错误：' + e.message);
            resetDraftUI();
          }
        });
      }
      read();
    }).catch(function(e) {
      if (e.name !== 'AbortError') {
        alert('请求失败：' + e.message);
      }
      resetDraftUI();
    });
  };

  function finishDraft(content, saved) {
    var ta = document.getElementById('editorContent');
    var preview = document.getElementById('ssePreview');
    var progress = document.getElementById('sseProgress');
    var btn = document.getElementById('btnDraft');
    var btnStop = document.getElementById('btnStop');

    ta.value = content;
    ta.style.display = '';
    preview.style.display = 'none';
    progress.style.display = 'none';
    btnStop.style.display = 'none';
    btnRestore(btn);
    updateWordCount();
    draftSseController = null;

    if (saved !== false) {
      api('save_content', { id: getStoryId(), content: content, note: 'SSE生成初稿' })
        .then(function(d) {
          if (d.ok) showToast('初稿已生成并保存');
          else showToast('初稿已生成，但保存失败：' + (d.msg || ''));
        });
    } else {
      showToast('生成已停止，内容已填入编辑器');
    }
  }

  function resetDraftUI() {
    var ta = document.getElementById('editorContent');
    var preview = document.getElementById('ssePreview');
    var progress = document.getElementById('sseProgress');
    var btn = document.getElementById('btnDraft');
    var btnStop = document.getElementById('btnStop');

    ta.style.display = '';
    preview.style.display = 'none';
    progress.style.display = 'none';
    btnStop.style.display = 'none';
    btnRestore(btn);
    draftSseController = null;
  }

  window.stopDraft = function() {
    if (draftSseController) {
      draftSseController.abort();
    }
  };

  window.saveContent = function() {
    var ta = document.getElementById('editorContent');
    if (!ta) return;
    api('save_content', { id: getStoryId(), content: ta.value, note: '手动保存' })
      .then(function(d) {
        if (d.ok) {
          showToast('保存成功');
        } else {
          alert(d.msg || '保存失败');
        }
      })
      .catch(function(e){ alert('网络错误：' + e.message); });
  };

  window.toggleBriefEdit = function() {
    var display = document.getElementById('briefDisplay');
    var edit = document.getElementById('briefEdit');
    var btn = document.getElementById('btnEditBrief');
    if (edit.style.display === 'none') {
      display.style.display = 'none';
      edit.style.display = 'block';
      btn.innerHTML = '<i class="bi bi-x-lg"></i>取消编辑';
      btn.onclick = cancelBriefEdit;
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
    btn.onclick = toggleBriefEdit;
  };

  window.saveBriefEdit = function() {
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
      btn.onclick = cancelBeatsEdit;
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
    btn.onclick = toggleBeatsEdit;
  };

  window.saveBeatsEdit = function() {
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

  window.polishShort = function(mode) {
    if (!confirm('确认进行' + (mode === 'full' ? '全文润色' : mode === 'opening' ? '开头强化' : mode === 'ending' ? '结尾强化' : mode === 'dialogue' ? '对话优化' : '润色') + '？当前内容将被覆盖。')) return;
    api('polish', { id: getStoryId(), mode: mode, model_id: getModelId() })
      .then(function(d) {
        if (d.ok && d.data) {
          var ta = document.getElementById('editorContent');
          if (ta && d.data.content) ta.value = d.data.content;
          updateWordCount();
          showToast('润色完成');
        } else {
          alert(d.msg || '润色失败');
        }
      })
      .catch(function(e){ alert('网络错误：' + e.message); });
  };

  window.optimizeTitle = function() {
    api('optimize_title', { id: getStoryId(), model_id: getModelId() })
      .then(function(d) {
        if (d.ok && d.data && d.data.titles) {
          var list = document.getElementById('titleList');
          list.innerHTML = '';
          d.data.titles.forEach(function(t) {
            var div = document.createElement('div');
            div.className = 'd-flex align-items-center justify-content-between p-2 mb-1';
            div.style.cssText = 'background:#12122a;border-radius:8px;';
            div.innerHTML = '<span class="text-light">' + escHtml(t) + '</span>' +
              '<button class="btn btn-outline-primary btn-sm" onclick="applyTitle(\'' + escAttr(t) + '\')">采用</button>';
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
          var html = '<div class="quality-box">';
          html += '<div class="q-score ' + (r.score >= 60 ? 'q-pass' : 'q-fail') + '">' + r.score + '分</div>';
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
    window.open('api/export_short_story.php?id=' + getStoryId(), '_blank');
  };

  window.showVersionModal = function() {
    new bootstrap.Modal(document.getElementById('versionModal')).show();
  };

  window.rollbackVersion = function(versionId) {
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

  function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }
  function escAttr(s) {
    return s.replace(/'/g, "\\'").replace(/"/g, '&quot;');
  }

  // ============== 章节模式 ==============

  window.switchEditMode = function(mode) {
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
        ta.value = state.chapters[state.currentIdx].content || '';
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
    if (!state.chapters[idx]) return;
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
      ta.value = state.chapters[idx].content || '';
      ta.readOnly = false;
      showChapterHeader();
      var btnWriteCh = document.getElementById('btnWriteChapter');
      if (btnWriteCh) btnWriteCh.disabled = false;
      updateWordCount();
    }
  };

  window.generateChapters = function() {
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
      btn.onclick = cancelChaptersEdit;
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
    btn.onclick = toggleChaptersEdit;
  };

  window.saveChaptersEdit = function() {
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
    runWriteChapter(state.currentIdx).catch(function(e){ alert(e.message || String(e)); });
  };

  window.writeAllChapters = function() {
    if (!state.chapters.length) { alert('尚未生成章节大纲'); return; }
    if (!confirm('将按顺序为所有未完成章节生成正文,可能耗时较长。继续?')) return;
    (function loop(i) {
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
      selectChapter(i);
      runWriteChapter(i).then(function() {
        loop(i + 1);
      }).catch(function(e) {
        alert('第 ' + (i+1) + ' 章生成失败:' + (e.message || e));
      });
    })(0);
  };

  function runWriteChapter(idx) {
    return new Promise(function(resolve, reject) {
      var btn = document.getElementById('btnWriteChapter');
      var btnStop = document.getElementById('btnStop');
      var ta = document.getElementById('editorContent');
      var preview = document.getElementById('ssePreview');
      var progress = document.getElementById('sseProgress');
      var bar = document.getElementById('sseBar');
      var wcEl = document.getElementById('sseWordCount');

      // 锁定 UI
      btn && btnLoading(btn, '生成中...');
      btnStop.style.display = '';
      ta.style.display = 'none';
      preview.style.display = 'block';
      preview.innerHTML = '';
      progress.style.display = 'block';
      bar.style.width = '0%';
      wcEl.textContent = '0字';

      draftSseController = new AbortController();
      var headers = jsonHeaders();
      headers['Accept'] = 'text/event-stream';

      fetch('/api/index.php?route=short_story&action=write_chapter', {
        method: 'POST',
        headers: headers,
        body: JSON.stringify({ id: getStoryId(), model_id: getModelId(), chapter_index: idx }),
        signal: draftSseController.signal,
      }).then(function(resp) {
        if (!resp.ok) return resp.text().then(function(t){ throw new Error('HTTP ' + resp.status + ': ' + t); });
        var reader = resp.body.getReader();
        var decoder = new TextDecoder('utf-8');
        var buf = '';
        var full = '';

        function read() {
          reader.read().then(function(result) {
            if (result.done) { finishChapterStream(idx, full, btn); resolve(); return; }
            buf += decoder.decode(result.value, { stream: true });
            var blocks = buf.split('\n\n');
            buf = blocks.pop();
            for (var i = 0; i < blocks.length; i++) {
              var b = blocks[i].trim();
              if (!b || b.charAt(0) === ':') continue;
              var payload = '';
              var lines = b.split('\n');
              for (var j = 0; j < lines.length; j++) {
                if (lines[j].indexOf('data:') === 0) payload += lines[j].substring(5).trim();
              }
              if (!payload) continue;
              var d;
              try { d = JSON.parse(payload); } catch(e){ continue; }

              if (d.error) { finishChapterStream(idx, full, btn); reject(new Error(d.error)); return; }
              if (d.done)  { finishChapterStream(idx, full, btn, d.saved); resolve(); return; }
              if (d.content) {
                full += d.content;
                preview.innerHTML = escHtml(full) + '<span class="sse-cursor"></span>';
                if (d.wordCount) wcEl.textContent = d.wordCount.toLocaleString() + '字';
                if (d.progress)  bar.style.width = Math.min(d.progress, 100) + '%';
                preview.scrollTop = preview.scrollHeight;
              }
            }
            read();
          }).catch(function(e) {
            if (e.name === 'AbortError') {
              finishChapterStream(idx, full, btn, false);
              resolve();
            } else {
              finishChapterStream(idx, full, btn, false);
              reject(e);
            }
          });
        }
        read();
      }).catch(function(e) {
        finishChapterStream(idx, '', btn, false);
        if (e.name !== 'AbortError') reject(e); else resolve();
      });
    });
  }

  function finishChapterStream(idx, content, btn, saved) {
    var ta = document.getElementById('editorContent');
    var preview = document.getElementById('ssePreview');
    var progress = document.getElementById('sseProgress');
    var btnStop = document.getElementById('btnStop');

    if (content) {
      state.chapters[idx].content = content;
      state.chapters[idx].status = 'written';
    }
    ta.value = content || '';
    ta.style.display = '';
    preview.style.display = 'none';
    progress.style.display = 'none';
    btnStop.style.display = 'none';
    if (btn) btnRestore(btn);
    showChapterHeader();
    updateWordCount();
    draftSseController = null;

    if (saved === false && content) {
      // 用户主动停止：写一次保存
      api('save_chapter_content', { id: getStoryId(), chapter_index: idx, content: content })
        .then(function(){ showToast('已停止并保存第 ' + (idx+1) + ' 章'); });
    } else if (saved !== false) {
      // 刷新左侧章节卡片显示
      var item = document.querySelector('.chapter-item[data-idx="' + idx + '"]');
      if (item) {
        var st = item.querySelector('.ch-status-pending, .ch-status-written, .ch-status-polished');
        if (st) { st.textContent = '● 已写'; st.className = 'ch-status-written'; }
        var meta = item.querySelector('.ch-meta');
        if (meta) {
          var wc = content.replace(/\s+/g, '').length;
          meta.innerHTML = '<span class="ch-status-written">● 已写</span> · ' + wc + '/' + (parseInt(state.chapters[idx].word_budget) || 0) + '字';
        }
      }
      showToast('第 ' + (idx+1) + ' 章生成完成');
    }
  }

  // 改写 saveContent: 分章模式下保存当前章节
  var origSaveContent = window.saveContent;
  window.saveContent = function() {
    var ta = document.getElementById('editorContent');
    if (!ta) return;
    if (isChapterMode() && state.editMode === 'chapter') {
      if (state.currentIdx < 0) { alert('请先选择左侧章节'); return; }
      api('save_chapter_content', { id: getStoryId(), chapter_index: state.currentIdx, content: ta.value })
        .then(function(d) {
          if (d.ok) {
            state.chapters[state.currentIdx].content = ta.value;
            state.chapters[state.currentIdx].status = ta.value.trim() ? 'written' : 'pending';
            showToast('本章已保存');
            showChapterHeader();
          } else {
            alert(d.msg || '保存失败');
          }
        })
        .catch(function(e){ alert('网络错误:' + e.message); });
      return;
    }
    return origSaveContent();
  };
})();
