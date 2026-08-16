<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\WeakRefSupport;
use PHPCfg\Func as CfgFunc;

/**
 * SplObjectStorage — object-to-data map (php-src ext/spl/spl_observer.c; #12962).
 */
final class SplObjectStorageBuiltin
{
    public const CLASS_LC = 'splobjectstorage';

    /**
     * @var array<int, array{
     *   entries: array<string, Variable>,
     *   objects: array<string, Variable>,
     *   order: list<string>,
     *   pos: int
     * }>
     */
    private static array $store = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            self::attachDeclaredInterfaces($ctx, $ctx->classes[self::CLASS_LC]);

            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('SplObjectStorage');
        self::attachDeclaredInterfaces($ctx, $entry);

        $entry->constructor = new SplObjectStorageConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        foreach ([
            'attach' => SplObjectStorageAttach::class,
            'addall' => SplObjectStorageAddAll::class,
            'removeall' => SplObjectStorageRemoveAll::class,
            'removeallexcept' => SplObjectStorageRemoveAllExcept::class,
            'contains' => SplObjectStorageContains::class,
            'count' => SplObjectStorageCount::class,
            'detach' => SplObjectStorageDetach::class,
            'rewind' => SplObjectStorageRewind::class,
            'valid' => SplObjectStorageValid::class,
            'current' => SplObjectStorageCurrent::class,
            'key' => SplObjectStorageKey::class,
            'next' => SplObjectStorageNext::class,
            'getinfo' => SplObjectStorageGetInfo::class,
            'setinfo' => SplObjectStorageSetInfo::class,
            'gethash' => SplObjectStorageGetHash::class,
            'offsetget' => SplObjectStorageOffsetGet::class,
            'offsetset' => SplObjectStorageOffsetSet::class,
            'offsetexists' => SplObjectStorageOffsetExists::class,
            'offsetunset' => SplObjectStorageOffsetUnset::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['offsetget'] = 'offsetGet';
        $entry->methodNames['offsetset'] = 'offsetSet';
        $entry->methodNames['offsetexists'] = 'offsetExists';
        $entry->methodNames['offsetunset'] = 'offsetUnset';
        // php-src spl_observer.stub.php — untyped $object; offsetSet info:mixed (#25856).
        SplArrayStorage::attachArrayAccessArginfoNamed($entry, 'object', null, 'info', 'mixed');
        $entry->methodNames['getinfo'] = 'getInfo';
        $entry->methodNames['setinfo'] = 'setInfo';
        $entry->methodNames['gethash'] = 'getHash';
        $entry->methodNames['addall'] = 'addAll';
        $entry->methodNames['removeall'] = 'removeAll';
        $entry->methodNames['removeallexcept'] = 'removeAllExcept';

        $entry->methods['__debuginfo'] = new SplObjectStorageDebugInfo();
        $entry->methodVisibility['__debuginfo'] = $pub;
        $entry->methodNames['__debuginfo'] = '__debugInfo';

        $entry->methods['__serialize'] = new SplObjectStorageSerialize();
        $entry->methodVisibility['__serialize'] = $pub;
        $entry->methods['__unserialize'] = new SplObjectStorageUnserialize();
        $entry->methodVisibility['__unserialize'] = $pub;

        $entry->isInternal = true;
        SplLegacySerializableMethods::register($entry, self::CLASS_LC, 'SplObjectStorage');
        $entry->cloneObjectHandler = [self::class, 'cloneInto'];
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset(
            $entry->methods['offsetset'],
            $entry->methods['attach'],
            $entry->methods['addall'],
            $entry->methods['removeall'],
            $entry->methods['removeallexcept'],
            $entry->methods['detach'],
            $entry->methods['rewind'],
            $entry->methods['getinfo'],
            $entry->methods['__debuginfo'],
            $entry->methods['__serialize'],
            $entry->methods['__unserialize']
        );
    }

    /** php-src ext/spl/spl_observer.c — lowercase ctx keys for class_implements() (#14033). */
    private static function attachDeclaredInterfaces(Context $ctx, ClassEntry $entry): void
    {
        $want = ['countable', 'iterator', 'traversable', 'serializable', 'arrayaccess'];
        $normalized = [];
        foreach ($entry->interfaces as $iface) {
            $lc = strtolower($iface);
            if (\in_array($lc, $want, true) && isset($ctx->classes[$lc])) {
                $normalized[$lc] = $lc;
            }
        }
        foreach ($want as $iface) {
            if (isset($ctx->classes[$iface])) {
                $normalized[$iface] = $iface;
            }
        }
        $entry->interfaces = array_values($normalized);
    }

    public static function init(ObjectEntry $object): void
    {
        self::$store[$object->id] = ['entries' => [], 'objects' => [], 'order' => [], 'pos' => 0];
    }

    /**
     * php-src SplObjectStorage clone_obj — deep-copy object→info map onto the shallow clone (#19805).
     */
    public static function cloneInto(ObjectEntry $src, ObjectEntry $dest): void
    {
        if (!isset(self::$store[$src->id])) {
            self::init($dest);

            return;
        }
        $state = self::$store[$src->id];
        $entries = [];
        $objects = [];
        foreach ($state['entries'] as $key => $info) {
            $infoCopy = new Variable();
            $infoCopy->copyFrom($info->resolveIndirect());
            $entries[$key] = $infoCopy;
        }
        foreach ($state['objects'] as $key => $object) {
            $objectCopy = new Variable();
            $objectCopy->copyFrom($object->resolveIndirect());
            $objects[$key] = $objectCopy;
        }
        self::$store[$dest->id] = [
            'entries' => $entries,
            'objects' => $objects,
            'order' => $state['order'],
            'pos' => $state['pos'],
        ];
    }

    /** @return array{entries: array<string, Variable>, objects: array<string, Variable>, order: list<string>, pos: int} */
    private static function state(ObjectEntry $object): array
    {
        if (!isset(self::$store[$object->id])) {
            self::init($object);
        }

        return self::$store[$object->id];
    }

    public static function addAll(ObjectEntry $storage, ObjectEntry $other): void
    {
        $otherState = self::state($other);
        foreach ($otherState['order'] as $key) {
            $object = self::objectForStoredKey($otherState, $key);
            if (null === $object) {
                continue;
            }
            self::attach($storage, $object, $otherState['entries'][$key]);
        }
    }

    /** php-src SplObjectStorage::removeAll — detach every object present in $other. */
    public static function removeAll(ObjectEntry $storage, ObjectEntry $other): void
    {
        $otherState = self::state($other);
        foreach ($otherState['order'] as $key) {
            $object = self::objectForStoredKey($otherState, $key);
            if (null === $object) {
                continue;
            }
            self::detach($storage, $object);
        }
    }

    /** php-src SplObjectStorage::removeAllExcept — keep only objects also present in $other. */
    public static function removeAllExcept(ObjectEntry $storage, ObjectEntry $other): void
    {
        $state = self::state($storage);
        $otherState = self::state($other);
        foreach ($state['order'] as $key) {
            if (isset($otherState['entries'][$key])) {
                continue;
            }
            $object = self::objectForStoredKey($state, $key);
            if (null === $object) {
                continue;
            }
            self::detach($storage, $object);
        }
    }

    /**
     * @param string $method  TypeError display name — php-src offsetSet/write_dimension cite
     *                        offsetSet, not attach (#31509 / ext/spl/spl_observer.c).
     */
    public static function attach(
        ObjectEntry $storage,
        Variable $object,
        ?Variable $info = null,
        string $method = 'attach'
    ): void {
        $key = self::storageObjectKey($object, $method);
        $state = self::state($storage);
        if (!isset($state['entries'][$key])) {
            self::$store[$storage->id]['order'][] = $key;
        }
        $objectCopy = new Variable();
        $objectCopy->copyFrom($object->resolveIndirect());
        self::$store[$storage->id]['objects'][$key] = $objectCopy;
        if (null === $info) {
            $stored = new Variable(Variable::TYPE_NULL);
            $stored->null();
        } else {
            $stored = new Variable();
            $stored->copyFrom($info->resolveIndirect());
        }
        self::$store[$storage->id]['entries'][$key] = $stored;
    }

    public static function contains(ObjectEntry $storage, Variable $object, string $method = 'contains'): bool
    {
        $key = self::storageObjectKey($object, $method);

        return isset(self::state($storage)['entries'][$key]);
    }

    public static function count(ObjectEntry $storage): int
    {
        return \count(self::state($storage)['entries']);
    }

    /**
     * @return list<array{0: Variable, 1: Variable}>
     */
    public static function exportSerializeEntries(ObjectEntry $storage): array
    {
        $out = [];
        $state = self::state($storage);
        foreach ($state['order'] as $key) {
            if (!isset($state['entries'][$key])) {
                continue;
            }
            $object = self::objectForStoredKey($state, $key);
            if (null === $object) {
                continue;
            }
            $out[] = [$object, $state['entries'][$key]];
        }

        return $out;
    }

    /**
     * Private storage bag for var_dump (php-src spl_object_storage_debug_info; #19826).
     */
    public static function debugInfoTable(ObjectEntry $storage): HashTable
    {
        $rows = [];
        foreach (self::exportSerializeEntries($storage) as [$objectVar, $infoVar]) {
            $row = new HashTable();
            $obj = new Variable();
            $obj->copyFrom($objectVar->resolveIndirect());
            $row->addNew('obj', $obj);
            $inf = new Variable();
            $inf->copyFrom($infoVar->resolveIndirect());
            $row->addNew('inf', $inf);
            $rowVar = new Variable();
            $rowVar->array($row);
            $rows[] = $rowVar;
        }
        $storageBag = new Variable();
        $storageHt = new HashTable();
        if ([] !== $rows) {
            $storageHt->assignPackedList($rows);
        }
        $storageBag->array($storageHt);

        $ht = new HashTable();
        $ht->addNew("\0SplObjectStorage\0storage", $storageBag);

        return $ht;
    }

    public static function offsetGet(ObjectEntry $storage, Variable $object): Variable
    {
        $key = self::storageObjectKey($object, 'offsetGet');
        $entries = self::state($storage)['entries'];
        if (!isset($entries[$key])) {
            throw new \UnexpectedValueException('Object not found');
        }
        $out = new Variable();
        $out->copyFrom($entries[$key]);

        return $out;
    }

    public static function offsetSet(ObjectEntry $storage, Variable $object, Variable $value): void
    {
        // php-src SPL_METHOD(SplObjectStorage, offsetSet) / write_dimension — TypeError cites offsetSet (#31509).
        self::attach($storage, $object, $value, 'offsetSet');
    }

    public static function offsetExists(ObjectEntry $storage, Variable $object): bool
    {
        return self::contains($storage, $object, 'offsetExists');
    }

    public static function detach(ObjectEntry $storage, Variable $object): void
    {
        self::offsetUnset($storage, $object, 'detach');
    }

    public static function offsetUnset(ObjectEntry $storage, Variable $object, string $method = 'offsetUnset'): void
    {
        $key = self::storageObjectKey($object, $method);
        if (!isset(self::state($storage)['entries'][$key])) {
            return;
        }
        $order = self::$store[$storage->id]['order'];
        $removedIndex = array_search($key, $order, true);
        unset(self::$store[$storage->id]['entries'][$key], self::$store[$storage->id]['objects'][$key]);
        self::$store[$storage->id]['order'] = array_values(
            array_filter($order, static fn (string $storedKey): bool => $storedKey !== $key)
        );
        if (false !== $removedIndex) {
            $pos = self::$store[$storage->id]['pos'];
            if ($removedIndex < $pos) {
                --self::$store[$storage->id]['pos'];
            } elseif ($removedIndex === $pos) {
                self::$store[$storage->id]['pos'] = min($pos, \count(self::$store[$storage->id]['order']));
            }
        }
    }

    public static function rewind(ObjectEntry $storage): void
    {
        self::$store[$storage->id]['pos'] = 0;
    }

    public static function next(ObjectEntry $storage): void
    {
        ++self::$store[$storage->id]['pos'];
    }

    public static function valid(ObjectEntry $storage): bool
    {
        $state = self::state($storage);

        return $state['pos'] >= 0 && $state['pos'] < \count($state['order']);
    }

    public static function current(ObjectEntry $storage): Variable
    {
        if (!self::valid($storage)) {
            throw new \RuntimeException('Called current() on invalid iterator position');
        }
        $key = self::state($storage)['order'][self::$store[$storage->id]['pos']];
        $object = self::objectForStoredKey(self::state($storage), $key);
        if (null === $object) {
            throw new \LogicException('SplObjectStorage iterator object missing');
        }

        return $object;
    }

    /**
     * @param array{entries: array<string, Variable>, objects: array<string, Variable>, order: list<string>, pos: int} $state
     */
    private static function objectForStoredKey(array $state, string $key): ?Variable
    {
        if (isset($state['objects'][$key])) {
            return $state['objects'][$key];
        }

        return WeakRefSupport::resolveMapKeyVariable($key);
    }

    /**
     * php-src SplObjectStorage::key — current iterator index (including past-end; #24327).
     */
    public static function key(ObjectEntry $storage): Variable
    {
        $out = new Variable();
        $out->int(self::state($storage)['pos']);

        return $out;
    }

    public static function getInfo(ObjectEntry $storage): Variable
    {
        if (!self::valid($storage)) {
            return self::nullVariable();
        }
        $key = self::state($storage)['order'][self::$store[$storage->id]['pos']];
        $out = new Variable();
        $out->copyFrom(self::state($storage)['entries'][$key]);

        return $out;
    }

    public static function setInfo(ObjectEntry $storage, Variable $info): void
    {
        if (!self::valid($storage)) {
            return;
        }
        $key = self::state($storage)['order'][self::$store[$storage->id]['pos']];
        $stored = new Variable();
        $stored->copyFrom($info->resolveIndirect());
        self::$store[$storage->id]['entries'][$key] = $stored;
    }

    public static function getHash(Variable $object, ?\PHPCompiler\VM\Context $context = null): string
    {
        self::requireStorageObject($object, 'getHash');

        // php-src spl_object_storage_get_hash — same 32-hex as spl_object_hash() (#24292).
        return \PHPCompiler\VM\ObjectHandleSupport::hashForObject(
            $object,
            'SplObjectStorage::getHash',
            $context
        );
    }

    private static function storageObjectKey(Variable $object, string $method): string
    {
        self::requireStorageObject($object, $method);

        return WeakRefSupport::objectKey($object);
    }

    private static function requireStorageObject(Variable $object, string $method): Variable
    {
        $resolved = $object->resolveIndirect();
        if (Variable::TYPE_OBJECT === $resolved->type || EnumCaseSupport::isEnumCaseVariable($resolved)) {
            return $object;
        }

        throw new \TypeError(\sprintf(
            'SplObjectStorage::%s(): Argument #1 ($object) must be of type object, %s given',
            $method,
            self::storageObjectTypeName($resolved)
        ));
    }

    private static function storageObjectTypeName(Variable $resolved): string
    {
        if (EnumCaseSupport::isEnumCaseVariable($resolved)) {
            return EnumCaseSupport::typeNameForVariable($resolved);
        }

        return match ($resolved->type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_RESOURCE => 'resource',
            default => 'unknown type',
        };
    }

    private static function nullVariable(): Variable
    {
        $out = new Variable(Variable::TYPE_NULL);
        $out->null();

        return $out;
    }
}

/**
 * SplObjectStorage::__debugInfo() — private storage rows {obj,inf} (#19826).
 */
final class SplObjectStorageDebugInfo extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__debugInfo');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::__debugInfo()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(SplObjectStorageBuiltin::debugInfoTable($object));
    }
}

