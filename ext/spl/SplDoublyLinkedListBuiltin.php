<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * SplDoublyLinkedList — deque push/pop/shift/unshift (php-src ext/spl/spl_dllist.c; #13080).
 */
final class SplDoublyLinkedListBuiltin
{
    public const CLASS_LC = 'spldoublylinkedlist';

    /** php-src SPL_DLLIST_IT_* (ext/spl/spl_dllist.h). */
    public const IT_MODE_FIFO = 0;

    public const IT_MODE_DELETE = 1;

    public const IT_MODE_LIFO = 2;

    public const IT_MODE_KEEP = 0;

    /** php-src SPL_DLLIST_IT_FIX — frozen LIFO/FIFO for SplQueue/SplStack. */
    public const IT_MODE_FIX = 4;

    private const IT_MODE_MASK = 3;

    /** @var array<int, list<Variable>> */
    private static array $store = [];

    /** @var array<int, int> */
    private static array $iteratorModes = [];

    /** @var array<int, int> iterator position per object (-1 = invalid) */
    private static array $iteratorPositions = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('SplDoublyLinkedList');
        foreach (['iterator', 'traversable', 'countable', 'arrayaccess', 'serializable'] as $iface) {
            if (isset($ctx->classes[$iface])
                && !\in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }

        // php-src REGISTER_SPL_CLASS_CONST_LONG for IT_MODE_* (#22350).
        SplClassConstants::registerIntConstants($entry, [
            'IT_MODE_LIFO' => self::IT_MODE_LIFO,
            'IT_MODE_FIFO' => self::IT_MODE_FIFO,
            'IT_MODE_DELETE' => self::IT_MODE_DELETE,
            'IT_MODE_KEEP' => self::IT_MODE_KEEP,
        ]);

        // php-src 8.2: no reflected/get_class_methods __construct (create via ce handler only) (#22789).
        $entry->constructor = new SplDoublyLinkedListConstruct();
        foreach ([
            'push' => SplDoublyLinkedListPush::class,
            'pop' => SplDoublyLinkedListPop::class,
            'shift' => SplDoublyLinkedListShift::class,
            'unshift' => SplDoublyLinkedListUnshift::class,
            'top' => SplDoublyLinkedListTop::class,
            'bottom' => SplDoublyLinkedListBottom::class,
            'isempty' => SplDoublyLinkedListIsEmpty::class,
            'add' => SplDoublyLinkedListAdd::class,
            'count' => SplDoublyLinkedListCount::class,
            'offsetget' => SplDoublyLinkedListOffsetGet::class,
            'offsetset' => SplDoublyLinkedListOffsetSet::class,
            'offsetexists' => SplDoublyLinkedListOffsetExists::class,
            'offsetunset' => SplDoublyLinkedListOffsetUnset::class,
            'rewind' => SplDoublyLinkedListRewind::class,
            'valid' => SplDoublyLinkedListValid::class,
            'current' => SplDoublyLinkedListCurrent::class,
            'key' => SplDoublyLinkedListKey::class,
            'next' => SplDoublyLinkedListNext::class,
            'prev' => SplDoublyLinkedListPrev::class,
            'setiteratormode' => SplDoublyLinkedListSetIteratorMode::class,
            'getiteratormode' => SplDoublyLinkedListGetIteratorMode::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['offsetget'] = 'offsetGet';
        $entry->methodNames['offsetset'] = 'offsetSet';
        $entry->methodNames['offsetexists'] = 'offsetExists';
        $entry->methodNames['offsetunset'] = 'offsetUnset';
        // php-src spl_dllist.stub.php — untyped $index; @tentative-return-type (#25856).
        SplArrayStorage::attachArrayAccessArginfoNamed($entry, 'index', null, 'value', 'mixed');
        $entry->methodNames['setiteratormode'] = 'setIteratorMode';
        $entry->methodNames['getiteratormode'] = 'getIteratorMode';
        $entry->methodNames['isempty'] = 'isEmpty';

        $entry->methods['__debuginfo'] = new SplDoublyLinkedListDebugInfo();
        $entry->methodVisibility['__debuginfo'] = $pub;
        $entry->methodNames['__debuginfo'] = '__debugInfo';

        $entry->isInternal = true;
        SplLegacySerializableMethods::register($entry, self::CLASS_LC, 'SplDoublyLinkedList');
        SplDllistSerializeSupport::registerMagicMethods($entry, $pub);
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset(
            $entry->methods['push'],
            $entry->methods['pop'],
            $entry->methods['top'],
            $entry->methods['bottom'],
            $entry->methods['isempty'],
            $entry->methods['add'],
            $entry->methods['prev'],
            $entry->methods['offsetget'],
            $entry->methods['rewind'],
            $entry->methods['valid'],
            $entry->methods['__debuginfo'],
            $entry->methods['__serialize'],
            $entry->methods['__unserialize']
        );
    }

    public static function init(ObjectEntry $object, int $iteratorMode = 0): void
    {
        self::$store[$object->id] = [];
        self::$iteratorModes[$object->id] = $iteratorMode;
    }

    public static function getIteratorMode(ObjectEntry $object): int
    {
        if (!isset(self::$iteratorModes[$object->id])) {
            self::init($object);
        }

        return self::$iteratorModes[$object->id];
    }

    public static function setIteratorMode(ObjectEntry $object, int $mode): int
    {
        $current = self::getIteratorMode($object);
        if (
            0 !== ($current & self::IT_MODE_FIX)
            && ($current & self::IT_MODE_LIFO) !== ($mode & self::IT_MODE_LIFO)
        ) {
            throw new \RuntimeException(
                "Iterators' LIFO/FIFO modes for SplStack/SplQueue objects are frozen"
            );
        }
        self::$iteratorModes[$object->id] = ($mode & self::IT_MODE_MASK) | ($current & self::IT_MODE_FIX);

        return self::$iteratorModes[$object->id];
    }

    public static function rewind(ObjectEntry $object): void
    {
        $mode = self::getIteratorMode($object);
        $count = self::count($object);
        if ($mode & self::IT_MODE_LIFO) {
            self::$iteratorPositions[$object->id] = $count > 0 ? $count - 1 : -1;
        } else {
            self::$iteratorPositions[$object->id] = $count > 0 ? 0 : -1;
        }
    }

    public static function valid(ObjectEntry $object): bool
    {
        $pos = self::iteratorPosition($object);

        return $pos >= 0 && $pos < self::count($object);
    }

    /**
     * php-src SplDoublyLinkedList::current — NULL when iterator not valid (#24326).
     */
    public static function current(ObjectEntry $object): Variable
    {
        if (!self::valid($object)) {
            $null = new Variable();
            $null->null();

            return $null;
        }
        $pos = self::iteratorPosition($object);
        $result = new Variable();
        $result->copyFrom(self::state($object)[$pos]);

        return $result;
    }

    public static function key(ObjectEntry $object): int
    {
        return self::iteratorPosition($object);
    }

    public static function next(ObjectEntry $object): void
    {
        if (!self::valid($object)) {
            return;
        }
        $mode = self::getIteratorMode($object);
        if ($mode & self::IT_MODE_DELETE) {
            if ($mode & self::IT_MODE_LIFO) {
                self::pop($object);
                $count = self::count($object);
                self::$iteratorPositions[$object->id] = $count > 0 ? $count - 1 : -1;
            } else {
                self::shift($object);
                self::$iteratorPositions[$object->id] = self::count($object) > 0 ? 0 : -1;
            }
        } elseif ($mode & self::IT_MODE_LIFO) {
            --self::$iteratorPositions[$object->id];
        } else {
            ++self::$iteratorPositions[$object->id];
        }
    }

    /** php-src spl_dllist_it_rewind / bidirectional — opposite of next() keep-mode step. */
    public static function prev(ObjectEntry $object): void
    {
        if (!self::valid($object)) {
            return;
        }
        $mode = self::getIteratorMode($object);
        if ($mode & self::IT_MODE_LIFO) {
            ++self::$iteratorPositions[$object->id];
        } else {
            --self::$iteratorPositions[$object->id];
        }
    }

    /**
     * php-src SplDoublyLinkedList::add — insert at $index (0..count inclusive).
     */
    public static function add(ObjectEntry $object, Variable $index, Variable $value): void
    {
        $pos = self::coerceIndex($index, 'add', false);
        $count = self::count($object);
        if ($pos < 0 || $pos > $count) {
            throw new \OutOfRangeException(
                'SplDoublyLinkedList::add(): Argument #1 ($index) is out of range'
            );
        }
        $copy = new Variable();
        $copy->copyFrom($value->resolveIndirect());
        \array_splice(self::$store[$object->id], $pos, 0, [$copy]);
    }

    private static function iteratorPosition(ObjectEntry $object): int
    {
        if (!isset(self::$iteratorPositions[$object->id])) {
            self::rewind($object);
        }

        return self::$iteratorPositions[$object->id];
    }

    /**
     * @return array<int, mixed>
     */
    public static function exportElements(ObjectEntry $object): array
    {
        $exported = [];
        foreach (self::state($object) as $index => $var) {
            $exported[$index] = VmJson::export($var->resolveIndirect());
        }

        return $exported;
    }

    /**
     * Private flags + dllist bag for var_dump/print_r (php-src spl_dllist_object_get_debug_info; #19824).
     */
    public static function debugInfoTable(ObjectEntry $object): HashTable
    {
        $ht = new HashTable();
        $flags = new Variable();
        $flags->int(self::getIteratorMode($object));
        $ht->addNew("\0SplDoublyLinkedList\0flags", $flags);

        $values = [];
        foreach (self::state($object) as $var) {
            $copy = new Variable();
            $copy->copyFrom($var->resolveIndirect());
            $values[] = $copy;
        }
        $dllist = new Variable();
        $dllistHt = new HashTable();
        if ([] !== $values) {
            $dllistHt->assignPackedList($values);
        }
        $dllist->array($dllistHt);
        $ht->addNew("\0SplDoublyLinkedList\0dllist", $dllist);

        return $ht;
    }

    /**
     * @param array<int|string, mixed> $elements
     */
    public static function restoreFromExported(ObjectEntry $object, int $iteratorMode, array $elements): void
    {
        self::init($object, $iteratorMode);
        if ([] === $elements) {
            return;
        }
        $indexed = [];
        foreach ($elements as $key => $raw) {
            if (!\is_int($key) && (!\is_string($key) || !ctype_digit((string) $key))) {
                continue;
            }
            $indexed[(int) $key] = $raw;
        }
        ksort($indexed);
        foreach ($indexed as $raw) {
            $imported = VmJson::import($raw);
            $copy = new Variable();
            $copy->copyFrom($imported);
            self::$store[$object->id][] = $copy;
        }
    }

    /** @return list<Variable> */
    private static function state(ObjectEntry $object): array
    {
        if (!isset(self::$store[$object->id])) {
            self::init($object);
        }

        return self::$store[$object->id];
    }

    public static function push(ObjectEntry $object, Variable $value): void
    {
        $copy = new Variable();
        $copy->copyFrom($value->resolveIndirect());
        self::$store[$object->id][] = $copy;
    }

    public static function pop(ObjectEntry $object): Variable
    {
        $state = self::state($object);
        if ([] === $state) {
            throw new \RuntimeException("Can't pop from an empty datastructure");
        }
        $last = \array_pop(self::$store[$object->id]);
        $result = new Variable();
        $result->copyFrom($last);

        return $result;
    }

    public static function top(ObjectEntry $object): Variable
    {
        $state = self::state($object);
        if ([] === $state) {
            throw new \RuntimeException("Can't peek at an empty datastructure");
        }
        $last = $state[\count($state) - 1];
        $result = new Variable();
        $result->copyFrom($last);

        return $result;
    }

    /** php-src spl_dllist_object_bottom — peek first element without removal. */
    public static function bottom(ObjectEntry $object): Variable
    {
        $state = self::state($object);
        if ([] === $state) {
            throw new \RuntimeException("Can't peek at an empty datastructure");
        }
        $first = $state[0];
        $result = new Variable();
        $result->copyFrom($first);

        return $result;
    }

    public static function isEmpty(ObjectEntry $object): bool
    {
        return 0 === self::count($object);
    }

    public static function shift(ObjectEntry $object): Variable
    {
        $state = self::state($object);
        if ([] === $state) {
            throw new \RuntimeException("Can't shift from an empty datastructure");
        }
        $first = \array_shift(self::$store[$object->id]);
        $result = new Variable();
        $result->copyFrom($first);

        return $result;
    }

    public static function unshift(ObjectEntry $object, Variable $value): void
    {
        $copy = new Variable();
        $copy->copyFrom($value->resolveIndirect());
        \array_unshift(self::$store[$object->id], $copy);
    }

    public static function count(ObjectEntry $object): int
    {
        return \count(self::state($object));
    }

    public static function offsetExists(ObjectEntry $object, Variable $offset): bool
    {
        $index = self::coerceIndex($offset, 'offsetExists', true);
        $count = \count(self::state($object));

        return $index >= 0 && $index < $count;
    }

    public static function offsetGet(ObjectEntry $object, Variable $offset): Variable
    {
        $index = self::coerceIndex($offset, 'offsetGet', false);
        $state = self::state($object);
        if ($index < 0 || $index >= \count($state)) {
            throw new \OutOfRangeException('SplDoublyLinkedList::offsetGet(): Argument #1 ($index) is out of range');
        }
        $result = new Variable();
        $result->copyFrom($state[$index]);

        return $result;
    }

    public static function offsetSet(ObjectEntry $object, Variable $offset, Variable $value): void
    {
        // php-src spl_dllist_object_write_dimension / zim_SplDoublyLinkedList_offsetSet —
        // null index (incl. $list[]=) appends via push; no E_DEPRECATED (#31731).
        $resolved = $offset->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            self::push($object, $value);

            return;
        }
        $index = self::coerceIndex($offset, 'offsetSet', true);
        $count = \count(self::state($object));
        if ($index < 0 || $index >= $count) {
            throw new \OutOfRangeException('SplDoublyLinkedList::offsetSet(): Argument #1 ($index) is out of range');
        }
        $copy = new Variable();
        $copy->copyFrom($value->resolveIndirect());
        self::$store[$object->id][$index] = $copy;
    }

    public static function offsetUnset(ObjectEntry $object, Variable $offset): void
    {
        $index = self::coerceIndex($offset, 'offsetUnset', false);
        $count = \count(self::state($object));
        if ($index < 0 || $index >= $count) {
            throw new \OutOfRangeException('SplDoublyLinkedList::offsetUnset(): Argument #1 ($index) is out of range');
        }
        \array_splice(self::$store[$object->id], $index, 1);
    }

    private static function coerceIndex(Variable $offset, string $method, bool $nullable): int
    {
        $resolved = $offset->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            if (!$nullable) {
                throw new \TypeError(
                    'SplDoublyLinkedList::'.$method.'(): Argument #1 ($index) must be of type int, null given'
                );
            }

            return 0;
        }
        if (Variable::TYPE_INTEGER !== $resolved->type) {
            $typeName = match ($resolved->type) {
                Variable::TYPE_BOOLEAN => 'bool',
                Variable::TYPE_FLOAT => 'float',
                Variable::TYPE_STRING => 'string',
                Variable::TYPE_ARRAY => 'array',
                Variable::TYPE_OBJECT => 'object',
                default => 'mixed',
            };
            $expected = $nullable ? '?int' : 'int';
            throw new \TypeError(
                'SplDoublyLinkedList::'.$method.'(): Argument #1 ($index) must be of type '.$expected.', '.$typeName.' given'
            );
        }

        return $resolved->toInt();
    }
}

/**
 * SplDoublyLinkedList::__debugInfo() — private flags + dllist
 * (php-src spl_dllist_object_get_debug_info; #19824). Inherited by SplQueue/SplStack.
 */
final class SplDoublyLinkedListDebugInfo extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__debugInfo');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::__debugInfo()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(SplDoublyLinkedListBuiltin::debugInfoTable($object));
    }
}

