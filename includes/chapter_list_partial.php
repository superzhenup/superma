<?php
/**
 * Shared chapter-list partial for novel.php and AJAX refreshes.
 *
 * Required variables:
 * $id, $novel, $allChapters, $chapters, $synopsisMap,
 * $totalChapterCount, $totalPages, $currentPage
 */
defined('APP_LOADED') or die('Direct access denied.');

$currentPage = max(1, (int)($currentPage ?? 1));
$totalPages = max(1, (int)($totalPages ?? 1));
$totalChapterCount = (int)($totalChapterCount ?? 0);
$chapters = is_array($chapters ?? null) ? $chapters : [];
$synopsisMap = is_array($synopsisMap ?? null) ? $synopsisMap : [];
$chapterTargetWords = (int)($novel['chapter_words'] ?? 0);
// 审计修复（2026-08-17 写作逻辑 B0）：质检失败章原因摘要（chapter_id => 原因），
// 由 novel.php / api/chapter_list.php 批量查询后传入；未传时退化为空（仅显示状态徽章）。
$hardFailMap = is_array($hardFailMap ?? null) ? $hardFailMap : [];
$candidateReviewMap = [];
$branchWorkbenchEnabled = false;
try {
    require_once __DIR__ . '/harness/candidates/CandidateUiPolicy.php';
    if (CandidateUiPolicy::enabledForNovel((int)$id)) {
        $branchWorkbenchEnabled = true;
        $visibleChapterIds = array_values(array_filter(array_map(
            static fn(array $chapter): int => (int)($chapter['id'] ?? 0),
            $chapters
        )));
        if ($visibleChapterIds !== []) {
            $candidatePlaceholders = implode(',', array_fill(0, count($visibleChapterIds), '?'));
            $candidateRows = DB::fetchAll(
                "SELECT cc.chapter_id, COUNT(*) AS total,
                        SUM(cc.lifecycle='quarantined') AS quarantined_total,
                        SUM(cc.lifecycle='approved') AS approved_total,
                        SUM(cc.source='branch_merge' AND cc.lifecycle='draft') AS merge_total
                 FROM chapter_candidates cc
                 WHERE cc.novel_id=? AND cc.chapter_id IN ({$candidatePlaceholders})
                   AND (cc.lifecycle IN ('quarantined','approved')
                        OR (cc.source='branch_merge' AND cc.lifecycle='draft'
                            AND cc.gate_state='passed' AND EXISTS (
                                SELECT 1 FROM approval_requests ar
                                WHERE ar.candidate_id=cc.id
                                  AND ar.action_type='branch_merge' AND ar.state='open'
                            )))
                 GROUP BY cc.chapter_id",
                array_merge([(int)$id], $visibleChapterIds)
            ) ?: [];
            foreach ($candidateRows as $candidateRow) {
                $candidateReviewMap[(int)$candidateRow['chapter_id']] = [
                    'total' => (int)$candidateRow['total'],
                    'quarantined' => (int)$candidateRow['quarantined_total'],
                    'approved' => (int)$candidateRow['approved_total'],
                    'merge' => (int)$candidateRow['merge_total'],
                ];
            }
        }
    }
} catch (Throwable $candidateUiError) {
    // Candidate UI is fail-closed: chapter rendering remains available but no
    // review affordance is shown when rollout configuration or storage fails.
    error_log('candidate review badge unavailable: ' . $candidateUiError->getMessage());
    $candidateReviewMap = [];
    $branchWorkbenchEnabled = false;
}
$pageUrl = fn($p) => 'novel.php?id=' . (int)$id . '&page=' . (int)$p;
?>
<div id="chapter-list-ajax-root"
     data-current-page="<?= $currentPage ?>"
     data-total-pages="<?= $totalPages ?>"
     data-total-chapters="<?= $totalChapterCount ?>">
  <?php if ($totalChapterCount <= 0): ?>
  <div class="empty-state">
    <div class="empty-icon"><i class="bi bi-list-ol"></i></div>
    <h6>暂无章节</h6>
    <p class="text-muted small">点击「生成章节大纲」按钮，AI将自动生成所有章节的大纲</p>
  </div>
  <?php endif; ?>

  <div class="page-card">
    <!-- 导出/导入按钮组 — 始终显示 -->
    <div class="d-flex justify-content-end align-items-center mb-3 gap-2">
      <div class="btn-group">
        <button type="button" class="btn btn-sm btn-outline-success dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
          <i class="bi bi-download me-1"></i>导出章节概要
        </button>
        <ul class="dropdown-menu">
          <li><a class="dropdown-item" href="#" data-csp-click="novel.export-synopses" data-format="json">
            <i class="bi bi-filetype-json me-2"></i>导出为JSON
          </a></li>
          <li><a class="dropdown-item" href="#" data-csp-click="novel.export-synopses" data-format="excel">
            <i class="bi bi-file-earmark-excel me-2"></i>导出为Excel
          </a></li>
          <li><a class="dropdown-item" href="#" data-csp-click="novel.export-synopses" data-format="txt">
            <i class="bi bi-filetype-txt me-2"></i>导出为TXT
          </a></li>
        </ul>
      </div>
      <button type="button" class="btn btn-sm btn-outline-primary" data-csp-click="novel.choose-import">
        <i class="bi bi-upload me-1"></i>导入章节概要
      </button>
      <input type="file" id="import-file-input" accept=".json,.csv,.txt" style="display:none" data-csp-change="novel.import-synopses">
      <?php if ($branchWorkbenchEnabled): ?>
      <button type="button" class="btn btn-sm btn-outline-info btn-branch-workbench"
              title="管理非主线分支、隔离记忆和人工合并">
        <i class="bi bi-diagram-3 me-1"></i>分支工作台
      </button>
      <?php endif; ?>
      <button type="button" class="btn btn-sm btn-outline-success" data-csp-click="novel.add-chapters" title="添加章节">
        <i class="bi bi-plus-lg"></i>
      </button>
      <button type="button" class="btn btn-sm btn-outline-danger" data-csp-click="novel.clear-chapters" title="清空所有章节内容">
        <i class="bi bi-trash"></i>
      </button>
    </div>

    <?php if ($totalChapterCount > 0): ?>
    <!-- 章节列表头 -->
    <div class="chapter-list-header">
      <span class="col-num">章节</span>
      <span class="col-title">标题 / 大纲概要</span>
      <span class="col-status">状态</span>
      <span class="col-words">字数</span>
      <span class="col-action">操作</span>
    </div>
    <!-- 章节行 -->
    <?php foreach ($chapters as $ch): ?>
    <?php
      $synopsisText = $synopsisMap[$ch['chapter_number']] ?? null;
      $synopsis = $synopsisText ? ['synopsis' => $synopsisText] : null;
    ?>
    <div class="chapter-list-row" data-status="<?= h($ch['status']) ?>">
      <div class="col-num">
        <span class="ch-number">第<?= (int)$ch['chapter_number'] ?>章</span>
      </div>
      <div class="col-title">
        <div class="ch-title"><?= h($ch['title'] ?: '（待生成标题）') ?></div>
        <?php if ($ch['outline']): ?>
        <div class="ch-outline"><?= h(mb_substr($ch['outline'], 0, 80)) ?><?= mb_strlen($ch['outline']) > 80 ? '…' : '' ?></div>
        <?php endif; ?>
        <?php if ($synopsis && $synopsis['synopsis']): ?>
        <div class="ch-synopsis mt-1">
          <span class="badge bg-secondary me-1">概要</span>
          <small class="text-muted"><?= h(mb_substr($synopsis['synopsis'], 0, 100)) ?><?= mb_strlen($synopsis['synopsis']) > 100 ? '…' : '' ?></small>
        </div>
        <?php endif; ?>
        <?php
          // 质检未过现仅提示：completed 警告章与旧版 failed 章都显示原因。
          $failReason = (string)($hardFailMap[(int)$ch['id']] ?? '');
        ?>
        <?php if ($failReason !== '' || ($ch['status'] ?? '') === 'failed'): ?>
        <div class="ch-hard-fail mt-1" title="<?= h($failReason) ?>">
          <span class="badge bg-warning text-dark me-1">质检未过</span>
          <?php if ($failReason !== ''): ?>
          <small class="text-danger"><?= h(mb_substr($failReason, 0, 80)) ?><?= mb_strlen($failReason) > 80 ? '…' : '' ?></small>
          <?php else: ?>
          <small class="text-muted">未记录具体质检原因</small>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php $candidateReview = $candidateReviewMap[(int)$ch['id']] ?? null; ?>
        <?php if (is_array($candidateReview)): ?>
        <div class="ch-candidate-review mt-1">
          <span class="badge bg-danger me-1">待审候选 <?= (int)$candidateReview['total'] ?></span>
          <small class="text-warning">尚未进入正史，自动续写已阻断</small>
        </div>
        <?php endif; ?>
      </div>
      <div class="col-status"><?= statusBadge($ch['status']) ?></div>
      <div class="col-words">
        <?php
          $isOverLimit = $ch['words'] > 0 && $chapterTargetWords > 0 && $ch['words'] > $chapterTargetWords + 500;
          if ($ch['words'] > 0) {
            $wordStyle = $isOverLimit ? 'color:#ef4444;font-weight:700;' : '';
            echo '<span class="ch-words" style="' . $wordStyle . '" title="' . ($isOverLimit ? "超出目标{$chapterTargetWords}字+500" : "字数正常") . '">' . number_format($ch['words']) . '</span>';
          } else {
            echo '<span class="ch-words-empty">—</span>';
          }
        ?>
      </div>
      <div class="col-action">
        <?php if (isset($candidateReviewMap[(int)$ch['id']])): ?>
        <button class="btn btn-xs btn-outline-danger btn-candidate-review"
                data-chapter-id="<?= (int)$ch['id'] ?>"
                data-chapter-num="<?= (int)$ch['chapter_number'] ?>"
                title="预览并处理隔离候选稿">
          <i class="bi bi-shield-exclamation"></i> 处理候选稿
        </button>
        <?php endif; ?>
        <?php if ($ch['status'] !== 'pending'): ?>
        <a href="chapter.php?id=<?= (int)$ch['id'] ?>&edit=1" class="btn btn-xs btn-outline-secondary" title="编辑章节">
          <i class="bi bi-pencil"></i> 编辑
        </a>
        <?php endif; ?>
        <?php if ($ch['status'] === 'completed'): ?>
        <button class="btn btn-xs btn-outline-info btn-chapter-detail"
                data-chapter-id="<?= (int)$ch['id'] ?>"
                data-chapter-num="<?= (int)$ch['chapter_number'] ?>">
          <i class="bi bi-eye"></i> 查看
        </button>
        <?php elseif ($ch['status'] === 'outlined'): ?>
        <button class="btn btn-xs btn-outline-primary btn-write-single"
                data-novel="<?= (int)$id ?>" data-chapter="<?= (int)$ch['id'] ?>">
          <i class="bi bi-pencil"></i> 写作
        </button>
        <?php elseif ($ch['status'] === 'writing'): ?>
        <button class="btn btn-xs btn-outline-warning" data-csp-click="novel.reset-chapter" data-chapter-id="<?= (int)$ch['id'] ?>" title="取消并重置">
          <i class="bi bi-x-circle"></i> 取消
        </button>
        <?php elseif ($ch['status'] === 'failed'): ?>
        <?php
          // 审计修复（2026-08-17 写作逻辑 B0）：failed 是「需介入」状态——
          // 保留正文重写走显式 chapter_id 写作（不清空旧正文，失败原因随重写覆盖）；
          // 清空重写沿用 reset_chapter（正文存历史版本后清空，恢复为已大纲）。
        ?>
        <button class="btn btn-xs btn-outline-primary btn-write-single"
                data-novel="<?= (int)$id ?>" data-chapter="<?= (int)$ch['id'] ?>"
                title="保留当前正文（自动存入历史版本），按失败原因重新生成本章">
          <i class="bi bi-arrow-repeat"></i> 保留正文重写
        </button>
        <button class="btn btn-xs btn-outline-warning" data-csp-click="novel.reset-chapter" data-chapter-id="<?= (int)$ch['id'] ?>"
                title="清空本章正文（存历史版本），恢复为已大纲状态">
          <i class="bi bi-eraser"></i> 清空重写
        </button>
        <?php else: ?>
        <span class="ch-status-text">待大纲</span>
        <?php endif; ?>
        <?php if ($ch['status'] !== 'pending'): ?>
        <button class="btn btn-xs btn-outline-warning btn-regenerate-synopsis"
                data-novel="<?= (int)$id ?>"
                data-chapter-id="<?= (int)$ch['id'] ?>"
                data-chapter-num="<?= (int)$ch['chapter_number'] ?>"
                data-has-title="<?= $ch['title'] ? '1' : '0' ?>"
                title="<?= $ch['title'] ? '重新生成细纲' : '生成章节标题' ?>">
          <i class="bi bi-arrow-clockwise"></i>
        </button>
        <?php endif; ?>
        <?php if ($ch['status'] !== 'writing'): ?>
        <button class="btn btn-xs btn-outline-danger btn-delete-chapter"
                data-chapter-id="<?= (int)$ch['id'] ?>"
                data-chapter-num="<?= (int)$ch['chapter_number'] ?>"
                title="删除本章（含正文/细纲/概要/历史版本）">
          <i class="bi bi-trash"></i>
        </button>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- 章节分页 -->
  <?php if ($totalPages > 1): ?>
  <div class="d-flex justify-content-center align-items-center mt-3 gap-2 flex-wrap">
    <span class="text-muted small me-2">第 <?= $currentPage ?> / <?= $totalPages ?> 页（共 <?= $totalChapterCount ?> 章）</span>
    <nav aria-label="章节分页">
      <ul class="pagination pagination-sm mb-0">
        <?php if ($currentPage > 1): ?>
          <li class="page-item">
            <a class="page-link chapter-list-page-link" data-ajax-page="<?= $currentPage - 1 ?>" href="<?= $pageUrl($currentPage - 1) ?>"><i class="bi bi-chevron-left"></i></a>
          </li>
        <?php else: ?>
          <li class="page-item disabled"><span class="page-link"><i class="bi bi-chevron-left"></i></span></li>
        <?php endif; ?>

        <?php
          $pageStart = max(1, $currentPage - 2);
          $pageEnd = min($totalPages, $currentPage + 2);
        ?>
        <?php if ($pageStart > 1): ?>
          <li class="page-item"><a class="page-link chapter-list-page-link" data-ajax-page="1" href="<?= $pageUrl(1) ?>">1</a></li>
          <?php if ($pageStart > 2): ?>
            <li class="page-item disabled"><span class="page-link">…</span></li>
          <?php endif; ?>
        <?php endif; ?>

        <?php for ($p = $pageStart; $p <= $pageEnd; $p++): ?>
          <?php if ($p === $currentPage): ?>
            <li class="page-item active"><span class="page-link"><?= $p ?></span></li>
          <?php else: ?>
            <li class="page-item"><a class="page-link chapter-list-page-link" data-ajax-page="<?= $p ?>" href="<?= $pageUrl($p) ?>"><?= $p ?></a></li>
          <?php endif; ?>
        <?php endfor; ?>

        <?php if ($pageEnd < $totalPages): ?>
          <?php if ($pageEnd < $totalPages - 1): ?>
            <li class="page-item disabled"><span class="page-link">…</span></li>
          <?php endif; ?>
          <li class="page-item"><a class="page-link chapter-list-page-link" data-ajax-page="<?= $totalPages ?>" href="<?= $pageUrl($totalPages) ?>"><?= $totalPages ?></a></li>
        <?php endif; ?>

        <?php if ($currentPage < $totalPages): ?>
          <li class="page-item">
            <a class="page-link chapter-list-page-link" data-ajax-page="<?= $currentPage + 1 ?>" href="<?= $pageUrl($currentPage + 1) ?>"><i class="bi bi-chevron-right"></i></a>
          </li>
        <?php else: ?>
          <li class="page-item disabled"><span class="page-link"><i class="bi bi-chevron-right"></i></span></li>
        <?php endif; ?>
      </ul>
    </nav>
  </div>
  <?php endif; ?>
  <!-- /章节分页 -->
</div>
