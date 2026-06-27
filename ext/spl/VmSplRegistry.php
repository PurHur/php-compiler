<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/**
 * SPL class registry for spl_classes() (php-src ext/spl/php_spl.c SPL_LIST_CLASSES; issue #11802, #11817).
 */
final class VmSplRegistry
{
    /**
     * php-src SPL_LIST_CLASSES order — classes and interfaces registered by ext/spl.
     *
     * @var list<string>
     */
    public const REGISTRY = [
        'AppendIterator',
        'ArrayIterator',
        'ArrayObject',
        'BadFunctionCallException',
        'BadMethodCallException',
        'CachingIterator',
        'CallbackFilterIterator',
        'DirectoryIterator',
        'DomainException',
        'EmptyIterator',
        'FilesystemIterator',
        'FilterIterator',
        'GlobIterator',
        'InfiniteIterator',
        'InvalidArgumentException',
        'IteratorIterator',
        'LengthException',
        'LimitIterator',
        'LogicException',
        'MultipleIterator',
        'NoRewindIterator',
        'OuterIterator',
        'OutOfBoundsException',
        'OutOfRangeException',
        'OverflowException',
        'ParentIterator',
        'RangeException',
        'RecursiveArrayIterator',
        'RecursiveCachingIterator',
        'RecursiveCallbackFilterIterator',
        'RecursiveDirectoryIterator',
        'RecursiveFilterIterator',
        'RecursiveIterator',
        'RecursiveIteratorIterator',
        'RecursiveRegexIterator',
        'RecursiveTreeIterator',
        'RegexIterator',
        'RuntimeException',
        'SeekableIterator',
        'SplDoublyLinkedList',
        'SplFileInfo',
        'SplFileObject',
        'SplFixedArray',
        'SplHeap',
        'SplMinHeap',
        'SplMaxHeap',
        'SplObjectStorage',
        'SplObserver',
        'SplPriorityQueue',
        'SplQueue',
        'SplStack',
        'SplSubject',
        'SplTempFileObject',
        'UnderflowException',
        'UnexpectedValueException',
    ];

