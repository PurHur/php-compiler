<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/**
 * array_find family string-callback paths for compiled JIT/AOT modules (#14842, #17674, php-in-PHP).
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

    /**
     * Walk hashtable with a compile-time string callback (stdlib builtin or user function).
     */
    public static function walkWithNamedCallback(
        HashTable $ht,
        string $name,
        int $mode,
        bool $strict,
        bool $unaryInternalUsesKey = false,
    ): Variable {
        $ctx = Superglobals::getActiveContext();
        $function = self::functionNameForMode($mode);
        $keyFirst = VmArrayValueCallback::callbackKeyFirst($function) || $unaryInternalUsesKey;
        $unaryUsesKey = $unaryInternalUsesKey;
        try {
            $fn = VmInternalCall::resolveStringCallback($name);
            $invoke = static function (Variable $item, Variable $keyVar) use (
                $fn,
                $unaryUsesKey,
                $keyFirst
            ): Variable {
                return VmArrayFindInternalInvoke::invoke($fn, $item, $keyVar, $unaryUsesKey, $keyFirst);
            };
        } catch (\LogicException) {
            if (null === $ctx) {
                throw new \LogicException(
                    'ArrayFindJitHelper::walkWithNamedCallback() requires an active VM context in this compiler build'
                );
            }
            $userFn = VmUserCall::resolveStringCallback($ctx, $name);
            $invoke = static function (Variable $item, Variable $keyVar) use (
                $ctx,
                $userFn,
                $keyFirst
            ): Variable {
                // Assign ternary results before ARG_SEND — inline `?:` call args leave
                // Temporary slots unbound under NestedJIT (#26824).
                $arg0 = $keyFirst ? $keyVar : $item;
                $arg1 = $keyFirst ? $item : $keyVar;
                // Zend zend_call_function by-ref mismatch Warning (#28928).
                VmCallable::warnPhpFuncByRefValueArgs($ctx, null, $userFn, [$arg0, $arg1]);

                return VmUserCall::invokeTwo($ctx, $userFn, $arg0, $arg1);
            };
        }

        return self::walkWithPredicate($ht, $mode, $strict, $invoke);
    }

    /** @deprecated use walkWithNamedCallback — strict=false preserved for legacy ABI callers */
    public static function walkWithBuiltin(HashTable $ht, string $builtinName, int $mode): Variable
    {
        return self::walkWithNamedCallback($ht, $builtinName, $mode, false);
    }

    public static function walkWithClosure(
        HashTable $ht,
        Variable $closure,
        int $mode,
        bool $strict,
        bool $unaryInternalUsesKey = false,
    ): Variable {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'ArrayFindJitHelper::walkWithClosure() requires an active VM context in this compiler build'
            );
        }
        $closureState = VmClosureCall::resolve($closure);
        $function = self::functionNameForMode($mode);
        $keyFirst = VmArrayValueCallback::callbackKeyFirst($function) || $unaryInternalUsesKey;
        $invoke = static function (Variable $item, Variable $keyVar) use (
            $ctx,
            $closureState,
            $keyFirst
        ): Variable {
            // Assign ternary results before ARG_SEND — inline `?:` call args leave
            // Temporary slots unbound under NestedJIT (#26824).
            $arg0 = $keyFirst ? $keyVar : $item;
            $arg1 = $keyFirst ? $item : $keyVar;
            // Zend zend_call_function by-ref mismatch Warning (#28928).
            VmCallable::warnPhpFuncByRefValueArgs($ctx, null, $closureState->func, [$arg0, $arg1]);

            return VmClosureCall::invoke($ctx, $closureState, $arg0, $arg1);
        };

        return self::walkWithPredicate($ht, $mode, $strict, $invoke);
    }

    /**
     * @param callable(Variable, Variable): Variable $invokePredicate
     */
    private static function walkWithPredicate(
        HashTable $ht,
        int $mode,
        bool $strict,
        callable $invokePredicate
    ): Variable {
        $out = new Variable();
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            $item = new Variable();
            $item->copyFrom($value);
            $keyVar = new Variable();
            $keyVar->copyFrom($key);
            $result = $invokePredicate($item, $keyVar);
            $matches = VmArrayValueCallback::predicateMatches($result, $strict);
            if (self::MODE_ANY === $mode) {
                if ($matches) {
                    $out->bool(true);

                    return $out;
                }

                continue;
            }
            if (self::MODE_ALL === $mode) {
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
        if (self::MODE_ANY === $mode) {
            $out->bool(false);
        } elseif (self::MODE_ALL === $mode) {
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
            default => 'array_find',
        };
    }
}