final class SplObjectStorageConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::__construct()'
        );
        SplObjectStorageBuiltin::init($object);
    }
}

final class SplObjectStorageAttach extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('attach');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::attach()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_START(1, 2) — #30954
        $this->requireUserArgCountRange($frame, 'SplObjectStorage::attach', 1, 2);
        $info = isset($frame->calledArgs[2]) ? $frame->calledArgs[2] : null;
        SplObjectStorageBuiltin::attach($object, $frame->calledArgs[1], $info);
    }
}

final class SplObjectStorageAddAll extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('addAll');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::addAll()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_START(1, 1) — #30999
        $this->requireExactUserArgCount($frame, 'SplObjectStorage::addAll', 1);
        $other = SplObjectStorageStorageArg::require($frame->calledArgs[1], 'addAll');
        SplObjectStorageBuiltin::addAll($object, $other);
    }
}

final class SplObjectStorageRemoveAll extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('removeAll');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::removeAll()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_START(1, 1) — #30999
        $this->requireExactUserArgCount($frame, 'SplObjectStorage::removeAll', 1);
        $other = SplObjectStorageStorageArg::require($frame->calledArgs[1], 'removeAll');
        SplObjectStorageBuiltin::removeAll($object, $other);
    }
}

final class SplObjectStorageRemoveAllExcept extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('removeAllExcept');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::removeAllExcept()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_START(1, 1) — #30999
        $this->requireExactUserArgCount($frame, 'SplObjectStorage::removeAllExcept', 1);
        $other = SplObjectStorageStorageArg::require($frame->calledArgs[1], 'removeAllExcept');
        SplObjectStorageBuiltin::removeAllExcept($object, $other);
    }
}

