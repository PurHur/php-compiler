<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Function-local static init tracking SSOT (#10173, php-in-PHP).
 *
 * VM {@see \PHPCompiler\VM\Context} stores per-request init flags; JIT uses
 * {@see \PHPCompiler\ext\standard\FunctionStaticJitHelper} module storage keyed by slot id.
 *
 * php-src: Zend/zend_compile.c — zend_compile_static_variables()
 */
final class VmFunctionStatic
{
    /**
     * Stable i64 slot for JIT/AOT ABI from compile-time storage key.
     */
    public static function slotIdForKey(string $storageKey): int
    {
        $hex = substr(hash('sha256', $storageKey), 0, 16);

        return (int) hexdec($hex);
    }

    /**
     * @param array<string, true> $initialized
     */
    public static function isInitialized(string $storageKey, array $initialized): bool
    {
        return isset($initialized[$storageKey]);
    }

    /**
     * @param array<string, true> $initialized
     */
    public static function markInitialized(string $storageKey, array &$initialized): void
    {
        $initialized[$storageKey] = true;
    }
}
