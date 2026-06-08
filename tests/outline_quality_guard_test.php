<?php
define('APP_LOADED', true);

require_once dirname(__DIR__) . '/includes/OutlineQualityGuard.php';

function guard_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$guard = new OutlineQualityGuard();

$history = [
    [
        'chapter_number' => 10,
        'title' => '暗巷追踪',
        'summary' => '主角在暗巷发现神秘符号，被黑衣人追杀后反击，众人震惊。',
        'key_points' => ['发现符号', '遭遇追杀', '反击成功'],
        'hook' => '黑衣人说出幕后主使的名字',
    ],
];

$batch = [
    [
        'chapter_number' => 11,
        'title' => '暗巷反击',
        'summary' => '主角在暗巷发现神秘符号，被黑衣人追杀后反击，众人震惊。',
        'key_points' => ['发现符号', '遭遇追杀', '反击成功'],
        'hook' => '幕后主使的名字浮出水面',
    ],
    [
        'chapter_number' => 12,
        'title' => '真相逼近',
        'summary' => '主角带着线索找到旧案卷宗，确认幕后主使与宗门长老有关。',
        'key_points' => ['查到旧案', '锁定长老', '准备试探'],
        'hook' => '长老主动召见主角',
    ],
];

$issues = $guard->evaluate($batch, $history);
$types = array_values(array_unique(array_column($issues, 'type')));

guard_assert(in_array('repeat', $types, true), 'Guard must detect repeated chapter logic.');
guard_assert($guard->hasBlockingIssues($issues), 'Repeated chapter logic must be blocking.');
guard_assert(str_contains($guard->formatIssuesForPrompt($issues), '第11章'), 'Issue prompt must mention the problematic chapter.');

$messages = $guard->buildRepairMessages(
    ['title' => '测试小说', 'genre' => '玄幻', 'writing_style' => '爽文'],
    $batch,
    $issues,
    ['history' => $history]
);
guard_assert(count($messages) === 2, 'Repair prompt must contain system and user messages.');
guard_assert(str_contains($messages[1]['content'], '只修复有问题的章节'), 'Repair prompt must ask for local repair.');
guard_assert(str_contains($messages[1]['content'], 'story_delta'), 'Repair prompt must preserve quality fields.');

echo "outline_quality_guard_test passed\n";
