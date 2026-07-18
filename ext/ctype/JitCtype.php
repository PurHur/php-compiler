<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ctype;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\CtypeRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM JIT helper for ctype_* (php-src ext/ctype/ctype.c; #7253, #9234, #19717, #20611). */
final class JitCtype
{
    public static function invoke(Context $context, JITVariable $arg, string $function): Value
    {
        $spec = VmCtype::specForFunction($function);
        $literal = JitStringArg::compileTimeLiteral($arg);
        if (null !== $literal) {
            return self::boolConst(
                $context,
                VmCtype::checkString($literal, $spec['kind'])
            );
        }
        if (JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            // mixed $text — ctype_fallback for null (never TypeError; #20611 / #19717).
            self::emitFallbackDeprecation($context, $function, 'null');

            return self::boolConst($context, false);
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && null !== $arg->compileTimeLong) {
            // php-src ctype_fallback: int still deprecated (#19717).
            self::emitFallbackDeprecation($context, $function, 'int');

            return self::boolConst(
                $context,
                VmCtype::checkInt(
                    (int) $arg->compileTimeLong,
                    $spec['kind'],
                    $spec['allow_digits'],
                    $spec['allow_minus']
                )
            );
        }

        CtypeRuntime::ensureLinked($context);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $zero = $i32->constInt(0, false);
        $kindConst = $i8->constInt($spec['kind'], false);
        $allowDigits = $i8->constInt($spec['allow_digits'] ? 1 : 0, false);
        $allowMinus = $i8->constInt($spec['allow_minus'] ? 1 : 0, false);

        if (JITVariable::TYPE_STRING === $arg->type) {
            $result = $context->builder->call(
                $context->lookupFunction('__phpc_ctype_check_string'),
                $arg->value,
                $kindConst
            );

            return $context->builder->icmp(Builder::INT_NE, $result, $zero);
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            self::emitFallbackDeprecation($context, $function, 'int');
            $result = $context->builder->call(
                $context->lookupFunction('__phpc_ctype_check_long'),
                $arg->value,
                $kindConst,
                $allowDigits,
                $allowMinus
            );

            return $context->builder->icmp(Builder::INT_NE, $result, $zero);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::lowerFromValue(
                $context,
                $arg,
                $function,
                $kindConst,
                $allowDigits,
                $allowMinus,
                $zero
            );
        }

        self::emitFallbackDeprecation($context, $function, 'mixed');

        return self::boolConst($context, false);
    }

    private static function emitFallbackDeprecation(Context $context, string $function, string $typeName): void
    {
        $message = sprintf(
            '%s(): Argument of type %s will be interpreted as string in the future',
            $function,
            $typeName
        );
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $sizeT->constInt(\strlen($message), false);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(ErrorReporter::E_DEPRECATED, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }

    private static function boolConst(Context $context, bool $value): Value
    {
        return $context->getTypeFromString('int1')->constInt($value ? 1 : 0, false);
    }

    private static function lowerFromValue(
        Context $context,
        JITVariable $arg,
        string $function,
        Value $kindConst,
        Value $allowDigits,
        Value $allowMinus,
        Value $zero
    ): Value {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');

        $stringBlock = BasicBlockHelper::append($context, 'ctype_value_string');
        $longBlock = BasicBlockHelper::append($context, 'ctype_value_long');
        $nullBlock = BasicBlockHelper::append($context, 'ctype_value_null');
        $falseBlock = BasicBlockHelper::append($context, 'ctype_value_false');
        $doneBlock = BasicBlockHelper::append($context, 'ctype_value_done');
        $afterStringCheck = BasicBlockHelper::append($context, 'ctype_value_after_string');
        $afterLongCheck = BasicBlockHelper::append($context, 'ctype_value_after_long');

        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_STRING & 0x7f, false)
            ),
            $stringBlock,
            $afterStringCheck
        );

        $context->builder->positionAtEnd($stringBlock);
        $strPtr = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $stringResult = $context->builder->call(
            $context->lookupFunction('__phpc_ctype_check_string'),
            $strPtr,
            $kindConst
        );
        $stringEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterStringCheck);
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)
            ),
            $longBlock,
            $afterLongCheck
        );

        $context->builder->positionAtEnd($longBlock);
        self::emitFallbackDeprecation($context, $function, 'int');
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $longResult = $context->builder->call(
            $context->lookupFunction('__phpc_ctype_check_long'),
            $longVal,
            $kindConst,
            $allowDigits,
            $allowMinus
        );
        $longEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterLongCheck);
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_NULL & 0x7f, false)
            ),
            $nullBlock,
            $falseBlock
        );

        $context->builder->positionAtEnd($nullBlock);
        // mixed $text — ctype_fallback for null (never TypeError; #20611 / #19717).
        self::emitFallbackDeprecation($context, $function, 'null');
        $nullEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($falseBlock);
        // Non-string/non-int fallback (bool/float/object/array/…) — php-src ctype_fallback (#19717).
        self::emitFallbackDeprecation($context, $function, 'mixed');
        $falseEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i32, 'ctype_value_result');
        $phi->addIncoming($stringResult, $stringEnd);
        $phi->addIncoming($longResult, $longEnd);
        $phi->addIncoming($zero, $nullEnd);
        $phi->addIncoming($zero, $falseEnd);

        return $context->builder->icmp(Builder::INT_NE, $phi, $zero);
    }
}
