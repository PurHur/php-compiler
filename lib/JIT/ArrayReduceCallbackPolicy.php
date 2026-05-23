<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VMVariable;

/**
 * Supported vs deferred array_reduce() callback forms (issue #1213).
 *
 * JIT/AOT: compile-time string stdlib builtins with two arguments only.
 * Closures and other callables stay deferred ([#142](https://github.com/PurHur/php-compiler/issues/142)).
 */
final class ArrayReduceCallbackPolicy
{
    public const DEFERRED_SUMMARY =
        'array_reduce callbacks: compile-time string builtins only; closures and [class, method] callables deferred';

    public const DEFERRED_KINDS = 'closures, array callables, and invokable objects';

    public const JIT_SUBSET = 'compile-time string stdlib builtin names';

    public static function isJitLowerable(JITVariable $callback): bool
    {
        return self::isJitLowerableScalar($callback->type, $callback->compileTimeString);
    }

    public static function isJitLowerableScalar(int $type, ?string $compileTimeString): bool
    {
        return JITVariable::TYPE_STRING === $type && null !== $compileTimeString;
    }

    public static function isVmSupportedType(int $type): bool
    {
        return VMVariable::TYPE_STRING === $type;
    }

    public static function jitRejectionMessage(): string
    {
        return 'array_reduce() callback must be '.self::JIT_SUBSET
            .' for JIT/AOT in this compiler build; '.self::DEFERRED_KINDS.' are deferred';
    }

    public static function vmRejectionMessage(): string
    {
        return 'array_reduce() callback must be a string builtin name in this compiler build; '
            .self::DEFERRED_KINDS.' are deferred';
    }
}
