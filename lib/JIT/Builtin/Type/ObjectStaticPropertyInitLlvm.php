<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin\Type;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable as VMVariable;
use PHPLLVM\Value;

/**
 * LLVM init emission for typed static property globals (#9938).
 */
final class ObjectStaticPropertyInitLlvm
{
    public static function scalarInitializer(Object_ $object, int $jitType, ?VMVariable $value): Value
    {
        $context = $object->jitContext();
        if (Variable::TYPE_NATIVE_DOUBLE === $jitType) {
            $llvmType = $context->getTypeFromString('double');
            $float = null !== $value && VMVariable::TYPE_FLOAT === $value->type ? $value->toFloat() : 0.0;

            return $llvmType->constReal($float);
        }
        $llvmType = $context->getTypeFromString(
            Variable::TYPE_NATIVE_BOOL === $jitType ? 'int1' : 'int64'
        );
        $int = 0;
        if (null !== $value) {
            $int = match ($value->type) {
                VMVariable::TYPE_INTEGER => $value->toInt(),
                VMVariable::TYPE_BOOLEAN => $value->toBool() ? 1 : 0,
                default => 0,
            };
        }

        return $llvmType->constInt($int, false);
    }

    public static function initStringDefault(Object_ $object, Value $global, VMVariable $value): void
    {
        if (VMVariable::TYPE_STRING !== $value->type) {
            throw new \LogicException('Static string property default must be a string');
        }
        $context = $object->jitContext();
        $restore = $context->builder->getInsertBlock();
        $context->positionBuilderAtInitEmission();
        $str = $context->builder->load(
            $context->constantStringFromString($value->toString())
        );
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $context->builder->store($owned, $global);
        if (null !== $restore) {
            BasicBlockHelper::restoreInsertBlock($context, $restore);
        }
    }

    /** Allocate a null {@see __value__} box for untyped static properties (bootstrap JIT helpers). */
    public static function initValueNull(Object_ $object, Value $global): void
    {
        $context = $object->jitContext();
        $restore = $context->builder->getInsertBlock();
        $context->positionBuilderAtInitEmission();
        $valueType = $context->getTypeFromString('__value__');
        $heapVal = $context->memory->malloc($valueType);
        $heapPtr = $context->builder->pointerCast(
            $heapVal,
            $context->getTypeFromString('__value__*')
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $heapPtr
        );
        $context->builder->store($heapPtr, $global);
        if (null !== $restore) {
            BasicBlockHelper::restoreInsertBlock($context, $restore);
        }
    }

    /** Initialize a typed static array property with an empty hashtable (#8716). */
    public static function initHashtableEmpty(Object_ $object, Value $global): void
    {
        $context = $object->jitContext();
        $restore = $context->builder->getInsertBlock();
        $context->positionBuilderAtInitEmission();
        $ht = HashTableHelper::alloc($context);
        $context->builder->store($ht, $global);
        if (null !== $restore) {
            BasicBlockHelper::restoreInsertBlock($context, $restore);
        }
    }

    /**
     * Initialize a typed static array property from a folded VM hashtable (#31967).
     *
     * php-src: Zend/zend_compile.c — ZEND_DECLARE_STATIC_PROP copies the default zval.
     */
    public static function initHashtableFromVmArray(Object_ $object, Value $global, VMVariable $value): void
    {
        if (VMVariable::TYPE_ARRAY !== $value->type) {
            throw new \LogicException('Static array property default must be an array');
        }
        $table = $value->toArray();
        if (!$table instanceof \PHPCompiler\VM\HashTable) {
            throw new \LogicException('Static array property default must be a HashTable');
        }
        if (0 === $table->getNumElements()) {
            self::initHashtableEmpty($object, $global);

            return;
        }
        $context = $object->jitContext();
        $restore = $context->builder->getInsertBlock();
        $context->positionBuilderAtInitEmission();
        $htVar = HashTableHelper::variableFromVmHashTable($context, $table);
        $htPtr = $context->helper->loadValue($htVar);
        $context->refcount->addref($htPtr);
        $context->builder->store($htPtr, $global);
        if (null !== $restore) {
            BasicBlockHelper::restoreInsertBlock($context, $restore);
        }
    }

    /**
     * WeakRef registry slot tables allocate on first write — eager __init__ hashtable alloc
     * runs before runtime tables are ready and segfaults HelloWorld AOT (#11437).
     */
    public static function deferHashtableInitInAot(Object_ $object, int $classId): bool
    {
        $context = $object->jitContext();
        if (\PHPCompiler\JIT\Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            return false;
        }
        $classLc = strtolower(ltrim($object->classNameForId($classId), '\\'));

        return 'phpcompiler\\ext\\standard\\weakrefregistryjithelper' === $classLc;
    }

