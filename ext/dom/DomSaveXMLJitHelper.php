<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** DOMDocument::saveXML() for compiled JIT/AOT modules (#18268, #18938). */
final class DomSaveXMLJitHelper
{
    public static function saveXMLArgv(Context $ctx, ObjectEntry $document, Variable $nodeVar): string
    {
        $node = null;
        $nodeVar = $nodeVar->resolveIndirect();
        if (Variable::TYPE_OBJECT === $nodeVar->type) {
            $object = $nodeVar->toObject();
            if (VmDom::isDomNode($object)) {
                $node = $object;
            }
        }

        return VmDom::saveXML($document, $node);
    }
}
