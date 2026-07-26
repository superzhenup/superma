/**
 * CharacterNetwork - 角色关系网络（独立页面·美化版）
 */
const CharacterNetworkStandalone = {
    network: null,
    nodes: null,
    edges: null,
    allNodes: [],
    allEdges: [],
    novelId: null,
    selectedNodeId: null,
    hiddenRoles: new Set(),

    apiUrl(route, params) {
        if (typeof window.apiRouteUrl === 'function') {
            return window.apiRouteUrl(route, params || '');
        }
        // 独立关系图页面不加载 layout/app.js，保留一个同协议的相对路径 fallback。
        return 'api/index.php?route=' + encodeURIComponent(route)
            + (params ? '&' + String(params).replace(/^\?/, '') : '');
    },

    // 审计修复 M-2（2026-06-22）：统一为正则转义 5 字符，与 app.js/knowledge.js 一致。
    // 原 DOM textContent 方式不转义引号，用于属性上下文有 XSS 风险。
    escHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function(m){
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m];
        });
    },

    init(novelId) {
        this.novelId = novelId;
        this.loadData();
    },

    async loadData() {
        const container = document.getElementById('character-network-container');
        const loading = document.getElementById('cn-loading');
        const empty = document.getElementById('cn-empty');

        try {
            const response = await fetch(this.apiUrl(
                'character_network_data',
                'novel_id=' + encodeURIComponent(this.novelId)
            ));
            if (!response.ok) throw new Error('HTTP ' + response.status);
            const data = await response.json();

            if (data.ok === false) throw new Error(data.msg || '未登录');
            if (data.error) throw new Error(data.error);

            if (!data.nodes || data.nodes.length === 0) {
                if (loading) loading.style.display = 'none';
                if (empty) empty.style.display = 'flex';
                // 显示API返回的提示信息
                if (data.info) {
                    const infoEl = document.querySelector('#cn-empty .cn-overlay-sub');
                    if (infoEl) infoEl.textContent = data.info;
                }
                return;
            }

            if (typeof vis === 'undefined') throw new Error('vis-network 库未加载');

            // 清空容器
            if (loading) loading.style.display = 'none';
            if (empty) empty.style.display = 'none';
            container.innerHTML = '';

            this.allNodes = data.nodes;
            this.allEdges = data.edges;
            this.renderNetwork(container, data);
            this.updateStats(data.stats);
            this.renderCharList(data.nodes);

        } catch (error) {
            console.error('[CN]', error);
            if (loading) {
                // 审计修复 M-13（2026-06-17）：error.message 拼 innerHTML 会引入
                // DOM XSS（error.message 来自服务端 msg/error 字段，恶意服务器或
                // 上游注入可携带 <script> / 事件属性）。改用 textContent 构造。
                loading.innerHTML = '';
                var overlay = document.createElement('div');
                overlay.className = 'cn-overlay';
                var icon = document.createElement('i');
                icon.className = 'bi bi-exclamation-triangle cn-overlay-icon';
                overlay.appendChild(icon);
                var txt = document.createElement('div');
                txt.className = 'cn-overlay-text';
                txt.textContent = error.message;
                overlay.appendChild(txt);
                loading.appendChild(overlay);
            }
        }
    },

    renderNetwork(container, data) {
        this.nodes = new vis.DataSet(data.nodes);
        this.edges = new vis.DataSet(data.edges);

        const options = {
            nodes: {
                shape: 'dot',
                font: {
                    size: 13,
                    color: '#ffffff',
                    face: 'system-ui, -apple-system, sans-serif',
                },
                borderWidth: 1,
                borderWidthSelected: 2,
                shadow: { enabled: true, color: 'rgba(0,0,0,0.3)', size: 8, x: 2, y: 2 },
                color: {
                    highlight: { border: '#818cf8', background: '#818cf8' },
                    hover: { border: '#818cf8', background: '#6366f1' },
                },
            },
            edges: {
                arrows: '',
                smooth: { enabled: true, type: 'continuous', roundness: 0.3 },
                font: {
                    size: 10,
                    color: '#94a3b8',
                    face: 'system-ui',
                },
                selectionWidth: 2,
                hoverWidth: 1.5,
            },
            physics: {
                enabled: true,
                stabilization: { iterations: 200, fit: true },
                forceAtlas2Based: {
                    gravitationalConstant: -60,
                    centralGravity: 0.004,
                    springLength: 180,
                    springConstant: 0.015,
                    damping: 0.4,
                },
                solver: 'forceAtlas2Based',
                timestep: 0.35,
            },
            interaction: {
                hover: true,
                tooltipDelay: 400,
                zoomView: true,
                dragView: true,
                hideEdgesOnDrag: true,
                hideEdgesOnZoom: true,
                navigationButtons: false,
                keyboard: false,
            },
        };

        this.network = new vis.Network(container, { nodes: this.nodes, edges: this.edges }, options);

        // 点击节点
        this.network.on('click', (params) => {
            if (params.nodes.length > 0) {
                this.selectNode(params.nodes[0]);
            }
        });

        // 双击聚焦
        this.network.on('doubleClick', (params) => {
            if (params.nodes.length > 0) {
                this.focusNode(params.nodes[0]);
            }
        });

        // 悬停高亮
        this.network.on('hoverNode', (params) => {
            this.highlightConnected(params.node);
        });
        this.network.on('blurNode', () => {
            this.clearHighlight();
        });

        this.network.once('stabilizationIterationsDone', () => {
            this.network.fit({ animation: { duration: 500, easingFunction: 'easeOutQuad' } });
        });
    },

    // --- 选中节点 ---
    selectNode(nodeId) {
        this.selectedNodeId = nodeId;
        const node = this.nodes.get(nodeId);
        if (!node) return;

        // 高亮选中节点
        this.network.focus(nodeId, { scale: 1.2, animation: { duration: 400 } });

        // 更新详情面板
        this.renderDetail(node);

        // 更新列表选中状态
        document.querySelectorAll('.cn-char-item').forEach(el => {
            el.classList.toggle('active', parseInt(el.dataset.id) === nodeId);
        });
    },

    // --- 聚焦节点 ---
    focusNode(nodeId) {
        this.network.focus(nodeId, { scale: 2.0, animation: { duration: 600, easingFunction: 'easeOutQuad' } });
    },

    focusSelected() {
        if (this.selectedNodeId) {
            this.focusNode(this.selectedNodeId);
        }
    },

    // --- 悬停高亮 ---
    highlightConnected(nodeId) {
        const connectedEdges = this.edges.get({ filter: e => e.from === nodeId || e.to === nodeId });
        const connectedNodeIds = new Set([nodeId]);
        connectedEdges.forEach(e => {
            connectedNodeIds.add(e.from);
            connectedNodeIds.add(e.to);
        });

        this.nodes.forEach(n => {
            const dim = !connectedNodeIds.has(n.id);
            this.nodes.update({
                id: n.id,
                opacity: dim ? 0.15 : 1,
            });
        });
        this.edges.forEach(e => {
            const dim = e.from !== nodeId && e.to !== nodeId;
            this.edges.update({
                id: e.id,
                opacity: dim ? 0.05 : 1,
            });
        });
    },

    clearHighlight() {
        this.nodes.forEach(n => this.nodes.update({ id: n.id, opacity: 1 }));
        this.edges.forEach(e => this.edges.update({ id: e.id, opacity: 1 }));
    },

    // --- 搜索 ---
    search(query) {
        query = query.trim().toLowerCase();
        if (!query) {
            this.nodes.forEach(n => this.nodes.update({ id: n.id, opacity: 1, hidden: false }));
            this.edges.forEach(e => this.edges.update({ id: e.id, opacity: 1, hidden: false }));
            return;
        }

        const matchIds = new Set();
        this.nodes.forEach(n => {
            if (n.label.toLowerCase().includes(query) || (n.title || '').toLowerCase().includes(query)) {
                matchIds.add(n.id);
            }
        });

        this.nodes.forEach(n => {
            this.nodes.update({ id: n.id, opacity: matchIds.has(n.id) ? 1 : 0.1, hidden: false });
        });
        this.edges.forEach(e => {
            const show = matchIds.has(e.from) || matchIds.has(e.to);
            this.edges.update({ id: e.id, opacity: show ? 1 : 0.05, hidden: false });
        });

        // 聚焦第一个匹配
        if (matchIds.size > 0) {
            this.network.focus([...matchIds][0], { scale: 1.5, animation: { duration: 400 } });
        }
    },

    // --- 角色类型筛选 ---
    toggleRole(role) {
        const items = document.querySelectorAll('.cn-legend-item');
        if (this.hiddenRoles.has(role)) {
            this.hiddenRoles.delete(role);
        } else {
            this.hiddenRoles.add(role);
        }

        // 更新图例样式
        items.forEach(el => {
            const r = el.textContent.trim();
            const roleMap = { '主角': 'protagonist', '主要': 'major', '次要': 'minor', '背景': 'background' };
            const key = roleMap[r];
            if (key) el.classList.toggle('disabled', this.hiddenRoles.has(key));
        });

        // 更新节点
        this.nodes.forEach(n => {
            this.nodes.update({ id: n.id, hidden: this.hiddenRoles.has(n.group) });
        });
        this.edges.forEach(e => {
            const fn = this.nodes.get(e.from);
            const tn = this.nodes.get(e.to);
            this.edges.update({ id: e.id, hidden: !fn || !tn || fn.hidden || tn.hidden });
        });
    },

    // --- 详情面板 ---
    renderDetail(node) {
        const detail = document.getElementById('cn-detail');
        if (!detail) return;

        const roleNames = { protagonist: '主角', major: '主要角色', minor: '次要角色', background: '背景角色' };
        const roleClass = node.group || 'minor';

        // 查找关联角色
        const rels = [];
        this.edges.forEach(e => {
            if (e.from === node.id || e.to === node.id) {
                const otherId = e.from === node.id ? e.to : e.from;
                const other = this.nodes.get(otherId);
                if (other) {
                    rels.push({ id: otherId, name: other.label, type: e.label || '关联' });
                }
            }
        });

        let relHtml = '';
        if (rels.length > 0) {
            relHtml = '<ul class="cn-rel-list">' + rels.map(r =>
                `<li class="cn-rel-item">
                    <span class="cn-rel-name" onclick="CharacterNetworkStandalone.selectNode(${parseInt(r.id,10)||0})">${this.escHtml(r.name)}</span>
                    <span class="cn-rel-type">${this.escHtml(r.type)}</span>
                </li>`
            ).join('') + '</ul>';
        }

        detail.innerHTML = `
            <div class="cn-detail-header">
                <div class="cn-detail-avatar ${roleClass}">${this.escHtml(node.label.charAt(0))}</div>
                <div>
                    <div class="cn-detail-name">${this.escHtml(node.label)}</div>
                    <div class="cn-detail-role">${this.escHtml(roleNames[roleClass] || roleClass)}</div>
                </div>
            </div>
            ${node.title && node.title !== node.label ? `<div class="cn-detail-title"><i class="bi bi-tag me-1"></i>${this.escHtml(node.title)}</div>` : ''}
            ${rels.length > 0 ? `<div style="font-size:11px;color:var(--cn-text-muted);margin-bottom:6px;"><i class="bi bi-link-45deg me-1"></i>关系 (${rels.length})</div>${relHtml}` : '<div style="font-size:12px;color:var(--cn-text-muted);opacity:0.5;">暂无关系数据</div>'}
        `;
    },

    // --- 角色列表 ---
    renderCharList(nodes) {
        const list = document.getElementById('cn-char-list');
        if (!list) return;

        const sorted = [...nodes].sort((a, b) => {
            const order = { protagonist: 0, major: 1, minor: 2, background: 3 };
            return (order[a.group] ?? 3) - (order[b.group] ?? 3);
        });

        list.innerHTML = sorted.map(n => {
            const dotClass = `cn-dot-${n.group || 'minor'}`;
            const roleLabel = { protagonist: '主角', major: '主要', minor: '次要', background: '背景' }[n.group] || '';
            // 审计修复 P1-7（2026-07-12）：data-id 强制整数，防属性注入
            const safeId = parseInt(n.id, 10) || 0;
            return `<div class="cn-char-item" data-id="${safeId}" onclick="CharacterNetworkStandalone.selectNode(${safeId})">
                <span class="cn-char-dot ${dotClass}"></span>
                <span class="cn-char-name">${this.escHtml(n.label)}</span>
                <span class="cn-char-badge">${this.escHtml(roleLabel)}</span>
            </div>`;
        }).join('');
    },

    // --- 工具方法 ---
    resetView() {
        if (this.network) {
            this.network.fit({ animation: { duration: 500, easingFunction: 'easeOutQuad' } });
        }
    },

    togglePhysics() {
        if (!this.network) return;
        const btn = document.getElementById('cn-physics-btn');
        const current = this.network.getOptionsFromConfig().physics;
        const enabled = current ? current.enabled : true;
        this.network.setOptions({ physics: { enabled: !enabled } });
        if (btn) btn.classList.toggle('active', !enabled);
    },

    updateStats(stats) {
        const n = document.getElementById('cn-stats-nodes');
        const e = document.getElementById('cn-stats-edges');
        if (n) n.textContent = stats.character_count;
        if (e) e.textContent = stats.relationship_count;
    },
};
