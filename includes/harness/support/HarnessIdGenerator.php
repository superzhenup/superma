<?php
declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

interface HarnessIdGenerator
{
    /** Return exactly $bytes cryptographically random bytes as lowercase hex. */
    public function hex(int $bytes): string;
}

final class SecureHarnessIdGenerator implements HarnessIdGenerator
{
    public function hex(int $bytes): string
    {
        if ($bytes <= 0 || $bytes > 128) {
            throw new InvalidArgumentException('Harness ID byte length is out of range.');
        }
        return bin2hex(random_bytes($bytes));
    }
}
