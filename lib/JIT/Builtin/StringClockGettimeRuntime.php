<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_clock_gettime_assoc via ClockGettimeJitHelper (#11624).
 */
final class StringClockGettimeRuntime
{
    private const HELPER_PATH = '/ext/standard/ClockGettimeJitHelper.php';

    private const ASSOC_HELPER = 'PHPCompiler\\ext\\standard\\ClockGettimeJitHelper::assoc';

    public static function ensureLinked(Context $context): void
    {
        self::implementAssocBridge($context);
    }

    private static function implementAssocBridge(Context $context): void
    {
        $abiName = '__compiler_clock_gettime_assoc';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($htPtr, false, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('clock_gettime_assoc_entry');
        $context->builder->positionAtEnd($entry);
        $clockId = $fn->getParam(0);
        $clockI64 = $clockId->typeOf() === $i64
            ? $clockId
            : $context->builder->zExt($clockId, $i64);
        $resultRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::ASSOC_HELPER),
            [$clockI64]
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
            throw new \LogicException($logical.' missing after ClockGettimeJitHelper compile (#11624)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $lc = \strtolower(self::ASSOC_HELPER);
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ClockGettimeJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ClockGettimeJitHelper.php parseAndCompile failed (#11624)');
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
            throw new \LogicException($lc.' was not compiled for JIT (#11624)');
        }
    }
}
