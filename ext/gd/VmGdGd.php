<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\ext\standard\VmZlib;

/**
 * GD1 (.gd) + GD2 (.gd2) codecs — php-src ext/gd/libgd/gd_gd.c + gd_gd2.c (#20502).
 *
 * Words/ints are big-endian ({@see gdPutWord}/{@see gdPutInt}).
 * GD2 compressed chunks use zlib {@see compress}/{@see uncompress} (gzcompress/gzuncompress).
 */
final class VmGdGd
{
    /** Four-byte magic including trailing NUL (php-src gd.h GD2_ID loop i=0..3). */
    public const GD2_ID = "gd2\0";

    public const GD2_VERS = 2;

    public const GD2_FMT_RAW = 1;

    public const GD2_FMT_COMPRESSED = 2;

    public const GD2_FMT_TRUECOLOR_RAW = 3;

    public const GD2_FMT_TRUECOLOR_COMPRESSED = 4;

    public const GD2_CHUNKSIZE = 128;

    public const GD2_CHUNKSIZE_MIN = 64;

    public const GD2_CHUNKSIZE_MAX = 4096;

    public const GD_MAX_COLORS = 256;

    /** Truecolor .gd signature word (php-src gd_gd.c; 2.0.12+). */
    public const GD_SIG_TRUECOLOR = 65534;

    /** Palette .gd 2.x signature word. */
    public const GD_SIG_PALETTE = 65535;

    /**
     * Encode GD1 (.gd) bytes from an image state.
     */
    public static function encodeGd(GdImageState $state): string
    {
        $out = '';
        if ($state->truecolor) {
            $out .= self::putWord(self::GD_SIG_TRUECOLOR);
        } else {
            $out .= self::putWord(self::GD_SIG_PALETTE);
        }
        $out .= self::putWord($state->width);
        $out .= self::putWord($state->height);
        $out .= self::putColors($state);
        $n = $state->width * $state->height;
        if ($state->truecolor) {
            for ($i = 0; $i < $n; ++$i) {
                $out .= self::putInt($state->pixels[$i]);
            }
        } else {
            for ($i = 0; $i < $n; ++$i) {
                $out .= \chr($state->pixels[$i] & 0xFF);
            }
        }

        return $out;
    }

    /**
     * @return array{
     *   width: int,
     *   height: int,
     *   truecolor: bool,
     *   pixels: list<int>,
     *   colors: list<int>,
     *   transparent: int
     * }|false
     */
    public static function decodeGd(string $data): array|false
    {
        $len = \strlen($data);
        $pos = 0;
        $sx = self::getWord($data, $pos, $len);
        if (null === $sx) {
            return false;
        }
        $truecolor = false;
        $gd2x = false;
        if (self::GD_SIG_TRUECOLOR === $sx || self::GD_SIG_PALETTE === $sx) {
            $gd2x = true;
            $truecolor = self::GD_SIG_TRUECOLOR === $sx;
            $sx = self::getWord($data, $pos, $len);
            if (null === $sx) {
                return false;
            }
        }
        $sy = self::getWord($data, $pos, $len);
        if (null === $sy || $sx <= 0 || $sy <= 0) {
            return false;
        }
        $colorsInfo = self::getColors($data, $pos, $len, $truecolor, $gd2x);
        if (false === $colorsInfo) {
            return false;
        }
        [$colors, $transparent] = $colorsInfo;
        $n = $sx * $sy;
        $pixels = [];
        if ($truecolor) {
            for ($i = 0; $i < $n; ++$i) {
                $pix = self::getInt($data, $pos, $len);
                if (null === $pix) {
                    return false;
                }
                $pixels[] = $pix;
            }
        } else {
            for ($i = 0; $i < $n; ++$i) {
                if ($pos >= $len) {
                    return false;
                }
                $pixels[] = \ord($data[$pos++]);
            }
        }

        return [
            'width' => $sx,
            'height' => $sy,
            'truecolor' => $truecolor,
            'pixels' => $pixels,
            'colors' => $colors,
            'transparent' => $transparent,
        ];
    }

