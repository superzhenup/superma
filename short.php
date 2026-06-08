<?php
define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/ShortStoryService.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: shorts.php'); exit; }

$service = new ShortStoryService();
$story = $service->getStory($id);
if (!$story) { header('Location: shorts.php'); exit; }

$models = DB::fetchAll('SELECT * FROM ai_models ORDER BY is_default DESC, id ASC');
$versions = $service->listVersions($id);

$statusMap = [
  'draft' => '草稿', 'brief_ready' => '已立项', 'beats_ready' => '已排节拍',
  'written' => '已完稿', 'polished' => '已润色', 'completed' => '已完成',
];

pageHeader('短篇工作台 - ' . ($story['title'] ?: '未命名'), 'shorts');
?>

<style>
.wb-layout { display: grid; grid-template-columns: 280px 1fr 280px; gap: 1rem; min-height: calc(100vh - 120px); }
@media (max-width: 1100px) { .wb-layout { grid-template-columns: 1fr; } }

.wb-panel {
  background: #1a1a2e; border: 1px solid #2d2d4e; border-radius: 14px;
  padding: 1rem; overflow-y: auto; max-height: calc(100vh - 140px);
}
.wb-panel h6 { color: #a5b4fc; font-weight: 700; font-size: .85rem; margin-bottom: .8rem; }

.wb-center { display: flex; flex-direction: column; }
.wb-editor {
  flex-grow: 1; background: #12122a; border: 1px solid #2d2d4e; border-radius: 14px;
  padding: 1.2rem; min-height: 500px; overflow-y: auto;
}
.wb-editor textarea {
  width: 100%; min-height: 460px; background: transparent; border: none;
  color: #e8e8ff; font-size: .95rem; line-height: 1.8; resize: vertical;
  outline: none; font-family: inherit;
}
.wb-editor textarea::placeholder { color: #4a4a6e; }

.wb-status-bar {
  display: flex; align-items: center; justify-content: space-between;
  padding: .5rem 0; font-size: .78rem; color: #9090b8;
}

.wb-btn {
  display: block; width: 100%; text-align: left; padding: .55rem .8rem;
  border-radius: 8px; font-size: .82rem; font-weight: 500;
  border: 1px solid #2d2d4e; background: #1e1e38; color: #c8c8e0;
  margin-bottom: .4rem; cursor: pointer; transition: all .15s;
}
.wb-btn:hover { border-color: #6366f1; color: #e8e8ff; }
.wb-btn:disabled { opacity: .5; cursor: not-allowed; }
.wb-btn i { margin-right: .4rem; }

.brief-box, .beats-box {
  background: #12122a; border-radius: 8px; padding: .7rem;
  margin-bottom: .6rem; font-size: .8rem; color: #b8b8d0;
  line-height: 1.5;
}
.brief-box .bl { color: #818cf8; font-weight: 600; margin-right: .3rem; }

.beat-item {
  background: #12122a; border-radius: 8px; padding: .5rem .7rem;
  margin-bottom: .4rem; font-size: .78rem; color: #b8b8d0;
  border-left: 3px solid #6366f1;
}
.beat-item .beat-name { color: #a5b4fc; font-weight: 600; }

.quality-box {
  background: #12122a; border-radius: 8px; padding: .7rem;
  font-size: .8rem; color: #b8b8d0;
}
.quality-box .q-score { font-size: 1.5rem; font-weight: 800; }
.quality-box .q-pass { color: #4ade80; }
.quality-box .q-fail { color: #f87171; }

.version-item {
  display: flex; align-items: center; justify-content: space-between;
  padding: .4rem .6rem; border-radius: 6px; font-size: .78rem;
  color: #9090b8; cursor: pointer; transition: background .15s;
}
.version-item:hover { background: #2d2d4e; }

.sse-progress {
  background: #12122a; border-radius: 8px; padding: .7rem;
  margin-bottom: .6rem; font-size: .8rem; color: #b8b8d0;
}
.sse-progress .progress-bar-bg {
  height: 4px; background: #2d2d4e; border-radius: 2px; margin-top: .5rem; overflow: hidden;
}
.sse-progress .progress-bar-fill {
  height: 100%; background: linear-gradient(90deg, #6366f1, #818cf8); border-radius: 2px;
  transition: width .3s ease;
}
.sse-cursor {
  display: inline-block; width: 2px; height: 1em; background: #818cf8;
  animation: blink 1s step-end infinite; vertical-align: text-bottom; margin-left: 2px;
}
@keyframes blink { 50% { opacity: 0; } }

.chapter-item {
  display: flex; align-items: center; justify-content: space-between;
  background: #12122a; border-radius: 8px; padding: .5rem .7rem;
  margin-bottom: .4rem; font-size: .78rem; color: #b8b8d0;
  border-left: 3px solid #2d2d4e; cursor: pointer; transition: all .15s;
}
.chapter-item:hover { background: #1e1e38; border-left-color: #6366f1; }
.chapter-item.active { background: #1e1e38; border-left-color: #818cf8; }
.chapter-item .ch-title { color: #a5b4fc; font-weight: 600; }
.chapter-item .ch-meta { color: #707090; font-size: .72rem; margin-top: .1rem; }
.ch-status-pending  { color: #707090; }
.ch-status-written  { color: #4ade80; }
.ch-status-polished { color: #818cf8; }

.chapter-edit-block {
  background: #12122a; border-radius: 8px; padding: .7rem; margin-bottom: .4rem;
  font-size: .78rem; border-left: 3px solid #4ade80;
}
.chapter-edit-block .bl { color: #818cf8; font-weight: 600; margin-right: .3rem; }

.mode-tabs { display: flex; gap: .4rem; margin-bottom: .6rem; }
.mode-tab {
  padding: .35rem .8rem; border-radius: 6px; font-size: .78rem;
  border: 1px solid #2d2d4e; background: #1e1e38; color: #9090b8;
  cursor: pointer;
}
.mode-tab.active { background: #6366f1; border-color: #6366f1; color: #fff; }
.chapter-header {
  font-size: .85rem; color: #a5b4fc; font-weight: 600;
  padding: .4rem .7rem; background: #1e1e38; border-radius: 6px;
  margin-bottom: .5rem; display: flex; justify-content: space-between; align-items: center;
}
</style>

<div class="container-fluid py-3" style="max-width:1440px;">

<div class="d-flex align-items-center justify-content-between mb-3">
  <div class="d-flex align-items-center gap-2">
    <a href="shorts.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h5 class="mb-0 text-light fw-bold" id="storyTitle"><?= h($story['title'] ?: '未命名短篇') ?></h5>
    <span class="status-badge status-<?= $story['status'] ?>"><?= $statusMap[$story['status']] ?? $story['status'] ?></span>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-outline-success btn-sm" onclick="exportShort()"><i class="bi bi-download me-1"></i>导出TXT</button>
    <button class="btn btn-outline-info btn-sm" onclick="showVersionModal()"><i class="bi bi-clock-history me-1"></i>版本历史</button>
  </div>
</div>

<div class="wb-layout">
  <!-- Left: Brief & Beats -->
  <div class="wb-panel">
    <h6><i class="bi bi-card-text me-1"></i>故事概要</h6>
    <div id="briefArea">
      <?php if (!empty($story['brief_json'])): ?>
      <div class="brief-box" id="briefDisplay">
        <?php $b = $story['brief_json']; ?>
        <?php if (!empty($b['logline'])): ?><div><span class="bl">一句话：</span><?= h($b['logline']) ?></div><?php endif; ?>
        <?php if (!empty($b['theme'])): ?><div><span class="bl">主题：</span><?= h($b['theme']) ?></div><?php endif; ?>
        <?php if (!empty($b['protagonist_goal'])): ?><div><span class="bl">主角目标：</span><?= h($b['protagonist_goal']) ?></div><?php endif; ?>
        <?php if (!empty($b['obstacle'])): ?><div><span class="bl">阻碍：</span><?= h($b['obstacle']) ?></div><?php endif; ?>
        <?php if (!empty($b['turning_point'])): ?><div><span class="bl">转折：</span><?= h($b['turning_point']) ?></div><?php endif; ?>
        <?php if (!empty($b['ending_echo'])): ?><div><span class="bl">结尾回响：</span><?= h($b['ending_echo']) ?></div><?php endif; ?>
        <?php if (!empty($b['tone'])): ?><div><span class="bl">文风：</span><?= h($b['tone']) ?></div><?php endif; ?>
      </div>
      <div id="briefEdit" style="display:none">
        <div class="brief-box">
          <?php $b = $story['brief_json']; ?>
          <div class="mb-1"><span class="bl">一句话：</span><input type="text" class="form-control form-control-sm" id="editLogline" value="<?= h($b['logline'] ?? '') ?>"></div>
          <div class="mb-1"><span class="bl">主题：</span><input type="text" class="form-control form-control-sm" id="editTheme" value="<?= h($b['theme'] ?? '') ?>"></div>
          <div class="mb-1"><span class="bl">主角目标：</span><input type="text" class="form-control form-control-sm" id="editGoal" value="<?= h($b['protagonist_goal'] ?? '') ?>"></div>
          <div class="mb-1"><span class="bl">阻碍：</span><input type="text" class="form-control form-control-sm" id="editObstacle" value="<?= h($b['obstacle'] ?? '') ?>"></div>
          <div class="mb-1"><span class="bl">转折：</span><input type="text" class="form-control form-control-sm" id="editTurning" value="<?= h($b['turning_point'] ?? '') ?>"></div>
          <div class="mb-1"><span class="bl">结尾回响：</span><input type="text" class="form-control form-control-sm" id="editEcho" value="<?= h($b['ending_echo'] ?? '') ?>"></div>
          <div class="mb-1"><span class="bl">文风：</span><input type="text" class="form-control form-control-sm" id="editTone" value="<?= h($b['tone'] ?? '') ?>"></div>
          <div class="d-flex gap-1 mt-2">
            <button class="btn btn-success btn-sm flex-fill" onclick="saveBriefEdit()">保存</button>
            <button class="btn btn-outline-secondary btn-sm flex-fill" onclick="cancelBriefEdit()">取消</button>
          </div>
        </div>
      </div>
      <button class="wb-btn" onclick="toggleBriefEdit()" id="btnEditBrief"><i class="bi bi-pencil"></i>编辑概要</button>
      <?php else: ?>
      <div class="text-muted small">尚未生成概要</div>
      <?php endif; ?>
    </div>
    <button class="wb-btn" onclick="generateBrief()" id="btnBrief">
      <i class="bi bi-stars"></i>生成概要
    </button>

    <h6 class="mt-3"><i class="bi bi-list-ol me-1"></i>叙事节拍</h6>
    <div id="beatsArea">
      <?php if (!empty($story['beats_json'])): ?>
      <div id="beatsDisplay">
      <?php foreach ($story['beats_json'] as $i => $beat): ?>
      <div class="beat-item">
        <div class="beat-name"><?= h($beat['name'] ?? '节拍' . ($beat['order'] ?? ($i+1))) ?></div>
        <div><?= h($beat['purpose'] ?? '') ?></div>
        <?php if (!empty($beat['word_budget'])): ?><div class="text-muted" style="font-size:.72rem">约<?= (int)$beat['word_budget'] ?>字</div><?php endif; ?>
      </div>
      <?php endforeach; ?>
      </div>
      <div id="beatsEdit" style="display:none">
        <?php foreach ($story['beats_json'] as $i => $beat): ?>
        <div class="beat-item" style="border-left-color:#4ade80">
          <div class="mb-1"><span class="bl">名称：</span><input type="text" class="form-control form-control-sm beat-field" data-idx="<?= $i ?>" data-key="name" value="<?= h($beat['name'] ?? '') ?>"></div>
          <div class="mb-1"><span class="bl">作用：</span><input type="text" class="form-control form-control-sm beat-field" data-idx="<?= $i ?>" data-key="purpose" value="<?= h($beat['purpose'] ?? '') ?>"></div>
          <div class="mb-1"><span class="bl">事件：</span><input type="text" class="form-control form-control-sm beat-field" data-idx="<?= $i ?>" data-key="event" value="<?= h($beat['event'] ?? '') ?>"></div>
          <div class="mb-1"><span class="bl">情绪：</span><input type="text" class="form-control form-control-sm beat-field" data-idx="<?= $i ?>" data-key="emotion_shift" value="<?= h($beat['emotion_shift'] ?? '') ?>"></div>
          <div class="mb-1"><span class="bl">字数：</span><input type="number" class="form-control form-control-sm beat-field" data-idx="<?= $i ?>" data-key="word_budget" value="<?= (int)($beat['word_budget'] ?? 0) ?>" style="max-width:100px;display:inline-block"></div>
        </div>
        <?php endforeach; ?>
        <div class="d-flex gap-1 mt-2">
          <button class="btn btn-success btn-sm flex-fill" onclick="saveBeatsEdit()">保存</button>
          <button class="btn btn-outline-secondary btn-sm flex-fill" onclick="cancelBeatsEdit()">取消</button>
        </div>
      </div>
      <button class="wb-btn" onclick="toggleBeatsEdit()" id="btnEditBeats"><i class="bi bi-pencil"></i>编辑节拍</button>
      <?php else: ?>
      <div class="text-muted small">尚未生成节拍</div>
      <?php endif; ?>
    </div>
    <button class="wb-btn" onclick="generateBeats()" id="btnBeats" <?= empty($story['brief_json']) ? 'disabled' : '' ?>>
      <i class="bi bi-list-ol"></i>生成节拍
    </button>

    <?php if ((int)($story['chapter_count'] ?? 1) >= 2): ?>
    <h6 class="mt-3"><i class="bi bi-bookmark-star me-1"></i>章节列表
      <span class="text-muted" style="font-weight:400;font-size:.72rem">（共 <?= (int)$story['chapter_count'] ?> 章）</span>
    </h6>
    <div id="chaptersArea">
      <?php if (!empty($story['chapters_json'])): ?>
      <div id="chaptersList">
        <?php foreach ($story['chapters_json'] as $i => $ch):
          $st = $ch['status'] ?? 'pending';
          $stLabel = $st === 'written' ? '已写' : ($st === 'polished' ? '已润色' : '未写');
        ?>
        <div class="chapter-item" data-idx="<?= $i ?>" onclick="selectChapter(<?= $i ?>)">
          <div style="flex:1;min-width:0;">
            <div class="ch-title">第<?= (int)($ch['order'] ?? ($i+1)) ?>章 · <?= h($ch['title'] ?? '') ?></div>
            <div class="ch-meta">
              <span class="ch-status-<?= h($st) ?>">● <?= h($stLabel) ?></span>
              · <?= mb_strlen(preg_replace('/\s+/', '', $ch['content'] ?? '')) ?>/<?= (int)($ch['word_budget'] ?? 0) ?>字
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <button class="wb-btn mt-2" onclick="toggleChaptersEdit()" id="btnEditChapters"><i class="bi bi-pencil"></i>编辑章节大纲</button>
      <button class="wb-btn" onclick="writeAllChapters()" id="btnWriteAll"><i class="bi bi-lightning-charge"></i>按顺序写全部章节</button>

      <div id="chaptersEdit" style="display:none">
        <?php foreach ($story['chapters_json'] as $i => $ch): ?>
        <div class="chapter-edit-block">
          <div class="mb-1"><span class="bl">第<?= (int)($ch['order'] ?? ($i+1)) ?>章 标题：</span>
            <input type="text" class="form-control form-control-sm chapter-field" data-idx="<?= $i ?>" data-key="title" value="<?= h($ch['title'] ?? '') ?>">
          </div>
          <div class="mb-1"><span class="bl">梗概：</span>
            <textarea class="form-control form-control-sm chapter-field" data-idx="<?= $i ?>" data-key="synopsis" rows="2"><?= h($ch['synopsis'] ?? '') ?></textarea>
          </div>
          <div class="mb-1"><span class="bl">字数预算：</span>
            <input type="number" class="form-control form-control-sm chapter-field" data-idx="<?= $i ?>" data-key="word_budget" value="<?= (int)($ch['word_budget'] ?? 0) ?>" style="max-width:120px;display:inline-block">
          </div>
          <div class="mb-1"><span class="bl">覆盖节拍(逗号分隔)：</span>
            <input type="text" class="form-control form-control-sm chapter-field" data-idx="<?= $i ?>" data-key="beat_refs" value="<?= h(implode(',', array_map('intval', $ch['beat_refs'] ?? []))) ?>" style="max-width:150px;display:inline-block">
          </div>
        </div>
        <?php endforeach; ?>
        <div class="d-flex gap-1 mt-2">
          <button class="btn btn-success btn-sm flex-fill" onclick="saveChaptersEdit()">保存</button>
          <button class="btn btn-outline-secondary btn-sm flex-fill" onclick="cancelChaptersEdit()">取消</button>
        </div>
      </div>
      <?php else: ?>
      <div class="text-muted small">尚未生成章节大纲</div>
      <?php endif; ?>
    </div>
    <button class="wb-btn" onclick="generateChapters()" id="btnChapters" <?= empty($story['beats_json']) ? 'disabled' : '' ?>>
      <i class="bi bi-stars"></i>生成章节大纲
    </button>
    <?php endif; ?>
  </div>

  <!-- Center: Editor -->
  <div class="wb-center">
    <?php $isChapterMode = (int)($story['chapter_count'] ?? 1) >= 2; ?>
    <?php if ($isChapterMode): ?>
    <div class="mode-tabs">
      <div class="mode-tab active" id="tabChapter" onclick="switchEditMode('chapter')">分章模式</div>
      <div class="mode-tab" id="tabFull" onclick="switchEditMode('full')">全文预览</div>
    </div>
    <div id="chapterHeader" class="chapter-header" style="display:none">
      <span id="chapterHeaderTitle">选择左侧章节开始编辑</span>
      <span id="chapterHeaderProgress" class="text-muted" style="font-size:.72rem"></span>
    </div>
    <?php endif; ?>
    <div class="wb-editor" id="editorWrap">
      <div id="sseProgress" style="display:none" class="sse-progress">
        <div class="d-flex align-items-center justify-content-between">
          <span><i class="bi bi-cpu me-1"></i>AI 正在创作...</span>
          <span id="sseWordCount">0字</span>
        </div>
        <div class="progress-bar-bg"><div class="progress-bar-fill" id="sseBar" style="width:0%"></div></div>
      </div>
      <textarea id="editorContent" placeholder="<?= $isChapterMode ? '选择左侧章节,或点击「写本章」生成...' : '在这里写作,或点击右侧按钮生成初稿...' ?>"><?= h($story['content'] ?? '') ?></textarea>
      <div id="ssePreview" style="display:none;white-space:pre-wrap;color:#e8e8ff;font-size:.95rem;line-height:1.8;"></div>
    </div>
    <div class="wb-status-bar">
      <span id="wordCount" data-target="<?= $story['target_words'] ?>">字数：<?= number_format($story['word_count'] ?? 0) ?> / <?= number_format($story['target_words']) ?></span>
      <span>
        <button class="btn btn-outline-danger btn-sm me-1" onclick="stopDraft()" id="btnStop" style="display:none"><i class="bi bi-stop-fill me-1"></i>停止</button>
        <button class="btn btn-outline-primary btn-sm me-1" onclick="saveContent()" id="btnSave"><i class="bi bi-save me-1"></i>保存</button>
        <?php if ($isChapterMode): ?>
        <button class="btn btn-primary btn-sm" onclick="writeCurrentChapter()" id="btnWriteChapter" disabled>
          <i class="bi bi-pencil-square me-1"></i>写本章
        </button>
        <button class="btn btn-outline-warning btn-sm ms-1" onclick="writeDraft()" id="btnDraft" <?= empty($story['beats_json']) ? 'disabled' : '' ?> title="单篇模式用：一次生成整篇,不分章节">
          <i class="bi bi-pencil-square me-1"></i>生成初稿（单篇）
        </button>
        <?php else: ?>
        <button class="btn btn-primary btn-sm" onclick="writeDraft()" id="btnDraft" <?= empty($story['beats_json']) ? 'disabled' : '' ?> title="单篇模式：一次生成整篇,不分章节">
          <i class="bi bi-pencil-square me-1"></i>生成初稿（单篇模式用）
        </button>
        <?php endif; ?>
      </span>
    </div>
  </div>

  <!-- Right: AI Actions & Quality -->
  <div class="wb-panel">
    <h6><i class="bi bi-magic me-1"></i>AI 助手</h6>
    <button class="wb-btn" onclick="polishShort('full')"><i class="bi bi-brush"></i>全文润色</button>
    <button class="wb-btn" onclick="polishShort('opening')"><i class="bi bi-skip-start-fill"></i>强化开头</button>
    <button class="wb-btn" onclick="polishShort('ending')"><i class="bi bi-skip-end-fill"></i>强化结尾</button>
    <button class="wb-btn" onclick="polishShort('dialogue')"><i class="bi bi-chat-quote"></i>优化对话</button>
    <button class="wb-btn" onclick="optimizeTitle()"><i class="bi bi-type-bold"></i>优化标题</button>

    <h6 class="mt-3"><i class="bi bi-shield-check me-1"></i>质量检测</h6>
    <button class="wb-btn" onclick="checkQuality()"><i class="bi bi-clipboard-check"></i>运行检测</button>
    <div id="qualityArea" class="mt-2">
      <?php if (!empty($story['quality_report'])): ?>
      <div class="quality-box">
        <div class="q-score <?= ($story['quality_report']['score'] ?? 0) >= 60 ? 'q-pass' : 'q-fail' ?>">
          <?= $story['quality_report']['score'] ?? 0 ?>分
        </div>
        <?php foreach (($story['quality_report']['issues'] ?? []) as $issue): ?>
        <div class="small mt-1" style="color:<?= $issue['severity'] === 'error' ? '#f87171' : ($issue['severity'] === 'warning' ? '#fbbf24' : '#9090b8') ?>">
          · <?= h($issue['message']) ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

</div>

<!-- Version Modal -->
<div class="modal fade" id="versionModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="background:#1a1a2e;border:1px solid #2d2d4e;">
      <div class="modal-header" style="border-color:#2d2d4e;">
        <h6 class="modal-title text-light">版本历史</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="versionList" style="max-height:60vh;overflow-y:auto;">
        <?php foreach ($versions as $v): ?>
        <div class="version-item">
          <div>
            <strong class="text-light">v<?= $v['version'] ?></strong>
            <span class="ms-2"><?= h($v['note'] ?? '') ?></span>
            <span class="ms-2 text-muted" style="font-size:.72rem"><?= h($v['created_at']) ?></span>
          </div>
          <button class="btn btn-outline-warning btn-sm" style="font-size:.72rem" onclick="rollbackVersion(<?= $v['id'] ?>)">回滚</button>
        </div>
        <?php endforeach; ?>
        <?php if (empty($versions)): ?>
        <div class="text-muted text-center py-3">暂无版本历史</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Title Modal -->
<div class="modal fade" id="titleModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content" style="background:#1a1a2e;border:1px solid #2d2d4e;">
      <div class="modal-header" style="border-color:#2d2d4e;">
        <h6 class="modal-title text-light">选择标题</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="titleList"></div>
    </div>
  </div>
</div>

<script>
window.SHORT_STORY_ID = <?= $id ?>;
window.SHORT_STORY_MODEL_ID = <?= $story['model_id'] ?? 'null' ?>;
window.SHORT_STORY_CHAPTER_COUNT = <?= (int)($story['chapter_count'] ?? 1) ?>;
window.SHORT_STORY_CHAPTERS = <?= json_encode($story['chapters_json'] ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="assets/js/short_story.js?v=<?= @filemtime(__DIR__ . '/assets/js/short_story.js') ?: time() ?>"></script>
<script>
initShortStoryWordCount();
</script>

<?php pageFooter(); ?>
