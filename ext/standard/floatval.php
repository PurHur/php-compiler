<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * floatval() for scalar arguments (subset of PHP standard library).
 */
final class floatval extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('floatval() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_NULL === $v->type) {
            $frame->returnVar->float(0.0);

            return;
        }
        if (Variable::TYPE_INTEGER === $v->type) {
            $frame->returnVar->float((float) $v->toInt());

            return;
        }
        if (Variable::TYPE_FLOAT === $v->type) {
            $frame->returnVar->float($v->toFloat());

            return;
        }
        if (Variable::TYPE_BOOLEAN === $v->type) {
            $frame->returnVar->float($v->toBool() ? 1.0 : 0.0);

            return;
        }
        if (Variable::TYPE_STRING === $v->type) {
            $frame->returnVar->float((float) $v->toString());

            return;
        }
        throw new \LogicException('floatval() only supports null, integers, floats, booleans, and strings in this compiler build');
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('floatval() requires exactly one argument');
        }
        $v = $context->helper->loadValue($args[0]);
        $double = $context->getTypeFromString('double');
        switch ($args[0]->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return $context->builder->siToFp($v, $double);
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return $v;
            case JITVariable::TYPE_NATIVE_BOOL:
                return $context->builder->uiToFp($v, $double);
            case JITVariable::TYPE_STRING:
                $ptr = $this->stringDataPtr($context, $v);
                $endPtr = $context->getTypeFromString('int8**')->constNull();

                return $context->builder->call($context->lookupFunction('strtod'), $ptr, $endPtr);
            case JITVariable::TYPE_VALUE:
                $valuePtr = JitValueBox::pointer($context, $args[0]->value);
                $map = $context->structFieldMap['__value__'];
                $typeByte = $context->builder->load(
                    $context->builder->structGep($valuePtr, $map['type'])
                );
                $i8 = $context->getTypeFromString('int8');
                $isNull = $context->builder->icmp(
                    Builder::INT_EQ,
                    $typeByte,
                    $i8->constInt(Variable::TYPE_NULL, false)
                );

                return $context->builder->select(
                    $isNull,
                    $double->constReal(0.0),
                    $double->constReal(0.0)
                );
            default:
                throw new \LogicException('floatval() only supports null, integers, floats, booleans, and strings in this compiler build');
        }
    }

    private function stringDataPtr(Context $context, Value $strPtr): Value
    {
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $off = $context->structFieldMap[$structName]['value'];

        return $context->builder->structGep($strPtr, $off);
    }
}
