<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\ObjectEntry;

/** DOMNode::$firstChild / $lastChild for user-script AOT live tree reads (#18951). */
final class DomNodeChildPropertyJitHelper
{
    public static function firstChildArgv(ObjectEntry $node): ?ObjectEntry
    {
        return self::childArgv($node, true);
    }

    public static function lastChildArgv(ObjectEntry $node): ?ObjectEntry
    {
        return self::childArgv($node, false);
    }

    public static function firstChildByIdArgv(int $nodeId): ?ObjectEntry
    {
        $node = DomRegistry::entry($nodeId);

        return null === $node ? null : self::childArgv($node, true);
    }

    public static function lastChildByIdArgv(int $nodeId): ?ObjectEntry
    {
        $node = DomRegistry::entry($nodeId);

        return null === $node ? null : self::childArgv($node, false);
    }

    private static function childArgv(ObjectEntry $node, bool $first): ?ObjectEntry
    {
        $node = DomRegistry::entry($node->id) ?? $node;
        if (!DomRegistry::has($node)) {
            return null;
        }
        $state = DomRegistry::state($node);
        $childIds = $state->childIds;
        if ([] === $childIds) {
            return null;
        }
        $childId = $first ? $childIds[0] : $childIds[\count($childIds) - 1];

        return DomRegistry::entry($childId);
    }
}
