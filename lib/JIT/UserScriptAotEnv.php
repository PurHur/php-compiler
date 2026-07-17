<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * SSOT for PHP_COMPILER_AOT_USER_SCRIPT (#20246, #20256).
 *
 * Prefer this (or {@see Context::isUserScriptAot()}) over raw getenv at call sites.
 */
final class UserScriptAotEnv
{
    public static function isActive(): bool
    {
        $userScript = getenv('PHP_COMPILER_AOT_USER_SCRIPT');

        return '1' === $userScript || 'true' === strtolower((string) $userScript);
    }
}
