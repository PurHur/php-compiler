<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * DOMDocument::getElementById() for compiled JIT/AOT modules (#17954).
 *
 * SSOT: {@see VmDom::getElementById()}
 * php-src: ext/dom/php_dom.c — dom_document_get_element_by_id
 */
final class DomGetElementByIdJitHelper
{
    public static function getElementByIdArgv(Context $ctx, ObjectEntry $document, Variable $elementId): ?ObjectEntry
    {
        if (Variable::TYPE_STRING !== $elementId->type) {
            return null;
        }

        return VmDom::getElementById($document, $elementId->toString($ctx));
    }
}
