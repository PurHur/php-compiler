<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\Variable as VMVariable;

/**
 * Supported vs deferred set_error_handler() callback forms (issue #1379).
 */
final class ErrorHandlerCallbackPolicy
{
    public const DEFERRED_SUMMARY =
        'set_error_handler callbacks: string user-function names VM-only; closures deferred';

    public const DEFERRED_KINDS = 'closures, array callables, and invokable objects';

    public static function isVmSupportedType(int $type): bool
    {
        return VMVariable::TYPE_STRING === $type || VMVariable::TYPE_NULL === $type;
    }

    public static function vmRejectionMessage(): string
    {
        return 'set_error_handler() callback must be null or a string user-function name in this compiler build; '
            .self::DEFERRED_KINDS.' are deferred (#1379, #142)';
    }

    public static function isJitLowerable(Variable $callback): bool
    {
        return self::isJitLowerableScalar(
            $callback->type,
            $callback->isNullConstant,
            $callback->compileTimeString
        );
    }

    public static function isJitLowerableScalar(int $type, bool $isNullConstant, ?string $compileTimeString): bool
    {
        if ($isNullConstant) {
            return false;
        }

        return Variable::TYPE_STRING === $type && null !== $compileTimeString;
    }

    public static function jitRejectionMessage(): string
    {
        return 'set_error_handler() callback must be a compile-time string function name in this compiler build; '
            .self::DEFERRED_KINDS.' are deferred (#1379)';
    }
}
