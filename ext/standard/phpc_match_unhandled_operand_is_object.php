<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Match lowering helper — zend_throw_unhandled_match_error object branch (#5448, #7199).
 *
 * php-src: Zend/zend_exceptions.c — IS_OBJECT incl. enum cases (zend_enum.c).
 * Prefer this over is_object() in php-cfg match overlay so enum regressions cannot
 * fall through to string cast and throw generic Error.
 */
final class phpc_match_unhandled_operand_is_object extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_match_unhandled_operand_is_object');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('phpc_match_unhandled_operand_is_object() requires exactly one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        $frame->returnVar->bool(
            Variable::TYPE_OBJECT === $var->type
            || Variable::TYPE_ENUM_CASE === $var->type
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('phpc_match_unhandled_operand_is_object() requires exactly one argument');
        }
        $i1 = $context->getTypeFromString('int1');
        // Native object / enum $this (match($this) in a method) — always an object (#24163).
        if (JITVariable::TYPE_OBJECT === $args[0]->type) {
            return $i1->constInt(1, false);
        }
        if (JITVariable::TYPE_VALUE !== $args[0]->type) {
            return $i1->constInt(0, false);
        }
        $loaded = \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($context, $args[0]);
        $typeField = $context->structFieldMap['__value__']['type'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($loaded, $typeField)
        );
        $i8 = $context->getTypeFromString('int8');
        $objectTy = $i8->constInt(Variable::TYPE_OBJECT, false);
        $enumCaseTy = $i8->constInt(Variable::TYPE_ENUM_CASE, false);
        $matchObject = $context->builder->icmp(Builder::INT_EQ, $typeByte, $objectTy);
        $matchEnum = $context->builder->icmp(Builder::INT_EQ, $typeByte, $enumCaseTy);

        return $context->builder->or($matchObject, $matchEnum);
    }
}