final class SplDoublyLinkedListConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::__construct()'
        );
        SplDoublyLinkedListBuiltin::init($object);
    }
}

final class SplDoublyLinkedListPush extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('push');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::push()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_ARGS(1, 1) (#30964)
        $this->requireExactUserArgCount($frame, 'SplDoublyLinkedList::push', 1);
        SplDoublyLinkedListBuiltin::push($object, $frame->calledArgs[1]);
    }
}

final class SplDoublyLinkedListPop extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('pop');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::pop()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_NONE (#30911); ACE cites defining class
        $this->requireExactUserArgCount($frame, 'SplDoublyLinkedList::pop', 0);
        SplIteratorSupport::copyReturnFrom($frame, SplDoublyLinkedListBuiltin::pop($object));
    }
}

final class SplDoublyLinkedListShift extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('shift');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::shift()'
        );
        SplIteratorSupport::copyReturnFrom($frame, SplDoublyLinkedListBuiltin::shift($object));
    }
}

final class SplDoublyLinkedListUnshift extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('unshift');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::unshift()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_ARGS(1, 1) (#30964)
        $this->requireExactUserArgCount($frame, 'SplDoublyLinkedList::unshift', 1);
        SplDoublyLinkedListBuiltin::unshift($object, $frame->calledArgs[1]);
    }
}

