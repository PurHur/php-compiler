<?php

namespace PHPCompiler\JIT;

use PHPLLVM;
use PHPLLVM\Builder;

/**
 * Byte-level string offset read/write for JIT (issue #198, #3751 negative offsets).
 */
final class StringOffsetHelper
{
    public static function dimFetch(Context $context, PHPLLVM\Value $strSlot, Variable $dim): PHPLLVM\Value
    {
        $str = $context->builder->load($strSlot);
        $map = $context->structFieldMap['__string__'];
        $chars = $context->builder->structGep($str, $map['value']);
        $len = $context->builder->load(
            $context->builder->structGep($str, $map['length'])
        );
        $index = $context->helper->loadValue($dim);
        $offset = self::normalizeOffset($context, $index, $len);

        return $context->builder->gep($chars, $offset);
    }

    /**
     * Zend string offset index: negative values count from the end (PHP 7.1+).
     */
    public static function normalizeOffset(Context $context, PHPLLVM\Value $index, PHPLLVM\Value $len): PHPLLVM\Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $isNegative = $context->builder->icmp(Builder::INT_SLT, $index, $zero);

        $negBlock = BasicBlockHelper::append($context, 'str_offset_neg');
        $posBlock = BasicBlockHelper::append($context, 'str_offset_pos');
        $doneBlock = BasicBlockHelper::append($context, 'str_offset_done');
        $context->builder->branchIf($isNegative, $negBlock, $posBlock);

        $context->builder->positionAtEnd($negBlock);
        $lenI64 = $context->builder->zExt($len, $i64);
        $adjusted = $context->builder->add($lenI64, $index);
        $normalizedNeg = $context->builder->truncOrBitCast($adjusted, $sizeT);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($posBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($sizeT);
        $phi->addIncoming($normalizedNeg, $negBlock);
        $phi->addIncoming(
            $context->builder->truncOrBitCast($index, $sizeT),
            $posBlock
        );

        return $phi;
    }

    public static function dimAssign(Context $context, PHPLLVM\Value $charPtr, Variable $value): void
    {
        $byte = self::assignByte($context, $value);
        $context->builder->store($byte, $charPtr);
    }

    /**
     * String offset read returns a length-1 {@see __string__} (PHP $s[$i] semantics).
     */
    public static function readAsString(Context $context, PHPLLVM\Value $charPtr): PHPLLVM\Value
    {
        $byte = $context->builder->load($charPtr);
        $i8 = $context->getTypeFromString('int8');
        $buf = BasicBlockHelper::entryAlloca($context, $i8->arrayType(1));
        $bufChar = $context->builder->pointerCast($buf, $context->getTypeFromString('char*'));
        $context->builder->store($byte, $bufChar);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->getTypeFromString('int64')->constInt(1, false),
            $bufChar
        );
    }

    private static function assignByte(Context $context, Variable $value): PHPLLVM\Value
    {
        $i8 = $context->getTypeFromString('int8');
        switch ($value->type) {
            case Variable::TYPE_NATIVE_LONG:
                $long = $context->helper->loadValue($value);
                $trunc = $context->builder->truncOrBitCast($long, $i8);

                return $trunc;
            case Variable::TYPE_STRING:
                $str = $context->helper->loadValue($value);
                $map = $context->structFieldMap['__string__'];
                $chars = $context->builder->structGep($str, $map['value']);

                return $context->builder->load($chars);
            default:
                throw new \LogicException(
                    'String offset assignment supports int or string RHS in JIT (got type ' . $value->type . ')'
                );
        }
    }
}
