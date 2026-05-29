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
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * fdiv() — IEEE-754 float division (PHP 8.0, ext/standard/math.c / zend_fdiv).
 */
final class fdiv extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== count($frame->calledArgs)) {
            throw new \LogicException('fdiv() requires exactly two arguments');
        }
        $a = $frame->calledArgs[0]->resolveIndirect();
        $b = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(\fdiv(self::toFloat($a), self::toFloat($b)));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== count($args)) {
            throw new \LogicException('fdiv() requires exactly two arguments');
        }
        $double = $context->getTypeFromString('double');
        $left = self::toJitDouble($context, $args[0], $double);
        $right = self::toJitDouble($context, $args[1], $double);

        return $context->builder->fdiv($left, $right);
    }

    private static function toJitDouble(Context $context, JITVariable $arg, $double): Value
    {
        switch ($arg->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                $v = JitLongArg::lower($context, $arg, 'fdiv() argument');

                return $context->builder->siToFp($v, $double);
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return $context->helper->loadValue($arg);
            case JITVariable::TYPE_VALUE:
                return self::unboxValueToDouble($context, $arg, $double);
            default:
                if (JitValueBox::isValueOperand($arg)) {
                    return self::unboxValueToDouble($context, $arg, $double);
                }
                throw new \LogicException('fdiv() only supports integers and floats in this compiler build');
        }
    }

    /**
     * @see \PHPCompiler\JIT::unboxValueToNativeDouble()
     */
    private static function unboxValueToDouble(Context $context, JITVariable $arg, $double): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NATIVE_DOUBLE, false)
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)
        );
        $readDouble = $context->builder->call(
            $context->lookupFunction('__value__readDouble'),
            $valuePtr
        );
        $readLong = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valuePtr
        );
        $fromLong = $context->builder->siToFp($readLong, $double);

        return $context->builder->select(
            $isDouble,
            $readDouble,
            $context->builder->select($isLong, $fromLong, $double->constReal(0.0))
        );
    }

    private static function toFloat(Variable $v): float
    {
        if (Variable::TYPE_INTEGER === $v->type) {
            return (float) $v->toInt();
        }
        if (Variable::TYPE_FLOAT === $v->type) {
            return $v->toFloat();
        }
        throw new \LogicException('fdiv() only supports integers and floats in this compiler build');
    }
}