/** Shared SplObjectStorage argument coercion for addAll/removeAll/removeAllExcept. */
final class SplObjectStorageStorageArg
{
    public static function require(Variable $arg, string $method): ObjectEntry
    {
        $otherVar = $arg->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $otherVar->type) {
            throw new \TypeError(
                'SplObjectStorage::'.$method.'(): Argument #1 ($storage) must be of type SplObjectStorage, '
                .self::label($otherVar).' given'
            );
        }
        $other = $otherVar->toObject();
        if (strtolower($other->class->name) !== SplObjectStorageBuiltin::CLASS_LC) {
            throw new \TypeError(
                'SplObjectStorage::'.$method.'(): Argument #1 ($storage) must be of type SplObjectStorage, '
                .$other->class->name.' given'
            );
        }

        return $other;
    }

    public static function label(Variable $value): string
    {
        return match ($value->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INT => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $value->toObject()->class->name,
            default => 'mixed',
        };
    }
}

final class SplObjectStorageContains extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('contains');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::contains()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_START(1, 1) — #30954
        $this->requireExactUserArgCount($frame, 'SplObjectStorage::contains', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(
            SplObjectStorageBuiltin::contains($object, $frame->calledArgs[1])
        );
    }
}

final class SplObjectStorageCount extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::count()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(SplObjectStorageBuiltin::count($object));
    }
}