    /**
     * Encode GD2 bytes. $mode is IMG_GD2_RAW (1) or IMG_GD2_COMPRESSED (2).
     */
    public static function encodeGd2(GdImageState $state, int $chunkSize = self::GD2_CHUNKSIZE, int $mode = self::GD2_FMT_RAW): string
    {
        $cs = $chunkSize;
        if (0 === $cs) {
            $cs = self::GD2_CHUNKSIZE;
        } elseif ($cs < self::GD2_CHUNKSIZE_MIN) {
            $cs = self::GD2_CHUNKSIZE_MIN;
        } elseif ($cs > self::GD2_CHUNKSIZE_MAX) {
            $cs = self::GD2_CHUNKSIZE_MAX;
        }
        $fmt = ($mode === self::GD2_FMT_COMPRESSED) ? self::GD2_FMT_COMPRESSED : self::GD2_FMT_RAW;
        if ($state->truecolor) {
            $fmt += 2;
        }
        $sx = $state->width;
        $sy = $state->height;
        $ncx = (int) (($sx + $cs - 1) / $cs);
        $ncy = (int) (($sy + $cs - 1) / $cs);
        $compressed = self::isCompressedFmt($fmt);

        $out = self::GD2_ID;
        $out .= self::putWord(self::GD2_VERS);
        $out .= self::putWord($sx);
        $out .= self::putWord($sy);
        $out .= self::putWord($cs);
        $out .= self::putWord($fmt);
        $out .= self::putWord($ncx);
        $out .= self::putWord($ncy);

        $chunkIdx = [];
        $idxPos = 0;
        if ($compressed) {
            $idxPos = \strlen($out);
            // Reserve ncx*ncy * 8 bytes for offset/size pairs.
            $out .= \str_repeat("\0", $ncx * $ncy * 8);
        }

        $out .= self::putColors($state);

        $chunkNum = 0;
        for ($cy = 0; $cy < $ncy; ++$cy) {
            for ($cx = 0; $cx < $ncx; ++$cx) {
                $ylo = $cy * $cs;
                $yhi = $ylo + $cs;
                if ($yhi > $sy) {
                    $yhi = $sy;
                }
                $chunkData = '';
                for ($y = $ylo; $y < $yhi; ++$y) {
                    $xlo = $cx * $cs;
                    $xhi = $xlo + $cs;
                    if ($xhi > $sx) {
                        $xhi = $sx;
                    }
                    $rowBase = $y * $sx;
                    for ($x = $xlo; $x < $xhi; ++$x) {
                        $p = $state->pixels[$rowBase + $x];
                        if ($state->truecolor) {
                            if ($compressed) {
                                $chunkData .= \chr(($p >> 24) & 0xFF)
                                    .\chr(($p >> 16) & 0xFF)
                                    .\chr(($p >> 8) & 0xFF)
                                    .\chr($p & 0xFF);
                            } else {
                                $out .= self::putInt($p);
                            }
                        } else {
                            if ($compressed) {
                                $chunkData .= \chr($p & 0xFF);
                            } else {
                                $out .= \chr($p & 0xFF);
                            }
                        }
                    }
                }
                if ($compressed) {
                    $comp = VmZlib::gzcompress($chunkData, -1);
                    if (false === $comp) {
                        throw new \LogicException('VmGdGd::encodeGd2() zlib compress failed');
                    }
                    $chunkIdx[$chunkNum] = ['offset' => \strlen($out), 'size' => \strlen($comp)];
                    $out .= $comp;
                    ++$chunkNum;
                }
            }
        }

        if ($compressed) {
            $tail = \substr($out, $idxPos + $ncx * $ncy * 8);
            $head = \substr($out, 0, $idxPos);
            $index = '';
            for ($i = 0; $i < $chunkNum; ++$i) {
                $index .= self::putInt($chunkIdx[$i]['offset']);
                $index .= self::putInt($chunkIdx[$i]['size']);
            }
            // Pad unused index slots (should be exact).
            while (\strlen($index) < $ncx * $ncy * 8) {
                $index .= self::putInt(0).self::putInt(0);
            }
            $out = $head.$index.$tail;
        }

        return $out;
    }

