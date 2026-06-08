(function () {
  const CreativeAssist = {
    root: null,
    state: null,

    init() {
      this.root = document.getElementById('creative-assist-root');
      if (!this.root) return;

      this.bind();
      this.loadContext();
    },

    bind() {
      this.byId('assist-refresh')?.addEventListener('click', () => this.loadContext());
      this.byId('assist-save-directive')?.addEventListener('click', async () => {
        try {
          await this.saveDirective(true);
        } catch (err) {
          this.setStatus('指令保存失败：' + err.message, 'danger');
          this.toast('指令保存失败：' + err.message, 'error');
        }
      });
      this.byId('assist-start-write')?.addEventListener('click', () => this.startWriting());
      this.byId('assist-quality')?.addEventListener('click', () => this.runQualityCheck());
    },

    async loadContext() {
      const novelId = this.novelId();
      const chapterId = this.chapterId();
      const url = new URL('api/creative_assist.php', window.location.href);
      url.searchParams.set('action', 'context');
      url.searchParams.set('novel_id', novelId);
      if (chapterId) url.searchParams.set('chapter_id', chapterId);

      this.setStatus('正在加载创作上下文...', 'secondary');
      try {
        const res = await fetch(url.toString(), { cache: 'no-store' });
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || '加载失败');
        this.state = json.data;
        this.render();
        this.setStatus('创作上下文已加载', 'success');
      } catch (err) {
        this.setStatus('加载失败：' + err.message, 'danger');
      }
    },

    render() {
      this.renderTarget();
      this.renderContext();
      this.renderRisks();
      this.updateActions();
    },

    renderTarget() {
      const targetEl = this.byId('assist-target');
      const target = this.state?.target;
      const chapter = this.state?.chapter;
      if (!target || !chapter) {
        targetEl.innerHTML = '<div class="assist-muted">暂无可创作章节。</div>';
        return;
      }

      const keyPoints = this.renderList(target.key_points);
      const scenes = this.renderList(target.scene_breakdown);
      const dialogue = this.renderList(target.dialogue_beats);
      const sensory = this.renderList(target.sensory_details);

      targetEl.innerHTML = `
        <div class="d-flex align-items-start justify-content-between gap-2 flex-wrap mb-2">
          <div>
            <h5 class="mb-1">${this.escape(target.title)}</h5>
            <div class="assist-muted">第 ${target.chapter_number} 章 · ${this.escape(target.status)} · 目标 ${target.target_words || '-'} 字</div>
          </div>
          <div>
            ${this.chip('节奏', target.pacing || '-')}
            ${this.chip('悬念', target.suspense || '-')}
            ${this.chip('爽点', target.cool_point_type || '-')}
          </div>
        </div>
        ${this.section('章节大纲', target.outline)}
        ${this.section('章节概要', target.synopsis)}
        ${this.section('全书目标', target.story_goal)}
        ${this.section('本卷目标', target.volume_goal)}
        ${keyPoints ? `<div class="assist-block"><div class="assist-item-label">关键情节点</div>${keyPoints}</div>` : ''}
        ${scenes ? `<div class="assist-block"><div class="assist-item-label">场景拆分</div>${scenes}</div>` : ''}
        ${dialogue ? `<div class="assist-block"><div class="assist-item-label">对话节拍</div>${dialogue}</div>` : ''}
        ${sensory ? `<div class="assist-block"><div class="assist-item-label">感官细节</div>${sensory}</div>` : ''}
        ${this.section('结尾钩子', target.cliffhanger || target.hook)}
      `;
    },

    renderContext() {
      const el = this.byId('assist-context');
      const blocks = this.state?.context_blocks || [];
      if (!blocks.length) {
        el.innerHTML = '<div class="assist-muted">暂无上下文。</div>';
        return;
      }

      el.innerHTML = blocks.map(block => {
        const items = block.items || [];
        const body = items.length
          ? items.map(item => `
              <div class="assist-item">
                <div class="d-flex justify-content-between gap-2">
                  <div class="assist-item-label">${this.escape(item.label || '')}</div>
                  ${item.meta ? `<div class="assist-muted">${this.escape(item.meta)}</div>` : ''}
                </div>
                <div class="assist-item-text">${this.escape(item.text || '')}</div>
              </div>
            `).join('')
          : '<div class="assist-muted">暂无数据。</div>';
        return `
          <div class="assist-block">
            <div class="assist-panel-title mb-1"><i class="bi ${this.escape(block.icon || 'bi-dot')}"></i>${this.escape(block.title || '')}</div>
            ${body}
          </div>
        `;
      }).join('');
    },

    renderRisks() {
      const el = this.byId('assist-risks');
      const risks = this.state?.risks || [];
      if (!risks.length) {
        el.innerHTML = '<div class="assist-muted">暂无风险。</div>';
        return;
      }

      el.innerHTML = risks.map(risk => `
        <div class="assist-risk ${this.escape(risk.level || 'info')}">
          <div class="fw-semibold">${this.escape(risk.title || '')}</div>
          <div class="assist-muted mt-1">${this.escape(risk.message || '')}</div>
        </div>
      `).join('');
    },

    updateActions() {
      const chapter = this.state?.chapter;
      const view = this.byId('assist-view-chapter');
      if (chapter?.id) {
        view.href = `chapter.php?id=${chapter.id}`;
        view.classList.remove('disabled');
      } else {
        view.href = '#';
        view.classList.add('disabled');
      }

      const start = this.byId('assist-start-write');
      if (start) {
        start.disabled = !this.state?.writing_ready;
        start.title = this.state?.writing_ready ? '从辅助系统启动本章创作' : '当前章节尚未进入可写作状态';
      }
    },

    async saveDirective(showFeedback) {
      const directiveEl = this.byId('assist-directive');
      const directive = (directiveEl?.value || '').trim();
      if (!directive) {
        if (showFeedback) this.toast('请先填写临时写作指令', 'error');
        return false;
      }
      const chapterNumber = this.state?.chapter?.chapter_number;
      if (!chapterNumber) throw new Error('缺少章节信息');

      const json = await this.postJson('api/creative_assist.php?action=save_directive', {
        novel_id: this.novelId(),
        chapter_number: chapterNumber,
        directive,
      });
      if (!json.ok) throw new Error(json.error || '保存失败');

      this.byId('assist-directive-hint').textContent = '临时指令已保存到本章 Agent 指令。';
      if (showFeedback) this.toast('临时指令已保存', 'success');
      await this.loadContext();
      return true;
    },

    async startWriting() {
      if (!this.state?.chapter?.id) return;
      const log = this.byId('assist-write-log');
      const bar = this.byId('assist-write-bar');
      const directive = (this.byId('assist-directive')?.value || '').trim();

      try {
        if (directive) await this.saveDirective(false);

        log.textContent = '';
        bar.style.width = '8%';
        this.setStatus('正在启动章节创作...', 'secondary');

        if (typeof window.streamWriteChapter !== 'function') {
          throw new Error('写作客户端尚未加载，请刷新页面后重试');
        }

        const cursor = document.createElement('span');
        cursor.textContent = '▌';
        log.appendChild(cursor);

        const content = await window.streamWriteChapter(
          this.novelId(),
          this.state.chapter.id,
          (statsText, chapterId) => {
            bar.style.width = '100%';
            this.setStatus('章节创作完成：' + statsText, 'success');
            if (chapterId) {
              const view = this.byId('assist-view-chapter');
              view.href = `chapter.php?id=${chapterId}`;
              view.classList.remove('disabled');
            }
          },
          log,
          cursor
        );

        if (content) log.scrollTop = log.scrollHeight;
        await this.loadContext();
        await this.runQualityCheck();
      } catch (err) {
        bar.style.width = '0%';
        this.setStatus('创作失败：' + err.message, 'danger');
        this.toast('创作失败：' + err.message, 'error');
      }
    },

    async runQualityCheck() {
      const chapterId = this.state?.chapter?.id || this.chapterId();
      this.setStatus('正在运行质量检测...', 'secondary');
      try {
        const json = await this.postJson('api/creative_assist.php?action=quality', {
          novel_id: this.novelId(),
          chapter_id: chapterId,
        });
        if (!json.ok) throw new Error(json.error || '质量检测失败');
        this.renderQuality(json.data);
        this.setStatus('质量检测完成', 'success');
      } catch (err) {
        this.renderQualityError(err.message);
        this.setStatus('质量检测未完成：' + err.message, 'warning');
      }
    },

    renderQuality(data) {
      const panel = this.byId('assist-result-panel');
      const el = this.byId('assist-quality-result');
      panel.style.display = '';
      const gates = data.gates || [];
      el.innerHTML = `
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap mb-3">
          <div>
            <div class="assist-muted">第 ${data.chapter?.chapter_number || '-'} 章 · ${this.escape(data.chapter?.title || '')}</div>
            <h5 class="mb-0">质量评分 ${data.total_score ?? '-'} / 100</h5>
          </div>
          <span class="badge ${data.passes ? 'bg-success' : 'bg-warning text-dark'}">${data.passes ? '通过' : '需处理'}</span>
        </div>
        <div class="assist-item mb-3">${this.escape(data.summary || '')}</div>
        ${gates.map(gate => `
          <div class="assist-item">
            <div class="d-flex justify-content-between gap-2">
              <div class="assist-item-label">${this.escape(gate.name || '')}</div>
              <div class="assist-muted">${gate.score ?? '-'} 分 · ${gate.status ? '通过' : '关注'}</div>
            </div>
            ${(gate.issues || []).length ? `<ul class="mb-0 mt-2">${gate.issues.map(i => `<li>${this.escape(i)}</li>`).join('')}</ul>` : '<div class="assist-muted mt-2">暂无问题。</div>'}
          </div>
        `).join('')}
      `;
    },

    renderQualityError(message) {
      const panel = this.byId('assist-result-panel');
      const el = this.byId('assist-quality-result');
      panel.style.display = '';
      el.innerHTML = `<div class="assist-risk warning"><div class="fw-semibold">质量检测暂不可用</div><div class="assist-muted mt-1">${this.escape(message)}</div></div>`;
    },

    section(title, text) {
      if (!text) return '';
      return `
        <div class="assist-block">
          <div class="assist-item-label">${this.escape(title)}</div>
          <div class="assist-item-text">${this.escape(text)}</div>
        </div>
      `;
    },

    renderList(items) {
      if (!Array.isArray(items) || !items.length) return '';
      return items.map(item => {
        if (typeof item === 'string') return `<div class="assist-item-text">· ${this.escape(item)}</div>`;
        if (item && typeof item === 'object') {
          const text = item.title || item.event || item.content || item.desc || item.description || JSON.stringify(item);
          return `<div class="assist-item-text">· ${this.escape(text)}</div>`;
        }
        return '';
      }).join('');
    },

    chip(label, value) {
      return `<span class="assist-chip">${this.escape(label)}：${this.escape(value)}</span>`;
    },

    async postJson(url, payload) {
      const res = await fetch(url, {
        method: 'POST',
        headers: this.jsonHeaders(),
        body: JSON.stringify(payload),
      });
      return res.json();
    },

    jsonHeaders() {
      if (typeof window.jsonHeaders === 'function') return window.jsonHeaders();
      const meta = document.querySelector('meta[name="csrf-token"]');
      return {
        'Content-Type': 'application/json',
        'X-CSRF-Token': meta ? meta.content : '',
      };
    },

    setStatus(message, type) {
      const el = this.byId('assist-status');
      if (!el) return;
      el.className = `alert alert-${type || 'secondary'} py-2 small`;
      el.innerHTML = `<i class="bi bi-info-circle me-1"></i>${this.escape(message)}`;
    },

    toast(message, type) {
      if (typeof window.showToast === 'function') {
        window.showToast(message, type === 'error' ? 'error' : type);
      }
    },

    byId(id) {
      return document.getElementById(id);
    },

    novelId() {
      return parseInt(this.root.dataset.novelId || '0', 10);
    },

    chapterId() {
      return parseInt(this.root.dataset.chapterId || '0', 10) || 0;
    },

    escape(value) {
      return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    },
  };

  window.CreativeAssist = CreativeAssist;
  document.addEventListener('DOMContentLoaded', () => CreativeAssist.init());
})();
