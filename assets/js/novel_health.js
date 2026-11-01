// 小说详情页（novel.php）：全书健康度仪表板。由 novel.php 内联脚本原样外置。
// v1.10.3 工程控制论：全书健康度仪表板
let healthCharts = {};

document.getElementById('tab-emotion-trigger')?.addEventListener('shown.bs.tab', function() {
  if (window._healthDashboardLoaded) return;
  window._healthDashboardLoaded = true;
  loadHealthDashboard();
});

function loadHealthDashboard() {
  apiGet('health_dashboard', { novel_id: NOVEL_ID })
  .then(res => {
    if (!res.ok) return;
    const d = res.data;

    // 1. 情绪曲线
    renderLineChart('emotion-canvas', d.emotion_curve || [], '情绪分数', '#ff6b6b');
    renderLineChart('quality-canvas', d.quality_curve || [], '质量分数', '#4ecdc4');

    // 2. 爽点分布（柱状图）
    renderBarChart('coolpoint-canvas', d.cool_point_distribution || [], '爽点分布');
    if (d.cool_point_density) {
      document.getElementById('coolpoint-density-text').textContent =
        '平均 ' + d.cool_point_density + ' 章/爽点（共' + d.total_cool_points + '个爽点/' + d.total_completed + '章）';
    }

    // 3. 钩子分布（柱状图）
    renderBarChart('hook-canvas', d.hook_distribution || [], '钩子分布');

    // 3b. 钩子回收健康
    renderHookHealth(d.hook_health || {});

    // 4. 角色出场状态
    renderCharacterList(d.characters || []);

    // 5. 伏笔健康
    renderForeshadowList(d.foreshadowing || {});

    // 6. 系统健康
    renderSystemHealth(d.system_health || {});
  })
  .catch(err => {
    console.error('Health dashboard load error:', err);
  });
}

// v41 钩子回收健康面板
function renderHookHealth(h) {
  const el = document.getElementById('hook-health');
  if (!el) return;
  const checked = h.checked || 0;
  if (!checked) {
    el.innerHTML = '<span class="text-muted">钩子回收：暂无数据（写够 2 章后开始统计）</span>';
    return;
  }
  const rate = (h.rate != null) ? h.rate : 0;
  const rateClass = rate >= 85 ? 'text-success' : (rate >= 60 ? 'text-warning' : 'text-danger');
  let html = '<div class="d-flex justify-content-between align-items-center mb-1">'
    + '<span class="text-muted"><i class="bi bi-link-45deg me-1"></i>钩子回收率</span>'
    + '<span class="' + rateClass + ' fw-bold">' + rate + '% '
    + '<span class="text-muted fw-normal">(' + (h.resolved || 0) + '/' + checked + '，悬挂 ' + (h.dangling || 0) + ')</span></span>'
    + '</div>';
  const list = h.dangling_list || [];
  if (list.length) {
    html += '<div class="text-muted mb-1">⚠️ 悬挂钩子（抛出后未回收）：</div>';
    html += list.map(x =>
      '<div class="text-truncate" title="' + escHtml(x.hook || '') + '">'
      + '<span class="badge bg-danger me-1">第' + x.chapter + '章</span>'
      + escHtml(x.hook || '') + '</div>'
    ).join('');
  } else {
    html += '<div class="text-success">✓ 近期无悬挂钩子</div>';
  }
  el.innerHTML = html;
}

function renderLineChart(canvasId, data, label, color) {
  const ctx = document.getElementById(canvasId)?.getContext('2d');
  if (!ctx || !data.length) return;
  if (healthCharts[canvasId]) healthCharts[canvasId].destroy();
  const labels = data.map(p => p.x);
  const values = data.map(p => p.y);
  healthCharts[canvasId] = new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [{
        label,
        data: values,
        borderColor: color,
        backgroundColor: color.replace(')', ',0.1)').replace('rgb', 'rgba'),
        fill: true,
        tension: 0.3,
        pointRadius: 2,
        borderWidth: 2,
        spanGaps: true,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#999', maxTicksLimit: 12 } },
        y: { min: 0, max: 100, grid: { color: 'rgba(255,255,255,0.08)' }, ticks: { color: '#999' } }
      }
    }
  });
}

