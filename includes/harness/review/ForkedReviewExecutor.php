<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/ReviewCoordinator.php';

/**
 * A deliberately non-sensitive failure boundary for the process executor.
 *
 * Worker exception messages and Draft bytes never cross the process boundary.
 * AdaptiveReviewExecutor may fingerprint this canonical reason without storing
 * an exception's original text.
 */
final class ReviewParallelExecutionException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        HarnessPersistenceValue::identifier(
            $reason,
            'parallel_review.failure_reason',
            64
        );
        parent::__construct("Parallel Review execution failed: {$reason}.");
    }
}

/**
 * Strict JSON-only IPC codec. No PHP object deserialization is permitted.
 */
final class ReviewReportIpcProtocol
{
    public const SCHEMA = 'harness.review.worker_result.v1';
    public const MAX_ISSUES = 64;

    private function __construct()
    {
    }

    public static function successPayload(ReviewReport $report): string
    {
        return HarnessPersistenceValue::canonicalJson([
            'schema' => self::SCHEMA,
            'status' => 'ok',
            'report' => $report->toArray(),
        ]);
    }

    public static function failurePayload(string $errorCode): string
    {
        if (!in_array($errorCode, [
            'reviewer_failed',
            'result_too_large',
            'protocol_encoding_failed',
        ], true)) {
            $errorCode = 'reviewer_failed';
        }
        return HarnessPersistenceValue::canonicalJson([
            'schema' => self::SCHEMA,
            'status' => 'error',
            'error_code' => $errorCode,
        ]);
    }

    public static function frame(string $payload, int $maxPayloadBytes): string
    {
        $length = strlen($payload);
        if ($length < 1 || $length > $maxPayloadBytes) {
            throw new ReviewParallelExecutionException('ipc_payload_size_invalid');
        }
        return pack('N', $length) . $payload;
    }

