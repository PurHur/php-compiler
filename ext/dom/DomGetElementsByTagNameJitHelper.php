<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * DOMDocument::getElementsByTagName() for compiled JIT/AOT modules (#18461).
 *
 * SSOT: {@see VmDom::getElementsByTagName()}
 * php-src: ext/dom/php_dom.c — dom_document_get_elements_by_tag_name
 */
final class DomGetElementsByTagNameJitHelper
{
    public static function getElementsByTagNameArgv(
        Context $ctx,
        ObjectEntry $document,
        Variable $tagName
    ): ObjectEntry {
        $tagName = $tagName->resolveIndirect();
        if (Variable::TYPE_STRING !== $tagName->type && Variable::TYPE_NULL !== $tagName->type) {
            throw new \TypeError(\sprintf(
                'DOMDocument::getElementsByTagName() expects argument #1 to be of type string, %s given',
                VmDom::typeLabel($tagName)
            ));
        }
        $name = Variable::TYPE_NULL === $tagName->type ? '' : $tagName->toString($ctx);

        return VmDom::getElementsByTagName($ctx, $document, $name)->toObject();
    }
}
