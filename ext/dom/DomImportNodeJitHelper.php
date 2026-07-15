<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * DOMDocument::importNode() for compiled JIT/AOT modules (#19212).
 *
 * SSOT: {@see VmDom::importNode()}
 * php-src: ext/dom/php_dom.c — dom_document_import_node
 */
final class DomImportNodeJitHelper
{
    public static function importNodeArgv(
        Context $ctx,
        ObjectEntry $document,
        ObjectEntry $node,
        int $deep
    ): ObjectEntry {
        return VmDom::importNode($ctx, $document, $node, 0 !== $deep)->toObject();
    }

    public static function getAttributeArgv(ObjectEntry $element, string $name): string
    {
        return VmDom::getAttributeNS($element, null, $name);
    }
}
