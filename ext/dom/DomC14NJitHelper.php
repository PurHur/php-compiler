<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * DOMNode::C14N() / C14NFile() helpers for user-script AOT (#19467, #22378, #32962, #32964, #32973).
 *
 * Prefer {@see DomRegistry::entry()} when present; otherwise use the receiver (LiveSlots /
 * NestedJIT). Native {@see ?string} return (null = relative-NS false) — NestedJIT of
 * {@see \PHPCompiler\VM\Variable} returned `__object__*` and echo printed "Object" (#32962).
 *
 * Pure loadXML user scripts prefer compile-time fold in {@see JitDomC14N} / {@see JitDomC14NFile}.
 * After tree mutations, {@see JitDomLoadXMLUserScript::markTreeMutatedSinceLoad()} +
 * {@see JitDomLoadXMLUserScript::refreshCompileTimeXmlWithRootInner()} keep the fold source
 * current (#32972) — do not host-DOMDocument here (NestedJIT cannot lower loadXML correctly).
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
     * DOMNode::C14NFile() NestedJIT ABI (#32964 / #32973).
     *
     * Prefer {@see JitDomC14NFile} compile-time fold for loadXML / createElement.
     * DomRegistry / LiveSlots receivers use VmDom via active Context.
     *
     * @return int bytes written, or -1 on failure (false in PHP)
     */
    public static function c14nFileArgv(Context $ctx, ObjectEntry $node, string $uri, int $exclusive): int
    {
        $canonical = DomRegistry::entry($node->id) ?? $node;
        $bytes = VmDom::c14nFile(
            $ctx,
            $canonical,
            $uri,
            0 !== $exclusive,
            false,
            null,
            null,
            null
        );
        if (false === $bytes) {
            return -1;
        }

        return $bytes;
    }
}
