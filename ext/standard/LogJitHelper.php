<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * log() for compiled JIT/AOT modules (#15117, #27047, php-in-PHP).
 *
 * Kernel path: {@see phpc_log_kernel}; VM SSOT remains VmMath::log.
 * Calling VmMath::log / \log from this helper re-enters the MathLog bridge under
 * NestedJIT and yields 0 under thin standalone AOT (#27047 — cos/ceil/hypot peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(log)
 */
final class LogJitHelper
{
    public static function logArgv(float $num): float
    {
        return \phpc_log_kernel($num);
    }
}
