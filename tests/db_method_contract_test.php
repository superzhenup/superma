<?php
/**
 * DB 方法契约 + 旧库升级 SQL 静态回归测试。
 *
 * 背景（2026-05-31 系统化审计 P1 / 建议 五.3）：
 *   - includes/author/AuthorProfile.php 曾调用不存在的 DB::exec()，异常被空 catch
 *     吞掉，使用次数永不入库；includes/stats_tracker.php 曾调用不存在的
 *     DB::affecting()，异常后返回 0，使清理任务谎报"未清理数据"。
 *   - api/author_profile.php 的旧库升级 ALTER 中，前三个 COMMENT 字符串缺少
 *     闭合引号，AFTER 被吞入注释，老库升级路径必然抛 SQL 语法错误。
 *
 * 本测试纯静态、无需数据库：
 *   1. 扫描全部第一方 PHP，确保每个 DB::<method>(...) 调用都对应 includes/db.php
 *      中真实定义的方法，防止"调用不存在的包装器方法"复发。
 *   2. 守护 author_profiles 升级 SQL：杜绝 COMMENT 缺少闭合引号的格式错误复发，
 *      并确认四个提示词列的迁移逻辑仍在。
 *
 * 注意：本测试自身位于 tests/ 目录，扫描时被排除，因此可以安全地在注释/字符串中
 *       提及历史上的错误方法名（如 exec / affecting）而不触发自我误报。
 */

$root = dirname(__DIR__);

function dbtest_fail(string $msg): void { throw new RuntimeException($msg); }
function dbtest_assert(bool $cond, string $msg): void { if (!$cond) dbtest_fail($msg); }

// ---------------------------------------------------------------------------
// 1. 收集 DB 类中定义的方法名（静态解析，不加载/连接数据库）
// ---------------------------------------------------------------------------
$dbSource = @file_get_contents($root . '/includes/db.php');
dbtest_assert($dbSource !== false, 'Cannot read includes/db.php');

preg_match_all('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $dbSource, $defMatches);
$defined = [];
foreach ($defMatches[1] as $name) {
    $defined[strtolower($name)] = true;
}
dbtest_assert(isset($defined['execute']), 'Sanity check failed: DB::execute must be defined in includes/db.php');
dbtest_assert(isset($defined['query']),   'Sanity check failed: DB::query must be defined in includes/db.php');

// ---------------------------------------------------------------------------
// 2. 扫描第一方 PHP，校验所有 DB::<method>( 调用都解析到真实方法
// ---------------------------------------------------------------------------
$skipDirs = [
    'assets' . DIRECTORY_SEPARATOR . 'dist',
    'tests',
    '.git',
    'storage',
    'docs',
    'node_modules',
    'vendor',
];

$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$violations = [];
$scanned = 0;
foreach ($rii as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $rel = ltrim(str_replace($root, '', $file->getPathname()), DIRECTORY_SEPARATOR);
    foreach ($skipDirs as $skip) {
        if (strpos($rel, $skip . DIRECTORY_SEPARATOR) === 0) {
            continue 2;
        }
    }

    $src = @file_get_contents($file->getPathname());
    if ($src === false) {
        continue;
    }
    $scanned++;

    if (preg_match_all('/\\\\?DB::([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $src, $calls, PREG_OFFSET_CAPTURE)) {
        foreach ($calls[1] as $call) {
            $name = strtolower($call[0]);
            if (!isset($defined[$name])) {
                $line = substr_count(substr($src, 0, $call[1]), "\n") + 1;
                $violations[] = "{$rel}:{$line} → DB::{$call[0]}() is not defined on the DB class";
            }
        }
    }
}

dbtest_assert($scanned > 0, 'No PHP files were scanned — iterator/path misconfiguration');
dbtest_assert(
    empty($violations),
    "Undefined DB:: wrapper method call(s) detected:\n  " . implode("\n  ", $violations)
);

// ---------------------------------------------------------------------------
// 3. 守护 author_profiles 旧库升级 SQL 不再出现缺失闭合引号的格式错误
// ---------------------------------------------------------------------------
$authorApi = @file_get_contents($root . '/api/author_profile.php');
dbtest_assert($authorApi !== false, 'Cannot read api/author_profile.php');

// 历史缺陷的指纹：COMMENT 文本后直接跟 AFTER（中间没有闭合引号）。
// 正确写法是 COMMENT '...' AFTER `col`。下列三段是当年损坏的具体形态。
$brokenFingerprints = [
    "COMMENT '写作习惯提示词 AFTER",
    "COMMENT '叙事手法提示词 AFTER",
    "COMMENT '思想情感提示词 AFTER",
];
foreach ($brokenFingerprints as $bad) {
    dbtest_assert(
        strpos($authorApi, $bad) === false,
        "Malformed migration SQL regression: found unterminated COMMENT before AFTER → \"{$bad}\""
    );
}

// 迁移逻辑本身仍需存在：四个提示词列名都应在文件中出现。
foreach (['writing_habits_prompt', 'narrative_style_prompt', 'sentiment_prompt', 'creative_identity_prompt'] as $col) {
    dbtest_assert(
        strpos($authorApi, $col) !== false,
        "Prompt-column migration appears to be missing column: {$col}"
    );
}

echo "db_method_contract_test passed (scanned {$scanned} PHP files)\n";
