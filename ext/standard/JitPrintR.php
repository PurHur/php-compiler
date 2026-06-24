<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringPrintR;
use PHPCompiler\JIT\Context;
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
            throw new \LogicException('print_r() expects 1 or 2 arguments');
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

        $returns = self::boolValForBranch($context, $args[1]);
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

    private static function boolValForBranch(Context $context, JITVariable $arg): Value
    {
        $boolVal = JITVariable::TYPE_VALUE === $arg->type
            ? $context->castToBool(JitValueBox::valuePtrFromVariable($context, $arg))
            : $context->helper->loadValue($arg);
        if (JITVariable::KIND_VALUE !== $arg->kind) {
            return $boolVal;
        }
        $i1 = $context->getTypeFromString('int1');
        $slot = $context->builder->alloca($i1, 1, 'print_r_bool_tmp');
        $context->builder->store($boolVal, $slot);

        return $context->builder->load($slot);
    }
}
