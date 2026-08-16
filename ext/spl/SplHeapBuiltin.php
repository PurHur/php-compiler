<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\Compiler\ParameterMetadata;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * SplHeap / SplMinHeap / SplMaxHeap — binary heap (php-src ext/spl/spl_heap.c; #4387, #19891).
 *
 * SplHeap has no PHP constructor in php-src (object handlers init storage). A throwing
 * __construct here was inherited by user subclasses and fatally blocked `new H()`.
 */
final class SplHeapBuiltin
{
    public const CLASS_LC = 'splheap';

    public const KIND_MAX = 1;

    public const KIND_MIN = -1;

    /** User concrete subclass — ordering via overridden compare() (#19891). */
    public const KIND_USER = 0;

    /** @var array<int, array{elements: list<Variable>, kind: int, flags: int, corrupted: bool, iterPos: int}> */
    private static array $store = [];

    public static function registerClasses(Context $ctx): void
    {
        self::registerHeap($ctx);
        SplMinHeapBuiltin::registerClass($ctx);
        SplMaxHeapBuiltin::registerClass($ctx);
        SplPriorityQueueBuiltin::registerClass($ctx);
    }

    public static function registerHeap(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $prot = CfgFunc::FLAG_PROTECTED;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('SplHeap');
        foreach (['iterator', 'traversable', 'countable'] as $iface) {
            if (isset($ctx->classes[$iface]) && !\in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }
        $entry->isAbstract = true;
        $entry->abstractMethods['compare'] = true;
        $entry->methodNames['compare'] = 'compare';
        // No __construct — php-src SplHeap has none; create_object inits the heap (#19891).
        $entry->constructor = null;
        unset($entry->methods['__construct'], $entry->methodVisibility['__construct']);
        $entry->methods['compare'] = new SplHeapCompareAbstract();
        $entry->methodVisibility['compare'] = $prot;
        // php-src ext/spl/spl_heap.stub.php — compare(mixed $value1, mixed $value2) (#25555)
        $entry->methodParameterMetadata['compare'] = self::compareValueParamMetadata();
        foreach ([
            'insert' => SplHeapInsert::class,
            'extract' => SplHeapExtract::class,
            'top' => SplHeapTop::class,
            'count' => SplHeapCount::class,
            'isempty' => SplHeapIsEmpty::class,
            'iscorrupted' => SplHeapIsCorrupted::class,
            'rewind' => SplHeapRewind::class,
            'valid' => SplHeapValid::class,
            'current' => SplHeapCurrent::class,
            'key' => SplHeapKey::class,
            'next' => SplHeapNext::class,
            'recoverfromcorruption' => SplHeapRecoverFromCorruption::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['isempty'] = 'isEmpty';
        $entry->methodNames['iscorrupted'] = 'isCorrupted';
        $entry->methodNames['recoverfromcorruption'] = 'recoverFromCorruption';
        $entry->methods['__debuginfo'] = new SplHeapDebugInfo();
        $entry->methodVisibility['__debuginfo'] = $pub;
        $entry->methodNames['__debuginfo'] = '__debugInfo';
        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset(
            $entry->methods['insert'],
            $entry->methods['extract'],
            $entry->methods['rewind'],
            $entry->methods['iscorrupted'],
            $entry->methods['__debuginfo']
        );
    }

    /**
     * Zend stub params for SplHeap / SplMinHeap / SplMaxHeap::compare (#25555).
     *
     * @return list<ParameterMetadata>
     */
    public static function compareValueParamMetadata(): array
    {
        return [
            new ParameterMetadata('value1', [], false, false, false, false, 'mixed', null),
            new ParameterMetadata('value2', [], false, false, false, false, 'mixed', null),
        ];
    }

    /**
     * Zend stub params for SplPriorityQueue::compare (#25555).
     *
     * @return list<ParameterMetadata>
     */
    public static function comparePriorityParamMetadata(): array
    {
        return [
            new ParameterMetadata('priority1', [], false, false, false, false, 'mixed', null),
            new ParameterMetadata('priority2', [], false, false, false, false, 'mixed', null),
        ];
    }

    public static function init(ObjectEntry $object, int $kind): void
    {
        self::$store[$object->id] = [
            'elements' => [],
            'kind' => $kind,
            'flags' => 0,
            'corrupted' => false,
            'iterPos' => -1,
        ];
    }

    /** Lazy create_object equivalent for user SplHeap subclasses (#19891). */
    public static function ensureInit(ObjectEntry $object, int $kind = self::KIND_USER): void
    {
        if (!isset(self::$store[$object->id])) {
            self::init($object, $kind);
        }
    }

    public static function kind(ObjectEntry $object): int
    {
        return self::state($object)['kind'];
    }

    public static function insert(ObjectEntry $object, Variable $value, Frame $frame): void
    {
        self::ensureInit($object);
        self::assertNotCorrupted($object);
        $state = &self::$store[$object->id];
        $copy = new Variable();
        $copy->copyFrom($value->resolveIndirect());
        $state['elements'][] = $copy;
        self::siftUp($object, \count($state['elements']) - 1, $frame);
    }

    public static function extract(ObjectEntry $object, Frame $frame): Variable
    {
        self::ensureInit($object);
        self::assertNotCorrupted($object);

        return self::extractUnchecked($object, $frame);
    }

    /**
     * Delete top without corruption gate — SplHeap::next() on php-src 8.2 does not check
     * SPL_HEAP_CORRUPTED (unlike extract/insert/top); iterator move_forward does.
     */
    public static function extractUnchecked(ObjectEntry $object, Frame $frame): Variable
    {
        self::ensureInit($object);
        $state = &self::$store[$object->id];
        $n = \count($state['elements']);
        if (0 === $n) {
            throw new \RuntimeException("Can't extract from an empty heap");
        }
        $top = $state['elements'][0];
        $last = array_pop($state['elements']);
        if ($n > 1 && null !== $last) {
            $state['elements'][0] = $last;
            self::siftDown($object, 0, $frame);
        }
        $result = new Variable();
        $result->copyFrom($top);

        return $result;
    }

    public static function top(ObjectEntry $object): Variable
    {
        self::ensureInit($object);
        self::assertNotCorrupted($object);
        $state = self::state($object);
        if ([] === $state['elements']) {
            throw new \RuntimeException("Can't peek at an empty heap");
        }
        $result = new Variable();
        $result->copyFrom($state['elements'][0]);

        return $result;
    }

    public static function count(ObjectEntry $object): int
    {
        self::ensureInit($object);

        return \count(self::state($object)['elements']);
    }

    public static function isEmpty(ObjectEntry $object): bool
    {
        return 0 === self::count($object);
    }

    public static function rewind(ObjectEntry $object): void
    {
        self::ensureInit($object);
        // php-src spl_heap_it_rewind is a no-op; valid/key derive from count (#31600 / #22290).
    }

    public static function valid(ObjectEntry $object): bool
    {
        self::ensureInit($object);

        // php-src spl_heap_it_valid: heap->count != 0 (#31600).
        return self::count($object) > 0;
    }

    /**
     * SplHeap::current() peeks without consistency checks (php-src; unlike top()).
     */
    public static function current(ObjectEntry $object): Variable
    {
        self::ensureInit($object);
        $state = self::state($object);
        if ([] === $state['elements']) {
            $null = new Variable();
            $null->null();

            return $null;
        }
        $result = new Variable();
        $result->copyFrom($state['elements'][0]);

        return $result;
    }

    public static function key(ObjectEntry $object): int
    {
        self::ensureInit($object);
        // php-src spl_heap_it_get_current_key: count - 1 (#22290 / #31600).
        $n = self::count($object);

        return $n > 0 ? $n - 1 : -1;
    }

    public static function next(ObjectEntry $object, Frame $frame): void
    {
        if (0 === self::count($object)) {
            return;
        }
        // php-src: iterating SplHeap extracts elements (heap empties under foreach).
        // Method next() skips corruption gate on 8.2 (spl_heap.c PHP_METHOD SplHeap::next).
        self::extractUnchecked($object, $frame);
    }

    public static function isCorrupted(ObjectEntry $object): bool
    {
        self::ensureInit($object);

        return self::state($object)['corrupted'];
    }

    public static function recoverFromCorruption(ObjectEntry $object): bool
    {
        self::ensureInit($object);
        self::$store[$object->id]['corrupted'] = false;

        return true;
    }

    /**
     * Private flags / isCorrupted / heap for var_dump (php-src spl_heap_object_get_debug_info; #19825).
     */
    public static function debugInfoTable(ObjectEntry $object): HashTable
    {
        self::ensureInit($object);
        $state = self::state($object);
        $ht = new HashTable();

        $flags = new Variable();
        $flags->int($state['flags']);
        $ht->addNew("\0SplHeap\0flags", $flags);

        $corrupted = new Variable();
        $corrupted->bool($state['corrupted']);
        $ht->addNew("\0SplHeap\0isCorrupted", $corrupted);

        $values = [];
        foreach ($state['elements'] as $var) {
            $copy = new Variable();
            $copy->copyFrom($var->resolveIndirect());
            $values[] = $copy;
        }
        $heap = new Variable();
        $heapHt = new HashTable();
        if ([] !== $values) {
            $heapHt->assignPackedList($values);
        }
        $heap->array($heapHt);
        $ht->addNew("\0SplHeap\0heap", $heap);

        return $ht;
    }

    /**
     * Heap ordering via compare() (php-src spl_ptr_heap_cmp; #19891, #21977).
     * Bare SplMinHeap/SplMaxHeap use KIND_MIN/KIND_MAX fast path; user subclasses
     * of those (and SplHeap) are KIND_USER and dispatch to the instance method so
     * overridden compare() is honored.
     *
     * User compare() throw sets SPL_HEAP_CORRUPTED (php-src spl_ptr_heap_insert /
     * delete_top; #24312) — element stays in the heap; further insert/extract/top fail.
     */
    public static function compareElements(ObjectEntry $object, Variable $a, Variable $b, Frame $frame): int
    {
        $kind = self::kind($object);
        if (self::KIND_USER !== $kind) {
            $cmp = Variable::spaceshipCompare($a, $b);

            return $kind < 0 ? -$cmp : $cmp;
        }

        try {
            $result = self::vm($frame)->invokeInstanceMethod($object, 'compare', $a, $b)->resolveIndirect();
        } catch (\Throwable $e) {
            self::markCorrupted($object);
            throw $e;
        }

        return $result->toInt();
    }

    /** php-src spl_heap_consistency_validations — corrupted heap blocks write/peek ops. */
    public static function assertNotCorrupted(ObjectEntry $object): void
    {
        if (self::isCorrupted($object)) {
            throw new \RuntimeException('Heap is corrupted, heap properties are no longer ensured.');
        }
    }

    public static function markCorrupted(ObjectEntry $object): void
    {
        self::ensureInit($object);
        self::$store[$object->id]['corrupted'] = true;
    }

    /** True when $object is exactly SplMinHeap / SplMaxHeap (not a user subclass). */
    public static function isExactHeapClass(ObjectEntry $object, string $classLc): bool
    {
        return strtolower(ltrim($object->class->name, '\\')) === $classLc;
    }

    private static function siftUp(ObjectEntry $object, int $index, Frame $frame): void
    {
        $state = &self::$store[$object->id];
        while ($index > 0) {
            $parent = intdiv($index - 1, 2);
            if (self::compareElements($object, $state['elements'][$index], $state['elements'][$parent], $frame) <= 0) {
                break;
            }
            $tmp = $state['elements'][$index];
            $state['elements'][$index] = $state['elements'][$parent];
            $state['elements'][$parent] = $tmp;
            $index = $parent;
        }
    }

    private static function siftDown(ObjectEntry $object, int $index, Frame $frame): void
    {
        $state = &self::$store[$object->id];
        $n = \count($state['elements']);
        while (true) {
            $largest = $index;
            $left = 2 * $index + 1;
            $right = 2 * $index + 2;
            if ($left < $n
                && self::compareElements($object, $state['elements'][$left], $state['elements'][$largest], $frame) > 0) {
                $largest = $left;
            }
            if ($right < $n
                && self::compareElements($object, $state['elements'][$right], $state['elements'][$largest], $frame) > 0) {
                $largest = $right;
            }
            if ($largest === $index) {
                break;
            }
            $tmp = $state['elements'][$index];
            $state['elements'][$index] = $state['elements'][$largest];
            $state['elements'][$largest] = $tmp;
            $index = $largest;
        }
    }

    /** @return array{elements: list<Variable>, kind: int, flags: int, corrupted: bool, iterPos: int} */
    private static function state(ObjectEntry $object): array
    {
        if (!isset(self::$store[$object->id])) {
            throw new \LogicException('SplHeap object state missing');
        }

        return self::$store[$object->id];
    }

    private static function vm(Frame $frame): \PHPCompiler\VM
    {
        if (null === $frame->vmContext || null === $frame->vmContext->runtime) {
            throw new \LogicException('SplHeap requires VM runtime');
        }

        return $frame->vmContext->runtime->vm;
    }
}

final class SplMinHeapBuiltin
{
    public const CLASS_LC = 'splminheap';

    public static function registerClass(Context $ctx): void
    {
        SplHeapBuiltin::registerHeap($ctx);
        if (isset($ctx->classes[self::CLASS_LC]) && isset($ctx->classes[self::CLASS_LC]->methods['compare'])
            && !$ctx->classes[self::CLASS_LC]->isAbstract) {
            return;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $prot = CfgFunc::FLAG_PROTECTED;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('SplMinHeap');
        $entry->parentLc = SplHeapBuiltin::CLASS_LC;
        // Zend rematerializes Countable-first flattened ce->interfaces on the subclass
        // (not SplHeap Iterator-first order). Observable via class_implements() (#25822).
        $entry->interfaces = [];
        foreach (['countable', 'traversable', 'iterator'] as $iface) {
            if (isset($ctx->classes[$iface])) {
                $entry->interfaces[] = $iface;
            }
        }
        $entry->isAbstract = false;
        unset($entry->abstractMethods['compare']);
        $entry->constructor = new SplMinHeapConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['compare'] = new SplMinHeapCompare();
        $entry->methodVisibility['compare'] = $prot;
        $entry->methodNames['compare'] = 'compare';
        // php-src ext/spl/spl_heap.stub.php — not InternalArgInfo a/b (#25555)
        $entry->methodParameterMetadata['compare'] = SplHeapBuiltin::compareValueParamMetadata();
        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }
}

final class SplMaxHeapBuiltin
{
    public const CLASS_LC = 'splmaxheap';

    public static function registerClass(Context $ctx): void
    {
        SplHeapBuiltin::registerHeap($ctx);
        if (isset($ctx->classes[self::CLASS_LC]) && isset($ctx->classes[self::CLASS_LC]->methods['compare'])
            && !$ctx->classes[self::CLASS_LC]->isAbstract) {
            return;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $prot = CfgFunc::FLAG_PROTECTED;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('SplMaxHeap');
        $entry->parentLc = SplHeapBuiltin::CLASS_LC;
        // Zend rematerializes Countable-first flattened ce->interfaces on the subclass (#25822).
        $entry->interfaces = [];
        foreach (['countable', 'traversable', 'iterator'] as $iface) {
            if (isset($ctx->classes[$iface])) {
                $entry->interfaces[] = $iface;
            }
        }
        $entry->isAbstract = false;
        unset($entry->abstractMethods['compare']);
        $entry->constructor = new SplMaxHeapConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['compare'] = new SplMaxHeapCompare();
        $entry->methodVisibility['compare'] = $prot;
        $entry->methodNames['compare'] = 'compare';
        // php-src ext/spl/spl_heap.stub.php — not InternalArgInfo a/b (#25555)
        $entry->methodParameterMetadata['compare'] = SplHeapBuiltin::compareValueParamMetadata();
        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }
}

/**
 * SplPriorityQueue — priority heap with extract flags (php-src ext/spl/spl_heap.c; #4387).
 */
final class SplPriorityQueueBuiltin
{
    public const CLASS_LC = 'splpriorityqueue';

    public const EXTR_DATA = 1;

    public const EXTR_PRIORITY = 2;

    public const EXTR_BOTH = 3;

    /** @var array<int, array{elements: list<array{data: Variable, priority: Variable}>, flags: int, corrupted: bool, iterPos: int}> */
    private static array $store = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('SplPriorityQueue');
        foreach (['iterator', 'traversable', 'countable'] as $iface) {
            if (isset($ctx->classes[$iface]) && !\in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }
        $entry->constructor = new SplPriorityQueueConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        foreach ([
            'insert' => SplPriorityQueueInsert::class,
            'extract' => SplPriorityQueueExtract::class,
            'top' => SplPriorityQueueTop::class,
            'count' => SplPriorityQueueCount::class,
            'isempty' => SplPriorityQueueIsEmpty::class,
            'iscorrupted' => SplPriorityQueueIsCorrupted::class,
            'setextractflags' => SplPriorityQueueSetExtractFlags::class,
            'getextractflags' => SplPriorityQueueGetExtractFlags::class,
            'rewind' => SplPriorityQueueRewind::class,
            'valid' => SplPriorityQueueValid::class,
            'current' => SplPriorityQueueCurrent::class,
            'key' => SplPriorityQueueKey::class,
            'next' => SplPriorityQueueNext::class,
            'recoverfromcorruption' => SplPriorityQueueRecoverFromCorruption::class,
            'compare' => SplPriorityQueueCompare::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodVisibility['compare'] = $pub;
        // php-src ext/spl/spl_heap.stub.php — public compare(mixed $priority1, $priority2) (#25555)
        $entry->methodParameterMetadata['compare'] = SplHeapBuiltin::comparePriorityParamMetadata();
        $entry->methodNames['isempty'] = 'isEmpty';
        $entry->methodNames['iscorrupted'] = 'isCorrupted';
        $entry->methodNames['setextractflags'] = 'setExtractFlags';
        $entry->methodNames['getextractflags'] = 'getExtractFlags';
        $entry->methodNames['recoverfromcorruption'] = 'recoverFromCorruption';
        $entry->methods['__debuginfo'] = new SplPriorityQueueDebugInfo();
        $entry->methodVisibility['__debuginfo'] = $pub;
        $entry->methodNames['__debuginfo'] = '__debugInfo';
        SplClassConstants::registerIntConstants($entry, [
            'EXTR_DATA' => self::EXTR_DATA,
            'EXTR_PRIORITY' => self::EXTR_PRIORITY,
            'EXTR_BOTH' => self::EXTR_BOTH,
        ]);
        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset(
            $entry->methods['insert'],
            $entry->methods['extract'],
            $entry->methods['setextractflags'],
            $entry->methods['iscorrupted'],
            $entry->methods['__debuginfo']
        );
    }

    public static function init(ObjectEntry $object): void
    {
        self::$store[$object->id] = [
            'elements' => [],
            'flags' => self::EXTR_DATA,
            'corrupted' => false,
            'iterPos' => -1,
        ];
    }

    public static function setExtractFlags(ObjectEntry $object, int $flags): int
    {
        self::$store[$object->id]['flags'] = $flags & self::EXTR_BOTH;

        return self::$store[$object->id]['flags'];
    }

    public static function getExtractFlags(ObjectEntry $object): int
    {
        return self::state($object)['flags'];
    }

    public static function insert(ObjectEntry $object, Variable $data, Variable $priority, Frame $frame): void
    {
        self::assertNotCorrupted($object);
        $state = &self::$store[$object->id];
        $dataCopy = new Variable();
        $dataCopy->copyFrom($data->resolveIndirect());
        $prioCopy = new Variable();
        $prioCopy->copyFrom($priority->resolveIndirect());
        $state['elements'][] = ['data' => $dataCopy, 'priority' => $prioCopy];
        self::siftUp($object, \count($state['elements']) - 1, $frame);
    }

    public static function extract(ObjectEntry $object, Frame $frame): Variable
    {
        self::assertNotCorrupted($object);

        return self::extractUnchecked($object, $frame);
    }

    /** @see SplHeapBuiltin::extractUnchecked — next() skips corruption gate on 8.2. */
    public static function extractUnchecked(ObjectEntry $object, Frame $frame): Variable
    {
        $state = &self::$store[$object->id];
        $n = \count($state['elements']);
        if (0 === $n) {
            throw new \RuntimeException("Can't extract from an empty heap");
        }
        $top = $state['elements'][0];
        $last = array_pop($state['elements']);
        if ($n > 1 && null !== $last) {
            $state['elements'][0] = $last;
            self::siftDown($object, 0, $frame);
        }

        return self::formatElement($object, $top);
    }

    public static function top(ObjectEntry $object): Variable
    {
        self::assertNotCorrupted($object);
        $state = self::state($object);
        if ([] === $state['elements']) {
            throw new \RuntimeException("Can't peek at an empty heap");
        }

        return self::formatElement($object, $state['elements'][0]);
    }

    public static function count(ObjectEntry $object): int
    {
        return \count(self::state($object)['elements']);
    }

    public static function isEmpty(ObjectEntry $object): bool
    {
        return 0 === self::count($object);
    }

    public static function rewind(ObjectEntry $object): void
    {
        // php-src spl_heap_it_rewind is a no-op; valid/key derive from count (#31601 / #22290).
        unset($object);
    }

    public static function valid(ObjectEntry $object): bool
    {
        // php-src spl_heap_it_valid (shared with pqueue): heap->count != 0 (#31601).
        return self::count($object) > 0;
    }

    /** SplPriorityQueue::current() — no corruption gate (php-src). */
    public static function current(ObjectEntry $object): Variable
    {
        $state = self::state($object);
        if ([] === $state['elements']) {
            $null = new Variable();
            $null->null();

            return $null;
        }

        return self::formatElement($object, $state['elements'][0]);
    }

    public static function key(ObjectEntry $object): int
    {
        // php-src spl_heap_it_get_current_key: count - 1 (#22290 / #31601).
        $n = self::count($object);

        return $n > 0 ? $n - 1 : -1;
    }

    public static function next(ObjectEntry $object, Frame $frame): void
    {
        if (0 === self::count($object)) {
            return;
        }
        self::extractUnchecked($object, $frame);
    }

    public static function isCorrupted(ObjectEntry $object): bool
    {
        return self::state($object)['corrupted'];
    }

    public static function assertNotCorrupted(ObjectEntry $object): void
    {
        if (self::isCorrupted($object)) {
            throw new \RuntimeException('Heap is corrupted, heap properties are no longer ensured.');
        }
    }

    public static function markCorrupted(ObjectEntry $object): void
    {
        self::$store[$object->id]['corrupted'] = true;
    }

    public static function recoverFromCorruption(ObjectEntry $object): bool
    {
        self::$store[$object->id]['corrupted'] = false;

        return true;
    }

    /**
     * Private flags / isCorrupted / heap for PriorityQueue var_dump (#19825).
     */
    public static function debugInfoTable(ObjectEntry $object): HashTable
    {
        $state = self::state($object);
        $ht = new HashTable();

        $flags = new Variable();
        $flags->int($state['flags']);
        $ht->addNew("\0SplPriorityQueue\0flags", $flags);

        $corrupted = new Variable();
        $corrupted->bool($state['corrupted']);
        $ht->addNew("\0SplPriorityQueue\0isCorrupted", $corrupted);

        $rows = [];
        foreach ($state['elements'] as $element) {
            $row = new HashTable();
            $data = new Variable();
            $data->copyFrom($element['data']->resolveIndirect());
            $row->addNew('data', $data);
            $prio = new Variable();
            $prio->copyFrom($element['priority']->resolveIndirect());
            $row->addNew('priority', $prio);
            $rowVar = new Variable();
            $rowVar->array($row);
            $rows[] = $rowVar;
        }
        $heap = new Variable();
        $heapHt = new HashTable();
        if ([] !== $rows) {
            $heapHt->assignPackedList($rows);
        }
        $heap->array($heapHt);
        $ht->addNew("\0SplPriorityQueue\0heap", $heap);

        return $ht;
    }

    /** @param array{data: Variable, priority: Variable} $element */
    private static function formatElement(ObjectEntry $object, array $element): Variable
    {
        $flags = self::getExtractFlags($object);
        if (self::EXTR_PRIORITY === $flags) {
            $out = new Variable();
            $out->copyFrom($element['priority']);

            return $out;
        }
        if (self::EXTR_BOTH === $flags) {
            $ht = new HashTable();
            $data = new Variable();
            $data->copyFrom($element['data']);
            $prio = new Variable();
            $prio->copyFrom($element['priority']);
            $ht->add('data', $data);
            $ht->add('priority', $prio);
            $out = new Variable();
            $out->array($ht);

            return $out;
        }
        $out = new Variable();
        $out->copyFrom($element['data']);

        return $out;
    }

    /**
     * php-src SplPriorityQueue::compare / spl_ptr_pqueue_elem_cmp (#24328).
     * Exact SplPriorityQueue uses spaceship; subclasses dispatch to overridden compare().
     * User compare() throw marks corrupted (#24312).
     */
    private static function comparePriority(ObjectEntry $object, Variable $a, Variable $b, Frame $frame): int
    {
        if (SplHeapBuiltin::isExactHeapClass($object, self::CLASS_LC)) {
            return Variable::spaceshipCompare($a, $b);
        }
        if (null === $frame->vmContext || null === $frame->vmContext->runtime) {
            throw new \LogicException('SplPriorityQueue::compare() requires VM runtime');
        }
        try {
            $result = $frame->vmContext->runtime->vm
                ->invokeInstanceMethod($object, 'compare', $a, $b)
                ->resolveIndirect();
        } catch (\Throwable $e) {
            self::markCorrupted($object);
            throw $e;
        }

        return $result->toInt();
    }

    private static function siftUp(ObjectEntry $object, int $index, Frame $frame): void
    {
        $state = &self::$store[$object->id];
        while ($index > 0) {
            $parent = intdiv($index - 1, 2);
            if (self::comparePriority(
                $object,
                $state['elements'][$index]['priority'],
                $state['elements'][$parent]['priority'],
                $frame
            ) <= 0) {
                break;
            }
            $tmp = $state['elements'][$index];
            $state['elements'][$index] = $state['elements'][$parent];
            $state['elements'][$parent] = $tmp;
            $index = $parent;
        }
    }

    private static function siftDown(ObjectEntry $object, int $index, Frame $frame): void
    {
        $state = &self::$store[$object->id];
        $n = \count($state['elements']);
        while (true) {
            $largest = $index;
            $left = 2 * $index + 1;
            $right = 2 * $index + 2;
            if ($left < $n
                && self::comparePriority(
                    $object,
                    $state['elements'][$left]['priority'],
                    $state['elements'][$largest]['priority'],
                    $frame
                ) > 0) {
                $largest = $left;
            }
            if ($right < $n
                && self::comparePriority(
                    $object,
                    $state['elements'][$right]['priority'],
                    $state['elements'][$largest]['priority'],
                    $frame
                ) > 0) {
                $largest = $right;
            }
            if ($largest === $index) {
                break;
            }
            $tmp = $state['elements'][$index];
            $state['elements'][$index] = $state['elements'][$largest];
            $state['elements'][$largest] = $tmp;
            $index = $largest;
        }
    }

    /** @return array{elements: list<array{data: Variable, priority: Variable}>, flags: int, corrupted: bool, iterPos: int} */
    private static function state(ObjectEntry $object): array
    {
        if (!isset(self::$store[$object->id])) {
            throw new \LogicException('SplPriorityQueue object state missing');
        }

        return self::$store[$object->id];
    }
}

/**
 * SplHeap::__debugInfo() — private flags/isCorrupted/heap (#19825).
 * Inherited by SplMinHeap / SplMaxHeap.
 */
final class SplHeapDebugInfo extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__debugInfo');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplHeapBuiltin::CLASS_LC,
            'SplHeap::__debugInfo()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(SplHeapBuiltin::debugInfoTable($object));
    }
}

/**
 * SplPriorityQueue::__debugInfo() — private flags/isCorrupted/heap (#19825).
 */
final class SplPriorityQueueDebugInfo extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__debugInfo');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplPriorityQueueBuiltin::CLASS_LC,
            'SplPriorityQueue::__debugInfo()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(SplPriorityQueueBuiltin::debugInfoTable($object));
    }
}

final class SplHeapCompareAbstract extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('compare');
    }

    public function execute(Frame $frame): void
    {
        throw new \Error('Cannot call abstract method SplHeap::compare()');
    }
}

final class SplMinHeapConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA($frame, SplMinHeapBuiltin::CLASS_LC, 'SplMinHeap::__construct()');
        // User subclasses must KIND_USER so overridden compare() runs (#21977).
        $kind = SplHeapBuiltin::isExactHeapClass($object, SplMinHeapBuiltin::CLASS_LC)
            ? SplHeapBuiltin::KIND_MIN
            : SplHeapBuiltin::KIND_USER;
        SplHeapBuiltin::init($object, $kind);
    }
}

