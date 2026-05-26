<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * M3/M4 emit-helper TU mode detection (#1937, #2599).
 *
 * Link-time and runtime: real Runtime init (RuntimeEmitTuInit) when any emit-TU
 * env is set — not only PHP_COMPILER_M3_EMIT_MINIMAL.
 */
final class EmitTuMode
{
    /** @var list<string> */
    private const MINIMAL_RUNTIME_ENV_KEYS = [
        'PHP_COMPILER_M3_EMIT_MINIMAL',
        'PHP_COMPILER_EMIT_HELPER_LINK',
        'PHP_COMPILER_M3_EMIT_TU',
    ];

    public static function isMinimalRuntime(): bool
    {
        foreach (self::MINIMAL_RUNTIME_ENV_KEYS as $key) {
            $flag = getenv($key);
            if ('1' === $flag || 'true' === strtolower((string) $flag)) {
                return true;
            }
        }

        return false;
    }
}
