<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for binary-safe substring search via FindSubstrJitHelper PHP (#15287).
 *
 * Replaces ~250 LOC inline LLVM in ext/standard/JitStringSearch.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — zend_memnstr
 */
final class StringFindSubstr
{
    public const ABI_FIND_OFFSET = 'phpc_find_substr_offset';

    public const ABI_FIND_OFFSET_CI = 'phpc_find_substr_offset_ci';

    private const HELPER_PATH = '/ext/standard/FindSubstrJitHelper.php';

    private const FIND_OFFSET_HELPER = 'PHPCompiler\\ext\\standard\\FindSubstrJitHelper::findOffsetArgv';

    private const FIND_OFFSET_CI_HELPER = 'PHPCompiler\\ext\\standard\\FindSubstrJitHelper::findOffsetCiArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FIND_OFFSET_HELPER,
        self::FIND_OFFSET_CI_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implementFindOffset($context);
        self::implementFindOffsetCi($context);
    }

    public static function ensureCiLinked(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invokeFindOffsetI32(
        Context $context,
        Value $haystack,
        Value $needle,
        Value $offset,
        bool $caseInsensitive
    ): Value {
        self::ensureLinked($context);
        $abi = $caseInsensitive ? self::ABI_FIND_OFFSET_CI : self::ABI_FIND_OFFSET;

        return $context->builder->call(
            $context->lookupFunction($abi),
            $haystack,
            $needle,
            $offset
        );
    }

    private static function implementFindOffset(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_FIND_OFFSET,
            'find_substr_offset_bridge_entry',
            [$strPtr, $strPtr, $i64],
            $i32,
            self::FIND_OFFSET_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15287'
        );
    }

    private static function implementFindOffsetCi(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_FIND_OFFSET_CI,
            'find_substr_offset_ci_bridge_entry',
            [$strPtr, $strPtr, $i64],
            $i32,
            self::FIND_OFFSET_CI_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15287'
        );
    }
}