final class SplMaxHeapConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA($frame, SplMaxHeapBuiltin::CLASS_LC, 'SplMaxHeap::__construct()');
        $kind = SplHeapBuiltin::isExactHeapClass($object, SplMaxHeapBuiltin::CLASS_LC)
            ? SplHeapBuiltin::KIND_MAX
            : SplHeapBuiltin::KIND_USER;
        SplHeapBuiltin::init($object, $kind);
    }
}

final class SplMinHeapCompare extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('compare');
    }

    public function execute(Frame $frame): void
    {
        SplIteratorSupport::receiverIsA($frame, SplMinHeapBuiltin::CLASS_LC, 'SplMinHeap::compare()');
        $this->requireExactUserArgCount($frame, 'SplMinHeap::compare', 2);
        $cmp = -Variable::spaceshipCompare($frame->calledArgs[1], $frame->calledArgs[2]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($cmp);
        }
    }
}

final class SplMaxHeapCompare extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('compare');
    }

    public function execute(Frame $frame): void
    {
        SplIteratorSupport::receiverIsA($frame, SplMaxHeapBuiltin::CLASS_LC, 'SplMaxHeap::compare()');
        $this->requireExactUserArgCount($frame, 'SplMaxHeap::compare', 2);
        $cmp = Variable::spaceshipCompare($frame->calledArgs[1], $frame->calledArgs[2]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($cmp);
        }
    }
}

