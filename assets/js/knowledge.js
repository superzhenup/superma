/**
 * 智能知识库前端交互
 */
const NOVEL_ID = parseInt(document.querySelector('input[name="novel_id"]')?.value || '0');

// API 调用封装
function api(action, data = {}, method = 'GET') {
    const url = 'api/knowledge.php?action=' + action;
    const options = {
        method: method,
        headers: { 'Content-Type': 'application/json' }
    };
    if (method === 'POST') {
        options.body = JSON.stringify(data);
    }
    const queryStr = method === 'GET' ? '&' + new URLSearchParams(data).toString() : '';
    return fetch(url + queryStr, options).then(r => r.json());
}

function escapeHtml(text) {
    if (!text) return '';
    return String(text).replace(/[&<>"']/g, m => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[m]));
}

// 角色管理
function loadCharacters() {
    api('get_characters', { novel_id: NOVEL_ID }).then(res => {
        const tbody = document.getElementById('characters-list');
        if (!res.success || !res.data.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">暂无角色</td></tr>';
            return;
        }
    const roleTypes = { protagonist: '主角', major: '重要配角', minor: '次要' };
    const roleColors = { protagonist: 'primary', major: 'info', minor: 'secondary' };
    // 功能模板映射
    const tmplMap = {
        protagonist: { name: '主角', cls: 'primary' },
        mentor:      { name: '🧙 导师型', cls: 'success' },
        opponent:    { name: '⚔️ 对手型', cls: 'danger' },
        romantic:    { name: '💃 红颜型', cls: 'pink' },
        brother:     { name: '🤝 兄弟型', cls: 'info' },
        other:       { name: '📋 其他', cls: 'secondary' },
    };
    tbody.innerHTML = res.data.map(c => {
        var rt = c.role_template || 'other';
        var tm = tmplMap[rt] || tmplMap['other'];
        return `
            <tr>
                <td><strong>${escapeHtml(c.name)}</strong></td>
                <td><span class="badge bg-${roleColors[c.role_type] || 'secondary'}">${roleTypes[c.role_type] || c.role_type}</span></td>
                <td><span class="badge bg-${tm.cls}">${tm.name}</span></td>
                <td>${c.gender === 'male' ? '男' : c.gender === 'female' ? '女' : '-'}</td>
                <td>${escapeHtml(c.personality || '-').substring(0, 25)}</td>
                <td>
                    <button class="btn btn-sm btn-outline-primary me-1" onclick="editCharacter(${c.id})"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteCharacter(${c.id})"><i class="bi bi-trash"></i></button>
                </td>
            </tr>`;
    }).join('');
    });
}

function editCharacter(id) {
    const form = document.getElementById('form-character');
    form.reset();
    form.querySelector('[name="id"]').value = id;
    
    if (id > 0) {
        api('get_character', { id }).then(res => {
            if (res.success) {
                Object.keys(res.data).forEach(k => {
                    const input = form.querySelector('[name="' + k + '"]');
                    if (input) input.value = res.data[k] ?? '';
                });
            }
        });
    }
    new bootstrap.Modal(document.getElementById('modal-character')).show();
}

function saveCharacter() {
    const form = document.getElementById('form-character');
    const data = Object.fromEntries(new FormData(form));
    
    api('save_character', data, 'POST').then(res => {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('modal-character')).hide();
            loadCharacters();
            alert(res.message || '保存成功');
        } else {
            alert(res.message || '保存失败');
        }
    });
}

function deleteCharacter(id) {
    if (!confirm('确定删除此角色？')) return;
    api('delete_character', { id, novel_id: NOVEL_ID }, 'POST').then(res => {
        if (res.success) loadCharacters();
        else alert(res.message);
    });
}

// 世界观管理
function loadWorldbuilding() {
    api('get_worldbuilding', { novel_id: NOVEL_ID }).then(res => {
        const tbody = document.getElementById('worldbuilding-list');
        if (!res.success || !res.data.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">暂无设定</td></tr>';
            return;
        }
        const categoryNames = { location: '地点', faction: '势力', rule: '规则', item: '物品', other: '其他' };
        tbody.innerHTML = res.data.map(w => `
            <tr>
                <td><strong>${escapeHtml(w.name)}</strong></td>
                <td><span class="badge bg-info">${categoryNames[w.category] || w.category}</span></td>
                <td>${escapeHtml(w.description || '-').substring(0, 50)}</td>
                <td>
                    <button class="btn btn-sm btn-outline-primary me-1" onclick="editWorldbuilding(${w.id})"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteWorldbuilding(${w.id})"><i class="bi bi-trash"></i></button>
                </td>
            </tr>
        `).join('');
    });
}

function editWorldbuilding(id) {
    const form = document.getElementById('form-worldbuilding');
    form.reset();
    form.querySelector('[name="id"]').value = id;
    
    if (id > 0) {
        api('get_worldbuilding_item', { id }).then(res => {
            if (res.success) {
                Object.keys(res.data).forEach(k => {
                    const input = form.querySelector('[name="' + k + '"]');
                    if (input) input.value = res.data[k] ?? '';
                });
            }
        });
    }
    new bootstrap.Modal(document.getElementById('modal-worldbuilding')).show();
}

