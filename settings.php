<?php
define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';

$msg   = '';
$error = '';

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $name       = trim($_POST['name']       ?? '');
        $api_url    = trim($_POST['api_url']    ?? '');
        $api_key    = trim($_POST['api_key']    ?? '');
        $model_name = trim($_POST['model_name'] ?? '');
        $max_tokens = max(256, (int)($_POST['max_tokens']  ?? 8192));
        $temp       = min(2.0, max(0.0, (float)($_POST['temperature'] ?? 0.8)));
        $is_default = isset($_POST['is_default']) ? 1 : 0;
        $embedding_enabled = isset($_POST['embedding_enabled']) ? 1 : 0;
        $thinking_enabled  = isset($_POST['thinking_enabled'])  ? 1 : 0;

        if (!$name || !$api_url || !$model_name) {
            $error = '请填写名称、API地址和模型标识符。';
        } else {
            $isArkApi = stripos($api_url, 'ark.cn-beijing.volces.com') !== false
                     || stripos($api_url, 'volces.com') !== false;

            // 方舟API自动开启embedding（无需手动勾选）
            // 非方舟API不允许开启
            // 注意：前端 disabled 的 checkbox 不会随表单提交，所以这里必须后端强制修正
            if ($isArkApi) {
                $embedding_enabled = 1;
            } elseif ($embedding_enabled) {
                $error = '接口不支持Embedding模型，请使用方舟Coding Plan';
                $embedding_enabled = 0;
            }
            // embedding 模型名固定为方舟独占的 doubao-embedding-vision
            $embedding_model_name = $embedding_enabled ? 'doubao-embedding-vision' : '';
            if ($is_default) {
                DB::query('UPDATE ai_models SET is_default=0');
            }
            if ($action === 'edit') {
                $editId = (int)($_POST['edit_id'] ?? 0);
                DB::update('ai_models', [
                    'name'                => $name,
                    'api_url'             => $api_url,
                    'api_key'             => $api_key,
                    'model_name'          => $model_name,
                    'max_tokens'          => $max_tokens,
                    'temperature'         => $temp,
                    'is_default'          => $is_default,
                    'embedding_enabled'   => $embedding_enabled,
                    'thinking_enabled'    => $thinking_enabled,
                    'can_embed'           => $embedding_enabled,
                    'embedding_model_name'=> $embedding_model_name,
                ], 'id=?', [$editId]);
                $savedModelId = $editId;
                $msg = "模型「{$name}」已更新。";
            } else {
                DB::insert('ai_models', [
                    'name'                => $name,
                    'api_url'             => $api_url,
                    'api_key'             => $api_key,
                    'model_name'          => $model_name,
                    'max_tokens'          => $max_tokens,
                    'temperature'         => $temp,
                    'is_default'          => $is_default,
                    'embedding_enabled'   => $embedding_enabled,
                    'thinking_enabled'    => $thinking_enabled,
                    'can_embed'           => $embedding_enabled,
                    'embedding_model_name'=> $embedding_model_name,
                ]);
                $savedModelId = DB::lastId();
                $msg = "模型「{$name}」已添加。";
            }

            // ---- 桥接逻辑：同步 system_settings.global_embedding_model_id ----
            if ($embedding_enabled) {
                // 开启 embedding：将此模型 ID 写入全局设置
                $existing = DB::fetch(
                    'SELECT setting_value FROM system_settings WHERE setting_key=?',
                    ['global_embedding_model_id']
                );
                if ($existing) {
                    DB::update('system_settings',
                        ['setting_value' => (string)$savedModelId],
                        'setting_key=?', ['global_embedding_model_id']
                    );
                } else {
                    DB::insert('system_settings', [
                        'setting_key'   => 'global_embedding_model_id',
                        'setting_value' => (string)$savedModelId,
                    ]);
                }
                // 清除其他模型的 can_embed（全局只有一个 embedding 模型）
                DB::query('UPDATE ai_models SET can_embed=0 WHERE id!=?', [$savedModelId]);
            } else {
                // 关闭 embedding：如果此模型曾是全局 embedding 模型，清除全局设置
                $globalRow = DB::fetch(
                    'SELECT setting_value FROM system_settings WHERE setting_key=?',
                    ['global_embedding_model_id']
                );
                if ($globalRow && (int)$globalRow['setting_value'] === $savedModelId) {
                    DB::query("DELETE FROM system_settings WHERE setting_key='global_embedding_model_id'");
                }
            }
        }
    } elseif ($action === 'delete') {
        $delId = (int)($_POST['del_id'] ?? 0);
        $m = DB::fetch('SELECT name FROM ai_models WHERE id=?', [$delId]);
        if ($m) {
            DB::delete('ai_models', 'id=?', [$delId]);
            $msg = "模型「{$m['name']}」已删除。";
            // 清理：如果被删的是全局 embedding 模型，清除全局设置
            $globalRow = DB::fetch(
                'SELECT setting_value FROM system_settings WHERE setting_key=?',
                ['global_embedding_model_id']
            );
            if ($globalRow && (int)$globalRow['setting_value'] === $delId) {
                DB::query("DELETE FROM system_settings WHERE setting_key='global_embedding_model_id'");
            }
        }
    } elseif ($action === 'set_default') {
        $defId = (int)($_POST['def_id'] ?? 0);
        DB::query('UPDATE ai_models SET is_default=0');
        DB::update('ai_models', ['is_default' => 1], 'id=?', [$defId]);
        $msg = '默认模型已更新。';
    }
}

