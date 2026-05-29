<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;

/**
 * Extract declared PHP 8 attribute metadata from CFG op attrGroups (#1936, #3206).
 */
final class AttributeMetadata
{
    /**
     * @return list<AttributeEntry>
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
     * @return list<AttributeEntry>
     */
    public static function fromAttrGroups(array $groups): array
    {
        $entries = [];
        foreach ($groups as $group) {
            foreach ($group->attrs as $attr) {
                $args = [];
                foreach ($attr->args as $arg) {
                    $args[] = AttributeConstantEvaluator::evalArg($arg);
                }
                $entries[] = new AttributeEntry($attr->name->toString(), $args);
            }
        }

        return $entries;
    }
}
