<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/RunStage.php';

final readonly class WorkflowDefinition
{
    /** @var list<RunStage> */
    private array $orderedStages;
    public string $definitionHash;

    /** @param list<RunStage> $stages */
    public function __construct(
        public string $name,
        public string $version,
        array $stages
    ) {
        HarnessPersistenceValue::identifier($name, 'workflow.name', 64);
        HarnessPersistenceValue::identifier($version, 'workflow.version', 32);
        if (!array_is_list($stages) || $stages === []) {
            throw new WorkflowDefinitionException(
                'Workflow stages must be a non-empty ordered list.'
            );
        }

        $seen = [];
        $definition = [];
        foreach ($stages as $ordinal => $stage) {
            if (!$stage instanceof RunStage) {
                throw new WorkflowDefinitionException(
                    'Workflow definitions may contain only RunStage instances.'
                );
            }
            $stageName = HarnessPersistenceValue::identifier(
                $stage->name(),
                'workflow.stage_name',
                64
            );
            $stageType = HarnessPersistenceValue::identifier(
                $stage->type(),
                'workflow.stage_type',
                64
            );
            if (isset($seen[$stageName])) {
                throw new WorkflowDefinitionException(
                    'Workflow Stage names must be unique within a version.'
                );
            }
            $seen[$stageName] = true;
            $definition[] = [
                'ordinal' => $ordinal,
                'name' => $stageName,
                'type' => $stageType,
                'retry_policy' => $stage->retryPolicy()->toArray(),
            ];
        }

        $this->orderedStages = $stages;
        $this->definitionHash = RuntimeData::hashArray([
            'name' => $name,
            'version' => $version,
            'stages' => $definition,
        ], 'workflow.definition');
    }

    /** @return list<RunStage> */
    public function stages(): array
    {
        return $this->orderedStages;
    }

    public function count(): int
    {
        return count($this->orderedStages);
    }

    public function assertRequest(RunRequest $request): void
    {
        if ($request->workflowName !== $this->name
            || $request->workflowVersion !== $this->version) {
            throw new WorkflowDefinitionException(
                'Run request workflow identity does not match its definition.'
            );
        }
    }
}
