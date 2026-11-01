<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

/**
 * Pure, deterministic finding detector shared by the production Review gate
 * and the offline release Eval. Keeping this logic outside the orchestration
 * closure prevents an Eval from silently reimplementing production rules.
 */
final class ProductionReviewFindingDetector
{
    public const VERSION = 'production-review-findings-v1';

    private function __construct()
    {
    }

    /**
     * @return list<array{rule_id:string,message:string,evidence:array<string,int|string>}>
     */
    public static function detect(string $content): array
    {
        $findings = [];
        if (preg_match(
            '/(?:\[待补\]|【待补】|(?<![A-Za-z0-9_])(?:TODO|TBD)(?![A-Za-z0-9_])|未完待续\s*$)/iu',
            $content
        ) === 1) {
            $findings[] = [
                'rule_id' => 'content.truncated',
                'message' => '候选稿包含未完成占位符或明确的截断标记。',
                'evidence' => ['detector' => 'completion_marker_v1'],
            ];
        }

        $delimiterMismatches = 0;
        foreach ([
            ['“', '”'],
            ['「', '」'],
            ['『', '』'],
            ['（', '）'],
            ['《', '》'],
        ] as [$open, $close]) {
            if (substr_count($content, $open) !== substr_count($content, $close)) {
                $delimiterMismatches++;
            }
        }
        if ($delimiterMismatches > 0) {
            $findings[] = [
                'rule_id' => 'continuity.severe',
                'message' => '候选稿存在未闭合的配对标点，无法证明正文完整。',
                'evidence' => [
                    'detector' => 'paired_delimiter_balance_v1',
                    'mismatched_pair_count' => $delimiterMismatches,
                ],
            ];
        }

        return $findings;
    }
}
