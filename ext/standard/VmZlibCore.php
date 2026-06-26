<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Pure PHP zlib encode/decode — RFC1950/1951/1952 without libz FFI (#8837, #8805 pattern).
 *
 * Deflate/inflate core ported from sdefl/sinfl (fxfactorial/sdefl, public domain).
 * php-src: ext/zlib/zlib.c — php_zlib_encode / php_zlib_decode (behavior reference).
 */
final class VmZlibCore
{
    private const Z_DEFAULT_COMPRESSION = -1;

    private const ENCODING_RAW = 65534;

    private const ENCODING_DEFLATE = 65535;

    private const ENCODING_GZIP = 16;

    private const WIN_SIZ = 32768;

    private const WIN_MSK = 32767;

    private const MIN_MATCH = 4;

    private const MAX_MATCH = 258;

    private const HASH_BITS = 16;

    private const HASH_SIZ = 65536;

    private const HASH_MSK = 65535;

    private const NIL = -1;

    /** @var list<int> */
    private const MIRROR = [
        0, 128, 64, 192, 32, 160, 96, 224, 16, 144, 80, 208, 48, 176, 112, 240,
        8, 136, 72, 200, 40, 168, 104, 232, 24, 152, 88, 216, 56, 184, 120, 248,
        4, 132, 68, 196, 36, 164, 100, 228, 20, 148, 84, 212, 52, 180, 116, 244,
        12, 140, 76, 204, 44, 172, 108, 236, 28, 156, 92, 220, 60, 188, 124, 252,
        2, 130, 66, 194, 34, 162, 98, 226, 18, 146, 82, 210, 50, 178, 114, 242,
        10, 138, 74, 202, 42, 170, 106, 234, 26, 154, 90, 218, 58, 186, 122, 250,
        6, 134, 70, 198, 38, 166, 102, 230, 22, 150, 86, 214, 54, 182, 118, 246,
        14, 142, 78, 206, 46, 174, 110, 238, 30, 158, 94, 222, 62, 190, 126, 254,
        1, 129, 65, 193, 33, 161, 97, 225, 17, 145, 81, 209, 49, 177, 113, 241,
        9, 137, 73, 201, 41, 169, 105, 233, 25, 153, 89, 217, 57, 185, 121, 249,
        5, 133, 69, 197, 37, 165, 101, 229, 21, 149, 85, 213, 53, 181, 117, 245,
        13, 141, 77, 205, 45, 173, 109, 237, 29, 157, 93, 221, 61, 189, 125, 253,
        3, 131, 67, 195, 35, 163, 99, 227, 19, 147, 83, 211, 51, 179, 115, 243,
        11, 139, 75, 203, 43, 171, 107, 235, 27, 155, 91, 219, 59, 187, 123, 251,
        7, 135, 71, 199, 39, 167, 103, 231, 23, 151, 87, 215, 55, 183, 119, 247,
        15, 143, 79, 207, 47, 175, 111, 239, 31, 159, 95, 223, 63, 191, 127, 255,
    ];

    private static ?bool $ffiDisabled = null;

    public static function available(): bool
    {
        if (self::isFfiDisabled()) {
            return true;
        }

        return true;
    }

    public static function gzcompress(string $data, int $level = -1, int $encoding = \ZLIB_ENCODING_DEFLATE): string|false
    {
        $level = self::normalizeLevel($level);

        if (self::isDeflateEncoding($encoding) || (!self::isRawEncoding($encoding) && !self::isGzipEncoding($encoding))) {
            return self::compressZlib($data, $level);
        }
        if (self::isGzipEncoding($encoding)) {
            return self::encodeGzip($data, $level);
        }

        return self::rawDeflate($data, $level);
    }

    public static function gzuncompress(string $data, int $maxLength = 0): string|false
    {
        return self::decodeZlib($data, $maxLength);
    }

    public static function gzdeflate(string $data, int $level = -1, int $encoding = \ZLIB_ENCODING_RAW): string|false
    {
        $level = self::normalizeLevel($level);
        if (self::isGzipEncoding($encoding)) {
            return self::encodeGzip($data, $level);
        }
        if (self::isDeflateEncoding($encoding)) {
            return self::compressZlib($data, $level);
        }

        return self::rawDeflate($data, $level);
    }

    public static function gzinflate(string $data, int $maxLength = 0): string|false
    {
        return self::rawInflate($data, $maxLength);
    }

    public static function gzencode(string $data, int $level = -1, int $encoding = \ZLIB_ENCODING_GZIP): string|false
    {
        $level = self::normalizeLevel($level);
        if (self::isRawEncoding($encoding)) {
            return self::rawDeflate($data, $level);
        }
        if (self::isDeflateEncoding($encoding)) {
            return self::compressZlib($data, $level);
        }

        return self::encodeGzip($data, $level);
    }

    public static function gzdecode(string $data, int $maxLength = 0): string|false
    {
        return self::decodeGzip($data, $maxLength);
    }

    public static function zlib_encode(string $data, int $encoding, int $level = -1): string|false
    {
        $windowBits = self::encodingToWindowBits($encoding);
        if (null === $windowBits) {
            return false;
        }
        $level = self::normalizeLevel($level);
        if (31 === $windowBits) {
            return self::encodeGzip($data, $level);
        }
        if (15 === $windowBits) {
            return self::compressZlib($data, $level);
        }

        return self::rawDeflate($data, $level);
    }

    public static function zlib_decode(string $data, int $maxLength = 0): string|false
    {
        $result = self::inflateAuto($data, $maxLength);
        if (false !== $result) {
            return $result;
        }

        return self::rawInflate($data, $maxLength);
    }

    private static function isFfiDisabled(): bool
    {
        if (null !== self::$ffiDisabled) {
            return self::$ffiDisabled;
        }
        $env = \getenv('PHP_COMPILER_ZLIB_FFI');
        self::$ffiDisabled = false !== $env && '' !== $env && '0' === $env;

        return self::$ffiDisabled;
    }

    private static function normalizeLevel(int $level): int
    {
        if ($level < self::Z_DEFAULT_COMPRESSION) {
            return 6;
        }
        if ($level > 9) {
            return 9;
        }

        return $level;
    }

    private static function sdeflLevel(int $level): int
    {
        if ($level <= 0) {
            return 0;
        }
        if ($level >= 8) {
            return 8;
        }

        return $level;
    }

    private static function isGzipEncoding(int $encoding): bool
    {
        return self::ENCODING_GZIP === $encoding || 31 === $encoding;
    }

    private static function isRawEncoding(int $encoding): bool
    {
        return self::ENCODING_RAW === $encoding || -15 === $encoding;
    }

    private static function isDeflateEncoding(int $encoding): bool
    {
        return self::ENCODING_DEFLATE === $encoding || -16 === $encoding || 15 === $encoding;
    }

    private static function encodingToWindowBits(int $encoding): ?int
    {
        if (self::isGzipEncoding($encoding)) {
            return 31;
        }
        if (self::isDeflateEncoding($encoding)) {
            return 15;
        }
        if (self::isRawEncoding($encoding)) {
            return -15;
        }

        return null;
    }

    private static function compressZlib(string $data, int $level): string|false
    {
        $deflated = self::rawDeflate($data, $level);
        if (false === $deflated) {
            return false;
        }
        $adler = VmHashNonCrypto::adler32($data);

        return self::zlibHeader($level).$deflated.self::packU32Be($adler);
    }

    private static function decodeZlib(string $data, int $maxLength): string|false
    {
        $len = \strlen($data);
        if ($len < 6) {
            return false;
        }
        if ((\ord($data[0]) & 0x0F) !== 8) {
            return false;
        }
        $offset = 2;
        if ((\ord($data[1]) & 0x20) !== 0) {
            if ($len < 6) {
                return false;
            }
            $offset = 6;
        }
        if ($len < $offset + 4) {
            return false;
        }
        $payload = \substr($data, $offset, $len - $offset - 4);
        $expected = self::unpackU32Be(\substr($data, -4));
        $plain = self::rawInflate($payload, $maxLength);
        if (false === $plain) {
            return false;
        }
        if (VmHashNonCrypto::adler32($plain) !== $expected) {
            return false;
        }

        return $plain;
    }

    private static function encodeGzip(string $data, int $level): string|false
    {
        $deflated = self::rawDeflate($data, $level);
        if (false === $deflated) {
            return false;
        }
        $header = "\x1f\x8b\x08\x00".pack('V', 0)."\x00\xff";
        $crc = VmCrc32::compute($data, 0);
        $isize = \strlen($data) & 0xFFFFFFFF;

        return $header.$deflated.pack('V', $crc).pack('V', $isize);
    }

    private static function decodeGzip(string $data, int $maxLength): string|false
    {
        $len = \strlen($data);
        if ($len < 18) {
            return false;
        }
        if ($data[0] !== "\x1f" || $data[1] !== "\x8b" || $data[2] !== "\x08") {
            return false;
        }
        $offset = self::parseGzipHeader($data);
        if (false === $offset || $len < $offset + 8) {
            return false;
        }
        $payload = \substr($data, $offset, $len - $offset - 8);
        $plain = self::rawInflate($payload, $maxLength);
        if (false === $plain) {
            return false;
        }
        $trailer = \substr($data, -8);
        $crc = self::unpackU32Le($trailer);
        $isize = self::unpackU32Le(\substr($trailer, 4));
        if (VmCrc32::compute($plain, 0) !== $crc) {
            return false;
        }
        if ((\strlen($plain) & 0xFFFFFFFF) !== $isize) {
            return false;
        }

        return $plain;
    }

    private static function inflateAuto(string $data, int $maxLength): string|false
    {
        $len = \strlen($data);
        if ($len >= 2 && $data[0] === "\x1f" && $data[1] === "\x8b") {
            return self::decodeGzip($data, $maxLength);
        }
        if ($len >= 2 && (\ord($data[0]) & 0x0F) === 8) {
            return self::decodeZlib($data, $maxLength);
        }

        return false;
    }

    private static function parseGzipHeader(string $data): int|false
    {
        $len = \strlen($data);
        if ($len < 10) {
            return false;
        }
        $flags = \ord($data[3]);
        $pos = 10;
        if (0 !== ($flags & 4)) {
            if ($pos + 2 > $len) {
                return false;
            }
            $xlen = \ord($data[$pos]) | (\ord($data[$pos + 1]) << 8);
            $pos += 2 + $xlen;
        }
        if (0 !== ($flags & 8)) {
            while ($pos < $len && $data[$pos] !== "\x00") {
                ++$pos;
            }
            if ($pos >= $len) {
                return false;
            }
            ++$pos;
        }
        if (0 !== ($flags & 16)) {
            while ($pos < $len && $data[$pos] !== "\x00") {
                ++$pos;
            }
            if ($pos >= $len) {
                return false;
            }
            ++$pos;
        }
        if (0 !== ($flags & 2)) {
            $pos += 2;
        }
        if ($pos >= $len) {
            return false;
        }

        return $pos;
    }

    private static function zlibHeader(int $level): string
    {
        $levelFlags = 0;
        if ($level < 2) {
            $levelFlags = 0;
        } elseif ($level < 6) {
            $levelFlags = 1;
        } elseif (6 === $level) {
            $levelFlags = 2;
        } else {
            $levelFlags = 3;
        }
        $cmf = 0x78;
        $flg = $levelFlags << 6;
        $flg += 31 - ((($cmf << 8) + $flg) % 31);

        return \chr($cmf).\chr($flg);
    }

    private static function rawDeflate(string $data, int $level): string|false
    {
        $inLen = \strlen($data);
        $lvl = self::sdeflLevel($level);
        $outCap = 128 + (int) (($inLen * 110) / 100);
        $bound = 128 + $inLen + (((int) ($inLen / (31 * 1024))) + 1) * 5;
        if ($bound > $outCap) {
            $outCap = $bound;
        }

        /** @var list<int> $out */
        $out = \array_fill(0, $outCap, 0);
        $bits = 0;
        $cnt = 0;
        /** @var list<int> $tbl */
        $tbl = \array_fill(0, self::HASH_SIZ, self::NIL);
        /** @var list<int> $prv */
        $prv = \array_fill(0, self::WIN_SIZ, 0);

        $maxChain = $lvl < 8 ? (1 << ($lvl + 1)) : (1 << 13);
        $op = 0;
        $op = self::putBits($out, $op, $bits, $cnt, 0x01, 1);
        $op = self::putBits($out, $op, $bits, $cnt, 0x01, 2);

        $p = 0;
        while ($p < $inLen) {
            $bestLen = 0;
            $dist = 0;
            $maxMatch = $inLen - $p;
            if ($maxMatch > self::MAX_MATCH) {
                $maxMatch = self::MAX_MATCH;
            }
            if ($maxMatch > self::MIN_MATCH) {
                $limit = $p - self::WIN_SIZ;
                if ($limit < self::NIL) {
                    $limit = self::NIL;
                }
                $chainLen = $maxChain;
                $i = $tbl[self::hash32($data, $p)];
                while ($i > $limit) {
                    if ($data[$i + $bestLen] === $data[$p + $bestLen]
                        && self::load32($data, $i) === self::load32($data, $p)) {
                        $n = self::MIN_MATCH;
                        while ($n < $maxMatch && $data[$i + $n] === $data[$p + $n]) {
                            ++$n;
                        }
                        if ($n > $bestLen) {
                            $bestLen = $n;
                            $dist = $p - $i;
                            if ($n === $maxMatch) {
                                break;
                            }
                        }
                    }
                    if (0 === --$chainLen) {
                        break;
                    }
                    $i = $prv[$i & self::WIN_MSK];
                }
            }
            if ($lvl >= 5 && $bestLen >= self::MIN_MATCH && $bestLen < $maxMatch) {
                $x = $p + 1;
                $tarLen = $bestLen + 1;
                $limit = $x - self::WIN_SIZ;
                if ($limit < self::NIL) {
                    $limit = self::NIL;
                }
                $chainLen = $maxChain;
                $i = $tbl[self::hash32($data, $p)];
                while ($i > $limit) {
                    if ($data[$i + $bestLen] === $data[$x + $bestLen]
                        && self::load32($data, $i) === self::load32($data, $x)) {
                        $n = self::MIN_MATCH;
                        while ($n < $tarLen && $data[$i + $n] === $data[$x + $n]) {
                            ++$n;
                        }
                        if ($n === $tarLen) {
                            $bestLen = 0;
                            break;
                        }
                    }
                    if (0 === --$chainLen) {
                        break;
                    }
                    $i = $prv[$i & self::WIN_MSK];
                }
            }
            if ($bestLen >= self::MIN_MATCH) {
                $op = self::putMatch($out, $op, $bits, $cnt, $dist, $bestLen);
                $run = $bestLen;
            } else {
                $op = self::putLit($out, $op, $bits, $cnt, \ord($data[$p]));
                $run = 1;
            }
            while (0 !== $run--) {
                $h = self::hash32($data, $p);
                $prv[$p & self::WIN_MSK] = $tbl[$h];
                $tbl[$h] = $p;
                ++$p;
            }
        }

        $op = self::putBits($out, $op, $bits, $cnt, 0, 7);
        $op = self::putBits($out, $op, $bits, $cnt, 2, 10);
        $op = self::putBits($out, $op, $bits, $cnt, 2, 3);
        if ($op > $outCap) {
            return false;
        }

        $bytes = '';
        for ($i = 0; $i < $op; ++$i) {
            $bytes .= \chr($out[$i]);
        }

        return $bytes;
    }

    private static function rawInflate(string $data, int $maxLength): string|false
    {
        $inLen = \strlen($data);
        if (0 === $inLen) {
            return '';
        }
        $in = 0;
        $bits = 0;
        $bitcnt = 0;
        $out = '';
        $last = 0;
        $state = 'hdr';

        /** @var list<int> $lits */
        $lits = [];
        /** @var list<int> $dsts */
        $dsts = [];
        /** @var list<int> $lens */
        $lens = [];
        $tlit = 0;
        $tdist = 0;
        $tlen = 0;

        $order = [16, 17, 18, 0, 8, 7, 9, 6, 10, 5, 11, 4, 12, 3, 13, 2, 14, 1, 15];
        $dbase = [1, 2, 3, 4, 5, 7, 9, 13, 17, 25, 33, 49, 65, 97, 129, 193, 257, 385, 513, 769, 1025, 1537, 2049, 3073, 4097, 6145, 8193, 12289, 16385, 24577, 0, 0];
        $dbits = [0, 0, 0, 0, 1, 1, 2, 2, 3, 3, 4, 4, 5, 5, 6, 6, 7, 7, 8, 8, 9, 9, 10, 10, 11, 11, 12, 12, 13, 13, 0, 0];
        $lbase = [3, 4, 5, 6, 7, 8, 9, 10, 11, 13, 15, 17, 19, 23, 27, 31, 35, 43, 51, 59, 67, 83, 99, 115, 131, 163, 195, 227, 258, 0, 0, 0];
        $lbits = [0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 2, 2, 2, 2, 3, 3, 3, 3, 4, 4, 4, 4, 5, 5, 5, 5, 0, 0, 0, 0];

        self::getBits($data, $inLen, $in, $bits, $bitcnt, 0);

        while ($in < $inLen || $bitcnt > 0) {
            if ('hdr' === $state) {
                $last = self::getBits($data, $inLen, $in, $bits, $bitcnt, 1);
                $type = self::getBits($data, $inLen, $in, $bits, $bitcnt, 2);
                if (0 === $type) {
                    $state = 'stored';
                } elseif (1 === $type) {
                    $state = 'fixed';
                } elseif (2 === $type) {
                    $state = 'dyn';
                } else {
                    return false;
                }
            } elseif ('stored' === $state) {
                self::alignBits($bits, $bitcnt);
                if ($in + 4 > $inLen) {
                    return false;
                }
                $len = \ord($data[$in]) | (\ord($data[$in + 1]) << 8);
                $nlen = \ord($data[$in + 2]) | (\ord($data[$in + 3]) << 8);
                $in += 4;
                $bits = 0;
                $bitcnt = 0;
                if (($len ^ 0xFFFF) !== $nlen || $len > $inLen - $in) {
                    return false;
                }
                if (0 === $len) {
                    $state = 'hdr';
                    continue;
                }
                $chunk = \substr($data, $in, $len);
                $in += $len;
                $out .= $chunk;
                if ($maxLength > 0 && \strlen($out) > $maxLength) {
                    return false;
                }
                $state = 'hdr';
            } elseif ('fixed' === $state) {
                $codeLens = [];
                for ($n = 0; $n <= 143; ++$n) {
                    $codeLens[$n] = 8;
                }
                for ($n = 144; $n <= 255; ++$n) {
                    $codeLens[$n] = 9;
                }
                for ($n = 256; $n <= 279; ++$n) {
                    $codeLens[$n] = 7;
                }
                for ($n = 280; $n <= 287; ++$n) {
                    $codeLens[$n] = 8;
                }
                for ($n = 0; $n < 32; ++$n) {
                    $codeLens[288 + $n] = 5;
                }
                $tlit = self::buildTree($lits, $codeLens, 288);
                $tdist = self::buildTree($dsts, \array_slice($codeLens, 288, 32), 32);
                $state = 'blk';
            } elseif ('dyn' === $state) {
                $nlit = 257 + self::getBits($data, $inLen, $in, $bits, $bitcnt, 5);
                $ndist = 1 + self::getBits($data, $inLen, $in, $bits, $bitcnt, 5);
                $nlen = 4 + self::getBits($data, $inLen, $in, $bits, $bitcnt, 4);
                $nlens = \array_fill(0, 19, 0);
                for ($n = 0; $n < $nlen; ++$n) {
                    $nlens[$order[$n]] = self::getBits($data, $inLen, $in, $bits, $bitcnt, 3);
                }
                $tlen = self::buildTree($lens, $nlens, 19);
                $codeLens = [];
                $n = 0;
                while ($n < $nlit + $ndist) {
                    $sym = self::decodeSymbol($data, $inLen, $in, $bits, $bitcnt, $lens, $tlen);
                    if ($sym < 16) {
                        $codeLens[$n++] = $sym;
                    } elseif (16 === $sym) {
                        $rep = 3 + self::getBits($data, $inLen, $in, $bits, $bitcnt, 2);
                        for ($i = 0; $i < $rep; ++$i) {
                            $codeLens[$n] = $codeLens[$n - 1];
                            ++$n;
                        }
                    } elseif (17 === $sym) {
                        $rep = 3 + self::getBits($data, $inLen, $in, $bits, $bitcnt, 3);
                        for ($i = 0; $i < $rep; ++$i) {
                            $codeLens[$n++] = 0;
                        }
                    } else {
                        $rep = 11 + self::getBits($data, $inLen, $in, $bits, $bitcnt, 7);
                        for ($i = 0; $i < $rep; ++$i) {
                            $codeLens[$n++] = 0;
                        }
                    }
                }
                $tlit = self::buildTree($lits, \array_slice($codeLens, 0, $nlit), $nlit);
                $tdist = self::buildTree($dsts, \array_slice($codeLens, $nlit, $ndist), $ndist);
                $state = 'blk';
            } else {
                $sym = self::decodeSymbol($data, $inLen, $in, $bits, $bitcnt, $lits, $tlit);
                if ($sym > 256) {
                    $sym -= 257;
                    $matchLen = self::getBits($data, $inLen, $in, $bits, $bitcnt, $lbits[$sym]) + $lbase[$sym];
                    $dsym = self::decodeSymbol($data, $inLen, $in, $bits, $bitcnt, $dsts, $tdist);
                    $offs = self::getBits($data, $inLen, $in, $bits, $bitcnt, $dbits[$dsym]) + $dbase[$dsym];
                    if ($offs > \strlen($out)) {
                        return false;
                    }
                    for ($i = 0; $i < $matchLen; ++$i) {
                        $out .= $out[\strlen($out) - $offs];
                    }
                    if ($maxLength > 0 && \strlen($out) > $maxLength) {
                        return false;
                    }
                } elseif (256 === $sym) {
                    if (0 !== $last) {
                        if ($maxLength > 0 && \strlen($out) > $maxLength) {
                            return false;
                        }

                        return $out;
                    }
                    $state = 'hdr';
                } else {
                    $out .= \chr($sym);
                    if ($maxLength > 0 && \strlen($out) > $maxLength) {
                        return false;
                    }
                }
            }
        }

        if ('' === $out && 0 === $inLen) {
            return '';
        }

        return false;
    }

    /**
     * @param list<int> $out
     */
    private static function putBits(array &$out, int $op, int &$bits, int &$cnt, int $code, int $bitcnt): int
    {
        $bits |= ($code << $cnt) & 0xFFFFFFFF;
        $cnt += $bitcnt;
        while ($cnt >= 8) {
            $out[$op++] = $bits & 0xFF;
            $bits = ($bits >> 8) & 0xFFFFFFFF;
            $cnt -= 8;
        }

        return $op;
    }

    /**
     * @param list<int> $out
     */
    private static function putLit(array &$out, int $op, int &$bits, int &$cnt, int $c): int
    {
        if ($c <= 143) {
            return self::putBits($out, $op, $bits, $cnt, self::MIRROR[0x30 + $c], 8);
        }

        return self::putBits($out, $op, $bits, $cnt, 1 + 2 * self::MIRROR[0x90 - 144 + $c], 9);
    }

    /**
     * @param list<int> $out
     */
    private static function putMatch(array &$out, int $op, int &$bits, int &$cnt, int $dist, int $len): int
    {
        $lxmin = [0, 11, 19, 35, 67, 131];
        $dxmax = [0, 6, 12, 24, 48, 96, 192, 384, 768, 1536, 3072, 6144, 12288, 24576];
        $lmin = [11, 13, 15, 17, 19, 23, 27, 31, 35, 43, 51, 59, 67, 83, 99, 115, 131, 163, 195, 227];
        $dmin = [1, 2, 3, 4, 5, 7, 9, 13, 17, 25, 33, 49, 65, 97, 129, 193, 257, 385, 513, 769, 1025, 1537, 2049, 3073, 4097, 6145, 8193, 12289, 16385, 24577];

        $lc = $len;
        $lx = self::ilog2($len - 3) - 2;
        if ($lx < 0) {
            $lx = 0;
        }
        if (0 === $lx) {
            $lc += 254;
        } elseif ($len >= 258) {
            $lx = 0;
            $lc = 285;
        } else {
            $lc = (($lx - 1) << 2) + 265 + (($len - $lxmin[$lx]) >> $lx);
        }

        if ($lc <= 279) {
            $op = self::putBits($out, $op, $bits, $cnt, self::MIRROR[($lc - 256) << 1], 7);
        } else {
            $op = self::putBits($out, $op, $bits, $cnt, self::MIRROR[0xC0 - 280 + $lc], 8);
        }
        if (0 !== $lx) {
            $op = self::putBits($out, $op, $bits, $cnt, $len - $lmin[$lc - 265], $lx);
        }

        $dc = $dist - 1;
        $dx = self::ilog2(self::npow2($dist) >> 2);
        if ($dx < 0) {
            $dx = 0;
        }
        if (0 !== $dx) {
            $dc = (($dx + 1) << 1) + ($dist > $dxmax[$dx] ? 1 : 0);
        }
        $op = self::putBits($out, $op, $bits, $cnt, self::MIRROR[$dc << 3], 5);
        if (0 !== $dx) {
            $op = self::putBits($out, $op, $bits, $cnt, $dist - $dmin[$dc], $dx);
        }

        return $op;
    }

    private static function hash32(string $data, int $p): int
    {
        $n = self::load32($data, $p);

        return (int) ((($n * 0x9E377989) & 0xFFFFFFFF) >> (32 - self::HASH_BITS));
    }

    private static function load32(string $data, int $p): int
    {
        $len = \strlen($data);
        $b0 = \ord($data[$p]);
        $b1 = $p + 1 < $len ? \ord($data[$p + 1]) : 0;
        $b2 = $p + 2 < $len ? \ord($data[$p + 2]) : 0;
        $b3 = $p + 3 < $len ? \ord($data[$p + 3]) : 0;

        return $b0 | ($b1 << 8) | ($b2 << 16) | ($b3 << 24);
    }

    private static function npow2(int $n): int
    {
        --$n;
        $n |= $n >> 1;
        $n |= $n >> 2;
        $n |= $n >> 4;
        $n |= $n >> 8;
        $n |= $n >> 16;

        return ++$n;
    }

    private static function ilog2(int $n): int
    {
        static $tbl = null;
        if (null === $tbl) {
            $tbl = [
                -1, 0, 1, 1, 2, 2, 2, 2, 3, 3, 3, 3, 3, 3, 3, 3,
                4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4,
                5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5,
                5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5,
                6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6,
                6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6,
                6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6,
                6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6,
                7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7,
                7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7,
                7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7,
                7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7,
                7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7,
                7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7,
                7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7,
                7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 7,
            ];
        }
        if (($tt = $n >> 16) !== 0) {
            if (($t = $tt >> 8) !== 0) {
                return 24 + $tbl[$t];
            }

            return 16 + $tbl[$tt];
        }
        if (($t = $n >> 8) !== 0) {
            return 8 + $tbl[$t];
        }

        return $tbl[$n];
    }

    private static function alignBits(int &$bits, int &$bitcnt): void
    {
        $drop = $bitcnt & 7;
        $bits >>= $drop;
        $bitcnt -= $drop;
    }

    private static function getBits(string $data, int $inLen, int &$in, int &$bits, int &$bitcnt, int $n): int
    {
        $v = $bits & ((1 << $n) - 1);
        $bits >>= $n;
        $bitcnt -= $n;
        if ($bitcnt < 0) {
            $bitcnt = 0;
        }
        while ($bitcnt < 16 && $in < $inLen) {
            $bits |= (\ord($data[$in++]) << $bitcnt) & 0xFFFFFFFF;
            $bitcnt += 8;
        }

        return $v;
    }

    /**
     * @param list<int> $tree
     * @param list<int> $codeLens
     */
    private static function buildTree(array &$tree, array $codeLens, int $symcnt): int
    {
        $tree = [];
        $cnt = \array_fill(0, 16, 0);
        $first = \array_fill(0, 16, 0);
        $codes = \array_fill(0, 16, 0);
        $cnt[0] = $first[0] = $codes[0] = 0;
        for ($n = 0; $n < $symcnt; ++$n) {
            ++$cnt[$codeLens[$n]];
        }
        for ($n = 1; $n <= 15; ++$n) {
            $codes[$n] = ($codes[$n - 1] + $cnt[$n - 1]) << 1;
            $first[$n] = $first[$n - 1] + $cnt[$n - 1];
        }
        for ($n = 0; $n < $symcnt; ++$n) {
            $len = $codeLens[$n];
            if (0 === $len) {
                continue;
            }
            $code = $codes[$len]++;
            $slot = $first[$len]++;
            $tree[$slot] = (($code << (32 - $len)) & 0xFFFFFFFF) | ($n << 4) | $len;
        }

        return $first[15];
    }

    /**
     * @param list<int> $tree
     */
    private static function decodeSymbol(string $data, int $inLen, int &$in, int &$bits, int &$bitcnt, array $tree, int $max): int
    {
        if ($max <= 0) {
            return -1;
        }
        $search = ((self::rev16($bits) << 16) | 0xFFFF) & 0xFFFFFFFF;
        $lo = 0;
        $hi = $max;
        while ($lo < $hi) {
            $guess = (int) (($lo + $hi) / 2);
            if ($search < $tree[$guess]) {
                $hi = $guess;
            } else {
                $lo = $guess + 1;
            }
        }
        if ($lo <= 0) {
            return -1;
        }
        $key = $tree[$lo - 1];
        self::getBits($data, $inLen, $in, $bits, $bitcnt, $key & 0x0F);

        return ($key >> 4) & 0x0FFF;
    }

    private static function rev16(int $n): int
    {
        return ((self::MIRROR[$n & 0xFF] << 8) | self::MIRROR[($n >> 8) & 0xFF]) & 0xFFFF;
    }

    private static function packU32Be(int $value): string
    {
        return \pack('N', $value & 0xFFFFFFFF);
    }

    private static function unpackU32Be(string $bytes): int
    {
        $unpacked = \unpack('N', $bytes);

        return false === $unpacked ? 0 : $unpacked[1];
    }

    private static function unpackU32Le(string $bytes): int
    {
        $unpacked = \unpack('V', $bytes);

        return false === $unpacked ? 0 : $unpacked[1];
    }
}
