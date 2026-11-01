<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/JsonSchemaValidator.php';

final class ToolRegistry
{
    /** @var array<string,array{descriptor:ToolDescriptor,handler:ToolHandler}> */
    private array $tools = [];

    public function register(ToolDescriptor $descriptor, ToolHandler $handler): void
    {
        if (isset($this->tools[$descriptor->name])) {
            throw new InvalidArgumentException("Tool {$descriptor->name} is already registered.");
        }
        if ($descriptor->name === 'chapter.commit_candidate'
            || str_contains($descriptor->name, '.commit_')) {
            throw new ToolPolicyViolationException(
                'Canonical commit tools must never be exposed to a model Tool registry.'
            );
        }
        $this->tools[$descriptor->name] = [
            'descriptor' => $descriptor,
            'handler' => $handler,
        ];
        ksort($this->tools, SORT_STRING);
    }

    public function descriptor(string $name): ToolDescriptor
    {
        return $this->tools[$name]['descriptor']
            ?? throw new ToolNotRegisteredException("Tool {$name} is not registered.");
    }

    public function handler(string $name): ToolHandler
    {
        return $this->tools[$name]['handler']
            ?? throw new ToolNotRegisteredException("Tool {$name} is not registered.");
    }

    /** @return list<ToolDescriptor> */
    public function descriptors(): array
    {
        return array_values(array_map(
            static fn (array $entry): ToolDescriptor => $entry['descriptor'],
            $this->tools
        ));
    }

    /** @return list<array<string,mixed>> */
    public function modelDescriptors(): array
    {
        return array_map(
            static fn (ToolDescriptor $descriptor): array => $descriptor->modelDescriptor(),
            $this->descriptors()
        );
    }

    /** @param list<string> $names */
    public function subset(array $names): self
    {
        $subset = new self();
        $seen = [];
        foreach ($names as $name) {
            if (!is_string($name) || isset($seen[$name])) {
                throw new InvalidArgumentException('Tool subset names must be unique strings.');
            }
            $seen[$name] = true;
            $subset->register($this->descriptor($name), $this->handler($name));
        }
        return $subset;
    }
}

final class ClosureToolHandler implements ToolHandler
{
    private Closure $handler;

    /** @param callable(ToolExecutionContext,array<string,mixed>):array<string,mixed> $handler */
    public function __construct(callable $handler)
    {
        $this->handler = Closure::fromCallable($handler);
    }

    public function execute(ToolExecutionContext $context, array $arguments): array
    {
        $result = ($this->handler)($context, $arguments);
        if (!is_array($result) || array_is_list($result)) {
            throw new RuntimeException('Tool handler must return an object array.');
        }
        return $result;
    }
}
