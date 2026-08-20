<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * DOMElement::insertAdjacentElement/Text for compiled JIT/AOT modules.
 *
 * Generic {@see VmDomInstanceInvoke} NestedJIT aborts in thin-standalone AOT
 * (helper-runtime cache of VmDomInstanceInvoke is stale for new match arms).
 *
 * php-src: ext/dom/php_dom.c php_dom_insert_adjacent
 *          ext/dom/element.c PHP_METHOD(DOMElement, insertAdjacentText)
 */
final class DomInsertAdjacentJitHelper
{
    public static function insertAdjacentElementArgv(
        Context $ctx,
        ObjectEntry $element,
        string $where,
        ObjectEntry $node
    ): void {
        VmDom::insertAdjacentElement($ctx, $element, $where, $node);
    }

    public static function insertAdjacentTextArgv(
        Context $ctx,
        ObjectEntry $element,
        string $where,
        string $data
    ): void {
        VmDom::insertAdjacentText($ctx, $element, $where, $data);
    }
}