    /** Box an empty compile-time array default into a union/DNF static {@see __value__} property (#8708, #8719, DomRegistry::$states #6140). */
    public static function initValueEmptyArray(Object_ $object, Value $global): void
    {
        $context = $object->jitContext();
        $restore = $context->builder->getInsertBlock();
        $context->positionBuilderAtInitEmission();
        $valueType = $context->getTypeFromString('__value__');
        $heapVal = $context->memory->malloc($valueType);
        $heapPtr = $context->builder->pointerCast(
            $heapVal,
            $context->getTypeFromString('__value__*')
        );
        $ht = HashTableHelper::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $heapPtr,
            $ht
        );
        $context->builder->store($heapPtr, $global);
        if (null !== $restore) {
            BasicBlockHelper::restoreInsertBlock($context, $restore);
        }
    }

    /**
     * Box a folded array default (including non-empty) into a static {@see __value__} property (#31967).
     *
     * php-src: Zend/zend_compile.c — ZEND_DECLARE_STATIC_PROP copies the default zval.
     */
    public static function initValueArrayDefault(Object_ $object, Value $global, VMVariable $value): void
    {
        if (VMVariable::TYPE_ARRAY !== $value->type) {
            throw new \LogicException('Static boxed array property default must be an array');
        }
        $table = $value->toArray();
        if (!$table instanceof \PHPCompiler\VM\HashTable) {
            throw new \LogicException('Static boxed array property default must be a HashTable');
        }
        if (0 === $table->getNumElements()) {
            self::initValueEmptyArray($object, $global);

            return;
        }
        $context = $object->jitContext();
        $restore = $context->builder->getInsertBlock();
        $context->positionBuilderAtInitEmission();
        $valueType = $context->getTypeFromString('__value__');
        $heapVal = $context->memory->malloc($valueType);
        $heapPtr = $context->builder->pointerCast(
            $heapVal,
            $context->getTypeFromString('__value__*')
        );
        $htVar = HashTableHelper::variableFromVmHashTable($context, $table);
        $htPtr = $context->helper->loadValue($htVar);
        $context->refcount->addref($htPtr);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $heapPtr,
            $htPtr
        );
        $context->builder->store($heapPtr, $global);
        if (null !== $restore) {
            BasicBlockHelper::restoreInsertBlock($context, $restore);
        }
    }

    /** Box a compile-time scalar default into a union/DNF static {@see __value__} property (#8726). */
    public static function initValueScalarDefault(Object_ $object, Value $global, VMVariable $default): void
    {
        $context = $object->jitContext();
        $restore = $context->builder->getInsertBlock();
        $context->positionBuilderAtInitEmission();
        $valueType = $context->getTypeFromString('__value__');
        $heapVal = $context->memory->malloc($valueType);
        $heapPtr = $context->builder->pointerCast(
            $heapVal,
            $context->getTypeFromString('__value__*')
        );
        $valueMap = $context->structFieldMap['__value__'];
        $context->builder->store(
            $context->getTypeFromString('int8')->constInt($default->type, false),
            $context->builder->structGep($heapVal, $valueMap['type'])
        );
        if (VMVariable::TYPE_STRING === $default->type) {
            $str = $context->builder->load(
                $context->constantStringFromString($default->toString())
            );
            $owned = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $str
            );
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $heapPtr,
                $owned
            );
        } elseif (VMVariable::TYPE_INTEGER === $default->type) {
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                $heapPtr,
                $context->getTypeFromString('int64')->constInt($default->toInt(), false)
            );
        } elseif (VMVariable::TYPE_FLOAT === $default->type) {
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $heapPtr,
                $context->getTypeFromString('double')->constReal($default->toFloat())
            );
        } elseif (VMVariable::TYPE_BOOLEAN === $default->type) {
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                $heapPtr,
                $context->getTypeFromString('int64')->constInt($default->toBool() ? 1 : 0, false)
            );
        } else {
            throw new \LogicException(
                'Static union/DNF property default must be a scalar compile-time constant'
            );
        }
        $context->builder->store($heapPtr, $global);
        if (null !== $restore) {
            BasicBlockHelper::restoreInsertBlock($context, $restore);
        }
    }

    /** Box a compile-time enum case singleton into a typed static {@see __value__} property (#5891). */
    public static function initValueEnumCase(Object_ $object, Value $global, VMVariable $default): void
    {
        $enumClass = EnumCaseSupport::enumClassForCaseVariable($default);
        if (null === $enumClass) {
            throw new \LogicException('Static enum case property default requires enum class');
        }
        $context = $object->jitContext();
        $enumClassId = $object->lookup(strtolower($enumClass->name));
        $caseKey = strtolower(EnumCaseSupport::enumCaseNameForVariable($default));
        $globalName = $object->ensureEnumCaseSingletonGlobal($enumClassId, $caseKey);
        $context->emitInInit(function (Context $ctx) use ($global, $globalName): void {
            $objGlobal = $ctx->module->getNamedGlobal($globalName);
            if (null === $objGlobal) {
                throw new \LogicException("Missing enum case singleton global: {$globalName}");
            }
            $valueType = $ctx->getTypeFromString('__value__');
            $heapVal = $ctx->memory->malloc($valueType);
            $heapPtr = $ctx->builder->pointerCast(
                $heapVal,
                $ctx->getTypeFromString('__value__*')
            );
            $obj = $ctx->builder->load($objGlobal);
            $ctx->builder->call(
                $ctx->lookupFunction('__value__writeObject'),
                $heapPtr,
                $obj
            );
            $ctx->builder->store($heapPtr, $global);
        });
    }
}
