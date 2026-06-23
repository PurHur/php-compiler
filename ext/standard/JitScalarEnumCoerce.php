<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\Type\Object_ as JitObjectType;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT lowering for enum-case scalar coercion (#5623, #5714, #7120).
 *
 * Zend php-src (8.2+): explicit (int)/(float) casts and intval/floatval on enum cases
 * emit E_WARNING and yield legacy object cast 1 / 1.0, not backing scalars.
 */
final class JitScalarEnumCoerce
{
    private const ENUM_CASE_SLOT_VALUE = 1;

    /**
     * Emit enum-case scalar coercion when $objPtr is a declared enum; otherwise branch to $nonEnumTarget.
     *
     * @return Value|null LLVM scalar when matched (caller merges into phi); null when branched to $nonEnumTarget
     */
    public static function tryEmitObjectEnumCaseToLong(
        Context $context,
        Value $objPtr,
        string $function,
        BasicBlock $nonEnumTarget
    ): ?Value {
        return self::tryEmitObjectEnumCaseToScalar($context, $objPtr, $function, 'int', $nonEnumTarget, false);
    }

    public static function tryEmitObjectEnumCaseToDouble(
        Context $context,
        Value $objPtr,
        string $function,
        BasicBlock $nonEnumTarget
    ): ?Value {
        return self::tryEmitObjectEnumCaseToScalar($context, $objPtr, $function, 'float', $nonEnumTarget, false);
    }

    /**
     * Zend (int)/(float) cast on enum case — E_WARNING + legacy 1 / 1.0 (#5714, #5791, zend_operators.c).
     */
    public static function tryEmitObjectEnumCaseLegacyCastToLong(
        Context $context,
        Value $objPtr,
        string $function,
        BasicBlock $nonEnumTarget
    ): ?Value {
        return self::tryEmitObjectEnumCaseToScalar($context, $objPtr, $function, 'int', $nonEnumTarget, true);
    }

    public static function tryEmitObjectEnumCaseLegacyCastToDouble(
        Context $context,
        Value $objPtr,
        string $function,
        BasicBlock $nonEnumTarget
    ): ?Value {
        return self::tryEmitObjectEnumCaseToScalar($context, $objPtr, $function, 'float', $nonEnumTarget, true);
    }

    /**
     * Zend settype($x, 'string') on enum cases — Error, not backing coercion (#8861, ext/standard/type.c).
     *
     * @return bool true when enum matched and Error was emitted
     */
    public static function tryEmitObjectEnumCaseStringError(
        Context $context,
        Value $objPtr,
        string $function,
        BasicBlock $nonEnumTarget
    ): bool {
        $jitObject = $context->type->object;
        if (!$jitObject instanceof JitObjectType) {
            $context->builder->branch($nonEnumTarget);

            return false;
        }
        $enumNames = $jitObject->allDeclaredEnumLowerNames();
        if ([] === $enumNames) {
            $context->builder->branch($nonEnumTarget);

            return false;
        }
        $map = $context->structFieldMap['__object__'];
        $runtimeClassId = $context->builder->load(
            $context->builder->structGep($objPtr, $map['class_id'])
        );
        $i64 = $context->getTypeFromString('int64');
        $fn = BasicBlockHelper::parentFunction($context);
        $checkBlock = $context->builder->getInsertBlock();
        $ids = [];
        foreach ($enumNames as $lc) {
            $ids[] = $jitObject->lookup($lc);
        }
        $lastIdx = \count($ids) - 1;
        foreach ($ids as $idx => $enumId) {
            $context->builder->positionAtEnd($checkBlock);
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $runtimeClassId,
                $i64->constInt($enumId, false)
            );
            $caseBlock = $fn->appendBasicBlock($function.'_enum_string_err_'.$enumId);
            $nextBlock = $idx === $lastIdx
                ? $nonEnumTarget
                : $fn->appendBasicBlock($function.'_enum_string_try_'.($idx + 1));
            $context->builder->branchIf($match, $caseBlock, $nextBlock);
            $context->builder->positionAtEnd($caseBlock);
            ErrorRaise::ensureLinked($context);
            ErrorRaise::emitRaise(
                $context,
                'Object of class '.$jitObject->classNameForId($enumId).' could not be converted to string'
            );

            return true;
        }
        if ($checkBlock !== $nonEnumTarget) {
            $context->builder->positionAtEnd($checkBlock);
            $context->builder->branch($nonEnumTarget);
        }

        return false;
    }

    private static function tryEmitObjectEnumCaseToScalar(
        Context $context,
        Value $objPtr,
        string $function,
        string $kind,
        BasicBlock $nonEnumTarget,
        bool $legacyObjectCast
    ): ?Value {
        $jitObject = $context->type->object;
        if (!$jitObject instanceof JitObjectType) {
            $context->builder->branch($nonEnumTarget);

            return null;
        }
        $enumNames = $jitObject->allDeclaredEnumLowerNames();
        if ([] === $enumNames) {
            $context->builder->branch($nonEnumTarget);

            return null;
        }
        $map = $context->structFieldMap['__object__'];
        $runtimeClassId = $context->builder->load(
            $context->builder->structGep($objPtr, $map['class_id'])
        );
        $i64 = $context->getTypeFromString('int64');
        $destType = 'int' === $kind ? $i64 : $context->getTypeFromString('double');
        $destSlot = BasicBlockHelper::entryAlloca($context, $destType);
        $fn = BasicBlockHelper::parentFunction($context);
        $checkBlock = $context->builder->getInsertBlock();
        $matchedBlock = $fn->appendBasicBlock($function.'_enum_scalar_matched');
        $ids = [];
        foreach ($enumNames as $lc) {
            $ids[] = $jitObject->lookup($lc);
        }
        $lastIdx = \count($ids) - 1;
        foreach ($ids as $idx => $enumId) {
            $context->builder->positionAtEnd($checkBlock);
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $runtimeClassId,
                $i64->constInt($enumId, false)
            );
            $caseBlock = $fn->appendBasicBlock($function.'_enum_scalar_'.$enumId);
            $nextBlock = $idx === $lastIdx
                ? $nonEnumTarget
                : $fn->appendBasicBlock($function.'_enum_scalar_try_'.($idx + 1));
            $context->builder->branchIf($match, $caseBlock, $nextBlock);
            $context->builder->positionAtEnd($caseBlock);
            self::emitObjectScalarWarning($context, $jitObject->classNameForId($enumId), $kind);
            $scalar = self::enumCaseBackingScalar($context, $objPtr, $enumId, $jitObject, $kind, $legacyObjectCast);
            $context->builder->store($scalar, $destSlot);
            $context->builder->branch($matchedBlock);
            $checkBlock = $nextBlock;
        }
        if ($checkBlock !== $nonEnumTarget) {
            $context->builder->positionAtEnd($checkBlock);
            $context->builder->branch($nonEnumTarget);
        }

        $context->builder->positionAtEnd($matchedBlock);

        return $context->builder->load($destSlot);
    }

    private static function enumCaseBackingScalar(
        Context $context,
        Value $objPtr,
        int $enumId,
        JitObjectType $jitObject,
        string $kind,
        bool $legacyObjectCast
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        if ($legacyObjectCast || !$jitObject->enumHasBacking($enumId)) {
            return 'int' === $kind
                ? $i64->constInt(1, false)
                : $double->constReal(1.0);
        }
        $slot = self::propertySlotPtr($context, $objPtr, self::ENUM_CASE_SLOT_VALUE);
        $storage = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__'));
        $valueMap = $context->structFieldMap['__value__'];
        $context->builder->store(
            $context->getTypeFromString('int8')->constInt(JITVariable::TYPE_NULL, false),
            $context->builder->structGep($storage, $valueMap['type'])
        );
        $context->builder->call(
            $context->lookupFunction('__object__load_value_slot'),
            $slot,
            $storage
        );
        $backingPtr = JitValueBox::pointer($context, $storage);

        return 'int' === $kind
            ? self::valueBoxToLong($context, $backingPtr)
            : self::valueBoxToDouble($context, $backingPtr);
    }

    private static function valueBoxToLong(Context $context, Value $valuePtr): Value
    {
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $longBlock = BasicBlockHelper::append($context, 'enum_backing_long');
        $doubleBlock = BasicBlockHelper::append($context, 'enum_backing_double');
        $stringBlock = BasicBlockHelper::append($context, 'enum_backing_string');
        $doneBlock = BasicBlockHelper::append($context, 'enum_backing_long_done');
        $afterTag = BasicBlockHelper::append($context, 'enum_backing_long_after_tag');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)),
            $longBlock,
            $afterTag
        );
        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $longEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($afterTag);
        $afterDouble = BasicBlockHelper::append($context, 'enum_backing_long_after_double');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_DOUBLE, false)),
            $doubleBlock,
            $afterDouble
        );
        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $valuePtr);
        $doubleInt = $context->builder->fpToSi($doubleVal, $i64);
        $doubleEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($afterDouble);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_STRING, false)),
            $stringBlock,
            $doneBlock
        );
        $context->builder->positionAtEnd($stringBlock);
        $stringVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $ptr = self::stringDataPtr($context, $stringVal);
        $endPtr = $context->getTypeFromString('int8**')->constNull();
        $base = $context->builder->trunc($i64->constInt(10, false), $context->getTypeFromString('int32'));
        $raw = $context->builder->call($context->lookupFunction('strtol'), $ptr, $endPtr, $base);
        $stringInt = $context->builder->trunc($raw, $i64);
        $stringEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i64, 'enum_backing_long_phi');
        $phi->addIncoming($longVal, $longEnd);
        $phi->addIncoming($doubleInt, $doubleEnd);
        $phi->addIncoming($stringInt, $stringEnd);
        $phi->addIncoming($i64->constInt(0, false), $afterDouble);

        return $phi;
    }

    private static function valueBoxToDouble(Context $context, Value $valuePtr): Value
    {
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $double = $context->getTypeFromString('double');
        $longBlock = BasicBlockHelper::append($context, 'enum_backing_flong');
        $doubleBlock = BasicBlockHelper::append($context, 'enum_backing_fdouble');
        $stringBlock = BasicBlockHelper::append($context, 'enum_backing_fstring');
        $doneBlock = BasicBlockHelper::append($context, 'enum_backing_fdouble_done');
        $afterTag = BasicBlockHelper::append($context, 'enum_backing_fdouble_after_tag');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)),
            $longBlock,
            $afterTag
        );
        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $longFloat = $context->builder->siToFp($longVal, $double);
        $longEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($afterTag);
        $afterDouble = BasicBlockHelper::append($context, 'enum_backing_fdouble_after_double');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_DOUBLE, false)),
            $doubleBlock,
            $afterDouble
        );
        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $valuePtr);
        $nativeEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($afterDouble);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_STRING, false)),
            $stringBlock,
            $doneBlock
        );
        $context->builder->positionAtEnd($stringBlock);
        $stringVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $ptr = self::stringDataPtr($context, $stringVal);
        $endPtr = $context->getTypeFromString('int8**')->constNull();
        $stringFloat = $context->builder->call($context->lookupFunction('strtod'), $ptr, $endPtr);
        $stringEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($double, 'enum_backing_fdouble_phi');
        $phi->addIncoming($longFloat, $longEnd);
        $phi->addIncoming($doubleVal, $nativeEnd);
        $phi->addIncoming($stringFloat, $stringEnd);
        $phi->addIncoming($double->constReal(0.0), $afterDouble);

        return $phi;
    }

    private static function propertySlotPtr(Context $context, Value $obj, int $slotIndex): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        $voidpp = $context->getTypeFromString('void**');
        $cast = $context->builder->pointerCast($obj, $i8p);
        $headerSize = $context->builder->ptrToInt(
            $context->builder->gep(
                $context->getTypeFromString('__object__')->pointerType(0)->constNull(),
                $context->context->int32Type()->constInt(1, false)
            ),
            $context->getTypeFromString('size_t')
        );
        $slotOff = $context->builder->add(
            $headerSize,
            $context->constantFromInteger(8 * $slotIndex, 'size_t')
        );

        return $context->builder->pointerCast(
            $context->builder->gep($cast, $slotOff),
            $voidpp
        );
    }

    private static function stringDataPtr(Context $context, Value $strPtr): Value
    {
        $off = $context->structFieldIndex($strPtr, 'value');

        return $context->builder->structGep($strPtr, $off);
    }

    public static function emitObjectScalarWarning(Context $context, string $className, string $kind): void
    {
        $message = 'int' === $kind
            ? "Object of class {$className} could not be converted to int"
            : "Object of class {$className} could not be converted to float";
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $sizeT->constInt(\strlen($message), false),
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }
}
