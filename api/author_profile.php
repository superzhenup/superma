<?php
/**
 * 作者画像 API - 提供 RESTful 接口
 */

define('APP_LOADED', true);
ob_start();

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config.php';
require_once $baseDir . '/includes/error_handler.php';
require_once $baseDir . '/includes/db.php';
require_once $baseDir . '/includes/auth.php';
require_once $baseDir . '/includes/author/AuthorProfile.php';
require_once $baseDir . '/includes/author/WorkParser.php';
require_once $baseDir . '/includes/author/AuthorAnalyzer.php';

registerApiErrorHandlers();

header('Content-Type: application/json; charset=utf-8');

// v1.11.7 安全修复：CORS 限制同源
// 之前 'Access-Control-Allow-Origin: *' 允许任意域跨站读取，对登录态接口不安全
// 改为：仅当请求来源 = 当前站点时才放行（同源访问无需 CORS，但前端某些场景仍发 Origin 头）
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$host   = $_SERVER['HTTP_HOST'] ?? '';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$selfOrigin = $scheme . '://' . $host;
if ($origin !== '' && $origin === $selfOrigin) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

requireLoginApi();
$userId = $_SESSION['user_id'] ?? $_SESSION['uid'] ?? null;

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// 解析路由：获取脚本文件名后的部分
$scriptName = basename(__FILE__); // author_profile.php
$pos = strpos($path, '/' . $scriptName);
if ($pos !== false) {
    $routePath = substr($path, $pos + strlen('/' . $scriptName));
} else {
    $routePath = '';
}

$pathParts = array_filter(explode('/', $routePath));
$pathParts = array_values($pathParts);

$action = $pathParts[0] ?? '';

try {
    switch ($method) {
        case 'GET':
            handleGet($action, $pathParts, $userId);
            break;

        case 'POST':
            handlePost($action, $userId);
            break;

        case 'PUT':
            handlePut($action, $pathParts, $userId);
            break;

        case 'DELETE':
            handleDelete($action, $pathParts, $userId);
            break;

        default:
            jsonResponse(['error' => '不支持的请求方法'], 405);
    }
} catch (\Throwable $e) {
    error_log('AuthorProfile API Error: ' . $e->getMessage());
    jsonResponse(['error' => '服务器错误：' . $e->getMessage()], 500);
}

function updateProfilePromptsFromResults(int $profileId, array $results): void
{
    ensurePromptColumnsExist();

    $promptMap = [
        'writing_habits' => 'writing_habits_prompt',
        'narrative_style' => 'narrative_style_prompt',
        'sentiment' => 'sentiment_prompt',
        'creative_identity' => 'creative_identity_prompt',
    ];

    $updateData = [];
    foreach ($promptMap as $dimension => $field) {
        if (isset($results[$dimension])) {
            $updateData[$field] = generateNaturalPrompt($dimension, $results[$dimension]);
        }
    }

    if (!empty($updateData)) {
        DB::update('author_profiles', $updateData, 'id=?', [$profileId]);
    }
}

