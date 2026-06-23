<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __phpc_pow_int via PowIntJitHelper PHP (#9515).
 *
 * Integer exponent fast path delegates to {@see \PHPCompiler\ext\standard\VmMath::powInt}.
 * php-src: Zend/zend_operators.c — pow_function integer fast path
 */
final class PowIntRuntime
{
    private const HELPER_PATH = '/ext/standard/PowIntJitHelper.php';

    private const COMPUTE_HELPER = 'PHPCompiler\\ext\\standard\\PowIntJitHelper::compute';

    private const RESULT_INT_HELPER = 'PHPCompiler\\ext\\standard\\PowIntJitHelper::resultInt';

    private const RESULT_FLOAT_HELPER = 'PHPCompiler\\ext\\standard\\PowIntJitHelper::resultFloat';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COMPUTE_HELPER,
        self::RESULT_INT_HELPER,
        self::RESULT_FLOAT_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_pow_int');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementPowIntBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementPowIntBridge(Context $context): void
    {
        $abiName = '__phpc_pow_int';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $valuePtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $doubleTy = $context->getTypeFromString('double');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $valuePtr, $i64, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('pow_int_bridge_entry');
        $nullOut = $fn->appendBasicBlock('pow_int_bridge_null_out');
        $work = $fn->appendBasicBlock('pow_int_bridge_work');
        $intPath = $fn->appendBasicBlock('pow_int_bridge_int');
        $floatPath = $fn->appendBasicBlock('pow_int_bridge_float');
        $done = $fn->appendBasicBlock('pow_int_bridge_done');

        $context->builder->positionAtEnd($entry);
        $out = $fn->getParam(0);
        $base = $fn->getParam(1);
        $exp = $fn->getParam(2);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $out, $out->typeOf()->constNull());
        $context->builder->branchIf($isNull, $nullOut, $work);

        $context->builder->positionAtEnd($nullOut);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($work);
        $tag = $context->builder->call(
            self::helperFunction($context, self::COMPUTE_HELPER),
            $base,
            $exp
        );
        $tagI32 = $tag->typeOf() === $i32
            ? $tag
            : $context->builder->truncOrBitCast($tag, $i32);
        $isFloat = $context->builder->icmp(Builder::INT_NE, $tagI32, $i32->constInt(0, false));
        $context->builder->branchIf($isFloat, $floatPath, $intPath);

        $context->builder->positionAtEnd($intPath);
        $intResult = $context->builder->call(self::helperFunction($context, self::RESULT_INT_HELPER));
        $intI64 = $intResult->typeOf() === $i64
            ? $intResult
            : $context->builder->sext($intResult, $i64);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $out,
            $intI64
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($floatPath);
        $floatResult = $context->builder->call(self::helperFunction($context, self::RESULT_FLOAT_HELPER));
        $floatD = $floatResult->typeOf() === $doubleTy
            ? $floatResult
            : $context->builder->sitofp($floatResult, $doubleTy);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $out,
            $floatD
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after PowIntJitHelper compile (#9515)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'PowIntJitHelper.php');
            if (null === $block) {
                throw new \LogicException('PowIntJitHelper.php parseAndCompile failed (#9515)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9515)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__phpc_pow_int');
        if (null === $fn) {
            throw new \LogicException('__phpc_pow_int missing after PowIntRuntime bridge (#9515)');
        }
        $context->registerFunction('__phpc_pow_int', $fn);
    }
}
