<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/PostGenerationReviewBridge.php';
require_once __DIR__ . '/ForkedReviewExecutor.php';
require_once __DIR__ . '/ProductionReviewFindingDetector.php';
require_once dirname(__DIR__, 2) . '/quality/CanonicalQualityGate.php';

/**
 * Builds the deterministic production Review pipeline used between model
 * generation and Canon admission.
 *
 * Reviewers are intentionally pure and local: they can only emit immutable
 * observations about the frozen Draft. The sole Editor performs conservative
 * whitespace normalization; it never invents story facts. Canon admission is
 * still decided by the same frozen WritePolicySnapshot used by saveChapter().
 */
final class ProductionPostGenerationReviewFactory
{
    public const REVIEWER_VERSION = '1.0.0';
    public const EDITOR_NAME = 'conservative_editor';
    public const EDITOR_VERSION = '1.0.0';
    public const GATE_NAME = 'frozen_policy_gate';
    public const GATE_VERSION = '1.0.0';

    private function __construct()
    {
    }

    public static function create(
        WritePolicySnapshot $policy,
        PostGenerationReviewEvidenceStore $evidenceStore,
        ?ReviewExecutor $executor = null
    ): PostGenerationReviewBridge {
        $reviewers = [
            self::continuityReviewer(),
            self::styleReviewer(),
            self::dialogueReviewer(),
            self::audienceReviewer(),
        ];
        $coordinator = new ReviewCoordinator(
            $reviewers,
            $executor ?? ProductionReviewExecutorFactory::create(),
            new InMemoryReviewReportStore()
        );
        $editor = new CallableProvenancedCandidateEditor(
            self::EDITOR_NAME,
            self::EDITOR_VERSION,
            static function (
                ReviewInput $input,
                AggregatedReview $review,
                int $round
            ): EditedCandidate {
                // The report and round parameters are part of the mutation
                // authority contract even when this conservative editor only
                // performs deterministic, semantics-preserving cleanup.
                unset($review, $round);
                $content = str_replace(["\r\n", "\r"], "\n", $input->draft);
                $content = preg_replace('/[ \t]+$/m', '', $content) ?? $content;
                $content = preg_replace('/\n{4,}/', "\n\n\n", $content) ?? $content;
                $content = trim($content);
                return new EditedCandidate(
                    $content,
                    hash('sha256', $content),
                    $input->draftHash
                );
            }
        );
        $gate = new CallableProvenancedReviewHardGate(
            self::GATE_NAME,
            self::GATE_VERSION,
            static function (string $content, string $contentHash) use ($policy): ReviewGateOutcome {
                $words = self::wordCount($content);
                $hardFindings = ProductionReviewFindingDetector::detect($content);
                $decision = CanonicalQualityGate::evaluate(
                    $policy,
                    $content,
                    $words,
                    $hardFindings,
                    evaluatedAt: '1970-01-01T00:00:00Z'
                );
                if (!hash_equals($decision->contentHash(), $contentHash)) {
                    throw new RuntimeException(
                        'Frozen Review gate returned a mismatched content hash.'
                    );
                }
                $distance = abs($policy->targetWords() - $words);
                $scale = max(1, $policy->targetWords());
                $score = max(0, 100 - min(45, (int)floor(($distance * 45) / $scale)));
                if ($decision->shouldQuarantine()) {
                    $score = min($score, 49);
                }
                return new ReviewGateOutcome(
                    !$decision->shouldQuarantine(),
                    $score,
                    $decision->hardIssues()
                );
            }
        );
        return new PostGenerationReviewBridge(
            $coordinator,
            new ReviewAggregator(),
            $editor,
            $gate,
            $evidenceStore,
            2,
            3
        );
    }

    private static function continuityReviewer(): PureReviewer
    {
        return new ContinuityReviewerAdapter(
            self::REVIEWER_VERSION,
            static function (ReviewInput $input): ReviewReport {
                $issues = [];
                $pairs = [
                    ['“', '”'], ['「', '」'], ['『', '』'], ['（', '）'], ['《', '》'],
                ];
                $unbalanced = 0;
                foreach ($pairs as [$open, $close]) {
                    if (substr_count($input->draft, $open) !== substr_count($input->draft, $close)) {
                        $unbalanced++;
                    }
                }
                if ($unbalanced > 0) {
                    $issues[] = self::issue(
                        'CONTINUITY_DELIMITER_IMBALANCE',
                        ReviewSeverity::HIGH,
                        0.98,
                        "检测到 {$unbalanced} 组配对标点数量不一致。",
                        '核对缺失的引号或括号，避免语义边界断裂。',
                        true
                    );
                }
                return self::report('continuity', $input, $issues, $unbalanced * 18);
            }
        );
    }

