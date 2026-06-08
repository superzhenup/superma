<?php
/**
 * 热门选题数据推送接口（K-v5.md §9 协议）
 *
 * 入口：POST /api/hot_novels_ingest.php   （采集端 OpenClaw 按平台分批推送）
 *
 * ── 请求头 / 鉴权（三者缺一不可）──────────────────────────────
 *   X-Ingest-Key : 推送密钥；hash_equals 比对 system_settings.hot_novels_ingest_key
 *   X-Timestamp  : unix 秒；与服务器时间差须 ≤ 300s（防重放时间窗）
 *   X-Nonce      : 一次性随机串 [A-Za-z0-9_-]{1,64}；写入 hot_novel_nonces 去重（防重放）
 *
 * ── 请求体 JSON（顶层字段）────────────────────────────────────
 *   batch_id     string  批次号 ^[A-Za-z0-9_-]{1,40}$（每平台一个独立批次，勿合并）
 *   source       string  平台，必须 ∈ qidian|fanqie|zongheng|qimao
 *   pushed_by    string? 推送方标识（如 openclaw-node-1），仅记账用
 *   fetch_failed bool    本批抓取整体失败标记；为真或 items 为空时只记空批次台账
 *   summary      object  批次趋势摘要 {trend, top_keywords[], category_dist{}...}，整体存批次台账
 *   items[]      array   单本小说数组，单批 ≤ 500；逐条字段见下
 *
 * ── items[] 单条字段（最终写入 hot_novels 表）──────────────────
 *   title*            书名（必填；受书名字符硬约束，违反→BAD_TITLE）
 *   author            作者（可空；非空时受作者字符约束，违反→BAD_AUTHOR）
 *   raw_category      平台原始题材（黑名单→UNSUPPORTED_CATEGORY；归一化出 channel/normalized_category）
 *   tags[]            标签数组（JSON 存）
 *   word_count / click_count / collect_count / recommend_count   计数，缺失存 null
 *   hotness_score     热度分 0-100
 *   rank_no / rank_type / extra_rank_types[]   榜单位次 / 榜单名 / 兼任的其它榜单
 *   intro / cover_url / source_url             简介 / 封面 URL / 官方详情页 URL
 *   collected_at      采集时间（缺省取当前时间）
 *   confidence_score  可信度 0-100（< 阈值→LOW_CONFIDENCE 丢弃；⚠ 字段名不是 final_score）
 *   analysis{}        爆款分析（另写入 hot_novel_analysis 表，见下）
 *
 * ── analysis{} 子对象（写入 hot_novel_analysis 表；key 必须英文）─
 *   appeals / selling_points / tropes / audience / risks / suggestions / hooks   文本字段
 *   benchmarks[]      对标作品数组（JSON 存）
 *   _evidence{}       证据链（存为 evidence 列）
 *
 * ── 响应（HTTP 恒 200，业务状态在 body）──────────────────────
 *   { success, batch_id, accepted, updated, duplicated, failed[], batch_warnings[], analysis_cleared }
 *     accepted    新书入库数            updated   同名书内容有变、更新数
 *     duplicated  同批重复 + 内容未变跳过数（v5.2 内容签名去重）
 *     failed[]    单条失败明细 [{index, title, reason, detail}]
 *
 * 落库去向：hot_novels（书）/ hot_novel_analysis（分析）/ hot_novel_batches（批次台账）/ hot_novel_nonces（防重放）
 *
 * 失败码:
 *   INVALID_KEY / TIMESTAMP_SKEW / NONCE_REPLAY / VALIDATION_ERROR
 *   单条: BAD_TITLE / BAD_AUTHOR / UNSUPPORTED_CATEGORY / LOW_CONFIDENCE / DUPLICATED
 *   批次: BATCH_LOW_DIVERSITY（仅警告，v5.1 起不再清空 analysis）
 *
 * v5.1 (2026-05-30): 移除 clean_analysis 阻止 analysis 写入；BATCH_LOW_DIVERSITY 仅警告；analysis_cleared 恒 false
 * v5.2 (2026-06-01): 新增 BAD_AUTHOR 作者校验；内容签名去重（同名书内容未变→duplicated 跳过写库）
 */
define('APP_LOADED', true);

