<?php
/**
 * CreativeAssistService
 *
 * 聚合现有写作资产，给“智能创作辅助系统”提供一个轻量、可解释的写作前上下文。
 * 这个服务只读取和复用现有数据，不引入新表。
 */

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/data.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/agents/AgentDirectives.php';

final class CreativeAssistService
{
    public function buildContext(int $novelId, ?int $chapterId = null): array
    {
        $novel = getNovel($novelId);
        if (!$novel) {
            throw new RuntimeException('小说不存在');
        }

        $chapter = $this->resolveChapter($novelId, $chapterId);
        if (!$chapter) {
            return [
                'novel' => $this->novelPayload($novel),
                'chapter' => null,
                'writing_ready' => false,
                'target' => null,
                'context_blocks' => [],
                'risks' => [[
                    'level' => 'warning',
                    'title' => '暂无可辅助创作的章节',
                    'message' => '请先创建章节或生成章节大纲。',
                ]],
            ];
        }

        $chapterNumber = (int)$chapter['chapter_number'];
        $storyOutline = $this->getStoryOutline($novelId);
        $volumeOutline = $this->getVolumeOutline($novelId, $chapterNumber);
        $synopsis = $this->getChapterSynopsis($novelId, $chapterNumber);
        $previous = $this->getPreviousContext($novelId, $chapterNumber);
        $memoryAtoms = $this->getMemoryAtoms($novelId, $chapterNumber);
        $characterCards = $this->getCharacterCards($novelId, $chapterNumber);
        $emotionStates = $this->getCharacterEmotionStates($novelId, $chapterNumber);
        $foreshadowing = $this->getForeshadowing($novelId, $chapterNumber);
        $directives = AgentDirectives::active($novelId, $chapterNumber);

        $target = $this->targetPayload($novel, $chapter, $storyOutline, $volumeOutline, $synopsis);
        $contextBlocks = $this->contextBlocks(
            $previous,
            $memoryAtoms,
            $characterCards,
            $emotionStates,
            $foreshadowing,
            $directives,
            $novel
        );

        return [
            'novel' => $this->novelPayload($novel),
            'chapter' => $this->chapterPayload($chapter),
            'writing_ready' => in_array($chapter['status'] ?? '', ['outlined', 'skipped'], true),
            'target' => $target,
            'context_blocks' => $contextBlocks,
            'risks' => $this->buildRisks(
                $novel,
                $chapter,
                $storyOutline,
                $synopsis,
                $previous,
                $characterCards,
                $foreshadowing,
                $directives
            ),
        ];
    }

    public function saveTemporaryDirective(int $novelId, int $chapterNumber, string $directive): array
    {
        $directive = trim($directive);
        if ($novelId <= 0 || $chapterNumber <= 0) {
            throw new RuntimeException('缺少小说或章节信息');
        }
        if ($directive === '') {
            throw new RuntimeException('请填写本章临时写作指令');
        }

        $text = '【智能创作辅助系统·本章临时指令】' . $directive;
        $ok = AgentDirectives::add($novelId, $chapterNumber, 'strategy', $text, 1, 24, 'creative_assist');
        if (!$ok) {
            throw new RuntimeException('临时指令保存失败');
        }

        addLog($novelId, 'creative_assist_directive', "写入第{$chapterNumber}章临时指令：" . mb_substr($directive, 0, 80));

        return [
            'saved' => true,
            'chapter_number' => $chapterNumber,
            'directive' => $text,
        ];
    }

