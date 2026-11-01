<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

/**
 * Release approval for the exact production sources bound into an offline
 * Eval workflow manifest. Source drift is observable in reports and forces a
 * failed release until a reviewer explicitly pins the new manifest hash.
 */
final class ApprovedWorkflowManifestGate
{
    /** @param array<string,mixed> $current @return array<string,mixed> */
    public static function inspect(string $path, array $current): array
    {
        self::validateManifest($current, 'current');
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            throw new RuntimeException('Approved Eval workflow manifest is missing.');
        }
        $decoded = json_decode($bytes, true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)
            || array_keys($decoded) !== ['schema', 'approval_id', 'manifest']
            || ($decoded['schema'] ?? null) !== 'harness.eval.approved_workflow.v1'
            || !is_string($decoded['approval_id'] ?? null)
            || !is_array($decoded['manifest'] ?? null)) {
            throw new InvalidArgumentException('Approved Eval workflow manifest schema is invalid.');
        }
        $approvalId = HarnessPersistenceValue::identifier(
            $decoded['approval_id'],
            'eval.workflow_approval_id',
            96
        );
        $approved = $decoded['manifest'];
        self::validateManifest($approved, 'approved');
        if (($approved['workflow_id'] ?? null) !== ($current['workflow_id'] ?? null)) {
            throw new InvalidArgumentException(
                'Approved Eval workflow ID does not match the requested workflow.'
            );
        }

        $changedFiles = [];
        $approvedFiles = $approved['files'];
        $currentFiles = $current['files'];
        $paths = array_values(array_unique(array_merge(
            array_keys($approvedFiles),
            array_keys($currentFiles)
        )));
        sort($paths, SORT_STRING);
        foreach ($paths as $relative) {
            if (($approvedFiles[$relative] ?? null) !== ($currentFiles[$relative] ?? null)) {
                $changedFiles[] = $relative;
            }
        }
        $coordinates = ['workflow_name', 'workflow_version', 'entrypoint'];
        $coordinatesChanged = array_values(array_filter(
            $coordinates,
            static fn (string $field): bool => ($approved[$field] ?? null)
                !== ($current[$field] ?? null)
        ));
        $isApproved = hash_equals(
            (string)$approved['manifest_hash'],
            (string)$current['manifest_hash']
        ) && $changedFiles === [] && $coordinatesChanged === [];

        return [
            'schema' => 'harness.eval.workflow_approval_result.v1',
            'approval_id' => $approvalId,
            'approved' => $isApproved,
            'baseline_source_hash' => hash('sha256', $bytes),
            'approved_manifest_hash' => $approved['manifest_hash'],
            'current_manifest_hash' => $current['manifest_hash'],
            'changed_files' => $changedFiles,
            'changed_coordinates' => $coordinatesChanged,
        ];
    }

    /** @param array<string,mixed> $report @param array<string,mixed> $approval @return array<string,mixed> */
    public static function applyToReport(array $report, array $approval): array
    {
        if (($report['schema'] ?? null) !== 'harness.eval.report.v1'
            || !is_string($report['report_id'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/D', $report['report_id']) !== 1
            || !is_array($report['provenance'] ?? null)
            || !is_array($report['summary'] ?? null)
            || ($approval['schema'] ?? null)
                !== 'harness.eval.workflow_approval_result.v1'
            || !is_bool($approval['approved'] ?? null)
            || !is_string($approval['baseline_source_hash'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $approval['baseline_source_hash']) !== 1) {
            throw new InvalidArgumentException('Eval report workflow approval evidence is invalid.');
        }
        $priorReportId = $report['report_id'];
        $report['provenance']['workflow_approval'] = $approval;
        $report['summary']['workflow_manifest_approved'] = $approval['approved'];
        $report['summary']['release_pass'] = ($report['summary']['release_pass'] ?? false) === true
            && $approval['approved'] === true;
        $report['report_id'] = substr(hash('sha256', implode("\0", [
            'offline-eval-approved-report-v1',
            $priorReportId,
            HarnessPersistenceValue::canonicalJson($approval),
        ])), 0, 32);
        return $report;
    }

    /**
     * Explicitly replace the pinned baseline only when the caller repeats the
     * exact current manifest hash on the command line.
     *
     * @param array<string,mixed> $manifest
     * @return array{path:string,source_hash:string,manifest_hash:string}
     */
    public static function approve(
        string $path,
        array $manifest,
        string $expectedManifestHash
    ): array {
        self::validateManifest($manifest, 'approval candidate');
        $expectedManifestHash = HarnessPersistenceValue::hash(
            $expectedManifestHash,
            'eval.expected_manifest_hash'
        );
        if (!hash_equals($manifest['manifest_hash'], $expectedManifestHash)) {
            throw new HarnessIdempotencyConflictException(
                'Expected manifest hash does not match current production sources.'
            );
        }
        $directory = dirname($path);
        if (!is_dir($directory)
            && !mkdir($directory, 0775, true)
            && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the Eval approval directory.');
        }
        $payload = [
            'schema' => 'harness.eval.approved_workflow.v1',
            'approval_id' => $manifest['workflow_id'] . '-approved-v1',
            'manifest' => $manifest,
        ];
        $bytes = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
            throw new RuntimeException('Unable to write the approved Eval workflow manifest.');
        }
        return [
            'path' => $path,
            'source_hash' => hash('sha256', $bytes),
            'manifest_hash' => $manifest['manifest_hash'],
        ];
    }

    /** @param array<string,mixed> $manifest */
    private static function validateManifest(array $manifest, string $label): void
    {
        $required = [
            'workflow_id', 'workflow_name', 'workflow_version',
            'entrypoint', 'files', 'manifest_hash',
        ];
        if (array_keys($manifest) !== $required || !is_array($manifest['files'])) {
            throw new InvalidArgumentException("Eval {$label} workflow manifest fields are invalid.");
        }
        foreach (['workflow_id', 'workflow_name', 'workflow_version'] as $field) {
            HarnessPersistenceValue::identifier(
                $manifest[$field] ?? null,
                "eval.{$label}.{$field}",
                64
            );
        }
        $entrypoint = HarnessPersistenceValue::asciiString(
            $manifest['entrypoint'] ?? null,
            "eval.{$label}.entrypoint",
            1,
            191
        );
        if (str_contains($entrypoint, '..') || str_starts_with($entrypoint, '/')) {
            throw new InvalidArgumentException("Eval {$label} entrypoint is unsafe.");
        }
        if ($manifest['files'] === []) {
            throw new InvalidArgumentException("Eval {$label} workflow source set is empty.");
        }
        foreach ($manifest['files'] as $relative => $hash) {
            if (!is_string($relative) || $relative === ''
                || str_contains($relative, '..') || str_starts_with($relative, '/')
                || !is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new InvalidArgumentException(
                    "Eval {$label} workflow source evidence is invalid."
                );
            }
        }
        $manifestHash = HarnessPersistenceValue::hash(
            $manifest['manifest_hash'] ?? null,
            "eval.{$label}.manifest_hash"
        );
        $payload = $manifest;
        unset($payload['manifest_hash']);
        if (!hash_equals(
            $manifestHash,
            hash('sha256', HarnessPersistenceValue::canonicalJson($payload))
        )) {
            throw new InvalidArgumentException(
                "Eval {$label} workflow manifest checksum mismatch."
            );
        }
    }

    private function __construct()
    {
    }
}
