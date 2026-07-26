/**
 * 漫剧工作流 - 五步工作台前端交互
 * 依赖 app.js 全局函数：apiGet / apiPost / apiRouteUrl / escHtml / showToast
 */
'use strict';

// ---------------------------------------------------------------- 状态

let studioAssets = [];      // 资产列表
let studioShots = [];       // 分镜列表
let studioTasks = [];       // 当前剧集任务
let studioEpisode = null;   // 剧集对象（含 final_video_path）
let assetFilter = '';       // 资产类型筛选
let studioPollTimer = null;
let studioBusy = 0;         // 上一轮轮询的进行中任务数（用于状态翻转时全量刷新）

// 渲染签名：轮询时数据未变化则跳过对应区域重绘（避免打断视频播放 / 编辑输入）
const renderSigs = { assets: '', shots: '', images: '', videos: '', compose: '' };

const ASSET_TYPE_NAMES = { character: '角色', scene: '场景', prop: '道具' };
const SHOT_TYPES = ['远景', '全景', '中景', '近景', '特写'];
const CAMERA_MOVES = ['固定', '推', '拉', '摇', '移', '跟'];
const SHOT_STATUS_BADGES = {
    pending:       ['未出图', 'bg-secondary'],
    image_ready:   ['待生成', 'bg-info'],
    video_running: ['生成中', 'bg-primary'],
    video_done:    ['已完成', 'bg-success'],
    failed:        ['失败',   'bg-danger'],
};

// ---------------------------------------------------------------- 初始化

document.addEventListener('DOMContentLoaded', function () {
    initAssetFilter();
    initShotTableEvents();
    loadAssets();
    loadShots();
});

// ---------------------------------------------------------------- 数据加载与轮询

async function loadAssets() {
    const res = await apiGet('drama_actions', { action: 'list_assets', project_id: DRAMA_PROJECT_ID });
    if (res.ok) {
        studioAssets = res.data || [];
        renderAssets();
    }
}

async function loadShots() {
    const res = await apiGet('drama_actions', { action: 'list_shots', episode_id: DRAMA_EPISODE_ID });
    if (!res.ok) {
        showToast(res.msg || '加载分镜失败', 'error');
        scheduleStudioPoll(15000);
        return;
    }
    studioShots = (res.data && res.data.shots) || [];
    if (res.data && res.data.assets) studioAssets = res.data.assets;
    renderAll();
    scheduleStudioPoll(5000);
}

async function pollShots() {
    const res = await apiGet('drama_poll', { action: 'poll_shots', episode_id: DRAMA_EPISODE_ID });
    if (res.ok && res.data) {
        if (res.data.episode) studioEpisode = res.data.episode;
        if (res.data.shots) studioShots = res.data.shots;
        studioTasks = res.data.tasks || [];
        const busy = studioTasks.filter(t => t.status === 'pending' || t.status === 'running').length;
        // 有任务在跑（或刚跑完）时同步刷新资产（解析剧本/定妆照会改资产）
        if (busy > 0 || studioBusy > 0) {
            const aRes = await apiGet('drama_actions', { action: 'list_assets', project_id: DRAMA_PROJECT_ID });
            if (aRes.ok) studioAssets = aRes.data || [];
        }
        studioBusy = busy;
        renderAll();
        scheduleStudioPoll(busy > 0 ? 5000 : 15000);
    } else {
        scheduleStudioPoll(15000);
    }
}

function scheduleStudioPoll(delay) {
    if (studioPollTimer) clearTimeout(studioPollTimer);
    studioPollTimer = setTimeout(pollShots, delay);
}

function renderAll() {
    renderAssets();
    renderShotsTable();
    renderShotImages();
    renderShotVideos();
    renderCompose();
    renderTaskBadge();
}

// ---------------------------------------------------------------- 通用工具

function setBtnLoading(btn, loading) {
    if (!btn) return;
    if (loading) {
        btn.dataset.origHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>处理中';
    } else {
        btn.disabled = false;
        if (btn.dataset.origHtml) btn.innerHTML = btn.dataset.origHtml;
    }
}

function parseJsonArray(raw) {
    if (!raw) return [];
    try {
        const v = JSON.parse(raw);
        return Array.isArray(v) ? v : [];
    } catch (e) {
        return [];
    }
}

