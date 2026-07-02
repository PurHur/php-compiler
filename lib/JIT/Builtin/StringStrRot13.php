<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/**
 * JIT/AOT link for __compiler_str_rot13 via StrRot13JitHelper PHP (#14896).
 *
 * Replaces ~107 LOC inline LLVM in JitStrRot13.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(str_rot13)
 */
final class StringStrRot13
{
    private const ABI_STR_ROT13 = '__compiler_str_rot13';

    private const HELPER_PATH = '/ext/standard/StrRot13JitHelper.php';

    private const ROT13_HELPER = 'PHPCompiler\\ext\\standard\\StrRot13JitHelper::rot13Argv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ROT13_HELPER,
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
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_STR_ROT13,
            'str_rot13_bridge_entry',
            [$strPtr],
            $strPtr,
            self::ROT13_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14896'
        );
    }
}
