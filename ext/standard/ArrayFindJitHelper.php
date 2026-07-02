<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * array_find family string-builtin path for compiled JIT/AOT modules (#14842, php-in-PHP).
 *
 * SSOT shared with {@see array_find} VM execute() via {@see VmArrayValueCallback}.
 * php-src: ext/standard/array.c — php_array_find, php_array_find_key, php_array_any, php_array_all
 */
final class ArrayFindJitHelper
{
    public const MODE_FIND = 0;

    public const MODE_FIND_KEY = 1;

    public const MODE_ANY = 2;

    public const MODE_ALL = 3;

    public static function walkWithBuiltin(HashTable $ht, string $builtinName, int $mode): Variable
    {
        $fn = VmInternalCall::resolveStringCallback($builtinName);
        $out = new Variable();
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            $item = new Variable();
            $item->copyFrom($value);
            $keyVar = new Variable();
            $keyVar->copyFrom($key);
            $result = VmInternalCall::invoke($fn, $item, $keyVar);
            $truthy = VmArrayValueCallback::isTruthy($result);
            if (self::MODE_ANY === $mode) {
                if ($truthy) {
                    $out->bool(true);

                    return $out;
                }

                continue;
            }
            if (self::MODE_ALL === $mode) {
                if (!$truthy) {
                    $out->bool(false);

                    return $out;
                }

                continue;
            }
            if ($truthy) {
                if (self::MODE_FIND_KEY === $mode) {
                    $out->copyFrom($key);
                } else {
                    $out->copyFrom($value);
                }

                return $out;
            }
        }
        if (self::MODE_ANY === $mode) {
            $out->bool(false);
        } elseif (self::MODE_ALL === $mode) {
            $out->bool(true);
        } else {
            $out->null();
        }

        return $out;
    }
}