function saveWorldbuilding() {
    const form = document.getElementById('form-worldbuilding');
    const data = Object.fromEntries(new FormData(form));
    
    api('save_worldbuilding', data, 'POST').then(res => {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('modal-worldbuilding')).hide();
            loadWorldbuilding();
            alert(res.message || '保存成功');
        } else {
            alert(res.message || '保存失败');
        }
    });
}

function deleteWorldbuilding(id) {
    if (!confirm('确定删除此设定？')) return;
    api('delete_worldbuilding', { id, novel_id: NOVEL_ID }, 'POST').then(res => {
        if (res.success) loadWorldbuilding();
        else alert(res.message);
    });
}

// 情节管理
function loadPlots() {
    api('get_plots', { novel_id: NOVEL_ID }).then(res => {
        const tbody = document.getElementById('plots-list');
        if (!res.success || !res.data.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">暂无情节</td></tr>';
            return;
        }
    const eventTypes = { main: '主线', side: '支线', foreshadowing: '伏笔', callback: '呼应' };
    // 伏笔类型映射
    const fsTypeMap = {
        character: '👤 人物', item: '📦 物品', speech: '💬 言论',
        faction: '⚔️ 势力', realm: '🏔️ 境界', identity: '🎭 身份',
    };
    // 状态映射（含emoji可视化）
    const statusMap = {
        planted:    { name: '已埋设', cls: 'info', icon: '🔵' },
        active:     { name: '待回收', cls: 'warning', icon: '🟡' },
        resolving:  { name: '回收中', cls: 'success', icon: '🟢' },
        resolved:   { name: '已完成', cls: 'secondary', icon: '✅' },
        abandoned:  {name: '已作废', cls: 'danger', icon: '❌' },
    };
    tbody.innerHTML = res.data.map(p => {
        var st = p.status || 'active';
        var sm = statusMap[st] || statusMap['active'];
        var fsType = fsTypeMap[p.foreshadow_type] || '-';
        return `
            <tr>
                <td>第${p.chapter_from}章${p.chapter_to ? '-' + p.chapter_to : ''}</td>
                <td><strong>${escapeHtml(p.title)}</strong></td>
                <td><span class="badge bg-light text-dark">${fsType}</span></td>
                <td><span class="badge bg-${sm.cls}">${sm.icon} ${sm.name}</span></td>
                <td class="small text-muted" style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(p.description || '-')}</td>
                <td>
                    <button class="btn btn-sm btn-outline-primary me-1" onclick="editPlot(${p.id})"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deletePlot(${p.id})"><i class="bi bi-trash"></i></button>
                </td>
            </tr>`;
    }).join('');
    });
}

function editPlot(id) {
    const form = document.getElementById('form-plot');
    form.reset();
    form.querySelector('[name="id"]').value = id;
    
    if (id > 0) {
        api('get_plot', { id }).then(res => {
            if (res.success) {
                Object.keys(res.data).forEach(k => {
                    const input = form.querySelector('[name="' + k + '"]');
                    if (input) input.value = res.data[k] ?? '';
                });
            }
        });
    }
    new bootstrap.Modal(document.getElementById('modal-plot')).show();
}

function savePlot() {
    const form = document.getElementById('form-plot');
    const data = Object.fromEntries(new FormData(form));
    
    api('save_plot', data, 'POST').then(res => {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('modal-plot')).hide();
            loadPlots();
            alert(res.message || '保存成功');
        } else {
            alert(res.message || '保存失败');
        }
    });
}

function deletePlot(id) {
    if (!confirm('确定删除此情节？')) return;
    api('delete_plot', { id, novel_id: NOVEL_ID }, 'POST').then(res => {
        if (res.success) loadPlots();
        else alert(res.message);
    });
}

// 风格管理
function loadStyles() {
    api('get_styles', { novel_id: NOVEL_ID }).then(res => {
        const tbody = document.getElementById('styles-list');
        if (!res.success || !res.data.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">暂无风格</td></tr>';
            return;
        }
    // 风格向量标签
    const vecLabels = {
        concise:   '简洁干练',  ornate:  '华丽铺陈',  humorous: '幽默调侃',
        fast:      '快节奏',    slow:     '慢铺陈',    alternating: '交替',
        passionate:'热血',      warm:     '温馨',      dark:       '暗黑',
        strategy:  '谋略向',    power:    '力量向',    balanced:   '兼顾',
    };
    // 参考作者标签
    const authorMap = {
        chendong:  '辰东', maoni: '猫腻', ergen: '耳根',
        zhouzi:    '肘子', fenghuo: '烽火',
    };
    tbody.innerHTML = res.data.map(s => {
        var vecParts = [];
        if (s.vec_style) vecParts.push(vecLabels[s.vec_style] || s.vec_style);
        if (s.vec_pacing) vecParts.push(vecLabels[s.vec_pacing] || s.vec_pacing);
        var vecStr = vecParts.length ? vecParts.join('/') : '-';
        var refStr = (s.ref_author && authorMap[s.ref_author]) ? authorMap[s.ref_author] : '-';
        var kwStr = (s.keywords || '').split(',').filter(Boolean).slice(0,3).join(' ') || '-';
        return `
            <tr>
                <td><strong>${escapeHtml(s.name)}</strong></td>
                <td><small>${vecStr}</small></td>
                <td><span class="badge bg-light text-dark">${refStr}</span></td>
                <td><small class="text-muted">${kwStr}</small></td>
                <td>
                    <button class="btn btn-sm btn-outline-primary me-1" onclick="editStyle(${s.id})"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteStyle(${s.id})"><i class="bi bi-trash"></i></button>
                </td>
            </tr>`;
    }).join('');
    });
}

