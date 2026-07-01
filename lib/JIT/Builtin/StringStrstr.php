<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for phpc_strstr/phpc_stristr via StrstrJitHelper PHP (#14778).
 *
 * Replaces ~178 LOC inline LLVM in JitStrstr.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(strstr), PHP_FUNCTION(stristr)
 */
final class StringStrstr
{
    private const ABI_STRSTR = 'phpc_strstr';

    private const ABI_STRISTR = 'phpc_stristr';

    private const HELPER_PATH = '/ext/standard/StrstrJitHelper.php';

    private const STRSTR_HELPER = 'PHPCompiler\\ext\\standard\\StrstrJitHelper::strstrArgv';

    private const STRISTR_HELPER = 'PHPCompiler\\ext\\standard\\StrstrJitHelper::stristrArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STRSTR_HELPER,
        self::STRISTR_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implementStrstr($context);
        self::implementStristr($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(
        Context $context,
        Value $haystack,
        Value $needle,
        Value $beforeNeedle,
        bool $caseInsensitive = false
    ): Value {
        self::ensureLinked($context);
        $abi = $caseInsensitive ? self::ABI_STRISTR : self::ABI_STRSTR;

        return $context->builder->call(
            $context->lookupFunction($abi),
            $haystack,
            $needle,
            $beforeNeedle
        );
    }

    private static function implementStrstr(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i8 = $context->getTypeFromString('int8');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_STRSTR,
            'strstr_bridge_entry',
            [$strPtr, $strPtr, $i8],
            $strPtr,
            self::STRSTR_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14778'
        );
    }

    private static function implementStristr(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i8 = $context->getTypeFromString('int8');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_STRISTR,
            'stristr_bridge_entry',
            [$strPtr, $strPtr, $i8],
            $strPtr,
            self::STRISTR_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14778'
        );
    }
}
