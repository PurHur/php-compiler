<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_filter_parse_boolean_string via FilterBooleanJitHelper PHP (#9858).
 *
 * Replaces former ~213-line LLVM token parser with thin bridge into {@see VmFilter} SSOT.
 * php-src: ext/filter/logical_filters.c — php_filter_boolean
 */
final class StringFilterBoolean
{
    private const HELPER_PATH = '/ext/filter/FilterBooleanJitHelper.php';

    private const PARSE_HELPER = 'PHPCompiler\\ext\\filter\\FilterBooleanJitHelper::parseString';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PARSE_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_filter_parse_boolean_string',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_filter_parse_boolean_string');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementParseBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementParseBridge(Context $context): void
    {
        $abiName = '__compiler_filter_parse_boolean_string';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('filter_bool_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::PARSE_HELPER),
            $fn->getParam(0)
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after FilterBooleanJitHelper compile (#9858)');
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
        $prevSelfHostAot = \getenv('PHP_COMPILER_SELFHOST_AOT');
        if (\function_exists('putenv')) {
            \putenv('PHP_COMPILER_SELFHOST_AOT=0');
        }
        try {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'FilterBooleanJitHelper.php');
            if (null === $block) {
                throw new \LogicException('FilterBooleanJitHelper.php parseAndCompile failed (#9858)');
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
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9858)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringFilterBoolean bridge (#9858)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
