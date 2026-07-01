<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for phpc_strrpos/phpc_strripos via StrrposJitHelper PHP (#14752).
 *
 * Replaces ~160 LOC inline LLVM in JitStrrpos.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(strrpos), PHP_FUNCTION(strripos)
 */
final class StringStrrpos
{
    public const NOT_FOUND = 0;

    private const ABI_STRRPOS = 'phpc_strrpos';

    private const ABI_STRRIPOS = 'phpc_strripos';

    private const HELPER_PATH = '/ext/standard/StrrposJitHelper.php';

    private const STRRPOS_HELPER = 'PHPCompiler\\ext\\standard\\StrrposJitHelper::strrposArgv';

    private const STRRIPOS_HELPER = 'PHPCompiler\\ext\\standard\\StrrposJitHelper::strriposArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STRRPOS_HELPER,
        self::STRRIPOS_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implementStrrpos($context);
        self::implementStrripos($context);
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
        $abi = $caseInsensitive ? self::ABI_STRRIPOS : self::ABI_STRRPOS;

        return $context->builder->call(
            $context->lookupFunction($abi),
            $haystack,
            $needle,
            $off
        );
    }

    private static function implementStrrpos(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_STRRPOS,
            'strrpos_bridge_entry',
            [$strPtr, $strPtr, $i64],
            $i64,
            self::STRRPOS_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14752'
        );
    }

    private static function implementStrripos(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_STRRIPOS,
            'strripos_bridge_entry',
            [$strPtr, $strPtr, $i64],
            $i64,
            self::STRRIPOS_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14752'
        );
    }
}
