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
        GdRegistry::attach($entry, new GdImageState($encoded, $imageType));

        return $entry;
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

        return $state->encoded;
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
