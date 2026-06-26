<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * array_map() single-array paths for compiled JIT/AOT modules (#10183, php-in-PHP).
 *
 * SSOT: {@see array_map} VM execute path
 * php-src: ext/standard/array.c — php_array_map()
 */
final class ArrayMapJitHelper
{
    public static function mapNullIdentity(HashTable $src): HashTable
    {
        $out = new HashTable();
        foreach ($src->iterateKeyed(true) as [$key, $value]) {
            $copy = new Variable();
            $copy->copyFrom($value);
            self::appendKeyed($out, $key, $copy);
        }

        return $out;
    }

    public static function mapWithBuiltin(HashTable $src, string $builtinName): HashTable
    {
        $fn = VmInternalCall::resolveStringCallback($builtinName);
        $out = new HashTable();
        foreach ($src->iterateKeyed(true) as [$key, $value]) {
            $mapped = VmInternalCall::invoke($fn, $value);
            self::appendKeyed($out, $key, $mapped);
        }

        return $out;
    }

    private static function appendKeyed(HashTable $out, Variable $key, Variable $value): void
    {
        if (Variable::TYPE_INTEGER === $key->type) {
            $out->addIndex($key->toInt(), $value);

            return;
        }
        $out->add($key->toString(), $value);
    }
}
