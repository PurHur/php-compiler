<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\Compiler\ParameterMetadata;
use PHPCompiler\ext\standard\array_map;
use PHPCompiler\ext\standard\KeySortJitHelper;
use PHPCompiler\ext\standard\NaturalSortJitHelper;
use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\ValueSortJitHelper;
use PHPCompiler\ext\standard\VmArraySortCallback;
use PHPCompiler\ext\standard\VmInternalCompare;
use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Shared backing storage for SPL ArrayObject / ArrayIterator (php-src ext/spl/spl_array.c).
 */
final class SplArrayStorage
{
    public const FLAG_STD_PROP_LIST = 1;

    public const FLAG_ARRAY_AS_PROPS = 2;

    /**
     * php-src ext/spl/spl_array.stub.php — ArrayAccess methods use mixed $key / $value
     * (not legacy InternalArgInfo index/newval). Stub arginfo is required for LSP when
     * subclasses override or anonymously extend ArrayObject/ArrayIterator (#25840).
     *
     * Omit methodReturnDeclaredTypes — returns are @tentative-return-type in Zend
     * (see BuiltinInternalTentativeReturnInfo). mixed dump types parse as untyped for
     * variance (TypeSig::fromDumpTypeString), matching ArrayAccess (#25425).
     */
    public static function attachArrayAccessArginfo(ClassEntry $entry): void
    {
        self::attachArrayAccessArginfoNamed($entry, 'key', 'mixed', 'value', 'mixed');
    }

    /**
     * Attach ArrayAccess stub arginfo with php-src stub param names/types (#25840, #25856).
     *
     * @param ?string $offsetType null = untyped (SplFixedArray $index, CachingIterator $key, …)
     * @param ?string $valueType  null = untyped; typically 'mixed' for offsetSet value/info
     */
    public static function attachArrayAccessArginfoNamed(
        ClassEntry $entry,
        string $offsetName,
        ?string $offsetType,
        string $valueName = 'value',
        ?string $valueType = 'mixed',
    ): void {
        $offset = new ParameterMetadata($offsetName, [], false, false, false, false, $offsetType, null);
        $value = new ParameterMetadata($valueName, [], false, false, false, false, $valueType, null);
        $entry->methodParameterMetadata['offsetexists'] = [$offset];
        $entry->methodParameterMetadata['offsetget'] = [$offset];
        $entry->methodParameterMetadata['offsetset'] = [$offset, $value];
        $entry->methodParameterMetadata['offsetunset'] = [$offset];
        $entry->methodNames['offsetexists'] = 'offsetExists';
        $entry->methodNames['offsetget'] = 'offsetGet';
        $entry->methodNames['offsetset'] = 'offsetSet';
        $entry->methodNames['offsetunset'] = 'offsetUnset';
    }

    /**
     * @var array<int, array{
     *   flags: int,
     *   table: HashTable,
     *   propList: array<int|string, mixed>,
     *   iteratorClass: ?string,
     *   pos: int
     * }>
     */
    private static array $store = [];

    public static function init(
        ObjectEntry $object,
        HashTable $table,
        int $flags = 0,
        ?string $iteratorClass = null,
        array $propList = []
    ): void {
        self::$store[$object->id] = [
            'flags' => $flags,
            'table' => $table,
            'propList' => $propList,
            'iteratorClass' => $iteratorClass,
            'pos' => 0,
        ];
    }

    /**
     * Materialize / share backing storage for ArrayIterator::__construct(object) (#23886).
     *
     * php-src spl_array_set_array: SPL ArrayObject/ArrayIterator → share live table
     * (SPL_ARRAY_USE_OTHER); when flags arg omitted, inherit other→ar_flags.
     * Plain objects → public/dynamic property hashtable (php-src object properties).
     *
     * @return array{0: HashTable, 1: int}
     */
    public static function storageFromConstructObject(
        ObjectEntry $object,
        int $userFlags,
        bool $inheritFlagsFromOther
    ): array {
        if (self::hasState($object)) {
            $flags = $inheritFlagsFromOther ? self::getFlags($object) : $userFlags;

            return [self::state($object)['table'], $flags];
        }

        return [self::hashTableFromObjectProperties($object), $userFlags];
    }

