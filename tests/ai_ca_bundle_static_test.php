<?php
/**
 * AI HTTPS CA 证书包静态回归测试
 *
 * 背景（用户报告，Windows 宝塔）：
 *   ai.php 调 AI 用 CURLOPT_SSL_VERIFYPEER=true 但不指定 CURLOPT_CAINFO，靠 php.ini 的 curl.cainfo。
 *   网页(FastCGI) php.ini 配了 cainfo → SSE 正常；但异步 worker 走 CLI php.exe，CLI php.ini 常没配
 *   → worker 调 AI(HTTPS) 证书验证失败 → chatStream 抛错 → 无限重试、content=0 → 章节写不出/卡死。
 *
 * 守护目标：
 *   1) 仓库自带 CA 包 includes/cacert.pem 存在且是有效 PEM（兜底，保证任何环境都有可用 CA）。
 *   2) AIClient 定义了 caBundle()，且两处 curl 调用都用 CURLOPT_CAINFO 接入 caBundle()。
 *   3) EmbeddingProvider 的 curl 也补了 CURLOPT_CAINFO。
 */

$root = dirname(__DIR__);
function acb_assert(bool $cond, string $msg): void
{
    if (!$cond) { echo "ai_ca_bundle_static_test FAILED: {$msg}\n"; exit(1); }
}
function acb_read(string $rel): string
{
    $abs = dirname(__DIR__) . '/' . $rel;
    acb_assert(is_file($abs), "缺少文件 {$rel}");
    return (string)file_get_contents($abs);
}

// 1) 自带 CA 包存在且有效
$ca = acb_read('includes/cacert.pem');
$certCount = substr_count($ca, 'BEGIN CERTIFICATE');
acb_assert($certCount >= 50, "includes/cacert.pem 证书数异常（{$certCount}），疑似损坏或未上传");

// 2) AIClient::caBundle() 定义 + 两处 curl 接入 CURLOPT_CAINFO
$ai = acb_read('includes/ai.php');
acb_assert(preg_match('/function\s+caBundle\s*\(/', $ai) === 1,
    'ai.php 未定义 caBundle()');
acb_assert(strpos($ai, 'CURLOPT_SSL_VERIFYPEER') !== false,
    'ai.php 预期仍开启 CURLOPT_SSL_VERIFYPEER（安全基线）');
$cainfoHits = substr_count($ai, 'CURLOPT_CAINFO');
acb_assert($cainfoHits >= 2,
    "ai.php 的 CURLOPT_CAINFO 接入点不足（{$cainfoHits}，应 >=2：流式 + 同步）");
acb_assert(substr_count($ai, 'self::caBundle()') >= 2,
    'ai.php 两处 curl 都应调用 self::caBundle()');

// 3) EmbeddingProvider 也补 CURLOPT_CAINFO
$emb = acb_read('includes/memory/EmbeddingProvider.php');
acb_assert(strpos($emb, 'CURLOPT_CAINFO') !== false && strpos($emb, 'caBundle()') !== false,
    'EmbeddingProvider.php 未补 CURLOPT_CAINFO / caBundle()');

echo "ai_ca_bundle_static_test passed (cacert.pem {$certCount} 证书；ai.php/EmbeddingProvider 均接入 CURLOPT_CAINFO)\n";
