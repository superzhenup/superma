<?php
defined('APP_LOADED') or die('Direct access denied.');
require_once __DIR__ . '/HotNovelValidator.php';

/**
 * 热门选题业务层：封装四张表的读写与相关配置。
 *   - hot_novels          书数据         → upsertItem()
 *   - hot_novel_analysis  爆款分析       → upsertAnalysis()
 *   - hot_novel_batches   批次台账       → recordBatch()
 *   - hot_novel_nonces    防重放 nonce   → cleanupNonces()（写入在 ingest 端）
 *   - system_settings     配置项         → setting()/setSetting() 及下方各 getter
 */
class HotNovelService
{
    /** 配置读写：system_settings 表的 setting_key → setting_value。 */
    public static function setting(string $key, string $default = ''): string
    {
        $row = DB::fetch('SELECT setting_value FROM system_settings WHERE setting_key = ?', [$key]);
        return $row ? (string)$row['setting_value'] : $default;
    }

    public static function setSetting(string $key, string $value): void
    {
        DB::execute(
            'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$key, $value]
        );
    }

    /** 题材黑名单（hot_novels_unsupported_categories，逗号分隔）→ 命中即 UNSUPPORTED_CATEGORY。 */
    public static function getBlacklistCategories(): array
    {
        $raw = self::setting('hot_novels_unsupported_categories', '奇闻异事,游戏,体育,古风世情');
        $arr = array_map('trim', explode(',', $raw));
        return array_values(array_filter($arr, fn($s) => $s !== ''));
    }

    /** 可信度阈值（hot_novels_min_confidence，默认 50）→ item.confidence_score 低于此值的条目被丢弃。 */
    public static function getMinConfidence(): int
    {
        return (int)self::setting('hot_novels_min_confidence', '50');
    }

    /** 推送密钥（hot_novels_ingest_key）→ 与请求头 X-Ingest-Key 做 hash_equals 比对。 */
    public static function getIngestKey(): string
    {
        return self::setting('hot_novels_ingest_key', '');
    }

    /** 接口开关（hot_novels_ingest_enabled，'1'=开）→ 关闭时 ingest 端返回 INGEST_DISABLED。 */
    public static function isIngestEnabled(): bool
    {
        return self::setting('hot_novels_ingest_enabled', '1') === '1';
    }

    /** 重新生成并保存 64 位 hex 推送密钥（密钥轮换用）。 */
    public static function regenerateIngestKey(): string
    {
        $key = bin2hex(random_bytes(32));
        self::setSetting('hot_novels_ingest_key', $key);
        return $key;
    }

