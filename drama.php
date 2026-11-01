<?php
/**
 * 漫剧工作流 - 项目页
 * 项目设置 / 视频引擎配置 / 剧集管理 / 任务监控
 */
define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/data.php';
require_once __DIR__ . '/includes/drama/DramaService.php';

$id = (int)($_GET['novel_id'] ?? 0);
$novel = getNovel($id);
$novel = requireNovelAccess($novel);

// 小说 1:1 自动创建漫剧项目，页头直接展示项目名
$project = DramaService::getOrCreateProject($id, currentUserId());

pageHeader('漫剧工作流', 'home');
?>
<script nonce="<?= cspNonce() ?>">
AppEvents.register('page-drama-open-video-config-1', function (event, element) { openVideoConfig(); });
AppEvents.register('page-drama-save-project-2', function (event, element) { saveProject(); });
AppEvents.register('page-drama-create-episode-submit-3', function (event, element) { return createEpisodeSubmit(event); });
AppEvents.register('page-drama-drama-task-action-4', function (event, element) { dramaTaskAction('status'); });
AppEvents.register('page-drama-drama-task-action-5', function (event, element) { dramaTaskAction('run'); });
AppEvents.register('page-drama-drama-task-cancel-6', function (event, element) { dramaTaskCancel(); });
AppEvents.register('page-drama-drama-toggle-monitor-7', function (event, element) { dramaToggleMonitor(); });
AppEvents.register('page-drama-drama-copy-monitor-url-8', function (event, element) { dramaCopyMonitorUrl(); });
AppEvents.register('page-drama-on-provider-change-9', function (event, element) { onProviderChange(); });
AppEvents.register('page-drama-test-video-config-10', function (event, element) { testVideoConfig(); });
AppEvents.register('page-drama-save-video-config-11', function (event, element) { saveVideoConfig(); });
AppEvents.register('page-drama-drama-rotate-cron-token-12', function (event, element) { dramaRotateCronToken(); });
</script>


<!-- 页头 -->
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h4 class="mb-1"><i class="bi bi-film me-2"></i><?= h($project['title'] ?: $novel['title']) ?> <small class="text-muted fs-6">漫剧项目</small></h4>
        <a href="novel.php?id=<?= (int)$id ?>" class="text-muted small text-decoration-none">
            <i class="bi bi-arrow-left me-1"></i>返回小说管理
        </a>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span id="image-engine-badge"></span>
        <span id="video-engine-badge"></span>
        <button type="button" class="btn btn-outline-primary btn-sm" data-csp-click="page-drama-open-video-config-1">
            <i class="bi bi-camera-video me-1"></i>视频引擎
        </button>
    </div>
</div>

<!-- 环境提示横幅（exec / FFmpeg 不可用时由 JS 显示） -->
<div class="alert alert-warning py-2 mb-3" id="env-banner" style="display:none">
    <i class="bi bi-exclamation-triangle me-1"></i><span id="env-banner-text"></span>
</div>

