<?php

declare(strict_types=1);

/**
 * Echo/print for boxed __value__ variables in JIT (native LLVM).
 */

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

final class ValueEchoHelper
{
    private static int $seq = 0;

    public static function echoLiteral(Context $context, string $literal): void
    {
        $charPtr = $context->getTypeFromString('char*');
        $context->builder->call(
            $context->lookupFunction('printf'),
            $context->builder->pointerCast(
                $context->constantFromString('%s'),
                $charPtr
            ),
            $context->builder->pointerCast(
                $context->constantFromString($literal),
                $charPtr
            )
        );
    }

    public static function echo(Context $context, Value $valuePtr): void
    {
        $tag = 'ev'.(string) ++self::$seq;
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        $nullBlock = BasicBlockHelper::append($context, 'echo_value_null_'.$tag);
        $longBlock = BasicBlockHelper::append($context, 'echo_value_long_'.$tag);
        $boolBlock = BasicBlockHelper::append($context, 'echo_value_bool_'.$tag);
        $doubleBlock = BasicBlockHelper::append($context, 'echo_value_double_'.$tag);
        $stringBlock = BasicBlockHelper::append($context, 'echo_value_string_'.$tag);
        $arrayBlock = BasicBlockHelper::append($context, 'echo_value_array_'.$tag);
        $objectBlock = BasicBlockHelper::append($context, 'echo_value_object_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'echo_value_done_'.$tag);

        $type = $typeByte;
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $type,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $type,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $type,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $type,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $type,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $isHashtable = $context->builder->icmp(
            Builder::INT_EQ,
            $type,
            $i8->constInt(Variable::TYPE_HASHTABLE, false)
        );
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $type,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );

        $afterNull = BasicBlockHelper::append($context, 'echo_value_after_null_'.$tag);
        $context->builder->branchIf($isNull, $nullBlock, $afterNull);
        $context->builder->positionAtEnd($nullBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterNull);
        $afterLong = BasicBlockHelper::append($context, 'echo_value_after_long_'.$tag);
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valuePtr
        );
        $charPtr = $context->getTypeFromString('char*');
        $context->builder->call(
            $context->lookupFunction('printf'),
            $context->builder->pointerCast(
                $context->constantFromString('%lld'),
                $charPtr
            ),
            $longVal
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterLong);
        $afterBool = BasicBlockHelper::append($context, 'echo_value_after_bool_'.$tag);
        $context->builder->branchIf($isBool, $boolBlock, $afterBool);

        $context->builder->positionAtEnd($boolBlock);
        $boolVal = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valuePtr
        );
        $isTrue = $context->builder->icmp(
            Builder::INT_NE,
            $boolVal,
            $boolVal->typeOf()->constInt(0, false)
        );
        $trueBlock = BasicBlockHelper::append($context, 'echo_value_bool_true_'.$tag);
        $falseBlock = BasicBlockHelper::append($context, 'echo_value_bool_false_'.$tag);
        $boolDone = BasicBlockHelper::append($context, 'echo_value_bool_done_'.$tag);
        $context->builder->branchIf($isTrue, $trueBlock, $falseBlock);
        $context->builder->positionAtEnd($trueBlock);
        $context->builder->call(
            $context->lookupFunction('printf'),
            $context->builder->pointerCast($context->constantFromString('1'), $charPtr)
        );
        $context->builder->branch($boolDone);
        $context->builder->positionAtEnd($falseBlock);
        $context->builder->branch($boolDone);
        $context->builder->positionAtEnd($boolDone);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterBool);
        $afterDouble = BasicBlockHelper::append($context, 'echo_value_after_double_'.$tag);
        $context->builder->branchIf($isDouble, $doubleBlock, $afterDouble);

        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call(
            $context->lookupFunction('__value__readDouble'),
            $valuePtr
        );
        $context->builder->call(
            $context->lookupFunction('printf'),
            $context->builder->pointerCast(
                $context->constantFromString('%G'),
                $charPtr
            ),
            $doubleVal
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterDouble);
        $afterArray = BasicBlockHelper::append($context, 'echo_value_after_array_'.$tag);
        $context->builder->branchIf($isHashtable, $arrayBlock, $afterArray);

        $context->builder->positionAtEnd($arrayBlock);
        self::echoLiteral($context, 'Array');
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterArray);
        $afterObject = BasicBlockHelper::append($context, 'echo_value_after_object_'.$tag);
        $context->builder->branchIf($isObject, $objectBlock, $afterObject);

        $context->builder->positionAtEnd($objectBlock);
        self::echoLiteral($context, 'Object');
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterObject);
        $context->builder->branchIf($isString, $stringBlock, $doneBlock);

        $context->builder->positionAtEnd($stringBlock);
        $strPtr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
        $strMap = $context->structFieldMap['__string__'];
        $strLen = $context->builder->load(
            $context->builder->structGep($strPtr, $strMap['length'])
        );
        $strChars = $context->builder->structGep($strPtr, $strMap['value']);
        $context->builder->call(
            $context->lookupFunction('printf'),
            $context->builder->pointerCast(
                $context->constantFromString('%.*s'),
                $charPtr
            ),
            $strLen,
            $strChars
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        BasicBlockHelper::branchToFreshContinue($context, 'echo_value_continue_'.$tag);
    }
}
