<?php
declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');
require_once dirname(__DIR__) . '/ai.php';
require_once __DIR__ . '/StoryOutlineBudget.php';

/** A complete JSON document is the smallest reusable generation checkpoint. */
final class StoryOutlineStageGenerator
{
    public const STAGES = ['skeleton', 'characters', 'world'];
    public const STAGE_BUDGET_SECONDS = 900;
    public const MAX_ATTEMPTS = 1;
    private const MAX_RESPONSE_BYTES = 1048576;
    private const FIELDS = [
        'skeleton' => ['story_arc', 'act_division', 'major_turning_points'],
        'characters' => ['character_arcs', 'character_endpoints', 'character_progression'],
        'world' => ['world_evolution', 'recurring_motifs'],
    ];

    private $aiFactory;
    private $now;

    // Legacy constructor positions remain compatible; modelProvider/sleep are never invoked.
    public function __construct(?callable $aiFactory = null, ?callable $modelProvider = null, ?callable $now = null, ?callable $sleep = null)
    {
        $this->aiFactory = $aiFactory ?? static function(array $model): AIClient {
            // Credentials may rotate; provider identity and generation options may not.
            $live = DB::fetch('SELECT api_key,api_url FROM ai_models WHERE id=?', [(int)$model['id']]);
            if (!$live || (string)$live['api_url'] !== (string)$model['api_url']) {
                throw new InvalidArgumentException('冻结模型已删除或端点已修改，请重新生成任务。');
            }
            $model['api_key'] = $live['api_key'];
            return new AIClient($model);
        };
        $this->now = $now ?? static fn(): int => HarnessRuntime::unixTime();
    }

    /**
     * The caller persists deadlineUnix before dispatch, and writes the returned
     * validated array and step success in one transaction. Preview is never a
     * completed checkpoint. No provider is asked to continue malformed JSON.
     */
    public function generate(string $stage, array $context, array $completed, callable $checkpoint, callable $preview, int $deadlineUnix, ?callable $settle = null): array
    {
        if (!isset(self::FIELDS[$stage])) throw new InvalidArgumentException('Unknown story outline stage.');
        if (isset($completed[$stage])) return self::validateStage($stage, $completed[$stage], (int)($context['target_chapters'] ?? 0));

        $deadline = min($deadlineUnix, ($this->now)() + self::STAGE_BUDGET_SECONDS);
        $callbackFailure = null;
        $emitPreview = static function (string $text) use ($preview, &$callbackFailure): void {
            try {
                $preview($text);
            } catch (Throwable $error) {
                $callbackFailure = $error;
                throw $error;
            }
        };
        $guard = function () use ($checkpoint, $deadline, &$callbackFailure): void {
            try {
                $checkpoint();
            } catch (Throwable $error) {
                $callbackFailure = $error;
                throw $error;
            }
            if (($this->now)() >= $deadline) {
                throw new AIRequestTimeoutException('故事大纲阶段超过执行时限，已完成阶段已保留。');
            }
        };
        $guard();
        $messages = self::messages($stage, $context, $completed);
        $models = $context['models'] ?? [];
        if ($models === []) throw new InvalidArgumentException('任务缺少冻结模型配置，请选择重新生成。');
        $modelIndex = min(max(0,(int)($context['attempt_no'] ?? 1)-1),count($models)-1);
        $guard();
        $client = ($this->aiFactory)($models[$modelIndex]);
        if (!$client instanceof AIClient) throw new LogicException('Story outline client factory must return AIClient.');
        $client->setDurableRequestPolicy((int)$models[$modelIndex]['max_tokens'], (float)$models[$modelIndex]['temperature']);
        $oldPolicy = $client->getStreamTimeoutPolicy();
        $client->setStreamTimeoutPolicy([
            'request_timeout_seconds' => min(600, max(1, $deadline - ($this->now)())),
            'idle_timeout_seconds' => 180,
            'deadline_unix' => $deadline,
        ]);
        // These are new per-attempt clients. Scope callbacks to this task.
        $client->setCallbacks($guard, static fn(int $elapsed) => $guard(), static function () use ($guard): bool {
            $guard();
            return false;
        });
        $raw = '';
        $usage = null;
        $failure = null;
        try {
            $emitPreview('');
            $usage = $client->chatStream($messages, static function (string $chunk) use (&$raw, $guard, $emitPreview): void {
                $guard();
                $raw .= $chunk;
                if (strlen($raw) > self::MAX_RESPONSE_BYTES) throw new UnexpectedValueException('故事大纲单阶段输出过大。');
                $emitPreview($raw);
            }, 'structured', static function (string $thinking) use ($guard): void {
                $guard();
            });
            $guard();
            if ($client->lastFinishReason !== null && !in_array($client->lastFinishReason, ['stop', 'end_turn', 'eos'], true)) {
                throw new UnexpectedValueException('故事大纲阶段输出未完整结束：' . $client->lastFinishReason);
            }
            return self::validateStage($stage, self::decode($raw), (int)($context['target_chapters'] ?? 0));
        } catch (Throwable $error) {
            $failure = $error;
            if ($callbackFailure !== null) throw $callbackFailure;
            throw $error;
        } finally {
            $client->setCallbacks(null, null, null);
            $client->setStreamTimeoutPolicy($oldPolicy);
            if ($settle !== null) $settle(in_array($client->lastUsage['source'],['provider','provider_partial'],true) ? $client->lastUsage : $usage, $client->lastFinishReason, $failure);
        }
    }

