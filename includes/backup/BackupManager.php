<?php
defined('APP_LOADED') or die('Direct access denied.');

final class BackupManager
{
    private const FORMAT_VERSION = 1;
    private const STORAGE_ROOTS = ['covers', 'author_works', 'drama'];
    /** 备份时每个 storage 根目录的状态：有文件 / 存在但没有文件 / 不存在。 */
    private const ROOT_STATES = ['present', 'empty', 'absent'];
    /** 根目录里随代码部署的访问防护文件，属于部署而非用户数据：恢复既不删除也不覆盖它。 */
    private const PROTECTED_ROOT_FILE = '.htaccess';

    /**
     * @return array{ok:bool,path:string,manifest:array,verification:array}
     */
    public static function create(string $outputDirectory = '', array $options = []): array
    {
        self::ensureDatabaseClass();
        $webRoot = self::webRoot();
        if ($outputDirectory === '') {
            $outputDirectory = dirname($webRoot) . DIRECTORY_SEPARATOR . 'super-ma-backups';
        }
        self::assertOutsideWebRoot($outputDirectory);
        self::ensureDirectory($outputDirectory);
        self::assertOutsideWebRoot($outputDirectory);

        $name = 'super-ma-backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $backupDirectory = rtrim($outputDirectory, '/\\') . DIRECTORY_SEPARATOR . $name;
        if (file_exists($backupDirectory)) {
            throw new RuntimeException('Backup destination already exists.');
        }
        self::ensureDirectory($backupDirectory);

        $dumpFile = $backupDirectory . DIRECTORY_SEPARATOR . 'database.sql';
        $defaultsFile = self::createMysqlDefaultsFile();
        try {
            $mysqldump = self::resolveBinary((string)($options['mysqldump_bin'] ?? getenv('MYSQLDUMP_BIN') ?: 'mysqldump'));
            self::runCommand([
                $mysqldump,
                '--defaults-extra-file=' . $defaultsFile,
                '--single-transaction',
                '--quick',
                '--routines',
                '--triggers',
                '--events',
                '--hex-blob',
                '--set-gtid-purged=OFF',
                '--default-character-set=' . DB_CHARSET,
                DB_NAME,
            ], null, $dumpFile);
        } catch (Throwable $e) {
            self::removeTree($backupDirectory);
            throw $e;
        } finally {
            @unlink($defaultsFile);
        }

        if (!is_file($dumpFile) || filesize($dumpFile) < 16) {
            self::removeTree($backupDirectory);
            throw new RuntimeException('mysqldump produced an empty or incomplete database dump.');
        }

        // 审计修复（2026-07-19 H-中4）：storage 拷贝循环外包 try/catch，
        // 否则拷贝中途磁盘满/权限错误会留下含 database.sql 但无 manifest 的半成品目录，
        // verify() 永远失败，难以分辨是完整备份还是垃圾。
        $absentRoots = [];
        try {
            foreach (self::STORAGE_ROOTS as $storageRoot) {
                $source = $webRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $storageRoot;
                if (!is_dir($source)) {
                    $absentRoots[$storageRoot] = true;
                    continue;
                }
                $destination = $backupDirectory . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR
                    . 'storage' . DIRECTORY_SEPARATOR . $storageRoot;
                self::copyTree($source, $destination);
            }
        } catch (\Throwable $e) {
            self::removeTree($backupDirectory);
            throw new RuntimeException('Storage copy failed: ' . $e->getMessage(), 0, $e);
        }

        $schema = DB::schemaStatus();
        $trackedFiles = self::collectTrackedFiles($backupDirectory);
        // 审计整改 B2（2026-10-31）：记录每个根目录在备份时的状态，--replace-files 据此精确恢复；
        // 没有这项记录时，无法区分“备份时就不存在”和“备份在传输中丢了”。
        $rootStates = [];
        foreach (self::STORAGE_ROOTS as $storageRoot) {
            $rootStates[$storageRoot] = isset($absentRoots[$storageRoot])
                ? 'absent'
                : (self::trackedFilesUnderRoot($trackedFiles, $storageRoot) > 0 ? 'present' : 'empty');
        }
        $manifest = [
            'backup_format' => self::FORMAT_VERSION,
            'created_at_utc' => gmdate('c'),
            'application_version' => defined('APP_VERSION') ? APP_VERSION : null,
            'schema_version' => (int)$schema['current'],
            'database_name' => DB_NAME,
            'storage_roots' => self::STORAGE_ROOTS,
            'storage_root_states' => $rootStates,
            // 审计修复（2026-07-19 M5-2 残留）：记录备份时的表清单，
            // restore 据此清理目标库中不属于备份的冗余旧表。
            'tables' => self::listTables(),
            'files' => $trackedFiles,
        ];
        $manifestJson = json_encode(
            $manifest,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
        if ($manifestJson === false || file_put_contents(
            $backupDirectory . DIRECTORY_SEPARATOR . 'manifest.json',
            $manifestJson . PHP_EOL,
            LOCK_EX
        ) === false) {
            self::removeTree($backupDirectory);
            throw new RuntimeException('Unable to write backup manifest.');
        }

        $verification = self::verify($backupDirectory);
        if (!$verification['ok']) {
            self::removeTree($backupDirectory);
            throw new RuntimeException('Backup verification failed: ' . implode(' | ', $verification['errors']));
        }

        return [
            'ok' => true,
            'path' => $backupDirectory,
            'manifest' => $manifest,
            'verification' => $verification,
        ];
    }

    /** @return array{ok:bool,errors:string[],file_count:int,manifest:?array} */
    public static function verify(string $backupDirectory): array
    {
        $errors = [];
        $manifestFile = rtrim($backupDirectory, '/\\') . DIRECTORY_SEPARATOR . 'manifest.json';
        if (!is_file($manifestFile)) {
            return ['ok' => false, 'errors' => ['manifest.json is missing'], 'file_count' => 0, 'manifest' => null];
        }

        $manifest = json_decode((string)file_get_contents($manifestFile), true);
        if (!is_array($manifest)) {
            return ['ok' => false, 'errors' => ['manifest.json is invalid'], 'file_count' => 0, 'manifest' => null];
        }
        if ((int)($manifest['backup_format'] ?? 0) !== self::FORMAT_VERSION) {
            $errors[] = 'Unsupported backup format.';
        }
        $files = $manifest['files'] ?? null;
        if (!is_array($files) || !isset($files['database.sql'])) {
            $errors[] = 'Manifest does not track database.sql.';
            $files = is_array($files) ? $files : [];
        }

        foreach ($files as $relative => $metadata) {
            try {
                $path = self::safeJoin($backupDirectory, (string)$relative);
            } catch (InvalidArgumentException $e) {
                $errors[] = "Unsafe manifest path: {$relative}";
                continue;
            }
            if (!is_file($path)) {
                $errors[] = "Missing file: {$relative}";
                continue;
            }
            $expectedSize = (int)($metadata['size'] ?? -1);
            $expectedHash = strtolower((string)($metadata['sha256'] ?? ''));
            if ($expectedSize < 0 || filesize($path) !== $expectedSize) {
                $errors[] = "Size mismatch: {$relative}";
            }
            if (!preg_match('/^[a-f0-9]{64}$/', $expectedHash)
                || !hash_equals($expectedHash, (string)hash_file('sha256', $path))) {
                $errors[] = "SHA-256 mismatch: {$relative}";
            }
        }

        // 审计整改 B2：备份目录里只能有清单列出的文件，不得有链接——否则恢复会把未校验的内容写进站点。
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($backupDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen(rtrim($backupDirectory, '/\\')) + 1));
            if ($entry->isLink()) {
                $errors[] = "Link inside backup: {$relative}";
            } elseif ($entry->isFile() && $relative !== 'manifest.json' && !isset($files[$relative])) {
                $errors[] = "Untracked file in backup: {$relative}";
            }
        }

