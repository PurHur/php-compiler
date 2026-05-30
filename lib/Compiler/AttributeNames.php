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

    /**
     * Zend compile-time duplicate guard (zend_compile.c, zend_is_attribute_repeated) (#3718).
     *
     * @param list<string> $names
     */
    public static function assertNoDuplicates(array $names): void
    {
        $seen = [];
        foreach ($names as $name) {
            $key = strtolower(ltrim($name, '\\'));
            if (isset($seen[$key])) {
                throw new \CompileError(
                    'Attribute "'.self::messageName($name).'" must not be repeated'
                );
            }
            $seen[$key] = true;
        }
    }

    private static function messageName(string $name): string
    {
        $name = ltrim($name, '\\');
        $pos = strrpos($name, '\\');

        return false !== $pos ? substr($name, $pos + 1) : $name;
    }
}
