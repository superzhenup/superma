<?php
/**
 * 生成章节细纲「先落库、再增强」顺序 静态回归测试
 *
 * 背景（用户报告，第三轮）：生成到 21-25，5 章流式输出完成后**未自动保存**即超时，
 *   随后重复生成；控制台 `POST .../generate_outline net::ERR_INCOMPLETE_CHUNKED_ENCODING 200`。
 *
 * 根因：流式结束后、**保存之前**还要跑质量返修 `repairOutlineBatchWithGuard` +
 *   转折点 `enforceTurningPoints`（含多次阻塞式 AI 调用）。随着批次靠后、上下文增大，
 *   整批总时长累积超过 php-fpm `request_terminate_timeout`（硬杀，心跳挡不住），
 *   进程在「保存之前」被杀 → 章节未落库 → 前端重连重生成同一批。
 *
 * 修复：把保存抽成幂等闭包 `persistOutlines`，**先落库原始解析结果 + 立即 `batch_done`**，
 *   再做质量返修 / 转折点增强，最后把增强结果**回写**。这样无论后续增强多慢、被任何
 *   超时/异常中断，章节都已入库、前端也已据 `actual_end` 推进，不丢章、不重复整批生成。
 *
 * 守护目标（api/generate_outline.php 的步骤顺序）：
 *   1) 定义了 persistOutlines 幂等持久化闭包；
 *   2) 首次落库（$persistResult = $persistOutlines(...)）与 batch_done 都在
 *      质量返修 / 转折点之前；
 *   3) 质量返修 / 转折点之后存在一次「增强回写」persistOutlines 调用。
 */

function osbe_assert(bool $cond, string $msg): void
{
    if (!$cond) {
        echo "outline_save_before_enhance_static_test FAILED: {$msg}\n";
        exit(1);
    }
}

$go = @file_get_contents(dirname(__DIR__) . '/api/generate_outline.php');
osbe_assert($go !== false, '无法读取 api/generate_outline.php');

// 1) 持久化闭包存在
osbe_assert(strpos($go, '$persistOutlines = function') !== false,
    '未定义幂等持久化闭包 $persistOutlines（无法「先落库再增强」）');

// 关键锚点位置
$posFirstPersist = strpos($go, '$persistResult = $persistOutlines(');
$posBatchDone    = strpos($go, "sse('batch_done'");
$posGuard        = strpos($go, 'repairOutlineBatchWithGuard(');
$posTurningPts   = strpos($go, 'enforceTurningPoints(');

osbe_assert($posFirstPersist !== false, '未找到首次落库调用 $persistResult = $persistOutlines(...)');
osbe_assert($posBatchDone !== false,    '未找到 batch_done 事件');
osbe_assert($posGuard !== false,        '未找到 repairOutlineBatchWithGuard 调用');
osbe_assert($posTurningPts !== false,   '未找到 enforceTurningPoints 调用');

// 2) 先落库 + batch_done 必须在「质量返修 / 转折点」之前
osbe_assert($posFirstPersist < $posBatchDone,
    '首次落库应在 batch_done 之前');
osbe_assert($posBatchDone < $posGuard,
    'batch_done 必须在质量返修之前（否则增强超时会拖垮「章节先落库」）');
osbe_assert($posBatchDone < $posTurningPts,
    'batch_done 必须在转折点增强之前');

// 3) 质量返修 / 转折点之后存在「增强回写」persistOutlines 调用
$posRepersist = strpos($go, '$persistOutlines($outlines)', $posTurningPts);
osbe_assert($posRepersist !== false && $posRepersist > $posTurningPts,
    '质量返修 / 转折点之后未回写增强结果（应在增强后再次调用 $persistOutlines($outlines)）');

echo "outline_save_before_enhance_static_test passed (先落库原始解析结果 + batch_done，再增强回写；杜绝增强超时致丢章/重复生成)\n";
