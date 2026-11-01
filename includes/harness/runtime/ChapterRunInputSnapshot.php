<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

/**
 * Durable, credential-free input envelope for chapter_generate_v1.
 *
 * The snapshot deliberately contains the prose/configuration that is allowed
 * to influence Prompt compilation, plus hashes/coordinates for every model in
 * the fallback pool. Provider credentials are never persisted. A resumed Run
 * either consumes these values or refuses configuration drift before a model
 * is invoked.
 */
final readonly class ChapterRunInputSnapshot
{
    public const SCHEMA = 'harness.chapter_run.input_snapshot.v1';

    /** @var list<string> */
    private const SYSTEM_SETTING_PREFIXES = [
        'ws_', 'cf_', 'prompt_', 'context_', 'memory_', 'agent.',
    ];

    /** @var list<string> */
    private const NOVEL_SETTING_PREFIXES = ['ws_', 'cf_', 'prompt_', 'context_', 'memory_'];

    /** @var list<string> */
    private const NOVEL_SOURCE_FIELDS = [
        'id', 'user_id', 'title', 'genre', 'writing_style', 'protagonist_name',
        'protagonist_info', 'plot_settings', 'world_settings', 'extra_settings',
        'target_chapters', 'chapter_words', 'model_id', 'style_vector',
        'ref_author', 'author_profile_id', 'target_reader', 'narrative_structure',
        'narrative_method', 'narrative_pov', 'literary_genre', 'world_setting_era',
        'novel_types', 'writing_tone', 'protagonist_traits', 'core_conflicts',
        'appeal_points', 'taboos', 'opening_type', 'protagonist_entrance',
        'custom_settings', 'chapter_word_target',
    ];

    /** @var list<string> */
    private const CHAPTER_SOURCE_FIELDS = [
        'id', 'novel_id', 'chapter_number', 'title', 'outline', 'key_points',
        'hook', 'pacing', 'hook_type', 'cool_point_type', 'opening_type',
        'suspense', 'synopsis_id',
    ];

    /** @var array<string,mixed> */
    private array $payload;
    public string $checksum;

    /** @param array<string,mixed> $payloadWithoutChecksum */
    private function __construct(array $payloadWithoutChecksum)
    {
        $expectedKeys = [
            'schema', 'novel', 'chapter', 'models', 'system_settings',
            'novel_settings', 'prompt_sources',
        ];
        $actualKeys = array_keys($payloadWithoutChecksum);
        sort($expectedKeys, SORT_STRING);
        sort($actualKeys, SORT_STRING);
        if ($actualKeys !== $expectedKeys
            || ($payloadWithoutChecksum['schema'] ?? null) !== self::SCHEMA
            || !is_array($payloadWithoutChecksum['novel'] ?? null)
            || !is_array($payloadWithoutChecksum['chapter'] ?? null)
            || !is_array($payloadWithoutChecksum['models'] ?? null)
            || !array_is_list($payloadWithoutChecksum['models'])
            || !is_array($payloadWithoutChecksum['system_settings'] ?? null)
            || !is_array($payloadWithoutChecksum['novel_settings'] ?? null)
            || !is_array($payloadWithoutChecksum['prompt_sources'] ?? null)) {
            throw new InvalidArgumentException('Chapter Run input snapshot is malformed.');
        }
        $normalized = RuntimeData::normalizeArray(
            $payloadWithoutChecksum,
            'chapter_run.input_snapshot'
        );
        $novelId = HarnessPersistenceValue::positiveInt(
            $normalized['novel']['id'] ?? null,
            'chapter_run.input_snapshot.novel_id'
        );
        HarnessPersistenceValue::positiveInt(
            $normalized['chapter']['id'] ?? null,
            'chapter_run.input_snapshot.chapter_id'
        );
        if ((int)($normalized['chapter']['novel_id'] ?? 0) !== $novelId) {
            throw new InvalidArgumentException(
                'Chapter Run input snapshot Chapter does not belong to its Novel.'
            );
        }
        foreach ($normalized['prompt_sources'] as $name => $hash) {
            HarnessPersistenceValue::identifier($name, 'chapter_run.prompt_source', 80);
            HarnessPersistenceValue::hash($hash, 'chapter_run.prompt_source_hash');
        }
        $this->payload = $normalized;
        $this->checksum = RuntimeData::hashArray(
            $normalized,
            'chapter_run.input_snapshot'
        );
    }

    /**
     * @param array<string,mixed> $novel
     * @param array<string,mixed> $chapter
     * @param list<array<string,mixed>> $modelRows
     * @param list<array<string,mixed>> $systemSettingRows
     * @param list<array<string,mixed>> $novelSettingRows
     * @param array<string,string> $promptSources
     */
    public static function capture(
        array $novel,
        array $chapter,
        array $modelRows,
        array $systemSettingRows,
        array $novelSettingRows,
        array $promptSources
    ): self {
        $novel = self::sourceRow($novel, self::NOVEL_SOURCE_FIELDS);
        $chapter = self::sourceRow($chapter, self::CHAPTER_SOURCE_FIELDS);
        $models = self::normalizeModels($modelRows);
        $systemSettings = self::normalizeSettings(
            $systemSettingRows,
            self::SYSTEM_SETTING_PREFIXES
        );
        $novelSettings = self::normalizeSettings(
            $novelSettingRows,
            self::NOVEL_SETTING_PREFIXES
        );
        ksort($promptSources, SORT_STRING);
        return new self([
            'schema' => self::SCHEMA,
            'novel' => $novel,
            'chapter' => $chapter,
            'models' => $models,
            'system_settings' => $systemSettings,
            'novel_settings' => $novelSettings,
            'prompt_sources' => $promptSources,
        ]);
    }

    /** @param array<string,mixed> $value */
    public static function fromArray(array $value): self
    {
        $checksum = $value['checksum'] ?? null;
        unset($value['checksum']);
        $snapshot = new self($value);
        if (!is_string($checksum)
            || preg_match('/^[a-f0-9]{64}$/D', $checksum) !== 1
            || !hash_equals($snapshot->checksum, $checksum)) {
            throw new WorkflowCheckpointException(
                'Chapter Run input snapshot checksum is missing or stale.'
            );
        }
        return $snapshot;
    }

    public static function fromRunRequest(RunRequest $request): self
    {
        $value = $request->flagSnapshot['input_snapshot'] ?? null;
        $checksum = $request->flagSnapshot['input_snapshot_checksum'] ?? null;
        if (!is_array($value) || !is_string($checksum)) {
            throw new WorkflowCheckpointException(
                'Chapter Run request omitted its frozen input snapshot.'
            );
        }
        $snapshot = self::fromArray($value);
        if (!hash_equals($snapshot->checksum, $checksum)
            || ($request->goal['input_snapshot_checksum'] ?? null) !== $checksum) {
            throw new WorkflowCheckpointException(
                'Chapter Run request does not authenticate its input snapshot.'
            );
        }
        return $snapshot;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->payload + ['checksum' => $this->checksum];
    }

    /** @return array<string,mixed> */
    public function novel(): array
    {
        return $this->payload['novel'];
    }

    /** @return array<string,mixed> */
    public function chapter(): array
    {
        return $this->payload['chapter'];
    }

    /** @return array<string,string> */
    public function systemSettings(): array
    {
        return $this->payload['system_settings'];
    }

    /** @return array<string,string> */
    public function novelSettings(): array
    {
        return $this->payload['novel_settings'];
    }

    public function defaultModelId(): int
    {
        $models = $this->payload['models'];
        usort($models, static function (array $a, array $b): int {
            $default = (int)$b['is_default'] <=> (int)$a['is_default'];
            return $default !== 0 ? $default : (int)$a['id'] <=> (int)$b['id'];
        });
        return (int)($models[0]['id'] ?? 0);
    }

    public function bindInputHash(string $requestInputHash): string
    {
        HarnessPersistenceValue::hash($requestInputHash, 'chapter_run.request_input_hash');
        return hash('sha256', HarnessPersistenceValue::canonicalJson([
            'schema' => 'harness.chapter_run.bound_input.v1',
            'request_input_hash' => $requestInputHash,
            'input_snapshot_checksum' => $this->checksum,
        ]));
    }

    /**
     * Stable admission-time identity for the Prompt templates and settings.
     * Chapter prose and model credentials are deliberately excluded: content
     * is already bound by input_hash and the selected model is an Attempt
     * dimension of its own.
     */
    public function promptManifestHash(): string
    {
        return RuntimeData::hashArray([
            'schema' => 'harness.chapter_prompt_manifest.v1',
            'workflow_name' => 'chapter_generate',
            'workflow_version' => 'v1',
            'system_settings' => $this->payload['system_settings'],
            'novel_settings' => $this->payload['novel_settings'],
            'prompt_sources' => $this->payload['prompt_sources'],
        ], 'chapter_run.prompt_manifest');
    }

    public function assertScope(int $novelId, int $chapterId): void
    {
        if ((int)$this->payload['novel']['id'] !== $novelId
            || (int)$this->payload['chapter']['id'] !== $chapterId
            || (int)$this->payload['chapter']['novel_id'] !== $novelId) {
            throw new StaleRevisionException(
                'Frozen Chapter input snapshot escapes the immutable Run scope.'
            );
        }
    }

    /** @param array<string,mixed> $live */
    public function applyNovel(array $live): array
    {
        return array_replace($live, $this->novel());
    }

    /** @param array<string,mixed> $live */
    public function applyChapter(array $live): array
    {
        return array_replace($live, $this->chapter());
    }

    /** @param list<array<string,mixed>> $rows */
    public function assertModelRowsMatch(array $rows): void
    {
        $actual = self::normalizeModels($rows);
        // Older authenticated envelopes may contain retired metadata. Compare only current model fields.
        $currentFields = $actual[0] ?? [];
        $expected = array_map(
            static fn(array $model): array => array_intersect_key($model, $currentFields),
            $this->payload['models']
        );
        if (!hash_equals(
            RuntimeData::hashArray($expected, 'chapter_run.models'),
            RuntimeData::hashArray($actual, 'chapter_run.models')
        )) {
            throw new RuntimeControlException(
                'Chapter Run model/fallback configuration changed after admission.'
            );
        }
    }

    /** @param array<string,mixed> $row */
    public function assertModelRowMatches(array $row): void
    {
        $actual = self::normalizeModels([$row]);
        $modelId = (int)$actual[0]['id'];
        $expected = null;
        foreach ($this->payload['models'] as $model) {
            if ((int)$model['id'] === $modelId) {
                $expected = array_intersect_key($model, $actual[0]);
                break;
            }
        }
        if (!is_array($expected) || !hash_equals(
            RuntimeData::hashArray($expected, 'chapter_run.model'),
            RuntimeData::hashArray($actual[0], 'chapter_run.model')
        )) {
            throw new RuntimeControlException(
                'Chapter Run model configuration changed after admission.'
            );
        }
    }

    /**
     * @param list<array<string,mixed>> $systemRows
     * @param list<array<string,mixed>> $novelRows
     * @param array<string,string> $promptSources
     */
    public function assertPromptConfigurationMatches(
        array $systemRows,
        array $novelRows,
        array $promptSources
    ): void {
        $system = self::normalizeSettings($systemRows, self::SYSTEM_SETTING_PREFIXES);
        $novel = self::normalizeSettings($novelRows, self::NOVEL_SETTING_PREFIXES);
        ksort($promptSources, SORT_STRING);
        foreach ($promptSources as $hash) {
            HarnessPersistenceValue::hash($hash, 'chapter_run.prompt_source_hash');
        }
        $expected = [
            'system_settings' => $this->payload['system_settings'],
            'novel_settings' => $this->payload['novel_settings'],
            'prompt_sources' => $this->payload['prompt_sources'],
        ];
        $actual = [
            'system_settings' => $system,
            'novel_settings' => $novel,
            'prompt_sources' => $promptSources,
        ];
        if (!hash_equals(
            RuntimeData::hashArray($expected, 'chapter_run.prompt_configuration'),
            RuntimeData::hashArray($actual, 'chapter_run.prompt_configuration')
        )) {
            throw new RuntimeControlException(
                'Chapter Run Prompt configuration/source changed after admission.'
            );
        }
    }

    /** @param array<string,mixed> $row @param list<string> $allowed */
    private static function sourceRow(array $row, array $allowed): array
    {
        $source = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $row)) {
                $value = $row[$field];
                if (is_object($value) || is_resource($value)) {
                    throw new InvalidArgumentException(
                        "Chapter Run source field {$field} is not JSON-compatible."
                    );
                }
                $source[$field] = $value;
            }
        }
        ksort($source, SORT_STRING);
        return $source;
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private static function normalizeModels(array $rows): array
    {
        $models = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('Model snapshot row is malformed.');
            }
            $url = (string)($row['api_url'] ?? '');
            $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
            $capabilities = $row['capabilities'] ?? [];
            if (is_string($capabilities)) {
                $decoded = json_decode($capabilities, true);
                $capabilities = is_array($decoded) ? $decoded : [];
            }
            $capabilities = array_values(array_unique(array_map('strval',
                is_array($capabilities) ? $capabilities : []
            )));
            sort($capabilities, SORT_STRING);
            $models[] = [
                'id' => (int)($row['id'] ?? 0),
                'name' => (string)($row['name'] ?? ''),
                'provider' => self::providerCoordinate($host),
                'endpoint_hash' => hash('sha256', $url),
                'model_name' => (string)($row['model_name'] ?? ''),
                'max_tokens' => (int)($row['max_tokens'] ?? 0),
                'temperature' => self::normalizeFloat($row['temperature'] ?? 0),
                'is_default' => (int)($row['is_default'] ?? 0),
                'embedding_enabled' => (int)($row['embedding_enabled'] ?? 0),
                'thinking_enabled' => (int)($row['thinking_enabled'] ?? 0),
                'can_embed' => (int)($row['can_embed'] ?? 0),
                'embedding_model_name' => (string)($row['embedding_model_name'] ?? ''),
                'embedding_dim' => (int)($row['embedding_dim'] ?? 0),
                'capabilities' => $capabilities,
            ];
        }
        usort($models, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        foreach ($models as $model) {
            HarnessPersistenceValue::positiveInt($model['id'], 'chapter_run.model_id');
            HarnessPersistenceValue::string($model['model_name'], 'chapter_run.model_name', 1, 191);
        }
        return $models;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $prefixes
     * @return array<string,string>
     */
    private static function normalizeSettings(array $rows, array $prefixes): array
    {
        $settings = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('Setting snapshot row is malformed.');
            }
            $key = (string)($row['setting_key'] ?? '');
            $included = $key === 'global_embedding_model_id';
            foreach ($prefixes as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    $included = true;
                    break;
                }
            }
            if (!$included
                || str_starts_with($key, 'ws_harness_')
                || self::looksSensitiveKey($key)) {
                continue;
            }
            if (array_key_exists($key, $settings)) {
                throw new InvalidArgumentException('Setting snapshot contains a duplicate key.');
            }
            $settings[$key] = (string)($row['setting_value'] ?? '');
        }
        ksort($settings, SORT_STRING);
        return $settings;
    }

    private static function looksSensitiveKey(string $key): bool
    {
        $key = strtolower((string)preg_replace('/[^a-z0-9]+/i', '', $key));
        foreach (['apikey', 'password', 'passwd', 'credential', 'secret'] as $part) {
            if (str_contains($key, $part)) {
                return true;
            }
        }
        return str_ends_with($key, 'token');
    }

    private static function providerCoordinate(string $host): string
    {
        foreach ([
            'anthropic.com' => 'anthropic', 'openai.com' => 'openai',
            'deepseek.com' => 'deepseek', 'volces.com' => 'volces',
            'aliyuncs.com' => 'aliyun', 'bigmodel.cn' => 'zhipu',
            'siliconflow' => 'siliconflow', 'moonshot' => 'moonshot',
            'minimax' => 'minimax',
        ] as $needle => $provider) {
            if (str_contains($host, $needle)) {
                return $provider;
            }
        }
        return 'compatible-' . substr(hash('sha256', $host), 0, 16);
    }

    private static function normalizeFloat(mixed $value): string
    {
        $float = (float)$value;
        if (!is_finite($float)) {
            throw new InvalidArgumentException('Model temperature must be finite.');
        }
        return rtrim(rtrim(sprintf('%.8F', $float), '0'), '.') ?: '0';
    }
}

