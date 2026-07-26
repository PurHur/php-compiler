<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * str_repeat() for compiled JIT/AOT modules (#14602, php-in-PHP).
 *
 * Semantics match {@see VmString::repeat()} (SSOT for VM). The loop is inlined
 * here so NestedJIT/AOT does not call into VmString — that path returned null
 * and segfaulted user-script AOT (#23204 AOT guard).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(str_repeat)
 */
final class StrRepeatJitHelper
{
    public static function strRepeatArgv(string $input, int $times): string
    {
        if ($times < 0) {
            throw new \ValueError('str_repeat(): Argument #2 ($times) must be greater than or equal to 0');
        }
        if (0 === $times || '' === $input) {
            return '';
        }
        $out = '';
        for ($i = 0; $i < $times; ++$i) {
            $out .= $input;
        }

        return $out;
    }
}
