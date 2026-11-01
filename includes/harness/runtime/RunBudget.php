<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/RuntimeData.php';
require_once __DIR__ . '/RuntimeExceptions.php';

final readonly class RunBudget
{
    // Token limits remain enforced. Monetary fields are inert legacy checkpoint placeholders.
    public const DEFAULT_TOKEN_LIMIT = 120000;
    public const DEFAULT_COST_LIMIT = '0';
    public int $tokenLimit;
    public string $costLimit;
    public int $inputTokens;
    public int $outputTokens;
    public string $cost;

    public function __construct(
        int $tokenLimit = self::DEFAULT_TOKEN_LIMIT,
        string $costLimit = self::DEFAULT_COST_LIMIT,
        int $inputTokens = 0,
        int $outputTokens = 0,
        string $cost = '0'
    ) {
        $this->tokenLimit = HarnessPersistenceValue::nonNegativeInt(
            $tokenLimit,
            'runtime.token_limit'
        );
        $this->costLimit = '0';
        $this->inputTokens = HarnessPersistenceValue::nonNegativeInt(
            $inputTokens,
            'runtime.input_tokens'
        );
        $this->outputTokens = HarnessPersistenceValue::nonNegativeInt(
            $outputTokens,
            'runtime.output_tokens'
        );
        $this->cost = '0';
        $this->assertWithinLimits();
    }

    public function totalTokens(): int
    {
        if ($this->inputTokens > PHP_INT_MAX - $this->outputTokens) {
            throw new RunBudgetExceededException('Run token usage overflowed the integer range.');
        }
        return $this->inputTokens + $this->outputTokens;
    }

    public static function forChapter(): self
    {
        return new self(self::DEFAULT_TOKEN_LIMIT, self::DEFAULT_COST_LIMIT);
    }

    public function assertFunded(): void
    {
        if ($this->tokenLimit <= 0) {
            throw new RunBudgetExceededException('Chapter Run requires a positive token limit.');
        }
    }

    public function consume(int $inputTokens, int $outputTokens, string $cost): self
    {
        $inputTokens = HarnessPersistenceValue::nonNegativeInt(
            $inputTokens,
            'runtime.consumed_input_tokens'
        );
        $outputTokens = HarnessPersistenceValue::nonNegativeInt(
            $outputTokens,
            'runtime.consumed_output_tokens'
        );

        if ($inputTokens > PHP_INT_MAX - $this->inputTokens
            || $outputTokens > PHP_INT_MAX - $this->outputTokens) {
            throw new RunBudgetExceededException('Run token usage overflowed the integer range.');
        }

        return new self(
            $this->tokenLimit,
            $this->costLimit,
            $this->inputTokens + $inputTokens,
            $this->outputTokens + $outputTokens,
            '0'
        );
    }

    /** @return array{token_limit:int,cost_limit:string,input_tokens:int,output_tokens:int,cost:string} */
    public function toArray(): array
    {
        return [
            'token_limit' => $this->tokenLimit,
            'cost_limit' => $this->costLimit,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cost' => $this->cost,
        ];
    }

    /** @param array<string,mixed> $value */
    public static function fromArray(array $value): self
    {
        foreach (['token_limit', 'input_tokens', 'output_tokens'] as $field) {
            if (!array_key_exists($field, $value)) {
                throw new InvalidArgumentException('Run budget is missing a required field.');
            }
        }

        return new self(
            HarnessPersistenceValue::nonNegativeInt($value['token_limit'], 'runtime.token_limit'),
            '0',
            HarnessPersistenceValue::nonNegativeInt($value['input_tokens'], 'runtime.input_tokens'),
            HarnessPersistenceValue::nonNegativeInt($value['output_tokens'], 'runtime.output_tokens'),
            '0'
        );
    }

    private function assertWithinLimits(): void
    {
        if ($this->totalTokens() > $this->tokenLimit) {
            throw new RunBudgetExceededException('Run token budget was exceeded.');
        }

    }

    public static function decimalToMicros(string $value): int
    {
        $value = HarnessPersistenceValue::decimal($value, 'runtime.decimal');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $wholeValue = (int)$whole;
        if ((string)$wholeValue !== $whole && $whole !== '0') {
            throw new InvalidArgumentException('Runtime decimal exceeds the supported range.');
        }
        if ($wholeValue > intdiv(PHP_INT_MAX, 1000000)) {
            throw new InvalidArgumentException('Runtime decimal exceeds the supported range.');
        }
        $micros = $wholeValue * 1000000 + (int)str_pad($fraction, 6, '0');
        if ($micros < 0) {
            throw new InvalidArgumentException('Runtime decimal exceeds the supported range.');
        }
        return $micros;
    }

    public static function microsToDecimal(int $micros): string
    {
        if ($micros < 0) {
            throw new InvalidArgumentException('Runtime decimal must be non-negative.');
        }
        $whole = intdiv($micros, 1000000);
        $fraction = $micros % 1000000;
        if ($fraction === 0) {
            return (string)$whole;
        }
        return $whole . '.' . rtrim(str_pad((string)$fraction, 6, '0', STR_PAD_LEFT), '0');
    }
}