    public function buildQualityReport(int $novelId, ?int $chapterId = null): array
    {
        $chapter = $chapterId
            ? DB::fetch('SELECT c.*, n.genre, n.chapter_words, n.writing_style FROM chapters c JOIN novels n ON c.novel_id=n.id WHERE c.id=? AND c.novel_id=?', [$chapterId, $novelId])
            : DB::fetch('SELECT c.*, n.genre, n.chapter_words, n.writing_style FROM chapters c JOIN novels n ON c.novel_id=n.id WHERE c.novel_id=? AND c.status="completed" ORDER BY c.chapter_number DESC LIMIT 1', [$novelId]);

        if (!$chapter) {
            throw new RuntimeException('未找到可检测的章节');
        }
        $content = trim((string)($chapter['content'] ?? ''));
        if ($content === '') {
            throw new RuntimeException('章节内容为空，无法检测');
        }

        if (!defined('CLI_MODE')) {
            define('CLI_MODE', true);
        }
        require_once dirname(__DIR__) . '/api/validate_consistency.php';

        $results = [
            checkGate1_Structure($chapter, $content),
            checkGate2_Characters($novelId, $content),
            checkGate3_Description($chapter['genre'] ?? '', $content),
            checkGate4_CoolPoint($content, $chapter['outline'] ?? ''),
            checkGate5_Consistency((int)$chapter['id'], $novelId, $content),
        ];

        $scores = array_column($results, 'score');
        $avgScore = count($scores) ? round(array_sum($scores) / count($scores), 1) : 0;

        return [
            'chapter' => $this->chapterPayload($chapter),
            'passes' => !array_filter($results, fn($r) => empty($r['status'])),
            'total_score' => $avgScore,
            'summary' => generateSummary($results),
            'gates' => $results,
        ];
    }

    private function resolveChapter(int $novelId, ?int $chapterId): array|false
    {
        if ($chapterId) {
            return DB::fetch('SELECT * FROM chapters WHERE id=? AND novel_id=?', [$chapterId, $novelId]);
        }

        $chapter = DB::fetch(
            'SELECT * FROM chapters
             WHERE novel_id=? AND status IN ("outlined","skipped","pending","failed")
             ORDER BY chapter_number ASC LIMIT 1',
            [$novelId]
        );
        if ($chapter) return $chapter;

        return DB::fetch(
            'SELECT * FROM chapters WHERE novel_id=? ORDER BY chapter_number DESC LIMIT 1',
            [$novelId]
        );
    }

    private function novelPayload(array $novel): array
    {
        return [
            'id' => (int)$novel['id'],
            'title' => $novel['title'] ?? '',
            'genre' => $novel['genre'] ?? '',
            'writing_style' => $novel['writing_style'] ?? '',
            'protagonist_name' => $novel['protagonist_name'] ?? '',
            'target_chapters' => (int)($novel['target_chapters'] ?? 0),
            'chapter_words' => (int)($novel['chapter_words'] ?? 0),
            'status' => $novel['status'] ?? '',
        ];
    }

    private function chapterPayload(array $chapter): array
    {
        return [
            'id' => (int)$chapter['id'],
            'chapter_number' => (int)$chapter['chapter_number'],
            'title' => $chapter['title'] ?? '',
            'status' => $chapter['status'] ?? '',
            'words' => (int)($chapter['words'] ?? 0),
            'quality_score' => isset($chapter['quality_score']) ? (float)$chapter['quality_score'] : null,
        ];
    }

    private function targetPayload(array $novel, array $chapter, ?array $storyOutline, ?array $volumeOutline, ?array $synopsis): array
    {
        return [
            'chapter_number' => (int)$chapter['chapter_number'],
            'title' => $chapter['title'] ?: '第' . (int)$chapter['chapter_number'] . '章',
            'status' => $chapter['status'] ?? '',
            'target_words' => (int)($novel['chapter_words'] ?? 0),
            'outline' => trim((string)($chapter['outline'] ?? '')),
            'key_points' => $this->decodeJsonList($chapter['key_points'] ?? null),
            'hook' => trim((string)($chapter['hook'] ?? '')),
            'cool_point_type' => $chapter['cool_point_type'] ?? '',
            'pacing' => $chapter['pacing'] ?? '',
            'suspense' => $chapter['suspense'] ?? '',
            'story_goal' => $storyOutline ? $this->shorten($storyOutline['story_arc'] ?? '', 260) : '',
            'volume_goal' => $volumeOutline ? $this->shorten($volumeOutline['summary'] ?? '', 220) : '',
            'synopsis' => $synopsis ? $this->shorten($synopsis['synopsis'] ?? '', 360) : '',
            'scene_breakdown' => $synopsis ? $this->decodeJsonList($synopsis['scene_breakdown'] ?? null) : [],
            'dialogue_beats' => $synopsis ? $this->decodeJsonList($synopsis['dialogue_beats'] ?? null) : [],
            'sensory_details' => $synopsis ? $this->decodeJsonList($synopsis['sensory_details'] ?? null) : [],
            'cliffhanger' => $synopsis ? trim((string)($synopsis['cliffhanger'] ?? '')) : '',
        ];
    }

