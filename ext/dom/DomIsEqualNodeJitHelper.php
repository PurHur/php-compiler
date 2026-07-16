<?php
declare(strict_types=1);
namespace PHPCompiler\ext\dom;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
/** DOMNode::isEqualNode() AOT (#19507). int 0/1 — no unset(). */
final class DomIsEqualNodeJitHelper
{
    public static function isEqualNodeArgv(Context $ctx, ObjectEntry $node, ObjectEntry $other): int
    {
        return VmDom::isEqualNode($node, $other) ? 1 : 0;
    }
}
