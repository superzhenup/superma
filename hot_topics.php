<?php
define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';

pageHeader('热门选题', 'hot_topics');
?>

<style>
.ht-wrap { padding: 1rem 1.2rem; }
.ht-title { font-size: 1.4rem; font-weight: 800; color: #ff8a4c; margin-bottom: .8rem; display: flex; align-items: center; gap: .6rem; }
.ht-title .btn-setting {
  margin-left: auto; font-size: .8rem; padding: .25rem .7rem;
  background: #2d2d4e; border: 1px solid #3d3d6e; color: #c8c8e0; border-radius: 8px;
}
.ht-title .btn-setting:hover { background: #3d3d6e; }

.ht-tabs { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: .6rem; }
.ht-tab {
  padding: .35rem .9rem; border-radius: 18px; font-size: .82rem;
  background: #1e1e38; border: 1px solid #2d2d4e; color: #c8c8e0;
  cursor: pointer; display: inline-flex; align-items: center; gap: .4rem;
  transition: all .15s;
}
.ht-tab:hover { border-color: #6366f1; }
.ht-tab.active { background: #6366f1; border-color: #6366f1; color: #fff; }
.ht-tab .badge {
  background: rgba(0,0,0,.25); color: inherit; padding: .12rem .4rem;
  border-radius: 10px; font-size: .7rem; font-weight: 600;
}
.ht-tab.active .badge { background: rgba(255,255,255,.25); }

.ht-tabs-mini .ht-tab { padding: .25rem .7rem; font-size: .78rem; border-radius: 14px; }

.ht-filter-bar {
  display: flex; gap: .7rem; align-items: center; flex-wrap: wrap;
  background: #12122a; padding: .6rem .8rem; border-radius: 10px; margin: .8rem 0;
}
.ht-filter-bar label { color: #9090b8; font-size: .8rem; margin: 0; }
.ht-filter-bar select, .ht-filter-bar input {
  background: #1a1a2e; border: 1px solid #2d2d4e; color: #e8e8ff;
  border-radius: 6px; padding: .25rem .5rem; font-size: .82rem; min-width: 100px;
}
.ht-filter-bar button {
  background: #6366f1; border: none; color: #fff; border-radius: 6px;
  padding: .3rem .9rem; font-size: .82rem; cursor: pointer;
}
.ht-filter-bar button.btn-secondary { background: #2d2d4e; }

.ht-dashboard {
  background: #12122a; border: 1px solid #2d2d4e; border-radius: 12px;
  padding: 1rem; margin-bottom: 1rem;
}
.ht-dashboard h6 {
  color: #818cf8; font-weight: 700; font-size: .9rem;
  display: flex; align-items: center; gap: .4rem; margin-bottom: .8rem;
}
.ht-dashboard .row-line {
  font-size: .82rem; color: #c8c8e0; margin-bottom: .35rem; line-height: 1.6;
}
.ht-dashboard .row-line .lbl { color: #9090b8; margin-right: .4rem; }
.ht-dashboard .kw-chip, .ht-dashboard .cat-chip {
  display: inline-block; padding: .15rem .55rem; margin: .15rem .25rem .15rem 0;
  border-radius: 10px; font-size: .75rem; background: #1e1e38;
  color: #a5b4fc; border: 1px solid #2d2d4e;
}
.cat-chip.red        { background: rgba(239,68,68,.15); color: #f87171; border-color: rgba(239,68,68,.3); }
.cat-chip.mainstream { background: rgba(245,158,11,.15); color: #fbbf24; border-color: rgba(245,158,11,.3); }
.cat-chip.normal     { background: rgba(74,222,128,.15); color: #4ade80; border-color: rgba(74,222,128,.3); }
.cat-chip.blue       { background: rgba(99,102,241,.15); color: #818cf8; border-color: rgba(99,102,241,.3); }

.ht-charts { display: grid; grid-template-columns: 1fr 1.5fr; gap: 1rem; margin-top: .8rem; }
@media (max-width: 900px) { .ht-charts { grid-template-columns: 1fr; } }
.ht-chart-box {
  background: #0f0f25; border: 1px solid #2d2d4e; border-radius: 10px;
  padding: .8rem; min-height: 220px;
}
.ht-chart-box .ttl { color: #9090b8; font-size: .78rem; margin-bottom: .4rem; }
.ht-legend { color: #9090b8; font-size: .72rem; display: flex; gap: 1rem; flex-wrap: wrap; }
.ht-legend .dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 4px; }

.ht-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: .8rem; }
.ht-card {
  background: #1a1a2e; border: 1px solid #2d2d4e; border-radius: 10px;
  padding: .9rem; cursor: pointer; transition: all .15s;
  display: flex; flex-direction: column; gap: .4rem;
}
.ht-card:hover { border-color: #6366f1; transform: translateY(-2px); }
.ht-card-head { display: flex; align-items: center; justify-content: space-between; font-size: .72rem; }
.ht-card-head .src { color: #818cf8; background: #2d2d4e; padding: .1rem .45rem; border-radius: 4px; }
.ht-card-head .rank { color: #fbbf24; }
.ht-card-title { font-size: 1rem; font-weight: 700; color: #e8e8ff; line-height: 1.4;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.ht-card-meta { font-size: .78rem; color: #9090b8; display: flex; gap: .6rem; flex-wrap: wrap; }
.ht-card-meta i { margin-right: .2rem; }
.ht-card-hot { color: #ff8a4c; font-weight: 700; font-size: .92rem; }
.ht-card-tags { display: flex; gap: .3rem; flex-wrap: wrap; }
.ht-card-tags .tag {
  font-size: .68rem; padding: .1rem .45rem; border-radius: 4px;
  background: #2d2d4e; color: #c8c8e0;
}

.ht-page {
  margin: 1rem 0; display: flex; justify-content: center; gap: .4rem;
  color: #9090b8; font-size: .82rem;
}
.ht-page button {
  background: #1e1e38; border: 1px solid #2d2d4e; color: #c8c8e0;
  padding: .25rem .7rem; border-radius: 6px; cursor: pointer;
}
.ht-page button:hover:not(:disabled) { border-color: #6366f1; }
.ht-page button:disabled { opacity: .4; cursor: not-allowed; }
.ht-page button.active { background: #6366f1; border-color: #6366f1; color: #fff; }

/* 详情 Modal */
.ht-modal-bg {
  position: fixed; inset: 0; background: rgba(0,0,0,.7); z-index: 1050;
  display: none; align-items: center; justify-content: center;
}
.ht-modal-bg.show { display: flex; }
.ht-modal {
  background: #1a1a2e; border: 1px solid #2d2d4e; border-radius: 14px;
  width: 92%; max-width: 860px; max-height: 90vh; overflow-y: auto;
  padding: 1.5rem;
}
.ht-modal h4 { color: #ff8a4c; margin-bottom: .8rem; }
.ht-modal .close-btn {
  position: sticky; top: 0; float: right; background: transparent;
  border: 0; color: #9090b8; font-size: 1.4rem; cursor: pointer;
}
.ht-analysis-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .8rem; margin-top: 1rem; }
@media (max-width: 700px) { .ht-analysis-grid { grid-template-columns: 1fr; } }
.ht-analysis-block {
  background: #12122a; border-radius: 8px; padding: .8rem;
  border-left: 3px solid #6366f1; font-size: .85rem; color: #c8c8e0; line-height: 1.7;
}
.ht-analysis-block .lbl { color: #a5b4fc; font-weight: 700; font-size: .82rem; margin-bottom: .3rem; }

/* 设置 Modal */
.ht-setting-modal .form-label { color: #c8c8e0; font-size: .85rem; }
.ht-setting-modal .form-control {
  background: #1a1a2e; border: 1px solid #2d2d4e; color: #e8e8ff;
  font-family: monospace; font-size: .82rem;
}
.ht-setting-modal code {
  background: #0f0f25; padding: .15rem .4rem; border-radius: 4px;
  color: #a5b4fc; word-break: break-all; font-size: .78rem;
}
</style>

<div class="ht-wrap">

<div class="ht-title">
  <i class="bi bi-fire"></i> 热门选题
  <button class="btn-setting" onclick="openSettingsModal()"><i class="bi bi-gear me-1"></i>设置</button>
</div>

<!-- Source Tabs -->
<div class="ht-tabs" id="sourceTabs">
  <div class="ht-tab active" data-source="all">全部 <span class="badge" id="cnt-all">0</span></div>
  <div class="ht-tab" data-source="qidian">起点中文网 <span class="badge" id="cnt-qidian">0</span></div>
  <div class="ht-tab" data-source="fanqie">番茄小说 <span class="badge" id="cnt-fanqie">0</span></div>
  <div class="ht-tab" data-source="zongheng">纵横中文网 <span class="badge" id="cnt-zongheng">0</span></div>
  <div class="ht-tab" data-source="qimao">七猫小说 <span class="badge" id="cnt-qimao">0</span></div>
</div>

<!-- Channel Tabs -->
<div class="ht-tabs ht-tabs-mini" id="channelTabs">
  <div class="ht-tab active" data-channel="all">全部</div>
  <div class="ht-tab" data-channel="male">男频</div>
  <div class="ht-tab" data-channel="female">女频</div>
  <div class="ht-tab" data-channel="general">通用</div>
</div>

<!-- Category Tabs -->
<div class="ht-tabs ht-tabs-mini" id="categoryTabs">
  <div class="ht-tab active" data-category="all">全部题材</div>
  <?php foreach (['玄幻','奇幻','武侠','仙侠','都市','历史','军事','科幻','游戏','体育','悬疑灵异','现代言情','古代言情','幻想言情','仙侠奇缘','同人','短篇'] as $c): ?>
  <div class="ht-tab" data-category="<?= h($c) ?>"><?= h($c) ?></div>
  <?php endforeach; ?>
</div>

<!-- Filter Bar -->
<div class="ht-filter-bar">
  <label>榜单：</label>
  <select id="filterRankType"><option value="all">全部</option></select>
  <label>时间：</label>
  <select id="filterDays">
    <option value="7">近 7 天</option>
    <option value="14">近 14 天</option>
    <option value="30">近 30 天</option>
    <option value="90">近 90 天</option>
  </select>
  <label>搜索：</label>
  <input type="text" id="filterKeyword" placeholder="书名/作者" style="min-width:200px">
  <button onclick="applyFilter()">应用</button>
  <button class="btn-secondary" onclick="resetFilter()">重置</button>
</div>

<!-- Dashboard -->
<div class="ht-dashboard">
  <h6><i class="bi bi-bar-chart-line"></i> 数据看板 <span class="text-muted small" style="font-weight:400;margin-left:.4rem;color:#9090b8" id="latestBatchHint"></span></h6>
  <div id="dashboardContent">
    <div class="text-muted small">加载中…</div>
  </div>
  <div class="ht-charts">
    <div class="ht-chart-box">
      <div class="ttl">题材热度雷达（近 7 天）</div>
      <div id="radarChart"></div>
      <div class="ht-legend"><span><span class="dot" style="background:#818cf8"></span>上榜数量</span><span><span class="dot" style="background:#ff8a4c"></span>平均热度</span></div>
    </div>
    <div class="ht-chart-box">
      <div class="ttl">推送趋势（近 14 天）</div>
      <div id="trendChart"></div>
      <div class="ht-legend"><span><span class="dot" style="background:#4ade80"></span>新增 accepted</span><span><span class="dot" style="background:#fbbf24"></span>更新 updated</span></div>
    </div>
  </div>
</div>

<!-- List -->
<div id="totalLine" class="text-muted small" style="margin-bottom:.5rem"></div>
<div class="ht-list" id="novelList">
  <div class="text-muted small">加载中…</div>
</div>

<div class="ht-page" id="pagination"></div>
</div>

<!-- Detail Modal -->
<div class="ht-modal-bg" id="detailModal" onclick="if(event.target===this)closeModal('detailModal')">
  <div class="ht-modal" id="detailModalBody">
    <button class="close-btn" onclick="closeModal('detailModal')">&times;</button>
    <div id="detailContent">加载中…</div>
  </div>
</div>

<!-- Settings Modal -->
<div class="ht-modal-bg" id="settingsModal" onclick="if(event.target===this)closeModal('settingsModal')">
  <div class="ht-modal ht-setting-modal">
    <button class="close-btn" onclick="closeModal('settingsModal')">&times;</button>
    <h4><i class="bi bi-gear me-2"></i>热门选题 · 接入设置</h4>
    <div id="settingsContent">加载中…</div>
  </div>
</div>

<script src="assets/js/hot_topics.js?v=<?= @filemtime(__DIR__ . '/assets/js/hot_topics.js') ?: time() ?>"></script>

<?php pageFooter(); ?>
