<?php
declare(strict_types=1);
namespace PHPCompiler\ext\dom;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
/** DOMNode::getRootNode() AOT (#19507). no unset(). */
final class DomGetRootNodeJitHelper
{
    public static function getRootNodeArgv(Context $ctx, ObjectEntry $node): ObjectEntry
    {
        return VmDom::getRootNode($node);
    }
}