    /**
     * UPSERT 单条 item → hot_novels 表。
     *
     * 字段映射（$item 键 → hot_novels 列）：
     *   title            → title / title_norm（normalize 后，去重键之一）
     *   author           → author / author_norm（normalize 后，去重键之一）
     *   raw_category     → raw_category；并经 classifyChannel() 归一化出
     *                      normalized_category + channel(male/female/general/unknown)
     *   tags[]           → tags（JSON）
     *   word_count / click_count / collect_count / recommend_count → 同名列（缺失存 null）
     *   hotness_score    → hotness_score（缺省 0）
     *   rank_no / rank_type / extra_rank_types[] → 同名列（extra_rank_types 存 JSON）
     *   intro / cover_url / source_url           → 同名列
     *   collected_at     → collected_at（缺省取当前时间）
     *   confidence_score → confidence_score（缺省 0；注意字段名不是 final_score）
     *   source           → source；$batchId → last_batch_id
     *
     * 写入规则（按唯一键 (title_norm, author_norm) 命中既有记录）：
     *   - 不存在            → INSERT，status=accepted
     *   - 存在且内容有变化   → UPDATE（filterNonEmpty：空字段不覆盖既有），status=updated
     *   - 存在且内容未变     → 跳过写库，status=duplicated
     *     （比较时忽略每批必变的记账字段 last_batch_id / collected_at，避免同榜单天天 UPDATE）
     *
     * @return array{status: 'accepted'|'updated'|'duplicated', novel_id: int}
     */
    public function upsertItem(array $item, string $batchId): array
    {
        $titleNorm  = HotNovelValidator::normalize($item['title']);
        $authorNorm = HotNovelValidator::normalize($item['author'] ?? '');
        $existing   = DB::fetch(
            'SELECT * FROM hot_novels WHERE title_norm = ? AND author_norm = ?',
            [$titleNorm, $authorNorm]
        );

        $classify = HotNovelValidator::classifyChannel($item['raw_category'] ?? '');

        $data = [
            'source'              => $item['source'],
            'title'               => (string)$item['title'],
            'title_norm'          => $titleNorm,
            'author'              => (string)($item['author'] ?? ''),
            'author_norm'         => $authorNorm,
            'raw_category'        => (string)($item['raw_category'] ?? ''),
            'normalized_category' => $classify['normalized_category'],
            'channel'             => $classify['channel'],
            'tags'                => isset($item['tags']) ? json_encode($item['tags'], JSON_UNESCAPED_UNICODE) : null,
            'word_count'          => isset($item['word_count']) && $item['word_count'] !== null ? (int)$item['word_count'] : null,
            'click_count'         => isset($item['click_count']) && $item['click_count'] !== null ? (int)$item['click_count'] : null,
            'collect_count'       => isset($item['collect_count']) && $item['collect_count'] !== null ? (int)$item['collect_count'] : null,
            'recommend_count'     => isset($item['recommend_count']) && $item['recommend_count'] !== null ? (int)$item['recommend_count'] : null,
            'hotness_score'       => (int)($item['hotness_score'] ?? 0),
            'rank_no'             => (int)($item['rank_no'] ?? 0),
            'rank_type'           => (string)($item['rank_type'] ?? ''),
            'extra_rank_types'    => isset($item['extra_rank_types']) ? json_encode($item['extra_rank_types'], JSON_UNESCAPED_UNICODE) : null,
            'intro'               => $item['intro'] ?? null,
            'cover_url'           => $item['cover_url'] ?? null,
            'source_url'          => $item['source_url'] ?? null,
            'collected_at'        => $item['collected_at'] ?? date('Y-m-d H:i:s'),
            'confidence_score'    => (int)($item['confidence_score'] ?? 0),
            'last_batch_id'       => $batchId,
        ];

        if ($existing) {
            // 空字段（''/null/0/空 JSON）不覆盖既有
            $update = $this->filterNonEmpty($data);

            // 内容签名去重：除每批必变的记账字段外，若没有任何字段相对既有值发生变化，
            // 视为重复推送 → 跳过写库并记 duplicated。last_batch_id / collected_at 每次推送都会变，
            // 不能算作"内容变化"，否则同榜单天天 UPDATE。
            $bookkeeping = ['last_batch_id', 'collected_at'];
            $changed = false;
            foreach ($update as $k => $v) {
                if (in_array($k, $bookkeeping, true)) continue;
                if ((string)($existing[$k] ?? '') !== (string)$v) { $changed = true; break; }
            }
            if (!$changed) {
                return ['status' => 'duplicated', 'novel_id' => (int)$existing['id']];
            }

            if (!empty($update)) {
                $sets = [];
                $params = [];
                foreach ($update as $k => $v) {
                    $sets[] = "`{$k}` = ?";
                    $params[] = $v;
                }
                $params[] = (int)$existing['id'];
                DB::execute('UPDATE hot_novels SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
            }
            return ['status' => 'updated', 'novel_id' => (int)$existing['id']];
        } else {
            $id = DB::insert('hot_novels', $data);
            return ['status' => 'accepted', 'novel_id' => (int)$id];
        }
    }

    /**
     * 过滤掉"空"字段（空字符串、null、空数组的 JSON、0 字段中只对可空字段生效）
     * 用于 update 场景的"空不覆盖"
     */
    private function filterNonEmpty(array $data): array
    {
        $out = [];
        // 这些字段可以为 0，且 0 是有意义的更新值
        $allowZero = ['hotness_score', 'rank_no', 'confidence_score'];
        // 这些字段允许空（''或 null）覆盖
        $alwaysUpdate = ['source', 'title', 'title_norm', 'author_norm', 'channel', 'last_batch_id'];

        foreach ($data as $k => $v) {
            if (in_array($k, $alwaysUpdate, true)) {
                $out[$k] = $v;
                continue;
            }
            // 空 JSON（'[]' 或 'null'）跳过
            if (in_array($k, ['tags', 'extra_rank_types'], true)) {
                if ($v === null || $v === '[]' || $v === 'null') continue;
                $out[$k] = $v;
                continue;
            }
            if ($v === null || $v === '') continue;
            if (!in_array($k, $allowZero, true) && $v === 0) continue;
            $out[$k] = $v;
        }
        return $out;
    }

    /**
     * UPSERT 单条 analysis → hot_novel_analysis 表（与 hot_novels 按 novel_id 一对一）。
     *
     * 字段映射（$analysis 键 → hot_novel_analysis 列）：
     *   appeals / selling_points / tropes / audience / risks / suggestions / hooks → 同名文本列
     *   benchmarks[]  → benchmarks（JSON，对标作品数组）
     *   _evidence{}   → evidence（JSON，证据链）
     *
     * 规则：已存在 → 只更新非空字段（空不覆盖既有）；不存在 → 整行插入（含 novel_id，部分列可为 null）。
     * 注：$analysis 的 key 必须为英文，中文 key 会被上游 json_decode 解析异常。
     */
    public function upsertAnalysis(int $novelId, array $analysis): void
    {
        if (empty($analysis)) return;

        $existing = DB::fetch('SELECT novel_id FROM hot_novel_analysis WHERE novel_id = ?', [$novelId]);

        $evidence = $analysis['_evidence'] ?? null;
        $fields = [
            'appeals'        => $analysis['appeals'] ?? null,
            'selling_points' => $analysis['selling_points'] ?? null,
            'tropes'         => $analysis['tropes'] ?? null,
            'audience'       => $analysis['audience'] ?? null,
            'risks'          => $analysis['risks'] ?? null,
            'suggestions'    => $analysis['suggestions'] ?? null,
            'hooks'          => $analysis['hooks'] ?? null,
            'benchmarks'     => isset($analysis['benchmarks']) ? json_encode($analysis['benchmarks'], JSON_UNESCAPED_UNICODE) : null,
            'evidence'       => $evidence !== null ? json_encode($evidence, JSON_UNESCAPED_UNICODE) : null,
        ];

        if ($existing) {
            // 空不覆盖
            $update = [];
            foreach ($fields as $k => $v) {
                if ($v === null || $v === '' || $v === '[]' || $v === 'null') continue;
                $update[$k] = $v;
            }
            if (!empty($update)) {
                $sets = []; $params = [];
                foreach ($update as $k => $v) { $sets[] = "`{$k}` = ?"; $params[] = $v; }
                $params[] = $novelId;
                DB::execute('UPDATE hot_novel_analysis SET ' . implode(', ', $sets) . ' WHERE novel_id = ?', $params);
            }
        } else {
            // 新插入：所有字段一起写（即使部分为 null）
            $fields['novel_id'] = $novelId;
            DB::insert('hot_novel_analysis', $fields);
        }
    }

    /**
     * 计算批次多样性（B1/B2）：统计各条 $items[].analysis.tropes（套路）与 .benchmarks（对标作品）的集中度。
     *   - 单一 tropes 占比    > 40% → 警告 BATCH_LOW_DIVERSITY:TROPES_OVER_40
     *   - 单一 benchmark 占比 > 30% → 警告 BATCH_LOW_DIVERSITY:BENCHMARKS_OVER_30
     * 输入只读不写库；返回的 warnings 进批次台账 diversity_metrics，并回传客户端 batch_warnings。
     * 注：clean_analysis 为历史遗留字段；v5.1 起仅产出警告，不再据此清空 analysis。
     * @return array{top_tropes_share:float, top_benchmarks_share:float, warnings:string[], clean_analysis:bool}
     */
    public function computeBatchDiversity(array $items): array
    {
        $tropes = [];
        $benches = [];
        $totalWithTropes = 0;
        $totalWithBenches = 0;
        foreach ($items as $it) {
            $a = $it['analysis'] ?? [];
            if (!empty($a['tropes'])) {
                $tropes[trim((string)$a['tropes'])] = ($tropes[trim((string)$a['tropes'])] ?? 0) + 1;
                $totalWithTropes++;
            }
            if (!empty($a['benchmarks']) && is_array($a['benchmarks'])) {
                foreach ($a['benchmarks'] as $b) {
                    $name = is_string($b) ? trim($b) : '';
                    if ($name === '') continue;
                    $benches[$name] = ($benches[$name] ?? 0) + 1;
                }
                $totalWithBenches++;
            }
        }
        $topTrope = $totalWithTropes > 0 ? (max($tropes ?: [0]) / $totalWithTropes) : 0;
        $topBench = $totalWithBenches > 0 ? (max($benches ?: [0]) / $totalWithBenches) : 0;

        $warnings = [];
        $clean = false;
        if ($topTrope > 0.40) { $warnings[] = 'BATCH_LOW_DIVERSITY:TROPES_OVER_40'; $clean = true; }
        if ($topBench > 0.30) { $warnings[] = 'BATCH_LOW_DIVERSITY:BENCHMARKS_OVER_30'; $clean = true; }

        return [
            'top_tropes_share'     => round($topTrope, 4),
            'top_benchmarks_share' => round($topBench, 4),
            'warnings'             => $warnings,
            'clean_analysis'       => $clean,
        ];
    }

    /**
     * 批次台账 UPSERT → hot_novel_batches 表（按 batch_id 唯一）。
     *
     * 字段映射（$report 键 → hot_novel_batches 列）：
     *   batch_id / source / pushed_by / client_ip          → 同名列
     *   prepared_items / submitted_items                   → 同名列（准备数 / 实际提交数）
     *   accepted / updated / duplicated / failed           → 同名计数列
     *   failed_reasons{} / summary{} / diversity_metrics{} → 同名列（均存 JSON）
     *   fetch_failed(bool)                                 → fetch_failed（0/1）
     *
     * 同 batch_id 重复推送：UPSERT 只刷新计数 / failed_reasons / summary / diversity_metrics。
     */
    public function recordBatch(array $report): void
    {
        DB::execute(
            'INSERT INTO hot_novel_batches
                (batch_id, source, pushed_by, prepared_items, submitted_items, accepted, updated, duplicated, failed,
                 failed_reasons, summary, diversity_metrics, fetch_failed, client_ip)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                accepted=VALUES(accepted), updated=VALUES(updated), duplicated=VALUES(duplicated),
                failed=VALUES(failed), failed_reasons=VALUES(failed_reasons),
                diversity_metrics=VALUES(diversity_metrics), summary=VALUES(summary)',
            [
                $report['batch_id'],
                $report['source'],
                $report['pushed_by'] ?? null,
                $report['prepared_items'] ?? 0,
                $report['submitted_items'] ?? 0,
                $report['accepted'] ?? 0,
                $report['updated'] ?? 0,
                $report['duplicated'] ?? 0,
                $report['failed'] ?? 0,
                isset($report['failed_reasons']) ? json_encode($report['failed_reasons'], JSON_UNESCAPED_UNICODE) : null,
                isset($report['summary']) ? json_encode($report['summary'], JSON_UNESCAPED_UNICODE) : null,
                isset($report['diversity_metrics']) ? json_encode($report['diversity_metrics'], JSON_UNESCAPED_UNICODE) : null,
                !empty($report['fetch_failed']) ? 1 : 0,
                $report['client_ip'] ?? null,
            ]
        );
    }

    /** 清理过期 nonce（24h） */
    public function cleanupNonces(): void
    {
        try {
            DB::execute('DELETE FROM hot_novel_nonces WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)');
        } catch (\Throwable $e) { /* ignore */ }
    }
}
