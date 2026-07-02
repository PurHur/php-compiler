<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for acos() via AcosJitHelper PHP (#15141).
 *
 * Replaces libc `acos` LLVM lookup in ext/standard/acos.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmMath}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(acos)
 */
final class MathAcos
{
    private const ABI_ACOS = 'phpc_acos';

    private const HELPER_PATH = '/ext/standard/AcosJitHelper.php';

    private const ACOS_HELPER = 'PHPCompiler\\ext\\standard\\AcosJitHelper::acosArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ACOS_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_ACOS),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_ACOS,
            'acos_bridge_entry',
            [$double],
            $double,
            self::ACOS_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15141'
        );
    }
}
