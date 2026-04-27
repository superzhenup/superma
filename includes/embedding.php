<?php
defined('APP_LOADED') or die('Direct access denied.');

/**
 * ================================================================
 * includes/embedding.php
 *
 * 本文件在 v6 前曾定义 EmbeddingService 类。现已删除：
 * embedding 客户端的唯一来源是 includes/memory/EmbeddingProvider。
 * KnowledgeBase 通过 EmbeddingProvider::embed() 获取向量，通过
 * Vector::pack/unpack/cosine 处理 blob。
 *
 * 保留 KnowledgeBase 类用于：
 *   - knowledge.php 用户手动管理的角色/世界观/情节/风格库
 *   - 章节写入后的自动抽取（extractFromChapter）
 *   - 用户在 knowledge.php 的语义搜索（search）
 * ================================================================
 */

/**
 * ================================================================
 * KnowledgeBase — 智能知识库管理类
 * 封装知识库的存储、检索、更新操作
 *
 * v6 架构调整：
 *   - Embedding 客户端改用 memory/EmbeddingProvider（和 MemoryEngine 共享配置）
 *   - 向量打包/余弦相似度改用 memory/Vector 工具
 *   - 不再需要硬编码豆包模型名，全走 system_settings.global_embedding_model_id
 * ================================================================
 */
class KnowledgeBase {
    private int $novelId;

    public function __construct(int $novelId) {
        // 确保依赖的统一 embedding 基础设施已加载
        require_once __DIR__ . '/memory/EmbeddingProvider.php';
        require_once __DIR__ . '/memory/Vector.php';
        $this->novelId = $novelId;
    }

    /**
     * 检查 Embedding 服务是否可用（统一走 system_settings 配置）
     */
    public function isAvailable(): bool {
        return EmbeddingProvider::getConfig() !== null;
    }
    
    // ================================================================
    // 角色库操作
    // ================================================================
    
    /**
     * 规范化 role_type，将 AI 可能返回的非标准值映射到 ENUM 允许值
     */
    private function normalizeRoleType(string $type): string {
        $map = [
            'main'         => 'protagonist',
            'lead'         => 'protagonist',
            'primary'      => 'protagonist',
            'background'   => 'minor',
            'supporting'   => 'major',
        ];
        return $map[$type] ?? (in_array($type, ['protagonist','major','minor','background']) ? $type : 'minor');
    }

