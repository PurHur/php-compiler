<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\ObjectEntry;

/**
 * DOMDocument::getElementById() for compiled JIT/AOT modules (#17954).
 *
 * SSOT: {@see VmDom::getElementById()}
 * php-src: ext/dom/php_dom.c — dom_document_get_element_by_id
 */
final class DomGetElementByIdJitHelper
{
    public static function getElementByIdArgv(ObjectEntry $document, string $elementId): ?ObjectEntry
    {
        return VmDom::getElementById($document, $elementId);
    }
}
