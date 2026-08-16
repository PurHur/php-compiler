<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringPrintR;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\ValueEchoHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for print_r() (#6709). */
final class JitPrintR
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            // php-src ext/standard/var.c — ArgumentCountError (#28474).
            $message = $argc < 1
                ? 'print_r() expects at least 1 argument, 0 given'
                : \sprintf('print_r() expects at most 2 arguments, %d given', $argc);
            ExceptionBridge::emitArgumentCountErrorAndAbort($context, $message);
            $nullSlot = JitValueBox::alloc($context);
            $nullPtr = JitValueBox::pointer($context, $nullSlot);
            $context->builder->call($context->lookupFunction('__value__writeNull'), $nullPtr);

            return $nullPtr;
        }

        StringPrintR::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $args[0]);
        $str = $context->builder->call(
            $context->lookupFunction('__compiler_print_r'),
            $valuePtr
        );
        $outSlot = JitValueBox::alloc($context);
        $outPtr = JitValueBox::pointer($context, $outSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $outPtr,
            $str
        );

        if (1 === $argc) {
            ValueEchoHelper::echo($context, $outPtr);
            $context->builder->call($context->lookupFunction('__value__writeNull'), $outPtr);
            $trueSlot = JitValueBox::alloc($context);
            $truePtr = JitValueBox::pointer($context, $trueSlot);
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                $truePtr,
                $context->getTypeFromString('int64')->constInt(1, false)
            );

            return $truePtr;
        }

        // Compile-time null under strict: catchable TypeError then stop IR (#31337 / peer #31358).
        if ($context->callerStrictTypes && (
            JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)
        )) {
            JitNativeString::ensureInsertBlock($context);
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'print_r(): Argument #2 ($return) must be of type bool, null given'
            );
            JitNativeString::ensureInsertBlock($context);
            $nullSlot = JitValueBox::alloc($context);
            $nullPtr = JitValueBox::pointer($context, $nullSlot);
            $context->builder->call($context->lookupFunction('__value__writeNull'), $nullPtr);

            return $nullPtr;
        }
        // Z_PARAM_BOOL: strict TypeError on null; else null→false + E_DEPRECATED (#31337).
        $returns = JitBoolArg::lowerCoerceZParamBool($context, $args[1], 'print_r', 'return', 2);
        $returnBb = BasicBlockHelper::append($context, 'print_r_return_mode');
        $echoBb = BasicBlockHelper::append($context, 'print_r_echo_mode');
        $doneBb = BasicBlockHelper::append($context, 'print_r_call_done');
        $context->builder->branchIf($returns, $returnBb, $echoBb);

        $context->builder->positionAtEnd($returnBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($echoBb);
        ValueEchoHelper::echo($context, $outPtr);
        $echoEndBb = $context->builder->getInsertBlock();
        $context->builder->call($context->lookupFunction('__value__writeNull'), $outPtr);
        $trueSlot = JitValueBox::alloc($context);
        $truePtr = JitValueBox::pointer($context, $trueSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $truePtr,
            $context->getTypeFromString('int64')->constInt(1, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $ptrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($ptrTy);
        $result->addIncoming($outPtr, $returnBb);
        $result->addIncoming($truePtr, $echoEndBb);

        return $result;
    }
}