    /**
     * 添加/更新角色
     *
     * @param bool $skipEmbedding  true 时跳过 embedding 写入（供批量场景用，
     *                              稍后用 backfillEmbeddings() 一次性补齐）
     */
    public function saveCharacter(array $data, bool $skipEmbedding = false): int {
        $id = (int)($data['id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));

        // 自动查重：未指定 id 时按 (novel_id, name) 查已存在的角色
        // 避免 extractFromChapter 每次 INSERT 重复角色
        if ($id === 0 && $name !== '') {
            $existing = DB::fetch(
                'SELECT id FROM novel_characters WHERE novel_id=? AND name=? LIMIT 1',
                [$this->novelId, $name]
            );
            if ($existing) {
                $id = (int)$existing['id'];
            }
        }

        $characterData = [
            'novel_id' => $this->novelId,
            'name' => $name,
            'alias' => $data['alias'] ?? '',
            'role_type' => $this->normalizeRoleType($data['role_type'] ?? 'minor'),
            'role_template' => $data['role_template'] ?? 'other',
            'gender' => $data['gender'] ?? '',
            'appearance' => $data['appearance'] ?? null,
            'personality' => $data['personality'] ?? null,
            'background' => $data['background'] ?? null,
            'abilities' => $data['abilities'] ?? null,
            'relationships' => isset($data['relationships']) ? json_encode($data['relationships'], JSON_UNESCAPED_UNICODE) : null,
            'first_appear' => $data['first_appear'] ?? ($data['first_chapter'] ?? null),
            'last_appear' => $data['last_appear'] ?? ($data['climax_chapter'] ?? null),
            'appear_count' => $data['appear_count'] ?? 0,
            'notes' => $data['notes'] ?? null,
        ];

        if ($id > 0) {
            // 更新模式：只覆盖新值非空的字段，避免 null 覆盖已有内容
            // appear_count 单独累加，last_appear 更新
            // 安全：归属校验，防止跨小说污染数据
            $filtered = $this->mergeOnlyNonEmpty($characterData, [
                'novel_id', 'appear_count', 'first_appear', 'last_appear',
            ]);
            $this->incrementCharacterAppearance($id, $data['last_appear'] ?? null);
            if (!empty($filtered)) {
                DB::update('novel_characters', $filtered, 'id=? AND novel_id=?', [$id, $this->novelId]);
            }
            if (!$skipEmbedding) $this->updateCharacterEmbedding($id, $characterData);
            return $id;
        } else {
            $id = (int)DB::insert('novel_characters', $characterData);
            if (!$skipEmbedding) $this->updateCharacterEmbedding($id, $characterData);
            return $id;
        }
    }

    /**
     * 自增角色出场计数，更新最后出场章节
     * 安全：限定 novel_id，避免外部传入的 id 跨小说污染
     */
    private function incrementCharacterAppearance(int $id, $lastAppear): void {
        if ($lastAppear !== null) {
            DB::execute(
                'UPDATE novel_characters SET appear_count = appear_count + 1, last_appear = ? WHERE id=? AND novel_id=?',
                [$lastAppear, $id, $this->novelId]
            );
        } else {
            DB::execute(
                'UPDATE novel_characters SET appear_count = appear_count + 1 WHERE id=? AND novel_id=?',
                [$id, $this->novelId]
            );
        }
    }

    /**
     * 过滤掉空字符串和 null 的字段（用于 UPDATE 时避免覆盖已有数据）
     * @param array $data   原始数据
     * @param array $skipKeys  跳过不处理的字段名（由调用者单独处理）
     */
    private function mergeOnlyNonEmpty(array $data, array $skipKeys = []): array {
        $out = [];
        foreach ($data as $k => $v) {
            if (in_array($k, $skipKeys, true)) continue;
            if ($v === null) continue;
            if (is_string($v) && trim($v) === '') continue;
            $out[$k] = $v;
        }
        return $out;
    }
    
    /**
     * 更新角色的向量
     */
    private function updateCharacterEmbedding(int $characterId, array $data): void {
        // 构建用于向量化的文本
        $text = implode("\n", array_filter([
            "角色名：" . ($data['name'] ?? ''),
            $data['alias'] ? "别名：" . $data['alias'] : '',
            $data['appearance'] ? "外貌：" . $data['appearance'] : '',
            $data['personality'] ? "性格：" . $data['personality'] : '',
            $data['background'] ? "背景：" . $data['background'] : '',
            $data['abilities'] ? "能力：" . $data['abilities'] : '',
        ]));
        
        $this->storeEmbedding('character', $characterId, $text);
    }
    
    /**
     * 获取角色列表
     */
    public function getCharacters(?string $roleType = null): array {
        $sql = 'SELECT * FROM novel_characters WHERE novel_id=?';
        $params = [$this->novelId];
        
        if ($roleType) {
            $sql .= ' AND role_type=?';
            $params[] = $roleType;
        }
        
        $sql .= ' ORDER BY role_type, name';
        
        return DB::fetchAll($sql, $params);
    }
    
    /**
     * 删除角色
     */
    public function deleteCharacter(int $id): bool {
        DB::delete('novel_characters', 'id=? AND novel_id=?', [$id, $this->novelId]);
        DB::delete('novel_embeddings', 'source_type=? AND source_id=?', ['character', $id]);
        return true;
    }
    
    // ================================================================
    // 世界观库操作
    // ================================================================
    
    /**
     * 规范化 worldbuilding category，将 AI 返回的非标准值映射到 ENUM 允许值
     * ENUM: location / faction / rule / item / other
     */
    private function normalizeWorldCategory(string $cat): string {
        $map = [
            'place'       => 'location',
            'location'    => 'location',
            'area'        => 'location',
            'region'      => 'location',
            'country'     => 'location',
            'city'        => 'location',
            'faction'     => 'faction',
            'group'       => 'faction',
            'organization'=> 'faction',
            'sect'        => 'faction',
            'clan'        => 'faction',
            'power'       => 'faction',
            'rule'        => 'rule',
            'law'         => 'rule',
            'system'      => 'rule',
            'magic'       => 'rule',
            'cultivation' => 'rule',
            'mechanic'    => 'rule',
            'item'        => 'item',
            'weapon'      => 'item',
            'artifact'    => 'item',
            'treasure'    => 'item',
            'equipment'   => 'item',
            'culture'     => 'other',
            'history'     => 'other',
            'concept'     => 'other',
            'custom'      => 'other',
            'race'        => 'other',
            'species'     => 'other',
        ];
        return $map[$cat] ?? (in_array($cat, ['location','faction','rule','item','other']) ? $cat : 'other');
    }

    public function saveWorldbuilding(array $data, bool $skipEmbedding = false): int {
        $id = (int)($data['id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));

        // 按 (novel_id, name, category) 查重，避免 extractFromChapter 产生重复
        if ($id === 0 && $name !== '') {
            $cat = $this->normalizeWorldCategory($data['category'] ?? 'other');
            $existing = DB::fetch(
                'SELECT id FROM novel_worldbuilding WHERE novel_id=? AND name=? AND category=? LIMIT 1',
                [$this->novelId, $name, $cat]
            );
            if ($existing) {
                $id = (int)$existing['id'];
            }
        }

        $wbData = [
            'novel_id' => $this->novelId,
            'category' => $this->normalizeWorldCategory($data['category'] ?? 'other'),
            'name' => $name,
            'description' => $data['description'] ?? null,
            'attributes' => isset($data['attributes']) ? json_encode($data['attributes'], JSON_UNESCAPED_UNICODE) : null,
            'related_chapters' => isset($data['related_chapters']) ? json_encode($data['related_chapters'], JSON_UNESCAPED_UNICODE) : null,
            'importance' => $data['importance'] ?? 1,
            'notes' => $data['notes'] ?? null,
        ];

        if ($id > 0) {
            // 只覆盖非空字段，保留已有数据
            // 安全：归属校验，防止跨小说污染数据
            $filtered = $this->mergeOnlyNonEmpty($wbData, ['novel_id']);
            if (!empty($filtered)) {
                DB::update('novel_worldbuilding', $filtered, 'id=? AND novel_id=?', [$id, $this->novelId]);
            }
            if (!$skipEmbedding) $this->updateWorldbuildingEmbedding($id, $wbData);
            return $id;
        } else {
            $id = (int)DB::insert('novel_worldbuilding', $wbData);
            if (!$skipEmbedding) $this->updateWorldbuildingEmbedding($id, $wbData);
            return $id;
        }
    }
    
    private function updateWorldbuildingEmbedding(int $id, array $data): void {
        $categoryNames = [
            'location' => '地点',
            'faction' => '势力',
            'rule' => '规则',
            'item' => '物品',
            'other' => '其他',
        ];
        
        $text = implode("\n", array_filter([
            ($categoryNames[$data['category'] ?? 'other'] ?? '其他') . "：" . ($data['name'] ?? ''),
            $data['description'] ?? '',
        ]));
        
        $this->storeEmbedding('worldbuilding', $id, $text);
    }
    
    public function getWorldbuilding(?string $category = null): array {
        $sql = 'SELECT * FROM novel_worldbuilding WHERE novel_id=?';
        $params = [$this->novelId];
        
        if ($category) {
            $sql .= ' AND category=?';
            $params[] = $category;
        }
        
        $sql .= ' ORDER BY importance DESC, name';
        
        return DB::fetchAll($sql, $params);
    }
    
    public function deleteWorldbuilding(int $id): bool {
        DB::delete('novel_worldbuilding', 'id=? AND novel_id=?', [$id, $this->novelId]);
        DB::delete('novel_embeddings', 'source_type=? AND source_id=?', ['worldbuilding', $id]);
        return true;
    }
    
    // ================================================================
    // 情节库操作
    // ================================================================
    
    /**
     * 规范化 event_type，将 AI 返回的非标准值映射到 ENUM 允许值
     * ENUM: main / side / foreshadowing / callback
     */
    private function normalizeEventType(string $type): string {
        $map = [
            'sub'       => 'subplot',
            'minor'     => 'subplot',
            'secondary' => 'subplot',
            'side'      => 'subplot',
            'climax'    => 'main',
            'major'     => 'main',
            'payoff'    => 'callback',
            'resolve'   => 'callback',
            'resolution'=> 'callback',
        ];
        return $map[$type] ?? (in_array($type, ['main','subplot','foreshadowing','callback','other']) ? $type : 'main');
    }

    public function savePlot(array $data, bool $skipEmbedding = false): int {
        $id = (int)($data['id'] ?? 0);
        $title = trim((string)($data['title'] ?? ''));

        // 按 (novel_id, title, event_type) 查重
        if ($id === 0 && $title !== '') {
            $eventType = $this->normalizeEventType($data['event_type'] ?? 'main');
            $existing = DB::fetch(
                'SELECT id FROM novel_plots WHERE novel_id=? AND title=? AND event_type=? LIMIT 1',
                [$this->novelId, $title, $eventType]
            );
            if ($existing) {
                $id = (int)$existing['id'];
            }
        }

        $plotData = [
            'novel_id' => $this->novelId,
            'chapter_from' => $data['chapter_from'] ?? 1,
            'chapter_to' => $data['chapter_to'] ?? null,
            'event_type' => $this->normalizeEventType($data['event_type'] ?? 'main'),
            'foreshadow_type' => $data['foreshadow_type'] ?? null,
            'expected_payoff' => $data['expected_payoff'] ?? null,
            'deadline_chapter' => $data['deadline_chapter'] ?? null,
            'title' => $title,
            'description' => $data['description'] ?? null,
            'characters' => isset($data['characters']) ? json_encode($data['characters'], JSON_UNESCAPED_UNICODE) : null,
            'status' => $data['status'] ?? 'active',
            'importance' => $data['importance'] ?? 3,
            'notes' => $data['notes'] ?? null,
        ];

        if ($id > 0) {
            // 只覆盖非空字段；特别注意 chapter_from 不要被默认值 1 覆盖
            // 安全：归属校验，防止跨小说污染数据
            $filtered = $this->mergeOnlyNonEmpty($plotData, ['novel_id', 'chapter_from']);
            if (!empty($filtered)) {
                DB::update('novel_plots', $filtered, 'id=? AND novel_id=?', [$id, $this->novelId]);
            }
            if (!$skipEmbedding) $this->updatePlotEmbedding($id, $plotData);
            return $id;
        } else {
            $id = (int)DB::insert('novel_plots', $plotData);
            if (!$skipEmbedding) $this->updatePlotEmbedding($id, $plotData);
            return $id;
        }
    }
    
    private function updatePlotEmbedding(int $id, array $data): void {
        $text = implode("\n", array_filter([
            "第" . ($data['chapter_from'] ?? 1) . "章：" . ($data['title'] ?? ''),
            $data['description'] ?? '',
        ]));
        
        $this->storeEmbedding('plot', $id, $text);
    }
    
    public function getPlots(?string $eventType = null, ?string $status = null): array {
        $sql = 'SELECT * FROM novel_plots WHERE novel_id=?';
        $params = [$this->novelId];
        
        if ($eventType) {
            $sql .= ' AND event_type=?';
            $params[] = $eventType;
        }
        
        if ($status) {
            $sql .= ' AND status=?';
            $params[] = $status;
        }
        
        $sql .= ' ORDER BY chapter_from, importance DESC';
        
        return DB::fetchAll($sql, $params);
    }
    
    /**
     * 获取未回收的伏笔
     */
    public function getActiveForeshadowing(): array {
        return $this->getPlots('foreshadowing', 'active');
    }
    
    public function deletePlot(int $id): bool {
        DB::delete('novel_plots', 'id=? AND novel_id=?', [$id, $this->novelId]);
        DB::delete('novel_embeddings', 'source_type=? AND source_id=?', ['plot', $id]);
        return true;
    }
    
    // ================================================================
    // 风格库操作
    // ================================================================
    
    /**
     * 规范化 style category，将 AI 返回的非标准值映射到 ENUM 允许值
     * ENUM: narrative / dialogue / description / emotion / other
     */
    private function normalizeStyleCategory(string $cat): string {
        $map = [
            'metaphor'    => 'narrative',
            'pacing'      => 'narrative',
            'rhetoric'    => 'narrative',
            'narration'   => 'narrative',
            'dialog'      => 'dialogue',
            'conversation'=> 'dialogue',
            'speech'      => 'dialogue',
            'scene'       => 'description',
            'imagery'     => 'description',
            'atmosphere'  => 'description',
            'mood'        => 'emotion',
            'tone'        => 'emotion',
            'sentiment'   => 'emotion',
        ];
        return $map[$cat] ?? (in_array($cat, ['narrative','dialogue','description','emotion','other']) ? $cat : 'other');
    }

    public function saveStyle(array $data): int {
        $id = (int)($data['id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));

        // 按 (novel_id, name, category) 查重
        if ($id === 0 && $name !== '') {
            $cat = $this->normalizeStyleCategory($data['category'] ?? 'other');
            $existing = DB::fetch(
                'SELECT id FROM novel_style WHERE novel_id=? AND name=? AND category=? LIMIT 1',
                [$this->novelId, $name, $cat]
            );
            if ($existing) {
                $id = (int)$existing['id'];
            }
        }

        $styleData = [
            'novel_id' => $this->novelId,
            'category' => $this->normalizeStyleCategory($data['category'] ?? 'other'),
            'name' => $name,
            'content' => $data['content'] ?? null,
            'vec_style' => $data['vec_style'] ?? null,
            'vec_pacing' => $data['vec_pacing'] ?? null,
            'vec_emotion' => $data['vec_emotion'] ?? null,
            'vec_intellect' => $data['vec_intellect'] ?? null,
            'ref_author' => $data['ref_author'] ?? null,
            'keywords' => $data['keywords'] ?? null,
            'examples' => isset($data['examples']) ? json_encode($data['examples'], JSON_UNESCAPED_UNICODE) : null,
            'usage_count' => $data['usage_count'] ?? 0,
            'notes' => $data['notes'] ?? null,
        ];

        if ($id > 0) {
            // 安全：归属校验，防止跨小说污染数据
            $filtered = $this->mergeOnlyNonEmpty($styleData, ['novel_id', 'usage_count']);
            if (!empty($filtered)) {
                DB::update('novel_style', $filtered, 'id=? AND novel_id=?', [$id, $this->novelId]);
            }
            $this->updateStyleEmbedding($id, $styleData);
            return $id;
        } else {
            $id = (int)DB::insert('novel_style', $styleData);
            $this->updateStyleEmbedding($id, $styleData);
            return $id;
        }
    }
    
    private function updateStyleEmbedding(int $id, array $data): void {
        $vecParts = [];
        if (!empty($data['vec_style']))   $vecParts[] = '文风：' . $data['vec_style'];
        if (!empty($data['vec_pacing']))  $vecParts[] = '节奏：' . $data['vec_pacing'];
        if (!empty($data['vec_emotion'])) $vecParts[] = '情感：' . $data['vec_emotion'];
        if (!empty($data['vec_intellect']))$vecParts[] = '智慧：' . $data['vec_intellect'];
        if (!empty($data['ref_author']))  $vecParts[] = '参考：' . $data['ref_author'];
        if (!empty($data['keywords']))    $vecParts[] = '高频词：' . $data['keywords'];

        $text = implode("\n", array_filter([
            $data['name'] ?? '',
            $data['content'] ?? '',
            implode("\n", $vecParts),
        ]));

        $this->storeEmbedding('style', $id, $text);
    }
    
    public function getStyles(?string $category = null): array {
        $sql = 'SELECT * FROM novel_style WHERE novel_id=?';
        $params = [$this->novelId];
        
        if ($category) {
            $sql .= ' AND category=?';
            $params[] = $category;
        }
        
        $sql .= ' ORDER BY usage_count DESC, name';
        
        return DB::fetchAll($sql, $params);
    }
    
    public function deleteStyle(int $id): bool {
        DB::delete('novel_style', 'id=? AND novel_id=?', [$id, $this->novelId]);
        DB::delete('novel_embeddings', 'source_type=? AND source_id=?', ['style', $id]);
        return true;
    }
    
    // ================================================================
    // 向量存储与检索
    // ================================================================
    
    /**
     * 存储向量
     */
    /**
     * 存储向量
     *
     * 注意 v6：向量打包从 pack('f*') 改为 pack('g*')（Vector::pack）。
     * 在 x86/ARM 下两者结果相同（都是 little-endian float32），跨架构时
     * 'g*' 更稳。如果数据库里存在旧版（'f*'）数据且跨架构迁移，需重建。
     */
    private function storeEmbedding(string $sourceType, int $sourceId, string $content): bool {
        if (EmbeddingProvider::getConfig() === null) {
            return false;
        }

        $result = EmbeddingProvider::embed($content);
        if (!$result || empty($result['vec'])) {
            return false;
        }

        $binary = Vector::pack($result['vec']);
        $modelName = $result['model'] ?? '';

        // 使用 REPLACE INTO 实现插入或更新
        $sql = "REPLACE INTO novel_embeddings (novel_id, source_type, source_id, content, embedding_blob, embedding_model)
                VALUES (?, ?, ?, ?, ?, ?)";

        DB::query($sql, [
            $this->novelId,
            $sourceType,
            $sourceId,
            $content,
            $binary,
            $modelName,
        ]);

        return true;
    }
    
    /**
     * 语义检索 - 根据查询文本返回最相关的知识
     * 
     * @param string $query 查询文本
     * @param array $sourceTypes 要检索的知识类型 ['character', 'worldbuilding', 'plot', 'style']
     * @param int $limit 每种类型返回的最大数量
     * @param float $threshold 相似度阈值 (0-1)
     * @return array 按类型分组的检索结果
     */
    public function search(string $query, array $sourceTypes = [], int $limit = 5, float $threshold = 0.5): array {
        if (EmbeddingProvider::getConfig() === null) {
            return [];
        }

        $qEmb = EmbeddingProvider::embed($query);
        if (!$qEmb || empty($qEmb['vec'])) {
            return [];
        }
        $queryVector = $qEmb['vec'];

        // 默认检索所有类型
        if (empty($sourceTypes)) {
            $sourceTypes = ['character', 'worldbuilding', 'plot', 'style'];
        }

        $results = [];

        foreach ($sourceTypes as $type) {
            // 获取该类型的所有向量
            $embeddings = DB::fetchAll(
                "SELECT e.source_id, e.content, e.embedding_blob
                 FROM novel_embeddings e
                 WHERE e.novel_id=? AND e.source_type=?",
                [$this->novelId, $type]
            );

            $scored = [];

            foreach ($embeddings as $emb) {
                try {
                    $vector = Vector::unpack($emb['embedding_blob']);
                } catch (\Throwable $e) {
                    continue; // 损坏的 blob 跳过
                }

                if (empty($vector)) {
                    continue;
                }

                $similarity = Vector::cosine($queryVector, $vector);

                if ($similarity >= $threshold) {
                    $scored[] = [
                        'id' => $emb['source_id'],
                        'content' => $emb['content'],
                        'similarity' => $similarity,
                    ];
                }
            }

            // 按相似度排序，取前 N 个
            usort($scored, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
            $results[$type] = array_slice($scored, 0, $limit);
        }

        return $results;
    }
    
    /**
     * 获取写作上下文 - 根据当前章节内容检索相关知识
     * 
     * @param int $chapterNumber 当前章节号
     * @param string $chapterOutline 章节大纲/概要
     * @return string 格式化的上下文文本
     */
    public function getWritingContext(int $chapterNumber, string $chapterOutline): string {
        if (EmbeddingProvider::getConfig() === null) {
            return '';
        }
        
        // 构建查询文本
        $query = "第{$chapterNumber}章：" . $chapterOutline;
        
        // 检索相关知识
        $results = $this->search($query, ['character', 'worldbuilding', 'plot', 'style'], 3, 0.4);
        
        if (empty($results) || empty(array_filter($results))) {
            return '';
        }
        
        $context = [];
        
        // 角色信息
        if (!empty($results['character'])) {
            $context[] = "【相关角色】";
            foreach ($results['character'] as $item) {
                $context[] = $item['content'];
            }
        }
        
        // 世界观信息
        if (!empty($results['worldbuilding'])) {
            $context[] = "\n【相关设定】";
            foreach ($results['worldbuilding'] as $item) {
                $context[] = $item['content'];
            }
        }
        
        // 情节信息
        if (!empty($results['plot'])) {
            $context[] = "\n【相关情节】";
            foreach ($results['plot'] as $item) {
                $context[] = $item['content'];
            }
        }
        
        // 风格偏好
        if (!empty($results['style'])) {
            $context[] = "\n【写作风格参考】";
            foreach ($results['style'] as $item) {
                $context[] = $item['content'];
            }
        }
        
        // 添加未回收的伏笔提醒
        $foreshadowing = $this->getActiveForeshadowing();
        if (!empty($foreshadowing)) {
            $relevantFs = array_filter($foreshadowing, fn($f) => $f['chapter_from'] < $chapterNumber);
            if (!empty($relevantFs)) {
                $context[] = "\n【待回收伏笔提醒】";
                foreach (array_slice($relevantFs, 0, 5) as $fs) {
                    $context[] = "第{$fs['chapter_from']}章：{$fs['title']} - {$fs['description']}";
                }
            }
        }
        
        return implode("\n", $context);
    }
    
    /**
     * 自动从章节内容提取知识并存储
     * 
     * @param int $chapterNumber 章节号
     * @param string $content 章节内容
     * @return array 提取的知识条目统计
     */
    public function extractFromChapter(int $chapterNumber, string $content): array {
        $stats = [
            'characters' => 0,
            'worldbuilding' => 0,
            'plots' => 0,
            'styles' => 0,
        ];
        
        // 构建提取提示词
        $prompt = $this->buildExtractionPrompt($content, $chapterNumber);
        
        // 调用 AI 提取知识
        $extracted = $this->callAIForExtraction($prompt);
        
        if (empty($extracted)) {
            return $stats;
        }
        
        // 处理提取的角色
        if (!empty($extracted['characters'])) {
            foreach ($extracted['characters'] as $char) {
                try {
                    $this->saveCharacter([
                        'name' => $char['name'] ?? '',
                        'alias' => $char['alias'] ?? '',
                        'role_type' => $char['role_type'] ?? 'minor',
                        'gender' => $char['gender'] ?? '',
                        'appearance' => $char['appearance'] ?? null,
                        'personality' => $char['personality'] ?? null,
                        'background' => $char['background'] ?? null,
                        'first_appear' => $chapterNumber,
                        'last_appear' => $chapterNumber,
                        'appear_count' => 1,
                    ]);
                    $stats['characters']++;
                } catch (\Throwable $e) {
                    error_log("extractFromChapter saveCharacter failed: " . $e->getMessage());
                }
            }
        }
        
        // 处理提取的世界观
        if (!empty($extracted['worldbuilding'])) {
            foreach ($extracted['worldbuilding'] as $wb) {
                try {
                    $this->saveWorldbuilding([
                        'name' => $wb['name'] ?? '',
                        'category' => $wb['category'] ?? 'other',
                        'description' => $wb['description'] ?? null,
                        'importance' => $wb['importance'] ?? 3,
                    ]);
                    $stats['worldbuilding']++;
                } catch (\Throwable $e) {
                    error_log("extractFromChapter saveWorldbuilding failed: " . $e->getMessage() . " category=" . ($wb['category'] ?? 'null'));
                }
            }
        }
        
        // 处理提取的情节
        if (!empty($extracted['plots'])) {
            foreach ($extracted['plots'] as $plot) {
                try {
                    $this->savePlot([
                        'chapter_from' => $chapterNumber,
                        'title' => $plot['title'] ?? '',
                        'description' => $plot['description'] ?? null,
                        'event_type' => $plot['event_type'] ?? 'main',
                        'importance' => $plot['importance'] ?? 3,
                        'status' => 'active',
                    ]);
                    $stats['plots']++;
                } catch (\Throwable $e) {
                    error_log("extractFromChapter savePlot failed: " . $e->getMessage() . " event_type=" . ($plot['event_type'] ?? 'null'));
                }
            }
        }
        
        // 处理提取的风格
        if (!empty($extracted['styles'])) {
            foreach ($extracted['styles'] as $style) {
                try {
                    $this->saveStyle([
                        'name' => $style['name'] ?? '',
                        'category' => $style['category'] ?? 'other',
                        'content' => $style['content'] ?? null,
                    ]);
                    $stats['styles']++;
                } catch (\Throwable $e) {
                    error_log("extractFromChapter saveStyle failed: " . $e->getMessage() . " category=" . ($style['category'] ?? 'null'));
                }
            }
        }
        
        // 存储章节摘要向量（备用）
        $summary = mb_substr($content, 0, 500);
        $this->storeEmbedding('chapter', $chapterNumber, $summary);
        
        return $stats;
    }
    
    /**
     * 构建知识提取提示词
     */
    private function buildExtractionPrompt(string $content, int $chapterNumber): string {
        $contentPreview = mb_substr($content, 0, 3000);
        
        return <<<PROMPT
你是一个小说创作助手。请从以下章节内容中提取知识条目，以JSON格式返回：

章节内容（第{$chapterNumber}章）：
{$contentPreview}

请提取以下类型的知识：

1. **角色** - 出现的新人物或已知人物的详细信息：
   - name: 角色名
   - alias: 别名/绰号
   - role_type: 角色类型（必须是以下之一：protagonist/major/minor/background）
   - gender: 性别
   - appearance: 外貌特征
   - personality: 性格特点
   - background: 背景故事

2. **世界观设定** - 新的地点、势力、规则、物品等：
   - name: 名称
   - category: 类别（必须是以下之一：location/faction/rule/item/other）
   - description: 描述
   - importance: 重要程度（1-5）

3. **情节线索** - 重要事件、伏笔、发展：
   - title: 标题
   - description: 描述
   - event_type: 事件类型（必须是以下之一：main/subplot/foreshadowing/callback/other）
   - importance: 重要程度（1-5）

4. **写作风格** - 值得记录的表达方式、修辞手法：
   - name: 名称
   - category: 类别（必须是以下之一：narrative/dialogue/description/emotion/other）
   - content: 内容/示例

请返回以下JSON格式：
{
  "characters": [],
  "worldbuilding": [],
  "plots": [],
  "styles": []
}

如果某类别没有提取到内容，返回空数组。
PROMPT;
    }
    
    /**
     * 调用 AI 进行知识提取
     * 使用项目统一的 getAIClient()（默认模型），不再硬编码 qwen-plus
     */
    private function callAIForExtraction(string $prompt): array {
        try {
            require_once __DIR__ . '/ai.php';
            $ai = getAIClient(null);  // 用默认模型

            $raw = $ai->chat([
                ['role' => 'system', 'content' => '你是一个小说创作助手，擅长提取和分析小说中的知识要素。'],
                ['role' => 'user',   'content' => $prompt],
            ], 'structured');

            $content = trim($raw);
            // 提取 JSON 部分（兼容 markdown 代码块包裹）
            if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $content, $m)) {
                $content = trim($m[1]);
            }
            if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
                $json = json_decode($matches[0], true);
                if (is_array($json)) {
                    return $json;
                }
            }

            return [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}