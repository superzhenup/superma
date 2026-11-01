<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/BranchContracts.php';
require_once __DIR__ . '/BranchService.php';
require_once __DIR__ . '/BranchContinuationService.php';
require_once __DIR__ . '/CandidateTournament.php';
require_once __DIR__ . '/ProductionCandidateTournament.php';
require_once __DIR__ . '/BranchMemoryRepository.php';
require_once __DIR__ . '/BranchMergeService.php';
require_once __DIR__ . '/ProductionBranchMergeHardGate.php';
require_once __DIR__ . '/M65BranchRepository.php';
require_once __DIR__ . '/M65MergeCandidateSink.php';
require_once __DIR__ . '/M65CanonicalProjection.php';
require_once __DIR__ . '/BranchMergeCommitLedgerRepository.php';
