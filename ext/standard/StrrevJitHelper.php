<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * strrev() for compiled JIT/AOT modules (#14566, #21648, #27007, php-in-PHP).
 *
 * Logic mirrors {@see VmString}::strrev — self-contained (no VmString call) so NestedJIT
 * helper units are not ExternalMethod-stubbed (#16075 / peer StrRot13JitHelper #26868 /
 * Bin2hexJitHelper #20452).
 *
 * Ascending index reverse avoids NestedJIT unsigned wrap on `for ($i = $len - 1; $i >= 0; --$i)`
 * (#27007 AOT segfault).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(strrev)
 */
final class StrrevJitHelper
{
    public static function strrevArgv(string $string): string
    {
        $len = 0;
        while (isset($string[$len])) {
            ++$len;
        }
        $out = '';
        for ($i = 0; $i < $len; ++$i) {
            $out .= $string[$len - 1 - $i];
        }

        return $out;
    }
}