    public static function decodeFrame(
        string $frame,
        int $maxPayloadBytes,
        string $expectedReviewer,
        string $expectedVersion,
        string $expectedDraftHash
    ): ReviewReport {
        if (strlen($frame) < 5 || strlen($frame) > $maxPayloadBytes + 4) {
            throw new ReviewParallelExecutionException('ipc_frame_size_invalid');
        }
        $header = unpack('Nlength', substr($frame, 0, 4));
        $length = is_array($header) ? ($header['length'] ?? null) : null;
        if (!is_int($length)
            || $length < 1
            || $length > $maxPayloadBytes
            || strlen($frame) !== $length + 4) {
            throw new ReviewParallelExecutionException('ipc_frame_length_mismatch');
        }

        try {
            $decoded = json_decode(
                substr($frame, 4),
                true,
                32,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            throw new ReviewParallelExecutionException('ipc_json_invalid');
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ReviewParallelExecutionException('ipc_envelope_invalid');
        }
        $status = $decoded['status'] ?? null;
        if ($status === 'error') {
            self::assertExactKeys($decoded, ['error_code', 'schema', 'status']);
            if ($decoded['schema'] !== self::SCHEMA
                || !is_string($decoded['error_code'])
                || !in_array($decoded['error_code'], [
                    'reviewer_failed',
                    'result_too_large',
                    'protocol_encoding_failed',
                ], true)) {
                throw new ReviewParallelExecutionException('ipc_error_envelope_invalid');
            }
            throw new ReviewParallelExecutionException('reviewer_worker_failed');
        }
        self::assertExactKeys($decoded, ['report', 'schema', 'status']);
        if ($decoded['schema'] !== self::SCHEMA || $status !== 'ok') {
            throw new ReviewParallelExecutionException('ipc_envelope_invalid');
        }
        return self::decodeReport(
            $decoded['report'],
            $expectedReviewer,
            $expectedVersion,
            $expectedDraftHash
        );
    }

    private static function decodeReport(
        mixed $value,
        string $expectedReviewer,
        string $expectedVersion,
        string $expectedDraftHash
    ): ReviewReport {
        if (!is_array($value) || array_is_list($value)) {
            throw new ReviewParallelExecutionException('ipc_report_invalid');
        }
        self::assertExactKeys($value, [
            'draft_hash', 'issues', 'report_uid', 'reviewer',
            'schema_version', 'score', 'version',
        ]);
        if (($value['schema_version'] ?? null) !== 1
            || !is_string($value['report_uid'])
            || preg_match('/^[a-f0-9]{32}$/D', $value['report_uid']) !== 1
            || !is_string($value['reviewer'])
            || !hash_equals($expectedReviewer, $value['reviewer'])
            || !is_string($value['version'])
            || !hash_equals($expectedVersion, $value['version'])
            || !is_string($value['draft_hash'])
            || !hash_equals($expectedDraftHash, $value['draft_hash'])
            || !is_int($value['score'])
            || !is_array($value['issues'])
            || !array_is_list($value['issues'])
            || count($value['issues']) > self::MAX_ISSUES) {
            throw new ReviewParallelExecutionException('ipc_report_coordinates_invalid');
        }

        $issues = [];
        foreach ($value['issues'] as $issueValue) {
            $issues[] = self::decodeIssue($issueValue);
        }
        try {
            $report = new ReviewReport(
                $value['reviewer'],
                $value['version'],
                $value['draft_hash'],
                $issues,
                $value['score']
            );
        } catch (Throwable) {
            throw new ReviewParallelExecutionException('ipc_report_contract_invalid');
        }
        if (!hash_equals($report->reportUid, $value['report_uid'])) {
            throw new ReviewParallelExecutionException('ipc_report_uid_mismatch');
        }
        return $report;
    }

    private static function decodeIssue(mixed $value): ReviewIssue
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new ReviewParallelExecutionException('ipc_issue_invalid');
        }
        self::assertExactKeys($value, [
            'canon_impact', 'code', 'confidence', 'evidence',
            'issue_uid', 'severity', 'suggestion',
        ]);
        $confidence = $value['confidence'] ?? null;
        $severity = is_string($value['severity'] ?? null)
            ? ReviewSeverity::tryFrom($value['severity'])
            : null;
        if (!is_string($value['issue_uid'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/D', $value['issue_uid']) !== 1
            || !is_string($value['code'] ?? null)
            || !$severity instanceof ReviewSeverity
            || (!is_int($confidence) && !is_float($confidence))
            || !is_string($value['evidence'] ?? null)
            || !is_string($value['suggestion'] ?? null)
            || !is_bool($value['canon_impact'] ?? null)) {
            throw new ReviewParallelExecutionException('ipc_issue_contract_invalid');
        }
        try {
            $issue = new ReviewIssue(
                $value['code'],
                $severity,
                (float)$confidence,
                $value['evidence'],
                $value['suggestion'],
                $value['canon_impact']
            );
        } catch (Throwable) {
            throw new ReviewParallelExecutionException('ipc_issue_contract_invalid');
        }
        if (!hash_equals($issue->issueUid, $value['issue_uid'])) {
            throw new ReviewParallelExecutionException('ipc_issue_uid_mismatch');
        }
        return $issue;
    }

    /** @param list<string> $keys */
    private static function assertExactKeys(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($actual !== $keys) {
            throw new ReviewParallelExecutionException('ipc_shape_invalid');
        }
    }
}

/**
 * Unix-only process executor for pure Reviewers.
 *
 * All Reviewers are forked before the parent starts collecting their bounded
 * result frames. The parent always closes sockets and reaps every child.
 */
final class ForkedReviewExecutor implements ReviewExecutor
{
    public const DEFAULT_DEADLINE_MS = 3000;
    public const DEFAULT_MAX_RESULT_BYTES = 262144;
    public const MAX_REVIEWERS = 16;

    public function __construct(
        private readonly int $deadlineMs = self::DEFAULT_DEADLINE_MS,
        private readonly int $maxResultBytes = self::DEFAULT_MAX_RESULT_BYTES
    ) {
        HarnessPersistenceValue::boundedInt(
            $deadlineMs,
            'parallel_review.deadline_ms',
            100,
            10000
        );
        HarnessPersistenceValue::boundedInt(
            $maxResultBytes,
            'parallel_review.max_result_bytes',
            4096,
            1048576
        );
    }

    public static function runtimeSupported(
        ?string $osFamily = null,
        ?callable $functionProbe = null,
        ?callable $constantProbe = null
    ): bool {
        $osFamily ??= PHP_OS_FAMILY;
        if (strcasecmp($osFamily, 'Windows') === 0) {
            return false;
        }
        $functionProbe ??= static fn (string $name): bool => function_exists($name);
        $constantProbe ??= static fn (string $name): bool => defined($name);
        foreach ([
            'pcntl_fork', 'pcntl_waitpid', 'pcntl_wifexited',
            'pcntl_wexitstatus', 'pcntl_get_last_error', 'posix_kill',
            'stream_socket_pair',
        ] as $function) {
            if (($functionProbe)($function) !== true) {
                return false;
            }
        }
        foreach ([
            'STREAM_PF_UNIX', 'STREAM_SOCK_STREAM', 'STREAM_IPPROTO_IP',
            'WNOHANG', 'SIGTERM', 'SIGKILL', 'PCNTL_EINTR',
        ] as $constant) {
            if (($constantProbe)($constant) !== true) {
                return false;
            }
        }
        return true;
    }

