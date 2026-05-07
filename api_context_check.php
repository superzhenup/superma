<?php
/**
 * API Prompt 上下文检测工具
 * 用于检测各类型 prompt 的 token 消耗，帮助优化
 */
define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/ai.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';

requireLogin();

// 获取小说列表
$novels = DB::fetchAll('SELECT id, title, genre, model_id FROM novels ORDER BY id DESC');
$models = DB::fetchAll('SELECT * FROM ai_models ORDER BY is_default DESC, id ASC');

$action = $_GET['action'] ?? '';

// ============================================================
// AJAX: 构建 prompt 并估算 token
// ============================================================
if ($action === 'check') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $novelId   = (int)($_POST['novel_id'] ?? 0);
        $checkType = $_POST['check_type'] ?? 'outline';
        $modelId   = (int)($_POST['model_id'] ?? 0) ?: null;
        $contextWindow = (int)($_POST['context_window'] ?? 128000);
        $realCall  = !empty($_POST['real_call']); // 是否真实调用 API

        $novel = DB::fetch('SELECT * FROM novels WHERE id=?', [$novelId]);
        if (!$novel) throw new Exception('小说不存在');

        $ai = getAIClient($modelId);
        $modelInfo = [
            'name'      => $ai->modelLabel,
            'model'     => $ai->modelName,
            'max_tokens'=> $ai->getMaxTokens(),
        ];

        $messages = [];
        $promptLabel = '';

        switch ($checkType) {
            case 'outline':
                require_once __DIR__ . '/includes/prompt.php';
                require_once __DIR__ . '/includes/memory/MemoryEngine.php';
                $engine = new MemoryEngine($novelId);

                // 模拟第 1 批（第1~5章）
                $recentOutlines = DB::fetchAll(
                    'SELECT chapter_number, title, outline, hook, pacing, suspense FROM chapters
                     WHERE novel_id=? AND chapter_number < 1 AND outline IS NOT NULL AND outline != ""
                     ORDER BY chapter_number DESC LIMIT 8',
                    [$novelId]
                );
                $recentOutlines = array_reverse($recentOutlines);

                $queryText = trim(($novel['genre'] ?? '') . '：' . ($novel['plot_settings'] ?? ''));
                try {
                    $memoryCtx = $engine->getPromptContext(1, $queryText !== '：' ? $queryText : null, 5000, 20, 6);
                } catch (Throwable $e) {
                    $memoryCtx = null;
                }

                $existingTitleRows = DB::fetchAll(
                    'SELECT chapter_number, title FROM chapters WHERE novel_id=? AND title IS NOT NULL AND title != "" ORDER BY chapter_number ASC',
                    [$novelId]
                );
                $existingTitles = array_column($existingTitleRows, 'title', 'chapter_number');
                $messages = buildOutlinePrompt($novel, 1, 5, $recentOutlines, '', $memoryCtx, null, $existingTitles);
                $promptLabel = '大纲生成（第1-5章）';
                break;

            case 'outline_later':
                require_once __DIR__ . '/includes/prompt.php';
                require_once __DIR__ . '/includes/memory/MemoryEngine.php';
                $engine = new MemoryEngine($novelId);

                // 模拟后面的批次（取已有大纲最多的位置）
                $lastCh = DB::fetch(
                    'SELECT MAX(chapter_number) as m FROM chapters WHERE novel_id=? AND status != "pending"',
                    [$novelId]
                );
                $startCh = max(1, (int)($lastCh['m'] ?? 0) + 1);

                $recentOutlines = DB::fetchAll(
                    'SELECT chapter_number, title, outline, hook, pacing, suspense FROM chapters
                     WHERE novel_id=? AND chapter_number < ? AND outline IS NOT NULL AND outline != ""
                     ORDER BY chapter_number DESC LIMIT 8',
                    [$novelId, $startCh]
                );
                $recentOutlines = array_reverse($recentOutlines);

                $prevHook = '';
                if (!empty($recentOutlines)) {
                    $lastOutline = end($recentOutlines);
                    $prevHook = trim($lastOutline['hook'] ?? '');
                }

                $queryText = trim(($novel['genre'] ?? '') . '：' . ($novel['plot_settings'] ?? ''));
                try {
                    $memoryCtx = $engine->getPromptContext($startCh, $queryText !== '：' ? $queryText : null, 5000, 20, 6);
                } catch (Throwable $e) {
                    $memoryCtx = null;
                }

                $endCh = $startCh + 4;
                $existingTitleRows = DB::fetchAll(
                    'SELECT chapter_number, title FROM chapters WHERE novel_id=? AND title IS NOT NULL AND title != "" ORDER BY chapter_number ASC',
                    [$novelId]
                );
                $existingTitles = array_column($existingTitleRows, 'title', 'chapter_number');
                $messages = buildOutlinePrompt($novel, $startCh, $endCh, $recentOutlines, $prevHook, $memoryCtx, null, $existingTitles);
                $promptLabel = "大纲生成（第{$startCh}-{$endCh}章，有前情上下文）";
                break;

            case 'chapter':
                require_once __DIR__ . '/includes/prompt.php';
                require_once __DIR__ . '/includes/memory/MemoryEngine.php';
                $engine = new MemoryEngine($novelId);

                // 取第一章有大纲的章节
                $chapter = DB::fetch(
                    'SELECT * FROM chapters WHERE novel_id=? AND outline IS NOT NULL AND outline != "" ORDER BY chapter_number ASC LIMIT 1',
                    [$novelId]
                );
                if (!$chapter) throw new Exception('没有已生成大纲的章节');

                // 取前章摘要
                $chNum = (int)($chapter['chapter_number'] ?? $chapter['chapter'] ?? 0);
                $previousSummary = '';
                $prevChapters = DB::fetchAll(
                    'SELECT chapter_number, title, outline FROM chapters
                     WHERE novel_id=? AND chapter_number<? AND outline IS NOT NULL
                     ORDER BY chapter_number DESC LIMIT 3',
                    [$novelId, $chNum]
                );
                if (!empty($prevChapters)) {
                    $parts = [];
                    foreach (array_reverse($prevChapters) as $pc) {
                        $parts[] = "第{$pc['chapter_number']}章《{$pc['title']}》：" . safe_substr($pc['outline'], 0, 100);
                    }
                    $previousSummary = implode("\n", $parts);
                }

                $queryText = trim(($novel['genre'] ?? '') . '：' . ($novel['plot_settings'] ?? ''));
                try {
                    $memoryCtx = $engine->getPromptContext(
                        $chNum,
                        $queryText !== '：' ? $queryText : null,
                        8000, 30, 6
                    );
                } catch (Throwable $e) {
                    $memoryCtx = null;
                }

                $messages = buildChapterPrompt($novel, $chapter, $previousSummary, '', $memoryCtx);
                $promptLabel = "正文写作（第{$chNum}章）";
                break;

            case 'story_outline':
                require_once __DIR__ . '/includes/prompt.php';
                $messages = buildStoryOutlinePrompt($novel);
                $promptLabel = '全书故事大纲';
                break;

            default:
                throw new Exception('未知检测类型');
        }

        // 估算 token（中文约 1.5 字/token，英文约 4 字符/token）
        $totalChars = 0;
        $promptDetails = [];
        foreach ($messages as $idx => $msg) {
            $len = mb_strlen($msg['content']);
            $totalChars += $len;
            // 中文 token 估算：约 1 字 = 0.7 token（含标点、英文混合）
            $estTokens = (int)($len * 0.7);
            $promptDetails[] = [
                'role'   => $msg['role'],
                'chars'  => $len,
                'est_tokens' => $estTokens,
                'preview' => mb_substr($msg['content'], 0, 200) . (mb_strlen($msg['content']) > 200 ? '...' : ''),
            ];
        }
        $estTotalTokens = (int)($totalChars * 0.7);

        $result = [
            'success'       => true,
            'prompt_label'  => $promptLabel,
            'model_info'    => $modelInfo,
            'total_chars'   => $totalChars,
            'est_tokens'    => $estTotalTokens,
            'max_tokens'    => $ai->getMaxTokens(),
            'context_window'=> $contextWindow,
            'usage_percent' => round($estTotalTokens / $contextWindow * 100, 1),
            'remaining_tokens' => $contextWindow - $estTotalTokens,
            'prompt_details'=> $promptDetails,
            'messages_raw'  => $messages,
        ];

        // 如果需要真实调用 API，发送一个极短的 max_tokens 请求来获取精确 token 计数
        if ($realCall) {
            // 临时设置 max_tokens=1 来最小化消耗
            $ai->setMaxTokens(1);
            try {
                // 用 chatStream 获取精确 usage（max_tokens=1 只消耗约1个completion token）
                $usage = $ai->chatStream($messages, function($chunk) {
                    // 忽略输出，只收集 usage
                }, 'structured');
                $result['real_usage'] = $usage;
                $result['real_prompt_tokens'] = $usage['prompt_tokens'] ?? 0;
                $result['real_completion_tokens'] = $usage['completion_tokens'] ?? 0;
                $result['real_total_tokens'] = $usage['total_tokens'] ?? 0;

                // 用真实 token 覆盖估算值
                $realPrompt = $usage['prompt_tokens'] ?? 0;
                $result['usage_percent'] = round($realPrompt / $contextWindow * 100, 1);
                $result['remaining_tokens'] = $contextWindow - $realPrompt;
            } catch (Exception $e) {
                $result['real_error'] = $e->getMessage();
            }
        }

        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ============================================================
