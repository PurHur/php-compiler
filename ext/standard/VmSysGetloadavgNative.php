<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM sys_getloadavg() — {@see VmSysGetloadavgPure} SSOT, no libc FFI (#12106, #13564).
 *
 * Mirrors {@see SysGetloadavgJitHelper} — host `\sys_getloadavg()` or `/proc/loadavg` fallback.
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(sys_getloadavg)
 */
final class VmSysGetloadavgNative
{
    public static function available(): bool
    {
        return VmSysGetloadavgPure::available();
    }

    /**
     * @return array{0: float, 1: float, 2: float}|false
     */
    public static function getLoadavg(): array|false
    {
        return VmSysGetloadavgPure::getLoadavg();
    }
}
