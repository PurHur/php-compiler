<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_substr_count() runtime for compiled JIT/AOT modules (#4637 AOT leftover).
 *
 * NestedJIT must not call {@see VmString::substr_count} / {@see VmString::byteLength} — peer
 * {@see MbSearchJitHelper} (silent-0 under thin NestedJIT). Byte search uses strlen/substr only.
 *
 * SSOT (VM execute path): {@see VmString::substr_count()} after {@see VmMbstring::assertSubstrCountEncoding}
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_substr_count)
 */
final class MbSubstrCountJitHelper
{
    public static function substrCountArgv(string $haystack, string $needle, string $encoding): int
    {
        if ('' === $needle) {
            throw new \ValueError('mb_substr_count(): Argument #2 ($needle) must not be empty');
        }
        $encoding = VmMbstring::resolveNumericEntityEncoding($encoding, 'mb_substr_count', 2);
        VmMbstring::assertSubstrCountEncoding($encoding);

        return self::byteSubstrCount($haystack, $needle);
    }

    private static function byteSubstrCount(string $haystack, string $needle): int
    {
        $needleLen = \strlen($needle);
        if (0 === $needleLen) {
            return 0;
        }
        $count = 0;
        $offset = 0;
        $hayLen = \strlen($haystack);
        while ($offset <= $hayLen - $needleLen) {
            $match = true;
            $i = 0;
            while ($i < $needleLen) {
                if (\substr($haystack, $offset + $i, 1) !== \substr($needle, $i, 1)) {
                    $match = false;
                    break;
                }
                $i = $i + 1;
            }
            if ($match) {
                $count = $count + 1;
                $offset = $offset + $needleLen;
            } else {
                $offset = $offset + 1;
            }
        }

        return $count;
    }
}
