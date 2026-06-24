<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * quoted_printable_encode/decode for compiled JIT/AOT modules (#9910, php-in-PHP).
 *
 * SSOT: {@see VmString::quoted_printable_encode()} / {@see VmString::quoted_printable_decode()}
 * php-src: ext/standard/quot_print.c
 */
final class QuotPrintJitHelper
{
    public static function encode(string $subject): string
    {
        return VmString::quoted_printable_encode($subject);
    }

    public static function decode(string $subject): string
    {
        return VmString::quoted_printable_decode($subject);
    }
}
