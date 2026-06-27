<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VMVariable;

/**
 * Supported vs deferred array_map() callback forms (issue #1154).
 *
 * JIT/AOT lowers null (identity copy), compile-time string stdlib builtins, and closure/arrow
 * callbacks with native int/double returns ([#142](https://github.com/PurHur/php-compiler/issues/142)).
 * Array callables ([Class::class, 'method']) and invokable objects stay deferred (#1154).
 */
final class ArrayMapCallbackPolicy
{
    public const DEFERRED_SUMMARY =
        'array_map callbacks: null, compile-time string builtins, closure/arrow (int/double); [class, method] callables deferred';

    public const DEFERRED_KINDS = 'array callables and invokable objects';

    public const JIT_SUBSET = 'null, compile-time string stdlib builtin names, or closure/arrow callbacks';

    public static function isClosureJitLowerable(JITVariable $callback): bool
    {
        return null !== $callback->closureCall;
    }

    public static function isJitLowerable(JITVariable $callback): bool
    {
        if (self::isClosureJitLowerable($callback)) {
            return true;
        }

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

    /** Scalar types Zend rejects before callable dispatch (ext/standard/array.c; #12676). */
    public static function isPhpSrcInvalidCallbackType(int $type): bool
    {
        return \in_array($type, [
            VMVariable::TYPE_INTEGER,
            VMVariable::TYPE_BOOLEAN,
            VMVariable::TYPE_FLOAT,
            VMVariable::TYPE_ARRAY,
            VMVariable::TYPE_OBJECT,
        ], true);
    }

    /** @see JITVariable type bits for compile-time scalars */
    public static function isJitPhpSrcInvalidCallbackType(int $type): bool
    {
        return \in_array($type, [
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::TYPE_NATIVE_DOUBLE,
            JITVariable::TYPE_NATIVE_BOOL,
            JITVariable::TYPE_HASHTABLE,
            JITVariable::TYPE_OBJECT,
        ], true) || 0 !== ($type & JITVariable::IS_NATIVE_ARRAY);
    }

    /**
     * Zend array_map() invalid callback TypeError (ext/standard/array.c; #12676).
     */
    public static function invalidCallbackTypeError(): string
    {
        return 'array_map(): Argument #1 ($callback) must be a valid callback or null, no array or string given';
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