    /**
     * @return array{
     *   width: int,
     *   height: int,
     *   truecolor: bool,
     *   pixels: list<int>,
     *   colors: list<int>,
     *   transparent: int
     * }|false
     */
    public static function decodeGd2(string $data): array|false
    {
        $header = self::parseGd2Header($data);
        if (false === $header) {
            return false;
        }
        [
            'pos' => $pos,
            'sx' => $sx,
            'sy' => $sy,
            'cs' => $cs,
            'fmt' => $fmt,
            'ncx' => $ncx,
            'ncy' => $ncy,
            'chunkIdx' => $chunkIdx,
            'vers' => $vers,
        ] = $header;
        $truecolor = self::isTruecolorFmt($fmt);
        $compressed = self::isCompressedFmt($fmt);
        $len = \strlen($data);
        $colorsInfo = self::getColors($data, $pos, $len, $truecolor, 2 === $vers);
        if (false === $colorsInfo) {
            return false;
        }
        [$colors, $transparent] = $colorsInfo;
        $pixels = \array_fill(0, $sx * $sy, 0);
        $chunkNum = 0;
        $chunkMax = $cs * ($truecolor ? 4 : 1) * $cs;

        for ($cy = 0; $cy < $ncy; ++$cy) {
            for ($cx = 0; $cx < $ncx; ++$cx) {
                $ylo = $cy * $cs;
                $yhi = $ylo + $cs;
                if ($yhi > $sy) {
                    $yhi = $sy;
                }
                $chunkBuf = null;
                $chunkPos = 0;
                if ($compressed) {
                    if (!isset($chunkIdx[$chunkNum])) {
                        return false;
                    }
                    $off = $chunkIdx[$chunkNum]['offset'];
                    $sz = $chunkIdx[$chunkNum]['size'];
                    if ($off < 0 || $sz < 0 || $off + $sz > $len) {
                        return false;
                    }
                    $comp = \substr($data, $off, $sz);
                    $chunkBuf = VmZlib::gzuncompress($comp, $chunkMax + 64);
                    if (false === $chunkBuf) {
                        return false;
                    }
                    $chunkPos = 0;
                }
                for ($y = $ylo; $y < $yhi; ++$y) {
                    $xlo = $cx * $cs;
                    $xhi = $xlo + $cs;
                    if ($xhi > $sx) {
                        $xhi = $sx;
                    }
                    $rowBase = $y * $sx;
                    for ($x = $xlo; $x < $xhi; ++$x) {
                        if ($compressed) {
                            if ($truecolor) {
                                if ($chunkPos + 4 > \strlen($chunkBuf)) {
                                    return false;
                                }
                                $a = \ord($chunkBuf[$chunkPos++]);
                                $r = \ord($chunkBuf[$chunkPos++]);
                                $g = \ord($chunkBuf[$chunkPos++]);
                                $b = \ord($chunkBuf[$chunkPos++]);
                                $pixels[$rowBase + $x] = ($a << 24) | ($r << 16) | ($g << 8) | $b;
                            } else {
                                if ($chunkPos >= \strlen($chunkBuf)) {
                                    return false;
                                }
                                $pixels[$rowBase + $x] = \ord($chunkBuf[$chunkPos++]);
                            }
                        } else {
                            if ($truecolor) {
                                $pix = self::getInt($data, $pos, $len);
                                if (null === $pix) {
                                    return false;
                                }
                                $pixels[$rowBase + $x] = $pix;
                            } else {
                                if ($pos >= $len) {
                                    return false;
                                }
                                $pixels[$rowBase + $x] = \ord($data[$pos++]);
                            }
                        }
                    }
                }
                ++$chunkNum;
            }
        }

        return [
            'width' => $sx,
            'height' => $sy,
            'truecolor' => $truecolor,
            'pixels' => $pixels,
            'colors' => $colors,
            'transparent' => $transparent,
        ];
    }

