<?php
declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

/** M70: additive event segments, projection checkpoints and operation registry. */
final class EventEvolutionMigration
{
    private const TABLES = [
        'generation_event_id_allocator',
        'generation_event_segments',
        'generation_event_checkpoints',
        'generation_poll_watermarks',
        'generation_side_effect_operations',
    ];

    public static function assertDrained(PDO $pdo): void
    {
        foreach ([
            'generation_runs' => "state NOT IN ('succeeded','aborted_after_commit','failed','canceled')",
            'jobs' => "state NOT IN ('succeeded','failed','dead_letter','canceled')",
        ] as $table => $predicate) {
            $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $exists->execute([$table]);
            $present = (int)$exists->fetchColumn() > 0;
            $exists->closeCursor();
            if (!$present) continue;
            $statement = $pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE {$predicate}");
            $count = (int)$statement->fetchColumn();
            $statement->closeCursor();
            if ($count > 0) {
                throw new RuntimeException("M70 event evolution blocked: {$table} has {$count} active rows; drain old writers before migration.");
            }
        }
    }

    public static function apply(PDO $pdo): void
    {
        require_once dirname(__DIR__, 2) . '/schema.php';
        if (!SchemaMigrator::columnExists($pdo, 'generation_runs', 'event_stream_version')) {
            $pdo->exec('ALTER TABLE generation_runs ADD event_stream_version INT UNSIGNED NOT NULL DEFAULT 1');
        }
        $definitions = Schema::tables();
        foreach (self::TABLES as $table) $pdo->exec($definitions[$table]);
        // Validate additive structures before removing the legacy self-FK.
        // An incompatible partial migration must not weaken old constraints.
        self::assertShape($pdo);
        require_once dirname(__DIR__, 2) . '/harness/persistence/RunEventIdAllocator.php';
        RunEventIdAllocator::initialize($pdo);
        // A self-FK with SET NULL rewrites immutable causal identity when a
        // prefix is archived. M70 verifies causal references in RunEventStore
        // under the Run lock across live and integrity-verified segments.
        $foreignKey = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=? AND CONSTRAINT_TYPE=?');
        $foreignKey->execute(['generation_events', 'fk_generation_events_causation', 'FOREIGN KEY']);
        $hasForeignKey = (int)$foreignKey->fetchColumn() > 0;
        $foreignKey->closeCursor();
        if ($hasForeignKey) {
            $invalid = $pdo->query('SELECT COUNT(*) FROM generation_events child LEFT JOIN generation_events parent ON parent.id=child.causation_event_id WHERE child.causation_event_id IS NOT NULL AND (parent.id IS NULL OR parent.run_id<>child.run_id OR parent.event_no>=child.event_no)');
            $invalidCount = (int)$invalid->fetchColumn();
            $invalid->closeCursor();
            if ($invalidCount > 0) throw new RuntimeException('M70 refuses invalid cross-run, dangling or non-preceding causation references.');
            $pdo->exec('ALTER TABLE generation_events DROP FOREIGN KEY fk_generation_events_causation');
        }
        // Never silently coerce a previously deployed identity column/index.
        // A mismatched partial M70 schema needs an explicit forward repair.
        self::assertReady($pdo);
    }

    public static function assertReady(PDO $pdo): void
    {
        self::assertShape($pdo);
        $allocator = $pdo->query('SELECT allocator_id,next_id FROM generation_event_id_allocator');
        $allocatorRows = $allocator->fetchAll(PDO::FETCH_ASSOC);
        $allocator->closeCursor();
        if (count($allocatorRows) !== 1 || (int)$allocatorRows[0]['allocator_id'] !== 1
            || (int)$allocatorRows[0]['next_id'] < 1) {
            throw new RuntimeException('M70 event ID allocator has no valid durable high water mark. Run: php bin/migrate.php --apply');
        }
        $causation = $pdo->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='generation_events' AND COLUMN_NAME='causation_event_id' AND REFERENCED_TABLE_NAME IS NOT NULL");
        $causationForeignKeys = (int)$causation->fetchColumn();
        $causation->closeCursor();
        if ($causationForeignKeys !== 0) throw new RuntimeException('M70 causation identity must not have a live-only foreign key. Run: php bin/migrate.php --apply');
    }

