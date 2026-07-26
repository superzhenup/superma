<?php

defined('APP_LOADED') or die('Direct access denied.');

require_once dirname(__DIR__) . '/ai.php';
require_once __DIR__ . '/DramaService.php';

/**
 * 漫剧剧本解析：LLM 从章节正文提取角色/场景/道具资产 + 生成分镜脚本。
 * 复用小说绑定模型与 withModelFallback。
 */
final class DramaScriptParser
{
    /**
     * 解析剧集：提取实体资产并 upsert 到 drama_assets。
     * 若项目 style_prompt 为空且 LLM 给出风格建议，则回填。
     *
     * @return array{characters:int,scenes:int,props:int}
     */
    public static function parseEpisode(array $project, array $episode): array
    {
        $sourceText = trim((string)($episode['source_text'] ?? ''));
        if ($sourceText === '') throw new RuntimeException('剧集没有可用的章节正文');

        $novel = DB::fetch('SELECT id, model_id, genre FROM novels WHERE id=?', [(int)$project['novel_id']]);
        if (!$novel) throw new RuntimeException('小说不存在');

        // 写作系统人物卡预填，帮助 LLM 对齐既有设定
        $cards = DB::fetchAll(
            'SELECT name, title, attributes FROM character_cards WHERE novel_id=? ORDER BY id ASC LIMIT 30',
            [(int)$project['novel_id']]
        );
        $cardHint = '';
        foreach ($cards as $c) {
            $cardHint .= '- ' . $c['name'] . ($c['title'] ? '（' . $c['title'] . '）' : '') . "\n";
        }

        $prompt = self::loadPrompt('parse', [
            '{{GENRE}}'     => (string)($novel['genre'] ?? ''),
            '{{CARDS}}'     => $cardHint !== '' ? $cardHint : '（无）',
            '{{SOURCE}}'    => $sourceText,
        ]);

        $text = self::chat((int)($novel['model_id'] ?? 0), $prompt);
        $data = self::decodeJsonObject($text);

        $counts = ['characters' => 0, 'scenes' => 0, 'props' => 0];
        foreach ((array)($data['characters'] ?? []) as $item) {
            if (empty($item['name'])) continue;
            DramaService::upsertAsset((int)$project['id'], 'character', (string)$item['name'], (string)($item['description'] ?? ''), 'llm');
            $counts['characters']++;
        }
        foreach ((array)($data['scenes'] ?? []) as $item) {
            if (empty($item['name'])) continue;
            DramaService::upsertAsset((int)$project['id'], 'scene', (string)$item['name'], (string)($item['description'] ?? ''), 'llm');
            $counts['scenes']++;
        }
        foreach ((array)($data['props'] ?? []) as $item) {
            if (empty($item['name'])) continue;
            DramaService::upsertAsset((int)$project['id'], 'prop', (string)$item['name'], (string)($item['description'] ?? ''), 'llm');
            $counts['props']++;
        }

        $style = trim((string)($data['style_suggestion'] ?? ''));
        if ($style !== '' && trim((string)($project['style_prompt'] ?? '')) === '') {
            DramaService::updateProject((int)$project['id'], ['style_prompt' => $style]);
        }

        DB::update('drama_episodes', ['script_status' => 'parsed'], 'id=?', [(int)$episode['id']]);
        return $counts;
    }

    /**
     * 生成剧集分镜脚本并整集替换 drama_shots。
     *
     * @return int 分镜数量
     */
    public static function generateStoryboard(array $project, array $episode, int $targetShots = 12): int
    {
        $sourceText = trim((string)($episode['source_text'] ?? ''));
        if ($sourceText === '') throw new RuntimeException('剧集没有可用的章节正文');

        $novel = DB::fetch('SELECT id, model_id FROM novels WHERE id=?', [(int)$project['novel_id']]);
        if (!$novel) throw new RuntimeException('小说不存在');

        $assets = DramaService::listAssets((int)$project['id']);
        $assetHint = '';
        foreach ($assets as $a) {
            $typeLabel = ['character' => '角色', 'scene' => '场景', 'prop' => '道具'][$a['type']] ?? $a['type'];
            $assetHint .= "- [{$typeLabel}] {$a['name']}：{$a['description']}\n";
        }
        if ($assetHint === '') $assetHint = '（尚未解析资产，请根据正文自行设定出场角色外观）';

        $targetShots = max(4, min(40, $targetShots));
        $prompt = self::loadPrompt('storyboard', [
            '{{TARGET_SHOTS}}' => (string)$targetShots,
            '{{ASSETS}}'       => $assetHint,
            '{{SOURCE}}'       => $sourceText,
        ]);

        $text = self::chat((int)($novel['model_id'] ?? 0), $prompt);
        $data = self::decodeJsonObject($text);
        $rawShots = (array)($data['shots'] ?? []);
        if (!$rawShots) throw new RuntimeException('AI 未返回有效分镜');

        // 角色名 → asset_id 映射（characters 字段用角色名回传）
        $nameToId = [];
        foreach ($assets as $a) {
            if ($a['type'] === 'character') $nameToId[(string)$a['name']] = (int)$a['id'];
        }

        $shots = [];
        foreach ($rawShots as $raw) {
            $characterIds = [];
            foreach ((array)($raw['characters'] ?? []) as $name) {
                $name = trim((string)$name);
                if ($name !== '' && isset($nameToId[$name])) $characterIds[] = $nameToId[$name];
            }
            $shots[] = [
                'scene_desc'      => (string)($raw['scene_desc'] ?? ''),
                'shot_type'       => (string)($raw['shot_type'] ?? '中景'),
                'camera_movement' => (string)($raw['camera_movement'] ?? '固定'),
                'characters'      => $characterIds,
                'dialogue'        => (string)($raw['dialogue'] ?? ''),
                'image_prompt'    => (string)($raw['image_prompt'] ?? ''),
                'video_prompt'    => (string)($raw['video_prompt'] ?? ''),
                'duration'        => (int)($raw['duration'] ?? 5),
            ];
        }

        DramaService::replaceShots((int)$episode['id'], $shots);
        return count($shots);
    }

