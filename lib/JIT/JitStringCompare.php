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
                return self::identical($context, $leftStr, $rightStr);
            case OpCode::TYPE_EQUAL:
                return JitValueCompare::looseEqualStringToString($context, $leftStr, $rightStr);
            case OpCode::TYPE_NOT_IDENTICAL:
            case OpCode::TYPE_NOT_EQUAL:
                $same = self::identical($context, $leftStr, $rightStr);
                $i1 = $context->getTypeFromString('int1');

                return $context->builder->xor($same, $i1->constInt(1, false));
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
        $i1 = $context->getTypeFromString('int1');
        $falseVal = $i1->constInt(0, false);

        // __string__ is length-tracked and not guaranteed to be null-terminated; use memcmp
        // guarded by length equality (strcmp can read past the buffer and/or mismatch).
        $lenOk = BasicBlockHelper::append($context, 'jit_strcmp_len_ok');
        $lenBad = BasicBlockHelper::append($context, 'jit_strcmp_len_bad');
        $merge = BasicBlockHelper::append($context, 'jit_strcmp_done');
        $context->builder->branchIf($lenEq, $lenOk, $lenBad);

        $context->builder->positionAtEnd($lenBad);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($lenOk);
        $sizeT = $context->getTypeFromString('size_t');
        $len = $context->builder->zExt($leftLen, $sizeT);
        $cmp = $context->builder->call(
            $context->lookupFunction('memcmp'),
            $context->builder->structGep($leftStr, $map['value']),
            $context->builder->structGep($rightStr, $map['value']),
            $len
        );
        $strEq = $context->builder->icmp(
            Builder::INT_EQ,
            $cmp,
            $cmp->typeOf()->constInt(0, false)
        );
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($falseVal, $lenBad);
        $phi->addIncoming($strEq, $lenOk);

        return $phi;
    }

    /**
     * Strict equality between a boxed {@see __value__} and a native {@see __string__}.
     */
    public static function identicalValueToString(
        Context $context,
        Variable $boxed,
        Value $nativeStr
    ): Value {
        if (!JitValueBox::isValueOperand($boxed)) {
            throw new \LogicException('Expected boxed __value__ operand');
        }
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $boxed);
        $boxedStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
        $nullStr = $context->getTypeFromString('__string__*')->constNull();
        $hasString = $context->builder->icmp(
            Builder::INT_NE,
            $boxedStr,
            $nullStr
        );
        $same = self::identical($context, $boxedStr, $nativeStr);
        $falseVal = $context->getTypeFromString('int1')->constInt(0, false);

        return $context->builder->select($hasString, $same, $falseVal);
    }

    public static function identicalStringToValue(
        Context $context,
        Value $nativeStr,
        Variable $boxed
    ): Value {
        return self::identicalValueToString($context, $boxed, $nativeStr);
    }
}
