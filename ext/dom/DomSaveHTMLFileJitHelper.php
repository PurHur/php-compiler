<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\ObjectEntry;

/** DOMDocument::saveHTMLFile() for compiled JIT/AOT modules (#18268). */
final class DomSaveHTMLFileJitHelper
{
    public static function saveHTMLFileArgv(ObjectEntry $document, string $filename): int
    {
        return VmDom::saveHTMLFile($document, $filename);
    }
}
