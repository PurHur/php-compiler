<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\Variable as VMVariable;

/**
 * Supported vs deferred spl_autoload_register() callback forms (issue #1776).
 */
final class SplAutoloadCallbackPolicy
{
    public const DEFERRED_KINDS = 'closures, array callables, and invokable objects';

    public static function isVmSupportedType(int $type): bool
    {
        return VMVariable::TYPE_STRING === $type
            || VMVariable::TYPE_ARRAY === $type
            || VMVariable::TYPE_OBJECT === $type;
    }

    /** Scalar types Zend rejects before callable dispatch (#16692). */
    public static function isPhpSrcInvalidCallbackType(int $type): bool
    {
        return \in_array($type, [
            VMVariable::TYPE_INTEGER,
            VMVariable::TYPE_BOOLEAN,
            VMVariable::TYPE_FLOAT,
        ], true);
    }

    /** Compile-time scalars that must TypeError, not defer (#16692). */
    public static function isJitPhpSrcInvalidCallbackType(Variable $callback): bool
    {
        if (null !== $callback->closureCall) {
            return false;
        }

        return \in_array($callback->type, [
            Variable::TYPE_NATIVE_LONG,
            Variable::TYPE_NATIVE_DOUBLE,
            Variable::TYPE_NATIVE_BOOL,
        ], true);
    }

    public static function vmRejectionMessage(): string
    {
        return 'spl_autoload_register() callback must be a valid callable in this compiler build; '
            .self::DEFERRED_KINDS.' are deferred (#1369, #1776)';
    }

    public static function isJitLowerable(Variable $callback): bool
    {
        if (null !== $callback->closureCall) {
            return true;
        }
        $static = self::compileTimeStaticMethodName($callback);
        if (null !== $static) {
            return true;
        }

        return self::isJitLowerableFunctionNameScalar(
            $callback->type,
            $callback->isNullConstant,
            $callback->compileTimeString
        );
    }

    public static function isJitLowerableFunctionNameScalar(int $type, bool $isNullConstant, ?string $compileTimeString): bool
    {
        if ($isNullConstant) {
            return false;
        }

        return Variable::TYPE_STRING === $type
            && null !== $compileTimeString
            && !str_contains($compileTimeString, '::');
    }

    /**
     * Compile-time `Class::method` or `[Class::class, 'method']` static callback (#4744).
     */
    public static function compileTimeStaticMethodName(Variable $callback): ?string
    {
        if (null !== $callback->closureCall) {
            return null;
        }
        $literal = $callback->compileTimeString;
        if (Variable::TYPE_STRING === $callback->type && null !== $literal && str_contains($literal, '::')) {
            return $literal;
        }

        return null;
    }

    public static function jitRejectionMessage(): string
    {
        return 'spl_autoload_register() callback must be a compile-time function name, Class::method, or closure in this compiler build; '
            .self::DEFERRED_KINDS.' are deferred (#1776, #4744)';
    }

    /**
     * Zend spl_autoload_register() invalid callback TypeError (#6244, ext/spl/php_spl.c).
     */
    public static function invalidCallbackTypeError(): string
    {
        return 'spl_autoload_register(): Argument #1 ($callback) must be a valid callback or null, no array or string given';
    }

    /**
     * Zend spl_autoload_unregister() invalid callback TypeError (ext/spl/php_spl.c).
     */
    public static function invalidCallbackTypeErrorUnregister(): string
    {
        return 'spl_autoload_unregister(): Argument #1 ($callback) must be a valid callback, '
            .'no array or string given';
    }
}
