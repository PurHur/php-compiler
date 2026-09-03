<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Static bridge to Module-registered {@see ObjectComputedPropertyHandler}s (#36204).
 *
 * Used from {@see ObjectEntry} (no live Context) and VM property paths.
 */
final class ObjectComputedPropertySupport
{
    /** @var list<ObjectComputedPropertyHandler> */
    private static array $handlers = [];

    public static function clear(): void
    {
        self::$handlers = [];
    }

    public static function register(ObjectComputedPropertyHandler $handler): void
    {
        self::$handlers[] = $handler;
    }

    public static function isManagedProperty(ObjectEntry $object, string $name): bool
    {
        foreach (self::$handlers as $handler) {
            if (($handler->isManaged)($object, $name)) {
                return true;
            }
        }

        return false;
    }

    public static function getProperty(ObjectEntry $object, string $name): ?Variable
    {
        foreach (self::$handlers as $handler) {
            if (!($handler->isManaged)($object, $name)) {
                continue;
            }
            if (null === $handler->get) {
                continue;
            }

            return ($handler->get)($object, $name);
        }

        return null;
    }

    public static function propertyIsSet(ObjectEntry $object, string $name): ?bool
    {
        foreach (self::$handlers as $handler) {
            if (null === $handler->isset) {
                continue;
            }
            $result = ($handler->isset)($object, $name);
            if (null !== $result) {
                return $result;
            }
        }

        return null;
    }

    public static function propertyIsEmpty(ObjectEntry $object, string $name): ?bool
    {
        foreach (self::$handlers as $handler) {
            if (null === $handler->empty) {
                continue;
            }
            $result = ($handler->empty)($object, $name);
            if (null !== $result) {
                return $result;
            }
        }

        return null;
    }

    public static function rejectReadOnlyPropertyWrite(ObjectEntry $object, string $name): void
    {
        foreach (self::$handlers as $handler) {
            if (null === $handler->rejectWrite) {
                continue;
            }
            ($handler->rejectWrite)($object, $name);
        }
    }

    public static function tryAssign(
        ObjectEntry $object,
        string $name,
        Variable $value,
        Context $context
    ): bool {
        foreach (self::$handlers as $handler) {
            if (null === $handler->tryAssign) {
                continue;
            }
            if (($handler->tryAssign)($object, $name, $value, $context)) {
                return true;
            }
        }

        return false;
    }
}