final class SplHeapInsert extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('insert');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA($frame, SplHeapBuiltin::CLASS_LC, 'SplHeap::insert()');
        $this->requireExactUserArgCount($frame, 'SplHeap::insert', 1);
        SplHeapBuiltin::insert($object, $frame->calledArgs[1], $frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

final class SplHeapExtract extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('extract');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA($frame, SplHeapBuiltin::CLASS_LC, 'SplHeap::extract()');
        $this->requireExactUserArgCount($frame, 'SplHeap::extract', 0);
        SplIteratorSupport::copyReturnFrom($frame, SplHeapBuiltin::extract($object, $frame));
    }
}

final class SplHeapTop extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('top');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA($frame, SplHeapBuiltin::CLASS_LC, 'SplHeap::top()');
        $this->requireExactUserArgCount($frame, 'SplHeap::top', 0);
        SplIteratorSupport::copyReturnFrom($frame, SplHeapBuiltin::top($object));
    }
}

final class SplHeapCount extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA($frame, SplHeapBuiltin::CLASS_LC, 'SplHeap::count()');
        $this->requireExactUserArgCount($frame, 'SplHeap::count', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(SplHeapBuiltin::count($object));
    }
}

final class SplHeapIsEmpty extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isEmpty');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA($frame, SplHeapBuiltin::CLASS_LC, 'SplHeap::isEmpty()');
        $this->requireExactUserArgCount($frame, 'SplHeap::isEmpty', 0);
        SplIteratorSupport::setReturnBool($frame, SplHeapBuiltin::isEmpty($object));
    }
}

