<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\VmString;
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
    public static function getElementByIdArgv(ObjectEntry $document, Variable $elementId): ?ObjectEntry
    {
        $id = VmString::coerceStringBuiltinArg(
            $elementId->resolveIndirect(),
            'DOMDocument::getElementById()',
            0,
            'elementId'
        );

        return VmDom::getElementById($document, $id);
    }
}
