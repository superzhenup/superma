<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/persistence/HarnessPersistenceSupport.php';
require_once dirname(__DIR__) . '/persistence/HarnessSensitiveDataGuard.php';

final readonly class HarnessEvalCase
{
    /** @var array<string,mixed> */
    public array $input;
    /** @var list<string> */
    public array $expectedCodes;

    /** @param array<string,mixed> $input @param list<string> $expectedCodes */
    public function __construct(
        public string $id,
        public string $category,
        public bool $expectedBlock,
        array $input,
        array $expectedCodes = []
    ) {
        HarnessPersistenceValue::identifier($id, 'eval_case.id', 96);
        HarnessPersistenceValue::identifier($category, 'eval_case.category', 64);
        HarnessSensitiveDataGuard::assertSafeStructure($input, 'eval_case.input');
        foreach ($expectedCodes as $code) {
            if (!is_string($code) || preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', $code) !== 1) {
                throw new InvalidArgumentException('Eval expected rule code is invalid.');
            }
        }
        sort($expectedCodes, SORT_STRING);
        $this->input = $input;
        $this->expectedCodes = array_values(array_unique($expectedCodes));
    }

    /** @param array<string,mixed> $row */
    public static function fromArray(array $row): self
    {
        foreach (['id', 'category', 'expected_block', 'input'] as $field) {
            if (!array_key_exists($field, $row)) {
                throw new InvalidArgumentException("Eval case is missing {$field}.");
            }
        }
        if (!is_string($row['id']) || !is_string($row['category'])
            || !is_bool($row['expected_block']) || !is_array($row['input'])
            || (isset($row['expected_codes']) && !is_array($row['expected_codes']))) {
            throw new InvalidArgumentException('Eval case has invalid field types.');
        }
        return new self(
            $row['id'],
            $row['category'],
            $row['expected_block'],
            $row['input'],
            $row['expected_codes'] ?? []
        );
    }
}

final readonly class HarnessHardFinding
{
    /** @var array<string,mixed> */
    public array $evidence;

    /** @param array<string,mixed> $evidence */
    public function __construct(public string $code, array $evidence)
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', $code) !== 1) {
            throw new InvalidArgumentException('Hard finding code is invalid.');
        }
        HarnessSensitiveDataGuard::assertSafeStructure($evidence, 'hard_finding.evidence');
        $this->evidence = $evidence;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['code' => $this->code, 'evidence' => $this->evidence];
    }
}

final readonly class HarnessHardDecision
{
    /** @var list<HarnessHardFinding> */
    public array $findings;

    /** @param list<HarnessHardFinding> $findings */
    public function __construct(array $findings)
    {
        usort($findings, static fn (HarnessHardFinding $a, HarnessHardFinding $b): int =>
            strcmp($a->code, $b->code)
                ?: strcmp(
                    HarnessPersistenceValue::canonicalJson($a->evidence),
                    HarnessPersistenceValue::canonicalJson($b->evidence)
                )
        );
        $this->findings = $findings;
    }

    public function blocked(): bool { return $this->findings !== []; }
    /** @return list<string> */
    public function codes(): array
    {
        return array_values(array_unique(array_map(
            static fn (HarnessHardFinding $finding): string => $finding->code,
            $this->findings
        )));
    }
}

interface OfflineHarnessHardGate
{
    public function ruleVersion(): string;
    public function evaluate(HarnessEvalCase $case): HarnessHardDecision;
}
