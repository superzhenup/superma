<?php
/**
 * 生成章节细纲「按 batch_done 权威进度推进」静态回归测试
 *
 * 背景（用户报告）：点「生成章节细纲」后某一批（如 1-5）反复循环重生成、
 *   一个多小时仍卡在同一批；刷新网页能看到该批已存库，不刷新则一直循环，
 *   再点「生成 6-10」同样循环。
 *
 * 根因：后端 api/generate_outline.php 在章节落库后**先发 batch_done**
 *   （注释原话：「先发送 batch_done，让前端立即推进」），随后才做较慢且静默的
 *   「弧段摘要」AI 调用，**最后才发 [DONE]**。旧前端 runChunk 忽略 batch_done，
 *   只靠 [DONE] 抵达 / 二次查询 get_outline_progress（fetchLastOutlined）推进。
 *   连接在弧段摘要静默期被代理（宝塔/nginx）判定掉线 → runChunk 返回 'dropped'
 *   → 重连恢复依赖 fetchLastOutlined；该二次查询一旦失败/返回 0，即重复请求同一批，
 *   而后端 ignore_user_abort(true) 仍把数据写库 →「刷新可见、不刷新死循环」。
 *
 * 守护目标（assets/js/app.js 的 generateOutline 主循环）：
 *   1) 声明了 batchSavedEnd 进度变量；
 *   2) batch_done 事件把后端上报的 actual_end 记入 batchSavedEnd；
 *   3) 每段调用 runChunk 前重置 batchSavedEnd（避免沿用上一段进度误推进）；
 *   4) 主循环在判定 result==='complete' 之前，**优先**按 batchSavedEnd 推进
 *      （currentStart = batchSavedEnd + 1），使「已落库即推进」不依赖 [DONE]/二次查询。
 */

$app = @file_get_contents(dirname(__DIR__) . '/assets/js/app.js');
if ($app === false) {
    echo "outline_batch_progress_static_test FAILED: 无法读取 assets/js/app.js\n";
    exit(1);
}

function obp_assert(bool $cond, string $msg): void
{
    if (!$cond) {
        echo "outline_batch_progress_static_test FAILED: {$msg}\n";
        exit(1);
    }
}

// 1) 进度变量声明
obp_assert(strpos($app, 'let batchSavedEnd') !== false,
    'app.js 未声明 batchSavedEnd 进度变量');

// 2) batch_done 事件捕获后端的 actual_end（落库的确凿信号）
obp_assert(strpos($app, 'batchSavedEnd = Math.max(batchSavedEnd, parseInt(d.actual_end)') !== false,
    'batch_done 事件未把后端上报的 actual_end 记入 batchSavedEnd');

// 定位 generateOutline 主循环
$loopStart = strpos($app, 'while (currentStart <= endCh && outlineRunning)');
obp_assert($loopStart !== false, '未找到 generateOutline 的主循环 while (currentStart <= endCh && outlineRunning)');

// 主循环体内的关键锚点位置（均相对 loopStart 之后）
$posReset       = strpos($app, 'batchSavedEnd = 0', $loopStart);
$posRunChunk    = strpos($app, 'await runChunk(currentStart, currentEnd)', $loopStart);
$posAdvanceIf   = strpos($app, 'if (batchSavedEnd >= currentStart)', $loopStart);
$posAdvanceSet  = strpos($app, 'currentStart = batchSavedEnd + 1', $loopStart);
$posComplete    = strpos($app, "if (result === 'complete')", $loopStart);

obp_assert($posReset !== false,      '主循环体内未在调用 runChunk 前重置 batchSavedEnd');
obp_assert($posRunChunk !== false,   '主循环未找到 await runChunk(currentStart, currentEnd)');
obp_assert($posAdvanceIf !== false,  '主循环缺少按 batchSavedEnd 推进的判断 if (batchSavedEnd >= currentStart)');
obp_assert($posAdvanceSet !== false, '主循环未按 batchSavedEnd 推进 currentStart（currentStart = batchSavedEnd + 1）');
obp_assert($posComplete !== false,   "主循环未找到 result==='complete' 分支");

// 3) 重置发生在 runChunk 之前
obp_assert($posReset < $posRunChunk,
    'batchSavedEnd 的重置应在每次 runChunk 调用之前（否则会沿用上一段进度误推进）');

// 4) 权威推进的判断在 runChunk 之后、且**优先于** result==='complete' 分支
obp_assert($posRunChunk < $posAdvanceIf,
    '按 batchSavedEnd 推进的判断应在 runChunk 返回之后');
obp_assert($posAdvanceIf < $posComplete,
    '按 batchSavedEnd 推进必须优先于 result===\'complete\' 分支（已落库即推进，不依赖 [DONE]/二次查询）');

// currentStart = batchSavedEnd + 1 应落在「权威推进」块内（advanceIf 与 complete 分支之间）
obp_assert($posAdvanceSet > $posAdvanceIf && $posAdvanceSet < $posComplete,
    'currentStart = batchSavedEnd + 1 应位于 if (batchSavedEnd >= currentStart) 推进块内');

echo "outline_batch_progress_static_test passed (生成章节细纲按 batch_done/actual_end 权威推进，消除已落库仍重复生成的死循环)\n";
