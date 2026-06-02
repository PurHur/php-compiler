<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** Host sys_* helpers for stdlib builtins (issue #3464). */
final class VmSys
{
    /**
     * @return array{0: float, 1: float, 2: float}|false
     */
    public static function getLoadavg()
    {
        if (!\function_exists('sys_getloadavg')) {
            return false;
        }
        $avg = @\sys_getloadavg();
        if (false === $avg || !\is_array($avg) || 3 !== \count($avg)) {
            return false;
        }

        return [(float) $avg[0], (float) $avg[1], (float) $avg[2]];
    }

    /**
     * @param array{0: float, 1: float, 2: float} $avg
     */
    public static function loadavgToHashTable(array $avg): HashTable
    {
        $ht = new HashTable();
        foreach ($avg as $load) {
            $value = new Variable();
            $value->float($load);
            $ht->append($value);
        }

        return $ht;
    }
}