    /** @see php-src ArrayIterator::__construct(array|object) property materialization */
    public static function hashTableFromObjectProperties(ObjectEntry $object): HashTable
    {
        $table = new HashTable();
        foreach ($object->propertiesWithNames() as $name => $prop) {
            $copy = new Variable();
            $copy->copyFrom($prop->resolveIndirect());
            $intKey = HashTable::tryIntFromNumericString((string) $name);
            if (null !== $intKey) {
                $table->addIndex($intKey, $copy);
            } else {
                $table->add((string) $name, $copy);
            }
        }

        return $table;
    }

    /**
     * php-src spl_array_object_clone — deep-copy HashTable + flags onto the shallow-cloned object (#19803).
     */
    public static function cloneInto(ObjectEntry $src, ObjectEntry $dest): void
    {
        if (!isset(self::$store[$src->id])) {
            return;
        }
        $state = self::$store[$src->id];
        self::$store[$dest->id] = [
            'flags' => $state['flags'],
            'table' => $state['table']->duplicate(),
            'propList' => $state['propList'],
            'iteratorClass' => $state['iteratorClass'],
            'pos' => $state['pos'],
        ];
    }

    /** @return array{flags: int, table: HashTable, propList: array<int|string, mixed>, iteratorClass: ?string, pos: int} */
    public static function state(ObjectEntry $object): array
    {
        if (!isset(self::$store[$object->id])) {
            throw new \LogicException('SPL array object state missing');
        }

        return self::$store[$object->id];
    }

    public static function hasState(ObjectEntry $object): bool
    {
        return isset(self::$store[$object->id]);
    }

    public static function rewindIterator(ObjectEntry $object): void
    {
        self::$store[$object->id]['pos'] = 0;
    }

    public static function nextIterator(ObjectEntry $object): void
    {
        ++self::$store[$object->id]['pos'];
    }

    /** php-src spl_array_seek — position by numeric offset into iterator key list. */
    public static function seekIterator(ObjectEntry $object, int $position): void
    {
        $keyCount = \count(self::iteratorKeys($object));
        if ($position < 0 || $position >= $keyCount) {
            throw new \OutOfBoundsException('Seek position '.$position.' is out of range');
        }
        self::$store[$object->id]['pos'] = $position;
    }

    /** @return list<int|string> */
    public static function iteratorKeys(ObjectEntry $object): array
    {
        $keys = [];
        foreach (self::state($object)['table']->iterateKeyed(true) as [$keyVar, $_]) {
            $keys[] = Variable::TYPE_INTEGER === $keyVar->type
                ? $keyVar->toInt()
                : $keyVar->toString();
        }

        return $keys;
    }

    public static function iteratorValid(ObjectEntry $object): bool
    {
        $state = self::state($object);
        $keys = self::iteratorKeys($object);

        return $state['pos'] >= 0 && $state['pos'] < \count($keys);
    }

    /**
     * php-src SPL_METHOD(Array, current) — NULL when position invalid (bug77903.phpt; #24325).
     */
    public static function iteratorCurrent(ObjectEntry $object): Variable
    {
        $state = self::state($object);
        if (!self::iteratorValid($object)) {
            $null = new Variable();
            $null->null();

            return $null;
        }
        $key = self::iteratorKeys($object)[$state['pos']];
        $var = \is_int($key)
            ? $state['table']->findIndex($key)
            : $state['table']->find((string) $key);
        if (null === $var) {
            throw new \LogicException('ArrayIterator current key missing from backing array');
        }

        return $var;
    }

    /**
     * php-src SPL_METHOD(Array, key) — NULL when position invalid (#24325).
     */
    public static function iteratorKey(ObjectEntry $object): int|string|null
    {
        $state = self::state($object);
        if (!self::iteratorValid($object)) {
            return null;
        }

        return self::iteratorKeys($object)[$state['pos']];
    }

    public static function count(ObjectEntry $object): int
    {
        return \count(self::iteratorKeys($object));
    }

    public static function getArrayCopy(ObjectEntry $object): HashTable
    {
        return self::state($object)['table']->duplicate();
    }