$models  = DB::fetchAll('SELECT * FROM ai_models ORDER BY is_default DESC, id ASC');
$editModel = null;
if (isset($_GET['edit'])) {
    $editModel = DB::fetch('SELECT * FROM ai_models WHERE id=?', [(int)$_GET['edit']]);
}

pageHeader('模型设置', 'settings');

// Preset model configs
$presets = [
    'CHIYUN'    => ['name'=>'赤云优算（官方）',       'api_url'=>'https://api.6zhen.cn/v1',        'model_name'=>'DeepSeek-V3.2'],
    'openai-gpt4o'    => ['name'=>'方舟Coding Plan',       'api_url'=>'https://ark.cn-beijing.volces.com/api/coding/v3',        'model_name'=>'DeepSeek-V3.2'],
    'openai-gpt35'    => ['name'=>'硅基流动',       'api_url'=>'https://api.siliconflow.cn/v1',        'model_name'=>'Qwen/Qwen3.6-35B-A3B'],
    'deepseek-chat'   => ['name'=>'DeepSeek Chat',         'api_url'=>'https://api.deepseek.com/v1',     'model_name'=>'deepseek-chat'],
    'deepseek-r1'     => ['name'=>'DeepSeek R1',           'api_url'=>'https://api.deepseek.com/v1',     'model_name'=>'deepseek-reasoner'],
    'moonshot-v1'     => ['name'=>'Moonshot Kimi',         'api_url'=>'https://api.moonshot.cn/v1',      'model_name'=>'moonshot-v1-8k'],
    'zhipu-glm4'      => ['name'=>'智谱 GLM-4',            'api_url'=>'https://open.bigmodel.cn/api/paas/v4', 'model_name'=>'glm-4'],
    'qwen-turbo'      => ['name'=>'通义千问 Turbo',         'api_url'=>'https://dashscope.aliyuncs.com/compatible-mode/v1', 'model_name'=>'qwen-turbo'],
    'qwen-plus'       => ['name'=>'通义千问 Plus',          'api_url'=>'https://dashscope.aliyuncs.com/compatible-mode/v1', 'model_name'=>'qwen-plus'],
    'claude-sonnet'   => ['name'=>'Claude Sonnet',         'api_url'=>'https://api.anthropic.com/v1',    'model_name'=>'claude-sonnet-4-6'],
    'ollama-local'    => ['name'=>'Ollama (本地)',          'api_url'=>'http://localhost:11434/v1',        'model_name'=>'llama3'],
    'custom'          => ['name'=>'自定义模型',             'api_url'=>'',                                'model_name'=>''],
];
?>

