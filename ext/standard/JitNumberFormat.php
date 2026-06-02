<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helper for number_format() (int/float/numeric string, 0–4 args; subset of PHP).
 */
final class JitNumberFormat
{
    public static function format(Context $context, JITVariable ...$args): Value
    {
        $argc = count($args);
        if ($argc < 1 || $argc > 4) {
            throw new \LogicException('number_format() requires one to four arguments');
        }

        $number = self::coerceDouble($context, $args[0]);
        $decimals = $argc >= 2
            ? self::coerceInt64($context, $args[1])
            : $context->getTypeFromString('int64')->constInt(0, false);
        $decSep = $argc >= 3
            ? self::coerceString($context, $args[2])
            : $context->builder->load($context->constantStringFromString('.'));
        $thouSep = 4 === $argc
            ? self::coerceString($context, $args[3])
            : $context->builder->load($context->constantStringFromString(','));

        return $context->builder->call(
            $context->lookupFunction('__compiler_number_format'),
            $number,
            $decimals,
            $decSep,
            $thouSep
        );
    }

    private static function coerceDouble(Context $context, JITVariable $arg): Value
    {
        $value = $context->helper->loadValue($arg);
        $f64 = $context->getTypeFromString('double');
        switch ($arg->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return $context->builder->sitofp($value, $f64);
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return $value;
            case JITVariable::TYPE_STRING:
                return self::stringToDouble($context, $arg);
            case JITVariable::TYPE_VALUE:
                return self::valueToDouble($context, $arg);
            default:
                throw new \LogicException(
                    'number_format() number must be an integer, float, or numeric string in this compiler build'
                );
        }
    }

    private static function stringToDouble(Context $context, JITVariable $arg): Value
    {
        $literal = JitStringArg::compileTimeLiteral($arg);
        if (null !== $literal) {
            if (!\is_numeric($literal)) {
                throw new \TypeError('number_format(): Argument #1 ($num) must be of type float, string given');
            }

            return $context->getTypeFromString('double')->constReal((float) $literal);
        }

        $strPtr = JitStringArg::lower($context, $arg, 'number_format() argument #1');
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtr = $context->getTypeFromString('int8**')->constNull();

        return $context->builder->call($context->lookupFunction('strtod'), $charPtr, $endPtr);
    }

    private static function valueToDouble(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $f64 = $context->getTypeFromString('double');
        $zero = $f64->constReal(0.0);

        $longBlock = BasicBlockHelper::append($context, 'number_format_value_long');
        $doubleBlock = BasicBlockHelper::append($context, 'number_format_value_double');
        $stringBlock = BasicBlockHelper::append($context, 'number_format_value_string');
        $doneBlock = BasicBlockHelper::append($context, 'number_format_value_done');
        $fallbackBlock = BasicBlockHelper::append($context, 'number_format_value_fallback');

        $afterLong = BasicBlockHelper::append($context, 'number_format_value_after_long');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)),
            $longBlock,
            $afterLong
        );

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $longFloat = $context->builder->sitofp($longVal, $f64);
        $longEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterLong);
        $afterDouble = BasicBlockHelper::append($context, 'number_format_value_after_double');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_DOUBLE, false)),
            $doubleBlock,
            $afterDouble
        );

        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $valuePtr);
        $doubleEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterDouble);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_STRING, false)),
            $stringBlock,
            $fallbackBlock
        );

        $context->builder->positionAtEnd($stringBlock);
        $stringVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $structName = $stringVal->typeOf()->getElementType()->getName();
        $strMap = $context->structFieldMap[$structName];
        $charPtr = $context->builder->structGep($stringVal, $strMap['value']);
        $endPtr = $context->getTypeFromString('int8**')->constNull();
        $stringFloat = $context->builder->call($context->lookupFunction('strtod'), $charPtr, $endPtr);
        $stringEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($fallbackBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($f64, 'number_format_value_phi');
        $phi->addIncoming($longFloat, $longEndBlock);
        $phi->addIncoming($doubleVal, $doubleEndBlock);
        $phi->addIncoming($stringFloat, $stringEndBlock);
        $phi->addIncoming($zero, $fallbackBlock);

        return $phi;
    }

    private static function coerceInt64(Context $context, JITVariable $arg): Value
    {
        $value = $context->helper->loadValue($arg);
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return $value;
        }

        throw new \LogicException('number_format() decimals must be an integer in this compiler build');
    }

    private static function coerceString(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                $arg->value
            );
        }

        throw new \LogicException('number_format() separator must be a string in this compiler build');
    }
}
