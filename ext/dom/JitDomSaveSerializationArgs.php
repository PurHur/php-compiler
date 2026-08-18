<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;

/**
 * DOMDocument::saveXML/saveHTML — ?DOMNode $node, int $options (php-src ext/dom/document.c).
 *
 * Named `$options` with omitted `$node` may arrive undensified as arg #1 (#25182, #32018).
 */
final class JitDomSaveSerializationArgs
{
    /**
     * @param list<JITVariable> $args receiver at [0], user args from [1]
     *
     * @return array{0: ?JITVariable, 1: ?JITVariable} node (null = document-wide), options (null = default 0)
     */
    public static function parse(array $args): array
    {
        $node = null;
        $options = null;

        $hasNodeSlot = isset($args[1]);
        $hasOptSlot = isset($args[2]);

        if ($hasNodeSlot && !NamedOptionalCallArgs::isOmittedOptional($args[1])) {
            if (self::isDomNodeArgType($args[1])) {
                $node = $args[1];
                if ($hasOptSlot && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
                    $options = $args[2];
                }
            } else {
                // `saveXML(options: …)` without densified omitted $node (#32018).
                $options = $args[1];
            }
        } elseif ($hasOptSlot && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            $options = $args[2];
        }

        return [$node, $options];
    }

    public static function isNodeScoped(?JITVariable $node): bool
    {
        if (null === $node) {
            return false;
        }
        if (NamedOptionalCallArgs::isOmittedOptional($node)) {
            return false;
        }

        return JITVariable::TYPE_NULL !== $node->type && !($node->isNullConstant ?? false);
    }

    private static function isDomNodeArgType(JITVariable $arg): bool
    {
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return true;
        }

        return \in_array($arg->type, [JITVariable::TYPE_OBJECT, JITVariable::TYPE_VALUE], true);
    }
}
