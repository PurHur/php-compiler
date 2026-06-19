<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;


/** Shared (array) cast helpers for CastHelper + CastArrayValueBoxJit (#10046). */
final class CastArrayShared
{
    public static function ensureInsertBlock(Context $context, string $label): void
    {
        $insert = $context->builder->getInsertBlock();
        if (null === $insert) {
            throw new \LogicException('JIT cast lowering requires an active basic block');
        }
        if (null !== $insert->getTerminator()) {
            $next = BasicBlockHelper::append($context, $label);
            $context->builder->positionAtEnd($insert);
            $context->builder->branch($next);
            $context->builder->positionAtEnd($next);
        }
    }

    public static function wrapScalarInArray(Context $context, Variable $src): Variable
    {
        $ht = HashTableHelper::alloc($context);
        $zero = $context->getTypeFromString('size_t')->constInt(0, false);
        if (Variable::TYPE_VALUE === $src->type) {
            $boxed = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VALUE, $src->value);
            HashTableHelper::setAtIndex($context, $ht, $zero, $boxed);
        } else {
            HashTableHelper::setAtIndex($context, $ht, $zero, $src);
        }
        $array = HashTableHelper::emptyVariable($context);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);

        return $array;
    }
}