    public function execute(array $reviewers, ReviewInput $input): array
    {
        if (!self::runtimeSupported()) {
            throw new ReviewParallelExecutionException('parallel_runtime_unavailable');
        }
        if ($reviewers === [] || count($reviewers) > self::MAX_REVIEWERS) {
            throw new ReviewParallelExecutionException('reviewer_count_invalid');
        }
        foreach ($reviewers as $reviewer) {
            if (!$reviewer instanceof PureReviewer) {
                throw new ReviewParallelExecutionException('reviewer_contract_invalid');
            }
        }

        $deadline = self::now() + ($this->deadlineMs / 1000);
        /** @var array<int,array{pid:int,stream:resource,reviewer:string,version:string,bytes:string,reaped:bool,status:?int}> $children */
        $children = [];
        try {
            foreach ($reviewers as $index => $reviewer) {
                try {
                    $reviewerName = $reviewer->name();
                    $reviewerVersion = $reviewer->version();
                    HarnessPersistenceValue::identifier(
                        $reviewerName,
                        'parallel_review.reviewer',
                        64
                    );
                    HarnessPersistenceValue::identifier(
                        $reviewerVersion,
                        'parallel_review.reviewer_version',
                        32
                    );
                } catch (Throwable) {
                    throw new ReviewParallelExecutionException(
                        'reviewer_coordinates_invalid'
                    );
                }
                $pair = @stream_socket_pair(
                    constant('STREAM_PF_UNIX'),
                    constant('STREAM_SOCK_STREAM'),
                    constant('STREAM_IPPROTO_IP')
                );
                if (!is_array($pair) || count($pair) !== 2) {
                    throw new ReviewParallelExecutionException('socket_pair_failed');
                }
                [$parentSocket, $childSocket] = array_values($pair);
                if (!is_resource($parentSocket) || !is_resource($childSocket)) {
                    self::closeStream($parentSocket);
                    self::closeStream($childSocket);
                    throw new ReviewParallelExecutionException('socket_pair_failed');
                }

                $pid = @pcntl_fork();
                if ($pid === -1) {
                    fclose($parentSocket);
                    fclose($childSocket);
                    throw new ReviewParallelExecutionException('fork_failed');
                }
                if ($pid === 0) {
                    // Later children inherit the parent's earlier descriptors;
                    // close every one so EOF remains a trustworthy boundary.
                    foreach ($children as $existing) {
                        self::closeStream($existing['stream']);
                    }
                    fclose($parentSocket);
                    $exitCode = $this->runChild(
                        $childSocket,
                        $reviewer,
                        $input,
                        $deadline
                    );
                    fclose($childSocket);
                    exit($exitCode);
                }

                fclose($childSocket);
                $children[$index] = [
                    'pid' => $pid,
                    'stream' => $parentSocket,
                    'reviewer' => $reviewerName,
                    'version' => $reviewerVersion,
                    'bytes' => '',
                    'reaped' => false,
                    'status' => null,
                ];
                if (!stream_set_blocking($parentSocket, false)) {
                    throw new ReviewParallelExecutionException('socket_nonblocking_failed');
                }
            }

            $this->readAllFrames($children, $deadline);
            $this->reapAll($children, $deadline);

            $reports = [];
            foreach ($children as $child) {
                if (!is_int($child['status'])
                    || !pcntl_wifexited($child['status'])
                    || pcntl_wexitstatus($child['status']) !== 0) {
                    throw new ReviewParallelExecutionException('worker_exit_failed');
                }
                $reports[] = ReviewReportIpcProtocol::decodeFrame(
                    $child['bytes'],
                    $this->maxResultBytes,
                    $child['reviewer'],
                    $child['version'],
                    $input->draftHash
                );
            }
            return $reports;
        } catch (ReviewParallelExecutionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ReviewParallelExecutionException('parent_runtime_failed');
        } finally {
            $this->cleanupChildren($children);
        }
    }

