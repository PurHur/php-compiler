<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** Host filesystem helpers for stdlib builtins (VM). */
final class VmFs
{
    /**
     * @param list<string> $names
     */
    public static function stringListToArray(array $names): HashTable
    {
        $ht = new HashTable();
        foreach ($names as $name) {
            $value = new Variable();
            $value->string($name);
            $ht->append($value);
        }

        return $ht;
    }
}