final class SplObjectStorageOffsetGet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetGet');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::offsetGet()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_START(1, 1) — #30999
        $this->requireExactUserArgCount($frame, 'SplObjectStorage::offsetGet', 1);
        if (null === $frame->returnVar) {
            return;
        }
        SplIteratorSupport::copyReturnFrom(
            $frame,
            SplObjectStorageBuiltin::offsetGet($object, $frame->calledArgs[1])
        );
    }
}

final class SplObjectStorageOffsetSet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetSet');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::offsetSet()'
        );
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'SplObjectStorage::offsetSet() expects exactly 2 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        SplObjectStorageBuiltin::offsetSet($object, $frame->calledArgs[1], $frame->calledArgs[2]);
    }
}

final class SplObjectStorageOffsetExists extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetExists');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::offsetExists()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'SplObjectStorage::offsetExists() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(
            SplObjectStorageBuiltin::offsetExists($object, $frame->calledArgs[1])
        );
    }
}

final class SplObjectStorageDetach extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('detach');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::detach()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_START(1, 1) — #30954
        $this->requireExactUserArgCount($frame, 'SplObjectStorage::detach', 1);
        SplObjectStorageBuiltin::detach($object, $frame->calledArgs[1]);
    }
}

final class SplObjectStorageRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::rewind()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_NONE — #30999
        $this->requireExactUserArgCount($frame, 'SplObjectStorage::rewind', 0);
        SplObjectStorageBuiltin::rewind($object);
    }
}

