<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/OutlineEnhancementContracts.php';
require_once dirname(__DIR__, 2) . '/OutlineQualityGuard.php';

/**
 * Adapter around the existing outline-quality pipeline.
 *
 * The adapter contains no persistence. Every potentially blocking model call
 * therefore completes before DbOutlineEnhancementStore opens its CAS
 * transaction.
 */
final class LegacyOutlineEnhancer implements OutlineEnhancer
{
    public function enhance(
        OutlineEnhancementSnapshot $base,
        array $novel,
        array $recentOutlines,
        callable $checkpoint
    ): array {
        $startChapter = min($base->chapterNumbers);
        $endChapter = max($base->chapterNumbers);
        $outlines = $base->outlines;

        $checkpoint();
        $quality = repairOutlineBatchWithGuard(
            $novel,
            $outlines,
            $recentOutlines,
            static function (string $event, array $payload) use ($checkpoint): void {
                unset($event, $payload);
                $checkpoint();
            },
            2,
            $checkpoint
        );
        $outlines = is_array($quality['outlines'] ?? null)
            ? array_values($quality['outlines'])
            : $outlines;

        $checkpoint();
        $turningPoints = enforceTurningPoints(
            $novel,
            $startChapter,
            $endChapter,
            $outlines,
            null,
            $checkpoint
        );
        $outlines = is_array($turningPoints['outlines'] ?? null)
            ? array_values($turningPoints['outlines'])
            : $outlines;

        $checkpoint();
        $goldenThree = enforceGoldenThree(
            $novel,
            $startChapter,
            $endChapter,
            $outlines,
            null,
            $checkpoint
        );
        $outlines = is_array($goldenThree['outlines'] ?? null)
            ? array_values($goldenThree['outlines'])
            : $outlines;
        $checkpoint();

        return $outlines;
    }
}