function renderBarChart(canvasId, data, label) {
  const ctx = document.getElementById(canvasId)?.getContext('2d');
  if (!ctx || !data.length) return;
  if (healthCharts[canvasId]) healthCharts[canvasId].destroy();
  const colors = ['#ff6b6b','#4ecdc4','#ffe66d','#a29bfe','#fd79a8','#00cec9','#fab1a0','#81ecec'];
  healthCharts[canvasId] = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: data.map(d => d.label),
      datasets: [{
        label,
        data: data.map(d => d.count),
        backgroundColor: data.map((_, i) => colors[i % colors.length] + '99'),
        borderColor: data.map((_, i) => colors[i % colors.length]),
        borderWidth: 1,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      indexAxis: 'y',
      plugins: { legend: { display: false } },
      scales: {
        x: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#999', stepSize: 1 } },
        y: { grid: { display: false }, ticks: { color: '#ccc' } }
      }
    }
  });
}

function renderCharacterList(characters) {
  const container = document.getElementById('character-status-list');
  if (!characters.length) {
    container.innerHTML = '<div class="text-center text-muted py-3 small">暂无角色数据</div>';
    return;
  }
  container.innerHTML = characters.map(c => {
    const badgeClass = c.importance === 'protagonist' ? 'bg-danger text-white' :
                       c.importance === 'major' ? 'bg-warning text-dark' :
                       c.importance === 'minor' ? 'bg-info text-dark' : 'bg-secondary';
    const gapWarn = c.gap > 8 ? 'text-warning' : 'text-muted';
    const warnIcon = c.gap > 8 ? ' ⚠️' : '';
    return `<div class="list-group-item bg-transparent border-secondary d-flex justify-content-between align-items-center py-2">
      <div>
        <span class="badge ${badgeClass} me-2 small">${c.importance === 'protagonist' ? '主角' : c.importance === 'major' ? '主要' : c.importance === 'minor' ? '次要' : '背景'}</span>
        <span style="color:var(--text)">${escHtml(c.name)}</span>
      </div>
      <span class="small ${gapWarn}">
        第${c.last_chapter || '?'}章出场 · 距现${c.gap}章${warnIcon}
      </span>
    </div>`;
  }).join('');
}

function renderForeshadowList(fs) {
  const container = document.getElementById('foreshadow-status-list');
  const items = fs.items || [];
  if (!items.length) {
    container.innerHTML = '<div class="text-center text-muted py-3 small">暂无待回收伏笔</div>';
    return;
  }
  container.innerHTML = items.map(f => {
    const warnClass = f.warning ? 'text-warning' : 'text-muted';
    const warnIcon = f.warning ? ' ⚠️' : '';
    return `<div class="list-group-item bg-transparent border-secondary d-flex justify-content-between align-items-center py-2">
      <span style="color:var(--text);max-width:60%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${escHtml(f.description)}">
        ${escHtml(f.description)}
      </span>
      <span class="small ${warnClass}">
        埋于第${f.planted_chapter}章 · ${f.age}章前 · ${f.since_last}章未触动${warnIcon}
      </span>
    </div>`;
  }).join('');
  // 伏笔总数提示
  const total = fs.total || items.length;
  const aged = fs.aged_count || 0;
  if (aged > 0) {
    container.insertAdjacentHTML('afterbegin',
      `<div class="list-group-item bg-transparent border-secondary py-1 small text-warning">
        ⚠️ ${aged}条伏笔埋藏超25章，建议尽快回收
      </div>`
    );
  }
}

function renderSystemHealth(health) {
  const badge = document.getElementById('health-score-badge');
  const alertCount = document.getElementById('health-alert-count');
  const section = document.getElementById('health-alerts-section');
  const list = document.getElementById('health-alerts-list');

  const score = health.score ?? 100;
  let badgeClass = 'bg-success';
  if (score < 50) badgeClass = 'bg-danger';
  else if (score < 70) badgeClass = 'bg-warning text-dark';

  badge.className = 'badge ' + badgeClass + ' me-2';
  badge.style.fontSize = '1rem';
  badge.textContent = score + '/100';

  const alerts = health.alerts || [];
  alertCount.textContent = alerts.length > 0 ? alerts.length + '条告警' : '系统健康';

  if (alerts.length > 0) {
    section.style.display = '';
    list.innerHTML = alerts.map(a => {
      const levelClass = a.level === 'error' ? 'danger' : a.level === 'warning' ? 'warning' : 'secondary';
      return `<div class="list-group-item bg-transparent border-secondary py-2">
        <span class="badge bg-${levelClass} me-2 small">${a.level}</span>
        <span style="color:var(--text)">${escHtml(a.message)}</span>
      </div>`;
    }).join('');
    // 添加建议
    const recs = health.recommendations || [];
    if (recs.length > 0) {
      list.innerHTML += recs.map(r =>
        `<div class="list-group-item bg-transparent border-secondary py-1 small text-muted">
          <i class="bi bi-lightbulb me-1"></i>${escHtml(r)}
        </div>`
      ).join('');
    }
  } else {
    section.style.display = 'none';
  }
}