/** SplHeap::isCorrupted() — SPL_HEAP_CORRUPTED flag (php-src spl_heap.c; #22264). */
final class SplHeapIsCorrupted extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isCorrupted');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA($frame, SplHeapBuiltin::CLASS_LC, 'SplHeap::isCorrupted()');
        $this->requireExactUserArgCount($frame, 'SplHeap::isCorrupted', 0);
        SplIteratorSupport::setReturnBool($frame, SplHeapBuiltin::isCorrupted($object));
    }
}

final class SplHeapRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA($frame, SplHeapBuiltin::CLASS_LC, 'SplHeap::rewind()');
        $this->requireExactUserArgCount($frame, 'SplHeap::rewind', 0);
        SplHeapBuiltin::rewind($object);
    }
}

final class SplHeapValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA($frame, SplHeapBuiltin::CLASS_LC, 'SplHeap::valid()');
        $this->requireExactUserArgCount($frame, 'SplHeap::valid', 0);
        SplIteratorSupport::setReturnBool($frame, SplHeapBuiltin::valid($object));
    }
}

final class SplHeapCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA($frame, SplHeapBuiltin::CLASS_LC, 'SplHeap::current()');
        $this->requireExactUserArgCount($frame, 'SplHeap::current', 0);
        SplIteratorSupport::copyReturnFrom($frame, SplHeapBuiltin::current($object));
    }
}

