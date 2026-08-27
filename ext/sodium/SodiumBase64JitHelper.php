<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

use PHPCompiler\ext\standard\Base64JitHelper;

/**
 * sodium_bin2base64() for compiled JIT/AOT modules (#35378, leftover #20675).
 *
 * NestedJIT into the user binary via HELPER_BUNDLE with {@see Base64JitHelper}
 * (peer SodiumPadJitHelper #27687 / utf8 bundle #32879).
 * Variant flags match libsodium: bit0 must be set, bit1=no-pad, bit2=urlsafe
 * (php-src ext/sodium/libsodium.c — PHP_FUNCTION(sodium_bin2base64)).
 */
final class SodiumBase64JitHelper
{
    public static function bin2base64Argv(string $string, int $id): string
    {
        if (0x1 !== ($id & ~0x6)) {
            throw new \SodiumException(
                'sodium_bin2base64(): Argument #2 ($id) must be a valid base64 variant identifier'
            );
        }
        $b64 = Base64JitHelper::encodeArgv($string);
        if (0 !== ($id & 0x4)) {
            $b64 = self::toUrlSafe($b64);
        }
        if (0 !== ($id & 0x2)) {
            $b64 = self::stripPad($b64);
        }

        return $b64;
    }

    private static function toUrlSafe(string $b64): string
    {
        $out = '';
        $len = \strlen($b64);
        $i = 0;
        while ($i < $len) {
            $ch = $b64[$i];
            if ('+' === $ch) {
                $out .= '-';
            } elseif ('/' === $ch) {
                $out .= '_';
            } else {
                $out .= $ch;
            }
            ++$i;
        }

        return $out;
    }

    private static function stripPad(string $b64): string
    {
        $len = \strlen($b64);
        while ($len > 0 && '=' === $b64[$len - 1]) {
            --$len;
        }
        if ($len === \strlen($b64)) {
            return $b64;
        }
        $out = '';
        $i = 0;
        while ($i < $len) {
            $out .= $b64[$i];
            ++$i;
        }

        return $out;
    }
}
