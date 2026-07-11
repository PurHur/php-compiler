<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/**
 * JIT/AOT link for __compiler_stats_* via StatsJitHelper PHP (#13792).
 *
 * Replaces ~407-line Welford LLVM; SSOT {@see \PHPCompiler\ext\stats\VmStats}.
 * php-src: ext/stats — PECL descriptive statistics
 */
final class StatsJit
{
    private const ABI_VARIANCE = '__compiler_stats_variance';

    private const ABI_COVARIANCE = '__compiler_stats_covariance';

    private const HELPER_PATH = '/ext/stats/StatsJitHelper.php';

    private const VARIANCE_HELPER = 'PHPCompiler\\ext\\stats\\StatsJitHelper::variance';

    private const COVARIANCE_HELPER = 'PHPCompiler\\ext\\stats\\StatsJitHelper::covariance';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::VARIANCE_HELPER,
        self::COVARIANCE_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        self::ABI_VARIANCE,
        self::ABI_COVARIANCE,
    ];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_VARIANCE);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i1 = $context->getTypeFromString('int1');
        $double = $context->getTypeFromString('double');

        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_VARIANCE,
            'stats_variance_bridge_entry',
            [$htPtr, $i1],
            $double,
            self::VARIANCE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#13792'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_COVARIANCE,
            'stats_covariance_bridge_entry',
            [$htPtr, $htPtr, $i1],
            $double,
            self::COVARIANCE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#13792'
        );
        self::ensureLibcSqrt($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function ensureLibcSqrt(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        $name = 'sqrt';
        if (null === $context->module->getNamedFunction($name)) {
            $fn = $context->module->addFunction(
                $name,
                $context->context->functionType($double, false, $double)
            );
            $context->registerFunction($name, $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null !== $fn) {
                $context->registerFunction($name, $fn);
            }
        }
    }
}
