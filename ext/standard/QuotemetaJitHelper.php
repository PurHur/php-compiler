<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * quotemeta() for compiled JIT/AOT modules (#14705, #21589, #27011, php-in-PHP).
 *
 * Logic mirrors {@see VmString}::quotemeta — self-contained (no VmString call) so NestedJIT
 * helper units are not ExternalMethod-stubbed (#16075 / peer StrRot13JitHelper #26868 /
 * StrrevJitHelper #27007). Per-char transform via private method + match (no `if` in the
 * byte loop — branched loops empty the helper-runtime unit.o under NestedJIT emit).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(quotemeta)
 */
final class QuotemetaJitHelper
{
    public static function quotemetaArgv(string $str): string
    {
        $len = 0;
        while (isset($str[$len])) {
            ++$len;
        }
        $out = '';
        for ($i = 0; $i < $len; ++$i) {
            $out .= self::escapeChar($str[$i]);
        }

        return $out;
    }

    /** NestedJIT-safe quotemeta escape (php-src string.c c); peer StrRot13JitHelper::rot13Char. */
    private static function escapeChar(string $ch): string
    {
        return match ($ch) {
            '.' => '\\.',
            '\\' => '\\\\',
            '+' => '\\+',
            '*' => '\\*',
            '?' => '\\?',
            '[' => '\\[',
            ']' => '\\]',
            '^' => '\\^',
            '(' => '\\(',
            ')' => '\\)',
            '$' => '\\$',
            default => $ch,
        };
    }
}