<div class="row g-3">
    <!-- 左列：项目设置 -->
    <div class="col-lg-5">
        <div class="page-card mb-3">
            <div class="page-card-header">
                <i class="bi bi-gear me-2"></i>项目设置
            </div>
            <div class="p-3">
                <div class="mb-3">
                    <label class="form-label">项目标题</label>
                    <input type="text" id="proj-title" class="form-control" placeholder="漫剧项目标题">
                </div>
                <div class="mb-3">
                    <label class="form-label">风格正向提示词</label>
                    <textarea id="proj-style" class="form-control" rows="3"
                        placeholder="例如：国漫风格，色彩鲜艳，人物美型，电影感打光"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">负向提示词</label>
                    <textarea id="proj-negative" class="form-control" rows="2"
                        placeholder="例如：低画质，模糊，变形，多余手指"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">分镜图尺寸</label>
                    <select id="proj-image-size" class="form-select">
                        <option value="1280x720">1280x720（横屏 16:9）</option>
                        <option value="1920x1080">1920x1080（横屏高清）</option>
                        <option value="720x1280">720x1280（竖屏 9:16）</option>
                        <option value="1080x1920">1080x1920（竖屏高清）</option>
                    </select>
                </div>
                <div class="d-flex justify-content-end">
                    <button type="button" class="btn btn-primary" id="save-project-btn" data-csp-click="page-drama-save-project-2">
                        <i class="bi bi-check-circle me-1"></i>保存设置
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 右列：剧集 + 任务 -->
    <div class="col-lg-7">
        <div class="page-card mb-3">
            <div class="page-card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-collection-play me-2"></i>剧集</span>
                <small class="text-muted" id="assets-count-text"></small>
            </div>
            <div class="p-3">
                <!-- 新建剧集 -->
                <form class="row g-2 align-items-end mb-3" data-csp-submit="page-drama-create-episode-submit-3">
                    <div class="col-auto">
                        <label class="form-label small mb-1">起始章</label>
                        <input type="number" id="ep-chapter-start" class="form-control form-control-sm" min="1" value="1" required style="width:90px">
                    </div>
                    <div class="col-auto">
                        <label class="form-label small mb-1">结束章</label>
                        <input type="number" id="ep-chapter-end" class="form-control form-control-sm" min="1" value="5" required style="width:90px">
                    </div>
                    <div class="col">
                        <label class="form-label small mb-1">标题（可选）</label>
                        <input type="text" id="ep-title" class="form-control form-control-sm" placeholder="留空自动命名">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-success btn-sm" id="create-episode-btn">
                            <i class="bi bi-plus-circle me-1"></i>创建剧集
                        </button>
                    </div>
                </form>

                <!-- 剧集表格 -->
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width:56px">集号</th>
                                <th>标题</th>
                                <th>章节范围</th>
                                <th style="width:150px">分镜进度</th>
                                <th>状态</th>
                                <th>成片</th>
                                <th style="width:210px">操作</th>
                            </tr>
                        </thead>
                        <tbody id="episode-tbody">
                            <tr><td colspan="7" class="text-center text-muted py-4">加载中...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 任务 -->
        <div class="page-card">
            <div class="page-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span><i class="bi bi-list-task me-2"></i>任务 <small class="text-muted ms-2" id="task-hint"></small></span>
                <div class="btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-outline-secondary" data-csp-click="page-drama-drama-task-action-4" title="查看任务状态">
                        <i class="bi bi-activity me-1"></i>状态
                    </button>
                    <button type="button" class="btn btn-outline-primary" data-csp-click="page-drama-drama-task-action-5" title="立即同步处理 1-3 个任务">
                        <i class="bi bi-play-fill me-1"></i>立即处理
                    </button>
                    <button type="button" class="btn btn-outline-danger" data-csp-click="page-drama-drama-task-cancel-6" title="取消所有 pending/running 任务">
                        <i class="bi bi-x-circle me-1"></i>取消全部
                    </button>
                </div>
            </div>
            <div class="p-3" id="task-list">
                <div class="text-center text-muted py-3 small">暂无任务</div>
            </div>
        </div>

        <!-- 监控地址 + 任务状态面板 -->
        <div class="page-card mt-3">
            <div class="page-card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-gear-wide-connected me-2"></i>任务监控 & 自动驱动</span>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-csp-click="page-drama-drama-toggle-monitor-7">
                    <i class="bi bi-chevron-down" id="monitor-chevron"></i>
                </button>
            </div>
            <div class="p-3" id="monitor-panel" style="display:none">
                <!-- 监控地址 -->
                <div class="mb-3">
                    <label class="form-label small text-muted mb-1">定时任务命令（含访问令牌，请妥善保存）</label>
                    <div class="input-group input-group-sm">
                        <input type="text" id="monitor-url" class="form-control" readonly>
                        <button class="btn btn-outline-secondary" type="button" data-csp-click="page-drama-drama-copy-monitor-url-8">
                            <i class="bi bi-clipboard"></i>复制
                        </button>
                        <button class="btn btn-outline-warning" type="button" data-csp-click="page-drama-drama-rotate-cron-token-12" title="轮换令牌（旧令牌立即失效）">
                            <i class="bi bi-arrow-repeat"></i>轮换令牌
                        </button>
                    </div>
                    <div class="form-text">
                        <i class="bi bi-info-circle me-1"></i>宝塔 → 计划任务 → 添加“Shell 脚本”任务，每分钟执行上方命令。已有 URL 任务需要替换为此命令。
                    </div>
                </div>

                <!-- 快捷操作 -->
                <div class="mb-3">
                    <label class="form-label small text-muted mb-1">快捷操作</label>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="link-status" data-csp-click="page-drama-drama-task-action-4">
                            <i class="bi bi-activity me-1"></i>查看状态
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="link-run" data-csp-click="page-drama-drama-task-action-5">
                            <i class="bi bi-play-fill me-1"></i>立即处理
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="link-cancel" data-csp-click="page-drama-drama-task-cancel-6">
                            <i class="bi bi-x-circle me-1"></i>取消全部
                        </button>
                    </div>
                </div>

                <!-- 任务状态结果 -->
                <div>
                    <label class="form-label small text-muted mb-1">实时状态</label>
                    <pre class="rounded p-2 small mb-0" id="monitor-status" style="max-height:400px;overflow:auto;white-space:pre-wrap;font-size:12px;background:#1e1e2e;color:#cdd6f4;border:1px solid #313244;font-family:Consolas,Monaco,'Courier New',monospace">点击"状态"按钮查看...</pre>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 视频引擎配置模态框 -->
<div class="modal fade" id="videoConfigModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-camera-video me-2"></i>视频引擎配置</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">服务商</label>
                    <select id="vc-provider" class="form-select" data-csp-change="page-drama-on-provider-change-9"></select>
                </div>
                <div class="mb-3">
                    <label class="form-label">API 地址</label>
                    <input type="text" id="vc-url" class="form-control" placeholder="https://...">
                </div>
                <div class="mb-3">
                    <label class="form-label">API Key</label>
                    <input type="password" id="vc-key" class="form-control" placeholder="未设置" autocomplete="new-password">
                    <div class="form-text" id="vc-key-help">留空表示不修改已保存的 Key</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">模型</label>
                    <input type="text" id="vc-model" class="form-control" placeholder="视频模型名称">
                </div>
                <div class="alert alert-info py-2 mb-0 small" id="vc-test-result" style="display:none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" id="vc-test-btn" data-csp-click="page-drama-test-video-config-10">
                    <i class="bi bi-plug me-1"></i>测试连接
                </button>
                <button type="button" class="btn btn-primary" id="vc-save-btn" data-csp-click="page-drama-save-video-config-11">
                    <i class="bi bi-check-circle me-1"></i>保存
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.drama-progress { min-width: 120px; }
.drama-progress .progress { height: 8px; }
.drama-task-item + .drama-task-item { border-top: 1px solid var(--border-color); }
</style>

<script nonce="<?= cspNonce() ?>">
const DRAMA_NOVEL_ID = <?= (int)$id ?>;
</script>
<script src="assets/js/drama.js?v=<?= assetVersion('assets/js/drama.js') ?>"></script>

<?php pageFooter(); ?>
