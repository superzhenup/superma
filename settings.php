<?php
define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/model_presets.php';

$msg   = '';
$error = '';

/**
 * SSRF 防护：公网 API 强制 HTTPS，并阻止 private、link-local、保留地址。
 * 本系统的模型配置仅管理员可写；为兑现内置 Ollama 预设，精确放行
 * localhost / 127.0.0.1 / ::1，不放行其他内网地址或解析到内网的域名。
 */
function normalizeApiUrlHost(string $host): string {
    $host = strtolower(trim($host));
    if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
        $host = substr($host, 1, -1);
    }
    return rtrim($host, '.');
}

function isDisallowedIpv6(string $ip): bool {
    if (strlen($ip) !== 16) return false;

    if ($ip === str_repeat("\0", 16)) return true;
    if ($ip === str_repeat("\0", 15) . "\1") return true;

    $first = ord($ip[0]);
    $second = ord($ip[1]);
    if (($first & 0xFE) === 0xFC) return true;
    if ($first === 0xFE && ($second & 0xC0) === 0x80) return true;
    if ($first === 0xFF) return true;

    $mappedPrefix = str_repeat("\0", 10) . "\xff\xff";
    if (substr($ip, 0, 12) === $mappedPrefix) {
        $mappedLong = unpack('N', substr($ip, 12))[1];
        if (($mappedLong & 0xFF000000) === 0x7F000000) return true;
        if (($mappedLong & 0xFF000000) === 0x0A000000) return true;
        if (($mappedLong & 0xFFFF0000) === 0xC0A80000) return true;
        if (($mappedLong & 0xFFF00000) === 0xAC100000) return true;
        if (($mappedLong & 0xFFFF0000) === 0xA9FE0000) return true;
    }

    return false;
}

