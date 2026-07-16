<?php
declare(strict_types=1);
namespace PHPCompiler\ext\dom;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
/** DOMNode::contains() AOT (#19507). int 0/1 — no unset() (orphaned GEP). */
final class DomContainsJitHelper
{
    public static function containsArgv(Context $ctx, ObjectEntry $node, ObjectEntry $other): int
    {
        return VmDom::contains($node, $other) ? 1 : 0;
    }
}
