<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ds;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Ds depth classes/interfaces beyond Vector/Map/Set (#28062, php-ds/ext-ds).
 */
final class DsDepthClasses
{
    public static function register(Context $ctx): void
    {
        self::registerInterfaces($ctx);
        self::registerPair($ctx);
        self::registerDeque($ctx);
        self::registerStack($ctx);
        self::registerQueue($ctx);
        self::registerHeap($ctx);
        self::registerPriorityQueue($ctx);
        // Wire Sequence onto Vector / Deque when present.
        foreach ([VmDsStorage::VECTOR_LC, VmDsDepth::DEQUE_LC] as $lc) {
            if (isset($ctx->classes[$lc]) && isset($ctx->classes[VmDsDepth::SEQUENCE_LC])) {
                if (!\in_array(VmDsDepth::SEQUENCE_LC, $ctx->classes[$lc]->interfaces, true)) {
                    $ctx->classes[$lc]->interfaces[] = VmDsDepth::SEQUENCE_LC;
                }
            }
        }
        foreach ([VmDsStorage::VECTOR_LC, VmDsStorage::MAP_LC, VmDsStorage::SET_LC, VmDsDepth::DEQUE_LC, VmDsDepth::STACK_LC, VmDsDepth::QUEUE_LC, VmDsDepth::HEAP_LC, VmDsDepth::PRIORITY_QUEUE_LC] as $lc) {
            if (isset($ctx->classes[$lc]) && isset($ctx->classes[VmDsDepth::COLLECTION_LC])) {
                if (!\in_array(VmDsDepth::COLLECTION_LC, $ctx->classes[$lc]->interfaces, true)) {
                    $ctx->classes[$lc]->interfaces[] = VmDsDepth::COLLECTION_LC;
                }
            }
        }
    }

    private static function registerInterfaces(Context $ctx): void
    {
        if (!isset($ctx->classes[VmDsDepth::HASHABLE_LC])) {
            $entry = new ClassEntry('Ds\\Hashable');
            $entry->isInterface = true;
            $ctx->classes[VmDsDepth::HASHABLE_LC] = $entry;
        }
        if (!isset($ctx->classes[VmDsDepth::COLLECTION_LC])) {
            $entry = new ClassEntry('Ds\\Collection');
            $entry->isInterface = true;
            if (isset($ctx->classes['countable'])) {
                $entry->interfaces[] = 'countable';
            }
            if (isset($ctx->classes['traversable'])) {
                $entry->interfaces[] = 'traversable';
            }
            $ctx->classes[VmDsDepth::COLLECTION_LC] = $entry;
        }
        if (!isset($ctx->classes[VmDsDepth::SEQUENCE_LC])) {
            $entry = new ClassEntry('Ds\\Sequence');
            $entry->isInterface = true;
            $entry->interfaces[] = VmDsDepth::COLLECTION_LC;
            $ctx->classes[VmDsDepth::SEQUENCE_LC] = $entry;
        }
    }

    private static function registerPair(Context $ctx): void
    {
        if (isset($ctx->classes[VmDsDepth::PAIR_LC])) {
            return;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('Ds\\Pair');
        $entry->constructor = new DsPairConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['toarray'] = new DsPairToArray();
        $entry->methodVisibility['toarray'] = $pub;
        $entry->methodNames['toarray'] = 'toArray';
        $ctx->classes[VmDsDepth::PAIR_LC] = $entry;
    }

    private static function registerDeque(Context $ctx): void
    {
        if (isset($ctx->classes[VmDsDepth::DEQUE_LC])) {
            return;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('Ds\\Deque');
        if (isset($ctx->classes['countable'])) {
            $entry->interfaces[] = 'countable';
        }
        $entry->constructor = new DsDequeConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['count'] = new DsDequeCount();
        $entry->methodVisibility['count'] = $pub;
        $entry->methodNames['count'] = 'count';
        $entry->methods['push'] = new DsDequePush();
        $entry->methodVisibility['push'] = $pub;
        $entry->methodNames['push'] = 'push';
        $ctx->classes[VmDsDepth::DEQUE_LC] = $entry;
    }

    private static function registerStack(Context $ctx): void
    {
        if (isset($ctx->classes[VmDsDepth::STACK_LC])) {
            return;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('Ds\\Stack');
        if (isset($ctx->classes['countable'])) {
            $entry->interfaces[] = 'countable';
        }
        $entry->constructor = new DsStackConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['count'] = new DsStackCount();
        $entry->methodVisibility['count'] = $pub;
        $entry->methods['push'] = new DsStackPush();
        $entry->methodVisibility['push'] = $pub;
        $entry->methods['pop'] = new DsStackPop();
        $entry->methodVisibility['pop'] = $pub;
        $ctx->classes[VmDsDepth::STACK_LC] = $entry;
    }

    private static function registerQueue(Context $ctx): void
    {
        if (isset($ctx->classes[VmDsDepth::QUEUE_LC])) {
            return;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('Ds\\Queue');
        if (isset($ctx->classes['countable'])) {
            $entry->interfaces[] = 'countable';
        }
        $entry->constructor = new DsQueueConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['count'] = new DsQueueCount();
        $entry->methodVisibility['count'] = $pub;
        $entry->methods['push'] = new DsQueuePush();
        $entry->methodVisibility['push'] = $pub;
        $entry->methods['pop'] = new DsQueuePop();
        $entry->methodVisibility['pop'] = $pub;
        $ctx->classes[VmDsDepth::QUEUE_LC] = $entry;
    }

    private static function registerHeap(Context $ctx): void
    {
        if (isset($ctx->classes[VmDsDepth::HEAP_LC])) {
            return;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('Ds\\Heap');
        if (isset($ctx->classes['countable'])) {
            $entry->interfaces[] = 'countable';
        }
        $entry->constructor = new DsHeapConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['count'] = new DsHeapCount();
        $entry->methodVisibility['count'] = $pub;
        $entry->methods['push'] = new DsHeapPush();
        $entry->methodVisibility['push'] = $pub;
        $entry->methods['pop'] = new DsHeapPop();
        $entry->methodVisibility['pop'] = $pub;
        $ctx->classes[VmDsDepth::HEAP_LC] = $entry;
    }

    private static function registerPriorityQueue(Context $ctx): void
    {
        if (isset($ctx->classes[VmDsDepth::PRIORITY_QUEUE_LC])) {
            return;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('Ds\\PriorityQueue');
        if (isset($ctx->classes['countable'])) {
            $entry->interfaces[] = 'countable';
        }
        $entry->constructor = new DsPriorityQueueConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['count'] = new DsPriorityQueueCount();
        $entry->methodVisibility['count'] = $pub;
        $entry->methods['push'] = new DsPriorityQueuePush();
        $entry->methodVisibility['push'] = $pub;
        $entry->methods['pop'] = new DsPriorityQueuePop();
        $entry->methodVisibility['pop'] = $pub;
        $ctx->classes[VmDsDepth::PRIORITY_QUEUE_LC] = $entry;
    }
}

final class DsPairConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = VmDsStorage::receiver($frame, VmDsDepth::PAIR_LC, 'Ds\\Pair::__construct');
        $argc = \count($frame->calledArgs) - 1;
        if (2 !== $argc) {
            throw new \ArgumentCountError('Ds\\Pair::__construct() expects exactly 2 arguments, '.$argc.' given');
        }
        VmDsDepth::initPair($object, $frame->calledArgs[1], $frame->calledArgs[2]);
        $object->constructed = true;
    }
}

final class DsPairToArray extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('toArray');
    }

    public function execute(Frame $frame): void
    {
        $object = VmDsStorage::receiver($frame, VmDsDepth::PAIR_LC, 'Ds\\Pair::toArray');
        if (null === $frame->returnVar) {
            return;
        }
        $bag = VmDsDepth::pairBag($object);
        $ht = new HashTable();
        $ht->add('key', $bag['key']);
        $ht->add('value', $bag['value']);
        $frame->returnVar->array($ht);
    }
}

final class DsDequeConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = VmDsStorage::receiver($frame, VmDsDepth::DEQUE_LC, 'Ds\\Deque::__construct');
        $table = new HashTable();
        if (isset($frame->calledArgs[1])) {
            $table = VmDsStorage::requireArrayArg($frame->calledArgs[1], 'Ds\\Deque::__construct', 1);
        }
        VmDsDepth::initDeque($object, $table);
        $object->constructed = true;
    }
}

final class DsDequeCount extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        $object = VmDsStorage::receiver($frame, VmDsDepth::DEQUE_LC, 'Ds\\Deque::count');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmDsDepth::dequeTable($object)->getNumElements());
        }
    }
}

