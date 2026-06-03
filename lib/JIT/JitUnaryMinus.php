<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\OpCode;

/** Unary {@see OpCode::TYPE_UNARY_MINUS} lowering — same operand coercion as +, then negate (#5083). */
final class JitUnaryMinus
{
    public static function lower(Context $context, OpCode $opcode, Variable $var): Variable
    {
        if (OpCode::TYPE_UNARY_MINUS !== $opcode->type) {
            throw new \InvalidArgumentException('Expected TYPE_UNARY_MINUS opcode');
        }

        try {
            $coerced = JitUnaryPlus::lower($context, new OpCode(OpCode::TYPE_UNARY_PLUS), $var);
        } catch (\LogicException) {
            return $context->helper->unaryOp($opcode, $var);
        }

        $value = $context->helper->loadValue($coerced);
        if (Variable::TYPE_NATIVE_DOUBLE === $coerced->type) {
            $negated = $context->builder->fNegate($value);

            return new Variable($context, Variable::TYPE_NATIVE_DOUBLE, Variable::KIND_VALUE, $negated);
        }

        $negated = $context->builder->negate($value);

        return new Variable($context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $negated);
    }
}