    /**
     * Decode a rectangular part of a GD2 image into a new raster (php-src gdImageCreateFromGd2PartCtx).
     *
     * @return array{
     *   width: int,
     *   height: int,
     *   truecolor: bool,
     *   pixels: list<int>,
     *   colors: list<int>,
     *   transparent: int
     * }|false
     */
    public static function decodeGd2Part(string $data, int $srcx, int $srcy, int $w, int $h): array|false
    {
        if ($w < 1 || $h < 1) {
            return false;
        }
        $header = self::parseGd2Header($data);
        if (false === $header) {
            return false;
        }
        [
            'pos' => $pos,
            'sx' => $fsx,
            'sy' => $fsy,
            'cs' => $cs,
            'fmt' => $fmt,
            'ncx' => $ncx,
            'ncy' => $ncy,
            'chunkIdx' => $chunkIdx,
            'vers' => $vers,
        ] = $header;
        $truecolor = self::isTruecolorFmt($fmt);
        $compressed = self::isCompressedFmt($fmt);
        $len = \strlen($data);
        $colorsInfo = self::getColors($data, $pos, $len, $truecolor, 2 === $vers);
        if (false === $colorsInfo) {
            return false;
        }
        [$colors, $transparent] = $colorsInfo;
        $dstart = $pos;
        $pixels = \array_fill(0, $w * $h, 0);
        $chunkMax = $cs * ($truecolor ? 4 : 1) * $cs;

        $scx = (int) ($srcx / $cs);
        $scy = (int) ($srcy / $cs);
        if ($scx < 0) {
            $scx = 0;
        }
        if ($scy < 0) {
            $scy = 0;
        }
        $ecx = (int) (($srcx + $w) / $cs);
        $ecy = (int) (($srcy + $h) / $cs);
        if ($ecx >= $ncx) {
            $ecx = $ncx - 1;
        }
        if ($ecy >= $ncy) {
            $ecy = $ncy - 1;
        }

        for ($cy = $scy; $cy <= $ecy; ++$cy) {
            $ylo = $cy * $cs;
            $yhi = $ylo + $cs;
            if ($yhi > $fsy) {
                $yhi = $fsy;
            }
            for ($cx = $scx; $cx <= $ecx; ++$cx) {
                $xlo = $cx * $cs;
                $xhi = $xlo + $cs;
                if ($xhi > $fsx) {
                    $xhi = $fsx;
                }
                $chunkBuf = null;
                $chunkPos = 0;
                $rawPos = $pos;
                if ($compressed) {
                    $chunkNum = $cx + $cy * $ncx;
                    if (!isset($chunkIdx[$chunkNum])) {
                        return false;
                    }
                    $off = $chunkIdx[$chunkNum]['offset'];
                    $sz = $chunkIdx[$chunkNum]['size'];
                    if ($off < 0 || $sz < 0 || $off + $sz > $len) {
                        return false;
                    }
                    $comp = \substr($data, $off, $sz);
                    $chunkBuf = VmZlib::gzuncompress($comp, $chunkMax + 64);
                    if (false === $chunkBuf) {
                        return false;
                    }
                } else {
                    if ($truecolor) {
                        $dpos = ($cy * ($cs * $fsx) * 4 + $cx * $cs * ($yhi - $ylo) * 4) + $dstart;
                    } else {
                        $dpos = $cy * ($cs * $fsx) + $cx * $cs * ($yhi - $ylo) + $dstart;
                    }
                    $rawPos = $dpos;
                }
                for ($y = $ylo; $y < $yhi; ++$y) {
                    for ($x = $xlo; $x < $xhi; ++$x) {
                        if ($compressed) {
                            if ($truecolor) {
                                if ($chunkPos + 4 > \strlen($chunkBuf)) {
                                    $ch = 0;
                                } else {
                                    $a = \ord($chunkBuf[$chunkPos++]);
                                    $r = \ord($chunkBuf[$chunkPos++]);
                                    $g = \ord($chunkBuf[$chunkPos++]);
                                    $b = \ord($chunkBuf[$chunkPos++]);
                                    $ch = ($a << 24) | ($r << 16) | ($g << 8) | $b;
                                }
                            } else {
                                $ch = ($chunkPos < \strlen($chunkBuf)) ? \ord($chunkBuf[$chunkPos++]) : 0;
                            }
                        } else {
                            if ($truecolor) {
                                $ch = self::getInt($data, $rawPos, $len);
                                if (null === $ch) {
                                    $ch = 0;
                                }
                            } else {
                                if ($rawPos >= $len) {
                                    $ch = 0;
                                } else {
                                    $ch = \ord($data[$rawPos++]);
                                }
                            }
                        }
                        if ($x >= $srcx && $x < ($srcx + $w) && $x < $fsx && $x >= 0
                            && $y >= $srcy && $y < ($srcy + $h) && $y < $fsy && $y >= 0) {
                            $pixels[($y - $srcy) * $w + ($x - $srcx)] = $ch;
                        }
                    }
                }
            }
        }

        return [
            'width' => $w,
            'height' => $h,
            'truecolor' => $truecolor,
            'pixels' => $pixels,
            'colors' => $colors,
            'transparent' => $transparent,
        ];
    }

