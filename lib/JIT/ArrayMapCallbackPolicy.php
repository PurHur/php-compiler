<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VMVariable;

/**
 * Supported vs deferred array_map() callback forms (issue #1154, #36382).
 *
 * JIT/AOT lowers null (identity copy), compile-time string stdlib builtins, closure/arrow
 * callbacks with native int/double returns ([#142](https://github.com/PurHur/php-compiler/issues/142)),
 * and compile-time static / bound array callables `['Class','method']` / `[$this,'method']`
 * (FastRoute DataGenerator, #36382). Invokable objects remain VM-only (#16228).
 */
final class ArrayMapCallbackPolicy
{
    public const DEFERRED_SUMMARY =
        'array_map callbacks: null, compile-time string builtins, closure/arrow (int/double), '
        .'static/bound [class|object, method] array callables; invokable objects deferred';

    public const DEFERRED_KINDS = 'invokable objects';

    public const JIT_SUBSET =
        'null, compile-time string stdlib builtin names, closure/arrow callbacks, '
        .'or compile-time static/bound [Class|$this, method] array callables';

    public static function isClosureJitLowerable(JITVariable $callback): bool
    {
        return null !== $callback->closureCall;
    }

    public static function isJitLowerable(JITVariable $callback): bool
    {
        if (self::isClosureJitLowerable($callback)) {
            return true;
        }
        if (null !== self::compileTimeStaticArrayCallableNames($callback)) {
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

    /**
     * Packed `['Class','method']` literal tracked on {@see JITVariable::$compileTimeArray}.
     *
     * @return array{0:string,1:string}|null
     */
    public static function compileTimeStaticArrayCallableNames(JITVariable $callback): ?array
    {
        $arr = $callback->compileTimeArray;
        if (!\is_array($arr) || !isset($arr[0], $arr[1])) {
            return null;
        }
        if (!\is_string($arr[0]) || !\is_string($arr[1])) {
            return null;
        }
        if ('' === $arr[0] || '' === $arr[1]) {
            return null;
        }

        return [$arr[0], $arr[1]];
    }

    public static function isVmSupportedType(int $type): bool
    {
        return \in_array($type, [VMVariable::TYPE_NULL, VMVariable::TYPE_STRING], true);
    }

    /** Scalar types Zend rejects before callable dispatch (ext/standard/array.c; #12676). */
    public static function isPhpSrcInvalidCallbackType(int $type): bool
    {
        // TYPE_ARRAY / TYPE_OBJECT are not scalar rejects — callables (#25711 / #1154).
        return \in_array($type, [
            VMVariable::TYPE_INTEGER,
            VMVariable::TYPE_BOOLEAN,
            VMVariable::TYPE_FLOAT,
        ], true);
    }

    /** @see JITVariable type bits for compile-time scalars */
    public static function isJitPhpSrcInvalidCallbackType(int $type): bool
    {
        // Arrays (static/bound callables) and objects (closures/invokables) are not scalar rejects.
        return \in_array($type, [
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::TYPE_NATIVE_DOUBLE,
            JITVariable::TYPE_NATIVE_BOOL,
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
        return 'array_map() callback must be null, a string builtin name, a closure, an invokable object, or an array callable in this compiler build';
    }
}
