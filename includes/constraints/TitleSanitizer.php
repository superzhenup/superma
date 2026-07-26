<?php
/**
 * TitleSanitizer — 章节标题禁用词「落盘前自动改写」器
 *
 * 背景（根治死锁）：
 *   旧逻辑在「标题命中禁用词」且「全书正文词频 ≥ cf_max_banned_word_usage」时判 P0，
 *   严格模式下 saveChapter() 抛 WriteEngineValidationException 阻止落盘 → 整本书暂停挂机。
 *   而标题来自大纲、重试时不会变，恢复后会撞同一堵墙，形成死锁（「真相」已用18/15即此现象）。
 *
 * 本类在落盘前对标题做「确定性自动改写」：
 *   1. 默认套路词 → 同义替换（按章节号轮换候选，避免改写后又出现新的重复）；
 *   2. 自定义禁用词（无同义候选）→ 直接去词 + 收尾清理；
 *   3. 兜底：若改写后标题退化（空/过短/仍含禁用词），放弃改写保留原标题，
 *      交由 PostWriteValidator 记 P1 提示（不阻断落盘）。
 *
 * 纯字符串处理：无 AI 调用、无 DB 依赖（禁用词表可注入，便于单测）。
 *
 * @package ConstraintFramework
 */

defined('APP_LOADED') or die('Direct access denied.');

class TitleSanitizer
{
    /**
     * 默认套路词 → 同义替换候选（网文语境，保持语气，均为非禁用词）。
     * 多候选用于按章节号轮换，避免大量标题被替换成同一个词、形成新的重复。
     * @var array<string, string[]>
     */
    private const SYNONYMS = [
        '真相' => ['内情', '隐情', '底细', '端倪'],
        '绝境' => ['险境', '死局', '困局', '危局'],
        '反杀' => ['反制', '逆转', '反击', '翻盘'],
        '背水' => ['殊死', '决死', '死战', '孤注'],
        '逆袭' => ['翻身', '崛起', '逆转', '翻盘'],
    ];

    /**
     * 清洗标题中的禁用词。
     *
     * @param string        $title         原标题
     * @param int           $chapterNumber 章节号（用于同义词轮换，保证确定性）
     * @param string[]|null $bannedWords   禁用词表；为 null 时读 ConstraintConfig::bannedWords()
     * @return array{title:string, changed:bool, hit_words:string[]}
     */
    public static function sanitize(string $title, int $chapterNumber, ?array $bannedWords = null): array
    {
        $original = $title;

        if ($bannedWords === null) {
            require_once __DIR__ . '/ConstraintConfig.php';
            $bannedWords = ConstraintConfig::bannedWords();
        }
        $bannedWords = array_values(array_filter(
            array_map('trim', $bannedWords),
            static fn($w) => $w !== ''
        ));

        if ($title === '' || empty($bannedWords)) {
            return ['title' => $original, 'changed' => false, 'hit_words' => []];
        }

        $hit    = [];
        $result = $title;
        $seed   = abs($chapterNumber);

        foreach ($bannedWords as $word) {
            if (mb_strpos($result, $word) === false) {
                continue;
            }
            $hit[] = $word;

            if (!empty(self::SYNONYMS[$word])) {
                $candidates  = self::SYNONYMS[$word];
                $replacement = $candidates[$seed % count($candidates)];
                $result      = str_replace($word, $replacement, $result);
            } else {
                // 自定义禁用词无同义候选：直接去词，留待 tidy() 收尾
                $result = str_replace($word, '', $result);
            }
        }

        if (empty($hit)) {
            return ['title' => $original, 'changed' => false, 'hit_words' => []];
        }

        $result = self::tidy($result);

        // 兜底：改写后标题退化（空/过短/仍含禁用词/未变化）则放弃，保留原标题。
        // 此时 PostWriteValidator 会记 P1 提示，但严格模式不再因标题阻断落盘。
        if (mb_strlen($result) < 2 || self::stillBanned($result, $bannedWords) || $result === $original) {
            return ['title' => $original, 'changed' => false, 'hit_words' => $hit];
        }

        return ['title' => $result, 'changed' => true, 'hit_words' => $hit];
    }

    /** 改写后是否仍残留任一禁用词 */
    private static function stillBanned(string $title, array $bannedWords): bool
    {
        foreach ($bannedWords as $word) {
            if ($word !== '' && mb_strpos($title, $word) !== false) {
                return true;
            }
        }
        return false;
    }

    /** 收尾清理：折叠空白 + 去除首尾悬挂的连接符/标点（去词后可能残留） */
    private static function tidy(string $title): string
    {
        $title = preg_replace('/\s+/u', ' ', $title);
        $edge  = " \t\r\n·、，,。．.：:；;—－-_~～「」『』《》〈〉()（）[]【】";
        return self::edgeTrim($title, $edge);
    }

    /** 多字节安全的首尾字符裁剪（trim() 按字节会切坏中文，故自实现） */
    private static function edgeTrim(string $s, string $chars): string
    {
        $set = array_flip(preg_split('//u', $chars, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $arr = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n   = count($arr);
        $start = 0;
        $end   = $n - 1;
        while ($start <= $end && isset($set[$arr[$start]])) $start++;
        while ($end >= $start && isset($set[$arr[$end]]))   $end--;
        if ($start > $end) return '';
        return implode('', array_slice($arr, $start, $end - $start + 1));
    }
}
