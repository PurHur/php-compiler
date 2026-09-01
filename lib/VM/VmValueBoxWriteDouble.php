<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * SSOT for LLVM __value__writeDouble lowering.
 *
 * Replaces former always-on Value::implementValueWriteDouble body.
 * JIT trampoline: {@see \PHPCompiler\JIT\Builtin\ValueBoxWriteDoubleJit}
 *
 * php-src: zend/zend_variables.c — zval IS_DOUBLE write after dtor.
 */
final class VmValueBoxWriteDouble
{
    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__value__writeDouble');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__value__writeDouble', $probe);

            return;
        }

        $fn = $context->tryGetRegisteredFunction('__value__writeDouble')
            ?? $context->module->getNamedFunction('__value__writeDouble');
        if (null === $fn) {
            throw new \LogicException('__value__writeDouble shell missing before implement (#36141)');
        }
        self::emitWriteDouble($context, $fn);
        $context->registerFunction('__value__writeDouble', $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function emitWriteDouble(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $value = $fn->getParam(0);
        $double = $fn->getParam(1);

        $context->builder->call($context->lookupFunction('__value__valueDelref'), $value);

        $valMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $context->builder->store(
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false),
            $context->builder->structGep($value, $valMap['type'])
        );
        $valueField = $context->builder->structGep($value, $valMap['value']);
        $doublePtr = $context->builder->bitCast(
            $valueField,
            $context->getTypeFromString('double*')
        );
        $context->builder->store(
            $double,
            $context->builder->gep($doublePtr, $i32->constInt(0, false))
        );
        $context->builder->returnVoid();
    }
}
