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

    private static function parameterName(string $function, int $position): string
    {
        return match ($function) {
            'imagepng' => 1 === $position ? 'image' : 'arg',
            'imagecreatefromstring' => 'image',
            default => 'arg',
        };
    }
}