    private function getStoryOutline(int $novelId): ?array
    {
        return $this->fetchOneSafe('SELECT * FROM story_outlines WHERE novel_id=? LIMIT 1', [$novelId]);
    }

    private function getVolumeOutline(int $novelId, int $chapterNumber): ?array
    {
        return $this->fetchOneSafe(
            'SELECT * FROM volume_outlines WHERE novel_id=? AND start_chapter <= ? AND end_chapter >= ? LIMIT 1',
            [$novelId, $chapterNumber, $chapterNumber]
        );
    }

    private function getChapterSynopsis(int $novelId, int $chapterNumber): ?array
    {
        return $this->fetchOneSafe(
            'SELECT * FROM chapter_synopses WHERE novel_id=? AND chapter_number=? LIMIT 1',
            [$novelId, $chapterNumber]
        );
    }

    private function getPreviousContext(int $novelId, int $chapterNumber): array
    {
        return [
            'summary' => $chapterNumber > 1 ? getPreviousSummary($novelId, $chapterNumber, 5) : '',
            'tail' => $chapterNumber > 1 ? getPreviousTail($novelId, $chapterNumber, 800) : '',
        ];
    }

    private function getMemoryAtoms(int $novelId, int $chapterNumber): array
    {
        return $this->fetchAllSafe(
            'SELECT id, atom_type, content, source_chapter, confidence
             FROM memory_atoms
             WHERE novel_id=? AND (source_chapter IS NULL OR source_chapter < ?)
             ORDER BY confidence DESC, source_chapter DESC, id DESC
             LIMIT 10',
            [$novelId, $chapterNumber]
        );
    }

    private function getCharacterCards(int $novelId, int $chapterNumber): array
    {
        return $this->fetchAllSafe(
            'SELECT id, name, title, status, alive, attributes, last_updated_chapter
             FROM character_cards
             WHERE novel_id=?
             ORDER BY COALESCE(last_updated_chapter, 0) DESC, updated_at DESC
             LIMIT 8',
            [$novelId]
        );
    }

    private function getCharacterEmotionStates(int $novelId, int $chapterNumber): array
    {
        if ($chapterNumber <= 1) return [];
        return $this->fetchAllSafe(
            'SELECT character_name, emotion_state, intensity, summary, chapter_number
             FROM character_emotion_history
             WHERE novel_id=? AND chapter_number < ?
             ORDER BY chapter_number DESC, id DESC
             LIMIT 8',
            [$novelId, $chapterNumber]
        );
    }

    private function getForeshadowing(int $novelId, int $chapterNumber): array
    {
        return $this->fetchAllSafe(
            'SELECT id, description, priority, planted_chapter, deadline_chapter, last_mentioned_chapter, mention_count
             FROM foreshadowing_items
             WHERE novel_id=? AND resolved_chapter IS NULL
             ORDER BY
               CASE priority WHEN "critical" THEN 0 WHEN "major" THEN 1 ELSE 2 END,
               CASE WHEN deadline_chapter IS NULL THEN 999999 ELSE deadline_chapter END ASC,
               planted_chapter ASC
             LIMIT 10',
            [$novelId]
        );
    }

