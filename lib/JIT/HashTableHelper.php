<?php

declare(strict_types=1);

/**
 * LLVM helpers for packed-list __hashtable__ (stdlib array builtins).
 */

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\CallUnpackRuntime;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class HashTableHelper
{
    /**
     * Unbox a {@see Variable::TYPE_VALUE} slot to a __hashtable__* (array_push, count, isset patterns).
     */
    public static function readHashtableFromValueBox(Context $context, Variable $container): Value
    {
        if (Variable::TYPE_VALUE !== $container->type && !JitValueBox::isValueOperand($container)) {
            throw new \LogicException('readHashtableFromValueBox requires TYPE_VALUE');
        }

        return self::ensureHashtablePointer($context, $container);
    }

    /** Materialize empty hashtables for null boxed arrays and object properties (#1086). */
    public static function ensureHashtablePointer(Context $context, Variable $array): Value
    {
        return HashTableReadLlvm::ensureHashtablePointer($context, $array);
    }


    /**
     * HashTable view without objectPropertySlot — isset()/dim on $this->props['k'] (#764).
     */
    public static function asDetachedHashtable(Context $context, Variable $container): Variable
    {
        if (null === $container->objectPropertySlot || Variable::TYPE_STRING === $container->type) {
            return $container;
        }

        $detached = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            self::loadHashtablePointer($context, $container)
        );
        $detached->superglobalName = $container->superglobalName;

        return $detached;
    }

    /**
     * Load a native {@see __hashtable__*} from a boxed or direct array variable (#107).
     */

    /** Persist in-place hashtable mutations on native/boxed array operands (#1086). */
    public static function storeHashtableInArrayVariable(Context $context, Variable $array, Value $ht): void
    {
        HashTableWriteLlvm::storeHashtableInArrayVariable($context, $array, $ht);
    }

    public static function loadHashtablePointer(Context $context, Variable $array): Value
    {
        if (Variable::TYPE_STRING === $array->type) {
            ErrorRaise::registerDeclarations($context);
            ErrorRaise::ensureLinked($context);
            ErrorRaise::emitRaise(
                $context,
                \PHPCompiler\VM\TypeCheck::SCALAR_USED_AS_ARRAY_MESSAGE
            );

            return $context->getTypeFromString('__hashtable__*')->constNull();
        }
        if (null !== $array->objectPropertySlot) {
            if (Variable::TYPE_HASHTABLE === ($array->objectPropertyType ?? null)) {
                return $context->builder->pointerCast(
                    $context->builder->load($array->objectPropertySlot),
                    $context->getTypeFromString('__hashtable__*')
                );
            }

            return self::ensureHashtablePointer($context, $array);
        }
        if (Variable::TYPE_HASHTABLE === $array->type) {
            return $context->helper->loadValue($array);
        }
        if (Variable::TYPE_VALUE === $array->type || $array->valueBoxHashtable) {
            return self::ensureHashtablePointer($context, $array);
        }

        throw new \LogicException(
            'Array offset access requires hashtable or boxed array, got '
            .Variable::getStringType($array->type)
        );
    }

    /**
     * Stable string key for SplObjectStorage object offsets (pointer identity, issue #601).
     */
    public static function objectPointerAsStringKey(Context $context, Variable $keyObject): Variable
    {
        if (Variable::TYPE_OBJECT !== $keyObject->type) {
            throw new \LogicException('SplObjectStorage keys must be objects in this compiler build');
        }
        $objPtr = $context->helper->loadValue($keyObject);
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $ptrInt = $context->builder->ptrToInt($objPtr, $sizeT);
        $buf = $context->builder->alloca($context->getTypeFromString('int8'), $sizeT->constInt(32, false), 'spl_key_buf');
        $bufC = $context->builder->pointerCast($buf, $i8p);
        $fmt = $context->builder->pointerCast($context->constantFromString('%zu'), $i8p);
        $context->builder->call($context->lookupFunction('sprintf'), $bufC, $fmt, $ptrInt);
        $len = $context->builder->call($context->lookupFunction('strlen'), $bufC);
        $lenI64 = $context->getTypeFromString('int64');
        $lenForInit = $len->typeOf() === $lenI64
            ? $len
            : $context->builder->zExt($len, $lenI64);
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenForInit,
            $bufC
        );

        return new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $str
        );
    }

    public static function alloc(Context $context): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ht_alloc_cont');

        return $context->builder->call($context->lookupFunction('__hashtable__alloc'));
    }

    /** Empty packed list for variadic recv with zero trailing args (issue #197). */
    public static function emptyVariable(Context $context): Variable
    {
        return new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            self::alloc($context)
        );
    }

    public static function variableFromVmHashTable(Context $context, \PHPCompiler\VM\HashTable $table): Variable
    {
        $ht = self::alloc($context);
        $setLong = $context->lookupFunction('__hashtable__setLongAt');
        $setStringAt = $context->lookupFunction('__hashtable__setStringAt');
        $setStringKey = $context->lookupFunction('__hashtable__setStringKeyString');
        foreach ($table->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $resolved = $valueVar->resolveIndirect();
            if (\PHPCompiler\VM\Variable::TYPE_INTEGER === $keyVar->type) {
                $idx = $context->constantFromInteger($keyVar->toInt(), 'size_t');
                if (\PHPCompiler\VM\Variable::TYPE_INTEGER === $resolved->type) {
                    $context->builder->call(
                        $setLong,
                        $ht,
                        $idx,
                        $context->getTypeFromString('int64')->constInt($resolved->toInt(), false)
                    );
                } elseif (\PHPCompiler\VM\Variable::TYPE_STRING === $resolved->type) {
                    $str = $context->builder->load(
                        $context->constantStringFromString($resolved->toString())
                    );
                    $context->builder->call($setStringAt, $ht, $idx, $str);
                } elseif (\PHPCompiler\VM\Variable::TYPE_BOOLEAN === $resolved->type) {
                    $context->builder->call(
                        $setLong,
                        $ht,
                        $idx,
                        $context->getTypeFromString('int64')->constInt($resolved->toBool() ? 1 : 0, false)
                    );
                } elseif (\PHPCompiler\VM\Variable::TYPE_FLOAT === $resolved->type) {
                    $context->builder->call(
                        $context->lookupFunction('__hashtable__setDoubleAt'),
                        $ht,
                        $idx,
                        $context->constantFromFloat($resolved->toFloat())
                    );
                } elseif (\PHPCompiler\VM\Variable::TYPE_NULL === $resolved->type) {
                    $context->builder->call(
                        $context->lookupFunction('__hashtable__setNullAt'),
                        $ht,
                        $idx
                    );
                } elseif (\PHPCompiler\VM\Variable::TYPE_ARRAY === $resolved->type) {
                    self::setAtIndex(
                        $context,
                        $ht,
                        $idx,
                        self::variableFromVmHashTable($context, $resolved->toArray())
                    );
                } elseif (\PHPCompiler\VM\Variable::TYPE_OBJECT === $resolved->type
                    || \PHPCompiler\VM\Variable::TYPE_ENUM_CASE === $resolved->type) {
                    $context->type->object->embedClassConstArrayVmElementAtIndex($context, $ht, $idx, $resolved);
                } else {
                    throw new \LogicException(
                        'Unsupported class constant array element type for JIT: '
                        .Variable::getStringType(Variable::fromVMVariable($resolved->type))
                    );
                }

                continue;
            }
            if (\PHPCompiler\VM\Variable::TYPE_STRING !== $keyVar->type) {
                continue;
            }
            $key = $context->builder->load(
                $context->constantStringFromString($keyVar->toString())
            );
            if (\PHPCompiler\VM\Variable::TYPE_STRING === $resolved->type) {
                $str = $context->builder->load(
                    $context->constantStringFromString($resolved->toString())
                );
                $context->builder->call($setStringKey, $ht, $key, $str);
            } elseif (\PHPCompiler\VM\Variable::TYPE_INTEGER === $resolved->type) {
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyLong'),
                    $ht,
                    $key,
                    $context->getTypeFromString('int64')->constInt($resolved->toInt(), false)
                );
            } elseif (\PHPCompiler\VM\Variable::TYPE_BOOLEAN === $resolved->type) {
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyBool'),
                    $ht,
                    $key,
                    $context->getTypeFromString('bool')->constInt($resolved->toBool() ? 1 : 0, false)
                );
            } elseif (\PHPCompiler\VM\Variable::TYPE_FLOAT === $resolved->type) {
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyDouble'),
                    $ht,
                    $key,
                    $context->constantFromFloat($resolved->toFloat())
                );
            } elseif (\PHPCompiler\VM\Variable::TYPE_ARRAY === $resolved->type) {
                self::setAtKeyCoercingNumericString(
                    $context,
                    $ht,
                    $key,
                    self::variableFromVmHashTable($context, $resolved->toArray())
                );
            } elseif (\PHPCompiler\VM\Variable::TYPE_NULL === $resolved->type) {
                self::setAtKeyCoercingNumericString(
                    $context,
                    $ht,
                    $key,
                    new Variable(
                        $context,
                        Variable::TYPE_NULL,
                        Variable::KIND_VALUE,
                        $context->getTypeFromString('__value__*')->constNull()
                    )
                );
            } elseif (\PHPCompiler\VM\Variable::TYPE_OBJECT === $resolved->type
                || \PHPCompiler\VM\Variable::TYPE_ENUM_CASE === $resolved->type) {
                $context->type->object->embedClassConstArrayVmElementAtStringKey($context, $ht, $key, $resolved);
            } else {
                throw new \LogicException(
                    'Unsupported class constant array element type for JIT: '
                    .Variable::getStringType(Variable::fromVMVariable($resolved->type))
                );
            }
        }

        return new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $ht
        );
    }

    /**
     * Pack JIT call/recv arguments into a list hashtable (issue #197).
     *
     * @param list<Variable> $vars
     */
    public static function packVariables(Context $context, array $vars): Variable
    {
        $ht = self::alloc($context);
        $i64 = $context->getTypeFromString('int64');
        foreach ($vars as $index => $var) {
            if (!$var instanceof Variable) {
                continue;
            }
            self::setAtIndex($context, $ht, $i64->constInt($index, false), $var);
        }

        return new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $ht
        );
    }

    public static function listEntryPointer(Context $context, Value $ht, Value $index): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $values = $context->builder->load(
            $context->builder->structGep($ht, $map['values'])
        );

        return $context->builder->inBoundsGep($values, $index);
    }

    public static function offsetUnset(Context $context, Variable $container, Variable $dim): void
    {
        HashTableWriteLlvm::offsetUnset($context, $container, $dim);
    }

    /** isset() / empty() offset check for string, int, object, or boxed keys (issue #86). */
    public static function offsetIsSetDim(Context $context, Value $ht, Variable $dim): Value
    {
        return HashTableReadLlvm::offsetIsSetDim($context, $ht, $dim);
    }

    /**
     * Read an element into a stack {@see __value__} slot (string/int/object/boxed keys; issue #86).
     *
     * @param string|null $superglobalName When set, string keys use superglobal-safe read (issue #273).
     */
    public static function readDimToValueBox(
        Context $context,
        Value $ht,
        Variable $dim,
        ?string $superglobalName = null
    ): Variable {
        return HashTableReadLlvm::readDimToValueBox($context, $ht, $dim, $superglobalName);
    }

    public static function readStringAt(Context $context, Value $ht, Value $index): Value
    {
        $entry = self::listEntryPointer($context, $ht, $index);

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $entry
        );
    }

    /**
     * Read a packed-list element into a stack {@see __value__} slot (for echo / mixed-type index).
     */
    public static function readIndexedToValueBox(Context $context, Value $ht, Value $index): Variable
    {
        return HashTableReadLlvm::readIndexedToValueBox($context, $ht, $index);
    }

    /**
     * Lvalue marker for $arr['key'] = … without reading the old value first (#107).
     */
    public static function prepareStringKeyWrite(Context $context, Value $ht, Value $keyStr): Variable
    {
        return HashTableWriteLlvm::prepareStringKeyWrite($context, $ht, $keyStr);
    }

    /**
     * Lvalue marker for $arr[$key] = … when $key is a boxed __value__ (issue #86).
     */
    public static function prepareValueBoxKeyWrite(Context $context, Value $ht, Variable $dim): Variable
    {
        return HashTableWriteLlvm::prepareValueBoxKeyWrite($context, $ht, $dim);
    }

    /**
     * Lvalue marker for $arr[0] = … on a native hashtable (#107).
     */
    public static function prepareIndexWrite(Context $context, Value $ht, Value $index): Variable
    {
        return HashTableWriteLlvm::prepareIndexWrite($context, $ht, $index);
    }

    /**
     * Writable __value__ slot for a string key (creates an empty string entry if missing; issue #103).
     */
    public static function writableStringKeyValueBox(Context $context, Value $ht, Value $keyStr): Variable
    {
        return HashTableWriteLlvm::writableStringKeyValueBox($context, $ht, $keyStr);
    }

    public static function unsetStringKey(Context $context, Value $ht, Value $keyStr): void
    {
        $context->builder->call(
            $context->lookupFunction('__hashtable__unsetStringKey'),
            $ht,
            $keyStr
        );
    }

    public static function readSuperglobalStringKeyToValueBox(
        Context $context,
        Value $ht,
        Value $keyStr
    ): Variable {
        return HashTableReadLlvm::readSuperglobalStringKeyToValueBox($context, $ht, $keyStr);
    }

    public static function readStringKeyToValueBox(Context $context, Value $ht, Value $keyStr): Variable
    {
        return HashTableReadLlvm::readStringKeyToValueBox($context, $ht, $keyStr);
    }

    public static function initArray(Context $context, Variable $result): void
    {
        $result->nextFreeElement = 0;
        if ($result->type & Variable::IS_NATIVE_ARRAY) {
            return;
        }
        if (Variable::TYPE_STRING === $result->type) {
            // Inline include may bind array-literal temps to inherited string slots (#16866).
            $slot = BasicBlockHelper::entryAlloca(
                $context,
                $context->getTypeFromString('__hashtable__*')
            );
            $result->free();
            $result->type = Variable::TYPE_HASHTABLE;
            $result->kind = Variable::KIND_VARIABLE;
            $result->value = $slot;
            $result->initialize();
        }
        $ht = self::alloc($context);
        if (Variable::TYPE_VALUE === $result->type) {
            $context->builder->call(
                $context->lookupFunction('__value__writeHashtable'),
                $result->value,
                $ht
            );
            $result->valueBoxHashtable = true;

            return;
        }
        $context->builder->store($ht, $result->value);
    }

    public static function spreadAddElement(
        Context $context,
        Variable $array,
        Variable $element,
        Variable $key
    ): void {
        HashTableWriteLlvm::spreadAddElement($context, $array, $element, $key);
    }

    public static function addElement(
        Context $context,
        Variable $array,
        Variable $element,
        ?Variable $key = null
    ): void {
        HashTableWriteLlvm::addElement($context, $array, $element, $key);
    }

    public static function reserveAppendSlot(Context $context, Variable $array): Variable
    {
        return HashTableWriteLlvm::reserveAppendSlot($context, $array);
    }

    public static function setAtIndex(Context $context, Value $ht, Value $index, Variable $element): void
    {
        HashTableWriteLlvm::setAtIndex($context, $ht, $index, $element);
    }

    /** Foreach by-ref: packed index writes vs borrowed string-key entry (#4364). */
    public static function assignForeachByRefWritable(Context $context, Variable $lvalue, Variable $element): void
    {
        if (null === $lvalue->writableHt || null === $lvalue->foreachByRefPackedArm || null === $lvalue->writableIndex) {
            throw new \LogicException('assignForeachByRefWritable requires foreach by-ref writable markers');
        }
        self::setAtIndex($context, $lvalue->writableHt, $lvalue->writableIndex, $element);
    }

    public static function setValueBoxKey(
        Context $context,
        Value $ht,
        Variable $dim,
        Variable $element
    ): void {
        HashTableWriteLlvm::setValueBoxKey($context, $ht, $dim, $element);
    }

    public static function setAtObjectKey(
        Context $context,
        Value $ht,
        Value $keyObj,
        Variable $element
    ): void {
        HashTableWriteLlvm::setAtObjectKey($context, $ht, $keyObj, $element);
    }

    /**
     * Array element write: numeric strings use the int index slot (Zend zend_hash.c; #4151).
     */
    public static function setAtKeyCoercingNumericString(
        Context $context,
        Value $ht,
        Value $keyPtr,
        Variable $element
    ): void {
        HashTableWriteLlvm::setAtKeyCoercingNumericString($context, $ht, $keyPtr, $element);
    }

    public static function setAtStringKey(
        Context $context,
        Value $ht,
        Value $keyPtr,
        Variable $element
    ): void {
        HashTableWriteLlvm::setAtStringKey($context, $ht, $keyPtr, $element);
    }

    /**
     * SplObjectStorage-style map: object identity keys (issue #600 / self-host Compiler.php).
     */
    public static function readObjectKeyToValueBox(Context $context, Value $ht, Value $keyObj): Variable
    {
        return HashTableReadLlvm::readObjectKeyToValueBox($context, $ht, $keyObj);
    }

    public static function writableObjectKeyValueBox(Context $context, Value $ht, Value $keyObj): Variable
    {
        return HashTableWriteLlvm::writableObjectKeyValueBox($context, $ht, $keyObj);
    }

    /**
     * Copy a compile-time native array into a refcounted __hashtable__ for calls/properties (issue #767).
     */
    public static function materializeNativeArrayForCall(Context $context, Variable $array): Value
    {
        return HashTableWriteLlvm::materializeNativeArrayForCall($context, $array);
    }

    /**
     * Normalize a call/unpack operand to a packed __hashtable__* (issue #1361).
     */
    public static function coerceToPackedHashtable(Context $context, Variable $source): Variable
    {
        if ($source->type & Variable::IS_NATIVE_ARRAY) {
            return new Variable(
                $context,
                Variable::TYPE_HASHTABLE,
                Variable::KIND_VALUE,
                self::materializeNativeArrayForCall($context, $source)
            );
        }
        if (Variable::TYPE_HASHTABLE === $source->type) {
            return $source;
        }
        if (Variable::TYPE_VALUE === $source->type || $source->valueBoxHashtable) {
            $ht = self::ensureHashtablePointer($context, $source);

            return new Variable(
                $context,
                Variable::TYPE_HASHTABLE,
                Variable::KIND_VALUE,
                $ht
            );
        }
        if (Variable::TYPE_OBJECT === $source->type) {
            $ptr = $context->helper->loadValue($source);

            return new Variable(
                $context,
                Variable::TYPE_HASHTABLE,
                Variable::KIND_VALUE,
                $context->builder->pointerCast(
                    $ptr,
                    $context->getTypeFromString('__hashtable__*')
                )
            );
        }

        throw new \LogicException(
            'Array spread/unpack requires an array, got '.Variable::getStringType($source->type)
        );
    }

    public static function spreadInto(Context $context, Variable $dest, Variable $source): void
    {
        HashTableWriteLlvm::spreadInto($context, $dest, $source);
    }

    /**
     * Merge ARG_SEND entries that may include unpack markers (issue #1361).
     *
     * @param list<Variable|array{unpack: Variable}> $entries
     */
    public static function mergeCallArgEntries(Context $context, array $entries): Variable
    {
        CallUnpackRuntime::ensureLinked($context);

        if (1 === \count($entries)) {
            $only = $entries[0];
            if (\is_array($only) && isset($only['unpack'])) {
                ListUnpackHelper::emitCallUnpackOperandCheck($context, $only['unpack']);
                ListUnpackHelper::emitCheck($context, $only['unpack']);

                return self::coerceToPackedHashtable($context, $only['unpack']);
            }
        }

        $dest = self::emptyVariable($context);
        $destVar = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $dest->value
        );
        foreach ($entries as $entry) {
            if (\is_array($entry) && isset($entry['unpack'])) {
                ListUnpackHelper::emitCallUnpackOperandCheck($context, $entry['unpack']);
                ListUnpackHelper::emitCheck($context, $entry['unpack']);
                self::spreadInto($context, $destVar, $entry['unpack']);
                continue;
            }
            if (\is_array($entry) && isset($entry['named'])) {
                $nameVar = new Variable(
                    $context,
                    Variable::TYPE_STRING,
                    Variable::KIND_VALUE,
                    $context->constantFromString((string) $entry['named'])
                );
                self::addElement($context, $destVar, $entry['value'], $nameVar);
                continue;
            }
            $value = \is_array($entry) ? ($entry['v'] ?? $entry['value'] ?? null) : $entry;
            self::addElement($context, $destVar, $value, null);
        }

        return $destVar;
    }

    public static function emitIllegalOffsetType(Context $context, string $message = 'Illegal offset type'): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_type_error'));
    }
}