final class SplDoublyLinkedListTop extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('top');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::top()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_NONE (#30911); ACE cites defining class
        $this->requireExactUserArgCount($frame, 'SplDoublyLinkedList::top', 0);
        SplIteratorSupport::copyReturnFrom($frame, SplDoublyLinkedListBuiltin::top($object));
    }
}

final class SplDoublyLinkedListBottom extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('bottom');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::bottom()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_NONE (#30964)
        $this->requireExactUserArgCount($frame, 'SplDoublyLinkedList::bottom', 0);
        SplIteratorSupport::copyReturnFrom($frame, SplDoublyLinkedListBuiltin::bottom($object));
    }
}

final class SplDoublyLinkedListIsEmpty extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isEmpty');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::isEmpty()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_NONE (#30964)
        $this->requireExactUserArgCount($frame, 'SplDoublyLinkedList::isEmpty', 0);
        SplIteratorSupport::setReturnBool($frame, SplDoublyLinkedListBuiltin::isEmpty($object));
    }
}

final class SplDoublyLinkedListCount extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::count()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_NONE (#30911); ACE cites defining class
        $this->requireExactUserArgCount($frame, 'SplDoublyLinkedList::count', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(SplDoublyLinkedListBuiltin::count($object));
    }
}

final class SplDoublyLinkedListOffsetGet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetGet');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::offsetGet()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_ARGS(1, 1) (#30964)
        $this->requireExactUserArgCount($frame, 'SplDoublyLinkedList::offsetGet', 1);
        SplIteratorSupport::copyReturnFrom(
            $frame,
            SplDoublyLinkedListBuiltin::offsetGet($object, $frame->calledArgs[1])
        );
    }
}

