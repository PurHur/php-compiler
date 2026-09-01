<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * SSOT for LLVM __value__writeNull lowering.
 *
 * Replaces former always-on Value::implementValueWriteNull body.
 * JIT trampoline: {@see \PHPCompiler\JIT\Builtin\ValueBoxWriteNullJit}
 *
 * php-src: zend/zend_variables.c — zval type IS_NULL after dtor.
 */
final class VmValueBoxWriteNull
{
    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__value__writeNull');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__value__writeNull', $probe);

            return;
        }

        $fn = $context->tryGetRegisteredFunction('__value__writeNull')
            ?? $context->module->getNamedFunction('__value__writeNull');
        if (null === $fn) {
            throw new \LogicException('__value__writeNull shell missing before implement (#36124)');
        }
        self::emitWriteNull($context, $fn);
        $context->registerFunction('__value__writeNull', $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function emitWriteNull(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $value = $fn->getParam(0);

        $context->builder->call($context->lookupFunction('__value__valueDelref'), $value);

        $i8 = $context->getTypeFromString('int8');
        $nullType = $i8->constInt(Variable::TYPE_NULL, false);
        $typeGep = $context->builder->structGep(
            $value,
            $context->structFieldIndex($value, 'type')
        );
        $context->builder->store($nullType, $typeGep);
        $context->builder->returnVoid();
    }
}