final class SplObjectStorageNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::next()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_NONE — #30999
        $this->requireExactUserArgCount($frame, 'SplObjectStorage::next', 0);
        SplObjectStorageBuiltin::next($object);
    }
}

final class SplObjectStorageValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::valid()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_NONE — #30999
        $this->requireExactUserArgCount($frame, 'SplObjectStorage::valid', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(SplObjectStorageBuiltin::valid($object));
    }
}

final class SplObjectStorageCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::current()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_NONE — #30999
        $this->requireExactUserArgCount($frame, 'SplObjectStorage::current', 0);
        if (null === $frame->returnVar) {
            return;
        }
        SplIteratorSupport::copyReturnFrom(
            $frame,
            SplObjectStorageBuiltin::current($object)
        );
    }
}

final class SplObjectStorageKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::key()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_NONE — #30999
        $this->requireExactUserArgCount($frame, 'SplObjectStorage::key', 0);
        if (null === $frame->returnVar) {
            return;
        }
        SplIteratorSupport::copyReturnFrom(
            $frame,
            SplObjectStorageBuiltin::key($object)
        );
    }
}

final class SplObjectStorageGetInfo extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getInfo');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::getInfo()'
        );
        if (\count($frame->calledArgs) > 1) {
            throw new \ArgumentCountError(
                'SplObjectStorage::getInfo() expects exactly 0 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        SplIteratorSupport::copyReturnFrom($frame, SplObjectStorageBuiltin::getInfo($object));
    }
}

