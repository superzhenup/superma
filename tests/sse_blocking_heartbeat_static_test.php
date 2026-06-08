<?php
/**
 * SSE 阻塞式 AI 调用心跳保活 静态回归测试
 *
 * 背景（用户报告）：点「生成章节细纲」第一批 5 章流式输出完成后，前端报
 *   「连接中断（第 1/10 次），正在恢复...」，控制台 `ERR_INCOMPLETE_CHUNKED_ENCODING`，
 *   且章节似乎未正常保存。
 *
 * 根因：5 章流式输出（chatStream，有心跳）结束后，进入**静默**的后处理阶段——
 *   质量返修 `repairOutlineBatchWithGuard`、转折点 `enforceTurningPoints`、弧段摘要
 *   `generateAndSaveArcSummary`，它们走**阻塞式** `AIClient::chat()` → `doRequest()`，
 *   而 `doRequest()` 原先**没有** `CURLOPT_PROGRESSFUNCTION` 心跳（仅 `chatStream` 有）。
 *   这些 AI 调用发生在「保存之前」，整段静默一旦超过 nginx `fastcgi_read_timeout`（默认 60s），
 *   分块响应被中途掐断（ERR_INCOMPLETE_CHUNKED_ENCODING）→ 章节未落库 → 前端走「连接中断」恢复。
 *   雪上加霜：`generate_outline.php` 在流式结束后**立刻** `unset($GLOBALS['sendHeartbeat'])`，
 *   令后处理阶段即便有进度回调也拿不到心跳。
 *
 * 守护目标：
 *   1) includes/ai.php 的 doRequest() 注册了 CURLOPT_PROGRESSFUNCTION + NOPROGRESS=false，
 *      且回调里通过 heartbeatCallback / $GLOBALS['sendHeartbeat'] 发心跳；
 *   2) api/generate_outline.php 不再在「流式结束/后处理之前」注销 sendHeartbeat——
 *      唯一的 unset 必须位于 batch_done 之后（即后处理全部完成后）才执行。
 */

function sbh_assert(bool $cond, string $msg): void
{
    if (!$cond) {
        echo "sse_blocking_heartbeat_static_test FAILED: {$msg}\n";
        exit(1);
    }
}

$ai = @file_get_contents(dirname(__DIR__) . '/includes/ai.php');
sbh_assert($ai !== false, '无法读取 includes/ai.php');

$go = @file_get_contents(dirname(__DIR__) . '/api/generate_outline.php');
sbh_assert($go !== false, '无法读取 api/generate_outline.php');

// ---- 1) doRequest() 阻塞式请求带心跳进度回调 ----
$drStart = strpos($ai, 'private function doRequest');
sbh_assert($drStart !== false, 'ai.php 未找到 doRequest()');

// doRequest 体范围：到下一个方法/类结束。用 throw RuntimeException("CURL Error 作为收尾锚点。
$drEnd = strpos($ai, 'throw new RuntimeException("CURL Error', $drStart);
sbh_assert($drEnd !== false && $drEnd > $drStart, '未定位 doRequest() 函数体结尾锚点');
$drBody = substr($ai, $drStart, $drEnd - $drStart);

sbh_assert(strpos($drBody, 'CURLOPT_NOPROGRESS') !== false,
    'doRequest() 未设置 CURLOPT_NOPROGRESS（无法启用进度心跳回调）');
sbh_assert(strpos($drBody, 'CURLOPT_PROGRESSFUNCTION') !== false,
    'doRequest() 未设置 CURLOPT_PROGRESSFUNCTION（阻塞等待期间无心跳，SSE 会被 nginx 读超时掐断）');
sbh_assert(
    strpos($drBody, 'heartbeatCallback') !== false ||
    strpos($drBody, "\$GLOBALS['sendHeartbeat']") !== false,
    'doRequest() 进度回调未通过 heartbeatCallback / $GLOBALS[\'sendHeartbeat\'] 发送心跳');

// ---- 2) generate_outline.php 后处理期间保留心跳，unset 移到 batch_done 之后 ----
$posBatchDone = strpos($go, "sse('batch_done'");
sbh_assert($posBatchDone !== false, 'generate_outline.php 未找到 batch_done 事件');

$posUnset = strpos($go, "unset(\$GLOBALS['sendHeartbeat'])");
sbh_assert($posUnset !== false, 'generate_outline.php 未找到 sendHeartbeat 的注销（应保留一次，位于收尾处）');

sbh_assert($posUnset > $posBatchDone,
    'sendHeartbeat 的 unset 必须在 batch_done 之后（否则质量返修/转折点/弧段摘要等阻塞 AI 调用期间拿不到心跳，SSE 会被掐断）');

// 不应存在「流式结束后、batch_done 之前」就注销心跳的旧写法：
// 即第一处 unset 不能早于 batch_done。
$firstUnset = strpos($go, "unset(\$GLOBALS['sendHeartbeat'])");
sbh_assert($firstUnset > $posBatchDone,
    '检测到在 batch_done 之前注销 sendHeartbeat 的旧写法，后处理阶段会失去心跳');

echo "sse_blocking_heartbeat_static_test passed (阻塞式 chat() 后处理阶段保留 SSE 心跳，杜绝 ERR_INCOMPLETE_CHUNKED_ENCODING)\n";