    /** @param resource $socket */
    private function runChild(
        $socket,
        PureReviewer $reviewer,
        ReviewInput $input,
        float $deadline
    ): int {
        try {
            $payload = ReviewReportIpcProtocol::successPayload(
                $reviewer->analyze($input)
            );
            if (strlen($payload) > $this->maxResultBytes) {
                $payload = ReviewReportIpcProtocol::failurePayload('result_too_large');
            }
        } catch (Throwable) {
            // The exception class/message may contain model or Draft content.
            // Only this fixed code is allowed to cross the IPC boundary.
            $payload = ReviewReportIpcProtocol::failurePayload('reviewer_failed');
        }
        try {
            $frame = ReviewReportIpcProtocol::frame($payload, $this->maxResultBytes);
        } catch (Throwable) {
            $safePayload = ReviewReportIpcProtocol::failurePayload(
                'protocol_encoding_failed'
            );
            $frame = pack('N', strlen($safePayload)) . $safePayload;
        }
        return $this->writeUntilDeadline($socket, $frame, $deadline) ? 0 : 71;
    }

    /** @param array<int,array{pid:int,stream:resource,reviewer:string,version:string,bytes:string,reaped:bool,status:?int}> $children */
    private function readAllFrames(array &$children, float $deadline): void
    {
        $open = array_fill_keys(array_keys($children), true);
        while ($open !== []) {
            $remaining = $deadline - self::now();
            if ($remaining <= 0) {
                throw new ReviewParallelExecutionException('worker_deadline_exceeded');
            }
            $read = [];
            $streamToIndex = [];
            foreach (array_keys($open) as $index) {
                $stream = $children[$index]['stream'];
                if (is_resource($stream)) {
                    $read[] = $stream;
                    $streamToIndex[(int)$stream] = $index;
                }
            }
            if ($read === []) {
                throw new ReviewParallelExecutionException('worker_stream_missing');
            }
            $write = null;
            $except = null;
            [$seconds, $microseconds] = self::selectTimeout($remaining);
            $selected = @stream_select(
                $read,
                $write,
                $except,
                $seconds,
                $microseconds
            );
            if ($selected === false) {
                throw new ReviewParallelExecutionException('worker_select_failed');
            }
            if ($selected === 0) {
                throw new ReviewParallelExecutionException('worker_deadline_exceeded');
            }
            foreach ($read as $stream) {
                $index = $streamToIndex[(int)$stream] ?? null;
                if (!is_int($index)) {
                    throw new ReviewParallelExecutionException('worker_stream_unknown');
                }
                $chunk = @fread($stream, 8192);
                if ($chunk === false) {
                    throw new ReviewParallelExecutionException('worker_read_failed');
                }
                if ($chunk !== '') {
                    $children[$index]['bytes'] .= $chunk;
                    if (strlen($children[$index]['bytes']) > $this->maxResultBytes + 4) {
                        throw new ReviewParallelExecutionException('worker_result_too_large');
                    }
                }
                if (feof($stream)) {
                    fclose($stream);
                    unset($open[$index]);
                }
            }
        }
    }

    /** @param array<int,array{pid:int,stream:resource,reviewer:string,version:string,bytes:string,reaped:bool,status:?int}> $children */
    private function reapAll(array &$children, float $deadline): void
    {
        $remainingChildren = count($children);
        while ($remainingChildren > 0) {
            $madeProgress = false;
            foreach ($children as &$child) {
                if ($child['reaped']) {
                    continue;
                }
                $status = 0;
                $result = @pcntl_waitpid(
                    $child['pid'],
                    $status,
                    constant('WNOHANG')
                );
                if ($result === -1
                    && pcntl_get_last_error() === constant('PCNTL_EINTR')) {
                    continue;
                }
                if ($result === -1) {
                    throw new ReviewParallelExecutionException('worker_wait_failed');
                }
                if ($result === $child['pid']) {
                    $child['reaped'] = true;
                    $child['status'] = $status;
                    $remainingChildren--;
                    $madeProgress = true;
                }
            }
            unset($child);
            if ($remainingChildren === 0) {
                return;
            }
            if (self::now() >= $deadline) {
                throw new ReviewParallelExecutionException('worker_deadline_exceeded');
            }
            if (!$madeProgress) {
                usleep(1000);
            }
        }
    }

    /** @param resource $socket */
    private function writeUntilDeadline($socket, string $bytes, float $deadline): bool
    {
        if (!stream_set_blocking($socket, false)) {
            return false;
        }
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $remaining = $deadline - self::now();
            if ($remaining <= 0) {
                return false;
            }
            $read = null;
            $write = [$socket];
            $except = null;
            [$seconds, $microseconds] = self::selectTimeout($remaining);
            $selected = @stream_select(
                $read,
                $write,
                $except,
                $seconds,
                $microseconds
            );
            if ($selected !== 1) {
                return false;
            }
            $written = @fwrite($socket, substr($bytes, $offset, 8192));
            if (!is_int($written) || $written <= 0) {
                return false;
            }
            $offset += $written;
        }
        return true;
    }

