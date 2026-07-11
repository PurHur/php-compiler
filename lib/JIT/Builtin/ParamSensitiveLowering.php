<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * Record compile-time #[\SensitiveParameter] indices for JIT/AOT reflection (#16130).
 */
final class ParamSensitiveLowering
{
    /** @var array<string, list<int>> */
    private static array $functionParams = [];

    /** @var array<string, array<string, list<int>>> */
    private static array $methodParams = [];

    public static function recordFunction(string $funcLc, int $paramIndex): void
    {
        $funcLc = strtolower($funcLc);
        self::$functionParams[$funcLc] ??= [];
        if (!\in_array($paramIndex, self::$functionParams[$funcLc], true)) {
            self::$functionParams[$funcLc][] = $paramIndex;
        }
    }

    public static function recordMethod(string $classLc, string $methodLc, int $position): void
    {
        $classLc = strtolower($classLc);
        $methodLc = strtolower($methodLc);
        self::$methodParams[$classLc] ??= [];
        self::$methodParams[$classLc][$methodLc] ??= [];
        if (!\in_array($position, self::$methodParams[$classLc][$methodLc], true)) {
            self::$methodParams[$classLc][$methodLc][] = $position;
        }
    }

    public static function implementLookupFunctions(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_param_func_is_sensitive');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            return;
        }

        $functionJson = self::encodeFunctionParams(self::$functionParams);
        $methodJson = self::encodeMethodParams(self::$methodParams);
        self::resetAccumulated();

        ParamSensitiveLookupRuntime::implement($context, $functionJson, $methodJson);
        $context->builder->clearInsertionPosition();
    }

    public static function resetAccumulated(): void
    {
        self::$functionParams = [];
        self::$methodParams = [];
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