        // 新格式备份记录的根目录状态必须与清单里的文件一致。
        if (array_key_exists('storage_root_states', $manifest)) {
            $states = $manifest['storage_root_states'];
            if (!is_array($states) || count($states) !== count(self::STORAGE_ROOTS)
                || array_diff(self::STORAGE_ROOTS, array_keys($states)) !== []) {
                $errors[] = 'storage_root_states must list exactly: ' . implode(', ', self::STORAGE_ROOTS) . '.';
            } else {
                foreach (self::STORAGE_ROOTS as $storageRoot) {
                    $state = $states[$storageRoot];
                    $count = self::trackedFilesUnderRoot($files, $storageRoot);
                    if (!in_array($state, self::ROOT_STATES, true)) {
                        $errors[] = "Invalid storage root state for {$storageRoot}.";
                    } elseif (($state === 'present') !== ($count > 0)) {
                        $errors[] = "Storage root {$storageRoot} is recorded as {$state} but the manifest tracks {$count} file(s) under it.";
                    }
                }
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'file_count' => count($files),
            'manifest' => $manifest,
        ];
    }

    /**
     * @return array{ok:bool,verification:array,safety_backup:?string,schema:array,migration_required:bool}
     */
    public static function restore(string $backupDirectory, array $options = []): array
    {
        self::ensureDatabaseClass();
        self::assertOutsideWebRoot($backupDirectory);
        $verification = self::verify($backupDirectory);
        if (!$verification['ok']) {
            throw new RuntimeException('Restore refused: ' . implode(' | ', $verification['errors']));
        }

        // 审计修复（2026-07-19 H-中5）：校验 manifest 中的 database_name 与当前 DB_NAME 一致，
        // 防止把 A 库的备份导入 B 库。
        $manifest = json_decode(file_get_contents(self::safeJoin($backupDirectory, 'manifest.json')), true) ?: [];
        if (!empty($manifest['database_name']) && $manifest['database_name'] !== DB_NAME) {
            throw new RuntimeException(
                "Recovery refused: backup belongs to '{$manifest['database_name']}', current DB is '" . DB_NAME . "'."
                . ' Please check the backup directory.'
            );
        }

        // 审计整改 B2：精确替换的前置检查在安全备份和导入数据库之前完成，失败时什么都不改。
        $replaceFiles = !empty($options['replace_files']);
        $storageDirectory = self::webRoot() . DIRECTORY_SEPARATOR . 'storage';
        if ($replaceFiles) {
            self::assertStorageReplaceable($manifest, $storageDirectory);
        }

        // Serialize the entire stop/check/safety-backup/import window, including concurrent restores.
        $lockFp = self::acquireRestoreLock();
        require_once dirname(__DIR__) . '/maintenance.php';
        $maintenanceFile = maintenanceFilePath();
        $safetyBackup = null;
        $stage = 'drain_check';
        $drainCheck = null;
        $marker = ['reason' => 'db_restore', 'time' => time(), 'backup' => $backupDirectory,
            'safety_backup' => null, 'stage' => $stage,
            'skip_drain_check_requested' => !empty($options['skip_drain_check'])];
        $markerWritten = false;
        try {
            self::writeMaintenanceMarker($maintenanceFile, $marker);
            $markerWritten = true;
            $drainCheck = self::checkRestoreDrain(!empty($options['skip_drain_check']));
            $marker['drain_check'] = $drainCheck;
            $stage = 'safety_backup';
            $marker['stage'] = $stage;
            self::writeMaintenanceMarker($maintenanceFile, $marker);
            if (empty($options['no_safety_backup'])) {
                $safety = self::create(dirname(rtrim($backupDirectory, '/\\')), [
                    'mysqldump_bin' => $options['mysqldump_bin'] ?? null,
                ]);
                $safetyBackup = $safety['path'];
            }
            $marker['safety_backup'] = $safetyBackup;
            $stage = 'import';
            $marker['stage'] = $stage;
            self::writeMaintenanceMarker($maintenanceFile, $marker);
            $defaultsFile = self::createMysqlDefaultsFile();
            try {
                $mysql = self::resolveBinary((string)($options['mysql_bin'] ?? getenv('MYSQL_BIN') ?: 'mysql'));
                self::runCommand([
                    $mysql,
                    '--defaults-extra-file=' . $defaultsFile,
                    '--default-character-set=' . DB_CHARSET,
                    DB_NAME,
                ], self::safeJoin($backupDirectory, 'database.sql'), null);
            } finally {
                @unlink($defaultsFile);
            }

            $stage = 'drop_tables';
            $marker['stage'] = $stage;
            self::writeMaintenanceMarker($maintenanceFile, $marker);
            self::dropRedundantTables($manifest);
            $stage = 'storage';
            $marker['stage'] = $stage;
            self::writeMaintenanceMarker($maintenanceFile, $marker);
            self::restoreStorageFiles($backupDirectory, $manifest, $storageDirectory, $replaceFiles);
            $stage = 'schema';
            $marker['stage'] = $stage;
            self::writeMaintenanceMarker($maintenanceFile, $marker);
            $schema = DB::schemaStatus();
            // Success includes final schema inspection. A migration_required result still requires the runbook.
            if (!unlink($maintenanceFile)) throw new RuntimeException('Unable to clear maintenance marker after restore.');
        } catch (Throwable $e) {
            if ($markerWritten) {
                $marker['reason'] = 'restore_failed';
                $marker['time'] = time();
                $marker['stage'] = $stage;
                $marker['error'] = $e->getMessage();
                $marker['safety_backup'] = $safetyBackup;
                try { self::writeMaintenanceMarker($maintenanceFile, $marker); }
                catch (Throwable $markerError) {
                    // The existing marker remains fail-closed even if updating diagnostics fails.
                    error_log('Unable to update failed restore marker: ' . $markerError->getMessage());
                }
            }
            throw $e;
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }

        return [
            'ok' => true,
            'verification' => $verification,
            'safety_backup' => $safetyBackup,
            'drain_check' => $drainCheck,
            'skip_drain_check_requested' => !empty($options['skip_drain_check']),
            'schema' => $schema,
            'migration_required' => !$schema['ready'],
        ];
    }

