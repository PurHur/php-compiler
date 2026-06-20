<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for ?? branch tests via CoalesceJitHelper PHP (#10171).
 *
 * SSOT: {@see \PHPCompiler\VM\CoalesceJitHelper}
 */
final class CoalesceRuntime
{
    private const HELPER_PATH = '/lib/VM/CoalesceJitHelper.php';

    private const TAKE_LEFT_HELPER = 'PHPCompiler\\VM\\CoalesceJitHelper::takeLeftBranchFromTypeByte';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::TAKE_LEFT_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__coalesce__takeLeftBranch');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__coalesce__takeLeftBranch', $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementTakeLeftBridge($context);
        $context->builder->clearInsertionPosition();
    }

    public static function callTakeLeftBranch(Context $context, Value $typeByte): Value
    {
        self::ensureLinked($context);
        $fn = $context->lookupFunction('__coalesce__takeLeftBranch');
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->call(
            $fn,
            $context->builder->trunc($typeByte, $i8)
        );
    }

    private static function implementTakeLeftBridge(Context $context): void
    {
        $abiName = '__coalesce__takeLeftBranch';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $ft = $context->context->functionType($i1, false, $i8);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('coalesce_take_left_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::TAKE_LEFT_HELPER),
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
            throw new \LogicException($logical.' missing after CoalesceJitHelper compile (#10171)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'CoalesceJitHelper.php');
            if (null === $block) {
                throw new \LogicException('CoalesceJitHelper.php parseAndCompile failed (#10171)');
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
                throw new \LogicException($lc.' was not compiled for JIT (#10171)');
            }
        }
    }
}