    /** @param array<int,array{pid:int,stream:mixed,reviewer:string,version:string,bytes:string,reaped:bool,status:?int}> $children */
    private function cleanupChildren(array &$children): void
    {
        foreach ($children as &$child) {
            self::closeStream($child['stream']);
        }
        unset($child);

        $term = constant('SIGTERM');
        $kill = constant('SIGKILL');
        // Reap already-finished workers before signalling. This also avoids
        // signalling a stale PID if a host-level SIGCHLD handler reaped it.
        foreach ($children as &$child) {
            if ($child['reaped']) {
                continue;
            }
            $status = 0;
            do {
                $result = @pcntl_waitpid(
                    $child['pid'],
                    $status,
                    constant('WNOHANG')
                );
            } while ($result === -1
                && pcntl_get_last_error() === constant('PCNTL_EINTR'));
            if ($result === $child['pid'] || $result === -1) {
                $child['reaped'] = true;
                $child['status'] = $result === $child['pid'] ? $status : null;
            }
        }
        unset($child);
        foreach ($children as $child) {
            if (!$child['reaped']) {
                @posix_kill($child['pid'], $term);
            }
        }
        $graceDeadline = self::now() + 0.1;
        do {
            $pending = false;
            foreach ($children as &$child) {
                if ($child['reaped']) {
                    continue;
                }
                $status = 0;
                $result = @pcntl_waitpid(
                    $child['pid'],
                    $status,
                    constant('WNOHANG')
                );
                if ($result === -1
                    && pcntl_get_last_error() === constant('PCNTL_EINTR')) {
                    $pending = true;
                    continue;
                }
                if ($result === $child['pid'] || $result === -1) {
                    $child['reaped'] = true;
                    $child['status'] = $result === $child['pid'] ? $status : null;
                    continue;
                }
                $pending = true;
            }
            unset($child);
            if ($pending && self::now() < $graceDeadline) {
                usleep(1000);
            }
        } while ($pending && self::now() < $graceDeadline);

        foreach ($children as &$child) {
            if ($child['reaped']) {
                continue;
            }
            @posix_kill($child['pid'], $kill);
            $status = 0;
            do {
                $result = @pcntl_waitpid($child['pid'], $status);
            } while ($result === -1
                && pcntl_get_last_error() === constant('PCNTL_EINTR'));
            $child['reaped'] = true;
            $child['status'] = $result === $child['pid'] ? $status : null;
        }
        unset($child);
    }

    /** @return array{0:int,1:int} */
    private static function selectTimeout(float $remaining): array
    {
        $seconds = max(0, (int)floor($remaining));
        $microseconds = max(
            1,
            min(999999, (int)floor(($remaining - $seconds) * 1000000))
        );
        return [$seconds, $microseconds];
    }

    private static function now(): float
    {
        return hrtime(true) / 1000000000;
    }

    private static function closeStream(mixed &$stream): void
    {
        if (is_resource($stream)) {
            fclose($stream);
        }
        $stream = null;
    }
}

/** Selects real forked Review only when its complete cleanup surface exists. */
final class ProductionReviewExecutorFactory
{
    private function __construct()
    {
    }

    public static function create(
        ?callable $parallelMap = null,
        ?callable $runtimeProbe = null,
        ?ReviewExecutor $fallback = null,
        int $deadlineMs = ForkedReviewExecutor::DEFAULT_DEADLINE_MS,
        int $maxResultBytes = ForkedReviewExecutor::DEFAULT_MAX_RESULT_BYTES
    ): AdaptiveReviewExecutor {
        $supported = $runtimeProbe === null
            ? ForkedReviewExecutor::runtimeSupported()
            : ($runtimeProbe)();
        if (!is_bool($supported)) {
            throw new InvalidArgumentException(
                'Parallel Review runtime probe must return a boolean.'
            );
        }
        if (!$supported) {
            return new AdaptiveReviewExecutor(null, $fallback ?? new SerialReviewExecutor());
        }
        $parallelMap ??= [
            new ForkedReviewExecutor($deadlineMs, $maxResultBytes),
            'execute',
        ];
        return new AdaptiveReviewExecutor(
            $parallelMap,
            $fallback ?? new SerialReviewExecutor()
        );
    }
}
