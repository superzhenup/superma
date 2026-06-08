<?php
/**
 * 角色关系网络可视化页面（独立页面）
 */
define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/data.php';

$novelId = intval($_GET['novel_id'] ?? 0);
if ($novelId <= 0) {
    die('无效的小说ID');
}

$novel = getNovel($novelId);
if (!$novel) {
    die('小说不存在');
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>角色关系网络 - <?= htmlspecialchars($novel['title'], ENT_QUOTES, 'UTF-8') ?></title>
    <link href="assets/vendor/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/character_network_tab.css">
    <script src="https://cdn.jsdelivr.net/npm/vis-network@9.1.9/dist/vis-network.min.js"></script>
</head>
<body class="cn-page">

    <!-- 顶栏 -->
    <div class="cn-topbar">
        <div class="cn-topbar-left">
            <a href="novel.php?id=<?= $novelId ?>" class="cn-back-btn">
                <i class="bi bi-arrow-left"></i>返回
            </a>
            <h1 class="cn-page-title"><span><?= htmlspecialchars($novel['title'], ENT_QUOTES, 'UTF-8') ?></span> · 角色关系网络</h1>
        </div>
        <div class="cn-topbar-actions">
            <button class="cn-btn" onclick="CharacterNetwork.focusSelected()" title="聚焦选中角色">
                <i class="bi bi-crosshair"></i>聚焦
            </button>
            <button class="cn-btn" onclick="CharacterNetwork.resetView()" title="重置视图">
                <i class="bi bi-arrows-fullscreen"></i>重置
            </button>
            <button class="cn-btn" id="cn-physics-btn" onclick="CharacterNetwork.togglePhysics()" title="切换物理效果">
                <i class="bi bi-activity"></i>物理
            </button>
        </div>
    </div>

    <!-- 主体 -->
    <div class="cn-body">
        <!-- 网络图 -->
        <div class="cn-graph">
            <div id="character-network-container">
                <div id="cn-loading" class="cn-overlay">
                    <div class="spinner-border text-primary mb-3" style="width:2rem;height:2rem;"></div>
                    <div class="cn-overlay-text">正在加载角色关系...</div>
                </div>
                <div id="cn-empty" class="cn-overlay" style="display:none;">
                    <i class="bi bi-diagram-3 cn-overlay-icon"></i>
                    <div class="cn-overlay-text">暂无角色关系数据</div>
                    <div class="cn-overlay-sub">请先在"记忆引擎"中添加角色</div>
                </div>
            </div>

            <!-- 搜索 -->
            <div class="cn-search">
                <i class="bi bi-search cn-search-icon"></i>
                <input type="text" id="cn-search-input" placeholder="搜索角色..." oninput="CharacterNetwork.search(this.value)">
            </div>

            <!-- 统计 -->
            <div class="cn-stats-float">
                <div class="cn-stat-item">
                    <div class="cn-stat-num" id="cn-stats-nodes">0</div>
                    <div class="cn-stat-label">角色</div>
                </div>
                <div class="cn-stat-item">
                    <div class="cn-stat-num" id="cn-stats-edges">0</div>
                    <div class="cn-stat-label">关系</div>
                </div>
            </div>

            <!-- 图例 -->
            <div class="cn-legend-float">
                <div class="cn-legend-item" onclick="CharacterNetwork.toggleRole('protagonist')">
                    <span class="cn-dot cn-dot-protagonist"></span>主角
                </div>
                <div class="cn-legend-item" onclick="CharacterNetwork.toggleRole('major')">
                    <span class="cn-dot cn-dot-major"></span>主要
                </div>
                <div class="cn-legend-item" onclick="CharacterNetwork.toggleRole('minor')">
                    <span class="cn-dot cn-dot-minor"></span>次要
                </div>
                <div class="cn-legend-item" onclick="CharacterNetwork.toggleRole('background')">
                    <span class="cn-dot cn-dot-dead"></span>背景
                </div>
            </div>
        </div>

        <!-- 右侧面板 -->
        <div class="cn-sidebar">
            <!-- 角色详情 -->
            <div class="cn-detail" id="cn-detail">
                <div class="cn-detail-empty">
                    <i class="bi bi-cursor-fill d-block mb-2"></i>
                    点击节点查看角色详情
                </div>
            </div>

            <!-- 角色列表 -->
            <div class="cn-list-header">全部角色</div>
            <div class="cn-list-section" id="cn-char-list">
                <!-- JS 动态填充 -->
            </div>
        </div>
    </div>

    <script src="assets/js/character_network_standalone.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            CharacterNetwork.init(<?= $novelId ?>);
        });
    </script>
</body>
</html>
