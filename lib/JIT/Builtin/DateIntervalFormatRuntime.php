<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_date_interval_format via DateIntervalFormatJitHelper PHP (#9499).
 *
 * Standalone LLVM quarantine: {@see DateIntervalFormatStandaloneLlvm}
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_interval_format)
 */
final class DateIntervalFormatRuntime
{
    private const HELPER_PATH = '/ext/standard/DateIntervalFormatJitHelper.php';

    private const FORMAT_HELPER = 'PHPCompiler\\ext\\standard\\DateIntervalFormatJitHelper::formatFromScalars';

    private const ABI_NAME = '__compiler_date_interval_format';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FORMAT_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            DateIntervalFormatStandaloneLlvm::implement($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementFormatBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementFormatBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $dbl = $context->getTypeFromString('double');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType(
            $strPtr,
            false,
            $i64,
            $i64,
            $i64,
            $i64,
            $i64,
            $i64,
            $dbl,
            $i64,
            $i64,
            $i64,
            $strPtr
        );
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_NAME, $ft);

        $entry = $fn->appendBasicBlock('di_fmt_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::FORMAT_HELPER),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2),
            $fn->getParam(3),
            $fn->getParam(4),
            $fn->getParam(5),
            $fn->getParam(6),
            $fn->getParam(7),
            $fn->getParam(8),
            $fn->getParam(9),
            $fn->getParam(10)
        );
        $context->builder->returnValue($result);
        $context->registerFunction(self::ABI_NAME, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after DateIntervalFormatJitHelper compile (#9499)');
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
                $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'DateIntervalFormatJitHelper.php');
                if (null === $block) {
                    throw new \LogicException('DateIntervalFormatJitHelper.php parseAndCompile failed (#9499)');
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
                throw new \LogicException($lc.' was not compiled for JIT (#9499)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_NAME);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_NAME.' missing after DateIntervalFormatRuntime bridge (#11518)');
        }
        $context->registerFunction(self::ABI_NAME, $fn);
    }
}