// 页面渲染
// ============================================================
pageHeader('API 上下文检测', 'settings');
?>

<style>
.dt-card { background:var(--bg-card); border:1px solid var(--border); border-radius:12px; padding:24px; margin-bottom:20px; }
.dt-card h5 { color:var(--accent); margin-bottom:16px; }
.dt-form-row { display:flex; gap:12px; margin-bottom:12px; flex-wrap:wrap; }
.dt-form-row label { color:var(--text); font-weight:600; min-width:80px; line-height:38px; }
.dt-form-row select, .dt-form-row input { flex:1; min-width:160px; }
.dt-result { margin-top:20px; }
.dt-metric { display:inline-flex; flex-direction:column; align-items:center; padding:16px 24px; background:rgba(99,102,241,.08); border-radius:10px; margin:6px; }
.dt-metric .val { font-size:28px; font-weight:700; color:var(--accent); }
.dt-metric .lbl { font-size:12px; color:var(--text-muted); margin-top:4px; }
.dt-metric.warn .val { color:#f59e0b; }
.dt-metric.danger .val { color:#ef4444; }
.dt-msg-row { display:flex; gap:12px; padding:12px; border:1px solid var(--border); border-radius:8px; margin-bottom:8px; }
.dt-msg-role { font-weight:700; min-width:70px; padding-top:4px; }
.dt-msg-role.system { color:#6366f1; }
.dt-msg-role.user { color:#10b981; }
.dt-msg-role.assistant { color:#f59e0b; }
.dt-msg-body { flex:1; }
.dt-msg-chars { color:var(--text-muted); font-size:12px; }
.dt-raw-toggle { cursor:pointer; color:var(--accent); font-size:13px; }
.dt-raw-content { display:none; background:rgba(0,0,0,.3); border-radius:8px; padding:16px; margin-top:8px; max-height:500px; overflow:auto; white-space:pre-wrap; word-break:break-all; font-size:13px; color:#ccc; }
.dt-raw-content.show { display:block; }
.dt-bar { height:8px; background:rgba(255,255,255,.1); border-radius:4px; margin-top:8px; }
.dt-bar-fill { height:100%; border-radius:4px; transition:width .3s; }
</style>

<div class="dt-card">
    <h5><i class="bi bi-speedometer2 me-2"></i>API Prompt 上下文检测</h5>
    <p style="color:var(--text-muted);font-size:14px">检测各类型 prompt 的 token 消耗，帮助定位上下文超限问题。</p>

    <div class="dt-form-row">
        <label>小说</label>
        <select class="form-select" id="selNovel">
            <option value="">选择小说</option>
            <?php foreach ($novels as $n): ?>
            <option value="<?= $n['id'] ?>"><?= h($n['title']) ?>（ID:<?= $n['id'] ?>）</option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="dt-form-row">
        <label>检测类型</label>
        <select class="form-select" id="selType">
            <option value="outline">大纲生成（第1批，无前情）</option>
            <option value="outline_later">大纲生成（后续批次，有前情）</option>
            <option value="chapter">正文写作</option>
            <option value="story_outline">全书故事大纲</option>
        </select>
    </div>

    <div class="dt-form-row">
        <label>模型</label>
        <select class="form-select" id="selModel">
            <option value="">默认模型</option>
            <?php foreach ($models as $m): ?>
            <option value="<?= $m['id'] ?>"><?= h($m['name']) ?>（<?= h($m['model_name']) ?>）</option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="dt-form-row">
        <label>上下文窗口</label>
        <input type="number" class="form-control" id="inputContextWindow" placeholder="模型的上下文窗口大小（tokens），如 128000" value="128000" style="max-width:260px">
        <small style="color:var(--text-muted);line-height:38px">常见值：GPT-4o=128K, Claude3.5=200K, DeepSeek-V3=128K, Qwen2.5=128K</small>
    </div>

    <div class="dt-form-row">
        <label></label>
        <div class="form-check" style="line-height:38px">
            <input class="form-check-input" type="checkbox" id="chkRealCall">
            <label class="form-check-label" for="chkRealCall" style="font-weight:normal;color:var(--text-muted)">
                真实调用 API（<strong style="color:#f59e0b">会消耗约 1 token</strong>，但可获取精确 token 计数）
            </label>
        </div>
    </div>

    <div class="dt-form-row">
        <label></label>
        <button class="btn btn-primary" onclick="doCheck()">
            <i class="bi bi-search me-1"></i>开始检测
        </button>
    </div>
</div>

<div class="dt-card dt-result" id="resultArea" style="display:none">
    <h5 id="resultTitle">检测结果</h5>

    <div id="metricsRow" style="display:flex;flex-wrap:wrap;justify-content:center;margin-bottom:20px"></div>

    <div id="contextBar" style="margin-bottom:20px">
        <div style="display:flex;justify-content:space-between;font-size:13px;color:var(--text-muted)">
            <span>上下文用量</span>
            <span id="contextBarLabel"></span>
        </div>
        <div class="dt-bar"><div class="dt-bar-fill" id="contextBarFill"></div></div>
    </div>

    <h6 style="color:var(--text);margin-bottom:12px">Prompt 分段详情</h6>
    <div id="msgDetails"></div>

    <div style="margin-top:16px">
        <span class="dt-raw-toggle" onclick="toggleRaw()"><i class="bi bi-code-slash me-1"></i>查看完整 Prompt 原文</span>
        <div class="dt-raw-content" id="rawContent"></div>
    </div>
</div>

<script>
async function doCheck() {
    const novelId = document.getElementById('selNovel').value;
    if (!novelId) { showToast('请选择小说','error'); return; }

    const btn = event.target.closest('button');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>检测中...';

    try {
        const fd = new FormData();
        fd.append('novel_id', novelId);
        fd.append('check_type', document.getElementById('selType').value);
        fd.append('model_id', document.getElementById('selModel').value);
        fd.append('context_window', document.getElementById('inputContextWindow').value || '128000');
        fd.append('real_call', document.getElementById('chkRealCall').checked ? '1' : '');

        const res = await fetch('?action=check', { method:'POST', body:fd });
        const data = await res.json();

        if (!data.success) { showToast(data.error || '检测失败','error'); return; }

        renderResult(data);
    } catch(e) {
        showToast('请求失败：' + e.message, 'error');
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-search me-1"></i>开始检测';
}

function renderResult(data) {
    const area = document.getElementById('resultArea');
    area.style.display = '';

    document.getElementById('resultTitle').textContent = data.prompt_label + ' — ' + (data.model_info.name || '');

    // 指标卡片
    const tokens = data.real_prompt_tokens || data.est_tokens;
    const maxTokens = data.max_tokens;
    const contextWindow = data.context_window || (maxTokens * 4); // 粗估
    const usagePercent = data.usage_percent || Math.round(tokens / contextWindow * 100);

    let metricsHtml = '';
    metricsHtml += metricCard(data.total_chars.toLocaleString(), '总字符数');
    metricsHtml += metricCard(tokens.toLocaleString(), data.real_prompt_tokens ? '实际 Prompt Tokens' : '估算 Tokens');
    if (data.real_prompt_tokens) {
        metricsHtml += metricCard(data.real_completion_tokens.toLocaleString(), '实际 Completion Tokens');
        metricsHtml += metricCard(data.real_total_tokens.toLocaleString(), '实际总 Tokens');
    }
    if (data.context_window) {
        metricsHtml += metricCard(data.context_window.toLocaleString(), '模型上下文窗口');
        metricsHtml += metricCard(data.remaining_tokens.toLocaleString(), '剩余可用 Tokens', usagePercent > 80 ? 'warn' : '');
    }
    metricsHtml += metricCard(usagePercent + '%', '上下文用量', usagePercent > 90 ? 'danger' : usagePercent > 70 ? 'warn' : '');
    document.getElementById('metricsRow').innerHTML = metricsHtml;

    // 上下文用量条
    const barFill = document.getElementById('contextBarFill');
    barFill.style.width = Math.min(100, usagePercent) + '%';
    barFill.style.background = usagePercent > 90 ? '#ef4444' : usagePercent > 70 ? '#f59e0b' : '#10b981';
    document.getElementById('contextBarLabel').textContent =
        `${tokens.toLocaleString()} / ${data.context_window ? data.context_window.toLocaleString() : '?'} tokens（${usagePercent}%）`;

    // 分段详情
    let msgHtml = '';
    for (const m of data.prompt_details) {
        const roleClass = m.role;
        const roleLabel = m.role === 'system' ? 'System' : m.role === 'user' ? 'User' : 'Assistant';
        msgHtml += `<div class="dt-msg-row">
            <div class="dt-msg-role ${roleClass}">${roleLabel}</div>
            <div class="dt-msg-body">
                <div class="dt-msg-chars">${m.chars.toLocaleString()} 字 / ~${m.est_tokens.toLocaleString()} tokens</div>
                <div style="margin-top:4px;color:var(--text-muted);font-size:13px">${escHtml(m.preview)}</div>
            </div>
        </div>`;
    }
    document.getElementById('msgDetails').innerHTML = msgHtml;

    // 完整 prompt
    let rawText = '';
    for (const m of data.messages_raw) {
        rawText += `[${m.role.toUpperCase()}]\n${m.content}\n${'='.repeat(60)}\n\n`;
    }
    document.getElementById('rawContent').textContent = rawText;

    // 错误
    if (data.real_error) {
        showToast('API 调用出错：' + data.real_error, 'error');
    }
}

function metricCard(val, lbl, cls='') {
    return `<div class="dt-metric ${cls}"><div class="val">${val}</div><div class="lbl">${lbl}</div></div>`;
}

function toggleRaw() {
    document.getElementById('rawContent').classList.toggle('show');
}

function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
</script>

<?php pageFooter(); ?>