/** Production DB/source capture kept separate from the pure snapshot value. */
final class ChapterRunInputSnapshotFactory
{
    /** @param array<string,mixed> $novel @param array<string,mixed> $chapter */
    public static function capture(array $novel, array $chapter): ChapterRunInputSnapshot
    {
        $novelId = HarnessPersistenceValue::positiveInt(
            $novel['id'] ?? null,
            'chapter_run.capture.novel_id'
        );
        return ChapterRunInputSnapshot::capture(
            $novel,
            $chapter,
            DB::fetchAll('SELECT * FROM ai_models ORDER BY id ASC'),
            DB::fetchAll(
                'SELECT setting_key, setting_value FROM system_settings '
                    . 'ORDER BY setting_key ASC'
            ),
            DB::fetchAll(
                'SELECT setting_key, setting_value FROM novel_settings '
                    . 'WHERE novel_id=? ORDER BY setting_key ASC',
                [$novelId]
            ),
            self::promptSourceHashes()
        );
    }

    public static function assertCurrent(ChapterRunInputSnapshot $snapshot): void
    {
        $novelId = (int)$snapshot->novel()['id'];
        $snapshot->assertModelRowsMatch(
            DB::fetchAll('SELECT * FROM ai_models ORDER BY id ASC')
        );
        $snapshot->assertPromptConfigurationMatches(
            DB::fetchAll(
                'SELECT setting_key, setting_value FROM system_settings '
                    . 'ORDER BY setting_key ASC'
            ),
            DB::fetchAll(
                'SELECT setting_key, setting_value FROM novel_settings '
                    . 'WHERE novel_id=? ORDER BY setting_key ASC',
                [$novelId]
            ),
            self::promptSourceHashes()
        );
    }

