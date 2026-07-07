<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Func\Internal;

/**
 * Max positional args for internal builtins invoked as array_find-family string callbacks.
 *
 * php-src: zend_call_function trims fci.param_count to the internal handler num_args
 * (ext/standard/array.c php_array_find — value/key pair for closures; unary internals get value only).
 */
final class InternalArityPolicy
{
    /** @var array<class-string<Internal>, int>|null */
    private static ?array $exactMaxArgs = null;

    public static function maxArgsForArrayCallback(Internal $fn): int
    {
        self::bootExactMaxArgs();

        return self::$exactMaxArgs[$fn::class] ?? 1;
    }

    private static function bootExactMaxArgs(): void
    {
        if (null !== self::$exactMaxArgs) {
            return;
        }
        self::$exactMaxArgs = [
            str_contains::class => 2,
            str_starts_with::class => 2,
            str_ends_with::class => 2,
            array_key_exists::class => 2,
        ];
    }
}
