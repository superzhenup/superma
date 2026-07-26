/**
 * 漫剧工作流 - 项目页前端交互
 * 依赖 app.js 全局函数：apiGet / apiPost / apiRouteUrl / escHtml / showToast
 */
'use strict';

// ---------------------------------------------------------------- 状态

let dramaProject = null;      // 项目对象
let dramaEpisodes = [];       // 剧集列表
let dramaTasks = [];          // 任务列表
let dramaPollTimer = null;    // 轮询定时器
let dramaFormFilled = false;  // 设置表单是否已填充（避免轮询覆盖用户输入）
let videoModalAutoOpened = false;
let videoProviders = [];      // 视频服务商列表（切 provider 时填 default_base）

const TASK_TYPE_NAMES = {
    parse_script:   '解析剧本',
    gen_storyboard: 'AI 生成分镜',
    gen_asset:      '资产定妆照',
    gen_shot_image: '分镜图生成',
    gen_shot_video: '分镜视频生成',
    compose_episode:'合成成片',
};

const TASK_STATUS_BADGES = {
    pending:  ['等待中', 'bg-secondary'],
    running:  ['进行中', 'bg-primary'],
    done:     ['已完成', 'bg-success'],
    failed:   ['失败',   'bg-danger'],
    canceled: ['已取消', 'bg-dark'],
};

const SCRIPT_STATUS_NAMES = {
    pending:     ['未解析',     'bg-secondary'],
    parsed:      ['剧本已解析', 'bg-info'],
    storyboarded:['分镜已生成', 'bg-success'],
    failed:      ['解析失败',   'bg-danger'],
};

// ---------------------------------------------------------------- 初始化

document.addEventListener('DOMContentLoaded', function () {
    loadProject();
});

// ---------------------------------------------------------------- 数据加载

async function loadProject() {
    const res = await apiGet('drama_actions', { action: 'get_project', novel_id: DRAMA_NOVEL_ID });
    if (!res.ok) {
        showToast(res.msg || '加载项目失败', 'error');
        renderEpisodeError(res.msg || '加载失败');
        schedulePoll(15000);
        return;
    }
    applyProjectData(res.data || {});
    schedulePoll(5000);
}

async function pollProject() {
    if (!dramaProject) return;
    const res = await apiGet('drama_poll', { action: 'poll', project_id: dramaProject.id });
    if (res.ok && res.data) {
        if (res.data.project) dramaProject = res.data.project;
        dramaEpisodes = res.data.episodes || dramaEpisodes;
        dramaTasks = res.data.tasks || [];
        renderEpisodes();
        renderTasks();
        schedulePoll((res.data.busy || 0) > 0 ? 5000 : 15000);
    } else {
        schedulePoll(15000);
    }
}

function schedulePoll(delay) {
    if (dramaPollTimer) clearTimeout(dramaPollTimer);
    dramaPollTimer = setTimeout(pollProject, delay);
}

// ---------------------------------------------------------------- 渲染

function applyProjectData(d) {
    dramaProject = d.project || null;
    dramaEpisodes = d.episodes || [];
    dramaTasks = d.tasks || [];

    renderEngineBadges(d);
    renderEnvBanner(d);

    if (dramaProject && !dramaFormFilled) {
        document.getElementById('proj-title').value = dramaProject.title || '';
        document.getElementById('proj-style').value = dramaProject.style_prompt || '';
        document.getElementById('proj-negative').value = dramaProject.style_negative || '';
        document.getElementById('proj-image-size').value = dramaProject.image_size || '1280x720';
        dramaFormFilled = true;
    }

    const assetsCount = document.getElementById('assets-count-text');
    if (assetsCount) assetsCount.textContent = '资产 ' + (d.assets_count || 0) + ' 个';

    renderEpisodes();
    renderTasks();

    // 视频引擎未配置：首次加载自动弹出配置窗
    if (!d.video_configured && !videoModalAutoOpened) {
        videoModalAutoOpened = true;
        openVideoConfig();
    }
}

function renderEngineBadges(d) {
    const imgBadge = document.getElementById('image-engine-badge');
    const vidBadge = document.getElementById('video-engine-badge');
    if (d.image_configured) {
        imgBadge.innerHTML = '<span class="badge bg-success"><i class="bi bi-image me-1"></i>图片引擎 已配置</span>';
    } else {
        imgBadge.innerHTML = '<a href="settings.php" class="badge bg-danger text-decoration-none">' +
            '<i class="bi bi-image me-1"></i>图片引擎 未配置</a>';
    }
    if (d.video_configured) {
        vidBadge.innerHTML = '<span class="badge bg-success"><i class="bi bi-camera-video me-1"></i>视频引擎 已配置</span>';
    } else {
        vidBadge.innerHTML = '<a href="javascript:void(0)" class="badge bg-danger text-decoration-none" onclick="openVideoConfig()">' +
            '<i class="bi bi-camera-video me-1"></i>视频引擎 未配置</a>';
    }
}