final class SplObjectStorageSetInfo extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setInfo');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::setInfo()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_START(1, 1) — #30954
        $this->requireExactUserArgCount($frame, 'SplObjectStorage::setInfo', 1);
        SplObjectStorageBuiltin::setInfo($object, $frame->calledArgs[1]);
    }
}

final class SplObjectStorageGetHash extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getHash');
    }

    public function execute(Frame $frame): void
    {
        SplIteratorSupport::receiverIsA(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::getHash()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_START(1, 1) — #30999
        $this->requireExactUserArgCount($frame, 'SplObjectStorage::getHash', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(
            SplObjectStorageBuiltin::getHash($frame->calledArgs[1], $frame->vmContext)
        );
    }
}

final class SplObjectStorageOffsetUnset extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetUnset');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::offsetUnset()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'SplObjectStorage::offsetUnset() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        SplObjectStorageBuiltin::offsetUnset($object, $frame->calledArgs[1]);
    }
}

/** php-src SplObjectStorage::__serialize (ext/spl/spl_observer.c; #22268). */
final class SplObjectStorageSerialize extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__serialize');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::__serialize()'
        );
        SplIteratorSupport::copyReturnFrom(
            $frame,
            SplObjectStorageSerializeSupport::exportSerializeBag($object)
        );
    }
}

/** php-src SplObjectStorage::__unserialize (ext/spl/spl_observer.c; #22268). */
final class SplObjectStorageUnserialize extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__unserialize');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::__unserialize()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'SplObjectStorage::__unserialize() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $arg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arg->type) {
            throw new \TypeError(
                'SplObjectStorage::__unserialize(): Argument #1 ($data) must be of type array'
            );
        }
        SplObjectStorageSerializeSupport::restoreFromSerializeBag($object, $arg);
    }
}
