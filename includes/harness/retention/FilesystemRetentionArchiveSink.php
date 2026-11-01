<?php

declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/RetentionContracts.php';

final class FilesystemRetentionArchiveSink implements RetentionArchiveSink
{
    /** Canonical, pre-created directory used for every archive operation. */
    private string $directory;
    private string $directoryKey;
    private string $directoryIdentity;
    private string $requestedDirectory;
    private ?string $webRoot = null;
    private ?string $webRootKey = null;
    private ?string $requestedWebRoot = null;

    public function __construct(string $directory, ?string $webRoot = null)
    {
        if (!self::isAbsolutePath($directory)) {
            throw new InvalidArgumentException('Retention archive directory must be absolute.');
        }
        if (self::hasDotSegment($directory)) {
            throw new InvalidArgumentException(
                'Retention archive directory must not contain dot path segments.'
            );
        }
        $realDirectory = self::realDirectory($directory, 'Retention archive directory');
        if (self::containsLinkOrJunction($directory)) {
            throw new InvalidArgumentException(
                'Retention archive directory must not traverse a symlink or junction.'
            );
        }
        $this->requestedDirectory = $directory;
        $this->directory = $realDirectory;
        $this->directoryKey = self::pathKey($realDirectory);
        $this->directoryIdentity = self::directoryIdentity($realDirectory);

        if ($webRoot !== null) {
            if (!self::isAbsolutePath($webRoot)) {
                throw new InvalidArgumentException('Retention web root must be absolute.');
            }
            if (self::hasDotSegment($webRoot)) {
                throw new InvalidArgumentException(
                    'Retention web root must not contain dot path segments.'
                );
            }
            $this->requestedWebRoot = $webRoot;
            $this->webRoot = self::realDirectory($webRoot, 'Retention web root');
            $this->webRootKey = self::pathKey($this->webRoot);
            if (self::isWithin($this->directoryKey, $this->webRootKey)) {
                throw new InvalidArgumentException(
                    'Sensitive Retention archives must be outside the web root.'
                );
            }
        }

        // Re-resolve after all constructor checks. A replaced directory or
        // reparse point must fail before any sensitive bytes are written.
        $this->assertDirectorySafe();
    }

