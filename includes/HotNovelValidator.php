<?php
defined('APP_LOADED') or die('Direct access denied.');

/**
 * 热门选题校验器
 * 实现 K-v5.md §3.5 / §3.5.1 / §13 的字段约束、题材白名单、男女频归一化
 */
class HotNovelValidator
{
    public const VALID_SOURCES = ['qidian', 'fanqie', 'zongheng', 'qimao'];

    /** 男频题材关键词（K-v5.md §13） */
    public const MALE_CATEGORIES = [
        '玄幻', '奇幻', '武侠', '仙侠', '都市', '历史', '军事', '科幻', '游戏', '体育', '悬疑灵异',
    ];
    public const FEMALE_CATEGORIES = [
        '现代言情', '古代言情', '幻想言情', '仙侠奇缘',
    ];
    public const GENERAL_CATEGORIES = [
        '同人', '短篇',
    ];

    /**
     * 校验书名：返回 ['ok' => bool, 'reason' => string]
     * 失败时 reason = BAD_TITLE
     */
    public static function validateTitle(string $title): array
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) < 2) {
            return ['ok' => false, 'reason' => 'BAD_TITLE', 'detail' => 'too_short_or_empty'];
        }
        // 纯数字
        if (preg_match('/^\d+$/', $title)) {
            return ['ok' => false, 'reason' => 'BAD_TITLE', 'detail' => 'all_digits'];
        }
        // 替代字符 / PUA / 几何方块 / emoji / 装饰符 / HTML / 控制字符
        if (self::hasIllegalChar($title)) {
            return ['ok' => false, 'reason' => 'BAD_TITLE', 'detail' => 'illegal_char'];
        }
        // 同一非汉字字符 ≥ 4 连
        if (preg_match('/([^\x{4E00}-\x{9FFF}])\1{3,}/u', $title)) {
            return ['ok' => false, 'reason' => 'BAD_TITLE', 'detail' => 'repeated_symbol'];
        }
        // 有效字符占比 < 60%（汉字 + 字母 + 数字 + 常用标点）
        $total = mb_strlen($title);
        preg_match_all('/[\x{4E00}-\x{9FFF}A-Za-z0-9·\-_:：，。、!?！？\s]/u', $title, $m);
        $valid = isset($m[0]) ? count($m[0]) : 0;
        if ($total > 0 && ($valid / $total) < 0.6) {
            return ['ok' => false, 'reason' => 'BAD_TITLE', 'detail' => 'low_valid_ratio'];
        }
        return ['ok' => true];
    }

    /** 非法字符模式（替代字符 / PUA / 几何方块 / emoji / 装饰符 / HTML / 控制字符）。书名与作者共用。 */
    private static function badCharPatterns(): array
    {
        return [
            '/\x{FFFD}/u',                          // U+FFFD 替代字符
            '/[\x{E000}-\x{F8FF}]/u',               // 私用区
            '/[\x{F0000}-\x{10FFFD}]/u',            // 增补私用区
            '/[\x{25A0}-\x{25FF}]/u',               // 几何方块
            '/[\x{1F300}-\x{1FAFF}]/u',             // emoji
            '/[\x{2600}-\x{27BF}]/u',               // 杂项符号
            '/[★☆♥♡◆◇▲△▼▽●○◎※✓✗❤]/u',           // 装饰符
            '/[<>&]/',                              // HTML 字符
            '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}]/u',  // 控制字符（保留 \t\n\r）
        ];
    }

    private static function hasIllegalChar(string $s): bool
    {
        foreach (self::badCharPatterns() as $p) {
            if (preg_match($p, $s)) return true;
        }
        return false;
    }

    /**
     * 校验作者名：返回 ['ok' => bool, 'reason' => 'BAD_AUTHOR', 'detail' => string]
     * 作者非必填——空字符串视为合法（许多榜单条目无作者）；非空时与书名同口径过滤垃圾值。
     */
    public static function validateAuthor(string $author): array
    {
        $author = trim($author);
        if ($author === '') {
            return ['ok' => true];
        }
        if (mb_strlen($author) > 60) {
            return ['ok' => false, 'reason' => 'BAD_AUTHOR', 'detail' => 'too_long'];
        }
        if (preg_match('/^\d+$/', $author)) {
            return ['ok' => false, 'reason' => 'BAD_AUTHOR', 'detail' => 'all_digits'];
        }
        if (self::hasIllegalChar($author)) {
            return ['ok' => false, 'reason' => 'BAD_AUTHOR', 'detail' => 'illegal_char'];
        }
        // 同一非汉字字符 ≥ 4 连（如 "----"）
        if (preg_match('/([^\x{4E00}-\x{9FFF}])\1{3,}/u', $author)) {
            return ['ok' => false, 'reason' => 'BAD_AUTHOR', 'detail' => 'repeated_symbol'];
        }
        return ['ok' => true];
    }

    /**
     * 校验题材白名单：黑名单题材返回 UNSUPPORTED_CATEGORY
     * @param string $rawCategory 平台原始题材
     * @param array  $blacklist   黑名单题材数组
     */
    public static function validateCategory(string $rawCategory, array $blacklist): array
    {
        $cat = trim($rawCategory);
        if ($cat === '') {
            return ['ok' => true]; // 空题材允许通过（后续按 unknown 处理）
        }
        foreach ($blacklist as $bad) {
            if ($bad === '') continue;
            if ($cat === $bad || mb_strpos($cat, $bad) !== false) {
                return ['ok' => false, 'reason' => 'UNSUPPORTED_CATEGORY', 'detail' => $cat];
            }
        }
        return ['ok' => true];
    }

    /**
     * 根据 raw_category 归一化频道和题材
     * @return array{channel:string, normalized_category:?string}
     */
    public static function classifyChannel(string $rawCategory): array
    {
        $cat = trim($rawCategory);
        if ($cat === '') return ['channel' => 'unknown', 'normalized_category' => null];

        foreach (self::MALE_CATEGORIES as $kw) {
            if (mb_strpos($cat, $kw) !== false) {
                return ['channel' => 'male', 'normalized_category' => $kw];
            }
        }
        foreach (self::FEMALE_CATEGORIES as $kw) {
            if (mb_strpos($cat, $kw) !== false) {
                return ['channel' => 'female', 'normalized_category' => $kw];
            }
        }
        // 单独检测「言情」「穿越」「宫斗」「甜宠」等女频特征词
        $femaleHints = ['言情', '穿越', '宫斗', '甜宠', '虐恋', '豪门', '总裁', '玛丽苏'];
        foreach ($femaleHints as $kw) {
            if (mb_strpos($cat, $kw) !== false) {
                return ['channel' => 'female', 'normalized_category' => '现代言情'];
            }
        }
        foreach (self::GENERAL_CATEGORIES as $kw) {
            if (mb_strpos($cat, $kw) !== false) {
                return ['channel' => 'general', 'normalized_category' => $kw];
            }
        }
        return ['channel' => 'unknown', 'normalized_category' => $cat];
    }

    /**
     * 归一化书名/作者（去标点/全角→半角/小写/去后缀）用于去重
     */
    public static function normalize(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';
        // 去括号类
        $s = preg_replace('/[《》""\'\'\[\]【】（）()]/u', '', $s);
        // 全角 → 半角
        $s = mb_convert_kana($s, 'as', 'UTF-8');
        // 去空白
        $s = preg_replace('/\s+/', '', $s);
        // 小写
        $s = mb_strtolower($s, 'UTF-8');
        // 去常见后缀
        $suffixes = ['小说', '免费阅读', '最新章节', '全文阅读', '最新更新', '完结', '连载中'];
        foreach ($suffixes as $sfx) {
            $s = preg_replace('/' . preg_quote($sfx, '/') . '$/u', '', $s);
        }
        return $s;
    }
}
