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
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * number_format() for integers and floats (C-style locale subset).
 */
final class number_format extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 4) {
            throw new \LogicException('number_format() requires one to four arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $numVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $numVar->type && Variable::TYPE_FLOAT !== $numVar->type) {
            throw new \LogicException('number_format() number must be an integer or float in this compiler build');
        }
        $num = Variable::TYPE_INTEGER === $numVar->type ? (float) $numVar->toInt() : $numVar->toFloat();
        $decimals = 0;
        if ($argc >= 2) {
            $decVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $decVar->type) {
                throw new \LogicException('number_format() decimals must be an integer in this compiler build');
            }
            $decimals = $decVar->toInt();
        }
        $decimalSeparator = '.';
        if ($argc >= 3) {
            $sepVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_STRING !== $sepVar->type) {
                throw new \LogicException('number_format() decimal separator must be a string in this compiler build');
            }
            $decimalSeparator = $sepVar->toString();
        }
        $thousandsSeparator = ',';
        if (4 === $argc) {
            $thouVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_STRING !== $thouVar->type) {
                throw new \LogicException('number_format() thousands separator must be a string in this compiler build');
            }
            $thousandsSeparator = $thouVar->toString();
        }
        $frame->returnVar->string(VmNumberFormat::format(
            $num,
            $decimals,
            $decimalSeparator,
            $thousandsSeparator
        ));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = \count($args);
        if ($argc < 1 || $argc > 4) {
            throw new \LogicException('number_format() requires one to four arguments');
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $args[0]->type
            && JITVariable::TYPE_NATIVE_DOUBLE !== $args[0]->type) {
            throw new \LogicException('number_format() number must be an integer or float in this compiler build');
        }
        $double = $context->getTypeFromString('double');
        $number = JITVariable::TYPE_NATIVE_DOUBLE === $args[0]->type
            ? $context->helper->loadValue($args[0])
            : $context->builder->siToFp($context->helper->loadValue($args[0]), $double);

        $i64 = $context->getTypeFromString('int64');
        $decimals = $i64->constInt(0, false);
        if ($argc >= 2) {
            if (JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
                throw new \LogicException('number_format() decimals must be an integer in this compiler build');
            }
            $decimals = $context->helper->loadValue($args[1]);
        }

        $decSep = self::jitSeparatorByte($context, $argc >= 3 ? $args[2] : null, '.');
        $thouSep = self::jitSeparatorByte($context, 4 === $argc ? $args[3] : null, ',');

        return JitNumberFormat::format($context, $number, $decimals, $decSep, $thouSep);
    }

    private static function jitSeparatorByte(Context $context, ?JITVariable $arg, string $default): Value
    {
        $i8 = $context->getTypeFromString('int8');
        if (null === $arg) {
            return $i8->constInt(ord($default), false);
        }
        if (JITVariable::TYPE_STRING === $arg->type) {
            $str = $context->helper->loadValue($arg);
            $map = $context->structFieldMap['__string__'];
            $len = $context->builder->load($context->builder->structGep($str, $map['length']));
            $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $len->typeOf()->constInt(0, false));
            $chars = $context->builder->structGep($str, $map['value']);
            $first = $context->builder->load($chars);

            return $context->builder->select(
                $isEmpty,
                $i8->constInt(ord($default), false),
                $first
            );
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            $str = $context->builder->call(
                $context->lookupFunction('__value__readString'),
                $arg->value
            );

            return self::jitSeparatorByte(
                $context,
                new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $str),
                $default
            );
        }

        throw new \LogicException('number_format() separators must be strings in this compiler build');
    }
}
