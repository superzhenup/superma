<?php
/**
 * StatsTracker - 使用统计追踪器
 *
 * 功能：
 * 1. 记录每次写作完成的字数增量
 * 2. 每日汇总并上报到远程服务器
 * 3. 上报完成后清空当日数据
 */

defined('APP_LOADED') or die('Direct access denied.');

class StatsTracker
{
    private string $table = 'usage_stats';
    private static bool $tableEnsured = false;

    private static function ensureTable(): void
    {
        if (self::$tableEnsured) return;
        self::$tableEnsured = true;
        try {
            $check = DB::fetch("SHOW TABLES LIKE 'usage_stats'");
            if (empty($check)) {
                DB::execute("CREATE TABLE IF NOT EXISTS `usage_stats` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `stat_date` DATE NOT NULL,
                    `words_added` INT UNSIGNED NOT NULL DEFAULT 0,
                    `chapters_added` INT UNSIGNED NOT NULL DEFAULT 0,
                    `novels_active` INT UNSIGNED NOT NULL DEFAULT 0,
                    `reported_at` DATETIME DEFAULT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `uk_stat_date` (`stat_date`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                error_log('StatsTracker: auto-created usage_stats table');
            }
        } catch (\Throwable $e) {
            error_log('StatsTracker::ensureTable failed: ' . $e->getMessage());
        }
    }

    /**
     * 记录写作完成的字数增量
     */
    public static function record(int $wordsAdded, int $chaptersAdded = 1): void
    {
        if (!self::isEnabled()) {
            return;
        }

        self::ensureTable();
        $today = date('Y-m-d');

        try {
            $existing = DB::fetch(
                "SELECT id, words_added, chapters_added FROM usage_stats WHERE stat_date = ?",
                [$today]
            );

            if ($existing) {
                DB::update('usage_stats', [
                    'words_added' => $existing['words_added'] + $wordsAdded,
                    'chapters_added' => $existing['chapters_added'] + $chaptersAdded,
                    'reported_at' => null,
                ], 'id = ?', [$existing['id']]);
            } else {
                DB::insert('usage_stats', [
                    'stat_date' => $today,
                    'words_added' => $wordsAdded,
                    'chapters_added' => $chaptersAdded,
                    'novels_active' => 1,
                ]);
            }
        } catch (\Throwable $e) {
            error_log('StatsTracker::record failed: ' . $e->getMessage());
        }
    }

    /**
     * 获取待上报的统计数据
     */
    public static function getPendingStats(): array
    {
        self::ensureTable();
        $today = date('Y-m-d');

        try {
            $row = DB::fetch(
                "SELECT * FROM usage_stats WHERE stat_date = ? AND reported_at IS NULL",
                [$today]
            );

            return $row ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 上报统计数据到远程服务器
     * 直接从 novels 表取真实总字数（MAX total_words）和已完成章节数
     */
    public static function report(): array
    {
        if (!self::isEnabled()) {
            return ['success' => false, 'message' => 'Stats reporting disabled'];
        }

        $stats = self::getPendingStats();
        if (empty($stats)) {
            return ['success' => true, 'message' => 'No pending stats'];
        }

        $serverUrl = self::getServerUrl();
        if (empty($serverUrl)) {
            return ['success' => false, 'message' => 'Server URL not configured'];
        }

        $siteId = self::getSiteId();

        $totalWords = 0;
        $totalChapters = 0;
        $novelsActive = 0;
        try {
            $totalWords = (int)DB::fetchColumn("SELECT COALESCE(MAX(total_words), 0) FROM novels");
            $totalChapters = (int)DB::fetchColumn("SELECT COUNT(*) FROM chapters WHERE status = 'completed'");
            $novelsActive = (int)DB::fetchColumn("SELECT COUNT(*) FROM novels WHERE status IN ('writing','active')");
        } catch (\Throwable $e) {
            $totalWords = (int)$stats['words_added'];
            $totalChapters = (int)$stats['chapters_added'];
            $novelsActive = 1;
        }

        $payload = [
            'site_id' => $siteId,
            'date' => $stats['stat_date'],
            'words_added' => $totalWords,
            'chapters_added' => $totalChapters,
            'novels_active' => $novelsActive,
            'version' => '1.5',
        ];

        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $serverUrl,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'User-Agent: Super-Ma-Novel-System/1.5',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                return ['success' => false, 'message' => 'cURL error: ' . $error];
            }

            if ($httpCode >= 200 && $httpCode < 300) {
                // 上报成功，标记为已上报
                DB::update('usage_stats', [
                    'reported_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$stats['id']]);

                return ['success' => true, 'message' => 'Reported successfully', 'payload' => $payload];
            }

            return ['success' => false, 'message' => "HTTP {$httpCode}: {$response}"];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * v1.11.8: 实时上报（每次访问 novel.php 都上报）
     *
     * 与 report() 不同，此方法：
     * 1. 不检查是否已上报过
     * 2. 直接从 novels 表取实时数据
     * 3. 异步发送，不阻塞页面
     *
     * @param array|null $novel 当前小说数据（可选，用于获取额外信息）
     */
    public static function reportRealtime(?array $novel = null): void
    {
        if (!self::isEnabled()) {
            error_log('StatsTracker::reportRealtime skipped: disabled');
            return;
        }

        $serverUrl = self::getServerUrl();
        if (empty($serverUrl)) {
            error_log('StatsTracker::reportRealtime skipped: no server URL');
            return;
        }

        error_log('StatsTracker::reportRealtime starting... URL=' . $serverUrl);

        try {
            // 获取全站实时数据
            $totalWords = (int)DB::fetchColumn("SELECT COALESCE(SUM(total_words), 0) FROM novels");
            $totalChapters = (int)DB::fetchColumn("SELECT COUNT(*) FROM chapters WHERE status = 'completed'");
            $totalNovels = (int)DB::fetchColumn("SELECT COUNT(*) FROM novels");
            $novelsActive = (int)DB::fetchColumn("SELECT COUNT(*) FROM novels WHERE status = 'writing'");

            // 当前小说数据（如果有）
            $novelId = $novel['id'] ?? 0;
            $novelWords = (int)($novel['total_words'] ?? 0);
            $novelChapters = (int)($novel['current_chapter'] ?? 0);

            $payload = [
                'site_id' => self::getSiteId(),
                'date' => date('Y-m-d'),
                // 服务器期望的字段名
                'words_added' => $totalWords,
                'chapters_added' => $totalChapters,
                'novels_active' => $novelsActive,
                'version' => '1.5',
            ];

            error_log('StatsTracker::reportRealtime payload: ' . json_encode($payload, JSON_UNESCAPED_UNICODE));

            // 异步发送（不阻塞页面）
            self::sendAsync($serverUrl, $payload);

            // 同时更新本地 usage_stats 表（用于备份和调试）
            self::updateLocalStats($totalWords, $totalChapters, $totalNovels);

        } catch (\Throwable $e) {
            error_log('StatsTracker::reportRealtime failed: ' . $e->getMessage());
        }
    }

    /**
     * 异步发送统计数据（非阻塞）
     */
    private static function sendAsync(string $url, array $payload): void
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: Super-Ma-Novel-System/1.5',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_NOSIGNAL => true,  // 避免信号干扰
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // v1.11.8: 记录发送结果用于调试
        if ($error) {
            error_log('StatsTracker::sendAsync curl error: ' . $error);
        } else {
            error_log(sprintf('StatsTracker::sendAsync success: HTTP %d, response=%s', $httpCode, mb_substr($response ?? '', 0, 200)));
        }
    }

    /**
     * 更新本地统计表
     */
    private static function updateLocalStats(int $totalWords, int $totalChapters, int $totalNovels): void
    {
        self::ensureTable();
        $today = date('Y-m-d');

        try {
            $existing = DB::fetch(
                "SELECT id FROM usage_stats WHERE stat_date = ?",
                [$today]
            );

            if ($existing) {
                DB::update('usage_stats', [
                    'words_added' => $totalWords,
                    'chapters_added' => $totalChapters,
                    'novels_active' => $totalNovels,
                    'reported_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$existing['id']]);
            } else {
                DB::insert('usage_stats', [
                    'stat_date' => $today,
                    'words_added' => $totalWords,
                    'chapters_added' => $totalChapters,
                    'novels_active' => $totalNovels,
                    'reported_at' => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) {
            error_log('StatsTracker::updateLocalStats failed: ' . $e->getMessage());
        }
    }

    /**
     * 清理已上报的历史数据（保留7天）
     */
    public static function cleanup(): int
    {
        try {
            $cutoff = date('Y-m-d', strtotime('-7 days'));
            $count = DB::affecting(
                "DELETE FROM usage_stats WHERE reported_at IS NOT NULL AND stat_date < ?",
                [$cutoff]
            );
            return $count;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 检查是否应该上报（每天一次）
     */
    public static function shouldReport(): bool
    {
        if (!self::isEnabled()) {
            return false;
        }

        self::ensureTable();
        $today = date('Y-m-d');

        try {
            $row = DB::fetch(
                "SELECT reported_at FROM usage_stats WHERE stat_date = ?",
                [$today]
            );

            // 有数据且今天还没上报过
            return $row && empty($row['reported_at']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 获取统计开关状态
     */
    public static function isEnabled(): bool
    {
        return defined('STATS_REPORT_ENABLED') && STATS_REPORT_ENABLED === true;
    }

    /**
     * 获取上报服务器地址
     */
    public static function getServerUrl(): string
    {
        return defined('STATS_SERVER_URL') ? STATS_SERVER_URL : '';
    }

    /**
     * 获取站点唯一标识
     */
    public static function getSiteId(): string
    {
        if (defined('STATS_SITE_ID') && STATS_SITE_ID) {
            return STATS_SITE_ID;
        }

        // 基于域名生成唯一ID
        $domain = $_SERVER['SERVER_NAME'] ?? 'localhost';
        return substr(md5($domain), 0, 16);
    }

    /**
     * 获取统计摘要（用于展示）
     */
    public static function getSummary(): array
    {
        $today = date('Y-m-d');
        $thisMonth = date('Y-m');

        try {
            $todayStats = DB::fetch(
                "SELECT words_added, chapters_added FROM usage_stats WHERE stat_date = ?",
                [$today]
            ) ?: ['words_added' => 0, 'chapters_added' => 0];

            $monthStats = DB::fetch(
                "SELECT SUM(words_added) as words, SUM(chapters_added) as chapters FROM usage_stats WHERE stat_date LIKE ?",
                [$thisMonth . '%']
            ) ?: ['words' => 0, 'chapters' => 0];

            $totalStats = DB::fetch(
                "SELECT SUM(words_added) as words, SUM(chapters_added) as chapters FROM usage_stats"
            ) ?: ['words' => 0, 'chapters' => 0];

            return [
                'today' => [
                    'words' => (int)($todayStats['words_added'] ?? 0),
                    'chapters' => (int)($todayStats['chapters_added'] ?? 0),
                ],
                'month' => [
                    'words' => (int)($monthStats['words'] ?? 0),
                    'chapters' => (int)($monthStats['chapters'] ?? 0),
                ],
                'total' => [
                    'words' => (int)($totalStats['words'] ?? 0),
                    'chapters' => (int)($totalStats['chapters'] ?? 0),
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'today' => ['words' => 0, 'chapters' => 0],
                'month' => ['words' => 0, 'chapters' => 0],
                'total' => ['words' => 0, 'chapters' => 0],
            ];
        }
    }
}