    /** @return array<string,string> */
    public static function promptSourceHashes(): array
    {
        $root = dirname(__DIR__, 3);
        $files = [
            'chapter_input_snapshot' => $root . '/includes/harness/runtime/ChapterRunInputSnapshot.php',
            'chapter_prompt_builder' => $root . '/includes/ChapterPromptBuilder.php',
            'chapter_workflow' => $root . '/config/workflows/chapter_generate_v1.php',
            'embedding_provider' => $root . '/includes/memory/EmbeddingProvider.php',
            'legacy_stage_adapter' => $root . '/includes/harness/stages/LegacyWriteEngineStageAdapter.php',
            'model_fallback' => $root . '/includes/ai/ModelFallback.php',
            'write_engine' => $root . '/includes/write_engine.php',
        ];
        $hashes = [];
        foreach ($files as $name => $path) {
            $hash = is_file($path) ? hash_file('sha256', $path) : false;
            if (!is_string($hash)) {
                throw new RuntimeControlException(
                    "Chapter Run Prompt source {$name} is unavailable."
                );
            }
            $hashes[$name] = $hash;
        }
        ksort($hashes, SORT_STRING);
        return $hashes;
    }

    private function __construct()
    {
    }
}

/** Scoped access to frozen setting values for Prompt/Context compilation. */
final class ChapterRunFrozenConfigScope
{
    /** @var list<ChapterRunInputSnapshot> */
    private static array $stack = [];

