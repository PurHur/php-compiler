<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\BuiltinParamNames;
use PHPCompiler\JIT\Context;

/**
 * Record compile-time parameter names for ReflectionFunctionAbstract::getNamedArguments() (#17658).
 */
final class ReflectionNamedArgumentsLowering
{
    /** @var array<string, list<string>> */
    private static array $functionParams = [];

    /** @var array<string, array<string, list<string>>> */
    private static array $methodParams = [];

    /** @param list<string> $names */
    public static function recordFunction(string $funcLc, array $names): void
    {
        if ([] === $names) {
            return;
        }
        self::$functionParams[strtolower($funcLc)] = array_values($names);
    }

    /** @param list<string> $names */
    public static function recordMethod(string $classLc, string $methodLc, array $names): void
    {
        if ([] === $names) {
            return;
        }
        self::$methodParams[strtolower($classLc)][strtolower($methodLc)] = array_values($names);
    }

    public static function implementLookupFunctions(Context $context): void
    {
        if (!\PHPCompiler\CompilerVersion::supportsReflectionFunctionGetNamedArguments()) {
            return;
        }

        $probe = $context->module->getNamedFunction('__compiler_refl_func_named_count');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            return;
        }

        $functionParams = self::$functionParams;
        $methodParams = self::$methodParams;
        self::resetAccumulated();
        $functionParams['strlen'] ??= ['string'];

        ReflectionNamedArgumentsLookupRuntime::implement(
            $context,
            self::encodeFunctionParams($functionParams),
            self::encodeMethodParams($methodParams)
        );
        $context->builder->clearInsertionPosition();
    }

    public static function resetAccumulated(): void
    {
        self::$functionParams = [];
        self::$methodParams = [];
    }

    /** @param array<string, list<string>> $functionParams */
    private static function encodeFunctionParams(array $functionParams): string
    {
        if ([] === $functionParams) {
            return '{}';
        }

        return (string) json_encode($functionParams, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, array<string, list<string>>> $methodParams */
    private static function encodeMethodParams(array $methodParams): string
    {
        if ([] === $methodParams) {
            return '{}';
        }

        return (string) json_encode($methodParams, JSON_THROW_ON_ERROR);
    }
}