/** 某类型 + ref_id 是否有 pending/running 任务 */
function taskActiveFor(type, refId) {
    return studioTasks.some(t =>
        t.type === type && (t.status === 'pending' || t.status === 'running') &&
        (refId === undefined || String(t.ref_id) === String(refId))
    );
}

function shotSig(s) {
    return [s.id, s.status, s.image_path, s.video_path, s.error_msg, (s.image_candidates || '')].join('|');
}

// ================================================================ Tab1 资产

function initAssetFilter() {
    document.querySelectorAll('#asset-filter-group button').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('#asset-filter-group button').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            assetFilter = btn.dataset.type || '';
            renderAssets();
        });
    });
}

function renderAssets() {
    const grid = document.getElementById('asset-grid');
    const list = assetFilter ? studioAssets.filter(a => a.type === assetFilter) : studioAssets;
    const sig = JSON.stringify(list.map(a => [a.id, a.name, a.description, a.ref_image_path])) +
        '|' + assetFilter + '|' +
        studioTasks.filter(t => t.status === 'pending' || t.status === 'running')
            .map(t => t.type + ':' + t.ref_id).join(',');
    if (sig === renderSigs.assets) return;
    renderSigs.assets = sig;

    if (!list.length) {
        grid.innerHTML = '<div class="text-center text-muted py-4 w-100">' +
            '暂无资产，点击「解析剧本」自动提取，或「手动新建资产」</div>';
        return;
    }
    grid.innerHTML = list.map(function (a) {
        const generating = taskActiveFor('gen_asset', a.id);
        const img = a.ref_image_path
            ? '<img src="' + escHtml(a.ref_image_path) + '" class="asset-card-img" alt="">'
            : '<div class="asset-placeholder"><i class="bi bi-person-square"></i></div>';
        return '<div class="col-md-6 col-xl-4">' +
            '<div class="card h-100 border-secondary">' +
                '<div class="position-relative p-2 pb-0">' + img +
                    (generating
                        ? '<div class="shot-gen-overlay"><span class="spinner-border text-light me-2"></span>生成中</div>'
                        : '') +
                '</div>' +
                '<div class="card-body p-2">' +
                    '<div class="d-flex align-items-center gap-2 mb-2">' +
                        '<span class="badge bg-secondary">' + escHtml(ASSET_TYPE_NAMES[a.type] || a.type) + '</span>' +
                        '<input type="text" class="form-control form-control-sm" value="' + escHtml(a.name) + '" ' +
                            'onchange="saveAssetField(this, ' + escHtml(a.id) + ')" data-field="name">' +
                    '</div>' +
                    '<textarea class="form-control form-control-sm mb-2" rows="2" data-field="description" ' +
                        'onchange="saveAssetField(this, ' + escHtml(a.id) + ')">' + escHtml(a.description || '') + '</textarea>' +
                    '<div class="d-flex flex-wrap gap-1">' +
                        '<button type="button" class="btn btn-outline-primary btn-xs" onclick="genAssetImage(this, ' + escHtml(a.id) + ')">' +
                            '<i class="bi bi-magic"></i> 生成定妆照</button>' +
                        '<button type="button" class="btn btn-outline-secondary btn-xs" onclick="document.getElementById(\'asset-file-' + escHtml(a.id) + '\').click()">' +
                            '<i class="bi bi-upload"></i> 上传替换</button>' +
                        '<input type="file" id="asset-file-' + escHtml(a.id) + '" accept="image/*" style="display:none" ' +
                            'onchange="uploadAssetImage(this, ' + escHtml(a.id) + ')">' +
                        '<button type="button" class="btn btn-outline-danger btn-xs ms-auto" onclick="deleteAsset(this, ' + escHtml(a.id) + ')">' +
                            '<i class="bi bi-trash"></i></button>' +
                    '</div>' +
                '</div>' +
            '</div>' +
        '</div>';
    }).join('');
}

async function parseScript(btn) {
    setBtnLoading(btn, true);
    const res = await apiPost('drama_generate', { action: 'parse_script', episode_id: DRAMA_EPISODE_ID });
    setBtnLoading(btn, false);
    showToast(res.msg || (res.ok ? '解析任务已提交' : '提交失败'), res.ok ? 'success' : 'error');
    if (res.ok) pollShots();
}

