<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/HarnessControlPlane.php';

/** Default-deny rollout gate for every Candidate/Approval read and action. */
final class CandidateUiPolicy
{
    public const SETTING = 'ws_harness_candidate_ui_enabled';

    private function __construct()
    {
    }

    public static function enabledForNovel(int $novelId): bool
    {
        if ($novelId <= 0 || !class_exists('ConfigCenter', false)) {
            return false;
        }
        try {
            $enabled = HarnessControlPlane::normalizeBoolean(
                ConfigCenter::get(self::SETTING, false, 'bool'),
                false
            );
            if (!$enabled) {
                return false;
            }
            $control = HarnessControlPlane::captureForNovel($novelId);
            return HarnessControlPlane::allowsNewHarnessRun($control['harness_mode']);
        } catch (Throwable) {
            return false;
        }
    }

    public static function assertEnabledForNovel(int $novelId): void
    {
        if (!self::enabledForNovel($novelId)) {
            throw new CandidateUiDisabledException(
                '候选稿审批功能尚未对这部小说开放'
            );
        }
    }
}

final class CandidateUiDisabledException extends RuntimeException
{
}