final class SplHeapKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA($frame, SplHeapBuiltin::CLASS_LC, 'SplHeap::key()');
        $this->requireExactUserArgCount($frame, 'SplHeap::key', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(SplHeapBuiltin::key($object));
    }
}

final class SplHeapNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA($frame, SplHeapBuiltin::CLASS_LC, 'SplHeap::next()');
        $this->requireExactUserArgCount($frame, 'SplHeap::next', 0);
        SplHeapBuiltin::next($object, $frame);
    }
}

final class SplHeapRecoverFromCorruption extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('recoverFromCorruption');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA($frame, SplHeapBuiltin::CLASS_LC, 'SplHeap::recoverFromCorruption()');
        $this->requireExactUserArgCount($frame, 'SplHeap::recoverFromCorruption', 0);
        SplIteratorSupport::setReturnBool($frame, SplHeapBuiltin::recoverFromCorruption($object));
    }
}

final class SplPriorityQueueConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplPriorityQueueBuiltin::CLASS_LC,
            'SplPriorityQueue::__construct()'
        );
        SplPriorityQueueBuiltin::init($object);
    }
}

final class SplPriorityQueueInsert extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('insert');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplPriorityQueueBuiltin::CLASS_LC,
            'SplPriorityQueue::insert()'
        );
        $this->requireExactUserArgCount($frame, 'SplPriorityQueue::insert', 2);
        SplPriorityQueueBuiltin::insert($object, $frame->calledArgs[1], $frame->calledArgs[2], $frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

final class SplPriorityQueueExtract extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('extract');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplPriorityQueueBuiltin::CLASS_LC,
            'SplPriorityQueue::extract()'
        );
        $this->requireExactUserArgCount($frame, 'SplPriorityQueue::extract', 0);
        SplIteratorSupport::copyReturnFrom($frame, SplPriorityQueueBuiltin::extract($object, $frame));
    }
}