function generateNaturalPrompt(string $dimension, $data): string
{
    if (!is_array($data)) {
        return (string)$data;
    }

    $povLabels = [
        'first_person' => '第一人称',
        'second_person' => '第二人称',
        'third_limited' => '第三人称限视',
        'third_omniscient' => '第三人称全知',
        'multiple' => '多视角',
    ];

    $toneLabels = [
        'optimistic' => '积极乐观',
        'pessimistic' => '消极悲观',
        'neutral' => '中立客观',
        'bittersweet' => '苦乐参半',
        'dark' => '暗黑压抑',
        'uplifting' => '振奋人心',
    ];

    $sentimentLabels = [
        'joy' => '喜悦', 'sadness' => '悲伤', 'anger' => '愤怒',
        'fear' => '恐惧', 'surprise' => '惊讶', 'love' => '爱',
        'disgust' => '厌恶',
    ];

    $themeLabels = [
        'growth' => '成长蜕变', 'love' => '爱情', 'revenge' => '复仇',
        'freedom' => '自由', 'power' => '力量权势', 'friendship' => '友情',
        'family' => '亲情家族', 'justice' => '正义', 'survival' => '生存挣扎',
        'identity' => '身份秘密',
    ];

    $densityLabels = ['sparse' => '简洁', 'moderate' => '适中', 'detailed' => '细腻'];
    $complexityLabels = ['simple' => '简洁明了', 'moderate' => '中等', 'complex' => '文笔华丽'];
    $paceLabels = ['fast' => '快节奏', 'slow' => '慢节奏', 'medium' => '中等节奏', 'variable' => '变化节奏'];
    $editingLabels = ['minimal' => '轻度修改', 'moderate' => '中度润色', 'extensive' => '大量改写'];
    $planningLabels = ['plotter' => '大纲规划型', 'pantser' => '即兴创作型', 'hybrid' => '混合型'];

    switch ($dimension) {
        case 'writing_habits':
            $parts = [];

            $sl = $data['sentence_length_avg'] ?? 0;
            $pl = $data['paragraph_length_avg'] ?? 0;
            $sp = $data['sentence_patterns'] ?? [];
            $wc = $data['word_complexity'] ?? 'moderate';
            $us = $data['uniqueness_score'] ?? 0;
            $ud = $data['use_dialogue'] ?? 0;
            $up = $data['use_passive'] ?? 0;
            $rd = $data['rhetorical_devices'] ?? [];
            $mf = $data['metaphor_frequency'] ?? 'medium';
            $vp = $data['vocabulary_preference'] ?? [];

            if ($sl > 0) {
                $parts[] = "平均句长约{$sl}字";
                if ($sl < 25) $parts[] = '偏爱短句，节奏明快紧凑';
                elseif ($sl > 50) $parts[] = '擅长长句铺陈，句式结构复杂';
                else $parts[] = '句长适中，长短交替自然';
            }

            if ($pl > 0) {
                $parts[] = "平均段落约{$pl}字";
                if ($pl < 100) $parts[] = '段落精炼，适合快节奏阅读';
                elseif ($pl > 250) $parts[] = '段落饱满，信息密度较高';
            }

            if (!empty($sp)) {
                $sr = round(($sp['short_ratio'] ?? 0) * 100);
                $lr = round(($sp['long_ratio'] ?? 0) * 100);
                $dr = round(($sp['declarative_ratio'] ?? 0) * 100);
                $er = round(($sp['exclamatory_ratio'] ?? 0) * 100);
                $ir = round(($sp['interrogative_ratio'] ?? 0) * 100);
                $parts[] = "句式分布：短句{$sr}%、长句{$lr}%、陈述{$dr}%、感叹{$er}%、疑问{$ir}%";
            }

            $parts[] = '词汇复杂度：' . ($complexityLabels[$wc] ?? $wc);

            if ($us > 0) {
                $parts[] = '独特性评分：' . round($us * 100) . '%';
            }

            if ($ud > 0) {
                $dialoguePct = round($ud * 100, 2);
                $parts[] = "对话密度约{$dialoguePct}%";
                if ($ud > 0.03) $parts[] = '对话密集，人物互动频繁生动';
                elseif ($ud > 0.01) $parts[] = '对话与描写搭配均衡';
                else $parts[] = '以叙述描写为主，对话精简';
            }

            if ($up > 0) {
                $parts[] = "被动语态密度：" . round($up, 2) . '‰';
            }

            if (!empty($rd)) {
                $rdNames = [
                    'metaphor' => '暗喻', 'simile' => '明喻', 'personification' => '拟人',
                    'hyperbole' => '夸张', 'parallelism' => '排比', 'repetition' => '反复',
                ];
                $rdList = [];
                foreach ($rd as $k => $v) {
                    $rdList[] = ($rdNames[$k] ?? $k) . "({$v}处)";
                }
                $parts[] = '修辞手法：' . implode('、', $rdList);
            }

            $parts[] = '比喻频率：' . ($mf === 'high' ? '高频' : ($mf === 'low' ? '低频' : '中等'));

            if (!empty($vp)) {
                $topWords = array_slice($vp, 0, 10, true);
                $wordsList = [];
                foreach ($topWords as $w => $c) {
                    $wordsList[] = "{$w}(×{$c})";
                }
                $parts[] = '高频词汇：' . implode('、', $wordsList);
            }

            $cf = $data['confidence'] ?? 0;
            $parts[] = '置信度：' . round($cf * 100) . '%（分析' . ($data['source_chapter_count'] ?? 0) . '章）';

            return implode("。\n", $parts) . '。';

        case 'narrative_style':
            $elements = [];

            $pov = $data['narrative_pov'] ?? '';
            $psf = $data['pov_switch_frequency'] ?? '';
            $pt = $data['pacing_type'] ?? '';
            $sts = $data['scene_transition_style'] ?? '';
            $cs = $data['chapter_structure'] ?? '';
            $cu = $data['cliffhanger_usage'] ?? 0;
            $im = $data['interior_monologue'] ?? 0;
            $dd = $data['description_density'] ?? '';
            $tc = $data['tension_curve'] ?? [];
            $cf = $data['confidence'] ?? 0;

            if ($pov) $elements[] = '叙事视角：' . ($povLabels[$pov] ?? $pov);

            if ($pt) $elements[] = '叙事节奏：' . ($paceLabels[$pt] ?? $pt);

            $psfLabels = ['frequent' => '频繁切换', 'occasional' => '偶尔切换', 'rare' => '极少切换', 'never' => '从不切换'];
            if ($psf) $elements[] = '视角切换：' . ($psfLabels[$psf] ?? $psf);

            $stsLabels = [
                'section_break' => '空行分隔', 'ellipsis_break' => '省略号分隔',
                'time_jump' => '时间跳跃', 'explicit_marker' => '显式标记',
                'seamless' => '无缝衔接', 'mixed' => '混合方式',
            ];
            if ($sts) $elements[] = '场景过渡：' . ($stsLabels[$sts] ?? $sts);

            $csLabels = ['linear' => '线性结构', 'parallel' => '多线并行', 'alternating' => '交替叙事', 'circular' => '环形结构'];
            if ($cs) $elements[] = '章节结构：' . ($csLabels[$cs] ?? $cs);

            if ($cu > 0) {
                $elements[] = '悬念钩子使用率：' . round($cu * 100) . '%';
                if ($cu > 0.6) $elements[] = '擅用悬念结尾，阅读粘性强';
                elseif ($cu > 0.3) $elements[] = '适度使用章末钩子，张弛有度';
                else $elements[] = '倾向于自然收尾，不依赖强悬念';
            }

            if ($im > 0) {
                $elements[] = '内心独白密度：' . round($im, 2) . '‰';
                if ($im > 2) $elements[] = '大量运用内心独白，人物心理刻画深入';
            }

            if ($dd) $elements[] = '描写密度：' . ($densityLabels[$dd] ?? $dd);

            if (!empty($tc)) {
                $pattern = $tc['pattern'] ?? '';
                $patternLabels = [
                    'escalating' => '持续升级型', 'descending' => '渐降型',
                    'wave_like' => '波浪起伏型', 'steady' => '平稳型', 'unknown' => '待分析',
                ];
                $elements[] = '张力曲线：' . ($patternLabels[$pattern] ?? $pattern);
            }

            $elements[] = '置信度：' . round($cf * 100) . '%';

            return implode("。\n", $elements) . '。';

        case 'sentiment':
            $elements = [];

            $ot = $data['overall_tone'] ?? '';
            $ei = $data['emotion_intensity'] ?? '';
            $er = $data['emotional_range'] ?? [];
            $dl = $data['depth_level'] ?? '';
            $tc = $data['thematic_complexity'] ?? 0;
            $themes = $data['themes'] ?? [];
            $as = $data['aesthetic_style'] ?? '';
            $bdf = $data['beauty_description_focus'] ?? [];
            $vl = $data['violence_level'] ?? '';
            $mf = $data['moral_framework'] ?? '';
            $vt = $data['values_tendency'] ?? [];
            $cf = $data['confidence'] ?? 0;

            if ($ot) $elements[] = '整体基调：' . ($toneLabels[$ot] ?? $ot);

            $eiLabels = ['intense' => '强烈饱满', 'moderate' => '中等', 'subtle' => '含蓄内敛'];
            if ($ei) $elements[] = '情感强度：' . ($eiLabels[$ei] ?? $ei);

            if (!empty($er)) {
                arsort($er);
                $topEmotions = array_slice($er, 0, 5, true);
                $emoList = [];
                foreach ($topEmotions as $emo => $density) {
                    $emoList[] = ($sentimentLabels[$emo] ?? $emo) . '(' . round($density, 1) . ')';
                }
                $elements[] = '情感分布：' . implode(' > ', $emoList);
            }

            $dlLabels = [
                'philosophical' => '哲思深度', 'thoughtful' => '有思想深度',
                'entertaining' => '娱乐消遣', 'surface' => '表层叙事',
            ];
            if ($dl) $elements[] = '思想深度：' . ($dlLabels[$dl] ?? $dl);

            if ($tc > 0) $elements[] = '主题复杂度：' . round($tc * 100) . '%';

            if (!empty($themes)) {
                $themeNames = [];
                foreach (array_slice($themes, 0, 5) as $t) {
                    $themeNames[] = $themeLabels[$t] ?? $t;
                }
                $elements[] = '核心主题：' . implode('、', $themeNames);
            }

            $asLabels = ['romantic' => '浪漫主义', 'realistic' => '现实主义', 'fantasy' => '奇幻风格', 'classical' => '古典风格', 'modern' => '现代风格'];
            if ($as) $elements[] = '审美风格：' . ($asLabels[$as] ?? $as);

            if (!empty($bdf)) {
                $bdfLabels = ['nature' => '自然景物', 'character' => '人物外貌', 'architecture' => '建筑场景', 'action' => '动作场面', 'emotion' => '情感渲染'];
                $bdfNames = [];
                foreach ($bdf as $f) {
                    $bdfNames[] = $bdfLabels[$f] ?? $f;
                }
                $elements[] = '描写侧重点：' . implode('、', $bdfNames);
            }

            $vlLabels = ['graphic' => '血腥激烈', 'moderate' => '中等', 'mild' => '轻度', 'none' => '无'];
            if ($vl) $elements[] = '暴力程度：' . ($vlLabels[$vl] ?? $vl);

            if ($mf) $elements[] = '道德框架：' . $mf;

            if (!empty($vt)) {
                $vtLabels = ['success' => '成功荣耀', 'love' => '真爱守护', 'freedom' => '自由解放', 'justice' => '正义公道', 'family' => '家族传承'];
                $vtNames = [];
                foreach ($vt as $v) {
                    $vtNames[] = $vtLabels[$v] ?? $v;
                }
                $elements[] = '价值倾向：' . implode('、', $vtNames);
            }

            $elements[] = '置信度：' . round($cf * 100) . '%';

            return implode("。\n", $elements) . '。';

        case 'creative_identity':
            $elements = [];

            $wv = $data['writing_voice'] ?? '';
            $gp = $data['genre_preferences'] ?? [];
            $st = $data['style_tags'] ?? [];
            $ut = $data['unique_techniques'] ?? [];
            $sp = $data['signature_phrases'] ?? [];
            $tm = $data['trademark_elements'] ?? [];
            $ca = $data['character_archetype_favorites'] ?? [];
            $pp = $data['plot_preferences'] ?? [];
            $es = $data['editing_style'] ?? '';
            $ps = $data['planning_style'] ?? '';
            $cf = $data['confidence'] ?? 0;

            if ($wv) $elements[] = '文风特征：' . $wv;

            if (!empty($gp)) {
                $genreLabels = [
                    'fantasy' => '玄幻', 'xianxia' => '仙侠', 'urban' => '都市',
                    'romance' => '言情', 'martial_arts' => '武侠', 'scifi' => '科幻',
                    'historical' => '历史', 'horror' => '恐怖',
                ];
                $gNames = [];
                foreach ($gp as $g) {
                    $gNames[] = $genreLabels[$g] ?? $g;
                }
                $elements[] = '题材偏好：' . implode('、', $gNames);
            }

            if (!empty($st)) {
                $elements[] = '风格标签：' . implode('、', $st);
            }

            if (!empty($ut)) {
                $elements[] = '独特技法：' . implode('、', $ut);
            }

            if (!empty($sp)) {
                $elements[] = '标志句式：' . implode('、', array_slice($sp, 0, 5));
            }

            if (!empty($tm)) {
                $elements[] = '标志元素：' . implode('、', $tm);
            }

            $caLabels = [
                'hero' => '英雄主角', 'mentor' => '导师角色', 'villain' => '反派BOSS',
                'love_interest' => '恋人角色', 'comic_relief' => '喜剧角色', 'lancer' => '搭档角色',
            ];
            if (!empty($ca)) {
                $caNames = [];
                foreach ($ca as $a) {
                    $caNames[] = $caLabels[$a] ?? $a;
                }
                $elements[] = '角色原型偏好：' . implode('、', $caNames);
            }

            if (!empty($pp)) {
                $elements[] = '情节偏好：' . implode('、', $pp);
            }

            if ($es) $elements[] = '编辑风格：' . ($editingLabels[$es] ?? $es);
            if ($ps) $elements[] = '创作规划：' . ($planningLabels[$ps] ?? $ps);
            $elements[] = '置信度：' . round($cf * 100) . '%（基于分析章节数）';

            return implode("。\n", $elements) . '。';

        default:
            return json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}

function ensurePromptColumnsExist(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $columns = DB::fetchAll("SHOW COLUMNS FROM author_profiles LIKE 'writing_habits_prompt'");
    if (empty($columns)) {
        DB::query("ALTER TABLE author_profiles
            ADD COLUMN `writing_habits_prompt` TEXT DEFAULT NULL COMMENT '写作习惯提示词' AFTER `influences`,
            ADD COLUMN `narrative_style_prompt` TEXT DEFAULT NULL COMMENT '叙事手法提示词' AFTER `writing_habits_prompt`,
            ADD COLUMN `sentiment_prompt` TEXT DEFAULT NULL COMMENT '思想情感提示词' AFTER `narrative_style_prompt`,
            ADD COLUMN `creative_identity_prompt` TEXT DEFAULT NULL COMMENT '创作个性提示词' AFTER `sentiment_prompt`");
    }
}

function handleGet(string $action, array $pathParts, ?int $userId): void
{
    if ($action === 'list') {
        $page = max(1, intval($_GET['page'] ?? 1));
        $status = $_GET['status'] ?? '';

        $filters = ['user_id' => $userId];
        if (!empty($status)) {
            $filters['status'] = $status;
        }

        $result = AuthorProfile::listAll($filters, $page);
        jsonResponse($result);
    }

    if ($action === 'profile' && isset($pathParts[1])) {
        $profileId = intval($pathParts[1]);
        $profile = AuthorProfile::find($profileId);

        if (!$profile) {
            jsonResponse(['error' => '画像不存在'], 404);
        }

        $profile->incrementUsage();
        jsonResponse($profile->toArray());
    }

    if ($action === 'style-guide' && isset($pathParts[2])) {
        $profileId = intval($pathParts[2]);
        $profile = AuthorProfile::find($profileId);

        if (!$profile) {
            jsonResponse(['error' => '画像不存在'], 404);
        }

        jsonResponse(['style_guide' => $profile->generateStyleGuide()]);
    }

    if ($action === 'vector' && isset($pathParts[2])) {
        $profileId = intval($pathParts[2]);
        $profile = AuthorProfile::find($profileId);

        if (!$profile) {
            jsonResponse(['error' => '画像不存在'], 404);
        }

        jsonResponse($profile->toVectorStyle());
    }

    if ($action === 'default') {
        $profile = AuthorProfile::getDefault();
        if (!$profile) {
            jsonResponse(['error' => '没有默认画像'], 404);
        }
        jsonResponse($profile->toArray());
    }

    if ($action === 'works' && isset($pathParts[2])) {
        $profileId = intval($pathParts[2]);
        $works = DB::fetchAll(
            'SELECT * FROM author_uploaded_works WHERE profile_id=? ORDER BY created_at DESC',
            [$profileId]
        );
        jsonResponse(['works' => $works ?: []]);
    }

    if ($action === 'detailed-report' && isset($pathParts[1])) {
        $profileId = intval($pathParts[1]);
        $profile = AuthorProfile::find($profileId);
        if (!$profile) {
            jsonResponse(['error' => '画像不存在'], 404);
        }

        $workId = $profile->toArray()['source_work_id'] ?? 0;
        if (!$workId) {
            jsonResponse(['error' => '没有已上传的作品'], 400);
        }

        $work = DB::fetch('SELECT * FROM author_uploaded_works WHERE id=? AND profile_id=?', [$workId, $profileId]);
        if (!$work) {
            jsonResponse(['error' => '作品不存在'], 404);
        }

        $parser = new WorkParser();
        $content = $parser->extractContent($work['file_path'], $work['file_type']);
        if ($content === false) {
            jsonResponse(['error' => '无法读取作品内容'], 500);
        }

        $parseResult = $parser->parseText($content);
        $chapters = $parseResult['chapters'] ?? [];

        $dimensionNames = ['writing_habits', 'narrative_style', 'sentiment', 'creative_identity'];
        $tables = [
            'writing_habits' => 'author_writing_habits',
            'narrative_style' => 'author_narrative_styles',
            'sentiment' => 'author_sentiment_analysis',
            'creative_identity' => 'author_creative_identity',
        ];

        $dimensions = [];

        foreach ($dimensionNames as $dim) {
            $table = $tables[$dim];
            $row = DB::fetch("SELECT * FROM `{$table}` WHERE profile_id=? ORDER BY updated_at DESC LIMIT 1", [$profileId]);
            if (!$row) continue;

            $jsonFields = [];
            switch ($dim) {
                case 'writing_habits':
                    $jsonFields = ['vocabulary_preference', 'sentence_patterns', 'rhetorical_devices'];
                    break;
                case 'narrative_style':
                    $jsonFields = ['tension_curve'];
                    break;
                case 'sentiment':
                    $jsonFields = ['emotional_range', 'themes', 'beauty_description_focus', 'values_tendency'];
                    break;
                case 'creative_identity':
                    $jsonFields = ['signature_phrases', 'unique_techniques', 'trademark_elements',
                        'genre_preferences', 'character_archetype_favorites', 'plot_preferences',
                        'style_tags', 'influence_sources'];
                    break;
            }

            $dimData = [];
            foreach ($row as $col => $val) {
                if ($col === 'id' || $col === 'profile_id' || $col === 'created_at' || $col === 'updated_at') continue;
                if (in_array($col, $jsonFields) && is_string($val)) {
                    $decoded = json_decode($val, true);
                    $dimData[$col] = is_array($decoded) ? $decoded : $val;
                } else {
                    $dimData[$col] = $val;
                }
            }

            if (!empty($dimData)) {
                $dimensions[$dim] = $dimData;
            }
        }

        $chapterStats = [];
        foreach ($chapters as $ch) {
            $text = $ch['content'] ?? '';
            $charCount = mb_strlen($text);
            $dialogueCount = preg_match_all('/["""\'"].*?["""\']/u', $text);
            $chapterStats[] = [
                'number' => $ch['number'] ?? 0,
                'title' => $ch['title'] ?? '',
                'char_count' => $charCount,
                'dialogue_count' => $dialogueCount,
                'dialogue_density' => $charCount > 0 ? round($dialogueCount * 50 / $charCount, 4) : 0,
            ];
        }

        jsonResponse([
            'success' => true,
            'profile_name' => $profile->toArray()['profile_name'] ?? '',
            'analysis_status' => $profile->toArray()['analysis_status'] ?? '',
            'total_chapters' => count($chapters),
            'total_chars' => $parseResult['char_count'] ?? 0,
            'dimensions' => $dimensions,
            'chapter_stats' => $chapterStats,
        ]);
    }

    jsonResponse(['error' => '未知操作'], 400);
}

function handlePost(string $action, ?int $userId): void
{
    // 支持 FormData 和 JSON 两种格式
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_starts_with($contentType, 'application/json')) {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
    } else {
        // FormData 格式
        $input = $_POST;
    }

    if ($action === 'create') {
        $name = trim($input['profile_name'] ?? '新建画像');
        
        error_log("Creating profile - name: '$name', userId: " . ($userId ?? 'NULL'));

        try {
            $profile = AuthorProfile::create([
                'profile_name' => $name,
                'user_id' => $userId,
                'analysis_status' => 'pending',
            ]);

            if (!$profile) {
                error_log('AuthorProfile::create returned null');
                jsonResponse(['success' => false, 'message' => '创建画像失败'], 500);
            }

            error_log('Profile created successfully - id: ' . $profile->toArray()['id']);
            jsonResponse(['success' => true, 'data' => $profile->toArray()], 201);
        } catch (\Exception $e) {
            error_log('Error creating profile: ' . $e->getMessage());
            jsonResponse(['success' => false, 'message' => '创建画像失败: ' . $e->getMessage()], 500);
        }
    }

    if ($action === 'upload') {
        $profileId = intval($input['profile_id'] ?? 0);
        if (!$profileId) {
            jsonResponse(['error' => '缺少 profile_id'], 400);
        }

        $profile = AuthorProfile::find($profileId);
        if (!$profile) {
            jsonResponse(['error' => '画像不存在'], 404);
        }

        if (empty($_FILES['file'])) {
            jsonResponse(['error' => '没有上传文件'], 400);
        }

        $parser = new WorkParser();
        $result = $parser->parseUpload($_FILES['file']);

        if (!$result['success']) {
            jsonResponse(['error' => $result['error'] ?? '文件解析失败'], 400);
        }

        $workId = DB::insert('author_uploaded_works', [
            'profile_id' => $profileId,
            'file_name' => $_FILES['file']['name'],
            'file_path' => $result['file_path'],
            'file_size' => $_FILES['file']['size'],
            'file_type' => strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION)),
            'upload_status' => 'completed',
            'chapter_count' => $result['chapter_count'],
            'total_characters' => $result['char_count'],
        ]);

        DB::update('author_profiles', [
            'source_work_id' => $workId,
            'analysis_status' => 'pending',
        ], 'id=?', [$profileId]);

        jsonResponse([
            'success' => true,
            'work_id' => $workId,
            'chapter_count' => $result['chapter_count'],
            'char_count' => $result['char_count'],
            'chapters_preview' => array_slice($result['chapters'], 0, 3),
        ]);
    }

    if ($action === 'analyze') {
        $profileId = intval($input['profile_id'] ?? 0);
        if (!$profileId) {
            jsonResponse(['error' => '缺少 profile_id'], 400);
        }

        $profile = AuthorProfile::find($profileId);
        if (!$profile) {
            jsonResponse(['error' => '画像不存在'], 404);
        }

        $workId = $input['work_id'] ?? $profile->toArray()['source_work_id'] ?? 0;
        if (!$workId) {
            jsonResponse(['error' => '没有上传作品进行分析'], 400);
        }

        $work = DB::fetch('SELECT * FROM author_uploaded_works WHERE id=? AND profile_id=?', [$workId, $profileId]);
        if (!$work) {
            jsonResponse(['error' => '作品不存在'], 404);
        }

        $parser = new WorkParser();
        $content = $parser->extractContent($work['file_path'], $work['file_type']);
        if ($content === false) {
            jsonResponse(['error' => '无法读取作品内容'], 500);
        }

        $parseResult = $parser->parseText($content);

        $result = AuthorAnalyzer::analyzeFromWork($profileId, $parseResult);

        if (!$result['success']) {
            jsonResponse(['error' => $result['error'] ?? '分析失败'], 500);
        }

        updateProfilePromptsFromResults($profileId, $result['results']);

        jsonResponse([
            'success' => true,
            'results' => $result['results'],
            'summary' => $result['summary'],
        ]);
    }

    if ($action === 'analyze-dimension') {
        $profileId = intval($input['profile_id'] ?? 0);
        $dimension = $input['dimension'] ?? '';
        if (!$profileId) {
            jsonResponse(['error' => '缺少 profile_id'], 400);
        }
        if (!in_array($dimension, ['writing_habits', 'narrative_style', 'sentiment', 'creative_identity'])) {
            jsonResponse(['error' => '无效的维度'], 400);
        }

        $profile = AuthorProfile::find($profileId);
        if (!$profile) {
            jsonResponse(['error' => '画像不存在'], 404);
        }

        $workId = $input['work_id'] ?? $profile->toArray()['source_work_id'] ?? 0;
        if (!$workId) {
            jsonResponse(['error' => '没有上传作品进行分析'], 400);
        }

        $work = DB::fetch('SELECT * FROM author_uploaded_works WHERE id=? AND profile_id=?', [$workId, $profileId]);
        if (!$work) {
            jsonResponse(['error' => '作品不存在'], 404);
        }

        $parser = new WorkParser();
        $content = $parser->extractContent($work['file_path'], $work['file_type']);
        if ($content === false) {
            jsonResponse(['error' => '无法读取作品内容'], 500);
        }

        $parseResult = $parser->parseText($content);
        $chapters = $parseResult['chapters'] ?? [];

        $analyzer = new AuthorAnalyzer($profileId, $chapters);
        $result = $analyzer->analyzeDimension($dimension);

        if (!$result['success']) {
            jsonResponse(['error' => $result['error'] ?? '分析失败'], 500);
        }

        $dimensionPromptMap = [
            'writing_habits' => 'writing_habits_prompt',
            'narrative_style' => 'narrative_style_prompt',
            'sentiment' => 'sentiment_prompt',
            'creative_identity' => 'creative_identity_prompt',
        ];
        if (isset($dimensionPromptMap[$dimension])) {
            ensurePromptColumnsExist();
            $promptField = $dimensionPromptMap[$dimension];
            $promptValue = generateNaturalPrompt($dimension, $result['result']);
            DB::update('author_profiles', [$promptField => $promptValue], 'id=?', [$profileId]);
        }

        jsonResponse([
            'success' => true,
            'dimension' => $result['dimension'],
            'result' => $result['result'],
            'progress' => $analyzer->getProgress(),
        ]);
    }

    if ($action === 'analyze-text') {
        $profileId = intval($input['profile_id'] ?? 0);
        $text = trim($input['text'] ?? '');

        if (!$profileId) {
            jsonResponse(['error' => '缺少 profile_id'], 400);
        }

        if (empty($text)) {
            jsonResponse(['error' => '缺少分析文本'], 400);
        }

        if (mb_strlen($text) < 1000) {
            jsonResponse(['error' => '文本太短，至少需要1000字进行分析'], 400);
        }

        $parser = new WorkParser();
        $parseResult = $parser->parseText($text);

        $result = AuthorAnalyzer::analyzeFromWork($profileId, $parseResult);

        if (!$result['success']) {
            jsonResponse(['error' => $result['error'] ?? '分析失败'], 500);
        }

        updateProfilePromptsFromResults($profileId, $result['results']);

        jsonResponse([
            'success' => true,
            'results' => $result['results'],
            'summary' => $result['summary'],
        ]);
    }

    jsonResponse(['error' => '未知操作'], 400);
}

