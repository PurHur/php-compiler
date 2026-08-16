<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringVarExport;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TypedPropertyUninitGuard;
use PHPCompiler\JIT\ValueEchoHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for var_export() (#9189). */
final class JitVarExport
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = count($args);
        if ($argc < 1 || $argc > 2) {
            // php-src ext/standard/var.c — ArgumentCountError (#28474).
            $message = $argc < 1
                ? 'var_export() expects at least 1 argument, 0 given'
                : \sprintf('var_export() expects at most 2 arguments, %d given', $argc);
            ExceptionBridge::emitArgumentCountErrorAndAbort($context, $message);
            $nullSlot = JitValueBox::alloc($context);
            $nullPtr = JitValueBox::pointer($context, $nullSlot);
            $context->builder->call($context->lookupFunction('__value__writeNull'), $nullPtr);

            return $nullPtr;
        }
        if (JITVariable::TYPE_VALUE === $args[0]->type) {
            TypedPropertyUninitGuard::emitBeforeRead($context, $args[0]);
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $args[0]->type) {
            self::echoBoolJit($context, self::boolValForBranch($context, $args[0]));
            $outSlot = JitValueBox::alloc($context);
            $outPtr = JitValueBox::pointer($context, $outSlot);
            $context->builder->call($context->lookupFunction('__value__writeNull'), $outPtr);

            return $outPtr;
        }

        StringVarExport::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $args[0]);
        $str = $context->builder->call(
            $context->lookupFunction('__compiler_var_export'),
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
            $nullSlot = JitValueBox::alloc($context);
            $nullPtr = JitValueBox::pointer($context, $nullSlot);
            $context->builder->call($context->lookupFunction('__value__writeNull'), $nullPtr);

            return $nullPtr;
        }
        // Compile-time null under strict: catchable TypeError then stop IR (#31337 / peer #31358).
        if ($context->callerStrictTypes && (
            JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)
        )) {
            JitNativeString::ensureInsertBlock($context);
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'var_export(): Argument #2 ($return) must be of type bool, null given'
            );
            JitNativeString::ensureInsertBlock($context);
            $nullSlot = JitValueBox::alloc($context);
            $nullPtr = JitValueBox::pointer($context, $nullSlot);
            $context->builder->call($context->lookupFunction('__value__writeNull'), $nullPtr);

            return $nullPtr;
        }
        // Z_PARAM_BOOL: strict TypeError on null; else null→false + E_DEPRECATED (#31337).
        $returns = JitBoolArg::lowerCoerceZParamBool($context, $args[1], 'var_export', 'return', 2);
        $returnBb = BasicBlockHelper::append($context, 'var_export_return_mode');
        $echoBb = BasicBlockHelper::append($context, 'var_export_echo_mode');
        $doneBb = BasicBlockHelper::append($context, 'var_export_call_done');
        $context->builder->branchIf($returns, $returnBb, $echoBb);
        $context->builder->positionAtEnd($returnBb);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($echoBb);
        ValueEchoHelper::echo($context, $outPtr);
        $echoEndBb = $context->builder->getInsertBlock();
        $context->builder->call($context->lookupFunction('__value__writeNull'), $outPtr);
        $nullSlot = JitValueBox::alloc($context);
        $nullPtr = JitValueBox::pointer($context, $nullSlot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $nullPtr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
        $ptrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($ptrTy);
        $result->addIncoming($outPtr, $returnBb);
        $result->addIncoming($nullPtr, $echoEndBb);

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
        $slot = $context->builder->alloca($i1, 1, 'var_export_bool_tmp');
        $context->builder->store($boolVal, $slot);

        return $context->builder->load($slot);
    }

    private static function echoBoolJit(Context $context, Value $boolVal): void
    {
        // Use write-backed echo (not libc printf): under thin AOT, printf is fully buffered
        // when stdout is not a TTY, so `var_export(true); echo "\n";` printed `\n` then
        // flushed `true` at exit (#26929 Done-when / peer ValueEchoHelper).
        $trueBlock = BasicBlockHelper::append($context, 'var_export_bool_true');
        $falseBlock = BasicBlockHelper::append($context, 'var_export_bool_false');
        $doneBlock = BasicBlockHelper::append($context, 'var_export_bool_done');
        $context->builder->branchIf($boolVal, $trueBlock, $falseBlock);
        $context->builder->positionAtEnd($trueBlock);
        ValueEchoHelper::echoLiteral($context, 'true');
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($falseBlock);
        ValueEchoHelper::echoLiteral($context, 'false');
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);
    }
}
