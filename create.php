<?php
define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';

$models   = DB::fetchAll('SELECT * FROM ai_models ORDER BY is_default DESC, id ASC');
$error    = '';
$editId   = (int)($_GET['edit'] ?? 0);
$novel    = $editId ? getNovel($editId) : false;
$isEdit   = $editId && $novel;

if ($editId && !$novel) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title            = trim($_POST['title']            ?? '');
    $genre            = trim($_POST['genre']            ?? '');
    $writing_style    = trim($_POST['writing_style']    ?? '');
    $protagonist_name = trim($_POST['protagonist_name'] ?? '');
    $protagonist_info = trim($_POST['protagonist_info'] ?? '');
    $plot_settings    = trim($_POST['plot_settings']    ?? '');
    $world_settings   = trim($_POST['world_settings']   ?? '');
    $extra_settings   = trim($_POST['extra_settings']   ?? '');
    $target_chapters  = max(1, (int)($_POST['target_chapters'] ?? 100));
    $chapter_words    = max(500, (int)($_POST['chapter_words']  ?? 2000));
    $model_id         = (int)($_POST['model_id'] ?? 0) ?: null;
    $postEditId       = (int)($_POST['edit_id'] ?? 0);

    if (!$title) {
        $error = '请输入书名。';
    } elseif ($postEditId) {
        // 编辑模式：更新现有小说
        $existNovel = getNovel($postEditId);
        if (!$existNovel) {
            $error = '小说不存在。';
        } else {
            DB::update('novels', [
                'title'            => $title,
                'genre'            => $genre,
                'writing_style'    => $writing_style,
                'protagonist_name' => $protagonist_name,
                'protagonist_info' => $protagonist_info,
                'plot_settings'    => $plot_settings,
                'world_settings'   => $world_settings,
                'extra_settings'   => $extra_settings,
                'target_chapters'  => $target_chapters,
                'chapter_words'    => $chapter_words,
                'model_id'         => $model_id,
            ], 'id=?', [$postEditId]);
            addLog($postEditId, 'edit', "编辑小说设定《{$title}》");
            header("Location: novel.php?id={$postEditId}&saved=1");
            exit;
        }
    } else {
        // 新建模式
        $id = DB::insert('novels', [
            'title'            => $title,
            'genre'            => $genre,
            'writing_style'    => $writing_style,
            'protagonist_name' => $protagonist_name,
            'protagonist_info' => $protagonist_info,
            'plot_settings'    => $plot_settings,
            'world_settings'   => $world_settings,
            'extra_settings'   => $extra_settings,
            'target_chapters'  => $target_chapters,
            'chapter_words'    => $chapter_words,
            'model_id'         => $model_id,
            'cover_color'      => randomColor(),
            'status'           => 'draft',
        ]);
        addLog((int)$id, 'create', "创建小说《{$title}》");
        header("Location: novel.php?id=$id&created=1");
        exit;
    }
}

// 编辑模式：用数据库数据填充表单默认值
if ($isEdit) {
    $v = $novel;
} else {
    $v = $_POST;
}

pageHeader($isEdit ? '编辑设定 - ' . $novel['title'] : '新建小说', 'create');
?>

<div class="row justify-content-center">
<div class="col-12 col-lg-9 col-xl-8">

