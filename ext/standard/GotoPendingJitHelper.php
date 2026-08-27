<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/** JIT/AOT: break/continue must resume after finally (#35547). */
final class GotoPendingJitHelper
{
    private static bool $pending = false;

    public static function clearGotoPending(): void
    {
        self::$pending = false;
    }

    public static function hasGotoPending(): bool
    {
        return self::$pending;
    }

    public static function setGotoPending(): void
    {
        self::$pending = true;
    }

    /** @internal test reset */
    public static function resetForTest(): void
    {
        self::clearGotoPending();
    }
}