    /**
     * php-src spl_array_get_properties_for(ZEND_PROP_PURPOSE_ARRAY_CAST) — dup backing
     * storage when STD_PROP_LIST is unset; null falls through to zend_std properties (#19631).
     *
     * Uses {@see \PHPCompiler\VM\HashTableJitHelper::duplicateCopy} so JIT/AOT nested helpers
     * resolve the same linked `__hashtable__duplicate` bridge as other cast paths (#18451).
     */
    public static function arrayCastDuplicate(ObjectEntry $object): ?HashTable
    {
        if (!self::hasState($object)) {
            return null;
        }
        if (0 !== (self::getFlags($object) & self::FLAG_STD_PROP_LIST)) {
            return null;
        }

        return \PHPCompiler\VM\HashTableJitHelper::duplicateCopy(self::state($object)['table']);
    }

    /**
     * php-src spl_array_get_properties_for(ZEND_PROP_PURPOSE_VAR_EXPORT) — backing storage
     * when STD_PROP_LIST is unset (#24447); null falls through to zend_std properties.
     */
    public static function varExportStorageTable(ObjectEntry $object): ?HashTable
    {
        if (!self::hasState($object)) {
            return null;
        }
        if (0 !== (self::getFlags($object) & self::FLAG_STD_PROP_LIST)) {
            return null;
        }

        return self::getArrayCopy($object);
    }

    /**
     * php-src spl_array_object_exchange_array — replace backing array, return previous (#12964).
     *
     * Outstanding ArrayIterator / RecursiveArrayIterator instances share the live HashTable
     * (php-src SPL_ARRAY_USE_OTHER via getIterator / ArrayIterator::__construct). Replacing only
     * the ArrayObject store entry would leave them on the stale table (#24243); retarget every
     * store row that still points at the previous HashTable identity. Iterator positions are kept
     * (Zend does not rewind USE_OTHER iterators on exchange).
     */
    public static function exchangeArray(ObjectEntry $object, HashTable $input): HashTable
    {
        $oldTable = self::state($object)['table'];
        $old = $oldTable->duplicate();
        $newTable = $input->duplicate();
        foreach (self::$store as $id => $state) {
            if ($state['table'] === $oldTable) {
                self::$store[$id]['table'] = $newTable;
            }
        }
        self::$store[$object->id]['pos'] = 0;

        return $old;
    }

    /** php-src spl_array_getIteratorClass — default ArrayIterator (#10639). */
    public static function getIteratorClass(ObjectEntry $object): string
    {
        $class = self::state($object)['iteratorClass'];

        return null !== $class && '' !== $class ? $class : 'ArrayIterator';
    }

    /** php-src spl_array_setIteratorClass (#10639). */
    public static function setIteratorClass(ObjectEntry $object, string $iteratorClass): void
    {
        self::$store[$object->id]['iteratorClass'] = $iteratorClass;
    }

    public static function getFlags(ObjectEntry $object): int
    {
        return self::state($object)['flags'];
    }

    public static function setFlags(ObjectEntry $object, int $flags): void
    {
        self::$store[$object->id]['flags'] = $flags;
    }

    public static function isArrayObject(ObjectEntry $object): bool
    {
        return ArrayObjectBuiltin::CLASS_LC === strtolower(ltrim($object->class->name, '\\'));
    }

    /**
     * Zend FE_RESET_RW allow-list: array-backed ArrayIterator (not ArrayObject itself —
     * foreach resolves IteratorAggregate::getIterator() first) (#19444, zend_execute.c).
     */
    public static function allowsForeachByRef(ObjectEntry $object): bool
    {
        return self::hasState($object) && !self::isArrayObject($object);
    }

    /** Live HashTable entry for foreach by-ref write-through (#19444). */
    public static function foreachCurrentByRef(ObjectEntry $object): Variable
    {
        return self::iteratorCurrent($object);
    }

    /** php-src SPL_ARRAY_AS_PROPS — backing array keys as object properties (spl_array.c). */
    public static function hasArrayAsProps(ObjectEntry $object): bool
    {
        return self::hasState($object)
            && 0 !== (self::getFlags($object) & self::FLAG_ARRAY_AS_PROPS);
    }