function openAssetModal() {
    document.getElementById('asset-id').value = '0';
    document.getElementById('asset-type').value = 'character';
    document.getElementById('asset-name').value = '';
    document.getElementById('asset-desc').value = '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('assetModal')).show();
}

async function saveAssetFromModal(btn) {
    const name = document.getElementById('asset-name').value.trim();
    if (!name) {
        showToast('请填写资产名称', 'error');
        return;
    }
    setBtnLoading(btn, true);
    const res = await apiPost('drama_actions', {
        action: 'save_asset',
        project_id: DRAMA_PROJECT_ID,
        asset_id: parseInt(document.getElementById('asset-id').value, 10) || 0,
        type: document.getElementById('asset-type').value,
        name: name,
        description: document.getElementById('asset-desc').value.trim(),
    });
    setBtnLoading(btn, false);
    showToast(res.msg || (res.ok ? '已保存' : '保存失败'), res.ok ? 'success' : 'error');
    if (res.ok) {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('assetModal')).hide();
        loadAssets();
    }
}

async function saveAssetField(el, assetId) {
    const asset = studioAssets.find(a => String(a.id) === String(assetId));
    if (!asset) return;
    asset[el.dataset.field] = el.value;
    const res = await apiPost('drama_actions', {
        action: 'save_asset',
        project_id: DRAMA_PROJECT_ID,
        asset_id: assetId,
        type: asset.type,
        name: asset.name,
        description: asset.description || '',
    });
    if (!res.ok) showToast(res.msg || '保存失败', 'error');
}

async function genAssetImage(btn, assetId) {
    setBtnLoading(btn, true);
    const res = await apiPost('drama_generate', {
        action: 'gen_asset_image', project_id: DRAMA_PROJECT_ID, asset_id: assetId,
    });
    setBtnLoading(btn, false);
    showToast(res.msg || (res.ok ? '生成任务已提交' : '提交失败'), res.ok ? 'success' : 'error');
    if (res.ok) pollShots();
}

async function uploadAssetImage(input, assetId) {
    if (!input.files || !input.files[0]) return;
    const ok = await uploadMedia('upload_asset_image', { asset_id: assetId }, input.files[0]);
    input.value = '';
    if (ok) loadAssets();
}

async function deleteAsset(btn, assetId) {
    if (!confirm('确认删除该资产？其定妆照将一并删除。')) return;
    setBtnLoading(btn, true);
    const res = await apiPost('drama_actions', {
        action: 'delete_asset', project_id: DRAMA_PROJECT_ID, asset_id: assetId,
    });
    setBtnLoading(btn, false);
    showToast(res.msg || (res.ok ? '已删除' : '删除失败'), res.ok ? 'success' : 'error');
    if (res.ok) loadAssets();
}

// ================================================================ Tab2 分镜

function initShotTableEvents() {
    const wrap = document.getElementById('shot-table-wrap');
    // 下拉变更立即保存
    wrap.addEventListener('change', function (e) {
        const row = e.target.closest('tr[data-shot-id]');
        if (row) saveShot(row);
    });
    // 文本域失焦保存
    wrap.addEventListener('focusout', function (e) {
        if (e.target.tagName !== 'TEXTAREA') return;
        const row = e.target.closest('tr[data-shot-id]');
        if (row) saveShot(row);
    });
}

