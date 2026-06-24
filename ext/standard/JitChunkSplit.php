<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM JIT helper for chunk_split(). */
final class JitChunkSplit
{
    private const LENGTH_ERROR = 'chunk_split(): Argument #2 ($length) must be greater than 0';

    /** Lower chunk_split() $length with Z_PARAM_LONG parity (#6032, ext/standard/string.c). */
    public static function lowerLengthArg(Context $context, JITVariable $arg): Value
    {
        $enumLabel = JitOperandTypeLabel::compileTimeEnumClassName($context, $arg);
        if (null !== $enumLabel) {
            self::emitLengthTypeErrorAndAbort($context, $enumLabel);

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (($arg->type & JITVariable::IS_NATIVE_ARRAY) || JITVariable::TYPE_HASHTABLE === $arg->type) {
            self::emitLengthTypeErrorAndAbort($context, 'array');

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            self::emitLengthTypeErrorAndAbort($context, self::compileTimeObjectLabel($context, $arg));

            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxedLengthArg($context, $arg);
        }

        return JitLongArg::lower($context, $arg, 'chunk_split() length');
    }

    private static function lowerBoxedLengthArg(Context $context, JITVariable $arg): Value
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

        $okBlock = BasicBlockHelper::append($context, 'chunksplit_len_ok');
        $arrayBlock = BasicBlockHelper::append($context, 'chunksplit_len_array');
        $rejectBlock = BasicBlockHelper::append($context, 'chunksplit_len_reject');
        $coerceBlock = BasicBlockHelper::append($context, 'chunksplit_len_coerce');

        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeByte, $arrayTy);
        $context->builder->branchIf($isArray, $arrayBlock, $okBlock);

        $context->builder->positionAtEnd($arrayBlock);
        self::emitLengthTypeErrorAndAbort($context, 'array');

