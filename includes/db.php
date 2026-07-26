<?php
defined('APP_LOADED') or die('Direct access denied.');

// 审计优化 P3-6（2026-06-16）：加载迁移辅助工具类
require_once __DIR__ . '/db/SchemaMigrator.php';
// 审计优化 P1（2026-07-01）：migrate() 职责拆分到独立类
require_once __DIR__ . '/db/migrations/ColumnMigrations.php';
require_once __DIR__ . '/db/migrations/EnumMigrations.php';
require_once __DIR__ . '/db/migrations/SchemaEvolutionMigrations.php';
require_once __DIR__ . '/db/migrations/IndexMigrations.php';
require_once __DIR__ . '/db/migrations/ForeignKeyMigrations.php';

class DB {
    private static ?PDO $pdo = null;

    /**
     * 允许的表名白名单（防止表名注入）
     *
     * v1.5.2: 改为从 Schema::whitelist() 派生为单一真理源。
     * 保留 ALLOWED_TABLES 常量作为兜底——当 Schema 类不可用时（极少见）
     * 仍能保护数据库。新增表时只需在 Schema::tables() 一处添加。
     */
    private const ALLOWED_TABLES = [
        'novels', 'novel_settings', 'chapters', 'chapter_versions', 'chapter_synopses',
        'ai_models', 'system_settings', 'novel_characters', 'novel_worldbuilding',
        'novel_embeddings', 'character_cards', 'character_card_history',
        'foreshadowing_items', 'novel_state', 'memory_atoms', 'bible_nodes', 'story_relations',
        'arc_summaries', 'story_outlines', 'volume_outlines',
        'consistency_logs', 'writing_logs', 'novel_plots', 'novel_style',
        // v1.4: Agent 体系表 + 书籍分析表
        'agent_decision_logs', 'agent_action_logs',
        'agent_directives', 'book_analyses',
        // v1.5: Agent 反馈闭环
        'agent_directive_outcomes', 'agent_performance_stats',
        // v1.3.5: 约束框架
        'constraint_state', 'constraint_logs',
        // v1.7: 作者画像系统表
        'author_profiles', 'author_writing_habits', 'author_narrative_styles',
        'author_sentiment_analysis', 'author_creative_identity', 'author_uploaded_works',
        // v1.1: 迭代改进设置表
        'iterative_settings',
        // v1.10.3: PID控制器状态表
        'pid_states',
        // v1.10.3: 金句表
        'novel_catchphrases',
        // v1.10.3: 场景模板使用记录表
        'novel_scene_templates',
        // v1.11.1: 使用统计表（远程上报数据源）
        'usage_stats',
        // v1.11.2: 角色情绪历史表（情绪连续性）
        'character_emotion_history',
        // v1.11.5: 伏笔提及日志表（支持重写回滚）
        'foreshadowing_mention_log',
        // v1.11.5: 金句回调日志表（支持重写回滚）
        'catchphrase_callback_log',
        // v1.8: 高阶写作向导表
        'novel_wizard_progress', 'novel_wizard_chats',
        // v41: 全书圣经（1000章长程记忆）
        'novel_bible',
        // v41: 全书一致性体检
        'novel_audits',
        // v1.8: 导入续写会话表
        'novel_import_sessions',
        // v1.8: 短篇小说
        'short_stories', 'short_story_versions',
        // v1.8: 热门选题系列
        'hot_novels', 'hot_novel_analysis', 'hot_novel_batches', 'hot_novel_nonces',
        // v53: 持久写作任务、后处理重试与投影幂等
        'writing_tasks', 'postprocess_jobs', 'chapter_projection_runs',
    ];

    /**
     * v1.5.2: 获取允许的表名（优先用 Schema::whitelist 派生，回退到 ALLOWED_TABLES 常量）
     * @return string[]
     */
    private static function getAllowedTables(): array {
        static $cache = null;
        if ($cache !== null) return $cache;

        // 优先用 Schema 单一真理源
        $schemaFile = __DIR__ . '/schema.php';
        if (is_readable($schemaFile)) {
            require_once $schemaFile;
            if (class_exists('Schema') && method_exists('Schema', 'whitelist')) {
                try {
                    $cache = Schema::whitelist();
                    return $cache;
                } catch (\Throwable $e) {
                    error_log("Schema::whitelist failed, fallback to ALLOWED_TABLES: " . $e->getMessage());
                }
            }
        }

        // 回退到硬编码常量
        $cache = self::ALLOWED_TABLES;
        return $cache;
    }