final class DsDequePush extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('push');
    }

    public function execute(Frame $frame): void
    {
        $object = VmDsStorage::receiver($frame, VmDsDepth::DEQUE_LC, 'Ds\\Deque::push');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 1) {
            throw new \ArgumentCountError('Ds\\Deque::push() expects at least 1 argument, '.$argc.' given');
        }
        $table = VmDsDepth::dequeTable($object);
        for ($i = 1; $i < \count($frame->calledArgs); ++$i) {
            $copy = new Variable();
            $copy->copyFrom($frame->calledArgs[$i]->resolveIndirect());
            $table->append($copy);
        }
    }
}

final class DsStackConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = VmDsStorage::receiver($frame, VmDsDepth::STACK_LC, 'Ds\\Stack::__construct');
        $table = new HashTable();
        if (isset($frame->calledArgs[1])) {
            $table = VmDsStorage::requireArrayArg($frame->calledArgs[1], 'Ds\\Stack::__construct', 1);
        }
        VmDsDepth::initStack($object, $table);
        $object->constructed = true;
    }
}

final class DsStackCount extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        $object = VmDsStorage::receiver($frame, VmDsDepth::STACK_LC, 'Ds\\Stack::count');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmDsDepth::stackTable($object)->getNumElements());
        }
    }
}

