<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringImageTypeToMimeType;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * image_type_to_mime_type() — IMAGETYPE_* to MIME string (ext/standard/image.c, #6063).
 *
 * VM: {@see VmImage::imageTypeToMimeType()}; JIT/AOT: {@see StringImageTypeToMimeType} + {@see ImageTypeToMimeTypeJitHelper}.
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/image.c PHP_FUNCTION(image_type_to_mime_type)
 */
final class image_type_to_mime_type extends Internal
{
    public function __construct()
    {
        parent::__construct('image_type_to_mime_type');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \LogicException('image_type_to_mime_type() accepts exactly one argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $imageType = VmMath::parseIntBuiltinArgForFrame(
            $frame,
            0,
            'image_type_to_mime_type',
            1,
            'image_type'
        );
        $frame->returnVar->string(VmImage::imageTypeToMimeType($imageType));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('image_type_to_mime_type() accepts exactly one argument in this compiler build');
        }
        JitInternalStrictArg::requireInt(
            $context,
            $args[0],
            'image_type_to_mime_type',
            'image_type',
            1
        );
        StringImageTypeToMimeType::ensureLinked($context);
        $mimeStr = $context->builder->call(
            $context->lookupFunction('__compiler_image_type_to_mime_type'),
            JitImageTypeArg::lowerImageType($context, $args[0], 'image_type_to_mime_type')
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $mimeStr
        );

        return $ptr;
    }
}
