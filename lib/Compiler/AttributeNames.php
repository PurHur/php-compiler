<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;

/**
 * Extract declared PHP 8 attribute class names from CFG op metadata (#1936).
 */
final class AttributeNames
{
    /**
     * @return list<string> Fully-qualified attribute names as written in source.
     */
    public static function fromOp(Op $op): array
    {
        if (!$op->hasAttribute('attrGroups')) {
            return [];
        }
        $groups = $op->getAttribute('attrGroups');
        if (!\is_array($groups)) {
            return [];
        }

        return self::fromAttrGroups($groups);
    }

    /**
     * @param list<\PhpParser\Node\AttributeGroup> $groups
     *
     * @return list<string>
     */
    public static function fromAttrGroups(array $groups): array
    {
        $names = [];
        foreach ($groups as $group) {
            foreach ($group->attrs as $attr) {
                $names[] = $attr->name->toString();
            }
        }

        return $names;
    }

    /** True when `#[\AllowDynamicProperties]` is present (#3467). */
    public static function hasAllowDynamicProperties(array $attributeNames): bool
    {
        foreach ($attributeNames as $name) {
            if ('AllowDynamicProperties' === ltrim($name, '\\')) {
                return true;
            }
        }

        return false;
    }

    /** True when `#[\Override]` is present (#6864). */
    public static function hasOverride(array $names): bool
    {
        foreach ($names as $name) {
            $normalized = strtolower(ltrim($name, '\\'));
            if ('override' === $normalized || str_ends_with($normalized, '\\override')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zend compile-time target guard (zend_attributes.c, issue #6864).
     * `#[\Override]` is only valid on methods (Attribute::TARGET_METHOD).
     *
     * @param list<string> $names
     */
    public static function assertOverrideMethodTargetOnly(array $names, string $target): void
    {
        if (!self::hasOverride($names)) {
            return;
        }

        throw new \CompileError(
            'Attribute "'.self::messageName('Override').'" cannot target '.$target.' (allowed targets: method)'
        );
    }

    /**
     * Zend compile-time target guard (zend_attributes.c, issue #5137).
     * `#[\AllowDynamicProperties]` is only valid on classes.
     *
     * @param list<string> $names
     */
    public static function assertAllowDynamicPropertiesClassTargetOnly(array $names, string $target): void
    {
        if (!self::hasAllowDynamicProperties($names)) {
            return;
        }

        throw new \CompileError(
            'Attribute "'.self::messageName('AllowDynamicProperties').'" cannot target '.$target.' (allowed targets: class)'
        );
    }

    /**
     * Zend compile-time guard (zend_compile.c, issue #7299).
     * `#[\AllowDynamicProperties]` and `readonly class` are mutually exclusive.
     *
     * @param list<string> $names
     */
    public static function assertAllowDynamicPropertiesNotOnReadonlyClass(array $names, string $classDisplay): void
    {
        if (!self::hasAllowDynamicProperties($names)) {
            return;
        }

        throw new \CompileError(
            'Cannot apply #[AllowDynamicProperties] to readonly class '.$classDisplay
        );
    }

    /**
     * Zend compile-time guard (zend_compile.c, php-src GH-15731, issue #9734).
     * `#[\AllowDynamicProperties]` has no meaning on enums.
     *
     * @param list<string> $names
     */
    public static function assertAllowDynamicPropertiesNotOnEnum(array $names, string $enumDisplay): void
    {
        if (!self::hasAllowDynamicProperties($names)) {
            return;
        }

        throw new \CompileError(
            'Cannot apply #[AllowDynamicProperties] to enum '.$enumDisplay
        );
    }

    /** PHP 8.2 #[\SensitiveParameter] on parameters (issue #3351, Zend zend_attributes.c). */
    public static function isSensitiveParameter(array $names): bool
    {
        foreach ($names as $name) {
            if ('SensitiveParameter' === $name || str_ends_with($name, '\\SensitiveParameter')) {
                return true;
            }
        }

        return false;
    }

    /** PHP 8.4+ #[\NoDiscard] on functions/methods (issue #5078, Zend zend_attributes.c). */
    public static function hasNoDiscard(array $names): bool
    {
        foreach ($names as $name) {
            $base = ltrim($name, '\\');
            if ('NoDiscard' === $base || str_ends_with($base, '\\NoDiscard')) {
                return true;
            }
        }

        return false;
    }

    /** PHP 8.4+ #[\CompileTime] on constants (issue #7300, Zend zend_attributes.c). */
    public static function hasCompileTime(array $names): bool
    {
        foreach ($names as $name) {
            $base = ltrim($name, '\\');
            if ('CompileTime' === $base || str_ends_with($base, '\\CompileTime')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zend compile-time target guard (zend_attributes.c, issue #7300).
     * `#[\CompileTime]` is only valid on global and class constants.
     *
     * @param list<string> $names
     */
    public static function assertCompileTimeConstTargetOnly(array $names, string $target): void
    {
        if (!self::hasCompileTime($names)) {
            return;
        }

        if ('constant' === $target || 'class constant' === $target) {
            return;
        }

        throw new \CompileError(
            'Attribute "'.self::messageName('CompileTime').'" cannot target '.$target.' (allowed targets: class constant, constant)'
        );
    }

    /**
     * Zend compile-time duplicate guard (zend_compile.c, zend_is_attribute_repeated) (#3718, #6912).
     *
     * Allows duplicates when the attribute class declares Attribute::IS_REPEATABLE; marks
     * all instances of a repeated name with {@see AttributeEntry::$isRepeated}.
     *
     * @param list<AttributeEntry> $entries
     *
     * @return list<AttributeEntry>
     */
    public static function validateDuplicates(array $entries, AttributeClassRegistry $registry): array
    {
        $counts = [];
        foreach ($entries as $entry) {
            if (!$entry instanceof AttributeEntry) {
                continue;
            }
            $key = strtolower(ltrim($entry->name, '\\'));
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $result = [];
        foreach ($entries as $entry) {
            if (!$entry instanceof AttributeEntry) {
                continue;
            }
            $key = strtolower(ltrim($entry->name, '\\'));
            if ($counts[$key] > 1) {
                if (!$registry->isRepeatable($entry->name)) {
                    throw new \CompileError(
                        'Attribute "'.self::messageName($entry->name).'" must not be repeated'
                    );
                }
                $result[] = new AttributeEntry($entry->name, $entry->args, true);
            } else {
                $result[] = $entry;
            }
        }

        return $result;
    }

    /**
     * @param list<string> $names
     *
     * @deprecated Use {@see validateDuplicates()} with {@see AttributeClassRegistry}.
     */
    public static function assertNoDuplicates(array $names): void
    {
        $registry = new AttributeClassRegistry();
        $entries = [];
        foreach ($names as $name) {
            $entries[] = new AttributeEntry($name);
        }
        self::validateDuplicates($entries, $registry);
    }

    private static function messageName(string $name): string
    {
        $name = ltrim($name, '\\');
        $pos = strrpos($name, '\\');

        return false !== $pos ? substr($name, $pos + 1) : $name;
    }
}
