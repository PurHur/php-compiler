<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StreamReadRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for stream_get_contents() via __compiler_stream_get_contents (#3142). */
final class JitStreamGetContents
{
    /** Z_PARAM_LONG_OR_NULL parity for $length (#6008, ext/standard/streamsfuncs.c). */
    public static function lowerLengthArg(Context $context, JITVariable $arg): Value
    {
        $enumLabel = JitOperandTypeLabel::compileTimeEnumClassName($context, $arg);
        if (null !== $enumLabel) {
            self::emitNullableLengthTypeErrorAndAbort($context, $enumLabel);

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (($arg->type & JITVariable::IS_NATIVE_ARRAY) || JITVariable::TYPE_HASHTABLE === $arg->type) {
            self::emitNullableLengthTypeErrorAndAbort($context, 'array');

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            self::emitNullableLengthTypeErrorAndAbort($context, self::compileTimeObjectLabel($context, $arg));

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (JITVariable::TYPE_NULL === $arg->type) {
            return $context->getTypeFromString('int64')->constInt(-1, true);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxedNullableLengthArg($context, $arg);
        }

        return JitLongArg::lower($context, $arg, 'stream_get_contents() length');
    }

    /** Z_PARAM_LONG parity for $offset (#6008). */
    public static function lowerOffsetArg(Context $context, JITVariable $arg): Value
    {
        $enumLabel = JitOperandTypeLabel::compileTimeEnumClassName($context, $arg);
        if (null !== $enumLabel) {
            self::emitOffsetTypeErrorAndAbort($context, $enumLabel);

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (($arg->type & JITVariable::IS_NATIVE_ARRAY) || JITVariable::TYPE_HASHTABLE === $arg->type) {
            self::emitOffsetTypeErrorAndAbort($context, 'array');

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            self::emitOffsetTypeErrorAndAbort($context, self::compileTimeObjectLabel($context, $arg));

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxedOffsetArg($context, $arg);
        }

        return JitLongArg::lower($context, $arg, 'stream_get_contents() offset');
    }

    private static function lowerBoxedNullableLengthArg(Context $context, JITVariable $arg): Value
    {
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
        $enumCaseTy = $i8->constInt(VmVariable::TYPE_ENUM_CASE, false);

        $nullBlock = BasicBlockHelper::append($context, 'stream_gc_len_null');
        $notNullBlock = BasicBlockHelper::append($context, 'stream_gc_len_not_null');
        $arrayBlock = BasicBlockHelper::append($context, 'stream_gc_len_array');
        $rejectBlock = BasicBlockHelper::append($context, 'stream_gc_len_reject');
        $coerceBlock = BasicBlockHelper::append($context, 'stream_gc_len_coerce');
        $mergeBlock = BasicBlockHelper::append($context, 'stream_gc_len_merge');

        $isNull = $context->builder->icmp(Builder::INT_EQ, $typeByte, $nullTy);
        $context->builder->branchIf($isNull, $nullBlock, $notNullBlock);

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($notNullBlock);
        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeByte, $arrayTy);
        $context->builder->branchIf($isArray, $arrayBlock, $coerceBlock);

        $context->builder->positionAtEnd($arrayBlock);
        self::emitNullableLengthTypeErrorAndAbort($context, 'array');

        $context->builder->positionAtEnd($coerceBlock);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeByte, $objectTy);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeByte, $enumCaseTy);
        $isObjOrEnum = $context->builder->or($isObject, $isEnumCase);
        $numericBlock = BasicBlockHelper::append($context, 'stream_gc_len_numeric');
        $context->builder->branchIf($isObjOrEnum, $rejectBlock, $numericBlock);

        $context->builder->positionAtEnd($rejectBlock);
        self::emitNullableLengthTypeErrorAndAbort($context, self::compileTimeObjectLabel($context, $arg));

        $context->builder->positionAtEnd($numericBlock);
        $longVal = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valuePtr
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $i64 = $context->getTypeFromString('int64');
        $phi = $context->builder->phi($i64);
        $phi->addIncoming($i64->constInt(-1, true), $nullBlock);
        $phi->addIncoming($longVal, $numericBlock);

        return $phi;
    }

    private static function lowerBoxedOffsetArg(Context $context, JITVariable $arg): Value
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $arrayTy = $i8->constInt(VmVariable::TYPE_ARRAY, false);
        $objectTy = $i8->constInt(VmVariable::TYPE_OBJECT, false);
        $enumCaseTy = $i8->constInt(VmVariable::TYPE_ENUM_CASE, false);

        $arrayBlock = BasicBlockHelper::append($context, 'stream_gc_off_array');
        $rejectBlock = BasicBlockHelper::append($context, 'stream_gc_off_reject');
        $coerceBlock = BasicBlockHelper::append($context, 'stream_gc_off_coerce');

        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeByte, $arrayTy);
        $okBlock = BasicBlockHelper::append($context, 'stream_gc_off_ok');
        $context->builder->branchIf($isArray, $arrayBlock, $okBlock);

        $context->builder->positionAtEnd($arrayBlock);
        self::emitOffsetTypeErrorAndAbort($context, 'array');

        $context->builder->positionAtEnd($okBlock);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeByte, $objectTy);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeByte, $enumCaseTy);
        $isObjOrEnum = $context->builder->or($isObject, $isEnumCase);
        $context->builder->branchIf($isObjOrEnum, $rejectBlock, $coerceBlock);

        $context->builder->positionAtEnd($rejectBlock);
        self::emitOffsetTypeErrorAndAbort($context, self::compileTimeObjectLabel($context, $arg));

        $context->builder->positionAtEnd($coerceBlock);

        return $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valuePtr
        );
    }

    private static function compileTimeObjectLabel(Context $context, JITVariable $arg): string
    {
        $enumLabel = JitOperandTypeLabel::compileTimeEnumClassName($context, $arg);
        if (null !== $enumLabel) {
            return $enumLabel;
        }
        if (JITVariable::KIND_VALUE !== $arg->kind) {
            return 'object';
        }
        $objMap = $context->structFieldMap['__object__'] ?? null;
        if (null === $objMap || !isset($objMap['class_id'])) {
            return 'object';
        }
        $classIdVal = $context->builder->load(
            $context->builder->structGep($arg->value, $objMap['class_id'])
        );
        if (!method_exists($classIdVal, 'isConstant') || !$classIdVal->isConstant()) {
            return 'object';
        }
        $classId = (int) $classIdVal->getConstantValue();

        return $context->type->object->classNameForId($classId);
    }

    private static function nullableLengthTypeError(string $given): string
    {
        return sprintf(
            'stream_get_contents(): Argument #2 ($length) must be of type ?int, %s given',
            $given
        );
    }

    private static function offsetTypeError(string $given): string
    {
        return sprintf(
            'stream_get_contents(): Argument #3 ($offset) must be of type int, %s given',
            $given
        );
    }

    private static function emitNullableLengthTypeErrorAndAbort(Context $context, string $given): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, self::nullableLengthTypeError($given));
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function emitOffsetTypeErrorAndAbort(Context $context, string $given): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, self::offsetTypeError($given));
        $context->builder->call($context->lookupFunction('abort'));
    }

    /** @return Value (string data, or boolean false on failure) */
    public static function invoke(
        Context $context,
        Value $handleLong,
        Value $maxlengthLong,
        Value $offsetLong,
    ): Value {
        StreamReadRuntime::ensureLinked($context);
        $contents = $context->builder->call(
            $context->lookupFunction('__compiler_stream_get_contents'),
            $handleLong,
            $maxlengthLong,
            $offsetLong
        );
        $strPtr = $context->getTypeFromString('__string__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $contents, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'stream_get_contents_fail');
        $okBlock = BasicBlockHelper::append($context, 'stream_get_contents_ok');
        $doneBlock = BasicBlockHelper::append($context, 'stream_get_contents_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $contents
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
