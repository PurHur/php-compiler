<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for phpc_strpos/phpc_stripos via StrposJitHelper PHP (#14766).
 *
 * Replaces ~75 LOC inline LLVM in JitStrpos.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(strpos), PHP_FUNCTION(stripos)
 */
final class StringStrpos
{
    public const NOT_FOUND = 0;

    private const ABI_STRPOS = 'phpc_strpos';

    private const ABI_STRIPOS = 'phpc_stripos';

    private const HELPER_PATH = '/ext/standard/StrposJitHelper.php';

    private const STRPOS_HELPER = 'PHPCompiler\\ext\\standard\\StrposJitHelper::strposArgv';

    private const STRIPOS_HELPER = 'PHPCompiler\\ext\\standard\\StrposJitHelper::striposArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STRPOS_HELPER,
        self::STRIPOS_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implementStrpos($context);
        self::implementStripos($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(
        Context $context,
        Value $haystack,
        Value $needle,
        ?Value $offset,
        bool $caseInsensitive = false
    ): Value {
        self::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $off = $offset ?? $i64->constInt(0, false);
        $abi = $caseInsensitive ? self::ABI_STRIPOS : self::ABI_STRPOS;

        return $context->builder->call(
            $context->lookupFunction($abi),
            $haystack,
            $needle,
            $off
        );
    }

    private static function implementStrpos(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_STRPOS,
            'strpos_bridge_entry',
            [$strPtr, $strPtr, $i64],
            $i64,
            self::STRPOS_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14766'
        );
    }

    private static function implementStripos(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_STRIPOS,
            'stripos_bridge_entry',
            [$strPtr, $strPtr, $i64],
            $i64,
            self::STRIPOS_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14766'
        );
    }
}
