<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/** DOMDocument::loadXML() for compiled JIT/AOT modules (#18268). */
final class DomLoadXMLJitHelper
{
    public static function loadXMLArgv(Context $ctx, ObjectEntry $document, string $xml): bool
    {
        return VmDom::loadXML($ctx, $document, $xml, null);
    }
}