    public function putIdempotent(string $archiveUid, string $bytes, string $contentHash): string
    {
        HarnessPersistenceValue::uid($archiveUid, 'retention_sink.archive_uid');
        HarnessPersistenceValue::hash($contentHash, 'retention_sink.content_hash');
        if (!hash_equals(hash('sha256', $bytes), $contentHash)) {
            throw new InvalidArgumentException('Retention bytes do not match their hash.');
        }
        $directory = $this->assertDirectorySafe();
        $path = $this->path($directory, $archiveUid);
        if (self::isLinkOrJunction($path)) {
            throw new RuntimeException('Existing Retention archive must be a regular file.');
        }
        if (file_exists($path)) {
            if (!is_file($path)) {
                throw new RuntimeException('Existing Retention archive must be a regular file.');
            }
            if (!$this->verify($this->uri($path), $contentHash, strlen($bytes))) {
                throw new RuntimeException('Existing Retention archive conflicts with immutable bytes.');
            }
            return $this->uri($path);
        }
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(8));
        $written = $this->writeExclusive($temporary, $bytes);
        if (!$written || !@chmod($temporary, 0600)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to write protected Retention archive bytes.');
        }
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to publish Retention archive atomically.');
        }
        $this->assertDirectorySafe();
        if (!$this->verify($this->uri($path), $contentHash, strlen($bytes))) {
            throw new RuntimeException('Published Retention archive failed its boundary/hash check.');
        }
        return $this->uri($path);
    }

    public function verify(string $storageUri, string $contentHash, int $sizeBytes): bool
    {
        HarnessSensitiveDataGuard::assertSafeStorageUri($storageUri);
        HarnessPersistenceValue::hash($contentHash, 'retention_sink.verify_hash');
        HarnessPersistenceValue::nonNegativeInt($sizeBytes, 'retention_sink.verify_size');
        try {
            $directory = $this->assertDirectorySafe();
        } catch (Throwable) {
            return false;
        }
        $path = self::fileUriPath($storageUri);
        if ($path === '' || !is_file($path) || self::isLinkOrJunction($path)) {
            return false;
        }
        $realPath = realpath($path);
        if (!is_string($realPath)
            || self::pathKey((string)dirname($realPath)) !== self::pathKey($directory)
            || preg_match('/^[a-f0-9]{32}\.json$/D', basename($realPath)) !== 1
            || filesize($realPath) !== $sizeBytes) {
            return false;
        }
        $actualHash = hash_file('sha256', $realPath);
        return is_string($actualHash) && hash_equals($actualHash, $contentHash);
    }

    private function path(string $directory, string $archiveUid): string
    {
        return rtrim($directory, "\\/") . DIRECTORY_SEPARATOR . $archiveUid . '.json';
    }

    private function uri(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        return 'file:///' . ltrim(str_replace('%2F', '/', rawurlencode($normalized)), '/');
    }

    private static function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        if ($path[0] === '/') {
            return true;
        }
        if (strlen($path) >= 3
            && ctype_alpha($path[0])
            && $path[1] === ':'
            && ($path[2] === '/' || $path[2] === '\\')) {
            return true;
        }
        return str_starts_with($path, '\\\\') || str_starts_with($path, '//');
    }

    private function assertDirectorySafe(): string
    {
        if (self::hasDotSegment($this->requestedDirectory)
            || self::containsLinkOrJunction($this->requestedDirectory)) {
            throw new RuntimeException(
                'Retention archive directory changed into an unsafe path.'
            );
        }
        $real = self::realDirectory($this->requestedDirectory, 'Retention archive directory');
        $key = self::pathKey($real);
        $identity = self::directoryIdentity($real);
        if (!hash_equals($this->directoryKey, $key)
            || !hash_equals($this->directoryIdentity, $identity)) {
            throw new RuntimeException('Retention archive directory identity changed.');
        }
        if ($this->requestedWebRoot !== null && $this->webRootKey !== null) {
            $currentRoot = self::realDirectory(
                $this->requestedWebRoot,
                'Retention web root'
            );
            $rootKey = self::pathKey($currentRoot);
            if (!hash_equals($this->webRootKey, $rootKey) || self::isWithin($key, $rootKey)) {
                throw new RuntimeException('Retention archive directory crossed the web root.');
            }
        }
        return $real;
    }

    private static function realDirectory(string $path, string $field): string
    {
        clearstatcache(true, $path);
        $real = realpath($path);
        if (!is_string($real) || !is_dir($real)) {
            throw new InvalidArgumentException("{$field} must be pre-created and resolvable.");
        }
        return rtrim($real, "\\/");
    }

    private static function directoryIdentity(string $path): string
    {
        clearstatcache(true, $path);
        $stat = @stat($path);
        $device = is_array($stat) ? ($stat['dev'] ?? null) : null;
        $inode = is_array($stat) ? ($stat['ino'] ?? null) : null;
        if (!is_int($device) || !is_int($inode) || $device < 0 || $inode <= 0) {
            throw new RuntimeException(
                'Retention archive filesystem has no stable directory identity.'
            );
        }
        return (string)$device . ':' . (string)$inode;
    }

    private static function hasDotSegment(string $path): bool
    {
        $segments = preg_split('#[\\\\/]#', $path) ?: [];
        return in_array('.', $segments, true) || in_array('..', $segments, true);
    }

    private static function containsLinkOrJunction(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);
        $segments = array_values(array_filter(
            explode('/', $normalized),
            static fn (string $segment): bool => $segment !== ''
        ));
        if ($segments === []) {
            return false;
        }

        if (preg_match('/^[A-Za-z]:$/D', $segments[0]) === 1) {
            $cursor = array_shift($segments) . DIRECTORY_SEPARATOR;
        } elseif (str_starts_with($normalized, '//') && count($segments) >= 2) {
            $cursor = DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR
                . array_shift($segments) . DIRECTORY_SEPARATOR . array_shift($segments);
        } else {
            $cursor = DIRECTORY_SEPARATOR;
        }
        foreach ($segments as $segment) {
            $cursor = rtrim($cursor, "\\/") . DIRECTORY_SEPARATOR . $segment;
            if (self::isLinkOrJunction($cursor)) {
                return true;
            }
            // PHP builds do not consistently expose Windows junctions through
            // is_link()/readlink(). A reparse traversal still resolves to a
            // different canonical component and is therefore rejected.
            $resolved = realpath($cursor);
            if (is_string($resolved)
                && self::pathKey($resolved) !== self::pathKey($cursor)) {
                return true;
            }
        }
        return false;
    }

    private function writeExclusive(string $path, string $bytes): bool
    {
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            return false;
        }
        $offset = 0;
        $length = strlen($bytes);
        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }
            while ($offset < $length) {
                $chunk = substr($bytes, $offset, min(1048576, $length - $offset));
                $count = fwrite($handle, $chunk);
                if (!is_int($count) || $count < 1) {
                    return false;
                }
                $offset += $count;
            }
            if (!fflush($handle)) {
                return false;
            }
            if (function_exists('fsync') && !fsync($handle)) {
                return false;
            }
            return $offset === $length;
        } finally {
            fclose($handle);
        }
    }

    private static function isLinkOrJunction(string $path): bool
    {
        if (is_link($path) || @filetype($path) === 'link') {
            return true;
        }
        // Windows PHP may echo an ordinary file/directory's absolute path.
        // Compare spelling only, never realpath(target) to realpath(path): that
        // would also make a genuine junction and its target look equivalent.
        $linkTarget = function_exists('readlink') ? @readlink($path) : false;
        if (is_string($linkTarget) && self::pathKey($linkTarget) !== self::pathKey($path)) {
            return true;
        }
        $stat = @lstat($path);
        return is_array($stat) && (((int)($stat['mode'] ?? 0) & 0170000) === 0120000);
    }

    private static function pathKey(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
    }

    private static function isWithin(string $pathKey, string $rootKey): bool
    {
        return $pathKey === $rootKey || str_starts_with($pathKey . '/', $rootKey . '/');
    }

    /** Local filesystem path of a file:// URI, or '' when it is not local. */
    public static function fileUriPath(string $uri): string
    {
        $parts = parse_url($uri);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'file') {
            return '';
        }
        $host = (string)($parts['host'] ?? '');
        if ($host !== '' && strtolower($host) !== 'localhost') {
            return '';
        }
        $path = rawurldecode((string)($parts['path'] ?? ''));
        if (DIRECTORY_SEPARATOR === '\\' && preg_match('#^/[A-Za-z]:/#', $path) === 1) {
            $path = substr($path, 1);
        }
        return $path;
    }
}
