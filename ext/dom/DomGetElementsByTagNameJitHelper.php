<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * DOMDocument::getElementsByTagName() for compiled JIT/AOT modules (#18461).
 *
 * SSOT: {@see VmDom::getElementsByTagName()}
 * php-src: ext/dom/php_dom.c — dom_document_get_elements_by_tag_name
 */
final class DomGetElementsByTagNameJitHelper
{
    public static function getElementsByTagNameStringArgv(
        Context $ctx,
        ObjectEntry $document,
        string $tagName
    ): ObjectEntry {
        return VmDom::getElementsByTagName($ctx, $document, $tagName)->toObject();
    }
}
