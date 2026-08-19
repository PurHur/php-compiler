<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\FsDirRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for touch() via __compiler_touch (php-in-PHP FsDirRuntime; #32510). */
final class JitTouch
{
    /** @return Value */
    public static function invoke(Context $context, Value $pathStr, Value $mtimeLong, Value $atimeLong): Value
    {
        FsDirRuntime::ensureLinked($context);
        $i32 = $context->getTypeFromString('int32');
        $ret = $context->builder->call(
            $context->lookupFunction('__compiler_touch'),
            $pathStr,
            $mtimeLong,
            $atimeLong
        );
        $one = $i32->constInt(1, false);

        return $context->builder->icmp(Builder::INT_EQ, $ret, $one);
    }

    /** php-src Z_PARAM_LONG_OR_NULL for touch() mtime/atime (#4989). */
    public static function lowerNullableLong(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName,
        Value $nullSentinel
    ): Value {
        if (JITVariable::TYPE_NULL === $arg->type) {
            return $nullSentinel;
        }
        if (JITVariable::TYPE_STRING === $arg->type && $context->callerStrictTypes) {
            self::emitNullableLongTypeErrorAndAbort($context, $argIndex, $paramName, 'string');

            return $nullSentinel;
        }
        if (($arg->type & JITVariable::IS_NATIVE_ARRAY) || JITVariable::TYPE_HASHTABLE === $arg->type) {
            self::emitNullableLongTypeErrorAndAbort($context, $argIndex, $paramName, 'array');

            return $nullSentinel;
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            self::emitNullableLongTypeErrorAndAbort($context, $argIndex, $paramName, 'object');

            return $nullSentinel;
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxedNullableLong($context, $arg, $argIndex, $paramName, $nullSentinel);
        }

        return JitLongArg::lower($context, $arg, sprintf('touch() argument #%d', $argIndex));
    }

    private static function lowerBoxedNullableLong(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName,
        Value $nullSentinel
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
        $arrayTy = $i8->constInt(VmVariable::TYPE_ARRAY, false);
        $objectTy = $i8->constInt(VmVariable::TYPE_OBJECT, false);

        $notNullBlock = BasicBlockHelper::append($context, 'touch_nullable_long_not_null');
        $nullBlock = BasicBlockHelper::append($context, 'touch_nullable_long_null');
        $arrayBlock = BasicBlockHelper::append($context, 'touch_nullable_long_array');
        $objectBlock = BasicBlockHelper::append($context, 'touch_nullable_long_object');
        $coerceBlock = BasicBlockHelper::append($context, 'touch_nullable_long_coerce');
        $mergeBlock = BasicBlockHelper::append($context, 'touch_nullable_long_merge');

        $isNull = $context->builder->icmp(Builder::INT_EQ, $typeByte, $nullTy);
        $context->builder->branchIf($isNull, $nullBlock, $notNullBlock);

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($notNullBlock);
        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeByte, $arrayTy);
        $context->builder->branchIf($isArray, $arrayBlock, $objectBlock);

        $context->builder->positionAtEnd($arrayBlock);
        self::emitNullableLongTypeErrorAndAbort($context, $argIndex, $paramName, 'array');

        $context->builder->positionAtEnd($objectBlock);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeByte, $objectTy);
        $errBlock = BasicBlockHelper::append($context, 'touch_nullable_long_err');
        $context->builder->branchIf($isObject, $errBlock, $coerceBlock);

        $context->builder->positionAtEnd($errBlock);
        self::emitNullableLongTypeErrorAndAbort($context, $argIndex, $paramName, 'object');

        $context->builder->positionAtEnd($coerceBlock);
        $longVal = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valuePtr
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($context->getTypeFromString('int64'));
        $phi->addIncoming($nullSentinel, $nullBlock);
        $phi->addIncoming($longVal, $coerceBlock);

        return $phi;
    }

    private static function nullableLongTypeError(int $argIndex, string $paramName, string $given): string
    {
        return sprintf(
            'touch(): Argument #%d ($%s) must be of type ?int, %s given',
            $argIndex,
            $paramName,
            $given
        );
    }

    private static function emitNullableLongTypeErrorAndAbort(
        Context $context,
        int $argIndex,
        string $paramName,
        string $given
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, self::nullableLongTypeError($argIndex, $paramName, $given));
        $context->builder->call($context->lookupFunction('abort'));
    }
}
