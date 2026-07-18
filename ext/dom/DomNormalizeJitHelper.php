<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\ObjectEntry;

/** DOMNode::normalize() / DOMDocument::normalizeDocument() — user-script AOT (#20642). */
final class DomNormalizeJitHelper
{
    public static function normalizeArgv(ObjectEntry $node): void
    {
        $ctx = VmDomJitFrame::vmContext();
        $canonical = DomRegistry::entry($node->id) ?? $node;
        VmDom::normalizeLiveStandard($ctx, $canonical);
        if ($canonical !== $node) {
            VmDom::mirrorNodeLinkProperties($node, $canonical);
        }
    }

    public static function normalizeDocumentArgv(ObjectEntry $document): void
    {
        VmDom::normalizeDocument(VmDomJitFrame::vmContext(), $document);
    }
}
