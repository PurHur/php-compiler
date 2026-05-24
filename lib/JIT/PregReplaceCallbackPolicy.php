<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VMVariable;

/**
 * Supported vs deferred preg_replace_callback() callback forms (issue #1177).
 *
 * VM lowers compile-time string user-function names. Closures and other callables stay
 * deferred until user-function / callable JIT lands ([#142](https://github.com/PurHur/php-compiler/issues/142)).
 */
final class PregReplaceCallbackPolicy
{
    public const DEFERRED_SUMMARY =
        'preg_replace_callback callbacks: compile-time string user-function names VM-only; closures deferred';

    public const DEFERRED_KINDS = 'closures, array callables, and invokable objects';

    public const JIT_SUBSET = 'compile-time string user-function names (VM only in this build)';

    public static function isJitLowerable(JITVariable $callback): bool
    {
        return false;
    }

    public static function isVmSupportedType(int $type): bool
    {
        return VMVariable::TYPE_STRING === $type;
    }

    public static function jitRejectionMessage(): string
    {
        return 'preg_replace_callback() is not implemented for JIT in this compiler build (issue #1177); '
            .self::DEFERRED_KINDS.' are deferred (#142)';
    }

    public static function vmRejectionMessage(): string
    {
        return 'preg_replace_callback() callback must be a string user-function name in this compiler build; '
            .self::DEFERRED_KINDS.' are deferred';
    }
}