final class SplPriorityQueueTop extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('top');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplPriorityQueueBuiltin::CLASS_LC,
            'SplPriorityQueue::top()'
        );
        $this->requireExactUserArgCount($frame, 'SplPriorityQueue::top', 0);
        SplIteratorSupport::copyReturnFrom($frame, SplPriorityQueueBuiltin::top($object));
    }
}

final class SplPriorityQueueCount extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplPriorityQueueBuiltin::CLASS_LC,
            'SplPriorityQueue::count()'
        );
        $this->requireExactUserArgCount($frame, 'SplPriorityQueue::count', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(SplPriorityQueueBuiltin::count($object));
    }
}

final class SplPriorityQueueIsEmpty extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isEmpty');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplPriorityQueueBuiltin::CLASS_LC,
            'SplPriorityQueue::isEmpty()'
        );
        $this->requireExactUserArgCount($frame, 'SplPriorityQueue::isEmpty', 0);
        SplIteratorSupport::setReturnBool($frame, SplPriorityQueueBuiltin::isEmpty($object));
    }
}

/** SplPriorityQueue::isCorrupted() — SPL_HEAP_CORRUPTED flag (php-src spl_heap.c; #22264). */
final class SplPriorityQueueIsCorrupted extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isCorrupted');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplPriorityQueueBuiltin::CLASS_LC,
            'SplPriorityQueue::isCorrupted()'
        );
        $this->requireExactUserArgCount($frame, 'SplPriorityQueue::isCorrupted', 0);
        SplIteratorSupport::setReturnBool($frame, SplPriorityQueueBuiltin::isCorrupted($object));
    }
}