function handlePut(string $action, array $pathParts, ?int $userId): void
{
    if ($action === 'profile' && isset($pathParts[1])) {
        $profileId = intval($pathParts[1]);
        $profile = AuthorProfile::find($profileId);

        if (!$profile) {
            jsonResponse(['error' => '画像不存在'], 404);
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $basicFields = ['profile_name', 'avatar_url', 'gender', 'age_range', 'mbti', 'constellation', 'occupation'];
        $backgroundFields = ['education_bg', 'writing_experience', 'influences'];
        $promptFields = ['writing_habits_prompt', 'narrative_style_prompt', 'sentiment_prompt', 'creative_identity_prompt'];
        $allFields = array_merge($basicFields, $backgroundFields, $promptFields);

        $updateData = [];
        foreach ($allFields as $field) {
            if (isset($input[$field])) {
                $updateData[$field] = $input[$field];
            }
        }

        if (isset($input['is_default'])) {
            $updateData['is_default'] = $input['is_default'] ? 1 : 0;
        }

        if (empty($updateData)) {
            jsonResponse(['error' => '没有需要更新的字段'], 400);
        }

        $hasPromptUpdate = !empty(array_intersect(array_keys($updateData), $promptFields));
        if ($hasPromptUpdate) {
            ensurePromptColumnsExist();
        }

        $success = $profile->update($updateData);
        if (!$success) {
            jsonResponse(['error' => '更新失败'], 500);
        }

        jsonResponse($profile->toArray());
    }

    if ($action === 'dimension' && isset($pathParts[1]) && isset($pathParts[2])) {
        $dimension = $pathParts[1];
        $profileId = intval($pathParts[2]);

        $validDimensions = ['writing_habits', 'narrative_style', 'sentiment', 'creative_identity'];
        if (!in_array($dimension, $validDimensions)) {
            jsonResponse(['error' => '无效的维度'], 400);
        }

        $profile = AuthorProfile::find($profileId);
        if (!$profile) {
            jsonResponse(['error' => '画像不存在'], 404);
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $resultData = $input['result'] ?? null;

        if ($resultData === null) {
            jsonResponse(['error' => '缺少结果数据'], 400);
        }

        jsonResponse(['success' => true, 'dimension' => $dimension]);
    }

    jsonResponse(['error' => '未知操作'], 400);
}

function handleDelete(string $action, array $pathParts, ?int $userId): void
{
    if ($action === 'profile' && isset($pathParts[1])) {
        $profileId = intval($pathParts[1]);
        $profile = AuthorProfile::find($profileId);

        if (!$profile) {
            jsonResponse(['error' => '画像不存在'], 404);
        }

        $works = DB::fetchAll('SELECT file_path FROM author_uploaded_works WHERE profile_id=?', [$profileId]);
        foreach ($works as $work) {
            if (!empty($work['file_path']) && file_exists($work['file_path'])) {
                @unlink($work['file_path']);
            }
        }

        $success = $profile->delete();
        if (!$success) {
            jsonResponse(['error' => '删除失败'], 500);
        }

        jsonResponse(['success' => true, 'message' => '画像已删除']);
    }

    if ($action === 'work' && isset($pathParts[1])) {
        $workId = intval($pathParts[1]);
        $work = DB::fetch('SELECT * FROM author_uploaded_works WHERE id=?', [$workId]);

        if (!$work) {
            jsonResponse(['error' => '作品不存在'], 404);
        }

        if (!empty($work['file_path']) && file_exists($work['file_path'])) {
            @unlink($work['file_path']);
        }

        DB::delete('author_uploaded_works', 'id=?', [$workId]);

        jsonResponse(['success' => true, 'message' => '作品已删除']);
    }

    jsonResponse(['error' => '未知操作'], 400);
}

function jsonResponse(array $data, int $code = 200): void
{
    if (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
