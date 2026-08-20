<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * DOMNode::C14NFile() helper for user-script AOT (#32964).
 *
 * Peer {@see DomC14NJitHelper}: resolve DomRegistry before VmDom.
 * Returns bytes written, or -1 when Zend would return false.
 */
final class DomC14NFileJitHelper
{
    public static function c14nFileArgv(
        Context $ctx,
        ObjectEntry $node,
        string $uri,
        int $exclusive
    ): int {
        $canonical = DomRegistry::entry($node->id);
        if (null === $canonical) {
            return -1;
        }
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