    /**
     * php-src spl_array_has_property — ARRAY_AS_PROPS backing keys for property_exists() (#31039).
     */
    public static function propertyExistsByName(ObjectEntry $object, string $name): bool
    {
        if (!self::hasArrayAsProps($object)) {
            return false;
        }
        $key = new Variable(Variable::TYPE_STRING);
        $key->string($name);

        return self::offsetExists($object, $key);
    }

    /**
     * php-src spl_array_get_properties — internal storage keys as object properties for json_encode (#13924).
     *
     * @return array<string, Variable>
     */
    public static function collectJsonEncodeProperties(ObjectEntry $object): array
    {
        if (!self::hasState($object)) {
            return [];
        }
        $state = self::state($object);
        if (0 !== ($state['flags'] & self::FLAG_ARRAY_AS_PROPS)) {
            /** @var array<string, Variable> $result */
            $result = [];
            foreach ($state['propList'] as $name => $value) {
                if ($value instanceof Variable) {
                    $copy = new Variable();
                    $copy->copyFrom($value);
                    $result[(string) $name] = $copy;
                }
            }

            return $result;
        }
        /** @var array<string, Variable> $result */
        $result = [];
        foreach ($state['table']->iterateKeyed(true) as [$keyVar, $valVar]) {
            $name = Variable::TYPE_INTEGER === $keyVar->type
                ? (string) $keyVar->toInt()
                : $keyVar->toString();
            $copy = new Variable();
            $copy->copyFrom($valVar);
            $result[$name] = $copy;
        }

        return $result;
    }

