<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VMVariable;

/**
 * Supported vs deferred array_map() callback forms (issue #1154).
 *
 * JIT/AOT lowers null (identity copy) and compile-time string stdlib builtins only.
 * Closures, array callables ([Class::class, 'method']), and invokable objects stay
 * deferred until user-function / callable JIT lands ([#142](https://github.com/PurHur/php-compiler/issues/142)).
 */
final class ArrayMapCallbackPolicy
{
    public const DEFERRED_SUMMARY =
        'array_map callbacks: null or compile-time string builtins only; closures and [class, method] callables deferred';

    public const DEFERRED_KINDS = 'closures, array callables, and invokable objects';

    public const JIT_SUBSET = 'null or compile-time string stdlib builtin names';

    public static function isJitLowerable(JITVariable $callback): bool
    {
        return self::isJitLowerableScalar(
            $callback->type,
            $callback->isNullConstant,
            $callback->compileTimeString
        );
    }

    public static function isJitLowerableScalar(int $type, bool $isNullConstant, ?string $compileTimeString): bool
    {
        if (JITVariable::TYPE_NULL === $type || $isNullConstant) {
            return true;
        }

        return JITVariable::TYPE_STRING === $type && null !== $compileTimeString;
    }

    public static function isVmSupportedType(int $type): bool
    {
        return \in_array($type, [VMVariable::TYPE_NULL, VMVariable::TYPE_STRING], true);
    }

    public static function jitRejectionMessage(): string
    {
        return 'array_map() callback must be '.self::JIT_SUBSET
            .' for JIT/AOT in this compiler build; '.self::DEFERRED_KINDS.' are deferred';
    }

    public static function vmRejectionMessage(): string
    {
        return 'array_map() callback must be null or a string builtin name in this compiler build; '
            .self::DEFERRED_KINDS.' are deferred';
    }
}
