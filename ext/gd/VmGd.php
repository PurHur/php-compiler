<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmImage;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ObjectHandleSupport;
use PHPCompiler\VM\OutputBuffer;
use PHPCompiler\VM\Variable;

/**
 * GD image decode/encode VM helpers (php-src ext/gd/gd.c; issues #6215, #3496).
 *
 * v1 stores validated encoded bytes for lossless PNG round-trip without libgd drawing (#3496).
 */
final class VmGd
{
    public const CLASS_GDIMAGE = 'gdimage';

    public const CLASS_GDFONT = 'gdfont';

    /** IMG_GIF — imagetypes() bit (php-src php_gd.h; #20471). */
    public const IMG_GIF = 1;

    public const IMG_JPG = 2;

    public const IMG_PNG = 4;

    public const IMG_WBMP = 8;

    public const IMG_XPM = 16;

    public const IMG_WEBP = 32;

    public const IMG_BMP = 64;

    public const IMG_TGA = 128;

    public const IMG_AVIF = 256;

    /**
     * imagetypes() — honest format bitmask for this build (php-src; #20471).
     *
     * Advertises GIF/JPG/PNG/WEBP/BMP/AVIF/WBMP/TGA (encoders/readers present). Omits XPM
     * (XPM soft-fails — no libXpm; #20472).
     */
    public static function imageTypesMask(): int
    {
        return self::IMG_GIF
            | self::IMG_JPG
            | self::IMG_PNG
            | self::IMG_WEBP
            | self::IMG_BMP
            | self::IMG_AVIF
            | self::IMG_WBMP
            | self::IMG_TGA;
    }

    /**
     * gd_info() — php-src-shaped assoc array (php-src ext/gd/gd.c; #20471).
     */
    public static function gdInfoToHashTable(): HashTable
    {
        $ft = VmGdFreeType::available();
        $info = [
            'GD Version' => 'bundled (2.1.0 compatible)',
            'FreeType Support' => $ft,
            'GIF Read Support' => true,
            'GIF Create Support' => true,
            'JPEG Support' => true,
            'PNG Support' => true,
            'WBMP Support' => true,
            'XPM Support' => false,
            'XBM Support' => true,
            'WebP Support' => true,
            'BMP Support' => true,
            'AVIF Support' => true,
            'TGA Read Support' => true,
            'JIS-mapped Japanese Font Support' => false,
        ];
        if ($ft) {
            // Insert FreeType Linkage after FreeType Support like php-src.
            $ordered = [];
            foreach ($info as $key => $value) {
                $ordered[$key] = $value;
                if ('FreeType Support' === $key) {
                    $ordered['FreeType Linkage'] = 'with freetype';
                }
            }
            $info = $ordered;
        }

        $ht = new HashTable();
        foreach ($info as $key => $value) {
            $slot = new Variable();
            if (\is_bool($value)) {
                $slot->bool($value);
            } else {
                $slot->string((string) $value);
            }
            $ht->add((string) $key, $slot);
        }

        return $ht;
    }

    public static function createFromString(Frame $frame, string $data): ObjectEntry|false
    {
        $parsed = VmImage::getImageSizeFromBytes($data);
        if (false === $parsed) {
            self::warnInvalidImageFormat($frame, 'imagecreatefromstring');

            return false;
        }
        $imageType = (int) $parsed[2];
        if (VmImage::IMAGETYPE_PNG !== $imageType) {
            self::warnInvalidImageFormat($frame, 'imagecreatefromstring');

            return false;
        }

        return self::createImage($frame->vmContext, $data, $imageType);
    }

