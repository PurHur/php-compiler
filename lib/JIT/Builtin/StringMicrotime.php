<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_microtime_* via MicrotimeJitHelper PHP (#9181).
 *
 * Replaces gettimeofday/snprintf LLVM; SSOT {@see \PHPCompiler\ext\standard\VmDate}.
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(microtime)
 */
final class StringMicrotime
{
    private const HELPER_PATH = '/ext/standard/MicrotimeJitHelper.php';

    private const FLOAT_HELPER = 'PHPCompiler\\ext\\standard\\MicrotimeJitHelper::microtimeFloat';

    private const STRING_HELPER = 'PHPCompiler\\ext\\standard\\MicrotimeJitHelper::microtimeString';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FLOAT_HELPER,
        self::STRING_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $strProbe = $context->module->getNamedFunction('__compiler_microtime_string');
        if (null !== $strProbe && $strProbe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementFloatBridge($context);
        self::implementStringBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementFloatBridge(Context $context): void
    {
        $abiName = '__compiler_microtime_float';
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

        $entry = $fn->appendBasicBlock('microtime_float_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(self::helperFunction($context, self::FLOAT_HELPER));
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementStringBridge(Context $context): void
    {
        $abiName = '__compiler_microtime_string';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($strPtr, false)
            );

        $entry = $fn->appendBasicBlock('microtime_string_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(self::helperFunction($context, self::STRING_HELPER));
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after MicrotimeJitHelper compile (#9181)');
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
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'MicrotimeJitHelper.php');
            if (null === $block) {
                throw new \LogicException('MicrotimeJitHelper.php parseAndCompile failed (#9181)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9181)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (['__compiler_microtime_string', '__compiler_microtime_float'] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringMicrotime bridge (#9181)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
