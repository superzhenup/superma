<?php

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/db.php';

/**
 * 漫剧项目 / 剧集 / 资产 / 分镜的数据访问与归属校验（Drama Studio v1.8）。
 */
final class DramaService
{
    // ---------------------------------------------------------------- 项目

    public static function getProjectByNovel(int $novelId): ?array
    {
        $row = DB::fetch('SELECT * FROM drama_projects WHERE novel_id=? LIMIT 1', [$novelId]);
        return is_array($row) ? $row : null;
    }

    public static function getProject(int $projectId): ?array
    {
        $row = DB::fetch('SELECT * FROM drama_projects WHERE id=? LIMIT 1', [$projectId]);
        return is_array($row) ? $row : null;
    }

    /**
     * 列出用户名下全部漫剧项目（关联小说标题与剧集数，用于「漫剧项目」总入口页）。
     * 仅返回当前用户归属的项目，按 updated_at 倒序。
     */
    public static function listProjectsByUser(int $userId): array
    {
        $sql = 'SELECT p.*, n.title AS novel_title, n.status AS novel_status,
                       (SELECT COUNT(*) FROM drama_episodes e WHERE e.project_id = p.id) AS episode_count
                FROM drama_projects p
                LEFT JOIN novels n ON n.id = p.novel_id
                WHERE p.user_id = ?
                ORDER BY p.updated_at DESC, p.id DESC';
        return DB::fetchAll($sql, [$userId]);
    }

    /** 获取或自动创建小说对应的漫剧项目（小说 1:1 项目）。 */
    public static function getOrCreateProject(int $novelId, int $userId): array
    {
        $existing = self::getProjectByNovel($novelId);
        if ($existing) return $existing;

        $novel = DB::fetch('SELECT id, title FROM novels WHERE id=?', [$novelId]);
        if (!$novel) throw new RuntimeException('小说不存在');

        DB::insert('drama_projects', [
            'novel_id' => $novelId,
            'user_id'  => $userId,
            'title'    => (string)$novel['title'],
        ]);
        $project = self::getProjectByNovel($novelId);
        if (!$project) throw new RuntimeException('漫剧项目创建失败');
        return $project;
    }

    /**
     * 归属校验：项目 → novel → checkNovelOwnership。返回项目行。
     */
    public static function assertProjectAccess(int $projectId, int $userId): array
    {
        $project = self::getProject($projectId);
        if (!$project) {
            http_response_code(404);
            throw new RuntimeException('漫剧项目不存在');
        }
        checkNovelOwnership((int)$project['novel_id'], $userId);
        return $project;
    }

    /** 归属校验：剧集 → 项目 → novel。返回 [episode, project]。 */
    public static function assertEpisodeAccess(int $episodeId, int $userId): array
    {
        $episode = self::getEpisode($episodeId);
        if (!$episode) {
            http_response_code(404);
            throw new RuntimeException('剧集不存在');
        }
        $project = self::assertProjectAccess((int)$episode['project_id'], $userId);
        return [$episode, $project];
    }

    public static function updateProject(int $projectId, array $fields): void
    {
        $allowed = ['title', 'style_prompt', 'style_negative', 'image_size', 'status'];
        $data = array_intersect_key($fields, array_flip($allowed));
        if (isset($data['image_size']) && !preg_match('/^(\d{2,4})x(\d{2,4})$/', (string)$data['image_size'])) {
            throw new RuntimeException('分镜图尺寸格式无效');
        }
        if ($data) {
            DB::update('drama_projects', $data, 'id=?', [$projectId]);
        }
    }

    // ---------------------------------------------------------------- 剧集

    public static function listEpisodes(int $projectId): array
    {
        return DB::fetchAll(
            'SELECT * FROM drama_episodes WHERE project_id=? ORDER BY episode_no ASC',
            [$projectId]
        );
    }

    public static function getEpisode(int $episodeId): ?array
    {
        $row = DB::fetch('SELECT * FROM drama_episodes WHERE id=? LIMIT 1', [$episodeId]);
        return is_array($row) ? $row : null;
    }

