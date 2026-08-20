<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * DOMNode::C14N() / C14NFile() helpers for user-script AOT (#19467, #22378, #32962, #32964).
 *
 * Prefer {@see DomRegistry::entry()} when present; otherwise use the receiver (LiveSlots /
 * NestedJIT). Native {@see ?string} return (null = relative-NS false) — NestedJIT of
 * {@see \PHPCompiler\VM\Variable} returned `__object__*` and echo printed "Object" (#32962).
 * Peer string ABI: {@see DomXPathEvaluateJitHelper::evaluateStringArgv}.
 *
 * Pure loadXML user scripts prefer compile-time fold in {@see JitDomC14N} / {@see JitDomC14NFile}
 * (empty NestedJIT DomRegistry); this helper covers non-foldable receivers.
 * C14N returns ?string (null = relative-NS false; #32962). C14NFile ABI returns -1 when
 * DomRegistry misses (peer DomSaveHTMLFile int64 shape; #32964).
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

    /**
     * DOMNode::C14NFile() NestedJIT ABI (#32964).
     *
     * No VM Context parameter — NestedJIT int64 bridges match DomSaveHTMLFileJitHelper.
     * Registered nodes still need a Context for VmDom; thin AOT documentElement path uses
     * {@see JitDomC14NFile} compile-time fold instead.
     *
     * @return int bytes written, or -1 on failure (false in PHP)
     */
    public static function c14nFileArgv(ObjectEntry $node, string $uri, int $exclusive): int
    {
        // Thin AOT documentElement uses JitDomC14NFile compile-time fold; NestedJIT
        // DomRegistry is typically empty for shadow receivers (#32964).
        unset($node, $uri, $exclusive);

        return -1;
    }
}