while (ob_get_level()) ob_end_clean();
ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/HotNovelService.php';
require_once dirname(__DIR__) . '/includes/HotNovelQuery.php';

/** 统一出口：把 $payload 编码为 JSON 输出并结束请求（成功与各类失败都经此返回）。 */
function respond(array $payload, int $http = 200): void
{
    http_response_code($http);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
}

if (!HotNovelService::isIngestEnabled()) {
    respond(['success' => false, 'error' => 'INGEST_DISABLED'], 503);
}

// ---- 鉴权头解析：X-Ingest-Key(密钥) / X-Timestamp(防重放时间窗) / X-Nonce(一次性串) ----
$ingestKey = '';
$timestamp = '';
$nonce     = '';
$headers   = function_exists('getallheaders') ? getallheaders() : [];
foreach ($headers as $k => $v) {
    $kl = strtolower($k);
    if ($kl === 'x-ingest-key')  $ingestKey = trim($v);
    if ($kl === 'x-timestamp')   $timestamp = trim($v);
    if ($kl === 'x-nonce')       $nonce     = trim($v);
}
if ($ingestKey === '' && isset($_SERVER['HTTP_X_INGEST_KEY'])) $ingestKey = $_SERVER['HTTP_X_INGEST_KEY'];
if ($timestamp === '' && isset($_SERVER['HTTP_X_TIMESTAMP'])) $timestamp = $_SERVER['HTTP_X_TIMESTAMP'];
if ($nonce === '' && isset($_SERVER['HTTP_X_NONCE']))         $nonce     = $_SERVER['HTTP_X_NONCE'];

$serverKey = HotNovelService::getIngestKey();
if ($serverKey === '' || $ingestKey === '' || !hash_equals($serverKey, $ingestKey)) {
    respond(['success' => false, 'error' => 'INVALID_KEY'], 401);
}

if ($timestamp === '' || !is_numeric($timestamp) || abs(time() - (int)$timestamp) > 300) {
    respond(['success' => false, 'error' => 'TIMESTAMP_SKEW'], 400);
}

if ($nonce === '' || strlen($nonce) > 64 || !preg_match('/^[a-zA-Z0-9_\-]+$/', $nonce)) {
    respond(['success' => false, 'error' => 'VALIDATION_ERROR', 'detail' => 'bad_nonce'], 400);
}

$service = new HotNovelService();
$service->cleanupNonces(); // 清理 hot_novel_nonces 中过期(>24h)的 nonce

// 写入本次 nonce 到 hot_novel_nonces（唯一键）；插入冲突 = 该 nonce 用过 = 重放 → NONCE_REPLAY
try {
    DB::execute('INSERT INTO hot_novel_nonces (nonce) VALUES (?)', [$nonce]);
} catch (\Throwable $e) {
    if (stripos($e->getMessage(), 'Duplicate') !== false || stripos($e->getMessage(), '1062') !== false) {
        respond(['success' => false, 'error' => 'NONCE_REPLAY'], 409);
    }
    respond(['success' => false, 'error' => 'VALIDATION_ERROR', 'detail' => 'nonce_db'], 500);
}

// ---- Body 解析 ----
$rawBody = file_get_contents('php://input');
if (strlen($rawBody) > 5 * 1024 * 1024) {
    respond(['success' => false, 'error' => 'VALIDATION_ERROR', 'detail' => 'body_too_large'], 413);
}
$body = json_decode($rawBody, true);
if (!is_array($body)) {
    respond(['success' => false, 'error' => 'VALIDATION_ERROR', 'detail' => 'invalid_json'], 400);
}

$batchId   = isset($body['batch_id']) ? (string)$body['batch_id'] : '';      // 批次号；写入每条书(last_batch_id)与批次台账
$source    = isset($body['source']) ? (string)$body['source'] : '';          // 平台；限 qidian|fanqie|zongheng|qimao
$pushedBy  = isset($body['pushed_by']) ? (string)$body['pushed_by'] : null;  // 推送方标识；仅记账
$items     = isset($body['items']) && is_array($body['items']) ? $body['items'] : [];       // 单本小说数组（核心数据，逐条入库）
$summary   = isset($body['summary']) && is_array($body['summary']) ? $body['summary'] : [];  // 批次趋势摘要；整体存批次台账
$fetchFail = !empty($body['fetch_failed']);                                  // 抓取整体失败标记；为真则只记空批次

