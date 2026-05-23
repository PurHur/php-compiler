<?php

namespace PHPCompiler\JIT;

use PHPLLVM;

/**
 * Byte-level string offset read/write for JIT (issue #198).
 */
final class StringOffsetHelper
{
    public static function dimFetch(Context $context, PHPLLVM\Value $strSlot, Variable $dim): PHPLLVM\Value
    {
        $str = $context->builder->load($strSlot);
        $map = $context->structFieldMap['__string__'];
        $chars = $context->builder->structGep($str, $map['value']);
        $offset = $context->builder->truncOrBitCast(
            $context->helper->loadValue($dim),
            $context->getTypeFromString('size_t')
        );

        return $context->builder->gep($chars, $offset);
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