final class SplDoublyLinkedListOffsetSet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetSet');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::offsetSet()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_ARGS(2, 2) (#30964)
        $this->requireExactUserArgCount($frame, 'SplDoublyLinkedList::offsetSet', 2);
        SplDoublyLinkedListBuiltin::offsetSet($object, $frame->calledArgs[1], $frame->calledArgs[2]);
    }
}

final class SplDoublyLinkedListOffsetExists extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetExists');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::offsetExists()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_ARGS(1, 1) (#30964)
        $this->requireExactUserArgCount($frame, 'SplDoublyLinkedList::offsetExists', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(
            SplDoublyLinkedListBuiltin::offsetExists($object, $frame->calledArgs[1])
        );
    }
}

final class SplDoublyLinkedListOffsetUnset extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetUnset');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::offsetUnset()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_ARGS(1, 1) (#30964)
        $this->requireExactUserArgCount($frame, 'SplDoublyLinkedList::offsetUnset', 1);
        SplDoublyLinkedListBuiltin::offsetUnset($object, $frame->calledArgs[1]);
    }
}

final class SplDoublyLinkedListRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::rewind()'
        );
        SplDoublyLinkedListBuiltin::rewind($object);
    }
}

final class SplDoublyLinkedListValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::valid()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(SplDoublyLinkedListBuiltin::valid($object));
    }
}

