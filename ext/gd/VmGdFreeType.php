<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

/**
 * FreeType FFI bridge for imagettftext()/imagettfbbox() (php-src ext/gd/libgd/gdft.c; #6532).
 *
 * Struct layouts match FreeType 2.11 on x86_64 Linux (Ubuntu 22.04 libfreetype6).
 */
final class VmGdFreeType
{
    private const FT_LOAD_DEFAULT = 0;

    private const FT_LOAD_RENDER = 4;

    private const FT_LOAD_IGNORE_TRANSFORM = 2048;

    private const FT_PIXEL_MODE_MONO = 1;

    private const FT_PIXEL_MODE_GRAY = 2;

    private const FT_FACE_FLAG_KERNING = 64;

    private const FT_KERNING_DEFAULT = 0;

    /** libgd GD_RESOLUTION — 96 dpi (php-src ext/gd/libgd/gdft.c). */
    private const GD_RESOLUTION = 96;

    /** @var \FFI|null */
    private static $ffi = null;

    private static bool $ffiUnavailable = false;

    /** @var \FFI\CData|null FT_Library */
    private static $library = null;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /**
     * @return list<int>|string 8-int brect on success, error message string on failure
     */
    public static function stringFT(
        ?GdImageState $state,
        float $ptsize,
        float $angleRadians,
        int $x,
        int $y,
        int $fg,
        string $fontFilename,
        string $text
    ): array|string {
        $ffi = self::ffi();
        if (null === $ffi) {
            return 'FreeType support is not available';
        }
        if ('' === $fontFilename || !\is_readable($fontFilename)) {
            return 'Could not find/open font';
        }

        $library = self::library($ffi);
        if (null === $library) {
            return 'Failure to initialize font library';
        }

        $face = $ffi->new('FT_Face');
        $rc = (int) $ffi->FT_New_Face($library, $fontFilename, 0, \FFI::addr($face));
        if (0 !== $rc || null === $face) {
            return 'Could not read font';
        }

        try {
            if (0 !== (int) $ffi->FT_Set_Char_Size(
                $face,
                0,
                (int) \round($ptsize * 64.0),
                self::GD_RESOLUTION,
                self::GD_RESOLUTION
            )) {
                return 'Could not set character size';
            }

            $sinA = \sin($angleRadians);
            $cosA = \cos($angleRadians);
            $matrix = $ffi->new('FT_Matrix');
            $matrix->xx = (int) \round($cosA * (1 << 16));
            $matrix->yx = (int) \round($sinA * (1 << 16));
            $matrix->xy = -$matrix->yx;
            $matrix->yy = $matrix->xx;

            $penfX = 0;
            $penfY = 0;
            $penX = 0;
            $penY = 0;
            $x1 = 0;
            $y1 = 0;

            $bboxMinX = 0;
            $bboxMinY = 0;
            $bboxMaxX = 0;
            $bboxMaxY = 0;
            $bboxInit = false;

            $faceRec = $ffi->cast('FT_FaceRec*', $face);
            $useKerning = (0 !== ((int) $faceRec->face_flags & self::FT_FACE_FLAG_KERNING));
            $previous = 0;
            $render = null !== $state && $state->hasRaster();

            foreach (self::utf8Codepoints($text) as $ch) {
                if (10 === $ch) { // "\n"
                    $penfX = 0;
                    $sizeRec = $ffi->cast('FT_SizeRec*', $faceRec->size);
                    $penfY -= (int) ($sizeRec->metrics->height * 1.05);
                    $penfY = ($penfY - 32) & -64;
                    $x1 = (int) ((-$penfY * $sinA + 32) / 64);
                    $y1 = (int) ((-$penfY * $cosA + 32) / 64);
                    $penX = 0;
                    $penY = 0;
                    $previous = 0;
                    continue;
                }
                if (13 === $ch) { // "\r"
                    $penfX = 0;
                    $x1 = (int) ((-$penfY * $sinA + 32) / 64);
                    $y1 = (int) ((-$penfY * $cosA + 32) / 64);
                    $penX = 0;
                    $penY = 0;
                    $previous = 0;
                    continue;
                }

                $ffi->FT_Set_Transform($face, \FFI::addr($matrix), null);
                $glyphIndex = (int) $ffi->FT_Get_Char_Index($face, $ch);

                if ($useKerning && 0 !== $previous && 0 !== $glyphIndex) {
                    $delta = $ffi->new('FT_Vector');
                    $ffi->FT_Get_Kerning($face, $previous, $glyphIndex, self::FT_KERNING_DEFAULT, \FFI::addr($delta));
                    $penX += (int) ($delta->x * $cosA);
                    $penY -= (int) ($delta->x * $sinA);
                    $penfX += (int) $delta->x;
                }

                // BBox uses untransformed glyph metrics (php-src gdft.c).
                if (0 !== (int) $ffi->FT_Load_Glyph($face, $glyphIndex, self::FT_LOAD_DEFAULT | self::FT_LOAD_IGNORE_TRANSFORM)) {
                    return 'Problem loading glyph';
                }
                $slot = $ffi->cast('FT_GlyphSlotRec*', $faceRec->glyph);
                $metrics = $slot->metrics;
                $gMinX = (int) $metrics->horiBearingX + $penfX;
                $gMaxX = $gMinX + (int) $metrics->width;
                $gMaxY = (int) $metrics->horiBearingY + $penfY;
                $gMinY = $gMaxY - (int) $metrics->height;
                if (32 === $ch) {
                    $gMaxX += (int) $metrics->horiAdvance;
                }
                if (!$bboxInit) {
                    $bboxMinX = $gMinX;
                    $bboxMinY = $gMinY;
                    $bboxMaxX = $gMaxX;
                    $bboxMaxY = $gMaxY;
                    $bboxInit = true;
                } else {
                    $bboxMinX = \min($bboxMinX, $gMinX);
                    $bboxMinY = \min($bboxMinY, $gMinY);
                    $bboxMaxX = \max($bboxMaxX, $gMaxX);
                    $bboxMaxY = \max($bboxMaxY, $gMaxY);
                }
                $penfX += (int) $metrics->horiAdvance;

                if ($render) {
                    if (0.0 !== $angleRadians) {
                        if (0 !== (int) $ffi->FT_Load_Glyph($face, $glyphIndex, self::FT_LOAD_DEFAULT)) {
                            return 'Problem loading glyph';
                        }
                    } else {
                        // Angle 0: reload without IGNORE_TRANSFORM (identity matrix).
                        if (0 !== (int) $ffi->FT_Load_Glyph($face, $glyphIndex, self::FT_LOAD_RENDER)) {
                            return 'Problem loading glyph';
                        }
                    }
                    $slot = $ffi->cast('FT_GlyphSlotRec*', $faceRec->glyph);
                    if (0.0 !== $angleRadians) {
                        if (0 !== (int) $ffi->FT_Render_Glyph($faceRec->glyph, 0)) {
                            return 'Problem rendering glyph';
                        }
                        $slot = $ffi->cast('FT_GlyphSlotRec*', $faceRec->glyph);
                    }
                    $bm = $slot->bitmap;
                    $bx = $x + $x1 + (($penX + 31) >> 6) + (int) $slot->bitmap_left;
                    $by = $y + $y1 + (($penY + 31) >> 6) - (int) $slot->bitmap_top;
                    self::blitBitmap($state, $bm, $bx, $by, $fg);
                    // slot->advance is 26.6 (php-src uses FT_Glyph 16.16 >> 10).
                    $penX += (int) $slot->advance->x;
                    $penY -= (int) $slot->advance->y;
                } else {
                    // BBox-only: still need transformed advance for pen when angle != 0.
                    if (0.0 !== $angleRadians) {
                        if (0 !== (int) $ffi->FT_Load_Glyph($face, $glyphIndex, self::FT_LOAD_DEFAULT)) {
                            return 'Problem loading glyph';
                        }
                        $slot = $ffi->cast('FT_GlyphSlotRec*', $faceRec->glyph);
                        $penX += (int) $slot->advance->x;
                        $penY -= (int) $slot->advance->y;
                    } else {
                        $penX += (int) $metrics->horiAdvance;
                    }
                }

                $previous = $glyphIndex;
            }

            $d1 = \sin($angleRadians + 0.78539816339744830962);
            $d2 = \sin($angleRadians - 0.78539816339744830962);
            $b0 = (int) ($bboxMinX * $cosA - $bboxMinY * $sinA);
            $b1 = (int) ($bboxMinX * $sinA + $bboxMinY * $cosA);
            $b2 = (int) ($bboxMaxX * $cosA - $bboxMinY * $sinA);
            $b3 = (int) ($bboxMaxX * $sinA + $bboxMinY * $cosA);
            $b4 = (int) ($bboxMaxX * $cosA - $bboxMaxY * $sinA);
            $b5 = (int) ($bboxMaxX * $sinA + $bboxMaxY * $cosA);
            $b6 = (int) ($bboxMinX * $cosA - $bboxMaxY * $sinA);
            $b7 = (int) ($bboxMinX * $sinA + $bboxMaxY * $cosA);

            return [
                $x + self::gdRoundUpDown($b0, $d2 > 0),
                $y - self::gdRoundUpDown($b1, $d1 < 0),
                $x + self::gdRoundUpDown($b2, $d1 > 0),
                $y - self::gdRoundUpDown($b3, $d2 > 0),
                $x + self::gdRoundUpDown($b4, $d2 < 0),
                $y - self::gdRoundUpDown($b5, $d1 > 0),
                $x + self::gdRoundUpDown($b6, $d1 < 0),
                $y - self::gdRoundUpDown($b7, $d2 < 0),
            ];
        } finally {
            $ffi->FT_Done_Face($face);
        }
    }

