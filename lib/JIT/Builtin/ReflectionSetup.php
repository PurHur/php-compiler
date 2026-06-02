<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
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
        $voidPtr = $context->getTypeFromString('void*');
        $context->builder->store(
            $context->builder->pointerCast($heapPtr, $voidPtr),
            $slot
        );
    }

    public static function emitSetClassFromStringVar(Context $context, Value $obj, Variable $nameVar): void
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
        $context->builder->call(
            $context->lookupFunction('phpc_reflect_set_class'),
            $context->builder->pointerCast($obj, $i8p),
            $context->builder->pointerCast($data, $i8p),
            $context->builder->zExt($len, $context->getTypeFromString('size_t'))
        );
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
        $propVar = JitNativeString::coerce($context, $propVar);
        $strPtr = $context->helper->loadValue($propVar);
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
}