    public static function createImage(Context $ctx, string $encoded, int $imageType): ObjectEntry
    {
        $class = $ctx->classes[self::CLASS_GDIMAGE] ?? null;
        if (null === $class) {
            throw new \LogicException('GdImage is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        GdRegistry::attach($entry, GdImageState::fromEncoded($encoded, $imageType));

        return $entry;
    }

    public static function createTruecolorImage(Frame $frame, int $width, int $height): ObjectEntry|false
    {
        if ($width <= 0 || $height <= 0) {
            self::warnInvalidDimensions($frame, 'imagecreatetruecolor');

            return false;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('imagecreatetruecolor() requires VM context');
        }
        $class = $ctx->classes[self::CLASS_GDIMAGE] ?? null;
        if (null === $class) {
            throw new \LogicException('GdImage is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        GdRegistry::attach($entry, GdImageState::createTruecolor($width, $height));

        return $entry;
    }

    /**
     * imagecreate() — palette canvas (php-src gdImageCreate; #20415).
     */
    public static function createPaletteImage(Frame $frame, int $width, int $height): ObjectEntry|false
    {
        if ($width <= 0 || $height <= 0) {
            self::warnInvalidDimensions($frame, 'imagecreate');

            return false;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('imagecreate() requires VM context');
        }
        $class = $ctx->classes[self::CLASS_GDIMAGE] ?? null;
        if (null === $class) {
            throw new \LogicException('GdImage is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        GdRegistry::attach($entry, GdImageState::createPalette($width, $height));

        return $entry;
    }

    /**
     * imageistruecolor() — im->trueColor (php-src ext/gd/gd.c; #20415).
     */
    public static function isTruecolor(ObjectEntry $image): bool
    {
        $state = GdRegistry::state($image);
        if (null === $state) {
            return false;
        }

        return $state->truecolor;
    }

    /**
     * imagetruecolortopalette() — gdImageTrueColorToPalette (php-src ext/gd/gd.c; #20415).
     *
     * Honest PHP quantization: exact map when unique colors fit; otherwise popularity
     * sampling + nearest-color remap with optional Floyd–Steinberg dither.
     */
    public static function trueColorToPalette(
        Frame $frame,
        ObjectEntry $image,
        bool $dither,
        int $numColors
    ): bool {
        if ($numColors <= 0 || $numColors >= 2147483647) {
            throw new \ValueError(
                'imagetruecolortopalette(): Argument #3 ($num_colors) must be greater than 0 and less than 2147483647'
            );
        }
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return false;
        }
        if (!$state->truecolor) {
            return true;
        }
        $wanted = $numColors > 256 ? 256 : $numColors;
        if ($wanted < 1) {
            self::warnCouldNotConvertToPalette($frame);

            return false;
        }

        $pixels = $state->pixels;
        $n = $state->width * $state->height;
        $counts = [];
        for ($i = 0; $i < $n; ++$i) {
            $rgb = $pixels[$i] & 0xFFFFFF;
            $counts[$rgb] = ($counts[$rgb] ?? 0) + 1;
        }
        arsort($counts, SORT_NUMERIC);
        $paletteKeys = array_keys($counts);
        if (\count($paletteKeys) > $wanted) {
            $paletteKeys = array_slice($paletteKeys, 0, $wanted);
        }
        $palette = [];
        foreach ($paletteKeys as $rgb) {
            $palette[] = $rgb & 0xFFFFFF;
        }
        if ([] === $palette) {
            $palette[] = 0;
        }

        $indexOf = [];
        foreach ($palette as $idx => $rgb) {
            $indexOf[$rgb] = $idx;
        }

        $mapped = [];
        if ($dither) {
            $width = $state->width;
            $height = $state->height;
            $work = [];
            for ($i = 0; $i < $n; ++$i) {
                $c = $pixels[$i];
                $work[$i] = [
                    (float) (($c >> 16) & 0xFF),
                    (float) (($c >> 8) & 0xFF),
                    (float) ($c & 0xFF),
                ];
            }
            for ($y = 0; $y < $height; ++$y) {
                for ($x = 0; $x < $width; ++$x) {
                    $pos = $y * $width + $x;
                    $r = (int) max(0, min(255, (int) round($work[$pos][0])));
                    $g = (int) max(0, min(255, (int) round($work[$pos][1])));
                    $b = (int) max(0, min(255, (int) round($work[$pos][2])));
                    $idx = self::nearestPaletteIndex($palette, $r, $g, $b);
                    $mapped[$pos] = $idx;
                    $pr = ($palette[$idx] >> 16) & 0xFF;
                    $pg = ($palette[$idx] >> 8) & 0xFF;
                    $pb = $palette[$idx] & 0xFF;
                    $er = $work[$pos][0] - $pr;
                    $eg = $work[$pos][1] - $pg;
                    $eb = $work[$pos][2] - $pb;
                    self::ditherDiffuse($work, $width, $height, $x, $y, $er, $eg, $eb);
                }
            }
        } else {
            for ($i = 0; $i < $n; ++$i) {
                $rgb = $pixels[$i] & 0xFFFFFF;
                if (isset($indexOf[$rgb])) {
                    $mapped[$i] = $indexOf[$rgb];
                } else {
                    $mapped[$i] = self::nearestPaletteIndex(
                        $palette,
                        ($rgb >> 16) & 0xFF,
                        ($rgb >> 8) & 0xFF,
                        $rgb & 0xFF
                    );
                }
            }
        }

        $state->pixels = $mapped;
        $state->colors = $palette;
        $state->truecolor = false;
        $state->alphaBlending = GdConstants::REGISTERED['IMG_EFFECT_REPLACE'];
        $state->encoded = '';

        return true;
    }

    /**
     * imageloadfont() — load architecture-dependent .gdf dump (php-src ext/gd/gd.c; #20486).
     */
    public static function loadFont(Frame $frame, string $filename): ObjectEntry|false
    {
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('imageloadfont() requires VM context');
        }
        $bytes = VmFs::fileGetContents($filename, false, null, 0, null, $ctx);
        if (false === $bytes) {
            return false;
        }
        $len = \strlen($bytes);
        if ($len < 16) {
            $ctx->errors->triggerError(
                'imageloadfont(): End of file while reading header',
                ErrorReporter::E_WARNING,
                '' !== $frame->scriptPath ? $frame->scriptPath : null,
                $ctx,
                $frame
            );

            return false;
        }
        $fontData = GdFonts::parseGdf($bytes);
        if (null === $fontData) {
            $ctx->errors->triggerError(
                'imageloadfont(): Error reading font, invalid font header',
                ErrorReporter::E_WARNING,
                '' !== $frame->scriptPath ? $frame->scriptPath : null,
                $ctx,
                $frame
            );

            return false;
        }
        $class = $ctx->classes[self::CLASS_GDFONT] ?? null;
        if (null === $class) {
            throw new \LogicException('GdFont is not registered in this compiler build');
        }
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        GdFontRegistry::attach($entry, $fontData);

        return $entry;
    }

    /**
     * imagecolormatch() — gdImageColorMatch (php-src ext/gd/libgd/gd_color_match.c; #20486).
     *
     * Averages truecolor source pixels into the palette destination's color table
     * keyed by palette index occupancy.
     */
    public static function colorMatch(ObjectEntry $image1, ObjectEntry $image2): bool
    {
        $im1 = GdRegistry::state($image1);
        $im2 = GdRegistry::state($image2);
        if (null === $im1 || !$im1->hasRaster() || !$im1->truecolor) {
            throw new \ValueError('imagecolormatch(): Argument #1 ($image1) must be TrueColor');
        }
        if (null === $im2 || !$im2->hasRaster() || $im2->truecolor) {
            throw new \ValueError('imagecolormatch(): Argument #2 ($image2) must be Palette');
        }
        if ($im1->width !== $im2->width || $im1->height !== $im2->height) {
            throw new \ValueError(
                'imagecolormatch(): Argument #2 ($image2) must be the same size as argument #1 ($image1)'
            );
        }
        $colorsTotal = \count($im2->colors);
        if ($colorsTotal < 1) {
            throw new \ValueError('imagecolormatch(): Argument #2 ($image2) must have at least one color');
        }

        $count = [];
        $sumR = [];
        $sumG = [];
        $sumB = [];
        $sumA = [];
        for ($i = 0; $i < $colorsTotal; ++$i) {
            $count[$i] = 0;
            $sumR[$i] = 0;
            $sumG[$i] = 0;
            $sumB[$i] = 0;
            $sumA[$i] = 0;
        }

        $n = $im1->width * $im1->height;
        for ($i = 0; $i < $n; ++$i) {
            $color = $im2->pixels[$i];
            if ($color < 0 || $color >= $colorsTotal) {
                continue;
            }
            $rgb = $im1->pixels[$i];
            ++$count[$color];
            $sumR[$color] += ($rgb >> 16) & 0xFF;
            $sumG[$color] += ($rgb >> 8) & 0xFF;
            $sumB[$color] += $rgb & 0xFF;
            $sumA[$color] += ($rgb >> 24) & 0x7F;
        }

        for ($color = 0; $color < $colorsTotal; ++$color) {
            $c = $count[$color];
            if ($c > 0) {
                $im2->colors[$color] = (((int) ($sumA[$color] / $c) & 0x7F) << 24)
                    | (((int) ($sumR[$color] / $c) & 0xFF) << 16)
                    | (((int) ($sumG[$color] / $c) & 0xFF) << 8)
                    | ((int) ($sumB[$color] / $c) & 0xFF);
            }
        }

        return true;
    }

    /**
     * Resolve GdFont|int for imagestring/imagechar (php-src php_find_gd_font; #20486).
     *
     * @return array{nchars:int,offset:int,w:int,h:int,data:string}
     */
    public static function resolveFont(Variable $arg, string $function, int $position): array
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_OBJECT === $arg->type) {
            $object = $arg->toObject();
            $fontData = GdFontRegistry::font($object);
            if (null !== $fontData) {
                return $fontData;
            }
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($font) must be of type GdFont|int, %s given',
                $function,
                $position,
                $object->class->name
            ));
        }
        if (EnumCaseSupport::isEnumCaseVariable($arg)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($font) must be of type GdFont|int, %s given',
                $function,
                $position,
                EnumCaseSupport::typeNameForVariable($arg)
            ));
        }
        if (Variable::TYPE_INTEGER !== $arg->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($font) must be of type GdFont|int, %s given',
                $function,
                $position,
                self::typeLabel($arg)
            ));
        }

        return GdFonts::get($arg->toInt());
    }

    /**
     * imagepalettetotruecolor() — gdImagePaletteToTrueColor (php-src ext/gd/gd.c; #20415).
     */
    public static function paletteToTrueColor(ObjectEntry $image): bool
    {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return false;
        }
        if ($state->truecolor) {
            return true;
        }
        $colors = $state->colors;
        $n = $state->width * $state->height;
        $pixels = $state->pixels;
        $expanded = [];
        for ($i = 0; $i < $n; ++$i) {
            $idx = $pixels[$i];
            $expanded[$i] = isset($colors[$idx]) ? ($colors[$idx] & 0xFFFFFF) : 0;
        }
        $state->pixels = $expanded;
        $state->colors = [];
        $state->truecolor = true;
        $state->alphaBlending = GdConstants::REGISTERED['IMG_EFFECT_ALPHABLEND'];
        $state->encoded = '';

        return true;
    }

    /**
     * imagegetinterpolation() — im->interpolation_id (php-src ext/gd/gd.c; #20416).
     */
    public static function getInterpolation(ObjectEntry $image): int
    {
        $state = GdRegistry::state($image);
        if (null === $state) {
            return GdConstants::REGISTERED['IMG_BILINEAR_FIXED'];
        }

        return $state->interpolationId;
    }

    /**
     * imagesetinterpolation() — gdImageSetInterpolationMethod (php-src ext/gd/libgd; #20416).
     *
     * method -1 resets to IMG_BILINEAR_FIXED; IMG_DEFAULT remaps to BILINEAR_FIXED (PHP-8.4 libgd).
     */
    public static function setInterpolation(ObjectEntry $image, int $method): bool
    {
        $state = GdRegistry::state($image);
        if (null === $state) {
            return false;
        }
        if (-1 === $method) {
            $method = GdConstants::REGISTERED['IMG_BILINEAR_FIXED'];
        }
        if ($method < 0 || $method > GdConstants::REGISTERED['IMG_WEIGHTED4']) {
            return false;
        }
        if (GdConstants::REGISTERED['IMG_DEFAULT'] === $method) {
            $method = GdConstants::REGISTERED['IMG_BILINEAR_FIXED'];
        }
        $state->interpolationId = $method;

        return true;
    }

    public static function colorAllocate(ObjectEntry $image, int $red, int $green, int $blue): int|false
    {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return false;
        }
        if ($red < 0 || $red > 255 || $green < 0 || $green > 255 || $blue < 0 || $blue > 255) {
            return false;
        }
        $rgb = ($red << 16) | ($green << 8) | $blue;
        if ($state->truecolor) {
            return $rgb;
        }
        foreach ($state->colors as $idx => $existing) {
            if (($existing & 0xFFFFFF) === $rgb) {
                return $idx;
            }
        }
        if (\count($state->colors) >= 256) {
            return false;
        }
        $state->colors[] = $rgb;

        return \count($state->colors) - 1;
    }

    /**
     * imagecolorclosest() — gdImageColorClosest (php-src ext/gd/libgd/gd.c; #20440).
     */
    public static function colorClosest(ObjectEntry $image, int $red, int $green, int $blue): int
    {
        return self::colorClosestAlpha($image, $red, $green, $blue, 0);
    }

    /**
     * gdImageColorClosestAlpha — truecolor packs ARGB; palette uses Euclidean distance (#20440).
     */
    public static function colorClosestAlpha(
        ObjectEntry $image,
        int $red,
        int $green,
        int $blue,
        int $alpha
    ): int {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return -1;
        }
        if ($state->truecolor) {
            return (($alpha & 0x7F) << 24) | (($red & 0xFF) << 16) | (($green & 0xFF) << 8) | ($blue & 0xFF);
        }
        $ct = -1;
        $first = true;
        $mindist = 0;
        foreach ($state->colors as $i => $packed) {
            $rd = (($packed >> 16) & 0xFF) - $red;
            $gd = (($packed >> 8) & 0xFF) - $green;
            $bd = ($packed & 0xFF) - $blue;
            $ad = (($packed >> 24) & 0x7F) - $alpha;
            $dist = $rd * $rd + $gd * $gd + $bd * $bd + $ad * $ad;
            if ($first || $dist < $mindist) {
                $mindist = $dist;
                $ct = (int) $i;
                $first = false;
            }
        }

        return $ct;
    }

    /**
     * imagecolorclosesthwb() — gdImageColorClosestHWB (php-src ext/gd/libgd/gd.c; #20473).
     *
     * Palette images: HWB distance (Smith/Lyons + Philip Warner metric).
     * Truecolor: gdTrueColor pack (opaque RGB).
     */
    public static function colorClosestHwb(ObjectEntry $image, int $red, int $green, int $blue): int
    {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return -1;
        }
        if ($state->truecolor) {
            return (($red & 0xFF) << 16) | (($green & 0xFF) << 8) | ($blue & 0xFF);
        }
        $ct = -1;
        $first = true;
        $mindist = 0.0;
        foreach ($state->colors as $i => $packed) {
            $dist = self::hwbDiff(
                ($packed >> 16) & 0xFF,
                ($packed >> 8) & 0xFF,
                $packed & 0xFF,
                $red,
                $green,
                $blue
            );
            if ($first || $dist < $mindist) {
                $mindist = $dist;
                $ct = (int) $i;
                $first = false;
            }
        }

        return $ct;
    }

    /** HWB_UNDEFINED sentinel from libgd (hue not defined when W == 1 - B). */
    private const HWB_UNDEFINED = -1.0;

    /**
     * HWB_Diff — php-src ext/gd/libgd/gd.c (Smith/Lyons RGB↔HWB + Warner distance).
     */
    private static function hwbDiff(int $r1, int $g1, int $b1, int $r2, int $g2, int $b2): float
    {
        $hwb1 = self::rgbToHwb($r1 / 255.0, $g1 / 255.0, $b1 / 255.0);
        $hwb2 = self::rgbToHwb($r2 / 255.0, $g2 / 255.0, $b2 / 255.0);

        if ($hwb1[0] === self::HWB_UNDEFINED || $hwb2[0] === self::HWB_UNDEFINED) {
            $diff = 0.0;
        } else {
            $diff = \abs($hwb1[0] - $hwb2[0]);
            if ($diff > 3.0) {
                $diff = 6.0 - $diff;
            }
        }

        return $diff * $diff
            + ($hwb1[1] - $hwb2[1]) * ($hwb1[1] - $hwb2[1])
            + ($hwb1[2] - $hwb2[2]) * ($hwb1[2] - $hwb2[2]);
    }

    /**
     * RGB_to_HWB — returns [H, W, B] with H on [0,6] or HWB_UNDEFINED.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    private static function rgbToHwb(float $R, float $G, float $B): array
    {
        $w = \min($R, $G, $B);
        $v = \max($R, $G, $B);
        $b = 1.0 - $v;
        if ($v === $w) {
            return [self::HWB_UNDEFINED, $w, $b];
        }
        $f = ($R === $w) ? ($G - $B) : (($G === $w) ? ($B - $R) : ($R - $G));
        $i = ($R === $w) ? 3 : (($G === $w) ? 5 : 1);

        return [$i - $f / ($v - $w), $w, $b];
    }

    /**
     * imagecolorexact() — opaque exact match (php-src gdImageColorExact; #20459).
     */
    public static function colorExact(ObjectEntry $image, int $red, int $green, int $blue): int
    {
        return self::colorExactAlpha($image, $red, $green, $blue, 0);
    }

    /**
     * gdImageColorExactAlpha — truecolor packs ARGB; palette exact RGBA (#20459).
     */
    public static function colorExactAlpha(
        ObjectEntry $image,
        int $red,
        int $green,
        int $blue,
        int $alpha
    ): int {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return -1;
        }
        if ($state->truecolor) {
            return (($alpha & 0x7F) << 24) | (($red & 0xFF) << 16) | (($green & 0xFF) << 8) | ($blue & 0xFF);
        }
        $want = (($alpha & 0x7F) << 24) | (($red & 0xFF) << 16) | (($green & 0xFF) << 8) | ($blue & 0xFF);
        foreach ($state->colors as $i => $packed) {
            if ($packed === $want) {
                return (int) $i;
            }
        }

        return -1;
    }

    /**
     * imagecolorresolve() — exact or allocate or closest (php-src gdImageColorResolve; #20459).
     */
    public static function colorResolve(ObjectEntry $image, int $red, int $green, int $blue): int
    {
        return self::colorResolveAlpha($image, $red, $green, $blue, 0);
    }

    /**
     * gdImageColorResolveAlpha without open-slot reuse (dense palette; #20459).
     */
    public static function colorResolveAlpha(
        ObjectEntry $image,
        int $red,
        int $green,
        int $blue,
        int $alpha
    ): int {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return -1;
        }
        if ($state->truecolor) {
            return (($alpha & 0x7F) << 24) | (($red & 0xFF) << 16) | (($green & 0xFF) << 8) | ($blue & 0xFF);
        }
        $exact = self::colorExactAlpha($image, $red, $green, $blue, $alpha);
        if (-1 !== $exact && $exact !== $state->transparent) {
            return $exact;
        }
        // Prefer allocate when room (skip transparent index as resolve target).
        if (\count($state->colors) < 256) {
            $state->colors[] = (($alpha & 0x7F) << 24)
                | (($red & 0xFF) << 16)
                | (($green & 0xFF) << 8)
                | ($blue & 0xFF);

            return \count($state->colors) - 1;
        }
        $ct = -1;
        $mindist = 4 * 255 * 255;
        foreach ($state->colors as $i => $packed) {
            if ((int) $i === $state->transparent) {
                continue;
            }
            $rd = (($packed >> 16) & 0xFF) - $red;
            $gd = (($packed >> 8) & 0xFF) - $green;
            $bd = ($packed & 0xFF) - $blue;
            $ad = (($packed >> 24) & 0x7F) - $alpha;
            $dist = $rd * $rd + $gd * $gd + $bd * $bd + $ad * $ad;
            if (0 === $dist) {
                return (int) $i;
            }
            if ($dist < $mindist) {
                $mindist = $dist;
                $ct = (int) $i;
            }
        }

        return $ct;
    }

    /**
     * imagecolortransparent() get/set (php-src gdImageColorTransparent; #20459).
     */
    public static function colorTransparent(ObjectEntry $image, ?int $color): int
    {
        $state = GdRegistry::state($image);
        if (null === $state) {
            return -1;
        }
        if (null !== $color) {
            if (-1 === $color) {
                if (!$state->truecolor && $state->transparent >= 0 && isset($state->colors[$state->transparent])) {
                    $state->colors[$state->transparent] = $state->colors[$state->transparent] & 0xFFFFFF;
                }
                $state->transparent = -1;
            } elseif ($color >= -1) {
                if ($state->truecolor) {
                    $state->transparent = $color;
                } elseif ($color < 256) {
                    if ($state->transparent !== -1 && isset($state->colors[$state->transparent])) {
                        $state->colors[$state->transparent] = $state->colors[$state->transparent] & 0xFFFFFF;
                    }
                    if (isset($state->colors[$color])) {
                        $state->colors[$color] = ($state->colors[$color] & 0xFFFFFF) | (127 << 24);
                    }
                    $state->transparent = $color;
                }
            }
        }

        return $state->transparent;
    }

    /**
     * imagecolorset() — mutate palette slot (php-src; success null / failure false; #20440).
     *
     * @return null|false
     */
    public static function colorSet(
        ObjectEntry $image,
        int $color,
        int $red,
        int $green,
        int $blue,
        int $alpha = 0
    ): mixed {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster() || $state->truecolor) {
            return false;
        }
        if ($color < 0 || $color >= \count($state->colors)) {
            return false;
        }
        $state->colors[$color] = (($alpha & 0x7F) << 24)
            | (($red & 0xFF) << 16)
            | (($green & 0xFF) << 8)
            | ($blue & 0xFF);

        return null;
    }

    /**
     * imagecolorsforindex() — red/green/blue/alpha assoc (php-src; #20440).
     *
     * @return array{red: int, green: int, blue: int, alpha: int}
     */
    public static function colorsForIndex(ObjectEntry $image, int $index): array
    {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            throw new \ValueError('imagecolorsforindex(): Argument #2 ($color) is out of range');
        }
        if ($state->truecolor) {
            if ($index < 0) {
                throw new \ValueError('imagecolorsforindex(): Argument #2 ($color) is out of range');
            }

            return [
                'red' => ($index >> 16) & 0xFF,
                'green' => ($index >> 8) & 0xFF,
                'blue' => $index & 0xFF,
                'alpha' => ($index >> 24) & 0x7F,
            ];
        }
        if ($index < 0 || $index >= \count($state->colors)) {
            throw new \ValueError('imagecolorsforindex(): Argument #2 ($color) is out of range');
        }
        $packed = $state->colors[$index];

        return [
            'red' => ($packed >> 16) & 0xFF,
            'green' => ($packed >> 8) & 0xFF,
            'blue' => $packed & 0xFF,
            'alpha' => ($packed >> 24) & 0x7F,
        ];
    }

    /**
     * @param array{red: int, green: int, blue: int, alpha: int} $components
     */
    public static function colorsForIndexToHashTable(array $components): HashTable
    {
        $ht = new HashTable();
        foreach ($components as $key => $value) {
            $var = new Variable();
            $var->int($value);
            $ht->update($key, $var);
        }

        return $ht;
    }

    /** php-src CHECK_RGBA_RANGE for Red/Green/Blue (0..255) or Alpha (0..127). */
    public static function requireRgbaComponent(
        int $value,
        string $function,
        int $position,
        string $paramName,
        int $max
    ): void {
        if ($value < 0 || $value > $max) {
            throw new \ValueError(\sprintf(
                '%s(): Argument #%d ($%s) must be between 0 and %d (inclusive)',
                $function,
                $position,
                $paramName,
                $max
            ));
        }
    }

    /**
     * imagecolorallocatealpha() — GD truecolor ARGB (alpha 0 opaque .. 127 transparent; #6535).
     */
    public static function colorAllocateAlpha(
        ObjectEntry $image,
        int $red,
        int $green,
        int $blue,
        int $alpha
    ): int|false {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster() || !$state->truecolor) {
            return false;
        }
        if ($red < 0 || $red > 255 || $green < 0 || $green > 255 || $blue < 0 || $blue > 255) {
            return false;
        }
        if ($alpha < 0) {
            $alpha = 0;
        }
        if ($alpha > 127) {
            $alpha = 127;
        }

        return (($alpha & 0x7F) << 24) | (($red & 0xFF) << 16) | (($green & 0xFF) << 8) | ($blue & 0xFF);
    }

    /**
     * imagealphablending() — sets alphaBlendingFlag to 0/1 (php-src RETURN_TRUE; #6535).
     */
    public static function setAlphaBlending(ObjectEntry $image, bool $enable): bool
    {
        $state = GdRegistry::state($image);
        if (null === $state) {
            return false;
        }
        $state->alphaBlending = $enable
            ? GdConstants::REGISTERED['IMG_EFFECT_ALPHABLEND']
            : GdConstants::REGISTERED['IMG_EFFECT_REPLACE'];

        return true;
    }

    /**
     * imagelayereffect() — gdImageAlphaBlending(im, effect) (php-src ext/gd/gd.c; #20429).
     */
    public static function setLayerEffect(ObjectEntry $image, int $effect): bool
    {
        $state = GdRegistry::state($image);
        if (null === $state) {
            return false;
        }
        $state->alphaBlending = $effect;

        return true;
    }

    /**
     * imageresolution() getter — [res_x, res_y] (php-src ext/gd/gd.c; #20430).
     *
     * @return array{0: int, 1: int}|null
     */
    public static function getResolution(ObjectEntry $image): ?array
    {
        $state = GdRegistry::state($image);
        if (null === $state) {
            return null;
        }

        return [$state->resX, $state->resY];
    }

    /**
     * imageresolution() setter — gdImageSetResolution (php-src ext/gd/libgd/gd.c; #20430).
     * Values of 0 leave that axis unchanged (libgd `if (res > 0)`).
     */
    public static function setResolution(ObjectEntry $image, int $resX, int $resY): bool
    {
        $state = GdRegistry::state($image);
        if (null === $state) {
            return false;
        }
        if ($resX > 0) {
            $state->resX = $resX;
        }
        if ($resY > 0) {
            $state->resY = $resY;
        }

        return true;
    }

    /**
     * @return HashTable indexed [res_x, res_y]
     */
    public static function resolutionToHashTable(int $resX, int $resY): HashTable
    {
        return self::brectToHashTable([$resX, $resY]);
    }

    /** UINT_MAX — imageresolution() ValueError upper bound (php-src #20430). */
    public const RESOLUTION_UINT_MAX = 4294967295;

    /** imageopenpolygon / imagepolygon filled flag (php-src php_imagepolygon; #20431). */
    public const POLYGON_OPEN = -1;

    public const POLYGON_CLOSED = 0;

    /**
     * Stroke polygon via gdImageOpenPolygon / gdImagePolygon (php-src ext/gd/libgd/gd.c; #20431).
     *
     * @param list<array{0: int, 1: int}> $points
     */
    public static function strokePolygon(ObjectEntry $image, array $points, int $color, int $mode): bool
    {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return false;
        }
        $n = \count($points);
        if ($n <= 0) {
            return true;
        }
        if (self::POLYGON_CLOSED === $mode) {
            self::line(
                $image,
                $points[0][0],
                $points[0][1],
                $points[$n - 1][0],
                $points[$n - 1][1],
                $color
            );
        }
        for ($i = 1; $i < $n; ++$i) {
            self::line(
                $image,
                $points[$i - 1][0],
                $points[$i - 1][1],
                $points[$i][0],
                $points[$i][1],
                $color
            );
        }

        return true;
    }

    /**
     * Parse image*polygon() args (php-src ext/gd/gd.c php_imagepolygon; #20431).
     *
     * @return array{0: list<array{0: int, 1: int}>, 1: int}
     */
    public static function parsePolygonArgs(Frame $frame, string $function): array
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \LogicException($function.'() expects 3 to 4 arguments in this compiler build');
        }
        $pointsArg = $frame->calledArgs[1]->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($pointsArg)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #2 ($points) must be of type array, %s given',
                $function,
                EnumCaseSupport::typeNameForVariable($pointsArg)
            ));
        }
        if (Variable::TYPE_ARRAY !== $pointsArg->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #2 ($points) must be of type array, %s given',
                $function,
                self::typeLabel($pointsArg)
            ));
        }
        $table = $pointsArg->toArray();
        $nelem = $table->getNumElements();

        $legacyNumPoints = false;
        if (4 === $argc) {
            $colorArg = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL === $colorArg->type) {
                $color = self::coerceIntArg($frame->calledArgs[2], $function, 3, 'num_points_or_color');
                if (0 !== $nelem % 2) {
                    throw new \ValueError($function.'(): Argument #2 ($points) must have an even number of elements');
                }
                $npoints = intdiv($nelem, 2);
            } else {
                $legacyNumPoints = true;
                $npoints = self::coerceIntArg($frame->calledArgs[2], $function, 3, 'num_points_or_color');
                $color = self::coerceIntArg($colorArg, $function, 4, 'color');
            }
        } else {
            $color = self::coerceIntArg($frame->calledArgs[2], $function, 3, 'num_points_or_color');
            if (0 !== $nelem % 2) {
                throw new \ValueError($function.'(): Argument #2 ($points) must have an even number of elements');
            }
            $npoints = intdiv($nelem, 2);
        }

        if ($legacyNumPoints && null !== $frame->vmContext) {
            $frame->vmContext->errors->triggerError(
                $function.'(): Using the $num_points parameter is deprecated',
                ErrorReporter::E_DEPRECATED,
                '' !== $frame->scriptPath ? $frame->scriptPath : null,
                $frame->vmContext,
                $frame
            );
        }

        if ($npoints < 3) {
            throw new \ValueError(\sprintf(
                '%s(): Argument #3 ($num_points_or_color) must be greater than or equal to 3',
                $function
            ));
        }
        if ($nelem < $npoints * 2) {
            throw new \ValueError(\sprintf(
                'Trying to use %d points in array with only %d points',
                $npoints,
                intdiv($nelem, 2)
            ));
        }

        $points = [];
        for ($i = 0; $i < $npoints; ++$i) {
            $xVar = $table->findIndex($i * 2);
            $yVar = $table->findIndex($i * 2 + 1);
            $points[] = [
                null !== $xVar ? self::coercePolygonCoord($xVar->resolveIndirect()) : 0,
                null !== $yVar ? self::coercePolygonCoord($yVar->resolveIndirect()) : 0,
            ];
        }

        return [$points, $color];
    }

    private static function coercePolygonCoord(Variable $arg): int
    {
        if (Variable::TYPE_INTEGER === $arg->type) {
            return $arg->toInt();
        }
        if (Variable::TYPE_FLOAT === $arg->type) {
            return (int) $arg->toFloat();
        }
        if (Variable::TYPE_BOOLEAN === $arg->type) {
            return $arg->toBool() ? 1 : 0;
        }
        if (Variable::TYPE_STRING === $arg->type) {
            return (int) $arg->toString();
        }

        return 0;
    }

    /**
     * imagesavealpha() — always true like php-src RETURN_TRUE (#6535).
     */
    public static function setSaveAlpha(ObjectEntry $image, bool $enable): bool
    {
        $state = GdRegistry::state($image);
        if (null === $state) {
            return false;
        }
        $state->saveAlpha = $enable;

        return true;
    }

    /**
     * imagesetthickness() — always true like php-src RETURN_TRUE (#20406).
     * php-src: ext/gd/gd.c PHP_FUNCTION(imagesetthickness) → gdImageSetThickness.
     */
    public static function setThickness(ObjectEntry $image, int $thickness): bool
    {
        $state = GdRegistry::state($image);
        if (null === $state) {
            return false;
        }
        $state->thick = $thickness;

        return true;
    }

    /**
     * imageantialias() — always true like php-src RETURN_TRUE (#20406).
     * php-src: ext/gd/gd.c PHP_FUNCTION(imageantialias) — sets im->AA only on truecolor.
     */
    public static function setAntiAlias(ObjectEntry $image, bool $enable): bool
    {
        $state = GdRegistry::state($image);
        if (null === $state) {
            return false;
        }
        if ($state->truecolor) {
            $state->antiAlias = $enable;
        }

        return true;
    }

    public static function fill(ObjectEntry $image, int $x, int $y, int $color): bool
    {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return false;
        }
        $width = $state->width;
        $height = $state->height;
        if ($x < 0 || $y < 0 || $x >= $width || $y >= $height) {
            return false;
        }

        $pixels = $state->pixels;
        $index = $y * $width + $x;
        $target = $pixels[$index];
        if ($target === $color) {
            return true;
        }

        $stack = [[$x, $y]];
        while ([] !== $stack) {
            [$px, $py] = array_pop($stack);
            if ($px < 0 || $py < 0 || $px >= $width || $py >= $height) {
                continue;
            }
            $pos = $py * $width + $px;
            if ($pixels[$pos] !== $target) {
                continue;
            }
            $pixels[$pos] = $color;
            $stack[] = [$px + 1, $py];
            $stack[] = [$px - 1, $py];
            $stack[] = [$px, $py + 1];
            $stack[] = [$px, $py - 1];
        }
        $state->pixels = $pixels;

        return true;
    }

    /**
     * imagefilltoborder() — flood fill until border color (php-src gdImageFillToBorder; #20439).
     */
    public static function fillToBorder(ObjectEntry $image, int $x, int $y, int $border, int $color): bool
    {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return false;
        }
        // libgd refuses non-solid border/color (special negative color ids).
        if ($border < 0 || $color < 0) {
            return true;
        }
        if (!$state->truecolor) {
            $colorsTotal = \count($state->colors);
            if ($color >= $colorsTotal || $border >= $colorsTotal) {
                return true;
            }
        }

        $restoreAlpha = $state->alphaBlending;
        $state->alphaBlending = GdConstants::REGISTERED['IMG_EFFECT_REPLACE'];

        $sx = $state->width;
        $sy = $state->height;
        if ($x >= $sx) {
            $x = $sx - 1;
        } elseif ($x < 0) {
            $x = 0;
        }
        if ($y >= $sy) {
            $y = $sy - 1;
        } elseif ($y < 0) {
            $y = 0;
        }

        // Iterative stack mirrors libgd recursion (avoids PHP call-stack limits).
        $stack = [[$x, $y]];
        while ([] !== $stack) {
            [$cx, $cy] = array_pop($stack);
            $leftLimit = -1;
            for ($i = $cx; $i >= 0; --$i) {
                if (self::rawPixel($state, $i, $cy) === $border) {
                    break;
                }
                self::putPixel($state, $i, $cy, $color);
                $leftLimit = $i;
            }
            if (-1 === $leftLimit) {
                continue;
            }
            $rightLimit = $cx;
            for ($i = $cx + 1; $i < $sx; ++$i) {
                if (self::rawPixel($state, $i, $cy) === $border) {
                    break;
                }
                self::putPixel($state, $i, $cy, $color);
                $rightLimit = $i;
            }
            if ($cy > 0) {
                $lastBorder = 1;
                for ($i = $leftLimit; $i <= $rightLimit; ++$i) {
                    $c = self::rawPixel($state, $i, $cy - 1);
                    if ($lastBorder) {
                        if ($c !== $border && $c !== $color) {
                            $stack[] = [$i, $cy - 1];
                            $lastBorder = 0;
                        }
                    } elseif ($c === $border || $c === $color) {
                        $lastBorder = 1;
                    }
                }
            }
            if ($cy < $sy - 1) {
                $lastBorder = 1;
                for ($i = $leftLimit; $i <= $rightLimit; ++$i) {
                    $c = self::rawPixel($state, $i, $cy + 1);
                    if ($lastBorder) {
                        if ($c !== $border && $c !== $color) {
                            $stack[] = [$i, $cy + 1];
                            $lastBorder = 0;
                        }
                    } elseif ($c === $border || $c === $color) {
                        $lastBorder = 1;
                    }
                }
            }
        }

        $state->alphaBlending = $restoreAlpha;

        return true;
    }

    /**
     * imagesetbrush() — brush GdImage for IMG_COLOR_BRUSHED (php-src gdImageSetBrush; #20439).
     */
    public static function setBrush(ObjectEntry $image, ObjectEntry $brush): bool
    {
        $state = GdRegistry::state($image);
        $brushState = GdRegistry::state($brush);
        if (null === $state || null === $brushState) {
            return false;
        }
        $state->brush = $brush;
        $state->brushColorMap = [];
        if (!$state->truecolor && !$brushState->truecolor) {
            foreach ($brushState->colors as $i => $packed) {
                $state->brushColorMap[(int) $i] = self::colorResolveAlpha(
                    $image,
                    ($packed >> 16) & 0xFF,
                    ($packed >> 8) & 0xFF,
                    $packed & 0xFF,
                    ($packed >> 24) & 0x7F
                );
            }
        }

        return true;
    }

    /**
     * imagesetstyle() — style color list for IMG_COLOR_STYLED (php-src gdImageSetStyle; #20439).
     *
     * @param list<int> $style
     */
    public static function setStyle(ObjectEntry $image, array $style): bool
    {
        $state = GdRegistry::state($image);
        if (null === $state) {
            return false;
        }
        $state->style = array_values($style);
        $state->stylePos = 0;

        return true;
    }

    /**
     * Coerce imagesetstyle() $style array elements (zval_get_long; #20439).
     *
     * @return list<int>
     */
    public static function coerceStyleArray(Variable $arg, string $function, int $position): array
    {
        $arg = $arg->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($arg)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($style) must be of type array, %s given',
                $function,
                $position,
                EnumCaseSupport::typeNameForVariable($arg)
            ));
        }
        if (Variable::TYPE_ARRAY !== $arg->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($style) must be of type array, %s given',
                $function,
                $position,
                self::typeLabel($arg)
            ));
        }
        $table = $arg->toArray();
        if (0 === $table->getNumElements()) {
            throw new \ValueError(\sprintf(
                '%s(): Argument #%d ($style) must not be empty',
                $function,
                $position
            ));
        }
        $style = [];
        foreach ($table->iterate(true) as $item) {
            $style[] = self::coerceStyleColor($item);
        }

        return $style;
    }

    private static function coerceStyleColor(Variable $arg): int
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_INTEGER === $arg->type) {
            return $arg->toInt();
        }
        if (Variable::TYPE_FLOAT === $arg->type) {
            return (int) $arg->toFloat();
        }
        if (Variable::TYPE_BOOLEAN === $arg->type) {
            return $arg->toBool() ? 1 : 0;
        }
        if (Variable::TYPE_STRING === $arg->type) {
            return (int) $arg->toString();
        }
        if (Variable::TYPE_NULL === $arg->type) {
            return 0;
        }

        return 0;
    }

    public static function destroy(ObjectEntry $image): bool
    {
        if (null === GdRegistry::state($image)) {
            return false;
        }
        GdRegistry::forget($image);

        return true;
    }

    public static function getWidth(ObjectEntry $image): int|false
    {
        $state = GdRegistry::state($image);
        if (null === $state) {
            return false;
        }
        if ($state->hasRaster()) {
            return $state->width;
        }
        if ($state->hasEncoded()) {
            $parsed = VmImage::getImageSizeFromBytes($state->encoded);
            if (false === $parsed) {
                return false;
            }

            return (int) $parsed[0];
        }

        return false;
    }

    public static function getHeight(ObjectEntry $image): int|false
    {
        $state = GdRegistry::state($image);
        if (null === $state) {
            return false;
        }
        if ($state->hasRaster()) {
            return $state->height;
        }
        if ($state->hasEncoded()) {
            $parsed = VmImage::getImageSizeFromBytes($state->encoded);
            if (false === $parsed) {
                return false;
            }

            return (int) $parsed[1];
        }

        return false;
    }

    public static function colorAt(Frame $frame, ObjectEntry $image, int $x, int $y): int|false
    {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return false;
        }
        if ($x < 0 || $y < 0 || $x >= $state->width || $y >= $state->height) {
            self::warnColorAtOutOfBounds($frame);

            return false;
        }

        return $state->pixels[$y * $state->width + $x];
    }

    public static function setPixel(ObjectEntry $image, int $x, int $y, int $color): bool
    {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return false;
        }
        if ($x < 0 || $y < 0 || $x >= $state->width || $y >= $state->height) {
            return false;
        }
        self::putPixel($state, $x, $y, $color);

        return true;
    }

    /** Package-visible pixel write for FreeType glyph blit (#6532). */
    public static function blendPixelAt(GdImageState $state, int $x, int $y, int $color): void
    {
        self::putPixel($state, $x, $y, $color);
    }

    /**
     * imagettfbbox() — FreeType string bounding box (php-src ext/gd/gd.c; #6532).
     *
     * @return list<int>|false
     */
    public static function ttfBBox(
        Frame $frame,
        float $size,
        float $angleDegrees,
        string $fontFilename,
        string $text
    ): array|false {
        $result = VmGdFreeType::stringFT(
            null,
            $size,
            $angleDegrees * (M_PI / 180.0),
            0,
            0,
            0,
            $fontFilename,
            $text
        );
        if (\is_string($result)) {
            self::warnTtf($frame, 'imagettfbbox', $result);

            return false;
        }

        return $result;
    }

    /**
     * imagettftext() — FreeType string draw + bbox (php-src ext/gd/gd.c; #6532).
     *
     * @return list<int>|false
     */
    public static function ttfText(
        Frame $frame,
        ObjectEntry $image,
        float $size,
        float $angleDegrees,
        int $x,
        int $y,
        int $color,
        string $fontFilename,
        string $text
    ): array|false {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return false;
        }
        $result = VmGdFreeType::stringFT(
            $state,
            $size,
            $angleDegrees * (M_PI / 180.0),
            $x,
            $y,
            $color,
            $fontFilename,
            $text
        );
        if (\is_string($result)) {
            self::warnTtf($frame, 'imagettftext', $result);

            return false;
        }

        return $result;
    }

    /**
     * @param list<int> $brect
     */
    public static function brectToHashTable(array $brect): HashTable
    {
        $ht = new HashTable();
        foreach ($brect as $value) {
            $var = new Variable();
            $var->int((int) $value);
            $ht->append($var);
        }

        return $ht;
    }

    private static function warnTtf(Frame $frame, string $function, string $error): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            $function.'(): '.$error,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    /**
     * imageline() — Bresenham stroke with libgd clip/thickness/AA (php-src ext/gd/libgd/gd.c gdImageLine; #6534, #20406).
     */
    public static function line(ObjectEntry $image, int $x1, int $y1, int $x2, int $y2, int $color): bool
    {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return false;
        }
        // php-src PHP_FUNCTION(imageline): if (im->AA) { gdImageSetAntiAliased(im, col); col = gdAntiAliased; }
        if ($state->antiAlias && $state->truecolor) {
            self::aaLine($state, $x1, $y1, $x2, $y2, $color);

            return true;
        }

        $maxX = $state->width - 1;
        $maxY = $state->height - 1;
        if (!self::clip1d($x1, $y1, $x2, $y2, $maxX) || !self::clip1d($y1, $x1, $y2, $x2, $maxY)) {
            return true;
        }

        $dx = \abs($x2 - $x1);
        $dy = \abs($y2 - $y1);
        if (0 === $dx) {
            self::vLine($state, $x1, $y1, $y2, $color);

            return true;
        }
        if (0 === $dy) {
            self::hLine($state, $y1, $x1, $x2, $color);

            return true;
        }

        $thick = $state->thick;
        if ($dy <= $dx) {
            // More-or-less horizontal — wid is vertical stroke (libgd gdImageLine).
            if (0 === $dx && 0 === $dy) {
                $wid = 1;
            } else {
                $ac = \cos(\atan2($dy, $dx));
                $wid = (0.0 !== $ac) ? (int) ($thick / $ac) : 1;
                if (0 === $wid) {
                    $wid = 1;
                }
            }
            $d = 2 * $dy - $dx;
            $incr1 = 2 * $dy;
            $incr2 = 2 * ($dy - $dx);
            if ($x1 > $x2) {
                $x = $x2;
                $y = $y2;
                $ydirflag = -1;
                $xend = $x1;
            } else {
                $x = $x1;
                $y = $y1;
                $ydirflag = 1;
                $xend = $x2;
            }
            $wstart = $y - intdiv($wid, 2);
            for ($w = $wstart; $w < $wstart + $wid; ++$w) {
                self::putPixel($state, $x, $w, $color);
            }
            if ((($y2 - $y1) * $ydirflag) > 0) {
                while ($x < $xend) {
                    ++$x;
                    if ($d < 0) {
                        $d += $incr1;
                    } else {
                        ++$y;
                        $d += $incr2;
                    }
                    $wstart = $y - intdiv($wid, 2);
                    for ($w = $wstart; $w < $wstart + $wid; ++$w) {
                        self::putPixel($state, $x, $w, $color);
                    }
                }
            } else {
                while ($x < $xend) {
                    ++$x;
                    if ($d < 0) {
                        $d += $incr1;
                    } else {
                        --$y;
                        $d += $incr2;
                    }
                    $wstart = $y - intdiv($wid, 2);
                    for ($w = $wstart; $w < $wstart + $wid; ++$w) {
                        self::putPixel($state, $x, $w, $color);
                    }
                }
            }
        } else {
            // More-or-less vertical — wid is horizontal stroke.
            $as = \sin(\atan2($dy, $dx));
            $wid = (0.0 !== $as) ? (int) ($thick / $as) : 1;
            if (0 === $wid) {
                $wid = 1;
            }
            $d = 2 * $dx - $dy;
            $incr1 = 2 * $dx;
            $incr2 = 2 * ($dx - $dy);
            if ($y1 > $y2) {
                $y = $y2;
                $x = $x2;
                $yend = $y1;
                $xdirflag = -1;
            } else {
                $y = $y1;
                $x = $x1;
                $yend = $y2;
                $xdirflag = 1;
            }
            $wstart = $x - intdiv($wid, 2);
            for ($w = $wstart; $w < $wstart + $wid; ++$w) {
                self::putPixel($state, $w, $y, $color);
            }
            if ((($x2 - $x1) * $xdirflag) > 0) {
                while ($y < $yend) {
                    ++$y;
                    if ($d < 0) {
                        $d += $incr1;
                    } else {
                        ++$x;
                        $d += $incr2;
                    }
                    $wstart = $x - intdiv($wid, 2);
                    for ($w = $wstart; $w < $wstart + $wid; ++$w) {
                        self::putPixel($state, $w, $y, $color);
                    }
                }
            } else {
                while ($y < $yend) {
                    ++$y;
                    if ($d < 0) {
                        $d += $incr1;
                    } else {
                        --$x;
                        $d += $incr2;
                    }
                    $wstart = $x - intdiv($wid, 2);
                    for ($w = $wstart; $w < $wstart + $wid; ++$w) {
                        self::putPixel($state, $w, $y, $color);
                    }
                }
            }
        }

        return true;
    }

    /**
     * libgd gdImageAALine (php-src ext/gd/libgd/gd.c; #20406).
     */
    private static function aaLine(GdImageState $state, int $x1, int $y1, int $x2, int $y2, int $color): void
    {
        $maxX = $state->width - 1;
        $maxY = $state->height - 1;
        if (!self::clip1d($x1, $y1, $x2, $y2, $maxX) || !self::clip1d($y1, $x1, $y2, $x2, $maxY)) {
            return;
        }
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        if (0 === $dx && 0 === $dy) {
            return;
        }
        if (\abs($dx) > \abs($dy)) {
            if ($dx < 0) {
                $tmp = $x1;
                $x1 = $x2;
                $x2 = $tmp;
                $tmp = $y1;
                $y1 = $y2;
                $y2 = $tmp;
                $dx = $x2 - $x1;
                $dy = $y2 - $y1;
            }
            $y = $y1;
            $inc = intdiv($dy * 65536, $dx);
            $frac = 0;
            for ($x = $x1; $x <= $x2; ++$x) {
                self::putAaPixel($state, $x, $y, $color, ($frac >> 8) & 0xFF);
                if ($y + 1 < $state->height) {
                    self::putAaPixel($state, $x, $y + 1, $color, (~$frac >> 8) & 0xFF);
                }
                $frac += $inc;
                if ($frac >= 65536) {
                    $frac -= 65536;
                    ++$y;
                } elseif ($frac < 0) {
                    $frac += 65536;
                    --$y;
                }
            }
        } else {
            if ($dy < 0) {
                $tmp = $x1;
                $x1 = $x2;
                $x2 = $tmp;
                $tmp = $y1;
                $y1 = $y2;
                $y2 = $tmp;
                $dx = $x2 - $x1;
                $dy = $y2 - $y1;
            }
            $x = $x1;
            $inc = intdiv($dx * 65536, $dy);
            $frac = 0;
            for ($y = $y1; $y <= $y2; ++$y) {
                self::putAaPixel($state, $x, $y, $color, ($frac >> 8) & 0xFF);
                if ($x + 1 < $state->width) {
                    self::putAaPixel($state, $x + 1, $y, $color, (~$frac >> 8) & 0xFF);
                }
                $frac += $inc;
                if ($frac >= 65536) {
                    $frac -= 65536;
                    ++$x;
                } elseif ($frac < 0) {
                    $frac += 65536;
                    --$x;
                }
            }
        }
    }

    /**
     * libgd gdImageSetAAPixelColor — BLEND_COLOR coverage blend (php-src ext/gd/libgd/gd.c).
     */
    private static function putAaPixel(GdImageState $state, int $x, int $y, int $color, int $t): void
    {
        if ($x < 0 || $y < 0 || $x >= $state->width || $y >= $state->height) {
            return;
        }
        $index = $y * $state->width + $x;
        $p = $state->pixels[$index];
        $dr = ($color >> 16) & 0xFF;
        $dg = ($color >> 8) & 0xFF;
        $db = $color & 0xFF;
        $r = ($p >> 16) & 0xFF;
        $g = ($p >> 8) & 0xFF;
        $b = $p & 0xFF;
        // BLEND_COLOR(t, nc, c, cc): nc = cc + ((((c - cc) * t) + (((c - cc) * t) >> 8) + 0x80) >> 8)
        $dr = self::blendColorChannel($t, $r, $dr);
        $dg = self::blendColorChannel($t, $g, $dg);
        $db = self::blendColorChannel($t, $b, $db);
        // gdAlphaOpaque = 0
        $state->pixels[$index] = (($dr & 0xFF) << 16) | (($dg & 0xFF) << 8) | ($db & 0xFF);
    }

    private static function blendColorChannel(int $t, int $c, int $cc): int
    {
        return $cc + ((((($c - $cc) * $t) + ((($c - $cc) * $t) >> 8) + 0x80) >> 8));
    }

    /**
     * imagearc() — outline arc (php-src gdImageArc → gdImageFilledArc + gdNoFill; #20437).
     */
    public static function arc(
        ObjectEntry $image,
        int $cx,
        int $cy,
        int $w,
        int $h,
        int $s,
        int $e,
        int $color
    ): bool {
        return self::filledArc($image, $cx, $cy, $w, $h, $s, $e, $color, GdConstants::ARC_NOFILL);
    }

    /**
     * imagefilledarc() — libgd gdImageFilledArc (php-src ext/gd/libgd/gd.c; #20437).
     *
     * Angles are degrees, 0 at +x, increasing clockwise (libgd convention).
     */
    public static function filledArc(
        ObjectEntry $image,
        int $cx,
        int $cy,
        int $w,
        int $h,
        int $s,
        int $e,
        int $color,
        int $style
    ): bool {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return false;
        }

        // php-src PHP_FUNCTION(imagearc/imagefilledarc): negative angles %= 360 before libgd.
        if ($e < 0) {
            $e %= 360;
        }
        if ($s < 0) {
            $s %= 360;
        }

        // Mirror C gdPoint pts[363] with dense indices 0..pti (libgd gdImageFilledArc).
        /** @var array<int, array{0: int, 1: int}> $pts */
        $pts = [];
        $lx = 0;
        $ly = 0;
        $fx = 0;
        $fy = 0;
        $startx = -1;
        $starty = -1;
        $endx = -1;
        $endy = -1;

        if (($s % 360) === ($e % 360)) {
            $s = 0;
            $e = 360;
        } else {
            if ($s > 360) {
                $s %= 360;
            }
            if ($e > 360) {
                $e %= 360;
            }
            while ($s < 0) {
                $s += 360;
            }
            while ($e < $s) {
                $e += 360;
            }
            if ($s === $e) {
                $s = 0;
                $e = 360;
            }
        }

        $pti = 1;
        for ($i = $s; $i <= $e; ++$i, ++$pti) {
            $deg = $i % 360;
            if ($deg < 0) {
                $deg += 360;
            }
            $x = $endx = intdiv(GdTrigTables::COS[$deg] * $w, 2 * 1024) + $cx;
            $y = $endy = intdiv(GdTrigTables::SIN[$deg] * $h, 2 * 1024) + $cy;
            if ($i !== $s) {
                if (0 === ($style & GdConstants::ARC_CHORD)) {
                    if (0 !== ($style & GdConstants::ARC_NOFILL)) {
                        self::line($image, $lx, $ly, $x, $y, $color);
                    } else {
                        if ($y === $ly) {
                            --$pti; // don't add this point
                            if ((($i > 270 || $i < 90) && $x > $lx) || (($i > 90 && $i < 270) && $x < $lx)) {
                                // replace the old x coord
                                $pts[$pti][0] = $x;
                            }
                        } else {
                            $pts[$pti] = [$x, $y];
                        }
                    }
                }
            } else {
                $fx = $x;
                $fy = $y;
                if (0 === ($style & (GdConstants::ARC_CHORD | GdConstants::ARC_NOFILL))) {
                    $pts[0] = [$cx, $cy];
                    $pts[$pti] = [$x, $y];
                    $startx = $x;
                    $starty = $y;
                }
            }
            $lx = $x;
            $ly = $y;
        }

        if (0 !== ($style & GdConstants::ARC_CHORD)) {
            if (0 !== ($style & GdConstants::ARC_NOFILL)) {
                if (0 !== ($style & GdConstants::ARC_EDGED)) {
                    self::line($image, $cx, $cy, $lx, $ly, $color);
                    self::line($image, $cx, $cy, $fx, $fy, $color);
                }
                self::line($image, $fx, $fy, $lx, $ly, $color);
            } else {
                self::filledPolygon($image, [[$fx, $fy], [$lx, $ly], [$cx, $cy]], $color);
            }
        } else {
            if (0 !== ($style & GdConstants::ARC_NOFILL)) {
                if (0 !== ($style & GdConstants::ARC_EDGED)) {
                    self::line($image, $cx, $cy, $lx, $ly, $color);
                    self::line($image, $cx, $cy, $fx, $fy, $color);
                }
            } else {
                if (($e - $s) < 360) {
                    if ($pts[1][0] !== $startx && $pts[1][1] === $starty) {
                        for ($j = $pti; $j > 1; --$j) {
                            $pts[$j] = $pts[$j - 1];
                        }
                        $pts[1] = [$startx, $starty];
                        ++$pti;
                    }
                    if ($pts[$pti - 1][0] !== $endx && $pts[$pti - 1][1] === $endy) {
                        $pts[$pti] = [$endx, $endy];
                        ++$pti;
                    }
                }
                $pts[$pti] = [$cx, $cy];
                $packed = [];
                for ($j = 0; $j <= $pti; ++$j) {
                    $packed[] = $pts[$j];
                }
                self::filledPolygon($image, $packed, $color);
            }
        }

        return true;
    }

    /**
     * libgd gdImageFilledPolygon scanline fill (php-src ext/gd/libgd/gd.c; #20437, #20448).
     *
     * Shared by imagefilledpolygon() and filled-arc pie wedges.
     *
     * @param list<array{0: int, 1: int}> $points
     */
    public static function filledPolygon(ObjectEntry $image, array $points, int $color): bool
    {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return false;
        }
        $n = \count($points);
        if ($n <= 0) {
            return true;
        }

        $miny = $points[0][1];
        $maxy = $points[0][1];
        for ($i = 1; $i < $n; ++$i) {
            $y = $points[$i][1];
            if ($y < $miny) {
                $miny = $y;
            }
            if ($y > $maxy) {
                $maxy = $y;
            }
        }
        if ($n > 1 && $miny === $maxy) {
            $x1 = $x2 = $points[0][0];
            for ($i = 1; $i < $n; ++$i) {
                $x = $points[$i][0];
                if ($x < $x1) {
                    $x1 = $x;
                } elseif ($x > $x2) {
                    $x2 = $x;
                }
            }
            self::line($image, $x1, $miny, $x2, $miny, $color);

            return true;
        }
        $pmaxy = $maxy;
        if ($miny < 0) {
            $miny = 0;
        }
        if ($maxy >= $state->height) {
            $maxy = $state->height - 1;
        }

        for ($y = $miny; $y <= $maxy; ++$y) {
            $ints = [];
            for ($i = 0; $i < $n; ++$i) {
                if (0 === $i) {
                    $ind1 = $n - 1;
                    $ind2 = 0;
                } else {
                    $ind1 = $i - 1;
                    $ind2 = $i;
                }
                $y1 = $points[$ind1][1];
                $y2 = $points[$ind2][1];
                if ($y1 < $y2) {
                    $x1 = $points[$ind1][0];
                    $x2 = $points[$ind2][0];
                } elseif ($y1 > $y2) {
                    $y2 = $points[$ind1][1];
                    $y1 = $points[$ind2][1];
                    $x2 = $points[$ind1][0];
                    $x1 = $points[$ind2][0];
                } else {
                    continue;
                }
                if ($y >= $y1 && $y < $y2) {
                    $ints[] = (int) (((float) (($y - $y1) * ($x2 - $x1)) / (float) ($y2 - $y1)) + 0.5 + $x1);
                } elseif ($y === $pmaxy && $y === $y2) {
                    $ints[] = $x2;
                }
            }
            sort($ints, SORT_NUMERIC);
            $count = \count($ints);
            for ($i = 0; $i < $count - 1; $i += 2) {
                self::line($image, $ints[$i], $y, $ints[$i + 1], $y, $color);
            }
        }

        return true;
    }

    /**
     * imageellipse() — mid-point stroke ellipse (php-src gdImageEllipse; #20438).
     */
    public static function ellipse(ObjectEntry $image, int $mx, int $my, int $w, int $h, int $color): bool
    {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return false;
        }
        $a = $w >> 1;
        $b = $h >> 1;
        // Skip overflowMul3 — PHP int is wide enough for canvas sizes we support.
        self::putPixel($state, $mx + $a, $my, $color);
        self::putPixel($state, $mx - $a, $my, $color);
        $mx1 = $mx - $a;
        $my1 = $my;
        $mx2 = $mx + $a;
        $my2 = $my;
        $aq = $a * $a;
        $bq = $b * $b;
        $dx = $aq << 1;
        $dy = $bq << 1;
        $r = $a * $bq;
        $rx = $r << 1;
        $ry = 0;
        $x = $a;
        while ($x > 0) {
            if ($r > 0) {
                ++$my1;
                --$my2;
                $ry += $dx;
                $r -= $ry;
            }
            if ($r <= 0) {
                --$x;
                ++$mx1;
                --$mx2;
                $rx -= $dy;
                $r += $rx;
            }
            self::putPixel($state, $mx1, $my1, $color);
            self::putPixel($state, $mx1, $my2, $color);
            self::putPixel($state, $mx2, $my1, $color);
            self::putPixel($state, $mx2, $my2, $color);
        }

        return true;
    }

    /**
     * imagefilledellipse() — mid-point filled ellipse (php-src gdImageFilledEllipse; #20438).
     */
    public static function filledEllipse(ObjectEntry $image, int $mx, int $my, int $w, int $h, int $color): bool
    {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return false;
        }
        $a = $w >> 1;
        $b = $h >> 1;
        for ($x = $mx - $a; $x <= $mx + $a; ++$x) {
            self::putPixel($state, $x, $my, $color);
        }
        $mx1 = $mx - $a;
        $my1 = $my;
        $mx2 = $mx + $a;
        $my2 = $my;
        $aq = $a * $a;
        $bq = $b * $b;
        $dx = $aq << 1;
        $dy = $bq << 1;
        $r = $a * $bq;
        $rx = $r << 1;
        $ry = 0;
        $x = $a;
        $oldY2 = -2;
        while ($x > 0) {
            if ($r > 0) {
                ++$my1;
                --$my2;
                $ry += $dx;
                $r -= $ry;
            }
            if ($r <= 0) {
                --$x;
                ++$mx1;
                --$mx2;
                $rx -= $dy;
                $r += $rx;
            }
            if ($oldY2 !== $my2) {
                for ($i = $mx1; $i <= $mx2; ++$i) {
                    self::putPixel($state, $i, $my2, $color);
                    self::putPixel($state, $i, $my1, $color);
                }
            }
            $oldY2 = $my2;
        }

        return true;
    }

    /**
     * imagerectangle() — outline rect (php-src ext/gd/libgd/gd.c gdImageRectangle; #20457).
     */
    public static function rectangle(ObjectEntry $image, int $x1, int $y1, int $x2, int $y2, int $color): bool
    {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return false;
        }
        $thick = $state->thick;
        if ($x1 === $x2 && $y1 === $y2 && 1 === $thick) {
            self::putPixel($state, $x1, $y1, $color);

            return true;
        }
        if ($y2 < $y1) {
            $t = $y1;
            $y1 = $y2;
            $y2 = $t;
        }
        if ($x2 < $x1) {
            $t = $x1;
            $x1 = $x2;
            $x2 = $t;
        }
        if ($thick > 1) {
            $half = $thick >> 1;
            $x1ul = $x1 - $half;
            $y1ul = $y1 - $half;
            $x2lr = $x2 + $half;
            $y2lr = $y2 + $half;
            $cy = $y1ul + $thick;
            while ($cy-- > $y1ul) {
                $cx = $x1ul - 1;
                while ($cx++ < $x2lr) {
                    self::putPixel($state, $cx, $cy, $color);
                }
            }
            $cy = $y2lr - $thick;
            while ($cy++ < $y2lr) {
                $cx = $x1ul - 1;
                while ($cx++ < $x2lr) {
                    self::putPixel($state, $cx, $cy, $color);
                }
            }
            $cy = $y1ul + $thick - 1;
            while ($cy++ < $y2lr - $thick) {
                $cx = $x1ul - 1;
                while ($cx++ < $x1ul + $thick) {
                    self::putPixel($state, $cx, $cy, $color);
                }
            }
            $cy = $y1ul + $thick - 1;
            while ($cy++ < $y2lr - $thick) {
                $cx = $x2lr - $thick - 1;
                while ($cx++ < $x2lr) {
                    self::putPixel($state, $cx, $cy, $color);
                }
            }

            return true;
        }
        if ($x1 === $x2 || $y1 === $y2) {
            self::line($image, $x1, $y1, $x2, $y2, $color);
        } else {
            self::line($image, $x1, $y1, $x2, $y1, $color);
            self::line($image, $x1, $y2, $x2, $y2, $color);
            self::line($image, $x1, $y1 + 1, $x1, $y2 - 1, $color);
            self::line($image, $x2, $y1 + 1, $x2, $y2 - 1, $color);
        }

        return true;
    }

    /**
     * imagedashedline() — Bresenham dashed stroke (php-src gdImageDashedLine; #20457).
     *
     * Dash period is libgd gdDashSize (4). Thickness wid uses sin(atan2) for both
     * branches (libgd quirk; matches php-src ext/gd/libgd/gd.c).
     */
    public static function dashedLine(ObjectEntry $image, int $x1, int $y1, int $x2, int $y2, int $color): bool
    {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return false;
        }
        $dashStep = 0;
        $on = true;
        $thick = $state->thick;
        $dx = \abs($x2 - $x1);
        $dy = \abs($y2 - $y1);
        if ($dy <= $dx) {
            $as = \sin(\atan2($dy, $dx));
            $wid = (0.0 !== $as) ? (int) ($thick / $as) : 1;
            $vert = true;
            $d = 2 * $dy - $dx;
            $incr1 = 2 * $dy;
            $incr2 = 2 * ($dy - $dx);
            if ($x1 > $x2) {
                $x = $x2;
                $y = $y2;
                $ydirflag = -1;
                $xend = $x1;
            } else {
                $x = $x1;
                $y = $y1;
                $ydirflag = 1;
                $xend = $x2;
            }
            self::dashedSet($state, $x, $y, $color, $on, $dashStep, $wid, $vert);
            if ((($y2 - $y1) * $ydirflag) > 0) {
                while ($x < $xend) {
                    ++$x;
                    if ($d < 0) {
                        $d += $incr1;
                    } else {
                        ++$y;
                        $d += $incr2;
                    }
                    self::dashedSet($state, $x, $y, $color, $on, $dashStep, $wid, $vert);
                }
            } else {
                while ($x < $xend) {
                    ++$x;
                    if ($d < 0) {
                        $d += $incr1;
                    } else {
                        --$y;
                        $d += $incr2;
                    }
                    self::dashedSet($state, $x, $y, $color, $on, $dashStep, $wid, $vert);
                }
            }
        } else {
            $as = \sin(\atan2($dy, $dx));
            $wid = (0.0 !== $as) ? (int) ($thick / $as) : 1;
            $vert = false;
            $d = 2 * $dx - $dy;
            $incr1 = 2 * $dx;
            $incr2 = 2 * ($dx - $dy);
            if ($y1 > $y2) {
                $y = $y2;
                $x = $x2;
                $yend = $y1;
                $xdirflag = -1;
            } else {
                $y = $y1;
                $x = $x1;
                $yend = $y2;
                $xdirflag = 1;
            }
            self::dashedSet($state, $x, $y, $color, $on, $dashStep, $wid, $vert);
            if ((($x2 - $x1) * $xdirflag) > 0) {
                while ($y < $yend) {
                    ++$y;
                    if ($d < 0) {
                        $d += $incr1;
                    } else {
                        ++$x;
                        $d += $incr2;
                    }
                    self::dashedSet($state, $x, $y, $color, $on, $dashStep, $wid, $vert);
                }
            } else {
                while ($y < $yend) {
                    ++$y;
                    if ($d < 0) {
                        $d += $incr1;
                    } else {
                        --$x;
                        $d += $incr2;
                    }
                    self::dashedSet($state, $x, $y, $color, $on, $dashStep, $wid, $vert);
                }
            }
        }

        return true;
    }

    /**
     * libgd dashedSet helper — gdDashSize=4 (php-src ext/gd/libgd/gd.h; #20457).
     */
    private static function dashedSet(
        GdImageState $state,
        int $x,
        int $y,
        int $color,
        bool &$on,
        int &$dashStep,
        int $wid,
        bool $vert
    ): void {
        ++$dashStep;
        if (4 === $dashStep) {
            $dashStep = 0;
            $on = !$on;
        }
        if (!$on) {
            return;
        }
        if ($vert) {
            $wstart = $y - intdiv($wid, 2);
            for ($w = $wstart; $w < $wstart + $wid; ++$w) {
                self::putPixel($state, $x, $w, $color);
            }
        } else {
            $wstart = $x - intdiv($wid, 2);
            for ($w = $wstart; $w < $wstart + $wid; ++$w) {
                self::putPixel($state, $w, $y, $color);
            }
        }
    }

    /**
     * imagefilledrectangle() — clipped fill (php-src _gdImageFilledVRectangle; #6534).
     */
    public static function filledRectangle(ObjectEntry $image, int $x1, int $y1, int $x2, int $y2, int $color): bool
    {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return false;
        }
        if ($x1 === $x2 && $y1 === $y2) {
            self::putPixel($state, $x1, $y1, $color);

            return true;
        }
        if ($x1 > $x2) {
            $t = $x1;
            $x1 = $x2;
            $x2 = $t;
        }
        if ($y1 > $y2) {
            $t = $y1;
            $y1 = $y2;
            $y2 = $t;
        }
        if ($x1 < 0) {
            $x1 = 0;
        }
        if ($x2 >= $state->width) {
            $x2 = $state->width - 1;
        }
        if ($y1 < 0) {
            $y1 = 0;
        }
        if ($y2 >= $state->height) {
            $y2 = $state->height - 1;
        }
        if ($x1 > $x2 || $y1 > $y2) {
            return true;
        }
        $width = $state->width;
        for ($y = $y1; $y <= $y2; ++$y) {
            for ($x = $x1; $x <= $x2; ++$x) {
                self::putPixel($state, $x, $y, $color);
            }
        }

        return true;
    }

    /**
     * imagechar() — single glyph from built-in / loaded font (php-src gdImageChar; #6534, #20486).
     *
     * @param array{nchars:int,offset:int,w:int,h:int,data:string} $fontData
     */
    public static function char(ObjectEntry $image, array $fontData, int $x, int $y, string $char, int $color): bool
    {
        $ch = '' === $char ? 0 : \ord($char[0]);

        return self::drawChar(GdRegistry::state($image), $fontData, $x, $y, $ch, $color);
    }

    /**
     * imagestring() — horizontal string from built-in / loaded font (php-src gdImageString; #6534, #20486).
     *
     * @param array{nchars:int,offset:int,w:int,h:int,data:string} $fontData
     */
    public static function string(ObjectEntry $image, array $fontData, int $x, int $y, string $text, int $color): bool
    {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return false;
        }
        $len = \strlen($text);
        for ($i = 0; $i < $len; ++$i) {
            self::drawChar($state, $fontData, $x, $y, \ord($text[$i]), $color);
            $x += $fontData['w'];
        }

        return true;
    }

    /**
     * imagecharup() — 90° CCW built-in / loaded font glyph (php-src gdImageCharUp; #20460, #20486).
     *
     * @param array{nchars:int,offset:int,w:int,h:int,data:string} $fontData
     */
    public static function charUp(ObjectEntry $image, array $fontData, int $x, int $y, string $char, int $color): bool
    {
        $ch = '' === $char ? 0 : \ord($char[0]);

        return self::drawCharUp(GdRegistry::state($image), $fontData, $x, $y, $ch, $color);
    }

    /**
     * imagestringup() — vertical string via CharUp (php-src gdImageStringUp; #20460, #20486).
     *
     * @param array{nchars:int,offset:int,w:int,h:int,data:string} $fontData
     */
    public static function stringUp(ObjectEntry $image, array $fontData, int $x, int $y, string $text, int $color): bool
    {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return false;
        }
        $len = \strlen($text);
        for ($i = 0; $i < $len; ++$i) {
            self::drawCharUp($state, $fontData, $x, $y, \ord($text[$i]), $color);
            $y -= $fontData['w'];
        }

        return true;
    }

    /**
     * imagegammacorrect() — pow gamma remap (php-src ext/gd/gd.c; #20460).
     */
    public static function gammaCorrect(ObjectEntry $image, float $inputgamma, float $outputgamma): bool
    {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return false;
        }
        if ($inputgamma <= 0.0 || !is_finite($inputgamma)) {
            throw new \ValueError(\sprintf(
                'imagegammacorrect(): Argument #2 ($inputgamma) must be %s',
                $inputgamma <= 0.0 ? 'greater than 0' : 'finite'
            ));
        }
        if ($outputgamma <= 0.0 || !is_finite($outputgamma)) {
            throw new \ValueError(\sprintf(
                'imagegammacorrect(): Argument #3 ($outputgamma) must be %s',
                $outputgamma <= 0.0 ? 'greater than 0' : 'finite'
            ));
        }
        $gamma = $inputgamma / $outputgamma;
        if ($state->truecolor) {
            $n = \count($state->pixels);
            for ($i = 0; $i < $n; ++$i) {
                $c = $state->pixels[$i];
                $r = (int) ((pow((($c >> 16) & 0xFF) / 255.0, $gamma) * 255) + 0.5);
                $g = (int) ((pow((($c >> 8) & 0xFF) / 255.0, $gamma) * 255) + 0.5);
                $b = (int) ((pow(($c & 0xFF) / 255.0, $gamma) * 255) + 0.5);
                $a = ($c >> 24) & 0x7F;
                $state->pixels[$i] = (($a & 0x7F) << 24) | (($r & 0xFF) << 16) | (($g & 0xFF) << 8) | ($b & 0xFF);
            }
        } else {
            foreach ($state->colors as $i => $packed) {
                $r = (int) ((pow((($packed >> 16) & 0xFF) / 255.0, $gamma) * 255) + 0.5);
                $g = (int) ((pow((($packed >> 8) & 0xFF) / 255.0, $gamma) * 255) + 0.5);
                $b = (int) ((pow(($packed & 0xFF) / 255.0, $gamma) * 255) + 0.5);
                $a = ($packed >> 24) & 0x7F;
                $state->colors[$i] = (($a & 0x7F) << 24) | (($r & 0xFF) << 16) | (($g & 0xFF) << 8) | ($b & 0xFF);
            }
        }

        return true;
    }

    /**
     * imageinterlace() get/set (php-src; returns current interlace bool; #20460).
     */
    public static function interlace(ObjectEntry $image, ?bool $enable): bool
    {
        $state = GdRegistry::state($image);
        if (null === $state) {
            return false;
        }
        if (null !== $enable) {
            $state->interlace = $enable;
        }

        return $state->interlace;
    }

    /**
     * imagesetclip() — set inclusive clip rect (php-src gdImageSetClip; #20460).
     */
    public static function setClip(ObjectEntry $image, int $x1, int $y1, int $x2, int $y2): bool
    {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return false;
        }
        $sx = $state->width;
        $sy = $state->height;
        if ($x1 < 0) {
            $x1 = 0;
        }
        if ($x1 >= $sx) {
            $x1 = $sx - 1;
        }
        if ($x2 < 0) {
            $x2 = 0;
        }
        if ($x2 >= $sx) {
            $x2 = $sx - 1;
        }
        if ($y1 < 0) {
            $y1 = 0;
        }
        if ($y1 >= $sy) {
            $y1 = $sy - 1;
        }
        if ($y2 < 0) {
            $y2 = 0;
        }
        if ($y2 >= $sy) {
            $y2 = $sy - 1;
        }
        $state->cx1 = $x1;
        $state->cy1 = $y1;
        $state->cx2 = $x2;
        $state->cy2 = $y2;

        return true;
    }

    /**
     * imagegetclip() — [x1,y1,x2,y2] (php-src; #20460).
     *
     * @return list<int>
     */
    public static function getClip(ObjectEntry $image): array
    {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return [0, 0, 0, 0];
        }

        return [$state->cx1, $state->cy1, $state->cx2, $state->cy2];
    }

    public static function getClipToHashTable(ObjectEntry $image): HashTable
    {
        return self::brectToHashTable(self::getClip($image));
    }

    public static function copy(
        ObjectEntry $dst,
        ObjectEntry $src,
        int $dstX,
        int $dstY,
        int $srcX,
        int $srcY,
        int $srcW,
        int $srcH
    ): bool {
        $dstState = GdRegistry::state($dst);
        $srcState = GdRegistry::state($src);
        if (null === $dstState || null === $srcState
            || !$dstState->hasRaster() || !$srcState->hasRaster()) {
            return false;
        }
        if ($srcW <= 0 || $srcH <= 0) {
            return false;
        }

        $dstPixels = $dstState->pixels;
        $srcPixels = $srcState->pixels;
        $dstWidth = $dstState->width;
        $srcWidth = $srcState->width;

        for ($row = 0; $row < $srcH; ++$row) {
            $sy = $srcY + $row;
            $dy = $dstY + $row;
            if ($sy < 0 || $sy >= $srcState->height || $dy < 0 || $dy >= $dstState->height) {
                continue;
            }
            for ($col = 0; $col < $srcW; ++$col) {
                $sx = $srcX + $col;
                $dx = $dstX + $col;
                if ($sx < 0 || $sx >= $srcWidth || $dx < 0 || $dx >= $dstWidth) {
                    continue;
                }
                $dstPixels[$dy * $dstWidth + $dx] = $srcPixels[$sy * $srcWidth + $sx];
            }
        }

        $dstState->pixels = $dstPixels;

        return true;
    }

    public static function copyMerge(
        ObjectEntry $dst,
        ObjectEntry $src,
        int $dstX,
        int $dstY,
        int $srcX,
        int $srcY,
        int $srcW,
        int $srcH,
        int $pct
    ): bool {
        $dstState = GdRegistry::state($dst);
        $srcState = GdRegistry::state($src);
        if (null === $dstState || null === $srcState
            || !$dstState->hasRaster() || !$srcState->hasRaster()) {
            return false;
        }
        if ($srcW <= 0 || $srcH <= 0) {
            return false;
        }
        if ($pct < 0) {
            $pct = 0;
        }
        if ($pct > 100) {
            $pct = 100;
        }
        if (0 === $pct) {
            return true;
        }
        if (100 === $pct) {
            return self::copy($dst, $src, $dstX, $dstY, $srcX, $srcY, $srcW, $srcH);
        }

        $dstPixels = $dstState->pixels;
        $srcPixels = $srcState->pixels;
        $dstWidth = $dstState->width;
        $srcWidth = $srcState->width;
        $invPct = 100 - $pct;

        for ($row = 0; $row < $srcH; ++$row) {
            $sy = $srcY + $row;
            $dy = $dstY + $row;
            if ($sy < 0 || $sy >= $srcState->height || $dy < 0 || $dy >= $dstState->height) {
                continue;
            }
            for ($col = 0; $col < $srcW; ++$col) {
                $sx = $srcX + $col;
                $dx = $dstX + $col;
                if ($sx < 0 || $sx >= $srcWidth || $dx < 0 || $dx >= $dstWidth) {
                    continue;
                }
                $dstColor = $dstPixels[$dy * $dstWidth + $dx];
                $srcColor = $srcPixels[$sy * $srcWidth + $sx];
                $dstPixels[$dy * $dstWidth + $dx] = self::blendRgb($dstColor, $srcColor, $pct, $invPct);
            }
        }

        $dstState->pixels = $dstPixels;

        return true;
    }

    public static function copyResampled(
        ObjectEntry $dst,
        ObjectEntry $src,
        int $dstX,
        int $dstY,
        int $srcX,
        int $srcY,
        int $dstW,
        int $dstH,
        int $srcW,
        int $srcH
    ): bool {
        $dstState = GdRegistry::state($dst);
        $srcState = GdRegistry::state($src);
        if (null === $dstState || null === $srcState
            || !$dstState->hasRaster() || !$srcState->hasRaster()) {
            return false;
        }
        if ($dstW <= 0 || $dstH <= 0 || $srcW <= 0 || $srcH <= 0) {
            return false;
        }

        $dstPixels = $dstState->pixels;
        $dstWidth = $dstState->width;
        $srcWidth = $srcState->width;
        $srcHeight = $srcState->height;
        $srcPixels = $srcState->pixels;

        for ($row = 0; $row < $dstH; ++$row) {
            $dy = $dstY + $row;
            if ($dy < 0 || $dy >= $dstState->height) {
                continue;
            }
            $srcFy = $srcY + ($row + 0.5) * $srcH / $dstH - 0.5;
            for ($col = 0; $col < $dstW; ++$col) {
                $dx = $dstX + $col;
                if ($dx < 0 || $dx >= $dstWidth) {
                    continue;
                }
                $srcFx = $srcX + ($col + 0.5) * $srcW / $dstW - 0.5;
                $dstPixels[$dy * $dstWidth + $dx] = self::sampleBilinear(
                    $srcPixels,
                    $srcWidth,
                    $srcHeight,
                    $srcFx,
                    $srcFy
                );
            }
        }

        $dstState->pixels = $dstPixels;

        return true;
    }

    /**
     * imagecopyresized() — nearest-neighbour scale blit (php-src gdImageCopyResized; #20405).
     */
    public static function copyResized(
        ObjectEntry $dst,
        ObjectEntry $src,
        int $dstX,
        int $dstY,
        int $srcX,
        int $srcY,
        int $dstW,
        int $dstH,
        int $srcW,
        int $srcH
    ): bool {
        $dstState = GdRegistry::state($dst);
        $srcState = GdRegistry::state($src);
        if (null === $dstState || null === $srcState
            || !$dstState->hasRaster() || !$srcState->hasRaster()) {
            return false;
        }
        if ($dstW <= 0 || $dstH <= 0 || $srcW <= 0 || $srcH <= 0) {
            return false;
        }

        $dstPixels = $dstState->pixels;
        $dstWidth = $dstState->width;
        $srcWidth = $srcState->width;
        $srcHeight = $srcState->height;
        $srcPixels = $srcState->pixels;

        for ($row = 0; $row < $dstH; ++$row) {
            $dy = $dstY + $row;
            if ($dy < 0 || $dy >= $dstState->height) {
                continue;
            }
            $sy = $srcY + (int) (($row * $srcH) / $dstH);
            if ($sy < 0 || $sy >= $srcHeight) {
                continue;
            }
            for ($col = 0; $col < $dstW; ++$col) {
                $dx = $dstX + $col;
                if ($dx < 0 || $dx >= $dstWidth) {
                    continue;
                }
                $sx = $srcX + (int) (($col * $srcW) / $dstW);
                if ($sx < 0 || $sx >= $srcWidth) {
                    continue;
                }
                $dstPixels[$dy * $dstWidth + $dx] = $srcPixels[$sy * $srcWidth + $sx];
            }
        }

        $dstState->pixels = $dstPixels;

        return true;
    }

    /**
     * imagerotate() — counterclockwise degrees; 90° steps exact (php-src gdImageRotateInterpolated; #20405).
     */
    public static function rotate(Frame $frame, ObjectEntry $image, float $angle, int $bgColor): ObjectEntry|false
    {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return false;
        }

        $normalized = fmod($angle, 360.0);
        if ($normalized < 0.0) {
            $normalized += 360.0;
        }
        $quarter = (int) round($normalized / 90.0);
        if (abs($normalized - (90.0 * $quarter)) < 0.0001) {
            $quarter %= 4;
            if (0 === $quarter) {
                return self::createTruecolorFromPixels($frame, $state->width, $state->height, $state->pixels);
            }
            if (1 === $quarter) {
                return self::rotate90CounterClockwise($frame, $state);
            }
            if (2 === $quarter) {
                return self::rotate180($frame, $state);
            }

            return self::rotate270CounterClockwise($frame, $state);
        }

        return self::rotateArbitrary($frame, $state, $normalized, $bgColor);
    }

    /**
     * imagescale() — new raster sized to $width×$height (php-src gdImageScale; #20405).
     */
    public static function scale(Frame $frame, ObjectEntry $image, int $width, int $height, int $mode): ObjectEntry|false
    {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return false;
        }
        if ($mode < GdConstants::REGISTERED['IMG_DEFAULT'] || $mode >= GdConstants::INTERPOLATION_METHOD_COUNT) {
            throw new \ValueError('imagescale(): Argument #4 ($mode) must be one of the GD_* constants');
        }
        if ($height < 0 && $width < 0) {
            throw new \ValueError('imagescale(): Argument #2 ($width) and argument #3 ($height) cannot be both negative');
        }
        $srcW = $state->width;
        $srcH = $state->height;
        if ($height < 0) {
            if ($srcW <= 0) {
                return false;
            }
            $height = (int) (($width * $srcH) / $srcW);
        }
        if ($width < 0) {
            if ($srcH <= 0) {
                return false;
            }
            $width = (int) (($height * $srcW) / $srcH);
        }
        if ($width <= 0 || $height <= 0) {
            return false;
        }

        $dst = self::createTruecolorImage($frame, $width, $height);
        if (false === $dst) {
            return false;
        }
        $nearest = GdConstants::REGISTERED['IMG_NEAREST_NEIGHBOUR'] === $mode;
        if ($nearest) {
            self::copyResized($dst, $image, 0, 0, 0, 0, $width, $height, $srcW, $srcH);
        } else {
            self::copyResampled($dst, $image, 0, 0, 0, 0, $width, $height, $srcW, $srcH);
        }

        return $dst;
    }

    /**
     * imageaffine() — gdTransformAffineGetImage (php-src ext/gd/gd.c / libgd; #20404).
     *
     * @param list<float> $affine 6-element matrix [a,b,c,d,e,f]: x'=a*x+c*y+e, y'=b*x+d*y+f
     * @param array{x: int, y: int, width: int, height: int}|null $clip
     */
    public static function affine(
        Frame $frame,
        ObjectEntry $image,
        array $affine,
        ?array $clip
    ): ObjectEntry|false {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return false;
        }
        if (!$state->truecolor) {
            if (!self::paletteToTrueColor($image)) {
                return false;
            }
            $state = GdRegistry::state($image);
            if (null === $state || !$state->hasRaster()) {
                return false;
            }
        }

        $srcX = 0;
        $srcY = 0;
        $srcW = $state->width;
        $srcH = $state->height;
        if (null !== $clip) {
            $srcX = $clip['x'];
            $srcY = $clip['y'];
            $srcW = $clip['width'];
            $srcH = $clip['height'];
            if ($srcW <= 0 || $srcH <= 0) {
                return false;
            }
            if ($srcX < 0 || $srcY < 0
                || $srcX + $srcW > $state->width
                || $srcY + $srcH > $state->height) {
                return false;
            }
        }

        $bbox = self::affineBoundingBox($srcW, $srcH, $affine);
        if (null === $bbox) {
            return false;
        }
        [$bboxX, $bboxY, $dstW, $dstH] = $bbox;
        if ($dstW <= 0 || $dstH <= 0) {
            return false;
        }

        // Translate so bbox origin lands at (0,0) — gdAffineTranslate(-bbox) ∘ affine.
        $m = self::affineConcat($affine, [1.0, 0.0, 0.0, 1.0, (float) (-$bboxX), (float) (-$bboxY)]);
        $inv = self::affineInvert($m);
        if (null === $inv) {
            return false;
        }

        $srcPixels = $state->pixels;
        $srcWidth = $state->width;
        $srcHeight = $state->height;
        $nearest = GdConstants::REGISTERED['IMG_NEAREST_NEIGHBOUR'] === $state->interpolationId;
        $dstPixels = array_fill(0, $dstW * $dstH, 0);

        for ($y = 0; $y < $dstH; ++$y) {
            for ($x = 0; $x < $dstW; ++$x) {
                $ptX = $x + 0.5;
                $ptY = $y + 0.5;
                $srcPtX = $ptX * $inv[0] + $ptY * $inv[2] + $inv[4];
                $srcPtY = $ptX * $inv[1] + $ptY * $inv[3] + $inv[5];
                $sx = $srcX + $srcPtX;
                $sy = $srcY + $srcPtY;
                if ($nearest) {
                    $ix = (int) floor($sx);
                    $iy = (int) floor($sy);
                    if ($ix < $srcX || $iy < $srcY
                        || $ix >= $srcX + $srcW || $iy >= $srcY + $srcH
                        || $ix < 0 || $iy < 0 || $ix >= $srcWidth || $iy >= $srcHeight) {
                        $dstPixels[$y * $dstW + $x] = 0;

                        continue;
                    }
                    $dstPixels[$y * $dstW + $x] = $srcPixels[$iy * $srcWidth + $ix];
                } else {
                    if ($sx < $srcX || $sy < $srcY
                        || $sx >= $srcX + $srcW || $sy >= $srcY + $srcH) {
                        $dstPixels[$y * $dstW + $x] = 0;

                        continue;
                    }
                    $dstPixels[$y * $dstW + $x] = self::sampleBilinear(
                        $srcPixels,
                        $srcWidth,
                        $srcHeight,
                        $sx,
                        $sy
                    );
                }
            }
        }

        $out = self::createTruecolorFromPixels($frame, $dstW, $dstH, $dstPixels);
        if (false === $out) {
            return false;
        }
        $outState = GdRegistry::state($out);
        if (null !== $outState) {
            $outState->interpolationId = $state->interpolationId;
            $outState->saveAlpha = true;
            $outState->alphaBlending = GdConstants::REGISTERED['IMG_EFFECT_REPLACE'];
        }

        return $out;
    }

    /**
     * @param list<float> $affine
     * @return array{0: int, 1: int, 2: int, 3: int}|null bbox x,y,width,height
     */
    private static function affineBoundingBox(int $width, int $height, array $affine): ?array
    {
        $corners = [
            [0.0, 0.0],
            [(float) $width, 0.0],
            [(float) $width, (float) $height],
            [0.0, (float) $height],
        ];
        $minX = INF;
        $minY = INF;
        $maxX = -INF;
        $maxY = -INF;
        foreach ($corners as [$cx, $cy]) {
            $x = $cx * $affine[0] + $cy * $affine[2] + $affine[4];
            $y = $cx * $affine[1] + $cy * $affine[3] + $affine[5];
            if (!is_finite($x) || !is_finite($y)) {
                return null;
            }
            if ($x < $minX) {
                $minX = $x;
            }
            if ($y < $minY) {
                $minY = $y;
            }
            if ($x > $maxX) {
                $maxX = $x;
            }
            if ($y > $maxY) {
                $maxY = $y;
            }
        }
        $dstW = (int) floor($maxX - $minX);
        $dstH = (int) floor($maxY - $minY);
        // Match php-src identity “same dimensions” (libgd bbox_width uses width-1 + inclusive loops).
        if ($dstW < 1) {
            $dstW = 1;
        }
        if ($dstH < 1) {
            $dstH = 1;
        }

        return [(int) $minX, (int) $minY, $dstW, $dstH];
    }

    /**
     * @param list<float> $m1
     * @param list<float> $m2
     * @return list<float>
     */
    private static function affineConcat(array $m1, array $m2): array
    {
        return [
            $m1[0] * $m2[0] + $m1[1] * $m2[2],
            $m1[0] * $m2[1] + $m1[1] * $m2[3],
            $m1[2] * $m2[0] + $m1[3] * $m2[2],
            $m1[2] * $m2[1] + $m1[3] * $m2[3],
            $m1[4] * $m2[0] + $m1[5] * $m2[2] + $m2[4],
            $m1[4] * $m2[1] + $m1[5] * $m2[3] + $m2[5],
        ];
    }

    /**
     * @param list<float> $src
     * @return list<float>|null
     */
    private static function affineInvert(array $src): ?array
    {
        $det = $src[0] * $src[3] - $src[1] * $src[2];
        if ($det <= 0.0) {
            return null;
        }
        $rDet = 1.0 / $det;
        $dst0 = $src[3] * $rDet;
        $dst1 = -$src[1] * $rDet;
        $dst2 = -$src[2] * $rDet;
        $dst3 = $src[0] * $rDet;

        return [
            $dst0,
            $dst1,
            $dst2,
            $dst3,
            -$src[4] * $dst0 - $src[5] * $dst2,
            -$src[4] * $dst1 - $src[5] * $dst3,
        ];
    }

    /**
     * @return list<float>
     */
    public static function coerceAffineMatrix(
        Variable $arg,
        string $function,
        int $position,
        string $paramName = 'affine'
    ): array {
        $arg = $arg->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($arg)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type array, %s given',
                $function,
                $position,
                $paramName,
                EnumCaseSupport::typeNameForVariable($arg)
            ));
        }
        if (Variable::TYPE_ARRAY !== $arg->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type array, %s given',
                $function,
                $position,
                $paramName,
                self::typeLabel($arg)
            ));
        }
        $table = $arg->toArray();
        if (6 !== $table->getNumElements()) {
            throw new \ValueError($function.'(): Argument #'.$position.' ($'.$paramName.') must have 6 elements');
        }
        $affine = [];
        for ($i = 0; $i < 6; ++$i) {
            $elem = $table->findIndex($i);
            if (null === $elem) {
                throw new \ValueError($function.'(): Argument #'.$position.' ($'.$paramName.') must have 6 elements');
            }
            $elem = $elem->resolveIndirect();
            if (Variable::TYPE_INTEGER === $elem->type) {
                $affine[] = (float) $elem->toInt();
            } elseif (Variable::TYPE_FLOAT === $elem->type) {
                $affine[] = $elem->toFloat();
            } elseif (Variable::TYPE_STRING === $elem->type) {
                $affine[] = (float) $elem->toString();
            } else {
                throw new \TypeError(\sprintf(
                    '%s(): Argument #%d ($%s) contains invalid type for element %d',
                    $function,
                    $position,
                    $paramName,
                    $i
                ));
            }
        }

        return $affine;
    }

    /**
     * imageaffinematrixget() — gdAffineTranslate/Scale/Rotate/Shear* (php-src ext/gd/gd.c; #20441).
     *
     * @return list<float>|null
     */
    public static function affineMatrixGet(int $type, Variable $options): ?array
    {
        $options = $options->resolveIndirect();
        switch ($type) {
            case GdConstants::REGISTERED['IMG_AFFINE_TRANSLATE']:
            case GdConstants::REGISTERED['IMG_AFFINE_SCALE']:
                if (Variable::TYPE_ARRAY !== $options->type) {
                    // php-src zend_argument_type_error(1, ...) — Argument #1 wording (#20441).
                    throw new \TypeError(
                        'imageaffinematrixget(): Argument #1 ($type) must be of type array when using translate or scale'
                    );
                }
                $table = $options->toArray();
                $xVar = $table->find('x');
                if (null === $xVar) {
                    throw new \ValueError('imageaffinematrixget(): Argument #2 ($options) must have an "x" key');
                }
                $yVar = $table->find('y');
                if (null === $yVar) {
                    throw new \ValueError('imageaffinematrixget(): Argument #2 ($options) must have a "y" key');
                }
                $x = self::zvalGetDouble($xVar);
                $y = self::zvalGetDouble($yVar);
                if (GdConstants::REGISTERED['IMG_AFFINE_TRANSLATE'] === $type) {
                    return [1.0, 0.0, 0.0, 1.0, $x, $y];
                }

                return [$x, 0.0, 0.0, $y, 0.0, 0.0];

            case GdConstants::REGISTERED['IMG_AFFINE_ROTATE']:
            case GdConstants::REGISTERED['IMG_AFFINE_SHEAR_HORIZONTAL']:
            case GdConstants::REGISTERED['IMG_AFFINE_SHEAR_VERTICAL']:
                $angle = self::zvalGetDouble($options);
                $rad = $angle * M_PI / 180.0;
                if (GdConstants::REGISTERED['IMG_AFFINE_SHEAR_HORIZONTAL'] === $type) {
                    return [1.0, 0.0, \tan($rad), 1.0, 0.0, 0.0];
                }
                if (GdConstants::REGISTERED['IMG_AFFINE_SHEAR_VERTICAL'] === $type) {
                    return [1.0, \tan($rad), 0.0, 1.0, 0.0, 0.0];
                }
                $sinT = \sin($rad);
                $cosT = \cos($rad);

                return [$cosT, $sinT, -$sinT, $cosT, 0.0, 0.0];

            default:
                throw new \ValueError('imageaffinematrixget(): Argument #1 ($type) must be a valid element type');
        }
    }

    /**
     * @param list<float> $m1
     * @param list<float> $m2
     * @return list<float>
     */
    public static function concatAffineMatrices(array $m1, array $m2): array
    {
        return self::affineConcat($m1, $m2);
    }

    /**
     * @param list<float> $matrix
     */
    public static function affineMatrixToHashTable(array $matrix): HashTable
    {
        $ht = new HashTable();
        foreach ($matrix as $index => $value) {
            $slot = new Variable();
            $slot->float($value);
            $ht->updateIndex((int) $index, $slot);
        }

        return $ht;
    }

    /** zval_get_double-style coercion for affine options (php-src Zend/zend_operators.c). */
    private static function zvalGetDouble(Variable $arg): float
    {
        $arg = $arg->resolveIndirect();
        switch ($arg->type) {
            case Variable::TYPE_FLOAT:
                return $arg->toFloat();
            case Variable::TYPE_INTEGER:
                return (float) $arg->toInt();
            case Variable::TYPE_BOOLEAN:
                return $arg->toBool() ? 1.0 : 0.0;
            case Variable::TYPE_NULL:
                return 0.0;
            case Variable::TYPE_STRING:
                return (float) $arg->toString();
            case Variable::TYPE_ARRAY:
                return 1.0;
            default:
                return 1.0;
        }
    }

    /**
     * @return array{x: int, y: int, width: int, height: int}
     */
    public static function coerceAffineClipRect(Variable $arg, string $function, int $position): array
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arg->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($clip) must be of type ?array, %s given',
                $function,
                $position,
                self::typeLabel($arg)
            ));
        }
        $table = $arg->toArray();
        $values = [];
        foreach (['x', 'y', 'width', 'height'] as $key) {
            $valueVar = $table->find($key);
            if (null === $valueVar) {
                throw new \ValueError(\sprintf(
                    '%s(): Argument #%d ($clip) must have a "%s" key',
                    $function,
                    $position,
                    $key
                ));
            }
            $valueVar = $valueVar->resolveIndirect();
            if (Variable::TYPE_INTEGER === $valueVar->type) {
                $values[$key] = $valueVar->toInt();
            } elseif (Variable::TYPE_FLOAT === $valueVar->type) {
                $values[$key] = (int) $valueVar->toFloat();
            } else {
                $values[$key] = (int) $valueVar->toInt();
            }
        }

        return [
            'x' => $values['x'],
            'y' => $values['y'],
            'width' => $values['width'],
            'height' => $values['height'],
        ];
    }

    /**
     * imageconvolution() — in-place 3×3 kernel (php-src gdImageConvolution; #20405).
     *
     * @param list<list<float>> $matrix
     */
    public static function convolve(ObjectEntry $image, array $matrix, float $divisor, float $offset): bool
    {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return false;
        }
        if (!is_finite($divisor) || 0.0 === $divisor) {
            throw new \ValueError('imageconvolution(): Argument #3 ($divisor) must be a non-zero finite number');
        }
        if (!is_finite($offset)) {
            throw new \ValueError('imageconvolution(): Argument #4 ($offset) must be finite');
        }

        $width = $state->width;
        $height = $state->height;
        $src = $state->pixels;
        $dst = $src;
        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                $r = 0.0;
                $g = 0.0;
                $b = 0.0;
                for ($ky = 0; $ky < 3; ++$ky) {
                    $sy = $y + $ky - 1;
                    if ($sy < 0 || $sy >= $height) {
                        continue;
                    }
                    for ($kx = 0; $kx < 3; ++$kx) {
                        $sx = $x + $kx - 1;
                        if ($sx < 0 || $sx >= $width) {
                            continue;
                        }
                        $weight = $matrix[$ky][$kx];
                        [$pr, $pg, $pb] = self::unpackRgb($src[$sy * $width + $sx]);
                        $r += $pr * $weight;
                        $g += $pg * $weight;
                        $b += $pb * $weight;
                    }
                }
                $dst[$y * $width + $x] = self::packRgb(
                    self::clampChannel((int) round($r / $divisor + $offset)),
                    self::clampChannel((int) round($g / $divisor + $offset)),
                    self::clampChannel((int) round($b / $divisor + $offset))
                );
            }
        }
        $state->pixels = $dst;

        return true;
    }

    /**
     * @return list<list<float>>
     */
    public static function coerceConvolutionMatrix(Variable $arg, string $function, int $position): array
    {
        $arg = $arg->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($arg)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($matrix) must be of type array, %s given',
                $function,
                $position,
                EnumCaseSupport::typeNameForVariable($arg)
            ));
        }
        if (Variable::TYPE_ARRAY !== $arg->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($matrix) must be of type array, %s given',
                $function,
                $position,
                self::typeLabel($arg)
            ));
        }

        $outer = $arg->toArray();
        $matrix = [];
        for ($i = 0; $i < 3; ++$i) {
            $rowVar = $outer->find((string) $i);
            if (null === $rowVar) {
                throw new \ValueError(\sprintf(
                    '%s(): Argument #%d ($matrix) must be a 3x3 array',
                    $function,
                    $position
                ));
            }
            $rowVar = $rowVar->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $rowVar->type) {
                throw new \ValueError(\sprintf(
                    '%s(): Argument #%d ($matrix) must be a 3x3 array',
                    $function,
                    $position
                ));
            }
            $rowHt = $rowVar->toArray();
            $row = [];
            for ($j = 0; $j < 3; ++$j) {
                $cellVar = $rowHt->find((string) $j);
                if (null === $cellVar) {
                    throw new \ValueError(\sprintf(
                        '%s(): Argument #%d ($matrix) must be a 3x3 array, matrix[%d][%d] cannot be found (missing integer key)',
                        $function,
                        $position,
                        $i,
                        $j
                    ));
                }
                $row[] = self::coerceFloatArg($cellVar, $function, $position, 'matrix');
            }
            $matrix[] = $row;
        }

        return $matrix;
    }

    private static function rotate90CounterClockwise(Frame $frame, GdImageState $state): ObjectEntry|false
    {
        $srcW = $state->width;
        $srcH = $state->height;
        $dstW = $srcH;
        $dstH = $srcW;
        $pixels = [];
        for ($y = 0; $y < $dstH; ++$y) {
            for ($x = 0; $x < $dstW; ++$x) {
                // 90° CCW: dest(x,y) ← src(srcW-1-y, x)
                $sx = $srcW - 1 - $y;
                $sy = $x;
                $pixels[] = $state->pixels[$sy * $srcW + $sx];
            }
        }

        return self::createTruecolorFromPixels($frame, $dstW, $dstH, $pixels);
    }

    private static function rotate180(Frame $frame, GdImageState $state): ObjectEntry|false
    {
        $srcW = $state->width;
        $srcH = $state->height;
        $pixels = [];
        for ($y = 0; $y < $srcH; ++$y) {
            for ($x = 0; $x < $srcW; ++$x) {
                $sx = $srcW - 1 - $x;
                $sy = $srcH - 1 - $y;
                $pixels[] = $state->pixels[$sy * $srcW + $sx];
            }
        }

        return self::createTruecolorFromPixels($frame, $srcW, $srcH, $pixels);
    }

    private static function rotate270CounterClockwise(Frame $frame, GdImageState $state): ObjectEntry|false
    {
        $srcW = $state->width;
        $srcH = $state->height;
        $dstW = $srcH;
        $dstH = $srcW;
        $pixels = [];
        for ($y = 0; $y < $dstH; ++$y) {
            for ($x = 0; $x < $dstW; ++$x) {
                // 270° CCW: dest(x,y) ← src(y, srcH-1-x)
                $sx = $y;
                $sy = $srcH - 1 - $x;
                $pixels[] = $state->pixels[$sy * $srcW + $sx];
            }
        }

        return self::createTruecolorFromPixels($frame, $dstW, $dstH, $pixels);
    }

    private static function rotateArbitrary(
        Frame $frame,
        GdImageState $state,
        float $angleDegrees,
        int $bgColor
    ): ObjectEntry|false {
        $srcW = $state->width;
        $srcH = $state->height;
        $rad = deg2rad($angleDegrees);
        $cos = cos($rad);
        $sin = sin($rad);
        $cx = ($srcW - 1) / 2.0;
        $cy = ($srcH - 1) / 2.0;
        $corners = [
            [0.0, 0.0],
            [(float) ($srcW - 1), 0.0],
            [(float) ($srcW - 1), (float) ($srcH - 1)],
            [0.0, (float) ($srcH - 1)],
        ];
        $minX = INF;
        $maxX = -INF;
        $minY = INF;
        $maxY = -INF;
        foreach ($corners as [$px, $py]) {
            $dx = $px - $cx;
            $dy = $py - $cy;
            $rx = $dx * $cos - $dy * $sin;
            $ry = $dx * $sin + $dy * $cos;
            $minX = min($minX, $rx);
            $maxX = max($maxX, $rx);
            $minY = min($minY, $ry);
            $maxY = max($maxY, $ry);
        }
        $dstW = (int) floor($maxX - $minX) + 1;
        $dstH = (int) floor($maxY - $minY) + 1;
        if ($dstW <= 0 || $dstH <= 0) {
            return false;
        }
        $dstCx = ($dstW - 1) / 2.0;
        $dstCy = ($dstH - 1) / 2.0;
        $invCos = cos(-$rad);
        $invSin = sin(-$rad);
        $pixels = [];
        for ($y = 0; $y < $dstH; ++$y) {
            for ($x = 0; $x < $dstW; ++$x) {
                $dx = $x - $dstCx;
                $dy = $y - $dstCy;
                $sx = $dx * $invCos - $dy * $invSin + $cx;
                $sy = $dx * $invSin + $dy * $invCos + $cy;
                if ($sx < -0.5 || $sy < -0.5 || $sx >= $srcW - 0.5 || $sy >= $srcH - 0.5) {
                    $pixels[] = $bgColor;
                } else {
                    $pixels[] = self::sampleBilinear($state->pixels, $srcW, $srcH, $sx, $sy);
                }
            }
        }

        return self::createTruecolorFromPixels($frame, $dstW, $dstH, $pixels);
    }

    public static function coerceIntArg(Variable $arg, string $function, int $position, string $name): int
    {
        $arg = $arg->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($arg)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type int, %s given',
                $function,
                $position,
                $name,
                EnumCaseSupport::typeNameForVariable($arg)
            ));
        }
        if (Variable::TYPE_INTEGER !== $arg->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type int, %s given',
                $function,
                $position,
                $name,
                self::typeLabel($arg)
            ));
        }

        return $arg->toInt();
    }

    public static function requireGdImage(Variable $arg, string $function, int $position): ObjectEntry
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $arg->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type GdImage, %s given',
                $function,
                $position,
                self::parameterName($function, $position),
                self::typeLabel($arg)
            ));
        }
        $object = $arg->toObject();
        if (null === GdRegistry::state($object)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type GdImage, %s given',
                $function,
                $position,
                self::parameterName($function, $position),
                $object->class->name
            ));
        }

        return $object;
    }

    public static function encodedBytes(ObjectEntry $image): string
    {
        $state = GdRegistry::state($image);
        if (null === $state) {
            throw new \TypeError('imagepng(): Argument #1 ($image) must be of type GdImage');
        }

        if ($state->hasEncoded()) {
            return $state->encoded;
        }
        if ($state->hasRaster()) {
            $pixels = self::truecolorPixelsForEncode($state);
            if ($state->saveAlpha) {
                return VmGdPng::encodeRgba($state->width, $state->height, $pixels);
            }

            return VmGdPng::encodeRgb($state->width, $state->height, $pixels);
        }

        throw new \TypeError('imagepng(): Argument #1 ($image) must be of type GdImage');
    }

    public static function writePngToOutput(Frame $frame, ObjectEntry $image): bool
    {
        OutputBuffer::append(self::encodedBytes($image), $frame->scriptPath ?: null);

        return true;
    }

    public static function encodedWebpBytes(ObjectEntry $image): string
    {
        $state = GdRegistry::state($image);
        if (null === $state) {
            throw new \TypeError('imagewebp(): Argument #1 ($image) must be of type GdImage');
        }
        if ($state->hasRaster()) {
            return VmGdWebp::encodeRgb($state->width, $state->height, self::truecolorPixelsForEncode($state));
        }
        if ($state->hasEncoded() && VmImage::IMAGETYPE_WEBP === $state->imageType) {
            return $state->encoded;
        }

        throw new \TypeError('imagewebp(): Argument #1 ($image) must be of type GdImage');
    }

    public static function writeWebpToOutput(Frame $frame, ObjectEntry $image): bool
    {
        OutputBuffer::append(self::encodedWebpBytes($image), $frame->scriptPath ?: null);

        return true;
    }

    public static function encodedAvifBytes(ObjectEntry $image): string
    {
        $state = GdRegistry::state($image);
        if (null === $state) {
            throw new \TypeError('imageavif(): Argument #1 ($image) must be of type GdImage');
        }
        if ($state->hasRaster()) {
            return VmGdAvif::encodeRgb($state->width, $state->height, self::truecolorPixelsForEncode($state));
        }
        if ($state->hasEncoded() && VmImage::IMAGETYPE_AVIF === $state->imageType) {
            return $state->encoded;
        }

        throw new \TypeError('imageavif(): Argument #1 ($image) must be of type GdImage');
    }

    public static function writeAvifToOutput(Frame $frame, ObjectEntry $image): bool
    {
        OutputBuffer::append(self::encodedAvifBytes($image), $frame->scriptPath ?: null);

        return true;
    }

    public static function createFromWebpBytes(Frame $frame, string $data): ObjectEntry|false
    {
        $decoded = VmGdWebp::decodeRgb($data);
        if (false === $decoded) {
            self::warnInvalidImageFormat($frame, 'imagecreatefromwebp');

            return false;
        }
        [$width, $height, $pixels] = $decoded;
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('imagecreatefromwebp() requires VM context');
        }
        $class = $ctx->classes[self::CLASS_GDIMAGE] ?? null;
        if (null === $class) {
            throw new \LogicException('GdImage is not registered in this compiler build');
        }
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        GdRegistry::attach($entry, GdImageState::fromRaster($width, $height, $pixels));

        return $entry;
    }

    public static function createFromAvifBytes(Frame $frame, string $data): ObjectEntry|false
    {
        $decoded = VmGdAvif::decodeRgb($data);
        if (false === $decoded) {
            self::warnInvalidImageFormat($frame, 'imagecreatefromavif');

            return false;
        }
        [$width, $height, $pixels] = $decoded;
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('imagecreatefromavif() requires VM context');
        }
        $class = $ctx->classes[self::CLASS_GDIMAGE] ?? null;
        if (null === $class) {
            throw new \LogicException('GdImage is not registered in this compiler build');
        }
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        GdRegistry::attach($entry, GdImageState::fromRaster($width, $height, $pixels));

        return $entry;
    }

    public static function createFromBmpBytes(Frame $frame, string $data): ObjectEntry|false
    {
        $decoded = VmGdBmp::decodeRgb($data);
        if (false === $decoded) {
            self::warnInvalidImageFormat($frame, 'imagecreatefrombmp');

            return false;
        }
        [$width, $height, $pixels] = $decoded;
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('imagecreatefrombmp() requires VM context');
        }
        $class = $ctx->classes[self::CLASS_GDIMAGE] ?? null;
        if (null === $class) {
            throw new \LogicException('GdImage is not registered in this compiler build');
        }
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $state = GdImageState::fromRaster($width, $height, $pixels);
        $state->imageType = VmImage::IMAGETYPE_BMP;
        GdRegistry::attach($entry, $state);

        return $entry;
    }

    public static function createFromPngBytes(Frame $frame, string $data): ObjectEntry|false
    {
        $decoded = VmGdPng::decodeRgb($data);
        if (false === $decoded) {
            self::warnInvalidImageFormat($frame, 'imagecreatefrompng');

            return false;
        }
        [$width, $height, $pixels] = $decoded;

        return self::attachRasterImage($frame, $width, $height, $pixels, VmImage::IMAGETYPE_PNG, 'imagecreatefrompng');
    }

    public static function createFromJpegBytes(Frame $frame, string $data): ObjectEntry|false
    {
        $decoded = VmGdJpeg::decodeRgb($data);
        if (false === $decoded) {
            self::warnInvalidImageFormat($frame, 'imagecreatefromjpeg');

            return false;
        }
        [$width, $height, $pixels] = $decoded;

        return self::attachRasterImage($frame, $width, $height, $pixels, VmImage::IMAGETYPE_JPEG, 'imagecreatefromjpeg');
    }

    public static function createFromGifBytes(Frame $frame, string $data): ObjectEntry|false
    {
        $decoded = VmGdGif::decodeRgb($data);
        if (false === $decoded) {
            self::warnInvalidImageFormat($frame, 'imagecreatefromgif');

            return false;
        }
        [$width, $height, $pixels] = $decoded;

        return self::attachRasterImage($frame, $width, $height, $pixels, VmImage::IMAGETYPE_GIF, 'imagecreatefromgif');
    }

    /**
     * @param list<int> $pixels
     */
    private static function attachRasterImage(
        Frame $frame,
        int $width,
        int $height,
        array $pixels,
        int $imageType,
        string $function
    ): ObjectEntry {
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException($function.'() requires VM context');
        }
        $class = $ctx->classes[self::CLASS_GDIMAGE] ?? null;
        if (null === $class) {
            throw new \LogicException('GdImage is not registered in this compiler build');
        }
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $state = GdImageState::fromRaster($width, $height, $pixels);
        $state->imageType = $imageType;
        GdRegistry::attach($entry, $state);

        return $entry;
    }

    public static function encodedJpegBytes(ObjectEntry $image, int $quality = 75): string
    {
        $state = GdRegistry::state($image);
        if (null === $state) {
            throw new \TypeError('imagejpeg(): Argument #1 ($image) must be of type GdImage');
        }
        if ($state->hasRaster()) {
            return VmGdJpeg::encodeRgb(
                $state->width,
                $state->height,
                self::truecolorPixelsForEncode($state),
                $quality
            );
        }
        if ($state->hasEncoded() && VmImage::IMAGETYPE_JPEG === $state->imageType) {
            return $state->encoded;
        }

        throw new \TypeError('imagejpeg(): Argument #1 ($image) must be of type GdImage');
    }

    public static function writeJpegToOutput(Frame $frame, ObjectEntry $image, int $quality = 75): bool
    {
        OutputBuffer::append(self::encodedJpegBytes($image, $quality), $frame->scriptPath ?: null);

        return true;
    }

    public static function encodedGifBytes(ObjectEntry $image): string
    {
        $state = GdRegistry::state($image);
        if (null === $state) {
            throw new \TypeError('imagegif(): Argument #1 ($image) must be of type GdImage');
        }
        if ($state->hasRaster()) {
            return VmGdGif::encodeRgb(
                $state->width,
                $state->height,
                self::truecolorPixelsForEncode($state)
            );
        }
        if ($state->hasEncoded() && VmImage::IMAGETYPE_GIF === $state->imageType) {
            return $state->encoded;
        }

        throw new \TypeError('imagegif(): Argument #1 ($image) must be of type GdImage');
    }

    public static function writeGifToOutput(Frame $frame, ObjectEntry $image): bool
    {
        OutputBuffer::append(self::encodedGifBytes($image), $frame->scriptPath ?: null);

        return true;
    }

    public static function encodedBmpBytes(ObjectEntry $image, bool $compressed = true): string
    {
        $state = GdRegistry::state($image);
        if (null === $state) {
            throw new \TypeError('imagebmp(): Argument #1 ($image) must be of type GdImage');
        }
        if ($state->hasRaster()) {
            return VmGdBmp::encodeRgb(
                $state->width,
                $state->height,
                self::truecolorPixelsForEncode($state),
                $compressed
            );
        }
        if ($state->hasEncoded() && VmImage::IMAGETYPE_BMP === $state->imageType) {
            return $state->encoded;
        }

        throw new \TypeError('imagebmp(): Argument #1 ($image) must be of type GdImage');
    }

    public static function writeBmpToOutput(Frame $frame, ObjectEntry $image, bool $compressed = true): bool
    {
        OutputBuffer::append(self::encodedBmpBytes($image, $compressed), $frame->scriptPath ?: null);

        return true;
    }

    /**
     * Default WBMP/XBM foreground RGB — first black palette entry, else 0 (php-src gd.c; #20472).
     *
     * @return int RGB to treat as black; -1 means no match (all white)
     */
    public static function resolveMonoForegroundRgb(ObjectEntry $image, ?int $foreground): int
    {
        $state = GdRegistry::state($image);
        if (null === $state) {
            throw new \TypeError('GdImage required for mono foreground resolve');
        }
        if (null !== $foreground) {
            if ($state->truecolor) {
                return $foreground & 0xFFFFFF;
            }

            return isset($state->colors[$foreground])
                ? ($state->colors[$foreground] & 0xFFFFFF)
                : -1;
        }
        if ($state->truecolor) {
            return 0;
        }
        foreach ($state->colors as $rgb) {
            if (0 === ($rgb & 0xFFFFFF)) {
                return $rgb & 0xFFFFFF;
            }
        }

        // php-src leaves i == colorsTotal — no pixel matches.
        return -1;
    }

    public static function encodedWbmpBytes(ObjectEntry $image, ?int $foreground = null): string
    {
        $state = GdRegistry::state($image);
        if (null === $state) {
            throw new \TypeError('imagewbmp(): Argument #1 ($image) must be of type GdImage');
        }
        if (!$state->hasRaster()) {
            throw new \TypeError('imagewbmp(): Argument #1 ($image) must be of type GdImage');
        }
        $fg = self::resolveMonoForegroundRgb($image, $foreground);

        return VmGdWbmp::encodeRgb(
            $state->width,
            $state->height,
            self::truecolorPixelsForEncode($state),
            $fg
        );
    }

    public static function writeWbmpToOutput(Frame $frame, ObjectEntry $image, ?int $foreground = null): bool
    {
        OutputBuffer::append(self::encodedWbmpBytes($image, $foreground), $frame->scriptPath ?: null);

        return true;
    }

    public static function createFromWbmpBytes(Frame $frame, string $data): ObjectEntry|false
    {
        $decoded = VmGdWbmp::decodeRgb($data);
        if (false === $decoded) {
            self::warnInvalidImageFormat($frame, 'imagecreatefromwbmp');

            return false;
        }
        [$width, $height, $pixels] = $decoded;

        return self::attachRasterImage($frame, $width, $height, $pixels, VmImage::IMAGETYPE_WBMP, 'imagecreatefromwbmp');
    }

    /**
     * imagegd() encode — php-src gdImageGd (#20502).
     */
    public static function encodedGdBytes(ObjectEntry $image): string
    {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            throw new \TypeError('imagegd(): Argument #1 ($image) must be of type GdImage');
        }

        return VmGdGd::encodeGd($state);
    }

    public static function writeGdToOutput(Frame $frame, ObjectEntry $image): bool
    {
        OutputBuffer::append(self::encodedGdBytes($image), $frame->scriptPath ?: null);

        return true;
    }

    public static function createFromGdBytes(Frame $frame, string $data): ObjectEntry|false
    {
        $decoded = VmGdGd::decodeGd($data);
        if (false === $decoded) {
            self::warnInvalidImageFormat($frame, 'imagecreatefromgd');

            return false;
        }

        return self::attachGdDecoded($frame, $decoded, 'imagecreatefromgd');
    }

    /**
     * imagegd2() encode — php-src gdImageGd2 (#20502).
     */
    public static function encodedGd2Bytes(
        ObjectEntry $image,
        int $chunkSize = 128, // VmGdGd::GD2_CHUNKSIZE — literal default (#3803 / #22642 spine AOT)
        int $mode = 1 // VmGdGd::GD2_FMT_RAW
    ): string {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            throw new \TypeError('imagegd2(): Argument #1 ($image) must be of type GdImage');
        }

        return VmGdGd::encodeGd2($state, $chunkSize, $mode);
    }

    public static function writeGd2ToOutput(
        Frame $frame,
        ObjectEntry $image,
        int $chunkSize = 128, // VmGdGd::GD2_CHUNKSIZE — literal default (#3803 / #22642 spine AOT)
        int $mode = 1 // VmGdGd::GD2_FMT_RAW
    ): bool {
        OutputBuffer::append(self::encodedGd2Bytes($image, $chunkSize, $mode), $frame->scriptPath ?: null);

        return true;
    }

    public static function createFromGd2Bytes(Frame $frame, string $data): ObjectEntry|false
    {
        $decoded = VmGdGd::decodeGd2($data);
        if (false === $decoded) {
            self::warnInvalidImageFormat($frame, 'imagecreatefromgd2');

            return false;
        }

        return self::attachGdDecoded($frame, $decoded, 'imagecreatefromgd2');
    }

    public static function createFromGd2PartBytes(
        Frame $frame,
        string $data,
        int $srcx,
        int $srcy,
        int $width,
        int $height
    ): ObjectEntry|false {
        $decoded = VmGdGd::decodeGd2Part($data, $srcx, $srcy, $width, $height);
        if (false === $decoded) {
            self::warnInvalidImageFormat($frame, 'imagecreatefromgd2part');

            return false;
        }

        return self::attachGdDecoded($frame, $decoded, 'imagecreatefromgd2part');
    }

    public static function createFromTgaBytes(Frame $frame, string $data): ObjectEntry|false
    {
        $decoded = VmGdTga::decodeRgb($data);
        if (false === $decoded) {
            self::warnInvalidImageFormat($frame, 'imagecreatefromtga');

            return false;
        }
        [$width, $height, $pixels, $hasAlpha] = $decoded;
        $entry = self::attachRasterImage($frame, $width, $height, $pixels, VmImage::IMAGETYPE_PNG, 'imagecreatefromtga');
        if ($hasAlpha) {
            $state = GdRegistry::state($entry);
            if (null !== $state) {
                $state->alphaBlending = 0;
                $state->saveAlpha = true;
            }
        }

        return $entry;
    }

    /**
     * @param array{
     *   width: int,
     *   height: int,
     *   truecolor: bool,
     *   pixels: list<int>,
     *   colors: list<int>,
     *   transparent: int
     * } $decoded
     */
    private static function attachGdDecoded(Frame $frame, array $decoded, string $function): ObjectEntry
    {
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException($function.'() requires VM context');
        }
        $class = $ctx->classes[self::CLASS_GDIMAGE] ?? null;
        if (null === $class) {
            throw new \LogicException('GdImage is not registered in this compiler build');
        }
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        GdRegistry::attach(
            $entry,
            GdImageState::fromGdDecoded(
                $decoded['width'],
                $decoded['height'],
                $decoded['truecolor'],
                $decoded['pixels'],
                $decoded['colors'],
                $decoded['transparent']
            )
        );

        return $entry;
    }

    public static function encodedXbmBytes(ObjectEntry $image, ?int $foreground = null, string $name = 'image'): string
    {
        $state = GdRegistry::state($image);
        if (null === $state) {
            throw new \TypeError('imagexbm(): Argument #1 ($image) must be of type GdImage');
        }
        if (!$state->hasRaster()) {
            throw new \TypeError('imagexbm(): Argument #1 ($image) must be of type GdImage');
        }
        $fg = self::resolveMonoForegroundRgb($image, $foreground);

        return VmGdXbm::encodeRgb(
            $state->width,
            $state->height,
            self::truecolorPixelsForEncode($state),
            $fg,
            $name
        );
    }

    public static function writeXbmToOutput(Frame $frame, ObjectEntry $image, ?int $foreground = null, string $name = 'image'): bool
    {
        OutputBuffer::append(self::encodedXbmBytes($image, $foreground, $name), $frame->scriptPath ?: null);

        return true;
    }

    public static function createFromXbmBytes(Frame $frame, string $data): ObjectEntry|false
    {
        $decoded = VmGdXbm::decodeRgb($data);
        if (false === $decoded) {
            self::warnInvalidImageFormat($frame, 'imagecreatefromxbm');

            return false;
        }
        [$width, $height, $pixels] = $decoded;

        return self::attachRasterImage($frame, $width, $height, $pixels, VmImage::IMAGETYPE_XBM, 'imagecreatefromxbm');
    }

    /** imagecreatefromxpm — no libXpm in this build; warn + false (php-src HAVE_GD_XPM off shape; #20472). */
    public static function createFromXpmUnsupported(Frame $frame): false
    {
        if (null !== $frame->vmContext) {
            $frame->vmContext->errors->triggerError(
                'imagecreatefromxpm(): XPM support is not available in this PHP build',
                ErrorReporter::E_WARNING,
                '' !== $frame->scriptPath ? $frame->scriptPath : null,
                $frame->vmContext,
                $frame
            );
        }

        return false;
    }

    public static function applyFilter(
        Frame $frame,
        ObjectEntry $image,
        int $filter,
        int $arg1,
        int $arg2,
        int $arg3,
        int $arg4
    ): bool {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster() || !$state->truecolor) {
            return false;
        }

        $width = $state->width;
        $height = $state->height;
        $pixels = $state->pixels;

        switch ($filter) {
            case GdConstants::REGISTERED['IMG_FILTER_NEGATE']:
                for ($i = 0, $n = $width * $height; $i < $n; ++$i) {
                    $pixels[$i] = self::negateColor($pixels[$i]);
                }
                break;
            case GdConstants::REGISTERED['IMG_FILTER_GRAYSCALE']:
                for ($i = 0, $n = $width * $height; $i < $n; ++$i) {
                    $pixels[$i] = self::grayscaleColor($pixels[$i]);
                }
                break;
            case GdConstants::REGISTERED['IMG_FILTER_BRIGHTNESS']:
                for ($i = 0, $n = $width * $height; $i < $n; ++$i) {
                    $pixels[$i] = self::brightnessColor($pixels[$i], $arg1);
                }
                break;
            default:
                self::warnUnsupportedFilter($frame, $filter);

                return false;
        }

        $state->pixels = $pixels;

        return true;
    }

    public static function flip(ObjectEntry $image, int $mode): bool
    {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return false;
        }

        if (!\in_array($mode, [
            GdConstants::REGISTERED['IMG_FLIP_HORIZONTAL'],
            GdConstants::REGISTERED['IMG_FLIP_VERTICAL'],
            GdConstants::REGISTERED['IMG_FLIP_BOTH'],
        ], true)) {
            return false;
        }

        $width = $state->width;
        $height = $state->height;
        $flipped = $state->pixels;

        if (GdConstants::REGISTERED['IMG_FLIP_VERTICAL'] === $mode
            || GdConstants::REGISTERED['IMG_FLIP_BOTH'] === $mode) {
            for ($y = 0; $y < (int) ($height / 2); ++$y) {
                $mirrorY = $height - 1 - $y;
                for ($x = 0; $x < $width; ++$x) {
                    $a = $y * $width + $x;
                    $b = $mirrorY * $width + $x;
                    [$flipped[$a], $flipped[$b]] = [$flipped[$b], $flipped[$a]];
                }
            }
        }

        if (GdConstants::REGISTERED['IMG_FLIP_HORIZONTAL'] === $mode
            || GdConstants::REGISTERED['IMG_FLIP_BOTH'] === $mode) {
            for ($y = 0; $y < $height; ++$y) {
                $row = $y * $width;
                for ($x = 0; $x < (int) ($width / 2); ++$x) {
                    $mirrorX = $width - 1 - $x;
                    $a = $row + $x;
                    $b = $row + $mirrorX;
                    [$flipped[$a], $flipped[$b]] = [$flipped[$b], $flipped[$a]];
                }
            }
        }

        $state->pixels = $flipped;

        return true;
    }

    public static function crop(Frame $frame, ObjectEntry $image, Variable $rectArg): ObjectEntry|false
    {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return false;
        }

        $rect = self::coerceCropRect($rectArg, 'imagecrop', 2);
        if (null === $rect) {
            return false;
        }

        ['x' => $x, 'y' => $y, 'width' => $cropWidth, 'height' => $cropHeight] = $rect;
        if ($cropWidth <= 0 || $cropHeight <= 0) {
            self::warnCropDimensions($frame);

            return false;
        }
        if ($x < 0 || $y < 0 || $x + $cropWidth > $state->width || $y + $cropHeight > $state->height) {
            self::warnCropOutOfBounds($frame);

            return false;
        }

        $pixels = [];
        for ($row = 0; $row < $cropHeight; ++$row) {
            $srcRow = ($y + $row) * $state->width + $x;
            for ($col = 0; $col < $cropWidth; ++$col) {
                $pixels[] = $state->pixels[$srcRow + $col];
            }
        }

        return self::createTruecolorFromPixels($frame, $cropWidth, $cropHeight, $pixels);
    }

    public static function cropAuto(
        Frame $frame,
        ObjectEntry $image,
        int $mode,
        float $threshold,
        int $color
    ): ObjectEntry|false {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return false;
        }

        $width = $state->width;
        $height = $state->height;
        $pixels = $state->pixels;
        if (0 === $width || 0 === $height) {
            return false;
        }

        $background = match ($mode) {
            GdConstants::REGISTERED['IMG_CROP_BLACK'] => 0,
            GdConstants::REGISTERED['IMG_CROP_WHITE'] => self::packRgb(255, 255, 255),
            GdConstants::REGISTERED['IMG_CROP_TRANSPARENT'] => 0,
            GdConstants::REGISTERED['IMG_CROP_THRESHOLD'] => $color,
            default => $pixels[0],
        };

        $minX = $width;
        $minY = $height;
        $maxX = -1;
        $maxY = -1;
        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                $pixel = $pixels[$y * $width + $x];
                if (self::pixelMatchesCropBackground($pixel, $background, $mode, $threshold)) {
                    continue;
                }
                if ($x < $minX) {
                    $minX = $x;
                }
                if ($y < $minY) {
                    $minY = $y;
                }
                if ($x > $maxX) {
                    $maxX = $x;
                }
                if ($y > $maxY) {
                    $maxY = $y;
                }
            }
        }

        if ($maxX < $minX || $maxY < $minY) {
            return self::createTruecolorFromPixels($frame, $width, $height, $pixels);
        }

        $cropWidth = $maxX - $minX + 1;
        $cropHeight = $maxY - $minY + 1;
        $cropped = [];
        for ($row = 0; $row < $cropHeight; ++$row) {
            $srcRow = ($minY + $row) * $width + $minX;
            for ($col = 0; $col < $cropWidth; ++$col) {
                $cropped[] = $pixels[$srcRow + $col];
            }
        }

        return self::createTruecolorFromPixels($frame, $cropWidth, $cropHeight, $cropped);
    }

    /**
     * @param list<int> $pixels
     */
    public static function createTruecolorFromPixels(
        Frame $frame,
        int $width,
        int $height,
        array $pixels
    ): ObjectEntry|false {
        if ($width <= 0 || $height <= 0) {
            return false;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('GdImage creation requires VM context');
        }
        $class = $ctx->classes[self::CLASS_GDIMAGE] ?? null;
        if (null === $class) {
            throw new \LogicException('GdImage is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        GdRegistry::attach($entry, GdImageState::fromRaster($width, $height, $pixels));

        return $entry;
    }

    /**
     * @return array{x: int, y: int, width: int, height: int}|null
     */
    public static function coerceCropRect(Variable $arg, string $function, int $position): ?array
    {
        $arg = $arg->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($arg)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($rect) must be of type array, %s given',
                $function,
                $position,
                EnumCaseSupport::typeNameForVariable($arg)
            ));
        }
        if (Variable::TYPE_ARRAY !== $arg->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($rect) must be of type array, %s given',
                $function,
                $position,
                self::typeLabel($arg)
            ));
        }

        $values = [];
        $table = $arg->toArray();
        foreach (['x', 'y', 'width', 'height'] as $key) {
            $valueVar = $table->find($key);
            if (null === $valueVar) {
                $values[$key] = 0;

                continue;
            }
            $valueVar = $valueVar->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $valueVar->type) {
                throw new \TypeError(\sprintf(
                    '%s(): Argument #%d ($rect[%s]) must be of type int, %s given',
                    $function,
                    $position,
                    $key,
                    self::typeLabel($valueVar)
                ));
            }
            $values[$key] = $valueVar->toInt();
        }

        return [
            'x' => $values['x'],
            'y' => $values['y'],
            'width' => $values['width'],
            'height' => $values['height'],
        ];
    }

    public static function coerceFloatArg(Variable $arg, string $function, int $position, string $name): float
    {
        $arg = $arg->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($arg)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type float, %s given',
                $function,
                $position,
                $name,
                EnumCaseSupport::typeNameForVariable($arg)
            ));
        }
        if (Variable::TYPE_FLOAT === $arg->type) {
            return $arg->toFloat();
        }
        if (Variable::TYPE_INTEGER === $arg->type) {
            return (float) $arg->toInt();
        }

        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($%s) must be of type float, %s given',
            $function,
            $position,
            $name,
            self::typeLabel($arg)
        ));
    }

    private static function negateColor(int $color): int
    {
        [$r, $g, $b] = self::unpackRgb($color);

        return self::packRgb(255 - $r, 255 - $g, 255 - $b);
    }

    private static function grayscaleColor(int $color): int
    {
        [$r, $g, $b] = self::unpackRgb($color);
        $gray = (int) round(0.299 * $r + 0.587 * $g + 0.114 * $b);

        return self::packRgb($gray, $gray, $gray);
    }

    private static function brightnessColor(int $color, int $level): int
    {
        [$r, $g, $b] = self::unpackRgb($color);

        return self::packRgb(
            self::clampChannel($r + $level),
            self::clampChannel($g + $level),
            self::clampChannel($b + $level)
        );
    }

    private static function pixelMatchesCropBackground(
        int $pixel,
        int $background,
        int $mode,
        float $threshold
    ): bool {
        if (GdConstants::REGISTERED['IMG_CROP_THRESHOLD'] === $mode) {
            [$pr, $pg, $pb] = self::unpackRgb($pixel);
            [$br, $bg, $bb] = self::unpackRgb($background);
            $distance = abs($pr - $br) + abs($pg - $bg) + abs($pb - $bb);

            return $distance <= (int) round($threshold * 765.0);
        }

        return $pixel === $background;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function unpackRgb(int $color): array
    {
        return [($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF];
    }

    private static function packRgb(int $red, int $green, int $blue): int
    {
        return ($red << 16) | ($green << 8) | $blue;
    }

    private static function blendRgb(int $dstColor, int $srcColor, int $pct, int $invPct): int
    {
        [$dr, $dg, $db] = self::unpackRgb($dstColor);
        [$sr, $sg, $sb] = self::unpackRgb($srcColor);

        return self::packRgb(
            (int) (($dr * $invPct + $sr * $pct) / 100),
            (int) (($dg * $invPct + $sg * $pct) / 100),
            (int) (($db * $invPct + $sb * $pct) / 100)
        );
    }

    /**
     * @param list<int> $pixels
     */
    private static function sampleBilinear(
        array $pixels,
        int $width,
        int $height,
        float $fx,
        float $fy
    ): int {
        if ($fx < 0.0 || $fy < 0.0 || $fx >= $width || $fy >= $height) {
            return 0;
        }

        $x1 = (int) floor($fx);
        $y1 = (int) floor($fy);
        $x2 = min($x1 + 1, $width - 1);
        $y2 = min($y1 + 1, $height - 1);
        $xFrac = $fx - $x1;
        $yFrac = $fy - $y1;

        $c11 = $pixels[$y1 * $width + $x1];
        $c21 = $pixels[$y1 * $width + $x2];
        $c12 = $pixels[$y2 * $width + $x1];
        $c22 = $pixels[$y2 * $width + $x2];

        [$r11, $g11, $b11] = self::unpackRgb($c11);
        [$r21, $g21, $b21] = self::unpackRgb($c21);
        [$r12, $g12, $b12] = self::unpackRgb($c12);
        [$r22, $g22, $b22] = self::unpackRgb($c22);

        $topR = $r11 + ($r21 - $r11) * $xFrac;
        $topG = $g11 + ($g21 - $g11) * $xFrac;
        $topB = $b11 + ($b21 - $b11) * $xFrac;
        $botR = $r12 + ($r22 - $r12) * $xFrac;
        $botG = $g12 + ($g22 - $g12) * $xFrac;
        $botB = $b12 + ($b22 - $b12) * $xFrac;

        return self::packRgb(
            (int) round($topR + ($botR - $topR) * $yFrac),
            (int) round($topG + ($botG - $topG) * $yFrac),
            (int) round($topB + ($botB - $topB) * $yFrac)
        );
    }

    private static function clampChannel(int $channel): int
    {
        if ($channel < 0) {
            return 0;
        }
        if ($channel > 255) {
            return 255;
        }

        return $channel;
    }

    public static function coerceImageString(Frame $frame, Variable $arg, string $function): string
    {
        return VmString::coerceStringBuiltinArg($arg, $function, 0, 'image');
    }

    private static function warnInvalidImageFormat(Frame $frame, string $function): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            $function.'(): Invalid image format',
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    private static function warnInvalidDimensions(Frame $frame, string $function): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            $function.'(): Invalid image dimensions',
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    private static function warnCouldNotConvertToPalette(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            'imagetruecolortopalette(): Couldn\'t convert to palette',
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    /**
     * Expand palette indices to truecolor RGB for encoders (#20415).
     *
     * @return list<int>
     */
    private static function truecolorPixelsForEncode(GdImageState $state): array
    {
        if ($state->truecolor) {
            return $state->pixels;
        }
        $colors = $state->colors;
        $n = $state->width * $state->height;
        $out = [];
        for ($i = 0; $i < $n; ++$i) {
            $idx = $state->pixels[$i];
            $out[$i] = isset($colors[$idx]) ? ($colors[$idx] & 0xFFFFFF) : 0;
        }

        return $out;
    }

    /**
     * @param list<int> $palette
     */
    private static function nearestPaletteIndex(array $palette, int $r, int $g, int $b): int
    {
        $best = 0;
        $bestDist = PHP_INT_MAX;
        foreach ($palette as $idx => $rgb) {
            $pr = ($rgb >> 16) & 0xFF;
            $pg = ($rgb >> 8) & 0xFF;
            $pb = $rgb & 0xFF;
            $dr = $pr - $r;
            $dg = $pg - $g;
            $db = $pb - $b;
            $dist = $dr * $dr + $dg * $dg + $db * $db;
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best = $idx;
                if (0 === $dist) {
                    break;
                }
            }
        }

        return $best;
    }

    /**
     * Floyd–Steinberg error diffusion for truecolor→palette (#20415).
     *
     * @param list<array{0: float, 1: float, 2: float}> $work
     */
    private static function ditherDiffuse(
        array &$work,
        int $width,
        int $height,
        int $x,
        int $y,
        float $er,
        float $eg,
        float $eb
    ): void {
        $neighbors = [
            [$x + 1, $y, 7 / 16],
            [$x - 1, $y + 1, 3 / 16],
            [$x, $y + 1, 5 / 16],
            [$x + 1, $y + 1, 1 / 16],
        ];
        foreach ($neighbors as [$nx, $ny, $w]) {
            if ($nx < 0 || $ny < 0 || $nx >= $width || $ny >= $height) {
                continue;
            }
            $pos = $ny * $width + $nx;
            $work[$pos][0] += $er * $w;
            $work[$pos][1] += $eg * $w;
            $work[$pos][2] += $eb * $w;
        }
    }

    private static function typeLabel(Variable $var): string
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            return EnumCaseSupport::typeNameForVariable($var);
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            return $var->toObject()->class->name;
        }

        return ObjectHandleSupport::vmTypeName($var->type);
    }

    private static function warnColorAtOutOfBounds(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            'imagecolorat(): X and Y must be within the image bounds',
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    private static function warnUnsupportedFilter(Frame $frame, int $filter): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            \sprintf('imagefilter(): Unknown filter identifier %d', $filter),
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    private static function warnCropDimensions(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            'imagecrop(): Width and height must be greater than 0',
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    private static function warnCropOutOfBounds(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            'imagecrop(): Crop rectangle does not fit within image bounds',
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    private static function parameterName(string $function, int $position): string
    {
        return match ($function) {
            'imagepng', 'imagewebp', 'imageavif', 'imagejpeg', 'imagegif' => 1 === $position ? 'image' : 'arg',
            'imagecreatefromstring' => 'image',
            'imagecreatefromwebp', 'imagecreatefromavif', 'imagecreatefrombmp',
            'imagecreatefrompng', 'imagecreatefromjpeg', 'imagecreatefromgif' => 'filename',
            'imagesx', 'imagesy', 'imageistruecolor', 'imagepalettetotruecolor', 'imagedestroy', 'imagegetinterpolation' => 'image',
            'imageaffine' => match ($position) {
                1 => 'image',
                2 => 'affine',
                3 => 'clip',
                default => 'arg',
            },
            'imageaffinematrixget' => match ($position) {
                1 => 'type',
                2 => 'options',
                default => 'arg',
            },
            'imageaffinematrixconcat' => match ($position) {
                1 => 'matrix1',
                2 => 'matrix2',
                default => 'arg',
            },
            'imagesetinterpolation' => match ($position) {
                1 => 'image',
                2 => 'method',
                default => 'arg',
            },
            'imagetruecolortopalette' => match ($position) {
                1 => 'image',
                2 => 'dither',
                3 => 'num_colors',
                default => 'arg',
            },
            'imagealphablending', 'imagesavealpha', 'imageantialias' => match ($position) {
                1 => 'image',
                2 => 'enable',
                default => 'arg',
            },
            'imagelayereffect' => match ($position) {
                1 => 'image',
                2 => 'effect',
                default => 'arg',
            },
            'imageresolution' => match ($position) {
                1 => 'image',
                2 => 'resolution_x',
                3 => 'resolution_y',
                default => 'arg',
            },
            'imagepolygon', 'imageopenpolygon', 'imagefilledpolygon' => match ($position) {
                1 => 'image',
                2 => 'points',
                3 => 'num_points_or_color',
                4 => 'color',
                default => 'arg',
            },
            'imagesetthickness' => match ($position) {
                1 => 'image',
                2 => 'thickness',
                default => 'arg',
            },
            'imagefilltoborder' => match ($position) {
                1 => 'image',
                2 => 'x',
                3 => 'y',
                4 => 'border_color',
                5 => 'color',
                default => 'arg',
            },
            'imagesetbrush' => match ($position) {
                1 => 'image',
                2 => 'brush',
                default => 'arg',
            },
            'imagesetstyle' => match ($position) {
                1 => 'image',
                2 => 'style',
                default => 'arg',
            },
            'imagecharup', 'imagestringup' => match ($position) {
                1 => 'image',
                2 => 'font',
                3 => 'x',
                4 => 'y',
                5 => 'imagecharup' === $function ? 'char' : 'string',
                6 => 'color',
                default => 'arg',
            },
            'imagegammacorrect' => match ($position) {
                1 => 'image',
                2 => 'inputgamma',
                3 => 'outputgamma',
                default => 'arg',
            },
            'imageinterlace' => match ($position) {
                1 => 'image',
                2 => 'enable',
                default => 'arg',
            },
            'imagesetclip' => match ($position) {
                1 => 'image',
                2 => 'x1',
                3 => 'y1',
                4 => 'x2',
                5 => 'y2',
                default => 'arg',
            },
            'imagegetclip' => 'image',
            'imagecolorallocatealpha' => match ($position) {
                1 => 'image',
                2 => 'red',
                3 => 'green',
                4 => 'blue',
                5 => 'alpha',
                default => 'arg',
            },
            'imagecolorat' => match ($position) {
                1 => 'image',
                2 => 'x',
                3 => 'y',
                default => 'arg',
            },
            'imageline', 'imagefilledrectangle' => match ($position) {
                1 => 'image',
                2 => 'x1',
                3 => 'y1',
                4 => 'x2',
                5 => 'y2',
                6 => 'color',
                default => 'arg',
            },
            'imagecolormatch' => match ($position) {
                1 => 'image1',
                2 => 'image2',
                default => 'arg',
            },
            'imageloadfont' => 'filename',
            'imagestring', 'imagechar' => match ($position) {
                1 => 'image',
                2 => 'font',
                3 => 'x',
                4 => 'y',
                5 => 'char' === \substr($function, -4) ? 'char' : 'string',
                6 => 'color',
                default => 'arg',
            },
            'imagettfbbox' => match ($position) {
                1 => 'size',
                2 => 'angle',
                3 => 'font_filename',
                4 => 'string',
                default => 'arg',
            },
            'imagettftext' => match ($position) {
                1 => 'image',
                2 => 'size',
                3 => 'angle',
                4 => 'x',
                5 => 'y',
                6 => 'color',
                7 => 'font_filename',
                8 => 'text',
                default => 'arg',
            },
            default => 'arg',
        };
    }

    private static function putPixel(GdImageState $state, int $x, int $y, int $color): void
    {
        switch ($color) {
            case GdConstants::COLOR_STYLED:
                if (null === $state->style || [] === $state->style) {
                    return;
                }
                $p = $state->style[$state->stylePos++];
                $state->stylePos %= \count($state->style);
                if (GdConstants::COLOR_TRANSPARENT !== $p) {
                    self::putPixel($state, $x, $y, $p);
                }

                return;
            case GdConstants::COLOR_STYLEDBRUSHED:
                if (null === $state->style || [] === $state->style) {
                    return;
                }
                $p = $state->style[$state->stylePos++];
                $state->stylePos %= \count($state->style);
                if (GdConstants::COLOR_TRANSPARENT !== $p && 0 !== $p) {
                    self::putPixel($state, $x, $y, GdConstants::COLOR_BRUSHED);
                }

                return;
            case GdConstants::COLOR_BRUSHED:
                self::brushApply($state, $x, $y);

                return;
            case GdConstants::COLOR_TILED:
                // imagesettile() not in #20439 — no-op without a tile (libgd same).
                return;
            default:
                break;
        }
        if ($x < 0 || $y < 0 || $x >= $state->width || $y >= $state->height) {
            return;
        }
        // libgd gdImageBoundsSafe — also honor clip rect (#20460).
        if ($x < $state->cx1 || $x > $state->cx2 || $y < $state->cy1 || $y > $state->cy2) {
            return;
        }
        $index = $y * $state->width + $x;
        if (!$state->truecolor) {
            $state->pixels[$index] = $color;

            return;
        }
        // php-src ext/gd/libgd/gd.c gdImageSetPixel — default/unknown → replace (#20429).
        switch ($state->alphaBlending) {
            case GdConstants::REGISTERED['IMG_EFFECT_ALPHABLEND']:
            case GdConstants::REGISTERED['IMG_EFFECT_NORMAL']:
                $state->pixels[$index] = self::gdAlphaBlend($state->pixels[$index], $color);
                break;
            case GdConstants::REGISTERED['IMG_EFFECT_OVERLAY']:
                $state->pixels[$index] = self::gdLayerOverlay($state->pixels[$index], $color);
                break;
            case GdConstants::REGISTERED['IMG_EFFECT_MULTIPLY']:
                $state->pixels[$index] = self::gdLayerMultiply($state->pixels[$index], $color);
                break;
            case GdConstants::REGISTERED['IMG_EFFECT_REPLACE']:
            default:
                $state->pixels[$index] = $color;
                break;
        }
    }

    private static function rawPixel(GdImageState $state, int $x, int $y): int
    {
        return $state->pixels[$y * $state->width + $x];
    }

    /**
     * libgd gdImageBrushApply (php-src ext/gd/libgd/gd.c; #20439).
     */
    private static function brushApply(GdImageState $state, int $x, int $y): void
    {
        if (null === $state->brush) {
            return;
        }
        $brushState = GdRegistry::state($state->brush);
        if (null === $brushState || !$brushState->hasRaster()) {
            return;
        }
        $hy = intdiv($brushState->height, 2);
        $hx = intdiv($brushState->width, 2);
        $y1 = $y - $hy;
        $y2 = $y1 + $brushState->height;
        $x1 = $x - $hx;
        $x2 = $x1 + $brushState->width;
        $srcy = 0;
        $brushTransparent = $brushState->transparent;

        if ($state->truecolor) {
            for ($ly = $y1; $ly < $y2; ++$ly) {
                $srcx = 0;
                for ($lx = $x1; $lx < $x2; ++$lx) {
                    if ($brushState->truecolor) {
                        $p = self::rawPixel($brushState, $srcx, $srcy);
                        if ($p !== $brushTransparent) {
                            self::putPixel($state, $lx, $ly, $p);
                        }
                    } else {
                        $p = self::rawPixel($brushState, $srcx, $srcy);
                        if ($p !== $brushTransparent) {
                            $tc = self::trueColorFromBrushPixel($brushState, $p);
                            self::putPixel($state, $lx, $ly, $tc);
                        }
                    }
                    ++$srcx;
                }
                ++$srcy;
            }

            return;
        }

        for ($ly = $y1; $ly < $y2; ++$ly) {
            $srcx = 0;
            for ($lx = $x1; $lx < $x2; ++$lx) {
                $p = self::rawPixel($brushState, $srcx, $srcy);
                if ($p !== $brushTransparent) {
                    if ($brushState->truecolor) {
                        self::putPixel(
                            $state,
                            $lx,
                            $ly,
                            self::resolveColorOnState(
                                $state,
                                ($p >> 16) & 0xFF,
                                ($p >> 8) & 0xFF,
                                $p & 0xFF,
                                ($p >> 24) & 0x7F
                            )
                        );
                    } else {
                        self::putPixel($state, $lx, $ly, $state->brushColorMap[$p] ?? $p);
                    }
                }
                ++$srcx;
            }
            ++$srcy;
        }
    }

    /**
     * Palette color resolve against a GdImageState (gdImageColorResolveAlpha; #20439).
     */
    private static function resolveColorOnState(
        GdImageState $state,
        int $red,
        int $green,
        int $blue,
        int $alpha
    ): int {
        if ($state->truecolor) {
            return (($alpha & 0x7F) << 24) | (($red & 0xFF) << 16) | (($green & 0xFF) << 8) | ($blue & 0xFF);
        }
        foreach ($state->colors as $i => $packed) {
            if ((int) $i === $state->transparent) {
                continue;
            }
            if (
                (($packed >> 16) & 0xFF) === ($red & 0xFF)
                && (($packed >> 8) & 0xFF) === ($green & 0xFF)
                && ($packed & 0xFF) === ($blue & 0xFF)
                && (($packed >> 24) & 0x7F) === ($alpha & 0x7F)
            ) {
                return (int) $i;
            }
        }
        if (\count($state->colors) < 256) {
            $state->colors[] = (($alpha & 0x7F) << 24)
                | (($red & 0xFF) << 16)
                | (($green & 0xFF) << 8)
                | ($blue & 0xFF);

            return \count($state->colors) - 1;
        }
        $ct = -1;
        $mindist = 4 * 255 * 255;
        foreach ($state->colors as $i => $packed) {
            if ((int) $i === $state->transparent) {
                continue;
            }
            $rd = (($packed >> 16) & 0xFF) - $red;
            $gd = (($packed >> 8) & 0xFF) - $green;
            $bd = ($packed & 0xFF) - $blue;
            $ad = (($packed >> 24) & 0x7F) - $alpha;
            $dist = $rd * $rd + $gd * $gd + $bd * $bd + $ad * $ad;
            if (0 === $dist) {
                return (int) $i;
            }
            if ($dist < $mindist) {
                $mindist = $dist;
                $ct = (int) $i;
            }
        }

        return $ct;
    }

    private static function trueColorFromBrushPixel(GdImageState $brushState, int $p): int
    {
        if ($brushState->truecolor) {
            return $p;
        }
        if (!isset($brushState->colors[$p])) {
            return 0;
        }
        $packed = $brushState->colors[$p];
        if ($brushState->transparent === $p) {
            return ($packed & 0xFFFFFF) | (127 << 24);
        }

        return $packed;
    }

    /**
     * libgd gdAlphaBlend — GD alpha 0 opaque .. 127 transparent (php-src ext/gd/libgd/gd.c).
     */
    private static function gdAlphaBlend(int $dst, int $src): int
    {
        $srcAlpha = ($src >> 24) & 0x7F;
        if (0 === $srcAlpha) {
            return $src;
        }
        if (127 === $srcAlpha) {
            return $dst;
        }

        $dstAlpha = ($dst >> 24) & 0x7F;
        $srcWeight = 127 - $srcAlpha;
        $dstWeight = ((127 - $dstAlpha) * $srcAlpha) / 127;
        $weightSum = $srcWeight + $dstWeight;
        if ($weightSum <= 0) {
            return $dst & 0x7FFFFFFF;
        }

        $red = (int) (((($src >> 16) & 0xFF) * $srcWeight + (($dst >> 16) & 0xFF) * $dstWeight) / $weightSum);
        $green = (int) (((($src >> 8) & 0xFF) * $srcWeight + (($dst >> 8) & 0xFF) * $dstWeight) / $weightSum);
        $blue = (int) ((($src & 0xFF) * $srcWeight + ($dst & 0xFF) * $dstWeight) / $weightSum);
        $alpha = 127 - (int) $weightSum;

        return (($alpha & 0x7F) << 24) | (($red & 0xFF) << 16) | (($green & 0xFF) << 8) | ($blue & 0xFF);
    }

    /**
     * libgd gdLayerOverlay (php-src ext/gd/libgd/gd.c; #20429).
     */
    private static function gdLayerOverlay(int $dst, int $src): int
    {
        $a1 = 127 - (($dst >> 24) & 0x7F);
        $a2 = 127 - (($src >> 24) & 0x7F);

        return (((127 - (int) ($a1 * $a2 / 127)) & 0x7F) << 24)
            | ((self::gdAlphaOverlayColor(($src >> 16) & 0xFF, ($dst >> 16) & 0xFF, 255) & 0xFF) << 16)
            | ((self::gdAlphaOverlayColor(($src >> 8) & 0xFF, ($dst >> 8) & 0xFF, 255) & 0xFF) << 8)
            | (self::gdAlphaOverlayColor($src & 0xFF, $dst & 0xFF, 255) & 0xFF);
    }

    /**
     * libgd gdAlphaOverlayColor (php-src ext/gd/libgd/gd.c; #20429).
     */
    private static function gdAlphaOverlayColor(int $src, int $dst, int $max): int
    {
        $dst2 = $dst << 1;
        if ($dst2 > $max) {
            return $dst2 + ($src << 1) - (int) ($dst2 * $src / $max) - $max;
        }

        return (int) ($dst2 * $src / $max);
    }

    /**
     * libgd gdLayerMultiply (php-src ext/gd/libgd/gd.c; #20429).
     */
    private static function gdLayerMultiply(int $dst, int $src): int
    {
        $a1 = 127 - (($src >> 24) & 0x7F);
        $a2 = 127 - (($dst >> 24) & 0x7F);

        $r1 = 255 - (int) ($a1 * (255 - (($src >> 16) & 0xFF)) / 127);
        $r2 = 255 - (int) ($a2 * (255 - (($dst >> 16) & 0xFF)) / 127);
        $g1 = 255 - (int) ($a1 * (255 - (($src >> 8) & 0xFF)) / 127);
        $g2 = 255 - (int) ($a2 * (255 - (($dst >> 8) & 0xFF)) / 127);
        $b1 = 255 - (int) ($a1 * (255 - ($src & 0xFF)) / 127);
        $b2 = 255 - (int) ($a2 * (255 - ($dst & 0xFF)) / 127);

        $a1 = 127 - $a1;
        $a2 = 127 - $a2;

        return (((int) ($a1 * $a2 / 127) & 0x7F) << 24)
            | (((int) ($r1 * $r2 / 255) & 0xFF) << 16)
            | (((int) ($g1 * $g2 / 255) & 0xFF) << 8)
            | ((int) ($b1 * $b2 / 255) & 0xFF);
    }

    private static function hLine(GdImageState $state, int $y, int $x1, int $x2, int $color): void
    {
        if ($state->thick > 1) {
            $thickhalf = $state->thick >> 1;
            self::fillRectOnState(
                $state,
                $x1,
                $y - $thickhalf,
                $x2,
                $y + $state->thick - $thickhalf - 1,
                $color
            );

            return;
        }
        if ($x2 < $x1) {
            $t = $x2;
            $x2 = $x1;
            $x1 = $t;
        }
        for (; $x1 <= $x2; ++$x1) {
            self::putPixel($state, $x1, $y, $color);
        }
    }

    private static function vLine(GdImageState $state, int $x, int $y1, int $y2, int $color): void
    {
        if ($state->thick > 1) {
            $thickhalf = $state->thick >> 1;
            self::fillRectOnState(
                $state,
                $x - $thickhalf,
                $y1,
                $x + $state->thick - $thickhalf - 1,
                $y2,
                $color
            );

            return;
        }
        if ($y2 < $y1) {
            $t = $y1;
            $y1 = $y2;
            $y2 = $t;
        }
        for (; $y1 <= $y2; ++$y1) {
            self::putPixel($state, $x, $y1, $color);
        }
    }

    /** Thick h/v stroke helper — clipped filled rect (libgd _gdImageFilledHRectangle). */
    private static function fillRectOnState(GdImageState $state, int $x1, int $y1, int $x2, int $y2, int $color): void
    {
        if ($x1 > $x2) {
            $t = $x1;
            $x1 = $x2;
            $x2 = $t;
        }
        if ($y1 > $y2) {
            $t = $y1;
            $y1 = $y2;
            $y2 = $t;
        }
        if ($x1 < 0) {
            $x1 = 0;
        }
        if ($x2 >= $state->width) {
            $x2 = $state->width - 1;
        }
        if ($y1 < 0) {
            $y1 = 0;
        }
        if ($y2 >= $state->height) {
            $y2 = $state->height - 1;
        }
        if ($x1 > $x2 || $y1 > $y2) {
            return;
        }
        for ($y = $y1; $y <= $y2; ++$y) {
            for ($x = $x1; $x <= $x2; ++$x) {
                self::putPixel($state, $x, $y, $color);
            }
        }
    }

    /**
     * libgd clip_1d — clip line segment to [0, maxdim] on the first axis.
     */
    private static function clip1d(int &$x0, int &$y0, int &$x1, int &$y1, int $maxdim): bool
    {
        if ($x0 < 0) {
            if ($x1 < 0) {
                return false;
            }
            $m = ($y1 - $y0) / (float) ($x1 - $x0);
            $y0 -= (int) ($m * $x0);
            $x0 = 0;
            if ($x1 > $maxdim) {
                $y1 += (int) ($m * ($maxdim - $x1));
                $x1 = $maxdim;
            }

            return true;
        }
        if ($x0 > $maxdim) {
            if ($x1 > $maxdim) {
                return false;
            }
            $m = ($y1 - $y0) / (float) ($x1 - $x0);
            $y0 += (int) ($m * ($maxdim - $x0));
            $x0 = $maxdim;
            if ($x1 < 0) {
                $y1 -= (int) ($m * $x1);
                $x1 = 0;
            }

            return true;
        }
        if ($x1 > $maxdim) {
            $m = ($y1 - $y0) / (float) ($x1 - $x0);
            $y1 += (int) ($m * ($maxdim - $x1));
            $x1 = $maxdim;

            return true;
        }
        if ($x1 < 0) {
            $m = ($y1 - $y0) / (float) ($x1 - $x0);
            $y1 -= (int) ($m * $x1);
            $x1 = 0;

            return true;
        }

        return true;
    }

    /**
     * @param array{nchars:int,offset:int,w:int,h:int,data:string}|null $font
     */
    private static function drawChar(?GdImageState $state, ?array $font, int $x, int $y, int $c, int $color): bool
    {
        if (null === $state || !$state->hasRaster() || null === $font) {
            return false;
        }
        if ($c < $font['offset'] || $c >= ($font['offset'] + $font['nchars'])) {
            return true;
        }
        $fw = $font['w'];
        $fh = $font['h'];
        $fline = ($c - $font['offset']) * $fh * $fw;
        $data = $font['data'];
        $cy = 0;
        for ($py = $y; $py < $y + $fh; ++$py) {
            $cx = 0;
            for ($px = $x; $px < $x + $fw; ++$px) {
                if ("\x01" === $data[$fline + $cy * $fw + $cx]) {
                    self::putPixel($state, $px, $py, $color);
                }
                ++$cx;
            }
            ++$cy;
        }

        return true;
    }

    /**
     * libgd gdImageCharUp — 90° CCW glyph blit (php-src; #20460).
     *
     * @param array{nchars:int,offset:int,w:int,h:int,data:string}|null $font
     */
    private static function drawCharUp(?GdImageState $state, ?array $font, int $x, int $y, int $c, int $color): bool
    {
        if (null === $state || !$state->hasRaster() || null === $font) {
            return false;
        }
        if ($c < $font['offset'] || $c >= ($font['offset'] + $font['nchars'])) {
            return true;
        }
        $fw = $font['w'];
        $fh = $font['h'];
        $fline = ($c - $font['offset']) * $fh * $fw;
        $data = $font['data'];
        $xupper = $x + $fh;
        $ylower = $y - $fw;
        $cx = 0;
        $cy = 0;
        for ($py = $y; $py > $ylower; --$py) {
            for ($px = $x; $px < $xupper; ++$px) {
                if ("\x01" === $data[$fline + $cy * $fw + $cx]) {
                    self::putPixel($state, $px, $py, $color);
                }
                ++$cy;
            }
            $cy = 0;
            ++$cx;
        }

        return true;
    }
}
