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

    public static function colorAllocate(ObjectEntry $image, int $red, int $green, int $blue): int|false
    {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster() || !$state->truecolor) {
            return false;
        }
        if ($red < 0 || $red > 255 || $green < 0 || $green > 255 || $blue < 0 || $blue > 255) {
            return false;
        }

        return ($red << 16) | ($green << 8) | $blue;
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
        $state->pixels[$y * $state->width + $x] = $color;

        return true;
    }

    /**
     * imageline() — Bresenham stroke with libgd clip (php-src ext/gd/libgd/gd.c gdImageLine; #6534).
     */
    public static function line(ObjectEntry $image, int $x1, int $y1, int $x2, int $y2, int $color): bool
    {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return false;
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

        if ($dy <= $dx) {
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
            self::putPixel($state, $x, $y, $color);
            if ((($y2 - $y1) * $ydirflag) > 0) {
                while ($x < $xend) {
                    ++$x;
                    if ($d < 0) {
                        $d += $incr1;
                    } else {
                        ++$y;
                        $d += $incr2;
                    }
                    self::putPixel($state, $x, $y, $color);
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
                    self::putPixel($state, $x, $y, $color);
                }
            }
        } else {
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
            self::putPixel($state, $x, $y, $color);
            if ((($x2 - $x1) * $xdirflag) > 0) {
                while ($y < $yend) {
                    ++$y;
                    if ($d < 0) {
                        $d += $incr1;
                    } else {
                        ++$x;
                        $d += $incr2;
                    }
                    self::putPixel($state, $x, $y, $color);
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
                    self::putPixel($state, $x, $y, $color);
                }
            }
        }

        return true;
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
            $row = $y * $width;
            for ($x = $x1; $x <= $x2; ++$x) {
                $state->pixels[$row + $x] = $color;
            }
        }

        return true;
    }

    /**
     * imagechar() — single glyph from built-in font (php-src gdImageChar; #6534).
     */
    public static function char(ObjectEntry $image, int $font, int $x, int $y, string $char, int $color): bool
    {
        $ch = '' === $char ? 0 : \ord($char[0]);

        return self::drawChar(GdRegistry::state($image), GdFonts::get($font), $x, $y, $ch, $color);
    }

    /**
     * imagestring() — horizontal string from built-in font (php-src gdImageString; #6534).
     */
    public static function string(ObjectEntry $image, int $font, int $x, int $y, string $text, int $color): bool
    {
        $state = GdRegistry::state($image);
        if (null === $state || !$state->hasRaster()) {
            return false;
        }
        $fontData = GdFonts::get($font);
        $len = \strlen($text);
        for ($i = 0; $i < $len; ++$i) {
            self::drawChar($state, $fontData, $x, $y, \ord($text[$i]), $color);
            $x += $fontData['w'];
        }

        return true;
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
            return VmGdPng::encodeRgb($state->width, $state->height, $state->pixels);
        }

        throw new \TypeError('imagepng(): Argument #1 ($image) must be of type GdImage');
    }

    public static function writePngToOutput(Frame $frame, ObjectEntry $image): bool
    {
        OutputBuffer::append(self::encodedBytes($image), $frame->scriptPath ?: null);

        return true;
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
        if (null === $state || !$state->hasRaster()) {
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
            'imagepng' => 1 === $position ? 'image' : 'arg',
            'imagecreatefromstring' => 'image',
            'imagesx', 'imagesy' => 'image',
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
            'imagestring', 'imagechar' => match ($position) {
                1 => 'image',
                2 => 'font',
                3 => 'x',
                4 => 'y',
                5 => 'char' === \substr($function, -4) ? 'char' : 'string',
                6 => 'color',
                default => 'arg',
            },
            default => 'arg',
        };
    }

    private static function putPixel(GdImageState $state, int $x, int $y, int $color): void
    {
        if ($x < 0 || $y < 0 || $x >= $state->width || $y >= $state->height) {
            return;
        }
        $state->pixels[$y * $state->width + $x] = $color;
    }

    private static function hLine(GdImageState $state, int $y, int $x1, int $x2, int $color): void
    {
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
        if ($y2 < $y1) {
            $t = $y1;
            $y1 = $y2;
            $y2 = $t;
        }
        for (; $y1 <= $y2; ++$y1) {
            self::putPixel($state, $x, $y1, $color);
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
}
