<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/**
 * JIT/AOT link for __phpc_char_in_mask via CharInMaskJitHelper PHP (#14908).
 *
 * Replaces ~109 LOC inline LLVM char-mask scan.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}
 * php-src: ext/standard/string.c — php_charmask()
 */
final class StringTrimMask
{
    private const ABI_CHAR_IN_MASK = '__phpc_char_in_mask';

    private const HELPER_PATH = '/ext/standard/CharInMaskJitHelper.php';

    private const CHAR_IN_MASK_HELPER = 'PHPCompiler\\ext\\standard\\CharInMaskJitHelper::charInMaskArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CHAR_IN_MASK_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $strPtrTy = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_CHAR_IN_MASK,
            'char_in_mask_bridge_entry',
            [$i32, $strPtrTy],
            $i32,
            self::CHAR_IN_MASK_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14908'
        );
    }
}
