<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

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

    public const MODE_ALL_KEY = 4;

    public const MODE_ANY_KEY = 5;

    public static function walkWithBuiltin(HashTable $ht, string $builtinName, int $mode): Variable
    {
        $fn = VmInternalCall::resolveStringCallback($builtinName);
        $unaryUsesKey = self::MODE_ALL_KEY === $mode || self::MODE_ANY_KEY === $mode;
        $out = new Variable();
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            $item = new Variable();
            $item->copyFrom($value);
            $keyVar = new Variable();
            $keyVar->copyFrom($key);
            $result = VmArrayFindInternalInvoke::invoke($fn, $item, $keyVar, $unaryUsesKey);
            $truthy = VmArrayValueCallback::isTruthy($result);
            if (self::MODE_ANY === $mode || self::MODE_ANY_KEY === $mode) {
                if ($truthy) {
                    $out->bool(true);

                    return $out;
                }

                continue;
            }
            if (self::MODE_ALL === $mode || self::MODE_ALL_KEY === $mode) {
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
        if (self::MODE_ANY === $mode || self::MODE_ANY_KEY === $mode) {
            $out->bool(false);
        } elseif (self::MODE_ALL === $mode || self::MODE_ALL_KEY === $mode) {
            $out->bool(true);
        } else {
            $out->null();
        }

        return $out;
    }

    public static function walkWithClosure(HashTable $ht, Variable $closure, int $mode, bool $strict): Variable
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'ArrayFindJitHelper::walkWithClosure() requires an active VM context in this compiler build'
            );
        }
        $closureState = VmClosureCall::resolve($closure);
        $function = self::functionNameForMode($mode);
        $keyFirst = VmArrayValueCallback::callbackKeyFirst($function);
        $out = new Variable();
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            $item = new Variable();
            $item->copyFrom($value);
            $keyVar = new Variable();
            $keyVar->copyFrom($key);
            $result = VmClosureCall::invoke(
                $ctx,
                $closureState,
                $keyFirst ? $keyVar : $item,
                $keyFirst ? $item : $keyVar,
            );
            $matches = VmArrayValueCallback::predicateMatches($result, $strict);
            if (self::MODE_ANY === $mode || self::MODE_ANY_KEY === $mode) {
                if ($matches) {
                    $out->bool(true);

                    return $out;
                }

                continue;
            }
            if (self::MODE_ALL === $mode || self::MODE_ALL_KEY === $mode) {
                if (!$matches) {
                    $out->bool(false);

                    return $out;
                }

                continue;
            }
            if ($matches) {
                if (self::MODE_FIND_KEY === $mode) {
                    $out->copyFrom($key);
                } else {
                    $out->copyFrom($value);
                }

                return $out;
            }
        }
        if (self::MODE_ANY === $mode || self::MODE_ANY_KEY === $mode) {
            $out->bool(false);
        } elseif (self::MODE_ALL === $mode || self::MODE_ALL_KEY === $mode) {
            $out->bool(true);
        } else {
            $out->null();
        }

        return $out;
    }

    private static function functionNameForMode(int $mode): string
    {
        return match ($mode) {
            self::MODE_FIND_KEY => 'array_find_key',
            self::MODE_ANY => 'array_any',
            self::MODE_ALL => 'array_all',
            self::MODE_ALL_KEY => 'array_all_key',
            self::MODE_ANY_KEY => 'array_any_key',
            default => 'array_find',
        };
    }
}
