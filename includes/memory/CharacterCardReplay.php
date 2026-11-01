<?php
defined('APP_LOADED') or die('Direct access denied.');

/** Pure reconstruction of attributed card edits; untraceable legacy facts fail closed. */
final class CharacterCardReplay
{
    public const EVENT = '_card_revision_v1';
    public const FIELDS = ['title', 'status', 'alive', 'attributes', 'voice_profile', 'last_updated_chapter'];

    public static function snapshot(?array $card): ?array
    {
        if ($card === null) return null;
        $state = [];
        foreach (self::FIELDS as $field) {
            $value = $card[$field] ?? null;
            if (in_array($field, ['attributes', 'voice_profile'], true)) {
                if (is_string($value)) $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                $value = is_array($value) ? $value : [];
                ksort($value);
            } elseif ($field === 'alive') {
                $value = (int)(bool)($value ?? 1);
            } elseif ($field === 'last_updated_chapter') {
                $value = (int)$value;
            }
            $state[$field] = $value;
        }
        return $state;
    }

    public static function apply(?array $state, array $updates, int $chapter): array
    {
        $next = $state ?? self::snapshot([]);
        foreach (['title', 'status'] as $field) {
            if (array_key_exists($field, $updates)) {
                $value = trim((string)$updates[$field]);
                $next[$field] = $value === '' ? null : $value;
            }
        }
        if (array_key_exists('alive', $updates)) $next['alive'] = (int)(bool)$updates['alive'];
        foreach (['attributes', 'voice_profile'] as $field) {
            if (isset($updates[$field]) && is_array($updates[$field])) {
                $next[$field] = array_merge($next[$field], $updates[$field]);
                ksort($next[$field]);
            }
        }
        if ($state !== $next) $next['last_updated_chapter'] = $chapter;
        return $next;
    }

    public static function decode(array $row): array
    {
        $event = json_decode((string)$row['new_value'], true, 512, JSON_THROW_ON_ERROR);
        $before = json_decode((string)$row['old_value'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($event) || !in_array($event['source'] ?? '', ['chapter', 'manual', 'baseline'], true)
            || !is_array($event['updates'] ?? null) || !is_array($event['after'] ?? null)
            || !is_array($event['history_ids'] ?? null) || ($before !== null && !is_array($before))) {
            throw new RuntimeException('人物记忆来源记录不完整，请恢复可信人物基线');
        }
        return $event + ['before' => $before, 'id' => (int)$row['id'], 'chapter' => (int)$row['chapter_number']];
    }

    /** @return array{state:?array,removed:array,retained:array} */
    public static function rewind(array $card, array $history, int $from): array
    {
        $events = [];
        $covered = [];
        foreach ($history as $row) {
            if ($row['field_name'] !== self::EVENT) continue;
            $event = self::decode($row);
            $events[] = $event;
            foreach ($event['history_ids'] as $id) $covered[(int)$id] = true;
        }
        foreach ($history as $row) {
            if ($row['field_name'] !== self::EVENT && !isset($covered[(int)$row['id']])
                && (int)$row['chapter_number'] >= $from) {
                self::incomplete((string)$card['name']);
            }
        }
        if ($history === [] && (int)($card['last_updated_chapter'] ?? 0) >= $from) {
            self::incomplete((string)$card['name']);
        }
        $first = null;
        foreach ($events as $index => $event) {
            if ($event['source'] === 'chapter' && $event['chapter'] >= $from) { $first = $index; break; }
        }
        $current = self::snapshot($card);
        if ($first === null) {
            $current['last_updated_chapter'] = min($current['last_updated_chapter'], max(0, $from - 1));
            return ['state' => $current, 'removed' => [], 'retained' => []];
        }
        $expected = self::snapshot($events[count($events) - 1]['after']);
        // Content presence has its own lightweight update and carries no new facts.
        unset($expected['last_updated_chapter'], $current['last_updated_chapter']);
        if ($expected !== $current) self::incomplete((string)$card['name']);

        $state = $events[$first]['before'];
        $removed = [];
        $retained = [];
        foreach (array_slice($events, $first) as $event) {
            $removed = array_merge($removed, $event['history_ids']);
            if ($event['source'] === 'chapter' && $event['chapter'] >= $from) {
                $removed[] = $event['id'];
                continue;
            }
            $event['before'] = $state;
            $state = self::apply($state, $event['updates'], $event['chapter']);
            $event['after'] = $state;
            $retained[] = $event;
        }
        if ($state !== null) $state['last_updated_chapter'] = min($state['last_updated_chapter'], max(0, $from - 1));
        return ['state' => $state, 'removed' => array_values(array_unique($removed)), 'retained' => $retained];
    }

    private static function incomplete(string $name): never
    {
        throw new RuntimeException("人物「{$name}」的旧记忆缺少可靠来源，未完成重建。请在记忆管理中编辑该角色，删除撤稿事实后勾选“确认重放基线”；旧稿独有角色请删除，再重试后处理。");
    }
}