<div class="row g-4">

  <!-- Model List -->
  <div class="col-12 col-lg-7">
    <div class="page-card">
      <div class="page-card-header"><i class="bi bi-cpu me-2"></i>已配置模型</div>
      <?php if ($msg): ?>
      <div class="alert alert-success alert-sm m-3"><?= h($msg) ?></div>
      <?php endif; ?>
      <?php if (empty($models)): ?>
      <div class="empty-state py-4">
        <div class="empty-icon"><i class="bi bi-cpu"></i></div>
        <h6>尚未添加模型</h6>
        <p class="text-muted small">请在右侧表单添加您的AI模型</p>
      </div>
      <?php else: ?>
      <div class="list-group list-group-flush">
        <?php foreach ($models as $m): ?>
        <div class="list-group-item bg-transparent border-secondary model-item">
          <div class="d-flex align-items-start justify-content-between">
            <div class="flex-grow-1">
              <div class="d-flex align-items-center gap-2 mb-1">
                <span class="fw-semibold text-light"><?= h($m['name']) ?></span>
                <?php if ($m['is_default']): ?>
                <span class="badge bg-primary">默认</span>
                <?php endif; ?>
              </div>
              <div class="small text-muted">
                <span class="me-3"><i class="bi bi-tag me-1"></i><?= h($m['model_name']) ?></span>
                <span class="me-3"><i class="bi bi-link me-1"></i><?= h(parse_url($m['api_url'], PHP_URL_HOST) ?: $m['api_url']) ?></span>
              </div>
              <div class="small text-muted">
                max_tokens: <?= $m['max_tokens'] ?> · temperature: <?= $m['temperature'] ?>
                <?php if (!empty($m['thinking_enabled'])): ?>
                · <span class="text-info"><i class="bi bi-lightbulb me-1"></i>深度思考</span>
                <?php endif; ?>
              </div>
            </div>
            <div class="d-flex gap-1 ms-2 flex-shrink-0">
              <?php if (!$m['is_default']): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="set_default">
                <input type="hidden" name="def_id" value="<?= $m['id'] ?>">
                <button type="submit" class="btn btn-xs btn-outline-primary" title="设为默认">
                  <i class="bi bi-star"></i>
                </button>
              </form>
              <?php endif; ?>
              <button class="btn btn-xs btn-outline-info btn-edit-model"
                      data-model='<?= json_encode($m, JSON_HEX_APOS|JSON_UNESCAPED_UNICODE) ?>'>
                <i class="bi bi-pencil"></i>
              </button>
              <form method="post" class="d-inline"
                    onsubmit="return confirm('确定删除模型「<?= h(addslashes($m['name'])) ?>」？')">
                <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="del_id" value="<?= $m['id'] ?>">
                <button type="submit" class="btn btn-xs btn-outline-danger">
                  <i class="bi bi-trash"></i>
                </button>
              </form>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- 方舟 Coding Plan 推荐卡片 -->
    <div class="page-card mt-3" style="border-color:rgba(99,102,241,.4);background:linear-gradient(135deg,rgba(99,102,241,.08),rgba(139,92,246,.08))">
      <div class="page-card-header" style="border-color:rgba(99,102,241,.3)">
        <i class="bi bi-stars me-2" style="color:#a78bfa"></i>算力平台推荐：
      </div>
      <div class="p-3">
        <div class="d-flex align-items-start gap-3">
          <div class="flex-shrink-0 mt-1">
            <div style="width:42px;height:42px;border-radius:10px;background:linear-gradient(135deg,#6366f1,#8b5cf6);display:flex;align-items:center;justify-content:center">
              <i class="bi bi-lightning-charge-fill text-white fs-5"></i>
            </div>
          </div>
          <div class="flex-grow-1">
            <div class="fw-semibold text-light mb-1" style="font-size:.9rem">Super MA 智算中心 - AI加速创作</div>
            <ul class="small text-muted mb-2 ps-3" style="line-height:1.8">
              <li>赤云智算中心 按量计费</li>
              <li>支持：GPT / Claude / Grok / DeepSeek-V3.2 / GLM / MiniMax / Kimi / Doubao 等世界顶尖模型，低延迟高并发</li>
              <li>量大管饱，专为 AI 写作 / 编程场景优化</li>
            </ul>
            <a href="https://api.6zhen.cn/"
               target="_blank" rel="noopener"
               class="btn btn-sm"
               style="background:linear-gradient(135deg,#6366f1,#8b5cf6);border:none;color:#fff;font-weight:600">
              <i class="bi bi-box-arrow-up-right me-1"></i>立即注册使用
            </a>
          </div>
		   <div class="flex-grow-2">
            <div class="fw-semibold text-light mb-1" style="font-size:.9rem">火山方舟 Coding Plan</div>
            <ul class="small text-muted mb-2 ps-3" style="line-height:1.8">
              <li>字节跳动旗下AI基座平台</li>
              <li>DeepSeek-V3.2 / Doubao / GLM / MiniMax / Kimi等顶尖模型，低延迟高并发</li>
              <li>量大管饱，专为 AI 写作 / 编程场景优化</li>
            </ul>
            <a href="https://www.volcengine.com/activity/codingplan?utm_source=7&utm_medium=daren_cpa&utm_term=daren_cpa_gaodingai_doumeng&utm_campaign=0&utm_content=codingplan_doumeng"
               target="_blank" rel="noopener"
               class="btn btn-sm"
               style="background:linear-gradient(135deg,#6366f1,#8b5cf6);border:none;color:#fff;font-weight:600">
              <i class="bi bi-box-arrow-up-right me-1"></i>立即注册使用
            </a>
          </div>
        </div>
      </div>
    </div>
	
	 <!-- 搞定AI公众号推荐卡片 -->
    <div class="page-card mt-3" style="border-color:rgba(99,102,241,.4);background:linear-gradient(135deg,rgba(99,102,241,.08),rgba(139,92,246,.08))">
      <div class="page-card-header" style="border-color:rgba(99,102,241,.3)">
        <i class="bi bi-stars me-2" style="color:#a78bfa"></i>更多好玩微信关注“搞定AI”
      </div>
      <div class="p-3">
        <div class="d-flex align-items-start gap-3">
          <div class="flex-shrink-0 mt-1">
            <div style="width:42px;height:42px;border-radius:10px;background:linear-gradient(135deg,#6366f1,#8b5cf6);display:flex;align-items:center;justify-content:center">
              <i class="bi bi-lightning-charge-fill text-white fs-5"></i>
            </div>
          </div>
          <div class="vxx">
                <img height="300" width="300" src="https://www.itzo.cn/api/ai.jpg" alt="搞定AI" class="ai-img">
            </div>
        </div>
      </div>
    </div>

    <!-- Test connection -->
    <div class="page-card mt-3">
      <div class="page-card-header"><i class="bi bi-wifi me-2"></i>连接测试</div>
      <div class="p-3">
        <div class="row g-2 align-items-end">
          <div class="col">
            <select class="form-select form-select-sm" id="test-model-select">
              <option value="">选择要测试的模型</option>
              <?php foreach ($models as $m): ?>
              <option value="<?= $m['id'] ?>"><?= h($m['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-auto">
            <button class="btn btn-sm btn-outline-info" onclick="testConnection()">
              <i class="bi bi-send me-1"></i>测试
            </button>
          </div>
        </div>
        <div id="test-result" class="mt-2 small" style="display:none"></div>
      </div>
    </div>
  </div>

  <!-- Add/Edit Form -->
  <div class="col-12 col-lg-5">
    <div class="page-card">
      <div class="page-card-header" id="form-header">
        <i class="bi bi-plus-circle me-2"></i>添加模型
      </div>
      <?php if ($error): ?>
      <div class="alert alert-danger m-3 alert-sm"><?= h($error) ?></div>
      <?php endif; ?>

      <!-- Preset buttons -->
      <div class="p-3 pb-0">
        <div class="small text-muted mb-2">快速选择预设：</div>
        <div class="d-flex flex-wrap gap-1 mb-3">
          <?php foreach ($presets as $key => $p): ?>
          <button type="button" class="btn btn-xs btn-outline-secondary btn-preset"
                  data-preset='<?= json_encode($p, JSON_HEX_APOS) ?>'>
            <?= h($p['name']) ?>
          </button>
          <?php endforeach; ?>
        </div>
      </div>

      <form method="post" id="model-form" class="px-3 pb-3">
        <input type="hidden" name="_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" id="form-action" value="add">
        <input type="hidden" name="edit_id" id="form-edit-id" value="">

        <div class="mb-3">
          <label class="form-label">模型名称 <span class="text-danger">*</span></label>
          <input type="text" name="name" id="f-name" class="form-control form-control-sm"
                 placeholder="例：DeepSeek Chat" required>
        </div>
        <div class="mb-3">
          <label class="form-label">API 地址 <span class="text-danger">*</span></label>
          <input type="url" name="api_url" id="f-api-url" class="form-control form-control-sm"
                 placeholder="https://api.openai.com/v1" required>
          <div class="form-text">OpenAI 协议兼容的 API 地址（不含 /chat/completions）</div>
        </div>
        <div class="mb-3">
          <label class="form-label">API 密钥</label>
          <div class="input-group input-group-sm">
            <input type="password" name="api_key" id="f-api-key" class="form-control form-control-sm"
                   placeholder="sk-...">
            <button class="btn btn-outline-secondary" type="button" id="toggle-api-key" title="显示/隐藏密钥">
              <i class="bi bi-eye" id="eye-icon"></i>
            </button>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">模型标识符 <span class="text-danger">*</span></label>
          <input type="text" name="model_name" id="f-model-name" class="form-control form-control-sm"
                 placeholder="gpt-4o / deepseek-chat / ..." required>
        </div>
        <div class="row g-2 mb-3">
          <div class="col-6">
            <label class="form-label">Max Tokens</label>
            <input type="number" name="max_tokens" id="f-max-tokens" class="form-control form-control-sm"
                   value="8192" min="256" max="131072">
          </div>
          <div class="col-6">
            <label class="form-label">Temperature</label>
            <input type="number" name="temperature" id="f-temperature" class="form-control form-control-sm"
                   value="0.8" min="0" max="2" step="0.1">
          </div>
        </div>
        <div class="mb-3">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_default" id="f-default">
            <label class="form-check-label text-muted" for="f-default">设为默认模型</label>
          </div>
        </div>
        
        <!-- 深度思考(Thinking)开关 -->
        <div class="mb-3 p-3 rounded" style="background:rgba(234,179,8,.08);border:1px solid rgba(234,179,8,.2)">
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="thinking_enabled" id="f-thinking">
            <label class="form-check-label text-light fw-semibold" for="f-thinking">
              <i class="bi bi-lightbulb me-1" style="color:#eab308"></i>深度思考 Enable Thinking
            </label>
          </div>
          <div class="small text-muted" style="line-height:1.6">
            开启后，AI 在生成回复前会进行深度推理（思维链），显著提升复杂推理和创作质量。系统会根据 API 地址自动识别厂商并使用对应的参数格式：
          </div>
          <div class="small mt-2" style="line-height:1.5">
            <table class="w-100" style="font-size:.75rem">
              <tr><td class="text-warning" style="width:10px">●</td><td class="text-muted">DeepSeek / 火山方舟 / 硅基流动</td><td class="text-secondary">thinking: {"type":"enabled"}</td></tr>
              <tr><td class="text-warning">●</td><td class="text-muted">阿里云百炼（通义千问）</td><td class="text-secondary">enable_thinking + thinking_budget</td></tr>
              <tr><td class="text-warning">●</td><td class="text-muted">智谱GLM / Kimi</td><td class="text-secondary">enable_thinking: true</td></tr>
              <tr><td class="text-warning">●</td><td class="text-muted">OpenAI（o1/o3）</td><td class="text-secondary">reasoning_effort: "high"</td></tr>
            </table>
          </div>
          <div class="small text-muted mt-2" style="line-height:1.6">
            开启后会增加 Token 消耗和响应时间，但输出质量更高。不支持深度思考的模型请勿开启，否则可能报错（系统会自动回退重试）。
          </div>
        </div>
        
        <!-- 记忆增强-Embedding模型开关 -->
        <div class="mb-3 p-3 rounded" style="background:rgba(99,102,241,.08);border:1px solid rgba(99,102,241,.2)">
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="embedding_enabled" id="f-embedding" onchange="validateEmbedding()">
            <label class="form-check-label text-light fw-semibold" for="f-embedding">
              <i class="bi bi-lightning-charge me-1" style="color:#a78bfa"></i>记忆增强-Embedding模型
            </label>
          </div>
          <div class="small text-muted mb-2" style="line-height:1.6">
            使用方舟Coding Plan API时自动开启，提升大模型记忆能力以及写作能力（会增加token消耗）。其他API暂不支持。
          </div>
          <input type="hidden" name="embedding_model_name" id="f-embedding-model" value="">
          <div id="embedding-error" class="small text-danger" style="display:none">
            <i class="bi bi-exclamation-triangle me-1"></i>接口不支持Embedding模型，请使用方舟Coding Plan
          </div>
          <div id="embedding-supported" class="small text-success" style="display:none">
            <i class="bi bi-check-circle me-1"></i>已自动开启记忆增强（doubao-embedding-vision）
          </div>
          <div id="embedding-test-result" class="small mt-2" style="display:none"></div>
          <button type="button" class="btn btn-outline-light btn-sm mt-2" onclick="testEmbedding()" id="btn-test-embedding">
            <i class="bi bi-lightning-charge me-1"></i>检测记忆增强是否生效
          </button>
        </div>
        
        <div class="d-flex gap-2">
          <button type="submit" class="btn btn-primary btn-sm flex-grow-1" id="form-submit-btn">
            <i class="bi bi-plus-lg me-1"></i>添加模型
          </button>
          <button type="button" class="btn btn-secondary btn-sm" id="form-cancel-btn"
                  style="display:none" onclick="resetForm()">取消</button>
        </div>
      </form>
    </div>

   

<script>
// Toggle API key visibility
document.getElementById('toggle-api-key').addEventListener('click', function() {
    const input = document.getElementById('f-api-key');
    const icon = document.getElementById('eye-icon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('bi-eye');
        icon.classList.add('bi-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('bi-eye-slash');
        icon.classList.add('bi-eye');
    }
});

// Embedding模型验证
function validateEmbedding() {
    const apiUrl = document.getElementById('f-api-url').value;
    const embeddingCheckbox = document.getElementById('f-embedding');
    const errorMsg = document.getElementById('embedding-error');
    const supportedMsg = document.getElementById('embedding-supported');
    
    const isArkApi = apiUrl.includes('ark.cn-beijing.volces.com') || apiUrl.includes('volces.com');
    
    // 方舟API：自动勾选+锁定，不允许取消
    // 注意：disabled 的 checkbox 不会随表单提交，所以改用 checked+readonly 视觉锁定
    if (isArkApi) {
        embeddingCheckbox.checked = true;
        embeddingCheckbox.disabled = true;
        // 修复：disabled checkbox 不提交表单，添加 hidden input 传递值
        let hiddenEmb = document.getElementById('f-embedding-hidden');
        if (!hiddenEmb) {
            hiddenEmb = document.createElement('input');
            hiddenEmb.type = 'hidden';
            hiddenEmb.name = 'embedding_enabled';
            hiddenEmb.value = '1';
            hiddenEmb.id = 'f-embedding-hidden';
            embeddingCheckbox.parentNode.appendChild(hiddenEmb);
        }
        errorMsg.style.display = 'none';
        supportedMsg.style.display = 'block';
        return true;
    }
    
    // 非方舟API：不允许勾选
    if (embeddingCheckbox.checked) {
        errorMsg.style.display = 'block';
        supportedMsg.style.display = 'none';
        embeddingCheckbox.checked = false;
        return false;
    }
    
    errorMsg.style.display = 'none';
    supportedMsg.style.display = 'none';
    return true;
}

// API URL 输入框变化时重新验证
document.getElementById('f-api-url').addEventListener('input', function() {
    const embeddingCheckbox = document.getElementById('f-embedding');
    const errorMsg = document.getElementById('embedding-error');
    const supportedMsg = document.getElementById('embedding-supported');
    
    const isArkApi = this.value.includes('ark.cn-beijing.volces.com') || this.value.includes('volces.com');
    
    if (isArkApi) {
        // 方舟API：自动勾选+锁定
        embeddingCheckbox.checked = true;
        embeddingCheckbox.disabled = true;
        // 修复：disabled checkbox 不提交表单，添加 hidden input 传递值
        let hiddenEmb = document.getElementById('f-embedding-hidden');
        if (!hiddenEmb) {
            hiddenEmb = document.createElement('input');
            hiddenEmb.type = 'hidden';
            hiddenEmb.name = 'embedding_enabled';
            hiddenEmb.value = '1';
            hiddenEmb.id = 'f-embedding-hidden';
            embeddingCheckbox.parentNode.appendChild(hiddenEmb);
        }
        supportedMsg.style.display = 'block';
        errorMsg.style.display = 'none';
    } else {
        // 非方舟API：取消勾选+解锁
        embeddingCheckbox.checked = false;
        embeddingCheckbox.disabled = false;
        // 移除 hidden input
        const hiddenEmb = document.getElementById('f-embedding-hidden');
        if (hiddenEmb) hiddenEmb.remove();
        errorMsg.style.display = 'none';
        supportedMsg.style.display = 'none';
    }
});

// Preset fill
document.querySelectorAll('.btn-preset').forEach(btn => {
    btn.addEventListener('click', () => {
        const p = JSON.parse(btn.dataset.preset);
        document.getElementById('f-name').value      = p.name;
        document.getElementById('f-api-url').value   = p.api_url;
        document.getElementById('f-model-name').value = p.model_name;
        // 触发API URL验证
        document.getElementById('f-api-url').dispatchEvent(new Event('input'));
    });
});

// Edit model
document.querySelectorAll('.btn-edit-model').forEach(btn => {
    btn.addEventListener('click', () => {
        const m = JSON.parse(btn.dataset.model);
        document.getElementById('form-header').innerHTML  = '<i class="bi bi-pencil me-2"></i>编辑模型';
        document.getElementById('form-action').value     = 'edit';
        document.getElementById('form-edit-id').value    = m.id;
        document.getElementById('f-name').value          = m.name;
        document.getElementById('f-api-url').value       = m.api_url;
        document.getElementById('f-api-key').value       = m.api_key;
        document.getElementById('f-model-name').value    = m.model_name;
        document.getElementById('f-max-tokens').value    = m.max_tokens;
        document.getElementById('f-temperature').value   = m.temperature;
        document.getElementById('f-default').checked     = m.is_default == 1;
        document.getElementById('f-embedding').checked   = (m.embedding_enabled ?? 0) == 1;
        document.getElementById('f-thinking').checked    = (m.thinking_enabled ?? 0) == 1;
        // 联动显示 embedding 支持提示
        const isArkApi = (m.api_url || '').includes('ark.cn-beijing.volces.com') || (m.api_url || '').includes('volces.com');
        const embCheckbox = document.getElementById('f-embedding');
        if (isArkApi) {
            // 方舟API：自动勾选+锁定
            embCheckbox.checked = true;
            embCheckbox.disabled = true;
            // 修复：disabled checkbox 不提交表单，添加 hidden input
            let hiddenEmb = document.getElementById('f-embedding-hidden');
            if (!hiddenEmb) {
                hiddenEmb = document.createElement('input');
                hiddenEmb.type = 'hidden';
                hiddenEmb.name = 'embedding_enabled';
                hiddenEmb.value = '1';
                hiddenEmb.id = 'f-embedding-hidden';
                embCheckbox.parentNode.appendChild(hiddenEmb);
            }
            document.getElementById('embedding-supported').style.display = 'block';
        } else {
            embCheckbox.disabled = false;
            // 移除 hidden input
            const hiddenEmb = document.getElementById('f-embedding-hidden');
            if (hiddenEmb) hiddenEmb.remove();
            document.getElementById('embedding-supported').style.display = 'none';
        }
        document.getElementById('form-submit-btn').innerHTML = '<i class="bi bi-check-lg me-1"></i>保存修改';
        document.getElementById('form-cancel-btn').style.display = '';
        // 触发API URL验证
        document.getElementById('f-api-url').dispatchEvent(new Event('input'));
        document.getElementById('model-form').scrollIntoView({behavior:'smooth'});
    });
});

function resetForm() {
    document.getElementById('model-form').reset();
    document.getElementById('form-action').value = 'add';
    document.getElementById('form-edit-id').value = '';
    document.getElementById('form-header').innerHTML = '<i class="bi bi-plus-circle me-2"></i>添加模型';
    document.getElementById('form-submit-btn').innerHTML = '<i class="bi bi-plus-lg me-1"></i>添加模型';
    document.getElementById('form-cancel-btn').style.display = 'none';
    // 重置embedding验证状态
    document.getElementById('embedding-error').style.display = 'none';
    document.getElementById('embedding-supported').style.display = 'none';
    document.getElementById('f-embedding').disabled = false;
    // 移除可能残留的 hidden input
    const hiddenEmb = document.getElementById('f-embedding-hidden');
    if (hiddenEmb) hiddenEmb.remove();
}

// 检测记忆增强（Embedding）是否生效
async function testEmbedding() {
    const el = document.getElementById('embedding-test-result');
    const btn = document.getElementById('btn-test-embedding');
    el.style.display = 'block';
    el.className = 'mt-2 small text-muted';
    el.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>正在检测记忆增强...';
    btn.disabled = true;
    try {
        const res = await fetch('api/memory_actions.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ action: 'embedding_status', novel_id: 0 })
        });
        const data = await res.json();
        if (!data.ok) {
            el.className = 'mt-2 small text-danger';
            el.innerHTML = '<i class="bi bi-x-circle me-1"></i>检测失败：' + (data.error || '未知错误');
            return;
        }
        const d = data.data;
        if (!d.configured) {
            el.className = 'mt-2 small text-warning';
            el.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>未配置：' + (d.error || '请在模型配置中使用方舟API');
            return;
        }
        if (!d.self_test_ok) {
            el.className = 'mt-2 small text-danger';
            el.innerHTML = '<i class="bi bi-x-circle me-1"></i>配置已就绪，但API调用失败：' + (d.error || '请检查API Key');
            return;
        }
        // 全部通过
        el.className = 'mt-2 small text-success';
        let html = '<i class="bi bi-check-circle me-1"></i>记忆增强已生效！'
            + '<br>模型：' + d.model_info;
        if (d.atoms_total > 0 || d.kb_total > 0) {
            html += '<br>记忆原子：' + d.atoms_with_vec + '/' + d.atoms_total + ' 条已向量化'
                + '<br>知识库：' + d.kb_with_vec + '/' + d.kb_total + ' 条已向量化';
        }
        el.innerHTML = html;
    } catch (e) {
        el.className = 'mt-2 small text-danger';
        el.innerHTML = '<i class="bi bi-x-circle me-1"></i>网络错误：' + e.message;
    } finally {
        btn.disabled = false;
    }
}

// ── 图片生成引擎配置 ──────────────────────────────────────────
// 加载图片 API 配置
(async function loadImageApiConfig() {
    try {
        const res = await fetch('api/cover_actions.php?action=get_image_api_config');
        const data = await res.json();
        if (data.ok && data.data) {
            document.getElementById('img-api-url').value = data.data.api_url || '';
            document.getElementById('img-api-key').value = data.data.api_key_masked || '';
            document.getElementById('img-model-name').value = data.data.model || 'gpt-image-2';
            if (data.data.size) document.getElementById('img-size').value = data.data.size;
            if (data.data.prompt_prefix) document.getElementById('img-prompt-prefix').value = data.data.prompt_prefix;
        }
    } catch(e) {}
})();

// 预设快捷填充
document.querySelectorAll('.img-preset').forEach(btn => {
    btn.addEventListener('click', () => {
        const p = JSON.parse(btn.dataset.imgPreset);
        document.getElementById('img-api-url').value = p.api_url || '';
        document.getElementById('img-model-name').value = p.model || 'gpt-image-2';
    });
});

// 切换密钥可见
document.getElementById('toggle-img-api-key').addEventListener('click', function() {
    const input = document.getElementById('img-api-key');
    const icon = document.getElementById('img-eye-icon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('bi-eye', 'bi-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('bi-eye-slash', 'bi-eye');
    }
});

// 保存图片 API 配置
async function saveImageApiConfig() {
    const status = document.getElementById('img-api-status');
    status.style.display = '';
    status.className = 'mt-2 small text-muted';
    status.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>保存中...';

    try {
        const res = await fetch('api/cover_actions.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action: 'save_image_api_config',
                api_url: document.getElementById('img-api-url').value.trim(),
                api_key: document.getElementById('img-api-key').value.trim(),
                model: document.getElementById('img-model-name').value.trim() || 'gpt-image-2',
                size: document.getElementById('img-size').value,
                prompt_prefix: document.getElementById('img-prompt-prefix').value.trim(),
            })
        });
        const data = await res.json();
        if (data.ok) {
            status.className = 'mt-2 small text-success';
            status.innerHTML = '<i class="bi bi-check-circle me-1"></i>' + data.msg;
            setTimeout(() => loadImageApiConfig(), 1000);
        } else {
            status.className = 'mt-2 small text-danger';
            status.innerHTML = '<i class="bi bi-x-circle me-1"></i>' + (data.msg || '保存失败');
        }
    } catch(e) {
        status.className = 'mt-2 small text-danger';
        status.innerHTML = '<i class="bi bi-x-circle me-1"></i>请求失败：' + e.message;
    }
}

