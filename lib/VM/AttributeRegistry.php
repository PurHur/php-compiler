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
        $target = AttributeSupport::TARGET_CLASS;
        $entries = ReflectionSupport::filterEntriesByName($ctx, $entry->attributeEntries, $filter, $flags);
        if ([] !== $entries) {
            return ReflectionSupport::attributesArrayFromEntries($frame, $entries, $target);
        }

        return ReflectionSupport::attributesArray(
            $frame,
            ReflectionSupport::filterByName($ctx, $entry->attributeNames, $filter, $flags),
            $target
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
        $target = AttributeSupport::TARGET_METHOD;
        $methodLc = self::resolveMethodAttributeKey($entry, $methodLc);
        $allEntries = $entry->methodAttributeEntries[$methodLc] ?? [];
        $entries = ReflectionSupport::filterEntriesByName($ctx, $allEntries, $filter, $flags);
        if ([] !== $entries) {
            return ReflectionSupport::attributesArrayFromEntries($frame, $entries, $target);
        }

        $all = $entry->methodAttributeNames[$methodLc] ?? [];

        return ReflectionSupport::attributesArray(
            $frame,
            ReflectionSupport::filterByName($ctx, $all, $filter, $flags),
            $target
        );
    }

    /**
     * Map ReflectionProperty hook method names (`$prop::get`) to synthetic methods (#26328).
     */
    private static function resolveMethodAttributeKey(ClassEntry $entry, string $methodLc): string
    {
        $methodLc = strtolower($methodLc);
        if (isset($entry->methodAttributeEntries[$methodLc]) || isset($entry->methodAttributeNames[$methodLc])) {
            return $methodLc;
        }
        $resolved = \PHPCompiler\SourcePreprocessor\PropertyHooks::hookMethodFromReflectionName($methodLc);
        if (null !== $resolved
            && (isset($entry->methodAttributeEntries[$resolved]) || isset($entry->methodAttributeNames[$resolved]))
        ) {
            return $resolved;
        }

        return $methodLc;
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
        $target = AttributeSupport::TARGET_PROPERTY;
        $allEntries = $entry->propertyAttributeEntries[$propLc] ?? [];
        $entries = ReflectionSupport::filterEntriesByName($ctx, $allEntries, $filter, $flags);
        if ([] !== $entries) {
            return ReflectionSupport::attributesArrayFromEntries($frame, $entries, $target);
        }

        $all = $entry->propertyAttributeNames[$propLc] ?? [];

        return ReflectionSupport::attributesArray(
            $frame,
            ReflectionSupport::filterByName($ctx, $all, $filter, $flags),
            $target
        );
    }

    /**
     * @param string $constKey Class constant storage key — {@see \PHPCompiler\ClassConstName::key} (#25963)
     */
    public static function constantAttributes(
        Frame $frame,
        ClassEntry $entry,
        string $constKey,
        ?string $filter,
        int $flags = 0
    ): Variable {
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('AttributeRegistry requires active VM context');
        }
        $target = AttributeSupport::TARGET_CLASS_CONSTANT;
        $allEntries = $entry->constAttributeEntries[$constKey] ?? [];
        $entries = ReflectionSupport::filterEntriesByName($ctx, $allEntries, $filter, $flags);
        if ([] !== $entries) {
            return ReflectionSupport::attributesArrayFromEntries($frame, $entries, $target);
        }

        $all = $entry->constAttributeNames[$constKey] ?? [];

        return ReflectionSupport::attributesArray(
            $frame,
            ReflectionSupport::filterByName($ctx, $all, $filter, $flags),
            $target
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
            ReflectionSupport::filterEntriesByName($ctx, $entries, $filter, $flags),
            AttributeSupport::TARGET_CLASS_CONSTANT
        );
    }

    public static function functionAttributes(
        Frame $frame,
        ObjectEntry $reflection,
        ?string $filter,
        int $flags = 0
    ): Variable {
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('AttributeRegistry requires active VM context');
        }
        $target = AttributeSupport::TARGET_FUNCTION;
        if ($reflection->reflectionIsInternalFunction ?? false) {
            // php-src stub attributes on internals (e.g. xml_set_object #[\Deprecated]; #28172).
            $internal = ReflectionSupport::resolveFunctionForReflection(
                $ctx,
                ReflectionSupport::functionNameFromReflection($reflection)
            );
            if ($internal instanceof \PHPCompiler\Func\Internal && [] !== $internal->attributeEntries) {
                $entries = ReflectionSupport::filterEntriesByName(
                    $ctx,
                    $internal->attributeEntries,
                    $filter,
                    $flags
                );

                return ReflectionSupport::attributesArrayFromEntries($frame, $entries, $target);
            }

            return ReflectionSupport::attributesArray($frame, [], $target);
        }
        $func = ReflectionSupport::resolveFunctionFromReflection($ctx, $reflection);
        if (!$func instanceof \PHPCompiler\Func\PHP) {
            return ReflectionSupport::attributesArray($frame, [], $target);
        }
        $entries = ReflectionSupport::filterEntriesByName($ctx, $func->attributeEntries, $filter, $flags);
        if ([] !== $entries) {
            return ReflectionSupport::attributesArrayFromEntries($frame, $entries, $target);
        }

        return ReflectionSupport::attributesArray(
            $frame,
            ReflectionSupport::filterByName($ctx, $func->attributeNames, $filter, $flags),
            $target
        );
    }
}
