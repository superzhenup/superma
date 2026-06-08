<?php
/**
 * Tests for the title banned-word deadlock root-fix.
 *
 * Functional: TitleSanitizer is pure (DB-free when banned words are injected),
 * so we exercise its real behavior. Static: assert the wiring in write_engine.php
 * and PostWriteValidator.php that prevents the strict-mode "title P0 → paused
 * book → resume hits the same wall" deadlock from ever recurring.
 */

$root = dirname(__DIR__);

function read_source(string $rel): string
{
    global $root;
    $p = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($p)) {
        throw new RuntimeException("Missing source file: {$rel}");
    }
    return file_get_contents($p);
}
function assert_true(bool $c, string $m): void
{
    if (!$c) throw new RuntimeException($m);
}
function assert_contains(string $n, string $h, string $m): void
{
    assert_true(strpos($h, $n) !== false, $m);
}
function assert_not_contains(string $n, string $h, string $m): void
{
    assert_true(strpos($h, $n) === false, $m);
}

if (!defined('APP_LOADED')) {
    define('APP_LOADED', true);
}
require_once $root . '/includes/constraints/TitleSanitizer.php';

$banned = ['绝境', '反杀', '真相', '背水', '逆袭'];

// 1) A known banned word in the title is replaced with a non-banned synonym.
$r = TitleSanitizer::sanitize('惊天真相', 1, $banned);
assert_true($r['changed'] === true, 'Known banned word must trigger an auto-fix.');
assert_not_contains('真相', $r['title'], 'Sanitized title must not contain the banned word.');
assert_true(mb_strpos($r['title'], '惊天') === 0, 'Sanitizer must preserve the non-banned part of the title.');
assert_true(in_array('真相', $r['hit_words'], true), 'hit_words must report the matched banned word.');

// 2) Determinism — same input (incl. chapter number) yields the same output.
$r2 = TitleSanitizer::sanitize('惊天真相', 1, $banned);
assert_true($r['title'] === $r2['title'], 'Sanitizer must be deterministic for a given chapter number.');

// 3) Multiple banned words in one title are all removed.
$r3 = TitleSanitizer::sanitize('绝境逆袭', 5, $banned);
assert_true($r3['changed'] === true, 'A multi-hit title must be fixed.');
foreach ($banned as $w) {
    assert_not_contains($w, $r3['title'], "Sanitized title must drop every banned word ({$w}).");
}

// 4) A clean title is returned verbatim.
$r4 = TitleSanitizer::sanitize('风起长安', 3, $banned);
assert_true($r4['changed'] === false, 'A clean title must not be modified.');
assert_true($r4['title'] === '风起长安', 'A clean title must be returned verbatim.');

// 5) Degenerate result (whole title is an unfixable custom banned word) → keep
//    the original instead of persisting garbage; the chapter still saves (P1 only).
$r5 = TitleSanitizer::sanitize('黑幕', 2, ['黑幕']);
assert_true($r5['changed'] === false, 'A title that would collapse to empty must not be auto-changed.');
assert_true($r5['title'] === '黑幕', 'A degenerate fix must fall back to the original title.');

// 6) A strippable custom banned word leaves a tidy remainder.
$r6 = TitleSanitizer::sanitize('黑幕风云', 1, ['黑幕']);
assert_true($r6['changed'] === true, 'A strippable custom banned word must be auto-fixed.');
assert_true($r6['title'] === '风云', 'Stripping a prefix banned word must leave a tidy remainder.');

// ---- Static wiring: the strict-mode title deadlock cannot recur ----
$engine    = read_source('includes/write_engine.php');
$validator = read_source('includes/constraints/PostWriteValidator.php');

assert_contains('TitleSanitizer::sanitize', $engine, 'saveChapter must auto-sanitize the title.');
assert_true(
    strpos($engine, 'TitleSanitizer::sanitize') < strpos($engine, 'new PostWriteValidator'),
    'Title must be sanitized BEFORE post-write validation so the validator sees a clean title.'
);
assert_contains("\$updates['title']", $engine, 'A rewritten title must be persisted with the chapter (else resume hits the same wall).');

// A title banned-word must never be a P0 — P0 is exactly what strict mode blocks on.
assert_not_contains('banned_word_exceeded', $validator, 'Title banned-word must no longer escalate to a blocking P0.');
assert_not_contains('getBannedWordUsage', $validator, 'Title severity must not depend on body word-frequency anymore.');

echo "title_sanitizer_static_test passed\n";
