<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * SSOT for LLVM __value__writeLong lowering.
 *
 * Replaces former always-on Value::implementValueWriteLong body.
 * JIT trampoline: {@see \PHPCompiler\JIT\Builtin\ValueBoxWriteLongJit}
 *
 * php-src: zend/zend_variables.c — zval IS_LONG write after dtor.
 */
final class VmValueBoxWriteLong
{
    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__value__writeLong');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__value__writeLong', $probe);

            return;
        }

        $fn = $context->tryGetRegisteredFunction('__value__writeLong')
            ?? $context->module->getNamedFunction('__value__writeLong');
        if (null === $fn) {
            throw new \LogicException('__value__writeLong shell missing before implement (#36135)');
        }
        self::emitWriteLong($context, $fn);
        $context->registerFunction('__value__writeLong', $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function emitWriteLong(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $value = $fn->getParam(0);
        $long = $fn->getParam(1);

        $context->builder->call($context->lookupFunction('__value__valueDelref'), $value);

        $valMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $context->builder->store(
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false),
            $context->builder->structGep($value, $valMap['type'])
        );
        $valueField = $context->builder->structGep($value, $valMap['value']);
        $longPtr = $context->builder->bitCast(
            $valueField,
            $context->getTypeFromString('int64*')
        );
        $context->builder->store(
            $long,
            $context->builder->gep($longPtr, $i32->constInt(0, false))
        );
        $context->builder->returnVoid();
    }
}
