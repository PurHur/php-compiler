<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VMVariable;

/**
 * Supported vs deferred usort() / uksort() callback forms (issue #1210, #3597).
 *
 * JIT/AOT lowers compile-time strcmp and closure/arrow comparators (int return).
 * strcasecmp/strnatcmp/strnatcasecmp/strcoll are VM-only until dedicated hashtable
 * sort lowering lands. Array callables and invokable objects stay deferred ([#142](https://github.com/PurHur/php-compiler/issues/142)).
 */
final class UsortCallbackPolicy
{
    public const DEFERRED_SUMMARY =
        'usort callbacks: compile-time strcmp + closure/arrow for JIT/AOT; strcmp-family string builtins VM-only';

    public const DEFERRED_KINDS = 'array callables and invokable objects';

    public const JIT_SUBSET = 'compile-time strcmp or closure/arrow comparator';

    public const VM_SUBSET = 'strcmp, strcasecmp, strcoll, strnatcmp, or strnatcasecmp';

    /** @var array<string, true> */
    private const VM_STRING_CALLBACKS = [
        'strcmp' => true,
        'strcasecmp' => true,
        'strcoll' => true,
        'strnatcmp' => true,
        'strnatcasecmp' => true,
    ];

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
        return 'usort() callback must be '.self::VM_SUBSET
            .' in this compiler build; '.self::DEFERRED_KINDS.' are deferred';
    }
}
