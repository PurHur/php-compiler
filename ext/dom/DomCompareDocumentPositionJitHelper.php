<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/** DOMNode::compareDocumentPosition() NestedJIT bridge (#25878). */
final class DomCompareDocumentPositionJitHelper
{
    public static function compareDocumentPositionArgv(Context $ctx, ObjectEntry $node, ObjectEntry $other): int
    {
        return VmDom::compareDocumentPosition($node, $other);
    }
}
