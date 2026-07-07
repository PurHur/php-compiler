<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/**
 * JIT/AOT link for __compiler_image_type_to_mime_type via ImageTypeToMimeTypeJitHelper PHP (#17126).
 *
 * Replaces inline LLVM select chain in JitImageTypeToMimeType.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmImage}.
 * php-src: ext/standard/image.c — PHP_FUNCTION(image_type_to_mime_type)
 */
final class StringImageTypeToMimeType
{
    private const ABI_IMAGE_TYPE_TO_MIME = '__compiler_image_type_to_mime_type';

    private const HELPER_PATH = '/ext/standard/ImageTypeToMimeTypeJitHelper.php';

    private const LOOKUP_HELPER = 'PHPCompiler\\ext\\standard\\ImageTypeToMimeTypeJitHelper::lookupArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::LOOKUP_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    private static function implement(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_IMAGE_TYPE_TO_MIME,
            'imgmime_bridge_entry',
            [$i64],
            $strPtr,
            self::LOOKUP_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#17126'
        );
    }
}
