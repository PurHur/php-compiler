<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for umask() via UmaskJitHelper PHP (#15497).
 *
 * Replaces libc umask(2) LLVM in ext/standard/JitUmask.php.
 * SSOT: {@see \PHPCompiler\ext\standard\UmaskJitHelper}.
 * php-src: ext/standard/filestat.c — PHP_FUNCTION(umask)
 */
final class StringUmask
{
    private const ABI_GET = 'phpc_umask_get';

    private const ABI_SET = 'phpc_umask_set';

    private const HELPER_PATH = '/ext/standard/UmaskJitHelper.php';

    private const GET_HELPER = 'PHPCompiler\\ext\\standard\\UmaskJitHelper::getArgv';

    private const SET_HELPER = 'PHPCompiler\\ext\\standard\\UmaskJitHelper::setArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GET_HELPER,
        self::SET_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implementGet($context);
        self::implementSet($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, ?Value $maskLong): Value
    {
        self::ensureLinked($context);
        if (null === $maskLong) {
            return $context->builder->call($context->lookupFunction(self::ABI_GET));
        }

        $maskI64 = JitNestedHelperCoerce::scalarToI64($context, $maskLong, $maskLong->typeOf());

        return $context->builder->call($context->lookupFunction(self::ABI_SET), $maskI64);
    }

    private static function implementGet(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_GET,
            'umask_get_bridge_entry',
            [],
            $i64,
            self::GET_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15497'
        );
    }

    private static function implementSet(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_SET,
            'umask_set_bridge_entry',
            [$i64],
            $i64,
            self::SET_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15497'
        );
    }
}