// 测试图片 API 连接
async function testImageApi() {
    const status = document.getElementById('img-api-status');
    status.style.display = '';
    status.className = 'mt-2 small text-muted';
    status.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>测试连接中...';

    try {
        const apiUrl = document.getElementById('img-api-url').value.trim();
        const apiKey = document.getElementById('img-api-key').value.trim();

        if (!apiUrl || !apiKey) {
            status.className = 'mt-2 small text-warning';
            status.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>请先填写 API 地址和密钥';
            return;
        }

        // 尝试调用 models 接口验证
        const res = await fetch(apiUrl.replace(/\/images\/generations$/, '') + '/models', {
            headers: {'Authorization': 'Bearer ' + apiKey}
        });
        if (res.ok) {
            status.className = 'mt-2 small text-success';
            status.innerHTML = '<i class="bi bi-check-circle me-1"></i>连接成功！API 可访问';
        } else {
            status.className = 'mt-2 small text-warning';
            status.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>API 返回 ' + res.status + '，请检查密钥是否正确';
        }
    } catch(e) {
        status.className = 'mt-2 small text-danger';
        status.innerHTML = '<i class="bi bi-x-circle me-1"></i>连接失败：' + e.message;
    }
}

async function testConnection() {
    const modelId = document.getElementById('test-model-select').value;
    if (!modelId) { alert('请选择要测试的模型'); return; }
    const el = document.getElementById('test-result');
    el.style.display = '';
    el.className = 'mt-2 small text-muted';
    el.textContent = '正在连接...';
    try {
        const res  = await fetch('api/actions.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ action: 'test_model', model_id: parseInt(modelId) })
        });
        
        // 先获取响应文本，再尝试解析 JSON
        const responseText = await res.text();
        console.log('API 响应状态:', res.status);
        console.log('API 响应文本:', responseText);
        
        if (!responseText) {
            throw new Error('服务器返回空响应（可能 PHP 错误或数据库连接失败）');
        }
        
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (parseError) {
            throw new Error('JSON 解析失败，服务器返回：' + responseText.substring(0, 200));
        }
        
        if (data.ok) {
            el.className = 'mt-2 small text-success';
            el.textContent = '✓ 连接成功：' + data.data;
        } else {
            el.className = 'mt-2 small text-danger';
            el.textContent = '✗ 连接失败：' + data.msg;
        }
    } catch(e) {
        el.className = 'mt-2 small text-danger';
        el.textContent = '✗ 请求错误：' + e.message;
        console.error('测试连接错误:', e);
    }
}
</script>

<?php pageFooter(); ?>