function renderEnvBanner(d) {
    const banner = document.getElementById('env-banner');
    const text = document.getElementById('env-banner-text');
    const msgs = [];
    if (d.exec_available === false) msgs.push('服务器 PHP exec 不可用，后台任务将由页面轮询内联驱动，处理速度较慢');
    if (d.ffmpeg_available === false) msgs.push('服务器未检测到 FFmpeg，在线合成不可用，可使用「导出素材包」自行合成');
    if (msgs.length) {
        text.textContent = msgs.join('；') + '。';
        banner.style.display = '';
    } else {
        banner.style.display = 'none';
    }
}

function episodeStatusBadge(ep) {
    const key = ep.script_status || 'pending';
    const [label, cls] = SCRIPT_STATUS_NAMES[key] || [key, 'bg-secondary'];
    return '<span class="badge ' + cls + '">' + escHtml(label) + '</span>';
}

function renderEpisodes() {
    const tbody = document.getElementById('episode-tbody');
    if (!dramaEpisodes.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">' +
            '还没有剧集，使用上方表单创建第一集</td></tr>';
        return;
    }
    tbody.innerHTML = dramaEpisodes.map(function (ep) {
        const st = ep.stats || {};
        const total = st.total || 0;
        const done = st.video_done || 0;
        const pct = total > 0 ? Math.round(done * 100 / total) : 0;
        const finalVideo = ep.final_video_path
            ? '<a href="' + escHtml(ep.final_video_path) + '" class="btn btn-outline-success btn-xs" download>' +
              '<i class="bi bi-download"></i> 下载</a>'
            : '<span class="text-muted small">—</span>';
        return '<tr>' +
            '<td>第' + escHtml(ep.episode_no) + '集</td>' +
            '<td>' + escHtml(ep.title || '') + '</td>' +
            '<td class="small">第 ' + escHtml(ep.chapter_start) + ' - ' + escHtml(ep.chapter_end) + ' 章</td>' +
            '<td class="drama-progress">' +
                '<div class="progress" title="' + done + '/' + total + '">' +
                    '<div class="progress-bar bg-success" style="width:' + pct + '%"></div>' +
                '</div>' +
                '<small class="text-muted">' + done + '/' + total + ' 镜</small>' +
            '</td>' +
            '<td>' + episodeStatusBadge(ep) + '</td>' +
            '<td>' + finalVideo + '</td>' +
            '<td class="text-nowrap">' +
                '<a class="btn btn-primary btn-xs" href="drama_studio.php?episode_id=' + escHtml(ep.id) + '">' +
                    '<i class="bi bi-easel"></i> 工作台</a> ' +
                '<button type="button" class="btn btn-outline-info btn-xs" onclick="parseScript(this, ' + escHtml(ep.id) + ')">' +
                    '<i class="bi bi-file-text"></i> 解析剧本</button> ' +
                '<button type="button" class="btn btn-outline-danger btn-xs" onclick="deleteEpisode(this, ' + escHtml(ep.id) + ')">' +
                    '<i class="bi bi-trash"></i></button>' +
            '</td>' +
        '</tr>';
    }).join('');
}

function renderEpisodeError(msg) {
    document.getElementById('episode-tbody').innerHTML =
        '<tr><td colspan="7" class="text-center text-danger py-4">' + escHtml(msg) + '</td></tr>';
}

function renderTasks() {
    const box = document.getElementById('task-list');
    const hint = document.getElementById('task-hint');
    const active = dramaTasks.filter(t => t.status === 'pending' || t.status === 'running');
    hint.textContent = active.length ? ('进行中 ' + active.length + ' 个') : '';
    if (!dramaTasks.length) {
        box.innerHTML = '<div class="text-center text-muted py-3 small">暂无任务</div>';
        return;
    }
    box.innerHTML = dramaTasks.map(function (t) {
        const [sLabel, sCls] = TASK_STATUS_BADGES[t.status] || [t.status, 'bg-secondary'];
        const pct = Math.max(0, Math.min(100, t.progress || 0));
        return '<div class="drama-task-item py-2">' +
            '<div class="d-flex justify-content-between align-items-center mb-1">' +
                '<span><i class="bi bi-dot"></i>' + escHtml(TASK_TYPE_NAMES[t.type] || t.type) +
                    ' <small class="text-muted">#' + escHtml(t.id) + '</small></span>' +
                '<span class="badge ' + sCls + '">' + escHtml(sLabel) + '</span>' +
            '</div>' +
            '<div class="progress" style="height:6px">' +
                '<div class="progress-bar" style="width:' + pct + '%"></div>' +
            '</div>' +
            (t.error ? '<div class="text-danger small mt-1">' + escHtml(t.error) + '</div>' : '') +
        '</div>';
    }).join('');
}