function isSafeApiUrl(string $url): bool {
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host || $host === '') return false;
    $host = normalizeApiUrlHost($host);

    // 单用户部署允许管理员连接本机 Ollama；远程端点仍强制 HTTPS。
    if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
        return $scheme === 'http' || $scheme === 'https';
    }
    if ($scheme !== 'https') return false;

    $ip = @inet_pton($host);
    if ($ip === false) {
        // 非 IP 地址（域名），DNS 解析后再校验
        $resolved = @gethostbyname($host);
        if ($resolved === $host) return true; // 解析失败，放行（由 cURL 自行处理）
        $ip = @inet_pton($resolved);
        if ($ip === false) return true; // 非 IPv4，放行
    }

    if (strlen($ip) === 16) return !isDisallowedIpv6($ip);
    if (strlen($ip) !== 4) return true;

    $ipLong = unpack('N', $ip)[1];

    // 0.0.0.0/8
    if (($ipLong & 0xFF000000) === 0x00000000) return false;
    // 10.0.0.0/8
    if (($ipLong & 0xFF000000) === 0x0A000000) return false;
    // 100.64.0.0/10 (CGN)
    if (($ipLong & 0xFFC00000) === 0x64400000) return false;
    // 127.0.0.0/8 (loopback — 127.0.0.1 已在上方放行，其余拒绝)
    if (($ipLong & 0xFF000000) === 0x7F000000) return false;
    // 169.254.0.0/16 (link-local)
    if (($ipLong & 0xFFFF0000) === 0xA9FE0000) return false;
    // 172.16.0.0/12
    if (($ipLong & 0xFFF00000) === 0xAC100000) return false;
    // 192.0.0.0/24 (IETF Protocol Assignments)
    if (($ipLong & 0xFFFFFF00) === 0xC0000000) return false;
    // 192.0.2.0/24 (TEST-NET-1)
    if (($ipLong & 0xFFFFFF00) === 0xC0000200) return false;
    // 192.168.0.0/16
    if (($ipLong & 0xFFFF0000) === 0xC0A80000) return false;
    // 198.18.0.0/15 (benchmark)
    if (($ipLong & 0xFFFE0000) === 0xC6120000) return false;
    // 198.51.100.0/24 (TEST-NET-2)
    if (($ipLong & 0xFFFFFF00) === 0xC6336400) return false;
    // 203.0.113.0/24 (TEST-NET-3)
    if (($ipLong & 0xFFFFFF00) === 0xCB007100) return false;
    // 224.0.0.0/4 (multicast)
    if (($ipLong & 0xF0000000) === 0xE0000000) return false;
    // 240.0.0.0/4 (reserved)
    if (($ipLong & 0xF0000000) === 0xF0000000) return false;

    return true;
}

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
        $editId = $action === 'edit' ? (int)($_POST['edit_id'] ?? 0) : 0;
        $existingEditModel = $editId > 0
            ? DB::fetch('SELECT id, capabilities FROM ai_models WHERE id=?', [$editId])
            : null;

        // 编辑时保留系统暂不认识的第三方/未来能力标签，只重建当前表单
        // 管理的四个已知标签，避免一次普通编辑静默丢失扩展能力。
        $knownCapabilities = ['creative', 'structured', 'synopsis', 'context_1m'];
        $capabilities = [];
        if ($existingEditModel) {
            $existingCapabilities = json_decode((string)($existingEditModel['capabilities'] ?? '[]'), true);
            if (is_array($existingCapabilities)) {
                foreach ($existingCapabilities as $capability) {
                    if (
                        is_string($capability)
                        && !in_array($capability, $knownCapabilities, true)
                        && preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/', $capability)
                    ) {
                        $capabilities[] = $capability;
                    }
                }
            }
        }
        if (isset($_POST['cap_creative']))   $capabilities[] = 'creative';
        if (isset($_POST['cap_structured'])) $capabilities[] = 'structured';
        if (isset($_POST['cap_synopsis']))   $capabilities[] = 'synopsis';
        if (isset($_POST['cap_context_1m'])) $capabilities[] = 'context_1m';
        $capabilities = array_values(array_unique($capabilities));
        $capabilities_json = !empty($capabilities) ? json_encode($capabilities) : null;

        if (!$name || !$api_url || !$model_name) {
            $error = '请填写名称、API地址和模型标识符。';
        } elseif ($action === 'edit' && !$existingEditModel) {
            $error = '要编辑的模型不存在，请刷新页面后重试。';
        } elseif (!isSafeApiUrl($api_url)) {
            $error = '公网 API 必须使用 HTTPS；仅本机 Ollama 可使用 http://localhost、127.0.0.1 或 ::1。';
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
            if ($action === 'edit') {
                $update = [
                    'name'                => $name,
                    'api_url'             => $api_url,
                    'model_name'          => $model_name,
                    'max_tokens'          => $max_tokens,
                    'temperature'         => $temp,
                    'is_default'          => $is_default,
                    'embedding_enabled'   => $embedding_enabled,
                    'thinking_enabled'    => $thinking_enabled,
                    'capabilities'        => $capabilities_json,
                    'can_embed'           => $embedding_enabled,
                    'embedding_model_name'=> $embedding_model_name,
                ];
                if (!empty($api_key)) {
                    $update['api_key'] = $api_key;
                }
                DB::update('ai_models', $update, 'id=?', [$editId]);
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
                    'capabilities'        => $capabilities_json,
                    'can_embed'           => $embedding_enabled,
                    'embedding_model_name'=> $embedding_model_name,
                ]);
                $savedModelId = DB::lastId();
                $msg = "模型「{$name}」已添加。";
            }
            if ($is_default) {
                // 当前模型已成功保存后再清理其他默认标记；保存失败时不会
                // 先把系统唯一默认模型清空。
                DB::query('UPDATE ai_models SET is_default=0 WHERE id!=?', [$savedModelId]);
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
            clearSystemSettingsCache();
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
                clearSystemSettingsCache();
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

$presets = getSettingsModelPresets();
?>

<div class="row g-4">

  <!-- Model List -->
  <div class="col-12 col-lg-7 order-lg-2">
    <div class="page-card">
      <div class="page-card-header"><i class="bi bi-cpu me-2"></i>已配置模型</div>
      <?php if ($msg): ?>
      <div class="alert alert-success alert-sm m-3"><?= h($msg) ?></div>
      <?php endif; ?>
      <?php if (empty($models)): ?>
      <div class="empty-state py-4">
        <div class="empty-icon"><i class="bi bi-cpu"></i></div>
        <h6>尚未添加模型</h6>
        <p class="text-muted small">请在左侧表单添加您的AI模型</p>
      </div>
      <?php else: ?>
      <div class="list-group list-group-flush">
        <?php foreach ($models as $m): ?>
        <div class="list-group-item bg-transparent border-secondary model-item">
          <div class="d-flex align-items-start justify-content-between">
            <div class="flex-grow-1">
              <div class="d-flex align-items-center gap-2 mb-1">
                <span class="fw-semibold" style="color:var(--text)"><?= h($m['name']) ?></span>
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
              <?php 
              $caps = json_decode($m['capabilities'] ?? '[]', true) ?: [];
              if (!empty($caps)): ?>
              <div class="small mt-1">
                <?php foreach ($caps as $cap): ?>
                <?php if ($cap === 'creative'): ?>
                <span class="badge bg-primary me-1">creative</span>
                <?php elseif ($cap === 'structured'): ?>
                <span class="badge bg-info me-1">structured</span>
                <?php elseif ($cap === 'synopsis'): ?>
                <span class="badge bg-warning me-1">synopsis</span>
                <?php elseif ($cap === 'context_1m'): ?>
                <span class="badge bg-success me-1">1M 上下文</span>
                <?php endif; ?>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
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
                      data-model='<?php
                        $safeM = $m;
                        unset($safeM['api_key']);
                        echo json_encode($safeM, JSON_HEX_APOS|JSON_UNESCAPED_UNICODE);
                      ?>'>
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

    <!-- 官方公告（iframe 嵌入远程页面） -->
<?php $announcementUrl = ConfigCenter::get('announcement_url', 'https://www.itzo.cn/api/super.html', 'string'); ?>
    <div class="page-card mt-3" style="border-color:rgba(99,102,241,.4);background:linear-gradient(135deg,rgba(99,102,241,.08),rgba(139,92,246,.08))">
      <div class="page-card-header d-flex align-items-center justify-content-between" style="border-color:rgba(99,102,241,.3)">
        <div><i class="bi bi-stars me-2" style="color:#a78bfa"></i>官方公告</div>
        <div class="small text-muted" id="announce-status" style="font-size:.75rem"></div>
      </div>
      <div class="p-3">
        <!-- URL 配置行 -->

        <!-- iframe 嵌入远程公告 -->
        <div id="announce-iframe-wrap" style="max-height:600px;overflow:hidden;border-radius:6px;<?= $announcementUrl ? '' : 'display:none' ?>">
          <iframe id="announce-iframe"
                  src="<?= $announcementUrl ? h($announcementUrl) : '' ?>"
                  style="width:100%;height:600px;border:none;background:transparent"
                  sandbox="allow-scripts allow-same-origin allow-popups allow-top-navigation"
                  loading="lazy"
                  onload="this.style.height=Math.min(this.contentDocument?.body?.scrollHeight+20||380,400)+'px'"
                  onerror="document.getElementById('announce-placeholder').style.display='';this.parentElement.style.display='none'">
          </iframe>
        </div>
        <!-- 无 URL 时的提示 -->
        <div id="announce-placeholder" class="text-center text-muted py-3 small"
             style="<?= $announcementUrl ? 'display:none' : '' ?>">
          <i class="bi bi-info-circle me-1"></i>加载失败
        </div>
		</div></div>

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
  <div class="col-12 col-lg-5 order-lg-1">
    <div class="page-card">
      <!-- Tab Navigation -->
      <div class="engine-tabs">
        <button type="button" class="engine-tab active" data-tab="text-engine">
          <i class="bi bi-cpu me-1"></i>文本生成引擎
        </button>
        <button type="button" class="engine-tab" data-tab="image-engine">
          <i class="bi bi-image me-1"></i>图片生成引擎
        </button>
      </div>

      <!-- ===== Tab 1: 文本生成引擎 ===== -->
      <div class="engine-panel active" id="text-engine-panel">
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
            <label class="form-check-label fw-semibold" style="color:var(--text)" for="f-thinking">
              <i class="bi bi-lightbulb me-1" style="color:#eab308"></i>深度思考 Enable Thinking
            </label>
          </div>
          <div class="small text-muted" style="line-height:1.6">
            开启后，AI 在生成回复前会进行深度推理（思维链），显著提升复杂推理和创作质量(会增加 Token 消耗和响应时间)。系统会根据 API 地址自动识别厂商并使用对应的参数格式：
          </div>
          <div class="small mt-2" style="line-height:1.5">
            <table class="w-100" style="font-size:.75rem">
              <tr><td class="text-warning" style="width:10px">●</td><td class="text-muted">DeepSeek / 火山方舟 / 硅基流动</td><td class="text-secondary">thinking: {"type":"enabled"}</td></tr>
              <tr><td class="text-warning">●</td><td class="text-muted">阿里云百炼（通义千问）</td><td class="text-secondary">enable_thinking + thinking_budget</td></tr>
            </table>
          </div>
        </div>
        
        <!-- 模型能力标签 -->
        <div class="mb-3 p-3 rounded" style="background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.2)">
          <div class="mb-2">
            <label class="form-label fw-semibold mb-1" style="color:var(--text)">
              <i class="bi bi-tags me-1" style="color:#22c55e"></i>模型能力标签
            </label>
          </div>
          <div class="small text-muted mb-2" style="line-height:1.6">
            标记此模型擅长的任务类型，系统会根据任务类型智能选择最合适的模型。未标记的模型仍可作为备用。
          </div>
          <div class="d-flex flex-wrap gap-3">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="cap_creative" id="f-cap-creative">
              <label class="form-check-label text-muted" for="f-cap-creative">
                <span class="badge bg-primary me-1">creative</span>正文写作
              </label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="cap_structured" id="f-cap-structured">
              <label class="form-check-label text-muted" for="f-cap-structured">
                <span class="badge bg-info me-1">structured</span>结构化任务
              </label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="cap_synopsis" id="f-cap-synopsis">
              <label class="form-check-label text-muted" for="f-cap-synopsis">
                <span class="badge bg-warning me-1">synopsis</span>章节简介
              </label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="cap_context_1m" id="f-cap-context-1m">
              <label class="form-check-label text-muted" for="f-cap-context-1m">
                <span class="badge bg-success me-1">1M</span>支持 1M 上下文
              </label>
            </div>
          </div>
          <div class="small text-muted mt-2" style="line-height:1.6">
            <strong>creative</strong>：正文写作，需要高创意和文采<br>
            <strong>structured</strong>：大纲生成、摘要提取、JSON解析等结构化任务<br>
            <strong>synopsis</strong>：章节简介生成，介于创意和结构之间<br>
            <strong>1M 上下文</strong>：仅当模型服务明确支持约 100 万 Token 上下文时勾选；系统会为它构建完整历史上下文
          </div>
        </div>
        
        <!-- 记忆增强-Embedding模型开关 -->
        <div class="mb-3 p-3 rounded" style="background:rgba(99,102,241,.08);border:1px solid rgba(99,102,241,.2)">
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="embedding_enabled" id="f-embedding" onchange="validateEmbedding()">
            <label class="form-check-label fw-semibold" style="color:var(--text)" for="f-embedding">
              <i class="bi bi-lightning-charge me-1" style="color:#a78bfa"></i>记忆增强-Embedding模型
            </label>
          </div>
          <div class="small text-muted mb-2" style="line-height:1.6">
            使用方舟Coding Plan API时自动开启，提升大模型记忆能力（会增加token消耗）。其他API暂不支持。
          </div>
          <input type="hidden" name="embedding_model_name" id="f-embedding-model" value="">
          <div id="embedding-error" class="small text-danger" style="display:none">
            <i class="bi bi-exclamation-triangle me-1"></i>接口不支持Embedding模型，请使用方舟Coding Plan
          </div>
          <div id="embedding-supported" class="small text-success" style="display:none">
            <i class="bi bi-check-circle me-1"></i>已自动开启记忆增强（doubao-embedding-vision）
          </div>
          <div id="embedding-test-result" class="small mt-2" style="display:none"></div>
          <button type="button" class="btn btn-outline-info btn-sm mt-2" onclick="testEmbedding()" id="btn-test-embedding">
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
      </div><!-- /text-engine-panel -->

      <!-- ===== Tab 2: 图片生成引擎 ===== -->
      <div class="engine-panel" id="image-engine-panel">
      <div class="p-3">
        <div class="small text-muted mb-3" style="line-height:1.6">
          配置图片生成 API，用于 AI 生成小说封面。推荐使用 OpenAI-compatible Images API；
          仅当供应商明确通过 Chat Completions 返回图片时，才选择兼容模式。
        </div>

        <!-- 预设快捷填充 -->
        <div class="mb-3">
          <div class="small text-muted mb-2">快速选择预设：</div>
          <div class="d-flex flex-wrap gap-1">
            <button type="button" class="btn btn-xs btn-outline-secondary img-preset"
                    data-img-preset='{"name":"赤云优算","api_url":"https://api.6zhen.cn/v1","model":"gpt-image-2","mode":"images"}'>
              赤云优算
            </button>
            <button type="button" class="btn btn-xs btn-outline-secondary img-preset"
                    data-img-preset='{"name":"OpenAI 官方","api_url":"https://api.openai.com/v1","model":"gpt-image-2","mode":"images"}'>
              OpenAI 官方
            </button>
            <button type="button" class="btn btn-xs btn-outline-secondary img-preset"
                    data-img-preset='{"name":"自定义","api_url":"","model":"gpt-image-2","mode":"images"}'>
              自定义
            </button>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label">端点模式 <span class="text-danger">*</span></label>
          <select class="form-select form-select-sm" id="img-api-mode">
            <option value="images" selected>Images API（推荐）</option>
            <option value="chat_image">Chat Completions 图片兼容模式</option>
          </select>
          <div class="form-text" id="img-api-mode-help">
            请求 <code>/images/generations</code>，读取顶层 <code>data[0].b64_json</code> 或 <code>data[0].url</code>。
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label">API 地址 <span class="text-danger">*</span></label>
          <input type="url" class="form-control form-control-sm" id="img-api-url"
                 placeholder="https://api.openai.com/v1" value="">
          <div class="form-text">填写 API 基础地址（例如 <code>https://api.openai.com/v1</code>），不要附加具体生成端点。</div>
        </div>
        <div class="mb-3">
          <label class="form-label">API 密钥</label>
          <div class="input-group input-group-sm">
            <input type="password" class="form-control form-control-sm" id="img-api-key"
                   placeholder="sk-...">
            <button class="btn btn-outline-secondary" type="button" id="toggle-img-api-key" title="显示/隐藏">
              <i class="bi bi-eye" id="img-eye-icon"></i>
            </button>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">模型名称 <span class="text-danger">*</span></label>
          <input type="text" class="form-control form-control-sm" id="img-model-name"
                 placeholder="gpt-image-2" value="gpt-image-2">
          <div class="form-text">图片生成模型标识符，如 <code>gpt-image-2</code>、<code>dall-e-3</code>、<code>FLUX.1-schnell</code> 等</div>
        </div>
        <div class="mb-3">
          <label class="form-label">生成尺寸</label>
          <select class="form-select form-select-sm" id="img-size">
            <option value="1024x1536" selected>1024×1536（推荐，接近 1086×1448 比例）</option>
            <option value="1024x1024">1024×1024（方形）</option>
            <option value="1536x1024">1536×1024（横版）</option>
            <option value="1024x1792">1024×1792（DALL·E 竖版）</option>
            <option value="1792x1024">1792×1024（DALL·E 横版）</option>
          </select>
          <div class="form-text">生成后系统会自动缩放到 1086×1448 封面标准分辨率</div>
        </div>
        <div class="mb-3">
          <label class="form-label">默认封面 Prompt 前缀</label>
          <textarea class="form-control form-control-sm" id="img-prompt-prefix" rows="2"
                    placeholder="A professional book cover illustration for a novel. Style: high quality, detailed, dramatic lighting."></textarea>
          <div class="form-text">生成封面时，用户输入的关键词会追加在此前缀之后。留空则使用系统默认。</div>
        </div>

        <div class="d-flex gap-2">
          <button type="button" class="btn btn-sm flex-grow-1" style="background:linear-gradient(135deg,#ec4899,#a855f7);border:none;color:#fff;font-weight:600" onclick="saveImageApiConfig()">
            <i class="bi bi-check-lg me-1"></i>保存配置
          </button>
          <button type="button" class="btn btn-sm btn-outline-info" onclick="testImageApi()">
            <i class="bi bi-send me-1"></i>测试连接
          </button>
        </div>
        <div id="img-api-status" class="mt-2 small" style="display:none"></div>
        <div class="form-text mt-1">连接测试只验证 HTTPS、鉴权和 <code>/models</code>，不会实际生成图片或产生图片生成费用。</div>
      </div>
      </div><!-- /image-engine-panel -->
    </div><!-- /page-card -->
  </div>
</div>

<style>
/* Engine Tabs */
.engine-tabs {
  display: flex;
  border-bottom: 2px solid var(--border);
  gap: 0;
}
.engine-tab {
  flex: 1;
  padding: 12px 16px;
  border: none;
  background: transparent;
  color: var(--text-muted);
  font-size: .875rem;
  font-weight: 600;
  cursor: pointer;
  position: relative;
  transition: all .2s;
  border-bottom: 2px solid transparent;
  margin-bottom: -2px;
}
.engine-tab:hover {
  color: var(--text-sub);
  background: var(--bg-hover);
}
.engine-tab.active {
  color: var(--text);
  border-bottom-color: #6366f1;
  background: var(--primary-bg);
}
.engine-tab.active i {
  color: #a78bfa;
}
.engine-panel {
  display: none;
}
.engine-panel.active {
  display: block;
}
/* 远程公告内容样式 */
.remote-announce {
  color: var(--text);
  font-size: .85rem;
  line-height: 1.7;
}
.remote-announce a { color: #a78bfa; }
.remote-announce h3, .remote-announce h4 { color: var(--text); margin: .8em 0 .4em; font-size: .95rem; }
.remote-announce ul, .remote-announce ol { padding-left: 1.2em; }
.remote-announce li { margin-bottom: .25em; }
.remote-announce img { max-width: 100%; border-radius: 8px; }
.remote-announce p { margin-bottom: .5em; }
</style>
<script>
// Engine Tab Switching
document.querySelectorAll('.engine-tab').forEach(tab => {
    tab.addEventListener('click', function() {
        const targetId = this.dataset.tab + '-panel';
        // Update tabs
        document.querySelectorAll('.engine-tab').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        // Update panels
        document.querySelectorAll('.engine-panel').forEach(p => p.classList.remove('active'));
        document.getElementById(targetId).classList.add('active');
    });
});

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
        const formHeader = document.getElementById('form-header');
        if (formHeader) formHeader.innerHTML = '<i class="bi bi-pencil me-2"></i>编辑模型';
        document.getElementById('form-action').value     = 'edit';
        document.getElementById('form-edit-id').value    = m.id;
        document.getElementById('f-name').value          = m.name;
        document.getElementById('f-api-url').value       = m.api_url;
        document.getElementById('f-api-key').value       = '';
        document.getElementById('f-api-key').placeholder = '（留空则不修改）';
        document.getElementById('f-model-name').value    = m.model_name;
        document.getElementById('f-max-tokens').value    = m.max_tokens;
        document.getElementById('f-temperature').value   = m.temperature;
        document.getElementById('f-default').checked     = m.is_default == 1;
        document.getElementById('f-embedding').checked   = (m.embedding_enabled ?? 0) == 1;
        document.getElementById('f-thinking').checked    = (m.thinking_enabled ?? 0) == 1;
        
        // 设置capabilities字段
        const caps = JSON.parse(m.capabilities || '[]');
        document.getElementById('f-cap-creative').checked   = caps.includes('creative');
        document.getElementById('f-cap-structured').checked = caps.includes('structured');
        document.getElementById('f-cap-synopsis').checked   = caps.includes('synopsis');
        document.getElementById('f-cap-context-1m').checked = caps.includes('context_1m');
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
    const formHeader = document.getElementById('form-header');
    if (formHeader) formHeader.innerHTML = '<i class="bi bi-plus-circle me-2"></i>添加模型';
    document.getElementById('form-submit-btn').innerHTML = '<i class="bi bi-plus-lg me-1"></i>添加模型';
    document.getElementById('form-cancel-btn').style.display = 'none';
    // 重置embedding验证状态
    document.getElementById('embedding-error').style.display = 'none';
    document.getElementById('embedding-supported').style.display = 'none';
    document.getElementById('f-embedding').disabled = false;
    // 移除可能残留的 hidden input
    const hiddenEmb = document.getElementById('f-embedding-hidden');
    if (hiddenEmb) hiddenEmb.remove();
    // 重置capabilities字段
    document.getElementById('f-cap-creative').checked = false;
    document.getElementById('f-cap-structured').checked = false;
    document.getElementById('f-cap-synopsis').checked = false;
    document.getElementById('f-cap-context-1m').checked = false;
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
        const data = await apiPost('memory_actions', {
            action: 'embedding_status',
            novel_id: 0,
        });
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
// 加载图片 API 配置。等待 app.js 加载后统一走路由。
async function loadImageApiConfig() {
    try {
        const data = await apiGet('cover_actions', {action: 'get_image_api_config'});
        if (data.ok && data.data) {
            document.getElementById('img-api-url').value = data.data.api_url || '';
            const keyInput = document.getElementById('img-api-key');
            keyInput.value = '';
            keyInput.placeholder = data.data.has_api_key
                ? `已保存（${data.data.api_key_hint || '留空不修改'}）`
                : 'sk-...';
            document.getElementById('img-api-mode').value = data.data.mode || 'images';
            document.getElementById('img-model-name').value = data.data.model || 'gpt-image-2';
            if (data.data.size) document.getElementById('img-size').value = data.data.size;
            if (data.data.prompt_prefix) document.getElementById('img-prompt-prefix').value = data.data.prompt_prefix;
            updateImageApiModeHelp();
            if (data.data.legacy_mode_inferred) {
                const status = document.getElementById('img-api-status');
                status.style.display = '';
                status.className = 'mt-2 small text-warning';
                status.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>旧配置未记录端点模式，已暂按 Chat 图片兼容模式保留；确认供应商协议后请保存一次。';
            }
        }
    } catch(e) {}
}
document.addEventListener('DOMContentLoaded', loadImageApiConfig);

// 预设快捷填充
document.querySelectorAll('.img-preset').forEach(btn => {
    btn.addEventListener('click', () => {
        const p = JSON.parse(btn.dataset.imgPreset);
        document.getElementById('img-api-url').value = p.api_url || '';
        document.getElementById('img-api-mode').value = p.mode || 'images';
        document.getElementById('img-model-name').value = p.model || 'gpt-image-2';
        updateImageApiModeHelp();
    });
});

function updateImageApiModeHelp() {
    const mode = document.getElementById('img-api-mode').value;
    const help = document.getElementById('img-api-mode-help');
    if (mode === 'chat_image') {
        help.innerHTML = '请求 <code>/chat/completions</code>，仅兼容会在文本/SSE 中明确返回 HTTPS 图片链接或 base64 图片的供应商；生成尺寸是否生效由供应商决定。';
    } else {
        help.innerHTML = '请求 <code>/images/generations</code>，读取顶层 <code>data[0].b64_json</code> 或 <code>data[0].url</code>。';
    }
}
document.getElementById('img-api-mode').addEventListener('change', updateImageApiModeHelp);

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
        const data = await apiPost('cover_actions', {
            action: 'save_image_api_config',
            api_url: document.getElementById('img-api-url').value.trim(),
            api_key: document.getElementById('img-api-key').value.trim(),
            mode: document.getElementById('img-api-mode').value,
            model: document.getElementById('img-model-name').value.trim() || 'gpt-image-2',
            size: document.getElementById('img-size').value,
            prompt_prefix: document.getElementById('img-prompt-prefix').value.trim(),
        });
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

        if (!apiUrl) {
            status.className = 'mt-2 small text-warning';
            status.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>请先填写 API 地址';
            return;
        }

        const data = await apiPost('cover_actions', {
            action: 'test_image_api_config',
            api_url: apiUrl,
            api_key: apiKey,
            mode: document.getElementById('img-api-mode').value,
            model: document.getElementById('img-model-name').value.trim() || 'gpt-image-2'
        });
        if (data.ok) {
            status.className = 'mt-2 small text-success';
            status.innerHTML = '<i class="bi bi-check-circle me-1"></i>' + (data.msg || '连接成功');
        } else {
            status.className = 'mt-2 small text-warning';
            status.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>' + (data.msg || '连接失败，请检查配置');
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
        const data = await apiPost('actions', {
            action: 'test_model',
            model_id: parseInt(modelId),
        });
        
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

// ── 远程公告加载（15秒超时）─────────────────────────────────────
async function loadAnnounce(url) {
    const contentWrap  = document.getElementById('announce-content-wrap');
    const contentArea  = document.getElementById('announce-content');
    const placeholder  = document.getElementById('announce-placeholder');
    const statusEl     = document.getElementById('announce-status');

    if (!url) {
        contentWrap.style.display = 'none';
        placeholder.style.display = '';
        placeholder.innerHTML = '<i class="bi bi-info-circle me-1"></i>请设置公告源地址';
        statusEl.textContent = '';
        return;
    }

    // 显示加载状态
    placeholder.style.display = '';
    placeholder.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>正在加载公告...';
    contentWrap.style.display = 'none';
    statusEl.innerHTML = '<span class="text-muted"><i class="bi bi-arrow-repeat spin me-1"></i>加载中</span>';

    const controller = new AbortController();
    const timeoutId  = setTimeout(() => controller.abort(), 15000);

    try {
        const response = await fetch(url, {
            signal: controller.signal,
            headers: { 'Accept': 'text/html,text/plain,*/*' },
        });
        clearTimeout(timeoutId);

        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }

        const html = await response.text();
        if (!html.trim()) throw new Error('返回内容为空');

        contentArea.innerHTML = html;
        contentWrap.style.display = '';
        placeholder.style.display = 'none';
        statusEl.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>已加载</span>';

    } catch (e) {
        clearTimeout(timeoutId);
        contentWrap.style.display = 'none';
        placeholder.style.display = '';
        const errMsg = e.name === 'AbortError' ? '请求超时（15秒）' : e.message;
        placeholder.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i><span class="text-warning">加载失败：' + escapeHtml(errMsg) + '</span>';
        statusEl.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>失败</span>';
    }
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function refreshAnnounce() {
    const url = document.getElementById('announce-url').value.trim();
    loadAnnounce(url);
}

// 保存公告地址到 system_settings
async function saveAnnounceUrl() {
    const url     = document.getElementById('announce-url').value.trim();
    const statusEl = document.getElementById('announce-status');
    statusEl.innerHTML = '<span class="text-muted"><i class="bi bi-hourglass-split me-1"></i>保存中</span>';
 
    try {
        const data = await apiPost('actions', {
            action: 'save_announcement_url',
            url: url,
        });
        if (data.ok) {
            statusEl.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>已保存</span>';
            // 保存成功后自动加载公告
            loadAnnounce(url);
        } else {
            statusEl.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + (data.msg || '保存失败') + '</span>';
        }
    } catch(e) {
        statusEl.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>保存失败</span>';
    }
}

// 页面加载时自动拉取公告（如果已配置 URL）
(function() {
    const urlInput = document.getElementById('announce-url');
    const url = urlInput?.value.trim();
    if (url) {
        // 延迟一小段等页面渲染完
        setTimeout(() => loadAnnounce(url), 300);
    }
})();

// 旋转动画
const spinStyle = document.createElement('style');
spinStyle.textContent = '.spin { animation: spin-ann 1s linear infinite; display: inline-block; } @keyframes spin-ann { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }';
document.head.appendChild(spinStyle);
</script>

<?php pageFooter(); ?>