    public static function messages(string $stage, array $context, array $completed): array
    {
        $messages = $context['base_messages'] ?? null;
        if (!is_array($messages) || $messages === []) throw new InvalidArgumentException('Missing frozen story outline context.');
        foreach ($messages as $message) {
            if (!is_array($message) || !isset($message['role'], $message['content']) || !is_string($message['content'])) {
                throw new InvalidArgumentException('Invalid frozen story outline context.');
            }
        }
        $canonical = [];
        $stagePosition = array_search($stage, self::STAGES, true);
        foreach (array_slice(self::STAGES, 0, $stagePosition) as $previousStage) {
            if (!isset($completed[$previousStage])) throw new InvalidArgumentException('Missing preceding story outline checkpoint.');
            $canonical = array_merge($canonical, self::validateStage($previousStage, $completed[$previousStage]));
        }
        $fields = implode('、', self::FIELDS[$stage]);
        $instruction = "本次分阶段完成全书故事大纲，当前阶段为 {$stage}。沿用上文的小说设定、卷结构、已写章节事实及全部内容规则。"
            . "上文的全字段示例仅用于说明结构，本次只输出一个完整 JSON 对象，顶层仅包含 {$fields}；不要输出其他阶段字段、说明或 Markdown。"
            . '每个指定字段必须完整。三幕必须有 act1、act2、act3，各含 chapters、theme、key_events、character_growth。'
            . '人物弧线按人物名映射，每人含 start、midpoint、end；人物进阶按人物名映射，每人含 power_system、progression_plan。'
            . '如没有修炼等级，用符合题材的身份或能力成长，不得编造已写章节中未出现的事实。';
        $target = (int)($context['target_chapters'] ?? 0);
        if ($target > 0) $instruction .= "三幕章节范围必须以闭区间连续且不重复覆盖第1至{$target}章，格式为1-20或单章数字；目标不足3章时，尾部空幕的chapters使用无。转折点和人物成长范围不得越界或乱序。";
        if ($canonical !== []) {
            $instruction .= "\n以下是已经完成并锁定的阶段结果。必须沿用这些人物名、幕结构、事件顺序和章节范围，不得改写或重新生成它们：\n"
                . json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        $messages[] = ['role' => 'user', 'content' => $instruction];
        return $messages;
    }

    private static function decode(string $raw): array
    {
        $raw = trim($raw);
        if (preg_match('/\A```(?:json)?\s*\R?(.*?)\R?```\z/s', $raw, $match)) $raw = trim($match[1]);
        if ($raw === '' || $raw[0] !== '{') throw new UnexpectedValueException('故事大纲阶段未返回 JSON 对象。');
        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new UnexpectedValueException('故事大纲阶段 JSON 不完整。', 0, $error);
        }
        if (!is_array($decoded)) throw new UnexpectedValueException('故事大纲阶段 JSON 类型错误。');
        return $decoded;
    }

    public static function merge(array $completed, int $targetChapters = 0): array
    {
        $result = [];
        foreach (self::STAGES as $stage) {
            if (!isset($completed[$stage]) || !is_array($completed[$stage])) throw new UnexpectedValueException('故事大纲仍有未完成阶段。');
            $result = array_merge($result, self::validateStage($stage, $completed[$stage], $targetChapters));
        }
        return $result;
    }

