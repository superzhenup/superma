<?php
defined('APP_LOADED') or die('Direct access denied.');

/** A completed-content check failed; callers must apply their unavailable policy. */
class CausalChainValidationException extends RuntimeException {}

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
    /** 正文跳级判定中需核对肯定/否定语境的升境交代词。 */
    private const REALM_EXPLAIN_KW = ['突破','渡劫','奇遇','传承','血脉','觉醒','顿悟','晋升','晋级','飞升','机缘','夺舍','吞噬','涅槃','闭关','感悟天道','逆天改命'];
    /** 审计修复（2026-08-17 写作逻辑 B2）：场景延续/转场过渡关键词。 */
    private const SCENE_TRANSITION_KW = ['随后','接着','此时','与此同时','次日','三天后','数日后','数月后','片刻后','不久','转眼','另一边','话说','且说','离开','赶往','前往','抵达','来到','出发','传送','动身','启程','回到','返回','走出','踏入','进入','一路上'];
    /** 审计修复（2026-08-17 写作逻辑 B2）：世界设定被打破的可疑表述关键词。 */
    private const WORLD_REVERSAL_KW = ['修复','逆转','恢复','打破铁律','无视禁令','违背规则','成功做到','破解','解除','痊愈'];

    public function __construct(int $novelId, int $chNum, string $outline)
    {
        $this->novelId = $novelId;
        $this->chNum   = $chNum;
        $this->outline = $outline;
    }

    public function validate(): array
    {
        $this->conflicts = [];
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

    /**
     * 对已写出的正文做预防性校验。
     * 大纲侧仍只预警；正文侧把「严重」矛盾计入质量关，避免只写进 Prompt 却照样落盘。
     *
     * 审计修复（2026-08-17 写作逻辑 B2）：正文侧补齐四项——
     *   · 死人复出（严重，硬拦）；
     *   · 境界无交代跳级（严重，硬拦）；
     *   · 场景突变无过渡（警告；严格模式升为严重）；
     *   · 世界绝对约束被打破（警告；严格模式升为严重）。
     * 乱序补写沿用 last_updated_chapter / location_chapter 边界，
     * 避免未来章的死亡/境界/位置状态污染历史章判定。
     *
     * @return list<array{severity:string,desc:string}>
     */
    public function validateWrittenContent(string $content): array
    {
        if (!getSystemSetting('ws_causal_chain_guard', true, 'bool')) return [];
        if (trim($content) === '') return [];

        $this->conflicts = [];
        $this->checkDeadCharactersInContent($content);
        $this->checkRealmSkipInContent($content);
        $this->checkLocationContinuityInContent($content);
        $this->checkWorldConstraintsInContent($content);
        return $this->conflicts;
    }

    private function checkDeadCharacters(): void
    {
        try {
            // 审计修复（2026-08-17 P3）：补章节边界（与 ChapterPromptBuilder P1-2 同款）。
            // 乱序补写历史章时，在未来章节才死亡的角色对本章而言仍然存活，
            // 不应误报「死人复出」；last_updated_chapter 记录状态最后变更章（死亡即一次变更）。
            $deadCards = DB::fetchAll(
                'SELECT name, last_updated_chapter FROM character_cards WHERE novel_id=? AND alive=0 AND (last_updated_chapter IS NULL OR last_updated_chapter < ?)',
                [$this->novelId, $this->chNum]
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

    private function checkDeadCharactersInContent(string $content): void
    {
        try {
            // 审计修复（2026-08-17 P3）：补章节边界（与 ChapterPromptBuilder P1-2 同款）。
            // 乱序补写历史章时，在未来章节才死亡的角色对本章而言仍然存活，
            // 不应误报「死人复出」；last_updated_chapter 记录状态最后变更章（死亡即一次变更）。
            $deadCards = DB::fetchAll(
                'SELECT name, last_updated_chapter FROM character_cards WHERE novel_id=? AND alive=0 AND (last_updated_chapter IS NULL OR last_updated_chapter < ?)',
                [$this->novelId, $this->chNum]
            );
            if (empty($deadCards)) return;

            foreach ($deadCards as $card) {
                $name = trim((string)($card['name'] ?? ''));
                if (self::deadNameAppearsUnframed($content, $name)) {
                    $deathCh = $card['last_updated_chapter'] ?? '?';
                    $this->conflicts[] = [
                        'severity' => '严重',
                        'desc'     => "「{$name}」已在第{$deathCh}章死亡，正文中再次出场且未见回忆/幻象/遗物/复活交代。",
                    ];
                }
            }
        } catch (\Throwable $e) {
            throw new CausalChainValidationException('Dead-character validation could not complete.', 0, $e);
        }
    }

    /**
     * 回忆只作用于当前叙事段，遗物等提及只豁免当前句；不能豁免后文。
     * 明确归属于该角色的真实复活是状态变化，允许其后的现实出场。
     */
    public static function deadNameAppearsUnframed(string $content, string $name): bool
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) < 2) return false;
        $recallWords = ['回忆','想起','忆起','记起','幻象','梦境','记忆','当初','那年','当年'];
        $mentionWords = ['遗物','魂魄','影像','灵体','牌位','墓','遗言','尸体','遗体','遗骸','尸身'];
        $resurrected = false;
        foreach (preg_split('/\R/u', $content) ?: [] as $paragraph) {
            $inRecall = false;
            foreach (preg_split('/(?<=[，,。！？；])/u', $paragraph) ?: [] as $sentence) {
                $returnsToPresent = self::textHasKeyword($sentence, ['回过神','回到现实','回忆结束','梦醒','醒来','眼下','此刻']);
                if ($returnsToPresent) {
                    $inRecall = false;
                }
                // A narrator can introduce the frame before naming this character.
                // Update it for every clause; an explicit return must not reopen
                // the frame merely because its marker contains "回忆".
                $recall = !$returnsToPresent && self::textHasKeyword($sentence, $recallWords);
                if ($recall) $inRecall = true;
                if (mb_strpos($sentence, $name) === false) continue;

                if (!$inRecall && !$recall && self::hasAffirmedResurrection($sentence, $name)) {
                    $resurrected = true;
                }
                if ($resurrected) continue;
                // A wish or denial is not a live appearance and does not
                // establish resurrection for a later, asserted action.
                if (self::isUnassertedNarration($sentence)
                    || preg_match('/' . preg_quote($name, '/')
                        . '(?:并|仍|还)?(?:没有|并未|未能|未曾|不曾|无法|不能|不会|尚未|不可能)'
                        . '(?:真的|成功)?(?:复活|复生|还魂|重生|转世|涅槃重生)/u', $sentence) === 1) continue;
                if ($inRecall || self::textHasKeyword($sentence, $mentionWords)) continue;
                return true;
            }
        }
        return false;
    }

    private static function hasAffirmedResurrection(string $sentence, string $name): bool
    {
        if (self::isUnassertedNarration($sentence)) return false;
        $subject = preg_quote($name, '/');
        $revival = '(?:复活|复生|还魂|重生|转世|涅槃重生)';
        $adverbs = '(?:终于|已经|早已|已|成功|竟然|竟|真的|重新|得以|了|又)*';
        $matched = preg_match('/' . $subject
            . '(?:在[^，。！？；]{1,24}(?:中|后|下)|被[^，。！？；]{1,12})?'
            . $adverbs . $revival . '/u', $sentence, $match, PREG_OFFSET_CAPTURE) === 1
            || preg_match('/' . $revival . '(?:之后|以后|后)的?' . $subject . '/u', $sentence, $match, PREG_OFFSET_CAPTURE) === 1;
        return $matched
            && preg_match('/不|未|没|阻止|阻断|失败/u', $match[0][0]) !== 1
            && !self::hasNegatedPrefix($sentence, $match[0][1]);
    }

    /** A hard finding must concern asserted current events, not a wish or a recollection. */
    private static function isUnassertedNarration(string $sentence): bool
    {
        return self::textHasKeyword($sentence, [
            '如果','假如','倘若','要是','也许','或许','希望','但愿','盼望','祈求',
            '回忆','想起','忆起','梦境','幻象','那年','当年','当初',
        ]) || preg_match('/[？?]/u', $sentence) === 1;
    }

    /** Only the current clause can qualify an attributed assertion. Offsets are bytes. */
    private static function hasNegatedPrefix(string $sentence, int $offset): bool
    {
        $prefix = preg_split('/[，,。！？；]/u', substr($sentence, 0, $offset)) ?: [];
        $clause = (string)end($prefix);
        return preg_match('/(?:不是|并非|并不|没有|未曾|不曾|从未|尚未|未能|否认)[^，,。！？；]{0,12}$/u', $clause) === 1;
    }

    /**
     * 审计修复（2026-08-17 写作逻辑 B2）：正文境界跳级检查（硬拦级）。
     *
     * 人物卡当前境界 vs 正文明确归属于主角的更高境界；跳过 2 级以上且无
     * 「突破/渡劫/奇遇」类交代 → 严重。下一境不算跳级（正常推进/铺垫）。
     * 人物卡 last_updated_chapter > 本章时跳过：未来章的境界状态不得
     * 回溯污染乱序补写的历史章。
     */
    private function checkRealmSkipInContent(string $content): void
    {
        try {
            $novel = DB::fetch('SELECT protagonist_name FROM novels WHERE id=?', [$this->novelId]);
            $pName = trim((string)($novel['protagonist_name'] ?? ''));
            if ($pName === '') return;

            require_once __DIR__ . '/memory/CharacterCardRepo.php';
            $repo = new CharacterCardRepo($this->novelId);
            $protagonist = $repo->getByName($pName);
            if (!$protagonist) return;

            $lastUpd = (int)($protagonist['last_updated_chapter'] ?? 0);
            if ($lastUpd > 0 && $lastUpd > $this->chNum) return;

            $attrs = $protagonist['attributes'] ?? [];
            if (is_string($attrs)) $attrs = json_decode($attrs, true) ?: [];
            $currentRealm = trim((string)($attrs['realm'] ?? $attrs['level'] ?? ''));
            if ($currentRealm === '') return;

            require_once __DIR__ . '/PowerSystem.php';
            $ps = new PowerSystem($this->novelId);
            $chain = $ps->getRealmChain(true, $currentRealm);
            $realms = $chain['realms'] ?? [];
            $currentIdx = $ps->getRealmIndex($currentRealm);
            if ($currentIdx < 0 || count($realms) < 2) return;

            $skip = self::findRealmSkip($realms, $currentIdx, $pName, $currentRealm, $content);
            if ($skip !== null) {
                $this->conflicts[] = $skip;
            }
        } catch (\Throwable $e) {
            throw new CausalChainValidationException('Realm validation could not complete.', 0, $e);
        }
    }

    /**
     * 审计修复（2026-08-17 写作逻辑 B2）：纯文本判定（零 DB，可单测）——
     * 只对明确归属于主角的当前境界断言作硬判；名字与境界共现不构成证据。
     * 否定、假设、回忆及他人境界不硬拦；当前句有升境交代时允许。
     * 下一境（currentIdx+1）不算跳级，允许正常推进铺垫。
     *
     * @param string[] $realms
     * @return array{severity:string,desc:string}|null
     */
    public static function findRealmSkip(array $realms, int $currentIdx, string $pName, string $currentRealm, string $content): ?array
    {
        $pName = trim($pName);
        if ($pName === '' || $currentIdx < 0 || count($realms) < 2) return null;
        foreach ($realms as $idx => $realm) {
            $realm = trim((string)$realm);
            if ($realm === '' || $idx <= $currentIdx + 1) continue;
            foreach (preg_split('/(?<=[。！？；])|\R/u', $content) ?: [] as $sentence) {
                if (self::isUnassertedNarration($sentence)) continue;
                $subject = preg_quote($pName, '/');
                $rank = preg_quote($realm, '/');
                // A bounded, same-subject physical description may precede the
                // predicate, e.g. 林昭周身灵光暴涨，赫然已是化神境修为。
                $physical = '(?:(?:周身|浑身|体内)(?:灵光|灵力|气息|气机|气势)(?:暴涨|涌动|升腾|流转)[，,]?)?';
                $adverbs = '(?:赫然|竟然|居然|竟|已然|早已|已经|如今|现在|仍然|仍|已|才|仅|只|却)*';
                $attribute = '(?:的?(?:修为|境界|实力))?';
                $predicate = '(?:是|为|乃是|就是|达到|达到了)';
                $rankSuffix = '(?:境(?:界)?)?(?:初期|中期|后期|巅峰)?';
                $endOfAttribute = '(?:了)?(?=[，,。！？；\s]|$)';
                // 拥有化神功法/是金丹修士的弟子 do not assert the
                // character's realm. Require a completed realm/state phrase.
                $attribution = $subject . $physical . $attribute . $adverbs;
                $owned = preg_match('/' . $attribution . $predicate . $rank . $rankSuffix
                    . '(?:修为|修士|境界|强者|实力)?' . $endOfAttribute . '/u', $sentence, $match, PREG_OFFSET_CAPTURE) === 1
                    || preg_match('/' . $attribution . '(?:拥有|有着|只有|已有)' . $rank . $rankSuffix
                    . '(?:的)?(?:修为|境界|实力)' . $endOfAttribute . '/u', $sentence, $match, PREG_OFFSET_CAPTURE) === 1;
                $owned = $owned && !self::hasNegatedPrefix($sentence, $match[0][1]);
                if ($owned && !self::hasRealmExplanation($sentence)) {
                    return [
                        'severity' => '严重',
                        'desc'     => "主角「{$pName}」人物卡境界为「{$currentRealm}」，正文以「{$realm}」描述且未见突破/渡劫/奇遇类交代（疑似跳级）。",
                    ];
                }
            }
        }
        return null;
    }

    private static function hasRealmExplanation(string $sentence): bool
    {
        foreach (self::REALM_EXPLAIN_KW as $keyword) {
            $offset = 0;
            while (($position = mb_strpos($sentence, $keyword, $offset)) !== false) {
                $prefix = mb_substr($sentence, max(0, $position - 16), min(16, $position));
                if (preg_match('/(?:没有|未曾|并未|不曾|未能|尚未|从未|未|无)(?:任何|丝毫|经历|经过|发生|获得|得到|过|的)*$/u', $prefix) !== 1) {
                    return true;
                }
                $offset = $position + mb_strlen($keyword);
            }
        }
        return false;
    }

    /** 关键词命中（静态版，供零 DB 单测复用）。 */
    private static function textHasKeyword(string $text, array $keywords): bool
    {
        foreach ($keywords as $kw) {
            if (is_string($kw) && $kw !== '' && mb_strpos($text, $kw) !== false) return true;
        }
        return false;
    }

    /**
     * 审计修复（2026-08-17 写作逻辑 B2）：正文场景连续性检查（记分级）。
     *
     * 上一章 novel_state.current_location 与本章开篇地点突变且无过渡句 →
     * 警告；约束严格模式（cf_strict_mode）开启时升为严重以便硬拦。
     * location_chapter >= 本章时跳过（未来章位置不回溯污染历史章）。
     */
    private function checkLocationContinuityInContent(string $content): void
    {
        try {
            $state = DB::fetch(
                'SELECT current_location, location_chapter FROM novel_state WHERE novel_id=?',
                [$this->novelId]
            );
            $prevLoc = trim((string)($state['current_location'] ?? ''));
            if ($prevLoc === '' || mb_strlen($prevLoc) < 2) return;
            $locCh = (int)($state['location_chapter'] ?? 0);
            if ($locCh > 0 && $locCh >= $this->chNum) return;

            $opening = mb_substr($content, 0, 200);
            $probe = mb_substr($prevLoc, 0, 4);
            if ($probe !== '' && mb_strpos($opening, $probe) !== false) return;
            if ($this->hasKeyword($opening, self::SCENE_TRANSITION_KW)) return;

            $severity = $this->isStrictMode() ? '严重' : '警告';
            $this->conflicts[] = [
                'severity' => $severity,
                'desc'     => "上章结束场景在「{$prevLoc}」，本章开篇未见该场景或转场过渡，疑似场景突变。",
            ];
        } catch (\Throwable $e) {
            throw new CausalChainValidationException('Location validation could not complete.', 0, $e);
        }
    }

    /**
     * 审计修复（2026-08-17 写作逻辑 B2）：正文世界矛盾检查（记分级）。
     *
     * 复用大纲侧 StoryBible world_md 的绝对约束抽取；正文在约束核心对象
     * 附近出现「修复/逆转/打破铁律」类表述 → 警告（严格模式升为严重）。
     */
    private function checkWorldConstraintsInContent(string $content): void
    {
        try {
            require_once __DIR__ . '/memory/StoryBible.php';
            $bible = StoryBible::getStrict($this->novelId, $this->chNum);
            $worldRules = (string)($bible['world_md'] ?? '');
            if ($worldRules === '') return;

            $absolutePatterns = [
                '/(?:绝对|永远|绝不|不可能|无法|禁止|不许)(.{2,15}?)(?:[。，；])/u',
                '/(?:无解|无药可解|无法破解|不可逆|不可修复)(.{2,15}?)/u',
            ];

            $severity = $this->isStrictMode() ? '严重' : '警告';
            foreach ($absolutePatterns as $pattern) {
                if (!preg_match_all($pattern, $worldRules, $matches, PREG_SET_ORDER)) {
                    continue;
                }
                foreach ($matches as $set) {
                    $constraint = trim((string)$set[0]);
                    $core = trim((string)($set[1] ?? ''));
                    if ($constraint === '' || $core === '' || mb_strlen($core) < 2) continue;
                    $coreLen = mb_strlen($core);
                    $pos = 0;
                    while (($p = mb_strpos($content, $core, $pos)) !== false) {
                        $from = max(0, $p - 60);
                        $win = mb_substr($content, $from, 120 + $coreLen);
                        if ($this->hasKeyword($win, self::WORLD_REVERSAL_KW)) {
                            $this->conflicts[] = [
                                'severity' => $severity,
                                'desc'     => "世界设定绝对约束「{$constraint}」疑似在正文被打破（附近出现逆转/修复类表述），请补足合理化解释。",
                            ];
                            break;
                        }
                        $pos = $p + $coreLen;
                    }
                }
            }
        } catch (\Throwable $e) {
            throw new CausalChainValidationException('World-constraint validation could not complete.', 0, $e);
        }
    }

    /** 约束严格模式（cf_strict_mode）读取；支持小说级覆盖（E），配置不可用时按关闭降级。 */
    private function isStrictMode(): bool
    {
        try {
            if (!class_exists('ConstraintConfig', false)) {
                require_once __DIR__ . '/constraints/ConstraintConfig.php';
            }
            return class_exists('ConstraintConfig', false)
                ? (bool)ConstraintConfig::isStrictMode($this->novelId)
                : (bool)getSystemSetting('cf_strict_mode', false, 'bool');
        } catch (\Throwable $e) {
            return false;
        }
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
