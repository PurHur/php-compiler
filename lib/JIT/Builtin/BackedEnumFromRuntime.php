<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\JitThrow;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT lowering for BackedEnum::from() / ::tryFrom() (#10273, #24208).
 *
 * Matching and error text are emitted as LLVM IR — nested VM helpers that call stdlib builtins
 * misbehave when reached from synthesized native enum::from() under thin standalone AOT (#24208).
 *
 * SSOT for coercion semantics: {@see \PHPCompiler\VM\EnumFromJitHelper}
 * php-src: Zend/zend_enum.c — zend_enum_from_case(), zend_try_enum_from_case()
 */
final class BackedEnumFromRuntime
{
    public static function ensureLinked(Context $context): void
    {
        self::ensureExternals($context);
    }

    public static function normalizeStringBacking(
        Context $context,
        Value $valuePtr,
        string $function = 'BackedEnum::from'
    ): Value {
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
        // Z_PARAM_STR_OR_LONG: finite float→long→str (+ E_DEPRECATED); NAN/INF→"NAN"/"INF" (#22947).
        $isFinite = MathIsFinite::invoke($context, $floatVal);
        $finiteBlock = $fn->appendBasicBlock('enum_from_norm_str_float_finite');
        $nonFiniteBlock = $fn->appendBasicBlock('enum_from_norm_str_float_nonfinite');
        $mergeFloat = $fn->appendBasicBlock('enum_from_norm_str_float_merge');
        $context->builder->branchIf($isFinite, $finiteBlock, $nonFiniteBlock);
        $context->builder->positionAtEnd($finiteBlock);
        $floatLong = JitIntdiv::floatToLongWithPrecisionWarning($context, $floatVal);
        $finiteStr = JitNativeString::formatIndexKey($context, $floatLong);
        $finiteEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeFloat);
        $context->builder->positionAtEnd($nonFiniteBlock);
        $nonFiniteStr = self::formatDoubleString($context, $floatVal);
        $nonFiniteEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeFloat);
        $context->builder->positionAtEnd($mergeFloat);
        $strPtrTyEarly = $context->getTypeFromString('__string__*');
        $floatStr = $context->builder->phi($strPtrTyEarly, 'enum_from_norm_str_float_phi');
        $floatStr->addIncoming($finiteStr, $finiteEnd);
        $floatStr->addIncoming($nonFiniteStr, $nonFiniteEnd);
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
        // Zend: E_DEPRECATED then null→"0" (same as false) (#20072, #26786).
        JitStringBuiltinArg::emitNullStringParamDeprecation(
            $context,
            $function,
            0,
            'value',
            'string|int'
        );
        $nullStr = $context->builder->load($context->constantStringFromString('0'));
        $nullEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($typeErrorEmit);
        ExceptionBridge::emitTypeError($context, 'Argument #1 ($value) must be of type string given');
        // Fresh load in this block — $nullStr from nullBlock does not dominate (#21109).
        $typeErrorStr = $context->builder->load($context->constantStringFromString('0'));
        $typeErrorEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $strPtrTy = $context->getTypeFromString('__string__*');
        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($strPtrTy, 'enum_from_norm_str_phi');
        $phi->addIncoming($stringVal, $stringEnd);
        $phi->addIncoming($intStr, $intEnd);
        $phi->addIncoming($floatStr, $floatEnd);
        $phi->addIncoming($boolStr, $boolEnd);
        $phi->addIncoming($nullStr, $nullEnd);
        $phi->addIncoming($typeErrorStr, $typeErrorEnd);

        return $phi;
    }

    public static function normalizeIntBacking(
        Context $context,
        string $className,
        Value $valuePtr,
        string $function = ''
    ): Value {
        if ('' === $function) {
            $function = $className.'::from';
        }
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
        $nullBlock = $fn->appendBasicBlock('enum_from_norm_int_null');
        $boolBlock = $fn->appendBasicBlock('enum_from_norm_int_bool');
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
        // Z_PARAM_LONG: finite float truncates (+ E_DEPRECATED on precision loss); NAN/INF → TypeError (#22947).
        $isFinite = MathIsFinite::invoke($context, $doubleVal);
        $floatOk = $fn->appendBasicBlock('enum_from_norm_int_float_ok');
        $floatBad = $fn->appendBasicBlock('enum_from_norm_int_float_bad');
        $context->builder->branchIf($isFinite, $floatOk, $floatBad);
        $context->builder->positionAtEnd($floatBad);
        $badMsg = self::literalString(
            $context,
            $className.'::from(): Argument #1 ($value) must be of type int, mixed given'
        );
        self::raiseTypeErrorFromString($context, $badMsg);
        $floatBadEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($floatOk);
        $floatInt = JitIntdiv::floatToLongWithPrecisionWarning($context, $doubleVal);
        $floatEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterFloat);
        $afterString = $fn->appendBasicBlock('enum_from_norm_int_after_string');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_STRING, false)),
            $stringBlock,
            $afterString
        );
        $context->builder->positionAtEnd($stringBlock);
        $stringVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $stringInt = self::stringToInt($context, $stringVal);
        $stringEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterString);
        $afterNull = $fn->appendBasicBlock('enum_from_norm_int_after_null');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_NULL, false)),
            $nullBlock,
            $afterNull
        );
        $context->builder->positionAtEnd($nullBlock);
        // Zend: E_DEPRECATED then null→0 under weak types (#20072, #26786).
        JitStringBuiltinArg::emitNullStringParamDeprecation(
            $context,
            $function,
            0,
            'value',
            'string|int'
        );
        $nullInt = $i64->constInt(0, false);
        $nullEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterNull);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)),
            $boolBlock,
            $typeErrorEmit
        );
        $context->builder->positionAtEnd($boolBlock);
        $boolVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $boolInt = $context->builder->select(
            $context->builder->icmp(Builder::INT_NE, $boolVal, $i64->constInt(0, false)),
            $i64->constInt(1, false),
            $i64->constInt(0, false)
        );
        $boolEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($typeErrorEmit);
        $message = self::literalString(
            $context,
            $className.'::from(): Argument #1 ($value) must be of type int, mixed given'
        );
        self::raiseTypeErrorFromString($context, $message);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i64, 'enum_from_norm_int_phi');
        $phi->addIncoming($longVal, $longEnd);
        $phi->addIncoming($floatInt, $floatEnd);
        $phi->addIncoming($i64->constInt(0, false), $floatBadEnd);
        $phi->addIncoming($stringInt, $stringEnd);
        $phi->addIncoming($nullInt, $nullEnd);
        $phi->addIncoming($boolInt, $boolEnd);
        $phi->addIncoming($i64->constInt(0, false), $typeErrorEmit);

        return $phi;
    }

    public static function emitStringValueError(Context $context, string $className, Value $strPtr): void
    {
        self::ensureLinked($context);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $message = self::formatStringBackingValueError($context, $strPtr, $className);
        self::raiseValueErrorFromString($context, $message);
    }

    public static function emitIntValueError(Context $context, string $className, Value $intVal): void
    {
        self::ensureLinked($context);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $message = self::formatIntBackingValueError($context, $intVal, $className);
        self::raiseValueErrorFromString($context, $message);
    }

    private static function formatStringBackingValueError(
        Context $context,
        Value $strPtr,
        string $enumName
    ): Value {
        self::ensureExternals($context);
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $map = $context->structFieldMap['__string__'];
        $data = $context->builder->pointerCast(
            $context->builder->structGep($strPtr, $map['value']),
            $charPtr
        );
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $bufSize = $sizeT->constInt(256, false);
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufSize);
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString('"%.*s" is not a valid backing value for enum %s'),
            $charPtr
        );
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $bufSize,
            $fmt,
            $context->builder->trunc($len, $i32),
            $data,
            $context->builder->pointerCast($context->constantFromString($enumName), $charPtr)
        );
        $outLen = $context->builder->zExt($written, $i64);
        $str = $context->builder->call($context->lookupFunction('__string__init'), $outLen, $bufChar);
        $context->builder->call($context->lookupFunction('__mm__free'), $buf);

        return $str;
    }

    private static function formatIntBackingValueError(
        Context $context,
        Value $intVal,
        string $enumName
    ): Value {
        self::ensureExternals($context);
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');
        $i64 = $context->getTypeFromString('int64');
        $bufSize = $sizeT->constInt(192, false);
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufSize);
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString('%lld is not a valid backing value for enum %s'),
            $charPtr
        );
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $bufSize,
            $fmt,
            $intVal,
            $context->builder->pointerCast($context->constantFromString($enumName), $charPtr)
        );
        $outLen = $context->builder->zExt($written, $i64);
        $str = $context->builder->call($context->lookupFunction('__string__init'), $outLen, $bufChar);
        $context->builder->call($context->lookupFunction('__mm__free'), $buf);

        return $str;
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

    /**
     * Invalid backing value — catchable ValueError (#24219).
     *
     * Synthesized {@code Enum::from()} is a separate LLVM function, so try/catch lives in the
     * caller. Set throw-pending (object) for catchable dispatch and the type-error pending
     * message for uncaught abort text; {@see TryCatchHelper::emitCheckPendingThrowAfterCall}.
     */
    private static function raiseValueErrorFromString(Context $context, Value $messageStr): void
    {
        JitThrow::registerDeclarations($context);
        JitThrow::ensureLinked($context);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);

        $object = $context->type->object;
        $classId = $object->lookup('ValueError');
        $obj = $object->allocate($classId);
        $object->markObjectConstructed($obj);
        $msgVar = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $messageStr
        );
        $object->storeInstanceProperty($obj, 'ValueError', 'message', $msgVar);

        $handler = TryCatchHelper::resolveThrowHandler($context);
        if (null !== $handler && null !== $handler->dispatchBb) {
            $context->builder->call($context->lookupFunction('phpc_jit_set_throw_pending'), $obj);
            $context->builder->branch($handler->dispatchBb);

            return;
        }

        // Cross-function: object pending for try/catch + string pending for uncaught abort.
        $context->builder->call($context->lookupFunction('phpc_jit_set_throw_pending'), $obj);
        self::raisePendingFromString($context, $messageStr, '__compiler_jit_raise_value_error');
    }

    private static function raiseTypeErrorFromString(Context $context, Value $messageStr): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        self::raisePendingFromString($context, $messageStr, '__compiler_jit_raise_type_error');
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_type_error'));
        } else {
            $context->builder->call($context->lookupFunction('abort'));
            $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        }
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
        // strtol(3) via LibcExtern::ensureStrtolDecl after always-on drop (#31988).
        LibcExtern::ensureStrtolDecl($context);
        $raw = $context->builder->call($context->lookupFunction('strtol'), $ptr, $endPtr, $base);

        return $context->builder->trunc($raw, $i64);
    }

    private static function stringDataPtr(Context $context, Value $strPtr): Value
    {
        return $context->builder->structGep($strPtr, $context->structFieldIndex($strPtr, 'value'));
    }
}
