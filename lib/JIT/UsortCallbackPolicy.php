<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VMVariable;

/**
 * Supported vs deferred usort() callback forms (issue #1210).
 *
 * JIT/AOT lowers compile-time string builtins strcmp only (same packed sort as sort()).
 * strcasecmp is VM-only until a case-insensitive hashtable sort lands. Closures and other
 * callables stay deferred ([#142](https://github.com/PurHur/php-compiler/issues/142)).
 */
final class UsortCallbackPolicy
{
    public const DEFERRED_SUMMARY =
        'usort callbacks: compile-time strcmp for JIT/AOT; strcasecmp VM-only; closures deferred';

    public const DEFERRED_KINDS = 'closures, array callables, and invokable objects';

    public const JIT_SUBSET = 'compile-time strcmp';

    /** @var array<string, true> */
    private const VM_STRING_CALLBACKS = [
        'strcmp' => true,
        'strcasecmp' => true,
    ];

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
        if (JITVariable::TYPE_STRING !== $type || null === $compileTimeString) {
            return false;
        }

        return 'strcmp' === strtolower($compileTimeString);
    }

    public static function isVmSupportedType(int $type): bool
    {
        return VMVariable::TYPE_STRING === $type;
    }

    public static function isVmSupportedName(string $name): bool
    {
        return isset(self::VM_STRING_CALLBACKS[strtolower($name)]);
    }

    public static function jitRejectionMessage(): string
    {
        return 'usort() callback must be '.self::JIT_SUBSET
            .' for JIT/AOT in this compiler build; '.self::DEFERRED_KINDS.' are deferred';
    }

    public static function vmRejectionMessage(): string
    {
        return 'usort() callback must be strcmp or strcasecmp in this compiler build; '
            .self::DEFERRED_KINDS.' are deferred';
    }
}