function renderShotsTable() {
    const wrap = document.getElementById('shot-table-wrap');
    const sig = JSON.stringify(studioShots.map(s => [
        s.id, s.shot_no, s.scene_desc, s.shot_type, s.camera_movement, s.characters,
        s.dialogue, s.image_prompt, s.video_prompt, s.duration,
    ])) + '|' + studioAssets.length;
    if (sig === renderSigs.shots) return;
    renderSigs.shots = sig;

    if (!studioShots.length) {
        wrap.innerHTML = '<div class="text-center text-muted py-4">' +
            '暂无分镜。先在「资产」页解析剧本，再点击「AI 生成分镜」，或手动「添加分镜」</div>';
        return;
    }
    const charAssets = studioAssets.filter(a => a.type === 'character');
    const rows = studioShots.map(function (s) {
        const selChars = parseJsonArray(s.characters).map(String);
        const charOpts = charAssets.map(a =>
            '<option value="' + escHtml(a.id) + '"' + (selChars.includes(String(a.id)) ? ' selected' : '') + '>' +
            escHtml(a.name) + '</option>'
        ).join('');
        return '<tr data-shot-id="' + escHtml(s.id) + '">' +
            '<td class="text-center">' + escHtml(s.shot_no) + '</td>' +
            '<td><select class="form-select form-select-sm" data-field="shot_type">' +
                SHOT_TYPES.map(t => '<option' + (s.shot_type === t ? ' selected' : '') + '>' + t + '</option>').join('') +
            '</select></td>' +
            '<td><select class="form-select form-select-sm" data-field="camera_movement">' +
                CAMERA_MOVES.map(t => '<option' + (s.camera_movement === t ? ' selected' : '') + '>' + t + '</option>').join('') +
            '</select></td>' +
            '<td><textarea class="form-control form-control-sm" data-field="scene_desc" rows="2">' + escHtml(s.scene_desc || '') + '</textarea></td>' +
            '<td><select multiple class="form-select form-select-sm" data-field="characters" size="2" title="按住 Ctrl 多选">' +
                charOpts +
            '</select></td>' +
            '<td><textarea class="form-control form-control-sm" data-field="dialogue" rows="2">' + escHtml(s.dialogue || '') + '</textarea></td>' +
            '<td><textarea class="form-control form-control-sm" data-field="image_prompt" rows="2">' + escHtml(s.image_prompt || '') + '</textarea></td>' +
            '<td><textarea class="form-control form-control-sm" data-field="video_prompt" rows="2">' + escHtml(s.video_prompt || '') + '</textarea></td>' +
            '<td><select class="form-select form-select-sm" data-field="duration">' +
                [5, 10].map(d => '<option value="' + d + '"' + (String(s.duration) === String(d) ? ' selected' : '') + '>' + d + ' 秒</option>').join('') +
            '</select></td>' +
            '<td><button type="button" class="btn btn-outline-danger btn-xs" onclick="deleteShot(this, ' + escHtml(s.id) + ')">' +
                '<i class="bi bi-trash"></i></button></td>' +
        '</tr>';
    }).join('');
    wrap.innerHTML = '<div class="table-responsive"><table class="table table-sm align-middle mb-0">' +
        '<thead><tr>' +
        '<th style="width:48px" class="text-center">镜号</th><th style="width:80px">景别</th><th style="width:70px">运镜</th>' +
        '<th>画面描述</th><th style="width:120px">出场角色</th><th>对白</th><th>图 Prompt</th><th>视频 Prompt</th>' +
        '<th style="width:80px">时长</th><th style="width:44px"></th>' +
        '</tr></thead><tbody>' + rows + '</tbody></table></div>';
}

async function saveShot(row) {
    const shotId = row.dataset.shotId;
    const get = f => row.querySelector('[data-field="' + f + '"]');
    const characters = Array.from(get('characters').selectedOptions).map(o => parseInt(o.value, 10));
    const res = await apiPost('drama_actions', {
        action: 'save_shot',
        shot_id: parseInt(shotId, 10),
        shot_type: get('shot_type').value,
        camera_movement: get('camera_movement').value,
        scene_desc: get('scene_desc').value,
        characters: characters,
        dialogue: get('dialogue').value,
        image_prompt: get('image_prompt').value,
        video_prompt: get('video_prompt').value,
        duration: parseInt(get('duration').value, 10),
    });
    if (res.ok) {
        // 同步本地状态，避免轮询重绘打断正在进行的编辑
        const s = studioShots.find(x => String(x.id) === String(shotId));
        if (s) {
            s.shot_type = get('shot_type').value;
            s.camera_movement = get('camera_movement').value;
            s.scene_desc = get('scene_desc').value;
            s.characters = JSON.stringify(characters);
            s.dialogue = get('dialogue').value;
            s.image_prompt = get('image_prompt').value;
            s.video_prompt = get('video_prompt').value;
            s.duration = get('duration').value;
        }
    } else {
        showToast(res.msg || '分镜保存失败', 'error');
    }
}

