<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\BuiltinParamNames;
use PHPCompiler\JIT\Context;

/**
 * Record compile-time free-function arity + user-defined set for thin AOT
 * ReflectionFunction::{getNumberOfParameters,isUserDefined,isInternal} (#34218).
 *
 * Peer of {@see ReflectionFunctionVariadicLowering} (#22045). Always on (not
 * gated to 8.4 getNamedArguments).
 */
final class ReflectionFunctionParamCountLowering
{
    /** @var array<string, int> */
    private static array $userArity = [];

    public static function recordUserFunction(string $funcLc, int $arity): void
    {
        $lc = strtolower($funcLc);
        if ('' === $lc) {
            return;
        }
        self::$userArity[$lc] = max(0, $arity);
    }

    public static function implementLookupFunctions(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_refl_func_param_count');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            return;
        }

        $userArity = self::$userArity;
        self::resetAccumulated();

        $internalArity = [];
        foreach (['strlen', 'array_map', 'count'] as $builtin) {
            $names = BuiltinParamNames::paramNamesForInternalFunction($builtin);
            if (null !== $names) {
                $internalArity[strtolower($builtin)] = \count($names);
            }
        }
        if (ReflectionInternalFunctionLowering::consumeRuntimeInternalParameterLookup()) {
            $internalArity = ReflectionInternalFunctionLowering::buildAllInternalParamCounts() + $internalArity;
        }

        ReflectionFunctionParamCountLookupRuntime::implement(
            $context,
            self::encodeArityMap($userArity),
            self::encodeArityMap($internalArity),
            self::encodeNameList(array_keys($userArity))
        );
        $context->builder->clearInsertionPosition();
    }

    public static function resetAccumulated(): void
    {
        self::$userArity = [];
    }

    /** @param array<string, int> $map */
    private static function encodeArityMap(array $map): string
    {
        if ([] === $map) {
            return '{}';
        }

        return (string) json_encode($map, JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $names */
    private static function encodeNameList(array $names): string
    {
        if ([] === $names) {
            return '[]';
        }

        return (string) json_encode(array_values($names), JSON_THROW_ON_ERROR);
    }
}
