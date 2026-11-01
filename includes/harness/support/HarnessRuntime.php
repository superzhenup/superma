<?php
declare(strict_types=1);

defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/HarnessClock.php';
require_once __DIR__ . '/HarnessIdGenerator.php';
require_once __DIR__ . '/HarnessSleeper.php';
require_once __DIR__ . '/HarnessAiFake.php';
require_once __DIR__ . '/HarnessFaultInjector.php';

/**
 * Opaque, process-local authority for deterministic Harness test seams.
 *
 * There is deliberately no public issuer. The dedicated PHPUnit bootstrap
 * creates and binds the sole accepted instance through a private bootstrap
 * gate. Merely defining a constant, setting an environment variable or
 * unserializing another instance can therefore never enable test overrides.
 */
final class HarnessTestCapability
{
    private string $bootstrapDigest;

    private function __construct(string $bootstrapSecret)
    {
        $this->bootstrapDigest = hash('sha256', $bootstrapSecret, true);
    }

    /** @internal Used only by HarnessRuntime's identity-and-secret check. */
    public function matchesBootstrapSecret(string $bootstrapSecret): bool
    {
        return hash_equals($this->bootstrapDigest, hash('sha256', $bootstrapSecret, true));
    }

    private function __clone(): void
    {
    }

    public function __serialize(): array
    {
        throw new LogicException('Harness test capability cannot be serialized.');
    }

    public function __unserialize(array $data): void
    {
        unset($data);
        throw new LogicException('Harness test capability cannot be unserialized.');
    }
}

/**
 * Process-local dependency seam for deterministic orchestration tests.
 *
 * Default resolution always selects native production implementations. There
 * is intentionally no environment/config/request lookup and no public
 * capability issuer. Test overrides require the exact authority installed by
 * tests/support/HarnessRuntimeBootstrap.php.
 */
final class HarnessRuntime
{
    private static ?HarnessTestCapability $testCapability = null;
    private static ?string $testBootstrapSecret = null;
    private static ?HarnessClock $clock = null;
    private static ?HarnessIdGenerator $idGenerator = null;
    private static ?HarnessSleeper $sleeper = null;
    private static ?HarnessAiFake $aiFake = null;
    private static ?HarnessFaultInjector $faultInjector = null;
    private static bool $testingOverridesInstalled = false;

    public static function installForTesting(
        HarnessTestCapability $capability,
        ?HarnessClock $clock = null,
        ?HarnessIdGenerator $idGenerator = null,
        ?HarnessSleeper $sleeper = null,
        ?HarnessAiFake $aiFake = null,
        ?HarnessFaultInjector $faultInjector = null
    ): void {
        self::assertCapability($capability);
        self::$clock = $clock;
        self::$idGenerator = $idGenerator;
        self::$sleeper = $sleeper;
        self::$aiFake = $aiFake;
        self::$faultInjector = $faultInjector;
        self::$testingOverridesInstalled = true;
    }

    public static function resetForTesting(HarnessTestCapability $capability): void
    {
        self::assertCapability($capability);
        self::$clock = null;
        self::$idGenerator = null;
        self::$sleeper = null;
        self::$aiFake = null;
        self::$faultInjector = null;
        self::$testingOverridesInstalled = false;
    }

    /**
     * Run one callback with deterministic seams and restore the exact previous
     * process state even when the callback throws. Nested scopes are supported.
     */
    public static function withTestingOverrides(
        HarnessTestCapability $capability,
        callable $callback,
        ?HarnessClock $clock = null,
        ?HarnessIdGenerator $idGenerator = null,
        ?HarnessSleeper $sleeper = null,
        ?HarnessAiFake $aiFake = null,
        ?HarnessFaultInjector $faultInjector = null
    ): mixed {
        self::assertCapability($capability);
        $previous = self::testingState();
        self::installForTesting(
            $capability,
            $clock,
            $idGenerator,
            $sleeper,
            $aiFake,
            $faultInjector
        );

        try {
            return $callback();
        } finally {
            self::restoreTestingState($previous);
        }
    }

    public static function testingOverridesActive(HarnessTestCapability $capability): bool
    {
        self::assertCapability($capability);
        return self::$testingOverridesInstalled;
    }

    /**
     * Atomically detect and clear an unscoped override left by a prior test.
     * PHPUnit's lifecycle guard calls this both before and after every test.
     */
    public static function clearTestingOverrideLeak(HarnessTestCapability $capability): bool
    {
        self::assertCapability($capability);
        $leaked = self::$testingOverridesInstalled;
        if ($leaked) {
            self::resetForTesting($capability);
        }
        return $leaked;
    }

    public static function unixTime(): int
    {
        return self::clock()->unixTime();
    }

    public static function preciseTime(): float
    {
        return self::clock()->preciseTime();
    }

    public static function sqlNow(): string
    {
        return self::clock()->sqlDateTime();
    }

    public static function sqlAfterSeconds(int $seconds): string
    {
        return self::clock()->sqlDateTime(self::unixTime() + $seconds);
    }

    public static function randomHex(int $bytes): string
    {
        if ($bytes <= 0 || $bytes > 128) {
            throw new InvalidArgumentException('Harness ID byte length is out of range.');
        }
        $value = self::idGenerator()->hex($bytes);
        $expectedLength = $bytes * 2;
        if (strlen($value) !== $expectedLength
            || preg_match('/^[a-f0-9]+$/D', $value) !== 1) {
            throw new RuntimeException('Harness ID generator returned a non-canonical value.');
        }
        return $value;
    }

