<?php
defined('APP_LOADED') or die('Direct access denied.');

/**
 * Delete a file only when its resolved path is inside the managed directory.
 */
function deleteManagedAbsoluteFile(?string $filePath, string $managedDirectory): bool
{
    if ($filePath === null || $filePath === '') {
        return false;
    }

    $managedReal = realpath($managedDirectory);
    $fileReal = realpath($filePath);
    if ($managedReal === false || $fileReal === false || !is_file($fileReal)) {
        return false;
    }

    $prefix = rtrim($managedReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $inside = DIRECTORY_SEPARATOR === '\\'
        ? strncasecmp($fileReal, $prefix, strlen($prefix)) === 0
        : strncmp($fileReal, $prefix, strlen($prefix)) === 0;
    if (!$inside) {
        error_log('Refused to delete file outside managed directory: ' . $fileReal);
        return false;
    }

    return @unlink($fileReal);
}

/**
 * Delete one server-managed file referenced by a BASE_PATH-relative path.
 */
function deleteManagedRelativeFile(?string $storedPath, string $managedRelativeDirectory): bool
{
    if ($storedPath === null || $storedPath === '' || preg_match('#^https?://#i', $storedPath)) {
        return false;
    }

    $normalizedPath = str_replace('\\', '/', $storedPath);
    $normalizedDirectory = trim(str_replace('\\', '/', $managedRelativeDirectory), '/');
    $pattern = '#^' . preg_quote($normalizedDirectory, '#') . '/[A-Za-z0-9._-]+$#D';
    if (!preg_match($pattern, $normalizedPath)) {
        error_log('Refused to delete unmanaged relative path: ' . $normalizedPath);
        return false;
    }

    return deleteManagedAbsoluteFile(
        BASE_PATH . '/' . $normalizedPath,
        BASE_PATH . '/' . $normalizedDirectory
    );
}
