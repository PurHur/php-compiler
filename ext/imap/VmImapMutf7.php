<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

/**
 * Modified UTF-7 (RFC 3501) codec for IMAP mailbox names.
 *
 * Matches c-client / mbstring UTF7-IMAP used by php-src
 * {@code imap_utf7_*} (#27681) and {@code imap_utf8_to_mutf7} / {@code imap_mutf7_to_utf8}.
 * Pure PHP — no runtime/*.c.
 */
final class VmImapMutf7
{
    private const B64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+,';

    /**
     * imap_utf7_encode() — treat each ISO-8859-1 octet as a Unicode code point.
     */
    public static function iso88591ToMutf7(string $latin1): string
    {
        if ('' === $latin1) {
            return '';
        }
        $utf8 = '';
        $n = \strlen($latin1);
        for ($i = 0; $i < $n; ++$i) {
            $utf8 .= self::codepointToUtf8(\ord($latin1[$i]));
        }
        $out = self::utf8ToMutf7($utf8);

        return false === $out ? '' : $out;
    }

    /**
     * imap_utf7_decode() — Modified UTF-7 → ISO-8859-1; false if any code point > 0xFF.
     */
    public static function mutf7ToIso88591(string $mutf7): string|false
    {
        $utf8 = self::mutf7ToUtf8($mutf7);
        if (false === $utf8) {
            return false;
        }
        $out = '';
        $n = \strlen($utf8);
        $i = 0;
        while ($i < $n) {
            $cp = self::utf8CodepointAt($utf8, $i, $consumed);
            if (null === $cp || $cp > 0xFF) {
                return false;
            }
            $out .= \chr($cp);
            $i += $consumed;
        }

        return $out;
    }

    /**
     * imap_utf8_to_mutf7() — empty in → empty string; invalid UTF-8 → false.
     */
    public static function utf8ToMutf7(string $in): string|false
    {
        if ('' === $in) {
            return '';
        }

        $out = '';
        $pending = '';
        $len = \strlen($in);
        $i = 0;
        while ($i < $len) {
            $ord = \ord($in[$i]);
            if ($ord >= 0x20 && $ord <= 0x7e && '&' !== $in[$i]) {
                if ('' !== $pending) {
                    $out .= self::encodeShift($pending);
                    $pending = '';
                }
                $out .= $in[$i];
                ++$i;
                continue;
            }
            if ('&' === $in[$i]) {
                if ('' !== $pending) {
                    $out .= self::encodeShift($pending);
                    $pending = '';
                }
                $out .= '&-';
                ++$i;
                continue;
            }
            $cp = self::utf8CodepointAt($in, $i, $consumed);
            if (null === $cp) {
                return false;
            }
            $pending .= self::utf16BeChar($cp);
            $i += $consumed;
        }
        if ('' !== $pending) {
            $out .= self::encodeShift($pending);
        }

        return $out;
    }

    /**
     * imap_mutf7_to_utf8() — empty in → empty string; malformed → false.
     */
    public static function mutf7ToUtf8(string $in): string|false
    {
        if ('' === $in) {
            return '';
        }

        $out = '';
        $len = \strlen($in);
        $i = 0;
        while ($i < $len) {
            if ('&' !== $in[$i]) {
                $out .= $in[$i];
                ++$i;
                continue;
            }
            ++$i;
            if ($i < $len && '-' === $in[$i]) {
                $out .= '&';
                ++$i;
                continue;
            }
            $b64 = '';
            while ($i < $len && '-' !== $in[$i]) {
                $ch = $in[$i];
                if (
                    ($ch >= 'A' && $ch <= 'Z')
                    || ($ch >= 'a' && $ch <= 'z')
                    || ($ch >= '0' && $ch <= '9')
                    || '+' === $ch
                    || ',' === $ch
                ) {
                    $b64 .= $ch;
                    ++$i;
                    continue;
                }

                return false;
            }
            if ($i >= $len || '-' !== $in[$i] || '' === $b64) {
                return false;
            }
            ++$i;
            $decoded = self::decodeShift($b64);
            if (false === $decoded) {
                return false;
            }
            $out .= $decoded;
        }

        return $out;
    }

    private static function encodeShift(string $utf16Be): string
    {
        $bits = '';
        $n = \strlen($utf16Be);
        for ($i = 0; $i < $n; ++$i) {
            $bits .= \sprintf('%08b', \ord($utf16Be[$i]));
        }
        $encoded = '';
        $bitLen = \strlen($bits);
        for ($i = 0; $i < $bitLen; $i += 6) {
            $chunk = \substr($bits, $i, 6);
            if (\strlen($chunk) < 6) {
                $chunk = \str_pad($chunk, 6, '0');
            }
            $encoded .= self::B64[\bindec($chunk)];
        }

        return '&'.$encoded.'-';
    }