    private static function assertShape(PDO $pdo): void
    {
        require_once dirname(__DIR__, 2) . '/schema.php';
        $tables = Schema::tables();
        $columnQuery = $pdo->prepare(
            'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,COLLATION_NAME,EXTRA'
            . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
        );
        $columnQuery->execute(['generation_runs']);
        $runColumns = array_column($columnQuery->fetchAll(PDO::FETCH_ASSOC), null, 'COLUMN_NAME');
        $columnQuery->closeCursor();
        $version = $runColumns['event_stream_version'] ?? [];
        if (self::normalizeType((string)($version['COLUMN_TYPE'] ?? '')) !== 'int unsigned'
            || ($version['IS_NULLABLE'] ?? '') !== 'NO' || (string)($version['COLUMN_DEFAULT'] ?? '') !== '1') {
            throw new RuntimeException('M70 generation_runs.event_stream_version must be INT UNSIGNED NOT NULL DEFAULT 1. Run: php bin/migrate.php --apply');
        }
        foreach (self::TABLES as $table) {
            $engine = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $engine->execute([$table]);
            $actualEngine = $engine->fetchColumn();
            $engine->closeCursor();
            if ($actualEngine !== 'InnoDB') throw new RuntimeException("M70 {$table} is missing or is not InnoDB.");
            $columnQuery->execute([$table]);
            $columns = array_column($columnQuery->fetchAll(PDO::FETCH_ASSOC), null, 'COLUMN_NAME');
            $columnQuery->closeCursor();
            // Schema::tables remains the single definition for fresh install,
            // forward migration and runtime shape verification.
            preg_match_all('/^\s*`([a-z_]+)`\s+([A-Z]+(?:\(\d+\))?(?: UNSIGNED)?)([^\n]*)/m', $tables[$table], $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                [, $name, $type, $suffix] = $match;
                $actual = $columns[$name] ?? [];
                $normalized = self::normalizeType((string)($actual['COLUMN_TYPE'] ?? ''));
                $expected = self::normalizeType($type);
                $jsonAlias = $expected === 'json' && $normalized === 'longtext'
                    && ($actual['COLLATION_NAME'] ?? '') === 'utf8mb4_bin';
                $nullable = str_contains($suffix, 'NOT NULL') ? 'NO' : 'YES';
                if (($normalized !== $expected && !$jsonAlias) || ($actual['IS_NULLABLE'] ?? '') !== $nullable) {
                    throw new RuntimeException("M70 {$table}.{$name} has an incompatible type/nullability.");
                }
                if (preg_match('/COLLATE ([a-z0-9_]+)/', $suffix, $collation)
                    && ($actual['COLLATION_NAME'] ?? '') !== $collation[1]) {
                    throw new RuntimeException("M70 {$table}.{$name} has an incompatible collation.");
                }
                if (str_contains($suffix, 'AUTO_INCREMENT') && !str_contains((string)($actual['EXTRA'] ?? ''), 'auto_increment')) {
                    throw new RuntimeException("M70 {$table}.{$name} must auto increment.");
                }
                if (preg_match('/DEFAULT (CURRENT_TIMESTAMP\(6\)|NULL|[0-9]+)/', $suffix, $default)) {
                    $observedDefault = $actual['COLUMN_DEFAULT'] ?? null;
                    $expectedDefault = $default[1] === 'NULL' ? null : strtolower($default[1]);
                    // MariaDB reports the SQL literal NULL as a string.
                    if ($observedDefault === 'NULL' && $expectedDefault === null) $observedDefault = null;
                    if ($observedDefault !== null) $observedDefault = strtolower(trim((string)$observedDefault, "'"));
                    if ($observedDefault !== $expectedDefault) throw new RuntimeException("M70 {$table}.{$name} has an incompatible default.");
                }
                if ($jsonAlias) {
                    $create = $pdo->query("SHOW CREATE TABLE `{$table}`");
                    $createRow = $create->fetch(PDO::FETCH_NUM);
                    $create->closeCursor();
                    if (!is_array($createRow) || preg_match('/CHECK\s*\(\s*json_valid\s*\(\s*`' . preg_quote($name, '/') . '`\s*\)\s*\)/i', (string)$createRow[1]) !== 1) {
                        throw new RuntimeException("M70 {$table}.{$name} JSON alias requires JSON_VALID enforcement.");
                    }
                }
            }
            self::assertIndexes($pdo, $table, $tables[$table]);
            if ($table !== 'generation_event_id_allocator') self::assertRunForeignKey($pdo, $table);
        }
    }