    /** @return \FFI|null */
    private static function ffi()
    {
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\class_exists(\FFI::class, false) && !\extension_loaded('FFI')) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
typedef struct FT_LibraryRec_ *FT_Library;
typedef struct FT_FaceRec_ *FT_Face;
typedef struct FT_GlyphSlotRec_ *FT_GlyphSlot;
typedef struct FT_SizeRec_ *FT_Size;
typedef long FT_Long;
typedef unsigned long FT_ULong;
typedef int FT_Int;
typedef unsigned int FT_UInt;
typedef short FT_Short;
typedef unsigned short FT_UShort;
typedef signed long FT_Fixed;
typedef signed long FT_Pos;
typedef signed long FT_F26Dot6;
typedef int FT_Error;
typedef int32_t FT_Int32;
typedef struct FT_Vector_ { FT_Pos x; FT_Pos y; } FT_Vector;
typedef struct FT_Matrix_ { FT_Fixed xx, xy, yx, yy; } FT_Matrix;
typedef struct FT_Bitmap_ {
  unsigned int rows;
  unsigned int width;
  int pitch;
  unsigned char *buffer;
  unsigned short num_grays;
  unsigned char pixel_mode;
  unsigned char palette_mode;
  void *palette;
} FT_Bitmap;
typedef struct FT_Glyph_Metrics_ {
  FT_Pos width;
  FT_Pos height;
  FT_Pos horiBearingX;
  FT_Pos horiBearingY;
  FT_Pos horiAdvance;
  FT_Pos vertBearingX;
  FT_Pos vertBearingY;
  FT_Pos vertAdvance;
} FT_Glyph_Metrics;
typedef struct FT_Size_Metrics_ {
  FT_UShort x_ppem;
  FT_UShort y_ppem;
  FT_Fixed x_scale;
  FT_Fixed y_scale;
  FT_Pos ascender;
  FT_Pos descender;
  FT_Pos height;
  FT_Pos max_advance;
} FT_Size_Metrics;
typedef struct FT_SizeRec_ {
  char _pad[24];
  FT_Size_Metrics metrics;
} FT_SizeRec;
typedef struct FT_GlyphSlotRec_ {
  char _pad0[48];
  FT_Glyph_Metrics metrics;
  char _pad1[16];
  FT_Vector advance;
  long format;
  FT_Bitmap bitmap;
  FT_Int bitmap_left;
  FT_Int bitmap_top;
} FT_GlyphSlotRec;
typedef struct FT_FaceRec_ {
  FT_Long num_faces;
  FT_Long face_index;
  FT_Long face_flags;
  char _pad[128];
  FT_GlyphSlot glyph;
  FT_Size size;
} FT_FaceRec;
FT_Error FT_Init_FreeType(FT_Library *alibrary);
FT_Error FT_Done_FreeType(FT_Library library);
FT_Error FT_New_Face(FT_Library library, const char *filepathname, FT_Long face_index, FT_Face *aface);
FT_Error FT_Done_Face(FT_Face face);
FT_Error FT_Set_Char_Size(FT_Face face, FT_F26Dot6 char_width, FT_F26Dot6 char_height, FT_UInt horz_resolution, FT_UInt vert_resolution);
void FT_Set_Transform(FT_Face face, FT_Matrix *matrix, FT_Vector *delta);
FT_UInt FT_Get_Char_Index(FT_Face face, FT_ULong charcode);
FT_Error FT_Load_Glyph(FT_Face face, FT_UInt glyph_index, FT_Int32 load_flags);
FT_Error FT_Render_Glyph(FT_GlyphSlot slot, FT_Int32 render_mode);
FT_Error FT_Get_Kerning(FT_Face face, FT_UInt left_glyph, FT_UInt right_glyph, FT_UInt kern_mode, FT_Vector *akerning);
CDEF;