    /**
     * 校验表名是否在白名单中，防止表名 SQL 注入
     */
    private static function validateTable(string $table): void {
        if (!in_array($table, self::getAllowedTables(), true)) {
            throw new \InvalidArgumentException("Invalid table name: {$table}");
        }
    }

    /**
     * 校验列名格式，防止列名 SQL 注入
     * 仅允许字母、数字、下划线组成，且以字母或下划线开头
     */
    private static function validateColumns(array $data): void {
        foreach (array_keys($data) as $col) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) {
                throw new \InvalidArgumentException("Invalid column name: {$col}");
            }
        }
    }

    /**
     * 审计修复 H3（2026-06-12）：WHERE 子句静态校验。
     * 当前所有调用者都使用硬编码字符串（如 'id=?'、'novel_id=? AND status="completed"'），
     * 但为防止未来开发者把用户输入误传入 $where 引入 SQL 注入，
     * 此处强制要求 where 只能包含：
     *   - 占位符 ?
     *   - 标识符（字母/数字/下划线/点号）
     *   - 字符串字面量 'xxx' / "xxx"
     *   - 数值字面量
     *   - 比较与逻辑运算符 = <> != < > <= >= + - * /
     *   - 关键字 AND / OR / NOT / IN / LIKE / BETWEEN / IS / NULL / EXISTS
     *   - 括号
     * 显式拒绝：注释符、分号、子查询、函数调用、UNION/INTO 等。
     */
    private static function validateWhere(string $where): void {
        $w = trim($where);
        if ($w === '' || $w === '1' || $w === '0') {
            return;
        }
        // 剥离引号内的字符串字面量，避免 'or'、'and'、'--' 等出现在字面量内
        // 时误报为可疑 token。替换为不含引号的安全占位符，否则后续允许字符
        // 校验会把合法条件（如 status<>"completed"）误判为非法。
        $stripped = preg_replace('/"([^"\\\\]|\\\\.)*"/s', '0', $w);
        $stripped = preg_replace("/'([^'\\\\]|\\\\.)*'/s", '0', $stripped);

        // 拒绝 SQL 注释符、分号（多语句）、反引号（被剥离的字符串不应残留）
        // 审计修复 P1-4（2026-07-12）：增加 /* 和 */ 拦截，防止 UN/**/ION SELECT 绕过关键字过滤
        if (preg_match('/(--|;|`|#|\/\*|\*\/)/', $stripped)) {
            throw new \InvalidArgumentException('Unsafe WHERE clause: forbidden punctuation');
        }

        // 拒绝子查询/嵌套攻击关键字（必须用 \b 边界避免误伤 IN / NOT IN 等普通 SQL 关键字）
        $bad = '/\b(SELECT|INSERT|UPDATE|DELETE|DROP|UNION|EXEC|EXECUTE|INTO|FROM|JOIN|LOAD|FILE)\b/i';
        if (preg_match($bad, $stripped)) {
            throw new \InvalidArgumentException('Unsafe WHERE clause: nested query keyword');
        }

        // 只允许安全 token 集合：标识符、数字、占位符 ?、常见比较/逻辑符号、括号、
        // 以及 AND/OR/NOT/IN/LIKE/BETWEEN/IS/NULL/TRUE/FALSE/ON 等安全关键字。
        if (!preg_match('/^[a-zA-Z0-9_.\s?<>=!&|+\-*\/,%()]+$/', $stripped)) {
            throw new \InvalidArgumentException('Unsafe WHERE clause: invalid characters');
        }
    }

    public static function connect(): PDO {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST, DB_NAME, DB_CHARSET
            );
            self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE              => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE   => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES     => false,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]);

            // MySQL 5.7+ 版本检测（5.7 对 JSON/utf8mb4 支持完整，5.6 及以下有缺陷）
            $versionStmt = self::$pdo->query('SELECT VERSION()');
            $version = $versionStmt ? $versionStmt->fetchColumn() : '';
            $versionStmt->closeCursor();
            if ($version && preg_match('/(\d+)\.(\d+)/', $version, $m)) {
                $major = (int)$m[1];
                $minor = (int)$m[2];
                if ($major < 5 || ($major === 5 && $minor < 7)) {
                    error_log("AI小说系统警告：MySQL 版本 {$version} 过低，建议升级到 5.7+（当前可能缺少 JSON 类型支持）");
                }
                // MySQL 5.7 严格模式兼容：关闭 ONLY_FULL_GROUP_BY（避免复杂聚合查询报错）
                if ($major === 5 && $minor === 7) {
                    try {
                        // 审计修复（2026-07-19 H-中7）：仅移除 ONLY_FULL_GROUP_BY，
                        // 保留 STRICT_TRANS_TABLES。原代码连带剥离严格模式会导致
                        // 超长字符串静默截断、非法日期/枚举值被静默调整，掩盖数据质量问题。
                        self::$pdo->exec("SET SESSION sql_mode = REPLACE(@@sql_mode, 'ONLY_FULL_GROUP_BY', '')");
                    } catch (\Throwable $e) {
                        error_log('DB: sql_mode 设置失败 — ' . $e->getMessage());
                    }
                }
            }

            // v53：Web 请求和普通 worker 禁止隐式执行 DDL。
            // 只有 bin/migrate.php 显式进入迁移模式时才修改结构；其他入口
            // 只校验版本并 fail-closed，避免首个在线请求承担迁移风险。
            if (defined('DB_MIGRATION_MODE') && DB_MIGRATION_MODE === true) {
                self::migrate();
                self::assertSchemaReady();
            } elseif (!(defined('DB_SCHEMA_INSPECTION_MODE') && DB_SCHEMA_INSPECTION_MODE === true)) {
                self::assertSchemaReady();
            }
        }
        return self::$pdo;
    }

    /**
     * 自动迁移：补齐数据库缺失的列，兼容旧版本数据库
     * 新增：pending_foreshadowing（待回收伏笔）、story_momentum（故事势能）字段
     *
     * 性能优化：使用版本锁文件 + DB advisory lock 双保险，避免并发迁移。
     * 迁移完成后后续每次请求直接跳过全部检查，
     * 避免每次 PHP 请求都执行 9 次 information_schema 查询 + 5 次 CREATE TABLE IF NOT EXISTS。
     * 每次有结构变更时，递增 SCHEMA_VERSION 即可触发重新迁移。
     */
    private const SCHEMA_VERSION = 54;

    public static function expectedSchemaVersion(): int {
        return self::SCHEMA_VERSION;
    }

    /**
     * 供 CLI status/健康检查读取，不执行任何 DDL。
     * @return array{current:int,expected:int,ready:bool}
     */
    public static function schemaStatus(): array {
        $pdo = self::connect();
        $current = self::readSchemaVersion($pdo);
        return [
            'current'  => $current,
            'expected' => self::SCHEMA_VERSION,
            'ready'    => $current >= self::SCHEMA_VERSION,
        ];
    }

    private static function assertSchemaReady(): void {
        $current = self::readSchemaVersion(self::$pdo);
        if ($current < self::SCHEMA_VERSION) {
            throw new \RuntimeException(sprintf(
                'Database schema is not ready (current=%d, expected=%d). Run: php bin/migrate.php --apply',
                $current,
                self::SCHEMA_VERSION
            ));
        }
    }

    private static function readSchemaVersion(PDO $pdo): int {
        $tableStmt = $pdo->query("SHOW TABLES LIKE 'system_settings'");
        $exists = $tableStmt ? $tableStmt->fetchColumn() : false;
        if ($tableStmt) $tableStmt->closeCursor();
        if ($exists === false) return 0;

        $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key=? LIMIT 1');
        $stmt->execute(['schema_version_migrated']);
        $version = $stmt->fetchColumn();
        $stmt->closeCursor();
        return $version === false ? 0 : max(0, (int)$version);
    }

    private static function migrate(): void {
        // 优先使用数据库记录迁移状态，避免文件权限问题
        $pdo = self::$pdo;

        // 检查 system_settings 表是否存在
        $tableExistsStmt = $pdo->query("SHOW TABLES LIKE 'system_settings'");
        $tableExists = $tableExistsStmt->fetch();
        $tableExistsStmt->closeCursor(); // 必须关闭游标
        if ($tableExists) {
            // 检查迁移状态
            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
            $stmt->execute(['schema_version_migrated']);
            $migratedVersion = $stmt->fetchColumn();
            $stmt->closeCursor(); // 必须关闭游标，否则后续查询会报 "unbuffered queries" 错误
            if ($migratedVersion !== false && (int)$migratedVersion >= self::SCHEMA_VERSION) {
                return; // 已迁移，直接跳过
            }
        }

        // 回退到文件锁检查（兼容旧版本）
        // 旧锁文件只作为兼容提示，不能跳过真实迁移
        $storageDir = defined('BASE_PATH') ? BASE_PATH . '/storage' : dirname(__DIR__) . '/storage';
        $lockFile   = $storageDir . '/schema_v' . self::SCHEMA_VERSION . '.lock';

        if (file_exists($lockFile)) {
            error_log('Legacy schema lock detected; running migration verification to ensure database is consistent');
        }

        // ========== 数据库级 advisory lock（防并发迁移） ==========
        $locked = false;
        try {
            $lockStmt = $pdo->query("SELECT GET_LOCK('db_migrate_v" . self::SCHEMA_VERSION . "', 10)");
            $lockResult = $lockStmt->fetchColumn();
            $lockStmt->closeCursor(); // 必须关闭游标
            $locked = ($lockResult == 1);
            if (!$locked) {
                throw new \RuntimeException('DB Migrate: 未能获取迁移锁，另一进程可能正在迁移');
            }
        } catch (\Throwable $e) {
            error_log('DB Migrate: GET_LOCK 失败 — ' . $e->getMessage());
            throw new \RuntimeException('Unable to acquire database migration lock.', 0, $e);
        }

        if (!$locked) {
            throw new \RuntimeException('Unable to acquire database migration lock.');
        }

        try {
            // ========== 所有迁移操作在锁保护下执行 ==========
            // v1.7 PRO（2026-07-01）：原始内联逻辑已拆分到 4 个职责类
            //   - ColumnMigrations: 列补齐 + v9 alterColumns + v14 字段对齐
            //   - SchemaEvolutionMigrations: novel_embeddings 索引演进 + v10 列重命名
            //   - EnumMigrations: v8/v14 ENUM 扩展
            //   - IndexMigrations: v25/v46/v47 索引补齐
            $migrationErrors = [];

            // ── ① 建表阶段：Schema 单一真理源 ──
            // Schema::tables() 包含全部受管表（含 novels/novel_settings/chapters/
            // ai_models 等），CREATE TABLE IF NOT EXISTS 幂等。
            try {
                require_once __DIR__ . '/schema.php';
                if (class_exists('Schema')) {
                    Schema::applyAll($pdo);
                }
            } catch (\Throwable $e) {
                $message = 'Schema::applyAll - ' . $e->getMessage();
                $migrationErrors[] = $message;
                error_log('DB Migrate: ' . $message);
            }

            // ── ② 列补齐：v1.10.3 / v9 / v14 字段对齐 / ai_models.embedding_enabled ──
            ColumnMigrations::up($pdo, $migrationErrors);

            // ── ③ novel_embeddings 表结构演进：DROP 旧唯一键 + ADD uk_source + idx_ne_novel_type_id + v10 blob→embedding_blob ──
            SchemaEvolutionMigrations::up($pdo, $migrationErrors);

            // ── ④ ENUM 扩展：v8 atom_type / v14 plots.status/event_type / v14 style.category ──
            // ENUM 扩展失败仅 error_log，不收集到 $errors（非关键路径，老库下次重试）
            EnumMigrations::up($pdo, $migrationErrors);

            // ── ⑤ 索引补齐：v25 foreshadowing_items.idx_priority / v47 novels.idx_user / novels.idx_status / novels.idx_updated / writing_logs.idx_novel_created / v46 story_relations.idx_target_chapter ──
            IndexMigrations::up($pdo, $migrationErrors);

            // ── ⑥ 外键约束：核心表关系 FK（chapters/chapter_versions/character_cards 等 → novels.id）──
            ForeignKeyMigrations::up($pdo, $migrationErrors);

            // ── ⑦ v11/v16/v22/v41 system_settings 默认值（保留原位搬移） ──
            // 依赖业务函数 getWritingDefaults() / getConstraintDefaults()
            $wsDefaults = getWritingDefaults();
            $stmt = $pdo->prepare("INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES (?, ?)");
            foreach ($wsDefaults as $key => $def) {
                $stmt->execute([$key, (string)$def['default']]);
            }

            $cfDefaults = getConstraintDefaults();
            foreach ($cfDefaults as $key => $def) {
                $stmt->execute([$key, (string)$def['default']]);
            }

            $imgGenDefaults = [
                'image_gen_api_url'        => '',
                'image_gen_api_key'        => '',
                'image_gen_model'          => 'gpt-image-2',
                'image_gen_size'           => '1024x1536',
                'image_gen_prompt_prefix'  => '',
            ];
            $stmtImg = $pdo->prepare("INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES (?, ?)");
            foreach ($imgGenDefaults as $key => $val) {
                $stmtImg->execute([$key, $val]);
            }

            $hotNovelDefaults = [
                'hot_novels_ingest_key'             => '',
                'hot_novels_ingest_enabled'         => '1',
                'hot_novels_unsupported_categories' => '奇闻异事,游戏,体育,古风世情',
                'hot_novels_min_confidence'         => '50',
            ];
            $stmtHot = $pdo->prepare("INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES (?, ?)");
            foreach ($hotNovelDefaults as $key => $val) {
                $stmtHot->execute([$key, $val]);
            }

            // ── ⑧ 末尾兜底：Schema::applyAll 再跑一遍（幂等） ──
            // 职责类可能遗漏某些表，此处作为最后防线
            try {
                if (class_exists('Schema')) {
                    Schema::applyAll($pdo);
                }
            } catch (\Throwable $e) {
                $message = 'Schema::applyAll(final) - ' . $e->getMessage();
                $migrationErrors[] = $message;
                error_log('DB Migrate: ' . $message);
            }

            // 检查是否有不可忽略的迁移错误
            if ($migrationErrors !== []) {
                throw new \RuntimeException(
                    'Database migration incomplete: ' . implode(' | ', $migrationErrors)
                );
            }

            // 在数据库中记录迁移状态（避免文件权限问题）
            try {
                $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?")
                    ->execute(['schema_version_migrated', (string)self::SCHEMA_VERSION, (string)self::SCHEMA_VERSION]);
            } catch (\Throwable $e) {
                throw new \RuntimeException('schema_version_migrated 写入失败: ' . $e->getMessage(), 0, $e);
            }

            // 尝试写入版本锁文件（兼容旧版本），但忽略权限错误
            try {
                @file_put_contents($lockFile, 'schema_v' . self::SCHEMA_VERSION . ' migrated at ' . date('Y-m-d H:i:s') . PHP_EOL);
            } catch (\Throwable $e) {
                error_log('DB Migrate: 锁文件写入失败 — ' . $e->getMessage());
            }

        } finally {
            // 释放数据库迁移锁
            try {
                $relStmt = $pdo->query("SELECT RELEASE_LOCK('db_migrate_v" . self::SCHEMA_VERSION . "')");
                if ($relStmt) { $relStmt->fetchColumn(); $relStmt->closeCursor(); }
            } catch (\Throwable $e) {
                // 锁自动随连接释放
            }
        }
    }

    public static function query(string $sql, array $params = []): PDOStatement {
        // 审计优化 P2-7（2026-06-16）：慢查询监控
        // 阈值由 CFG_SLOW_QUERY_MS 控制（默认 200ms），超过则记录 error_log
        $slowThreshold = defined('CFG_SLOW_QUERY_MS') ? max(0, (int)CFG_SLOW_QUERY_MS) : 200;
        $start = $slowThreshold > 0 ? microtime(true) : 0;

        $stmt = self::connect()->prepare($sql);
        // 修复：EMULATE_PREPARES=false 下 execute($params) 把所有参数当字符串绑定，
        // LIMIT ? 处的字符串参数在部分 MySQL 版本报 1064（被 catch 吞掉后静默返回空）。
        // 改为逐个 bindValue：int 参数强制 PARAM_INT，null 用 PARAM_NULL，其余 PARAM_STR。
        // 全库无命名参数用法（均为 ? 占位符 + 索引数组），位置绑定安全。
        if (empty($params)) {
            $stmt->execute();
        } else {
            $i = 1;
            foreach ($params as $val) {
                if (is_int($val)) {
                    $stmt->bindValue($i, $val, PDO::PARAM_INT);
                } elseif ($val === null) {
                    $stmt->bindValue($i, null, PDO::PARAM_NULL);
                } else {
                    $stmt->bindValue($i, $val, PDO::PARAM_STR);
                }
                $i++;
            }
            $stmt->execute();
        }

        if ($slowThreshold > 0) {
            $elapsed = (microtime(true) - $start) * 1000;
            if ($elapsed > $slowThreshold) {
                $sqlPreview = substr(preg_replace('/\s+/', ' ', $sql), 0, 200);
                error_log(sprintf('[SLOW_DB] %.1fms (threshold=%dms) %s', $elapsed, $slowThreshold, $sqlPreview));
            }
        }

        return $stmt;
    }

    public static function execute(string $sql, array $params = []): int {
        $stmt = self::query($sql, $params);
        $count = $stmt->rowCount();
        $stmt->closeCursor();
        return $count;
    }

    public static function fetchAll(string $sql, array $params = []): array {
        $stmt = self::query($sql, $params);
        $result = $stmt->fetchAll();
        $stmt->closeCursor();
        return $result;
    }

    public static function fetch(string $sql, array $params = []) {
        $stmt = self::query($sql, $params);
        $result = $stmt->fetch();
        $stmt->closeCursor();  // v1.11.8: 关闭游标，避免"unbuffered queries"错误
        return $result;
    }

    /**
     * 取出单一标量值（结果集第一行第一列），常用于 COUNT(*) 等聚合查询。
     * 找不到行时返回 false，与 PDOStatement::fetchColumn() 行为一致。
     */
    public static function fetchColumn(string $sql, array $params = []) {
        $stmt = self::query($sql, $params);
        $result = $stmt->fetchColumn();
        $stmt->closeCursor();  // v1.11.8: 关闭游标，避免"unbuffered queries"错误
        return $result;
    }

    public static function insert(string $table, array $data): string {
        self::validateTable($table);
        self::validateColumns($data);
        $cols  = implode(',', array_map(fn($k) => "`$k`", array_keys($data)));
        $holes = implode(',', array_fill(0, count($data), '?'));
        self::query("INSERT INTO `$table` ($cols) VALUES ($holes)", array_values($data));
        return self::connect()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int {
        self::validateTable($table);
        self::validateColumns($data);
        self::validateWhere($where);
        $set  = implode(',', array_map(fn($k) => "`$k`=?", array_keys($data)));
        $stmt = self::query(
            "UPDATE `$table` SET $set WHERE $where",
            array_merge(array_values($data), $whereParams)
        );
        return $stmt->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int {
        self::validateTable($table);
        self::validateWhere($where);
        return self::query("DELETE FROM `$table` WHERE $where", $params)->rowCount();
    }

    public static function count(string $table, string $where = '1', array $params = []): int {
        self::validateTable($table);
        self::validateWhere($where);
        $row = self::fetch("SELECT COUNT(*) AS n FROM `$table` WHERE $where", $params);
        return (int)($row['n'] ?? 0);
    }

    public static function lastId(): string {
        return self::connect()->lastInsertId();
    }

    public static function getPdo(): PDO {
        return self::connect();
    }

    /**
     * 审计修复 C-4（2026-06-17）：统一事务入口，幂等 + 嵌套保护。
     * 旧实现：直接调用 PDO::beginTransaction()，已开启时抛 PDOException
     * 导致 `if (!DB::beginTransaction())` 守卫失效（PDO 不返回 false）。
     * 新行为：
     *   - 已在事务中：返回 false（调用方选择是否复用），不再抛异常
     *   - 开启成功：返回 true
     *   - 开启失败：返回 false（不再让 PDOException 冒泡，调用方需自行 catch）
     * 配套提供 DB::inTransaction() 供调用方守卫。
     */
    public static function beginTransaction(): bool {
        $pdo = self::connect();
        if ($pdo->inTransaction()) return false;
        try {
            return $pdo->beginTransaction();
        } catch (\PDOException $e) {
            error_log('DB::beginTransaction 失败: ' . $e->getMessage());
            return false;
        }
    }

    public static function inTransaction(): bool {
        return self::connect()->inTransaction();
    }

    public static function commit(): bool {
        $pdo = self::connect();
        if (!$pdo->inTransaction()) {
            // 防御：未开启事务的 commit 是不安全操作；记日志并返回 false
            error_log('DB::commit 调用但当前无活跃事务');
            return false;
        }
        return $pdo->commit();
    }

    public static function rollBack(): bool {
        $pdo = self::connect();
        if (!$pdo->inTransaction()) {
            // 嵌套 try/catch 中无事务可回滚是合法场景，不记日志
            return false;
        }
        return $pdo->rollBack();
    }
}