async function generateStoryboard(btn) {
    if (studioShots.length && !confirm('AI 生成分镜将覆盖现有 ' + studioShots.length + ' 条分镜，确认继续？')) return;
    setBtnLoading(btn, true);
    const res = await apiPost('drama_generate', {
        action: 'generate_storyboard',
        episode_id: DRAMA_EPISODE_ID,
        target_shots: parseInt(document.getElementById('target-shots').value, 10) || 12,
    });
    setBtnLoading(btn, false);
    showToast(res.msg || (res.ok ? '分镜生成任务已提交' : '提交失败'), res.ok ? 'success' : 'error');
    if (res.ok) pollShots();
}

async function addShot(btn) {
    setBtnLoading(btn, true);
    const res = await apiPost('drama_actions', { action: 'add_shot', episode_id: DRAMA_EPISODE_ID });
    setBtnLoading(btn, false);
    showToast(res.msg || (res.ok ? '已添加分镜' : '添加失败'), res.ok ? 'success' : 'error');
    if (res.ok) loadShots();
}

async function deleteShot(btn, shotId) {
    if (!confirm('确认删除该分镜？')) return;
    setBtnLoading(btn, true);
    const res = await apiPost('drama_actions', { action: 'delete_shot', shot_id: shotId });
    setBtnLoading(btn, false);
    showToast(res.msg || (res.ok ? '已删除' : '删除失败'), res.ok ? 'success' : 'error');
    if (res.ok) loadShots();
}

// ================================================================ Tab3 分镜图

function renderShotImages() {
    const grid = document.getElementById('shot-image-grid');
    const sig = JSON.stringify(studioShots.map(shotSig)) + '|' +
        studioTasks.filter(t => (t.status === 'pending' || t.status === 'running') && t.type === 'gen_shot_image')
            .map(t => t.ref_id).join(',');
    if (sig === renderSigs.images) return;
    renderSigs.images = sig;

    if (!studioShots.length) {
        grid.innerHTML = '<div class="text-center text-muted py-4 w-100">暂无分镜，请先在「分镜」页生成分镜</div>';
        return;
    }
    grid.innerHTML = studioShots.map(function (s) {
        const candidates = parseJsonArray(s.image_candidates);
        const generating = taskActiveFor('gen_shot_image', s.id);
        const main = s.image_path
            ? '<img src="' + escHtml(s.image_path) + '" class="shot-card-img" alt="">'
            : '<div class="shot-placeholder"><i class="bi bi-image"></i></div>';
        const thumbs = candidates.length
            ? '<div class="d-flex flex-wrap gap-1 mt-2">' + candidates.map(function (p) {
                return '<img src="' + escHtml(p) + '" class="candidate-thumb' + (p === s.image_path ? ' selected' : '') + '" ' +
                    'data-shot-id="' + escHtml(s.id) + '" data-path="' + escHtml(p) + '" ' +
                    'onclick="selectShotImage(this.dataset.shotId, this.dataset.path)" title="点击选定">';
            }).join('') + '</div>'
            : '';
        return '<div class="col-md-6 col-xl-4">' +
            '<div class="card h-100 border-secondary">' +
                '<div class="position-relative p-2 pb-0">' + main +
                    (generating
                        ? '<div class="shot-gen-overlay"><span class="spinner-border text-light me-2"></span>抽卡中</div>'
                        : '') +
                '</div>' +
                '<div class="card-body p-2">' +
                    '<div class="d-flex justify-content-between align-items-center mb-1">' +
                        '<strong>#' + escHtml(s.shot_no) + '</strong>' +
                        '<small class="text-muted text-truncate ms-2">' + escHtml(s.scene_desc || '') + '</small>' +
                    '</div>' +
                    thumbs +
                    '<div class="d-flex gap-1 mt-2">' +
                        '<button type="button" class="btn btn-outline-primary btn-xs" onclick="genShotImages(this, [' + escHtml(s.id) + '])">' +
                            '<i class="bi bi-stars"></i> 抽卡</button>' +
                        '<button type="button" class="btn btn-outline-secondary btn-xs" onclick="document.getElementById(\'shot-file-' + escHtml(s.id) + '\').click()">' +
                            '<i class="bi bi-upload"></i> 上传首帧图</button>' +
                        '<input type="file" id="shot-file-' + escHtml(s.id) + '" accept="image/*" style="display:none" ' +
                            'onchange="uploadShotImage(this, ' + escHtml(s.id) + ')">' +
                    '</div>' +
                '</div>' +
            '</div>' +
        '</div>';
    }).join('');
}