        foreach (['libfreetype.so.6', 'libfreetype.so', 'freetype'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable $e) {
                self::$ffi = null;
            }
        }
        self::$ffiUnavailable = true;

        return null;
    }

    /** @return \FFI\CData|null */
    private static function library(\FFI $ffi)
    {
        if (null !== self::$library) {
            return self::$library;
        }
        $lib = $ffi->new('FT_Library');
        if (0 !== (int) $ffi->FT_Init_FreeType(\FFI::addr($lib))) {
            return null;
        }
        self::$library = $lib;

        return self::$library;
    }

    private static function gdRoundUpDown(int $v, bool $up): int
    {
        return $up ? (($v + 63) >> 6) : ($v >> 6);
    }

    /**
     * @return list<int>
     */
    private static function utf8Codepoints(string $text): array
    {
        $out = [];
        $len = \strlen($text);
        for ($i = 0; $i < $len; ) {
            $c = \ord($text[$i]);
            if ($c < 0x80) {
                $out[] = $c;
                ++$i;
            } elseif ($c < 0xE0 && $i + 1 < $len) {
                $out[] = (($c & 0x1F) << 6) | (\ord($text[$i + 1]) & 0x3F);
                $i += 2;
            } elseif ($c < 0xF0 && $i + 2 < $len) {
                $out[] = (($c & 0x0F) << 12)
                    | ((\ord($text[$i + 1]) & 0x3F) << 6)
                    | (\ord($text[$i + 2]) & 0x3F);
                $i += 3;
            } elseif ($i + 3 < $len) {
                $out[] = (($c & 0x07) << 18)
                    | ((\ord($text[$i + 1]) & 0x3F) << 12)
                    | ((\ord($text[$i + 2]) & 0x3F) << 6)
                    | (\ord($text[$i + 3]) & 0x3F);
                $i += 4;
            } else {
                $out[] = $c;
                ++$i;
            }
        }

        return $out;
    }

    private static function blitBitmap(GdImageState $state, \FFI\CData $bitmap, int $penX, int $penY, int $fg): void
    {
        $rows = (int) $bitmap->rows;
        $width = (int) $bitmap->width;
        $pitch = (int) $bitmap->pitch;
        $mode = (int) $bitmap->pixel_mode;
        if ($rows <= 0 || $width <= 0 || null === $bitmap->buffer) {
            return;
        }
        $buf = $bitmap->buffer;
        $fgRgb = $fg & 0xFFFFFF;
        for ($row = 0; $row < $rows; ++$row) {
            $rowOff = $row * $pitch;
            for ($col = 0; $col < $width; ++$col) {
                if (self::FT_PIXEL_MODE_GRAY === $mode) {
                    $cell = $buf[$rowOff + $col];
                    $gray = \is_int($cell) ? ($cell & 0xFF) : \ord($cell);
                    if (0 === $gray) {
                        continue;
                    }
                    $alpha = 127 - (int) (($gray * 127) / 255);
                    $color = (($alpha & 0x7F) << 24) | $fgRgb;
                } elseif (self::FT_PIXEL_MODE_MONO === $mode) {
                    $cell = $buf[$rowOff + ($col >> 3)];
                    $byte = \is_int($cell) ? ($cell & 0xFF) : \ord($cell);
                    if (0 === (($byte << ($col & 7)) & 0x80)) {
                        continue;
                    }
                    $color = $fgRgb;
                } else {
                    continue;
                }
                VmGd::blendPixelAt($state, $penX + $col, $penY + $row, $color);
            }
        }
    }
}
