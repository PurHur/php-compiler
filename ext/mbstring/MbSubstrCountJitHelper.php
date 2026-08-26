<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_substr_count() runtime for compiled JIT/AOT modules (#4637 AOT leftover).
 *
 * NestedJIT must not call {@see VmString::substr_count} / {@see VmString::byteLength} — peer
 * {@see MbSearchJitHelper} (silent-0 under thin NestedJIT). Byte search uses strlen/substr only.
 *
 * Runtime encoding via {@see assertEncodingArgv} (#35155 leftover of #4637 / peer #34884).
 *
 * SSOT (VM execute path): {@see VmString::substr_count()} after {@see VmMbstring::assertSubstrCountEncoding}
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_substr_count)
 */
final class MbSubstrCountJitHelper
{
    /**
     * Int-returning encoding check — NestedJIT ValueError from string-returning helpers
     * SIGSEGVs under thin AOT; int helpers match {@see MbStrwidthJitHelper::assertEncodingArgv} (#35155).
     *
     * Argument #3 ($encoding) for mb_substr_count.
     */
    public static function assertEncodingArgv(string $encoding, string $function): int
    {
        $ok = 0;
        if ('UTF-8' === $encoding || 'utf-8' === $encoding || 'UTF8' === $encoding || 'utf8' === $encoding) {
            $ok = 1;
        }
        if (
            'ASCII' === $encoding || 'ascii' === $encoding
            || 'US-ASCII' === $encoding || 'us-ascii' === $encoding
        ) {
            $ok = 1;
        }
        if ('8BIT' === $encoding || '8bit' === $encoding || 'BINARY' === $encoding || 'binary' === $encoding) {
            $ok = 1;
        }
        if (0 === $ok) {
            // Concat (not sprintf) — NestedJIT sprintf+throw breaks module verify (#34625).
            throw new \ValueError(
                $function.'(): Argument #3 ($encoding) must be a valid encoding, "'.$encoding.'" given'
            );
        }

        return 1;
    }

    public static function substrCountArgv(string $haystack, string $needle, string $encoding): int
    {
        if ('' === $needle) {
            throw new \ValueError('mb_substr_count(): Argument #2 ($needle) must not be empty');
        }
        // Encoding must already be validated via {@see assertEncodingArgv} (#35155).
        unset($encoding);

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
