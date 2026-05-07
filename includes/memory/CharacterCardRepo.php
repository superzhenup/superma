<?php
defined('APP_LOADED') or die('Direct access denied.');

/**
 * ================================================================
 * CharacterCardRepo — 人物状态卡片仓储
 *
 * 替代原 novels.character_states JSON 字段。
 * 提供:upsert(覆写式更新)、list、getByName,以及变更自动入 history。
 * ================================================================
 */
final class CharacterCardRepo
{
    private int $novelId;

    public function __construct(int $novelId)
    {
        $this->novelId = $novelId;
    }

    /**
     * 列出当前小说所有角色卡片。
     * $onlyAlive=true 只返回存活的(章节 prompt 一般只需要活着的)
     */
    public function listAll(bool $onlyAlive = false): array
    {
        $sql = 'SELECT * FROM character_cards WHERE novel_id=?';
        $params = [$this->novelId];
        if ($onlyAlive) {
            $sql .= ' AND alive=1';
        }
        $sql .= ' ORDER BY name ASC';
        $rows = DB::fetchAll($sql, $params);
        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * 根据人物名查一张卡,不存在返回 null。
     */
    public function getByName(string $name): ?array
    {
        $row = DB::fetch(
            'SELECT * FROM character_cards WHERE novel_id=? AND name=? LIMIT 1',
            [$this->novelId, $name]
        );
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * 获取所有有 voice_profile 的角色卡片（用于写作时 prompt 注入）
     */
    public function listWithVoiceProfile(): array
    {
        $rows = DB::fetchAll(
            'SELECT name, voice_profile FROM character_cards WHERE novel_id=? AND alive=1 AND voice_profile IS NOT NULL',
            [$this->novelId]
        );
        $result = [];
        foreach ($rows as $row) {
            $vp = json_decode($row['voice_profile'], true);
            if ($vp) {
                $result[$row['name']] = $vp;
            }
        }
        return $result;
    }

    /**
     * upsert:插入或更新一张卡片,并自动把变化写入 history。
     *
     * @param string $name        人物名(唯一键)
     * @param array  $updates     要变更的字段:title/status/alive/attributes
     *                            ❗只传需要变更的键,未传的键保持原值
     * @param int    $chapterNum  本次更新是哪一章触发的
     * @return int                card_id
     */
    public function upsert(string $name, array $updates, int $chapterNum): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('character name is empty');
        }

        $existing = DB::fetch(
            'SELECT * FROM character_cards WHERE novel_id=? AND name=?',
            [$this->novelId, $name]
        );

        if (!$existing) {
            // 新建
            // [修复] attributes 入库前先按 key 排序，保证后续比对一致
            $newAttrs = null;
            if (isset($updates['attributes']) && is_array($updates['attributes'])) {
                $sorted = $updates['attributes'];
                ksort($sorted);
                $newAttrs = json_encode($sorted, JSON_UNESCAPED_UNICODE);
            }
            $cardId = (int)DB::insert('character_cards', [
                'novel_id'             => $this->novelId,
                'name'                 => $name,
                'title'                => self::normalizeString($updates['title'] ?? null),
                'status'               => self::normalizeString($updates['status'] ?? null),
                'alive'                => isset($updates['alive']) ? (int)(bool)$updates['alive'] : 1,
                'attributes'           => $newAttrs,
                'voice_profile'        => isset($updates['voice_profile']) ? json_encode($updates['voice_profile'], JSON_UNESCAPED_UNICODE) : null,
                'last_updated_chapter' => $chapterNum,
            ]);

            // 把"创建"当作 history 的首条
            $this->logHistory($cardId, $chapterNum, 'created', null, "$name 首次登场");
            return $cardId;
        }

        // 已存在 → 计算实际变化,只更新有变化的字段
        $cardId = (int)$existing['id'];
        $changes = [];
        $newData = [];

        foreach (['title', 'status'] as $f) {
            if (array_key_exists($f, $updates)) {
                $old = $existing[$f];
                $new = self::normalizeString($updates[$f]);
                if ($old !== $new) {
                    $changes[$f] = [$old, $new];
                    $newData[$f] = $new;
                }
            }
        }

        if (array_key_exists('alive', $updates)) {
            $old = (int)$existing['alive'];
            $new = (int)(bool)$updates['alive'];
            if ($old !== $new) {
                $changes['alive'] = [$old, $new];
                $newData['alive'] = $new;
            }
        }

        if (array_key_exists('attributes', $updates) && is_array($updates['attributes'])) {
            $oldAttrs = json_decode($existing['attributes'] ?? '{}', true) ?: [];
            // merge 模式:新 attributes 覆盖旧的同名键,其他键保留
            $mergedAttrs = array_merge($oldAttrs, $updates['attributes']);
            // [修复] 比对前按 key 排序,避免 json_encode 因键序不同产生假阳性变更
            // 例如旧 {"hp":1,"mp":2} vs 新 {"mp":2,"hp":1} 本质相同但字符串不等。
            $oldSorted = $oldAttrs;
            $newSorted = $mergedAttrs;
            ksort($oldSorted);
            ksort($newSorted);
            $oldJson = json_encode($oldSorted, JSON_UNESCAPED_UNICODE);
            $newJson = json_encode($newSorted, JSON_UNESCAPED_UNICODE);
            if ($oldJson !== $newJson) {
                $changes['attributes'] = [$oldJson, $newJson];
                // 存储时也存排序后的，保证后续比对一致
                $newData['attributes'] = $newJson;
            }
        }

        if (array_key_exists('voice_profile', $updates) && is_array($updates['voice_profile'])) {
            $oldVP = json_decode($existing['voice_profile'] ?? '{}', true) ?: [];
            $mergedVP = array_merge($oldVP, $updates['voice_profile']);
            $oldVPSorted = $oldVP;
            $newVPSorted = $mergedVP;
            ksort($oldVPSorted);
            ksort($newVPSorted);
            $oldVPJson = json_encode($oldVPSorted, JSON_UNESCAPED_UNICODE);
            $newVPJson = json_encode($newVPSorted, JSON_UNESCAPED_UNICODE);
            if ($oldVPJson !== $newVPJson) {
                $changes['voice_profile'] = [$oldVPJson, $newVPJson];
                $newData['voice_profile'] = $newVPJson;
            }
        }

        if (!empty($newData)) {
            $newData['last_updated_chapter'] = $chapterNum;
            DB::update('character_cards', $newData, 'id=?', [$cardId]);

            foreach ($changes as $field => [$old, $new]) {
                $this->logHistory(
                    $cardId, $chapterNum, $field,
                    is_scalar($old) ? (string)$old : json_encode($old, JSON_UNESCAPED_UNICODE),
                    is_scalar($new) ? (string)$new : json_encode($new, JSON_UNESCAPED_UNICODE)
                );
            }
        }

        return $cardId;
    }

