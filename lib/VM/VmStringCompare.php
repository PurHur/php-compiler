<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\VM\VmValueCompare;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\OpCode;
use function PHPCompiler\opcode_type_name;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * SSOT for JIT native __string__ compare lowering (Zend zend_operators.c, #9972).
 *
 * php-src: Zend/zend_operators.c — compare_function string branch
 *
 * JIT trampoline: {@see \PHPCompiler\JIT\JitStringCompare}
 */

final class VmStringCompare
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
                return VmValueCompare::looseEqualStringToString($context, $leftStr, $rightStr);
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

    /** True when $haystack ends with the same bytes as $suffix (inventory argv absolute paths — #3046). */
    public static function suffixIdentical(Context $context, Value $haystack, Value $suffix): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $falseVal = $i1->constInt(0, false);
        $hayLen = $context->builder->load(
            $context->builder->structGep($haystack, $map['length'])
        );
        $suffixLen = $context->builder->load(
            $context->builder->structGep($suffix, $map['length'])
        );
        $lenOk = $context->builder->icmp(Builder::INT_SGE, $hayLen, $suffixLen);
        $lenOkBb = BasicBlockHelper::append($context, 'jit_suffix_len_ok');
        $lenBadBb = BasicBlockHelper::append($context, 'jit_suffix_len_bad');
        $mergeBb = BasicBlockHelper::append($context, 'jit_suffix_done');
        $context->builder->branchIf($lenOk, $lenOkBb, $lenBadBb);
        $context->builder->positionAtEnd($lenBadBb);
        $context->builder->branch($mergeBb);
        $context->builder->positionAtEnd($lenOkBb);
        $offset = $context->builder->sub($hayLen, $suffixLen);
        $i8p = $context->getTypeFromString('int8*');
        $hayChars = $context->builder->structGep($haystack, $map['value']);
        $hayTail = $context->builder->gep($hayChars, $offset);
        $suffixChars = $context->builder->structGep($suffix, $map['value']);
        $sizeT = $context->getTypeFromString('size_t');
        $cmp = $context->builder->call(
            $context->lookupFunction('memcmp'),
            $hayTail,
            $suffixChars,
            $context->builder->zExt($suffixLen, $sizeT)
        );
        $suffixEq = $context->builder->icmp(
            Builder::INT_EQ,
            $cmp,
            $cmp->typeOf()->constInt(0, false)
        );
        $context->builder->branch($mergeBb);
        $context->builder->positionAtEnd($mergeBb);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($falseVal, $lenBadBb);
        $phi->addIncoming($suffixEq, $lenOkBb);

        return $phi;
    }

    /**
     * Zend strcmp() ordering on native {@see __string__} operands (ext/standard/string.c).
     *
     * Length-tracked buffers are not guaranteed null-terminated; C strcmp on raw
     * char* can read past the buffer (bootstrap concat VALUE-box globals — #1492).
     */
    public static function strcmp(Context $context, Value $leftStr, Value $rightStr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $nullStr = $context->getTypeFromString('__string__*')->constNull();
        $emptyStr = $context->builder->load($context->constantStringFromString(''));
        $leftNull = $context->builder->icmp(Builder::INT_EQ, $leftStr, $nullStr);
        $rightNull = $context->builder->icmp(Builder::INT_EQ, $rightStr, $nullStr);
        $leftStr = $context->builder->select($leftNull, $emptyStr, $leftStr);
        $rightStr = $context->builder->select($rightNull, $emptyStr, $rightStr);
        $leftLen = $context->builder->load(
            $context->builder->structGep($leftStr, $map['length'])
        );
        $rightLen = $context->builder->load(
            $context->builder->structGep($rightStr, $map['length'])
        );
        $leftLtRight = $context->builder->icmp(Builder::INT_SLT, $leftLen, $rightLen);
        $minLen = $context->builder->select($leftLtRight, $leftLen, $rightLen);
        $cmp = $context->builder->call(
            $context->lookupFunction('memcmp'),
            $context->builder->structGep($leftStr, $map['value']),
            $context->builder->structGep($rightStr, $map['value']),
            $context->builder->zExt($minLen, $sizeT)
        );
        $cmpNeZero = $context->builder->icmp(
            Builder::INT_NE,
            $cmp,
            $cmp->typeOf()->constInt(0, false)
        );
        $prefixResult = $context->builder->sExt($cmp, $i64);
        $lenDiff = $context->builder->sub($leftLen, $rightLen);

        return $context->builder->select($cmpNeZero, $prefixResult, $lenDiff);
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
        JitVariable $boxed,
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
        JitVariable $boxed
    ): Value {
        return self::identicalValueToString($context, $boxed, $nativeStr);
    }
}
