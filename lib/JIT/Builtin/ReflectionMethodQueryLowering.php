<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\VM\ParamArgumentCountError;
use PHPCompiler\VM\ReflectionSupport;

/**
 * Record compile-time method arity for thin AOT
 * ReflectionMethod::{getNumberOfParameters,getNumberOfRequiredParameters} (#34216).
 *
 * Visibility queries use {@see ReflectionMethodVisibilityRuntime} (peer #34047).
 * Peer of {@see ReflectionFunctionParamCountLowering} (#34218).
 */
final class ReflectionMethodQueryLowering
{
    /** @var array<string, array<string, array{params: int, required: int}>> */
    private static array $recordedMethods = [];

    public static function recordUserMethod(string $className, string $methodName, int $params, int $required): void
    {
        if ('' === $className || '' === $methodName) {
            return;
        }
        self::$recordedMethods[$className][$methodName] = [
            'params' => max(0, $params),
            'required' => max(0, min($required, max(0, $params))),
        ];
    }

    public static function recordUserMethodFromBlock(string $className, string $methodName, \PHPCompiler\Block $block): void
    {
        $paramNames = array_values($block->paramNames);
        $required = 0;
        for ($i = 0, $n = \count($paramNames); $i < $n; ++$i) {
            if (ReflectionSupport::parameterIsVariadic($block, $i)
                || ParamArgumentCountError::parameterHasDefault($block, $i)
            ) {
                break;
            }
            ++$required;
        }
        self::recordUserMethod($className, $methodName, \count($paramNames), $required);
    }

    public static function implementLookupFunctions(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_refl_method_param_count');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            return;
        }

        $recorded = self::$recordedMethods;
        self::resetAccumulated();

        ReflectionMethodQueryLookupRuntime::implement($context, self::encodeTable($recorded));
        $context->builder->clearInsertionPosition();
    }

    public static function resetAccumulated(): void
    {
        self::$recordedMethods = [];
    }

    /** @param array<string, array<string, array{params: int, required: int}>> $table */
    private static function encodeTable(array $table): string
    {
        if ([] === $table) {
            return '{}';
        }

        return (string) json_encode($table, JSON_THROW_ON_ERROR);
    }
}