final class SplPriorityQueueSetExtractFlags extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setExtractFlags');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplPriorityQueueBuiltin::CLASS_LC,
            'SplPriorityQueue::setExtractFlags()'
        );
        $this->requireExactUserArgCount($frame, 'SplPriorityQueue::setExtractFlags', 1);
        $flags = $frame->calledArgs[1]->resolveIndirect()->toInt();
        $result = SplPriorityQueueBuiltin::setExtractFlags($object, $flags);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($result);
        }
    }
}

final class SplPriorityQueueGetExtractFlags extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getExtractFlags');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplPriorityQueueBuiltin::CLASS_LC,
            'SplPriorityQueue::getExtractFlags()'
        );
        $this->requireExactUserArgCount($frame, 'SplPriorityQueue::getExtractFlags', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(SplPriorityQueueBuiltin::getExtractFlags($object));
    }
}

final class SplPriorityQueueRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplPriorityQueueBuiltin::CLASS_LC,
            'SplPriorityQueue::rewind()'
        );
        $this->requireExactUserArgCount($frame, 'SplPriorityQueue::rewind', 0);
        SplPriorityQueueBuiltin::rewind($object);
    }
}

final class SplPriorityQueueValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplPriorityQueueBuiltin::CLASS_LC,
            'SplPriorityQueue::valid()'
        );
        $this->requireExactUserArgCount($frame, 'SplPriorityQueue::valid', 0);
        SplIteratorSupport::setReturnBool($frame, SplPriorityQueueBuiltin::valid($object));
    }
}

