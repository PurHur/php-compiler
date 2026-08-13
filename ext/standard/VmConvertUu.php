<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * convert_uuencode()/convert_uudecode() NestedJIT/AOT SSOT (#30811).
 *
 * Peer {@see VmSoundex} / {@see VmLevenshtein}: NestedJIT-bundle with
 * {@see ConvertUuJitHelper}. Use strlen/substr/ord/chr — not string index
 * offset access or 256-arm match tables (thin AOT NestedJIT SIGSEGV).
 * Prefer `$i = $i + 1` over `++$i`.
 *
 * php-src: ext/standard/uuencode.c — php_uuencode / php_uudecode
 */
final class VmConvertUu
{
    public static function encode(string $src): string
    {
        $srcLen = \strlen($src);
        if (0 === $srcLen) {
            return "`\n";
        }
        $out = '';
        $offset = 0;
        while ($offset < $srcLen) {
            $chunkLen = $srcLen - $offset;
            if ($chunkLen > 45) {
                $chunkLen = 45;
            }
            $out = $out.self::encodeChunk($src, $offset, $chunkLen);
            $out = $out."\n";
            $offset = $offset + $chunkLen;
        }
        $out = $out.self::uuEnc(0)."\n";

        return $out;
    }

    private static function encodeChunk(string $data, int $offset, int $chunkLen): string
    {
        $out = self::uuEnc($chunkLen);
        $rel = 0;
        while (($rel + 3) <= $chunkLen) {
            $b0 = \ord(\substr($data, $offset + $rel, 1));
            $b1 = \ord(\substr($data, $offset + $rel + 1, 1));
            $b2 = \ord(\substr($data, $offset + $rel + 2, 1));
            $out = $out.self::uuEnc($b0 >> 2);
            $out = $out.self::uuEnc((($b0 << 4) & 48) | (($b1 >> 4) & 15));
            $out = $out.self::uuEnc((($b1 << 2) & 60) | (($b2 >> 6) & 3));
            $out = $out.self::uuEnc($b2 & 63);
            $rel = $rel + 3;
        }
        if ($rel < $chunkLen) {
            $b0 = \ord(\substr($data, $offset + $rel, 1));
            $b1 = 0;
            if (($rel + 1) < $chunkLen) {
                $b1 = \ord(\substr($data, $offset + $rel + 1, 1));
            }
            $b2 = 0;
            if (($rel + 2) < $chunkLen) {
                $b2 = \ord(\substr($data, $offset + $rel + 2, 1));
            }
            $left = $chunkLen - $rel;
            $out = $out.self::uuEnc($b0 >> 2);
            $out = $out.self::uuEnc((($b0 << 4) & 48) | (($b1 >> 4) & 15));
            if ($left > 1) {
                $out = $out.self::uuEnc((($b1 << 2) & 60) | (($b2 >> 6) & 3));
            } else {
                $out = $out.self::uuEnc(0);
            }
            if ($left > 2) {
                $out = $out.self::uuEnc($b2 & 63);
            } else {
                $out = $out.self::uuEnc(0);
            }
        }

        return $out;
    }

    /**
     * @return string|false
     */
    public static function decode(string $src)
    {
        $srcLen = \strlen($src);
        if (0 === $srcLen) {
            return false;
        }
        $totalLen = 0;
        $out = '';
        $cursor = 0;
        $pos = 0;
        while ($cursor < $srcLen) {
            $lineLen = self::uuDec(\ord(\substr($src, $cursor, 1)));
            $payload = $cursor + 1;
            if (0 === $lineLen) {
                break;
            }
            if ($lineLen > $srcLen) {
                return false;
            }
            $totalLen = $totalLen + $lineLen;
            $width = self::encodedWidth($lineLen);
            $ee = $payload + $width;
            if ($ee > $srcLen) {
                return false;
            }
            $pos = $payload;
            while ($pos < $ee) {
                if (($pos + 4) > $srcLen) {
                    return false;
                }
                $o0 = self::uuDec(\ord(\substr($src, $pos, 1)));
                $o1 = self::uuDec(\ord(\substr($src, $pos + 1, 1)));
                $o2 = self::uuDec(\ord(\substr($src, $pos + 2, 1)));
                $o3 = self::uuDec(\ord(\substr($src, $pos + 3, 1)));
                $out = $out.\chr((($o0 << 2) | ($o1 >> 4)) & 255);
                $out = $out.\chr((($o1 << 4) | ($o2 >> 2)) & 255);
                $out = $out.\chr((($o2 << 6) | $o3) & 255);
                $pos = $pos + 4;
            }
            if ($lineLen < 45) {
                break;
            }
            $cursor = $ee + 1;
        }
        $written = \strlen($out);
        if ($written < $totalLen) {
            $need = $totalLen;
            if ($need > $written) {
                $out = $out.\chr((self::uuDec(\ord(\substr($src, $pos, 1))) << 2 | self::uuDec(\ord(\substr($src, $pos + 1, 1))) >> 4) & 255);
                if ($need > 1) {
                    $out = $out.\chr((self::uuDec(\ord(\substr($src, $pos + 1, 1))) << 4 | self::uuDec(\ord(\substr($src, $pos + 2, 1))) >> 2) & 255);
                    if ($need > 2) {
                        $out = $out.\chr((self::uuDec(\ord(\substr($src, $pos + 2, 1))) << 6 | self::uuDec(\ord(\substr($src, $pos + 3, 1)))) & 255);
                    }
                }
            }
        }
        if (\strlen($out) !== $totalLen) {
            return \substr($out, 0, $totalLen);
        }

        return $out;
    }

    /** php-src floor(len * 1.33); full 45-byte line uses width 60 (no ternary — #26898). */
    private static function encodedWidth(int $lineLen): int
    {
        // Use >= 45 (not ===): NestedJIT has miscompiled strict equality on this path (#26898).
        if ($lineLen >= 45) {
            return 60;
        }

        return \intdiv($lineLen * 133, 100);
    }

    private static function uuEnc(int $c): string
    {
        if (0 === $c) {
            return '`';
        }

        return \chr(($c & 63) + 32);
    }

    private static function uuDec(int $c): int
    {
        return ($c - 32) & 63;
    }
}