    public static function run(ChapterRunInputSnapshot $snapshot, callable $callback): mixed
    {
        self::$stack[] = $snapshot;
        try {
            return $callback();
        } finally {
            array_pop(self::$stack);
        }
    }

    public static function readSystem(
        string $key,
        mixed $default,
        string $type,
        bool &$handled
    ): mixed {
        $snapshot = self::current();
        $handled = $snapshot !== null && self::isFrozenKey($key, false);
        if (!$handled) {
            return $default;
        }
        $settings = $snapshot->systemSettings();
        return array_key_exists($key, $settings)
            ? self::cast($settings[$key], $type)
            : $default;
    }

    /** @param array<string,string> $live @return array<string,string> */
    public static function overlaySystemSettings(array $live): array
    {
        $snapshot = self::current();
        if ($snapshot === null) {
            return $live;
        }
        foreach (array_keys($live) as $key) {
            if (self::isFrozenKey((string)$key, false)) {
                unset($live[$key]);
            }
        }
        foreach ($snapshot->systemSettings() as $key => $value) {
            $live[$key] = $value;
        }
        return $live;
    }

    public static function readNovel(
        int $novelId,
        string $key,
        mixed $default,
        string $type,
        bool &$handled
    ): mixed {
        $snapshot = self::current();
        $handled = $snapshot !== null
            && (int)$snapshot->novel()['id'] === $novelId
            && self::isFrozenKey($key, true);
        if (!$handled) {
            return $default;
        }
        $novel = $snapshot->novelSettings();
        if (array_key_exists($key, $novel)) {
            return self::cast($novel[$key], $type);
        }
        $system = $snapshot->systemSettings();
        return array_key_exists($key, $system)
            ? self::cast($system[$key], $type)
            : $default;
    }

