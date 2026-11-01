// 小说详情页（novel.php）：记忆引擎。由 novel.php 内联脚本原样外置。
// ================================================================
// Super-Ma 记忆引擎
// ================================================================

// 记忆引擎API封装
const MemoryAPI = {
  async call(action, data = {}) {
    const response = await apiPost('memory_actions', { action, novel_id: NOVEL_ID, ...data });
    return response;
  }
};

// 记忆引擎UI控制器
const MemoryUI = {
  init() {
    this.bindEvents();
    this.loadStats();
    this.loadAtoms();
    this.loadAutoExtractSetting();
  },

  bindEvents() {
    // 刷新按钮
    document.getElementById('btn-refresh-memory')?.addEventListener('click', () => {
      this.loadStats();
      this.loadAtoms();
    });

    // 自动提取开关
    document.getElementById('autoExtractToggle')?.addEventListener('change', (e) => {
      this.saveAutoExtractSetting(e.target.checked);
    });

    // 原子记忆筛选
    document.getElementById('atomTypeFilter')?.addEventListener('change', () => this.loadAtoms());
    document.getElementById('atomChapterFilter')?.addEventListener('change', () => this.loadAtoms());

    // 添加原子记忆
    document.getElementById('btn-add-atom')?.addEventListener('click', () => this.showAddAtomModal());

    // 场景聚类功能已整合到原子记忆，移除相关事件监听器

    // 记忆检索
    document.getElementById('btn-search-memory')?.addEventListener('click', () => this.searchMemory());
    document.getElementById('memorySearchInput')?.addEventListener('keypress', (e) => {
      if (e.key === 'Enter') this.searchMemory();
    });

    // 快捷检索按钮
    document.getElementById('btn-search-character')?.addEventListener('click', () => this.quickSearch('character'));
    document.getElementById('btn-search-plot')?.addEventListener('click', () => this.quickSearch('plot'));
    document.getElementById('btn-search-world')?.addEventListener('click', () => this.quickSearch('world'));

    // 子标签页切换时加载数据
    document.querySelectorAll('#memorySubTabs a[data-bs-toggle="pill"]').forEach(tab => {
      tab.addEventListener('shown.bs.tab', (e) => {
        const target = e.target.getAttribute('href');
        if (target === '#memory-cards')    this.loadCards();
        // 场景聚类功能已整合到原子记忆，不再需要加载
        if (target === '#memory-persona')  this.loadPersona();
      });
    });

    // 角色卡片存活过滤
    document.getElementById('cardAliveFilter')?.addEventListener('change', () => this.loadCards());
  },

  // 加载角色卡片列表
  async loadCards() {
    const aliveVal = document.getElementById('cardAliveFilter')?.value;
    const container = document.getElementById('cards-list');
    if (!container) return;

    container.innerHTML = `<div class="col-12 text-center text-muted py-4"><div class="spinner-border spinner-border-sm"></div><div class="mt-2">加载中...</div></div>`;

    try {
      const params = {};
      if (aliveVal === '1')  params.only_alive = true;
      if (aliveVal === '0')  params.only_dead  = true;
      const res = await MemoryAPI.call('get_cards', params);

      if (!res.ok) {
        container.innerHTML = `<div class="col-12 text-center text-danger py-4"><p>加载失败：${escHtml(res.msg || '未知错误')}</p></div>`;
        return;
      }

      let cards = res.data || [];
      // 前端二次过滤（get_cards 后端只支持 onlyAlive=true，死亡需前端过滤）
      if (aliveVal === '0') cards = cards.filter(c => !c.alive);
      else if (aliveVal === '1') cards = cards.filter(c => c.alive);

      if (cards.length === 0) {
        container.innerHTML = `<div class="col-12 text-center text-muted py-4"><i class="bi bi-person-x fs-1 d-block mb-2"></i><p>暂无角色卡片</p><small>完成章节写作后将自动生成角色卡片</small></div>`;
        return;
      }

      container.innerHTML = cards.map(c => this.renderCardItem(c)).join('');
    } catch(e) {
      container.innerHTML = `<div class="col-12 text-center text-danger py-4"><p>加载失败：${escHtml(e.message)}</p></div>`;
    }
  },

  // 渲染单张角色卡片
  renderCardItem(card) {
    const aliveLabel = card.alive
      ? `<span class="badge bg-success bg-opacity-25 text-success"><i class="bi bi-heart-fill me-1"></i>存活</span>`
      : `<span class="badge bg-danger  bg-opacity-25 text-danger"><i class="bi bi-heartbreak me-1"></i>死亡/离场</span>`;

    const attrs = card.attributes && Object.keys(card.attributes).length > 0
      ? Object.entries(card.attributes).map(([k, v]) =>
          `<span class="badge bg-dark text-light me-1 mb-1">${this.escapeHtml(k)}：${this.escapeHtml(String(v))}</span>`
        ).join('')
      : '<span class="text-muted small">暂无属性</span>';

    const title  = card.title  ? `<span class="text-muted small ms-2">${this.escapeHtml(card.title)}</span>`  : '';
    const status = card.status ? `<div class="text-muted small mt-1"><i class="bi bi-info-circle me-1"></i>${this.escapeHtml(card.status)}</div>` : '';
    const lastCh = card.last_updated_chapter ? `<span class="text-muted small">最近更新：第${card.last_updated_chapter}章</span>` : '';

    return `
      <div class="col-md-6 col-lg-4">
        <div class="card bg-secondary border-0 h-100">
          <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <div>
                <span class="fw-bold text-light">${this.escapeHtml(card.name)}</span>${title}
                ${status}
              </div>
              ${aliveLabel}
            </div>
            <div class="mb-2">${attrs}</div>
            <div class="d-flex justify-content-between align-items-center mt-2">
              ${lastCh}
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-warning" data-csp-click="novel.edit-card" data-card-id="${Number(card.id)}" title="编辑">
                  <i class="bi bi-pencil"></i>
                </button>
                <button class="btn btn-outline-info" data-csp-click="novel.card-history" data-card-id="${Number(card.id)}" data-card-name="${_escAttr(card.name)}" title="历史">
                  <i class="bi bi-clock-history"></i>
                </button>
                <button class="btn btn-outline-danger" data-csp-click="novel.delete-card" data-card-id="${Number(card.id)}" data-card-name="${_escAttr(card.name)}" title="删除">
                  <i class="bi bi-trash"></i>
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    `;
  },

  // 显示角色编辑 Modal（新建 cardId=null，编辑传 id）
  async showCardEdit(cardId = null) {
    let card = { id: null, name: '', title: '', status: '', alive: true, attributes: {} };

    if (cardId) {
      const res = await MemoryAPI.call('get_card', { card_id: cardId });
      if (!res.ok || !res.data) { alert('获取角色数据失败'); return; }
      card = res.data;
    }

    // 把 attributes 渲染为 key=value 每行一条的文本
    const attrsText = Object.entries(card.attributes || {})
      .map(([k, v]) => `${k}=${v !== null && typeof v === 'object' ? JSON.stringify(v) : v}`).join('\n');

    const modalId = 'cardEditModal';
    document.getElementById(modalId)?.remove();

    const html = `
      <div class="modal fade" id="${modalId}" tabindex="-1">
        <div class="modal-dialog">
          <div class="modal-content bg-dark text-light">
            <div class="modal-header border-secondary">
              <h5 class="modal-title">
                <i class="bi bi-person-gear me-2"></i>${cardId ? '编辑角色' : '新建角色'}
              </h5>
              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <div class="mb-3">
                <label class="form-label text-muted small">角色名称 <span class="text-danger">*</span></label>
                <input type="text" class="form-control bg-secondary border-secondary text-light"
                       id="cedit_name" value="${this.escapeHtml(card.name)}"
                       ${cardId ? 'readonly' : ''} placeholder="输入角色名称">
                ${cardId ? '<div class="form-text text-muted">角色名为唯一标识，不可修改</div>' : ''}
              </div>
              <div class="mb-3">
                <label class="form-label text-muted small">头衔 / 称号</label>
                <input type="text" class="form-control bg-secondary border-secondary text-light"
                       id="cedit_title" value="${this.escapeHtml(card.title || '')}" placeholder="如：圣女、魔王">
              </div>
              <div class="mb-3">
                <label class="form-label text-muted small">当前状态描述</label>
                <input type="text" class="form-control bg-secondary border-secondary text-light"
                       id="cedit_status" value="${this.escapeHtml(card.status || '')}" placeholder="如：重伤未愈、在旅途中">
              </div>
              <div class="mb-3">
                <label class="form-label text-muted small">存活状态</label>
                <select class="form-select bg-secondary border-secondary text-light" id="cedit_alive">
                  <option value="1" ${card.alive ? 'selected' : ''}>存活</option>
                  <option value="0" ${!card.alive ? 'selected' : ''}>死亡 / 离场</option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label text-muted small">
                  属性键值对
                  <span class="text-muted ms-1">（每行一条，格式：属性名=值）</span>
                </label>
                <textarea class="form-control bg-secondary border-secondary text-light font-monospace"
                          id="cedit_attrs" rows="6"
                          placeholder="等级=50&#10;门派=天剑宗&#10;武器=青霄剑">${this.escapeHtml(attrsText)}</textarea>
              </div>
              <div class="mb-3">
                <label class="form-label text-muted small">记录到第几章（用于历史溯源）</label>
                <input type="number" class="form-control bg-secondary border-secondary text-light"
                       id="cedit_chapter" value="0" min="0">
              </div>
              ${cardId ? `<div class="form-check border border-secondary rounded p-3 ps-5">
                <input type="checkbox" class="form-check-input" id="cedit_replay_baseline">
                <label class="form-check-label" for="cedit_replay_baseline">确认重放基线</label>
                <div class="small text-muted mt-1">仅在旧人物记忆无法重建时使用。请把卡片修正到待重放章节之前，移除撤稿事实并保留人工设定。保存将替换此卡旧历史；语音设定保留。旧稿独有角色请直接删除，然后重试后处理。</div>
              </div>` : ''}
            </div>
            <div class="modal-footer border-secondary">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
              <button type="button" class="btn btn-warning" data-csp-click="novel.save-card" data-card-id="${cardId == null ? '' : Number(cardId)}">
                <i class="bi bi-check-lg me-1"></i>保存
              </button>
            </div>
          </div>
        </div>
      </div>`;

    document.body.insertAdjacentHTML('beforeend', html);
    new bootstrap.Modal(document.getElementById(modalId)).show();
  },

  // 保存角色卡片（新建 or 编辑）
  async saveCard(cardId) {
    const name    = document.getElementById('cedit_name')?.value.trim();
    const title   = document.getElementById('cedit_title')?.value.trim();
    const status  = document.getElementById('cedit_status')?.value.trim();
    const alive   = document.getElementById('cedit_alive')?.value === '1';
    const chapter = parseInt(document.getElementById('cedit_chapter')?.value) || 0;
    const rawAttrs = document.getElementById('cedit_attrs')?.value.trim();

    if (!name) { alert('角色名称不能为空'); return; }

    // 解析属性文本 → 对象
    const attributes = {};
    if (rawAttrs) {
      rawAttrs.split('\n').forEach(line => {
        const eq = line.indexOf('=');
        if (eq > 0) {
          const k = line.slice(0, eq).trim();
          const v = line.slice(eq + 1).trim();
          if (k) {
            let parsed = v;
            if (v.startsWith('[') || v.startsWith('{')) {
              try { parsed = JSON.parse(v); } catch (_) { /* Ordinary text remains valid. */ }
            }
            attributes[k] = parsed;
          }
        }
      });
    }

    const data = { alive, title, status };
    data.attributes = attributes;
    const confirmBaseline = !!cardId && !!document.getElementById('cedit_replay_baseline')?.checked;
    if (confirmBaseline && !confirm('已核对待重放章节之前的人物事实？确认后将以当前表单替换此卡旧历史，并保留语音设定。')) return;

    const btn = document.querySelector('#cardEditModal .btn-warning');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> 保存中...'; }

    try {
      const res = await MemoryAPI.call(confirmBaseline ? 'confirm_card_replay_baseline' : 'upsert_card', {
        card_id: cardId,
        confirmed: confirmBaseline,
        name,
        data,
        chapter_number: chapter
      });

      if (!res.ok) {
        alert('保存失败：' + (res.msg || '未知错误'));
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>保存'; }
        return;
      }

      bootstrap.Modal.getInstance(document.getElementById('cardEditModal'))?.hide();
      this.loadCards();
    } catch(e) {
      alert('保存失败：' + e.message);
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>保存'; }
    }
  },

  // 删除角色卡片
  async deleteCard(cardId, name) {
    if (!confirm(`确定删除角色「${name}」？此操作将同时删除其所有变更历史，不可恢复。`)) return;

    try {
      const res = await MemoryAPI.call('delete_card', { card_id: cardId });
      if (!res.ok) { alert('删除失败：' + (res.msg || '未知错误')); return; }
      this.loadCards();
    } catch(e) {
      alert('删除失败：' + e.message);
    }
  },

  // 查看角色变更历史
  async showCardHistory(cardId, name) {
    const res = await MemoryAPI.call('get_card_history', { card_id: cardId });
    const history = (res.ok && res.data) ? res.data : [];

    const rows = history.length > 0
      ? history.map(h => `
          <tr>
            <td class="text-muted">第${h.chapter_number}章</td>
            <td><span class="badge bg-secondary">${this.escapeHtml(h.field_name)}</span></td>
            <td class="text-muted small">${h.old_value != null ? this.escapeHtml(String(h.old_value)) : '—'}</td>
            <td class="text-light small">${h.new_value != null ? this.escapeHtml(String(h.new_value)) : '—'}</td>
          </tr>`).join('')
      : `<tr><td colspan="4" class="text-center text-muted py-3">暂无变更记录</td></tr>`;

    const html = `
      <div class="modal fade" id="cardHistoryModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
          <div class="modal-content bg-dark text-light">
            <div class="modal-header border-secondary">
              <h5 class="modal-title"><i class="bi bi-clock-history me-2"></i>${this.escapeHtml(name)} 变更历史</h5>
              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <table class="table table-dark table-sm table-hover">
                <thead><tr><th>章节</th><th>字段</th><th>原值</th><th>新值</th></tr></thead>
                <tbody>${rows}</tbody>
              </table>
            </div>
          </div>
        </div>
      </div>`;

    document.getElementById('cardHistoryModal')?.remove();
    document.body.insertAdjacentHTML('beforeend', html);
    new bootstrap.Modal(document.getElementById('cardHistoryModal')).show();
  },

  // 加载统计信息
  async loadStats() {
    try {
      const res = await MemoryAPI.call('get_stats');
      if (res.ok) {
        document.getElementById('stat-atoms').textContent = res.data.atoms || 0;
        document.getElementById('stat-chapters').textContent = res.data.chapters || 0;
      }
    } catch(e) {
      console.warn('记忆统计加载失败:', e);
    }
  },

  // 加载原子记忆列表
  async loadAtoms(offset = 0) {
    const atomType = document.getElementById('atomTypeFilter')?.value || null;
    const sourceChapter = document.getElementById('atomChapterFilter')?.value || null;
    const pageSize = 50;
    
    const container = document.getElementById('atoms-list');
    if (!container) return;

    if (offset === 0) {
      container.innerHTML = `<div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm"></div><div class="mt-2">加载中...</div></div>`;
    }

    try {
      const res = await MemoryAPI.call('get_atoms', {
        atom_type: atomType || undefined,
        source_chapter: sourceChapter ? parseInt(sourceChapter) : undefined,
        limit: pageSize + 1,
        offset: offset
      });

      if (!res.ok) {
        container.innerHTML = `<div class="text-center text-danger py-4"><i class="bi bi-exclamation-circle fs-1 d-block mb-2"></i><p>加载失败：${escHtml(res.msg || '未知错误')}</p></div>`;
        return;
      }

      const hasMore = res.data && res.data.length > pageSize;
      const items = hasMore ? res.data.slice(0, pageSize) : (res.data || []);

      if (offset === 0) {
        if (items.length > 0) {
          container.innerHTML = items.map(atom => this.renderAtomItem(atom)).join('');
        } else {
          container.innerHTML = `
            <div class="text-center text-muted py-4">
              <i class="bi bi-inbox fs-1 d-block mb-2"></i>
              <p>暂无原子记忆</p>
              <small>完成章节写作后将自动提取记忆</small>
            </div>
          `;
          return;
        }
      } else {
        // 移除旧的"加载更多"按钮
        container.querySelector('.load-more-btn')?.remove();
        container.insertAdjacentHTML('beforeend', items.map(atom => this.renderAtomItem(atom)).join(''));
      }

      // 如果还有更多，加"加载更多"按钮
      if (hasMore) {
        container.insertAdjacentHTML('beforeend', `
          <div class="text-center mt-2 load-more-btn">
            <button class="btn btn-sm btn-outline-secondary" data-csp-click="novel.more-atoms" data-offset="${Number(offset) + Number(pageSize)}">
              加载更多
            </button>
          </div>
        `);
      }
    } catch(e) {
      container.innerHTML = `<div class="text-center text-danger py-4"><i class="bi bi-exclamation-circle fs-1 d-block mb-2"></i><p>加载失败：${escHtml(e.message)}</p></div>`;
    }
  },

  // 渲染原子记忆项
  renderAtomItem(atom) {
    const typeLabels = {
      'character_trait':  { label: '角色特征',     color: 'primary',   icon: 'person' },
      'plot_detail':      { label: '情节细节',     color: 'warning',   icon: 'bookmark' },
      'world_setting':    { label: '世界观设定',   color: 'success',   icon: 'globe' },
      'style_preference': { label: '风格偏好',     color: 'info',      icon: 'brush' },
      'constraint':       { label: '约束条件',     color: 'danger',    icon: 'shield' },
      'technique':        { label: '功法/技艺',    color: 'purple',    icon: 'lightning' },
      'world_state':      { label: '世界/场景状态', color: 'teal',     icon: 'map' },
      'cool_point':       { label: '亮点',         color: 'orange',    icon: 'star' },
    };
    const typeInfo = typeLabels[atom.atom_type] || { label: '未知', color: 'secondary', icon: 'question' };
    
    // 检查是否为关键事件
    const isKeyEvent = atom.metadata && (atom.metadata.is_key_event === 1 || atom.metadata.is_key_event === '1' || atom.metadata.is_key_event === true);
    const confidencePercent = Math.round(atom.confidence * 100);
    
    return `
      <div class="memory-item card bg-secondary border-0 mb-2${isKeyEvent ? ' border-warning' : ''}">
        <div class="card-body p-3">
          <div class="d-flex justify-content-between align-items-start">
            <div class="flex-grow-1">
              <div class="d-flex align-items-center gap-2 mb-2">
                <span class="badge bg-${typeInfo.color} bg-opacity-25 text-${typeInfo.color}">
                  <i class="bi bi-${typeInfo.icon} me-1"></i>${typeInfo.label}
                </span>
                ${isKeyEvent ? '<span class="badge bg-warning bg-opacity-25 text-warning"><i class="bi bi-star-fill me-1"></i>关键事件</span>' : ''}
                ${atom.source_chapter ? `<span class="badge bg-secondary">第${atom.source_chapter}章</span>` : ''}
                <span class="badge bg-dark">置信度 ${confidencePercent}%</span>
              </div>
              <div class="text-light small" style="line-height: 1.6;">${this.escapeHtml(atom.content)}</div>
            </div>
            <div class="d-flex gap-1">
              <button class="btn btn-sm btn-outline-primary" data-csp-click="novel.edit-atom" data-atom-id="${Number(atom.id)}" data-atom-type="${_escAttr(atom.atom_type)}" data-atom-content="${_escAttr(atom.content)}" title="编辑">
                <i class="bi bi-pencil"></i>
              </button>
              <button class="btn btn-sm btn-outline-danger" data-csp-click="novel.delete-atom" data-atom-id="${Number(atom.id)}" title="删除">
                <i class="bi bi-trash"></i>
              </button>
            </div>
          </div>
        </div>
      </div>
    `;
  },

  // 删除原子记忆
  async deleteAtom(atomId) {
    if (!confirm('确定要删除这条记忆吗？')) return;
    const res = await MemoryAPI.call('delete_atom', { atom_id: atomId });
    if (res.ok) {
      this.loadAtoms();
      this.loadStats();
    } else {
      alert(res.msg || '删除失败');
    }
  },

  // 场景聚类功能已整合到原子记忆（v6）：
  // 原 loadClusters / renderClusterItem / deleteCluster 死代码已移除，
  // 其调用的 create_cluster / get_clusters 后端从未实现真实存储。

  // 加载小说画像
  async loadPersona() {
    const res = await MemoryAPI.call('get_persona');
    const container = document.getElementById('persona-content');
    if (!container) return;

    if (res.ok && res.data) {
      const persona = res.data;
      container.innerHTML = `
        <div class="row g-3">
          ${this.renderPersonaSection('写作风格', persona.writing_style, 'brush', 'primary')}
          ${this.renderPersonaSection('叙事技巧', persona.narrative_techniques, 'journal-text', 'info')}
          ${this.renderPersonaSection('主题偏好', persona.theme_preferences, 'tags', 'warning')}
          ${this.renderPersonaSection('角色原型', persona.character_archetypes, 'people', 'success')}
          ${this.renderPersonaSection('世界观构建模式', persona.world_building_patterns, 'globe', 'secondary')}
          ${this.renderPersonaSection('语调一致性', persona.tone_consistency, 'chat-quote', 'danger')}
        </div>
        <div class="mt-3 text-end">
          <button class="btn btn-sm btn-outline-primary" data-csp-click="novel.edit-persona">
            <i class="bi bi-pencil me-1"></i>编辑画像
          </button>
        </div>
      `;
    } else {
      container.innerHTML = `
        <div class="text-center text-muted py-4">
          <i class="bi bi-person-badge fs-1 d-block mb-2"></i>
          <p>小说画像将随写作进度自动生成</p>
        </div>
      `;
    }
  },

  // 渲染画像部分
  renderPersonaSection(title, content, icon, color) {
    return `
      <div class="col-md-6">
        <div class="card bg-secondary border-0 h-100">
          <div class="card-body">
            <h6 class="card-title text-${color} mb-2">
              <i class="bi bi-${icon} me-1"></i>${title}
            </h6>
            <div class="small text-light" style="line-height: 1.6;">
              ${content ? this.escapeHtml(content) : '<span class="text-muted">待生成</span>'}
            </div>
          </div>
        </div>
      </div>
    `;
  },

  // 搜索记忆
  async searchMemory() {
    const keyword = document.getElementById('memorySearchInput')?.value?.trim();
    if (!keyword) return;

    const res = await MemoryAPI.call('search_atoms', { keyword });
    const container = document.getElementById('search-results');
    if (!container) return;

    if (res.ok && res.data.length > 0) {
      container.innerHTML = res.data.map(atom => this.renderAtomItem(atom)).join('');
    } else {
      container.innerHTML = `
        <div class="text-center text-muted py-4">
          <i class="bi bi-search fs-1 d-block mb-2"></i>
          <p>未找到相关记忆</p>
        </div>
      `;
    }
  },

  // 快捷检索
  async quickSearch(type) {
    const res = await MemoryAPI.call(`get_${type}_memory`, { keyword: 'all' });
    const container = document.getElementById('search-results');
    if (!container) return;

    if (res.ok && res.data && res.data.length > 0) {
      container.innerHTML = res.data.map(atom => this.renderAtomItem(atom)).join('');
    } else {
      container.innerHTML = `
        <div class="text-center text-muted py-4">
          <i class="bi bi-inbox fs-1 d-block mb-2"></i>
          <p>暂无${type === 'character' ? '角色' : type === 'plot' ? '情节' : '世界观'}记忆</p>
        </div>
      `;
    }
  },

  // 显示添加原子记忆对话框
  showAddAtomModal() {
    const html = `
      <div class="modal fade" id="addAtomModal" tabindex="-1">
        <div class="modal-dialog">
          <div class="modal-content bg-dark border-secondary">
            <div class="modal-header border-secondary">
              <h5 class="modal-title text-light"><i class="bi bi-plus-circle me-2"></i>添加原子记忆</h5>
              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <div class="mb-3">
                <label class="form-label text-light">记忆类型</label>
                <select class="form-select bg-secondary border-secondary text-light" id="newAtomType">
                  <option value="character_trait">角色特征</option>
                  <option value="plot_detail">情节细节</option>
                  <option value="world_setting">世界观设定</option>
                  <option value="style_preference">风格偏好</option>
                  <option value="constraint">约束条件</option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label text-light">记忆内容</label>
                <textarea class="form-control bg-secondary border-secondary text-light" id="newAtomContent" rows="3" placeholder="输入记忆内容..."></textarea>
              </div>
              <div class="mb-3">
                <label class="form-label text-light">来源章节（可选）</label>
                <select class="form-select bg-secondary border-secondary text-light" id="newAtomChapter">
                  <option value="">无</option>
                  ${NOVEL_PAGE_DATA.chapterNumbers.map(n => `<option value="${Number(n)}">第${Number(n)}章</option>`).join('')}
                </select>
              </div>
            </div>
            <div class="modal-footer border-secondary">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
              <button type="button" class="btn btn-primary" data-csp-click="novel.add-atom">添加</button>
            </div>
          </div>
        </div>
      </div>
    `;
    
    // 移除已存在的模态框
    document.getElementById('addAtomModal')?.remove();
    document.body.insertAdjacentHTML('beforeend', html);
    
    const modal = new bootstrap.Modal(document.getElementById('addAtomModal'));
    modal.show();
  },

  // 添加原子记忆
  async addAtom() {
    const atomType = document.getElementById('newAtomType')?.value;
    const content = document.getElementById('newAtomContent')?.value?.trim();
    const sourceChapter = document.getElementById('newAtomChapter')?.value;

    if (!content) {
      alert('请输入记忆内容');
      return;
    }

    const res = await MemoryAPI.call('add_atom', {
      atom_type: atomType,
      content,
      source_chapter: sourceChapter ? parseInt(sourceChapter) : null
    });

    if (res.ok) {
      bootstrap.Modal.getInstance(document.getElementById('addAtomModal'))?.hide();
      this.loadAtoms();
      this.loadStats();
    } else {
      alert(res.msg || '添加失败');
    }
  },

  // 显示编辑原子记忆对话框
  showEditAtomModal(atomId, atomType, content) {
    const typeOptions = ['character_trait', 'world_setting', 'plot_detail', 'style_preference', 'constraint']
      .map(t => `<option value="${t}" ${t === atomType ? 'selected' : ''}>${t === 'character_trait' ? '角色特征' : t === 'world_setting' ? '世界观' : t === 'plot_detail' ? '情节细节' : t === 'style_preference' ? '风格偏好' : '约束'}</option>`).join('');

    const html = `
      <div class="modal fade" id="editAtomModal" tabindex="-1">
        <div class="modal-dialog">
          <div class="modal-content bg-dark border-secondary">
            <div class="modal-header border-secondary">
              <h5 class="modal-title text-light"><i class="bi bi-pencil me-2"></i>编辑原子记忆</h5>
              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <input type="hidden" id="editAtomId" value="${atomId}">
              <div class="mb-3">
                <label class="form-label text-light">类型</label>
                <select class="form-select bg-secondary border-secondary text-light" id="editAtomType">
                  ${typeOptions}
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label text-light">内容</label>
                <textarea class="form-control bg-secondary border-secondary text-light" id="editAtomContent" rows="4">${this.escapeHtml(content)}</textarea>
              </div>
            </div>
            <div class="modal-footer border-secondary">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
              <button type="button" class="btn btn-primary" data-csp-click="novel.update-atom">保存</button>
            </div>
          </div>
        </div>
      </div>
    `;

    document.getElementById('editAtomModal')?.remove();
    document.body.insertAdjacentHTML('beforeend', html);

    const modal = new bootstrap.Modal(document.getElementById('editAtomModal'));
    modal.show();
  },

  // 更新原子记忆
  async updateAtom() {
    const atomId = document.getElementById('editAtomId')?.value;
    const atomType = document.getElementById('editAtomType')?.value;
    const content = document.getElementById('editAtomContent')?.value?.trim();

    if (!content) {
      alert('请输入记忆内容');
      return;
    }

    const res = await MemoryAPI.call('update_atom', {
      atom_id: parseInt(atomId),
      atom_type: atomType,
      content
    });

    if (res.ok) {
      bootstrap.Modal.getInstance(document.getElementById('editAtomModal'))?.hide();
      this.loadAtoms();
      this.loadStats();
    } else {
      alert(res.msg || '更新失败');
    }
  },

  // 编辑画像
  editPersona() {
    alert('画像编辑功能开发中...');
  },

  // 加载自动提取设置
  loadAutoExtractSetting() {
    let saved = null;
    try { saved = localStorage.getItem(`novel_${NOVEL_ID}_auto_extract`); } catch (_) {}
    const toggle = document.getElementById('autoExtractToggle');
    if (toggle) {
      toggle.checked = saved !== 'false'; // 默认开启
    }
  },

  // 保存自动提取设置
  saveAutoExtractSetting(enabled) {
    try { localStorage.setItem(`novel_${NOVEL_ID}_auto_extract`, enabled); } catch (_) {}
  },

  // 检查是否启用自动提取
  isAutoExtractEnabled() {
    return document.getElementById('autoExtractToggle')?.checked ?? true;
  },

  // 自动提取章节记忆（在章节完成时调用）
  async autoExtractChapterMemory(chapterId) {
    if (!this.isAutoExtractEnabled()) return;
    
    const res = await MemoryAPI.call('extract_chapter', { chapter_id: chapterId });
    if (res.ok) {
      this.loadStats();
      // 如果当前在记忆引擎标签页，刷新原子记忆列表
      if (document.querySelector('#tab-memory.show')) {
        this.loadAtoms();
      }
    }
  },

  // HTML转义
  escapeHtml(text) {
    // Card edit fields also use this helper inside quoted HTML attributes.
    return _escAttr(text);
  }
};

