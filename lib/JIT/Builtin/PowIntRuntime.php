<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __phpc_pow_int via PowIntJitHelper PHP (#9515, #23097, #29678).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer MathFrexp #22575 /
 * Ldexp #29578). NestedJIT-safe int**int peel lives in
 * {@see \PHPCompiler\ext\standard\PowIntJitHelper} (no VmMath::powInt / `**` /
 * `\is_int` under helper compile — #29678 / Fpow #28674 shape).
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

        // Preserve caller insert block — clearInsertionPosition orphans the **
        // call after JitValueBox::alloc (`Instruction referencing instruction not
        // embedded in a basic block`, #31966 / peer #26884).
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        $savedActive = $context->activeFunction;
        $savedLowering = $context->loweringLlvmFunction;
        try {
            self::ensureJitHelperCompiled($context);
            self::implementPowIntBridge($context);
            self::registerLinkedRuntime($context);
        } finally {
            $context->activeFunction = $savedActive;
            $context->loweringLlvmFunction = $savedLowering;
            if (null !== $savedBlock) {
                BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
            } else {
                $context->builder->clearInsertionPosition();
            }
        }
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

        $context->activeFunction = $abiName;
        $context->loweringLlvmFunction = $fn instanceof LlvmFunction ? $fn : null;
        $context->builder->positionAtEnd($entry);
        $out = $fn->getParam(0);
        $base = $fn->getParam(1);
        $exp = $fn->getParam(2);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $out, $out->typeOf()->constNull());
        $context->builder->branchIf($isNull, $nullOut, $work);

        $context->builder->positionAtEnd($nullOut);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($work);
        $tag = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::COMPUTE_HELPER),
            [$base, $exp]
        );
        $tagI64 = JitNestedHelperCoerce::extractLongFromHelperResult($context, $tag, $i64);
        $tagI32 = $tagI64->typeOf() === $i32
            ? $tagI64
            : $context->builder->truncOrBitCast($tagI64, $i32);
        $isFloat = $context->builder->icmp(Builder::INT_NE, $tagI32, $i32->constInt(0, false));
        $context->builder->branchIf($isFloat, $floatPath, $intPath);

        $context->builder->positionAtEnd($intPath);
        $intResult = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::RESULT_INT_HELPER),
            []
        );
        $intI64 = JitNestedHelperCoerce::extractLongFromHelperResult($context, $intResult, $i64);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $out,
            $intI64
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($floatPath);
        $floatResult = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::RESULT_FLOAT_HELPER),
            []
        );
        $floatD = JitNestedHelperCoerce::extractDoubleFromHelperResult($context, $floatResult);
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

        return JitVmHelperLink::lookupCompiled($context, $logical, '#23097');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#23097'
        );
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
