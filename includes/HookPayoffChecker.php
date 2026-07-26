<?php
/**
 * HookPayoffChecker — 章末钩子回收验证（闭环）
 *
 * 此前钩子是"开环"的：每章埋钩子、口头叮嘱下章承接，但从不验证下章是否真的回收。
 * 本类在每章写完后，取「上一章的章末钩子」，判断「本章是否正面回应了它」：
 *   - 已回收 → chapters.hook_resolved = 1
 *   - 悬挂（下章无视了上章钩子）→ hook_resolved = 0，写 Agent 指令让后续补回收
 *   - 无法判断（上章无钩子/无可判定实体）→ 不写（保持 NULL），避免误报
 *
 * 默认用「关键实体重叠」启发式（零额外 AI 调用，且保守——只在明显无视时判悬挂）；
 * 开启 ws_hook_payoff_ai 后改用一次轻量 AI 判定（更准）。
 */
defined('APP_LOADED') or die('Direct access denied.');

class HookPayoffChecker
{
    /**
     * @return array{checked:bool, resolved:?bool, prev_chapter:int, prev_hook:string, method:string}
     */
    public static function check(int $novelId, array $chapter, string $content): array
    {
        $miss = ['checked' => false, 'resolved' => null, 'prev_chapter' => 0, 'prev_hook' => '', 'method' => 'skip'];
        $chNum = (int)($chapter['chapter_number'] ?? 0);
        if ($chNum <= 1) return $miss;
        if (!getSystemSetting('ws_hook_payoff_enabled', true, 'bool')) return $miss;

        $prev = DB::fetch(
            "SELECT hook FROM chapters WHERE novel_id=? AND chapter_number=? AND status='completed' LIMIT 1",
            [$novelId, $chNum - 1]
        );
        $prevHook = trim((string)($prev['hook'] ?? ''));
        if ($prevHook === '') return $miss;

        // 本章开头（钩子应在前段被回应）
        $opening = mb_substr($content, 0, max(800, (int)(mb_strlen($content) * 0.4)));

        // 可选：AI 判定
        if (getSystemSetting('ws_hook_payoff_ai', false, 'bool')) {
            $aiRes = self::aiJudge($novelId, $prevHook, $opening, $chapter);
            if ($aiRes !== null) {
                return ['checked' => true, 'resolved' => $aiRes, 'prev_chapter' => $chNum - 1, 'prev_hook' => $prevHook, 'method' => 'ai'];
            }
        }

        // 启发式：从上章钩子里抽"关键实体/词"，看本章开头是否提及
        $keys = self::extractKeyTokens($novelId, $prevHook);
        if (empty($keys)) {
            // 抽不出可判定的实体 → 不下结论，避免误报
            return $miss;
        }
        // H-9 修复（2026-07-25）：原代码任意单个关键词命中即判"已回收"，
        // 常见词（如"剑""战斗"）在无关上下文出现会导致假阳性，让真正的烂尾钩子被忽略。
        // 改为：统计命中数，当有多个关键词时要求至少 2 个命中；
        // 仅当只有 1 个关键词时（如只有角色名）才接受单命中。
        $hitCount = 0;
        foreach ($keys as $k) {
            if (mb_strpos($opening, $k) !== false) {
                $hitCount++;
            }
        }
        $requiredHits = count($keys) >= 2 ? 2 : 1;
        $hit = $hitCount >= $requiredHits;
        return [
            'checked'      => true,
            'resolved'     => $hit,
            'prev_chapter' => $chNum - 1,
            'prev_hook'    => $prevHook,
            'method'       => 'keyword',
            'hit_count'    => $hitCount,
            'required_hits' => $requiredHits,
        ];
    }

