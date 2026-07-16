<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * DOMNode::C14N() helper for user-script AOT (#19467).
 *
 * Receivers from user-script AOT are often shadow ObjectEntries (id not in DomRegistry).
 * Resolve via {@see DomRegistry::entry()} before calling VmDom — never pass an unregistered
 * shadow into nested-compiled VmDom::c14n (corrupt string / abort).
 */
final class DomC14NJitHelper
{
    public static function c14nArgv(Context $ctx, ObjectEntry $node, int $exclusive): string
    {
        $canonical = DomRegistry::entry($node->id);
        if (null === $canonical) {
            return '';
        }
        $payload = VmDom::c14n($ctx, $canonical, 0 !== $exclusive, false, null, null);

        return false === $payload ? '' : $payload;
    }
}
