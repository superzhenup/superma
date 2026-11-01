<?php
defined('APP_LOADED') or die('Direct access denied.');

class ShortStoryService
{
    private const VALID_STRUCTURES = ['six_beat', 'eight_beat', 'three_act'];
    private const MIN_WORDS = 800;
    private const MAX_WORDS = 120000;
    private const DEFAULT_WORDS = 3000;
    private const MAX_CHAPTERS = 20;
    private const MIN_CHAPTERS = 1;

    private const STATUS_FLOW = [
        'draft'        => ['brief_ready'],
        'brief_ready'  => ['beats_ready', 'draft'],
        'beats_ready'  => ['written', 'brief_ready'],
        'written'      => ['polished', 'beats_ready'],
        'polished'     => ['completed', 'written'],
        'completed'    => ['polished'],
    ];

    public function listStories(int $userId = 0): array
    {
        $sql = 'SELECT id, title, genre, status, target_words, quality_score, model_id, created_at, updated_at, content
                FROM short_stories';
        $params = [];
        if ($userId > 0) {
            $sql .= ' WHERE user_id = ?';
            $params[] = $userId;
        }
        $sql .= ' ORDER BY updated_at DESC';
        $rows = DB::fetchAll($sql, $params);
        foreach ($rows as &$r) {
            $r['word_count'] = $this->contentWordCount($r);
            unset($r['content']);
        }
        unset($r);
        return $rows;
    }

    public function getStory(int $storyId): ?array
    {
        $story = DB::fetch('SELECT * FROM short_stories WHERE id = ?', [$storyId]);
        if (!$story) return null;
        $story['word_count'] = $this->contentWordCount($story);
        $story['brief_json'] = $story['brief_json'] ? json_decode($story['brief_json'], true) : null;
        $story['beats_json'] = $story['beats_json'] ? json_decode($story['beats_json'], true) : null;
        $story['chapters_json'] = !empty($story['chapters_json']) ? json_decode($story['chapters_json'], true) : null;
        $story['quality_report'] = $story['quality_report'] ? json_decode($story['quality_report'], true) : null;
        $story['chapter_count'] = (int)($story['chapter_count'] ?? 1);
        return $story;
    }

    public function createStory(array $input, int $userId = 0): int
    {
        $targetWords = (int)($input['target_words'] ?? self::DEFAULT_WORDS);
        $targetWords = max(self::MIN_WORDS, min(self::MAX_WORDS, $targetWords));
        $structure = $input['structure_type'] ?? 'eight_beat';
        if (!in_array($structure, self::VALID_STRUCTURES, true)) {
            $structure = 'eight_beat';
        }
        $chapterCount = (int)($input['chapter_count'] ?? 1);
        $chapterCount = max(self::MIN_CHAPTERS, min(self::MAX_CHAPTERS, $chapterCount));
        $id = DB::insert('short_stories', [
            'user_id'          => $userId,
            'title'            => trim($input['title'] ?? ''),
            'genre'            => trim($input['genre'] ?? ''),
            'theme'            => trim($input['theme'] ?? '') ?: null,
            'premise'          => trim($input['premise'] ?? '') ?: null,
            'protagonist'      => trim($input['protagonist'] ?? '') ?: null,
            'conflict'         => trim($input['conflict'] ?? '') ?: null,
            'ending_direction' => trim($input['ending_direction'] ?? '') ?: null,
            'style'            => trim($input['style'] ?? '') ?: null,
            'target_words'     => $targetWords,
            'structure_type'   => $structure,
            'chapter_count'    => $chapterCount,
            'model_id'         => !empty($input['model_id']) ? (int)$input['model_id'] : null,
            'status'           => 'draft',
        ]);
        return (int)$id;
    }