    public static function validateStage(string $stage, array $data, int $targetChapters = 0): array
    {
        if (!isset(self::FIELDS[$stage])) throw new InvalidArgumentException('Unknown story outline stage.');
        if ($stage === 'skeleton') {
            self::textField($data, 'story_arc');
            if (!isset($data['act_division']) || !is_array($data['act_division'])) throw new UnexpectedValueException('缺少完整三幕结构。');
            foreach (['act1', 'act2', 'act3'] as $actName) {
                $act = $data['act_division'][$actName] ?? null;
                if (!is_array($act)) throw new UnexpectedValueException('缺少故事大纲幕：' . $actName);
                foreach (['chapters', 'theme', 'character_growth'] as $field) self::textField($act, $field);
                self::stringList($act['key_events'] ?? null, 'key_events', true);
            }
            self::objectList($data['major_turning_points'] ?? null, 'major_turning_points', true);
            foreach ($data['major_turning_points'] as &$point) {
                if (!isset($point['chapter']) || filter_var($point['chapter'], FILTER_VALIDATE_INT) === false || (int)$point['chapter'] < 1) throw new UnexpectedValueException('转折点缺少有效章节号。');
                $point['chapter'] = (int)$point['chapter'];
                self::textField($point, 'event');
                self::textField($point, 'impact');
                if (isset($point['volume'])) {
                    if (filter_var($point['volume'], FILTER_VALIDATE_INT) === false || (int)$point['volume'] < 1) throw new UnexpectedValueException('转折点卷号无效。');
                    $point['volume'] = (int)$point['volume'];
                }
            }
            unset($point);
        } elseif ($stage === 'characters') {
            self::namedObjects($data['character_arcs'] ?? null, 'character_arcs');
            $endpoints = [];
            foreach ($data['character_arcs'] as $name => $arc) {
                foreach (['start', 'midpoint', 'end'] as $field) self::textField($arc, $field);
                $endpoints[] = $name . '：' . $arc['end'];
            }
            if (!isset($data['character_endpoints']) || $data['character_endpoints'] === '') $data['character_endpoints'] = implode('；', $endpoints);
            self::textField($data, 'character_endpoints');
            self::namedObjects($data['character_progression'] ?? null, 'character_progression');
            foreach ($data['character_progression'] as $progression) {
                self::textField($progression, 'power_system');
                self::objectList($progression['progression_plan'] ?? null, 'progression_plan', true);
                foreach ($progression['progression_plan'] as $step) {
                    foreach (['stage', 'chapter_range', 'realm'] as $field) self::textField($step, $field);
                }
            }
        } else {
            self::textField($data, 'world_evolution');
            self::stringList($data['recurring_motifs'] ?? null, 'recurring_motifs');
        }
        if ($targetChapters > 0) self::validateRanges($stage,$data,$targetChapters);
        return array_intersect_key($data, array_flip(self::FIELDS[$stage]));
    }

    private static function range(string $value, int $target): array
    {
        if (!preg_match('/^\s*(?:第)?([0-9]+)(?:\s*[-—–~～至]\s*([0-9]+))?\s*(?:章)?\s*$/uD',$value,$match)) throw new UnexpectedValueException('章节范围格式无效：'.$value);
        $from=(int)$match[1]; $to=(int)($match[2]??$match[1]);
        if ($from<1 || $to<$from || $to>$target) throw new UnexpectedValueException('故事大纲章节范围越界或倒序。');
        return [$from,$to];
    }

    private static function validateRanges(string $stage,array $data,int $target): void
    {
        if ($stage==='skeleton') {
            $next=1;
            foreach (['act1','act2','act3'] as $act) {
                $value=trim($data['act_division'][$act]['chapters']);
                if ($target<3 && $next===$target+1 && $value==='无') continue;
                [$from,$to]=self::range($value,$target);
                if ($from!==$next) throw new UnexpectedValueException('三幕章节范围存在缺段、重复或乱序。');
                $next=$to+1;
            }
            if ($next!==$target+1) throw new UnexpectedValueException('三幕章节范围未覆盖目标末章。');
            $previous=0;
            foreach ($data['major_turning_points'] as $point) {
                if ($point['chapter']>$target || $point['chapter']<=$previous) throw new UnexpectedValueException('转折点章节越界、重复或乱序。');
                $previous=$point['chapter'];
            }
        } elseif ($stage==='characters') {
            foreach ($data['character_progression'] as $progression) {
                $previous=0;
                foreach ($progression['progression_plan'] as $step) {
                    [$from,$to]=self::range($step['chapter_range'],$target);
                    if ($from<=$previous) throw new UnexpectedValueException('人物成长章节范围重叠或乱序。');
                    $previous=$to;
                }
            }
        }
    }

    private static function textField(array $data, string $field): void
    {
        if (!isset($data[$field]) || !is_string($data[$field]) || trim($data[$field]) === '') throw new UnexpectedValueException('故事大纲字段缺失或类型错误：' . $field);
    }

    private static function stringList(mixed $value, string $field, bool $nonempty = false): void
    {
        if (!is_array($value) || !array_is_list($value) || ($nonempty && $value === [])) throw new UnexpectedValueException('故事大纲列表不完整：' . $field);
        foreach ($value as $entry) {
            if (!is_string($entry) || trim($entry) === '') throw new UnexpectedValueException('故事大纲列表元素类型错误：' . $field);
        }
    }

    private static function objectList(mixed $value, string $field, bool $nonempty = false): void
    {
        if (!is_array($value) || !array_is_list($value) || ($nonempty && $value === [])) throw new UnexpectedValueException('故事大纲对象列表不完整：' . $field);
        foreach ($value as $entry) {
            if (!is_array($entry) || $entry === [] || array_is_list($entry)) throw new UnexpectedValueException('故事大纲对象列表元素类型错误：' . $field);
        }
    }

    private static function namedObjects(mixed $value, string $field): void
    {
        if (!is_array($value) || $value === [] || array_is_list($value)) throw new UnexpectedValueException('故事大纲人物映射不完整：' . $field);
        foreach ($value as $name => $entry) {
            if (!is_string($name) || trim($name) === '' || !is_array($entry)) throw new UnexpectedValueException('故事大纲人物映射类型错误：' . $field);
        }
    }
}
