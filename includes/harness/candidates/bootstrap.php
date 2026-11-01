<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/CandidateArtifactProvenance.php';
require_once __DIR__ . '/HarnessCandidate.php';
require_once __DIR__ . '/HarnessCandidateRepository.php';
require_once __DIR__ . '/ApprovalRequest.php';
require_once __DIR__ . '/ApprovalRequestRepository.php';
require_once __DIR__ . '/ApprovalCommitReplayGuard.php';
require_once __DIR__ . '/CandidateUiPolicy.php';
require_once __DIR__ . '/M63CandidateProjectionBridge.php';