    private static function normalizeType(string $type): string
    {
        return strtolower((string)preg_replace('/\b(BIGINT|INT|TINYINT|SMALLINT)\(\d+\)/i', '$1', trim($type)));
    }

    private static function assertIndexes(PDO $pdo, string $table, string $definition): void
    {
        $expected = [];
        preg_match_all('/(UNIQUE KEY|KEY) `([a-z_]+)` \(([^)]+)\)/', $definition, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            preg_match_all('/`([a-z_]+)`/', $match[3], $names);
            $expected[$match[2]] = [$match[1] === 'UNIQUE KEY' ? 0 : 1, $names[1]];
        }
        $primary = match ($table) {
            'generation_event_id_allocator' => 'allocator_id',
            'generation_event_checkpoints', 'generation_poll_watermarks' => 'run_id',
            default => 'id',
        };
        $expected['PRIMARY'] = [0, [$primary]];
        $statement = $pdo->prepare('SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX');
        $statement->execute([$table]);
        $actual = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) $actual[$row['INDEX_NAME']][] = $row;
        $statement->closeCursor();
        foreach ($expected as $name => [$nonUnique, $columns]) {
            $rows = $actual[$name] ?? [];
            if (count($rows) !== count($columns)) throw new RuntimeException("M70 {$table}.{$name} index is missing or has incorrect columns.");
            foreach ($rows as $index => $row) {
                if ((int)$row['NON_UNIQUE'] !== $nonUnique || $row['COLUMN_NAME'] !== $columns[$index]
                    || (int)$row['SEQ_IN_INDEX'] !== $index + 1 || $row['SUB_PART'] !== null) {
                    throw new RuntimeException("M70 {$table}.{$name} index definition is incompatible.");
                }
            }
        }
    }

    private static function assertRunForeignKey(PDO $pdo, string $table): void
    {
        // SHOW CREATE avoids MySQL 5.7's expensive cross-schema information
        // schema FK join on installations with many unrelated databases.
        $query = $pdo->query("SHOW CREATE TABLE `{$table}`");
        $row = $query->fetch(PDO::FETCH_NUM);
        $query->closeCursor();
        $definition = is_array($row) ? (string)$row[1] : '';
        preg_match_all('/FOREIGN KEY\s*\(([^)]+)\)\s+REFERENCES\s+`([^`]+)`\s*\(([^)]+)\)([^\n]*)/i', $definition, $matches, PREG_SET_ORDER);
        $foreignKey = $matches[0] ?? [];
        if (count($matches) !== 1 || trim($foreignKey[1] ?? '') !== '`run_id`'
            || ($foreignKey[2] ?? '') !== 'generation_runs' || trim($foreignKey[3] ?? '') !== '`id`'
            || preg_match('/^ ON DELETE CASCADE(?: ON UPDATE (?:RESTRICT|NO ACTION))?\s*,?$/i', (string)($foreignKey[4] ?? '')) !== 1) {
            throw new RuntimeException("M70 {$table} must reference generation_runs(id) ON DELETE CASCADE.");
        }
    }
}
