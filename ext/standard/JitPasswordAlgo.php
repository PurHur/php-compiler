<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** JIT lowering for password_hash() $algo — int or string (php-src password.c, issue #5039). */
final class JitPasswordAlgo
{
    public static function lower(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): Value {
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return $context->getTypeFromString('int64')->constInt(VmPassword::PASSWORD_BCRYPT, false);
        }
        $folded = self::lowerCompileTimeStringAlgo($context, $arg);
        if (null !== $folded) {
            return $folded;
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return self::lowerNativeInt($context, $arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxed($context, $arg, $function, $argIndex, $paramName);
        }

        self::emitTypeErrorAndAbort(
            $context,
            $function,
            $argIndex,
            $paramName,
            JitOperandTypeLabel::givenLabel($context, $arg)
        );

        return $context->getTypeFromString('int64')->constInt(0, false);
    }

    /** Fold PASSWORD_* string literals without strcmp CFG (#9275). */
    private static function lowerCompileTimeStringAlgo(Context $context, JITVariable $arg): ?Value
    {
        $lit = JitStringArg::compileTimeLiteral($arg);
        if (null === $lit && JITVariable::TYPE_STRING === $arg->type) {
            $lit = $arg->compileTimeString;
        } elseif (null === $lit && JITVariable::TYPE_VALUE === $arg->type) {
            $lit = $arg->compileTimeString;
        }
        if (null === $lit) {
            return null;
        }
        $i64 = $context->getTypeFromString('int64');
        if ('2y' === $lit) {
            return $i64->constInt(VmPassword::PASSWORD_BCRYPT, false);
        }
        if ('argon2i' === $lit && VmPasswordNative::argon2Available()) {
            return $i64->constInt(VmPassword::PASSWORD_ARGON2I, false);
        }
        if ('argon2id' === $lit && VmPasswordNative::argon2Available()) {
            return $i64->constInt(VmPassword::PASSWORD_ARGON2ID, false);
        }

        TypeErrorRaise::emitBranchOrAbortOnValueErrorFailure(
            $context,
            $context->constantFromBool(false),
            'pw_algo_const_str',
            VmPassword::PASSWORD_ALGO_INVALID_MSG
        );

        return $i64->constInt(VmPassword::PASSWORD_BCRYPT, false);
    }

    private static function lowerNativeInt(Context $context, JITVariable $arg): Value
    {
        $algo = $context->helper->loadValue($arg);
        TypeErrorRaise::emitBranchOrAbortOnValueErrorFailure(
            $context,
            self::algoIsSupported($context, $algo),
            'pw_algo_native',
            VmPassword::PASSWORD_ALGO_INVALID_MSG
        );

        return $algo;
    }

    private static function lowerBoxed(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): Value {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);

        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $nullTy = $i8->constInt(VmVariable::TYPE_NULL, false);
        $intTy = $i8->constInt(VmVariable::TYPE_INTEGER, false);
        $strTy = $i8->constInt(VmVariable::TYPE_STRING, false);
        $enumTy = $i8->constInt(VmVariable::TYPE_ENUM_CASE, false);
        $i64 = $context->getTypeFromString('int64');
        $bcrypt = $i64->constInt(VmPassword::PASSWORD_BCRYPT, false);

        $nullBlock = BasicBlockHelper::append($context, 'pw_algo_box_null');
        $typeBlock = BasicBlockHelper::append($context, 'pw_algo_box_type');
        $intBlock = BasicBlockHelper::append($context, 'pw_algo_box_int');
        $strBlock = BasicBlockHelper::append($context, 'pw_algo_box_str');
        $enumBlock = BasicBlockHelper::append($context, 'pw_algo_box_enum');
        $badBlock = BasicBlockHelper::append($context, 'pw_algo_box_bad');
        $doneBlock = BasicBlockHelper::append($context, 'pw_algo_box_done');

        $isNull = $context->builder->icmp(Builder::INT_EQ, $typeByte, $nullTy);
        $context->builder->branchIf($isNull, $nullBlock, $typeBlock);

        $context->builder->positionAtEnd($nullBlock);
        $nullEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($typeBlock);
        $isInt = $context->builder->icmp(Builder::INT_EQ, $typeByte, $intTy);
        $context->builder->branchIf($isInt, $intBlock, $strBlock);

        $context->builder->positionAtEnd($intBlock);
        $intVal = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valuePtr
        );
        TypeErrorRaise::emitBranchOrAbortOnValueErrorFailure(
            $context,
            self::algoIsSupported($context, $intVal),
            'pw_algo_box_int',
            VmPassword::PASSWORD_ALGO_INVALID_MSG
        );
        $intEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($strBlock);
        $isStr = $context->builder->icmp(Builder::INT_EQ, $typeByte, $strTy);
        $strBody = BasicBlockHelper::append($context, 'pw_algo_box_str_body');
        $context->builder->branchIf($isStr, $strBody, $enumBlock);

        $context->builder->positionAtEnd($strBody);
        $strPtr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
        // strcmp(3) via LibcExtern::ensureStrcmpDecl after always-on drop (#31971).
        LibcExtern::ensureStrcmpDecl($context);
        $is2y = $context->builder->call(
            $context->lookupFunction('strcmp'),
            self::stringData($context, $strPtr),
            self::cstr($context, '2y')
        );
        $i32 = $context->getTypeFromString('int32');
        TypeErrorRaise::emitBranchOrAbortOnValueErrorFailure(
            $context,
            $context->builder->icmp(Builder::INT_EQ, $is2y, $i32->constInt(0, false)),
            'pw_algo_box_str',
            VmPassword::PASSWORD_ALGO_INVALID_MSG
        );
        $strEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($enumBlock);
        $isEnum = $context->builder->icmp(Builder::INT_EQ, $typeByte, $enumTy);
        $enumErr = BasicBlockHelper::append($context, 'pw_algo_box_enum_err');
        $context->builder->branchIf($isEnum, $enumErr, $badBlock);
        $context->builder->positionAtEnd($enumErr);
        self::emitTypeErrorAndAbort(
            $context,
            $function,
            $argIndex,
            $paramName,
            JitOperandTypeLabel::givenLabel($context, $arg)
        );

        $context->builder->positionAtEnd($badBlock);
        self::emitTypeErrorAndAbort(
            $context,
            $function,
            $argIndex,
            $paramName,
            JitOperandTypeLabel::givenLabel($context, $arg)
        );

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i64);
        $phi->addIncoming($bcrypt, $nullEnd);
        $phi->addIncoming($intVal, $intEnd);
        $phi->addIncoming($bcrypt, $strEnd);

        return $phi;
    }

    /** php-src php_password_algo_find — PASSWORD_BCRYPT / PASSWORD_ARGON2* only (#5039). */
    private static function algoIsSupported(Context $context, Value $algo): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $isBcrypt = $context->builder->icmp(
            Builder::INT_EQ,
            $algo,
            $i64->constInt(VmPassword::PASSWORD_BCRYPT, false)
        );
        if (!VmPasswordNative::argon2Available()) {
            return $isBcrypt;
        }
        $isArgon2i = $context->builder->icmp(
            Builder::INT_EQ,
            $algo,
            $i64->constInt(VmPassword::PASSWORD_ARGON2I, false)
        );
        $isArgon2id = $context->builder->icmp(
            Builder::INT_EQ,
            $algo,
            $i64->constInt(VmPassword::PASSWORD_ARGON2ID, false)
        );

        return $context->builder->or($isBcrypt, $context->builder->or($isArgon2i, $isArgon2id));
    }

    private static function stringData(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->structGep($str, $map['value']),
            $context->getTypeFromString('int8*')
        );
    }

    private static function cstr(Context $context, string $literal): Value
    {
        return $context->pointerFromStringConstant($literal);
    }

    private static function emitTypeErrorAndAbort(
        Context $context,
        string $function,
        int $argIndex,
        string $paramName,
        string $given
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            \sprintf(
                '%s(): Argument #%d ($%s) must be of type string|int, %s given',
                $function,
                $argIndex + 1,
                $paramName,
                $given
            )
        );
        $context->builder->call($context->lookupFunction('abort'));
    }
}