    private static function writeMaintenanceMarker(string $path, array $marker): void
    {
        $json = json_encode($marker, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write maintenance marker; restore refused.');
        }
    }

    /** A bypass is allowed only for an unavailable query, never for observed active work. */
    private static function checkRestoreDrain(bool $skipUnavailableCheck): array
    {
        require_once __DIR__ . '/WriterDrainCensus.php';
        $queries = [
            'writing_chapters' => "SELECT COUNT(*) FROM chapters WHERE status='writing'",
            'active_writing_tasks' => WriterDrainCensus::countSql('writing_tasks'),
            'active_postprocess_jobs' => WriterDrainCensus::countSql('postprocess_jobs'),
        ];
        // 审计修复 F02（2026-11-01）：Harness Run/Job、漫剧任务和 v72 并行后处理子任务同样由
        // worker 认领写库，原排空只数上面三项。旧 Schema 可能还没有这些表——不存在的表不会有
        // 在途写入，记为 null；上面三项照旧是硬查询，空库/坏库仍须显式 --skip-drain-check。
        $optionalTables = ['generation_runs', 'jobs', 'drama_tasks', 'postprocess_subtasks'];
        $counts = [];
        try {
            foreach ($queries as $name => $sql) $counts[$name] = (int)DB::fetchColumn($sql);
            foreach ($optionalTables as $table) {
                $present = (int)DB::fetchColumn(
                    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
                    [$table]
                ) === 1;
                $counts['active_' . $table] = $present ? (int)DB::fetchColumn(WriterDrainCensus::countSql($table)) : null;
            }
        } catch (Throwable $e) {
            if (array_sum($counts) !== 0) throw new RuntimeException('Restore refused: active work remains: ' . json_encode($counts), 0, $e);
            if (!$skipUnavailableCheck) throw new RuntimeException(
                'Drain check unavailable: ' . $e->getMessage() . '. Complete external drain checks and pass --skip-drain-check explicitly.', 0, $e);
            error_log('Restore --skip-drain-check used: ' . $e->getMessage());
            return ['skipped' => true, 'error' => $e->getMessage(), 'counts' => $counts];
        }
        if (array_sum($counts) !== 0) throw new RuntimeException('Restore refused: active work remains: ' . json_encode($counts));
        return ['skipped' => false, 'counts' => $counts];
    }