    public static function createIterator(Context $ctx, ObjectEntry $object): Variable
    {
        $className = self::getIteratorClass($object);
        $lc = strtolower($className);
        $class = $ctx->classes[$lc] ?? null;
        if (null === $class) {
            throw new \LogicException("Iterator class '{$className}' is not registered");
        }
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        // Share the live backing HashTable (php-src spl_array_get_iterator) so
        // foreach-by-ref / offset writes on the iterator mutate the ArrayObject (#19444).
        $state = self::state($object);
        $table = $state['table'];
        if (
            ArrayIteratorBuiltin::CLASS_LC === $lc
            || RecursiveArrayIteratorBuiltin::CLASS_LC === $lc
        ) {
            SplArrayStorage::init($entry, $table, $state['flags'], null, []);
        } else {
            SplArrayStorage::init($entry, $table, 0, null, []);
        }
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    /**
     * php-src spl_array_read_dimension / ArrayObject::offsetGet — missing keys emit
     * E_WARNING "Undefined array key …" then null (#28820, ext/spl/spl_array.c).
     */
    public static function offsetGet(
        ObjectEntry $object,
        Variable $offset,
        ?Frame $frame = null
    ): Variable {
        $found = self::findOffset(self::state($object)['table'], $offset);
        if (null === $found || $found->resolveIndirect()->isUndefined()) {
            $ctx = $frame?->vmContext;
            if (null !== $ctx) {
                $scriptFile = null !== $frame && '' !== $frame->scriptPath ? $frame->scriptPath : null;
                $ctx->errors->undefinedArrayKey($offset, $ctx, $frame, $scriptFile);
            }
            $var = new Variable(Variable::TYPE_NULL);
            $var->null();

            return $var;
        }
        $resolved = $found->resolveIndirect();
        $out = new Variable($resolved->type);
        $out->copyFrom($resolved);

        return $out;
    }

    public static function offsetSet(ObjectEntry $object, Variable $offset, Variable $value): void
    {
        if (Variable::TYPE_NULL === $offset->resolveIndirect()->type) {
            self::append($object, $value);

            return;
        }
        $table = self::state($object)['table'];
        $resolved = $value->resolveIndirect();
        [$keyVar, $isInt] = self::offsetKeyVar($offset);
        if ($isInt) {
            $idx = $keyVar->toInt();
            if (null !== $table->findIndex($idx)) {
                $table->updateIndex($idx, $resolved);
            } else {
                $table->addIndex($idx, $resolved);
            }

            return;
        }
        $key = $keyVar->toString();
        if (null !== $table->find($key)) {
            $table->update($key, $resolved);
        } else {
            $table->add($key, $resolved);
        }
    }

    /**
     * php-src spl_array_offset_exists — zend_symtable_exists after HashTable tombstones.
     * {@see HashTable::offsetUnset} leaves TYPE_UNDEFINED buckets; {@see HashTable::hasKey}
     * matches array_key_exists (not isset) so null values still exist (#22322).
     */
    public static function offsetExists(ObjectEntry $object, Variable $offset): bool
    {
        [$keyVar] = self::offsetKeyVar($offset);

        return self::state($object)['table']->hasKey($keyVar);
    }

    /**
     * php-src spl_array_has_dimension(check_empty=0) — language isset/?? on dimensions.
     * Key present with a null value is unset; offsetExists (check_empty=2) stays true (#24251).
     */
    public static function dimensionIsSet(ObjectEntry $object, Variable $offset): bool
    {
        if (!self::offsetExists($object, $offset)) {
            return false;
        }
        $found = self::findOffset(self::state($object)['table'], $offset);
        if (null === $found) {
            return false;
        }
        $resolved = $found->resolveIndirect();

        return !$resolved->isUndefined() && Variable::TYPE_NULL !== $resolved->type;
    }

    public static function offsetUnset(ObjectEntry $object, Variable $offset): void
    {
        [$keyVar] = self::offsetKeyVar($offset);
        self::state($object)['table']->offsetUnset($keyVar);
    }

    /** php-src spl_array_method_append — push with next numeric index. */
    /** php-src spl_array_object_sort — in-place sort on backing array (#13141). */
    public static function sortBacking(
        ObjectEntry $object,
        string $kind,
        int $flags = StdlibConstants::SORT_REGULAR
    ): void {
        $table = self::state($object)['table'];
        match ($kind) {
            'asort' => ValueSortJitHelper::asortByValue($table, $flags),
            'arsort' => ValueSortJitHelper::arsortByValue($table, $flags),
            'ksort' => KeySortJitHelper::ksortByKey($table, $flags),
            'krsort' => KeySortJitHelper::krsortByKey($table, $flags),
            'natsort' => NaturalSortJitHelper::natsortByValue($table),
            'natcasesort' => NaturalSortJitHelper::natcasesortByValue($table),
            default => throw new \LogicException('Unsupported SPL array sort: '.$kind),
        };
        self::rewindIterator($object);
    }

    /** php-src spl_array_object_uasort — in-place user value sort (#9356, #23550). */
    public static function uasortBacking(
        ObjectEntry $object,
        Frame $frame,
        Variable $callbackArg,
        string $function = 'uasort'
    ): bool {
        $table = self::state($object)['table'];
        $callback = $callbackArg->resolveIndirect();
        VmArraySortCallback::requireCallback($callback, $function);
        VmArraySortCallback::rejectInvalidStringCallback($frame, $callback, $function);
        VmArraySortCallback::requireVmCallable($frame, $callback, $function);
        if ($table->getNumElements() < 2) {
            return true;
        }
        $pairs = [];
        foreach ($table->iterateKeyed(true) as [$key, $value]) {
            $keyCopy = new Variable();
            $keyCopy->duplicateFrom($key);
            $valCopy = new Variable();
            $valCopy->duplicateFrom($value);
            $pairs[] = [$keyCopy, $valCopy];
        }
        if (VmArraySortCallback::isStrcmpFamilyCallback($callback)) {
            $compare = VmInternalCompare::resolveStringCallback($callback->toString());
            VmInternalCompare::sortKeyedPairsByValue($pairs, $compare);
        } else {
            if (null === $frame->vmContext) {
                throw new \LogicException($function.'() requires VM context in this compiler build');
            }
            VmArraySortCallback::sortKeyedPairsByValue(
                $frame->vmContext,
                $pairs,
                $callback,
                false,
                $frame,
                $function
            );
        }
        $sorted = new HashTable();
        foreach ($pairs as [$key, $value]) {
            array_map::appendKeyedCopy($sorted, $key, $value);
        }
        self::$store[$object->id]['table'] = $sorted;
        self::rewindIterator($object);

        return true;
    }

    /** php-src spl_array_object_uksort — in-place user key sort (#9356, #23550). */
    public static function uksortBacking(
        ObjectEntry $object,
        Frame $frame,
        Variable $callbackArg,
        string $function = 'uksort'
    ): bool {
        $table = self::state($object)['table'];
        $callback = $callbackArg->resolveIndirect();
        VmArraySortCallback::requireCallback($callback, $function);
        VmArraySortCallback::rejectInvalidStringCallback($frame, $callback, $function);
        VmArraySortCallback::requireVmCallable($frame, $callback, $function);
        if ($table->getNumElements() < 2) {
            return true;
        }
        $pairs = [];
        foreach ($table->iterateKeyed(true) as [$key, $value]) {
            $keyCopy = new Variable();
            $keyCopy->duplicateFrom($key);
            $valCopy = new Variable();
            $valCopy->duplicateFrom($value);
            $pairs[] = [$keyCopy, $valCopy];
        }
        if (VmArraySortCallback::isStrcmpFamilyCallback($callback)) {
            $compare = VmInternalCompare::resolveStringCallback($callback->toString());
            VmInternalCompare::sortKeyedPairsByKeyWithCompare($pairs, $compare);
        } else {
            if (null === $frame->vmContext) {
                throw new \LogicException($function.'() requires VM context in this compiler build');
            }
            VmArraySortCallback::sortKeyedPairsByKey(
                $frame->vmContext,
                $pairs,
                $callback,
                false,
                $frame,
                $function
            );
        }
        $sorted = new HashTable();
        foreach ($pairs as [$key, $value]) {
            array_map::appendKeyedCopy($sorted, $key, $value);
        }
        self::$store[$object->id]['table'] = $sorted;
        self::rewindIterator($object);

        return true;
    }

    public static function append(ObjectEntry $object, Variable $value): void
    {
        $resolved = $value->resolveIndirect();
        $stored = new Variable($resolved->type);
        $stored->copyFrom($resolved);
        self::state($object)['table']->append($stored);
    }

    private static function findOffset(\PHPCompiler\VM\HashTable $table, Variable $offset): ?Variable
    {
        [$keyVar, $isInt] = self::offsetKeyVar($offset);

        return $isInt
            ? $table->findIndex($keyVar->toInt())
            : $table->find($keyVar->toString());
    }

    /** @return array{0: Variable, 1: bool} */
    private static function offsetKeyVar(Variable $offset): array
    {
        $resolved = $offset->resolveIndirect();
        if (Variable::TYPE_INTEGER === $resolved->type) {
            $key = new Variable(Variable::TYPE_INTEGER);
            $key->int($resolved->toInt());

            return [$key, true];
        }
        if (Variable::TYPE_STRING === $resolved->type) {
            $key = new Variable(Variable::TYPE_STRING);
            $key->string($resolved->toString());

            return [$key, false];
        }
        if (Variable::TYPE_NULL === $resolved->type) {
            $key = new Variable(Variable::TYPE_STRING);
            $key->string('');

            return [$key, false];
        }
        if (Variable::TYPE_FLOAT === $resolved->type) {
            $key = new Variable(Variable::TYPE_INTEGER);
            $key->int((int) $resolved->toFloat());

            return [$key, true];
        }

        throw new \TypeError('Array access offset must be of type int or string');
    }

    /** @return array<int|string, mixed> */
    public static function hashTableToExportedArray(HashTable $table): array
    {
        $out = [];
        foreach ($table->iterateKeyed(true) as [$keyVar, $valVar]) {
            $key = Variable::TYPE_INTEGER === $keyVar->type
                ? $keyVar->toInt()
                : $keyVar->toString();
            $out[$key] = VmJson::export($valVar);
        }

        return $out;
    }

    /** @param array<int|string, mixed> $data */
    public static function exportedArrayToHashTable(array $data): HashTable
    {
        return VmJson::import($data)->toArray();
    }

    public static function restoreFromExported(
        Context $ctx,
        ObjectEntry $object,
        int $flags,
        array $storage,
        array $propList,
        mixed $iteratorClass
    ): void {
        $table = self::exportedArrayToHashTable($storage);
        $iterClass = \is_string($iteratorClass) && '' !== $iteratorClass ? $iteratorClass : null;
        self::init($object, $table, $flags, $iterClass, $propList);
    }
}
