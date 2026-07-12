<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\ObjectEntry;

/** DOMDocument::saveHTML() for compiled JIT/AOT modules (#18268). */
final class DomSaveHTMLJitHelper
{
    public static function saveHTMLArgv(ObjectEntry $document): string
    {
        return VmDom::saveHTML($document, null);
    }
}