    /**
     * Parent class and interface metadata for SPL stub registration (#11817).
     *
     * @var array<string, array{parent: ?string, interfaces: list<string>}>
     */
    private const STUB_META = [
        'AppendIterator' => ['parent' => 'IteratorIterator', 'interfaces' => ['Iterator', 'Traversable', 'OuterIterator']],
        'ArrayIterator' => ['parent' => null, 'interfaces' => ['SeekableIterator', 'Traversable', 'Iterator', 'ArrayAccess', 'Serializable', 'Countable']],
        'ArrayObject' => ['parent' => null, 'interfaces' => ['IteratorAggregate', 'Traversable', 'ArrayAccess', 'Serializable', 'Countable']],
        'BadFunctionCallException' => ['parent' => 'LogicException', 'interfaces' => ['Stringable', 'Throwable']],
        'BadMethodCallException' => ['parent' => 'BadFunctionCallException', 'interfaces' => ['Throwable', 'Stringable']],
        'CachingIterator' => ['parent' => 'IteratorIterator', 'interfaces' => ['Stringable', 'Iterator', 'Traversable', 'OuterIterator', 'ArrayAccess', 'Countable']],
        'CallbackFilterIterator' => ['parent' => 'FilterIterator', 'interfaces' => ['OuterIterator', 'Traversable', 'Iterator']],
        'DirectoryIterator' => ['parent' => 'SplFileInfo', 'interfaces' => ['Stringable', 'SeekableIterator', 'Traversable', 'Iterator']],
        'DomainException' => ['parent' => 'LogicException', 'interfaces' => ['Stringable', 'Throwable']],
        'EmptyIterator' => ['parent' => null, 'interfaces' => ['Iterator', 'Traversable']],
        'FilesystemIterator' => ['parent' => 'DirectoryIterator', 'interfaces' => ['Iterator', 'Traversable', 'SeekableIterator', 'Stringable']],
        'FilterIterator' => ['parent' => 'IteratorIterator', 'interfaces' => ['Iterator', 'Traversable', 'OuterIterator']],
        'GlobIterator' => ['parent' => 'FilesystemIterator', 'interfaces' => ['Stringable', 'SeekableIterator', 'Traversable', 'Iterator', 'Countable']],
        'InfiniteIterator' => ['parent' => 'IteratorIterator', 'interfaces' => ['Iterator', 'Traversable', 'OuterIterator']],
        'InvalidArgumentException' => ['parent' => 'LogicException', 'interfaces' => ['Stringable', 'Throwable']],
        'IteratorIterator' => ['parent' => null, 'interfaces' => ['OuterIterator', 'Traversable', 'Iterator']],
        'LengthException' => ['parent' => 'LogicException', 'interfaces' => ['Stringable', 'Throwable']],
        'LimitIterator' => ['parent' => 'IteratorIterator', 'interfaces' => ['Iterator', 'Traversable', 'OuterIterator']],
        'LogicException' => ['parent' => 'Exception', 'interfaces' => ['Throwable', 'Stringable']],
        'MultipleIterator' => ['parent' => null, 'interfaces' => ['Iterator', 'Traversable']],
        'NoRewindIterator' => ['parent' => 'IteratorIterator', 'interfaces' => ['Iterator', 'Traversable', 'OuterIterator']],
        'OuterIterator' => ['parent' => null, 'interfaces' => ['Iterator', 'Traversable']],
        'OutOfBoundsException' => ['parent' => 'RuntimeException', 'interfaces' => ['Stringable', 'Throwable']],
        'OutOfRangeException' => ['parent' => 'LogicException', 'interfaces' => ['Stringable', 'Throwable']],
        'OverflowException' => ['parent' => 'RuntimeException', 'interfaces' => ['Stringable', 'Throwable']],
        'ParentIterator' => ['parent' => 'RecursiveFilterIterator', 'interfaces' => ['RecursiveIterator', 'Iterator', 'Traversable', 'OuterIterator']],
        'RangeException' => ['parent' => 'RuntimeException', 'interfaces' => ['Stringable', 'Throwable']],
        'RecursiveArrayIterator' => ['parent' => 'ArrayIterator', 'interfaces' => ['Countable', 'Serializable', 'ArrayAccess', 'Iterator', 'Traversable', 'SeekableIterator', 'RecursiveIterator']],
        'RecursiveCachingIterator' => ['parent' => 'CachingIterator', 'interfaces' => ['Countable', 'ArrayAccess', 'OuterIterator', 'Traversable', 'Iterator', 'Stringable', 'RecursiveIterator']],
        'RecursiveCallbackFilterIterator' => ['parent' => 'CallbackFilterIterator', 'interfaces' => ['Iterator', 'Traversable', 'OuterIterator', 'RecursiveIterator']],
        'RecursiveDirectoryIterator' => ['parent' => 'FilesystemIterator', 'interfaces' => ['Stringable', 'SeekableIterator', 'Traversable', 'Iterator', 'RecursiveIterator']],
        'RecursiveFilterIterator' => ['parent' => 'FilterIterator', 'interfaces' => ['OuterIterator', 'Traversable', 'Iterator', 'RecursiveIterator']],
        'RecursiveIterator' => ['parent' => null, 'interfaces' => ['Iterator', 'Traversable']],
        'RecursiveIteratorIterator' => ['parent' => null, 'interfaces' => ['OuterIterator', 'Traversable', 'Iterator']],
        'RecursiveRegexIterator' => ['parent' => 'RegexIterator', 'interfaces' => ['Iterator', 'Traversable', 'OuterIterator', 'RecursiveIterator']],
        'RecursiveTreeIterator' => ['parent' => 'RecursiveIteratorIterator', 'interfaces' => ['Iterator', 'Traversable', 'OuterIterator']],
        'RegexIterator' => ['parent' => 'FilterIterator', 'interfaces' => ['OuterIterator', 'Traversable', 'Iterator']],
        'RuntimeException' => ['parent' => 'Exception', 'interfaces' => ['Throwable', 'Stringable']],
        'SeekableIterator' => ['parent' => null, 'interfaces' => ['Iterator', 'Traversable']],
        'SplDoublyLinkedList' => ['parent' => null, 'interfaces' => ['Iterator', 'Traversable', 'Countable', 'ArrayAccess', 'Serializable']],
        'SplFileInfo' => ['parent' => null, 'interfaces' => ['Stringable']],
        'SplFileObject' => ['parent' => 'SplFileInfo', 'interfaces' => ['Stringable', 'RecursiveIterator', 'Traversable', 'Iterator', 'SeekableIterator']],
        'SplFixedArray' => ['parent' => null, 'interfaces' => ['IteratorAggregate', 'Traversable', 'ArrayAccess', 'Countable', 'JsonSerializable']],
        'SplHeap' => ['parent' => null, 'interfaces' => ['Iterator', 'Traversable', 'Countable']],
        'SplMinHeap' => ['parent' => 'SplHeap', 'interfaces' => ['Countable', 'Traversable', 'Iterator']],
        'SplMaxHeap' => ['parent' => 'SplHeap', 'interfaces' => ['Countable', 'Traversable', 'Iterator']],
        'SplObjectStorage' => ['parent' => null, 'interfaces' => ['Countable', 'Iterator', 'Traversable', 'Serializable', 'ArrayAccess']],
        'SplObserver' => ['parent' => null, 'interfaces' => []],
        'SplPriorityQueue' => ['parent' => null, 'interfaces' => ['Iterator', 'Traversable', 'Countable']],
        'SplQueue' => ['parent' => 'SplDoublyLinkedList', 'interfaces' => ['Serializable', 'ArrayAccess', 'Countable', 'Traversable', 'Iterator']],
        'SplStack' => ['parent' => 'SplDoublyLinkedList', 'interfaces' => ['Serializable', 'ArrayAccess', 'Countable', 'Traversable', 'Iterator']],
        'SplSubject' => ['parent' => null, 'interfaces' => []],
        'SplTempFileObject' => ['parent' => 'SplFileObject', 'interfaces' => ['SeekableIterator', 'Iterator', 'Traversable', 'RecursiveIterator', 'Stringable']],
        'UnderflowException' => ['parent' => 'RuntimeException', 'interfaces' => ['Stringable', 'Throwable']],
        'UnexpectedValueException' => ['parent' => 'RuntimeException', 'interfaces' => ['Stringable', 'Throwable']],
    ];

