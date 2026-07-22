<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * Record compile-time variadic free functions for ReflectionFunction::isVariadic() (#22045).
 */
final class ReflectionFunctionVariadicLowering
{
    /** @var array<string, true> */
    private static array $variadicFunctions = [];

    public static function recordFunction(string $funcLc): void
    {
        self::$variadicFunctions[strtolower($funcLc)] = true;
    }

    public static function implementLookupFunctions(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_refl_func_is_variadic');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            return;
        }

        $variadic = self::$variadicFunctions;
        self::resetAccumulated();

        foreach (['call_user_func', 'array_map', 'array_multisort'] as $builtin) {
            $variadic[strtolower($builtin)] = true;
        }

        ReflectionFunctionVariadicLookupRuntime::implement(
            $context,
            self::encodeVariadicFunctions($variadic)
        );
        $context->builder->clearInsertionPosition();
    }

    public static function resetAccumulated(): void
    {
        self::$variadicFunctions = [];
    }

    /** @param array<string, true> $variadic */
    private static function encodeVariadicFunctions(array $variadic): string
    {
        if ([] === $variadic) {
            return '[]';
        }

        return (string) json_encode(array_keys($variadic), JSON_THROW_ON_ERROR);
    }
}
