<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for memcmp()/strncmp() via NCompareJitHelper PHP (#15364, #24410).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} → ensureCompiled (peer StringIdate #24382).
 * Uses dedicated phpc_* ABIs on __string__* — does not replace libc memcmp/strncmp
 * used by {@see \PHPCompiler\VM\VmStringCompare} and {@see LibcExtern}.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}
 */
final class StringNCompare
{
    public const ABI_MEMCMP = 'phpc_memcmp';

    public const ABI_STRNCMP = 'phpc_strncmp';

    private const HELPER_PATH = '/ext/standard/NCompareJitHelper.php';

    private const MEMCMP_HELPER = 'PHPCompiler\\ext\\standard\\NCompareJitHelper::memcmpArgv';

    private const STRNCMP_HELPER = 'PHPCompiler\\ext\\standard\\NCompareJitHelper::strncmpArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::MEMCMP_HELPER,
        self::STRNCMP_HELPER,
    ];

    public static function ensureMemcmpLinked(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_MEMCMP,
            'phpc_memcmp_bridge_entry',
            [$strPtr, $strPtr, $i64],
            $i64,
            self::MEMCMP_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15364'
        );
    }

    public static function ensureStrncmpLinked(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_STRNCMP,
            'phpc_strncmp_bridge_entry',
            [$strPtr, $strPtr, $i64],
            $i64,
            self::STRNCMP_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15364'
        );
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureMemcmpLinked($context);
        self::ensureStrncmpLinked($context);
    }

    public static function invokeMemcmp(
        Context $context,
        Value $left,
        Value $right,
        Value $length
    ): Value {
        self::ensureMemcmpLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_MEMCMP),
            $left,
            $right,
            $length
        );
    }

    public static function invokeStrncmp(
        Context $context,
        Value $left,
        Value $right,
        Value $length
    ): Value {
        self::ensureStrncmpLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_STRNCMP),
            $left,
            $right,
            $length
        );
    }
}