        $context->builder->positionAtEnd($okBlock);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeByte, $objectTy);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeByte, $enumCaseTy);
        $isObjOrEnum = $context->builder->or($isObject, $isEnumCase);
        $context->builder->branchIf($isObjOrEnum, $rejectBlock, $coerceBlock);

        $context->builder->positionAtEnd($rejectBlock);
        self::emitLengthTypeErrorAndAbort($context, self::compileTimeObjectLabel($context, $arg));

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

    private static function lengthTypeError(string $given): string
    {
        return sprintf(
            'chunk_split(): Argument #2 ($length) must be of type int, %s given',
            $given
        );
    }

    private static function emitLengthTypeErrorAndAbort(Context $context, string $given): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, self::lengthTypeError($given));
        $context->builder->call($context->lookupFunction('abort'));
    }

    /** Lower chunk_split() $string with Z_PARAM_STR parity (#4580, ext/standard/string.c). */
    public static function lowerStringSubject(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type || JITVariable::TYPE_OBJECT === $arg->type) {
            $given = JITVariable::TYPE_HASHTABLE === $arg->type ? 'array' : 'object';
            $errBlock = BasicBlockHelper::append($context, 'chunksplit_str_err');
            $context->builder->branch($errBlock);
            $context->builder->positionAtEnd($errBlock);
            self::emitTypeErrorAndAbort($context, self::typeErrorMessage($given));

            return self::unreachableStringPtr($context);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxedStringSubject($context, $arg);
        }
        if ($context->callerStrictTypes) {
            JitInternalStrictArg::requireString($context, $arg, 'chunk_split', 'string', 1);
        }

        return JitStringArg::lower($context, $arg, 'chunk_split() argument #1');
    }

    private static function lowerBoxedStringSubject(Context $context, JITVariable $arg): Value
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

        $okBlock = BasicBlockHelper::append($context, 'chunksplit_str_ok');
        $arrayBlock = BasicBlockHelper::append($context, 'chunksplit_str_array');
        $objectBlock = BasicBlockHelper::append($context, 'chunksplit_str_object');
        $strictBlock = BasicBlockHelper::append($context, 'chunksplit_str_strict');

        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeByte, $arrayTy);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeByte, $objectTy);
        $context->builder->branchIf($isArray, $arrayBlock, $okBlock);

        $context->builder->positionAtEnd($arrayBlock);
        self::emitTypeErrorAndAbort($context, self::typeErrorMessage('array'));

        $context->builder->positionAtEnd($okBlock);
        $context->builder->branchIf($isObject, $objectBlock, $strictBlock);

        $context->builder->positionAtEnd($objectBlock);
        self::emitTypeErrorAndAbort($context, self::typeErrorMessage('object'));

        $context->builder->positionAtEnd($strictBlock);
        if ($context->callerStrictTypes) {
            $isString = $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(VmVariable::TYPE_STRING, false)
            );
            $coerceBlock = BasicBlockHelper::append($context, 'chunksplit_str_coerce');
            $strictErrBlock = BasicBlockHelper::append($context, 'chunksplit_str_strict_err');
            $context->builder->branchIf($isString, $coerceBlock, $strictErrBlock);
            $context->builder->positionAtEnd($strictErrBlock);
            self::emitTypeErrorAndAbort($context, self::typeErrorMessage('mixed'));
            $context->builder->positionAtEnd($coerceBlock);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
    }

    private static function typeErrorMessage(string $given): string
    {
        return sprintf(
            'chunk_split(): Argument #1 ($string) must be of type string, %s given',
            $given
        );
    }

    private static function emitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function unreachableStringPtr(Context $context): Value
    {
        return $context->getTypeFromString('__string__*')->constNull();
    }

    /**
     * Runtime guard for non-constant length (issue #3763; avoids div-by-zero in split()).
     */
    public static function emitRuntimeLengthGuard(Context $context, Value $chunkLen): void
    {
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);
        $invalid = $context->builder->icmp(Builder::INT_SLT, $chunkLen, $one);
        $okBlock = BasicBlockHelper::append($context, 'chunksplit_len_ok');
        $errBlock = BasicBlockHelper::append($context, 'chunksplit_len_err');
        $context->builder->branchIf($invalid, $errBlock, $okBlock);
        $context->builder->positionAtEnd($errBlock);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, self::LENGTH_ERROR);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($okBlock);
    }

    public static function split(
        Context $context,
        Value $input,
        Value $chunkLen,
        Value $separator
    ): Value {
        $map = $context->structFieldMap['__string__'];
        $inLen = $context->builder->load(
            $context->builder->structGep($input, $map['length'])
        );
        $inPtr = $context->builder->structGep($input, $map['value']);
        $sepLen = $context->builder->load(
            $context->builder->structGep($separator, $map['length'])
        );
        $sepPtr = $context->builder->structGep($separator, $map['value']);

        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        $doneBlock = BasicBlockHelper::append($context, 'chunksplit_done');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $inLen, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'chunksplit_empty');
        $workBlock = BasicBlockHelper::append($context, 'chunksplit_work');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyStr = $context->builder->call($context->lookupFunction('__string__alloc'), $sepLen);
        $emptyDestPtr = $context->builder->structGep($emptyStr, $map['value']);
        $context->builder->store(
            $sepLen,
            $context->builder->structGep($emptyStr, $map['length'])
        );
        $context->intrinsic->memcpy($emptyDestPtr, $sepPtr, $sepLen, false);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $numChunks = $context->builder->unsignedDiv(
            $context->builder->add(
                $context->builder->sub($inLen, $one),
                $chunkLen
            ),
            $chunkLen
        );
        $outLen = $context->builder->add(
            $inLen,
            $context->builder->mul($numChunks, $sepLen)
        );
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $outLen);
        $destPtr = $context->builder->structGep($dest, $map['value']);
        $context->builder->store(
            $outLen,
            $context->builder->structGep($dest, $map['length'])
        );

        $inPosSlot = $context->builder->alloca($i64, 1, 'chunksplit_in_pos');
        $outPosSlot = $context->builder->alloca($i64, 1, 'chunksplit_out_pos');
        $context->builder->store($zero, $inPosSlot);
        $context->builder->store($zero, $outPosSlot);

        $loopHead = BasicBlockHelper::append($context, 'chunksplit_head');
        $loopBody = BasicBlockHelper::append($context, 'chunksplit_body');
        $loopDone = BasicBlockHelper::append($context, 'chunksplit_loop_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $inPos = $context->builder->load($inPosSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $inPos, $inLen);
        $context->builder->branchIf($stop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $inPos = $context->builder->load($inPosSlot);
        $outPos = $context->builder->load($outPosSlot);
        $remain = $context->builder->sub($inLen, $inPos);
        $thisLen = $context->builder->select(
            $context->builder->icmp(Builder::INT_SGT, $chunkLen, $remain),
            $remain,
            $chunkLen
        );
        $context->intrinsic->memcpy(
            $context->builder->gep($destPtr, $outPos),
            $context->builder->gep($inPtr, $inPos),
            $thisLen,
            false
        );
        $outAfterChunk = $context->builder->add($outPos, $thisLen);
        $context->intrinsic->memcpy(
            $context->builder->gep($destPtr, $outAfterChunk),
            $sepPtr,
            $sepLen,
            false
        );
        $context->builder->store(
            $context->builder->add($outAfterChunk, $sepLen),
            $outPosSlot
        );
        $context->builder->store(
            $context->builder->add($inPos, $chunkLen),
            $inPosSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $result = $context->builder->phi($dest->typeOf());
        $result->addIncoming($emptyStr, $emptyBlock);
        $result->addIncoming($dest, $loopDone);

        return $result;
    }
}
