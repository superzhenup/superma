<?php
defined('APP_LOADED') or die('Direct access denied.');

/**
 * Computation-only dispatch for revision-bound post-processing tasks.
 * The coordinator freezes inputs and the worker restores its settings scope.
 * Results are candidates; only the fenced parent may publish business data.
 */
final class PostprocessAnalysisTasks
{
    public static function compute(string $type, array $input): array
    {
        if ($type === 'knowledge') {
            if (!is_int($input['novel_id'] ?? null) || $input['novel_id'] <= 0
                || !is_int($input['chapter_number'] ?? null) || $input['chapter_number'] <= 0
                || !is_string($input['content'] ?? null) || trim($input['content']) === '') {
                throw new InvalidArgumentException('Invalid frozen knowledge extraction input.');
            }
            require_once dirname(__DIR__) . '/embedding.php';
            return (new KnowledgeBase($input['novel_id']))->computeFromChapter(
                $input['chapter_number'], $input['content']
            );
        }
        if ($type === 'critic') {
            require_once dirname(__DIR__) . '/agents/AgentCoordinator.php';
            return AgentCoordinator::computeCritic($input);
        }
        throw new InvalidArgumentException('Unsupported post-processing analysis task: ' . $type);
    }
}
