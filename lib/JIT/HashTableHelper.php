<?php

declare(strict_types=1);

/**
 * LLVM helpers for packed-list __hashtable__ (stdlib array builtins).
 */

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\CallUnpackRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
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
        return HashTableReadLlvm::loadHashtablePointer($context, $array);
    }

    /**
     * Stable string key for SplObjectStorage object offsets (pointer identity, issue #601).
     */
    public static function objectPointerAsStringKey(Context $context, Variable $keyObject): Variable
    {
        return HashTableWriteLlvm::objectPointerAsStringKey($context, $keyObject);
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
        return HashTableWriteLlvm::variableFromVmHashTable($context, $table);
    }

    /**
     * Pack JIT call/recv arguments into a list hashtable (issue #197).
     *
     * @param list<Variable> $vars
     */
    public static function packVariables(Context $context, array $vars): Variable
    {
        return HashTableWriteLlvm::packVariables($context, $vars);
    }

    public static function listEntryPointer(Context $context, Value $ht, Value $index): Value
    {
        return HashTableReadLlvm::listEntryPointer($context, $ht, $index);
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
        ?string $superglobalName = null,
        bool $emitFloatKeyDeprecation = true
    ): Variable {
        return HashTableReadLlvm::readDimToValueBox(
            $context,
            $ht,
            $dim,
            $superglobalName,
            $emitFloatKeyDeprecation
        );
    }

    public static function readStringAt(Context $context, Value $ht, Value $index): Value
    {
        return HashTableReadLlvm::readStringAt($context, $ht, $index);
    }

    /**
     * Read a packed-list element into a stack {@see __value__} slot (for echo / mixed-type index).
     */
    public static function readIndexedToValueBox(Context $context, Value $ht, Value $index): Variable
    {
        return HashTableReadLlvm::readIndexedToValueBox($context, $ht, $index);
    }

    /** Live child HT at packed index for nested dim write (#24011). */
    public static function readIndexedHashtable(Context $context, Value $ht, Value $index): Value
    {
        return HashTableReadLlvm::readIndexedHashtable($context, $ht, $index);
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

    /** TYPE_OBJECT dim write: resource warn+cast or Illegal offset (#29550). */
    public static function prepareResourceOrIllegalObjectKeyWrite(
        Context $context,
        Value $ht,
        Variable $dim
    ): Variable {
        return HashTableWriteLlvm::prepareResourceOrIllegalObjectKeyWrite($context, $ht, $dim);
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
        HashTableWriteLlvm::unsetStringKey($context, $ht, $keyStr);
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
        HashTableWriteLlvm::initArray($context, $result);
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
     * Call-time `...$arr` allows string keys (named args). Do not use list-unpack's
     * array_is_list guard — that is for `list()` / `[...$arr]` only (#23971).
     *
     * @param list<Variable|array{unpack: Variable}> $entries
     */
    public static function mergeCallArgEntries(Context $context, array $entries): Variable
    {
        if (1 === \count($entries)) {
            $only = $entries[0];
            if (\is_array($only) && isset($only['unpack'])) {
                $src = $only['unpack'];
                if (
                    !ListUnpackHelper::isDefinitelyArrayAtCompileTime($src)
                    && Variable::TYPE_VALUE !== $src->type
                    && Variable::TYPE_OBJECT !== $src->type
                ) {
                    CallUnpackRuntime::ensureLinked($context);
                    ListUnpackHelper::emitCallUnpackOperandCheck($context, $src);
                }

                return self::coerceToPackedHashtableCopy($context, $src);
            }
        }

        $needsNonArrayGuard = false;
        foreach ($entries as $entry) {
            if (!\is_array($entry) || !isset($entry['unpack'])) {
                continue;
            }
            $u = $entry['unpack'];
            if (
                !ListUnpackHelper::isDefinitelyArrayAtCompileTime($u)
                && Variable::TYPE_VALUE !== $u->type
                && Variable::TYPE_OBJECT !== $u->type
            ) {
                $needsNonArrayGuard = true;
                break;
            }
        }
        if ($needsNonArrayGuard) {
            CallUnpackRuntime::ensureLinked($context);
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
                $u = $entry['unpack'];
                if (
                    $needsNonArrayGuard
                    && !ListUnpackHelper::isDefinitelyArrayAtCompileTime($u)
                    && Variable::TYPE_VALUE !== $u->type
                    && Variable::TYPE_OBJECT !== $u->type
                ) {
                    ListUnpackHelper::emitCallUnpackOperandCheck($context, $u);
                }
                self::spreadInto($context, $destVar, $u);
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

    /**
     * Box a native {@see __hashtable__*} into a value-boxed array local (#24167 k09).
     *
     * Variadic recv passes a packed HT; builtins like array_sum() need TYPE_VALUE with an
     * array tag (implode tolerates raw HT via {@see ArrayBuiltinHelper::loadHashTable}).
     */
    public static function boxedArrayFromHashtable(Context $context, Variable $ht): Variable
    {
        if (Variable::TYPE_HASHTABLE !== $ht->type) {
            throw new \LogicException(
                'boxedArrayFromHashtable requires TYPE_HASHTABLE, got '.Variable::getStringType($ht->type)
            );
        }
        $slot = JitValueBox::alloc($context);
        $ptr = $context->helper->loadValue($ht);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            JitValueBox::pointer($context, $slot),
            $ptr
        );
        $context->refcount->addref($ptr);
        $var = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
        $var->valueBoxHashtable = true;

        return $var;
    }

    /**
     * Like {@see coerceToPackedHashtable} but always returns an owned hashtable copy so
     * call-time unpack does not alias script-global / value-box storage
     * (`s(...$p)` then use `$v` — #23971 e08_spread).
     */
    public static function coerceToPackedHashtableCopy(Context $context, Variable $source): Variable
    {
        $packed = self::coerceToPackedHashtable($context, $source);
        $ptr = $context->helper->loadValue($packed);
        $copy = \PHPCompiler\JIT\Builtin\HashTableDuplicateRuntime::duplicate($context, $ptr);

        return new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $copy
        );
    }

    public static function emitIllegalOffsetType(Context $context, string $message = 'Illegal offset type'): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_type_error'));
    }

    /**
     * Array-literal / dim-write illegal key — PROFILE≥8.3 typed TypeError (#28628, zend_illegal_container_offset).
     */
    public static function emitIllegalOffsetTypeForKey(
        Context $context,
        Variable $key,
        string $legacyMessage = 'Illegal offset type'
    ): void {
        self::emitIllegalOffsetType(
            $context,
            self::illegalOffsetMessageForJitKey($context, $key, $legacyMessage)
        );
    }

    /**
     * Resolve zend_zval_type_name()-shaped label for a compile-time JIT array key (#28628).
     */
    public static function illegalOffsetMessageForJitKey(
        Context $context,
        Variable $key,
        string $legacyMessage = 'Illegal offset type'
    ): string {
        if (Variable::TYPE_HASHTABLE === $key->type) {
            return \PHPCompiler\VM\EnumCaseSupport::formatIllegalContainerOffsetMessage(
                'array',
                $legacyMessage
            );
        }

        $typeName = 'object';
        if (null !== $key->compileTimeEnumCase && isset($key->compileTimeEnumCase['classId'])) {
            $name = $context->type->object->classNameForId((int) $key->compileTimeEnumCase['classId']);
            if (\is_string($name) && '' !== $name) {
                $typeName = $name;
            }
        } elseif (null !== $key->objectPropertyClassName && '' !== $key->objectPropertyClassName) {
            $typeName = $key->objectPropertyClassName;
        } elseif (null !== $key->magicGetOverloadedClass && '' !== $key->magicGetOverloadedClass) {
            $typeName = $key->magicGetOverloadedClass;
        }

        return \PHPCompiler\VM\EnumCaseSupport::formatIllegalContainerOffsetMessage(
            $typeName,
            $legacyMessage
        );
    }
}
