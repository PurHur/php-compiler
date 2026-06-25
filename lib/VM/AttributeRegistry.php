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
    public static function classAttributes(Frame $frame, ClassEntry $entry, ?string $filter, int $flags = 0): Variable
    {
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('AttributeRegistry requires active VM context');
        }
        $entries = ReflectionSupport::filterEntriesByName($ctx, $entry->attributeEntries, $filter, $flags);
        if ([] !== $entries) {
            return ReflectionSupport::attributesArrayFromEntries($frame, $entries);
        }

        return ReflectionSupport::attributesArray(
            $frame,
            ReflectionSupport::filterByName($ctx, $entry->attributeNames, $filter, $flags)
        );
    }

    public static function methodAttributes(
        Frame $frame,
        ClassEntry $entry,
        string $methodLc,
        ?string $filter,
        int $flags = 0
    ): Variable {
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('AttributeRegistry requires active VM context');
        }
        $allEntries = $entry->methodAttributeEntries[$methodLc] ?? [];
        $entries = ReflectionSupport::filterEntriesByName($ctx, $allEntries, $filter, $flags);
        if ([] !== $entries) {
            return ReflectionSupport::attributesArrayFromEntries($frame, $entries);
        }

        $all = $entry->methodAttributeNames[$methodLc] ?? [];

        return ReflectionSupport::attributesArray(
            $frame,
            ReflectionSupport::filterByName($ctx, $all, $filter, $flags)
        );
    }

    public static function propertyAttributes(
        Frame $frame,
        ClassEntry $entry,
        string $propLc,
        ?string $filter,
        int $flags = 0
    ): Variable {
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('AttributeRegistry requires active VM context');
        }
        $allEntries = $entry->propertyAttributeEntries[$propLc] ?? [];
        $entries = ReflectionSupport::filterEntriesByName($ctx, $allEntries, $filter, $flags);
        if ([] !== $entries) {
            return ReflectionSupport::attributesArrayFromEntries($frame, $entries);
        }

        $all = $entry->propertyAttributeNames[$propLc] ?? [];

        return ReflectionSupport::attributesArray(
            $frame,
            ReflectionSupport::filterByName($ctx, $all, $filter, $flags)
        );
    }

    public static function constantAttributes(
        Frame $frame,
        ClassEntry $entry,
        string $constLc,
        ?string $filter,
        int $flags = 0
    ): Variable {
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('AttributeRegistry requires active VM context');
        }
        $allEntries = $entry->constAttributeEntries[$constLc] ?? [];
        $entries = ReflectionSupport::filterEntriesByName($ctx, $allEntries, $filter, $flags);
        if ([] !== $entries) {
            return ReflectionSupport::attributesArrayFromEntries($frame, $entries);
        }

        $all = $entry->constAttributeNames[$constLc] ?? [];

        return ReflectionSupport::attributesArray(
            $frame,
            ReflectionSupport::filterByName($ctx, $all, $filter, $flags)
        );
    }

    public static function enumCaseAttributes(
        Frame $frame,
        ClassEntry $entry,
        string $caseLc,
        ?string $filter,
        int $flags = 0
    ): Variable {
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('AttributeRegistry requires active VM context');
        }
        $entries = $entry->enumCaseAttributeEntries[$caseLc] ?? [];

        return ReflectionSupport::attributesArrayFromEntries(
            $frame,
            ReflectionSupport::filterEntriesByName($ctx, $entries, $filter, $flags)
        );
    }
}
