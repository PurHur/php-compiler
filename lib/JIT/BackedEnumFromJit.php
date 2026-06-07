<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT lowering for BackedEnum::from() / ::tryFrom() (#4053, Zend/zend_enum.c).
 */
final class BackedEnumFromJit
{
    private const SNPRINTF_BUF = 256;

    public static function emitFromFunction(
        Context $context,
        ObjectBuiltin $object,
        int $classId,
        string $className,
        string $backedType,
        bool $isTry
    ): void {
        $caseKeys = $object->enumCaseOrderForClass($classId);
        if ([] === $caseKeys) {
            return;
        }

        $valuePtrTy = $context->getTypeFromString('__value__*');
        $fnType = $context->context->functionType($valuePtrTy, false, $valuePtrTy);
        $method = $isTry ? 'tryfrom' : 'from';
        $funcName = strtolower(ltrim($className, '\\')).'::'.$method;
        if ($context->functionIsRegistered($funcName)) {
            return;
        }

        self::ensureExternals($context);

        $fn = $context->module->addFunction($funcName, $fnType);
        $lc = strtolower($funcName);
        $context->functions[$lc] = $fn;
        $context->functionProxies[$lc] = new Call\Native($fn, $funcName, [$valuePtrTy]);

        $object->defineMethodVisibility(
            $classId,
            $method,
            \PHPCfg\Func::FLAG_PUBLIC | \PHPCfg\Func::FLAG_STATIC,
            $isTry ? 'tryFrom' : 'from'
        );

        $restore = $context->builder->getInsertBlock();
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $arg = $fn->getParam(0);

        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);

        if ('string' === $backedType) {
            self::emitStringBackedBody($context, $object, $classId, $className, $caseKeys, $arg, $isTry);
        } elseif ('int' === $backedType) {
            self::emitIntBackedBody($context, $object, $classId, $className, $caseKeys, $arg, $isTry);
        } else {
            throw new \LogicException('Unsupported enum backing type for JIT from(): '.$backedType);
        }

        if (null !== $restore) {
            $context->builder->positionAtEnd($restore);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * @param list<string> $caseKeys
     */
    private static function emitStringBackedBody(
        Context $context,
        ObjectBuiltin $object,
        int $classId,
        string $className,
        array $caseKeys,
        Value $arg,
        bool $isTry
    ): void {
        $fn = BasicBlockHelper::parentFunction($context);
        $typeErrorBlock = $fn->appendBasicBlock('enum_from_string_type_error');
        $noMatchBlock = $fn->appendBasicBlock('enum_from_string_no_match');

        $normalized = self::normalizeValueBoxToString(
            $context,
            $arg,
            $typeErrorBlock,
            'Argument #1 ($value) must be of type string'
        );

        $lastIdx = count($caseKeys) - 1;
        foreach ($caseKeys as $idx => $caseKey) {
            $matchBlock = $fn->appendBasicBlock('enum_from_string_match_'.$idx);
            $nextBlock = $idx === $lastIdx ? $noMatchBlock : $fn->appendBasicBlock('enum_from_string_next_'.$idx);
            $expected = (string) $object->enumCaseBackingScalarForCase($classId, $caseKey);
            $expectedPtr = $context->builder->load($context->constantStringFromString($expected));
            $context->builder->branchIf(
                $context->builder->icmp(
                    Builder::INT_EQ,
                    $context->builder->call(
                        $context->lookupFunction('strcmp'),
                        self::stringDataPtr($context, $normalized),
                        self::stringDataPtr($context, $expectedPtr)
                    ),
                    $context->getTypeFromString('int32')->constInt(0, false)
                ),
                $matchBlock,
                $nextBlock
            );
            $context->builder->positionAtEnd($matchBlock);
            $context->builder->returnValue(self::returnEnumCaseValue($context, $object, $classId, $caseKey));
            $context->builder->positionAtEnd($nextBlock);
        }

        $context->builder->positionAtEnd($noMatchBlock);
        if ($isTry) {
            $context->builder->returnValue(self::returnNullValue($context));
        } else {
            self::emitStringValueError($context, $className, $normalized);
            $context->builder->returnValue(self::returnNullValue($context));
        }

        $context->builder->positionAtEnd($typeErrorBlock);
        $context->builder->returnValue(self::returnNullValue($context));
    }

    /**
     * @param list<string> $caseKeys
     */
    private static function emitIntBackedBody(
        Context $context,
        ObjectBuiltin $object,
        int $classId,
        string $className,
        array $caseKeys,
        Value $arg,
        bool $isTry
    ): void {
        $fn = BasicBlockHelper::parentFunction($context);
        $typeErrorBlock = $fn->appendBasicBlock('enum_from_int_type_error');
        $noMatchBlock = $fn->appendBasicBlock('enum_from_int_no_match');

        $normalized = self::normalizeValueBoxToInt(
            $context,
            $className,
            $arg,
            $typeErrorBlock
        );

        $i64 = $context->getTypeFromString('int64');
        $lastIdx = count($caseKeys) - 1;
        foreach ($caseKeys as $idx => $caseKey) {
            $matchBlock = $fn->appendBasicBlock('enum_from_int_match_'.$idx);
            $nextBlock = $idx === $lastIdx ? $noMatchBlock : $fn->appendBasicBlock('enum_from_int_next_'.$idx);
            $expected = (int) $object->enumCaseBackingScalarForCase($classId, $caseKey);
            $context->builder->branchIf(
                $context->builder->icmp(
                    Builder::INT_EQ,
                    $normalized,
                    $i64->constInt($expected, false)
                ),
                $matchBlock,
                $nextBlock
            );
            $context->builder->positionAtEnd($matchBlock);
            $context->builder->returnValue(self::returnEnumCaseValue($context, $object, $classId, $caseKey));
            $context->builder->positionAtEnd($nextBlock);
        }

        $context->builder->positionAtEnd($noMatchBlock);
        if ($isTry) {
            $context->builder->returnValue(self::returnNullValue($context));
        } else {
            self::emitIntValueError($context, $className, $normalized);
            $context->builder->returnValue(self::returnNullValue($context));
        }

        $context->builder->positionAtEnd($typeErrorBlock);
        $context->builder->returnValue(self::returnNullValue($context));
    }

    private static function returnEnumCaseValue(Context $context, ObjectBuiltin $object, int $classId, string $caseKey): Value
    {
        $caseVar = $object->jitEnumCaseFromBacking($classId, $caseKey);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $objPtr = Variable::KIND_VALUE === $caseVar->kind
            ? $caseVar->value
            : $context->builder->load($caseVar->value);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $objPtr
        );

        return $ptr;
    }

    private static function returnNullValue(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $ptr
        );

        return $ptr;
    }

    private static function normalizeValueBoxToString(
        Context $context,
        Value $valuePtr,
        Value $typeErrorBlock,
        string $typeErrorSuffix
    ): Value {
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $fn = BasicBlockHelper::parentFunction($context);
        $stringBlock = $fn->appendBasicBlock('enum_from_norm_string');
        $intBlock = $fn->appendBasicBlock('enum_from_norm_int');
        $floatBlock = $fn->appendBasicBlock('enum_from_norm_float');
        $boolBlock = $fn->appendBasicBlock('enum_from_norm_bool');
        $nullBlock = $fn->appendBasicBlock('enum_from_norm_null');
        $doneBlock = $fn->appendBasicBlock('enum_from_norm_string_done');
        $typeErrorEmit = $fn->appendBasicBlock('enum_from_norm_string_type_error_emit');

        $afterString = $fn->appendBasicBlock('enum_from_norm_after_string');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_STRING, false)),
            $stringBlock,
            $afterString
        );
        $context->builder->positionAtEnd($stringBlock);
        $stringVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $stringEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterString);
        $afterInt = $fn->appendBasicBlock('enum_from_norm_after_int');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_NATIVE_LONG, false)),
            $intBlock,
            $afterInt
        );
        $context->builder->positionAtEnd($intBlock);
        $intVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $intStr = JitNativeString::formatIndexKey($context, $intVal);
        $intEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterInt);
        $afterFloat = $fn->appendBasicBlock('enum_from_norm_after_float');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)),
            $floatBlock,
            $afterFloat
        );
        $context->builder->positionAtEnd($floatBlock);
        $floatVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $valuePtr);
        $floatStr = self::formatDoubleString($context, $floatVal);
        $floatEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterFloat);
        $afterBool = $fn->appendBasicBlock('enum_from_norm_after_bool');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)),
            $boolBlock,
            $afterBool
        );
        $context->builder->positionAtEnd($boolBlock);
        $boolVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $oneStr = $context->builder->load($context->constantStringFromString('1'));
        $zeroStr = $context->builder->load($context->constantStringFromString('0'));
        $boolStr = $context->builder->select(
            $context->builder->icmp(Builder::INT_NE, $boolVal, $context->getTypeFromString('int64')->constInt(0, false)),
            $oneStr,
            $zeroStr
        );
        $boolEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterBool);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_NULL, false)),
            $nullBlock,
            $typeErrorEmit
        );
        $context->builder->positionAtEnd($nullBlock);
        $emptyStr = $context->builder->load($context->constantStringFromString(''));
        $nullEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($typeErrorEmit);
        ExceptionBridge::emitTypeError($context, $typeErrorSuffix);
        $context->builder->branch($typeErrorBlock);

        $context->builder->positionAtEnd($doneBlock);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $phi = $context->builder->phi($strPtrTy, 'enum_from_norm_string_phi');
        $phi->addIncoming($stringVal, $stringEnd);
        $phi->addIncoming($intStr, $intEnd);
        $phi->addIncoming($floatStr, $floatEnd);
        $phi->addIncoming($boolStr, $boolEnd);
        $phi->addIncoming($emptyStr, $nullEnd);

        return $phi;
    }

    private static function normalizeValueBoxToInt(
        Context $context,
        string $className,
        Value $valuePtr,
        Value $typeErrorBlock
    ): Value {
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $fn = BasicBlockHelper::parentFunction($context);
        $longBlock = $fn->appendBasicBlock('enum_from_norm_int_long');
        $floatBlock = $fn->appendBasicBlock('enum_from_norm_int_float');
        $stringBlock = $fn->appendBasicBlock('enum_from_norm_int_string');
        $doneBlock = $fn->appendBasicBlock('enum_from_norm_int_done');
        $typeErrorEmit = $fn->appendBasicBlock('enum_from_norm_int_type_error_emit');

        $afterLong = $fn->appendBasicBlock('enum_from_norm_int_after_long');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_NATIVE_LONG, false)),
            $longBlock,
            $afterLong
        );
        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $longEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterLong);
        $afterFloat = $fn->appendBasicBlock('enum_from_norm_int_after_float');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)),
            $floatBlock,
            $afterFloat
        );
        $context->builder->positionAtEnd($floatBlock);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $valuePtr);
        $floatInt = $context->builder->fpToSi($doubleVal, $i64);
        $floatEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterFloat);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_STRING, false)),
            $stringBlock,
            $typeErrorEmit
        );
        $context->builder->positionAtEnd($stringBlock);
        $stringVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $stringInt = self::stringToInt($context, $stringVal);
        $stringEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($typeErrorEmit);
        ExceptionBridge::emitTypeError(
            $context,
            $className.'::from(): Argument #1 ($value) must be of type int, mixed given'
        );
        $context->builder->branch($typeErrorBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i64, 'enum_from_norm_int_phi');
        $phi->addIncoming($longVal, $longEnd);
        $phi->addIncoming($floatInt, $floatEnd);
        $phi->addIncoming($stringInt, $stringEnd);

        return $phi;
    }

    private static function emitStringValueError(Context $context, string $className, Value $strPtr): void
    {
        $written = self::snprintfToStack(
            $context,
            '"%s" is not a valid backing value for enum %s',
            [self::stringDataPtr($context, $strPtr), self::literalCstr($context, $className)]
        );
        self::emitDynamicValueError($context, $written);
    }

    private static function emitIntValueError(Context $context, string $className, Value $intVal): void
    {
        $written = self::snprintfToStack(
            $context,
            '%lld is not a valid backing value for enum %s',
            [$intVal, self::literalCstr($context, $className)]
        );
        self::emitDynamicValueError($context, $written);
    }

    private static function emitDynamicValueError(Context $context, Value $written): void
    {
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $buf = $written[0];
        $len = $written[1];
        $context->builder->call(
            $context->lookupFunction('__compiler_jit_raise_value_error'),
            $context->builder->pointerCast($buf, $charPtr),
            $context->builder->zExt($len, $sizeT)
        );
    }

    private static function formatDoubleString(Context $context, Value $doubleVal): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');
        $i64 = $context->getTypeFromString('int64');
        $bufSize = $sizeT->constInt(64, false);
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufSize);
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $fmt = $context->builder->pointerCast($context->constantFromString('%G'), $charPtr);
        $written = $context->builder->call($context->lookupFunction('snprintf'), $bufChar, $bufSize, $fmt, $doubleVal);
        $len = $context->builder->zExt($written, $i64);
        $str = $context->builder->call($context->lookupFunction('__string__init'), $len, $bufChar);
        $context->builder->call($context->lookupFunction('__mm__free'), $buf);

        return $str;
    }

    private static function stringToInt(Context $context, Value $strPtr): Value
    {
        $ptr = self::stringDataPtr($context, $strPtr);
        $endPtr = $context->getTypeFromString('int8**')->constNull();
        $i64 = $context->getTypeFromString('int64');
        $base = $context->builder->trunc($i64->constInt(10, false), $context->getTypeFromString('int32'));
        $raw = $context->builder->call($context->lookupFunction('strtol'), $ptr, $endPtr, $base);

        return $context->builder->trunc($raw, $i64);
    }

    private static function stringDataPtr(Context $context, Value $strPtr): Value
    {
        return $context->builder->structGep($strPtr, $context->structFieldIndex($strPtr, 'value'));
    }

    private static function literalCstr(Context $context, string $literal): Value
    {
        return $context->builder->pointerCast(
            $context->constantFromString($literal),
            $context->getTypeFromString('char*')
        );
    }

    /**
     * @param list<Value> $extraArgs
     *
     * @return array{0: Value, 1: Value}
     */
    private static function snprintfToStack(Context $context, string $fmt, array $extraArgs): array
    {
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');
        $i32 = $context->getTypeFromString('int32');
        $buf = $context->builder->alloca($i8, self::SNPRINTF_BUF, 'enum_from_snprintf');
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $sizeT->constInt(self::SNPRINTF_BUF, false),
            $context->builder->pointerCast($context->constantFromString($fmt), $charPtr),
            ...$extraArgs
        );

        return [$bufChar, $context->builder->trunc($written, $i32)];
    }

    private static function ensureExternals(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $longTy = $context->getTypeFromString('int64');
        $i8pp = $context->getTypeFromString('int8**');
        $charPtr = $context->getTypeFromString('char*');
        $longRet = $context->getTypeFromString('long');
        $int32 = $context->getTypeFromString('int32');
        foreach ([
            ['strcmp', $i32, [$i8p, $i8p]],
            ['snprintf', $i32, [$charPtr, $sizeT, $charPtr]],
            ['strtol', $longRet, [$i8p, $i8pp, $int32]],
        ] as [$name, $ret, $params]) {
            if (null === $context->module->getNamedFunction($name)) {
                $ft = $context->context->functionType($ret, false, ...$params);
                $context->registerFunction($name, $context->module->addFunction($name, $ft));
            }
        }
    }
}
