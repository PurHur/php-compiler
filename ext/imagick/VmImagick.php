<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imagick;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Imagick VM implementation — php-src ext/imagick/imagick_class.c (#6235).
 */
final class VmImagick
{
    public const CLASS_LC = 'imagick';

    /** @var array<int, array{path: string, filename: string}> */
    private static array $state = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('Imagick');
        $entry->isInternal = true;

        $entry->constructor = new ImagickConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;

        $methodMap = [
            'readimage' => ImagickReadImage::class,
            'writeimage' => ImagickWriteImage::class,
            'getimagewidth' => ImagickGetImageWidth::class,
            'getimageheight' => ImagickGetImageHeight::class,
            'resizeimage' => ImagickResizeImage::class,
        ];
        foreach ($methodMap as $lc => $class) {
            /** @var ImagickClassMethod $method */
            $method = new $class();
            $entry->methods[$lc] = $method;
            $entry->methodVisibility[$lc] = $pub;
        }

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function initObject(ObjectEntry $object): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_imagick_');
        if (false === $tmp) {
            throw new \Exception('Failed to create temporary Imagick workspace');
        }
        @unlink($tmp);
        self::$state[$object->id] = [
            'path' => $tmp,
            'filename' => '',
        ];
    }

    public static function requireReceiver(Variable $var, string $label): ObjectEntry
    {
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError($label.' must be called on Imagick');
        }
        $object = $var->toObject();
        if (!isset(self::$state[$object->id])) {
            throw new \TypeError($label.' must be called on Imagick');
        }

        return $object;
    }

    public static function coerceStringArg(Variable $var, string $label, int $index, string $paramName): string
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_STRING !== $resolved->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type string, %s given',
                $label,
                $index,
                $paramName,
                self::typeLabel($resolved)
            ));
        }

        return $resolved->toString();
    }

    public static function coerceIntArg(Variable $var, string $label, int $index, string $paramName, int $default = 0): int
    {
        if (Variable::TYPE_UNDEFINED === $var->type) {
            return $default;
        }
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $resolved->type && Variable::TYPE_FLOAT !== $resolved->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type int, %s given',
                $label,
                $index,
                $paramName,
                self::typeLabel($resolved)
            ));
        }

        return (int) $resolved->toInt();
    }

    public static function coerceFloatArg(Variable $var, string $label, int $index, string $paramName, float $default = 1.0): float
    {
        if (Variable::TYPE_UNDEFINED === $var->type) {
            return $default;
        }
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $resolved->type && Variable::TYPE_FLOAT !== $resolved->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type float, %s given',
                $label,
                $index,
                $paramName,
                self::typeLabel($resolved)
            ));
        }

        return (float) $resolved->toFloat();
    }

    public static function coerceBoolArg(Variable $var, string $label, int $index, string $paramName, bool $default = false): bool
    {
        if (Variable::TYPE_UNDEFINED === $var->type) {
            return $default;
        }
        $resolved = $var->resolveIndirect();

        return (bool) $resolved->toBool();
    }

    /** @return array{path: string, filename: string} */
    public static function slot(ObjectEntry $object): array
    {
        return self::$state[$object->id];
    }

    public static function setSlot(ObjectEntry $object, array $slot): void
    {
        self::$state[$object->id] = $slot;
    }

    public static function readImage(ObjectEntry $object, string $filename): bool
    {
        if (!is_file($filename) || !is_readable($filename)) {
            return false;
        }
        $slot = self::slot($object);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $target = '' !== $ext ? $slot['path'].'.'.$ext : $slot['path'];
        if (!VmImagickNative::copyFile($filename, $target)) {
            return false;
        }
        if ($target !== $slot['path'] && is_file($slot['path'])) {
            @unlink($slot['path']);
        }
        $slot['path'] = $target;
        $slot['filename'] = $filename;
        self::setSlot($object, $slot);

        return true;
    }

    public static function writeImage(ObjectEntry $object, string $filename): bool
    {
        $slot = self::slot($object);
        if (!is_file($slot['path'])) {
            return false;
        }

        return VmImagickNative::copyFile($slot['path'], $filename);
    }

    public static function getImageWidth(ObjectEntry $object): int
    {
        $dims = VmImagickNative::identifyDimensions(self::slot($object)['path']);

        return false === $dims ? 0 : $dims['width'];
    }

    public static function getImageHeight(ObjectEntry $object): int
    {
        $dims = VmImagickNative::identifyDimensions(self::slot($object)['path']);

        return false === $dims ? 0 : $dims['height'];
    }

    public static function resizeImage(
        ObjectEntry $object,
        int $columns,
        int $rows,
        int $filter,
        float $blur,
        bool $bestfit
    ): bool {
        $slot = self::slot($object);
        if (!is_file($slot['path'])) {
            return false;
        }
        $tmp = $slot['path'].'.resize';
        if (!VmImagickNative::resizeFile($slot['path'], $tmp, $columns, $rows, $filter, $blur, $bestfit)) {
            return false;
        }
        if (!@rename($tmp, $slot['path'])) {
            if (!VmImagickNative::copyFile($tmp, $slot['path'])) {
                @unlink($tmp);

                return false;
            }
            @unlink($tmp);
        }

        return true;
    }

    private static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }
}