    /**
     * 新建剧集：自动分配集号，快照章节正文。
     */
    public static function createEpisode(int $projectId, int $chapterStart, int $chapterEnd, string $title = ''): array
    {
        $project = self::getProject($projectId);
        if (!$project) throw new RuntimeException('漫剧项目不存在');
        if ($chapterStart < 1 || $chapterEnd < $chapterStart) {
            throw new RuntimeException('章节范围无效');
        }

        $chapters = DB::fetchAll(
            'SELECT chapter_number, title, content FROM chapters
             WHERE novel_id=? AND chapter_number BETWEEN ? AND ? AND status="completed"
             ORDER BY chapter_number ASC',
            [(int)$project['novel_id'], $chapterStart, $chapterEnd]
        );
        if (!$chapters) {
            throw new RuntimeException('所选章节范围没有已完成正文的章节');
        }

        $snapshot = '';
        foreach ($chapters as $ch) {
            $snapshot .= '【第' . (int)$ch['chapter_number'] . '章 ' . (string)$ch['title'] . "】\n"
                . (string)$ch['content'] . "\n\n";
        }
        // 快照上限 5 万字，防止超长连载合并集撑爆 MEDIUMTEXT 与 LLM 上下文
        if (mb_strlen($snapshot) > 50000) {
            $snapshot = mb_substr($snapshot, 0, 50000);
        }

        $nextNo = (int)DB::fetchColumn(
            'SELECT COALESCE(MAX(episode_no),0)+1 FROM drama_episodes WHERE project_id=?',
            [$projectId]
        );
        if ($title === '') {
            $first = $chapters[0];
            $title = '第' . $nextNo . '集';
            if (!empty($first['title'])) $title .= ' ' . (string)$first['title'];
        }

        DB::insert('drama_episodes', [
            'project_id'    => $projectId,
            'episode_no'    => $nextNo,
            'chapter_start' => $chapterStart,
            'chapter_end'   => $chapterEnd,
            'title'         => $title,
            'source_text'   => $snapshot,
        ]);
        $episode = self::getEpisode((int)DB::lastId());
        if (!$episode) throw new RuntimeException('剧集创建失败');
        return $episode;
    }

    /** 删除剧集：级联分镜、任务与磁盘媒体。 */
    public static function deleteEpisode(int $episodeId): void
    {
        $episode = self::getEpisode($episodeId);
        if (!$episode) return;
        $projectId = (int)$episode['project_id'];

        DB::delete('drama_shots', 'episode_id=?', [$episodeId]);
        DB::delete('drama_tasks', 'episode_id=?', [$episodeId]);
        DB::delete('drama_episodes', 'id=?', [$episodeId]);

        self::removeProjectDir($projectId, ['shots/' . $episodeId, 'videos/' . $episodeId]);
    }

    // ---------------------------------------------------------------- 资产

    public static function listAssets(int $projectId, string $type = ''): array
    {
        if ($type !== '') {
            return DB::fetchAll(
                'SELECT * FROM drama_assets WHERE project_id=? AND type=? ORDER BY id ASC',
                [$projectId, $type]
            );
        }
        return DB::fetchAll('SELECT * FROM drama_assets WHERE project_id=? ORDER BY type, id ASC', [$projectId]);
    }

    public static function getAsset(int $assetId): ?array
    {
        $row = DB::fetch('SELECT * FROM drama_assets WHERE id=? LIMIT 1', [$assetId]);
        return is_array($row) ? $row : null;
    }