    /**
     * 根据 card_id 查一张卡（管理面板按 id 操作常用）
     */
    public function getById(int $cardId): ?array
    {
        $row = DB::fetch(
            'SELECT * FROM character_cards WHERE id=? AND novel_id=? LIMIT 1',
            [$cardId, $this->novelId]
        );
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * 按 card_id 删除一张卡片 + 其变更历史（级联）
     * 返回 true 表示确实删掉了一行。
     */
    public function delete(int $cardId): bool
    {
        // 先校验归属，避免跨 novel 误删
        $card = DB::fetch(
            'SELECT id FROM character_cards WHERE id=? AND novel_id=?',
            [$cardId, $this->novelId]
        );
        if (!$card) return false;

        // 先清 history（表定义没有 FK 级联，这里显式清理）
        DB::execute(
            'DELETE FROM character_card_history WHERE card_id=?',
            [$cardId]
        );
        // 再删卡片本体
        $affected = DB::execute(
            'DELETE FROM character_cards WHERE id=? AND novel_id=?',
            [$cardId, $this->novelId]
        );
        return $affected > 0;
    }

    /**
     * 标记人物死亡(常用快捷方式)
     */
    public function markDeceased(string $name, int $chapterNum, ?string $reason = null): int
    {
        $updates = ['alive' => 0];
        if ($reason !== null) {
            $updates['status'] = $reason;
        }
        return $this->upsert($name, $updates, $chapterNum);
    }

    /**
     * 获取某人物的变更历史
     */
    public function getHistory(string $name, int $limit = 50): array
    {
        $card = $this->getByName($name);
        if (!$card) return [];
        return $this->getHistoryByCardId((int)$card['id'], $limit);
    }

    /**
     * 按 card_id 直接取历史（管理面板按 id 来）
     */
    public function getHistoryById(int $cardId, int $limit = 50): array
    {
        // 先校验归属
        $own = DB::fetch(
            'SELECT id FROM character_cards WHERE id=? AND novel_id=?',
            [$cardId, $this->novelId]
        );
        if (!$own) return [];
        return $this->getHistoryByCardId($cardId, $limit);
    }

    private function getHistoryByCardId(int $cardId, int $limit): array
    {
        return DB::fetchAll(
            'SELECT chapter_number, field_name, old_value, new_value, created_at
             FROM character_card_history
             WHERE card_id=? ORDER BY chapter_number DESC, id DESC LIMIT ' . (int)$limit,
            [$cardId]
        );
    }

    /**
     * 整批导入(迁移脚本用)。不经过变化检查,直接覆盖。
     */
    public function bulkImport(array $cards, int $fromChapter = 0): int
    {
        $n = 0;
        foreach ($cards as $name => $data) {
            if (!is_string($name) || trim($name) === '') continue;
            if (!is_array($data)) continue;
            $this->upsert($name, $data, $fromChapter);
            $n++;
        }
        return $n;
    }

    // ---------- 内部辅助 ----------

    private function logHistory(int $cardId, int $chapter, string $field, ?string $old, ?string $new): void
    {
        DB::insert('character_card_history', [
            'card_id'        => $cardId,
            'chapter_number' => $chapter,
            'field_name'     => $field,
            'old_value'      => $old,
            'new_value'      => $new,
        ]);
    }

    private function hydrate(array $row): array
    {
        if (!empty($row['attributes'])) {
            $row['attributes'] = json_decode($row['attributes'], true) ?: [];
        } else {
            $row['attributes'] = [];
        }
        if (!empty($row['voice_profile'])) {
            $row['voice_profile'] = json_decode($row['voice_profile'], true) ?: [];
        } else {
            $row['voice_profile'] = [];
        }
        $row['alive'] = (int)$row['alive'] === 1;
        return $row;
    }

    private static function normalizeString($v): ?string
    {
        if ($v === null) return null;
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }
}
