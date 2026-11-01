<?php
/**
 * 金手指设计模板组件（审计功能 2026-08-16 P3 · 写作能力缺口 C3）
 *
 * 网文金手指的五要素单一来源：名称 / 获得时机 / 升级路线 / 使用代价 / 限制条件。
 * 采集：向导蓝图阶段第三 APPLY 目标（<<<APPLY:golden_finger>>>）；
 * 存储：novel_worldbuilding（category='golden_finger'，attributes JSON），
 *       与 PowerSystem 读境界链（category='rule'）先例同构，不受大纲重建影响；
 * 注入：细纲层档位表 + 梗概层 system 规则区 + 章节层 user 约束段（章节感知档位）；
 * 核验：写后检测金手指使用场景是否体现代价/限制（零命中→下一章修正指令）。
 *
 * 分层设计：纯静态核心（零 DB，可独立行为测试）+ 实例 DB 封装
 * （仿 PowerSystem：构造函数收 novelId，方法内查库）。
 * 详见 docs/superpowers/specs/2026-08-16-golden-finger-design.md。
 */
defined('APP_LOADED') or die('Direct access denied.');

class GoldenFinger
{
    /** 代价/限制关键词抽取的停用 bigram（功能词无判定价值，防误命中） */
    private const TOKEN_STOPGRAMS = [
        '使用', '之后', '不可', '一次', '无法', '必须', '每次', '同一', '以及',
        '并且', '不能', '可以', '以上', '以下', '日内', '月内', '期间', '同时',
        '任何', '所有', '相应', '立即', '状态', '效果', '能力', '目标',
    ];

    // ================================================================
    // 纯静态核心（零 DB）
    // ================================================================

    /** 总开关（ws_golden_finger_enabled，默认开；控制注入与核验，不控采集）。独立加载视为开。 */
    public static function enabled(): bool
    {
        if (!function_exists('getSystemSetting')) return true;
        try {
            return (bool)getSystemSetting('ws_golden_finger_enabled', true, 'bool');
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * 蓝图阶段的金手指设计模板段（prompt 单一来源）。
     *
     * @param bool $mandatory 标签含「金手指」或用户消息提及时为 true（硬性要求产出）
     */
    public static function blueprintPromptTemplate(bool $mandatory): string
    {
        $lead = $mandatory
            ? "本书核心爽点包含金手指/系统类设定，蓝图必须额外产出【金手指设计】块。"
            : "若故事包含金手指/系统/外挂类核心设定，建议额外产出【金手指设计】块；纯现实/言情等无此设定的题材可省略。";
        return <<<EOT

【金手指设计】{$lead}
设计三原则（必须遵守）：
1. 升级路线分 3-5 档，章节区间须与全书目标章数对齐（前期档位跨度小、后期放大）；
2. 使用代价必须可叙事化（能写成正文中的负面状态/损失/风险），禁止"无代价"；
3. 限制条件必须能制造剧情困境（关键时刻失灵/不可用/有条件），而非摆设。
输出格式（包裹在 APPLY 标记内，纯 JSON，字段齐全）：
<<<APPLY:golden_finger>>>
{"name":"金手指名称","acquire_chapter":1,"acquire_scene":"获得场景一句话","progression":[{"tier":"一档名称","from_chapter":1,"to_chapter":40,"abilities":"本档能力上限"}],"cost":"使用代价","limit":"限制条件","summary":"一句话概述"}
<<<END>>>
EOT;
    }

    /**
     * 解析蓝图 AI 输出中的金手指 JSON（容错：优先 APPLY 块，回退整段裸 JSON / markdown 围栏）。
     * 返回规范化五要素数组；无法解析/校验失败返回 null。
     */
    public static function parseBlueprintOutput(string $raw): ?array
    {
        $candidate = $raw;
        if (preg_match('/<<<APPLY:golden_finger>>>\s*(.+?)\s*<<<END>>>/su', $raw, $m)) {
            $candidate = $m[1];
        }
        $candidate = trim((string)preg_replace('/^```(?:json)?|```$/mu', '', trim($candidate)));
        $start = mb_strpos($candidate, '{');
        $end   = mb_strrpos($candidate, '}');
        if ($start === false || $end === false || $end <= $start) return null;
        $json = mb_substr($candidate, $start, $end - $start + 1);
        $data = json_decode($json, true);
        if (!is_array($data)) return null;
        return self::normalizeSetting($data);
    }

    /**
     * 解析前端多行升级路线（每行：档位名|起始章|结束章|能力描述）。
     * 非法行跳过；返回 progression 数组（可能为空）。
     */
    public static function parseProgressionLines(string $multiLine): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/u', $multiLine) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 3) continue;
            $from = (int)$parts[1];
            $to   = (int)$parts[2];
            if ($parts[0] === '' || $from < 1 || $to < $from) continue;
            $out[] = [
                'tier'         => mb_substr($parts[0], 0, 60),
                'from_chapter' => $from,
                'to_chapter'   => $to,
                'abilities'    => mb_substr($parts[3] ?? '', 0, 200),
            ];
        }
        usort($out, fn($a, $b) => $a['from_chapter'] <=> $b['from_chapter']);
        return $out;
    }

