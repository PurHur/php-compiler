<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;


use PHPCompiler\Config;

/**
 * SSOT for PHP_COMPILER_AOT_USER_SCRIPT (#20246, #20256).
 *
 * Prefer this (or {@see Context::isUserScriptAot()}) over raw getenv at call sites.
 */
final class UserScriptAotEnv
{
    /** Sticky across NestedJIT env clear during helper compile (#15407, #36245). */
    private static bool $latchedUserScript = false;

    public static function latchUserScript(): void
    {
        if (self::isActive()) {
            self::$latchedUserScript = true;
        }
    }

    public static function resetLatchForTest(): void
    {
        self::$latchedUserScript = false;
    }

    public static function isActive(): bool
    {
        $userScript = Config::getenv('PHP_COMPILER_AOT_USER_SCRIPT');

        return '1' === $userScript || 'true' === strtolower((string) $userScript);
    }

    public static function isActiveOrLatched(): bool
    {
        return self::$latchedUserScript || self::isActive();
    }
}
