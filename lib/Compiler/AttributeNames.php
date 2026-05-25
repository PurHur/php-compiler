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
}
