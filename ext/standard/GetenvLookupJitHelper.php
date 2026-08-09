<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Slim getenv() environ leaf for NestedJIT / thin AOT (#20644, #29313).
 *
 * No static overlay state — keeps NestedJIT TU as small as Rename/Fpow helpers.
 * Overlay + putenv remain on {@see GetenvJitHelper} (embed / non-thin).
 * Leaf is `@getenv` → NestedJIT whitelist {@see getenv_} →
 * {@see \PHPCompiler\JIT\Builtin\StringGetenv::invokeNestedLeaf} (no kernel; chdir #29219).
 * php-src: ext/standard/basic_functions.c — zif_getenv
 */
final class GetenvLookupJitHelper
{
    /**
     * Process environ lookup via host {@see getenv}.
     * localOnly≠0 skips environ (overlay handled by {@see GetenvJitHelper} / bridge).
     * Returns null (not false) so NestedJIT uses {@see __string__*} instead of value-box (#20644).
     */
    public static function fromEnviron(?string $name, int $localOnly): ?string
    {
        if (null === $name || 0 !== $localOnly) {
            return null;
        }
        $val = @\getenv($name);

        return false === $val ? null : $val;
    }
}
