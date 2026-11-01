<?php
declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

/**
 * Read at most maxBytes + 1 from the SAPI input stream; null means too large.
 * Content-Length is only an early rejection, never the actual byte budget.
 * PHP owns the buffered input: later handler reads reopen the same body.
 * Multipart bodies are parsed by PHP into $_POST/$_FILES before this runs.
 */
function readRequestBodyBounded(int $maxBytes): ?string
{
    if ($maxBytes < 0 || $maxBytes === PHP_INT_MAX) {
        throw new InvalidArgumentException('Invalid request body limit');
    }
    $declaredLength = $_SERVER['CONTENT_LENGTH'] ?? $_SERVER['HTTP_CONTENT_LENGTH'] ?? null;
    if ($declaredLength !== null && (int)$declaredLength > $maxBytes) {
        return null;
    }
    $stream = fopen('php://input', 'rb');
    if ($stream === false) {
        throw new RuntimeException('Cannot open request body');
    }
    $body = '';
    try {
        while (!feof($stream) && strlen($body) <= $maxBytes) {
            $chunk = fread($stream, min(8192, $maxBytes + 1 - strlen($body)));
            if ($chunk === false) {
                throw new RuntimeException('Cannot read request body');
            }
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
        }
    } finally {
        fclose($stream);
    }
    return strlen($body) > $maxBytes ? null : $body;
}
