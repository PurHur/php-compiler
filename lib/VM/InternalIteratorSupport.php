<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Static bridge for InternalIterator factories owned by ext/spl (#36204).
 *
 * lib/ must not import PHPCompiler\\ext\\spl; Module::init registers callables.
 *
 * php-src: Zend/zend_interfaces.c — zend_create_internal_iterator_zval.
 */
final class InternalIteratorSupport
{
    /** @var null|callable(Context, HashTable): ObjectEntry */
    private static $fromTable = null;

    /** @var null|callable(Context, InternalIteratorLiveHandler): ObjectEntry */
    private static $fromLiveHandler = null;

    public static function clear(): void
    {
        self::$fromTable = null;
        self::$fromLiveHandler = null;
    }

    /** @param callable(Context, HashTable): ObjectEntry $hook */
    public static function setFromTable(callable $hook): void
    {
        self::$fromTable = $hook;
    }

    /** @param callable(Context, InternalIteratorLiveHandler): ObjectEntry $hook */
    public static function setFromLiveHandler(callable $hook): void
    {
        self::$fromLiveHandler = $hook;
    }

    public static function fromTable(Context $ctx, HashTable $table): ObjectEntry
    {
        if (null === self::$fromTable) {
            throw new \LogicException('InternalIterator factory is not registered (#36204)');
        }

        return (self::$fromTable)($ctx, $table);
    }

    public static function fromLiveHandler(Context $ctx, InternalIteratorLiveHandler $handler): ObjectEntry
    {
        if (null === self::$fromLiveHandler) {
            throw new \LogicException('InternalIterator live factory is not registered (#36204)');
        }

        return (self::$fromLiveHandler)($ctx, $handler);
    }
}
