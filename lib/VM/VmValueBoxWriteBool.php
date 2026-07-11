<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * SSOT for LLVM __value__writeBool lowering (#5480, #9570).
 *
 * Replaces former lib/AOT/runtime/phpc_value_box.c bool writer.
 * JIT trampoline: {@see \PHPCompiler\JIT\Builtin\ValueBoxWriteBoolJit}
 */
final class VmValueBoxWriteBool
{
    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__value__writeBool');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__value__writeBool', $probe);

            return;
        }

        $fn = $context->lookupFunction('__value__writeBool');
        self::emitWriteBool($context, $fn);
        $context->registerFunction('__value__writeBool', $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function emitWriteBool(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $write = $fn->appendBasicBlock('write');
        $done = $fn->appendBasicBlock('done');

        $context->builder->positionAtEnd($entry);
        $out = $fn->getParam(0);
        $value = $fn->getParam(1);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $out, $out->typeOf()->constNull());
        $context->builder->branchIf($isNull, $done, $write);

        $context->builder->positionAtEnd($write);
        $valMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $context->builder->store(
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false),
            $context->builder->structGep($out, $valMap['type'])
        );
        $zero = $i32->constInt(0, false);
        $isTruthy = $context->builder->icmp(Builder::INT_NE, $value, $zero);
        $boolByte = $context->builder->select(
            $isTruthy,
            $i8->constInt(1, false),
            $i8->constInt(0, false)
        );
        $valueField = $context->builder->structGep($out, $valMap['value']);
        $firstByte = $context->builder->inBoundsGEP(
            $valueField,
            $i32->constInt(0, false),
            $i64->constInt(0, false)
        );
        $context->builder->store($boolByte, $firstByte);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
    }
}