// ---------------------------------------------------------------- 项目设置

async function saveProject() {
    if (!dramaProject) return;
    const btn = document.getElementById('save-project-btn');
    setBtnLoading(btn, true);
    const res = await apiPost('drama_actions', {
        action: 'save_project',
        project_id: dramaProject.id,
        title: document.getElementById('proj-title').value.trim(),
        style_prompt: document.getElementById('proj-style').value.trim(),
        style_negative: document.getElementById('proj-negative').value.trim(),
        image_size: document.getElementById('proj-image-size').value,
    });
    setBtnLoading(btn, false);
    showToast(res.msg || (res.ok ? '已保存' : '保存失败'), res.ok ? 'success' : 'error');
    if (res.ok) loadProject();
}

// ---------------------------------------------------------------- 剧集操作

async function createEpisodeSubmit(e) {
    e.preventDefault();
    if (!dramaProject) return false;
    const btn = document.getElementById('create-episode-btn');
    setBtnLoading(btn, true);
    const res = await apiPost('drama_actions', {
        action: 'create_episode',
        project_id: dramaProject.id,
        chapter_start: parseInt(document.getElementById('ep-chapter-start').value, 10) || 0,
        chapter_end: parseInt(document.getElementById('ep-chapter-end').value, 10) || 0,
        title: document.getElementById('ep-title').value.trim(),
    });
    setBtnLoading(btn, false);
    showToast(res.msg || (res.ok ? '剧集已创建' : '创建失败'), res.ok ? 'success' : 'error');
    if (res.ok) {
        document.getElementById('ep-title').value = '';
        loadProject();
    }
    return false;
}

async function parseScript(btn, episodeId) {
    setBtnLoading(btn, true);
    const res = await apiPost('drama_generate', { action: 'parse_script', episode_id: episodeId });
    setBtnLoading(btn, false);
    showToast(res.msg || (res.ok ? '解析任务已提交' : '提交失败'), res.ok ? 'success' : 'error');
    if (res.ok) pollProject();
}

async function deleteEpisode(btn, episodeId) {
    if (!confirm('确认删除该剧集？其分镜、图片与视频将一并删除，不可恢复。')) return;
    setBtnLoading(btn, true);
    const res = await apiPost('drama_actions', { action: 'delete_episode', episode_id: episodeId });
    setBtnLoading(btn, false);
    showToast(res.msg || (res.ok ? '已删除' : '删除失败'), res.ok ? 'success' : 'error');
    if (res.ok) loadProject();
}

// ---------------------------------------------------------------- 视频引擎配置

async function openVideoConfig() {
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('videoConfigModal'));
    modal.show();
    const res = await apiGet('drama_video_config', { action: 'get_video_api_config' });
    if (!res.ok || !res.data) {
        showToast(res.msg || '读取视频配置失败', 'error');
        return;
    }
    const d = res.data;
    videoProviders = d.providers || [];
    const sel = document.getElementById('vc-provider');
    sel.innerHTML = videoProviders.map(function (p) {
        return '<option value="' + escHtml(p.key) + '"' + (p.key === d.provider ? ' selected' : '') + '>' +
            escHtml(p.label) + '</option>';
    }).join('');
    document.getElementById('vc-url').value = d.api_url || '';
    const keyInput = document.getElementById('vc-key');
    keyInput.value = '';
    keyInput.placeholder = d.api_key_hint ? ('已保存（' + d.api_key_hint + '），留空不修改') : '未设置';
    document.getElementById('vc-model').value = d.model || '';
    document.getElementById('vc-test-result').style.display = 'none';
}

function onProviderChange() {
    const key = document.getElementById('vc-provider').value;
    const p = videoProviders.find(x => x.key === key);
    if (p && p.default_base) document.getElementById('vc-url').value = p.default_base;
}

function videoConfigPayload() {
    return {
        provider: document.getElementById('vc-provider').value,
        api_url: document.getElementById('vc-url').value.trim(),
        api_key: document.getElementById('vc-key').value,
        model: document.getElementById('vc-model').value.trim(),
    };
}

async function testVideoConfig() {
    const btn = document.getElementById('vc-test-btn');
    const box = document.getElementById('vc-test-result');
    setBtnLoading(btn, true);
    const res = await apiPost('drama_video_config', Object.assign({ action: 'test_video_api_config' }, videoConfigPayload()));
    setBtnLoading(btn, false);
    box.style.display = '';
    box.className = 'alert py-2 mb-0 small ' + (res.ok ? 'alert-success' : 'alert-danger');
    box.textContent = res.msg || (res.ok ? '连接成功' : '连接失败');
}

