<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Runtime + shared abstract property-hook obligation checks (#6983, zend_property_hooks.c).
 */
final class AbstractPropertyHookCheck
{
    /**
     * @return list<array{0: string, 1: string}> owner display, "$prop::hookKind"
     */
    public static function missingForClass(ClassEntry $entry, Context $context): array
    {
        if ($entry->isAbstract || $entry->isInterface || $entry->isTrait) {
            return [];
        }

        $provided = self::classProvidedPropertyHooks($entry, $context);
        $requirements = self::collectRequirements($entry, $context);
        $missing = [];
        $seen = [];
        foreach ($requirements as [$ownerDisplay, $propDisplay, $hookKind]) {
            $propLc = strtolower($propDisplay);
            if (self::propertyHookKindProvided($provided[$propLc] ?? [], $hookKind)) {
                continue;
            }
            $label = '$'.$propDisplay.'::'.$hookKind;
            $key = $ownerDisplay.'::'.$label;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $missing[] = [$ownerDisplay, $label];
        }

        return $missing;
    }

    public static function isAbstractHookProperty(
        ClassEntry $declaringClass,
        ClassProperty $prop,
        Context $context
    ): bool {
        $lc = strtolower($declaringClass->name);
        $propMeta = $context->propertyHookRegistry[$lc][$prop->name]
            ?? $context->propertyHookRegistry[$lc][strtolower($prop->name)]
            ?? null;
        if (!is_array($propMeta)) {
            return false;
        }
        if (!empty($propMeta['requiresGet']) && null === $prop->getHookMethodLc) {
            return true;
        }
        if (!empty($propMeta['requiresSet']) && null === $prop->setHookMethodLc) {
            return true;
        }
        if (!empty($propMeta['requiresUnset']) && null === $prop->unsetHookMethodLc) {
            return true;
        }

        return false;
    }

    /**
     * Walk CE chain from $entry (including entry not yet in context->classes).
     *
     * @return array<string, array<string, true>> lcProp => hook kind => true
     */
    private static function classProvidedPropertyHooks(ClassEntry $entry, Context $context): array
    {
        $provided = [];
        $visited = [];
        $current = $entry;
        while (null !== $current && !isset($visited[strtolower($current->name)])) {
            $visited[strtolower($current->name)] = true;
            $lc = strtolower($current->name);
            foreach ($context->propertyHookRegistry[$lc] ?? [] as $prop => $meta) {
                if (!is_array($meta)) {
                    continue;
                }
                $propLc = strtolower($prop);
                if (!isset($provided[$propLc])) {
                    $provided[$propLc] = [];
                }
                foreach (['get', 'set', 'unset'] as $kind) {
                    if (isset($meta[$kind])) {
                        $provided[$propLc][$kind] = true;
                    }
                }
            }
            foreach ($current->properties as $prop) {
                $propLc = strtolower($prop->name);
                if (!isset($provided[$propLc])) {
                    $provided[$propLc] = [];
                }
                if (null !== $prop->getHookMethodLc) {
                    $provided[$propLc]['get'] = true;
                }
                if (null !== $prop->setHookMethodLc) {
                    $provided[$propLc]['set'] = true;
                }
                if (null !== $prop->unsetHookMethodLc) {
                    $provided[$propLc]['unset'] = true;
                }
                self::markImplicitBackingFieldHooks($provided, $propLc, $lc, $prop, $context);
            }
            $parentLc = $current->parentLc;
            $current = null !== $parentLc && isset($context->classes[$parentLc])
                ? $context->classes[$parentLc]
                : null;
        }

        return $provided;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}> owner display, prop display, hook kind
     */
    private static function collectRequirements(ClassEntry $entry, Context $context): array
    {
        $requirements = [];
        $visited = [];
        $current = $entry;
        while (null !== $current && !isset($visited[strtolower($current->name)])) {
            $visited[strtolower($current->name)] = true;
            $lc = strtolower($current->name);
            foreach ($context->propertyHookRegistry[$lc] ?? [] as $prop => $meta) {
                if (!is_array($meta)) {
                    continue;
                }
                foreach (self::requiredHookKinds($meta) as $hookKind) {
                    $requirements[] = [$current->name, $prop, $hookKind];
                }
            }
            $parentLc = $current->parentLc;
            $current = null !== $parentLc && isset($context->classes[$parentLc])
                ? $context->classes[$parentLc]
                : null;
        }

        return $requirements;
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return list<string>
     */
    private static function requiredHookKinds(array $meta): array
    {
        $kinds = [];
        if (!empty($meta['requiresGet'])) {
            $kinds[] = 'get';
        }
        if (!empty($meta['requiresSet'])) {
            $kinds[] = 'set';
        }
        if (!empty($meta['requiresUnset'])) {
            $kinds[] = 'unset';
        }

        return $kinds;
    }

    /**
     * @param array<string, true> $provided
     */
    private static function propertyHookKindProvided(array $provided, string $kind): bool
    {
        return isset($provided[$kind]);
    }

    /**
     * Plain typed property on a concrete class satisfies interface / inherited { get; } / { set; } (#7311).
     *
     * @param array<string, array<string, true>> $provided
     */
    private static function markImplicitBackingFieldHooks(
        array &$provided,
        string $propLc,
        string $classLc,
        ClassProperty $prop,
        Context $context
    ): void {
        if (null !== $prop->getHookMethodLc || null !== $prop->setHookMethodLc || null !== $prop->unsetHookMethodLc) {
            return;
        }
        $meta = $context->propertyHookRegistry[$classLc][$prop->name]
            ?? $context->propertyHookRegistry[$classLc][$propLc]
            ?? null;
        if (is_array($meta) && self::metaHasUnimplementedRequiredHooks($meta)) {
            return;
        }
        $provided[$propLc]['get'] = true;
        $provided[$propLc]['set'] = true;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private static function metaHasUnimplementedRequiredHooks(array $meta): bool
    {
        if (!empty($meta['get']) || !empty($meta['set']) || !empty($meta['unset'])) {
            return false;
        }

        return !empty($meta['requiresGet']) || !empty($meta['requiresSet']) || !empty($meta['requiresUnset']);
    }
}
