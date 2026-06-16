<?php

declare(strict_types=1);

namespace PHPCompiler\ext\lzf;

/**
 * Pure PHP liblzf (Marc Lehmann) — php-src ext/lzf/lzf.c reference (#6384, #8805).
 *
 * HLOG=16, VERY_FAST=1, INIT_HTAB=1 — sole LZF implementation for VM/JIT/AOT (#8852).
 */
final class VmLzfCore
{
    private const HLOG = 16;

    private const HSIZE = 65536;

    private const LZF_MAX_LIT = 32;

    private const LZF_MAX_OFF = 8192;

    private const LZF_MAX_REF = 264;

    public static function compress(string $data): string|false
    {
        $inLen = \strlen($data);
        if (0 === $inLen) {
            return '';
        }
        if ($inLen < 3) {
            return self::compressLiteralOnly($data, $inLen);
        }

        $outCap = $inLen + (int) ($inLen / 64) + 16;
        $out = \array_fill(0, $outCap, 0);
        $op = 1;
        $lit = 0;

        /** @var list<int> $htab */
        $htab = \array_fill(0, self::HSIZE, 0);

        $ip = 0;
        $hval = self::frst($data, $ip);

        while ($ip < $inLen - 2) {
            $hval = self::next($hval, $data, $ip);
            $slot = self::idx($hval);
            $ref = $htab[$slot];
            $htab[$slot] = $ip;

            $off = $ip - $ref - 1;
            if (
                $ref < $ip
                && $off < self::LZF_MAX_OFF
                && $ref > 0
                && $data[$ref + 2] === $data[$ip + 2]
                && \ord($data[$ref + 1]) << 8 | \ord($data[$ref]) === \ord($data[$ip + 1]) << 8 | \ord($data[$ip])
            ) {
                $len = 2;
                $maxlen = $inLen - $ip - $len;
                if ($maxlen > self::LZF_MAX_REF) {
                    $maxlen = self::LZF_MAX_REF;
                }

                if ($op + 3 + 1 >= $outCap) {
                    if ($op - ($lit > 0 ? 0 : 1) + 3 + 1 >= $outCap) {
                        return false;
                    }
                }

                $out[$op - $lit - 1] = $lit - 1;
                if (0 === $lit) {
                    --$op;
                }

                while (true) {
                    if ($maxlen > 16) {
                        for ($chunk = 0; $chunk < 16; ++$chunk) {
                            ++$len;
                            if ($data[$ref + $len] !== $data[$ip + $len]) {
                                break 2;
                            }
                        }
                    }

                    do {
                        ++$len;
                    } while ($len < $maxlen && $data[$ref + $len] === $data[$ip + $len]);

                    break;
                }

                $len -= 2;
                ++$ip;

                if ($len < 7) {
                    $out[$op++] = ($off >> 8) + ($len << 5);
                } else {
                    $out[$op++] = ($off >> 8) + (7 << 5);
                    $out[$op++] = $len - 7;
                }
                $out[$op++] = $off & 0xFF;

                $lit = 0;
                ++$op;

                $ip += $len + 1;

                if ($ip >= $inLen - 2) {
                    break;
                }

                $ip -= 2;
                $hval = self::frst($data, $ip);
                $hval = self::next($hval, $data, $ip);
                $htab[self::idx($hval)] = $ip;
                ++$ip;
                $hval = self::next($hval, $data, $ip);
                $htab[self::idx($hval)] = $ip;
                ++$ip;
            } else {
                if ($op >= $outCap) {
                    return false;
                }

                ++$lit;
                $out[$op++] = \ord($data[$ip++]);

                if ($lit === self::LZF_MAX_LIT) {
                    $out[$op - $lit - 1] = $lit - 1;
                    $lit = 0;
                    ++$op;
                }
            }
        }

        if ($op + 3 > $outCap) {
            return false;
        }

        while ($ip < $inLen) {
            ++$lit;
            $out[$op++] = \ord($data[$ip++]);

            if ($lit === self::LZF_MAX_LIT) {
                $out[$op - $lit - 1] = $lit - 1;
                $lit = 0;
                ++$op;
            }
        }

        $out[$op - $lit - 1] = $lit - 1;
        if (0 === $lit) {
            --$op;
        }

        return self::bytesToString($out, $op);
    }

    public static function decompress(string $data): string|false
    {
        $inLen = \strlen($data);
        if (0 === $inLen) {
            return '';
        }

        $outCap = \max(64, $inLen * 8);
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $result = self::decompressInto($data, $inLen, $outCap);
            if (false !== $result) {
                return $result;
            }
            $outCap *= 2;
        }

        return false;
    }

    private static function decompressInto(string $data, int $inLen, int $outCap): string|false
    {
        /** @var list<int> $out */
        $out = \array_fill(0, $outCap, 0);
        $ip = 0;
        $op = 0;

        while ($ip < $inLen) {
            $ctrl = \ord($data[$ip++]);

            if ($ctrl < 32) {
                ++$ctrl;
                if ($op + $ctrl > $outCap) {
                    return false;
                }
                if ($ip + $ctrl > $inLen) {
                    return false;
                }

                for ($i = 0; $i < $ctrl; ++$i) {
                    $out[$op++] = \ord($data[$ip++]);
                }

                continue;
            }

            $len = $ctrl >> 5;
            $ref = $op - (($ctrl & 0x1F) << 8) - 1;

            if ($ip >= $inLen) {
                return false;
            }

            if (7 === $len) {
                $len += \ord($data[$ip++]);
                if ($ip >= $inLen) {
                    return false;
                }
            }

            $ref -= \ord($data[$ip++]);

            if ($op + $len + 2 > $outCap) {
                return false;
            }
            if ($ref < 0) {
                return false;
            }

            $copyLen = $len + 2;
            if ($op >= $ref + $copyLen) {
                for ($i = 0; $i < $copyLen; ++$i) {
                    $out[$op++] = $out[$ref + $i];
                }
            } else {
                for ($i = 0; $i < $copyLen; ++$i) {
                    $out[$op++] = $out[$ref++];
                }
            }
        }

        return self::bytesToString($out, $op);
    }

    private static function compressLiteralOnly(string $data, int $inLen): string
    {
        /** @var list<int> $out */
        $out = \array_fill(0, $inLen + 2, 0);
        $op = 1;
        $lit = 0;
        for ($ip = 0; $ip < $inLen; ++$ip) {
            ++$lit;
            $out[$op++] = \ord($data[$ip]);
        }
        $out[$op - $lit - 1] = $lit - 1;
        if (0 === $lit) {
            --$op;
        }

        return self::bytesToString($out, $op);
    }

    private static function frst(string $data, int $ip): int
    {
        return (\ord($data[$ip]) << 8) | \ord($data[$ip + 1]);
    }

    private static function next(int $hval, string $data, int $ip): int
    {
        return (($hval << 8) | \ord($data[$ip + 2])) & 0xFFFFFFFF;
    }

    private static function idx(int $h): int
    {
        $h &= 0xFFFFFFFF;

        return (int) ((($h * 0x1E35A7BD) & 0xFFFFFFFF) >> 8) & (self::HSIZE - 1);
    }

    /**
     * @param list<int> $bytes
     */
    private static function bytesToString(array $bytes, int $len): string
    {
        if (0 === $len) {
            return '';
        }

        $packed = \pack('C*', ...\array_slice($bytes, 0, $len));

        return false !== $packed ? $packed : '';
    }
}
