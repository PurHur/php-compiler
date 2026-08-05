<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * PHP SSOT mirror of strtok for NestedJIT experiments / unit checks (#9812, #25171).
 *
 * Thin AOT NestedJIT of this helper aborts (#26906); production JIT/AOT uses
 * {@see \PHPCompiler\JIT\Builtin\StringStrtokJit} module globals (#27645).
 *
 * SSOT for VM: {@see VmString::strtok()} (php-src ext/standard/string.c — PHP_FUNCTION(strtok)).
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
