<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * substr_compare() for compiled JIT/AOT modules (#13536, php-in-PHP).
 *
 * SSOT: {@see VmString::substr_compare()} — implicit-length path is duplicated here
 * because NestedJIT helper TUs mis-link new VmString statics in AOT (#4297).
 * php-src: ext/standard/string.c — PHP_FUNCTION(substr_compare)
 */
final class SubstrCompareJitHelper
{
    public static function substrCompareArgv(
        string $haystack,
        string $needle,
        int $offset,
        int $length,
        bool $caseInsensitive
    ): int {
        if ($length < 0) {
            return self::compareImplicitLength($haystack, $needle, $offset, $caseInsensitive);
        }

        return VmString::substr_compare($haystack, $needle, $offset, $length, $caseInsensitive);
    }

    /** @internal bridge length=-1 — mirrors {@see VmString::substr_compareImplicitLength()} */
    private static function compareImplicitLength(
        string $haystack,
        string $needle,
        int $offset,
        bool $caseInsensitive
    ): int {
        $hayLen = \strlen($haystack);
        if ($offset < 0) {
            $offset += $hayLen;
            if ($offset < 0) {
                $offset = 0;
            }
        }
        if ($offset > $hayLen) {
            throw new \ValueError('substr_compare(): Argument #3 ($offset) must be contained in argument #1 ($haystack)');
        }
        $needleLen = \strlen($needle);
        $hayRemain = $hayLen - $offset;
        $compareLen = $hayRemain;
        $length = $needleLen > $hayRemain ? $hayRemain : $needleLen;
        $s1 = \substr($haystack, $offset, $length);
        $cmp = $caseInsensitive
            ? \strncasecmp($s1, $needle, $length)
            : \strncmp($s1, $needle, $length);
        if (0 !== $cmp) {
            return $cmp < 0 ? -1 : 1;
        }
        if ($compareLen !== $needleLen) {
            return $compareLen < $needleLen ? -1 : 1;
        }

        return 0;
    }
}
