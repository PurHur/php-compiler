<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_gettimeofday_* via GettimeofdayJitHelper PHP (#13764).
 *
 * Replaces libc gettimeofday LLVM; SSOT {@see \PHPCompiler\ext\standard\VmDate}.
 * php-src: ext/standard/microtimers.c — PHP_FUNCTION(gettimeofday)
 */
final class StringGettimeofday
{
    private const HELPER_PATH = '/ext/standard/GettimeofdayJitHelper.php';

    private const ARRAY_HELPER = 'PHPCompiler\\ext\\standard\\GettimeofdayJitHelper::gettimeofdayArray';

    private const FLOAT_HELPER = 'PHPCompiler\\ext\\standard\\GettimeofdayJitHelper::gettimeofdayFloat';

    private const SEC_HELPER = 'PHPCompiler\\ext\\standard\\GettimeofdayJitHelper::wallClockSec';

    private const USEC_MASKED_HELPER = 'PHPCompiler\\ext\\standard\\GettimeofdayJitHelper::wallClockUsecMasked';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ARRAY_HELPER,
        self::FLOAT_HELPER,
        self::SEC_HELPER,
        self::USEC_MASKED_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $arrayProbe = $context->module->getNamedFunction('__compiler_gettimeofday_array');
        if (null !== $arrayProbe && $arrayProbe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementFloatBridge($context);
        self::implementArrayBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    /**
     * Wall-clock parts shared by uniqid() lowering (tv_sec, tv_usec % $usecMod).
     *
     * @return array{0: Value, 1: Value} i32 sec and masked usec
     */
    public static function readSecUsec(Context $context, int $usecMod = 0): array
    {
        self::ensureLinked($context);
        $i32 = $context->getTypeFromString('int32');
        $sec = $context->builder->call(self::helperFunction($context, self::SEC_HELPER));
        $sec32 = $context->builder->truncOrBitCast($sec, $i32);
        $usecModArg = $i32->constInt(max(0, $usecMod), false);
        $usec = $context->builder->call(
            self::helperFunction($context, self::USEC_MASKED_HELPER),
            $usecModArg
        );
        $usec32 = $context->builder->truncOrBitCast($usec, $i32);

        return [$sec32, $usec32];
    }

    private static function implementFloatBridge(Context $context): void
    {
        $abiName = '__compiler_gettimeofday_float';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $doubleTy = $context->getTypeFromString('double');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($doubleTy, false)
            );

        $entry = $fn->appendBasicBlock('gettimeofday_float_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(self::helperFunction($context, self::FLOAT_HELPER));
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementArrayBridge(Context $context): void
    {
        $abiName = '__compiler_gettimeofday_array';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($htPtr, false)
            );

        $entry = $fn->appendBasicBlock('gettimeofday_array_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(self::helperFunction($context, self::ARRAY_HELPER));
        $ht = $result->typeOf() === $htPtr
            ? $result
            : $context->builder->pointerCast($result, $htPtr);
        $context->builder->returnValue($ht);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after GettimeofdayJitHelper compile (#13764)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        $realPath = \realpath($path) ?: $path;
        $prevSelfHostAot = \getenv('PHP_COMPILER_SELFHOST_AOT');
        if (\function_exists('putenv')) {
            \putenv('PHP_COMPILER_SELFHOST_AOT=0');
        }
        try {
            NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path, $realPath): void {
                $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'GettimeofdayJitHelper.php');
                if (null === $block) {
                    throw new \LogicException('GettimeofdayJitHelper.php parseAndCompile failed (#13764)');
                }
                $jit = new JIT($context);
                $jit->compile($block);
                $context->markJitIncludedFileCompiled($realPath);
            });
        } finally {
            if (\function_exists('putenv')) {
                if (false === $prevSelfHostAot || null === $prevSelfHostAot) {
                    \putenv('PHP_COMPILER_SELFHOST_AOT=');
                } else {
                    \putenv('PHP_COMPILER_SELFHOST_AOT='.$prevSelfHostAot);
                }
            }
        }
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#13764)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (['__compiler_gettimeofday_array', '__compiler_gettimeofday_float'] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringGettimeofday bridge (#13764)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
