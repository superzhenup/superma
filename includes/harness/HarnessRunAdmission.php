<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/HarnessEmergencyStop.php';
require_once __DIR__ . '/persistence/HarnessPersistenceSupport.php';

final class HarnessRunAdmissionException extends HarnessPersistenceException
{
}

/**
 * Admission is invoked from inside the generation_runs creation/claim
 * transaction. Implementations must lock the epoch before incident rows so
 * automatic stop and admission share one global lock order.
 */
interface HarnessRunAdmissionGuard
{
    public function assertDraftAdmitted(
        HarnessPersistenceDriver $db,
        GenerationRunDraft $draft
    ): void;

    /** @param array<string,mixed> $row */
    public function assertStoredRunAdmitted(
        HarnessPersistenceDriver $db,
        array $row
    ): void;
}

final class DurableHarnessRunAdmissionGuard implements HarnessRunAdmissionGuard
{
    public function assertDraftAdmitted(
        HarnessPersistenceDriver $db,
        GenerationRunDraft $draft
    ): void {
        $this->assertScopeAdmitted(
            $db,
            $draft->novelId,
            $draft->workflowName,
            $draft->workflowVersion,
            $draft->flagSnapshotJson,
            $draft->goalJson
        );
    }

    public function assertStoredRunAdmitted(
        HarnessPersistenceDriver $db,
        array $row
    ): void {
        foreach ([
            'novel_id',
            'workflow_name',
            'workflow_version',
            'flag_snapshot_json',
            'goal_json',
        ] as $field) {
            if (!array_key_exists($field, $row)) {
                throw new HarnessRunAdmissionException(
                    "Stored Harness Run is missing admission field {$field}."
                );
            }
        }
        try {
            $novelId = HarnessPersistenceValue::positiveInt(
                $row['novel_id'],
                'admission.novel_id'
            );
            $workflowName = HarnessPersistenceValue::identifier(
                $row['workflow_name'],
                'admission.workflow_name',
                64
            );
            $workflowVersion = HarnessPersistenceValue::identifier(
                $row['workflow_version'],
                'admission.workflow_version',
                32
            );
        } catch (Throwable $exception) {
            throw new HarnessRunAdmissionException(
                'Stored Harness Run has a malformed admission scope.',
                0,
                $exception
            );
        }
        $this->assertScopeAdmitted(
            $db,
            $novelId,
            $workflowName,
            $workflowVersion,
            $this->requireJsonString($row['flag_snapshot_json'], 'flag_snapshot_json'),
            $this->requireJsonString($row['goal_json'], 'goal_json')
        );
    }

    private function assertScopeAdmitted(
        HarnessPersistenceDriver $db,
        int $novelId,
        string $workflowName,
        string $workflowVersion,
        string $flagSnapshotJson,
        string $goalJson
    ): void {
        $capturedEpoch = $this->capturedEpoch($flagSnapshotJson, $goalJson);

        // This singleton row is deliberately locked before every incident key.
        // bumpOnce()/clearIncident() use the same order, so a Run insert either
        // commits before a stop (and is then an in-flight fenced Run) or observes
        // the stop; it can never be inserted through the race window.
        $epochRow = $db->fetchOne(
            'SELECT setting_value FROM system_settings WHERE setting_key=? FOR UPDATE',
            [HarnessEmergencyStop::SETTING_KEY]
        );
        $storedEpoch = $this->settingValue($epochRow, HarnessEmergencyStop::SETTING_KEY);
        try {
            $currentEpoch = HarnessEmergencyStop::normalizeEpoch($storedEpoch);
        } catch (Throwable $exception) {
            throw new HarnessRunAdmissionException(
                'Harness Run admission could not authenticate the emergency epoch.',
                0,
                $exception
            );
        }
        if ($capturedEpoch !== $currentEpoch) {
            throw new HarnessRunAdmissionException(sprintf(
                'Harness Run captured emergency epoch %d but durable epoch is %d.',
                $capturedEpoch,
                $currentEpoch
            ));
        }

        $fingerprints = HarnessEmergencyStop::matchingScopeFingerprints(
            $novelId,
            $workflowName,
            $workflowVersion
        );
        foreach ($fingerprints as $fingerprint) {
            $settingKey = HarnessEmergencyStop::incidentSettingKey($fingerprint);
            $incidentRow = $db->fetchOne(
                'SELECT setting_value FROM system_settings WHERE setting_key=? FOR UPDATE',
                [$settingKey]
            );
            if ($incidentRow === null) {
                continue;
            }
            $storedIncident = $this->settingValue($incidentRow, $settingKey);
            try {
                $disposition = HarnessEmergencyStop::incidentDisposition(
                    $storedIncident,
                    $currentEpoch
                );
            } catch (Throwable $exception) {
                throw new HarnessRunAdmissionException(
                    'Harness Run admission found a malformed stop incident.',
                    0,
                    $exception
                );
            }
            if ($disposition['state'] === 'active') {
                throw new HarnessRunAdmissionException(
                    'A matching automatic-stop incident is active; new Harness work is disabled.'
                );
            }
        }
    }

    private function capturedEpoch(string $flagSnapshotJson, string $goalJson): int
    {
        try {
            $flags = HarnessPersistenceValue::decodeJsonArray(
                $flagSnapshotJson,
                'admission.flag_snapshot_json'
            );
            $goal = HarnessPersistenceValue::decodeJsonArray(
                $goalJson,
                'admission.goal_json'
            );
        } catch (Throwable $exception) {
            throw new HarnessRunAdmissionException(
                'Harness Run admission snapshots are malformed.',
                0,
                $exception
            );
        }

        $values = [];
        if (array_key_exists('emergency_epoch', $flags)) {
            $values[] = $flags['emergency_epoch'];
        }
        if (array_key_exists('write_policy', $flags)) {
            if (!is_array($flags['write_policy'])
                || !array_key_exists('emergency_epoch', $flags['write_policy'])) {
                throw new HarnessRunAdmissionException(
                    'Harness Run write policy has no canonical emergency epoch.'
                );
            }
            $values[] = $flags['write_policy']['emergency_epoch'];
        }
        if (array_key_exists('emergency_epoch', $goal)) {
            $values[] = $goal['emergency_epoch'];
        }
        if ($values === []) {
            throw new HarnessRunAdmissionException(
                'Harness Run has no frozen emergency epoch and cannot be admitted.'
            );
        }

        try {
            $epochs = array_map(
                static fn (mixed $value): int => HarnessEmergencyStop::normalizeEpoch($value),
                $values
            );
        } catch (Throwable $exception) {
            throw new HarnessRunAdmissionException(
                'Harness Run has a malformed frozen emergency epoch.',
                0,
                $exception
            );
        }
        $captured = $epochs[0];
        foreach ($epochs as $epoch) {
            if ($epoch !== $captured) {
                throw new HarnessRunAdmissionException(
                    'Harness Run emergency epoch snapshots disagree.'
                );
            }
        }
        return $captured;
    }

    /** @param array<string,mixed>|null $row */
    private function settingValue(?array $row, string $settingKey): string
    {
        $value = $row['setting_value'] ?? null;
        if (!is_string($value)) {
            throw new HarnessRunAdmissionException(
                "Harness Run admission setting {$settingKey} is missing or malformed."
            );
        }
        return $value;
    }

    private function requireJsonString(mixed $value, string $field): string
    {
        if (!is_string($value) || $value === '') {
            throw new HarnessRunAdmissionException(
                "Stored Harness Run {$field} is missing or malformed."
            );
        }
        return $value;
    }
}

