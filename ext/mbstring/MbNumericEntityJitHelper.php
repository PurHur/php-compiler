<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_encode_numericentity()/mb_decode_numericentity() for compiled JIT/AOT modules (#7237, php-in-PHP).
 *
 * SSOT: {@see VmMbstring::encodeNumericEntity()} / {@see VmMbstring::decodeNumericEntity()}
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_encode_numericentity)
 */
final class MbNumericEntityJitHelper
{
    public static function encode4(
        string $str,
        int $m0,
        int $m1,
        int $m2,
        int $m3,
        string $encoding,
        int $isHex
    ): string {
        return self::encodeLongList($str, [$m0, $m1, $m2, $m3], $encoding, $isHex);
    }

    public static function decode4(string $str, int $m0, int $m1, int $m2, int $m3, string $encoding): string
    {
        return self::decodeLongList($str, [$m0, $m1, $m2, $m3], $encoding);
    }

    /**
     * @param list<int> $map
     */
    public static function encodeLongList(string $str, array $map, string $encoding, int $isHex): string
    {
        $encoding = VmMbstring::resolveNumericEntityEncoding($encoding, 'mb_encode_numericentity', 2);
        $convmap = VmMbstring::validateConvMapElements($map, 'mb_encode_numericentity');

        return VmMbstring::encodeNumericEntity($str, $convmap, $encoding, 0 !== $isHex);
    }

    /**
     * @param list<int> $map
     */
    public static function decodeLongList(string $str, array $map, string $encoding): string
    {
        $encoding = VmMbstring::resolveNumericEntityEncoding($encoding, 'mb_decode_numericentity', 2);
        $convmap = VmMbstring::validateConvMapElements($map, 'mb_decode_numericentity');

        return VmMbstring::decodeNumericEntity($str, $convmap, $encoding);
    }
}
