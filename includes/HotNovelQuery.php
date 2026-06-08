<?php
defined('APP_LOADED') or die('Direct access denied.');

/**
 * 热门选题查询层（前端 list / summary / detail）
 */
class HotNovelQuery
{
    /** 列表查询（带过滤） */
    public function list(array $filter, int $page = 1, int $pageSize = 24): array
    {
        $where = []; $params = [];
        if (!empty($filter['source']) && $filter['source'] !== 'all') {
            $where[] = 'source = ?'; $params[] = $filter['source'];
        }
        if (!empty($filter['channel']) && $filter['channel'] !== 'all') {
            $where[] = 'channel = ?'; $params[] = $filter['channel'];
        }
        if (!empty($filter['category']) && $filter['category'] !== 'all') {
            $where[] = 'normalized_category = ?'; $params[] = $filter['category'];
        }
        if (!empty($filter['rank_type']) && $filter['rank_type'] !== 'all') {
            $where[] = 'rank_type = ?'; $params[] = $filter['rank_type'];
        }
        if (!empty($filter['days'])) {
            $where[] = 'collected_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
            $params[] = (int)$filter['days'];
        }
        if (!empty($filter['keyword'])) {
            $where[] = '(title LIKE ? OR author LIKE ?)';
            $kw = '%' . $filter['keyword'] . '%';
            $params[] = $kw; $params[] = $kw;
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = (int)DB::fetch("SELECT COUNT(*) AS c FROM hot_novels $whereSql", $params)['c'];

        $offset = max(0, ($page - 1) * $pageSize);
        $sql = "SELECT id, source, title, author, raw_category, normalized_category, channel,
                       tags, hotness_score, rank_no, rank_type, intro, cover_url, source_url,
                       collected_at, confidence_score, last_seen_at
                FROM hot_novels $whereSql
                ORDER BY hotness_score DESC, last_seen_at DESC
                LIMIT $pageSize OFFSET $offset";
        $rows = DB::fetchAll($sql, $params);
        foreach ($rows as &$r) {
            $r['tags'] = $r['tags'] ? json_decode($r['tags'], true) : [];
        }
        unset($r);

        return [
            'items'      => $rows,
            'total'      => $total,
            'page'       => $page,
            'page_size'  => $pageSize,
            'total_page' => (int)ceil($total / $pageSize),
        ];
    }

    /** 各 source 计数（顶部 tab 角标） */
    public function sourceCounts(array $filter = []): array
    {
        $where = []; $params = [];
        if (!empty($filter['days'])) {
            $where[] = 'collected_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
            $params[] = (int)$filter['days'];
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $rows = DB::fetchAll("SELECT source, COUNT(*) AS c FROM hot_novels $whereSql GROUP BY source", $params);
        $out = ['qidian' => 0, 'fanqie' => 0, 'zongheng' => 0, 'qimao' => 0];
        $totalAll = 0;
        foreach ($rows as $r) { $out[$r['source']] = (int)$r['c']; $totalAll += (int)$r['c']; }
        $out['all'] = $totalAll;
        return $out;
    }

    /** 看板数据 */
    public function summary(array $filter = []): array
    {
        $where = []; $params = [];
        if (!empty($filter['source']) && $filter['source'] !== 'all') {
            $where[] = 'source = ?'; $params[] = $filter['source'];
        }
        if (!empty($filter['days'])) {
            $where[] = 'collected_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
            $params[] = (int)$filter['days'];
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // 最新批次
        $latestWhere = []; $latestParams = [];
        if (!empty($filter['source']) && $filter['source'] !== 'all') {
            $latestWhere[] = 'source = ?'; $latestParams[] = $filter['source'];
        }
        $latestWhereSql = $latestWhere ? 'WHERE ' . implode(' AND ', $latestWhere) : '';
        $latestBatch = DB::fetch(
            "SELECT batch_id, source, accepted, updated, prepared_items, created_at
             FROM hot_novel_batches $latestWhereSql ORDER BY created_at DESC LIMIT 1",
            $latestParams
        );

        // 题材分布
        if (empty($where)) {
            $catRows = DB::fetchAll(
                "SELECT normalized_category, COUNT(*) AS c FROM hot_novels
                 WHERE normalized_category IS NOT NULL
                 GROUP BY normalized_category ORDER BY c DESC LIMIT 20"
            );
        } else {
            $catRows = DB::fetchAll(
                "SELECT normalized_category, COUNT(*) AS c FROM hot_novels
                 $whereSql AND normalized_category IS NOT NULL
                 GROUP BY normalized_category ORDER BY c DESC LIMIT 20",
                $params
            );
        }

        $catTotal = array_sum(array_column($catRows, 'c'));
        $categoryDist = [];
        foreach ($catRows as $r) {
            $share = $catTotal > 0 ? round($r['c'] / $catTotal, 4) : 0;
            $level = $share >= 0.20 ? 'red'
                : ($share >= 0.10 ? 'mainstream'
                : ($share >= 0.04 ? 'normal' : 'blue'));
            $categoryDist[] = [
                'category'    => $r['normalized_category'],
                'count'       => (int)$r['c'],
                'share'       => $share,
                'competition' => $level,
            ];
        }

        // TOP 关键词（从 tags + raw_category 聚合）
        $tagWhere = $whereSql;
        $kwRows = DB::fetchAll(
            "SELECT tags, raw_category FROM hot_novels $tagWhere LIMIT 500",
            $params
        );
        $stopWords = ['小说','最新','免费','阅读','章节','作者','作品','连载','完结','更新'];
        $kwCounts = [];
        foreach ($kwRows as $r) {
            $tags = $r['tags'] ? json_decode($r['tags'], true) : [];
            if (is_array($tags)) {
                foreach ($tags as $t) {
                    $t = trim((string)$t);
                    if ($t === '' || in_array($t, $stopWords, true)) continue;
                    $kwCounts[$t] = ($kwCounts[$t] ?? 0) + 1;
                }
            }
            $c = trim((string)($r['raw_category'] ?? ''));
            if ($c !== '' && !in_array($c, $stopWords, true)) {
                $kwCounts[$c] = ($kwCounts[$c] ?? 0) + 1;
            }
        }
        arsort($kwCounts);
        $topKeywords = [];
        $i = 0;
        foreach ($kwCounts as $kw => $c) {
            if ($i++ >= 15) break;
            $topKeywords[] = ['keyword' => $kw, 'count' => $c];
        }

        // 近 14 天推送趋势
        $trendWhere = []; $trendParams = [];
        if (!empty($filter['source']) && $filter['source'] !== 'all') {
            $trendWhere[] = 'source = ?'; $trendParams[] = $filter['source'];
        }
        $trendWhereSql = $trendWhere ? 'AND ' . implode(' AND ', $trendWhere) : '';
        $trendRows = DB::fetchAll(
            "SELECT DATE(created_at) AS d, SUM(accepted) AS a, SUM(`updated`) AS u
             FROM hot_novel_batches
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) $trendWhereSql
             GROUP BY DATE(created_at) ORDER BY d ASC",
            $trendParams
        );

        // 入库总数（近 7 天）
        $recentRows = DB::fetchAll(
            "SELECT normalized_category, COUNT(*) AS c FROM hot_novels
             WHERE last_seen_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY normalized_category ORDER BY c DESC LIMIT 3"
        );
        $recent7Total = (int)(DB::fetch(
            "SELECT COUNT(*) AS c FROM hot_novels WHERE last_seen_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        )['c'] ?? 0);
        $recentTrend = [
            'total_7d'   => $recent7Total,
            'top_rising' => array_map(fn($r) => ['category' => $r['normalized_category'], 'count' => (int)$r['c']], $recentRows),
        ];

        return [
            'latest_batch' => $latestBatch,
            'top_keywords' => $topKeywords,
            'category_dist'=> $categoryDist,
            'push_trend'   => array_map(fn($r) => [
                'date'     => $r['d'],
                'accepted' => (int)$r['a'],
                'updated'  => (int)$r['u'],
            ], $trendRows),
            'recent_trend' => $recentTrend,
        ];
    }

    /** 单本详情（含 analysis） */
    public function get(int $novelId): ?array
    {
        $novel = DB::fetch('SELECT * FROM hot_novels WHERE id = ?', [$novelId]);
        if (!$novel) return null;
        $novel['tags'] = $novel['tags'] ? json_decode($novel['tags'], true) : [];
        $novel['extra_rank_types'] = $novel['extra_rank_types'] ? json_decode($novel['extra_rank_types'], true) : [];

        $analysis = DB::fetch('SELECT * FROM hot_novel_analysis WHERE novel_id = ?', [$novelId]);
        if ($analysis) {
            $analysis['benchmarks'] = $analysis['benchmarks'] ? json_decode($analysis['benchmarks'], true) : [];
            $analysis['evidence']   = $analysis['evidence']   ? json_decode($analysis['evidence'], true)   : null;
        }
        $novel['analysis'] = $analysis ?: null;

        return $novel;
    }

    /** 榜单类型列表（用于筛选下拉） */
    public function rankTypes(?string $source = null): array
    {
        $sql = "SELECT DISTINCT rank_type FROM hot_novels WHERE rank_type != ''";
        $params = [];
        if ($source && $source !== 'all') {
            $sql .= ' AND source = ?';
            $params[] = $source;
        }
        $sql .= ' ORDER BY rank_type';
        $rows = DB::fetchAll($sql, $params);
        return array_column($rows, 'rank_type');
    }
}
