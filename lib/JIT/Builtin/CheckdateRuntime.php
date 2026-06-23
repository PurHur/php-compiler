<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_checkdate via CheckdateJitHelper PHP (#9242).
 *
 * Replaces duplicated Gregorian calendar LLVM. SSOT: {@see \PHPCompiler\ext\standard\VmCheckdate}.
 * php-src: ext/standard/datetime.c PHP_FUNCTION(checkdate)
 */
final class CheckdateRuntime
{
    private const HELPER_PATH = '/ext/standard/VmCheckdate.php';

    private const CHECKDATE_HELPER = 'PHPCompiler\\ext\\standard\\VmCheckdate::validate';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_checkdate');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_checkdate', $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $ft = $context->context->functionType($i1, false, $i64, $i64, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__compiler_checkdate', $ft);

        self::ensureJitHelperCompiled($context);

        $entry = $fn->appendBasicBlock('checkdate_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2)
        );
        $context->builder->returnValue($result);
        $context->registerFunction('__compiler_checkdate', $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower(self::CHECKDATE_HELPER);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException(self::CHECKDATE_HELPER.' missing after CheckdateJitHelper compile (#9242)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $lc = \strtolower(self::CHECKDATE_HELPER);
        if (isset($context->functions[$lc])) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        $envKeys = [
            'PHP_COMPILER_SELFHOST_AOT',
            'PHP_COMPILER_EMIT_HELPER_LINK',
            'PHP_COMPILER_M3_EMIT_TU',
            'PHP_COMPILER_M3_COMPILE_DRIVER',
        ];
        $prevEnv = [];
        if (\function_exists('putenv')) {
            foreach ($envKeys as $key) {
                $prevEnv[$key] = \getenv($key);
                \putenv($key.'=0');
            }
        }
        $savedBuilder = $context->builder;
        $savedActive = $context->activeFunction;
        $restoreBlock = self::captureInsertBlock($context);
        try {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'VmCheckdate.php');
            if (null === $block) {
                throw new \LogicException('VmCheckdate.php parseAndCompile failed (#9242)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        } finally {
            $context->builder = $savedBuilder;
            self::restoreInsertBlock($context, $restoreBlock);
            $context->activeFunction = $savedActive;
            if (\function_exists('putenv')) {
                foreach ($prevEnv as $key => $val) {
                    if (false === $val || null === $val) {
                        \putenv($key.'=');
                    } else {
                        \putenv($key.'='.$val);
                    }
                }
            }
        }
        if (!isset($context->functions[$lc])) {
            throw new \LogicException(self::CHECKDATE_HELPER.' was not compiled for JIT (#9242)');
        }
    }

    private static function captureInsertBlock(Context $context): ?\PHPLLVM\BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?\PHPLLVM\BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
