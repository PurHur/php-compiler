<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for phpc_strpbrk via StrpbrkJitHelper PHP (#14791).
 *
 * Replaces ~69 LOC inline LLVM in JitStrpbrk.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(strpbrk)
 */
final class StringStrpbrk
{
    private const ABI_STRPBRK = 'phpc_strpbrk';

    private const HELPER_PATH = '/ext/standard/StrpbrkJitHelper.php';

    private const STRPBRK_HELPER = 'PHPCompiler\\ext\\standard\\StrpbrkJitHelper::strpbrkArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STRPBRK_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implementStrpbrk($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $haystack, Value $mask): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_STRPBRK),
            $haystack,
            $mask
        );
    }

    private static function implementStrpbrk(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_STRPBRK,
            'strpbrk_bridge_entry',
            [$strPtr, $strPtr],
            $strPtr,
            self::STRPBRK_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14791'
        );
    }
}
