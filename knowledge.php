<?php
define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/embedding.php';

$id = (int)($_GET['novel_id'] ?? 0);
$novel = DB::fetch('SELECT * FROM novels WHERE id = ?', [$id]);
if (!$novel) { header('Location: index.php'); exit; }

$kb = new KnowledgeBase($id);
$kbAvailable = $kb->isAvailable();

$stats = [
    'characters' => (int)DB::fetchColumn("SELECT COUNT(*) FROM novel_characters WHERE novel_id = ?", [$id]),
    'worldbuilding' => (int)DB::fetchColumn("SELECT COUNT(*) FROM novel_worldbuilding WHERE novel_id = ?", [$id]),
    'plots' => (int)DB::fetchColumn("SELECT COUNT(*) FROM novel_plots WHERE novel_id = ?", [$id]),
    'styles' => (int)DB::fetchColumn("SELECT COUNT(*) FROM novel_style WHERE novel_id = ?", [$id]),
    'embeddings' => (int)DB::fetchColumn("SELECT COUNT(*) FROM novel_embeddings WHERE novel_id = ?", [$id]),
];

pageHeader('智能知识库 - ' . $novel['title'], 'home');
?>
<div class="mb-3">
  <a href="novel.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>返回小说管理
  </a>
</div>

<div class="card mb-4" style="border-left: 4px solid <?= h($novel['cover_color']) ?>">
  <div class="card-body">
    <h5 class="mb-1"><?= h($novel['title']) ?></h5>
    <div class="text-muted small">
      <span class="me-3"><i class="bi bi-tag me-1"></i><?= h($novel['genre'] ?: '未分类') ?></span>
    </div>
  </div>
</div>

<?php if (!$kbAvailable): ?>
<div class="alert alert-warning mb-4">
  <i class="bi bi-exclamation-triangle me-2"></i>
  <strong>Embedding 服务未配置</strong> — 语义检索功能需要配置 API Key。
  <a href="settings.php" class="alert-link">前往设置</a>
</div>
<?php else: ?>
<div class="alert alert-success mb-4">
  <i class="bi bi-check-circle me-2"></i>
  <strong>知识库服务正常</strong> — 已索引 <?= $stats['embeddings'] ?> 条知识。
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="card bg-primary bg-opacity-10 border-0">
      <div class="card-body text-center py-3">
        <div class="fs-3 fw-bold text-primary"><?= $stats['characters'] ?></div>
        <div class="text-muted small">角色</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card bg-info bg-opacity-10 border-0">
      <div class="card-body text-center py-3">
        <div class="fs-3 fw-bold text-info"><?= $stats['worldbuilding'] ?></div>
        <div class="text-muted small">世界观</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card bg-warning bg-opacity-10 border-0">
      <div class="card-body text-center py-3">
        <div class="fs-3 fw-bold text-warning"><?= $stats['plots'] ?></div>
        <div class="text-muted small">情节</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card bg-success bg-opacity-10 border-0">
      <div class="card-body text-center py-3">
        <div class="fs-3 fw-bold text-success"><?= $stats['styles'] ?></div>
        <div class="text-muted small">风格</div>
      </div>
    </div>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header"><i class="bi bi-search me-2"></i>语义搜索</div>
  <div class="card-body">
    <div class="input-group">
      <input type="text" class="form-control" id="search-query" placeholder="输入搜索内容...">
      <button class="btn btn-primary" id="btn-search" <?= !$kbAvailable ? 'disabled' : '' ?>>
        <i class="bi bi-search me-1"></i>搜索
      </button>
    </div>
    <div id="search-results" class="mt-3"></div>
  </div>
</div>

<ul class="nav nav-tabs mb-3" id="kb-tabs">
  <li class="nav-item"><a class="nav-link active" href="#tab-characters" data-bs-toggle="tab"><i class="bi bi-people me-1"></i>角色库</a></li>
  <li class="nav-item"><a class="nav-link" href="#tab-worldbuilding" data-bs-toggle="tab"><i class="bi bi-globe me-1"></i>世界观</a></li>
  <li class="nav-item"><a class="nav-link" href="#tab-plots" data-bs-toggle="tab"><i class="bi bi-diagram-3 me-1"></i>情节库</a></li>
  <li class="nav-item"><a class="nav-link" href="#tab-styles" data-bs-toggle="tab"><i class="bi bi-palette me-1"></i>风格库</a></li>
</ul>

