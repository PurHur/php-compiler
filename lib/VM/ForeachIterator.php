<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;
use PHPCompiler\VM;

/**
 * Foreach over user Iterator / IteratorAggregate objects (Zend zend_iterators.c parity, #3234).
 */
final class ForeachIterator
{
    private const ITERATOR_METHODS = ['rewind', 'valid', 'current', 'key', 'next'];

    /**
     * Resolve Iterator or IteratorAggregate::getIterator() for foreach.
     *
     * @throws \TypeError when the object is not iterable
     * @throws \Exception when getIterator() returns a non-Iterator value (zend_interfaces.c, #19729)
     */
    public static function resolveTraversableObject(VM $vm, Frame $frame, Variable $container): Variable
    {
        $container = $container->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $container->type) {
            throw new \TypeError('foreach() argument must be of type array|object');
        }
        $entry = $container->toObject()->class;
        $context = $vm->context;

        if (InterfaceCheck::entryImplements($entry, 'iteratoraggregate', $context)) {
            $aggregateName = $entry->name;
            $inner = $vm->invokeForeachInstanceMethod($frame, $container, 'getIterator')->resolveIndirect();
            if (Variable::TYPE_OBJECT !== $inner->type) {
                throw self::badGetIteratorException($aggregateName);
            }
            $innerObject = $inner->toObject();
            if (null !== $innerObject->generatorState
                || self::entryImplementsIteratorProtocol($innerObject->class, $context)
            ) {
                return $inner;
            }
            // Nested IteratorAggregate (e.g. ArrayObject) — unwrap once more.
            // Returning $this is rejected by Zend (no Iterator handlers on the aggregate).
            if (InterfaceCheck::entryImplements($innerObject->class, 'iteratoraggregate', $context)
                && $innerObject !== $container->toObject()
            ) {
                return self::resolveTraversableObject($vm, $frame, $inner);
            }

            throw self::badGetIteratorException($aggregateName);
        }

        if (self::entryImplementsIteratorProtocol($entry, $context)) {
            return $container;
        }

        throw new \TypeError('Object of class '.$entry->name.' is not iterable');
    }

    public static function entryImplementsIteratorProtocol(ClassEntry $entry, Context $context): bool
    {
        if (InterfaceCheck::entryImplements($entry, 'iterator', $context)) {
            return true;
        }

        return self::entryHasIteratorMethods($entry);
    }

    private static function badGetIteratorException(string $aggregateName): \Exception
    {
        return new \Exception(
            'Objects returned by '.$aggregateName.'::getIterator() must be traversable or implement interface Iterator'
        );
    }

    private static function entryHasIteratorMethods(ClassEntry $entry): bool
    {
        foreach (self::ITERATOR_METHODS as $method) {
            if (!isset($entry->methods[$method])) {
                return false;
            }
        }

        return true;
    }
}
