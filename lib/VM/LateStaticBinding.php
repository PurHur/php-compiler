<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Late static binding scope resolution (issue #4792, #10247).
 *
 * php-src: Zend/zend_execute.c — get_called_scope(), ZEND_FETCH_CLASS
 * VM frames use {@see \PHPCompiler\Frame::$calledClass}; JIT/AOT uses runtime class id.
 */
final class LateStaticBinding
{
    /**
     * Effective called class id for standalone JIT/AOT (#4792).
     *
     * Non-zero runtime id wins; otherwise fall back to the declaring scope class id.
     */
    public static function effectiveCalledClassId(int $runtimeCalledClassId, int $declaringScopeClassId): int
    {
        return 0 !== $runtimeCalledClassId ? $runtimeCalledClassId : $declaringScopeClassId;
    }

    /**
     * Resolve `static::` keyword to lowercase class name (php-src get_called_scope).
     */
    public static function resolveLateStaticClassLc(?string $calledClassLc, string $declaringClassLc): string
    {
        if (null !== $calledClassLc && '' !== $calledClassLc) {
            return strtolower($calledClassLc);
        }

        return strtolower($declaringClassLc);
    }
}