<div class="tab-content">
  <div class="tab-pane fade show active" id="tab-characters">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h6 class="mb-0">角色列表</h6>
      <button class="btn btn-primary btn-sm" onclick="editCharacter(0)"><i class="bi bi-plus me-1"></i>添加角色</button>
    </div>
    <div class="table-responsive">
      <table class="table table-hover">
        <thead><tr><th>角色名</th><th>类型</th><th>功能模板</th><th>性别</th><th>性格</th><th>操作</th></tr></thead>
        <tbody id="characters-list"><tr><td colspan="6" class="text-center text-muted">加载中...</td></tr></tbody>
      </table>
    </div>
  </div>
  <div class="tab-pane fade" id="tab-worldbuilding">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h6 class="mb-0">世界观设定</h6>
      <button class="btn btn-primary btn-sm" onclick="editWorldbuilding(0)"><i class="bi bi-plus me-1"></i>添加设定</button>
    </div>
    <div class="table-responsive">
      <table class="table table-hover">
        <thead><tr><th>名称</th><th>类别</th><th>描述</th><th>操作</th></tr></thead>
        <tbody id="worldbuilding-list"><tr><td colspan="4" class="text-center text-muted">加载中...</td></tr></tbody>
      </table>
    </div>
  </div>
  <div class="tab-pane fade" id="tab-plots">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h6 class="mb-0">情节与伏笔</h6>
      <button class="btn btn-primary btn-sm" onclick="editPlot(0)"><i class="bi bi-plus me-1"></i>添加情节</button>
    </div>
    <div class="table-responsive">
      <table class="table table-hover">
        <thead><tr><th>章节</th><th>标题</th><th>伏笔类型</th><th>状态</th><th>描述</th><th>操作</th></tr></thead>
        <tbody id="plots-list"><tr><td colspan="6" class="text-center text-muted">加载中...</td></tr></tbody>
      </table>
    </div>
  </div>
  <div class="tab-pane fade" id="tab-styles">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h6 class="mb-0">写作风格参考</h6>
      <button class="btn btn-primary btn-sm" onclick="editStyle(0)"><i class="bi bi-plus me-1"></i>添加风格</button>
    </div>
    <div class="table-responsive">
      <table class="table table-hover">
        <thead><tr><th>名称</th><th>风格向量</th><th>参考作者</th><th>高频词</th><th>操作</th></tr></thead>
        <tbody id="styles-list"><tr><td colspan="5" class="text-center text-muted">加载中...</td></tr></tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="modal-character" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">编辑角色</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <form id="form-character">
          <input type="hidden" name="id" value="0">
          <input type="hidden" name="novel_id" value="<?= $id ?>">
          <div class="row g-3">
            <div class="col-md-5"><label class="form-label">角色名 *</label><input type="text" class="form-control" name="name" required></div>
            <div class="col-md-3"><label class="form-label">类型</label><select class="form-select" name="role_type"><option value="protagonist">主角</option><option value="major">重要配角</option><option value="minor">次要角色</option></select></div>
            <div class="col-md-4"><label class="form-label">功能模板</label><select class="form-select" name="role_template"><option value="protagonist" selected>🧑 主角</option><option value="mentor">🧙 导师型</option><option value="opponent">⚔️ 对手型</option><option value="romantic">💃 红颜型</option><option value="brother">🤝 兄弟型</option><option value="other">📋 其他</option></select><div class="form-text small">系统按模板生成写作建议</div></div>
            <div class="col-md-4"><label class="form-label">性别</label><select class="form-select" name="gender"><option value="">未知</option><option value="male">男</option><option value="female">女</option></select></div>
            <div class="col-md-4"><label class="form-label">首次出场章</label><input type="number" class="form-control" name="first_chapter" min="1" placeholder="可选"></div>
            <div class="col-md-4"><label class="form-label">预计高潮/退场章</label><input type="number" class="form-control" name="climax_chapter" min="1" placeholder="可选"></div>
            <div class="col-12"><label class="form-label">外貌</label><textarea class="form-control" name="appearance" rows="2"></textarea></div>
            <div class="col-12"><label class="form-label">性格</label><textarea class="form-control" name="personality" rows="2"></textarea></div>
            <div class="col-12"><label class="form-label">背景</label><textarea class="form-control" name="background" rows="3"></textarea></div>
          </div>
        </form>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button><button type="button" class="btn btn-primary" onclick="saveCharacter()">保存</button></div>
    </div>
  </div>
</div>

<div class="modal fade" id="modal-worldbuilding" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">编辑世界观</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <form id="form-worldbuilding">
          <input type="hidden" name="id" value="0">
          <input type="hidden" name="novel_id" value="<?= $id ?>">
          <div class="mb-3"><label class="form-label">名称 *</label><input type="text" class="form-control" name="name" required></div>
          <div class="mb-3"><label class="form-label">类别</label><select class="form-select" name="category"><option value="location">地点</option><option value="faction">势力</option><option value="rule">规则</option><option value="item">物品</option></select></div>
          <div class="mb-3"><label class="form-label">描述</label><textarea class="form-control" name="description" rows="4"></textarea></div>
        </form>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button><button type="button" class="btn btn-primary" onclick="saveWorldbuilding()">保存</button></div>
    </div>
  </div>
</div>