async function genShotImages(btn, shotIds) {
    setBtnLoading(btn, true);
    const payload = {
        action: 'gen_shot_images',
        episode_id: DRAMA_EPISODE_ID,
        batch: parseInt(document.getElementById('image-batch').value, 10) || 2,
    };
    if (shotIds && shotIds.length) payload.shot_ids = shotIds;
    const res = await apiPost('drama_generate', payload);
    setBtnLoading(btn, false);
    showToast(res.msg || (res.ok ? '抽卡任务已提交' : '提交失败'), res.ok ? 'success' : 'error');
    if (res.ok) pollShots();
}

async function selectShotImage(shotId, imagePath) {
    const res = await apiPost('drama_actions', {
        action: 'select_shot_image', shot_id: shotId, image_path: imagePath,
    });
    if (res.ok) {
        const s = studioShots.find(x => String(x.id) === String(shotId));
        if (s) {
            s.image_path = imagePath;
            if (s.status === 'pending') s.status = 'image_ready';
        }
        renderSigs.images = '';
        renderShotImages();
    } else {
        showToast(res.msg || '选定失败', 'error');
    }
}

async function uploadShotImage(input, shotId) {
    if (!input.files || !input.files[0]) return;
    const ok = await uploadMedia('upload_shot_image', { shot_id: shotId }, input.files[0]);
    input.value = '';
    if (ok) loadShots();
}

// ================================================================ Tab4 视频

function renderShotVideos() {
    const grid = document.getElementById('shot-video-grid');
    const sig = JSON.stringify(studioShots.map(shotSig));
    if (sig === renderSigs.videos) return;
    renderSigs.videos = sig;

    if (!studioShots.length) {
        grid.innerHTML = '<div class="text-center text-muted py-4 w-100">暂无分镜，请先在「分镜」页生成分镜</div>';
        return;
    }
    grid.innerHTML = studioShots.map(function (s) {
        const [label, cls] = SHOT_STATUS_BADGES[s.status] || [s.status, 'bg-secondary'];
        const thumb = s.image_path
            ? '<img src="' + escHtml(s.image_path) + '" class="shot-card-img" alt="">'
            : '<div class="shot-placeholder"><i class="bi bi-film"></i></div>';
        let body = '';
        if (s.status === 'video_done' && s.video_path) {
            body += '<video controls class="w-100 rounded mb-2" src="' + escHtml(s.video_path) + '" style="background:#000"></video>';
            body += '<button type="button" class="btn btn-outline-warning btn-xs" onclick="genShotVideos(this, [' + escHtml(s.id) + '])">' +
                '<i class="bi bi-arrow-repeat"></i> 重新生成</button>';
        } else if (s.status === 'image_ready') {
            body += '<button type="button" class="btn btn-outline-primary btn-xs" onclick="genShotVideos(this, [' + escHtml(s.id) + '])">' +
                '<i class="bi bi-play-circle"></i> 生成视频</button>';
        } else if (s.status === 'video_running') {
            body += '<div class="text-primary small"><span class="spinner-border spinner-border-sm me-1"></span>视频生成中...</div>';
        }
        if (s.status === 'failed' && s.error_msg) {
            body += '<div class="text-danger small mt-1">' + escHtml(s.error_msg) + '</div>';
        }
        return '<div class="col-md-6 col-xl-4">' +
            '<div class="card h-100 border-secondary">' +
                '<div class="p-2 pb-0">' + thumb + '</div>' +
                '<div class="card-body p-2">' +
                    '<div class="d-flex justify-content-between align-items-center mb-2">' +
                        '<strong>#' + escHtml(s.shot_no) + '</strong>' +
                        '<span class="badge ' + cls + '">' + escHtml(label) + '</span>' +
                    '</div>' +
                    body +
                '</div>' +
            '</div>' +
        '</div>';
    }).join('');
}

async function genShotVideos(btn, shotIds) {
    setBtnLoading(btn, true);
    const payload = { action: 'gen_shot_videos', episode_id: DRAMA_EPISODE_ID };
    if (shotIds && shotIds.length) payload.shot_ids = shotIds;
    const res = await apiPost('drama_generate', payload);
    setBtnLoading(btn, false);
    showToast(res.msg || (res.ok ? '视频任务已提交' : '提交失败'), res.ok ? 'success' : 'error');
    if (res.ok) pollShots();
}

