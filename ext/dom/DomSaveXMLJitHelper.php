<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\ObjectEntry;

/** DOMDocument::saveXML() for compiled JIT/AOT modules (#18268). */
final class DomSaveXMLJitHelper
{
    public static function saveXMLArgv(ObjectEntry $document): string
    {
        return VmDom::saveXML($document, null);
    }
}