    /** Register SPL class stubs missing from the VM context (#11817). */
    public static function registerStubs(Context $ctx): void
    {
        foreach (self::REGISTRY as $name) {
            self::ensureStub($ctx, $name);
        }
    }

    private static function ensureStub(Context $ctx, string $name): void
    {
        $lc = strtolower($name);
        if (isset($ctx->classes[$lc])) {
            return;
        }

        $meta = self::STUB_META[$name] ?? ['parent' => null, 'interfaces' => []];
        if (null !== $meta['parent']) {
            self::ensureStub($ctx, $meta['parent']);
        }

        $entry = new ClassEntry($name);
        if (null !== $meta['parent']) {
            $entry->parentLc = strtolower($meta['parent']);
        }
        foreach ($meta['interfaces'] as $iface) {
            if (isset($ctx->classes[strtolower($iface)])) {
                $entry->interfaces[] = $iface;
            }
        }
        $entry->isInternal = true;
        $ctx->classes[$lc] = $entry;
    }

    /**
     * @return array<string, string> class name => class name (Zend spl_classes wire)
     */
    public static function classesMap(Context $ctx): array
    {
        $result = [];
        foreach (self::REGISTRY as $name) {
            if (isset($ctx->classes[strtolower($name)])) {
                $result[$name] = $name;
            }
        }

        return $result;
    }

    public static function classesVariable(Context $ctx): Variable
    {
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach (self::classesMap($ctx) as $name => $value) {
            $var = new Variable();
            $var->string($value);
            $ht->add($name, $var);
        }

        return $result;
    }
}
