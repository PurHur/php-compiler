<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * Illegal-byte substitution for mb_convert_encoding NestedJIT (#25207 leftover).
 *
 * Reads packed {@see MbSubstituteCharacterJitHelper} codes from {@see
 * MbSubstituteCharacterRuntime::G_SUBST_CODE} — peer {@see MbstringState::substitutionOutput}.
 *
 * php-src: ext/mbstring/mbstring.c — libmbfl mbfl_filt_conv_illegal_output()
 */
final class MbConvertSubstJitHelper
{
    /**
     * @param int|null $codepoint Unicode scalar for unconvertible output, or null for illegal input byte
     */
    public static function substitutionOutputArgv(int $packedSubst, string $targetCanon, ?int $codepoint): string
    {
        if ($packedSubst === MbSubstituteCharacterJitHelper::CODE_NONE) {
            return '';
        }
        if ($packedSubst === MbSubstituteCharacterJitHelper::CODE_LONG) {
            if (null === $codepoint) {
                return self::encodeCodepointArgv(0x3F, $targetCanon);
            }

            return self::encodeAsciiMarkupArgv('U+'.strtoupper(dechex($codepoint)), $targetCanon);
        }
        if ($packedSubst === MbSubstituteCharacterJitHelper::CODE_ENTITY) {
            if (null === $codepoint) {
                return self::encodeCodepointArgv(0x3F, $targetCanon);
            }

            return self::encodeAsciiMarkupArgv('&#x'.strtoupper(dechex($codepoint)).';', $targetCanon);
        }
        if ($packedSubst >= 0) {
            return self::encodeCodepointArgv($packedSubst, $targetCanon);
        }

        return self::encodeCodepointArgv(0x3F, $targetCanon);
    }

    public static function scrubSameCharsetArgv(string $value, string $canon, int $packedSubst): string
    {
        if ('UTF-8' === $canon) {
            return self::scrubUtf8Argv($value, $packedSubst);
        }
        if ('ASCII' === $canon) {
            return self::scrubAsciiArgv($value, $packedSubst);
        }

        return $value;
    }

    private static function scrubAsciiArgv(string $value, int $packedSubst): string
    {
        $out = '';
        $len = \strlen($value);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $value[$i];
            if ($ch <= "\x7F") {
                $out .= $ch;
            } else {
                $out .= self::substitutionOutputArgv($packedSubst, 'ASCII', null);
            }
        }

        return $out;
    }

    private static function scrubUtf8Argv(string $value, int $packedSubst): string
    {
        $out = '';
        $len = \strlen($value);
        for ($i = 0; $i < $len; ) {
            $byte = \ord($value[$i]);
            if ($byte < 0x80) {
                $out .= $value[$i];
                ++$i;
                continue;
            }
            $need = 0;
            if (!self::utf8SequenceValidAt($value, $len, $i, $need)) {
                $out .= self::substitutionOutputArgv($packedSubst, 'UTF-8', null);
                ++$i;
                continue;
            }
            $out .= \substr($value, $i, $need + 1);
            $i += $need + 1;
        }

        return $out;
    }

    private static function encodeCodepointArgv(int $codepoint, string $canon): string
    {
        $encoded = self::tryEncodeCodepointArgv($codepoint, $canon);
        if (null !== $encoded) {
            return $encoded;
        }
        if (0x3F !== $codepoint) {
            $encoded = self::tryEncodeCodepointArgv(0x3F, $canon);
            if (null !== $encoded) {
                return $encoded;
            }
        }

        return '';
    }

    private static function tryEncodeCodepointArgv(int $codepoint, string $canon): ?string
    {
        if ('UTF-8' === $canon) {
            return self::codepointToUtf8Argv($codepoint);
        }
        if ('ASCII' === $canon) {
            return $codepoint <= 0x7F ? \chr($codepoint) : null;
        }
        if ('ISO-8859-1' === $canon) {
            return $codepoint <= 0xFF ? \chr($codepoint) : null;
        }

        return null;
    }

    private static function encodeAsciiMarkupArgv(string $ascii, string $canon): string
    {
        if ('UTF-8' === $canon || 'ASCII' === $canon || 'ISO-8859-1' === $canon) {
            return $ascii;
        }

        return $ascii;
    }

    private static function codepointToUtf8Argv(int $cp): string
    {
        if ($cp <= 0x7F) {
            return \chr($cp);
        }
        if ($cp <= 0x7FF) {
            return \chr(0xC0 | ($cp >> 6)).\chr(0x80 | ($cp & 0x3F));
        }
        if ($cp <= 0xFFFF) {
            return \chr(0xE0 | ($cp >> 12))
                .\chr(0x80 | (($cp >> 6) & 0x3F))
                .\chr(0x80 | ($cp & 0x3F));
        }

        return \chr(0xF0 | ($cp >> 18))
            .\chr(0x80 | (($cp >> 12) & 0x3F))
            .\chr(0x80 | (($cp >> 6) & 0x3F))
            .\chr(0x80 | ($cp & 0x3F));
    }

    /**
     * @param-out int $need
     */
    private static function utf8SequenceValidAt(string $value, int $len, int $i, ?int &$need = null): bool
    {
        $byte = \ord($value[$i]);
        if ($byte < 0x80) {
            $need = 0;

            return true;
        }
        if (($byte & 0xE0) === 0xC0) {
            $need = 1;
            $min = 0x80;
        } elseif (($byte & 0xF0) === 0xE0) {
            $need = 2;
            $min = 0x800;
        } elseif (($byte & 0xF8) === 0xF0) {
            $need = 3;
            $min = 0x10000;
        } else {
            $need = 0;

            return false;
        }
        if ($i + $need >= $len) {
            return false;
        }
        $cp = $byte & (0xFF >> (2 + $need));
        for ($j = 1; $j <= $need; ++$j) {
            $next = \ord($value[$i + $j]);
            if (($next & 0xC0) !== 0x80) {
                return false;
            }
            $cp = ($cp << 6) | ($next & 0x3F);
        }
        if ($cp < $min || ($cp >= 0xD800 && $cp <= 0xDFFF)) {
            return false;
        }

        return true;
    }
}
