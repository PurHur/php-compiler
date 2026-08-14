<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** Shared LLVM helpers for native reflection object setup (#1936). */
final class ReflectionSetup
{
    public static function loadObjectFromArg(Context $context, Variable $arg): Value
    {
        $raw = $context->helper->loadValue($arg);
        $rawTy = $context->getStringFromType($raw->typeOf());
        if ('__object__*' === $rawTy) {
            return $raw;
        }
        if ('__value__' === $rawTy) {
            $slot = JitValueBox::alloc($context);
            $context->builder->store($raw, $slot);
            $raw = JitValueBox::pointer($context, $slot);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $raw
        );
    }

    public static function markConstructed(Context $context, Value $obj): void
    {
        $map = $context->structFieldMap['__object__'];
        $i8 = $context->getTypeFromString('int8');
        $context->builder->store(
            $i8->constInt(1, false),
            $context->builder->structGep($obj, $map['constructed'])
        );
    }

    public static function emitSetStringPropertyFromCstr(
        Context $context,
        Value $obj,
        string $className,
        string $propName,
        Value $cstr,
        Value $len
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $len64 = $context->builder->zExt($len, $i64);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $cstr, $cstr->typeOf()->constNull());
        $empty = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $safe = $context->builder->select($isNull, $empty, $cstr);
        $len64 = $context->builder->select($isNull, $i64->constInt(0, false), $len64);
        $str = $context->builder->call($context->lookupFunction('__string__init'), $len64, $safe);
        $slot = $context->type->object->propertySlotFor($obj, $className, $propName);
        $valueType = $context->getTypeFromString('__value__');
        $heapVal = $context->memory->malloc($valueType);
        $heapPtr = $context->builder->pointerCast(
            $heapVal,
            $context->getTypeFromString('__value__*')
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $heapPtr,
            $str
        );
        // Property slots are void**; store void* (not i8*/bytePtr) — LLVM verify (#26828 / #26795).
        $voidPtr = $context->getTypeFromString('void*');
        $context->builder->store(
            $context->builder->pointerCast($heapPtr, $voidPtr),
            $slot
        );
    }

    public static function emitSetClassFromStringVar(Context $context, Value $obj, Variable $nameVar): void
    {
        self::emitSetStringPropertyFromVar(
            $context,
            $obj,
            'ReflectionClass',
            ReflectionSupport::PROP_CLASS_NAME,
            $nameVar
        );
    }

    /**
     * @return array{0: Value, 1: Value} cstr pointer and byte length (size_t)
     */
    public static function reflectionClassNameAsCstr(Context $context, Value $obj): array
    {
        return self::stringPropertyAsCstr(
            $context,
            $obj,
            'ReflectionClass',
            ReflectionSupport::PROP_CLASS_NAME
        );
    }

    /**
     * @return array{0: Value, 1: Value, 2: Value, 3: Value} class cstr/len, method cstr/len
     */
    public static function reflectionMethodClassAndMethodAsCstr(Context $context, Value $obj): array
    {
        [$classCstr, $classLen] = self::stringPropertyAsCstr(
            $context,
            $obj,
            'ReflectionMethod',
            ReflectionSupport::PROP_REFLECTION_METHOD_CLASS
        );
        [$methodCstr, $methodLen] = self::stringPropertyAsCstr(
            $context,
            $obj,
            'ReflectionMethod',
            ReflectionSupport::PROP_REFLECTION_METHOD_FUNC
        );

        return [$classCstr, $classLen, $methodCstr, $methodLen];
    }

    public static function emitSetStringPropertyFromVar(
        Context $context,
        Value $obj,
        string $className,
        string $propName,
        Variable $nameVar
    ): void {
        $nameVar = JitNativeString::coerce($context, $nameVar);
        $strPtr = $context->helper->loadValue($nameVar);
        $i8p = $context->getTypeFromString('int8*');
        $raw = $context->builder->pointerCast($strPtr, $i8p);
        $lenPtr = $context->builder->pointerCast(
            $context->builder->gep($raw, $context->constantFromInteger(8, 'size_t')),
            $context->getTypeFromString('int64*')
        );
        $len = $context->builder->load($lenPtr);
        $data = $context->builder->gep($raw, $context->constantFromInteger(16, 'size_t'));
        self::emitSetStringPropertyFromCstr(
            $context,
            $obj,
            $className,
            $propName,
            $context->builder->pointerCast($data, $i8p),
            $context->builder->zExt($len, $context->getTypeFromString('size_t'))
        );
    }

    /** Store a native long into an object property slot (#22044 ReflectionAttribute::$target). */
    public static function emitSetIntegerProperty(
        Context $context,
        Value $obj,
        string $className,
        string $propName,
        int $value
    ): void {
        $longVar = new Variable(
            $context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $context->constantFromInteger($value)
        );
        $slot = $context->type->object->propertySlotFor($obj, $className, $propName);
        $context->type->object->propertyStore($slot, $longVar, Variable::TYPE_NATIVE_LONG);
    }

    /**
     * @return array{0: Value, 1: Value} cstr pointer and byte length (size_t)
     */
    public static function stringPropertyAsCstr(
        Context $context,
        Value $obj,
        string $className,
        string $propName
    ): array {
        $propVar = $context->type->object->propertyFetch($obj, $className, $propName);
        // emitSetStringPropertyFromCstr stores a heap __value__* (string box) in the
        // property slot even when the declared JIT type is TYPE_STRING. Reading the
        // slot as a raw __string__* segfaults under AOT (#21551) — mirror
        // ReflectionAttributeGetName / ExceptionGetMessage via __value__readString.
        if (Variable::TYPE_VALUE === $propVar->type) {
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $propVar);
        } else {
            $valuePtr = $context->builder->pointerCast(
                $context->helper->loadValue($propVar),
                $context->getTypeFromString('__value__*')
            );
        }
        $strPtr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
        $i8p = $context->getTypeFromString('int8*');
        $raw = $context->builder->pointerCast($strPtr, $i8p);
        $lenPtr = $context->builder->pointerCast(
            $context->builder->gep($raw, $context->constantFromInteger(8, 'size_t')),
            $context->getTypeFromString('int64*')
        );
        $len = $context->builder->load($lenPtr);
        $data = $context->builder->gep($raw, $context->constantFromInteger(16, 'size_t'));
        $sizeT = $context->getTypeFromString('size_t');
        $empty = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $data, $data->typeOf()->constNull());
        $safe = $context->builder->select($isNull, $empty, $context->builder->pointerCast($data, $i8p));
        $lenSafe = $context->builder->select($isNull, $sizeT->constInt(0, false), $context->builder->zExt($len, $sizeT));

        return [$safe, $lenSafe];
    }

    public static function integerPropertyAsI64(
        Context $context,
        Value $obj,
        string $className,
        string $propName
    ): Value {
        $propVar = $context->type->object->propertyFetch($obj, $className, $propName);
        if (Variable::TYPE_NATIVE_LONG === $propVar->type) {
            return $context->helper->loadValue($propVar);
        }
        $valuePtr = Variable::KIND_VARIABLE === $propVar->kind
            ? JitValueBox::pointer($context, $propVar->value)
            : $propVar->value;

        return $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valuePtr
        );
    }

    /**
     * @return array{0: Value, 1: Value} cstr pointer and byte length (size_t)
     */
    public static function stringVarAsCstr(Context $context, Variable $nameVar): array
    {
        $nameVar = JitNativeString::coerce($context, $nameVar);
        $strPtr = $context->helper->loadValue($nameVar);
        $i8p = $context->getTypeFromString('int8*');
        $raw = $context->builder->pointerCast($strPtr, $i8p);
        $lenPtr = $context->builder->pointerCast(
            $context->builder->gep($raw, $context->constantFromInteger(8, 'size_t')),
            $context->getTypeFromString('int64*')
        );
        $len = $context->builder->load($lenPtr);
        $data = $context->builder->gep($raw, $context->constantFromInteger(16, 'size_t'));
        $sizeT = $context->getTypeFromString('size_t');
        $empty = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $data, $data->typeOf()->constNull());
        $safe = $context->builder->select($isNull, $empty, $context->builder->pointerCast($data, $i8p));
        $lenSafe = $context->builder->select($isNull, $sizeT->constInt(0, false), $context->builder->zExt($len, $sizeT));

        return [$safe, $lenSafe];
    }

    /**
     * Unqualified class name from a native cstr (ReflectionClass::getShortName).
     *
     * @return array{cstr: Value, len: Value}
     */
    public static function shortNameFromCstr(Context $context, Value $cstr, Value $len): array
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $backslash = $i32->constInt(ord('\\'), false);
        $slashPtr = $context->builder->call($context->lookupFunction('strrchr'), $cstr, $backslash);
        $nullPtr = $i8p->constNull();
        $hasSlash = $context->builder->icmp(Builder::INT_NE, $slashPtr, $nullPtr);
        $shortCstr = $context->builder->select(
            $hasSlash,
            $context->builder->gep($slashPtr, $i32->constInt(1, false)),
            $cstr
        );
        $slashOffset = $context->builder->ptrToInt($slashPtr, $i64);
        $baseOffset = $context->builder->ptrToInt($cstr, $i64);
        $skip = $context->builder->sub($slashOffset, $baseOffset);
        $skipWithSep = $context->builder->add($skip, $i64->constInt(1, false));
        $skip64 = $context->builder->zExt($skipWithSep, $sizeT);
        $shortLen = $context->builder->select(
            $hasSlash,
            $context->builder->sub($len, $skip64),
            $len
        );

        return ['cstr' => $shortCstr, 'len' => $shortLen];
    }

    /**
     * ReflectionClass 0-arg kind/query — JIT/AOT (#31126, php_reflection.c).
     *
     * $args[0] is $this. Caller must already have checked user argc.
     *
     * @param list<Variable> $args
     */
    public static function emitKindQuery(
        Context $context,
        array $args,
        string $helperMethod,
        bool $returnsInt = false
    ): Value {
        $obj = self::loadObjectFromArg($context, $args[0]);
        [$cstr, $len] = self::reflectionClassNameAsCstr($context, $obj);
        $i64 = $context->getTypeFromString('int64');
        $nameStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $cstr
        );
        $abi = '__phpc_refl_class_kind_'.strtolower($helperMethod);
        self::ensureKindQueryLinked($context, $abi, $helperMethod, $returnsInt);
        $raw = $context->builder->call($context->lookupFunction($abi), $nameStr);
        $resultSlot = JitValueBox::alloc($context);
        if ($returnsInt) {
            JitValueBox::writeLong($context, $resultSlot, $raw);
        } else {
            JitValueBox::writeBool($context, $resultSlot, $raw);
        }

        return $resultSlot;
    }

    private static function ensureKindQueryLinked(
        Context $context,
        string $abi,
        string $helperMethod,
        bool $returnsInt
    ): void {
        $probe = $context->module->getNamedFunction($abi);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abi, $probe);

            return;
        }
        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }
        $strPtr = $context->getTypeFromString('__string__*');
        $retTy = $context->getTypeFromString($returnsInt ? 'int64' : 'int1');
        $helperLogical = 'PHPCompiler\\ext\\standard\\ReflectionClassKindJitHelper::'.$helperMethod;
        JitVmHelperLink::ensureBridge(
            $context,
            $abi,
            'refl_class_kind_'.$helperMethod.'_bridge_entry',
            [$strPtr],
            $retTy,
            $helperLogical,
            '/ext/standard/ReflectionClassKindJitHelper.php',
            self::kindQueryCompiledHelpers(),
            '#31126'
        );
        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /** @return list<string> */
    private static function kindQueryCompiledHelpers(): array
    {
        $ns = 'PHPCompiler\\ext\\standard\\ReflectionClassKindJitHelper::';

        return [
            $ns.'isEnum',
            $ns.'isInterface',
            $ns.'isTrait',
            $ns.'isAbstract',
            $ns.'isReadOnly',
            $ns.'getModifiers',
        ];
    }
}
