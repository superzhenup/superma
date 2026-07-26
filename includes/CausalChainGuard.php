<?php
defined('APP_LOADED') or die('Direct access denied.');

class CausalChainGuard
{
    private int    $novelId;
    private int    $chNum;
    private string $outline;
    private array  $conflicts = [];

    private const DEATH_KEYWORDS = ['死','陨','殁','牺牲','阵亡','殒命','身亡','毙命','丧命'];
    private const COMBAT_KEYWORDS = ['战','打','斗','攻击','防御','对决','交手','搏杀','拼杀','迎战','应战'];
    private const TRAVEL_KEYWORDS = ['赶','飞','传送','前往','抵达','来到','到达','出发','离开'];
    private const HEAL_KEYWORDS = ['疗伤','治愈','恢复','痊愈','康复','解毒','医治'];
    private const BREAKTHROUGH_KW = ['突破','晋级','晋升','渡劫','觉醒','顿悟','飞升'];

    public function __construct(int $novelId, int $chNum, string $outline)
    {
        $this->novelId = $novelId;
        $this->chNum   = $chNum;
        $this->outline = $outline;
    }

    public function validate(): array
    {
        if (!getSystemSetting('ws_causal_chain_guard', true, 'bool')) return [];
        if (trim($this->outline) === '') return [];

        $this->checkDeadCharacters();
        $this->checkRealmConsistency();
        $this->checkLocationContinuity();
        $this->checkEstablishedConstraints();

        return $this->conflicts;
    }

    public function buildPromptSection(): string
    {
        $conflicts = $this->validate();
        if (empty($conflicts)) return '';

        $lines = ["【⚠️ 因果链预警——本章大纲存在以下潜在矛盾，写作时务必注意】"];
        foreach ($conflicts as $c) {
            $lines[] = "· [{$c['severity']}] {$c['desc']}";
        }
        $lines[] = "请在正文中自然化解上述矛盾，或添加过渡说明使其合理。\n";

        return implode("\n", $lines) . "\n";
    }

    private function checkDeadCharacters(): void
    {
        try {
            $deadCards = DB::fetchAll(
                'SELECT name, last_updated_chapter FROM character_cards WHERE novel_id=? AND alive=0',
                [$this->novelId]
            );
            if (empty($deadCards)) return;

            foreach ($deadCards as $card) {
                $name = $card['name'];
                if (mb_strpos($this->outline, $name) !== false) {
                    $deathCh = $card['last_updated_chapter'] ?? '?';
                    $hasResurrection = $this->hasKeyword($this->outline, ['复活','重生','转世','复生','还魂','涅槃']);

                    if (!$hasResurrection) {
                        $this->conflicts[] = [
                            'severity' => '严重',
                            'desc'     => "「{$name}」已在第{$deathCh}章死亡，但本章大纲中出现了该角色。如需出场，必须以回忆/幻象/遗物等形式，或明确写出复活理由。",
                        ];
                    }
                }
            }
        } catch (\Throwable $e) { error_log('CausalChainGuard checkCharacterConsistency failed: ' . $e->getMessage()); }
    }

    private function checkRealmConsistency(): void
    {
        if (!$this->hasKeyword($this->outline, self::COMBAT_KEYWORDS)
            && !$this->hasKeyword($this->outline, self::BREAKTHROUGH_KW)) {
            return;
        }

        try {
            require_once __DIR__ . '/memory/CharacterCardRepo.php';
            $repo = new CharacterCardRepo($this->novelId);
            $protagonist = null;

            $novel = DB::fetch('SELECT protagonist_name FROM novels WHERE id=?', [$this->novelId]);
            $pName = trim($novel['protagonist_name'] ?? '');
            if ($pName === '') return;

            $protagonist = $repo->getByName($pName);
            if (!$protagonist) return;

            $attrs = $protagonist['attributes'] ?? [];
            if (is_string($attrs)) $attrs = json_decode($attrs, true) ?: [];
            $currentRealm = $attrs['realm'] ?? $attrs['level'] ?? '';
            if ($currentRealm === '') return;

            if ($this->hasKeyword($this->outline, self::BREAKTHROUGH_KW)) {
                require_once __DIR__ . '/PowerSystem.php';
                $ps = new PowerSystem($this->novelId);
                $chain = $ps->getRealmChain();
                $realms = $chain['realms'] ?? [];
                $currentIdx = $ps->getRealmIndex($currentRealm);

                if ($currentIdx >= 0 && $currentIdx < count($realms) - 1) {
                    $nextRealm = $realms[$currentIdx + 1];
                    // M-13 修复（2026-07-25）：原注释声称"遍历所有境界找最大跳级数"，但内层
                    // break 在每个关键词首次匹配后跳出，只检测第一个境界，跳级数被低估。
                    // 移除 break，让内层循环遍历所有境界，配合 if($candidate > $skipCount)
                    // 保留最大值，真正实现"找最大跳级数"。
                    $skipCount = 0;
                    foreach (self::BREAKTHROUGH_KW as $kw) {
                        if (mb_strpos($this->outline, $kw) === false) continue;
                        foreach ($realms as $idx => $r) {
                            if ($idx > $currentIdx && mb_strpos($this->outline, $r) !== false) {
                                $candidate = $idx - $currentIdx - 1;
                                if ($candidate > $skipCount) $skipCount = $candidate;
                                // 不再 break：继续检查更远的境界以找最大跳级数
                            }
                        }
                    }
                    if ($skipCount > 1) {
                        $this->conflicts[] = [
                            'severity' => '警告',
                            'desc'     => "主角「{$pName}」当前境界为「{$currentRealm}」，大纲暗示跳级突破（跳过了{$skipCount}个境界）。建议：在正文中添加充分的突破理由（奇遇/传承/血脉觉醒），或改为逐步突破。",
                        ];
                    }
                }
            }
        } catch (\Throwable $e) { error_log('CausalChainGuard realm continuity check failed: ' . $e->getMessage()); }
    }

