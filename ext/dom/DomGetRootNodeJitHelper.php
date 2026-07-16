<?php
declare(strict_types=1);
namespace PHPCompiler\ext\dom;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
final class DomGetRootNodeJitHelper
{
    public static function getRootNodeArgv(Context $ctx, ObjectEntry $node): ObjectEntry
    {
        unset($ctx);
        $canonical = DomRegistry::entry($node->id) ?? $node;
        return VmDom::getRootNode($canonical);
    }
}