    // ----------------------------------------------------------------- 内部

    private static function chat(int $modelId, string $prompt): string
    {
        $messages = [
            ['role' => 'system', 'content' => '你是专业的漫剧导演与分镜师，擅长把小说文本拆解为可视化的影视资产与分镜脚本。只输出要求的 JSON，不要输出任何解释。'],
            ['role' => 'user', 'content' => $prompt],
        ];
        return withModelFallback(
            $modelId > 0 ? $modelId : null,
            function (AIClient $ai) use ($messages) {
                return $ai->chat($messages, 'structured');
            }
        );
    }

    /** 宽容解析 LLM 返回的 JSON 对象（去代码围栏、截取首个 { 到末尾 }）。 */
    private static function decodeJsonObject(string $text): array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/mi', '', $text) ?? $text;
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            throw new RuntimeException('AI 返回内容不是有效 JSON');
        }
        $data = json_decode(substr($text, $start, $end - $start + 1), true);
        if (!is_array($data)) {
            throw new RuntimeException('AI 返回 JSON 解析失败');
        }
        return $data;
    }

    private static function loadPrompt(string $kind, array $vars): string
    {
        static $prompts = [
            'parse' => <<<'PROMPT'
请阅读以下小说章节正文（题材：{{GENRE}}），为漫剧改编提取视觉资产。

写作系统已有人物卡（供对齐，不要照抄，需补充可视外观）：
{{CARDS}}

要求输出 JSON（不要输出其他内容）：
{
  "characters": [{"name": "角色名", "description": "可视外观描述：年龄感/发型发色/五官特征/服装/标志性配饰，50字内"}],
  "scenes": [{"name": "场景名", "description": "环境描述：地点/时间/光线/氛围/关键陈设，50字内"}],
  "props": [{"name": "道具名", "description": "外观描述，30字内"}],
  "style_suggestion": "适合本剧的整体画风提示词（如：国漫2D风格，赛璐璐上色，高饱和）"
}

注意：
- 只提取正文实际出场且对剧情有作用的角色/场景/道具
- description 必须具体可视，禁止"英俊""威严"这类抽象词，要写成可画的细节
- 同名角色与人物卡保持一致

章节正文：
{{SOURCE}}
PROMPT,
            'storyboard' => <<<'PROMPT'
请把以下小说章节正文改编为漫剧分镜脚本，共 {{TARGET_SHOTS}} 个镜头左右。

可用资产（出场角色请从这里引用名字）：
{{ASSETS}}

要求输出 JSON（不要输出其他内容）：
{
  "shots": [
    {
      "scene_desc": "画面内容描述（谁在哪做什么，40字内）",
      "shot_type": "远景/全景/中景/近景/特写 之一",
      "camera_movement": "固定/推/拉/摇/移/跟 之一",
      "characters": ["出场角色名"],
      "dialogue": "该镜头的对白或旁白（无则空字符串，80字内）",
      "image_prompt": "分镜首帧图的画面 prompt（具体可视：构图/动作/表情/环境/光线，100字内）",
      "video_prompt": "该镜头的运动描述（人物动作/表情变化/环境动态，50字内）",
      "duration": 5
    }
  ]
}

注意：
- 分镜按剧情节奏推进，关键冲突给特写，转场给远景
- 景别与运镜要有变化，避免全片一种镜头
- image_prompt 不要包含角色外观细节（系统会自动注入资产描述保持一致），只写构图/动作/环境
- duration 只能取 5 或 10

章节正文：
{{SOURCE}}
PROMPT,
        ];

        $template = $prompts[$kind] ?? '';
        return str_replace(array_keys($vars), array_values($vars), $template);
    }
}
