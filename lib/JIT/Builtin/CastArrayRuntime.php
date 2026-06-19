<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for (array) cast bool branch via CastJitHelper PHP (#10046).
 *
 * php-src: Zend/zend_operators.c — convert_to_array
 * SSOT: {@see \PHPCompiler\VM\CastSupport}, {@see \PHPCompiler\VM\CastJitHelper}
 */
final class CastArrayRuntime
{
    private const HELPER_PATH = '/lib/VM/CastJitHelper.php';

    private const BOOL_EMPTY_HELPER = 'PHPCompiler\\VM\\CastJitHelper::boolYieldsEmptyArray';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::BOOL_EMPTY_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__cast__boolYieldsEmptyArray');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__cast__boolYieldsEmptyArray', $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementBoolBridge($context);
        $context->builder->clearInsertionPosition();
    }

    public static function callBoolYieldsEmptyArray(Context $context, Value $boolI1): Value
    {
        self::ensureLinked($context);
        $fn = $context->lookupFunction('__cast__boolYieldsEmptyArray');
        $i1 = $context->getTypeFromString('int1');
        $boolArg = $context->builder->trunc($boolI1, $i1);

        return $context->builder->call($fn, $boolArg);
    }

    private static function implementBoolBridge(Context $context): void
    {
        $abiName = '__cast__boolYieldsEmptyArray';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i1 = $context->getTypeFromString('int1');
        $ft = $context->context->functionType($i1, false, $i1);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('cast_bool_empty_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::BOOL_EMPTY_HELPER),
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
            throw new \LogicException($logical.' missing after CastJitHelper compile (#10046)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'CastJitHelper.php');
            if (null === $block) {
                throw new \LogicException('CastJitHelper.php parseAndCompile failed (#10046)');
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
                throw new \LogicException($lc.' was not compiled for JIT (#10046)');
            }
        }
    }
}
