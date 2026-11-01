<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/ReviewContracts.php';

/**
 * Converts an existing analyzer into a pure, versioned Reviewer. The callback
 * receives only ReviewInput; persistence/effect collaborators cannot leak into
 * the role contract.
 */
abstract class RolePureReviewer implements PureReviewer
{
    private Closure $analyzer;

    /** @param callable(ReviewInput):ReviewReport $analyzer */
    final public function __construct(
        private readonly string $reviewerVersion,
        callable $analyzer
    ) {
        HarnessPersistenceValue::identifier(
            $reviewerVersion,
            'role_reviewer.version',
            32
        );
        $this->analyzer = Closure::fromCallable($analyzer);
    }

    final public function version(): string
    {
        return $this->reviewerVersion;
    }

    final public function analyze(ReviewInput $input): ReviewReport
    {
        $report = ($this->analyzer)($input);
        if (!$report instanceof ReviewReport
            || $report->reviewer !== $this->name()
            || $report->version !== $this->reviewerVersion
            || !hash_equals($report->draftHash, $input->draftHash)) {
            throw new RuntimeException(
                'Role Reviewer returned a report for different immutable coordinates.'
            );
        }
        return $report;
    }
}

final class ContinuityReviewerAdapter extends RolePureReviewer
{
    public function name(): string { return 'continuity'; }
}

final class StyleReviewerAdapter extends RolePureReviewer
{
    public function name(): string { return 'style'; }
}

final class DialogueReviewerAdapter extends RolePureReviewer
{
    public function name(): string { return 'dialogue'; }
}

final class AudienceReviewerAdapter extends RolePureReviewer
{
    public function name(): string { return 'audience'; }
}
