<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_bc* via BcmathJitHelper PHP (#6100, #9235).
 *
 * Replaces ~480-line libc double-parse LLVM with thin bridges into {@see VmBcmath} SSOT.
 * php-src: ext/bcmath/libbcmath/src/*
 */
final class BcmathJit
{
    private const HELPER_PATH = '/ext/bcmath/BcmathJitHelper.php';

    private const SCALE_HELPER = 'PHPCompiler\\ext\\bcmath\\BcmathJitHelper::bcscaleAsInt';

    private const ADD_HELPER = 'PHPCompiler\\ext\\bcmath\\BcmathJitHelper::add';

    private const SUB_HELPER = 'PHPCompiler\\ext\\bcmath\\BcmathJitHelper::sub';

    private const MUL_HELPER = 'PHPCompiler\\ext\\bcmath\\BcmathJitHelper::mul';

    private const DIV_HELPER = 'PHPCompiler\\ext\\bcmath\\BcmathJitHelper::div';

    private const COMP_HELPER = 'PHPCompiler\\ext\\bcmath\\BcmathJitHelper::comp';

    private const POWMOD_HELPER = 'PHPCompiler\\ext\\bcmath\\BcmathJitHelper::powmod';

    private const ROUND_HELPER = 'PHPCompiler\\ext\\bcmath\\BcmathJitHelper::round';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SCALE_HELPER,
        self::ADD_HELPER,
        self::SUB_HELPER,
        self::MUL_HELPER,
        self::DIV_HELPER,
        self::COMP_HELPER,
        self::POWMOD_HELPER,
        self::ROUND_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_bcscale',
        '__compiler_bcadd',
        '__compiler_bcsub',
        '__compiler_bcmul',
        '__compiler_bcdiv',
        '__compiler_bccomp',
        '__compiler_bcpowmod',
        '__compiler_bcround',
    ];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_bcadd');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, '__compiler_bcscale', self::implementBcscaleBridge(...));
        self::implementIfMissing(
            $context,
            '__compiler_bcadd',
            static function (Context $ctx, LlvmFunction $fn): void {
                self::implementBinaryStringBridge($ctx, $fn, self::ADD_HELPER);
            }
        );
        self::implementIfMissing(
            $context,
            '__compiler_bcsub',
            static function (Context $ctx, LlvmFunction $fn): void {
                self::implementBinaryStringBridge($ctx, $fn, self::SUB_HELPER);
            }
        );
        self::implementIfMissing(
            $context,
            '__compiler_bcmul',
            static function (Context $ctx, LlvmFunction $fn): void {
                self::implementBinaryStringBridge($ctx, $fn, self::MUL_HELPER);
            }
        );
        self::implementIfMissing(
            $context,
            '__compiler_bcdiv',
            static function (Context $ctx, LlvmFunction $fn): void {
                self::implementBinaryStringBridge($ctx, $fn, self::DIV_HELPER);
            }
        );
        self::implementIfMissing($context, '__compiler_bccomp', self::implementCompBridge(...));
        self::implementIfMissing($context, '__compiler_bcpowmod', self::implementPowmodBridge(...));
        self::implementIfMissing($context, '__compiler_bcround', self::implementRoundBridge(...));
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $str = $context->getTypeFromString('__string__*');

        return match ($name) {
            '__compiler_bcscale' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $i64, $i32)
            ),
            '__compiler_bcadd',
            '__compiler_bcsub',
            '__compiler_bcmul',
            '__compiler_bcdiv' => $context->module->addFunction(
                $name,
                $context->context->functionType($str, false, $str, $str, $i64, $i32)
            ),
            '__compiler_bccomp' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $str, $str, $i64, $i32)
            ),
            '__compiler_bcpowmod' => $context->module->addFunction(
                $name,
                $context->context->functionType($str, false, $str, $str, $str, $i64, $i32)
            ),
            '__compiler_bcround' => $context->module->addFunction(
                $name,
                $context->context->functionType($str, false, $str, $i64, $i64)
            ),
            default => throw new \LogicException('Unknown bcmath JIT helper: '.$name),
        };
    }

    private static function implementBcscaleBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('bcscale_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $old = $context->builder->call(
            self::helperFunction($context, self::SCALE_HELPER),
            $fn->getParam(0),
            $fn->getParam(1)
        );
        $context->builder->returnValue($old);
    }

    private static function implementBinaryStringBridge(
        Context $context,
        LlvmFunction $fn,
        string $helperLogical
    ): void {
        $entry = $fn->appendBasicBlock('bcmath_bin_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, $helperLogical),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2),
            $fn->getParam(3)
        );
        $context->builder->returnValue($result);
    }

    private static function implementCompBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('bccomp_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::COMP_HELPER),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2),
            $fn->getParam(3)
        );
        $i64 = $context->getTypeFromString('int64');
        $context->builder->returnValue($context->builder->sext($result, $i64));
    }

    private static function implementPowmodBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('bcpowmod_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::POWMOD_HELPER),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2),
            $fn->getParam(3),
            $fn->getParam(4)
        );
        $context->builder->returnValue($result);
    }

    private static function implementRoundBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('bcround_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::ROUND_HELPER),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2)
        );
        $context->builder->returnValue($result);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after BcmathJitHelper compile (#9235)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'BcmathJitHelper.php');
            if (null === $block) {
                throw new \LogicException('BcmathJitHelper.php parseAndCompile failed (#9235)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9235)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after BcmathJit bridge (#9235)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
