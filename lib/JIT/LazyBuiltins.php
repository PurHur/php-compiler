<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin;

/**
 * On-demand JIT lowering of ext/* module functions (issue #94).
 *
 * Skips eager {@see Runtime::loadJitCompileModuleFuncs} for MCJIT embed mode so
 * cold compile only lowers builtins referenced by the script.
 */
final class LazyBuiltins
{
    public static function isEnabled(int $loadType): bool
    {
        if (Builtin::LOAD_TYPE_EMBED !== $loadType) {
            return false;
        }
        if (EmitTuMode::isMinimalRuntime()) {
            return false;
        }
        if (getenv('PHP_COMPILER_SELFHOST_AOT') === '1') {
            return false;
        }
        $flag = getenv('PHP_COMPILER_JIT_LAZY_BUILTINS');
        if (false !== $flag) {
            return !('0' === $flag || 'false' === strtolower($flag));
        }

        return true;
    }

    /** Cache fingerprint segment when enabled state affects bitcode shape (#153). */
    public static function fingerprintSegment(): string
    {
        $flag = getenv('PHP_COMPILER_JIT_LAZY_BUILTINS');
        if (false === $flag || '' === $flag) {
            return 'default-lazy';
        }

        return 'flag='.strtolower($flag);
    }
}