    /**
     * 把备份里的 storage 根目录恢复到 $storageDirectory（审计整改 B2，2026-10-31）。
     *
     * merge（默认）：只把备份里的文件复制过去，不删除任何现有文件。
     * replace（--replace-files）：每个固定根目录按清单记录的备份时状态精确恢复——
     *   present / empty：清空后复制备份内容；absent：备份时没有这个目录，清空后不留任何用户文件。
     * 两种模式都不删除、不覆盖根目录里现有的 .htaccess（随代码部署的访问防护，不属于用户数据）；
     * 目标缺少它而备份里有时才复制。
     */
    public static function restoreStorageFiles(string $backupDirectory, array $manifest, string $storageDirectory, bool $replace): void
    {
        $states = $replace ? self::assertStorageReplaceable($manifest, $storageDirectory) : [];
        foreach (self::STORAGE_ROOTS as $storageRoot) {
            $source = self::safeJoin($backupDirectory, 'files/storage/' . $storageRoot);
            $destination = rtrim($storageDirectory, '/\\') . DIRECTORY_SEPARATOR . $storageRoot;
            if ($replace) {
                if (is_dir($destination)) {
                    self::clearDirectory($destination, self::PROTECTED_ROOT_FILE);
                }
                if ($states[$storageRoot] === 'absent') continue;
                self::ensureDirectory($destination);
            }
            if (!is_dir($source)) continue;
            self::copyTree($source, $destination, self::PROTECTED_ROOT_FILE);
        }
    }