if ($batchId === '' || !preg_match('/^[a-zA-Z0-9_\-]{1,40}$/', $batchId)) {
    respond(['success' => false, 'error' => 'VALIDATION_ERROR', 'detail' => 'bad_batch_id'], 400);
}
if (!in_array($source, HotNovelValidator::VALID_SOURCES, true)) {
    respond(['success' => false, 'error' => 'VALIDATION_ERROR', 'detail' => 'bad_source'], 400);
}
if (count($items) > 500) {
    respond(['success' => false, 'error' => 'VALIDATION_ERROR', 'detail' => 'too_many_items'], 400);
}

// ---- 空批次（抓取失败 或 items 为空）：只写 hot_novel_batches 台账（各计数全 0），不进入逐条流程 ----
if ($fetchFail || empty($items)) {
    $service->recordBatch([
        'batch_id'        => $batchId,
        'source'          => $source,
        'pushed_by'       => $pushedBy,
        'prepared_items'  => 0,
        'submitted_items' => count($items),
        'accepted'        => 0, 'updated' => 0, 'duplicated' => 0, 'failed' => 0,
        'summary'         => $summary,
        'fetch_failed'    => $fetchFail,
        'client_ip'       => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
    respond([
        'success'    => true,
        'batch_id'   => $batchId,
        'accepted'   => 0, 'updated' => 0, 'duplicated' => 0,
        'failed'     => [],
        'batch_warnings' => [],
    ]);
}

// ---- 批次级多样性体检（B1/B2）：读取各条 analysis.tropes / analysis.benchmarks，
//      计算"单一套路/单一对标"的集中度；超阈值产出 batch_warnings（仅警告，不拒收、不清空 analysis）----
$diversity = $service->computeBatchDiversity($items);
$batchWarnings = $diversity['warnings'];
// v5.1: 移除 clean_analysis 阻止 analysis 写入的逻辑
// 警告仍返回给客户端，但 analysis 数据始终写入
// $cleanAnalysis = $diversity['clean_analysis'];

// ---- 逐条校验 + 入库 ----
$blacklist    = HotNovelService::getBlacklistCategories(); // 题材黑名单（来自 system_settings）
$minConf      = HotNovelService::getMinConfidence();       // 可信度阈值（来自 system_settings）
$failed       = [];
$accepted     = 0;
$updated      = 0;
$duplicated   = 0;
$prepared     = count($items);

// 同批 dedup_key 防重
$seenInBatch = [];

foreach ($items as $idx => $item) {
    if (!is_array($item)) {
        $failed[] = ['index' => $idx, 'reason' => 'VALIDATION_ERROR', 'detail' => 'item_not_array'];
        continue;
    }
    $item['source'] = $source;

    // 1. 校验 title（书名字符硬约束 validateTitle）→ 不合格记 BAD_TITLE 并跳过本条
    $t = (string)($item['title'] ?? '');
    $check = HotNovelValidator::validateTitle($t);
    if (!$check['ok']) {
        $failed[] = ['index' => $idx, 'title' => $t, 'reason' => $check['reason'], 'detail' => $check['detail']];
        continue;
    }

    // 1b. BAD_AUTHOR（作者非必填；非空时与书名同口径过滤纯数字/非法字符等垃圾值）
    $authorCheck = HotNovelValidator::validateAuthor((string)($item['author'] ?? ''));
    if (!$authorCheck['ok']) {
        $failed[] = ['index' => $idx, 'title' => $t, 'reason' => $authorCheck['reason'], 'detail' => $authorCheck['detail']];
        continue;
    }

    // 2. 校验 raw_category（题材黑名单 validateCategory）→ 命中记 UNSUPPORTED_CATEGORY 并跳过本条
    $rawCat = (string)($item['raw_category'] ?? '');
    $catCheck = HotNovelValidator::validateCategory($rawCat, $blacklist);
    if (!$catCheck['ok']) {
        $failed[] = ['index' => $idx, 'title' => $t, 'reason' => $catCheck['reason'], 'detail' => $catCheck['detail']];
        continue;
    }

    // 3. 校验 confidence_score（可信度 < 阈值）→ 记 LOW_CONFIDENCE 并跳过本条（注意字段名不是 final_score）
    $conf = (int)($item['confidence_score'] ?? 0);
    if ($conf < $minConf) {
        $failed[] = ['index' => $idx, 'title' => $t, 'reason' => 'LOW_CONFIDENCE', 'detail' => "score={$conf}"];
        continue;
    }

    // 4. 同批去重：dedup_key = normalize(title)|normalize(author)；本批内重复 → 计 duplicated 并跳过
    $author     = (string)($item['author'] ?? '');
    $titleNorm  = HotNovelValidator::normalize($t);
    $authorNorm = HotNovelValidator::normalize($author);
    $dedupKey   = $titleNorm . '|' . $authorNorm;
    if (isset($seenInBatch[$dedupKey])) {
        $duplicated++;
        continue;
    }
    $seenInBatch[$dedupKey] = true;

    // 5. UPSERT 书数据 → hot_novels 表
    //    输入：整条 $item（title/author/raw_category/tags/各计数/hotness/rank*/intro/cover_url/source_url/
    //          collected_at/confidence_score + source），内部归一化出 title_norm/author_norm/channel/normalized_category。
    //    按 (title_norm, author_norm) 命中既有：内容有变→updated；内容未变→duplicated(跳过写库)；否则→accepted。
    //    返回：['status' => accepted|updated|duplicated, 'novel_id' => int]
    try {
        $result = $service->upsertItem($item, $batchId);
        if ($result['status'] === 'accepted') $accepted++;
        elseif ($result['status'] === 'duplicated') $duplicated++; // 内容未变，跳过写库
        else $updated++;

        // 6. 写分析数据 → hot_novel_analysis 表（按 novel_id；空字段不覆盖既有；v5.1 起始终写入）
        //    输入：$item['analysis'] = {appeals, selling_points, tropes, audience, risks, suggestions, hooks,
        //          benchmarks[], _evidence{}}（key 必须英文，中文 key 会被 PHP 解析为 "Array"）
        if (!empty($item['analysis']) && is_array($item['analysis'])) {
            $service->upsertAnalysis($result['novel_id'], $item['analysis']);
        }
    } catch (\Throwable $e) {
        $failed[] = ['index' => $idx, 'title' => $t, 'reason' => 'VALIDATION_ERROR', 'detail' => $e->getMessage()];
    }
}

// ---- 失败原因统计 ----
$failedReasons = [];
foreach ($failed as $f) {
    $r = $f['reason'] ?? 'UNKNOWN';
    $failedReasons[$r] = ($failedReasons[$r] ?? 0) + 1;
}
// v5.1: BATCH_LOW_DIVERSITY 不再计入 failed_reasons（仅警告，不拒收）
// if ($cleanAnalysis) {
//     $failedReasons['BATCH_LOW_DIVERSITY'] = ($failedReasons['BATCH_LOW_DIVERSITY'] ?? 0) + 1;
// }

// ---- 汇总写入批次台账 hot_novel_batches：各计数 + failed_reasons + summary + diversity_metrics + client_ip ----
$service->recordBatch([
    'batch_id'          => $batchId,
    'source'            => $source,
    'pushed_by'         => $pushedBy,
    'prepared_items'    => $prepared,
    'submitted_items'   => $prepared,
    'accepted'          => $accepted,
    'updated'           => $updated,
    'duplicated'        => $duplicated,
    'failed'            => count($failed),
    'failed_reasons'    => $failedReasons,
    'summary'           => $summary,
    'diversity_metrics' => $diversity,
    'fetch_failed'      => false,
    'client_ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
]);

// ---- 返回结果给采集端（供其 platform_submit_report 统计）：accepted/updated/duplicated 计数 + failed 明细 + 批次警告 ----
respond([
    'success'         => true,
    'batch_id'        => $batchId,
    'accepted'        => $accepted,
    'updated'         => $updated,
    'duplicated'      => $duplicated,
    'failed'          => $failed,
    'batch_warnings'  => $batchWarnings,
    'analysis_cleared' => false,  // v5.1: 不再清空 analysis
]);