    /** @param array<string,mixed> $row */
    public static function assertModelRow(array $row): void
    {
        self::current()?->assertModelRowMatches($row);
    }

    /** @param list<array<string,mixed>> $rows */
    public static function assertModelRows(array $rows): void
    {
        self::current()?->assertModelRowsMatch($rows);
    }

    /** @param array<string,mixed> $row */
    public static function assertModelSelection(?int $preferredModelId, array $row): void
    {
        $snapshot = self::current();
        if ($snapshot === null) {
            return;
        }
        $snapshot->assertModelRowMatches($row);
        if ($preferredModelId !== null && $preferredModelId > 0
            && (int)($row['id'] ?? 0) !== $preferredModelId) {
            throw new RuntimeControlException(
                'Chapter Run preferred model coordinate changed after admission.'
            );
        }
        if (($preferredModelId === null || $preferredModelId <= 0)) {
            if ($snapshot->defaultModelId() !== (int)($row['id'] ?? 0)) {
                throw new RuntimeControlException(
                    'Chapter Run default model coordinate changed after admission.'
                );
            }
        }
    }

    private static function current(): ?ChapterRunInputSnapshot
    {
        $current = self::$stack[array_key_last(self::$stack)] ?? null;
        return $current instanceof ChapterRunInputSnapshot ? $current : null;
    }

    private static function isFrozenKey(string $key, bool $novel): bool
    {
        // Admission/feature flags are already frozen in HarnessFeatureSnapshot;
        // the emergency epoch is intentionally live and must never be masked.
        if (str_starts_with($key, 'ws_harness_')) {
            return false;
        }
        if ($key === 'global_embedding_model_id') {
            return true;
        }
        $prefixes = $novel
            ? ['ws_', 'cf_', 'prompt_', 'context_', 'memory_']
            : ['ws_', 'cf_', 'prompt_', 'context_', 'memory_', 'agent.'];
        foreach ($prefixes as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private static function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'int' => (int)$value,
            'float' => (float)$value,
            'bool' => in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true),
            default => (string)$value,
        };
    }

    private function __construct()
    {
    }
}