final class SplPriorityQueueCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplPriorityQueueBuiltin::CLASS_LC,
            'SplPriorityQueue::current()'
        );
        $this->requireExactUserArgCount($frame, 'SplPriorityQueue::current', 0);
        SplIteratorSupport::copyReturnFrom($frame, SplPriorityQueueBuiltin::current($object));
    }
}

final class SplPriorityQueueKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplPriorityQueueBuiltin::CLASS_LC,
            'SplPriorityQueue::key()'
        );
        $this->requireExactUserArgCount($frame, 'SplPriorityQueue::key', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(SplPriorityQueueBuiltin::key($object));
    }
}

final class SplPriorityQueueNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplPriorityQueueBuiltin::CLASS_LC,
            'SplPriorityQueue::next()'
        );
        $this->requireExactUserArgCount($frame, 'SplPriorityQueue::next', 0);
        SplPriorityQueueBuiltin::next($object, $frame);
    }
}

final class SplPriorityQueueRecoverFromCorruption extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('recoverFromCorruption');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplPriorityQueueBuiltin::CLASS_LC,
            'SplPriorityQueue::recoverFromCorruption()'
        );
        $this->requireExactUserArgCount($frame, 'SplPriorityQueue::recoverFromCorruption', 0);
        SplIteratorSupport::setReturnBool($frame, SplPriorityQueueBuiltin::recoverFromCorruption($object));
    }
}

final class SplPriorityQueueCompare extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('compare');
    }

    public function execute(Frame $frame): void
    {
        SplIteratorSupport::receiverIsA(
            $frame,
            SplPriorityQueueBuiltin::CLASS_LC,
            'SplPriorityQueue::compare()'
        );
        $this->requireExactUserArgCount($frame, 'SplPriorityQueue::compare', 2);
        $cmp = Variable::spaceshipCompare($frame->calledArgs[1], $frame->calledArgs[2]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($cmp);
        }
    }
}
