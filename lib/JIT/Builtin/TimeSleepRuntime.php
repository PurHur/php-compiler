<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_time_nanosleep / __compiler_time_sleep_until via SleepJitHelper (#9378).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(time_nanosleep), time_sleep_until
 */
final class TimeSleepRuntime
{
    private const HELPER_PATH = '/ext/standard/SleepJitHelper.php';

    private const NANOSLEEP_HELPER = 'PHPCompiler\\ext\\standard\\SleepJitHelper::timeNanosleep';

    private const UNTIL_HELPER = 'PHPCompiler\\ext\\standard\\SleepJitHelper::timeSleepUntil';

    public static function ensureLinked(Context $context): void
    {
        self::implementNanosleepBridge($context);
        self::implementUntilBridge($context);
    }

    private static function implementNanosleepBridge(Context $context): void
    {
        self::implementBridge(
            $context,
            '__compiler_time_nanosleep',
            self::NANOSLEEP_HELPER,
            2
        );
    }

    private static function implementUntilBridge(Context $context): void
    {
        self::implementBridge(
            $context,
            '__compiler_time_sleep_until',
            self::UNTIL_HELPER,
            1
        );
    }

    private static function implementBridge(
        Context $context,
        string $abiName,
        string $helperLogical,
        int $paramCount
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

        self::emitBridge($context, $fn, self::helperFunction($context, $helperLogical), $paramCount);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function emitBridge(
        Context $context,
        LlvmFunction $fn,
        LlvmFunction $helper,
        int $paramCount
    ): void {
        $entry = $fn->appendBasicBlock('sleep_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $args = [];
        for ($i = 0; $i < $paramCount; ++$i) {
            $args[] = $fn->getParam($i);
        }
        $result = $context->builder->call($helper, ...$args);

        $i32 = $context->getTypeFromString('int32');
        $context->builder->returnValue($context->builder->zExt($result, $i32));
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after SleepJitHelper compile (#9378)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $needed = [\strtolower(self::NANOSLEEP_HELPER), \strtolower(self::UNTIL_HELPER)];
        $missing = false;
        foreach ($needed as $lc) {
            if (!isset($context->functions[$lc])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        $prevSelfHostAot = \getenv('PHP_COMPILER_SELFHOST_AOT');
        if (\function_exists('putenv')) {
            \putenv('PHP_COMPILER_SELFHOST_AOT=0');
        }
        try {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'SleepJitHelper.php');
            if (null === $block) {
                throw new \LogicException('SleepJitHelper.php parseAndCompile failed (#9378)');
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
        foreach ($needed as $lc) {
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9378)');
            }
        }
    }
}