function editStyle(id) {
    const form = document.getElementById('form-style');
    form.reset();
    form.querySelector('[name="id"]').value = id;
    
    if (id > 0) {
        api('get_style', { id }).then(res => {
            if (res.success) {
                Object.keys(res.data).forEach(k => {
                    const input = form.querySelector('[name="' + k + '"]');
                    if (input) input.value = res.data[k] ?? '';
                });
            }
        });
    }
    new bootstrap.Modal(document.getElementById('modal-style')).show();
}

function saveStyle() {
    const form = document.getElementById('form-style');
    const data = Object.fromEntries(new FormData(form));
    
    api('save_style', data, 'POST').then(res => {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('modal-style')).hide();
            loadStyles();
            alert(res.message || '保存成功');
        } else {
            alert(res.message || '保存失败');
        }
    });
}

function deleteStyle(id) {
    if (!confirm('确定删除此风格？')) return;
    api('delete_style', { id, novel_id: NOVEL_ID }, 'POST').then(res => {
        if (res.success) loadStyles();
        else alert(res.message);
    });
}

// 语义搜索
function doSearch() {
    const query = document.getElementById('search-query').value.trim();
    if (!query) return;
    
    const resultsDiv = document.getElementById('search-results');
    resultsDiv.innerHTML = '<div class="text-center"><div class="spinner-border spinner-border-sm"></div> 搜索中...</div>';
    
    api('search', { novel_id: NOVEL_ID, query }).then(res => {
        if (!res.success) {
            resultsDiv.innerHTML = '<div class="alert alert-warning">' + escapeHtml(res.message) + '</div>';
            return;
        }
        
        const results = res.data;
        let html = '';
        
        if (results.character?.length) {
            html += '<div class="mb-3"><h6><i class="bi bi-people me-1"></i>相关角色</h6>';
            results.character.forEach(c => {
                html += '<div class="card mb-2"><div class="card-body py-2"><strong>' + escapeHtml(c.name || '未知角色') + '</strong><br><small>' + escapeHtml((c.content || '').substring(0, 60)) + '</small><br><small class="text-muted">相似度: ' + (c.similarity * 100).toFixed(1) + '%</small></div></div>';
            });
            html += '</div>';
        }
        
        if (results.worldbuilding?.length) {
            html += '<div class="mb-3"><h6><i class="bi bi-globe me-1"></i>相关设定</h6>';
            results.worldbuilding.forEach(w => {
                html += '<div class="card mb-2"><div class="card-body py-2">' + escapeHtml(w.content) + '<br><small class="text-muted">相似度: ' + (w.similarity * 100).toFixed(1) + '%</small></div></div>';
            });
            html += '</div>';
        }
        
        if (results.plot?.length) {
            html += '<div class="mb-3"><h6><i class="bi bi-diagram-3 me-1"></i>相关情节</h6>';
            results.plot.forEach(p => {
                html += '<div class="card mb-2"><div class="card-body py-2">' + escapeHtml(p.content) + '<br><small class="text-muted">相似度: ' + (p.similarity * 100).toFixed(1) + '%</small></div></div>';
            });
            html += '</div>';
        }
        
        if (results.style?.length) {
            html += '<div class="mb-3"><h6><i class="bi bi-palette me-1"></i>相关风格</h6>';
            results.style.forEach(s => {
                html += '<div class="card mb-2"><div class="card-body py-2">' + escapeHtml(s.content) + '<br><small class="text-muted">相似度: ' + (s.similarity * 100).toFixed(1) + '%</small></div></div>';
            });
            html += '</div>';
        }
        
        if (!html) {
            html = '<div class="alert alert-info">未找到相关内容</div>';
        }
        
        resultsDiv.innerHTML = html;
    });
}

// 初始化
document.addEventListener('DOMContentLoaded', function() {
    loadCharacters();
    loadWorldbuilding();
    loadPlots();
    loadStyles();
    
    // 搜索事件
    document.getElementById('btn-search').addEventListener('click', doSearch);
    document.getElementById('search-query').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') doSearch();
    });
});