    public function updateMeta(int $storyId, array $input): array
    {
        $story = DB::fetch('SELECT * FROM short_stories WHERE id = ?', [$storyId]);
        if (!$story) return ['ok' => false, 'error' => '短篇小说不存在'];

        $data = [];
        $strFields = ['title', 'genre', 'theme', 'premise', 'protagonist', 'conflict', 'ending_direction', 'style'];
        foreach ($strFields as $f) {
            if (array_key_exists($f, $input)) {
                $v = trim($input[$f]);
                $data[$f] = $v !== '' ? $v : null;
            }
        }
        if (isset($input['target_words'])) {
            $data['target_words'] = max(self::MIN_WORDS, min(self::MAX_WORDS, (int)$input['target_words']));
        }
        if (isset($input['structure_type'])) {
            if (in_array($input['structure_type'], self::VALID_STRUCTURES, true)) {
                $data['structure_type'] = $input['structure_type'];
            }
        }
        if (isset($input['chapter_count'])) {
            $data['chapter_count'] = max(self::MIN_CHAPTERS, min(self::MAX_CHAPTERS, (int)$input['chapter_count']));
        }
        if (array_key_exists('model_id', $input)) {
            $data['model_id'] = !empty($input['model_id']) ? (int)$input['model_id'] : null;
        }
        if (empty($data)) return ['ok' => true, 'story' => $this->getStory($storyId)];

        $sets = [];
        $params = [];
        foreach ($data as $k => $v) {
            // M-5 修复：列名校验，与 HotNovelService 一致
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $k)) continue;
            $sets[] = "`{$k}` = ?";
            $params[] = $v;
        }
        $params[] = $storyId;
        DB::execute('UPDATE short_stories SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

        return ['ok' => true, 'story' => $this->getStory($storyId)];
    }

    public function saveContent(int $storyId, string $content, string $note = '手动保存'): array
    {
        $story = DB::fetch('SELECT * FROM short_stories WHERE id = ?', [$storyId]);
        if (!$story) return ['ok' => false, 'error' => '短篇小说不存在'];
        if ((int)($story['chapter_count'] ?? 1) >= 2) {
            return ['ok' => false, 'error' => '分章模式请保存当前章节，不能直接覆盖全文'];
        }

        $versionSaved = false;
        $oldContent = $story['content'] ?? '';
        if (mb_strlen(trim($oldContent)) > 0) {
            $this->createVersion($storyId, $story, $note);
            $versionSaved = true;
        }

        DB::update('short_stories', ['content' => $content], 'id = ?', [$storyId]);

        return ['ok' => true, 'story' => $this->getStory($storyId), 'version_saved' => $versionSaved];
    }

    public function saveBrief(int $storyId, array $brief): array
    {
        $story = DB::fetch('SELECT * FROM short_stories WHERE id = ?', [$storyId]);
        if (!$story) return ['ok' => false, 'error' => '短篇小说不存在'];

        DB::update('short_stories', [
            'brief_json' => json_encode($brief, JSON_UNESCAPED_UNICODE),
            'status'     => 'brief_ready',
        ], 'id = ?', [$storyId]);

        return ['ok' => true, 'story' => $this->getStory($storyId)];
    }

    public function saveBeats(int $storyId, array $beats): array
    {
        $story = DB::fetch('SELECT * FROM short_stories WHERE id = ?', [$storyId]);
        if (!$story) return ['ok' => false, 'error' => '短篇小说不存在'];

        DB::update('short_stories', [
            'beats_json' => json_encode($beats, JSON_UNESCAPED_UNICODE),
            'status'     => 'beats_ready',
        ], 'id = ?', [$storyId]);

        return ['ok' => true, 'story' => $this->getStory($storyId)];
    }

    public function saveGeneratedDraft(int $storyId, string $content, string $note = 'AI生成初稿'): array
    {
        $story = DB::fetch('SELECT * FROM short_stories WHERE id = ?', [$storyId]);
        if (!$story) return ['ok' => false, 'error' => '短篇小说不存在'];
        if ((int)($story['chapter_count'] ?? 1) >= 2) {
            return ['ok' => false, 'error' => '分章模式请使用写本章或连续写作'];
        }

        $versionSaved = false;
        $oldContent = $story['content'] ?? '';
        if (mb_strlen(trim($oldContent)) > 0) {
            $this->createVersion($storyId, $story, $note);
            $versionSaved = true;
        }

        DB::update('short_stories', [
            'content' => $content,
            'status'  => 'written',
        ], 'id = ?', [$storyId]);

        return ['ok' => true, 'story' => $this->getStory($storyId), 'version_saved' => $versionSaved];
    }

    public function updateStatus(int $storyId, string $newStatus): array
    {
        $story = DB::fetch('SELECT id, status FROM short_stories WHERE id = ?', [$storyId]);
        if (!$story) return ['ok' => false, 'error' => '短篇小说不存在'];

        $allowed = self::STATUS_FLOW[$story['status']] ?? [];
        if (!in_array($newStatus, $allowed, true) && $newStatus !== $story['status']) {
            return ['ok' => false, 'error' => "不允许从 {$story['status']} 转换到 {$newStatus}"];
        }

        DB::update('short_stories', ['status' => $newStatus], 'id = ?', [$storyId]);
        return ['ok' => true, 'story' => $this->getStory($storyId)];
    }

    public function saveQualityReport(int $storyId, array $report): array
    {
        $story = DB::fetch('SELECT id FROM short_stories WHERE id = ?', [$storyId]);
        if (!$story) return ['ok' => false, 'error' => '短篇小说不存在'];

        DB::update('short_stories', [
            'quality_score'  => $report['score'] ?? null,
            'quality_report' => json_encode($report, JSON_UNESCAPED_UNICODE),
        ], 'id = ?', [$storyId]);

        return ['ok' => true];
    }

    public function deleteStory(int $storyId): array
    {
        $story = DB::fetch('SELECT id FROM short_stories WHERE id = ?', [$storyId]);
        if (!$story) return ['ok' => false, 'error' => '短篇小说不存在'];

        DB::execute('DELETE FROM short_story_versions WHERE story_id = ?', [$storyId]);
        DB::execute('DELETE FROM short_stories WHERE id = ?', [$storyId]);
        return ['ok' => true];
    }

    public function listVersions(int $storyId): array
    {
        return DB::fetchAll(
            'SELECT id, story_id, version, title, note, quality_score, created_at
             FROM short_story_versions
             WHERE story_id = ?
             ORDER BY version DESC',
            [$storyId]
        );
    }

    public function getVersion(int $versionId): ?array
    {
        return DB::fetch('SELECT * FROM short_story_versions WHERE id = ?', [$versionId]);
    }

    public function rollbackVersion(int $storyId, int $versionId): array
    {
        $pdo = DB::connect();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $story = DB::fetch('SELECT * FROM short_stories WHERE id = ?' . $lock, [$storyId]);
            $ver = DB::fetch('SELECT * FROM short_story_versions WHERE id = ? AND story_id = ?', [$versionId, $storyId]);
            if (!$story || !$ver) {
                if ($ownTransaction) $pdo->rollBack();
                return ['ok' => false, 'error' => !$story ? '短篇小说不存在' : '版本不存在'];
            }

            $update = ['content' => $ver['content'] ?? ''];
            if ($ver['brief_json']) $update['brief_json'] = $ver['brief_json'];
            if ($ver['beats_json']) $update['beats_json'] = $ver['beats_json'];
            $versionChapters = json_decode((string)($ver['chapters_json'] ?? ''), true);
            if ((int)($story['chapter_count'] ?? 1) >= 2) {
                if (!is_array($versionChapters) || count($versionChapters) < 2) {
                    if ($ownTransaction) $pdo->rollBack();
                    return ['ok' => false, 'error' => '此历史版本没有完整分章数据，不能覆盖当前分章正文'];
                }
                $normalized = $this->normalizeChapters($versionChapters);
                $update['chapters_json'] = json_encode($normalized, JSON_UNESCAPED_UNICODE);
                $update['content'] = $this->mergeChapters($normalized);
            } elseif (!empty($ver['chapters_json'])) {
                $update['chapters_json'] = $ver['chapters_json'];
            }
            $this->createVersion($storyId, $story, '回滚前自动保存');
            DB::update('short_stories', $update, 'id = ?', [$storyId]);
            if ($ownTransaction) $pdo->commit();
        } catch (Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        return ['ok' => true, 'story' => $this->getStory($storyId)];
    }

    private function createVersion(int $storyId, array $story, string $note): void
    {
        $maxVer = DB::fetch(
            'SELECT MAX(version) AS v FROM short_story_versions WHERE story_id = ?',
            [$storyId]
        );
        $nextVer = ((int)($maxVer['v'] ?? 0)) + 1;

        DB::insert('short_story_versions', [
            'story_id'      => $storyId,
            'version'       => $nextVer,
            'title'         => $story['title'] ?? null,
            'content'       => $story['content'] ?? null,
            'brief_json'    => $story['brief_json'] ?? null,
            'beats_json'    => $story['beats_json'] ?? null,
            'chapters_json' => $story['chapters_json'] ?? null,
            'quality_score' => $story['quality_score'] ?? null,
            'note'          => $note,
        ]);
    }

    private function contentWordCount(array $story): int
    {
        $content = (string)($story['content'] ?? '');
        if ($content === '') return 0;
        return mb_strlen(preg_replace('/\s+/u', '', $content));
    }

    /** Select the only prose the polish operation is allowed to replace. */
    public function getPolishTarget(array $story, ?int $chapterIndex): array
    {
        if ((int)($story['chapter_count'] ?? 1) < 2) {
            if ($chapterIndex !== null) throw new InvalidArgumentException('单篇模式不接受章节参数');
            return $story;
        }
        $chapters = $story['chapters_json'] ?? [];
        if ($chapterIndex === null || $chapterIndex < 0 || !isset($chapters[$chapterIndex])) {
            throw new InvalidArgumentException('分章模式请先选择要润色的当前章节');
        }
        $chapter = $chapters[$chapterIndex];
        $story['content'] = (string)($chapter['content'] ?? '');
        $budget = (int)($chapter['word_budget'] ?? 0);
        $story['target_words'] = $budget > 0 ? $budget : max(500, $this->contentWordCount($chapter));
        return $story;
    }

    public function saveChapters(int $storyId, array $chapters): array
    {
        $pdo = DB::connect();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $story = DB::fetch('SELECT * FROM short_stories WHERE id = ?' . $lock, [$storyId]);
            if (!$story) {
                if ($ownTransaction) $pdo->rollBack();
                return ['ok' => false, 'error' => '短篇小说不存在'];
            }
            if ((int)($story['chapter_count'] ?? 1) < 2) {
                if ($ownTransaction) $pdo->rollBack();
                return ['ok' => false, 'error' => '单篇模式不接受章节大纲'];
            }
            $normalized = $this->normalizeChapters($chapters);
            // Read the current chapter prose under the same lock used by
            // chapter saves so outline edits cannot overwrite a newer chapter.
            $existing = json_decode((string)($story['chapters_json'] ?? ''), true) ?: [];
            foreach ($existing as $index => $oldChapter) {
                if (trim((string)($oldChapter['content'] ?? '')) === '') continue;
                if (!isset($normalized[$index])) {
                    if ($ownTransaction) $pdo->rollBack();
                    return ['ok' => false, 'error' => '不能通过修改大纲删除已有正文的章节'];
                }
                $normalized[$index]['content'] = (string)$oldChapter['content'];
                $normalized[$index]['status'] = (string)($oldChapter['status'] ?? 'written');
            }
            if (trim((string)($story['content'] ?? '')) !== '') {
                $this->createVersion($storyId, $story, '保存章节大纲');
            }
            DB::update('short_stories', [
                'chapters_json' => json_encode($normalized, JSON_UNESCAPED_UNICODE),
                'content' => $this->mergeChapters($normalized),
            ], 'id = ?', [$storyId]);
            if ($ownTransaction) $pdo->commit();
        } catch (Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        return ['ok' => true, 'story' => $this->getStory($storyId)];
    }

    public function updateChapterContent(int $storyId, int $chapterIndex, string $content, string $note = '保存章节正文'): array
    {
        $pdo = DB::connect();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $story = DB::fetch('SELECT * FROM short_stories WHERE id = ?' . $lock, [$storyId]);
            if (!$story) {
                if ($ownTransaction) $pdo->rollBack();
                return ['ok' => false, 'error' => '短篇小说不存在'];
            }

            $chapters = json_decode((string)($story['chapters_json'] ?? ''), true) ?: [];
            if ((int)($story['chapter_count'] ?? 1) < 2 || $chapterIndex < 0 || !isset($chapters[$chapterIndex])) {
                if ($ownTransaction) $pdo->rollBack();
                return ['ok' => false, 'error' => '章节不存在,请先生成章节大纲'];
            }

            if ((string)($chapters[$chapterIndex]['content'] ?? '') !== $content
                && trim((string)($story['content'] ?? '')) !== '') {
                $this->createVersion($storyId, $story, $note);
            }
            $chapters[$chapterIndex]['content'] = $content;
            $chapters[$chapterIndex]['status'] = mb_strlen(trim($content)) > 0 ? 'written' : 'pending';

            $merged = $this->mergeChapters($chapters);
            $allWritten = $this->allChaptersWritten($chapters);

            $update = [
                'chapters_json' => json_encode($chapters, JSON_UNESCAPED_UNICODE),
                'content'       => $merged,
            ];
            if ($allWritten && in_array($story['status'], ['draft', 'brief_ready', 'beats_ready'], true)) {
                $update['status'] = 'written';
            }
            DB::update('short_stories', $update, 'id = ?', [$storyId]);
            if ($ownTransaction) $pdo->commit();
        } catch (Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        return ['ok' => true, 'story' => $this->getStory($storyId)];
    }

    public function getChapterContext(array $story, int $chapterIndex): array
    {
        $chapters = $story['chapters_json'] ?? [];
        $tails = [];
        for ($i = max(0, $chapterIndex - 2); $i < $chapterIndex; $i++) {
            $c = $chapters[$i] ?? null;
            if (!$c) continue;
            $cnt = trim((string)($c['content'] ?? ''));
            if ($cnt === '') continue;
            $tails[] = [
                'order' => (int)($c['order'] ?? ($i + 1)),
                'title' => (string)($c['title'] ?? ''),
                'tail'  => mb_substr($cnt, max(0, mb_strlen($cnt) - 500)),
            ];
        }
        return $tails;
    }

    private function normalizeChapters(array $chapters): array
    {
        $out = [];
        $i = 0;
        foreach ($chapters as $c) {
            if (!is_array($c)) continue;
            $out[] = [
                'order'       => (int)($c['order'] ?? ($i + 1)),
                'title'       => (string)($c['title'] ?? ('第' . ($i + 1) . '章')),
                'synopsis'    => (string)($c['synopsis'] ?? ''),
                'beat_refs'   => $c['beat_refs'] ?? [],
                'word_budget' => (int)($c['word_budget'] ?? 0),
                'content'     => (string)($c['content'] ?? ''),
                'status'      => (string)($c['status'] ?? (mb_strlen(trim((string)($c['content'] ?? ''))) > 0 ? 'written' : 'pending')),
            ];
            $i++;
        }
        return $out;
    }

    private function mergeChapters(array $chapters): string
    {
        $parts = [];
        foreach ($chapters as $c) {
            $title = trim((string)($c['title'] ?? ''));
            $content = trim((string)($c['content'] ?? ''));
            if ($content === '') continue;
            if ($title !== '') {
                $parts[] = $title;
            }
            $parts[] = $content;
        }
        return implode("\n\n", $parts);
    }

    private function allChaptersWritten(array $chapters): bool
    {
        if (empty($chapters)) return false;
        foreach ($chapters as $c) {
            if (mb_strlen(trim((string)($c['content'] ?? ''))) === 0) return false;
        }
        return true;
    }
}
