<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for image_type_to_mime_type() via ImageTypeToMimeTypeJitHelper PHP (#17126).
 *
 * Replaces inline LLVM select chain formerly in ext/standard/JitImageTypeToMimeType.php.
 * SSOT: {@see \PHPCompiler\ext\standard\ImageTypeToMimeTypeJitHelper}.
 * php-src: ext/standard/image.c — PHP_FUNCTION(image_type_to_mime_type)
 */
final class ImageTypeToMimeType
{
    private const ABI = '__phpc_jit_image_type_to_mime_type';

    private const HELPER_PATH = '/ext/standard/ImageTypeToMimeTypeJitHelper.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\standard\\ImageTypeToMimeTypeJitHelper::mimeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::INVOKE_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $imageType): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $imageType
        );
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && JitVmHelperLink::hasNamedBridgeEntry($probe, 'image_type_to_mime_type_bridge_entry')) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            'image_type_to_mime_type_bridge_entry',
            [$i64],
            $strPtr,
            self::INVOKE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#17126'
        );
    }
}