    /**
     * @return array{
     *   pos: int,
     *   sx: int,
     *   sy: int,
     *   cs: int,
     *   fmt: int,
     *   ncx: int,
     *   ncy: int,
     *   vers: int,
     *   chunkIdx: list<array{offset: int, size: int}>
     * }|false
     */
    private static function parseGd2Header(string $data): array|false
    {
        $len = \strlen($data);
        $pos = 0;
        if ($len < 4 || \substr($data, 0, 4) !== self::GD2_ID) {
            return false;
        }
        $pos = 4;
        $vers = self::getWord($data, $pos, $len);
        if (null === $vers || (1 !== $vers && 2 !== $vers)) {
            return false;
        }
        $sx = self::getWord($data, $pos, $len);
        $sy = self::getWord($data, $pos, $len);
        $cs = self::getWord($data, $pos, $len);
        $fmt = self::getWord($data, $pos, $len);
        $ncx = self::getWord($data, $pos, $len);
        $ncy = self::getWord($data, $pos, $len);
        if (null === $sx || null === $sy || null === $cs || null === $fmt || null === $ncx || null === $ncy) {
            return false;
        }
        if ($sx <= 0 || $sy <= 0) {
            return false;
        }
        if ($cs < self::GD2_CHUNKSIZE_MIN || $cs > self::GD2_CHUNKSIZE_MAX) {
            return false;
        }
        if (!\in_array($fmt, [
            self::GD2_FMT_RAW,
            self::GD2_FMT_COMPRESSED,
            self::GD2_FMT_TRUECOLOR_RAW,
            self::GD2_FMT_TRUECOLOR_COMPRESSED,
        ], true)) {
            return false;
        }
        $chunkIdx = [];
        if (self::isCompressedFmt($fmt)) {
            $nc = $ncx * $ncy;
            if ($nc <= 0) {
                return false;
            }
            for ($i = 0; $i < $nc; ++$i) {
                $off = self::getInt($data, $pos, $len);
                $sz = self::getInt($data, $pos, $len);
                if (null === $off || null === $sz || $off < 0 || $sz < 0) {
                    return false;
                }
                $chunkIdx[] = ['offset' => $off, 'size' => $sz];
            }
        }

        return [
            'pos' => $pos,
            'sx' => $sx,
            'sy' => $sy,
            'cs' => $cs,
            'fmt' => $fmt,
            'ncx' => $ncx,
            'ncy' => $ncy,
            'vers' => $vers,
            'chunkIdx' => $chunkIdx,
        ];
    }

    private static function isCompressedFmt(int $fmt): bool
    {
        return self::GD2_FMT_COMPRESSED === $fmt || self::GD2_FMT_TRUECOLOR_COMPRESSED === $fmt;
    }

    private static function isTruecolorFmt(int $fmt): bool
    {
        return self::GD2_FMT_TRUECOLOR_RAW === $fmt || self::GD2_FMT_TRUECOLOR_COMPRESSED === $fmt;
    }

    private static function putColors(GdImageState $state): string
    {
        $out = \chr($state->truecolor ? 1 : 0);
        if (!$state->truecolor) {
            $out .= self::putWord(\count($state->colors));
        }
        $out .= self::putInt($state->transparent);
        if (!$state->truecolor) {
            for ($i = 0; $i < self::GD_MAX_COLORS; ++$i) {
                $rgb = $state->colors[$i] ?? 0;
                $out .= \chr(($rgb >> 16) & 0xFF);
                $out .= \chr(($rgb >> 8) & 0xFF);
                $out .= \chr($rgb & 0xFF);
                $out .= \chr(($rgb >> 24) & 0xFF); // alpha nibble in high byte when present
            }
        }

        return $out;
    }

