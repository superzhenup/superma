<?php
define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/author/AuthorProfile.php';

$models   = DB::fetchAll('SELECT * FROM ai_models ORDER BY is_default DESC, id ASC');
$error    = '';
$editId   = (int)($_GET['edit'] ?? 0);
$novel    = $editId ? getNovel($editId) : false;
$isEdit   = $editId && $novel;

$userId = $_SESSION['user_id'] ?? $_SESSION['uid'] ?? null;
$profiles = AuthorProfile::findByUser($userId);

if ($editId && !$novel) {
    header('Location: index.php');
    exit;
}

/**
 * 处理封面图片上传
 * 返回相对路径，无上传时返回 null
 */
function handleCoverUpload(int $novelId, ?array $existingNovel): ?string {
    if (empty($_FILES['cover_file']) || $_FILES['cover_file']['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $file = $_FILES['cover_file'];
    if ($file['size'] > 10 * 1024 * 1024) return null;

    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    // 检测 MIME 类型，优先用 finfo，不可用时回退到 getimagesize()
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
    } else {
        $imgInfo = @getimagesize($file['tmp_name']);
        $mimeType = $imgInfo ? $imgInfo['mime'] : '';
    }
    if (!in_array($mimeType, $allowedTypes)) return null;

    $coversDir = defined('BASE_PATH') ? BASE_PATH . '/storage/covers' : dirname(__DIR__) . '/storage/covers';
    if (!is_dir($coversDir)) @mkdir($coversDir, 0755, true);

    // 删除旧封面
    if ($existingNovel && !empty($existingNovel['cover_image'])) {
        $oldPath = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__)) . '/' . $existingNovel['cover_image'];
        if (file_exists($oldPath)) @unlink($oldPath);
    }

    $ext = match ($mimeType) {
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default       => 'jpg',
    };
    $filename = 'novel_' . $novelId . '_' . time() . '.' . $ext;
    $destPath = $coversDir . '/' . $filename;

    if (function_exists('gd_info')) {
        $srcImage = match ($mimeType) {
            'image/png'  => imagecreatefrompng($file['tmp_name']),
            'image/webp' => imagecreatefromwebp($file['tmp_name']),
            default       => imagecreatefromjpeg($file['tmp_name']),
        };
        if ($srcImage) {
            $targetW = 1086; $targetH = 1448;
            $dstImage = imagecreatetruecolor($targetW, $targetH);
            if ($mimeType !== 'image/jpeg') {
                imagealphablending($dstImage, false);
                imagesavealpha($dstImage, true);
            }
            imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $targetW, $targetH, imagesx($srcImage), imagesy($srcImage));
            match ($ext) {
                'png'  => imagepng($dstImage, $destPath, 8),
                'webp' => imagewebp($dstImage, $destPath, 85),
                default => imagejpeg($dstImage, $destPath, 90),
            };
            imagedestroy($srcImage);
            imagedestroy($dstImage);
        } else {
            move_uploaded_file($file['tmp_name'], $destPath);
        }
    } else {
        move_uploaded_file($file['tmp_name'], $destPath);
    }

    return 'storage/covers/' . $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $title            = trim($_POST['title']            ?? '');
    $genre            = trim($_POST['genre']            ?? '');
    $writing_style    = trim($_POST['writing_style']    ?? '');
    // 四维向量新字段（向后兼容）
    $vecStyle         = trim($_POST['vec_style']         ?? '');
    $vecPacing        = trim($_POST['vec_pacing']        ?? '');
    $vecEmotion       = trim($_POST['vec_emotion']       ?? '');
    $vecIntellect     = trim($_POST['vec_intellect']     ?? '');
    $refAuthor        = trim($_POST['ref_author']        ?? '');
    $authorProfileId  = (int)($_POST['author_profile_id'] ?? 0);
    $protagonist_name = trim($_POST['protagonist_name'] ?? '');
    $protagonist_info = trim($_POST['protagonist_info'] ?? '');
    $plot_settings    = trim($_POST['plot_settings']    ?? '');
    $world_settings   = trim($_POST['world_settings']   ?? '');
    $extra_settings   = trim($_POST['extra_settings']   ?? '');
    $target_reader    = trim($_POST['target_reader']    ?? 'general');
    $target_chapters  = max(1, (int)($_POST['target_chapters'] ?? 100));
    $chapter_words    = max(500, (int)($_POST['chapter_words']  ?? getSystemSetting('ws_chapter_words', 2000, 'int')));
    $model_id         = (int)($_POST['model_id'] ?? 0) ?: null;
    $postEditId       = (int)($_POST['edit_id'] ?? 0);

    // 解析卷设置（JSON格式：[{title, start, end}, ...]）
    $volumePlanRaw  = trim($_POST['volume_plan'] ?? '');
    $volumePlan     = [];
    if ($volumePlanRaw) {
        $decoded = json_decode($volumePlanRaw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $vol) {
                $start = (int)($vol['start'] ?? 0);
                $end   = (int)($vol['end']   ?? 0);
                $vtitle = trim($vol['title'] ?? '');
                if ($start > 0 && $end >= $start) {
                    $volumePlan[] = ['title' => $vtitle, 'start' => $start, 'end' => $end];
                }
            }
        }
    }

    // 处理自定义类型和风格
    if ($genre === '__custom__') {
        $genre = trim($_POST['genre_custom'] ?? '');
    }
    if ($writing_style === '__custom__') {
        $writing_style = trim($_POST['style_custom'] ?? '');
    }
    // 处理四维向量自定义值
    if ($vecStyle === '__custom__') {
        $vecStyle = trim($_POST['vec_style_custom'] ?? '');
    }
    if ($vecPacing === '__custom__') {
        $vecPacing = trim($_POST['vec_pacing_custom'] ?? '');
    }
    if ($vecEmotion === '__custom__') {
        $vecEmotion = trim($_POST['vec_emotion_custom'] ?? '');
    }
    if ($vecIntellect === '__custom__') {
        $vecIntellect = trim($_POST['vec_intellect_custom'] ?? '');
    }
    // 处理参考作者自定义
    if ($refAuthor === '__custom__') {
        $refAuthor = trim($_POST['ref_author_custom'] ?? '');
    }

    // 组合风格向量JSON
    $styleVector = null;
    if ($vecStyle || $vecPacing || $vecEmotion || $vecIntellect) {
        $styleVector = json_encode(array_filter([
            'style'     => $vecStyle,
            'pacing'    => $vecPacing,
            'emotion'   => $vecEmotion,
            'intellect' => $vecIntellect,
        ], fn($v) => $v !== ''), JSON_UNESCAPED_UNICODE);
    }

    if (!$title) {
        $error = '请输入书名。';
    }

    if (!$error && $genre === '同人小说') {
        $forbiddenKeywords = ['超能力','修仙','修真','渡劫','飞升','金丹','元婴','灵力','仙界','仙人','异世界','异界','传送门','召唤','魔法','魔法师','斗气','灵根','筑基','结丹','练气','御剑','仙术','法术','仙侠'];
        $checkFields = [
            '主角信息' => $protagonist_info,
            '情节设定' => $plot_settings,
            '世界观设定' => $world_settings,
            '额外设定' => $extra_settings,
        ];
        $found = [];
        foreach ($checkFields as $label => $text) {
            if (!$text) continue;
            foreach ($forbiddenKeywords as $kw) {
                if (mb_strpos($text, $kw) !== false) {
                    $found[] = "「{$kw}」（{$label}）";
                }
            }
        }
        if ($found) {
            $error = '同人小说内容验证失败：检测到以下违规关键词——' . implode('、', array_slice($found, 0, 5)) . '。同人小说禁止包含超能力元素、修仙题材内容、异世界设定，请修改后重新提交。';
        }
    }

    if (!$error && $postEditId) {
        // 编辑模式：更新现有小说
        $existNovel = getNovel($postEditId);
        if (!$existNovel) {
            $error = '小说不存在。';
        } else {
            $updateData = [
                'title'            => $title,
                'genre'            => $genre,
                'writing_style'    => $writing_style,
                'protagonist_name' => $protagonist_name,
                'protagonist_info' => $protagonist_info,
                'plot_settings'    => $plot_settings,
                'world_settings'   => $world_settings,
                'extra_settings'   => $extra_settings,
                'target_reader'    => $target_reader,
                'target_chapters'  => $target_chapters,
                'chapter_words'    => $chapter_words,
                'model_id'         => $model_id,
            ];
            // 如果有风格向量，尝试写入（向后兼容，忽略列不存在错误）
            if ($styleVector) {
                $updateData['style_vector'] = $styleVector;
            }
            // 参考作者单独存储
            if ($refAuthor) {
                $updateData['ref_author'] = $refAuthor;
            }
            if ($authorProfileId) {
                $updateData['author_profile_id'] = $authorProfileId;
            }
            // 处理封面颜色
            $coverColor = trim($_POST['cover_color'] ?? '#6366f1');
            if ($coverColor) {
                $updateData['cover_color'] = $coverColor;
            }
            // 处理封面上传
            $coverImagePath = handleCoverUpload($postEditId, $existNovel);
            if ($coverImagePath !== null) {
                $updateData['cover_image'] = $coverImagePath;
            }
            DB::update('novels', $updateData, 'id=?', [$postEditId]);
            addLog($postEditId, 'edit', "编辑小说设定《{$title}》");
            header("Location: novel.php?id={$postEditId}&saved=1");
            exit;
        }
    } elseif (!$error) {
        // 新建模式
        $coverColor = trim($_POST['cover_color'] ?? '#6366f1');
        $insertData = [
            'title'            => $title,
            'genre'            => $genre,
            'writing_style'    => $writing_style,
            'protagonist_name' => $protagonist_name,
            'protagonist_info' => $protagonist_info,
            'plot_settings'    => $plot_settings,
            'world_settings'   => $world_settings,
            'extra_settings'   => $extra_settings,
            'target_reader'    => $target_reader,
            'target_chapters'  => $target_chapters,
            'chapter_words'    => $chapter_words,
            'model_id'         => $model_id,
            'cover_color'      => $coverColor ?: randomColor(),
            'status'           => 'draft',
        ];
        if ($styleVector) {
            $insertData['style_vector'] = $styleVector;
        }
        // 参考作者单独存储
        if ($refAuthor) {
            $insertData['ref_author'] = $refAuthor;
        }
        if ($authorProfileId) {
            $insertData['author_profile_id'] = $authorProfileId;
        }
        $id = DB::insert('novels', $insertData);

        // 处理封面上传（需要 novel_id）
        $coverImagePath = handleCoverUpload((int)$id, null);
        if ($coverImagePath !== null) {
            DB::update('novels', ['cover_image' => $coverImagePath], 'id=?', [(int)$id]);
        }
        addLog((int)$id, 'create', "创建小说《{$title}》");

        // 保存用户预设的卷结构
        if (!empty($volumePlan)) {
            foreach ($volumePlan as $vIdx => $vol) {
                DB::insert('volume_outlines', [
                    'novel_id'      => (int)$id,
                    'volume_number' => $vIdx + 1,
                    'title'         => $vol['title'] ?: "第" . ($vIdx + 1) . "卷",
                    'summary'       => '',
                    'theme'         => '',
                    'start_chapter' => $vol['start'],
                    'end_chapter'   => $vol['end'],
                    'key_events'    => '[]',
                    'character_focus' => '[]',
                    'conflict'      => '',
                    'resolution'    => '',
                    'foreshadowing' => '[]',
                    'volume_goals'  => '[]',
                    'must_resolve_foreshadowing' => '[]',
                    'status'        => 'pending',
                ]);
            }
            addLog((int)$id, 'create', "预设" . count($volumePlan) . "卷结构");
        }

        header("Location: novel.php?id=$id&created=1");
        exit;
    }
}

