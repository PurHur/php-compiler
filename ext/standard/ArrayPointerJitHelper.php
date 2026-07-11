<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * key()/current()/next()/prev()/reset()/end() for compiled JIT/AOT modules (php-in-PHP).
 *
 * SSOT shared with VM execute() via {@see HashTable::pointerKey()} and pointer mutators.
 * php-src: ext/standard/array.c — php_array_key, php_array_current, php_array_next, …
 */
final class ArrayPointerJitHelper
{
    public static function keyArgv(HashTable $ht): Variable
    {
        $out = new Variable();
        $key = $ht->pointerKey();
        if (null === $key) {
            $out->null();
        } else {
            $out->copyFrom($key);
        }

        return $out;
    }

    public static function currentArgv(HashTable $ht): Variable
    {
        return self::valueOrFalse($ht->pointerCurrent());
    }

    public static function nextArgv(HashTable $ht): Variable
    {
        return self::valueOrFalse($ht->pointerNext());
    }

    public static function prevArgv(HashTable $ht): Variable
    {
        return self::valueOrFalse($ht->pointerPrev());
    }

    public static function resetArgv(HashTable $ht): Variable
    {
        return self::valueOrFalse($ht->pointerReset());
    }

    public static function endArgv(HashTable $ht): Variable
    {
        return self::valueOrFalse($ht->pointerEnd());
    }

    private static function valueOrFalse(?Variable $value): Variable
    {
        $out = new Variable();
        if (null === $value) {
            $out->bool(false);
        } else {
            $out->copyFrom($value);
        }

        return $out;
    }
}