    /**
     * @return array{0: list<int>, 1: int}|false colors, transparent
     */
    private static function getColors(string $data, int &$pos, int $len, bool $truecolor, bool $gd2xFlag): array|false
    {
        if ($gd2xFlag) {
            if ($pos >= $len) {
                return false;
            }
            $trueColorFlag = \ord($data[$pos++]);
            if ((bool) $trueColorFlag !== $truecolor) {
                return false;
            }
            $colorsTotal = 0;
            if (!$truecolor) {
                $colorsTotal = self::getWord($data, $pos, $len);
                if (null === $colorsTotal || $colorsTotal > self::GD_MAX_COLORS) {
                    return false;
                }
            }
            $transparent = self::getInt($data, $pos, $len);
            if (null === $transparent) {
                return false;
            }
            if ($truecolor) {
                return [[], $transparent];
            }
            $colors = [];
            for ($i = 0; $i < self::GD_MAX_COLORS; ++$i) {
                if ($pos + 4 > $len) {
                    return false;
                }
                $r = \ord($data[$pos++]);
                $g = \ord($data[$pos++]);
                $b = \ord($data[$pos++]);
                $a = \ord($data[$pos++]);
                if ($i < $colorsTotal) {
                    $colors[$i] = ($a << 24) | ($r << 16) | ($g << 8) | $b;
                }
            }
            // Reindex densely 0..colorsTotal-1
            $dense = [];
            for ($i = 0; $i < $colorsTotal; ++$i) {
                $dense[$i] = $colors[$i] ?? 0;
            }

            return [$dense, $transparent];
        }

        // Legacy GD1.x palette header
        if ($pos >= $len) {
            return false;
        }
        $colorsTotal = \ord($data[$pos++]);
        $transparent = self::getWord($data, $pos, $len);
        if (null === $transparent) {
            return false;
        }
        if (257 === $transparent) {
            $transparent = -1;
        }
        $colors = [];
        for ($i = 0; $i < self::GD_MAX_COLORS; ++$i) {
            if ($pos + 3 > $len) {
                return false;
            }
            $r = \ord($data[$pos++]);
            $g = \ord($data[$pos++]);
            $b = \ord($data[$pos++]);
            if ($i < $colorsTotal) {
                $colors[$i] = ($r << 16) | ($g << 8) | $b;
            }
        }
        $dense = [];
        for ($i = 0; $i < $colorsTotal; ++$i) {
            $dense[$i] = $colors[$i] ?? 0;
        }

        return [$dense, $transparent];
    }

    private static function putWord(int $w): string
    {
        return \chr(($w >> 8) & 0xFF).\chr($w & 0xFF);
    }

    private static function putInt(int $w): string
    {
        return \chr(($w >> 24) & 0xFF)
            .\chr(($w >> 16) & 0xFF)
            .\chr(($w >> 8) & 0xFF)
            .\chr($w & 0xFF);
    }

    private static function getWord(string $data, int &$pos, int $len): ?int
    {
        if ($pos + 2 > $len) {
            return null;
        }
        $hi = \ord($data[$pos++]);
        $lo = \ord($data[$pos++]);

        return ($hi << 8) | $lo;
    }

    private static function getInt(string $data, int &$pos, int $len): ?int
    {
        if ($pos + 4 > $len) {
            return null;
        }
        $b0 = \ord($data[$pos++]);
        $b1 = \ord($data[$pos++]);
        $b2 = \ord($data[$pos++]);
        $b3 = \ord($data[$pos++]);
        // Assemble as unsigned then cast to signed 32-bit (php-src gdGetInt).
        $u = ($b0 << 24) | ($b1 << 16) | ($b2 << 8) | $b3;
        if ($u >= 0x80000000) {
            return $u - 0x100000000;
        }

        return $u;
    }
}