    private static function styleReviewer(): PureReviewer
    {
        return new StyleReviewerAdapter(
            self::REVIEWER_VERSION,
            static function (ReviewInput $input): ReviewReport {
                $paragraphs = preg_split('/\n{2,}/u', trim($input->draft)) ?: [];
                $seen = [];
                $duplicates = 0;
                $veryLong = 0;
                foreach ($paragraphs as $paragraph) {
                    $normalized = preg_replace('/\s+/u', '', $paragraph) ?? $paragraph;
                    if ($normalized === '') {
                        continue;
                    }
                    $hash = hash('sha256', $normalized);
                    if (isset($seen[$hash])) {
                        $duplicates++;
                    }
                    $seen[$hash] = true;
                    if (self::charLength($normalized) > 900) {
                        $veryLong++;
                    }
                }
                $issues = [];
                if ($duplicates > 0) {
                    $issues[] = self::issue(
                        'STYLE_DUPLICATE_PARAGRAPH',
                        ReviewSeverity::MEDIUM,
                        0.99,
                        "检测到 {$duplicates} 个完全重复的段落。",
                        '删除机械重复段落，并确认信息只推进一次。',
                        false
                    );
                }
                if ($veryLong > 0) {
                    $issues[] = self::issue(
                        'STYLE_OVERSIZED_PARAGRAPH',
                        ReviewSeverity::LOW,
                        0.9,
                        "检测到 {$veryLong} 个超过安全长度的段落。",
                        '在动作、对话或视角转折处自然分段。',
                        false
                    );
                }
                return self::report(
                    'style',
                    $input,
                    $issues,
                    min(35, ($duplicates * 14) + ($veryLong * 5))
                );
            }
        );
    }

    private static function dialogueReviewer(): PureReviewer
    {
        return new DialogueReviewerAdapter(
            self::REVIEWER_VERSION,
            static function (ReviewInput $input): ReviewReport {
                $open = substr_count($input->draft, '“');
                $close = substr_count($input->draft, '”');
                $issues = [];
                $penalty = 0;
                if ($open !== $close) {
                    $issues[] = self::issue(
                        'DIALOGUE_QUOTE_IMBALANCE',
                        ReviewSeverity::HIGH,
                        0.99,
                        '人物对白引号未完整闭合。',
                        '逐段补齐对白边界后再提交。',
                        true
                    );
                    $penalty = 24;
                }
                return self::report('dialogue', $input, $issues, $penalty);
            }
        );
    }

    private static function audienceReviewer(): PureReviewer
    {
        return new AudienceReviewerAdapter(
            self::REVIEWER_VERSION,
            static function (ReviewInput $input): ReviewReport {
                $issues = [];
                $trimmed = trim($input->draft);
                $length = self::charLength($trimmed);
                if ($length > 0 && preg_match('/[。！？!?…」”』]$/u', $trimmed) !== 1) {
                    $issues[] = self::issue(
                        'AUDIENCE_ABRUPT_ENDING',
                        ReviewSeverity::MEDIUM,
                        0.8,
                        '章节末尾缺少可识别的句末或对白闭合标记。',
                        '确认结尾不是传输截断，并保留明确的场景落点。',
                        true
                    );
                }
                return self::report(
                    'audience',
                    $input,
                    $issues,
                    $issues === [] ? 0 : 15
                );
            }
        );
    }

    /** @param list<ReviewIssue> $issues */
    private static function report(
        string $reviewer,
        ReviewInput $input,
        array $issues,
        int $penalty
    ): ReviewReport {
        return new ReviewReport(
            $reviewer,
            self::REVIEWER_VERSION,
            $input->draftHash,
            $issues,
            max(0, 100 - min(100, $penalty))
        );
    }

    private static function issue(
        string $code,
        ReviewSeverity $severity,
        float $confidence,
        string $evidence,
        string $suggestion,
        bool $canonImpact
    ): ReviewIssue {
        return new ReviewIssue(
            $code,
            $severity,
            $confidence,
            $evidence,
            $suggestion,
            $canonImpact
        );
    }

    private static function wordCount(string $text): int
    {
        $chinese = preg_match_all('/[\x{4e00}-\x{9fa5}]/u', $text);
        $nonChinese = preg_replace('/[\x{4e00}-\x{9fa5}]/u', ' ', $text) ?? '';
        $english = preg_match_all('/[A-Za-z]+(?:[\'\-][A-Za-z]+)*/', $nonChinese);
        return ($chinese ?: 0) + ($english ?: 0);
    }

    private static function charLength(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }
}
