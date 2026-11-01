// 小说详情页（novel.php）：页面初始化。由 novel.php 内联脚本原样外置。
// 页面加载完成后初始化记忆引擎
document.addEventListener('DOMContentLoaded', () => {
  MemoryUI.init();
  AgentPanel.init();

  // ================================================================
  // 章节列表按钮绑定（查看 / 写作 / 详情Modal操作）
  // ================================================================

  // 「查看」按钮 → 已由 app.js bindChapterListDelegation() 事件委托统一处理

  // v1.10.3: 人工评分滑块实时显示
  document.querySelectorAll('#human-critic-dims input[type="range"]').forEach(el => {
    el.addEventListener('input', function() {
      var vEl = document.getElementById(this.id + '-v');
      if (vEl) vEl.textContent = this.value;
    });
  });

  // v1.10.3: 保存人工评分
  document.getElementById('btn-save-human-critic')?.addEventListener('click', async function() {
    var chapterId = parseInt(document.getElementById('detail-chapter-id')?.value || 0);
    if (!chapterId) return;
    var dims = ['thrill', 'immersion', 'pacing', 'freshness', 'read_next'];
    var scores = {};
    dims.forEach(d => {
      var el = document.getElementById('hc-' + d);
      if (el) scores[d] = parseInt(el.value);
    });
    var status = document.getElementById('human-critic-status');
    try {
      var res = await fetch(apiRouteUrl('human_critic'), {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded', 'X-CSRF-Token': getCsrf()},
        body: 'action=save&novel_id=' + NOVEL_ID + '&chapter_id=' + chapterId + '&scores=' + encodeURIComponent(JSON.stringify(scores))
      });
      var data = await res.json();
      if (data.ok) {
        status.innerHTML = '<span class="text-success">已保存</span>';
        setTimeout(() => status.innerHTML = '', 2000);
      } else {
        status.innerHTML = '<span class="text-danger">' + escHtml(data.msg || '保存失败') + '</span>';
      }
    } catch (e) {
      status.innerHTML = '<span class="text-danger">网络错误</span>';
    }
  });

  async function loadHumanCritic(chapterId) {
    var dims = ['thrill', 'immersion', 'pacing', 'freshness', 'read_next'];
    try {
      var res = await fetch(apiRouteUrl('human_critic', 'action=get&novel_id=' + encodeURIComponent(NOVEL_ID) + '&chapter_id=' + encodeURIComponent(chapterId)), {
        headers: {'X-CSRF-Token': getCsrf()}
      });
      var data = await res.json();
      if (data.ok && data.human) {
        dims.forEach(d => {
          var el = document.getElementById('hc-' + d);
          if (el && data.human[d] !== undefined) {
            el.value = data.human[d];
            document.getElementById('hc-' + d + '-v').textContent = data.human[d];
          }
        });
      } else {
        dims.forEach(d => {
          var el = document.getElementById('hc-' + d);
          if (el) { el.value = 5; document.getElementById('hc-' + d + '-v').textContent = '5'; }
        });
      }
    } catch (e) { /* ignore */ }
  }

  // 详情Modal → 标题保存/大纲保存/清空章节/重新生成
  // 已由 app.js 章节详情弹窗模块统一处理，此处不再重复绑定

  // === 添加章节 Modal ===
  window.openAddChaptersModal = function() {
    document.getElementById('add-chapters-count').value = 10;
    new bootstrap.Modal(document.getElementById('addChaptersModal')).show();
  };

  document.getElementById('btn-add-chapters-manual').addEventListener('click', async function() {
    const count = parseInt(document.getElementById('add-chapters-count').value) || 0;
    if (count < 1 || count > 200) { showToast('章节数量需在 1-200 之间', 'error'); return; }
    this.disabled = true;
    try {
      const data = await apiPost('actions', { action: 'add_chapters', novel_id: NOVEL_ID, count: count, mode: 'empty' });
      if (data.ok) {
        showToast(data.msg || '添加成功', 'success');
        bootstrap.Modal.getInstance(document.getElementById('addChaptersModal'))?.hide();
        setTimeout(() => location.reload(), 800);
      } else {
        showToast('添加失败：' + (data.msg || '未知错误'), 'error');
      }
    } catch (err) {
      showToast('添加失败：' + err.message, 'error');
    } finally {
      this.disabled = false;
    }
  });

  document.getElementById('btn-add-chapters-auto').addEventListener('click', async function() {
    const count = parseInt(document.getElementById('add-chapters-count').value) || 0;
    if (count < 1 || count > 200) { showToast('章节数量需在 1-200 之间', 'error'); return; }
    bootstrap.Modal.getInstance(document.getElementById('addChaptersModal'))?.hide();
    const btnOutline = document.getElementById('btn-outline');
    if (btnOutline) {
      btnOutline.dataset.target = parseInt(btnOutline.dataset.outlined || '0') + count;
    }
    generateOutline();
  });

  // 取消写作（重置章节状态）：唯一实现在 app.js 的 window.resetSingleChapter
  // （审计 P3-D，2026-06-11：此处原有的重复定义与 app.js 版本行为漂移，已收敛删除）
});

