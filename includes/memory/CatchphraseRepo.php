<?php
defined('APP_LOADED') or die('Direct access denied.');

/**
 * CatchphraseRepo — 金句/梗调度仓储
 *
 * 跟踪小说中的金句，支持：
 *   1. 提取/插入新金句
 *   2. 查询可 callback 的金句（用于 prompt 注入）
 *   3. 记录 callback（金句被再次引用）
 */
final class CatchphraseRepo
{
    private int $novelId;

    public function __construct(int $novelId)
    {
        $this->novelId = $novelId;
    }

    /**
     * 插入一条金句
     */
    public function add(string $phrase, ?string $speaker, int $chapterNum, string $importance = 'normal'): int
    {
        $phrase = trim($phrase);
        if ($phrase === '' || mb_strlen($phrase) > 255) return 0;

        $validImportance = ['iconic', 'normal', 'minor'];
        if (!in_array($importance, $validImportance, true)) {
            $importance = 'normal';
        }

        $existing = DB::fetch(
            'SELECT id FROM novel_catchphrases WHERE novel_id=? AND phrase=? LIMIT 1',
            [$this->novelId, $phrase]
        );
        if ($existing) return (int)$existing['id'];

        return (int)DB::insert('novel_catchphrases', [
            'novel_id'       => $this->novelId,
            'phrase'         => $phrase,
            'speaker'        => $speaker,
            'first_chapter'  => $chapterNum,
            'importance'     => $importance,
        ]);
    }

    /**
     * 记录一次 callback（金句被再次引用）
     */
    public function recordCallback(int $phraseId, int $chapterNum): void
    {
        DB::execute(
            'UPDATE novel_catchphrases SET callback_count=callback_count+1, last_callback_chapter=? WHERE id=? AND novel_id=?',
            [$chapterNum, $phraseId, $this->novelId]
        );
        try {
            DB::insert('catchphrase_callback_log', [
                'catchphrase_id' => $phraseId,
                'novel_id'       => $this->novelId,
                'chapter_number' => $chapterNum,
            ]);
        } catch (\Throwable $e) {}
    }

    /**
     * 获取可用于 callback 的金句列表
     * 优先返回 iconic > normal，按 callback 次数排序
     *
     * @param int $currentChapter 当前章节号
     * @param int $limit 最多返回条数
     */
    public function listForCallback(int $currentChapter, int $limit = 10): array
    {
        return DB::fetchAll(
            'SELECT id, phrase, speaker, first_chapter, callback_count, importance
             FROM novel_catchphrases
             WHERE novel_id=? AND first_chapter < ?
             ORDER BY FIELD(importance, "iconic", "normal", "minor"), callback_count ASC, first_chapter DESC
             LIMIT ' . (int)$limit,
            [$this->novelId, $currentChapter]
        );
    }

    /**
     * 获取所有金句
     */
    public function listAll(): array
    {
        return DB::fetchAll(
            'SELECT id, phrase, speaker, first_chapter, callback_count, last_callback_chapter, importance, created_at
             FROM novel_catchphrases
             WHERE novel_id=?
             ORDER BY first_chapter ASC',
            [$this->novelId]
        );
    }

    /**
     * 删除一条金句
     */
    public function delete(int $phraseId): bool
    {
        return DB::execute(
            'DELETE FROM novel_catchphrases WHERE id=? AND novel_id=?',
            [$phraseId, $this->novelId]
        ) > 0;
    }

    /**
     * 构建金句 prompt 注入段落
     */
    public function buildCallbackSection(int $currentChapter): string
    {
        $phrases = $this->listForCallback($currentChapter, 8);
        if (empty($phrases)) return '';

        $lines = [];
        foreach ($phrases as $p) {
            $speaker = $p['speaker'] ? "（{$p['speaker']}）" : '';
            $cbInfo = $p['callback_count'] > 0 ? "已callback {$p['callback_count']}次" : '未callback';
            $iconic = $p['importance'] === 'iconic' ? '⭐' : '';
            $lines[] = "{$iconic}· 第{$p['first_chapter']}章{$speaker}：「{$p['phrase']}」（{$cbInfo}）";
        }

        $section = "【💬 可调用金句（建议在恰当场景 callback，强化角色记忆点）】\n";
        $section .= implode("\n", $lines) . "\n";
        $section .= "如果本章是高潮/转折，建议让角色 callback 自己之前说过的金句，增加读者上头感。\n\n";

        return $section;
    }

    /**
     * 从章节正文中用关键词匹配检测金句的 callback 情况
     * 匹配策略：金句核心词（去掉标点后取前15字）出现在正文中
     */
    public function trackCallbacksInContent(string $content, int $chapterNum): int
    {
        $phrases = DB::fetchAll(
            'SELECT id, phrase FROM novel_catchphrases WHERE novel_id=? AND first_chapter < ?',
            [$this->novelId, $chapterNum]
        );
        if (empty($phrases)) return 0;

        $matched = 0;
        foreach ($phrases as $p) {
            $clean = preg_replace('/[\x{3000}-\x{303f}\x{ff01}-\x{ff5e}\x{2000}-\x{206f}\x{2018}-\x{201f}\x{300a}-\x{3011}\s]+/u', '', $p['phrase']);
            $kw = mb_substr($clean, 0, 15);
            if (mb_strlen($kw) < 4) continue;
            if (mb_strpos($content, $kw) !== false) {
                $this->recordCallback((int)$p['id'], $chapterNum);
                $matched++;
            }
        }
        return $matched;
    }

    /**
     * 回滚指定章节的金句回调记录（重写后数据清理用）
     *
     * 1. 从 catchphrase_callback_log 查找该章节的回调记录
     * 2. 对每个受影响的金句：callback_count-1，last_callback_chapter 回退到上一条日志
     * 3. 删除该章节的 callback_log 记录
     */
    public function revertCallbacksForChapter(int $chapterNumber): int
    {
        try {
            $logs = DB::fetchAll(
                'SELECT catchphrase_id FROM catchphrase_callback_log WHERE novel_id=? AND chapter_number=?',
                [$this->novelId, $chapterNumber]
            );
            if (empty($logs)) return 0;

            $affected = 0;
            foreach ($logs as $log) {
                $cid = (int)$log['catchphrase_id'];

                $prevLog = DB::fetch(
                    'SELECT chapter_number FROM catchphrase_callback_log WHERE catchphrase_id=? AND novel_id=? AND chapter_number<? ORDER BY chapter_number DESC LIMIT 1',
                    [$cid, $this->novelId, $chapterNumber]
                );

                $newLastCallback = $prevLog ? (int)$prevLog['chapter_number'] : null;

                DB::execute(
                    'UPDATE novel_catchphrases SET callback_count=GREATEST(callback_count-1, 0), last_callback_chapter=? WHERE id=? AND novel_id=?',
                    [$newLastCallback, $cid, $this->novelId]
                );
                $affected++;
            }

            DB::delete('catchphrase_callback_log', 'novel_id=? AND chapter_number=?', [$this->novelId, $chapterNumber]);

            return $affected;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
