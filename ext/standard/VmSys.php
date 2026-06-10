<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** VM sys_* helpers for stdlib builtins (issue #3464, #4607). */
final class VmSys
{
    /**
     * @return array{0: float, 1: float, 2: float}|false
     */
    public static function getLoadavg()
    {
        return VmSysGetloadavgNative::getLoadavg();
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
