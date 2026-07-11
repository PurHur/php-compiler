<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/**
 * JIT/AOT link for __compiler_str_pad via StrPadJitHelper PHP (#14863).
 *
 * Replaces ~190 LOC inline LLVM in JitStrPad.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(str_pad)
 */
final class StringStrPad
{
    private const ABI_STR_PAD = '__compiler_str_pad';

    private const HELPER_PATH = '/ext/standard/StrPadJitHelper.php';

    private const PAD_HELPER = 'PHPCompiler\\ext\\standard\\StrPadJitHelper::padArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PAD_HELPER,
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
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_STR_PAD,
            'str_pad_bridge_entry',
            [$strPtr, $i64, $strPtr, $i64],
            $strPtr,
            self::PAD_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14863'
        );
    }
}
