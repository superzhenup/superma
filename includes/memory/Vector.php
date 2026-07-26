<?php
defined('APP_LOADED') or die('Direct access denied.');

/**
 * ================================================================
 * Vector — float32 向量的打包 / 解包 / 余弦相似度
 * 所有向量以 float32 小端字节序存 BLOB,比 JSON 数组小 ~4x、快 ~10x
 * ================================================================
 */
final class Vector
{
    /**
     * 把 float[] 打包成 float32 BLOB。
     * 用小端序('g' 在 pack() 里代表 little-endian float32)保证跨平台一致。
     */
    public static function pack(array $floats): string
    {
        if (empty($floats)) return '';
        // PHP 7.0.15+ / 7.1.1+ 支持 'g' 和 'G'
        return pack('g*', ...$floats);
    }

    /**
     * 解包 float32 BLOB 为 float[]。
     */
    public static function unpack(string $blob): array
    {
        if ($blob === '') return [];
        $len = strlen($blob);
        if ($len % 4 !== 0) {
            throw new \RuntimeException(
                "Invalid vector blob length: $len bytes (not multiple of 4)"
            );
        }
        // unpack 结果是 1-indexed 数组,转成 0-indexed
        $arr = unpack('g*', $blob);
        return $arr ? array_values($arr) : [];
    }

    /**
     * 余弦相似度,范围 [-1, 1]。
     * 给向量做归一化之后点积即余弦,但我们不预先归一化(方便 debug),
     * 所以这里完整算。
     *
     * @param array<int,float> $a
     * @param array<int,float> $b
     */
    public static function cosine(array $a, array $b): float
    {
        $n = count($a);
        if ($n === 0 || $n !== count($b)) return 0.0;

        $dot = 0.0; $na = 0.0; $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $x = $a[$i]; $y = $b[$i];
            $dot += $x * $y;
            $na  += $x * $x;
            $nb  += $y * $y;
        }
        if ($na == 0.0 || $nb == 0.0) return 0.0;
        return $dot / (sqrt($na) * sqrt($nb));
    }

    /**
     * 将向量归一化为单位长度（L2 范数 = 1）。
     * 零向量原样返回（避免除零）。
     *
     * 审计修复 PERF-C1（2026-07-01）：预归一化后余弦相似度退化为点积，
     * 省去每次搜索时重复计算查询向量的范数。
     *
     * @param array<int,float> $vec
     * @return array<int,float>
     */
    public static function normalize(array $vec): array
    {
        $n = count($vec);
        if ($n === 0) return $vec;
        $mag = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $mag += $vec[$i] * $vec[$i];
        }
        if ($mag == 0.0) return $vec;
        $inv = 1.0 / sqrt($mag);
        for ($i = 0; $i < $n; $i++) {
            $vec[$i] *= $inv;
        }
        return $vec;
    }

    /**
     * 从候选集里按余弦相似度选 topK。
     * $candidates: [['id' => int, 'blob' => string, ...其他任意字段], ...]
     * 返回:按 score 降序,保留原 row 并附加 _score 字段
     *
     * 性能:O(N) — 一部小说 5k atoms 级别,PHP 7.4+ 毫秒级完成
     *
     * 审计修复 PERF-C1（2026-07-01）：查询向量预先归一化一次，
     * 每个候选只需 dot(q_norm, c) / |c|，省去 N 次重复的 |q| 计算。
     * 新写入向量经 EmbeddingProvider 预归一化后 |c|≈1，进一步退化为纯点积。
     */
    public static function topK(array $queryVec, array $candidates, int $k = 10, float $threshold = 0.0): array
    {
        if (empty($queryVec) || empty($candidates)) return [];

        $qNorm = self::normalize($queryVec);
        $n = count($qNorm);

        $scored = [];
        foreach ($candidates as $row) {
            if (empty($row['blob'])) continue;
            try {
                $vec = self::unpack($row['blob']);
            } catch (\Throwable $e) {
                continue; // 损坏的 blob 跳过
            }
            if (count($vec) !== $n) continue;

            // 查询向量已归一化（|q|=1），cosine = dot(q, c) / |c|
            $dot = 0.0; $nb = 0.0;
            for ($i = 0; $i < $n; $i++) {
                $y = $vec[$i];
                $dot += $qNorm[$i] * $y;
                $nb  += $y * $y;
            }
            if ($nb == 0.0) continue;
            $score = $dot / sqrt($nb);

            if ($score < $threshold) continue;
            $row['_score'] = $score;
            unset($row['blob']); // 不把 blob 透出去
            $scored[] = $row;
        }

        // 按 score 降序排,稳定
        usort($scored, fn($a, $b) => $b['_score'] <=> $a['_score']);

        return array_slice($scored, 0, $k);
    }
}
