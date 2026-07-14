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

    private static function childArgv(ObjectEntry $node, bool $first): ?ObjectEntry
    {
        if (!DomRegistry::has($node)) {
            return null;
        }
        $childIds = DomRegistry::state($node)->childIds;
        if ([] === $childIds) {
            return null;
        }
        $childId = $first ? $childIds[0] : $childIds[\count($childIds) - 1];

        return DomRegistry::entry($childId);
    }
}
