<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\ObjectEntry;

/**
 * DOMNode::$isConnected for JIT/AOT property reads (php-src ext/dom/node.c; #19653).
 *
 * Returns int 0/1 — nested helper bridges avoid bool/native ABI friction.
 */
final class DomIsConnectedJitHelper
{
    public static function isConnectedArgv(ObjectEntry $node): int
    {
        return VmDom::isConnected($node) ? 1 : 0;
    }
}
