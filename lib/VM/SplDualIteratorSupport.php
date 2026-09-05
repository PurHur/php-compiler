<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Static bridge for SplDualIteratorStorage owned by ext/spl (#36204).
 *
 * Used when class-const materialization detaches object identity while SPL
 * iterator wrappers keep sidecar state keyed by ObjectEntry::id (#17721).
 *
 * php-src: ext/spl/spl_iterators.c — IteratorIterator / RecursiveIteratorIterator state.
 */
final class SplDualIteratorSupport
{
    /** @var null|callable(ObjectEntry): bool */
    private static $hasStateFor = null;

    /** @var null|callable(int, int): void */
    private static $transferState = null;

    public static function clear(): void
    {
        self::$hasStateFor = null;
        self::$transferState = null;
    }

    /** @param callable(ObjectEntry): bool $hook */
    public static function setHasStateFor(callable $hook): void
    {
        self::$hasStateFor = $hook;
    }

    /** @param callable(int, int): void $hook */
    public static function setTransferState(callable $hook): void
    {
        self::$transferState = $hook;
    }

    public static function hasStateFor(ObjectEntry $object): bool
    {
        if (null === self::$hasStateFor) {
            return false;
        }

        return (self::$hasStateFor)($object);
    }

    public static function transferState(int $fromId, int $toId): void
    {
        if (null === self::$transferState) {
            return;
        }

        (self::$transferState)($fromId, $toId);
    }
}