    /**
     * Best-effort diagnostic ID for fatal/shutdown recovery code.
     *
     * Unlike lease/token generation this method must never propagate an
     * entropy-provider or test-double failure: recovery durability is more
     * important than cryptographic uniqueness for a human-facing trace ID.
     */
    public static function diagnosticHex(int $bytes = 6): string
    {
        $safeBytes = ($bytes > 0 && $bytes <= 128) ? $bytes : 6;
        try {
            return self::randomHex($safeBytes);
        } catch (Throwable) {
            // Continue with the original non-throwing diagnostic strategy.
        }

        $length = $safeBytes * 2;
        try {
            $seed = uniqid('', true)
                . ':' . (string)getmypid()
                . ':' . (string)microtime(true);
            $fallback = substr(hash('sha256', $seed), 0, $length);
            if (strlen($fallback) === $length
                && preg_match('/^[a-f0-9]+$/D', $fallback) === 1) {
                return $fallback;
            }
        } catch (Throwable) {
            // A deterministic final fallback below keeps shutdown code alive.
        }

        return str_repeat('0', $length);
    }

    public static function sleepSeconds(int $seconds): void
    {
        self::sleeper()->sleepSeconds($seconds);
    }

    public static function sleepMicroseconds(int $microseconds): void
    {
        self::sleeper()->sleepMicroseconds($microseconds);
    }

    public static function aiFake(): ?HarnessAiFake
    {
        return self::$aiFake;
    }

    public static function fault(string $point, array $context = []): void
    {
        self::$faultInjector?->hit($point, $context);
    }

    private static function clock(): HarnessClock
    {
        return self::$clock ??= new SystemHarnessClock();
    }

    private static function idGenerator(): HarnessIdGenerator
    {
        return self::$idGenerator ??= new SecureHarnessIdGenerator();
    }

    private static function sleeper(): HarnessSleeper
    {
        return self::$sleeper ??= new NativeHarnessSleeper();
    }

    /**
     * Private friend gate invoked only by the dedicated test bootstrap through
     * a class-scoped closure. Production code has no public issuance path.
     */
    private static function activateTestBootstrap(
        HarnessTestCapability $capability,
        string $bootstrapSecret,
        string $bootstrapFile
    ): void {
        $projectRoot = dirname(__DIR__, 3);
        $expected = realpath($projectRoot . '/tests/support/HarnessRuntimeBootstrap.php');
        $expectedPhpUnitBootstrap = realpath($projectRoot . '/tests/phpunit-bootstrap.php');
        $actual = realpath($bootstrapFile);
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12);
        $caller = isset($trace[1]['file']) ? realpath((string)$trace[1]['file']) : false;
        $loadedByPhpUnitBootstrap = false;
        if ($expectedPhpUnitBootstrap !== false) {
            foreach (array_slice($trace, 2) as $frame) {
                $frameFile = isset($frame['file']) ? realpath((string)$frame['file']) : false;
                if ($frameFile !== false && $frameFile === $expectedPhpUnitBootstrap) {
                    $loadedByPhpUnitBootstrap = true;
                    break;
                }
            }
        }

        if (PHP_SAPI !== 'cli'
            || $expected === false
            || $expectedPhpUnitBootstrap === false
            || $actual === false
            || $caller === false
            || $actual !== $expected
            || $caller !== $expected
            || !$loadedByPhpUnitBootstrap
            || strlen($bootstrapSecret) !== 32
            || !$capability->matchesBootstrapSecret($bootstrapSecret)) {
            throw new LogicException('Harness test bootstrap authority was rejected.');
        }
        if (self::$testCapability !== null && self::$testCapability !== $capability) {
            throw new LogicException('Harness test bootstrap authority is already bound.');
        }

        self::$testCapability = $capability;
        self::$testBootstrapSecret = $bootstrapSecret;
    }

    private static function assertCapability(HarnessTestCapability $capability): void
    {
        $valid = false;
        if (PHP_SAPI === 'cli'
            && self::$testCapability === $capability
            && self::$testBootstrapSecret !== null) {
            try {
                $valid = $capability->matchesBootstrapSecret(self::$testBootstrapSecret);
            } catch (Throwable) {
                $valid = false;
            }
        }
        if (!$valid) {
            throw new LogicException('Harness test overrides are disabled in production.');
        }
    }

    /** @return array{0:?HarnessClock,1:?HarnessIdGenerator,2:?HarnessSleeper,3:?HarnessAiFake,4:?HarnessFaultInjector,5:bool} */
    private static function testingState(): array
    {
        return [
            self::$clock,
            self::$idGenerator,
            self::$sleeper,
            self::$aiFake,
            self::$faultInjector,
            self::$testingOverridesInstalled,
        ];
    }

    /** @param array{0:?HarnessClock,1:?HarnessIdGenerator,2:?HarnessSleeper,3:?HarnessAiFake,4:?HarnessFaultInjector,5:bool} $state */
    private static function restoreTestingState(array $state): void
    {
        [
            self::$clock,
            self::$idGenerator,
            self::$sleeper,
            self::$aiFake,
            self::$faultInjector,
            self::$testingOverridesInstalled,
        ] = $state;
    }

    private function __construct()
    {
    }
}
