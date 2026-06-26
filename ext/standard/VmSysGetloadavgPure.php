<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Pure-PHP sys_getloadavg() via /proc/loadavg on Linux (#12106, php-in-PHP).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(sys_getloadavg)
 */
final class VmSysGetloadavgPure
{
    public static function available(): bool
    {
        return \is_readable('/proc/loadavg');
    }

    /**
     * @return array{0: float, 1: float, 2: float}|false
     */
    public static function getLoadavg(): array|false
    {
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
