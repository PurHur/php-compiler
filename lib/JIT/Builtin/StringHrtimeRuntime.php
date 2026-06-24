<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_hrtime_ns / __compiler_hrtime_pair via HrtimeJitHelper (#9182).
 */
final class StringHrtimeRuntime
{
    private const NS_PER_SEC = 1_000_000_000;

    private const HELPER_PATH = '/ext/standard/HrtimeJitHelper.php';

    private const PAIR_HELPER = 'PHPCompiler\\ext\\standard\\HrtimeJitHelper::pair';

    public static function ensureLinked(Context $context): void
    {
        self::implementNsBridge($context);
        self::implementPairBridge($context);
    }

    private static function implementPairBridge(Context $context): void
    {
        self::implementZeroArgBridge($context, '__compiler_hrtime_pair', self::PAIR_HELPER);
    }

    private static function implementNsBridge(Context $context): void
    {
        $abiName = '__compiler_hrtime_ns';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);

        $fn = null !== $probe
            ? $probe
            : $context->lookupFunction($abiName);

        $entry = $fn->appendBasicBlock('hrtime_ns_entry');
        $context->builder->positionAtEnd($entry);

        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::PAIR_HELPER),
            []
        );
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw);
        $sec = $context->builder->call(
            $context->lookupFunction('__hashtable__readLongAt'),
            $ht,
            $sizeT->constInt(0, false)
        );
        $nsec = $context->builder->call(
            $context->lookupFunction('__hashtable__readLongAt'),
            $ht,
            $sizeT->constInt(1, false)
        );
        $total = $context->builder->add(
            $context->builder->mul($sec, $i64->constInt(self::NS_PER_SEC, false)),
            $nsec
        );
        $context->builder->returnValue($total);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementZeroArgBridge(
        Context $context,
        string $abiName,
        string $helperLogical
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);

        $fn = null !== $probe
            ? $probe
            : $context->lookupFunction($abiName);

        $entry = $fn->appendBasicBlock('hrtime_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $resultRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helperLogical),
            []
        );
        $result = JitNestedHelperCoerce::coerceToHashtablePtr($context, $resultRaw);
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after HrtimeJitHelper compile (#9182)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $lc = \strtolower(self::PAIR_HELPER);
        if (isset($context->functions[$lc])) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        $prevSelfHostAot = \getenv('PHP_COMPILER_SELFHOST_AOT');
        if (\function_exists('putenv')) {
            \putenv('PHP_COMPILER_SELFHOST_AOT=0');
        }
        try {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'HrtimeJitHelper.php');
            if (null === $block) {
                throw new \LogicException('HrtimeJitHelper.php parseAndCompile failed (#9182)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        } finally {
            if (\function_exists('putenv')) {
                if (false === $prevSelfHostAot || null === $prevSelfHostAot) {
                    \putenv('PHP_COMPILER_SELFHOST_AOT=');
                } else {
                    \putenv('PHP_COMPILER_SELFHOST_AOT='.$prevSelfHostAot);
                }
            }
        }
        if (!isset($context->functions[$lc])) {
            throw new \LogicException($lc.' was not compiled for JIT (#9182)');
        }
    }
}
