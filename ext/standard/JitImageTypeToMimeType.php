<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for image_type_to_mime_type() (ext/standard/image.c; #6063). */
final class JitImageTypeToMimeType
{
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

    public static function invoke(Context $context, JITVariable $imageTypeArg): Value
    {
        JitInternalStrictArg::requireInt(
            $context,
            $imageTypeArg,
            'image_type_to_mime_type',
            'image_type',
            1
        );
        $imageType = JitImageTypeArg::lowerImageType(
            $context,
            $imageTypeArg,
            'image_type_to_mime_type'
        );
        $mimePtr = self::lookupMime($context, $imageType);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $mimePtr
        );

        return $ptr;
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
