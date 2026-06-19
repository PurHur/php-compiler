<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for phpc_strtok (#9812, php-in-PHP).
 *
 * SSOT: {@see VmString::strtok()} (php-src ext/standard/string.c — PHP_FUNCTION(strtok)).
 */
final class StrtokJitHelper
{
    public static function reset(): void
    {
        VmString::strtokResetState();
    }

    public static function init(?string $str): void
    {
        if (null === $str) {
            self::reset();

            return;
        }
        VmString::strtokInitState($str);
    }

    /**
     * @return ?string null when strtok() would return false (JIT null __string__*)
     */
    public static function tokenize(?string $str, ?string $tok, int $init): ?string
    {
        if (0 !== $init) {
            if (null === $str || null === $tok) {
                self::reset();

                return null;
            }
            $result = VmString::strtok($str, $tok);
        } else {
            if (null === $tok) {
                return null;
            }
            $result = VmString::strtok($tok);
        }

        return false === $result ? null : $result;
    }
}
