<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * VM attribute table reads for Reflection*::getAttributes() (#5301, #6922).
 *
 * Single SSOT over {@see ClassEntry} compile-time metadata; JIT/AOT uses
 * {@see \PHPCompiler\JIT\Builtin\AttributeRegistryLowering} with the same tables.
 */
final class AttributeRegistry
{
    public static function classAttributes(Frame $frame, ClassEntry $entry, ?string $filter): Variable
    {
        $entries = ReflectionSupport::filterEntriesByName($entry->attributeEntries, $filter);
        if ([] !== $entries) {
            return ReflectionSupport::attributesArrayFromEntries($frame, $entries);
        }

        return ReflectionSupport::attributesArray(
            $frame,
            ReflectionSupport::filterByName($entry->attributeNames, $filter)
        );
    }

    public static function methodAttributes(
        Frame $frame,
        ClassEntry $entry,
        string $methodLc,
        ?string $filter
    ): Variable {
        $allEntries = $entry->methodAttributeEntries[$methodLc] ?? [];
        $entries = ReflectionSupport::filterEntriesByName($allEntries, $filter);
        if ([] !== $entries) {
            return ReflectionSupport::attributesArrayFromEntries($frame, $entries);
        }

        $all = $entry->methodAttributeNames[$methodLc] ?? [];

        return ReflectionSupport::attributesArray(
            $frame,
            ReflectionSupport::filterByName($all, $filter)
        );
    }

    public static function propertyAttributes(
        Frame $frame,
        ClassEntry $entry,
        string $propLc,
        ?string $filter
    ): Variable {
        $allEntries = $entry->propertyAttributeEntries[$propLc] ?? [];
        $entries = ReflectionSupport::filterEntriesByName($allEntries, $filter);
        if ([] !== $entries) {
            return ReflectionSupport::attributesArrayFromEntries($frame, $entries);
        }

        $all = $entry->propertyAttributeNames[$propLc] ?? [];

        return ReflectionSupport::attributesArray(
            $frame,
            ReflectionSupport::filterByName($all, $filter)
        );
    }

    public static function constantAttributes(
        Frame $frame,
        ClassEntry $entry,
        string $constLc,
        ?string $filter
    ): Variable {
        $allEntries = $entry->constAttributeEntries[$constLc] ?? [];
        $entries = ReflectionSupport::filterEntriesByName($allEntries, $filter);
        if ([] !== $entries) {
            return ReflectionSupport::attributesArrayFromEntries($frame, $entries);
        }

        $all = $entry->constAttributeNames[$constLc] ?? [];

        return ReflectionSupport::attributesArray(
            $frame,
            ReflectionSupport::filterByName($all, $filter)
        );
    }

    public static function enumCaseAttributes(
        Frame $frame,
        ClassEntry $entry,
        string $caseLc,
        ?string $filter
    ): Variable {
        $entries = $entry->enumCaseAttributeEntries[$caseLc] ?? [];

        return ReflectionSupport::attributesArrayFromEntries(
            $frame,
            ReflectionSupport::filterEntriesByName($entries, $filter)
        );
    }
}
