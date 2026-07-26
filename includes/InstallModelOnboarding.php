<?php
defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/model_presets.php';

interface InstallModelOnboardingStore
{
    public function begin(): void;
    public function commit(): void;
    public function rollBack(): void;
    public function clearDefaults(): void;
    public function findByEndpoint(string $apiUrl, string $modelName): ?array;
    public function insert(array $data): int;
    public function update(int $id, array $data): void;
    public function setDefault(int $id): void;
}

final class InstallModelDbStore implements InstallModelOnboardingStore
{
    public function begin(): void
    {
        if (!DB::beginTransaction()) throw new RuntimeException('无法开启模型保存事务');
    }

    public function commit(): void
    {
        if (!DB::commit()) throw new RuntimeException('模型保存事务提交失败');
    }

    public function rollBack(): void
    {
        DB::rollBack();
    }

    public function clearDefaults(): void
    {
        DB::execute('UPDATE ai_models SET is_default=0');
    }

    public function findByEndpoint(string $apiUrl, string $modelName): ?array
    {
        $row = DB::fetch(
            'SELECT id, api_url, model_name FROM ai_models WHERE api_url=? AND model_name=? LIMIT 1',
            [$apiUrl, $modelName]
        );
        return is_array($row) ? $row : null;
    }

    public function insert(array $data): int
    {
        return (int)DB::insert('ai_models', $data);
    }

    public function update(int $id, array $data): void
    {
        DB::update('ai_models', $data, 'id=?', [$id]);
    }

    public function setDefault(int $id): void
    {
        DB::update('ai_models', ['is_default' => 1], 'id=?', [$id]);
    }
}

final class InstallModelOnboarding
{
    private InstallModelOnboardingStore $store;
    private $tester;
    private array $presets;

    public function __construct(
        InstallModelOnboardingStore $store,
        callable $tester,
        ?array $presets = null
    ) {
        $this->store = $store;
        $this->tester = $tester;
        $this->presets = $presets ?? getInstallSkyhostModelPresets();
    }

    /**
     * @return array{results:array<string,array{ok:bool,message:string}>,successful_models:string[]}
     */
    public function testModels(string $apiKey, array $selectedModels): array
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') throw new InvalidArgumentException('API Key 不能为空');

        $selected = $this->selectPresets($selectedModels);
        $results = [];
        $successful = [];
        foreach ($selected as $modelName => $preset) {
            $testCfg = $this->buildModelData($apiKey, $preset);
            $testCfg['id'] = 0;
            $testCfg['max_tokens'] = 64;
            $testCfg['temperature'] = 0.1;

            try {
                $reply = trim((string)call_user_func($this->tester, $testCfg));
                if ($reply === '') throw new RuntimeException('模型返回空响应');
                $successful[$modelName] = $preset;
                $results[$modelName] = ['ok' => true, 'message' => mb_substr($reply, 0, 300)];
            } catch (Throwable $e) {
                $message = trim(str_replace($apiKey, '[已隐藏]', $e->getMessage()));
                $results[$modelName] = [
                    'ok' => false,
                    'message' => mb_substr($message !== '' ? $message : '连接失败', 0, 300),
                ];
            }
        }

        return [
            'results' => $results,
            'successful_models' => array_keys($successful),
        ];
    }

    /**
     * @return array{saved_models:string[],default_model:string}
     */
    public function saveModels(string $apiKey, array $selectedModels, string $preferredDefault): array
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') throw new InvalidArgumentException('API Key 不能为空');

        $selected = $this->selectPresets($selectedModels);
        $defaultModel = isset($selected[$preferredDefault])
            ? $preferredDefault
            : array_key_first($selected);
        $savedIds = [];

        $this->store->begin();
        try {
            $this->store->clearDefaults();
            foreach ($selected as $modelName => $preset) {
                $data = $this->buildModelData($apiKey, $preset);
                $existing = $this->store->findByEndpoint(INSTALL_SKYHOST_API_URL, $modelName);
                if ($existing) {
                    $id = (int)$existing['id'];
                    $this->store->update($id, $data);
                } else {
                    $id = $this->store->insert($data);
                }
                $savedIds[$modelName] = $id;
            }
            $this->store->setDefault($savedIds[$defaultModel]);
            $this->store->commit();
        } catch (Throwable $e) {
            try {
                $this->store->rollBack();
            } catch (Throwable $re) {
                // 审计修复 C-5（2026-06-17）：外层回滚失败时记录日志
                // （嵌套异常通常意味着 commit 已成功或连接断开，但仍要留痕）
                error_log('InstallModelOnboarding: outer rollback failed — ' . $re->getMessage());
            }
            throw $e;
        }

        return [
            'saved_models' => array_keys($selected),
            'default_model' => $defaultModel,
        ];
    }

    /**
     * Backward-compatible combined operation for non-interactive callers.
     *
     * @return array{results:array<string,array{ok:bool,message:string}>,saved_models:string[],default_model:?string}
     */
    public function testAndSave(string $apiKey, array $selectedModels, string $preferredDefault): array
    {
        $tested = $this->testModels($apiKey, $selectedModels);
        if ($tested['successful_models'] === []) {
            return ['results' => $tested['results'], 'saved_models' => [], 'default_model' => null];
        }
        return array_merge(
            ['results' => $tested['results']],
            $this->saveModels($apiKey, $tested['successful_models'], $preferredDefault)
        );
    }

    private function selectPresets(array $selectedModels): array
    {
        $selected = [];
        foreach ($selectedModels as $modelName) {
            $modelName = trim((string)$modelName);
            if ($modelName === '' || isset($selected[$modelName])) continue;
            if (!isset($this->presets[$modelName])) {
                throw new InvalidArgumentException("不支持的安装模型：{$modelName}");
            }
            $selected[$modelName] = $this->presets[$modelName];
        }
        if ($selected === []) throw new InvalidArgumentException('请至少选择一个模型');
        return $selected;
    }

    private function buildModelData(string $apiKey, array $preset): array
    {
        return [
            'name' => (string)$preset['name'],
            'api_url' => INSTALL_SKYHOST_API_URL,
            'api_key' => $apiKey,
            'model_name' => (string)$preset['model_name'],
            'max_tokens' => (int)($preset['max_tokens'] ?? 8192),
            'temperature' => (float)($preset['temperature'] ?? 0.8),
            'is_default' => 0,
            'embedding_enabled' => 0,
            'thinking_enabled' => 0,
            'can_embed' => 0,
            'embedding_model_name' => '',
            'embedding_dim' => 0,
            'capabilities' => json_encode($preset['capabilities'] ?? [], JSON_UNESCAPED_UNICODE),
        ];
    }
}