    private function checkLocationContinuity(): void
    {
        try {
            $prevChapter = DB::fetch(
                "SELECT content FROM chapters
                 WHERE novel_id=? AND chapter_number=? AND status='completed'
                 LIMIT 1",
                [$this->novelId, $this->chNum - 1]
            );
            if (!$prevChapter || empty($prevChapter['content'])) return;

            $prevContent = $prevChapter['content'];
            $tailText = mb_substr($prevContent, -500);

            $locationPatterns = [
                '/在(.{2,8}?)(?:中|里|内|上|旁|边|前|后)/u',
                '/(?:来到|抵达|进入|回到|前往)(.{2,10}?)[。，]/u',
            ];

            $prevLocations = [];
            foreach ($locationPatterns as $pattern) {
                if (preg_match_all($pattern, $tailText, $matches)) {
                    $prevLocations = array_merge($prevLocations, $matches[1]);
                }
            }

            if (empty($prevLocations)) return;

            $outlineHasTravel = $this->hasKeyword($this->outline, self::TRAVEL_KEYWORDS);
            $outlineHasLocation = false;
            foreach ($prevLocations as $loc) {
                if (mb_strpos($this->outline, $loc) !== false) {
                    $outlineHasLocation = true;
                    break;
                }
            }

            if (!$outlineHasLocation && !$outlineHasTravel && count($prevLocations) > 0) {
                $lastLoc = end($prevLocations);
                $this->conflicts[] = [
                    'severity' => '提示',
                    'desc'     => "上章结尾场景可能在「{$lastLoc}」附近，但本章大纲未提及该地点也未描写转场。建议在正文开头确认场景位置，或补充转场描写。",
                ];
            }
        } catch (\Throwable $e) { error_log('CausalChainGuard location continuity check failed: ' . $e->getMessage()); }
    }

    private function checkEstablishedConstraints(): void
    {
        try {
            $bible = null;
            if (class_exists('StoryBible')) {
                $bible = StoryBible::get($this->novelId);
            }
            if (!$bible) return;

            $worldRules = $bible['world_md'] ?? '';
            if ($worldRules === '') return;

            $absolutePatterns = [
                '/(?:绝对|永远|绝不|不可能|无法|禁止|不许)(.{2,15}?)(?:[。，；])/u',
                '/(?:无解|无药可解|无法破解|不可逆|不可修复)(.{2,15}?)/u',
            ];

            $constraints = [];
            foreach ($absolutePatterns as $pattern) {
                if (preg_match_all($pattern, $worldRules, $matches)) {
                    foreach ($matches[0] as $m) {
                        $constraints[] = trim($m);
                    }
                }
            }

            foreach ($constraints as $constraint) {
                $constraintShort = mb_substr($constraint, 0, 20);
                $reversalKw = ['修复','逆转','恢复','成功做到','打破铁律','无视禁令','违背规则'];
                if ($this->hasKeyword($this->outline, $reversalKw)) {
                    foreach ($reversalKw as $kw) {
                        if (mb_strpos($this->outline, $kw) !== false
                            && mb_strlen($this->outline) > 10) {
                            $this->conflicts[] = [
                                'severity' => '警告',
                                'desc'     => "世界设定中有绝对约束「{$constraintShort}...」，但本章大纲可能暗示违反。请确保正文中有充分的合理化解释。",
                            ];
                            break 2;
                        }
                    }
                }
            }
        } catch (\Throwable $e) { error_log('CausalChainGuard checkEstablishedConstraints failed: ' . $e->getMessage()); }
    }

    private function hasKeyword(string $text, array $keywords): bool
    {
        foreach ($keywords as $kw) {
            if (mb_strpos($text, $kw) !== false) return true;
        }
        return false;
    }
}
