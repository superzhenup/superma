<?php
declare(strict_types=1);
defined('APP_LOADED') or die('Direct access denied.');

/** Accounting adapters own reservation persistence and interpret provider usage. */
interface AIInvocationAccounting
{
    public function beforeRequest(array $modelConfig,array $payload): mixed;
    public function succeeded(mixed $ticket,array $usage): void;
    public function failed(mixed $ticket,Throwable $error,array $usage): void;
}

/** Request-local scope. No database, Harness, secrets, output or network dependencies. */
final class AIInvocationAccountingScope
{
    public const MAX_OUTPUT_TOKENS = 16384;
    private static ?self $active = null;
    private ?Throwable $failure = null;
    private int $suspended = 0;

    private function __construct(private AIInvocationAccounting $accounting,private ?self $parent) {}

    public static function run(AIInvocationAccounting $accounting,callable $callback): mixed
    {
        self::assertHealthy();
        $scope = new self($accounting,self::$active);
        self::$active = $scope;
        try {
            $result = $callback();
            self::assertHealthy();
            return $result;
        } catch (Throwable $error) {
            throw $scope->failure ?? $error;
        } finally {
            self::$active = $scope->parent;
        }
    }

    public static function current(): ?AIInvocationAccounting
    {
        return self::$active !== null && self::$active->suspended === 0 ? self::$active->accounting : null;
    }

    /** The scope's safety limits also apply while another ledger owns accounting. */
    public static function isActive(): bool { return self::$active !== null; }

    public static function suspend(callable $callback): mixed
    {
        self::assertHealthy();
        $scope = self::$active;
        if ($scope === null) return $callback();
        $scope->suspended++;
        try { return $callback(); }
        finally { $scope->suspended--; }
    }

    public static function assertHealthy(): void
    {
        for ($scope=self::$active;$scope!==null;$scope=$scope->parent) {
            if ($scope->failure !== null) throw $scope->failure;
        }
    }

    /** Exactly one HTTP invocation; settlement errors must never settle a second time. */
    public static function performRequest(array $modelConfig,array $payload,callable $request,callable $usage): mixed
    {
        self::assertHealthy();
        $scope = self::$active;
        $accounting = self::current();
        if ($scope === null) return $request();
        if ($accounting === null) {
            try { return $request(); }
            catch (Throwable $error) { self::latchFailure($scope,$error); throw $error; }
        }
        try { $ticket = $accounting->beforeRequest($modelConfig,$payload); }
        catch (Throwable $error) { self::latchFailure($scope,$error); throw $error; }
        try {
            $result = $request();
        } catch (Throwable $error) {
            try { $accounting->failed($ticket,$error,$usage()); }
            catch (Throwable $settlementError) {
                self::latchFailure($scope,$settlementError);
                throw $settlementError;
            }
            self::latchFailure($scope,$error);
            throw $error;
        }
        try { $accounting->succeeded($ticket,$usage()); }
        catch (Throwable $error) { self::latchFailure($scope,$error); throw $error; }
        return $result;
    }

    /** Bridges an independently accounted Gateway failure into the enclosing scope. */
    public static function latch(Throwable $error): void
    {
        if (self::$active !== null) self::latchFailure(self::$active,$error);
    }

    private static function latchFailure(self $scope,Throwable $error): void
    {
        do {
            $scope->failure ??= $error;
            $scope = $scope->parent;
        } while ($scope !== null);
    }
}