async function saveVideoConfig() {
    const btn = document.getElementById('vc-save-btn');
    setBtnLoading(btn, true);
    const res = await apiPost('drama_video_config', Object.assign({ action: 'save_video_api_config' }, videoConfigPayload()));
    setBtnLoading(btn, false);
    showToast(res.msg || (res.ok ? '已保存' : '保存失败'), res.ok ? 'success' : 'error');
    if (res.ok) {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('videoConfigModal')).hide();
        loadProject();
    }
}

// ---------------------------------------------------------------- 工具

/** 按钮 loading：禁用并显示 spinner，恢复时还原原始内容。 */
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

// ---------------------------------------------------------------- 任务监控 & 自动驱动

const DRAMA_CRON_TOKEN = 'drama-cron-2026-07-26';
const DRAMA_CRON_SCRIPT = '/tmp_drama_cron.php';

/** 构建监控脚本完整 URL */
function dramaCronUrl(action) {
    const base = location.origin + DRAMA_CRON_SCRIPT;
    const params = new URLSearchParams({ token: DRAMA_CRON_TOKEN });
    if (action) params.set('action', action);
    return base + '?' + params.toString();
}

/** 初始化监控面板（填充 URL、绑定链接） */
function dramaInitMonitor() {
    const monitorUrl = dramaCronUrl(''); // 默认 run
    const el = document.getElementById('monitor-url');
    if (el) el.value = monitorUrl;
    const link = document.getElementById('monitor-url-link');
    if (link) link.href = monitorUrl;
    const linkStatus = document.getElementById('link-status');
    if (linkStatus) linkStatus.href = dramaCronUrl('status');
    const linkRun = document.getElementById('link-run');
    if (linkRun) linkRun.href = dramaCronUrl('');
    const linkCancel = document.getElementById('link-cancel');
    if (linkCancel) linkCancel.href = dramaCronUrl('cancel');
}

/** 展开/折叠监控面板 */
function dramaToggleMonitor() {
    const panel = document.getElementById('monitor-panel');
    const chevron = document.getElementById('monitor-chevron');
    if (!panel) return;
    const isHidden = panel.style.display === 'none';
    panel.style.display = isHidden ? '' : 'none';
    if (chevron) {
        chevron.classList.toggle('bi-chevron-down', !isHidden);
        chevron.classList.toggle('bi-chevron-up', isHidden);
    }
    if (isHidden) dramaTaskAction('status'); // 展开时自动拉一次状态
}

/** 复制监控 URL 到剪贴板 */
async function dramaCopyMonitorUrl() {
    const el = document.getElementById('monitor-url');
    if (!el) return;
    try {
        await navigator.clipboard.writeText(el.value);
        showToast('已复制监控地址', 'success');
    } catch (e) {
        el.select();
        document.execCommand('copy');
        showToast('已复制监控地址', 'success');
    }
}

/** 通用任务操作（status/run/cancel）—— 通过 fetch 调用 tmp_drama_cron.php */
async function dramaTaskAction(action) {
    const statusBox = document.getElementById('monitor-status');
    if (statusBox) {
        const actionLabel = action === 'status' ? '查询状态' : (action === 'cancel' ? '取消任务' : '处理任务');
        statusBox.textContent = '正在' + actionLabel + '...';
    }
    const url = dramaCronUrl(action === 'run' ? '' : action);
    try {
        const t0 = Date.now();
        const resp = await fetch(url, { method: 'GET' });
        const elapsed = Date.now() - t0;
        const data = await resp.json().catch(() => null);
        if (statusBox) {
            statusBox.textContent = '[耗时 ' + elapsed + 'ms]\n' + JSON.stringify(data, null, 2);
        }
        // 处理成功后刷新任务列表（pollProject 会重新拉取 drama_tasks）
        if (data && data.ok && (action === 'run' || action === 'cancel')) {
            if (typeof pollProject === 'function') pollProject();
            const msg = action === 'cancel'
                ? (data.msg || '已取消')
                : ('已处理 ' + (data.processed_count || 0) + ' 个任务');
            showToast(msg, 'success');
        }
        return data;
    } catch (e) {
        if (statusBox) statusBox.textContent = '请求失败: ' + e.message;
        showToast('请求失败: ' + e.message, 'error');
        return null;
    }
}

/** 取消全部任务（带确认） */
async function dramaTaskCancel() {
    if (!confirm('确定取消所有 pending/running 任务?\n\n此操作不可撤销,正在处理的任务会被强制中止。')) return;
    await dramaTaskAction('cancel');
}

// 页面加载时初始化监控面板
document.addEventListener('DOMContentLoaded', dramaInitMonitor);
