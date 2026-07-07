<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\ImageTypeToMimeType;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** JIT/AOT helper for image_type_to_mime_type() via ImageTypeToMimeTypeJitHelper PHP. */
final class JitImageTypeToMimeType
{
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
        $mimePtr = ImageTypeToMimeType::invoke($context, $imageType);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $mimePtr
        );

        return $ptr;
    }
}