// ================================================================ Tab5 合成

function renderCompose() {
    const total = studioShots.length;
    const imgReady = studioShots.filter(s => s.image_path).length;
    const vidDone = studioShots.filter(s => s.status === 'video_done').length;
    const finalVideo = studioEpisode ? (studioEpisode.final_video_path || '') : '';
    const sig = [total, imgReady, vidDone, finalVideo].join('|');
    if (sig === renderSigs.compose) return;
    renderSigs.compose = sig;

    document.getElementById('stat-total').textContent = total;
    document.getElementById('stat-image').textContent = imgReady;
    document.getElementById('stat-video').textContent = vidDone;

    const composeBtn = document.getElementById('compose-btn');
    const hint = document.getElementById('compose-hint');
    if (total > 0 && vidDone === total) {
        composeBtn.disabled = false;
        hint.textContent = '全部分镜视频已完成，可以合成成片。';
    } else {
        composeBtn.disabled = true;
        hint.textContent = '还有 ' + (total - vidDone) + ' 条分镜未完成视频生成，全部完成后才能合成成片。';
    }

    const box = document.getElementById('final-video-box');
    if (finalVideo) {
        const v = document.getElementById('final-video');
        if (v.getAttribute('src') !== finalVideo) v.src = finalVideo;
        document.getElementById('final-video-link').href = finalVideo;
        box.style.display = '';
    } else {
        box.style.display = 'none';
    }
}

async function composeEpisode(btn) {
    setBtnLoading(btn, true);
    const res = await apiPost('drama_generate', { action: 'compose_episode', episode_id: DRAMA_EPISODE_ID });
    setBtnLoading(btn, false);
    showToast(res.msg || (res.ok ? '合成任务已提交' : '提交失败'), res.ok ? 'success' : 'error');
    if (res.ok) pollShots();
}

async function exportZip(btn) {
    setBtnLoading(btn, true);
    const res = await apiPost('drama_generate', { action: 'export_zip', episode_id: DRAMA_EPISODE_ID });
    setBtnLoading(btn, false);
    if (res.ok && res.data && res.data.path) {
        document.getElementById('export-link').href = res.data.path;
        document.getElementById('export-result').style.display = '';
        showToast(res.msg || '素材包已导出', 'success');
    } else {
        showToast(res.msg || '导出失败', 'error');
    }
}

async function cancelTasks(btn) {
    if (!confirm('确认取消该剧集全部待执行任务？进行中的任务不受影响。')) return;
    setBtnLoading(btn, true);
    const res = await apiPost('drama_generate', { action: 'cancel_episode_tasks', episode_id: DRAMA_EPISODE_ID });
    setBtnLoading(btn, false);
    showToast(res.msg || (res.ok ? '已取消' : '操作失败'), res.ok ? 'success' : 'error');
    if (res.ok) pollShots();
}

// ---------------------------------------------------------------- 任务徽标

function renderTaskBadge() {
    const badge = document.getElementById('studio-task-badge');
    const active = studioTasks.filter(t => t.status === 'pending' || t.status === 'running');
    if (active.length) {
        badge.style.display = '';
        badge.className = 'badge bg-primary';
        badge.textContent = '进行中任务 ' + active.length + ' 个';
    } else {
        badge.style.display = 'none';
    }
}

// ---------------------------------------------------------------- 媒体上传

/** FormData 原生 fetch 上传（CSRF 由全局 fetch 拦截器注入），返回是否成功。 */
async function uploadMedia(action, fields, file) {
    const fd = new FormData();
    fd.append('action', action);
    Object.keys(fields).forEach(k => fd.append(k, fields[k]));
    fd.append('media_file', file);
    let res;
    try {
        res = await fetch(apiRouteUrl('drama_media'), { method: 'POST', body: fd });
    } catch (e) {
        showToast('网络请求失败，请检查网络连接', 'error');
        return false;
    }
    let data;
    try {
        data = await res.json();
    } catch (e) {
        showToast('上传失败：服务返回异常', 'error');
        return false;
    }
    showToast(data.msg || (data.ok ? '上传成功' : '上传失败'), data.ok ? 'success' : 'error');
    return !!data.ok;
}