final class DsStackPush extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('push');
    }

    public function execute(Frame $frame): void
    {
        $object = VmDsStorage::receiver($frame, VmDsDepth::STACK_LC, 'Ds\\Stack::push');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 1) {
            throw new \ArgumentCountError('Ds\\Stack::push() expects at least 1 argument, '.$argc.' given');
        }
        for ($i = 1; $i < \count($frame->calledArgs); ++$i) {
            VmDsDepth::stackPush($object, $frame->calledArgs[$i]);
        }
    }
}

final class DsStackPop extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('pop');
    }

    public function execute(Frame $frame): void
    {
        $object = VmDsStorage::receiver($frame, VmDsDepth::STACK_LC, 'Ds\\Stack::pop');
        $result = VmDsDepth::stackPop($object);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
    }
}

final class DsQueueConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = VmDsStorage::receiver($frame, VmDsDepth::QUEUE_LC, 'Ds\\Queue::__construct');
        $table = new HashTable();
        if (isset($frame->calledArgs[1])) {
            $table = VmDsStorage::requireArrayArg($frame->calledArgs[1], 'Ds\\Queue::__construct', 1);
        }
        VmDsDepth::initQueue($object, $table);
        $object->constructed = true;
    }
}

final class DsQueueCount extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        $object = VmDsStorage::receiver($frame, VmDsDepth::QUEUE_LC, 'Ds\\Queue::count');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmDsDepth::queueTable($object)->getNumElements());
        }
    }
}