    /**
     * 精确替换的前置检查，返回各根目录的备份时状态。拒绝：
     *  - 旧格式备份（没有 storage_root_states）：无法证明缺失的根目录在备份时为空，不能当作空目录清掉；
     *  - 目标根目录本身或其中任何位置是符号链接 / 目录联接：清空会删到 storage 之外的文件。
     *
     * @return array<string,string>
     */
    public static function assertStorageReplaceable(array $manifest, string $storageDirectory): array
    {
        $states = $manifest['storage_root_states'] ?? null;
        if (!is_array($states) || array_diff(self::STORAGE_ROOTS, array_keys($states)) !== []
            || array_diff(array_values($states), self::ROOT_STATES) !== []) {
            throw new RuntimeException(
                'Exact file replace refused: this backup does not record which storage roots existed when it was made'
                . ' (older backup format), so a missing root cannot be proven empty. Restore without --replace-files'
                . ' to merge files, or create a new backup with this version first.'
            );
        }
        foreach (self::STORAGE_ROOTS as $storageRoot) {
            $destination = rtrim($storageDirectory, '/\\') . DIRECTORY_SEPARATOR . $storageRoot;
            if (!file_exists($destination) && !is_link($destination)) continue;
            if (self::isLinkOrJunction($destination)) {
                throw new RuntimeException("Exact file replace refused: storage/{$storageRoot} is a link or junction.");
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($destination, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $entry) {
                if (self::isLinkOrJunction($entry->getPathname())) {
                    $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen(rtrim($storageDirectory, '/\\')) + 1));
                    throw new RuntimeException("Exact file replace refused: storage/{$relative} is a link or junction; remove it first.");
                }
            }
        }
        return $states;
    }

    private static function trackedFilesUnderRoot(array $files, string $storageRoot): int
    {
        $prefix = 'files/storage/' . $storageRoot . '/';
        return count(array_filter(array_keys($files), static fn($path): bool => str_starts_with((string)$path, $prefix)));
    }

    /** 符号链接，或在 Windows 上 realpath 指向别处的目录联接（is_link 认不出联接）。 */
    private static function isLinkOrJunction(string $path): bool
    {
        if (is_link($path)) return true;
        $real = realpath($path);
        $parent = realpath(dirname($path));
        if ($real === false || $parent === false) return false;
        $expected = rtrim($parent, '/\\') . DIRECTORY_SEPARATOR . basename($path);
        $normalize = static fn(string $p): string => PHP_OS_FAMILY === 'Windows'
            ? strtolower(str_replace('/', '\\', $p)) : $p;
        return $normalize($real) !== $normalize($expected);
    }

    /**
     * 恢复流程排他锁：防止两个 restore 并发，也给外部提供进行中信号。
     * @return resource
     */
    private static function acquireRestoreLock()
    {
        $lockFile = self::webRoot() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . '.restore.lock';
        $fp = fopen($lockFile, 'c');
        if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
            if (is_resource($fp)) fclose($fp);
            throw new RuntimeException('已有恢复任务在进行中，请等待其完成后再试。');
        }
        return $fp;
    }

    /** @return string[] 当前库全部表名（升序） */
    private static function listTables(): array
    {
        $tables = [];
        $stmt = DB::query('SHOW TABLES');
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = (string)$row[0];
        }
        sort($tables);
        return $tables;
    }

    /**
     * 删除目标库中不在备份表清单内的冗余表（旧数据残留）。
     * 旧格式备份无 tables 字段时跳过，保持兼容。
     */
    private static function dropRedundantTables(array $manifest): void
    {
        $keep = $manifest['tables'] ?? null;
        if (!is_array($keep) || $keep === []) {
            return;
        }
        $keepMap = array_flip(array_map('strval', $keep));
        foreach (self::listTables() as $table) {
            if (isset($keepMap[$table])) continue;
            // 白名单字符校验，防注入
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) continue;
            DB::query("DROP TABLE IF EXISTS `{$table}`");
            error_log("BackupManager::restore dropped redundant table: {$table}");
        }
    }

    public static function assertOutsideWebRoot(string $path): void
    {
        $root = rtrim(self::normalizePath(self::webRoot()), '/');
        $candidate = rtrim(self::normalizePath($path), '/');
        $rootCompare = PHP_OS_FAMILY === 'Windows' ? strtolower($root) : $root;
        $candidateCompare = PHP_OS_FAMILY === 'Windows' ? strtolower($candidate) : $candidate;
        if ($candidateCompare === $rootCompare || str_starts_with($candidateCompare . '/', $rootCompare . '/')) {
            throw new InvalidArgumentException('Backup and restore paths must be outside the application web root.');
        }
    }

    private static function ensureDatabaseClass(): void
    {
        if (!class_exists('DB', false)) {
            require_once dirname(__DIR__) . '/db.php';
        }
    }

    private static function webRoot(): string
    {
        return defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
    }

    private static function createMysqlDefaultsFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'super-ma-db-');
        if ($path === false) throw new RuntimeException('Unable to allocate temporary MySQL credentials file.');
        $quote = static fn(string $value): string => '"' . str_replace(
            ["\\", '"', "\r", "\n", "\0"],
            ["\\\\", '\\"', '\\r', '\\n', ''],
            $value
        ) . '"';
        $contents = "[client]\n"
            . 'host=' . $quote((string)DB_HOST) . "\n"
            . 'port=' . $quote((string)(defined('DB_PORT') ? (int)DB_PORT : 3306)) . "\n"
            . 'user=' . $quote((string)DB_USER) . "\n"
            . 'password=' . $quote((string)DB_PASS) . "\n"
            . 'default-character-set=' . $quote((string)DB_CHARSET) . "\n";
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            @unlink($path);
            throw new RuntimeException('Unable to write temporary MySQL credentials file.');
        }
        @chmod($path, 0600);
        return $path;
    }

    private static function resolveBinary(string $binary): string
    {
        $binary = trim($binary);
        if ($binary === '' || str_contains($binary, "\0") || str_contains($binary, "\n")) {
            throw new InvalidArgumentException('Invalid database utility path.');
        }
        return $binary;
    }

    private static function runCommand(array $command, ?string $stdinFile, ?string $stdoutFile): void
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException('proc_open is required for database backup and restore.');
        }
        $descriptors = [
            0 => $stdinFile !== null ? ['file', $stdinFile, 'rb'] : ['pipe', 'r'],
            1 => $stdoutFile !== null ? ['file', $stdoutFile, 'wb'] : ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($command, $descriptors, $pipes, self::webRoot());
        if ($process === false) {
            throw new RuntimeException('Unable to start database utility: ' . (string)$command[0]);
        }
        if ($stdinFile === null && isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);
        $stdout = '';
        if ($stdoutFile === null && isset($pipes[1]) && is_resource($pipes[1])) {
            $stdout = stream_get_contents($pipes[1]) ?: '';
            fclose($pipes[1]);
        }
        $stderr = '';
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            $stderr = stream_get_contents($pipes[2]) ?: '';
            fclose($pipes[2]);
        }
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            $message = trim($stderr !== '' ? $stderr : $stdout);
            throw new RuntimeException('Database utility failed with exit code ' . $exitCode
                . ($message !== '' ? ': ' . self::limit($message, 2000) : ''));
        }
    }

    /** @return array<string,array{sha256:string,size:int}> */
    private static function collectTrackedFiles(string $backupDirectory): array
    {
        $tracked = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($backupDirectory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if (!$entry->isFile() || $entry->isLink() || $entry->getFilename() === 'manifest.json') continue;
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen(rtrim($backupDirectory, '/\\')) + 1));
            $tracked[$relative] = [
                'sha256' => (string)hash_file('sha256', $entry->getPathname()),
                'size' => (int)$entry->getSize(),
            ];
        }
        ksort($tracked);
        return $tracked;
    }

    private static function safeJoin(string $base, string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);
        if ($relative === '' || str_starts_with($relative, '/')
            || preg_match('/^[a-zA-Z]:/', $relative)
            || in_array('..', explode('/', $relative), true)) {
            throw new InvalidArgumentException('Unsafe relative backup path.');
        }
        $baseNormalized = rtrim(self::normalizePath($base), '/');
        $joined = self::normalizePath($baseNormalized . '/' . $relative);
        $baseCompare = PHP_OS_FAMILY === 'Windows' ? strtolower($baseNormalized) : $baseNormalized;
        $joinedCompare = PHP_OS_FAMILY === 'Windows' ? strtolower($joined) : $joined;
        if (!str_starts_with($joinedCompare . '/', $baseCompare . '/')) {
            throw new InvalidArgumentException('Backup path escapes its root.');
        }
        return str_replace('/', DIRECTORY_SEPARATOR, $joined);
    }

    private static function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '') throw new InvalidArgumentException('Path cannot be empty.');
        if (!preg_match('#^(?:[a-zA-Z]:/|/)#', $path)) {
            $path = str_replace('\\', '/', getcwd() ?: '.') . '/' . $path;
        }
        $prefix = '';
        if (preg_match('#^[a-zA-Z]:#', $path, $match)) {
            $prefix = strtoupper($match[0]);
            $path = substr($path, 2);
        } elseif (str_starts_with($path, '/')) {
            $prefix = '/';
        }
        $segments = [];
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '' || $segment === '.') continue;
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        return ($prefix === '/' ? '/' : $prefix . '/') . implode('/', $segments);
    }

    /** @param string $keepExistingRootFile 目标根目录里已存在时不覆盖的文件名（如 .htaccess），空串表示不保留 */
    private static function copyTree(string $source, string $destination, string $keepExistingRootFile = ''): void
    {
        self::ensureDirectory($destination);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) continue;
            $relative = substr($entry->getPathname(), strlen(rtrim($source, '/\\')) + 1);
            $target = rtrim($destination, '/\\') . DIRECTORY_SEPARATOR . $relative;
            if ($keepExistingRootFile !== '' && $relative === $keepExistingRootFile && is_file($target)) {
                continue;
            }
            if ($entry->isDir()) {
                self::ensureDirectory($target);
            } else {
                self::ensureDirectory(dirname($target));
                if (!copy($entry->getPathname(), $target)) {
                    throw new RuntimeException('Unable to copy backup file: ' . $relative);
                }
            }
        }
    }

    private static function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create directory: ' . $directory);
        }
    }

    /** @param string $keepRootFile 保留根目录下这个文件（如 .htaccess），空串表示全部清空 */
    private static function clearDirectory(string $directory, string $keepRootFile = ''): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($keepRootFile !== '' && $iterator->getDepth() === 0 && $entry->getFilename() === $keepRootFile) {
                continue;
            }
            $removed = $entry->isDir() && !$entry->isLink()
                ? @rmdir($entry->getPathname())
                : @unlink($entry->getPathname());
            if (!$removed && file_exists($entry->getPathname())) {
                throw new RuntimeException('Unable to remove storage entry: ' . $entry->getPathname());
            }
        }
    }

    private static function removeTree(string $directory): void
    {
        if (!is_dir($directory)) return;
        self::clearDirectory($directory);
        @rmdir($directory);
    }

    private static function limit(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    }
}
