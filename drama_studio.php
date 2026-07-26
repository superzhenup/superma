<?php
/**
 * 漫剧工作流 - 五步工作台
 * 资产 → 分镜 → 分镜图 → 视频 → 合成
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

$id = (int)($_GET['episode_id'] ?? 0);
$episode = DramaService::getEpisode($id);
if (!$episode) {
    header('Location: drama.php');
    exit;
}
$project = DramaService::assertProjectAccess((int)$episode['project_id'], (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0));
$novel = getNovel((int)$project['novel_id']);

pageHeader('漫剧工作台 - 第' . $episode['episode_no'] . '集', 'home');
?>

<!-- 页头 -->
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h4 class="mb-1">
            <i class="bi bi-easel me-2"></i>第<?= (int)$episode['episode_no'] ?>集 <?= h($episode['title'] ?: '') ?>
            <small class="text-muted fs-6">第 <?= (int)$episode['chapter_start'] ?> - <?= (int)$episode['chapter_end'] ?> 章</small>
        </h4>
        <a href="drama.php?novel_id=<?= (int)$project['novel_id'] ?>" class="text-muted small text-decoration-none">
            <i class="bi bi-arrow-left me-1"></i>返回项目页（<?= h($project['title'] ?: ($novel['title'] ?? '')) ?>）
        </a>
    </div>
    <span class="badge bg-secondary" id="studio-task-badge" style="display:none"></span>
</div>

<!-- 五步导航 -->
<ul class="nav nav-tabs mb-3" id="studio-tabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-assets" type="button" role="tab">
            <i class="bi bi-person-bounding-box me-1"></i>1 资产
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-shots" type="button" role="tab">
            <i class="bi bi-list-columns me-1"></i>2 分镜
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-images" type="button" role="tab">
            <i class="bi bi-images me-1"></i>3 分镜图
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-videos" type="button" role="tab">
            <i class="bi bi-camera-reels me-1"></i>4 视频
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-compose" type="button" role="tab">
            <i class="bi bi-collection-play me-1"></i>5 合成
        </button>
    </li>
</ul>

<div class="tab-content">
    <!-- ================= Tab1 资产 ================= -->
    <div class="tab-pane fade show active" id="tab-assets" role="tabpanel">
        <div class="page-card">
            <div class="page-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span><i class="bi bi-person-bounding-box me-2"></i>资产库</span>
                <div class="d-flex align-items-center gap-2">
                    <div class="btn-group btn-group-sm" id="asset-filter-group">
                        <button type="button" class="btn btn-outline-secondary active" data-type="">全部</button>
                        <button type="button" class="btn btn-outline-secondary" data-type="character">角色</button>
                        <button type="button" class="btn btn-outline-secondary" data-type="scene">场景</button>
                        <button type="button" class="btn btn-outline-secondary" data-type="prop">道具</button>
                    </div>
                    <button type="button" class="btn btn-outline-info btn-sm" id="parse-script-btn" onclick="parseScript(this)">
                        <i class="bi bi-file-text me-1"></i>解析剧本
                    </button>
                    <button type="button" class="btn btn-success btn-sm" onclick="openAssetModal(0)">
                        <i class="bi bi-plus-circle me-1"></i>手动新建资产
                    </button>
                </div>
            </div>
            <div class="p-3">
                <div class="row g-3" id="asset-grid">
                    <div class="text-center text-muted py-4">加载中...</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ================= Tab2 分镜 ================= -->
    <div class="tab-pane fade" id="tab-shots" role="tabpanel">
        <div class="page-card">
            <div class="page-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span><i class="bi bi-list-columns me-2"></i>分镜表</span>
                <div class="d-flex align-items-center gap-2">
                    <div class="input-group input-group-sm" style="width:170px">
                        <span class="input-group-text">目标镜头数</span>
                        <input type="number" id="target-shots" class="form-control" min="1" max="60" value="12">
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="gen-storyboard-btn" onclick="generateStoryboard(this)">
                        <i class="bi bi-magic me-1"></i>AI 生成分镜
                    </button>
                    <button type="button" class="btn btn-success btn-sm" onclick="addShot(this)">
                        <i class="bi bi-plus-circle me-1"></i>添加分镜
                    </button>
                </div>
            </div>
            <div class="p-3" id="shot-table-wrap">
                <div class="text-center text-muted py-4">加载中...</div>
            </div>
        </div>
    </div>

    <!-- ================= Tab3 分镜图 ================= -->
    <div class="tab-pane fade" id="tab-images" role="tabpanel">
        <div class="page-card">
            <div class="page-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span><i class="bi bi-images me-2"></i>分镜图</span>
                <div class="d-flex align-items-center gap-2">
                    <div class="input-group input-group-sm" style="width:160px">
                        <span class="input-group-text">每镜抽卡数</span>
                        <select id="image-batch" class="form-select">
                            <option value="1">1</option>
                            <option value="2" selected>2</option>
                            <option value="3">3</option>
                            <option value="4">4</option>
                        </select>
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="gen-all-images-btn" onclick="genShotImages(this, null)">
                        <i class="bi bi-stars me-1"></i>全部抽卡
                    </button>
                </div>
            </div>
            <div class="p-3">
                <div class="row g-3" id="shot-image-grid">
                    <div class="text-center text-muted py-4">加载中...</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ================= Tab4 视频 ================= -->
    <div class="tab-pane fade" id="tab-videos" role="tabpanel">
        <div class="page-card">
            <div class="page-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span><i class="bi bi-camera-reels me-2"></i>分镜视频</span>
                <button type="button" class="btn btn-outline-primary btn-sm" id="gen-all-videos-btn" onclick="genShotVideos(this, null)">
                    <i class="bi bi-play-circle me-1"></i>全部生成视频
                </button>
            </div>
            <div class="p-3">
                <div class="row g-3" id="shot-video-grid">
                    <div class="text-center text-muted py-4">加载中...</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ================= Tab5 合成 ================= -->
    <div class="tab-pane fade" id="tab-compose" role="tabpanel">
        <div class="page-card mb-3">
            <div class="page-card-header">
                <i class="bi bi-collection-play me-2"></i>合成与导出
            </div>
            <div class="p-3">
                <div class="row g-3 mb-3 text-center" id="compose-stats">
                    <div class="col-4">
                        <div class="p-3 rounded border">
                            <div class="fs-4" id="stat-total">0</div>
                            <div class="text-muted small">总分镜数</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-3 rounded border">
                            <div class="fs-4 text-info" id="stat-image">0</div>
                            <div class="text-muted small">已出图</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-3 rounded border">
                            <div class="fs-4 text-success" id="stat-video">0</div>
                            <div class="text-muted small">已出视频</div>
                        </div>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button type="button" class="btn btn-primary" id="compose-btn" onclick="composeEpisode(this)">
                        <i class="bi bi-collection-play me-1"></i>合成成片
                    </button>
                    <button type="button" class="btn btn-outline-success" id="export-btn" onclick="exportZip(this)">
                        <i class="bi bi-file-zip me-1"></i>导出素材包
                    </button>
                    <button type="button" class="btn btn-outline-danger" id="cancel-tasks-btn" onclick="cancelTasks(this)">
                        <i class="bi bi-x-circle me-1"></i>取消全部待执行任务
                    </button>
                </div>
                <div class="alert alert-info py-2 small mb-3" id="compose-hint"></div>
                <div id="export-result" class="mb-3" style="display:none">
                    <a href="#" id="export-link" class="btn btn-success btn-sm" download>
                        <i class="bi bi-download me-1"></i>下载素材包
                    </a>
                </div>
                <div id="final-video-box" style="display:none">
                    <div class="form-section-title mb-2"><i class="bi bi-award me-2"></i>成片</div>
                    <video id="final-video" controls class="w-100 rounded" style="max-height:480px;background:#000"></video>
                    <div class="mt-2">
                        <a href="#" id="final-video-link" class="btn btn-outline-success btn-sm" download>
                            <i class="bi bi-download me-1"></i>下载成片
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 新建资产模态框 -->
<div class="modal fade" id="assetModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>新建资产</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="asset-id" value="0">
                <div class="mb-3">
                    <label class="form-label">类型</label>
                    <select id="asset-type" class="form-select">
                        <option value="character">角色</option>
                        <option value="scene">场景</option>
                        <option value="prop">道具</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">名称</label>
                    <input type="text" id="asset-name" class="form-control" placeholder="资产名称">
                </div>
                <div class="mb-3">
                    <label class="form-label">描述</label>
                    <textarea id="asset-desc" class="form-control" rows="3"
                        placeholder="外观 / 场景 / 道具细节描述，用于 AI 出图"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" id="asset-save-btn" onclick="saveAssetFromModal(this)">
                    <i class="bi bi-check-circle me-1"></i>保存
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.asset-card-img, .shot-card-img {
    width: 100%;
    aspect-ratio: 16 / 9;
    object-fit: cover;
    background: var(--card-bg);
    border-radius: 0.375rem;
}
.asset-placeholder, .shot-placeholder {
    width: 100%;
    aspect-ratio: 16 / 9;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--card-bg);
    border: 1px dashed var(--border-color);
    border-radius: 0.375rem;
    color: var(--text-muted);
    font-size: 2rem;
}
.candidate-thumb {
    width: 56px;
    height: 56px;
    object-fit: cover;
    border-radius: 0.25rem;
    border: 2px solid transparent;
    cursor: pointer;
}
.candidate-thumb.selected { border-color: var(--bs-primary); }
.shot-gen-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0,0,0,0.45);
    border-radius: 0.375rem;
}
#shot-table-wrap textarea { resize: vertical; min-height: 44px; }
</style>

<script>
const DRAMA_EPISODE_ID = <?= (int)$id ?>;
const DRAMA_PROJECT_ID = <?= (int)$project['id'] ?>;
</script>
<script src="assets/js/drama_studio.js?v=<?= assetVersion('assets/js/drama_studio.js') ?>"></script>

<?php pageFooter(); ?>
