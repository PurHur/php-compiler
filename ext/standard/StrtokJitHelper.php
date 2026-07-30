<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for phpc_strtok (#9812, #25171, php-in-PHP).
 *
 * Non-nullable `string` params (+ null flags) keep NestedJIT on __string__* ABI;
 * `string|false` return is boxed `__value__*` (bridge maps false → null __string__*).
 *
 * SSOT: {@see VmString::strtok()} (php-src ext/standard/string.c — PHP_FUNCTION(strtok)).
 */
final class StrtokJitHelper
{
    public static function reset(): void
    {
        VmString::strtokResetState();
    }

    public static function init(string $str, int $isNull): void
    {
        if (0 !== $isNull) {
            self::reset();

            return;
        }
        VmString::strtokInitState($str);
    }

    public static function tokenize(
        string $str,
        string $tok,
        int $init,
        int $strIsNull,
        int $tokIsNull
    ): string|false {
        $strArg = 0 !== $strIsNull ? null : $str;
        $tokArg = 0 !== $tokIsNull ? null : $tok;
        if (0 !== $init) {
            return VmString::strtok($strArg, $tokArg);
        }

        return VmString::strtok($tokArg);
    }
}
