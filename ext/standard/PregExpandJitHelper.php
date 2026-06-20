<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * preg_replace() replacement expansion for compiled JIT/AOT modules (#10064, php-in-PHP).
 *
 * SSOT: {@see PregReplacementExpand}; VM path uses the same expand() via {@see VmPregNative}.
 * php-src: ext/pcre/php_pcre.c — replacement string parsing
 */
final class PregExpandJitHelper
{
    /**
     * @param string $packedOvector little-endian int64 pairs [start,end,...] per capture group (16 bytes per group)
     */
    public static function expand(
        string $replacement,
        string $packedOvector,
        int $ovectorCount,
        string $subject
    ): string {
        if ($ovectorCount <= 0) {
            return PregReplacementExpand::expand($replacement, [], 0, $subject);
        }

        return PregReplacementExpand::expand(
            $replacement,
            self::unpackOvector($packedOvector, $ovectorCount),
            $ovectorCount,
            $subject
        );
    }

    /** @return list<int> */
    private static function unpackOvector(string $packed, int $count): array
    {
        $ovector = [];
        $len = \strlen($packed);
        for ($g = 0; $g < $count; $g++) {
            $off = $g * 16;
            if ($off + 16 > $len) {
                break;
            }
            $ovector[] = self::readI64Le($packed, $off);
            $ovector[] = self::readI64Le($packed, $off + 8);
        }

        return $ovector;
    }

    private static function readI64Le(string $bytes, int $offset): int
    {
        $chunk = \substr($bytes, $offset, 8);
        if (8 !== \strlen($chunk)) {
            return -1;
        }

        return (int) \unpack('q', $chunk)[1];
    }
}
