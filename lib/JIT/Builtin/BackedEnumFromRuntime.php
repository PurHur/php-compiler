<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for BackedEnum::from() / ::tryFrom() via EnumFromJitHelper PHP (#10273).
 *
 * SSOT: {@see \PHPCompiler\VM\EnumFromJitHelper}, {@see \PHPCompiler\VM\BackedEnum}
 * php-src: Zend/zend_enum.c — zend_enum_from_case(), zend_try_enum_from_case()
 */
final class BackedEnumFromRuntime
{
    private const HELPER_PATH = '/VM/EnumFromJitHelper.php';

    private const NS = 'PHPCompiler\\VM\\EnumFromJitHelper::';

    private const STRING_FROM_STRING = self::NS.'stringBackingFromString';

    private const STRING_FROM_LONG = self::NS.'stringBackingFromLong';

    private const STRING_FROM_DOUBLE = self::NS.'stringBackingFromDouble';

    private const STRING_FROM_BOOL = self::NS.'stringBackingFromBool';

    private const STRING_FROM_NULL = self::NS.'stringBackingFromNull';

    private const INT_FROM_LONG = self::NS.'intBackingFromLong';

    private const INT_FROM_DOUBLE = self::NS.'intBackingFromDouble';

    private const INT_FROM_STRING = self::NS.'intBackingFromString';

    private const MATCH_STRING_PACKED = self::NS.'matchStringBackingPacked';

    private const MATCH_INT_CSV = self::NS.'matchIntBackingCsv';

    private const FORMAT_STRING_VE = self::NS.'formatStringValueError';

    private const FORMAT_INT_VE = self::NS.'formatIntValueError';

    private const INT_TYPE_ERROR = self::NS.'intTypeErrorSuffix';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STRING_FROM_STRING,
        self::STRING_FROM_LONG,
        self::STRING_FROM_DOUBLE,
        self::STRING_FROM_BOOL,
        self::STRING_FROM_NULL,
        self::INT_FROM_LONG,
        self::INT_FROM_DOUBLE,
        self::INT_FROM_STRING,
        self::MATCH_STRING_PACKED,
        self::MATCH_INT_CSV,
        self::FORMAT_STRING_VE,
        self::FORMAT_INT_VE,
        self::INT_TYPE_ERROR,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureExternals($context);
        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#10273');
    }

    public static function normalizeStringBacking(Context $context, Value $valuePtr): Value
    {
        self::ensureLinked($context);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $fn = BasicBlockHelper::parentFunction($context);
        $stringBlock = $fn->appendBasicBlock('enum_from_norm_str_string');
        $intBlock = $fn->appendBasicBlock('enum_from_norm_str_int');
        $floatBlock = $fn->appendBasicBlock('enum_from_norm_str_float');
        $boolBlock = $fn->appendBasicBlock('enum_from_norm_str_bool');
        $nullBlock = $fn->appendBasicBlock('enum_from_norm_str_null');
        $doneBlock = $fn->appendBasicBlock('enum_from_norm_str_done');
        $typeErrorEmit = $fn->appendBasicBlock('enum_from_norm_str_type_error');

        $afterString = $fn->appendBasicBlock('enum_from_norm_str_after_string');
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
        $afterInt = $fn->appendBasicBlock('enum_from_norm_str_after_int');
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
        $afterFloat = $fn->appendBasicBlock('enum_from_norm_str_after_float');
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
        $afterBool = $fn->appendBasicBlock('enum_from_norm_str_after_bool');
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
        ExceptionBridge::emitTypeError($context, 'Argument #1 ($value) must be of type string given');
        $context->builder->branch($doneBlock);

        $strPtrTy = $context->getTypeFromString('__string__*');
        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($strPtrTy, 'enum_from_norm_str_phi');
        $phi->addIncoming($stringVal, $stringEnd);
        $phi->addIncoming($intStr, $intEnd);
        $phi->addIncoming($floatStr, $floatEnd);
        $phi->addIncoming($boolStr, $boolEnd);
        $phi->addIncoming($emptyStr, $nullEnd);
        $phi->addIncoming($emptyStr, $typeErrorEmit);

        return $phi;
    }

    public static function normalizeIntBacking(Context $context, string $className, Value $valuePtr): Value
    {
        self::ensureLinked($context);
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
        $typeErrorEmit = $fn->appendBasicBlock('enum_from_norm_int_type_error');

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
        $message = self::callStringHelper(
            $context,
            self::INT_TYPE_ERROR,
            self::literalString($context, $className)
        );
        self::raiseTypeErrorFromString($context, $message);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i64, 'enum_from_norm_int_phi');
        $phi->addIncoming($longVal, $longEnd);
        $phi->addIncoming($floatInt, $floatEnd);
        $phi->addIncoming($stringInt, $stringEnd);
        $phi->addIncoming($i64->constInt(0, false), $typeErrorEmit);

        return $phi;
    }

    public static function matchStringBacking(
        Context $context,
        Value $normalizedStr,
        string $packedBackings,
        int $caseCount
    ): Value {
        self::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $packed = $context->builder->load($context->constantStringFromString($packedBackings));
        $index = $context->builder->call(
            JitVmHelperLink::lookupCompiled($context, self::MATCH_STRING_PACKED, '#10273'),
            $normalizedStr,
            $packed,
            $i64->constInt($caseCount, false)
        );

        return $context->builder->truncOrBitCast($index, $i64);
    }

    public static function matchIntBacking(Context $context, Value $normalizedInt, string $backingCsv): Value
    {
        self::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $csv = $context->builder->load($context->constantStringFromString($backingCsv));
        $index = $context->builder->call(
            JitVmHelperLink::lookupCompiled($context, self::MATCH_INT_CSV, '#10273'),
            $normalizedInt,
            $csv
        );

        return $context->builder->truncOrBitCast($index, $i64);
    }

    public static function emitStringValueError(Context $context, string $className, Value $strPtr): void
    {
        self::ensureLinked($context);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $message = self::callStringHelper(
            $context,
            self::FORMAT_STRING_VE,
            $strPtr,
            self::literalString($context, $className)
        );
        self::raiseValueErrorFromString($context, $message);
    }

    public static function emitIntValueError(Context $context, string $className, Value $intVal): void
    {
        self::ensureLinked($context);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $message = self::callStringHelper(
            $context,
            self::FORMAT_INT_VE,
            $intVal,
            self::literalString($context, $className)
        );
        self::raiseValueErrorFromString($context, $message);
    }

    private static function callStringHelper(Context $context, string $logical, Value ...$args): Value
    {
        return $context->builder->call(
            JitVmHelperLink::lookupCompiled($context, $logical, '#10273'),
            ...$args
        );
    }

    private static function literalString(Context $context, string $literal): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($literal), false),
            $context->builder->pointerCast($context->constantFromString($literal), $charPtr)
        );
    }

    private static function raiseValueErrorFromString(Context $context, Value $messageStr): void
    {
        self::raisePendingFromString($context, $messageStr, '__compiler_jit_raise_value_error');
    }

    private static function raiseTypeErrorFromString(Context $context, Value $messageStr): void
    {
        self::raisePendingFromString($context, $messageStr, '__compiler_jit_raise_type_error');
    }

    private static function raisePendingFromString(Context $context, Value $messageStr, string $callee): void
    {
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $map = $context->structFieldMap['__string__'];
        $data = $context->builder->structGep($messageStr, $map['value']);
        $len = $context->builder->load(
            $context->builder->structGep($messageStr, $map['length'])
        );
        $context->builder->call(
            $context->lookupFunction($callee),
            $context->builder->pointerCast($data, $charPtr),
            $context->builder->zExt($len, $sizeT)
        );
    }

    private static function ensureExternals(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $longTy = $context->getTypeFromString('int64');
        $i8pp = $context->getTypeFromString('int8**');
        $charPtr = $context->getTypeFromString('char*');
        $int32 = $context->getTypeFromString('int32');
        foreach ([
            ['snprintf', $i32, [$charPtr, $sizeT, $charPtr]],
            ['strtol', $longTy, [$i8p, $i8pp, $int32]],
        ] as [$name, $ret, $params]) {
            if (null === $context->module->getNamedFunction($name)) {
                $ft = $context->context->functionType($ret, false, ...$params);
                $context->registerFunction($name, $context->module->addFunction($name, $ft));
            }
        }
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
}
