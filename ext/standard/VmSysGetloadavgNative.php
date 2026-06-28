<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM sys_getloadavg() — libc getloadavg(3) when FFI available, else /proc/loadavg (#12106, #13020).
 *
 * Mirrors {@see SysGetloadavgJitHelper} — {@see VmSysGetloadavgPure} is FFI-free fallback.
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(sys_getloadavg)
 */
final class VmSysGetloadavgNative
{
    public static function available(): bool
    {
        return VmSysGetloadavgLibc::available() || VmSysGetloadavgPure::available();
    }

    /**
     * @return array{0: float, 1: float, 2: float}|false
     */
    public static function getLoadavg(): array|false
    {
        if (VmSysGetloadavgLibc::available()) {
            $libc = VmSysGetloadavgLibc::getLoadavg();
            if (false !== $libc) {
                return $libc;
            }
        }

        return VmSysGetloadavgPure::getLoadavg();
    }
}