    private function contextBlocks(
        array $previous,
        array $memoryAtoms,
        array $characterCards,
        array $emotionStates,
        array $foreshadowing,
        array $directives,
        array $novel
    ): array {
        return [
            [
                'key' => 'previous',
                'title' => '上一章衔接',
                'icon' => 'bi-arrow-return-left',
                'items' => array_values(array_filter([
                    $previous['summary'] ? ['label' => '近章摘要', 'text' => $this->shorten($previous['summary'], 360)] : null,
                    $previous['tail'] ? ['label' => '上一章结尾', 'text' => $this->shorten($previous['tail'], 360)] : null,
                ])),
            ],
            [
                'key' => 'memory',
                'title' => '相关记忆',
                'icon' => 'bi-memory',
                'items' => array_map(fn($row) => [
                    'label' => $this->memoryTypeLabel($row['atom_type'] ?? ''),
                    'text' => $this->shorten($row['content'] ?? '', 180),
                    'meta' => isset($row['source_chapter']) ? '第' . (int)$row['source_chapter'] . '章' : '',
                ], $memoryAtoms),
            ],
            [
                'key' => 'characters',
                'title' => '角色状态',
                'icon' => 'bi-person-lines-fill',
                'items' => array_map(fn($row) => [
                    'label' => ($row['alive'] ?? 1) ? ($row['name'] ?? '') : ($row['name'] ?? '') . '（已死亡）',
                    'text' => $this->shorten(trim(($row['title'] ?? '') . ' ' . ($row['status'] ?? '')), 180),
                    'meta' => !empty($row['last_updated_chapter']) ? '更新于第' . (int)$row['last_updated_chapter'] . '章' : '',
                ], $characterCards),
            ],
            [
                'key' => 'emotions',
                'title' => '角色情绪余波',
                'icon' => 'bi-activity',
                'items' => array_map(fn($row) => [
                    'label' => ($row['character_name'] ?? '') . ' · ' . ($row['emotion_state'] ?? ''),
                    'text' => $this->shorten($row['summary'] ?? '', 160),
                    'meta' => '强度' . (int)($row['intensity'] ?? 0) . ' · 第' . (int)($row['chapter_number'] ?? 0) . '章',
                ], $emotionStates),
            ],
            [
                'key' => 'foreshadowing',
                'title' => '待处理伏笔',
                'icon' => 'bi-bookmark-star',
                'items' => array_map(fn($row) => [
                    'label' => strtoupper($row['priority'] ?? 'minor'),
                    'text' => $this->shorten($row['description'] ?? '', 180),
                    'meta' => !empty($row['deadline_chapter']) ? '建议第' . (int)$row['deadline_chapter'] . '章前回收' : '无明确期限',
                ], $foreshadowing),
            ],
            [
                'key' => 'directives',
                'title' => '当前 Agent 指令',
                'icon' => 'bi-cpu',
                'items' => array_map(fn($row) => [
                    'label' => $row['type'] ?? 'strategy',
                    'text' => $this->shorten($row['directive'] ?? '', 220),
                    'meta' => $row['created_at'] ?? '',
                ], $directives),
            ],
            [
                'key' => 'style',
                'title' => '风格锚点',
                'icon' => 'bi-brush',
                'items' => array_values(array_filter([
                    !empty($novel['genre']) ? ['label' => '类型', 'text' => $novel['genre']] : null,
                    !empty($novel['writing_style']) ? ['label' => '写作风格', 'text' => $novel['writing_style']] : null,
                    !empty($novel['ref_author']) ? ['label' => '参考作者', 'text' => $novel['ref_author']] : null,
                    !empty($novel['protagonist_name']) ? ['label' => '主角锚定', 'text' => $novel['protagonist_name']] : null,
                ])),
            ],
        ];
    }