final class DsQueuePush extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('push');
    }

    public function execute(Frame $frame): void
    {
        $object = VmDsStorage::receiver($frame, VmDsDepth::QUEUE_LC, 'Ds\\Queue::push');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 1) {
            throw new \ArgumentCountError('Ds\\Queue::push() expects at least 1 argument, '.$argc.' given');
        }
        for ($i = 1; $i < \count($frame->calledArgs); ++$i) {
            VmDsDepth::queuePush($object, $frame->calledArgs[$i]);
        }
    }
}

final class DsQueuePop extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('pop');
    }

    public function execute(Frame $frame): void
    {
        $object = VmDsStorage::receiver($frame, VmDsDepth::QUEUE_LC, 'Ds\\Queue::pop');
        $result = VmDsDepth::queuePop($object);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
    }
}

final class DsHeapConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = VmDsStorage::receiver($frame, VmDsDepth::HEAP_LC, 'Ds\\Heap::__construct');
        $table = new HashTable();
        if (isset($frame->calledArgs[1])) {
            $table = VmDsStorage::requireArrayArg($frame->calledArgs[1], 'Ds\\Heap::__construct', 1);
        }
        VmDsDepth::initHeap($object, $table);
        $object->constructed = true;
    }
}

final class DsHeapCount extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        $object = VmDsStorage::receiver($frame, VmDsDepth::HEAP_LC, 'Ds\\Heap::count');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(\count(VmDsDepth::heapList($object)));
        }
    }
}

final class DsHeapPush extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('push');
    }

    public function execute(Frame $frame): void
    {
        $object = VmDsStorage::receiver($frame, VmDsDepth::HEAP_LC, 'Ds\\Heap::push');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 1) {
            throw new \ArgumentCountError('Ds\\Heap::push() expects at least 1 argument, '.$argc.' given');
        }
        for ($i = 1; $i < \count($frame->calledArgs); ++$i) {
            VmDsDepth::heapPush($object, $frame->calledArgs[$i]);
        }
    }
}

final class DsHeapPop extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('pop');
    }

    public function execute(Frame $frame): void
    {
        $object = VmDsStorage::receiver($frame, VmDsDepth::HEAP_LC, 'Ds\\Heap::pop');
        $result = VmDsDepth::heapPop($object);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
    }
}

final class DsPriorityQueueConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = VmDsStorage::receiver($frame, VmDsDepth::PRIORITY_QUEUE_LC, 'Ds\\PriorityQueue::__construct');
        VmDsDepth::initPriorityQueue($object);
        $object->constructed = true;
    }
}

final class DsPriorityQueueCount extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        $object = VmDsStorage::receiver($frame, VmDsDepth::PRIORITY_QUEUE_LC, 'Ds\\PriorityQueue::count');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmDsDepth::priorityQueueCount($object));
        }
    }
}

final class DsPriorityQueuePush extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('push');
    }

    public function execute(Frame $frame): void
    {
        $object = VmDsStorage::receiver($frame, VmDsDepth::PRIORITY_QUEUE_LC, 'Ds\\PriorityQueue::push');
        $argc = \count($frame->calledArgs) - 1;
        if (2 !== $argc) {
            throw new \ArgumentCountError('Ds\\PriorityQueue::push() expects exactly 2 arguments, '.$argc.' given');
        }
        $priVar = $frame->calledArgs[2]->resolveIndirect();
        $priority = match ($priVar->type) {
            Variable::TYPE_INTEGER => (float) $priVar->toInt(),
            Variable::TYPE_FLOAT => $priVar->toFloat(),
            default => (float) $priVar->toInt(),
        };
        VmDsDepth::priorityQueuePush($object, $frame->calledArgs[1], $priority);
    }
}

final class DsPriorityQueuePop extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('pop');
    }

    public function execute(Frame $frame): void
    {
        $object = VmDsStorage::receiver($frame, VmDsDepth::PRIORITY_QUEUE_LC, 'Ds\\PriorityQueue::pop');
        $result = VmDsDepth::priorityQueuePop($object);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
    }
}
