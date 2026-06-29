<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Pure-PHP sys_getloadavg() — host builtin when available, else /proc/loadavg (#12106, #13564).
 *
 * Under Zend PHP bootstrap, {@see \sys_getloadavg()} provides full double precision without libc FFI.
 * Self-host AOT without host builtins falls back to /proc/loadavg (~2 decimal digits).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(sys_getloadavg)
 */
final class VmSysGetloadavgPure
{
    public static function available(): bool
    {
        return \function_exists('sys_getloadavg') || \is_readable('/proc/loadavg');
    }

    /**
     * @return array{0: float, 1: float, 2: float}|false
     */
    public static function getLoadavg(): array|false
    {
        if (\function_exists('sys_getloadavg')) {
            $loads = @\sys_getloadavg();
            if (\is_array($loads) && \count($loads) >= 3) {
                /** @var array{0: float, 1: float, 2: float} */
                return [(float) $loads[0], (float) $loads[1], (float) $loads[2]];
            }
        }

        return self::getLoadavgFromProc();
    }

    /**
     * @return array{0: float, 1: float, 2: float}|false
     */
    private static function getLoadavgFromProc(): array|false
    {
        if (!\is_readable('/proc/loadavg')) {
            return false;
        }

        $raw = @\file_get_contents('/proc/loadavg');
        if (false === $raw || '' === $raw) {
            return false;
        }

        $parts = \preg_split('/\s+/', \trim($raw), 4);
        if (!\is_array($parts) || \count($parts) < 3) {
            return false;
        }

        $loads = [];
        for ($i = 0; $i < 3; ++$i) {
            if (!\is_numeric($parts[$i])) {
                return false;
            }
            $loads[] = (float) $parts[$i];
        }

        /** @var array{0: float, 1: float, 2: float} */
        return [$loads[0], $loads[1], $loads[2]];
    }
}
