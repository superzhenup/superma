<?php
defined('APP_LOADED') or die('Direct access denied.');

final class BackupManager
{
    private const FORMAT_VERSION = 1;
    private const STORAGE_ROOTS = ['covers', 'author_works', 'drama'];

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
        try {
            foreach (self::STORAGE_ROOTS as $storageRoot) {
                $source = $webRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $storageRoot;
                if (!is_dir($source)) continue;
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
        $manifest = [
            'backup_format' => self::FORMAT_VERSION,
            'created_at_utc' => gmdate('c'),
            'application_version' => defined('APP_VERSION') ? APP_VERSION : null,
            'schema_version' => (int)$schema['current'],
            'database_name' => DB_NAME,
            'storage_roots' => self::STORAGE_ROOTS,
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

        $safetyBackup = null;
        if (empty($options['no_safety_backup'])) {
            $safety = self::create(dirname(rtrim($backupDirectory, '/\\')), [
                'mysqldump_bin' => $options['mysqldump_bin'] ?? null,
            ]);
            $safetyBackup = $safety['path'];
        }

        // 审计修复（2026-07-19 M5-2 残留 / N2）：恢复期间加排他锁 + 维护标记。
        // 原实现无并发防护，恢复中途的并发请求可能向半还原的库写入数据混入。
        $lockFp = self::acquireRestoreLock();
        $maintenanceFile = self::webRoot() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . '.maintenance';
        @file_put_contents($maintenanceFile, json_encode([
            'reason' => 'db_restore',
            'time'   => time(),
        ], JSON_UNESCAPED_UNICODE), LOCK_EX);

        try {
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

            // 审计修复（2026-07-19 M5-2 残留）：清理目标库中不在备份清单内的冗余旧表，
            // 避免旧数据残留污染还原结果。
            self::dropRedundantTables($manifest);

            $replaceFiles = !empty($options['replace_files']);
            foreach (self::STORAGE_ROOTS as $storageRoot) {
                $source = self::safeJoin($backupDirectory, 'files/storage/' . $storageRoot);
                if (!is_dir($source)) continue;
                $destination = self::webRoot() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $storageRoot;
                if ($replaceFiles && is_dir($destination)) {
                    self::clearDirectory($destination);
                }
                self::copyTree($source, $destination);
            }
        } finally {
            @unlink($maintenanceFile);
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }

        $schema = DB::schemaStatus();
        return [
            'ok' => true,
            'verification' => $verification,
            'safety_backup' => $safetyBackup,
            'schema' => $schema,
            'migration_required' => !$schema['ready'],
        ];
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

    private static function copyTree(string $source, string $destination): void
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

    private static function clearDirectory(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink()
                ? @rmdir($entry->getPathname())
                : @unlink($entry->getPathname());
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
