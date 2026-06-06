<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Object comparison helpers — Zend zend_compare_objects parity (#6749).
 *
 * VM walks named properties via {@see ObjectEntry::compareSpaceship()}.
 * JIT/AOT store slot counts on the __object__ header and read via __object__prop_count.
 */
final class ObjectCompare
{
    /** Declared instance property slot count for object spaceship walks. */
    public static function propertySlotCount(ObjectEntry $object): int
    {
        return \count($object->properties);
    }
}
