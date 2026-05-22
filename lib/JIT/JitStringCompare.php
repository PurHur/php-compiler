<?php

declare(strict_types=1);

/**
 * LLVM helpers for strict/loose equality on native {@see __string__} operands.
 */

namespace PHPCompiler\JIT;

require_once __DIR__.'/../OpCodeNames.php';

use PHPCompiler\OpCode;
use function PHPCompiler\opcode_type_name;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitStringCompare
{
    public static function binaryOp(
        Context $context,
        OpCode $opcode,
        Value $leftStr,
        Value $rightStr
    ): Value {
        switch ($opcode->type) {
            case OpCode::TYPE_IDENTICAL:
            case OpCode::TYPE_EQUAL:
                return self::identical($context, $leftStr, $rightStr);
            case OpCode::TYPE_NOT_IDENTICAL:
            case OpCode::TYPE_NOT_EQUAL:
                $same = self::identical($context, $leftStr, $rightStr);
                $i1 = $context->getTypeFromString('int1');

                return $context->builder->icmp(Builder::INT_NE, $same, $i1->constInt(1, false));
            default:
                throw new \LogicException(
                    'String/string comparison opcode not implemented for JIT: '.opcode_type_name($opcode->type)
                );
        }
    }

    public static function identical(Context $context, Value $leftStr, Value $rightStr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $leftLen = $context->builder->load(
            $context->builder->structGep($leftStr, $map['length'])
        );
        $rightLen = $context->builder->load(
            $context->builder->structGep($rightStr, $map['length'])
        );
        $lenEq = $context->builder->icmp(Builder::INT_EQ, $leftLen, $rightLen);
        $cmp = $context->builder->call(
            $context->lookupFunction('strcmp'),
            $context->builder->structGep($leftStr, $map['value']),
            $context->builder->structGep($rightStr, $map['value'])
        );
        $strEq = $context->builder->icmp(
            Builder::INT_EQ,
            $cmp,
            $cmp->typeOf()->constInt(0, false)
        );

        return $context->builder->and($lenEq, $strEq);
    }

    /**
     * Strict equality between a boxed {@see __value__} and a native {@see __string__}.
     */
    public static function identicalValueToString(
        Context $context,
        Variable $boxed,
        Value $nativeStr
    ): Value {
        if (Variable::TYPE_VALUE !== $boxed->type) {
            throw new \LogicException('Expected boxed __value__ operand');
        }
        $valuePtr = Variable::KIND_VARIABLE === $boxed->kind
            ? $boxed->value
            : $context->helper->loadValue($boxed);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $stringTag = $context->getTypeFromString('int8')->constInt(Variable::TYPE_STRING & 0xff, false);
        $isString = $context->builder->icmp(Builder::INT_EQ, $typeByte, $stringTag);
        $boxedStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
        $same = self::identical($context, $boxedStr, $nativeStr);
        $falseVal = $context->getTypeFromString('int1')->constInt(0, false);

        return $context->builder->select($isString, $same, $falseVal);
    }

    public static function identicalStringToValue(
        Context $context,
        Value $nativeStr,
        Variable $boxed
    ): Value {
        return self::identicalValueToString($context, $boxed, $nativeStr);
    }
}
