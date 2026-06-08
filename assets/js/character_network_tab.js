/**
 * CharacterNetwork - 角色关系网络（集成到 novel.php 标签页）
 * 懒加载：仅当标签页第一次显示时才加载 vis-network 和渲染
 */
const CharacterNetwork = {
    network: null,
    nodes: null,
    edges: null,
    data: null,
    initialized: false,
    novelId: null,
    visibleRoles: ['protagonist', 'antagonist', 'supporting', 'minor'],

    /**
     * 初始化（在标签页第一次显示时调用）
     */
    init(novelId) {
        if (this.initialized) return;
        this.novelId = novelId;
        this.initialized = true;

        // 动态加载 vis-network CSS + JS
        this.loadVisLibrary(() => {
            this.loadData();
        });
    },

    /**
     * 动态加载 vis-network 库
     */
    loadVisLibrary(callback) {
        // 加载 CSS
        if (!document.querySelector('link[href*="vis-network"]')) {
            const css = document.createElement('link');
            css.rel = 'stylesheet';
            css.href = 'https://cdn.jsdelivr.net/npm/vis-network@9.1.9/dist/vis-network.min.css';
            document.head.appendChild(css);
        }

        // 加载 JS
        if (typeof vis !== 'undefined') {
            callback();
            return;
        }

        const js = document.createElement('script');
        js.src = 'https://cdn.jsdelivr.net/npm/vis-network@9.1.9/dist/vis-network.min.js';
        js.onload = callback;
        js.onerror = () => {
            document.getElementById('character-network-loading').innerHTML =
                '<div class="text-center text-muted">' +
                '<i class="bi bi-wifi-off fs-1 d-block mb-2"></i>' +
                '<p>加载可视化库失败，请检查网络连接</p>' +
                '</div>';
        };
        document.head.appendChild(js);
    },

    /**
     * 加载角色关系数据
     */
    async loadData() {
        try {
            const response = await fetch(`api/character_network_data.php?novel_id=${this.novelId}`);
            const data = await response.json();

            if (data.error) {
                throw new Error(data.error);
            }

            if (!data.nodes || data.nodes.length === 0) {
                document.getElementById('character-network-loading').style.display = 'none';
                document.getElementById('character-network-empty').style.display = 'flex';
                return;
            }

            this.renderNetwork(data);
            this.updateStats(data.stats);

            document.getElementById('character-network-loading').style.display = 'none';
            document.getElementById('character-network-canvas').style.display = 'block';
        } catch (error) {
            console.error('加载角色关系数据失败:', error);
            document.getElementById('character-network-loading').innerHTML =
                '<div class="text-center text-muted">' +
                '<i class="bi bi-exclamation-triangle fs-1 d-block mb-2"></i>' +
                '<p>加载失败: ' + error.message + '</p>' +
                '</div>';
        }
    },

    /**
     * 渲染网络图
     */
    renderNetwork(data) {
        const container = document.getElementById('character-network-canvas');

        this.nodes = new vis.DataSet(data.nodes);
        this.edges = new vis.DataSet(data.edges);
        this.data = { nodes: this.nodes, edges: this.edges };

        const options = {
            nodes: {
                shape: 'circle',
                size: 25,
                font: {
                    size: 14,
                    color: '#ffffff',
                },
                borderWidth: 2,
                shadow: true,
                color: {
                    background: '#666',
                    border: '#999',
                    highlight: { background: '#999', border: '#fff' }
                }
            },
            edges: {
                width: 2,
                color: {
                    color: '#666',
                    highlight: '#fff',
                },
                arrows: {
                    to: { enabled: true, scaleFactor: 1, type: 'arrow' }
                },
                smooth: { enabled: true, type: 'cubicBezier' },
                font: {
                    size: 12,
                    color: '#aaa',
                    strokeWidth: 0,
                },
            },
            physics: {
                enabled: true,
                stabilization: { iterations: 100 },
                barnesHut: {
                    gravitationalConstant: -2000,
                    centralGravity: 0.3,
                    springLength: 150,
                    springConstant: 0.05,
                },
            },
            interaction: {
                hover: true,
                tooltipDelay: 200,
                hideEdgesOnDrag: false,
                zoomView: true,
                dragView: true,
            },
            layout: {
                improvedLayout: true,
            },
        };

        this.network = new vis.Network(container, this.data, options);

        // 点击节点时高亮相关边
        this.network.on('click', (params) => {
            if (params.nodes.length > 0) {
                this.highlightNode(params.nodes[0]);
            } else {
                this.clearHighlight();
            }
        });

        this.network.once('stabilizationIterationsDone', () => {
            this.network.fit();
        });
    },

    /**
     * 高亮选中节点及其关联的边
     */
    highlightNode(nodeId) {
        const connectedEdges = this.edges.get({
            filter: (edge) => edge.from === nodeId || edge.to === nodeId
        });

        // 重置所有节点和边的样式
        this.nodes.forEach(node => {
            this.nodes.update({
                id: node.id,
                opacity: 0.3,
                label: '',
            });
        });
        this.edges.forEach(edge => {
            this.edges.update({
                id: edge.id,
                width: 1,
                color: { color: '#333' },
            });
        });

        // 高亮选中节点
        this.nodes.update({
            id: nodeId,
            opacity: 1,
            label: this.nodes.get(nodeId).label,
        });

        // 高亮关联节点和边
        connectedEdges.forEach(edge => {
            const otherId = edge.from === nodeId ? edge.to : edge.from;
            this.nodes.update({
                id: otherId,
                opacity: 0.8,
                label: this.nodes.get(otherId).label,
            });
            this.edges.update({
                id: edge.id,
                width: 3,
                color: { color: '#4CAF50' },
            });
        });
    },

    /**
     * 清除高亮
     */
    clearHighlight() {
        this.nodes.forEach(node => {
            this.nodes.update({
                id: node.id,
                opacity: 1,
                label: node.label,
            });
        });
        this.edges.forEach(edge => {
            this.edges.update({
                id: edge.id,
                width: 2,
                color: { color: '#666' },
            });
        });
    },

    /**
     * 更新统计信息
     */
    updateStats(stats) {
        const nodesEl = document.getElementById('cn-stats-nodes');
        const edgesEl = document.getElementById('cn-stats-edges');
        if (nodesEl) nodesEl.textContent = stats.character_count;
        if (edgesEl) edgesEl.textContent = stats.relationship_count;
    },

    /**
     * 重置视图
     */
    resetView() {
        if (this.network) {
            this.network.fit();
            this.network.setOptions({ physics: true });
        }
    },

    /**
     * 切换物理效果
     */
    togglePhysics() {
        if (!this.network) return;
        const current = this.network.getOptionsFromConfig().physics;
        this.network.setOptions({ physics: !current });
    },

    /**
     * 按角色类型筛选
     */
    filterByRole() {
        if (!this.nodes) return;

        const checkboxes = {
            'protagonist': document.getElementById('cn-filter-protagonist'),
            'antagonist': document.getElementById('cn-filter-antagonist'),
            'supporting': document.getElementById('cn-filter-supporting'),
            'minor': document.getElementById('cn-filter-minor'),
        };

        this.visibleRoles = [];
        for (const [role, cb] of Object.entries(checkboxes)) {
            if (cb && cb.checked) {
                this.visibleRoles.push(role);
            }
        }

        // 显示/隐藏节点
        this.nodes.forEach(node => {
            const hidden = !this.visibleRoles.includes(node.group);
            this.nodes.update({ id: node.id, hidden: hidden });
        });

        // 隐藏没有可见节点的边
        this.edges.forEach(edge => {
            const fromNode = this.nodes.get(edge.from);
            const toNode = this.nodes.get(edge.to);
            const hidden = !fromNode || !toNode || fromNode.hidden || toNode.hidden;
            this.edges.update({ id: edge.id, hidden: hidden });
        });
    },
};

/**
 * 监听标签页切换，懒加载角色关系网络
 */
document.addEventListener('DOMContentLoaded', () => {
    const tabTrigger = document.getElementById('tab-characters-trigger');
    if (tabTrigger) {
        tabTrigger.addEventListener('shown.bs.tab', () => {
            // 从 URL 或全局变量获取 novel_id
            const urlParams = new URLSearchParams(window.location.search);
            const novelId = urlParams.get('id') || (typeof NOVEL_ID !== 'undefined' ? NOVEL_ID : 0);
            if (novelId) {
                CharacterNetwork.init(novelId);
            }
        });
    }
});
