<?php
/**
 * 拆书分析 API
 * analyze_step: SSE 流式，单步分析，有实时输出 + 心跳
 * rewrite: JSON，一键改写
 */

// 强制禁用输出缓冲（SSE 必须）
ini_set('output_buffering', 'Off');
ini_set('implicit_flush', 'On');
ini_set('zlib.output_compression', 'Off');

ob_start();
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('APP_LOADED', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai.php';

requireLoginApi();
session_write_close();

ob_end_clean();

$action = $_GET['action'] ?? '';

// SSE 请求的特殊处理
$isSSE = ($action === 'analyze_step');

if ($isSSE) {
    set_time_limit(CFG_TIME_MEDIUM);
    while (ob_get_level()) ob_end_clean();
}

try {
    switch ($action) {
        case 'analyze_step':
            analyzeStep();
            break;
        case 'rewrite':
            rewriteToNovel();
            break;
        case 'save':
            saveAnalysis();
            break;
        case 'list':
            listAnalyses();
            break;
        case 'get':
            getAnalysis();
            break;
        case 'delete':
            deleteAnalysis();
            break;
        case 'upload_txt':
            uploadTxt();
            break;
        default:
            header('Content-Type: application/json; charset=utf-8');
            throw new Exception('无效的操作');
    }
} catch (Exception $e) {
    if ($isSSE) {
        // SSE 模式下通过事件报错
        sseSend('error', ['message' => $e->getMessage()]);
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
}

// ============================================================
// 分步分析 — SSE 流式
// ============================================================
function analyzeStep() {
    // SSE 头
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');

    $input = json_decode(file_get_contents('php://input'), true);

    $title      = trim($input['title'] ?? '');
    $author     = trim($input['author'] ?? '');
    $genre      = trim($input['genre'] ?? '');
    $chapters   = trim($input['chapters'] ?? '');
    $step       = trim($input['step'] ?? '');
    $modelId    = (int)($input['model_id'] ?? 0) ?: null;
    $prevResult = trim($input['prev_result'] ?? '');

    if (empty($title))    { sseSend('error', ['message' => '请输入书名']); return; }
    if (empty($chapters)) { sseSend('error', ['message' => '请粘贴或上传章节内容']); return; }
    if (empty($step))     { sseSend('error', ['message' => '缺少 step 参数']); return; }

    // 截取过长内容
    $maxChars = 60000;
    if (mb_strlen($chapters) > $maxChars) {
        $chapters = mb_substr($chapters, 0, $maxChars) . "\n\n[内容过长，已截取前 {$maxChars} 字]";
        sseSend('warning', ['message' => "内容已截取前 {$maxChars} 字"]);
    }

    try {
        $ai = getAIClient($modelId);

        sseSend('model', ['name' => $ai->modelLabel, 'model_name' => $ai->modelName]);
        sseSend('status', ['message' => '正在分析...']);

        $messages = buildStepPrompt($title, $author, $genre, $chapters, $step, $prevResult);

        $fullContent = '';
        $lastHeartbeat = time();

        // 注册全局心跳（供 ai.php CURLOPT_PROGRESSFUNCTION 调用）
        $GLOBALS['sendHeartbeat'] = function() use (&$lastHeartbeat) {
            $now = time();
            if ($now - $lastHeartbeat >= 3) {
                sseSend('heartbeat', ['time' => $now]);
                $lastHeartbeat = $now;
            }
        };

        try {
            $ai->chatStream($messages, function($chunk) use (&$fullContent, &$lastHeartbeat) {
                if ($chunk === '[DONE]') return;
                $fullContent .= $chunk;
                sseSend('chunk', ['t' => $chunk]);
                // 每 2 秒检查心跳
                $now = time();
                if ($now - $lastHeartbeat >= 3) {
                    sseSend('heartbeat', ['time' => $now]);
                    $lastHeartbeat = $now;
                }
            }, 'creative');
        } catch (Exception $e) {
            // 流式失败，回退非流式
            sseSend('status', ['message' => '流式不可用，切换普通模式...']);
            $fullContent = $ai->chat($messages, 'creative');
            // 一次性输出
            if ($fullContent) {
                sseSend('chunk', ['t' => $fullContent]);
            }
        }

        unset($GLOBALS['sendHeartbeat']);

        sseSend('done', ['step' => $step, 'total_chars' => mb_strlen($fullContent)]);

    } catch (Exception $e) {
        sseSend('error', ['message' => $e->getMessage()]);
    }
}

// ============================================================
// SSE 发送
// ============================================================
function sseSend(string $event, $data): void {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    echo "event: {$event}\ndata: {$json}\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

// ============================================================
// 一键改写 — JSON
// ============================================================
function rewriteToNovel() {
    header('Content-Type: application/json; charset=utf-8');
    set_time_limit(CFG_TIME_SHORT);
    
    $input = json_decode(file_get_contents('php://input'), true);

    $analysis       = trim($input['analysis'] ?? '');
    $title          = trim($input['title'] ?? '');
    $protagonistName = trim($input['protagonist_name'] ?? '');
    $modelId        = (int)($input['model_id'] ?? 0) ?: null;

    if (empty($analysis))        throw new Exception('缺少分析结果');
    if (empty($title))           throw new Exception('缺少书名');
    if (empty($protagonistName)) throw new Exception('缺少主角姓名');

    $ai = getAIClient($modelId);

    $renameRule = <<<RULE
【重要：角色改名规则】
- 主角姓名必须使用用户指定的名字：{$protagonistName}，绝对不能修改
- 除主角外，所有其他角色（女主、配角、反派等）的名字都必须随机替换为全新的名字
- 男性角色命名规则：姓 + 1~2字名，如"叶辰""萧云""苏墨白"，要符合男性气质
- 女性角色命名规则：姓 + 1~2字名，如"林清雪""苏沐橙""白婉儿"，要柔美优雅
- 新名字要与原角色的性格/身份/气质匹配，不要出现违和的名字
- 在 protagonist_info、world_settings、plot_settings、extra_settings 中凡是涉及角色名字的地方都要替换
- 不要在输出中说明"原名XX改为XX"，直接使用新名字即可
RULE;

    $messages = [
        [
            'role' => 'system',
            'content' => '你是一个网文分析助手。用户会给你一份拆书分析报告，你需要从中提取关键信息，输出为 JSON 格式，供新建小说时使用。只输出 JSON，不要输出任何其他内容。'
        ],
        [
            'role' => 'user',
            'content' => <<<PROMPT
请根据以下拆书分析报告，提取出新建小说所需的信息，输出为 JSON 格式。

书名：《{$title}》
主角姓名：{$protagonistName}

{$renameRule}

分析报告：
{$analysis}

请输出如下 JSON（所有字段都是字符串，不要输出 null，没有的填空字符串）：
```json
{
  "title": "书名",
  "genre": "类型（从以下选一个：玄幻修仙/都市言情/科幻末世/历史穿越/武侠仙侠/悬疑推理/奇幻冒险/军事战争/游戏竞技/其他）",
  "protagonist_name": "主角姓名",
  "protagonist_info": "主角信息（200字以内，包含背景、性格、能力、核心矛盾，使用新名字）",
  "world_settings": "世界观设定（300字以内，涉及角色处使用新名字）",
  "plot_settings": "情节设定（300字以内，包含主线、核心矛盾、重要事件，涉及角色处使用新名字）",
  "writing_style": "写作风格描述（50字以内）",
  "extra_settings": "额外设定（其他重要配角、金手指系统、特殊设定等，300字以内，所有配角使用新名字）"
}
```

注意：
1. 只输出 JSON，不要有任何其他文字
2. 内容要精炼，不要照搬原文的长篇大论
3. 每个字段要有实质内容，不要写"详见上文"
4. 严格遵守角色改名规则，主角名用 {$protagonistName}，其他角色全部换新名
PROMPT
        ],
    ];

    $content = '';
    try {
        $ai->chatStream($messages, function($chunk) use (&$content) {
            if ($chunk === '[DONE]') return;
            $content .= $chunk;
        }, 'structured');
    } catch (Exception $e) {
        $content = $ai->chat($messages, 'structured');
    }

    // 提取 JSON
    $jsonStr = $content;
    if (preg_match('/```json\s*([\s\S]+?)\s*```/', $content, $m)) {
        $jsonStr = $m[1];
    } elseif (preg_match('/\{[\s\S]+\}/', $content, $m)) {
        $jsonStr = $m[0];
    }

    $data = json_decode($jsonStr, true);
    if (!$data) {
        echo json_encode(['success' => true, 'raw' => $content, 'fields' => null], JSON_UNESCAPED_UNICODE);
        return;
    }

    echo json_encode(['success' => true, 'raw' => $content, 'fields' => $data], JSON_UNESCAPED_UNICODE);
}

// ============================================================
// 分步 Prompt
// ============================================================
function buildStepPrompt(string $title, string $author, string $genre, string $chapters, string $step, string $prevResult): array {
    $metaInfo = "书名：《{$title}》";
    if ($author) $metaInfo .= "\n作者：{$author}";
    if ($genre)  $metaInfo .= "\n类型：{$genre}";

    $prevContext = '';
    if ($prevResult) {
        $prevContext = "\n\n【之前步骤的分析结果（供参考，避免重复）】：\n" . mb_substr($prevResult, 0, 3000);
    }

    $systemBase = '你是一位专业的网文分析师，擅长深度拆解网络文学作品。请用 Markdown 格式输出，善用表格、列表等排版。分析要深入、专业、有洞察力。';

    $stepPrompts = [
        'characters' => "请分析《{$title}》的核心人设。\n\n对每个重要角色，用表格详细列出：\n| 维度 | 详细设定 |\n|------|---------|\n\n角色包括：主角、女主角（如有多个分别列出）、重要配角。\n\n每个角色至少包含以下维度：\n- 身份、外形特征、性格特点\n- 核心矛盾/前史创伤\n- 人物弧光（成长变化方向）\n- 底层价值观/信念\n- 角色功能定位\n- 感情线索/与其他角色的关系\n{$prevContext}",

        'worldview' => "请分析《{$title}》的世界观与背景设定。\n\n包括：\n1. **世界背景** — 与现实的异同，时代背景\n2. **核心矛盾** — 推动故事的根本冲突\n3. **金手指/系统设计**（如有）— 详细解析架构、货币、等级、技能、限制\n4. **势力/组织架构**（如有）\n5. **独特设定** — 区别于同类作品的创新点\n{$prevContext}",

        'storyline' => "请梳理《{$title}》的完整故事线大纲。\n\n按幕/卷展开，标注章节范围：\n- 每幕的核心事件、转折点、情感高潮\n- 角色关系变化\n- 关键打脸/爽点场景\n- 已埋下的伏笔\n- 对后续剧情的合理推测\n\n用层级列表呈现，主线用粗体标注。\n{$prevContext}",

        'emotion' => "请分析《{$title}》的情绪曲线与节奏设计。\n\n1. **情绪曲线图** — 用文字图表展示情绪起伏\n2. **节奏规律** — 快慢交替模式\n3. **钩子密度** — 每N字一个钩子\n4. **爽点设计公式** — 归纳反复使用的爽点公式\n5. **低谷与调剂** — 高燃之间的调节手法\n{$prevContext}",

        'explosive' => "请深度分析《{$title}》的爆款原因。\n\n1. **社会情绪共振点** — 踩中了什么社会心理？\n2. **双重代入感机制**\n3. **爽点设计公式**\n4. **钩子密度分析**\n5. **配角反应放大法**\n6. **幽默感与爽感的平衡技巧**\n7. **稀缺赛道差异化**\n{$prevContext}",

        'summary' => "请为《{$title}》提炼爆款公式。\n\n1. 用一句话概括本书的核心卖点\n2. 用公式表达：爆款 = A × B × C × ...\n3. 最值得其他作者学习的3个技巧\n4. 本书最大的风险/短板是什么？\n{$prevContext}",
    ];

    if (!isset($stepPrompts[$step])) {
        throw new Exception("未知分析步骤: {$step}");
    }

    return [
        ['role' => 'system', 'content' => $systemBase],
        ['role' => 'user',   'content' => "{$metaInfo}\n\n【以下是小说章节内容】：\n{$chapters}\n\n" . $stepPrompts[$step]],
    ];
}

// ============================================================
// 上传 TXT
// ============================================================
function uploadTxt() {
    header('Content-Type: application/json; charset=utf-8');

    if (empty($_FILES['txt_file']) || $_FILES['txt_file']['error'] !== UPLOAD_ERR_OK) {
        $errCodes = [
            UPLOAD_ERR_INI_SIZE   => '文件超过服务器限制',
            UPLOAD_ERR_FORM_SIZE  => '文件超过表单限制',
            UPLOAD_ERR_PARTIAL    => '文件上传不完整',
            UPLOAD_ERR_NO_FILE    => '没有文件被上传',
        ];
        $code = $_FILES['txt_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        throw new Exception($errCodes[$code] ?? '上传失败');
    }

    $file = $_FILES['txt_file'];
    if ($file['size'] > 10 * 1024 * 1024) throw new Exception('文件超过 10MB 限制');

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['txt', 'text'])) throw new Exception('仅支持 .txt 文本文件');

    $content = file_get_contents($file['tmp_name']);
    $encodings = ['UTF-8', 'GBK', 'GB2312', 'GB18030', 'BIG5'];
    $detected = mb_detect_encoding($content, $encodings, true);
    if ($detected && $detected !== 'UTF-8') {
        $content = mb_convert_encoding($content, 'UTF-8', $detected);
    }
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    $content = trim($content);
    if (empty($content)) throw new Exception('文件内容为空');

    echo json_encode(['success' => true, 'content' => $content, 'char_count' => mb_strlen($content), 'filename' => $file['name']], JSON_UNESCAPED_UNICODE);
}

// ============================================================
// CRUD
// ============================================================
function saveAnalysis() {
    header('Content-Type: application/json; charset=utf-8');
    $input = json_decode(file_get_contents('php://input'), true);
    $title = trim($input['title'] ?? '');
    $content = trim($input['content'] ?? '');
    if (empty($title) || empty($content)) throw new Exception('缺少必要参数');
    ensureBookAnalysesTable();
    $id = DB::insert('book_analyses', [
        'title' => $title,
        'author' => trim($input['author'] ?? '') ?: '',
        'genre' => trim($input['genre'] ?? '') ?: '',
        'content' => $content,
        'source_text' => trim($input['source_text'] ?? ''),
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    echo json_encode(['success' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
}

function listAnalyses() {
    header('Content-Type: application/json; charset=utf-8');
    ensureBookAnalysesTable();
    $rows = DB::fetchAll('SELECT id, title, author, genre, created_at FROM book_analyses ORDER BY created_at DESC LIMIT 50');
    echo json_encode(['success' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
}

function getAnalysis() {
    header('Content-Type: application/json; charset=utf-8');
    ensureBookAnalysesTable();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) throw new Exception('缺少 id');
    $row = DB::fetch('SELECT * FROM book_analyses WHERE id=?', [$id]);
    if (!$row) throw new Exception('记录不存在');
    echo json_encode(['success' => true, 'data' => $row], JSON_UNESCAPED_UNICODE);
}

function deleteAnalysis() {
    header('Content-Type: application/json; charset=utf-8');
    ensureBookAnalysesTable();
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);
    if (!$id) throw new Exception('缺少 id');
    DB::query('DELETE FROM book_analyses WHERE id=?', [$id]);
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
}

function ensureBookAnalysesTable() {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    DB::query("CREATE TABLE IF NOT EXISTS `book_analyses` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `title` VARCHAR(200) NOT NULL DEFAULT '',
        `author` VARCHAR(100) NOT NULL DEFAULT '',
        `genre` VARCHAR(100) NOT NULL DEFAULT '',
        `content` MEDIUMTEXT NOT NULL,
        `source_text` MEDIUMTEXT,
        `created_at` DATETIME NOT NULL,
        INDEX `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
