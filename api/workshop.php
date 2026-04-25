<?php
/**
 * 创意工坊 API
 * 生成小说创意框架
 */
define('APP_LOADED', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLoginApi();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai.php';

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'generate':
            generateIdea();
            break;
        case 'generate_stream':
            generateIdeaStream();
            break;
        case 'rewrite_section':
            rewriteSection();
            break;
        case 'create_novel':
            createNovel();
            break;
        case 'check_model':
            checkModel();
            break;
        default:
            header('Content-Type: application/json; charset=utf-8');
            throw new Exception('无效的操作');
    }
} catch (Exception $e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * 检查模型配置
 */
function checkModel() {
    header('Content-Type: application/json; charset=utf-8');
    
    $modelId = (int)($_GET['model_id'] ?? 0) ?: null;
    
    $model = $modelId 
        ? DB::fetch('SELECT id, name, model_name, api_url, is_default FROM ai_models WHERE id = ?', [$modelId])
        : DB::fetch('SELECT id, name, model_name, api_url, is_default FROM ai_models WHERE is_default = 1 LIMIT 1');
    
    if (!$model) {
        echo json_encode(['success' => false, 'error' => '未配置 AI 模型，请先在设置中添加']);
        return;
    }
    
    echo json_encode([
        'success' => true,
        'model' => [
            'id' => $model['id'],
            'name' => $model['name'],
            'model_name' => $model['model_name'],
            'api_url' => $model['api_url'],
            'is_default' => $model['is_default']
        ]
    ]);
}

/**
 * 生成小说创意（非流式）
 */
function generateIdea() {
    header('Content-Type: application/json; charset=utf-8');
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $idea = trim($input['idea'] ?? '');
    $genre = trim($input['genre'] ?? '');
    $style = trim($input['style'] ?? '');
    $plotPattern = trim($input['plot_pattern'] ?? '');
    $plotCustomDesc = trim($input['plot_custom_desc'] ?? '');
    $endingStyle = trim($input['ending_style'] ?? '');
    $endingCustomDesc = trim($input['ending_custom_desc'] ?? '');
    $extraSettings = trim($input['extra_settings'] ?? '');
    $modelId = (int)($input['model_id'] ?? 0) ?: null;
    
    if (empty($idea)) {
        throw new Exception('请输入小说思路');
    }
    
    // 使用系统已有的 AIClient
    $ai = getAIClient($modelId);
    
    // 构建提示词
    $prompt = buildPrompt($idea, $genre, $style, $plotPattern, $plotCustomDesc, $endingStyle, $endingCustomDesc, $extraSettings);
    
    // 调用 AI API
    $messages = [['role' => 'user', 'content' => $prompt]];
    $result = $ai->chat($messages, 'structured');
    
    // 解析结果
    $parsed = parseResult($result);
    
    echo json_encode([
        'success' => true,
        'data' => $parsed,
        'raw' => $result
    ]);
}

/**
 * 生成小说创意（流式输出）
 */
function generateIdeaStream() {
    // 设置超时时间
    set_time_limit(CFG_TIME_MEDIUM);
    
    // 设置 SSE 头
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // 禁用所有输出缓冲
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    ob_implicit_flush(true);
    
    // 忽略用户中断
    ignore_user_abort(true);
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $idea = trim($input['idea'] ?? '');
    $genre = trim($input['genre'] ?? '');
    $style = trim($input['style'] ?? '');
    $plotPattern = trim($input['plot_pattern'] ?? '');
    $plotCustomDesc = trim($input['plot_custom_desc'] ?? '');
    $endingStyle = trim($input['ending_style'] ?? '');
    $endingCustomDesc = trim($input['ending_custom_desc'] ?? '');
    $extraSettings = trim($input['extra_settings'] ?? '');
    $modelId = (int)($input['model_id'] ?? 0) ?: null;
    
    if (empty($idea)) {
        sendEvent('error', ['message' => '请输入小说思路']);
        return;
    }
    
    try {
        // 使用系统已有的 AIClient
        $ai = getAIClient($modelId);
        
        // 发送模型信息
        sendEvent('model', [
            'name' => $ai->modelLabel,
            'model_name' => $ai->modelName
        ]);
        
        // 构建提示词
        $prompt = buildPrompt($idea, $genre, $style, $plotPattern, $plotCustomDesc, $endingStyle, $endingCustomDesc, $extraSettings);
        
        sendEvent('status', ['message' => '正在连接 AI 服务...']);
        
        // 流式调用 AI
        $fullContent = '';
        $messages = [['role' => 'user', 'content' => $prompt]];
        $chunkCount = 0;
        $streamError = null;
        
        sendEvent('debug', ['message' => '开始流式请求...']);
        
        try {
            $ai->chatStream($messages, function($chunk) use (&$fullContent, &$chunkCount) {
                if ($chunk === '[DONE]') {
                    sendEvent('debug', ['message' => "流式完成，共收到 {$chunkCount} 个数据块"]);
                    return;
                }
                $fullContent .= $chunk;
                $chunkCount++;
                sendEvent('chunk', $chunk);
            }, 'structured');
        } catch (Exception $e) {
            $streamError = $e;
            sendEvent('debug', ['message' => '流式请求失败: ' . $e->getMessage()]);
        }
        
        // 如果流式失败或没有收到内容，回退到非流式
        if ($streamError || empty($fullContent)) {
            sendEvent('status', ['message' => '流式不可用，切换到普通模式...']);
            
            // 使用非流式方式
            $fullContent = $ai->chat($messages, 'structured');
            sendEvent('debug', ['message' => '非流式请求完成']);
        }
        
        sendEvent('status', ['message' => '正在解析结果...']);
        
        // 解析结果
        $parsed = parseResult($fullContent);
        
        sendEvent('done', [
            'data' => $parsed,
            'raw' => $fullContent
        ]);
        
    } catch (Exception $e) {
        // 记录详细错误信息
        error_log("Workshop Stream Error: " . $e->getMessage());
        sendEvent('error', ['message' => $e->getMessage()]);
    }
}

/**
 * 发送 SSE 事件
 */
function sendEvent($event, $data) {
    // 始终 json_encode，确保前端 JSON.parse 能正确解析
    $data = json_encode($data, JSON_UNESCAPED_UNICODE);
    
    $output = "event: {$event}\n";
    $output .= "data: {$data}\n\n";
    
    echo $output;
    
    // 强制刷新输出
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

/**
 * 重新生成/改写单个字段
 */
function rewriteSection() {
    header('Content-Type: application/json; charset=utf-8');
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $section = trim($input['section'] ?? '');    // 要改写的字段名
    $context = $input['context'] ?? [];           // 当前所有字段的值
    $feedback = trim($input['feedback'] ?? '');    // 用户意见（可选）
    $modelId = (int)($input['model_id'] ?? 0) ?: null;
    
    // 字段映射
    $sectionMap = [
        'protagonist_info' => [
            'label' => '主角信息',
            'desc' => '主角详细信息（背景、性格、能力、目标等，100-200字）',
            'length' => '100-200字',
        ],
        'world_settings' => [
            'label' => '世界观设定',
            'desc' => '世界观设定（世界背景、力量体系、地理环境、势力分布等，150-300字）',
            'length' => '150-300字',
        ],
        'plot_settings' => [
            'label' => '情节设定',
            'desc' => '情节设定（主线剧情、核心矛盾、重要转折点、最终目标等，200-400字）',
            'length' => '200-400字',
        ],
        'extra_settings' => [
            'label' => '额外设定',
            'desc' => '额外设定（重要配角、特殊物品、金手指设定等，100-200字）',
            'length' => '100-200字',
        ],
    ];
    
    if (!isset($sectionMap[$section])) {
        throw new Exception('无效的字段: ' . $section);
    }
    
    $info = $sectionMap[$section];
    $currentValue = $context[$section] ?? '';
    
    // 构建 prompt
    $contextStr = '';
    $contextFields = [
        'title' => '书名',
        'protagonist_name' => '主角姓名',
        'protagonist_info' => '主角信息',
        'world_settings' => '世界观设定',
        'plot_settings' => '情节设定',
        'extra_settings' => '额外设定',
    ];
    foreach ($contextFields as $key => $label) {
        if (isset($context[$key]) && $context[$key] !== '') {
            $contextStr .= "【{$label}】{$context[$key]}\n";
        }
    }
    
    if ($feedback) {
        // 有用户意见 → 改写
        $prompt = "你是一位专业的网文创意策划师。以下是一个小说框架的当前内容：

{$contextStr}

当前【{$info['label']}】的内容是：
{$currentValue}

用户希望对【{$info['label']}】进行修改，修改意见如下：
{$feedback}

请根据用户的修改意见，重新撰写【{$info['label']}】的内容。
要求：
1. 必须遵循用户的修改意见
2. 与其他字段的内容保持一致和呼应
3. 字数控制在{$info['length']}
4. 只输出修改后的内容，不要输出任何其他文字、标注或解释";
    } else {
        // 无意见 → 重新生成
        $prompt = "你是一位专业的网文创意策划师。以下是一个小说框架的当前内容：

{$contextStr}

请重新生成【{$info['label']}】的内容（{$info['desc']}）。
要求：
1. 保持与其他字段的一致性和呼应
2. 与当前内容有所区别，提供新的创意
3. 字数控制在{$info['length']}
4. 只输出重新生成的内容，不要输出任何其他文字、标注或解释";
    }
    
    $ai = getAIClient($modelId);
    $messages = [['role' => 'user', 'content' => $prompt]];
    $result = $ai->chat($messages, 'structured');
    
    echo json_encode([
        'success' => true,
        'section' => $section,
        'content' => trim($result)
    ]);
}

/**
 * 创建小说
 */
function createNovel() {
    header('Content-Type: application/json; charset=utf-8');
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $title = trim($input['title'] ?? '');
    $genre = trim($input['genre'] ?? '');
    $style = trim($input['style'] ?? '');
    $protagonistName = trim($input['protagonist_name'] ?? '');
    $protagonistInfo = trim($input['protagonist_info'] ?? '');
    $worldSettings = trim($input['world_settings'] ?? '');
    $plotSettings = trim($input['plot_settings'] ?? '');
    $extraSettings = trim($input['extra_settings'] ?? '');
    $targetChapters = max(1, (int)($input['target_chapters'] ?? 100));
    $chapterWords = max(500, (int)($input['chapter_words'] ?? 2000));
    $coverColor = trim($input['cover_color'] ?? '#6366f1');
    $modelId = (int)($input['model_id'] ?? 0) ?: null;
    
    if (empty($title)) {
        throw new Exception('书名不能为空');
    }
    
    // 创建小说
    $id = DB::insert('novels', [
        'title' => $title,
        'genre' => $genre,
        'writing_style' => $style,
        'protagonist_name' => $protagonistName,
        'protagonist_info' => $protagonistInfo,
        'world_settings' => $worldSettings,
        'plot_settings' => $plotSettings,
        'extra_settings' => $extraSettings,
        'target_chapters' => $targetChapters,
        'chapter_words' => $chapterWords,
        'cover_color' => $coverColor,
        'model_id' => $modelId,
        'status' => 'draft',
    ]);
    
    addLog((int)$id, 'create', "通过创意工坊创建小说《{$title}》");
    
    echo json_encode([
        'success' => true,
        'novel_id' => (int)$id,
        'message' => '小说创建成功'
    ]);
}

/**
 * 构建提示词
 */
function buildPrompt($idea, $genre, $style, $plotPattern, $plotCustomDesc, $endingStyle, $endingCustomDesc, $extraSettings) {
    // 剧情模式映射
    $plotPatterns = [
        'linear_growth' => '线性成长型（经典爽文模式）：主角从弱小起步，通过努力、奇遇或金手指，一步步克服困难，实力/地位不断提升。目标明确（变强、复仇、守护），节奏清晰，爽点密集。',
        'unit_puzzle' => '单元解谜/副本探索型：主角进入一个个相对独立的"副本"或"案件"，解决谜题、战胜敌人，并逐步揭开背后的主线真相。节奏快，悬念强，每个单元有完整起承转合。',
        'apocalypse' => '救世/末世生存型：世界面临崩溃或已被摧毁，主角在绝望中寻找希望，对抗终极威胁（天灾、邪神、外星文明）。压抑与燃点并存，强调人性的挣扎与牺牲。',
        'intellectual_battle' => '智斗/布局型：多方势力（包括主角、反派、第三方）通过信息差、策略、阴谋进行较量，剧情充满反转。逻辑严密，伏笔深远，读者需要动脑参与。',
        'anti_cliche' => '反套路/解构型：颠覆传统网文套路（如废柴逆袭、龙傲天），用幽默或荒诞的方式解构经典设定。轻松搞笑，脑洞清奇，常有"第四面墙"互动。',
        'custom' => $plotCustomDesc ?: '自定义剧情模式'
    ];
    
    // 大结局风格映射
    $endingStyles = [
        'happy_ending' => '圆满胜利型（大团圆）：主角达成所有目标（击败最终BOSS、拯救世界、抱得美人归），一切问题得到解决，世界恢复和平。',
        'open_ending' => '开放式结局（留白）：主线矛盾解决，但留下一些次要线索或未解之谜，让读者自行想象。',
        'tragic_hero' => '悲剧/牺牲型：主角成功拯救世界或达成目标，但付出了巨大代价（如失去挚爱、自身消亡、世界满目疮痍）。',
        'dark_twist' => '黑暗反转型：结局揭露一个颠覆性的真相（如"拯救的世界是虚拟的"、"主角才是最终BOSS"、"一切都是轮回"），让之前的剧情有了全新解读。',
        'daily_return' => '日常回归型：经历波澜壮阔的冒险后，主角回归平静生活，强调"平凡可贵"。',
        'sequel_setup' => '续作铺垫型：当前危机解除，但引出更大的世界观或威胁，为续集埋下伏笔。',
        'custom' => $endingCustomDesc ?: '自定义大结局风格'
    ];
    
    $plotDesc = $plotPatterns[$plotPattern] ?? '';
    $endingDesc = $endingStyles[$endingStyle] ?? '';
    
    $prompt = "你是一位专业的网文创意策划师。请根据以下信息，生成一个完整的小说框架。

【核心创意】
{$idea}

【小说类型】
:" . ($genre ?: '根据创意自动判断') . "

【写作风格】
:" . ($style ?: '根据创意自动判断') . "

【剧情走向】
:" . ($plotDesc ?: '根据创意自动选择最合适的剧情模式') . "

【大结局风格】
:" . ($endingDesc ?: '根据创意自动选择最合适的大结局风格') . "

【额外要求】
:" . ($extraSettings ?: '无') . "

请按以下 JSON 格式输出（必须是合法的 JSON，不要有任何额外文字）：

{
    \"title\": \"书名（要有吸引力，符合网文命名风格）\",
    \"protagonist_name\": \"主角姓名\",
    \"protagonist_info\": \"主角详细信息（背景、性格、能力、目标等，100-200字）\",
    \"world_settings\": \"世界观设定（世界背景、力量体系、地理环境、势力分布等，150-300字）\",
    \"plot_settings\": \"情节设定（主线剧情、核心矛盾、重要转折点、最终目标等，200-400字）\",
    \"extra_settings\": \"额外设定（重要配角、特殊物品、金手指设定等，100-200字）\"
}

注意：
1. 书名要有吸引力，符合当前网文流行趋势
2. 主角要有鲜明的性格特点和成长空间
3. 世界观要完整且独特，避免过于套路化
4. 情节要有起伏，符合选择的剧情模式
5. 所有内容要相互呼应，形成完整的小说框架
6. 输出必须是纯 JSON 格式，不要有任何 Markdown 标记或其他文字";

    return $prompt;
}

/**
 * 解析 AI 返回结果
 */
function parseResult($result) {
    // 尝试提取 JSON
    $json = $result;
    
    // 如果结果包含 Markdown 代码块，提取其中的 JSON
    if (preg_match('/```(?:json)?\s*([\s\S]+?)```/', $result, $matches)) {
        $json = $matches[1];
    }
    
    // 尝试解析 JSON
    $data = json_decode(trim($json), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        // JSON 解析失败，尝试从文本中提取信息
        $data = [
            'title' => extractField($result, ['书名', '标题', 'title']),
            'protagonist_name' => extractField($result, ['主角姓名', '主角名字', 'protagonist_name']),
            'protagonist_info' => extractField($result, ['主角信息', '主角介绍', 'protagonist_info']),
            'world_settings' => extractField($result, ['世界观', '世界设定', 'world_settings']),
            'plot_settings' => extractField($result, ['情节', '剧情', 'plot_settings']),
            'extra_settings' => extractField($result, ['额外', '补充', 'extra_settings'])
        ];
    }
    
    // 确保所有字段都存在
    $defaults = [
        'title' => '',
        'protagonist_name' => '',
        'protagonist_info' => '',
        'world_settings' => '',
        'plot_settings' => '',
        'extra_settings' => ''
    ];
    
    return array_merge($defaults, $data);
}

/**
 * 从文本中提取字段
 */
function extractField($text, $keywords) {
    foreach ($keywords as $keyword) {
        // 匹配 "字段名：值" 或 "字段名: 值" 格式
        if (preg_match('/' . preg_quote($keyword, '/') . '[：:]\s*(.+?)(?=\n|$)/u', $text, $matches)) {
            return trim($matches[1]);
        }
        // 匹配 "【字段名】值" 格式
        if (preg_match('/【' . preg_quote($keyword, '/') . '】\s*(.+?)(?=\n|$)/u', $text, $matches)) {
            return trim($matches[1]);
        }
    }
    return '';
}
