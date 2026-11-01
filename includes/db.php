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

// 向后兼容旧 config.php 的开关名称，但运行时连接永不执行隐式 DDL。
// Schema 滞后或结构探针缺失时一律 fail closed，并要求管理员显式运行
// `php bin/migrate.php --apply`；只有该 CLI 入口和安装器有迁移授权。
if (!defined('DB_AUTO_MIGRATE')) {
    define('DB_AUTO_MIGRATE', false);
}

class DB {
    private static ?PDO $pdo = null;
    /** 仅在 applyMigrations() 建立底层连接期间为 true。 */
    private static bool $migrationEntryActive = false;
    /** 显式迁移成功后允许同一 CLI 进程复用已校验连接。 */
    private static bool $migrationModeReady = false;
    /** Internal authorization shared by explicit and automatic migration. */
    private static bool $harnessMigrationEntryActive = false;

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
        'writing_tasks', 'postprocess_jobs', 'postprocess_subtasks', 'chapter_projection_runs',
        // v61: Candidate-generation-aware post-processing identity
        'chapter_candidates',
        // v62-v67: durable Harness persistence, Jobs, Revisions, Branch memory and FK closure
        'generation_runs', 'generation_steps', 'generation_attempts',
        'generation_events', 'generation_artifacts', 'chapter_commit_ledger',
        'approval_requests', 'jobs', 'job_events',
        'story_branches', 'chapter_revisions', 'chapter_branch_heads',
        'chapter_canonical_heads', 'branch_merge_commit_ledger',
        'branch_memory_records',
        'harness_retention_archives',
        // 2026-08-19 白名单审计补齐：以下 7 张表此前只在 Schema::tables() 定义，
        // 兜底常量遗漏（运行时 Schema::whitelist() 优先，仅 schema.php 不可读时受影响）
        'volume_summaries',                    // 2026-08-16 P3: 卷级摘要
        'drama_projects', 'drama_episodes', 'drama_assets', 'drama_shots', 'drama_tasks', // v1.8 漫剧工作流
        'login_throttle',                      // 2026-08-02 H1-DB: 登录限速（DB 持久化）
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
        // 审计修复（2026-08-02 F1）：反斜杠是引号剥离绕过的根因——
        // `name='a\' AND password=' OR 'x'='x'` 经剥离后变为 `name=0 OR 0=0` 全检放行，
        // 而 MySQL 按默认转义规则解析为恒真条件。$where 是开发者硬编码片段，
        // 全库调用点均无需反斜杠，直接拒绝可彻底封死该绕过路径（fail-closed）。
        if (strpos($w, '\\') !== false) {
            throw new \InvalidArgumentException('Unsafe WHERE clause: backslash is not allowed');
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

    /**
     * 断线（2006/2013）后允许原样重发的语句形态判定（fail closed）。
     *
     * 审计修复（2026-10-31 B2）：非事务断线时服务端可能已提交该语句而客户端
     * 未收到 ACK，只有可证明幂等的语句才能重发，否则 exactly-once 被静默破坏：
     *   - 读语句（SELECT/SHOW/DESCRIBE/EXPLAIN）安全；但连接级命名锁函数
     *     （GET_LOCK 等）作用于新连接时语义完全不同，一律拒绝。
     *   - DELETE：重发至多再删 0 行。
     *   - UPDATE：仅绝对值赋值与收敛守卫（GREATEST/COALESCE/IF）可重发；
     *     拒绝自引用算术（attempt=attempt+1 计数器会双跳）、LAST_INSERT_ID
     *     计数器技巧、CONCAT 拼接赋值（append 非幂等）。
     *   - INSERT：仅 ON DUPLICATE KEY UPDATE 且更新子句满足 UPDATE 同款规则；
     *     普通 INSERT / INSERT IGNORE 无法从文本证明唯一键兜底，一律拒绝。
     *   - 其余形态（REPLACE/DDL/CALL/WITH…）未知即拒绝。
     *
     * 判定只看 SQL 文本（与 validateWhere 同层的防御性保守校验），不查
     * schema、不产生额外查询；无法证明幂等 ⇒ 不可重发，交由调用方受控重试。
     */
    private static function isRetrySafeStatement(string $sql): bool
    {
        $s = ltrim($sql);
        if ($s === '') {
            return false;
        }
        // 连接级命名锁函数：丢弃连接即已释放锁，新连接上重发会错误地
        // 获取/释放另一连接的锁（B1 同源风险，此处兜底拦截）。
        if (preg_match('/\b(GET_LOCK|RELEASE_LOCK|IS_FREE_LOCK|IS_USED_LOCK|RELEASE_ALL_LOCKS)\s*\(/i', $s)) {
            return false;
        }
        $first = strtoupper(substr($s, 0, strcspn($s, " \t\r\n(")));
        if ($first === 'SELECT' || $first === 'SHOW'
            || $first === 'DESCRIBE' || $first === 'DESC' || $first === 'EXPLAIN') {
            return true;
        }
        if ($first === 'DELETE') {
            return true;
        }
        // 自引用算术（计数器双跳）与不可证明收敛的赋值函数
        $unsafeAssignment = '/\b(\w+)\s*=\s*\1\s*[+\-]/i';
        $unsafeFunction = '/\b(LAST_INSERT_ID|CONCAT)\s*\(/i';
        if ($first === 'UPDATE') {
            return preg_match($unsafeAssignment, $s) !== 1
                && preg_match($unsafeFunction, $s) !== 1;
        }
        if ($first === 'INSERT') {
            if (preg_match('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i', $s) !== 1) {
                return false;
            }
            $odku = (string)preg_replace('/^.*\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/is', '', $s);
            return preg_match($unsafeAssignment, $odku) !== 1
                && preg_match($unsafeFunction, $odku) !== 1;
        }
        return false;
    }

    public static function connect(): PDO {
        if (defined('DB_MIGRATION_MODE') && DB_MIGRATION_MODE === true
            && !self::$migrationEntryActive
            && !self::$migrationModeReady) {
            throw new \LogicException(
                'DB_MIGRATION_MODE connections must be opened through DB::applyMigrations().'
            );
        }

        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                DB_HOST, defined('DB_PORT') ? (int)DB_PORT : 3306, DB_NAME, DB_CHARSET
            );
            self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE              => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE   => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES     => false,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]);

            // Kernel leases, CAS timestamps and legacy NOW(6) writes share one
            // UTC clock contract. Do not retain a partially initialized PDO:
            // a server that rejects the session setting must fail closed.
            try {
                self::$pdo->exec("SET SESSION time_zone = '+00:00'");
            } catch (\Throwable $e) {
                self::$pdo = null;
                throw new \RuntimeException(
                    "Database session must use UTC (SET SESSION time_zone='+00:00' failed).",
                    0,
                    $e
                );
            }

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

            // 显式迁移模式只建立连接，不在 connect() 中隐式执行 DDL。
            // 获得授权的 CLI 调用者必须显式调用 applyMigrations()；这样生产
            // 迁移命令与集成测试共用同一受控入口，也不会再依赖私有实现细节。
            // 普通 Web/worker 请求只读校验 Schema，绝不顺带执行 DDL。
            if (defined('DB_MIGRATION_MODE') && DB_MIGRATION_MODE === true) {
                // DDL 由当前 applyMigrations() 调用在连接建立后继续执行。
            } elseif (!(defined('DB_SCHEMA_INSPECTION_MODE') && DB_SCHEMA_INSPECTION_MODE === true)) {
                try {
                    self::assertSchemaReady();
                } catch (\Throwable $e) {
                    // A caller that catches the readiness exception must not
                    // obtain the same unverified PDO on a later connect().
                    self::$pdo = null;
                    throw $e;
                }
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
    private const SCHEMA_VERSION = 72;
    private const M66_MAIN_BRANCH_BACKFILL_BATCH_SIZE = 250;
    private const M66_MAIN_BRANCH_MAX_HIGH_WATER_ADVANCES = 8;
    private const M66_MAIN_BRANCH_BACKFILL_LOCK = 'harness_m66_main_branch_backfill';
    private const CHAPTER_COMMIT_LEGACY_HASH_MARKER = 'm63_candidate_legacy_revision_v1';
    private const CHAPTER_COMMIT_LEGACY_HASH_EXPRESSION =
        'IF(`candidate_id` IS NULL, `committed_revision_hash`, NULL)';
    private const POSTPROCESS_JOB_UID_MARKER = 'postprocess_job_v2';
    private const POSTPROCESS_JOB_UID_EXPRESSION = "LOWER(LEFT(SHA2(CONCAT("
        . "'postprocess_job_v2:',"
        . "IF(`accepted_candidate_uid` IS NULL,"
        . "CONCAT('legacy:', `chapter_id`, ':', `revision_hash`),"
        . "CONCAT('candidate:', `accepted_candidate_uid`)),"
        . "':', `stage`), 256), 32))";

    public static function expectedSchemaVersion(): int {
        return self::SCHEMA_VERSION;
    }

    /**
     * Apply the Candidate/Canon schema through one implementation for both the
     * explicit migrator and the installer. The installer owns a separately
     * constructed PDO because config.php does not exist yet.
     */
    public static function applyHarnessSchemaMigrations(PDO $pdo): void {
        $migrationAuthorized = self::$harnessMigrationEntryActive;
        $installationAuthorized = defined('DB_INSTALLATION_MODE')
            && DB_INSTALLATION_MODE === true;
        if (!$migrationAuthorized && !$installationAuthorized) {
            throw new \LogicException('Harness schema migration is not authorized.');
        }

        $errors = [];
        // MySQL 5.7 rejects a CASCADE/SET NULL action on a base column used by
        // a STORED generated column. Repair released definitions before M60 or
        // M63 tries to add/modify those generated columns; M67 verifies again
        // after every additive migration has supplied the required columns.
        self::applyHarnessGeneratedColumnForeignKeyPreflight($pdo, $errors);
        self::applyHarnessM60Schema($pdo, $errors);
        self::applyHarnessM63Schema($pdo, $errors);
        self::applyHarnessM64Schema($pdo, $errors);
        self::applyHarnessM65Schema($pdo, $errors);
        self::applyHarnessM66Schema($pdo, $errors);
        self::applyHarnessM67Schema($pdo, $errors);
        self::applyHarnessM69EventStoreInvariants($pdo, $errors);
        try {
            require_once __DIR__ . '/db/migrations/EventEvolutionMigration.php';
            EventEvolutionMigration::apply($pdo);
        } catch (\Throwable $exception) {
            $errors[] = 'M70 event evolution - ' . $exception->getMessage();
        }
        if ($errors !== []) {
            throw new \RuntimeException(
                'Harness schema migration incomplete: ' . implode(' | ', $errors)
            );
        }
    }

    /**
     * Bring either a fresh or pre-existing database supplied to install.php to
     * the same structural baseline as the production migrator, without using
     * DB's configured singleton connection or stamping a version prematurely.
     */
    public static function applyInstallationSchemaMigrations(PDO $pdo): void
    {
        if (!(defined('DB_INSTALLATION_MODE') && DB_INSTALLATION_MODE === true)) {
            throw new \LogicException('Installation schema migration is not authorized.');
        }

        require_once __DIR__ . '/db/migrations/LeaseClockMigration.php';
        LeaseClockMigration::assertDrained($pdo);
        require_once __DIR__ . '/db/migrations/EventEvolutionMigration.php';
        EventEvolutionMigration::assertDrained($pdo);
        require_once __DIR__ . '/schema.php';
        if (!class_exists('Schema')) {
            throw new \RuntimeException('Schema class unavailable during installation migration.');
        }

        $errors = [];
        Schema::applyAll($pdo);
        ColumnMigrations::up($pdo, $errors);
        SchemaEvolutionMigrations::up($pdo, $errors);
        EnumMigrations::up($pdo, $errors);
        IndexMigrations::up($pdo, $errors);
        self::applyHarnessSchemaMigrations($pdo);
        ForeignKeyMigrations::up($pdo, $errors);
        Schema::applyAll($pdo);

        if ($errors !== []) {
            throw new \RuntimeException(
                'Installation schema migration incomplete: ' . implode(' | ', $errors)
            );
        }
    }

    /**
     * 显式执行生产级 schema 迁移。
     *
     * 这是迁移实现唯一公开入口。调用者必须同时满足：
     *  - 运行于 CLI；
     *  - 在加载 DB 类前显式 define('DB_MIGRATION_MODE', true)。
     *
     * bin/migrate.php 与隔离的集成测试 bootstrap 均通过此入口执行同一套
     * 幂等迁移；Web 请求或普通 worker 即使误调用也会在任何连接/DDL 前失败。
     */
    public static function applyMigrations(): void {
        if (PHP_SAPI !== 'cli') {
            throw new \LogicException('Database migrations are CLI-only.');
        }
        if (!(defined('DB_MIGRATION_MODE') && DB_MIGRATION_MODE === true)) {
            throw new \LogicException(
                'Database migration is not authorized. Define DB_MIGRATION_MODE=true in an explicit CLI entry point.'
            );
        }

        $previousEntryState = self::$migrationEntryActive;
        self::$migrationEntryActive = true;
        try {
            if (self::$pdo === null) {
                self::connect();
            }

            self::migrate();
            self::assertSchemaReady();
            self::$migrationModeReady = true;
        } finally {
            self::$migrationEntryActive = $previousEntryState;
        }
    }

    /**
     * 供 CLI status/健康检查读取，不执行任何 DDL。
     * @return array{current:int,expected:int,ready:bool}
     */
    public static function schemaStatus(): array {
        $pdo = self::connect();
        $current = self::readSchemaVersion($pdo);
        $ready = $current >= self::SCHEMA_VERSION;
        if ($ready) {
            // --status is read-only but must not call a forged/stale version
            // marker ready when required columns are absent.
            self::assertRequiredColumnsReady();
            self::assertEventStoreInvariantsReady();
            self::assertEventEvolutionReady();
        }
        return [
            'current'  => $current,
            'expected' => self::SCHEMA_VERSION,
            'ready'    => $ready,
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
        self::assertRequiredColumnsReady();
        self::assertEventStoreInvariantsReady();
        self::assertEventEvolutionReady();
    }

    private static function assertEventEvolutionReady(): void
    {
        require_once __DIR__ . '/db/migrations/EventEvolutionMigration.php';
        EventEvolutionMigration::assertReady(self::$pdo);
    }

    /**
     * 只读验证关键列，防止伪造/陈旧 schema version 绕过发布屏障。
     *
     * 触发场景：install.php 复用旧库时 CREATE TABLE IF NOT EXISTS 跳过旧表，
     * 而安装直接写 schema_version_migrated=SCHEMA_VERSION 却未跑 ColumnMigrations，
     * 导致 novels.user_id 等列缺失、首页查询 1054 报错。
     *
     * 实现：用一条 information_schema 查询探测不同时期的关键列（探针），
     * 任一缺失即拒绝当前请求，并提示通过显式迁移入口修复；这里不得调用
     * ColumnMigrations/IndexMigrations，也不得执行任何 CREATE/ALTER/DROP。
     * 探测成本：每请求 1 条 COUNT 查询（DB::connect 为单例，仅首次连接执行一次）。
     */
    private static function assertRequiredColumnsReady(): void {
        $pdo = self::$pdo;
        // 探针：覆盖不同迁移时期的关键列，任一缺失即代表 schema 不完整
        $canaries = [
            ['novels',   'user_id'],           // v47
            ['novels',   'daemon_write'],      // v12
            ['chapters', 'chapter_summary'],   // 早期
            ['chapters', 'claim_token'],       // 2026-08-04 写作认领；v58 已达标的旧库常缺此列
            ['chapters', 'gate_results'],      // 质检落盘
            ['book_analyses', 'user_id'],      // v71 运行时 DDL 收编
            ['volume_outlines', 'chapter_to'], // v71 运行时 DDL 收编
        ];
        try {
            $or = [];
            $params = [];
            foreach ($canaries as [$t, $c]) {
                $or[] = '(TABLE_NAME = ? AND COLUMN_NAME = ?)';
                $params[] = $t;
                $params[] = $c;
            }
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND (' . implode(' OR ', $or) . ')'
            );
            $stmt->execute($params);
            $present = (int)$stmt->fetchColumn();
            $stmt->closeCursor();
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Database schema readiness probe failed; refusing service without verification.',
                0,
                $e
            );
        }
        if ($present >= count($canaries)) {
            return;
        }
        throw new \RuntimeException(sprintf(
            'Database schema marker is current but required columns are missing (%d/%d). '
                . 'Run: php bin/migrate.php --apply',
            $present,
            count($canaries)
        ));
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
            // 拿到锁后复查版本（2026-08-19 自动迁移配套）：并发场景下另一进程
            // 可能刚完成迁移，此时直接返回，避免重复执行全量幂等迁移。
            if (self::readSchemaVersion($pdo) >= self::SCHEMA_VERSION) {
                return; // finally 块会释放锁
            }

            // No DDL or version stamp may precede the UTC lease transition
            // barrier. Live legacy DATETIME values have no reliable timezone.
            require_once __DIR__ . '/db/migrations/LeaseClockMigration.php';
            LeaseClockMigration::assertDrained($pdo);
            require_once __DIR__ . '/db/migrations/EventEvolutionMigration.php';
            EventEvolutionMigration::assertDrained($pdo);

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

            // v60 core plus the v61 generation-identity convergence patch.
            // M62 tables are additive and are created by Schema::applyAll
            // before and after this guarded column/index migration.
            // The routine is fully idempotent so databases already stamped v60
            // still receive job_uid/rewrite_allowed and index replacement when
            // the release advances beyond the v61 boundary.
            try {
                self::$harnessMigrationEntryActive = true;
                self::applyHarnessSchemaMigrations($pdo);
            } catch (\Throwable $e) {
                $message = 'Harness schema migrations - ' . $e->getMessage();
                $migrationErrors[] = $message;
                error_log('DB Migrate: ' . $message);
            } finally {
                self::$harnessMigrationEntryActive = false;
            }

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

    /**
     * v60 Candidate -> Canon schema plus the v61 identity convergence patch.
     *
     * MySQL 5.7 compatible and safe to retry: columns and indexes are guarded
     * through information_schema, generated identity is validated before the
     * legacy uniqueness is replaced, and every failure prevents the schema
     * version from advancing.
     */
    private static function applyHarnessM60Schema(PDO $pdo, array &$errors): void
    {
        $columns = [
            ['chapters', 'canonical_state',
                "ALTER TABLE `chapters` ADD COLUMN `canonical_state` VARCHAR(16) NOT NULL DEFAULT 'none' COMMENT 'none|legacy|canonical'"],
            ['chapters', 'content_revision_hash',
                "ALTER TABLE `chapters` ADD COLUMN `content_revision_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL"],
            ['chapters', 'canonicalized_at',
                "ALTER TABLE `chapters` ADD COLUMN `canonicalized_at` DATETIME(6) DEFAULT NULL"],
            ['chapters', 'write_policy_snapshot_json',
                "ALTER TABLE `chapters` ADD COLUMN `write_policy_snapshot_json` JSON DEFAULT NULL"],
            ['chapters', 'canonical_candidate_uid',
                "ALTER TABLE `chapters` ADD COLUMN `canonical_candidate_uid` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL"],

            ['writing_tasks', 'policy_snapshot_json',
                "ALTER TABLE `writing_tasks` ADD COLUMN `policy_snapshot_json` JSON DEFAULT NULL"],
            ['writing_tasks', 'base_chapter_snapshot_json',
                "ALTER TABLE `writing_tasks` ADD COLUMN `base_chapter_snapshot_json` JSON DEFAULT NULL"],
            ['writing_tasks', 'policy_snapshot_hash',
                "ALTER TABLE `writing_tasks` ADD COLUMN `policy_snapshot_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL"],
            ['writing_tasks', 'lease_token',
                "ALTER TABLE `writing_tasks` ADD COLUMN `lease_token` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL"],
            ['writing_tasks', 'fence_generation',
                "ALTER TABLE `writing_tasks` ADD COLUMN `fence_generation` BIGINT UNSIGNED NOT NULL DEFAULT 0"],
            ['writing_tasks', 'accepted_candidate_uid',
                "ALTER TABLE `writing_tasks` ADD COLUMN `accepted_candidate_uid` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL"],

            ['postprocess_jobs', 'policy_snapshot_json',
                "ALTER TABLE `postprocess_jobs` ADD COLUMN `policy_snapshot_json` JSON DEFAULT NULL"],
            ['postprocess_jobs', 'policy_snapshot_hash',
                "ALTER TABLE `postprocess_jobs` ADD COLUMN `policy_snapshot_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL"],
            ['postprocess_jobs', 'lease_token',
                "ALTER TABLE `postprocess_jobs` ADD COLUMN `lease_token` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL"],
            ['postprocess_jobs', 'fence_generation',
                "ALTER TABLE `postprocess_jobs` ADD COLUMN `fence_generation` BIGINT UNSIGNED NOT NULL DEFAULT 0"],
            ['postprocess_jobs', 'accepted_candidate_uid',
                "ALTER TABLE `postprocess_jobs` ADD COLUMN `accepted_candidate_uid` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL"],
        ];

        foreach ($columns as [$table, $column, $sql]) {
            try {
                if (!SchemaMigrator::columnExists($pdo, $table, $column)) {
                    $pdo->exec($sql);
                }
            } catch (\Throwable $e) {
                $message = "v60_column:{$table}.{$column} - " . $e->getMessage();
                if (!SchemaMigrator::isIgnorableError($e)) {
                    $errors[] = $message;
                }
                error_log('DB Migrate: ' . $message);
            }
        }

        // v61 deployment boundary. These columns cannot be folded into an
        // already-released v60 marker: otherwise an existing v60 database
        // skips migration and the first new enqueue fails with unknown column.
        $v61Columns = [
            ['postprocess_jobs', 'rewrite_allowed',
                "ALTER TABLE `postprocess_jobs` ADD COLUMN `rewrite_allowed` TINYINT(1) NOT NULL DEFAULT 0"],
        ];
        foreach ($v61Columns as [$table, $column, $sql]) {
            try {
                if (!SchemaMigrator::columnExists($pdo, $table, $column)) {
                    $pdo->exec($sql);
                }
            } catch (\Throwable $e) {
                $message = "v61_column:{$table}.{$column} - " . $e->getMessage();
                if (!SchemaMigrator::isIgnorableError($e)) {
                    $errors[] = $message;
                }
                error_log('DB Migrate: ' . $message);
            }
        }

        try {
            $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `chapter_candidates` (
    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `candidate_uid`        CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `user_id`              INT UNSIGNED NOT NULL,
    `novel_id`             INT UNSIGNED NOT NULL,
    `chapter_id`           INT UNSIGNED NOT NULL,
    `source`               VARCHAR(16) NOT NULL COMMENT 'initial|rewrite',
    `source_run_uid`       CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    `source_task_id`       VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    `parent_candidate_uid` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    `base_revision_hash`   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    `base_snapshot_json`   JSON DEFAULT NULL,
    `inline_content`       LONGTEXT NOT NULL,
    `content_hash`         CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `words`                INT UNSIGNED NOT NULL DEFAULT 0,
    `lifecycle`            VARCHAR(24) NOT NULL DEFAULT 'draft' COMMENT 'draft|evaluated|quarantined|committed|superseded',
    `gate_state`           VARCHAR(24) NOT NULL DEFAULT 'pending' COMMENT 'pending|passed|quarantined|error',
    `validation_json`      JSON DEFAULT NULL,
    `quarantine_reason`    TEXT NULL,
    `policy_snapshot_json` JSON DEFAULT NULL,
    `policy_snapshot_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    `evaluated_at`         DATETIME(6) DEFAULT NULL,
    `quarantined_at`       DATETIME(6) DEFAULT NULL,
    `committed_at`         DATETIME(6) DEFAULT NULL,
    `created_at`           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at`           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_candidate_uid` (`candidate_uid`),
    KEY `idx_candidate_chapter_content` (`chapter_id`, `content_hash`),
    KEY `idx_candidate_chapter_lifecycle` (`chapter_id`, `lifecycle`, `created_at`),
    KEY `idx_candidate_novel_lifecycle` (`novel_id`, `lifecycle`, `created_at`),
    KEY `idx_candidate_source_task` (`source_task_id`),
    KEY `idx_candidate_source_run` (`source_run_uid`),
    KEY `idx_candidate_parent` (`parent_candidate_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Pre-canon chapter candidates'
SQL
            );
        } catch (\Throwable $e) {
            $message = 'v60_table:chapter_candidates - ' . $e->getMessage();
            if (!SchemaMigrator::isIgnorableError($e)) {
                $errors[] = $message;
            }
            error_log('DB Migrate: ' . $message);
        }

        // A generated job_uid keeps the durable identity convergent even when
        // a draining v60 worker omits the new column. Candidate NULL remains a
        // deliberate legacy identity; the database never guesses ownership by
        // matching content alone.
        $jobIdentityReady = true;
        try {
            self::ensurePostprocessGeneratedJobUid($pdo);
            self::backfillAndValidatePostprocessCandidateIdentity($pdo);
            self::assertGeneratedJobUidsMatch($pdo);
        } catch (\Throwable $e) {
            $jobIdentityReady = false;
            $message = 'v61_identity:postprocess_jobs - ' . $e->getMessage();
            $errors[] = $message;
            error_log('DB Migrate: ' . $message);
        }

        // Replace the early-M60 Candidate content uniqueness in one ALTER
        // whenever both names have not already converged. A failed ADD keeps
        // the original unique index intact for a safe retry.
        try {
            self::replaceLegacyIndex(
                $pdo,
                'chapter_candidates',
                'uk_candidate_chapter_content',
                'idx_candidate_chapter_content',
                ['chapter_id', 'content_hash'],
                'ADD INDEX `idx_candidate_chapter_content` (`chapter_id`, `content_hash`)'
            );
        } catch (\Throwable $e) {
            $message = 'v61_index:chapter_candidates.content_lookup - ' . $e->getMessage();
            $errors[] = $message;
            error_log('DB Migrate: ' . $message);
        }

        // The old revision uniqueness may be removed only after the generated
        // identity exists and its replacement is verified as a single-column
        // UNIQUE index. This ordering survives every MySQL 5.7 implicit-commit
        // boundary without exposing an unkeyed queue.
        if ($jobIdentityReady) {
            try {
                self::ensureIndexDefinition(
                    $pdo,
                    'postprocess_jobs',
                    'uk_postprocess_job_uid',
                    ['job_uid'],
                    true,
                    'ADD UNIQUE INDEX `uk_postprocess_job_uid` (`job_uid`)'
                );
                self::replaceLegacyIndex(
                    $pdo,
                    'postprocess_jobs',
                    'uk_postprocess_revision',
                    'idx_postprocess_revision',
                    ['chapter_id', 'revision_hash', 'stage'],
                    'ADD INDEX `idx_postprocess_revision` (`chapter_id`, `revision_hash`, `stage`)'
                );
            } catch (\Throwable $e) {
                $message = 'v61_index:postprocess_jobs.identity_switch - ' . $e->getMessage();
                $errors[] = $message;
                error_log('DB Migrate: ' . $message);
            }
        }

        $indexes = [
            ['chapters', 'idx_chapters_canonical_status',
                "ALTER TABLE `chapters` ADD INDEX `idx_chapters_canonical_status` (`novel_id`, `canonical_state`, `status`)"],
            ['chapters', 'uk_chapters_canonical_candidate',
                "ALTER TABLE `chapters` ADD UNIQUE INDEX `uk_chapters_canonical_candidate` (`canonical_candidate_uid`)"],
            ['writing_tasks', 'idx_writing_task_fence',
                "ALTER TABLE `writing_tasks` ADD INDEX `idx_writing_task_fence` (`task_id`, `lease_token`, `fence_generation`)"],
            ['postprocess_jobs', 'idx_postprocess_fence',
                "ALTER TABLE `postprocess_jobs` ADD INDEX `idx_postprocess_fence` (`id`, `lease_token`, `fence_generation`)"],
        ];

        foreach ($indexes as [$table, $index, $sql]) {
            try {
                if (!SchemaMigrator::indexExists($pdo, $table, $index)) {
                    $pdo->exec($sql);
                }
            } catch (\Throwable $e) {
                $message = "v60_index:{$table}.{$index} - " . $e->getMessage();
                if (!SchemaMigrator::isIgnorableError($e)) {
                    $errors[] = $message;
                }
                error_log('DB Migrate: ' . $message);
            }
        }
    }

    /**
     * M63 is deliberately additive. Legacy M60 candidates remain readable
     * with nullable Artifact/Run references while every newly-created M63
     * candidate is authenticated by its Run and immutable Artifact identity.
     *
     * MySQL 5.7 publishes most ALTER TABLE statements with implicit commits,
     * so every step establishes and verifies its replacement guard before an
     * older uniqueness constraint is relaxed.
     */
    private static function applyHarnessM63Schema(PDO $pdo, array &$errors): void
    {
        $columns = [
            ['chapter_candidates', 'run_id',
                "ALTER TABLE `chapter_candidates` ADD COLUMN `run_id` BIGINT UNSIGNED DEFAULT NULL AFTER `chapter_id`"],
            ['chapter_candidates', 'draft_artifact_id',
                "ALTER TABLE `chapter_candidates` ADD COLUMN `draft_artifact_id` BIGINT UNSIGNED DEFAULT NULL AFTER `base_snapshot_json`"],
            ['chapter_candidates', 'validation_artifact_id',
                "ALTER TABLE `chapter_candidates` ADD COLUMN `validation_artifact_id` BIGINT UNSIGNED DEFAULT NULL AFTER `gate_state`"],
            ['chapter_candidates', 'approved_at',
                "ALTER TABLE `chapter_candidates` ADD COLUMN `approved_at` DATETIME(6) DEFAULT NULL AFTER `quarantined_at`"],
            ['chapter_candidates', 'rejected_at',
                "ALTER TABLE `chapter_candidates` ADD COLUMN `rejected_at` DATETIME(6) DEFAULT NULL AFTER `approved_at`"],
            ['chapter_candidates', 'stale_at',
                "ALTER TABLE `chapter_candidates` ADD COLUMN `stale_at` DATETIME(6) DEFAULT NULL AFTER `rejected_at`"],
            ['chapter_commit_ledger', 'candidate_id',
                "ALTER TABLE `chapter_commit_ledger` ADD COLUMN `candidate_id` BIGINT UNSIGNED DEFAULT NULL AFTER `chapter_id`"],
        ];
        foreach ($columns as [$table, $column, $sql]) {
            try {
                if (!SchemaMigrator::columnExists($pdo, $table, $column)) {
                    $pdo->exec($sql);
                }
            } catch (\Throwable $e) {
                $message = "v63_column:{$table}.{$column} - " . $e->getMessage();
                if (!SchemaMigrator::isIgnorableError($e)) {
                    $errors[] = $message;
                }
                error_log('DB Migrate: ' . $message);
            }
        }

        $ledgerIdentityReady = true;
        try {
            self::ensureChapterCommitLegacyHash($pdo);
        } catch (\Throwable $e) {
            $ledgerIdentityReady = false;
            $message = 'v63_identity:chapter_commit_ledger - ' . $e->getMessage();
            $errors[] = $message;
            error_log('DB Migrate: ' . $message);
        }

        try {
            $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `approval_requests` (
    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `approval_uid`         CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `user_id`              INT UNSIGNED NOT NULL,
    `novel_id`             INT UNSIGNED NOT NULL,
    `chapter_id`           INT UNSIGNED NOT NULL,
    `run_id`               BIGINT UNSIGNED DEFAULT NULL,
    `candidate_id`         BIGINT UNSIGNED NOT NULL,
    `idempotency_key`      VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `action_type`          VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `risk_level`           VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `state`                VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'open' COMMENT 'open|approved|rejected|stale|canceled',
    `base_revision_hash`   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    `policy_snapshot_json` JSON NOT NULL,
    `policy_snapshot_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `summary_artifact_id`  BIGINT UNSIGNED DEFAULT NULL,
    `requested_by`         VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `decided_by`           VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    `decision_reason`      VARCHAR(500) DEFAULT NULL,
    `decision_idempotency_key` VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    `decision_payload_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    `expires_at`           DATETIME(6) DEFAULT NULL,
    `created_at`           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `decided_at`           DATETIME(6) DEFAULT NULL,
    `updated_at`           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    `open_slot`            TINYINT(1) GENERATED ALWAYS AS (IF(`state`='open', 1, NULL)) STORED,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_approval_uid` (`approval_uid`),
    UNIQUE KEY `uk_approval_user_idempotency` (`user_id`, `idempotency_key`),
    UNIQUE KEY `uk_approval_user_decision_idempotency` (`user_id`, `decision_idempotency_key`),
    UNIQUE KEY `uk_approval_candidate_open` (`candidate_id`, `action_type`, `open_slot`),
    KEY `idx_approval_candidate_state` (`candidate_id`, `state`, `created_at`),
    KEY `idx_approval_run_state` (`run_id`, `state`, `created_at`),
    KEY `idx_approval_expires` (`state`, `expires_at`),
    KEY `idx_approval_summary_artifact` (`summary_artifact_id`),
    CONSTRAINT `fk_approval_requests_novel`
        FOREIGN KEY (`novel_id`) REFERENCES `novels` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_approval_requests_chapter`
        FOREIGN KEY (`chapter_id`) REFERENCES `chapters` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_approval_requests_run`
        FOREIGN KEY (`run_id`) REFERENCES `generation_runs` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_approval_requests_candidate`
        FOREIGN KEY (`candidate_id`) REFERENCES `chapter_candidates` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_approval_requests_summary_artifact`
        FOREIGN KEY (`summary_artifact_id`) REFERENCES `generation_artifacts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Durable candidate approval decisions'
SQL
            );
        } catch (\Throwable $e) {
            $message = 'v63_table:approval_requests - ' . $e->getMessage();
            if (!SchemaMigrator::isIgnorableError($e)) {
                $errors[] = $message;
            }
            error_log('DB Migrate: ' . $message);
        }

        $approvalColumns = [
            ['decision_idempotency_key',
                "ALTER TABLE `approval_requests` ADD COLUMN `decision_idempotency_key` VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER `decision_reason`"],
            ['decision_payload_hash',
                "ALTER TABLE `approval_requests` ADD COLUMN `decision_payload_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER `decision_idempotency_key`"],
            ['open_slot',
                "ALTER TABLE `approval_requests` ADD COLUMN `open_slot` TINYINT(1) GENERATED ALWAYS AS (IF(`state`='open', 1, NULL)) STORED AFTER `updated_at`"],
        ];
        foreach ($approvalColumns as [$column, $sql]) {
            try {
                if (!SchemaMigrator::columnExists($pdo, 'approval_requests', $column)) {
                    $pdo->exec($sql);
                }
            } catch (\Throwable $e) {
                $message = "v63_column:approval_requests.{$column} - " . $e->getMessage();
                if (!SchemaMigrator::isIgnorableError($e)) {
                    $errors[] = $message;
                }
                error_log('DB Migrate: ' . $message);
            }
        }

        // Backfill only an unambiguous, fully-owned legacy source_run_uid.
        // Duplicate byte-identical historical candidates intentionally keep a
        // NULL Run instead of having identity guessed from content alone.
        try {
            $pdo->exec(<<<'SQL'
UPDATE `chapter_candidates` cc
INNER JOIN `generation_runs` r
        ON BINARY r.`run_uid`=BINARY cc.`source_run_uid`
       AND r.`user_id`=cc.`user_id`
       AND r.`novel_id`=cc.`novel_id`
       AND r.`chapter_id`=cc.`chapter_id`
INNER JOIN `novels` n
        ON n.`id`=cc.`novel_id`
       AND n.`user_id`=cc.`user_id`
INNER JOIN `chapters` ch
        ON ch.`id`=cc.`chapter_id`
       AND ch.`novel_id`=cc.`novel_id`
LEFT JOIN `chapter_candidates` earlier
       ON earlier.`run_id` IS NULL
      AND BINARY earlier.`source_run_uid`=BINARY cc.`source_run_uid`
      AND earlier.`user_id`=cc.`user_id`
      AND earlier.`novel_id`=cc.`novel_id`
      AND earlier.`chapter_id`=cc.`chapter_id`
      AND BINARY earlier.`content_hash`=BINARY cc.`content_hash`
      AND earlier.`id` < cc.`id`
LEFT JOIN `chapter_candidates` claimed
       ON claimed.`run_id`=r.`id`
      AND BINARY claimed.`content_hash`=BINARY cc.`content_hash`
SET cc.`run_id`=r.`id`
WHERE cc.`run_id` IS NULL
  AND cc.`source_run_uid` IS NOT NULL
  AND earlier.`id` IS NULL
  AND claimed.`id` IS NULL
SQL
            );
        } catch (\Throwable $e) {
            $message = 'v63_backfill:chapter_candidates.run_id - ' . $e->getMessage();
            $errors[] = $message;
            error_log('DB Migrate: ' . $message);
        }

        $indexDefinitions = [
            ['chapter_candidates', 'uk_candidate_run_content', ['run_id', 'content_hash'], true,
                'ADD UNIQUE INDEX `uk_candidate_run_content` (`run_id`, `content_hash`)'],
            ['chapter_candidates', 'idx_candidate_draft_artifact', ['draft_artifact_id'], false,
                'ADD INDEX `idx_candidate_draft_artifact` (`draft_artifact_id`)'],
            ['chapter_candidates', 'idx_candidate_validation_artifact', ['validation_artifact_id'], false,
                'ADD INDEX `idx_candidate_validation_artifact` (`validation_artifact_id`)'],
            ['approval_requests', 'uk_approval_uid', ['approval_uid'], true,
                'ADD UNIQUE INDEX `uk_approval_uid` (`approval_uid`)'],
            ['approval_requests', 'uk_approval_user_idempotency', ['user_id', 'idempotency_key'], true,
                'ADD UNIQUE INDEX `uk_approval_user_idempotency` (`user_id`, `idempotency_key`)'],
            ['approval_requests', 'uk_approval_user_decision_idempotency', ['user_id', 'decision_idempotency_key'], true,
                'ADD UNIQUE INDEX `uk_approval_user_decision_idempotency` (`user_id`, `decision_idempotency_key`)'],
            ['approval_requests', 'uk_approval_candidate_open', ['candidate_id', 'action_type', 'open_slot'], true,
                'ADD UNIQUE INDEX `uk_approval_candidate_open` (`candidate_id`, `action_type`, `open_slot`)'],
            ['approval_requests', 'idx_approval_candidate_state', ['candidate_id', 'state', 'created_at'], false,
                'ADD INDEX `idx_approval_candidate_state` (`candidate_id`, `state`, `created_at`)'],
            ['approval_requests', 'idx_approval_run_state', ['run_id', 'state', 'created_at'], false,
                'ADD INDEX `idx_approval_run_state` (`run_id`, `state`, `created_at`)'],
            ['approval_requests', 'idx_approval_expires', ['state', 'expires_at'], false,
                'ADD INDEX `idx_approval_expires` (`state`, `expires_at`)'],
            ['approval_requests', 'idx_approval_summary_artifact', ['summary_artifact_id'], false,
                'ADD INDEX `idx_approval_summary_artifact` (`summary_artifact_id`)'],
        ];
        foreach ($indexDefinitions as [$table, $index, $indexColumns, $unique, $addClause]) {
            try {
                self::ensureIndexDefinition(
                    $pdo,
                    $table,
                    $index,
                    $indexColumns,
                    $unique,
                    $addClause
                );
            } catch (\Throwable $e) {
                $message = "v63_index:{$table}.{$index} - " . $e->getMessage();
                $errors[] = $message;
                error_log('DB Migrate: ' . $message);
            }
        }

        if ($ledgerIdentityReady) {
            try {
                self::ensureIndexDefinition(
                    $pdo,
                    'chapter_commit_ledger',
                    'uk_chapter_commit_candidate',
                    ['candidate_id'],
                    true,
                    'ADD UNIQUE INDEX `uk_chapter_commit_candidate` (`candidate_id`)'
                );
                self::ensureIndexDefinition(
                    $pdo,
                    'chapter_commit_ledger',
                    'uk_chapter_commit_legacy_revision',
                    ['chapter_id', 'legacy_revision_hash'],
                    true,
                    'ADD UNIQUE INDEX `uk_chapter_commit_legacy_revision` (`chapter_id`, `legacy_revision_hash`)'
                );
                // The candidate and legacy guards now cover both identity
                // families. Only then may the over-broad revision uniqueness
                // become a lookup index, enabling candidate A -> B -> A.
                self::replaceLegacyIndex(
                    $pdo,
                    'chapter_commit_ledger',
                    'uk_chapter_commit_revision',
                    'idx_chapter_commit_revision',
                    ['chapter_id', 'committed_revision_hash'],
                    'ADD INDEX `idx_chapter_commit_revision` (`chapter_id`, `committed_revision_hash`)'
                );
            } catch (\Throwable $e) {
                $message = 'v63_index:chapter_commit_ledger.identity_switch - ' . $e->getMessage();
                $errors[] = $message;
                error_log('DB Migrate: ' . $message);
            }
        }

        $foreignKeys = [
            ['chapter_candidates', 'fk_chapter_candidates_novel', 'novel_id', 'novels', 'id', 'CASCADE'],
            ['chapter_candidates', 'fk_chapter_candidates_chapter', 'chapter_id', 'chapters', 'id', 'CASCADE'],
            ['chapter_candidates', 'fk_chapter_candidates_run', 'run_id', 'generation_runs', 'id', 'SET NULL'],
            ['chapter_candidates', 'fk_chapter_candidates_draft_artifact', 'draft_artifact_id', 'generation_artifacts', 'id', 'SET NULL'],
            ['chapter_candidates', 'fk_chapter_candidates_validation_artifact', 'validation_artifact_id', 'generation_artifacts', 'id', 'SET NULL'],
            ['approval_requests', 'fk_approval_requests_novel', 'novel_id', 'novels', 'id', 'CASCADE'],
            ['approval_requests', 'fk_approval_requests_chapter', 'chapter_id', 'chapters', 'id', 'CASCADE'],
            ['approval_requests', 'fk_approval_requests_run', 'run_id', 'generation_runs', 'id', 'SET NULL'],
            ['approval_requests', 'fk_approval_requests_candidate', 'candidate_id', 'chapter_candidates', 'id', 'CASCADE'],
            ['approval_requests', 'fk_approval_requests_summary_artifact', 'summary_artifact_id', 'generation_artifacts', 'id', 'SET NULL'],
            ['chapter_commit_ledger', 'fk_chapter_commit_candidate', 'candidate_id', 'chapter_candidates', 'id', 'RESTRICT'],
        ];
        foreach ($foreignKeys as [$table, $name, $column, $referencedTable, $referencedColumn, $deleteRule]) {
            try {
                self::ensureHarnessForeignKey(
                    $pdo,
                    $table,
                    $name,
                    $column,
                    $referencedTable,
                    $referencedColumn,
                    $deleteRule
                );
            } catch (\Throwable $e) {
                $message = "v63_foreign_key:{$table}.{$name} - " . $e->getMessage();
                $errors[] = $message;
                error_log('DB Migrate: ' . $message);
            }
        }
    }

    /**
     * M64 is additive: the legacy Run remains the workflow authority while a
     * unified Job supplies queue/lease/retry/cancel semantics. Creating both
     * tables here keeps direct forward migration and fresh installation on the
     * same definitions from Schema::tables().
     */
    private static function applyHarnessM64Schema(PDO $pdo, array &$errors): void
    {
        try {
            require_once __DIR__ . '/schema.php';
            $tables = Schema::tablesInDependencyOrder();
            foreach (['jobs', 'job_events'] as $table) {
                if (!isset($tables[$table])) {
                    throw new \RuntimeException("Schema definition {$table} is missing.");
                }
                $pdo->exec($tables[$table]);
            }
        } catch (\Throwable $e) {
            $message = 'v64_tables:job_kernel - ' . $e->getMessage();
            if (!SchemaMigrator::isIgnorableError($e)) {
                $errors[] = $message;
            }
            error_log('DB Migrate: ' . $message);
        }
    }

    /**
     * M65 adds only optional Branch coordinates to the existing Candidate
     * table. The four Branch/Revision tables and the Retention ledger are
     * additive Schema definitions created by Schema::applyAll before this
     * method runs.
     */
    private static function applyHarnessM65Schema(PDO $pdo, array &$errors): void
    {
        try {
            require_once __DIR__ . '/schema.php';
            $tables = Schema::tablesInDependencyOrder();
        } catch (\Throwable $e) {
            $message = 'v65_schema:table_definitions - ' . $e->getMessage();
            $errors[] = $message;
            error_log('DB Migrate: ' . $message);
            return;
        }

        foreach ([
            'story_branches',
            'chapter_revisions',
            'chapter_branch_heads',
            'chapter_canonical_heads',
            'branch_merge_commit_ledger',
            'harness_retention_archives',
        ] as $table) {
            try {
                if (!isset($tables[$table])) {
                    throw new \RuntimeException("Schema definition {$table} is missing.");
                }
                $pdo->exec($tables[$table]);
            } catch (\Throwable $e) {
                $message = "v65_table:{$table} - " . $e->getMessage();
                if (!SchemaMigrator::isIgnorableError($e)) {
                    $errors[] = $message;
                }
                error_log('DB Migrate: ' . $message);
            }
        }

        $columns = [
            ['target_branch_uid',
                "ALTER TABLE `chapter_candidates` ADD COLUMN `target_branch_uid` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER `parent_candidate_uid`"],
            ['base_revision_uid',
                "ALTER TABLE `chapter_candidates` ADD COLUMN `base_revision_uid` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER `target_branch_uid`"],
            ['merge_source_revision_uid',
                "ALTER TABLE `chapter_candidates` ADD COLUMN `merge_source_revision_uid` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER `base_revision_uid`"],
        ];
        foreach ($columns as [$column, $sql]) {
            try {
                if (!SchemaMigrator::columnExists($pdo, 'chapter_candidates', $column)) {
                    $pdo->exec($sql);
                }
            } catch (\Throwable $e) {
                $message = "v65_column:chapter_candidates.{$column} - " . $e->getMessage();
                if (!SchemaMigrator::isIgnorableError($e)) {
                    $errors[] = $message;
                }
                error_log('DB Migrate: ' . $message);
            }
        }

        foreach ([
            ['idx_candidate_target_branch', ['target_branch_uid', 'chapter_id', 'created_at'],
                'ADD INDEX `idx_candidate_target_branch` (`target_branch_uid`, `chapter_id`, `created_at`)'],
            ['idx_candidate_merge_source', ['merge_source_revision_uid'],
                'ADD INDEX `idx_candidate_merge_source` (`merge_source_revision_uid`)'],
        ] as [$index, $indexColumns, $addClause]) {
            try {
                self::ensureIndexDefinition(
                    $pdo,
                    'chapter_candidates',
                    $index,
                    $indexColumns,
                    false,
                    $addClause
                );
            } catch (\Throwable $e) {
                $message = "v65_index:chapter_candidates.{$index} - " . $e->getMessage();
                $errors[] = $message;
                error_log('DB Migrate: ' . $message);
            }
        }
    }

    /**
     * M66 adds an isolated non-main Branch memory store. Legacy/main MemoryEngine
     * rows are intentionally not copied: doing so would duplicate authority and
     * let a fork silently mutate main memory. Existing novels receive only a
     * deterministic main Branch coordinate; non-main Branch memory starts empty
     * until an explicit Branch caller writes through the scoped repository.
     */
    private static function applyHarnessM66Schema(PDO $pdo, array &$errors): void
    {
        try {
            self::ensureIndexDefinition(
                $pdo,
                'story_branches',
                'uk_story_branch_scope',
                ['user_id', 'novel_id', 'branch_uid'],
                true,
                'ADD UNIQUE INDEX `uk_story_branch_scope` (`user_id`, `novel_id`, `branch_uid`)'
            );
        } catch (\Throwable $e) {
            $message = 'v66_index:story_branches.uk_story_branch_scope - '
                . $e->getMessage();
            $errors[] = $message;
            error_log('DB Migrate: ' . $message);
            return;
        }

        try {
            require_once __DIR__ . '/schema.php';
            $tables = Schema::tablesInDependencyOrder();
            if (!isset($tables['branch_memory_records'])) {
                throw new \RuntimeException(
                    'Schema definition branch_memory_records is missing.'
                );
            }
            $pdo->exec($tables['branch_memory_records']);
        } catch (\Throwable $e) {
            $message = 'v66_table:branch_memory_records - ' . $e->getMessage();
            if (!SchemaMigrator::isIgnorableError($e)) {
                $errors[] = $message;
            }
            error_log('DB Migrate: ' . $message);
            return;
        }

        try {
            self::ensureHarnessCompositeForeignKey(
                $pdo,
                'branch_memory_records',
                'fk_branch_memory_scope',
                ['user_id', 'novel_id', 'branch_uid'],
                'story_branches',
                ['user_id', 'novel_id', 'branch_uid'],
                'CASCADE'
            );
        } catch (\Throwable $e) {
            $message = 'v66_foreign_key:branch_memory_records.scope - '
                . $e->getMessage();
            $errors[] = $message;
            error_log('DB Migrate: ' . $message);
        }

        try {
            // Existing legacy Memory remains authoritative in MemoryEngine
            // tables and is never copied into the non-main namespace store.
            // The coordinate backfill itself is cursor-batched so a structural
            // migration never emits an unbounded INSERT ... SELECT or COUNT.
            self::backfillM66MainBranchesInBatches($pdo);
        } catch (\Throwable $e) {
            $message = 'v66_backfill:story_branches.main - ' . $e->getMessage();
            $errors[] = $message;
            error_log('DB Migrate: ' . $message);
        }
    }

    /**
     * Backfill and verify deterministic main Branch coordinates while a
     * dedicated advisory lock serializes both the migrator and installer path.
     */
    private static function backfillM66MainBranchesInBatches(PDO $pdo): void
    {
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $lock->execute([self::M66_MAIN_BRANCH_BACKFILL_LOCK]);
        try {
            $locked = (int)$lock->fetchColumn() === 1;
        } finally {
            $lock->closeCursor();
        }
        if (!$locked) {
            throw new \RuntimeException(
                'Unable to acquire the M66 main Branch backfill lock.'
            );
        }

        try {
            self::backfillM66MainBranchesUnderLock($pdo);
            self::assertM66MainBranchBackfillComplete($pdo);
        } finally {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([self::M66_MAIN_BRANCH_BACKFILL_LOCK]);
                try {
                    $release->fetchColumn();
                } finally {
                    $release->closeCursor();
                }
            } catch (\Throwable $e) {
                // A broken connection releases named locks automatically. Do
                // not mask the structural error that caused this finally path.
                error_log('DB Migrate: M66 backfill lock release failed - ' . $e->getMessage());
            }
        }
    }

    /**
     * Use bounded primary-key pages under the M66 advisory lock. A moving
     * high-water mark admits a small amount of application-side novel creation
     * but fails closed if cardinality keeps changing during migration.
     */
    private static function backfillM66MainBranchesUnderLock(PDO $pdo): void
    {
        $batchSize = self::M66_MAIN_BRANCH_BACKFILL_BATCH_SIZE;
        $cursor = 0;
        $highWater = self::m66NovelHighWaterMark($pdo);
        $highWaterAdvances = 0;

        while (true) {
            $select = $pdo->prepare(
                'SELECT `id`,`user_id` FROM `novels`'
                . ' WHERE `id`>? AND `id`<=? ORDER BY `id` ASC LIMIT ' . $batchSize
            );
            $select->execute([$cursor, $highWater]);
            try {
                $rows = $select->fetchAll(PDO::FETCH_ASSOC);
            } finally {
                $select->closeCursor();
            }
            if (!is_array($rows) || count($rows) > $batchSize) {
                throw new \RuntimeException('M66 main Branch batch cardinality is invalid.');
            }

            if ($rows === []) {
                $nextHighWater = self::m66NovelHighWaterMark($pdo);
                if ($nextHighWater <= $highWater) {
                    return;
                }
                $highWaterAdvances++;
                if ($highWaterAdvances > self::M66_MAIN_BRANCH_MAX_HIGH_WATER_ADVANCES) {
                    throw new \RuntimeException(
                        'Novel cardinality changed continuously during the M66 main Branch backfill.'
                    );
                }
                $highWater = $nextHighWater;
                continue;
            }

            $valueSql = [];
            $insertParams = [];
            $novelIds = [];
            foreach ($rows as $row) {
                $novelId = (int)($row['id'] ?? 0);
                $userId = (int)($row['user_id'] ?? 0);
                if ($novelId <= $cursor || $novelId > $highWater || $userId <= 0) {
                    throw new \RuntimeException('M66 main Branch cursor returned an invalid ownership row.');
                }
                $valueSql[] = "(?,?,?,'main',1,'active')";
                $insertParams[] = substr(
                    hash('sha256', 'story-main-branch-v1:' . $userId . ':' . $novelId),
                    0,
                    32
                );
                $insertParams[] = $userId;
                $insertParams[] = $novelId;
                $novelIds[] = $novelId;
                $cursor = $novelId;
            }

            $insert = $pdo->prepare(
                'INSERT INTO `story_branches`'
                . ' (`branch_uid`,`user_id`,`novel_id`,`name`,`is_main`,`state`) VALUES '
                . implode(',', $valueSql)
                . ' ON DUPLICATE KEY UPDATE `branch_uid`=`branch_uid`'
            );
            $insert->execute($insertParams);
            $insert->closeCursor();

            $placeholders = implode(',', array_fill(0, count($novelIds), '?'));
            $verify = $pdo->prepare(
                'SELECT n.`id` FROM `novels` n'
                . ' LEFT JOIN `story_branches` sb'
                . ' ON sb.`user_id`=n.`user_id` AND sb.`novel_id`=n.`id` AND sb.`is_main`=1'
                . " WHERE n.`id` IN ({$placeholders}) AND sb.`id` IS NULL LIMIT 1"
            );
            $verify->execute($novelIds);
            try {
                $missingNovelId = $verify->fetchColumn();
            } finally {
                $verify->closeCursor();
            }
            if ($missingNovelId !== false) {
                throw new \RuntimeException(
                    'Novel ' . (string)$missingNovelId
                    . ' could not receive an unambiguous main Branch coordinate.'
                );
            }
        }
    }

    /**
     * Scan every Novel in bounded pages after the idempotent fill. The final
     * high-water comparison prevents a successful validation from racing a new
     * Novel into the unvalidated tail immediately before this method returns.
     */
    private static function assertM66MainBranchBackfillComplete(PDO $pdo): void
    {
        $batchSize = self::M66_MAIN_BRANCH_BACKFILL_BATCH_SIZE;
        $cursor = 0;
        $highWater = self::m66NovelHighWaterMark($pdo);

        while (true) {
            $select = $pdo->prepare(
                'SELECT n.`id`,sb.`id` AS `main_branch_id` FROM `novels` n'
                . ' LEFT JOIN `story_branches` sb'
                . ' ON sb.`user_id`=n.`user_id`'
                . ' AND sb.`novel_id`=n.`id` AND sb.`is_main`=1'
                . ' WHERE n.`id`>? AND n.`id`<=?'
                . ' ORDER BY n.`id` ASC LIMIT ' . $batchSize
            );
            $select->execute([$cursor, $highWater]);
            try {
                $rows = $select->fetchAll(PDO::FETCH_ASSOC);
            } finally {
                $select->closeCursor();
            }
            if (!is_array($rows) || count($rows) > $batchSize) {
                throw new \RuntimeException(
                    'M66 main Branch validation batch cardinality is invalid.'
                );
            }
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $novelId = (int)($row['id'] ?? 0);
                if ($novelId <= $cursor || $novelId > $highWater) {
                    throw new \RuntimeException(
                        'M66 main Branch validation cursor returned an invalid row.'
                    );
                }
                if (($row['main_branch_id'] ?? null) === null) {
                    throw new \RuntimeException(
                        'Novel ' . $novelId . ' is missing its main Branch coordinate.'
                    );
                }
                $cursor = $novelId;
            }
        }

        if (self::m66NovelHighWaterMark($pdo) !== $highWater) {
            throw new \RuntimeException(
                'Novel cardinality changed during final M66 main Branch validation.'
            );
        }
    }

    private static function m66NovelHighWaterMark(PDO $pdo): int
    {
        $statement = $pdo->query('SELECT COALESCE(MAX(`id`),0) FROM `novels`');
        try {
            $value = $statement->fetchColumn();
        } finally {
            $statement->closeCursor();
        }
        if (!is_int($value) && (!is_string($value) || preg_match('/^\d+$/D', $value) !== 1)) {
            throw new \RuntimeException('M66 novel high-water mark is invalid.');
        }
        return (int)$value;
    }

    /**
     * Repair released FK actions before a MySQL 5.7 server sees the STORED
     * generated columns. Older databases may not have the M63 ledger column
     * yet, so only this preflight is allowed to defer a missing coordinate.
     */
    private static function applyHarnessGeneratedColumnForeignKeyPreflight(
        PDO $pdo,
        array &$errors
    ): void {
        self::applyHarnessGeneratedColumnForeignKeyRules(
            $pdo,
            $errors,
            true,
            'v67_preflight_foreign_key'
        );
    }

    /**
     * M67 converges FK actions whose child columns feed STORED generated
     * identities. MySQL 5.7 forbids CASCADE/SET NULL on those base columns;
     * RESTRICT preserves immutable identity and makes parent cleanup explicit.
     */
    private static function applyHarnessM67Schema(PDO $pdo, array &$errors): void
    {
        self::applyHarnessGeneratedColumnForeignKeyRules(
            $pdo,
            $errors,
            false,
            'v67_foreign_key'
        );
    }

    /**
     * M69（专项九 P1.4）：generation_events 的幂等 authority 依赖两个结构不变量，
     * 而已部署旧库可能已发生漂移：event_key 的 ascii/ascii_bin（二进制、精确比较）
     * 与 (run_id,event_key)、(run_id,event_no) 两个唯一索引。旧版本号已达标的库
     * 不会重跑迁移，因此本迁移：
     *   1. 只做精确校验（列序、唯一性、无 prefix），从不按索引名放行；
     *   2. 转换前先做数据 preflight：非 ASCII key 或字节级重复一律 fail closed，
     *      绝不允许 MySQL 静默替换字符造成幂等 identity 漂移；
     *   3. 同名但定义错误的索引在 preflight 通过后显式替换。
     * 全部错误进入 $errors，任何失败都会阻止 schema version 写入。
     */
    private static function applyHarnessM69EventStoreInvariants(PDO $pdo, array &$errors): void
    {
        try {
            $tables = $pdo->query("SHOW TABLES LIKE 'generation_events'");
            $exists = $tables ? $tables->fetchColumn() : false;
            if ($tables) $tables->closeCursor();
            if ($exists === false) {
                // Fresh installs receive the table from Schema::applyAll with
                // the correct invariants; nothing to verify or repair.
                return;
            }
            self::ensureEventKeyColumnInvariants($pdo);
            self::ensureEventUniqueIndexInvariants(
                $pdo,
                'uk_generation_event_sequence',
                ['run_id', 'event_no'],
                'event_no'
            );
            self::ensureEventUniqueIndexInvariants(
                $pdo,
                'uk_generation_event_key',
                ['run_id', 'event_key'],
                'event_key'
            );
        } catch (\Throwable $e) {
            $message = 'M69 event-store invariants - ' . $e->getMessage();
            $errors[] = $message;
            error_log('DB Migrate: ' . $message);
        }
    }

    private static function ensureEventKeyColumnInvariants(PDO $pdo): void
    {
        $column = self::eventKeyColumnDefinition($pdo);
        if (($column['CHARACTER_SET_NAME'] ?? '') === 'ascii'
            && ($column['COLLATION_NAME'] ?? '') === 'ascii_bin') {
            return;
        }
        // Preflight 1: a charset conversion to ASCII silently replaces bytes
        // that cannot be represented, silently forking event identity.
        $nonAscii = $pdo->query(
            "SELECT COUNT(*) FROM generation_events"
            . " WHERE LENGTH(event_key)<>CHAR_LENGTH(event_key)"
            . " OR event_key REGEXP '[^ -~]'"
        );
        $nonAsciiCount = $nonAscii === false ? 0 : (int)$nonAscii->fetchColumn();
        if ($nonAscii) $nonAscii->closeCursor();
        if ($nonAsciiCount > 0) {
            throw new \RuntimeException(sprintf(
                'generation_events.event_key has %d non-ASCII value(s); refusing lossy collation migration.',
                $nonAsciiCount
            ));
        }
        // Preflight 2: byte-level duplicates violate the target unique index
        // regardless of the current collation.
        self::assertNoEventKeyDuplicates($pdo);
        $pdo->exec(
            'ALTER TABLE `generation_events`'
            . ' MODIFY `event_key` VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL'
        );
        $rechecked = self::eventKeyColumnDefinition($pdo);
        if (($rechecked['CHARACTER_SET_NAME'] ?? '') !== 'ascii'
            || ($rechecked['COLLATION_NAME'] ?? '') !== 'ascii_bin') {
            throw new \RuntimeException(
                'generation_events.event_key did not converge to ascii/ascii_bin.'
            );
        }
    }

    /** @return array<string,string> */
    private static function eventKeyColumnDefinition(PDO $pdo): array
    {
        $statement = $pdo->prepare(
            'SELECT CHARACTER_SET_NAME, COLLATION_NAME'
            . ' FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'
        );
        $statement->execute(['generation_events', 'event_key']);
        try {
            $row = $statement->fetch(PDO::FETCH_ASSOC);
        } finally {
            $statement->closeCursor();
        }
        if (!is_array($row)) {
            throw new \RuntimeException('generation_events.event_key column is missing.');
        }
        return $row;
    }

    private static function assertNoEventKeyDuplicates(PDO $pdo): void
    {
        $duplicates = $pdo->query(
            'SELECT COUNT(*) FROM ('
            . ' SELECT run_id, BINARY event_key AS event_key_bytes'
            . ' FROM generation_events'
            . ' GROUP BY run_id, BINARY event_key HAVING COUNT(*)>1'
            . ') duplicated_event_keys'
        );
        $count = $duplicates === false ? 0 : (int)$duplicates->fetchColumn();
        if ($duplicates) $duplicates->closeCursor();
        if ($count > 0) {
            throw new \RuntimeException(sprintf(
                'generation_events has %d duplicate (run_id,event_key) group(s); refusing unique-index migration.',
                $count
            ));
        }
    }

    /**
     * @param list<string> $columns
     */
    private static function ensureEventUniqueIndexInvariants(
        PDO $pdo,
        string $index,
        array $columns,
        string $ordinalColumn
    ): void {
        if (self::indexMatchesDefinition($pdo, 'generation_events', $index, $columns, true)) {
            return;
        }
        if (SchemaMigrator::indexExists($pdo, 'generation_events', $index)) {
            // A wrong same-name index means the unique guarantee was never
            // real, so duplicates may exist under any collation.
            $duplicates = $pdo->query(
                'SELECT COUNT(*) FROM ('
                . ' SELECT run_id, ' . $ordinalColumn
                . ' FROM generation_events'
                . ' GROUP BY run_id, ' . $ordinalColumn . ' HAVING COUNT(*)>1'
                . ') duplicated_rows'
            );
            $count = $duplicates === false ? 0 : (int)$duplicates->fetchColumn();
            if ($duplicates) $duplicates->closeCursor();
            if ($count > 0) {
                throw new \RuntimeException(sprintf(
                    'generation_events has %d duplicate (run_id,%s) row(s); refusing unique-index repair on %s.',
                    $count,
                    $ordinalColumn,
                    $index
                ));
            }
            $columnList = '`' . implode('`, `', $columns) . '`';
            // One ALTER is the atomic swap boundary: if ADD fails, MySQL does
            // not publish the DROP and the existing index survives.
            $pdo->exec(
                "ALTER TABLE `generation_events`"
                . " DROP INDEX `{$index}`, ADD UNIQUE KEY `{$index}` ({$columnList})"
            );
        } else {
            $columnList = '`' . implode('`, `', $columns) . '`';
            $pdo->exec(
                "ALTER TABLE `generation_events` ADD UNIQUE KEY `{$index}` ({$columnList})"
            );
        }
        if (!self::indexMatchesDefinition($pdo, 'generation_events', $index, $columns, true)) {
            throw new \RuntimeException(
                "generation_events.{$index} did not converge to the required unique definition."
            );
        }
    }

    /**
     * 只读 Event 不变量 readiness（专项九 P1.4）：schema version 已达标的旧库
     * 也可能在部署期发生索引/排序规则漂移；DB::connect() 首次连接与 CLI
     * --status 均会拒绝这样的库。两条 information_schema 查询，只读。
     */
    private static function assertEventStoreInvariantsReady(): void
    {
        $pdo = self::$pdo;
        $statement = $pdo->prepare(
            'SELECT CHARACTER_SET_NAME, COLLATION_NAME'
            . ' FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'
        );
        $statement->execute(['generation_events', 'event_key']);
        try {
            $column = $statement->fetch(PDO::FETCH_ASSOC);
        } finally {
            $statement->closeCursor();
        }
        if (!is_array($column)
            || ($column['CHARACTER_SET_NAME'] ?? '') !== 'ascii'
            || ($column['COLLATION_NAME'] ?? '') !== 'ascii_bin') {
            throw new \RuntimeException(
                'generation_events.event_key must use ascii/ascii_bin for exact idempotent Event keys.'
                . ' Run: php bin/migrate.php --apply'
            );
        }
        $indexes = $pdo->prepare(
            'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART'
            . ' FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
            . ' AND INDEX_NAME IN (?, ?)'
            . ' ORDER BY INDEX_NAME, SEQ_IN_INDEX'
        );
        $indexes->execute([
            'generation_events',
            'uk_generation_event_sequence',
            'uk_generation_event_key',
        ]);
        try {
            $rows = $indexes->fetchAll(PDO::FETCH_ASSOC);
        } finally {
            $indexes->closeCursor();
        }
        $required = [
            'uk_generation_event_key' => ['run_id', 'event_key'],
            'uk_generation_event_sequence' => ['run_id', 'event_no'],
        ];
        $observed = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $name = (string)($row['INDEX_NAME'] ?? '');
            $position = (int)($row['SEQ_IN_INDEX'] ?? 0);
            if ($position >= 1 && $position <= 2) {
                $observed[$name][$position] = $row;
            }
        }
        foreach ($required as $name => $expectedColumns) {
            $definition = [];
            $unique = null;
            foreach ([1, 2] as $position) {
                $row = $observed[$name][$position] ?? null;
                if (!is_array($row)
                    || ($row['SUB_PART'] ?? null) !== null
                    || ($row['COLUMN_NAME'] ?? '') !== $expectedColumns[$position - 1]) {
                    throw new \RuntimeException(
                        "generation_events.{$name} is missing or drifted."
                        . ' Run: php bin/migrate.php --apply'
                    );
                }
                $unique = (int)($row['NON_UNIQUE'] ?? -1);
                $definition[] = (string)$row['COLUMN_NAME'];
            }
            if ($unique !== 0 || $definition !== $expectedColumns) {
                throw new \RuntimeException(
                    "generation_events.{$name} must stay a unique index over ("
                    . implode(',', $expectedColumns) . '). Run: php bin/migrate.php --apply'
                );
            }
        }
    }

    private static function applyHarnessGeneratedColumnForeignKeyRules(
        PDO $pdo,
        array &$errors,
        bool $allowMissingColumns,
        string $errorPrefix
    ): void {
        foreach ([
            [
                'postprocess_jobs',
                'fk_postprocess_jobs_chapter',
                'chapter_id',
                'chapters',
                'id',
            ],
            [
                'chapter_commit_ledger',
                'fk_chapter_commit_candidate',
                'candidate_id',
                'chapter_candidates',
                'id',
            ],
        ] as [$table, $constraint, $column, $referencedTable, $referencedColumn]) {
            try {
                $coordinatesReady = SchemaMigrator::columnExists($pdo, $table, $column)
                    && SchemaMigrator::columnExists(
                        $pdo,
                        $referencedTable,
                        $referencedColumn
                    );
                if (!$coordinatesReady) {
                    if ($allowMissingColumns) {
                        continue;
                    }
                    throw new \RuntimeException(
                        "Foreign key coordinates {$table}.{$column} -> "
                        . "{$referencedTable}.{$referencedColumn} are missing."
                    );
                }
                self::reconcileHarnessForeignKeyRule(
                    $pdo,
                    $table,
                    $constraint,
                    $column,
                    $referencedTable,
                    $referencedColumn,
                    'RESTRICT'
                );
            } catch (\Throwable $e) {
                $message = "{$errorPrefix}:{$table}.{$constraint} - " . $e->getMessage();
                $errors[] = $message;
                error_log('DB Migrate: ' . $message);
            }
        }
    }

    /**
     * Replace only the delete action of a known single-column FK. Existing
     * constraints use one MySQL 5.7 ALTER statement for DROP + ADD so no
     * auto-commit boundary can expose an unconstrained child table.
     */
    private static function reconcileHarnessForeignKeyRule(
        PDO $pdo,
        string $table,
        string $constraint,
        string $column,
        string $referencedTable,
        string $referencedColumn,
        string $deleteRule
    ): void {
        $row = self::harnessForeignKeyDefinition($pdo, $table, $constraint);
        if (is_array($row)) {
            if (!hash_equals($column, (string)($row['COLUMN_NAME'] ?? ''))
                || !hash_equals(
                    $referencedTable,
                    (string)($row['REFERENCED_TABLE_NAME'] ?? '')
                )
                || !hash_equals(
                    $referencedColumn,
                    (string)($row['REFERENCED_COLUMN_NAME'] ?? '')
                )) {
                throw new \RuntimeException(
                    "Foreign key {$table}.{$constraint} has unexpected coordinates."
                );
            }
            $actualRule = strtoupper((string)($row['DELETE_RULE'] ?? ''));
            if (hash_equals($deleteRule, $actualRule)) {
                return;
            }
            if (!in_array($actualRule, ['CASCADE', 'SET NULL', 'NO ACTION'], true)) {
                throw new \RuntimeException(
                    "Foreign key {$table}.{$constraint} has unsupported delete rule {$actualRule}."
                );
            }
            $pdo->exec(
                "ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint}`,"
                . " ADD CONSTRAINT `{$constraint}` FOREIGN KEY (`{$column}`)"
                . " REFERENCES `{$referencedTable}` (`{$referencedColumn}`)"
                . " ON DELETE {$deleteRule}"
            );
            self::ensureHarnessForeignKey(
                $pdo,
                $table,
                $constraint,
                $column,
                $referencedTable,
                $referencedColumn,
                $deleteRule
            );
            return;
        }

        $orphan = $pdo->query(
            "SELECT 1 FROM `{$table}` child_row"
            . " LEFT JOIN `{$referencedTable}` parent_row"
            . " ON parent_row.`{$referencedColumn}`=child_row.`{$column}`"
            . " WHERE child_row.`{$column}` IS NOT NULL"
            . " AND parent_row.`{$referencedColumn}` IS NULL LIMIT 1"
        );
        try {
            $hasOrphan = $orphan->fetchColumn() !== false;
        } finally {
            $orphan->closeCursor();
        }
        if ($hasOrphan) {
            throw new \RuntimeException(
                "Foreign key {$table}.{$constraint} cannot be restored while orphan rows exist."
            );
        }

        self::ensureHarnessForeignKey(
            $pdo,
            $table,
            $constraint,
            $column,
            $referencedTable,
            $referencedColumn,
            $deleteRule
        );
    }

    private static function ensureChapterCommitLegacyHash(PDO $pdo): void
    {
        $definition = '`legacy_revision_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin'
            . ' GENERATED ALWAYS AS (' . self::CHAPTER_COMMIT_LEGACY_HASH_EXPRESSION . ')'
            . " STORED COMMENT '" . self::CHAPTER_COMMIT_LEGACY_HASH_MARKER . "'";
        if (!SchemaMigrator::columnExists($pdo, 'chapter_commit_ledger', 'legacy_revision_hash')) {
            $pdo->exec('ALTER TABLE `chapter_commit_ledger` ADD COLUMN ' . $definition
                . ' AFTER `committed_revision_hash`');
            return;
        }

        $statement = $pdo->prepare(
            'SELECT COLUMN_TYPE, CHARACTER_SET_NAME, COLLATION_NAME,'
            . ' EXTRA, COLUMN_COMMENT, GENERATION_EXPRESSION FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1'
        );
        $statement->execute(['chapter_commit_ledger', 'legacy_revision_hash']);
        try {
            $column = $statement->fetch(PDO::FETCH_ASSOC);
        } finally {
            $statement->closeCursor();
        }
        $matches = is_array($column)
            && hash_equals('char(64)', strtolower((string)($column['COLUMN_TYPE'] ?? '')))
            && hash_equals('ascii', strtolower((string)($column['CHARACTER_SET_NAME'] ?? '')))
            && hash_equals('ascii_bin', strtolower((string)($column['COLLATION_NAME'] ?? '')))
            && stripos((string)($column['EXTRA'] ?? ''), 'STORED GENERATED') !== false
            && hash_equals(
                self::CHAPTER_COMMIT_LEGACY_HASH_MARKER,
                (string)($column['COLUMN_COMMENT'] ?? '')
            )
            && hash_equals(
                self::normalizeGeneratedExpression(self::CHAPTER_COMMIT_LEGACY_HASH_EXPRESSION),
                self::normalizeGeneratedExpression((string)($column['GENERATION_EXPRESSION'] ?? ''))
            );
        if (!$matches) {
            // Values are wholly derived from immutable ledger coordinates, so
            // MODIFY is deterministic and safe to retry after an interrupted
            // M63 rollout.
            $pdo->exec('ALTER TABLE `chapter_commit_ledger` MODIFY COLUMN ' . $definition);
        }
    }

    /**
     * Add or verify an ordered composite ownership FK. Single-column helpers
     * cannot prove that a globally valid branch_uid belongs to the requested
     * user/novel, which is the isolation boundary M66 must enforce in SQL.
     *
     * @param list<string> $columns
     * @param list<string> $referencedColumns
     */
    private static function ensureHarnessCompositeForeignKey(
        PDO $pdo,
        string $table,
        string $constraint,
        array $columns,
        string $referencedTable,
        array $referencedColumns,
        string $deleteRule
    ): void {
        if ($columns === [] || count($columns) !== count($referencedColumns)) {
            throw new \InvalidArgumentException(
                'Composite foreign key coordinates are incomplete.'
            );
        }
        $statement = $pdo->prepare(
            'SELECT k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,'
            . ' k.ORDINAL_POSITION,r.DELETE_RULE'
            . ' FROM information_schema.KEY_COLUMN_USAGE k'
            . ' INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r'
            . ' ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA'
            . ' AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME'
            . ' WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME=?'
            . ' AND k.CONSTRAINT_NAME=? ORDER BY k.ORDINAL_POSITION'
        );
        $statement->execute([$table, $constraint]);
        try {
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } finally {
            $statement->closeCursor();
        }
        if (is_array($rows) && $rows !== []) {
            $actualColumns = [];
            $actualReferenced = [];
            foreach ($rows as $row) {
                if (!hash_equals(
                    $referencedTable,
                    (string)($row['REFERENCED_TABLE_NAME'] ?? '')
                ) || !hash_equals(
                    $deleteRule,
                    strtoupper((string)($row['DELETE_RULE'] ?? ''))
                )) {
                    throw new \RuntimeException(
                        "Foreign key {$table}.{$constraint} has an unexpected definition."
                    );
                }
                $actualColumns[] = (string)($row['COLUMN_NAME'] ?? '');
                $actualReferenced[] = (string)($row['REFERENCED_COLUMN_NAME'] ?? '');
            }
            if ($actualColumns !== $columns
                || $actualReferenced !== $referencedColumns) {
                throw new \RuntimeException(
                    "Foreign key {$table}.{$constraint} has an unexpected column order."
                );
            }
            return;
        }

        $quote = static fn (string $column): string => '`'
            . str_replace('`', '``', $column) . '`';
        $pdo->exec(
            "ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` FOREIGN KEY ("
            . implode(',', array_map($quote, $columns)) . ') REFERENCES '
            . "`{$referencedTable}` ("
            . implode(',', array_map($quote, $referencedColumns)) . ')'
            . " ON DELETE {$deleteRule}"
        );
        self::ensureHarnessCompositeForeignKey(
            $pdo,
            $table,
            $constraint,
            $columns,
            $referencedTable,
            $referencedColumns,
            $deleteRule
        );
    }

    private static function ensureHarnessForeignKey(
        PDO $pdo,
        string $table,
        string $constraint,
        string $column,
        string $referencedTable,
        string $referencedColumn,
        string $deleteRule
    ): void {
        $row = self::harnessForeignKeyDefinition($pdo, $table, $constraint);
        if (is_array($row)) {
            if (!hash_equals($column, (string)($row['COLUMN_NAME'] ?? ''))
                || !hash_equals($referencedTable, (string)($row['REFERENCED_TABLE_NAME'] ?? ''))
                || !hash_equals($referencedColumn, (string)($row['REFERENCED_COLUMN_NAME'] ?? ''))
                || !hash_equals($deleteRule, strtoupper((string)($row['DELETE_RULE'] ?? '')))) {
                throw new \RuntimeException(
                    "Foreign key {$table}.{$constraint} has an unexpected definition."
                );
            }
            return;
        }

        $pdo->exec(
            "ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}`"
            . " FOREIGN KEY (`{$column}`) REFERENCES `{$referencedTable}` (`{$referencedColumn}`)"
            . " ON DELETE {$deleteRule}"
        );
        self::ensureHarnessForeignKey(
            $pdo,
            $table,
            $constraint,
            $column,
            $referencedTable,
            $referencedColumn,
            $deleteRule
        );
    }

    /** @return array<string,mixed>|null */
    private static function harnessForeignKeyDefinition(
        PDO $pdo,
        string $table,
        string $constraint
    ): ?array {
        $statement = $pdo->prepare(
            'SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE'
            . ' FROM information_schema.KEY_COLUMN_USAGE k'
            . ' INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r'
            . ' ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA'
            . ' AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME'
            . ' WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME=?'
            . ' AND k.CONSTRAINT_NAME=? ORDER BY k.ORDINAL_POSITION'
        );
        $statement->execute([$table, $constraint]);
        try {
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } finally {
            $statement->closeCursor();
        }
        if (!is_array($rows) || $rows === []) {
            return null;
        }
        if (count($rows) !== 1 || !is_array($rows[0])) {
            throw new \RuntimeException(
                "Foreign key {$table}.{$constraint} is not the expected single-column constraint."
            );
        }
        return $rows[0];
    }

    private static function postprocessJobUidColumnDefinition(): string
    {
        return '`job_uid` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin'
            . ' GENERATED ALWAYS AS (' . self::POSTPROCESS_JOB_UID_EXPRESSION . ')'
            . " STORED COMMENT '" . self::POSTPROCESS_JOB_UID_MARKER . "'";
    }

    /**
     * information_schema canonicalizes generated expressions differently
     * across MySQL 5.7 patch levels (identifier quotes, whitespace, redundant
     * parentheses and IS NULL vs ISNULL()). Normalize only syntax outside SQL
     * string literals: literal bytes are part of the durable hash identity and
     * even one added space must be treated as schema drift on an empty queue.
     */
    private static function normalizeGeneratedExpression(string $expression): string
    {
        $normalized = '';
        $outside = '';
        $length = strlen($expression);

        for ($offset = 0; $offset < $length;) {
            if ($expression[$offset] !== "'") {
                $outside .= $expression[$offset];
                $offset++;
                continue;
            }

            $normalized .= self::normalizeGeneratedExpressionOutside($outside);
            $outside = '';

            // Preserve the complete literal, including quotes, case, spaces,
            // doubled quotes and backslash escapes. It feeds SHA2 verbatim.
            $literal = "'";
            $offset++;
            while ($offset < $length) {
                $character = $expression[$offset];
                $literal .= $character;
                $offset++;
                if ($character !== "'") {
                    continue;
                }

                if ($offset < $length && $expression[$offset] === "'") {
                    $literal .= "'";
                    $offset++;
                    continue;
                }

                $backslashes = 0;
                for ($index = strlen($literal) - 2;
                    $index >= 0 && $literal[$index] === '\\';
                    $index--) {
                    $backslashes++;
                }
                if (($backslashes % 2) === 0) {
                    break;
                }
            }
            $normalized .= $literal;
        }

        return $normalized . self::normalizeGeneratedExpressionOutside($outside);
    }

    private static function normalizeGeneratedExpressionOutside(string $segment): string
    {
        $normalized = strtolower($segment);
        $normalized = str_replace('`', '', $normalized);
        $normalized = preg_replace(
            '/\bisnull\s*\(\s*([a-z_][a-z0-9_]*)\s*\)/i',
            '$1 is null',
            $normalized
        ) ?? $normalized;
        $normalized = preg_replace('/\s+/', '', $normalized) ?? $normalized;
        // MySQL may serialize a connection-derived literal with an explicit
        // introducer. All managed identity literals are ASCII, so only these
        // known equivalent introducers may be discarded immediately before it.
        $normalized = preg_replace(
            '/_(?:utf8mb4|utf8|latin1|ascii)$/i',
            '',
            $normalized
        ) ?? $normalized;
        // Parentheses carry AST structure outside literals. Be deliberately
        // conservative here: a false negative only causes our exact managed
        // definition to be re-applied, while a false positive can stamp a
        // semantically different generated identity on an empty queue.
        return $normalized;
    }

    private static function ensurePostprocessGeneratedJobUid(PDO $pdo): void
    {
        $definition = self::postprocessJobUidColumnDefinition();
        if (!SchemaMigrator::columnExists($pdo, 'postprocess_jobs', 'job_uid')) {
            $pdo->exec('ALTER TABLE `postprocess_jobs` ADD COLUMN ' . $definition);
            return;
        }

        $statement = $pdo->prepare(
            'SELECT COLUMN_TYPE, CHARACTER_SET_NAME, COLLATION_NAME,'
            . ' EXTRA, COLUMN_COMMENT, GENERATION_EXPRESSION FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1'
        );
        $statement->execute(['postprocess_jobs', 'job_uid']);
        try {
            $column = $statement->fetch(PDO::FETCH_ASSOC);
        } finally {
            $statement->closeCursor();
        }
        $isManagedGeneratedColumn = is_array($column)
            && hash_equals('char(32)', strtolower((string)($column['COLUMN_TYPE'] ?? '')))
            && hash_equals('ascii', strtolower((string)($column['CHARACTER_SET_NAME'] ?? '')))
            && hash_equals('ascii_bin', strtolower((string)($column['COLLATION_NAME'] ?? '')))
            && stripos((string)($column['EXTRA'] ?? ''), 'STORED GENERATED') !== false
            && hash_equals(
                self::POSTPROCESS_JOB_UID_MARKER,
                (string)($column['COLUMN_COMMENT'] ?? '')
            )
            && hash_equals(
                self::normalizeGeneratedExpression(self::POSTPROCESS_JOB_UID_EXPRESSION),
                self::normalizeGeneratedExpression(
                    (string)($column['GENERATION_EXPRESSION'] ?? '')
                )
            );
        if (!$isManagedGeneratedColumn) {
            // MySQL 5.7 supports converting a normal column to STORED. Every
            // historical value is recomputed from immutable row identity.
            $pdo->exec('ALTER TABLE `postprocess_jobs` MODIFY COLUMN ' . $definition);
        }
    }

    private static function backfillAndValidatePostprocessCandidateIdentity(PDO $pdo): void
    {
        // NULL is a durable legacy owner, not an invitation to infer a newer
        // Candidate from the chapter's current Canon pointer. Existing
        // Candidate identities are immutable, and only rows that fully
        // authenticate may receive the derived rewrite eligibility.
        $pdo->exec(<<<'SQL'
UPDATE `postprocess_jobs` j
INNER JOIN `novels` n
        ON n.`id`=j.`novel_id`
INNER JOIN `chapter_candidates` cc
        ON BINARY cc.`candidate_uid`=BINARY j.`accepted_candidate_uid`
       AND cc.`user_id`=n.`user_id`
       AND cc.`novel_id`=j.`novel_id`
       AND cc.`chapter_id`=j.`chapter_id`
       AND cc.`lifecycle`='committed'
       AND cc.`gate_state`='passed'
       AND cc.`source` IN ('initial','rewrite')
       AND BINARY cc.`content_hash`=BINARY j.`revision_hash`
       AND BINARY SHA2(cc.`inline_content`, 256)=BINARY cc.`content_hash`
       AND cc.`policy_snapshot_json` IS NOT NULL
       AND cc.`policy_snapshot_hash` IS NOT NULL
       AND j.`policy_snapshot_json` IS NOT NULL
       AND j.`policy_snapshot_hash` IS NOT NULL
       AND BINARY JSON_UNQUOTE(JSON_EXTRACT(
               cc.`policy_snapshot_json`, '$.checksum'
           ))=BINARY cc.`policy_snapshot_hash`
       AND BINARY JSON_UNQUOTE(JSON_EXTRACT(
               j.`policy_snapshot_json`, '$.checksum'
           ))=BINARY j.`policy_snapshot_hash`
       AND BINARY j.`policy_snapshot_hash`=BINARY cc.`policy_snapshot_hash`
       AND JSON_CONTAINS(j.`policy_snapshot_json`, cc.`policy_snapshot_json`)
       AND JSON_CONTAINS(cc.`policy_snapshot_json`, j.`policy_snapshot_json`)
SET j.`rewrite_allowed`=IF(cc.`source`='initial', 1, 0)
WHERE j.`accepted_candidate_uid` IS NOT NULL
SQL
        );
        $pdo->exec(
            'UPDATE `postprocess_jobs` SET `rewrite_allowed`=0'
            . ' WHERE `accepted_candidate_uid` IS NULL AND `rewrite_allowed`<>0'
        );

        $invalidAccepted = self::migrationCount($pdo, <<<'SQL'
SELECT COUNT(*)
FROM `postprocess_jobs` j
WHERE j.`accepted_candidate_uid` IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM `chapter_candidates` cc
      INNER JOIN `novels` n
              ON n.`user_id`=cc.`user_id`
      WHERE BINARY cc.`candidate_uid`=BINARY j.`accepted_candidate_uid`
        AND n.`id`=j.`novel_id`
        AND cc.`novel_id`=j.`novel_id`
        AND cc.`chapter_id`=j.`chapter_id`
        AND cc.`lifecycle`='committed'
        AND cc.`gate_state`='passed'
        AND cc.`source` IN ('initial','rewrite')
        AND BINARY cc.`content_hash`=BINARY j.`revision_hash`
        AND BINARY SHA2(cc.`inline_content`, 256)=BINARY cc.`content_hash`
        AND cc.`policy_snapshot_json` IS NOT NULL
        AND cc.`policy_snapshot_hash` IS NOT NULL
        AND j.`policy_snapshot_json` IS NOT NULL
        AND j.`policy_snapshot_hash` IS NOT NULL
        AND BINARY JSON_UNQUOTE(JSON_EXTRACT(
                cc.`policy_snapshot_json`, '$.checksum'
            ))=BINARY cc.`policy_snapshot_hash`
        AND BINARY JSON_UNQUOTE(JSON_EXTRACT(
                j.`policy_snapshot_json`, '$.checksum'
            ))=BINARY j.`policy_snapshot_hash`
        AND BINARY j.`policy_snapshot_hash`=BINARY cc.`policy_snapshot_hash`
        AND JSON_CONTAINS(j.`policy_snapshot_json`, cc.`policy_snapshot_json`)
        AND JSON_CONTAINS(cc.`policy_snapshot_json`, j.`policy_snapshot_json`)
        AND j.`rewrite_allowed`=IF(cc.`source`='initial', 1, 0)
  )
SQL
        );
        if ($invalidAccepted !== 0) {
            throw new \RuntimeException(
                "{$invalidAccepted} post-processing jobs have unauthenticated Candidate ownership."
            );
        }
    }

    private static function assertGeneratedJobUidsMatch(PDO $pdo): void
    {
        $mismatches = self::migrationCount(
            $pdo,
            'SELECT COUNT(*) FROM `postprocess_jobs`'
            . ' WHERE `job_uid` IS NULL OR BINARY `job_uid`<>BINARY '
            . self::POSTPROCESS_JOB_UID_EXPRESSION
        );
        if ($mismatches !== 0) {
            throw new \RuntimeException(
                "{$mismatches} post-processing jobs have a non-convergent generated identity."
            );
        }
    }

    private static function migrationCount(PDO $pdo, string $sql): int
    {
        $statement = $pdo->query($sql);
        if (!$statement instanceof PDOStatement) {
            throw new \RuntimeException('Harness migration validation query failed.');
        }
        try {
            return (int)$statement->fetchColumn();
        } finally {
            $statement->closeCursor();
        }
    }

    /** @param list<string> $columns */
    private static function indexMatchesDefinition(
        PDO $pdo,
        string $table,
        string $index,
        array $columns,
        bool $unique
    ): bool {
        $statement = $pdo->prepare(
            'SELECT NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART'
            . ' FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?'
            . ' ORDER BY SEQ_IN_INDEX'
        );
        $statement->execute([$table, $index]);
        try {
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } finally {
            $statement->closeCursor();
        }
        if (!is_array($rows) || count($rows) !== count($columns)) {
            return false;
        }
        foreach ($columns as $offset => $column) {
            $row = $rows[$offset] ?? null;
            if (!is_array($row)
                || (int)($row['SEQ_IN_INDEX'] ?? 0) !== $offset + 1
                || !hash_equals($column, (string)($row['COLUMN_NAME'] ?? ''))
                || (int)($row['NON_UNIQUE'] ?? -1) !== ($unique ? 0 : 1)
                || ($row['SUB_PART'] ?? null) !== null) {
                return false;
            }
        }
        return true;
    }

    /** @param list<string> $columns */
    private static function ensureIndexDefinition(
        PDO $pdo,
        string $table,
        string $index,
        array $columns,
        bool $unique,
        string $addClause
    ): void {
        $exists = SchemaMigrator::indexExists($pdo, $table, $index);
        if ($exists && !self::indexMatchesDefinition($pdo, $table, $index, $columns, $unique)) {
            throw new \RuntimeException("Index {$table}.{$index} has an unexpected definition.");
        }
        if (!$exists) {
            $pdo->exec("ALTER TABLE `{$table}` {$addClause}");
        }
        if (!self::indexMatchesDefinition($pdo, $table, $index, $columns, $unique)) {
            throw new \RuntimeException("Index {$table}.{$index} was not installed exactly.");
        }
    }

    /**
     * @param list<string> $columns
     */
    private static function replaceLegacyIndex(
        PDO $pdo,
        string $table,
        string $legacyIndex,
        string $replacementIndex,
        array $columns,
        string $addClause
    ): void {
        $legacyExists = SchemaMigrator::indexExists($pdo, $table, $legacyIndex);
        $replacementExists = SchemaMigrator::indexExists($pdo, $table, $replacementIndex);
        if ($legacyExists
            && !self::indexMatchesDefinition(
                $pdo,
                $table,
                $legacyIndex,
                $columns,
                true
            )) {
            throw new \RuntimeException(
                "Legacy index {$table}.{$legacyIndex} has an unexpected definition."
            );
        }
        if ($replacementExists
            && !self::indexMatchesDefinition(
                $pdo,
                $table,
                $replacementIndex,
                $columns,
                false
            )) {
            throw new \RuntimeException(
                "Index {$table}.{$replacementIndex} has an unexpected definition."
            );
        }

        if ($legacyExists && !$replacementExists) {
            // One ALTER gives MySQL 5.7 a single table-swap boundary: if ADD
            // fails, DROP is not published and the legacy uniqueness remains.
            $pdo->exec(
                "ALTER TABLE `{$table}` DROP INDEX `{$legacyIndex}`, {$addClause}"
            );
        } elseif ($legacyExists) {
            $pdo->exec("ALTER TABLE `{$table}` DROP INDEX `{$legacyIndex}`");
        } elseif (!$replacementExists) {
            $pdo->exec("ALTER TABLE `{$table}` {$addClause}");
        }

        if (SchemaMigrator::indexExists($pdo, $table, $legacyIndex)
            || !self::indexMatchesDefinition(
                $pdo,
                $table,
                $replacementIndex,
                $columns,
                false
            )) {
            throw new \RuntimeException(
                "Index replacement {$table}.{$legacyIndex} did not converge safely."
            );
        }
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        // 审计优化 P2-7（2026-06-16）：慢查询监控
        // 阈值由 CFG_SLOW_QUERY_MS 控制（默认 200ms），超过则记录 error_log
        $slowThreshold = defined('CFG_SLOW_QUERY_MS') ? max(0, (int)CFG_SLOW_QUERY_MS) : 200;

        // A disconnected driver may forget its transaction or throw when
        // queried. Capture the state before executing, and fail closed if the
        // state itself cannot be read. Never reconnect an interrupted transaction.
        $inTransaction = true;
        try {
            $inTransaction = self::$pdo !== null && self::$pdo->inTransaction();
            return self::prepareAndExecute($sql, $params, $slowThreshold);
        } catch (\PDOException $e) {
            // 审计修复（2026-08-16 #12）：长驻 worker（bin/postprocess_worker --loop 等）
            // 的连接可能被 MySQL wait_timeout（默认 8h）静默掐断，首个查询抛
            // "server has gone away"(2006) / "Lost connection"(2013) 且永不自愈。
            // 事务中的断连无法安全重试（前面语句已生效，重试会造成重复写入），
            // 原样抛出；非事务场景丢弃单例连接、重建后重试一次。
            $driverCode = (int)($e->errorInfo[1] ?? 0);
            $lostConnection = in_array($driverCode, [2006, 2013], true)
                || in_array((int)$e->getCode(), [2006, 2013], true);
            if (!$inTransaction && self::$pdo !== null) {
                try {
                    $inTransaction = self::$pdo->inTransaction();
                } catch (\Throwable $ignored) {
                    $inTransaction = true;
                }
            }
            if (!$lostConnection || $inTransaction) {
                throw $e;
            }
            // 审计修复（2026-10-31 B1）：持 NovelProjectionFence（连接级 GET_LOCK）
            // 时禁止丢弃重建连接。MySQL 命名锁绑定连接：单例连接被置空重建的瞬间
            // 服务端锁即释放，而本进程仍以"持锁者"身份继续写记忆/投影，并发写者
            // 此刻可成功 GET_LOCK 进入同一临界区——正是该 fence 要阻止的丢失更新。
            // class_exists 探测不触发（不存在的）自动加载：只有真正加载过
            // fence 的进程才可能持锁。fail closed：原样抛出，交由调用方按租约
            // 丢失/崩溃恢复路径处理（释放时 RELEASE_LOCK 亦会失败并报
            // "connection changed"，与其余 fail-closed 语义一致）。
            if (class_exists('NovelProjectionFence', false) && NovelProjectionFence::hasHeld()) {
                error_log(sprintf(
                    '[DB] 连接丢失(driver_code=%d)且仍持有 NovelProjectionFence 命名锁，拒绝重连（fail closed）',
                    $driverCode
                ));
                throw $e;
            }
            // 审计修复（2026-10-31 B2）：断线重试仅面向可证明幂等的语句。
            // 服务端可能已提交该语句而客户端未收到 ACK：盲目重发纯 INSERT
            //（writing_logs / agent_decision_logs 等无幂等键）会产生重复审计行，
            // 自增计数 UPDATE（attempt=attempt+1、fence_generation=...+1）会
            // 双跳并直接破坏租约/围栏语义。连接仍然丢弃重建（后续任一幂等语句
            // 即自愈），但非幂等语句原样抛出（exactly-once），由上层受控重试
            //（harness attempt / 调用方 catch）而非静默重复。
            if (!self::isRetrySafeStatement($sql)) {
                error_log(sprintf(
                    '[DB] 连接丢失(driver_code=%d)且语句非幂等（%s），丢弃连接但不重发（exactly-once）',
                    $driverCode,
                    substr((string)preg_replace('/\s+/', ' ', $sql), 0, 120)
                ));
                self::$pdo = null;
                throw $e;
            }
            error_log(sprintf(
                '[DB] 连接丢失(driver_code=%d, msg=%s)，非事务上下文重建连接并重试一次',
                $driverCode,
                $e->getMessage()
            ));
            self::$pdo = null;
            return self::prepareAndExecute($sql, $params, $slowThreshold);
        }
    }

    /**
     * prepare + 分类绑定 + 执行 + 慢查询日志（query() 的实际执行体）。
     * 审计修复（2026-08-16 #12）：从 query() 拆出以便断线重试时复用。
     */
    private static function prepareAndExecute(string $sql, array $params, int $slowThreshold): PDOStatement
    {
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