final class SplDoublyLinkedListCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::current()'
        );
        SplIteratorSupport::copyReturnFrom($frame, SplDoublyLinkedListBuiltin::current($object));
    }
}

final class SplDoublyLinkedListKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::key()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(SplDoublyLinkedListBuiltin::key($object));
    }
}

final class SplDoublyLinkedListNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::next()'
        );
        SplDoublyLinkedListBuiltin::next($object);
    }
}

final class SplDoublyLinkedListPrev extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('prev');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::prev()'
        );
        SplDoublyLinkedListBuiltin::prev($object);
    }
}

final class SplDoublyLinkedListAdd extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('add');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::add()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_ARGS(2, 2) (#30964)
        $this->requireExactUserArgCount($frame, 'SplDoublyLinkedList::add', 2);
        SplDoublyLinkedListBuiltin::add($object, $frame->calledArgs[1], $frame->calledArgs[2]);
    }
}

final class SplDoublyLinkedListSetIteratorMode extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setIteratorMode');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::setIteratorMode()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_ARGS(1, 1) (#30964)
        $this->requireExactUserArgCount($frame, 'SplDoublyLinkedList::setIteratorMode', 1);
        $mode = $frame->calledArgs[1]->resolveIndirect()->toInt();
        $newMode = SplDoublyLinkedListBuiltin::setIteratorMode($object, $mode);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int($newMode);
    }
}

final class SplDoublyLinkedListGetIteratorMode extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getIteratorMode');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::getIteratorMode()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_NONE (#30964)
        $this->requireExactUserArgCount($frame, 'SplDoublyLinkedList::getIteratorMode', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(SplDoublyLinkedListBuiltin::getIteratorMode($object));
    }
}
