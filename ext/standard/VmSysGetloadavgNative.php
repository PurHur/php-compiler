<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM sys_getloadavg() — pure PHP /proc/loadavg (#12106, #4607).
 *
 * Mirrors {@see SysGetloadavgJitHelper} — no libc getloadavg(3) FFI.
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
