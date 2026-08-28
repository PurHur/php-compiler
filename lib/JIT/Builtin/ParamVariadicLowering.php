<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\BuiltinInternalArgInfo;
use PHPCompiler\BuiltinParamNames;
use PHPCompiler\JIT\Context;

/**
 * Embed internal variadic parameter indices for ReflectionParameter::isVariadic() (#24461).
 *
 * Peer of {@see ReflectionFunctionVariadicLowering} (#22045 / #23593) at parameter granularity.
 */
final class ParamVariadicLowering
{
    public static function implementLookupFunctions(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_param_func_is_variadic');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            return;
        }

        $functionJson = self::encodeFunctionParams(self::buildFunctionParams());
        $methodJson = self::encodeMethodParams(self::buildMethodParams());

        ParamVariadicLookupRuntime::implement($context, $functionJson, $methodJson);
        $context->builder->clearInsertionPosition();
    }

    /** @return array<string, list<int>> */
    private static function buildFunctionParams(): array
    {
        $out = [];
        foreach (BuiltinParamNames::variadicInternalFunctionNames() as $fn) {
            $idx = BuiltinParamNames::variadicParamIndexForFunction($fn);
            if (null !== $idx) {
                $out[strtolower($fn)] = [$idx];
            }
        }

        return $out;
    }

    /** @return array<string, array<string, list<int>>> */
    private static function buildMethodParams(): array
    {
        $out = [];
        foreach (BuiltinInternalArgInfo::methodArityTables() as $classLc => $methods) {
            foreach ($methods as $methodLc => $arity) {
                if (!BuiltinInternalArgInfo::methodIsVariadic($classLc, $methodLc)) {
                    continue;
                }
                $callable = $classLc.'::'.$methodLc;
                $idx = BuiltinParamNames::variadicParamIndexForFunction($callable);
                if (null === $idx) {
                    $total = $arity['total'] ?? 0;
                    if ($total <= 0) {
                        continue;
                    }
                    $idx = $total - 1;
                }
                $out[$classLc][$methodLc] = [$idx];
            }
        }

        return $out;
    }

    /** @param array<string, list<int>> $functionParams */
    private static function encodeFunctionParams(array $functionParams): string
    {
        if ([] === $functionParams) {
            return '{}';
        }

        return (string) json_encode($functionParams, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, array<string, list<int>>> $methodParams */
    private static function encodeMethodParams(array $methodParams): string
    {
        if ([] === $methodParams) {
            return '{}';
        }

        return (string) json_encode($methodParams, JSON_THROW_ON_ERROR);
    }
}
