<?php
/**
 * VoiceProfileGenerator — 角色语音指纹自动生成
 *
 * 此前 character_cards.voice_profile 列、写入方法、prompt 注入（buildVoiceProfileSection）都在，
 * 却没有任何代码填充它 —— 导致"角色语音规则"段永远为空，所有角色一个腔调。
 *
 * 本类为缺指纹的主要角色一次性设计【互相区分】的语音指纹（称呼/句长/口癖/禁用词/情绪/用语），
 * 写回 voice_profile，激活已有的注入逻辑。一次 AI 调用处理多个角色，保证彼此可区分。
 */
defined('APP_LOADED') or die('Direct access denied.');

class VoiceProfileGenerator
{
    /**
     * 为缺 voice_profile 的角色生成语音指纹。返回新生成的角色数。
     */
    public static function generateMissing(int $novelId, int $chNum, ?int $modelId = null, int $maxChars = 8): int
    {
        if (!getSystemSetting('ws_voice_profile_enabled', true, 'bool')) return 0;
        try {
            require_once __DIR__ . '/memory/CharacterCardRepo.php';
            require_once __DIR__ . '/ai.php';
            $repo = new CharacterCardRepo($novelId);

            // 挑出"还没有语音指纹"的存活角色（上限 maxChars，按卡片顺序≈重要度）
            $targets = [];
            foreach ($repo->listAll(true) as $c) {
                $vp = $c['voice_profile'] ?? [];
                if (is_array($vp) && !empty($vp)) continue;   // 已有，跳过
                $targets[] = $c;
                if (count($targets) >= $maxChars) break;
            }
            if (empty($targets)) return 0;

            // 组装角色简介上下文
            $charBlock = '';
            foreach ($targets as $c) {
                $attrs = $c['attributes'] ?? [];
                if (!is_array($attrs)) $attrs = json_decode((string)$attrs, true) ?: [];
                $brief = [];
                foreach (['角色类型', '定位', '性格', '背景'] as $k) {
                    if (!empty($attrs[$k])) $brief[] = "{$k}:" . mb_substr((string)$attrs[$k], 0, 40);
                }
                $charBlock .= "- {$c['name']}" . (!empty($c['title']) ? "（{$c['title']}）" : '')
                    . ($brief ? ' ' . implode('；', $brief) : '') . "\n";
            }

            $novel = DB::fetch("SELECT title, genre FROM novels WHERE id=?", [$novelId]);
            $title = $novel['title'] ?? '';
            $genre = $novel['genre'] ?? '';

            $sys = "你是小说人物语言设计师。为角色设计【互相区分】的语音指纹，"
                . "目标是读者光看对话内容（不看说话人标签）也能分辨是谁。区分点要自然、贴合人物身份性格，不要做作。";
            $user = "小说《{$title}》（类型：{$genre}）。为以下角色各设计一份语音指纹，彼此必须有明显差异：\n"
                . $charBlock . "\n"
                . "严格输出 JSON（不要 markdown 代码块），key 为角色名，value 字段如下：\n"
                . "{\"角色名\":{"
                . "\"dialogue_style\":\"一句话概括说话风格\","
                . "\"sentence_length\":\"short 或 medium 或 long\","
                . "\"catchphrases\":[\"口癖/常用语1\",\"口癖2\"],"
                . "\"forbidden_words\":[\"该角色绝不会说的词/语气\"],"
                . "\"emotional_range\":\"情绪表达特点\","
                . "\"vocabulary_level\":\"用语层次，如 文雅/书面/市井/粗俗/孩子气 等\","
                . "\"subtext_style\":\"潜台词/留白风格：该角色隐藏情感时的表达方式，如'用反问代替关心''越紧张越沉默''笑着说悲伤的事'\","
                . "\"subtext_signals\":[\"该角色隐藏情绪时的肢体/行为信号1\",\"信号2\"]"
                . "}, ...}";

            $ai = getAIClient($modelId ?: null);
            $raw = trim($ai->chat([
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user', 'content' => $user],
            ], 'structured'));

            $data = self::parseJson($raw);
            if (!$data) return 0;

            $n = 0;
            foreach ($targets as $c) {
                $vp = $data[$c['name']] ?? null;
                if (!is_array($vp)) continue;
                $clean = [
                    'dialogue_style'   => mb_substr(trim((string)($vp['dialogue_style'] ?? '')), 0, 60),
                    'sentence_length'  => in_array(($vp['sentence_length'] ?? ''), ['short', 'medium', 'long'], true) ? $vp['sentence_length'] : 'medium',
                    'catchphrases'     => array_slice(array_values(array_filter(array_map('strval', (array)($vp['catchphrases'] ?? [])))), 0, 4),
                    'forbidden_words'  => array_slice(array_values(array_filter(array_map('strval', (array)($vp['forbidden_words'] ?? [])))), 0, 4),
                    'emotional_range'  => mb_substr(trim((string)($vp['emotional_range'] ?? '')), 0, 40),
                    'vocabulary_level' => mb_substr(trim((string)($vp['vocabulary_level'] ?? '')), 0, 20),
                    'subtext_style'    => mb_substr(trim((string)($vp['subtext_style'] ?? '')), 0, 60),
                    'subtext_signals'  => array_slice(array_values(array_filter(array_map('strval', (array)($vp['subtext_signals'] ?? [])))), 0, 3),
                ];
                if ($clean['dialogue_style'] === '' && empty($clean['catchphrases'])) continue;
                $repo->upsert($c['name'], ['voice_profile' => $clean], $chNum);
                $n++;
            }

            if ($n > 0) {
                addLog($novelId, 'info', "角色语音指纹：为 {$n} 个角色生成差异化语音规则");
            }
            return $n;
        } catch (\Throwable $e) {
            error_log('VoiceProfileGenerator::generateMissing ' . $e->getMessage());
            return 0;
        }
    }

    private static function parseJson(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') return null;
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $raw, $m)) $raw = trim($m[1]);
        $data = json_decode($raw, true);
        if (is_array($data)) return $data;
        $s = strpos($raw, '{'); $e = strrpos($raw, '}');
        if ($s !== false && $e !== false && $e > $s) {
            $data = json_decode(substr($raw, $s, $e - $s + 1), true);
            if (is_array($data)) return $data;
        }
        return null;
    }
}
