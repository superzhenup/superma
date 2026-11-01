// 小说详情页（novel.php）：Agent 决策面板。由 novel.php 内联脚本原样外置。
// ================================================================
// Agent 决策面板
// ================================================================

const AgentPanel = {
  _data: null,

  // ── 初始化 ──────────────────────────────────────────────────
  init() {
    // 监听 Agent 决策 Tab 切换
    const agentTab = document.querySelector('a[href="#tab-agent"]');
    if (agentTab) {
      agentTab.addEventListener('shown.bs.tab', () => {
        if (!this._data) this.loadAll();
      });
    }

    // 监听子标签页切换（不需要重新加载数据，只做渲染）
    document.querySelectorAll('#agentSubTabs [data-bs-toggle="pill"]').forEach(tab => {
      tab.addEventListener('shown.bs.tab', (e) => {
        if (!this._data) return;
        const target = e.target.getAttribute('data-bs-target');
        if (target === '#agent-timeline') this.renderTimeline();
        if (target === '#agent-directives') this.renderDirectives();
        if (target === '#agent-outcomes') this.renderOutcomes();
      });
    });
  },

  // ── 加载全部数据 ────────────────────────────────────────────
  async loadAll() {
    this.showLoading();
    try {
      const res = await fetch(apiRouteUrl('agent_status', 'novel_id=' + encodeURIComponent(NOVEL_ID)));
      const data = await res.json();
      if (!data.ok) {
        this.showError(data.msg || '加载失败');
        return;
      }
      this._data = data;
      this.updateStats(data.stats);
      this.renderTimeline();
    } catch (e) {
      this.showError(e.message);
    }
  },

  // ── 刷新 ────────────────────────────────────────────────────
  async refresh() {
    this._data = null;
    await this.loadAll();
  },

  // ── 加载中态 ────────────────────────────────────────────────
  showLoading() {
    ['agent-timeline-content','agent-directives-content','agent-outcomes-content'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.innerHTML = '<div class="text-center text-muted py-5"><div class="spinner-border spinner-border-sm me-2"></div>加载中...</div>';
    });
    // 重置状态徽章为加载态
    const badge = document.getElementById('agent-status-badge');
    if (badge) { badge.className = 'badge bg-light text-dark'; badge.textContent = '加载中...'; }
  },

  showError(msg) {
    ['agent-timeline-content'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.innerHTML = '<div class="text-center text-danger py-5"><p>' + this._esc(msg) + '</p></div>';
    });
    // 更新状态徽章
    const badge = document.getElementById('agent-status-badge');
    if (badge) { badge.className = 'badge bg-danger'; badge.textContent = '连接失败'; }
  },

  // ── 更新统计卡片 ────────────────────────────────────────────
  updateStats(s) {
    document.getElementById('stat-total-decisions').textContent = s.total_decisions ?? '-';
    document.getElementById('stat-success-rate').textContent = (s.success_rate ?? '-') + '%';
    document.getElementById('stat-active-directives').textContent = s.active_directives ?? '-';
    const avgImp = s.avg_improvement ?? 0;
    if (avgImp === 0) {
      document.getElementById('stat-avg-improvement').textContent = '-';
    } else if (avgImp > 0) {
      document.getElementById('stat-avg-improvement').textContent = '+' + avgImp.toFixed(2);
    } else {
      document.getElementById('stat-avg-improvement').textContent = avgImp.toFixed(2);
    }

    // 更新 Agent 运行状态徽章
    const badge = document.getElementById('agent-status-badge');
    if (badge) {
      if ((s.active_directives ?? 0) > 0) {
        badge.className = 'badge bg-success';
        badge.textContent = '运行中';
      } else {
        badge.className = 'badge bg-secondary';
        badge.textContent = '待命中';
      }
    }
  },

  // ── 决策时间线渲染 ──────────────────────────────────────────
  renderTimeline() {
    const container = document.getElementById('agent-timeline-content');
    if (!container) return;
    const items = this._data?.timeline || [];
    if (!items.length) {
      container.innerHTML = '<div class="text-center text-muted py-5"><i class="bi bi-clock-history fs-1 d-block mb-2"></i><p>暂无决策记录</p><small>Agent 将在章节写作过程中自动产生决策</small></div>';
      return;
    }

    const typeIcons = {
      'writing_strategy': 'bi bi-pencil-square',
      'quality_monitor':  'bi bi-shield-check',
      'optimization':     'bi bi-lightning-charge'
    };
    const typeColors = {
      'writing_strategy': '#6366f1',
      'quality_monitor':  '#10b981',
      'optimization':     '#f59e0b'
    };
    const typeLabels = {
      'writing_strategy': '策略',
      'quality_monitor':  '质量',
      'optimization':     '优化'
    };

    const statusIcons = {
      'success':  'bi bi-check-circle text-success',
      'failed':   'bi bi-x-circle text-danger',
      'skipped':  'bi bi-dash-circle text-muted',
      'decided':  'bi bi-lightbulb text-warning'
    };

    const html = items.map((item, i) => {
      const icon = typeIcons[item.agent_type] || 'bi bi-cpu';
      const color = typeColors[item.agent_type] || '#6b7280';
      const label = typeLabels[item.agent_type] || item.agent_type;
      const statusIcon = statusIcons[item.status] || 'bi bi-dot';
      const time = (item.created_at || '').replace(' ', String.fromCharCode(160)).slice(5, 16);
      const detailStr = item.detail
        ? JSON.stringify(item.detail, null, 1).replace(/[{}"]/g, '').replace(/\n\s*/g, ' ').substring(0, 100)
        : '';
      const actionName = item.action || '未知操作';

      return `
        <div class="d-flex align-items-start py-2 ${i > 0 ? 'border-top border-secondary' : ''}">
          <div class="me-2 rounded-circle d-flex align-items-center justify-content-center"
               style="width:28px;height:28px;background:${color}22;flex-shrink:0">
            <i class="${icon}" style="color:${color};font-size:14px"></i>
          </div>
          <div class="flex-grow-1 min-w-0">
            <div class="d-flex justify-content-between align-items-center">
              <small class="fw-bold text-light">${this._esc(actionName)}</small>
              <small class="text-muted ms-2 flex-shrink-0">${time}</small>
            </div>
            <div class="d-flex align-items-center gap-1 mt-1">
              <span class="badge bg-secondary bg-opacity-25 text-muted" style="font-size:10px">${label}</span>
              <i class="${statusIcon}" style="font-size:12px"></i>
              ${detailStr ? '<small class="text-muted text-truncate d-inline-block" style="max-width:300px">' + this._esc(detailStr) + '</small>' : ''}
            </div>
          </div>
        </div>`;
    }).join('');

    container.innerHTML = html;
  },

  // ── 活跃指令渲染 ────────────────────────────────────────────
  renderDirectives() {
    const container = document.getElementById('agent-directives-content');
    if (!container) return;
    const items = this._data?.directives || [];
    if (!items.length) {
      container.innerHTML = '<div class="text-center text-muted py-5"><i class="bi bi-chat-right-text fs-1 d-block mb-2"></i><p>暂无活跃指令</p><small>Agent 会在写作过程中自动注入优化指令</small></div>';
      return;
    }

    const typeColors = {
      'quality':       'badge bg-success bg-opacity-25 text-success',
      'strategy':      'badge bg-primary bg-opacity-25 text-primary',
      'optimization':  'badge bg-warning bg-opacity-25 text-warning'
    };

    const html = items.map((d, i) => {
      const typeBadge = typeColors[d.type] || 'badge bg-secondary';
      const createdAt = (d.created_at || '').replace(' ', ' ').slice(0, 16);
      const expiresAt = d.expires_at ? (d.expires_at || '').replace(' ', ' ').slice(0, 16) : null;

      return `
        <div class="card bg-secondary border-0 mb-2">
          <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <span class="${typeBadge}">${d.type}</span>
              <small class="text-muted">${createdAt}</small>
            </div>
            <div class="text-light small mb-2">${this._esc(d.directive)}</div>
            <div class="d-flex justify-content-between align-items-center">
              <small class="text-muted">
                <i class="bi bi-book me-1"></i>第${d.apply_from}-${d.apply_to}章
              </small>
              ${expiresAt ? '<small class="text-muted"><i class="bi bi-clock me-1"></i>过期: ' + expiresAt + '</small>' : '<small class="text-success"><i class="bi bi-infinity me-1"></i>永不过期</small>'}
              <button class="btn btn-sm btn-outline-danger" data-csp-click="novel.deactivate-directive" data-directive-id="${Number(d.id)}" title="停用">
                <i class="bi bi-x-lg"></i>
              </button>
            </div>
          </div>
        </div>`;
    }).join('');

    container.innerHTML = html;
  },

  // ── 效果分析渲染 ────────────────────────────────────────────
  renderOutcomes() {
    const container = document.getElementById('agent-outcomes-content');
    if (!container) return;
    const outcomes = this._data?.outcomes;
    if (!outcomes) {
      container.innerHTML = '<div class="text-center text-muted py-5">暂无评估数据</div>';
      return;
    }

    let html = '';

    // 按类型统计表
    const byType = outcomes.by_type || [];
    if (byType.length > 0) {
      html += '<h6 class="text-muted mb-3"><i class="bi bi-pie-chart me-2"></i>按指令类型统计</h6>';
      html += '<div class="table-responsive mb-4"><table class="table table-sm table-dark table-borderless"><thead><tr><th class="text-muted">类型</th><th class="text-muted text-end">评估次数</th><th class="text-muted text-end">平均改善</th><th class="text-muted text-end">改善率</th></tr></thead><tbody>';
      byType.forEach(row => {
        const total = parseInt(row.outcome_count) || 0;
        const improved = parseInt(row.improved) || 0;
        const rate = total > 0 ? Math.round((improved / total) * 100) : 0;
        const avg = row.avg_change !== null ? parseFloat(row.avg_change).toFixed(1) : '0.0';
        const avgClass = parseFloat(avg) > 0 ? 'text-success' : parseFloat(avg) < 0 ? 'text-danger' : 'text-muted';
        html += `<tr>
          <td><span class="badge bg-secondary bg-opacity-25">${this._esc(row.type)}</span></td>
          <td class="text-end">${total}</td>
          <td class="text-end ${avgClass}">${avg >= 0 ? '+' : ''}${avg}</td>
          <td class="text-end">${rate}%</td>
        </tr>`;
      });
      html += '</tbody></table></div>';
    }

    // 最有效 TOP5
    const topEff = outcomes.top_effective || [];
    if (topEff.length > 0) {
      html += '<h6 class="text-muted mb-3"><i class="bi bi-graph-up-arrow me-2 text-success"></i>改善最大的指令</h6>';
      html += '<div class="mb-4">';
      topEff.forEach((item, i) => {
        html += `
          <div class="d-flex justify-content-between align-items-center py-2 ${i > 0 ? 'border-top border-secondary' : ''}">
            <div class="small min-w-0 me-2">
              <span class="badge bg-secondary bg-opacity-25">${this._esc(item.type)}</span>
              <span class="text-light ms-1 text-truncate d-inline-block" style="max-width:350px">${this._esc(item.directive || '')}</span>
            </div>
            <span class="small text-success fw-bold flex-shrink-0">+${parseFloat(item.quality_change).toFixed(1)}</span>
          </div>`;
      });
      html += '</div>';
    }

    // 最有副作用 TOP5
    const topHarm = outcomes.top_harmful || [];
    if (topHarm.length > 0) {
      html += '<h6 class="text-muted mb-3"><i class="bi bi-graph-down-arrow me-2 text-danger"></i>需要关注的指令（质量下降）</h6>';
      html += '<div>';
      topHarm.forEach((item, i) => {
        html += `
          <div class="d-flex justify-content-between align-items-center py-2 ${i > 0 ? 'border-top border-secondary' : ''}">
            <div class="small min-w-0 me-2">
              <span class="badge bg-secondary bg-opacity-25">${this._esc(item.type)}</span>
              <span class="text-light ms-1 text-truncate d-inline-block" style="max-width:350px">${this._esc(item.directive || '')}</span>
            </div>
            <span class="small text-danger fw-bold flex-shrink-0">${parseFloat(item.quality_change).toFixed(1)}</span>
          </div>`;
      });
      html += '</div>';
    }

    if (!byType.length && !topEff.length && !topHarm.length) {
      html = '<div class="text-center text-muted py-5"><i class="bi bi-graph-up fs-1 d-block mb-2"></i><p>暂无效果数据</p><small>Agent 指令效果将在章节完成后自动评估</small></div>';
    }

    container.innerHTML = html;
  },

  // ── 停用指令 ────────────────────────────────────────────────
  async deactivateDirective(id) {
    if (!confirm('确定停用该指令？')) return;
    try {
      const data = await apiPost('agent_status', { action: 'deactivate', novel_id: NOVEL_ID, directive_id: id });
      if (data.ok) {
        this.refresh();
      } else {
        alert(data.msg || '停用失败');
      }
    } catch (e) {
      alert('停用失败: ' + e.message);
    }
  },

  // ── 工具函数 ────────────────────────────────────────────────
  _esc(s) {
    const div = document.createElement('div');
    div.textContent = String(s || '');
    return div.innerHTML;
  }
};
