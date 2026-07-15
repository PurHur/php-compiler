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
        $pixels = $state->pixels;
        $pixels[$y * $state->width + $x] = $color;
        $state->pixels = $pixels;

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
            default => 'arg',
        };
    }
}
