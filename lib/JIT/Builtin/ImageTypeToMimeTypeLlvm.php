<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmImage;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM implementation of __phpc_jit_image_type_to_mime_type for user-script standalone AOT (#17126).
 *
 * Nested ImageTypeToMimeTypeJitHelper segfaults in minimal standalone init; restore pre-#17126 LLVM.
 * php-src: ext/standard/image.c — PHP_FUNCTION(image_type_to_mime_type)
 */
final class ImageTypeToMimeTypeLlvm
{
    private const ABI = '__phpc_jit_image_type_to_mime_type';

    /** @var array<int, string> */
    private const MIME_TYPES = [
        VmImage::IMAGETYPE_GIF => 'image/gif',
        VmImage::IMAGETYPE_JPEG => 'image/jpeg',
        VmImage::IMAGETYPE_PNG => 'image/png',
        VmImage::IMAGETYPE_SWF => 'application/x-shockwave-flash',
        VmImage::IMAGETYPE_SWC => 'application/x-shockwave-flash',
        VmImage::IMAGETYPE_PSD => 'image/psd',
        VmImage::IMAGETYPE_BMP => 'image/bmp',
        VmImage::IMAGETYPE_TIFF_II => 'image/tiff',
        VmImage::IMAGETYPE_TIFF_MM => 'image/tiff',
        VmImage::IMAGETYPE_IFF => 'image/iff',
        VmImage::IMAGETYPE_WBMP => 'image/vnd.wap.wbmp',
        VmImage::IMAGETYPE_JPC => 'application/octet-stream',
        VmImage::IMAGETYPE_JP2 => 'image/jp2',
        VmImage::IMAGETYPE_XBM => 'image/xbm',
        VmImage::IMAGETYPE_ICO => 'image/vnd.microsoft.icon',
        VmImage::IMAGETYPE_WEBP => 'image/webp',
        VmImage::IMAGETYPE_AVIF => 'image/avif',
        VmImage::IMAGETYPE_HEIF => 'image/heif',
    ];

    private const MIME_TYPE_UNKNOWN = 'application/octet-stream';

    public static function implement(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $i64);
        $fn = $context->module->getNamedFunction(self::ABI);
        if (null === $fn) {
            $fn = $context->module->addFunction(self::ABI, $ft);
        }
        if (JitVmHelperLink::hasNamedBridgeEntry($fn, 'image_type_to_mime_type_llvm_main')) {
            $context->registerFunction(self::ABI, $fn);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $entry = $fn->appendBasicBlock('image_type_to_mime_type_llvm_main');
        $context->builder->positionAtEnd($entry);
        $result = self::lookupMime($context, $fn->getParam(0));
        $context->builder->returnValue($result);
        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function lookupMime(Context $context, Value $imageType): Value
    {
        $result = self::literalString($context, self::MIME_TYPE_UNKNOWN);
        $i64 = $context->getTypeFromString('int64');
        foreach (self::MIME_TYPES as $type => $mime) {
            $eq = $context->builder->icmp(
                Builder::INT_EQ,
                $imageType,
                $i64->constInt($type, false)
            );
            $candidate = self::literalString($context, $mime);
            $result = $context->builder->select($eq, $candidate, $result);
        }

        return $result;
    }

    private static function literalString(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $cstr = $context->builder->pointerCast($context->constantFromString($text), $i8p);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($text), false),
            $cstr
        );
    }
}
