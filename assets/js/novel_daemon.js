// 小说详情页（novel.php）：挂机写作控制器。由 novel.php 内联脚本原样外置。
// ================================================================
// 挂机写作控制器
// ================================================================
const DaemonWrite = {
  _pollTimer: null,
  _token: null,   // 缓存 token，避免重复请求

  // 获取 daemon token（从后端取一次后缓存）
  async _getToken() {
    if (this._token) return this._token;
    try {
      const d = await apiPost('daemon_token', { action: 'get_or_create' });
      if (d.token) {
        this._token = d.token;
        return this._token;
      }
    } catch(e) {}
    return null;
  },

  // 切换启用/停用
  async toggle() {
    const btn     = document.getElementById('btn-daemon-write');
    const enabled = btn?.dataset.enabled === '1';
    if (enabled) {
      await this.stop();
    } else {
      // 启用前先检查是否有其他书籍开着挂机
      const token = await this._getToken();
      if (token) {
        try {
          const res = await fetch('api/daemon_write.php?action=status', {
            headers: {'X-Daemon-Token': token}
          });
          const d   = await res.json();
          if (d.ok && d.data && d.data.id && d.data.id != NOVEL_ID && d.data.daemon_write == 1) {
            if (!confirm(`《${d.data.title}》正在挂机写作中，确定切换到当前书籍吗？（将自动停止原书籍的挂机）`)) return;
          }
        } catch(e) {}
      }
      await this.start();
    }
  },

  // 启用挂机写作
  async start() {
    const token = await this._getToken();
    if (!token) { alert('获取挂机令牌失败，请刷新页面重试'); return; }

    try {
      const res = await fetch('api/daemon_write.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Daemon-Token': token, 'X-CSRF-Token': getCsrf()},
        body: new URLSearchParams({ novel_id: String(NOVEL_ID), action: 'enable' })
      });
      const d   = await res.json();
      if (!d.ok) { alert('启用失败：' + d.msg); return; }

      // 如果切换了其他书籍，给出提示
      if (d.switched_from) {
        showToast(`已自动关闭《${d.switched_from.title}》的挂机，切换到当前书籍`, 'warning');
      }

      // 更新按钮状态
      const btn = document.getElementById('btn-daemon-write');
      if (btn) {
        btn.dataset.enabled = '1';
        btn.className = btn.className.replace('btn-outline-success','btn-success');
        btn.innerHTML = '<i class="bi bi-robot me-1"></i>挂机中';
      }
      document.getElementById('daemon-write-panel')?.classList.remove('d-none');

      // 生成 Cron 命令
      this._updateCurlCmd(token);

      // 开始轮询状态
      this._startPoll(token);

      showToast(d.msg || '挂机写作已启用，等待宝塔 Cron 触发（每分钟一次）', 'success');
    } catch(e) {
      alert('操作失败：' + e.message);
    }
  },

  // 停用挂机写作
  async stop() {
    if (!confirm('确定停止挂机写作？当前正在写作的章节会在本次完成后停止。')) return;
    const token = await this._getToken();
    if (!token) { alert('获取令牌失败'); return; }

    try {
      const res = await fetch('api/daemon_write.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Daemon-Token': token, 'X-CSRF-Token': getCsrf()},
        body: new URLSearchParams({ novel_id: String(NOVEL_ID), action: 'disable' })
      });
      const d   = await res.json();
      if (!d.ok) { alert('停用失败：' + d.msg); return; }

      // 更新按钮
      const btn = document.getElementById('btn-daemon-write');
      if (btn) {
        btn.dataset.enabled = '0';
        btn.className = btn.className.replace('btn-success','btn-outline-success');
        btn.innerHTML = '<i class="bi bi-robot me-1"></i>挂机写作';
      }
      document.getElementById('daemon-write-panel')?.classList.add('d-none');
      this._stopPoll();
      showToast('挂机写作已停止', 'info');
    } catch(e) {
      alert('操作失败：' + e.message);
    }
  },

  // 更新 Cron 命令显示（不含 novel_id，全局唯一）
  _updateCurlCmd(token) {
    const daemonUrl = new URL('api/daemon_write.php', window.location.href).toString();
    const cmd    = `curl -s -H "X-Daemon-Token: ${token}" "${daemonUrl}"`;
    const el = document.getElementById('daemon-curl-cmd');
    if (el) el.textContent = cmd;
  },

  // 复制 Cron 命令
  async copyCmd() {
    const el = document.getElementById('daemon-curl-cmd');
    if (!el) return;
    try {
      await navigator.clipboard.writeText(el.textContent);
      showToast('命令已复制到剪贴板', 'success');
    } catch(e) {
      prompt('请手动复制以下命令：', el.textContent);
    }
  },

  // 轮换挂机令牌（审计 Low-3 / R2）
  // 旧令牌立即失效，必须同步更新宝塔计划任务里的命令，否则挂机写作无法继续。
  async rotateToken() {
    if (!confirm('确定要轮换挂机令牌吗？\n\n旧令牌会立即失效，必须同步更新宝塔计划任务里的命令，否则挂机写作将无法继续执行。')) return;
    const d = await apiPost('daemon_token', { action: 'rotate' });
    if (!d || !d.ok || !d.token) {
      // 失败时保持现有令牌与命令显示不变
      showToast('轮换失败：' + ((d && d.msg) || '未知错误'), 'error');
      return;
    }
    this._token = d.token;
    this._updateCurlCmd(d.token);
    // 轮询用的是旧令牌，轮换后需要用新令牌重新开始轮询
    if (this._pollTimer) this._startPoll(d.token);
    showToast('令牌已轮换，请到宝塔计划任务中更新命令中的新令牌', 'success');
  },

  // 开始轮询状态（每 30 秒刷新一次）
  _startPoll(token) {
    this._stopPoll();
    this._updateCurlCmd(token);
    this._poll(token);
    this._pollTimer = setInterval(() => this._poll(token), 30000);
  },

  _stopPoll() {
    if (this._pollTimer) {
      clearInterval(this._pollTimer);
      this._pollTimer = null;
    }
  },

  async _poll(token) {
    try {
      const res = await fetch(`api/daemon_write.php?novel_id=${NOVEL_ID}&action=status`, {
        headers: {'X-Daemon-Token': token}
      });
      const d   = await res.json();
      if (!d.ok || !d.data) return;
      this._renderStatus(d.data);
    } catch(e) {}
  },

  _renderStatus(data) {
    const completed = data.completed ?? 0;
    const outlined  = data.outlined  ?? 0;
    const remain    = outlined - completed;
    const pct       = outlined > 0 ? Math.round(completed / outlined * 100) : 0;
    const words     = data.total_words ?? 0;
    const locked    = data.locked ?? false;
    const schedule  = data.schedule ?? null;

    const el = (id) => document.getElementById(id);
    if (el('daemon-stat-done'))   el('daemon-stat-done').textContent   = completed;
    if (el('daemon-stat-remain')) el('daemon-stat-remain').textContent = remain > 0 ? remain : 0;
    if (el('daemon-stat-words'))  el('daemon-stat-words').textContent  = words >= 10000 ? (words/10000).toFixed(1)+'万' : words;
    if (el('daemon-progress-bar')) el('daemon-progress-bar').style.width = pct + '%';
    if (el('daemon-progress-pct')) el('daemon-progress-pct').textContent = pct + '%';

    const label = el('daemon-progress-label');
    if (label) {
      if (data.daemon_write == 0) {
        label.textContent = '挂机已完成或已停用';
      } else if (locked) {
        label.textContent = '正在写作中...（宝塔 Cron 已触发）';
      } else if (schedule?.enabled && !schedule.allowed) {
        label.textContent = `休眠中：下次 ${schedule.next_start_at || schedule.window_label} 自动运行`;
      } else {
        label.textContent = `等待下次触发（已完成 ${completed}/${outlined} 章）`;
      }
    }

    const badge = el('daemon-badge');
    if (badge) {
      if (data.daemon_write == 0) {
        badge.className = 'badge bg-secondary';
        badge.textContent = '已完成';
      } else if (locked) {
        badge.className = 'badge bg-warning text-dark';
        badge.textContent = '写作中';
      } else if (schedule?.enabled && !schedule.allowed) {
        badge.className = 'badge bg-info text-dark';
        badge.textContent = '计划等待';
      } else {
        badge.className = 'badge bg-success';
        badge.textContent = '运行中';
      }
    }

    const scheduleSummary = el('daemon-schedule-summary');
    if (scheduleSummary && schedule) {
      scheduleSummary.textContent = schedule.enabled
        ? `每日 ${schedule.window_label}（${schedule.timezone}）`
        : '未限制运行时间（全天可启动新章节）';
    }

    // 渲染日志
    const logsEl = el('daemon-logs');
    if (logsEl && Array.isArray(data.logs) && data.logs.length > 0) {
      logsEl.innerHTML = data.logs.map(log => {
        const isErr  = log.action?.includes('error') || log.action?.includes('fail');
        const isDone = log.action?.includes('done') || log.action?.includes('complete');
        const cls = isErr ? 'text-danger' : isDone ? 'text-success' : 'text-muted';
        return `<div class="${cls}">[${this._esc((log.created_at_display||'').slice(11,19))}] ${this._esc(log.message||log.action)}</div>`;
      }).join('');
      logsEl.scrollTop = logsEl.scrollHeight;
    }

    // 如果挂机已关闭，停止轮询
    if (!data.daemon_write) {
      this._stopPoll();
    }
  },

  _esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  },

  // 页面加载时初始化（如果已是挂机状态则自动开始轮询）
  async init() {
    const btn = document.getElementById('btn-daemon-write');
    if (!btn || btn.dataset.enabled !== '1') return;

    const token = await this._getToken();
    if (!token) return;
    this._updateCurlCmd(token);
    this._startPoll(token);
  },
};

// 页面加载完成后初始化挂机控制器
document.addEventListener('DOMContentLoaded', () => DaemonWrite.init());
