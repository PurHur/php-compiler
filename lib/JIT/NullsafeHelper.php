<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\BasicBlock;
use PHPLLVM\Value\Function_;
use PHPCompiler\Block;
use PHPCompiler\JIT;

/**
 * LLVM lowering helpers for ?-> nullsafe branch targets (issues #308, #3219).
 */
final class NullsafeHelper
{
    public static function compileBranch(
        JIT $jit,
        Function_ $func,
        Block $branchBlock
    ): BasicBlock {
        return $jit->compileSubBlock($func, $branchBlock);
    }

    /**
     * i1: receiver is PHP null (Zend: TYPE_NULL on value box or null object handle).
     */
    public static function isReceiverNull(JIT $jit, Variable $receiver): \PHPLLVM\Value
    {
        $context = $jit->context;
        $builder = $context->builder;
        if (Variable::TYPE_OBJECT === $receiver->type) {
            $obj = $context->helper->loadValue($receiver);

            return $builder->icmp(
                \PHPLLVM\Builder::INT_EQ,
                $obj,
                $obj->typeOf()->constNull()
            );
        }
        if (Variable::TYPE_VALUE !== $receiver->type) {
            throw new \LogicException('nullsafe receiver must be object or value box');
        }
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $receiver);
        $typeByte = $builder->load(
            $builder->structGep(
                $valuePtr,
                $context->structFieldMap['__value__']['type']
            )
        );
        $i8 = $context->getTypeFromString('int8');

        return $builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
    }
}