    /**
     * 执行检查并落库 + 悬挂时写 Agent 指令。返回结果数组（同 check）。
     */
    public static function run(int $novelId, array $chapter, string $content): array
    {
        try {
            $res = self::check($novelId, $chapter, $content);
            if (!$res['checked']) return $res;

            DB::update('chapters', ['hook_resolved' => $res['resolved'] ? 1 : 0], 'id=?', [(int)$chapter['id']]);

            if ($res['resolved'] === false) {
                addLog($novelId, 'warn', sprintf(
                    '悬挂钩子：本章未明显回收第%d章结尾钩子「%s」',
                    $res['prev_chapter'],
                    mb_substr($res['prev_hook'], 0, 40)
                ), (int)$chapter['id']);
                try {
                    require_once __DIR__ . '/agents/AgentDirectives.php';
                    AgentDirectives::add(
                        $novelId,
                        (int)($chapter['chapter_number'] ?? 0) + 1,
                        'quality',
                        "前面留有未回收的章末钩子（第{$res['prev_chapter']}章）：「" . mb_substr($res['prev_hook'], 0, 60) . "」。本章请尽快正面回应/推进它，避免悬念烂尾。",
                        1, 24
                    );
                } catch (\Throwable $e) { /* 指令写入失败不影响主流程 */ }
            } else {
                addLog($novelId, 'info', "钩子回收：本章已承接第{$res['prev_chapter']}章结尾钩子", (int)$chapter['id']);
            }
            return $res;
        } catch (\Throwable $e) {
            error_log('HookPayoffChecker::run ' . $e->getMessage());
            return ['checked' => false, 'resolved' => null, 'prev_chapter' => 0, 'prev_hook' => '', 'method' => 'error'];
        }
    }

    /** 从钩子文本抽取可判定的关键词：优先角色名，其次去停用词后的 3-gram */
    private static function extractKeyTokens(int $novelId, string $hook): array
    {
        $keys = [];

        // 1) 角色名（最可靠的实体）
        try {
            $names = DB::fetchAll("SELECT name FROM character_cards WHERE novel_id=? LIMIT 200", [$novelId]);
            foreach ($names as $n) {
                $nm = trim((string)($n['name'] ?? ''));
                if ($nm !== '' && mb_strlen($nm) >= 2 && mb_strpos($hook, $nm) !== false) {
                    $keys[] = $nm;
                }
            }
        } catch (\Throwable $e) { /* 忽略 */ }

        // 2) 钩子里的"引号内内容"（常是关键名词/台词）
        if (preg_match_all('/[「『"“](.+?)[」』"”]/u', $hook, $qm)) {
            foreach ($qm[1] as $q) {
                $q = trim($q);
                if (mb_strlen($q) >= 2 && mb_strlen($q) <= 12) $keys[] = $q;
            }
        }

        // 3) 兜底：去掉常见虚词后取较长的 CJK 片段（3-4字）
        if (empty($keys)) {
            $stop = ['这个','那个','什么','为什么','怎么','突然','竟然','居然','原来','已经','却是','只是','可是','但是','然而','于是','即将','马上','一个','他们','她们','自己','现在','如今','此时','此刻'];
            $clean = preg_replace('/[^\x{4e00}-\x{9fa5}]+/u', ' ', $hook);
            foreach (preg_split('/\s+/u', trim($clean)) as $seg) {
                $len = mb_strlen($seg);
                if ($len < 3) continue;
                // 取片段里的 3-gram 作为候选关键词
                for ($i = 0; $i + 3 <= $len; $i++) {
                    $g = mb_substr($seg, $i, 3);
                    if (!in_array($g, $stop, true)) $keys[] = $g;
                }
            }
            $keys = array_slice(array_values(array_unique($keys)), 0, 6);
        }

        return array_values(array_unique($keys));
    }

    /** 可选 AI 判定：是/否。失败返回 null（回退到启发式） */
    private static function aiJudge(int $novelId, string $prevHook, string $opening, array $chapter): ?bool
    {
        try {
            require_once __DIR__ . '/ai.php';
            $novel = DB::fetch("SELECT model_id FROM novels WHERE id=?", [$novelId]);
            $ai = getAIClient(!empty($novel['model_id']) ? (int)$novel['model_id'] : null);
            $raw = trim($ai->chat([
                ['role' => 'system', 'content' => '你是小说连续性审校。只回一个字：是 或 否。'],
                ['role' => 'user', 'content' =>
                    "上一章结尾抛出的悬念：「{$prevHook}」\n\n本章开头：\n" . mb_substr($opening, 0, 1200)
                    . "\n\n本章开头是否正面回应/推进了上面这个悬念？只回「是」或「否」。"],
            ], 'structured'));
            if (mb_strpos($raw, '是') !== false && mb_strpos($raw, '否') === false) return true;
            if (mb_strpos($raw, '否') !== false) return false;
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