    /**
     * 章节感知约束段（四态 + 恒定段）。无设定/关闭返回空串。
     */
    public static function buildConstraintFromSetting(?array $setting, int $chNum): string
    {
        if ($setting === null || !self::enabled()) return '';
        try {
            $name    = (string)($setting['name'] ?? '');
            $acqCh   = (int)($setting['acquire_chapter'] ?? 1);
            $acqScene = trim((string)($setting['acquire_scene'] ?? ''));
            $cost    = trim((string)($setting['cost'] ?? ''));
            $limit   = trim((string)($setting['limit'] ?? ''));
            if ($name === '') return '';

            $lines = ["【⚡ 金手指「{$name}」——设定铁律（必须严格遵守）】"];

            if ($chNum < $acqCh) {
                $near = ($acqCh - $chNum) <= 3 ? '距获得章已近，暗示可逐步加强（异象、传闻、残片等）。' : '铺垫期保持克制。';
                $lines[] = "- 铺垫期（第{$acqCh}章才获得）：本章禁止让「{$name}」完整亮相或暴露能力全貌，仅可埋征兆/伏笔暗示。{$near}";
            } elseif ($chNum === $acqCh) {
                $sceneNote = $acqScene !== '' ? "（获得场景参考：{$acqScene}）" : '';
                $lines[] = "- 本章为获得章：必须完成「{$name}」的正式亮相与认主/激活{$sceneNote}，亮相后立即展示一次基础能力，并首次体现代价。";
            } else {
                $tiers   = is_array($setting['progression'] ?? null) ? $setting['progression'] : [];
                $current = self::currentTier($tiers, $chNum);
                if ($current !== null) {
                    $lines[] = "- 本章能力档位：「{$current['tier']}」——可用能力上限：{$current['abilities']}。禁止提前使用更高档位能力（防跳级开挂）。";
                } else {
                    $route = self::routeSummary($tiers);
                    $lines[] = "- 本章能力档位：按升级路线执行{$route}。禁止超出当前进度应有的能力。";
                }
                $next = self::nextTier($tiers, $chNum);
                if ($next !== null) {
                    $lines[] = "- 下一档位「{$next['tier']}」第{$next['from_chapter']}章解锁——本章不得提前触及。";
                }
            }

            if ($cost !== '')  $lines[] = "- 使用代价（每次使用必须体现至少一项）：{$cost}";
            if ($limit !== '') $lines[] = "- 限制条件（剧情中必须真实生效，制造困境而非摆设）：{$limit}";
            $lines[] = "- 铁律：金手指每次使用场景至少体现代价或限制中的一项；连续使用须累积代价；无代价开挂视为设定崩坏。";
            $lines[] = "- 名称锚定：正文中统一称呼「{$name}」，不得随意改名或换称。";

            return implode("\n", $lines) . "\n";
        } catch (\Throwable $e) {
            error_log('GoldenFinger::buildConstraintFromSetting failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * 细纲批档位表：本批各章 → 当前档位（获得章在批内或档位变化时注入，其余按铺垫期提示）。
     */
    public static function batchTableFromSetting(?array $setting, int $fromCh, int $toCh): string
    {
        if ($setting === null || !self::enabled()) return '';
        try {
            $name  = (string)($setting['name'] ?? '');
            $acqCh = (int)($setting['acquire_chapter'] ?? 1);
            $tiers = is_array($setting['progression'] ?? null) ? $setting['progression'] : [];
            if ($name === '' || $toCh < $fromCh) return '';

            $cost  = trim((string)($setting['cost'] ?? ''));
            $limit = trim((string)($setting['limit'] ?? ''));

            $lines = ["【⚡ 金手指「{$name}」排期约束（细纲必须遵守）】"];
            if ($toCh < $acqCh) {
                $lines[] = "本批全部处于铺垫期（第{$acqCh}章获得）：细纲不得安排金手指完整亮相或能力全貌暴露，仅可埋伏笔/征兆。";
            } else {
                $lines[] = "各章能力档位（细纲事件强度不得超出对应档位）：";
                $lastKey = '';
                for ($ch = max($fromCh, $acqCh); $ch <= $toCh; $ch++) {
                    $t = self::currentTier($tiers, $ch);
                    $key = $t !== null ? $t['tier'] : '—';
                    if ($ch === $acqCh) {
                        $lines[] = "  第{$ch}章：⚡获得章（正式亮相+首次代价体现）｜档位：{$key}";
                    } elseif ($key !== $lastKey) {
                        $ab = $t !== null ? mb_substr($t['abilities'], 0, 40) : '';
                        $lines[] = "  第{$ch}章起：{$key}" . ($ab !== '' ? "（{$ab}）" : '');
                    }
                    $lastKey = $key;
                }
            }
            if ($cost !== '')  $lines[] = "使用代价：{$cost}";
            if ($limit !== '') $lines[] = "限制条件：{$limit}";
            return implode("\n", $lines) . "\n";
        } catch (\Throwable $e) {
            error_log('GoldenFinger::batchTableFromSetting failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * 写后核验（纯静态）：正文出现金手指名称 ≥2 次视为使用场景，
     * 检测 cost/limit 关键词覆盖（仿转折点覆盖模式）。返回 {passed, misses, uses}；
     * 无设定/名称未出现/无法抽取关键词 → null（不误报）。
     */
    public static function verifyFromSetting(?array $setting, string $content): ?array
    {
        if ($setting === null || !self::enabled()) return null;
        try {
            $name = (string)($setting['name'] ?? '');
            if ($name === '') return null;
            $uses = mb_substr_count($content, $name);
            if ($uses < 2) return null; // 非使用场景，不误报

            $tokens = self::extractCostTokens(
                trim((string)($setting['cost'] ?? '')) . '；' . trim((string)($setting['limit'] ?? ''))
            );
            if (empty($tokens)) return null; // 无可判定关键词

            foreach ($tokens as $tk) {
                if (mb_strpos($content, $tk) !== false) {
                    return ['passed' => true, 'misses' => [], 'uses' => $uses];
                }
            }
            return ['passed' => false, 'misses' => ['代价/限制未体现'], 'uses' => $uses];
        } catch (\Throwable $e) {
            error_log('GoldenFinger::verifyFromSetting failed: ' . $e->getMessage());
            return null;
        }
    }

    // ================================================================
    // 纯核心私有辅助
    // ================================================================

    /** 五要素规范化与校验：name 必填；返回规范化数组或 null */
    private static function normalizeSetting(array $raw): ?array
    {
        $name = trim((string)($raw['name'] ?? ''));
        if ($name === '') return null;
        $progression = [];
        if (isset($raw['progression']) && is_array($raw['progression'])) {
            foreach ($raw['progression'] as $p) {
                if (!is_array($p)) continue;
                $tier = trim((string)($p['tier'] ?? $p['name'] ?? ''));
                $from = (int)($p['from_chapter'] ?? $p['from'] ?? 0);
                $to   = (int)($p['to_chapter'] ?? $p['to'] ?? 0);
                if ($tier === '' || $from < 1 || $to < $from) continue;
                $progression[] = [
                    'tier'         => mb_substr($tier, 0, 60),
                    'from_chapter' => $from,
                    'to_chapter'   => $to,
                    'abilities'    => mb_substr(trim((string)($p['abilities'] ?? '')), 0, 200),
                ];
            }
            usort($progression, fn($a, $b) => $a['from_chapter'] <=> $b['from_chapter']);
        }
        return [
            'name'            => mb_substr($name, 0, 60),
            'acquire_chapter' => max(1, (int)($raw['acquire_chapter'] ?? 1)),
            'acquire_scene'   => mb_substr(trim((string)($raw['acquire_scene'] ?? '')), 0, 200),
            'progression'     => $progression,
            'cost'            => mb_substr(trim((string)($raw['cost'] ?? '')), 0, 300),
            'limit'           => mb_substr(trim((string)($raw['limit'] ?? '')), 0, 300),
            'summary'         => mb_substr(trim((string)($raw['summary'] ?? $raw['description'] ?? '')), 0, 120),
        ];
    }

    /** 当前章所在档位（from<=ch<=to）；未命中返回 null */
    private static function currentTier(array $tiers, int $chNum): ?array
    {
        foreach ($tiers as $t) {
            $from = (int)($t['from_chapter'] ?? 0);
            $to   = (int)($t['to_chapter'] ?? 0);
            if ($chNum >= $from && $chNum <= $to) return $t;
        }
        return null;
    }

    /** 下一个档位（from > chNum 的最近一档） */
    private static function nextTier(array $tiers, int $chNum): ?array
    {
        foreach ($tiers as $t) {
            if ((int)($t['from_chapter'] ?? 0) > $chNum) return $t;
        }
        return null;
    }

    /** 全路线一句话（档位回退注入用） */
    private static function routeSummary(array $tiers): string
    {
        $parts = [];
        foreach ($tiers as $t) {
            $parts[] = "{$t['tier']}({$t['from_chapter']}-{$t['to_chapter']}章)";
        }
        return $parts ? '：' . implode(' → ', $parts) : '';
    }

    /** 从代价/限制文本抽取判定 bigram（CJK 连续段，去停用词，去重） */
    private static function extractCostTokens(string $text): array
    {
        $tokens = [];
        if (!preg_match_all('/[\x{4e00}-\x{9fff}]{2,}/u', $text, $segs)) return $tokens;
        foreach ($segs[0] as $seg) {
            $len = mb_strlen($seg);
            for ($i = 0; $i + 2 <= $len; $i++) {
                $gram = mb_substr($seg, $i, 2);
                if (in_array($gram, self::TOKEN_STOPGRAMS, true)) continue;
                $tokens[$gram] = true;
            }
        }
        return array_keys($tokens);
    }

    // ================================================================
    // 实例 DB 封装（仿 PowerSystem：构造收 novelId，方法内查库）
    // ================================================================

    private int $novelId;

    public function __construct(int $novelId)
    {
        $this->novelId = $novelId;
    }

    /** 读取主金手指设定（importance 最高、id 最先）；无/异常返回 null */
    public function get(): ?array
    {
        if (!class_exists('DB', false)) return null;
        try {
            $row = DB::fetch(
                "SELECT name, description, attributes FROM novel_worldbuilding
                 WHERE novel_id=? AND category='golden_finger'
                 ORDER BY importance DESC, id ASC LIMIT 1",
                [$this->novelId]
            );
            if (!$row) return null;
            $attrs = is_string($row['attributes'] ?? null)
                ? json_decode($row['attributes'], true)
                : ($row['attributes'] ?? null);
            if (!is_array($attrs)) return null;
            $attrs['name'] = trim((string)($row['name'] ?? ($attrs['name'] ?? '')));
            if (($attrs['summary'] ?? '') === '') {
                $attrs['summary'] = trim((string)($row['description'] ?? ''));
            }
            return self::normalizeSetting($attrs);
        } catch (\Throwable $e) {
            error_log('GoldenFinger::get failed: ' . $e->getMessage());
            return null;
        }
    }

    /** 保存（upsert by novel_id+name+category）。返回是否成功。 */
    public function save(array $setting): bool
    {
        if (!class_exists('DB', false)) return false;
        try {
            $normalized = self::normalizeSetting($setting);
            if ($normalized === null) return false;
            $row = [
                'category'    => 'golden_finger',
                'description' => $normalized['summary'],
                'attributes'  => json_encode($normalized, JSON_UNESCAPED_UNICODE),
                'importance'  => 5,
                'notes'       => '来源：向导蓝图（金手指设计模板）',
            ];
            $exist = DB::fetch(
                "SELECT id FROM novel_worldbuilding
                 WHERE novel_id=? AND name=? AND category='golden_finger' LIMIT 1",
                [$this->novelId, $normalized['name']]
            );
            if ($exist) {
                DB::update('novel_worldbuilding', $row, 'id=?', [(int)$exist['id']]);
            } else {
                $row['novel_id'] = $this->novelId;
                $row['name']     = $normalized['name'];
                DB::insert('novel_worldbuilding', $row);
            }
            return true;
        } catch (\Throwable $e) {
            error_log('GoldenFinger::save failed: ' . $e->getMessage());
            return false;
        }
    }

    /** 删除指定名称的金手指设定（前端空名称保存=清除）。 */
    public function delete(string $name): bool
    {
        if (!class_exists('DB', false) || trim($name) === '') return false;
        try {
            DB::execute(
                "DELETE FROM novel_worldbuilding WHERE novel_id=? AND name=? AND category='golden_finger'",
                [$this->novelId, trim($name)]
            );
            return true;
        } catch (\Throwable $e) {
            error_log('GoldenFinger::delete failed: ' . $e->getMessage());
            return false;
        }
    }

    /** 章节层约束（DB 读取 + 四态构造的实例封装） */
    public function buildConstraint(int $chNum): string
    {
        return self::buildConstraintFromSetting($this->get(), $chNum);
    }

    /** 细纲层批档位表（实例封装） */
    public function batchConstraintTable(int $fromCh, int $toCh): string
    {
        return self::batchTableFromSetting($this->get(), $fromCh, $toCh);
    }

    /** 写后核验（实例封装；章节号仅用于日志语境，核验本身章号无关） */
    public function verifyWrittenChapter(int $chNum, string $content): ?array
    {
        return self::verifyFromSetting($this->get(), $content);
    }
}