    private static function decodeShift(string $b64): string|false
    {
        $bits = '';
        $n = \strlen($b64);
        for ($i = 0; $i < $n; ++$i) {
            $pos = \strpos(self::B64, $b64[$i]);
            if (false === $pos) {
                return false;
            }
            $bits .= \sprintf('%06b', $pos);
        }
        // Drop padding bits that do not form a full UTF-16 code unit.
        $usable = \intdiv(\strlen($bits), 16) * 16;
        if (0 === $usable) {
            return false;
        }
        $bits = \substr($bits, 0, $usable);
        $utf16 = '';
        for ($i = 0; $i < $usable; $i += 8) {
            $utf16 .= \chr(\bindec(\substr($bits, $i, 8)));
        }
        $out = '';
        $len = \strlen($utf16);
        for ($i = 0; $i < $len; $i += 2) {
            if ($i + 1 >= $len) {
                return false;
            }
            $unit = (\ord($utf16[$i]) << 8) | \ord($utf16[$i + 1]);
            if ($unit >= 0xd800 && $unit <= 0xdbff) {
                if ($i + 3 >= $len) {
                    return false;
                }
                $low = (\ord($utf16[$i + 2]) << 8) | \ord($utf16[$i + 3]);
                if ($low < 0xdc00 || $low > 0xdfff) {
                    return false;
                }
                $cp = 0x10000 + (($unit - 0xd800) << 10) + ($low - 0xdc00);
                $out .= self::codepointToUtf8($cp);
                $i += 2;
                continue;
            }
            if ($unit >= 0xdc00 && $unit <= 0xdfff) {
                return false;
            }
            $out .= self::codepointToUtf8($unit);
        }

        return $out;
    }

    private static function utf8CodepointAt(string $s, int $i, ?int &$consumed = null): ?int
    {
        $ord = \ord($s[$i]);
        $len = \strlen($s);
        if ($ord < 0x80) {
            $consumed = 1;

            return $ord;
        }
        if (($ord & 0xe0) === 0xc0) {
            if ($i + 1 >= $len) {
                return null;
            }
            $consumed = 2;

            return (($ord & 0x1f) << 6) | (\ord($s[$i + 1]) & 0x3f);
        }
        if (($ord & 0xf0) === 0xe0) {
            if ($i + 2 >= $len) {
                return null;
            }
            $consumed = 3;

            return (($ord & 0x0f) << 12)
                | ((\ord($s[$i + 1]) & 0x3f) << 6)
                | (\ord($s[$i + 2]) & 0x3f);
        }
        if (($ord & 0xf8) === 0xf0) {
            if ($i + 3 >= $len) {
                return null;
            }
            $consumed = 4;

            return (($ord & 0x07) << 18)
                | ((\ord($s[$i + 1]) & 0x3f) << 12)
                | ((\ord($s[$i + 2]) & 0x3f) << 6)
                | (\ord($s[$i + 3]) & 0x3f);
        }

        return null;
    }

    private static function utf16BeChar(int $cp): string
    {
        if ($cp < 0x10000) {
            return \chr(($cp >> 8) & 0xff).\chr($cp & 0xff);
        }
        $cp -= 0x10000;
        $hi = 0xd800 + (($cp >> 10) & 0x3ff);
        $lo = 0xdc00 + ($cp & 0x3ff);

        return \chr(($hi >> 8) & 0xff).\chr($hi & 0xff)
            .\chr(($lo >> 8) & 0xff).\chr($lo & 0xff);
    }

    private static function codepointToUtf8(int $cp): string
    {
        if ($cp < 0x80) {
            return \chr($cp);
        }
        if ($cp < 0x800) {
            return \chr(0xc0 | ($cp >> 6)).\chr(0x80 | ($cp & 0x3f));
        }
        if ($cp < 0x10000) {
            return \chr(0xe0 | ($cp >> 12))
                .\chr(0x80 | (($cp >> 6) & 0x3f))
                .\chr(0x80 | ($cp & 0x3f));
        }

        return \chr(0xf0 | ($cp >> 18))
            .\chr(0x80 | (($cp >> 12) & 0x3f))
            .\chr(0x80 | (($cp >> 6) & 0x3f))
            .\chr(0x80 | ($cp & 0x3f));
    }
}