<div class="page-card">
  <div class="page-card-header">
    <i class="bi bi-<?= $isEdit ? 'pencil-square' : 'book-half' ?> me-2"></i><?= $isEdit ? '编辑小说设定' : '新建小说' ?>
    <small class="text-muted ms-2"><?= $isEdit ? '修改小说的基本设定和写作参数' : '填写基本设定，AI将为您自动创作' ?></small>
  </div>

  <?php if ($error): ?>
  <div class="alert alert-danger m-3"><?= h($error) ?></div>
  <?php endif; ?>

  <form method="post" class="p-4">
    <?php if ($isEdit): ?>
    <input type="hidden" name="edit_id" value="<?= $editId ?>">
    <?php endif; ?>

    <!-- Step 1: 基本信息 -->
    <div class="form-section">
      <div class="form-section-title"><i class="bi bi-info-circle me-2"></i>基本信息</div>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">书名 <span class="text-danger">*</span></label>
          <input type="text" name="title" class="form-control" placeholder="例：斗破苍穹、凡人修仙传..."
                 value="<?= h($v['title'] ?? '') ?>" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">小说类型</label>
          <select name="genre" class="form-select">
            <option value="">选择类型</option>
            <?php foreach (genreOptions() as $g): ?>
            <option value="<?= h($g) ?>" <?= ($v['genre']??'')===$g?'selected':'' ?>><?= h($g) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">写作风格</label>
          <select name="writing_style" class="form-select">
            <option value="">选择风格</option>
            <?php foreach (styleOptions() as $s): ?>
            <option value="<?= h($s) ?>" <?= ($v['writing_style']??'')===$s?'selected':'' ?>><?= h($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <!-- Step 2: 主角设定 -->
    <div class="form-section">
      <div class="form-section-title"><i class="bi bi-person-badge me-2"></i>主角设定</div>
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">主角姓名</label>
          <input type="text" name="protagonist_name" class="form-control" placeholder="例：叶辰、萧炎..."
                 value="<?= h($v['protagonist_name'] ?? '') ?>">
        </div>
        <div class="col-md-8">
          <label class="form-label">主角信息</label>
          <textarea name="protagonist_info" class="form-control" rows="3"
            placeholder="描述主角的背景、性格、能力、目标等..."><?= h($v['protagonist_info'] ?? '') ?></textarea>
        </div>
      </div>
    </div>

    <!-- Step 3: 世界观与情节 -->
    <div class="form-section">
      <div class="form-section-title"><i class="bi bi-globe me-2"></i>世界观与情节设定</div>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">世界观设定</label>
          <textarea name="world_settings" class="form-control" rows="4"
            placeholder="描述小说的世界背景、修炼体系、地理环境、种族势力等..."><?= h($v['world_settings'] ?? '') ?></textarea>
        </div>
        <div class="col-md-6">
          <label class="form-label">情节设定</label>
          <textarea name="plot_settings" class="form-control" rows="4"
            placeholder="描述主线剧情、核心矛盾、重要事件、最终目标等..."><?= h($v['plot_settings'] ?? '') ?></textarea>
        </div>
        <div class="col-12">
          <label class="form-label">额外设定 <small class="text-muted">（可选）</small></label>
          <textarea name="extra_settings" class="form-control" rows="2"
            placeholder="其他补充设定，例如重要配角、特殊物品、禁忌规则等..."><?= h($v['extra_settings'] ?? '') ?></textarea>
        </div>
      </div>
    </div>

    <!-- Step 4: 写作参数 -->
    <div class="form-section">
      <div class="form-section-title"><i class="bi bi-sliders me-2"></i>写作参数</div>
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">目标章数</label>
          <input type="number" name="target_chapters" class="form-control" min="1" max="9999"
                 value="<?= (int)($v['target_chapters'] ?? 100) ?>">
          <div class="form-text">计划写多少章</div>
        </div>
        <div class="col-md-4">
          <label class="form-label">每章字数</label>
          <input type="number" name="chapter_words" class="form-control" min="500" max="10000" step="100"
                 value="<?= (int)($v['chapter_words'] ?? 2000) ?>">
          <div class="form-text">每章目标字数</div>
        </div>
        <div class="col-md-4">
          <label class="form-label">AI 模型</label>
          <select name="model_id" class="form-select">
            <option value="">默认模型</option>
            <?php foreach ($models as $m): ?>
            <option value="<?= $m['id'] ?>" <?= ($isEdit ? ($v['model_id']??null)==$m['id'] : $m['is_default'])?'selected':'' ?>>
              <?= h($m['name']) ?> (<?= h($m['model_name']) ?>)
            </option>
            <?php endforeach; ?>
          </select>
          <?php if (empty($models)): ?>
          <div class="form-text text-warning">
            <i class="bi bi-exclamation-triangle me-1"></i>
            <a href="settings.php" class="text-warning">请先添加AI模型</a>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="d-flex gap-2 justify-content-end">
      <a href="<?= $isEdit ? "novel.php?id={$editId}" : 'index.php' ?>" class="btn btn-secondary">取消</a>
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-<?= $isEdit ? 'check-circle' : 'plus-circle' ?> me-1"></i><?= $isEdit ? '保存修改' : '创建小说' ?>
      </button>
    </div>
  </form>
</div>

</div>
</div>

<?php pageFooter(); ?>
