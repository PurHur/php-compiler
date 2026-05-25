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
        return VMVariable::TYPE_STRING === $type;
    }

    public static function vmRejectionMessage(): string
    {
        return 'spl_autoload_register() callback must be a string user-function name in this compiler build; '
            .self::DEFERRED_KINDS.' are deferred (#1369, #1776)';
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

        return Variable::TYPE_STRING === $type && null !== $compileTimeString && !str_contains($compileTimeString, '::');
    }

    public static function jitRejectionMessage(): string
    {
        return 'spl_autoload_register() callback must be a compile-time string function name in this compiler build; '
            .self::DEFERRED_KINDS.' are deferred (#1776)';
    }
}
