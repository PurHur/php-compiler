<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Lowered into JIT/AOT modules for late-static scope decisions (#10247, php-in-PHP).
 *
 * php-src: Zend/zend_execute.c — get_called_scope()
 * SSOT: {@see LateStaticBinding}
 */
final class LateStaticJitHelper
{
    public static function effectiveCalledClassId(int $runtimeCalledClassId, int $declaringScopeClassId): int
    {
        return LateStaticBinding::effectiveCalledClassId($runtimeCalledClassId, $declaringScopeClassId);
    }
}
