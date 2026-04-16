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
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $name       = trim($_POST['name']       ?? '');
        $api_url    = trim($_POST['api_url']    ?? '');
        $api_key    = trim($_POST['api_key']    ?? '');
        $model_name = trim($_POST['model_name'] ?? '');
        $max_tokens = max(256, (int)($_POST['max_tokens']  ?? 4096));
        $temp       = min(2.0, max(0.0, (float)($_POST['temperature'] ?? 0.8)));
        $is_default = isset($_POST['is_default']) ? 1 : 0;

        if (!$name || !$api_url || !$model_name) {
            $error = '请填写名称、API地址和模型标识符。';
        } else {
            if ($is_default) {
                DB::query('UPDATE ai_models SET is_default=0');
            }
            if ($action === 'edit') {
                $editId = (int)($_POST['edit_id'] ?? 0);
                DB::update('ai_models', [
                    'name'        => $name,
                    'api_url'     => $api_url,
                    'api_key'     => $api_key,
                    'model_name'  => $model_name,
                    'max_tokens'  => $max_tokens,
                    'temperature' => $temp,
                    'is_default'  => $is_default,
                ], 'id=?', [$editId]);
                $msg = "模型「{$name}」已更新。";
            } else {
                DB::insert('ai_models', [
                    'name'        => $name,
                    'api_url'     => $api_url,
                    'api_key'     => $api_key,
                    'model_name'  => $model_name,
                    'max_tokens'  => $max_tokens,
                    'temperature' => $temp,
                    'is_default'  => $is_default,
                ]);
                $msg = "模型「{$name}」已添加。";
            }
        }
    } elseif ($action === 'delete') {
        $delId = (int)($_POST['del_id'] ?? 0);
        $m = DB::fetch('SELECT name FROM ai_models WHERE id=?', [$delId]);
        if ($m) {
            DB::delete('ai_models', 'id=?', [$delId]);
            $msg = "模型「{$m['name']}」已删除。";
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
    'openai-gpt4o'    => ['name'=>'方舟Coding Plan',       'api_url'=>'https://ark.cn-beijing.volces.com/api/coding/v3',        'model_name'=>'DeepSeek-V3.2'],
    'openai-gpt4'     => ['name'=>'OpenAI GPT-4',         'api_url'=>'https://api.openai.com/v1',        'model_name'=>'gpt-4'],
    'openai-gpt35'    => ['name'=>'OpenAI GPT-3.5',       'api_url'=>'https://api.openai.com/v1',        'model_name'=>'gpt-3.5-turbo'],
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
              </div>
            </div>
            <div class="d-flex gap-1 ms-2 flex-shrink-0">
              <?php if (!$m['is_default']): ?>
              <form method="post" class="d-inline">
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
        <i class="bi bi-stars me-2" style="color:#a78bfa"></i>推荐：火山方舟 Coding Plan
      </div>
      <div class="p-3">
        <div class="d-flex align-items-start gap-3">
          <div class="flex-shrink-0 mt-1">
            <div style="width:42px;height:42px;border-radius:10px;background:linear-gradient(135deg,#6366f1,#8b5cf6);display:flex;align-items:center;justify-content:center">
              <i class="bi bi-lightning-charge-fill text-white fs-5"></i>
            </div>
          </div>
          <div class="flex-grow-1">
            <div class="fw-semibold text-light mb-1" style="font-size:.9rem">火山方舟 Coding Plan — AI加速创作</div>
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
          <input type="text" name="api_key" id="f-api-key" class="form-control form-control-sm"
                 placeholder="sk-...">
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
                   value="4096" min="256" max="131072">
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
        <div class="d-flex gap-2">
          <button type="submit" class="btn btn-primary btn-sm flex-grow-1" id="form-submit-btn">
            <i class="bi bi-plus-lg me-1"></i>添加模型
          </button>
          <button type="button" class="btn btn-secondary btn-sm" id="form-cancel-btn"
                  style="display:none" onclick="resetForm()">取消</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Preset fill
document.querySelectorAll('.btn-preset').forEach(btn => {
    btn.addEventListener('click', () => {
        const p = JSON.parse(btn.dataset.preset);
        document.getElementById('f-name').value      = p.name;
        document.getElementById('f-api-url').value   = p.api_url;
        document.getElementById('f-model-name').value = p.model_name;
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
        document.getElementById('form-submit-btn').innerHTML = '<i class="bi bi-check-lg me-1"></i>保存修改';
        document.getElementById('form-cancel-btn').style.display = '';
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