    private function buildRisks(
        array $novel,
        array $chapter,
        ?array $storyOutline,
        ?array $synopsis,
        array $previous,
        array $characterCards,
        array $foreshadowing,
        array $directives
    ): array {
        $risks = [];
        $chNum = (int)$chapter['chapter_number'];

        if (!$storyOutline) {
            $risks[] = ['level' => 'warning', 'title' => '缺少全书故事大纲', 'message' => '建议先生成全书故事大纲，让本章目标和长期主线对齐。'];
        }
        if (trim((string)($chapter['outline'] ?? '')) === '') {
            $risks[] = ['level' => 'danger', 'title' => '本章缺少章节大纲', 'message' => '写作入口可能无法稳定工作，请先补齐章节细纲。'];
        }
        if (!$synopsis) {
            $risks[] = ['level' => 'warning', 'title' => '缺少章节概要', 'message' => '没有场景拆分、对话节拍和感官细节，正文生成的可控性会下降。'];
        }
        if ($chNum > 1 && trim((string)($previous['tail'] ?? '')) === '') {
            $risks[] = ['level' => 'warning', 'title' => '上一章结尾缺失', 'message' => '缺少衔接尾巴，开篇承接可能变弱。'];
        }
        if (trim((string)($chapter['hook'] ?? '')) === '' && (!$synopsis || trim((string)($synopsis['cliffhanger'] ?? '')) === '')) {
            $risks[] = ['level' => 'info', 'title' => '结尾钩子未明确', 'message' => '建议在临时指令中指定本章结尾悬念或反转。'];
        }
        if (empty($chapter['cool_point_type'])) {
            $risks[] = ['level' => 'info', 'title' => '爽点类型未标记', 'message' => '如果这是商业网文，建议明确本章爽点类型。'];
        }
        if (empty($characterCards)) {
            $risks[] = ['level' => 'info', 'title' => '角色状态较少', 'message' => '当前记忆中角色卡片不足，前几章请更留意主角名和人物关系。'];
        }
        foreach ($foreshadowing as $item) {
            $deadline = (int)($item['deadline_chapter'] ?? 0);
            if ($deadline > 0 && $deadline <= $chNum + 3) {
                $risks[] = [
                    'level' => $deadline <= $chNum ? 'danger' : 'warning',
                    'title' => '伏笔临近回收',
                    'message' => '「' . $this->shorten($item['description'] ?? '', 80) . '」建议在第' . $deadline . '章前后处理。',
                ];
                break;
            }
        }
        if (empty($directives)) {
            $risks[] = ['level' => 'info', 'title' => '暂无 Agent 指令', 'message' => '本章没有额外策略约束，可按大纲正常推进。'];
        }

        return $risks ?: [[
            'level' => 'success',
            'title' => '写作准备较完整',
            'message' => '本章已有基础目标和上下文，可以进入生成或手动微调。',
        ]];
    }

    private function fetchOneSafe(string $sql, array $params): ?array
    {
        try {
            $row = DB::fetch($sql, $params);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function fetchAllSafe(string $sql, array $params): array
    {
        try {
            return DB::fetchAll($sql, $params);
        } catch (Throwable $e) {
            return [];
        }
    }

    private function decodeJsonList($value): array
    {
        if (is_array($value)) return $value;
        if (!is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) return [];
        return array_values($decoded);
    }

    private function memoryTypeLabel(string $type): string
    {
        return [
            'character_trait' => '角色特征',
            'world_setting' => '世界观',
            'plot_detail' => '情节细节',
            'style_preference' => '风格偏好',
            'constraint' => '约束',
            'technique' => '写法技巧',
            'world_state' => '世界状态',
            'cool_point' => '爽点',
        ][$type] ?? $type;
    }

    private function shorten(?string $text, int $limit): string
    {
        $text = trim((string)$text);
        if ($text === '') return '';
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        if (mb_strlen($text) <= $limit) return $text;
        return mb_substr($text, 0, $limit - 1) . '…';
    }
}