<div class="modal fade" id="modal-plot" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">编辑情节</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <form id="form-plot">
          <input type="hidden" name="id" value="0">
          <input type="hidden" name="novel_id" value="<?= $id ?>">
          <div class="row g-3">
            <div class="col-md-4"><label class="form-label">起始章节 *</label><input type="number" class="form-control" name="chapter_from" min="1" required></div>
            <div class="col-md-4"><label class="form-label">结束章节</label><input type="number" class="form-control" name="chapter_to" min="1"></div>
            <div class="col-md-4"><label class="form-label">预计回收章节</label><input type="number" class="form-control" name="deadline_chapter" min="1" placeholder="伏笔何时揭晓"></div>
            <div class="col-12"><label class="form-label">标题 *</label><input type="text" class="form-control" name="title" required></div>
            <div class="col-md-5"><label class="form-label">类型</label><select class="form-select" name="event_type"><option value="main">主线</option><option value="subplot">支线</option><option value="foreshadowing">伏笔</option><option value="callback">呼应</option></select></div>
            <div class="col-md-4"><label class="form-label">伏笔类型</label><select class="form-select" name="foreshadow_type"><option value="">非伏笔/通用</option><option value="character">👤 人物伏笔（神秘身份）</option><option value="item">📦 物品伏笔（关键道具）</option><option value="speech">💬 言论伏笔（预言/暗示）</option><option value="faction">⚔️ 势力伏笔（隐藏势力）</option><option value="realm">🏔️ 境界伏笔（后期达成）</option><option value="identity">🎭 身份伏笔（隐藏身份）</option></select></div>
            <div class="col-md-3"><label class="form-label">状态</label><select class="form-select" name="status"><option value="planted">🔵 已埋设</option><option value="active">🟡 待回收</option><option value="resolving">🟢 回收中</option><option value="resolved">✅ 已完成</option><option value="abandoned">❌ 已作废</option></select></div>
            <div class="col-12"><label class="form-label">预期回收方式</label><input type="text" class="form-control" name="expected_payoff" placeholder="描述伏笔如何揭晓/回收"></div>
            <div class="col-12"><label class="form-label">描述</label><textarea class="form-control" name="description" rows="3"></textarea></div>
          </div>
        </form>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button><button type="button" class="btn btn-primary" onclick="savePlot()">保存</button></div>
    </div>
  </div>
</div>

<div class="modal fade" id="modal-style" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">编辑风格</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <form id="form-style">
          <input type="hidden" name="id" value="0">
          <input type="hidden" name="novel_id" value="<?= $id ?>">
          <div class="mb-3"><label class="form-label">名称 *</label><input type="text" class="form-control" name="name" required placeholder="如：辰东风 / 快节奏都市风"></div>

          <!-- 四维度向量 -->
          <div class="mb-3">
            <label class="form-label">📐 风格四维度向量</label>
            <div class="row g-2">
              <div class="col-6"><label class="form-label small text-muted">文风</label><select class="form-select form-select-sm" name="vec_style"><option value="concise">简洁干练</option><option value="ornate">华丽铺陈</option><option value="humorous">幽默调侃</option></select></div>
              <div class="col-6"><label class="form-label small text-muted">节奏</label><select class="form-select form-select-sm" name="vec_pacing"><option value="fast">快/爽点密集</option><option value="slow">慢/细腻铺陈</option><option value="alternating">快慢交替</option></select></div>
              <div class="col-6"><label class="form-label small text-muted">情感基调</label><select class="form-select form-select-sm" name="vec_emotion"><option value="passionate">热血激情</option><option value="warm">温馨治愈</option><option value="dark">暗黑压抑</option></select></div>
              <div class="col-6"><label class="form-label small text-muted">智慧感</label><select class="form-select form-select-sm" name="vec_intellect"><option value="strategy">智斗谋略向</option><option value="power">热血力量向</option><option value="balanced">兼顾平衡</option></select></div>
            </div>
          </div>

          <!-- 参考作者 -->
          <div class="mb-3"><label class="form-label">✍️ 参考作者风格</label><select class="form-select form-select-sm" name="ref_author"><option value="">不指定</option><option value="chendong">辰东 — 华丽/热血/力量</option><option value="maoni">猫腻 — 简洁/谋略/高智慧</option><option value="ergen">耳根 — 简洁/虐心/慢热</option><option value="zhouzi">会说话的肘子 — 幽默/快节奏/系统</option><option value="fenghuo">烽火戏诸侯 — 文白夹杂/群像/意境</option></select></div>

          <!-- 高频词库 -->
          <div class="mb-3"><label class="form-label">📝 自定义高频词汇（逗号分隔）</label><textarea class="form-control form-control-sm" name="keywords" rows="2" placeholder="如：修为,境界,灵气,轰鸣,恐怖,骇然"></textarea><div class="form-text small">这些词会被注入到每章的写作提示中</div></div>

          <!-- 详细描述（保留原字段） -->
          <div class="mb-3"><label class="form-label">详细风格说明</label><textarea class="form-control" name="content" rows="3"></textarea></div>
        </form>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button><button type="button" class="btn btn-primary" onclick="saveStyle()">保存</button></div>
    </div>
  </div>
</div>

<script src="assets/js/knowledge.js"></script>
