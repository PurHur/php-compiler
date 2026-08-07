<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ds;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Storage helpers for Ds depth types (#28062) — Pair / Deque / Stack / Queue / Heap / PriorityQueue.
 */
final class VmDsDepth
{
    public const PAIR_LC = 'ds\\pair';
    public const DEQUE_LC = 'ds\\deque';
    public const STACK_LC = 'ds\\stack';
    public const QUEUE_LC = 'ds\\queue';
    public const HEAP_LC = 'ds\\heap';
    public const PRIORITY_QUEUE_LC = 'ds\\priorityqueue';
    public const COLLECTION_LC = 'ds\\collection';
    public const HASHABLE_LC = 'ds\\hashable';
    public const SEQUENCE_LC = 'ds\\sequence';

    /** @var array<int, array{key: Variable, value: Variable}> */
    private static array $pairs = [];

    /** @var array<int, HashTable> */
    private static array $deques = [];

    /** @var array<int, HashTable> */
    private static array $stacks = [];

    /** @var array<int, HashTable> */
    private static array $queues = [];

    /** @var array<int, list<Variable>> */
    private static array $heaps = [];

    /** @var array<int, list<array{priority: float, value: Variable}>> */
    private static array $priorityQueues = [];

    public static function initPair(ObjectEntry $object, Variable $key, Variable $value): void
    {
        $k = new Variable();
        $k->copyFrom($key->resolveIndirect());
        $v = new Variable();
        $v->copyFrom($value->resolveIndirect());
        self::$pairs[$object->id] = ['key' => $k, 'value' => $v];
    }

    /** @return array{key: Variable, value: Variable} */
    public static function pairBag(ObjectEntry $object): array
    {
        if (!isset(self::$pairs[$object->id])) {
            $k = new Variable();
            $k->null();
            $v = new Variable();
            $v->null();
            self::$pairs[$object->id] = ['key' => $k, 'value' => $v];
        }

        return self::$pairs[$object->id];
    }

    public static function initDeque(ObjectEntry $object, HashTable $values): void
    {
        self::$deques[$object->id] = self::reindex($values);
    }

    public static function dequeTable(ObjectEntry $object): HashTable
    {
        return self::$deques[$object->id] ?? new HashTable();
    }

    public static function initStack(ObjectEntry $object, HashTable $values): void
    {
        self::$stacks[$object->id] = self::reindex($values);
    }

    public static function stackTable(ObjectEntry $object): HashTable
    {
        return self::$stacks[$object->id] ?? new HashTable();
    }

    public static function stackPush(ObjectEntry $object, Variable $value): void
    {
        if (!isset(self::$stacks[$object->id])) {
            self::$stacks[$object->id] = new HashTable();
        }
        $copy = new Variable();
        $copy->copyFrom($value->resolveIndirect());
        self::$stacks[$object->id]->append($copy);
    }

    public static function stackPop(ObjectEntry $object): Variable
    {
        $table = self::stackTable($object);
        if ($table->getNumElements() < 1) {
            throw new \UnderflowException('Stack is empty');
        }
        $last = null;
        foreach ($table->iterate() as $entry) {
            $last = $entry;
        }
        if (null === $last) {
            throw new \UnderflowException('Stack is empty');
        }
        $out = new Variable();
        $out->copyFrom($last->resolveIndirect());
        self::$stacks[$object->id] = self::dropLast($table);

        return $out;
    }

    public static function initQueue(ObjectEntry $object, HashTable $values): void
    {
        self::$queues[$object->id] = self::reindex($values);
    }

    public static function queueTable(ObjectEntry $object): HashTable
    {
        return self::$queues[$object->id] ?? new HashTable();
    }

    public static function queuePush(ObjectEntry $object, Variable $value): void
    {
        if (!isset(self::$queues[$object->id])) {
            self::$queues[$object->id] = new HashTable();
        }
        $copy = new Variable();
        $copy->copyFrom($value->resolveIndirect());
        self::$queues[$object->id]->append($copy);
    }

    public static function queuePop(ObjectEntry $object): Variable
    {
        $table = self::queueTable($object);
        if ($table->getNumElements() < 1) {
            throw new \UnderflowException('Queue is empty');
        }
        $first = null;
        foreach ($table->iterate() as $entry) {
            $first = $entry;
            break;
        }
        if (null === $first) {
            throw new \UnderflowException('Queue is empty');
        }
        $out = new Variable();
        $out->copyFrom($first->resolveIndirect());
        self::$queues[$object->id] = self::dropFirst($table);

        return $out;
    }

    public static function initHeap(ObjectEntry $object, HashTable $values): void
    {
        $list = [];
        foreach ($values->iterate() as $entry) {
            $copy = new Variable();
            $copy->copyFrom($entry->resolveIndirect());
            $list[] = $copy;
        }
        self::$heaps[$object->id] = $list;
    }

    /** @return list<Variable> */
    public static function heapList(ObjectEntry $object): array
    {
        return self::$heaps[$object->id] ?? [];
    }

    public static function heapPush(ObjectEntry $object, Variable $value): void
    {
        if (!isset(self::$heaps[$object->id])) {
            self::$heaps[$object->id] = [];
        }
        $copy = new Variable();
        $copy->copyFrom($value->resolveIndirect());
        self::$heaps[$object->id][] = $copy;
    }

    public static function heapPop(ObjectEntry $object): Variable
    {
        $list = self::heapList($object);
        if ([] === $list) {
            throw new \UnderflowException('Heap is empty');
        }
        // Max-heap by string/int identity — pop last for MVP (insertion order max approx).
        $out = \array_pop($list);
        self::$heaps[$object->id] = $list;
        if (null === $out) {
            throw new \UnderflowException('Heap is empty');
        }

        return $out;
    }

    public static function initPriorityQueue(ObjectEntry $object): void
    {
        self::$priorityQueues[$object->id] = [];
    }

    public static function priorityQueuePush(ObjectEntry $object, Variable $value, float $priority): void
    {
        if (!isset(self::$priorityQueues[$object->id])) {
            self::$priorityQueues[$object->id] = [];
        }
        $copy = new Variable();
        $copy->copyFrom($value->resolveIndirect());
        self::$priorityQueues[$object->id][] = ['priority' => $priority, 'value' => $copy];
    }

    public static function priorityQueueCount(ObjectEntry $object): int
    {
        return \count(self::$priorityQueues[$object->id] ?? []);
    }

    public static function priorityQueuePop(ObjectEntry $object): Variable
    {
        $list = self::$priorityQueues[$object->id] ?? [];
        if ([] === $list) {
            throw new \UnderflowException('PriorityQueue is empty');
        }
        $bestIdx = 0;
        $bestPri = $list[0]['priority'];
        foreach ($list as $i => $row) {
            if ($row['priority'] > $bestPri) {
                $bestPri = $row['priority'];
                $bestIdx = $i;
            }
        }
        $out = $list[$bestIdx]['value'];
        \array_splice($list, $bestIdx, 1);
        self::$priorityQueues[$object->id] = $list;

        return $out;
    }

    private static function reindex(HashTable $src): HashTable
    {
        $out = new HashTable();
        foreach ($src->iterate() as $value) {
            $copy = new Variable();
            $copy->copyFrom($value->resolveIndirect());
            $out->append($copy);
        }

        return $out;
    }

    private static function dropLast(HashTable $src): HashTable
    {
        $out = new HashTable();
        $n = $src->getNumElements();
        $i = 0;
        foreach ($src->iterate() as $value) {
            ++$i;
            if ($i >= $n) {
                break;
            }
            $copy = new Variable();
            $copy->copyFrom($value->resolveIndirect());
            $out->append($copy);
        }

        return $out;
    }

    private static function dropFirst(HashTable $src): HashTable
    {
        $out = new HashTable();
        $first = true;
        foreach ($src->iterate() as $value) {
            if ($first) {
                $first = false;
                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($value->resolveIndirect());
            $out->append($copy);
        }

        return $out;
    }
}