    public static function findAssetsByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return DB::fetchAll("SELECT * FROM drama_assets WHERE id IN ($placeholders)", $ids);
    }

    /** upsert（project_id + type + name 唯一键）。 */
    public static function upsertAsset(int $projectId, string $type, string $name, string $description, string $source = 'llm'): int
    {
        if (!in_array($type, ['character', 'scene', 'prop'], true)) {
            throw new RuntimeException('资产类型无效');
        }
        $name = mb_substr(trim($name), 0, 100);
        if ($name === '') throw new RuntimeException('资产名称不能为空');

        $existing = DB::fetch(
            'SELECT id FROM drama_assets WHERE project_id=? AND type=? AND name=? LIMIT 1',
            [$projectId, $type, $name]
        );
        if ($existing) {
            DB::update('drama_assets', ['description' => $description], 'id=?', [(int)$existing['id']]);
            return (int)$existing['id'];
        }
        DB::insert('drama_assets', [
            'project_id'  => $projectId,
            'type'        => $type,
            'name'        => $name,
            'description' => $description,
            'source'      => in_array($source, ['llm', 'manual', 'character_card'], true) ? $source : 'llm',
        ]);
        return (int)DB::lastId();
    }

    // ---------------------------------------------------------------- 分镜

    public static function listShots(int $episodeId): array
    {
        return DB::fetchAll('SELECT * FROM drama_shots WHERE episode_id=? ORDER BY shot_no ASC', [$episodeId]);
    }

    public static function getShot(int $shotId): ?array
    {
        $row = DB::fetch('SELECT * FROM drama_shots WHERE id=? LIMIT 1', [$shotId]);
        return is_array($row) ? $row : null;
    }

    /** 整集替换分镜（事务），供 AI 生成后落库。 */
    public static function replaceShots(int $episodeId, array $shots): void
    {
        DB::beginTransaction();
        try {
            DB::delete('drama_shots', 'episode_id=?', [$episodeId]);
            $no = 1;
            foreach ($shots as $shot) {
                DB::insert('drama_shots', [
                    'episode_id'      => $episodeId,
                    'shot_no'         => $no++,
                    'scene_desc'      => (string)($shot['scene_desc'] ?? ''),
                    'shot_type'       => mb_substr((string)($shot['shot_type'] ?? '中景'), 0, 20),
                    'camera_movement' => mb_substr((string)($shot['camera_movement'] ?? '固定'), 0, 20),
                    'characters'      => !empty($shot['characters']) ? json_encode(array_values(array_map('intval', (array)$shot['characters'])), JSON_UNESCAPED_UNICODE) : null,
                    'dialogue'        => (string)($shot['dialogue'] ?? ''),
                    'image_prompt'    => (string)($shot['image_prompt'] ?? ''),
                    'video_prompt'    => (string)($shot['video_prompt'] ?? ''),
                    'duration'        => max(3, min(12, (int)($shot['duration'] ?? 5))),
                ]);
            }
            DB::update('drama_episodes', ['script_status' => 'storyboarded'], 'id=?', [$episodeId]);
            DB::commit();
        } catch (Throwable $e) {
            if (DB::inTransaction()) DB::rollBack();
            throw $e;
        }
    }

    // ---------------------------------------------------------------- 存储

    public static function projectStorageDir(int $projectId): string
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        return $base . '/storage/drama/' . $projectId;
    }

    public static function ensureProjectDirs(int $projectId): void
    {
        foreach (['', '/assets', '/shots', '/videos', '/final'] as $sub) {
            $dir = self::projectStorageDir($projectId) . $sub;
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('无法创建漫剧存储目录，请检查 storage 权限');
            }
        }
    }

    /** 删除项目存储目录下的指定子目录（递归，仅限 storage/drama 内）。 */
    public static function removeProjectDir(int $projectId, array $subDirs): void
    {
        $root = self::projectStorageDir($projectId);
        foreach ($subDirs as $sub) {
            $sub = trim((string)$sub, '/');
            if ($sub === '' || str_contains($sub, '..')) continue;
            $target = $root . '/' . $sub;
            $real = realpath($target);
            $realRoot = realpath($root);
            if ($real === false || $realRoot === false) continue;
            $prefix = strncasecmp($real, $realRoot, strlen($realRoot)) === 0;
            if (!$prefix || $real === $realRoot) continue;
            self::rrmdir($real);
        }
    }

    private static function rrmdir(string $dir): void
    {
        $items = @scandir($dir);
        if ($items === false) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                self::rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
