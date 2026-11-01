<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/EvalContracts.php';

final class DeterministicOfflineHardGate implements OfflineHarnessHardGate
{
    public function ruleVersion(): string { return 'hard-rules-1.0.0'; }

    public function evaluate(HarnessEvalCase $case): HarnessHardDecision
    {
        $input = $case->input;
        $findings = [];
        $canon = self::stringMap($input['canon_facts'] ?? [], 'canon_facts');
        $candidate = self::stringMap($input['candidate_facts'] ?? [], 'candidate_facts');
        $immutable = self::stringList($input['immutable_keys'] ?? [], 'immutable_keys');
        $explained = self::stringList($input['explained_changes'] ?? [], 'explained_changes');
        foreach ($immutable as $key) {
            if (array_key_exists($key, $canon)
                && array_key_exists($key, $candidate)
                && $canon[$key] !== $candidate[$key]
                && !in_array($key, $explained, true)) {
                $findings[] = new HarnessHardFinding('CANON_FACT_CONFLICT', [
                    'fact' => $key,
                    'canon_hash' => hash('sha256', $canon[$key]),
                    'candidate_hash' => hash('sha256', $candidate[$key]),
                ]);
            }
        }

        $content = is_string($input['content'] ?? null) ? $input['content'] : '';
        foreach (self::stringList($input['required_points'] ?? [], 'required_points') as $point) {
            if (!str_contains($content, $point)) {
                $findings[] = new HarnessHardFinding('OUTLINE_REQUIRED_POINT_MISSING', [
                    'point_hash' => hash('sha256', $point),
                ]);
            }
        }

        $requiredContext = self::stringList(
            $input['required_context'] ?? [],
            'required_context'
        );
        $availableContext = self::stringList(
            $input['available_context'] ?? [],
            'available_context'
        );
        foreach (array_diff($requiredContext, $availableContext) as $contextId) {
            $findings[] = new HarnessHardFinding('REQUIRED_CONTEXT_MISSING', [
                'context_id' => $contextId,
            ]);
        }

        $requiredPov = $input['required_pov'] ?? null;
        $candidatePov = $input['candidate_pov'] ?? null;
        if (($input['pov_hard'] ?? false) === true
            && is_string($requiredPov) && is_string($candidatePov)
            && $requiredPov !== $candidatePov) {
            $findings[] = new HarnessHardFinding('POV_CONTRACT_CONFLICT', [
                'required' => $requiredPov,
                'actual' => $candidatePov,
            ]);
        }

        $minChars = $input['min_chars'] ?? null;
        if (is_int($minChars) && $minChars > 0 && self::length($content) < $minChars) {
            $findings[] = new HarnessHardFinding('TRUNCATED_DRAFT', [
                'minimum_chars' => $minChars,
                'actual_chars' => self::length($content),
            ]);
        }
        if (($input['stream_complete'] ?? true) !== true) {
            $findings[] = new HarnessHardFinding('INCOMPLETE_MODEL_STREAM', [
                'stream_complete' => false,
            ]);
        }
        return new HarnessHardDecision($findings);
    }

    /** @return array<string,string> */
    private static function stringMap(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException("{$field} must be an object.");
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) || !is_string($item)) {
                throw new InvalidArgumentException("{$field} must contain strings.");
            }
            $result[$key] = $item;
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    /** @return list<string> */
    private static function stringList(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException("{$field} must be a list.");
        }
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new InvalidArgumentException("{$field} must contain non-empty strings.");
            }
        }
        sort($value, SORT_STRING);
        return array_values(array_unique($value));
    }

    private static function length(string $content): int
    {
        return function_exists('mb_strlen')
            ? mb_strlen($content, 'UTF-8')
            : strlen($content);
    }
}