// 编辑模式：用数据库数据填充表单默认值
// 新建模式：支持从拆书分析页 GET 跳转预填充
if ($isEdit) {
    $v = $novel;
} else {
    $v = [];
    // 从拆书分析跳转来的预填充数据（通过 GET 参数 json 传递）
    $prefill = $_GET['prefill'] ?? '';
    if ($prefill) {
        $decoded = json_decode(base64_decode($prefill), true);
        if (is_array($decoded)) {
            $v = $decoded;
        }
    }
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

  <form method="post" enctype="multipart/form-data" class="p-4" onsubmit="return validateGenreConstraints()">
    <input type="hidden" name="_token" value="<?= csrf_token() ?>">
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
          <select name="genre" id="genre-select" class="form-select" onchange="toggleCustomInput('genre'); toggleGenreConstraints()">
            <option value="">选择类型</option>
            <?php 
            $genreValue = $v['genre'] ?? '';
            $isCustomGenre = true;
            foreach (genreOptions() as $key => $g): 
                $optionValue = is_string($key) ? $key : $g;
                if ($genreValue === $optionValue) $isCustomGenre = false;
            ?>
            <option value="<?= h($optionValue) ?>" <?= $genreValue===$optionValue?'selected':'' ?>><?= h($g) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="genre_custom" id="genre-custom" class="form-control mt-2" 
                 placeholder="输入自定义类型" style="display:none"
                 value="<?= $isCustomGenre && $genreValue ? h($genreValue) : '' ?>">
          <div id="genre-constraints-notice" class="alert alert-warning py-2 px-3 mt-2 mb-0 small" style="display:none">
            <i class="bi bi-exclamation-triangle me-1"></i><strong>同人小说内容限制：</strong>
            <ul class="mb-0 mt-1 ps-3">
              <li>禁止包含超能力元素</li>
              <li>禁止包含修仙题材内容</li>
              <li>禁止包含异世界设定</li>
            </ul>
          </div>
        </div>
        <div class="col-md-3">
          <label class="form-label">写作风格（预设）</label>
          <select name="writing_style" id="style-select" class="form-select">
            <option value="">选择预设</option>
            <?php
            $styleValue = $v['writing_style'] ?? '';
            foreach (styleOptions() as $key => $s):
              $optionValue = is_string($key) ? $key : $s;
            ?>
            <option value="<?= h($optionValue) ?>" <?= $styleValue===$optionValue?'selected':'' ?>><?= h($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <!-- Step 1.5: 封面设置 -->
    <div class="form-section">
      <div class="form-section-title"><i class="bi bi-image me-2"></i>封面设置</div>
      <div class="row g-3">
        <div class="col-md-4">
          <div class="cover-preview-box" id="coverPreviewBox" style="width:180px;height:240px;border-radius:8px;overflow:hidden;background:linear-gradient(135deg,<?= h($v['cover_color'] ?? '#6366f1') ?>,<?= h($v['cover_color'] ?? '#6366f1') ?>99);display:flex;align-items:center;justify-content:center;cursor:pointer;position:relative" onclick="document.getElementById('cover-file-input').click()">
            <div id="coverPreviewContent" style="color:#fff;font-size:1.5rem;font-weight:700;text-align:center;padding:10px;text-shadow:0 2px 8px rgba(0,0,0,.5)">
              <?= h(safe_substr($v['title'] ?? '封面预览', 0, 4)) ?>
            </div>
            <img id="coverPreviewImg" src="" alt="" style="display:none;position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;pointer-events:none">
            <div style="position:absolute;bottom:8px;right:8px;background:rgba(0,0,0,.6);color:#fff;border-radius:4px;padding:2px 8px;font-size:.7rem;z-index:2">
              <i class="bi bi-pencil"></i> 更换
            </div>
          </div>
          <input type="file" id="cover-file-input" name="cover_file" accept="image/jpeg,image/png,image/webp" style="display:none" onchange="previewCoverFile(this)">
          <input type="hidden" name="cover_image_path" id="cover-image-path" value="<?= h($v['cover_image'] ?? '') ?>">
        </div>
        <div class="col-md-8">
          <div class="mb-3">
            <label class="form-label small text-muted">上传封面图片</label>
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-outline-primary btn-sm" onclick="document.getElementById('cover-file-input').click()">
                <i class="bi bi-upload me-1"></i>选择图片
              </button>
              <button type="button" class="btn btn-outline-danger btn-sm" id="btn-remove-cover" style="display:none" onclick="removeCover()">
                <i class="bi bi-trash me-1"></i>移除封面
              </button>
            </div>
            <div class="form-text">推荐分辨率 1086×1448，支持 JPG/PNG/WebP，最大 10MB，PRO版本支持一键生成</div>
          </div>
            <div class="form-text">需要先在模型设置中配置图片生成 API。生成尺寸约 1024×1536，会自动缩放。</div>
            <div id="cover-generate-status" class="small mt-1" style="display:none"></div>
          </div>
          <div class="mb-3">
            <label class="form-label small text-muted">封面颜色（无封面图时显示）</label>
            <input type="color" name="cover_color" class="form-control form-control-sm" style="width:60px;height:36px;padding:2px" value="<?= h($v['cover_color'] ?? '#6366f1') ?>">
          </div>
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

      <!-- 主角类型模板快捷选择 -->
      <div class="mb-3">
        <label class="form-label small text-muted">👤 主角类型模板（可选快捷填充）</label>
        <div class="row g-2">
          <div class="col-6 col-md-3"><button type="button" class="btn btn-outline-primary btn-sm w-100" onclick="fillProtagonist('underdog')">📉 废柴逆袭型</button></div>
          <div class="col-6 col-md-3"><button type="button" class="btn btn-outline-primary btn-sm w-100" onclick="fillProtagonist('fallen')">⭐ 天骄陨落型</button></div>
          <div class="col-6 col-md-3"><button type="button" class="btn btn-outline-primary btn-sm w-100" onclick="fillProtagonist('transmigrator')">🌀 穿越者型</button></div>
          <div class="col-6 col-md-3"><button type="button" class="btn btn-outline-primary btn-sm w-100" onclick="fillProtagonist('raising')">🌱 养成型</button></div>
        </div>
    </div>

    <!-- Step 3: 写作风格（结构化） -->
    <div class="form-section">
      <div class="form-section-title"><i class="bi bi-palette me-2"></i>写作风格（四维向量）</div>

      <!-- 四维度快速选择 -->
      <div class="row g-2 mb-2">
        <div class="col-md-3">
          <label class="form-label small text-muted">文风</label>
          <?php
          $svStyle = json_decode($v['style_vector'] ?? '{}', true)['style'] ?? '';
          $isCustomStyle = !in_array($svStyle, ['concise','ornate','humorous',''], true);
          ?>
          <select name="vec_style" id="vec-style-select" class="form-select form-select-sm" onchange="toggleCustomInput('vec_style')">
            <option value="">— 文风 —</option>
            <option value="concise" <?= ($svStyle==='concise')?'selected':'' ?>>简洁干练</option>
            <option value="ornate" <?= ($svStyle==='ornate')?'selected':'' ?>>华丽铺陈</option>
            <option value="humorous" <?= ($svStyle==='humorous')?'selected':'' ?>>幽默调侃</option>
            <option value="__custom__" <?= $isCustomStyle?'selected':'' ?>>✏️ 自定义</option>
          </select>
          <input type="text" name="vec_style_custom" id="vec-style-custom" class="form-control form-control-sm mt-1"
                 placeholder="例：古风文言、意识流..."
                 style="display:<?= $isCustomStyle?'block':'none' ?>"
                 value="<?= $isCustomStyle ? h($svStyle) : '' ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label small text-muted">节奏</label>
          <?php
          $svPacing = json_decode($v['style_vector'] ?? '{}', true)['pacing'] ?? '';
          $isCustomPacing = !in_array($svPacing, ['fast','slow','alternating',''], true);
          ?>
          <select name="vec_pacing" id="vec-pacing-select" class="form-select form-select-sm" onchange="toggleCustomInput('vec_pacing')">
            <option value="">— 节奏 —</option>
            <option value="fast" <?= ($svPacing==='fast')?'selected':'' ?>>快/爽点密集</option>
            <option value="slow" <?= ($svPacing==='slow')?'selected':'' ?>>慢/细腻铺陈</option>
            <option value="alternating" <?= ($svPacing==='alternating')?'selected':'' ?>>快慢交替</option>
            <option value="__custom__" <?= $isCustomPacing?'selected':'' ?>>✏️ 自定义</option>
          </select>
          <input type="text" name="vec_pacing_custom" id="vec-pacing-custom" class="form-control form-control-sm mt-1"
                 placeholder="例：渐入佳境、波峰波谷..."
                 style="display:<?= $isCustomPacing?'block':'none' ?>"
                 value="<?= $isCustomPacing ? h($svPacing) : '' ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label small text-muted">情感基调</label>
          <?php
          $svEmotion = json_decode($v['style_vector'] ?? '{}', true)['emotion'] ?? '';
          $isCustomEmotion = !in_array($svEmotion, ['passionate','warm','dark',''], true);
          ?>
          <select name="vec_emotion" id="vec-emotion-select" class="form-select form-select-sm" onchange="toggleCustomInput('vec_emotion')">
            <option value="">— 情感 —</option>
            <option value="passionate" <?= ($svEmotion==='passionate')?'selected':'' ?>>热血激情</option>
            <option value="warm" <?= ($svEmotion==='warm')?'selected':'' ?>>温馨治愈</option>
            <option value="dark" <?= ($svEmotion==='dark')?'selected':'' ?>>暗黑压抑</option>
            <option value="__custom__" <?= $isCustomEmotion?'selected':'' ?>>✏️ 自定义</option>
          </select>
          <input type="text" name="vec_emotion_custom" id="vec-emotion-custom" class="form-control form-control-sm mt-1"
                 placeholder="例：悲壮、浪漫、讽刺..."
                 style="display:<?= $isCustomEmotion?'block':'none' ?>"
                 value="<?= $isCustomEmotion ? h($svEmotion) : '' ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label small text-muted">智慧感</label>
          <?php
          $svIntellect = json_decode($v['style_vector'] ?? '{}', true)['intellect'] ?? '';
          $isCustomIntellect = !in_array($svIntellect, ['strategy','power','balanced',''], true);
          ?>
          <select name="vec_intellect" id="vec-intellect-select" class="form-select form-select-sm" onchange="toggleCustomInput('vec_intellect')">
            <option value="">— 智慧 —</option>
            <option value="strategy" <?= ($svIntellect==='strategy')?'selected':'' ?>>智斗/谋略向</option>
            <option value="power" <?= ($svIntellect==='power')?'selected':'' ?>>热血/力量向</option>
            <option value="balanced" <?= ($svIntellect==='balanced')?'selected':'' ?>>兼顾平衡</option>
            <option value="__custom__" <?= $isCustomIntellect?'selected':'' ?>>✏️ 自定义</option>
          </select>
          <input type="text" name="vec_intellect_custom" id="vec-intellect-custom" class="form-control form-control-sm mt-1"
                 placeholder="例：悬疑推理、商战博弈..."
                 style="display:<?= $isCustomIntellect?'block':'none' ?>"
                 value="<?= $isCustomIntellect ? h($svIntellect) : '' ?>">
        </div>
      </div>

      <!-- 作者画像绑定 -->
      <div class="mb-3">
        <label class="form-label small text-muted">🎨 绑定作者画像</label>
        <select name="author_profile_id" class="form-select form-select-sm">
          <option value="0">不绑定（使用默认风格）</option>
          <?php foreach ($profiles as $p): 
            $pId = $p['id'] ?? 0;
            $pName = $p['profile_name'] ?? '未命名';
            $pStatus = $p['analysis_status'] ?? 'pending';
            $statusBadge = match($pStatus) {
                'completed' => '✅',
                'analyzing' => '⏳',
                'failed' => '❌',
                default => '📋',
            };
            $selected = ($v['author_profile_id'] ?? 0) == $pId ? 'selected' : '';
          ?>
            <option value="<?= $pId ?>" <?= $selected ?>><?= $statusBadge ?> <?= h($pName) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">选择已分析的作者画像，AI 写作时将自动参考该画像的写作风格、叙事手法、情感基调等特征。在 <a href="writing_settings.php?tab=profile" target="_blank">写作设置 → 作者画像</a> 中上传作品即可生成。</div>
      </div>

      <!-- 参考作者 -->
      <div class="mb-2">
        <?php
        $ra = $v['ref_author'] ?? '';
        $isCustomAuthor = !in_array($ra, ['chendong','maoni','ergen','zhouzi',''], true);
        ?>
        <select name="ref_author" id="ref-author-select" class="form-select form-select-sm" onchange="toggleCustomInput('ref_author')">
          <option value="">参考作者（可选）</option>
          <option value="chendong" <?= ($ra==='chendong')?'selected':'' ?>>辰东（华丽热血风）</option>
          <option value="maoni" <?= ($ra==='maoni')?'selected':'' ?>>猫腻（简洁智斗风）</option>
          <option value="ergen" <?= ($ra==='ergen')?'selected':'' ?>>耳根（慢热虐心风）</option>
          <option value="zhouzi" <?= ($ra==='zhouzi')?'selected':'' ?>>会说话的肘子（幽默系统风）</option>
          <option value="__custom__" <?= $isCustomAuthor?'selected':'' ?>>✏️ 自定义作者</option>
        </select>
        <input type="text" name="ref_author_custom" id="ref-author-custom" class="form-control form-control-sm mt-1"
               placeholder="例：天蚕土豆、我吃西红柿..."
               style="display:<?= $isCustomAuthor?'block':'none' ?>"
               value="<?= $isCustomAuthor ? h($ra) : '' ?>">
      </div>

      <!-- 自由补充 -->
      <div><textarea name="writing_style" class="form-control" rows="2"
        placeholder="补充说明你的风格偏好..."><?= h($v['writing_style'] ?? '') ?></textarea></div>
    </div>

    <!-- Step 4: 世界观与情节 -->
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
                 value="<?= (int)($v['chapter_words'] ?? getSystemSetting('ws_chapter_words', 2000, 'int')) ?>">
          <div class="form-text">每章目标字数</div>
        </div>
        <div class="col-md-4">
          <label class="form-label">目标读者 <small class="text-muted">（v1.10.3）</small></label>
          <select name="target_reader" class="form-select">
            <?php foreach (readerProfileOptions() as $rk => $rl): ?>
            <option value="<?= h($rk) ?>" <?= (($v['target_reader'] ?? 'general') === $rk) ? 'selected' : '' ?>><?= h($rl) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text" id="target-reader-hint">
            <?= h(getReaderProfile($v['target_reader'] ?? 'general')['prompt_hint']) ?>
          </div>
        </div>
        <div class="col-md-4">
          <label class="form-label">AI 模型</label>
          <select name="model_id" class="form-select">
            <option value="">默认模型</option>
            <?php foreach ($models as $m): ?>
            <option value="<?= $m['id'] ?>" <?= (($v['model_id'] ?? null) == $m['id'] || (!$isEdit && !isset($v['model_id']) && $m['is_default']))?'selected':'' ?>>
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

    <!-- Step 5: 卷结构规划（新建时可选） -->
    <?php if (!$isEdit): ?>
    <div class="form-section" id="volume-plan-section">
      <div class="form-section-title">
        <i class="bi bi-journals me-2"></i>卷结构规划
        <small class="text-muted ms-2">预设各卷章节范围，生成故事大纲时AI会参考此结构</small>
      </div>

      <div id="volume-list">
        <!-- 默认3卷，由JS渲染 -->
      </div>

      <div class="d-flex gap-2 mt-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addVolume()">
          <i class="bi bi-plus-circle me-1"></i>添加卷
        </button>
        <span class="text-muted small align-self-center">章节范围根据目标章数自动推算，可手动修改</span>
      </div>

      <!-- 隐藏字段，提交时序列化卷数据 -->
      <input type="hidden" name="volume_plan" id="volume-plan-input">
    </div>
    <?php endif; ?>

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

<script>
// ── 封面相关 ────────────────────────────────────────────────────
function showCoverImage(src) {
    var img = document.getElementById('coverPreviewImg');
    var text = document.getElementById('coverPreviewContent');
    img.src = src;
    img.style.display = 'block';
    text.style.display = 'none';
    document.getElementById('btn-remove-cover').style.display = '';
}

function previewCoverFile(input) {
    if (!input.files || !input.files[0]) return;
    var file = input.files[0];
    var reader = new FileReader();
    reader.onload = function(e) {
        showCoverImage(e.target.result);
    };
    reader.readAsDataURL(file);
}

function removeCover() {
    var img = document.getElementById('coverPreviewImg');
    var text = document.getElementById('coverPreviewContent');
    img.src = '';
    img.style.display = 'none';
    text.style.display = '';
    document.getElementById('cover-file-input').value = '';
    document.getElementById('cover-image-path').value = '';
    document.getElementById('btn-remove-cover').style.display = 'none';
}

function generateCoverAI() {
    var novelId = <?= $editId ?: 0 ?>;
    var keyword = document.getElementById('cover-keyword').value.trim();
    if (!keyword) { alert('请输入封面描述关键词'); return; }
    if (!novelId) { alert('请先创建小说后再生成封面'); return; }

    var btn = document.getElementById('btn-generate-cover');
    var status = document.getElementById('cover-generate-status');
    btn.disabled = true;
    status.style.display = '';
    status.className = 'small mt-1 text-info';
    status.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>正在生成封面，请稍候...';

    fetch('api/cover_actions.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'generate', novel_id: novelId, keyword: keyword })
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        if (data.ok) {
            status.className = 'small mt-1 text-success';
            status.innerHTML = '<i class="bi bi-check-circle me-1"></i>' + data.msg;
            showCoverImage(data.path + '?t=' + Date.now());
            document.getElementById('cover-image-path').value = data.path;
        } else {
            status.className = 'small mt-1 text-danger';
            status.innerHTML = '<i class="bi bi-x-circle me-1"></i>' + (data.msg || '生成失败');
        }
    })
    .catch(err => {
        btn.disabled = false;
        status.className = 'small mt-1 text-danger';
        status.innerHTML = '<i class="bi bi-x-circle me-1"></i>请求失败：' + err.message;
    });
}

// 编辑模式时初始化封面预览
document.addEventListener('DOMContentLoaded', function() {
    var existingCover = <?= json_encode($v['cover_image'] ?? '') ?>;
    if (existingCover) {
        showCoverImage(existingCover);
    }
});

// 主角类型模板快捷填充
function fillProtagonist(type) {
    const templates = {
        underdog: '资质平庸的底层少年，受尽欺凌却不屈不挠，意外获得{金手指}后踏上逆袭之路。核心驱动力：证明自己 ≠ 向欺负自己的人复仇。',
        fallen: '曾经的天之骄子因某种原因跌落神坛，从云端跌入泥潭。在绝境中觉醒真正的力量，重新崛起时已脱胎换骨。性格比从前更加沉稳内敛。',
        transmigrator: '来自地球的现代灵魂穿越到这个陌生的世界，带着前世的记忆/知识/系统。最大的优势是对这个世界走向的"预知"，最大的挑战是适应新的身体和社会关系。',
        raising: '从幼年/新手阶段开始培养，读者见证他从零到一的完整成长过程。初期弱小但可塑性强，每个阶段性成长都有足够的铺垫和成就感。',
    };
    var field = document.querySelector('[name="protagonist_info"]');
    if (field && templates[type]) field.value = templates[type];
}

// 通用自定义输入框显示/隐藏
function toggleCustomInput(fieldKey) {
    var dashedKey = fieldKey.replace(/_/g, '-');
    var selectEl = document.getElementById(dashedKey + '-select');
    var customEl = document.getElementById(dashedKey + '-custom');
    if (selectEl && customEl) {
        customEl.style.display = selectEl.value === '__custom__' ? 'block' : 'none';
    }
}

var FORBIDDEN_GENRE_KEYWORDS = ['超能力','修仙','修真','渡劫','飞升','金丹','元婴','灵力','仙界','仙人','异世界','异界','传送门','召唤','魔法','魔法师','斗气','灵根','筑基','结丹','练气','御剑','仙术','法术','仙侠'];
var FORBIDDEN_HINT = '同人小说禁止包含：超能力元素、修仙题材内容、异世界设定';

function toggleGenreConstraints() {
    var genre = document.getElementById('genre-select').value;
    var notice = document.getElementById('genre-constraints-notice');
    if (notice) {
        notice.style.display = genre === '同人小说' ? 'block' : 'none';
    }
}

function validateGenreConstraints() {
    var genre = document.getElementById('genre-select').value;
    if (genre !== '同人小说') return true;

    var fields = ['protagonist_info', 'plot_settings', 'world_settings', 'extra_settings'];
    var found = [];
    for (var i = 0; i < fields.length; i++) {
        var el = document.querySelector('[name="' + fields[i] + '"]');
        if (!el) continue;
        var val = el.value || '';
        for (var j = 0; j < FORBIDDEN_GENRE_KEYWORDS.length; j++) {
            if (val.indexOf(FORBIDDEN_GENRE_KEYWORDS[j]) !== -1) {
                found.push('「' + FORBIDDEN_GENRE_KEYWORDS[j] + '」');
            }
        }
    }
    if (found.length > 0) {
        alert('⚠️ 同人小说内容验证失败！\n\n检测到以下违规关键词：' + found.join('、') + '\n\n' + FORBIDDEN_HINT + '\n\n请修改相关内容后重新提交。');
        return false;
    }
    return true;
}

// ── 卷结构管理 ────────────────────────────────────────────────────
var volumes = [];

function getTargetChapters() {
    return Math.max(1, parseInt(document.querySelector('[name="target_chapters"]').value) || 100);
}

// 根据目标章数自动计算默认卷范围（3卷）
function calcDefaultVolumes(total) {
    var v1End   = Math.floor(total * 0.33);
    var v2End   = Math.floor(total * 0.67);
    return [
        { title: '', start: 1,       end: v1End   },
        { title: '', start: v1End+1, end: v2End   },
        { title: '', start: v2End+1, end: total   },
    ];
}

function renderVolumes() {
    var container = document.getElementById('volume-list');
    if (!container) return;
    container.innerHTML = '';
    volumes.forEach(function(vol, idx) {
        var row = document.createElement('div');
        row.className = 'volume-row d-flex gap-2 align-items-center mb-2';
        row.innerHTML =
            '<span class="text-muted small" style="min-width:36px;text-align:right">第'+(idx+1)+'卷</span>' +
            '<input type="text" class="form-control form-control-sm" style="max-width:180px" ' +
                'placeholder="卷标题（可选）" value="'+escHtml(vol.title)+'" ' +
                'oninput="volumes['+idx+'].title=this.value">' +
            '<span class="text-muted small">章节范围</span>' +
            '<input type="number" class="form-control form-control-sm" style="max-width:80px" ' +
                'min="1" value="'+vol.start+'" ' +
                'oninput="volumes['+idx+'].start=Math.max(1,parseInt(this.value)||1);syncVolumeBoundary('+idx+')">' +
            '<span class="text-muted small">—</span>' +
            '<input type="number" class="form-control form-control-sm" style="max-width:80px" ' +
                'min="1" value="'+vol.end+'" ' +
                'oninput="volumes['+idx+'].end=Math.max(volumes['+idx+'].start,parseInt(this.value)||1)">' +
            (volumes.length > 1
                ? '<button type="button" class="btn btn-outline-danger btn-sm px-2" onclick="removeVolume('+idx+')" title="删除此卷">' +
                  '<i class="bi bi-trash"></i></button>'
                : '<span style="width:34px"></span>');
        container.appendChild(row);
    });
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// 联动：修改某卷 start 时，自动把上一卷 end 调整为 start-1
function syncVolumeBoundary(idx) {
    if (idx > 0) {
        volumes[idx-1].end = Math.max(volumes[idx-1].start, volumes[idx].start - 1);
    }
    renderVolumes();
}

function addVolume() {
    var total = getTargetChapters();
    var lastEnd = volumes.length > 0 ? volumes[volumes.length-1].end : 0;
    var newStart = lastEnd + 1;
    var newEnd = Math.min(total, newStart + 49);
    if (newStart > total) newStart = total;
    volumes.push({ title: '', start: newStart, end: newEnd });
    renderVolumes();
}

function removeVolume(idx) {
    volumes.splice(idx, 1);
    renderVolumes();
}

// 目标章数变化时，重算最后一卷的 end
document.querySelector('[name="target_chapters"]').addEventListener('input', function() {
    var total = getTargetChapters();
    if (volumes.length > 0) {
        volumes[volumes.length-1].end = total;
        renderVolumes();
    }
});

// 初始化3卷默认值
document.addEventListener('DOMContentLoaded', function() {
    // 初始化自定义输入框
    ['vec_style','vec_pacing','vec_emotion','vec_intellect','ref_author'].forEach(toggleCustomInput);

    var genreSelect = document.getElementById('genre-select');
    var genreCustom = document.getElementById('genre-custom');
    if (genreSelect && genreCustom && (genreSelect.value==='__custom__' || (genreCustom.value && !genreSelect.value))) {
        genreCustom.style.display = 'block';
        genreSelect.value = '__custom__';
    }

    // 初始化同人小说限制提示
    toggleGenreConstraints();

    // 初始化卷列表（仅新建页面有 #volume-list）
    if (document.getElementById('volume-list')) {
        volumes = calcDefaultVolumes(getTargetChapters());
        renderVolumes();
    }
});

// 提交前序列化卷数据
document.querySelector('form').addEventListener('submit', function(e) {
    var genreSelect = document.getElementById('genre-select');
    var genreCustom = document.getElementById('genre-custom');
    if (genreSelect && genreSelect.value==='__custom__' && !genreCustom.value.trim()) {
        e.preventDefault(); alert('请输入自定义类型'); genreCustom.focus(); return false;
    }
    if (!validateGenreConstraints()) { e.preventDefault(); return false; }
    var customFields = [
        {key:'vec_style',name:'文风'},{key:'vec_pacing',name:'节奏'},
        {key:'vec_emotion',name:'情感基调'},{key:'vec_intellect',name:'智慧感'},
        {key:'ref_author',name:'参考作者'},
    ];
    for (var i=0; i<customFields.length; i++) {
        var dk = customFields[i].key.replace(/_/g,'-');
        var sel = document.getElementById(dk+'-select');
        var inp = document.getElementById(dk+'-custom');
        if (sel && inp && sel.value==='__custom__' && !inp.value.trim()) {
            e.preventDefault(); alert('请输入自定义'+customFields[i].name); inp.focus(); return false;
        }
    }

    // 序列化卷数据到隐藏字段
    var planInput = document.getElementById('volume-plan-input');
    if (planInput && volumes.length > 0) {
        // 过滤掉 start>end 的非法行
        var valid = volumes.filter(function(v){ return v.start > 0 && v.end >= v.start; });
        planInput.value = JSON.stringify(valid);
    }
    return true;
});
</script>

<?php pageFooter(); ?>
