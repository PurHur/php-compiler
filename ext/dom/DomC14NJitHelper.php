<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * DOMNode::C14N() helper for user-script AOT (#19467, #22378, #32962).
 *
 * Prefer {@see DomRegistry::entry()} when present; otherwise use the receiver (LiveSlots /
 * NestedJIT). Native {@see ?string} return (null = relative-NS false) — NestedJIT of
 * {@see \PHPCompiler\VM\Variable} returned `__object__*` and echo printed "Object" (#32962).
 * Peer string ABI: {@see DomXPathEvaluateJitHelper::evaluateStringArgv}.
 *
 * Pure loadXML user scripts prefer compile-time fold in {@see JitDomC14N} (empty NestedJIT
 * DomRegistry); this helper covers non-foldable receivers.
 */
final class DomC14NJitHelper
{
    public static function c14nArgv(Context $ctx, ObjectEntry $node, int $exclusive): ?string
    {
        $canonical = DomRegistry::entry($node->id) ?? $node;
        $payload = VmDom::c14n($ctx, $canonical, 0 !== $exclusive, false, null, null, null, 'DOMNode::C14N');
        if (false === $payload) {
            return null;
        }

        return $payload;
    }
}
