<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\ObjectEntry;

/**
 * json_encode() wire for DOM objects (php-src ext/dom/php_dom.c, ext/json/php_json_encoder.c; #18292).
 *
 * Zend serializes only public properties with backing storage. PHP-in-PHP DOM exposes computed
 * node-graph properties (documentElement, childNodes, …); walking them hits recursion.
 */
final class DomJsonExport
{
    public static function handles(ObjectEntry $object): bool
    {
        if (VmDom::isDomNode($object)
            || VmDom::isNodeList($object)
            || VmDom::isNamedNodeMap($object)) {
            return true;
        }
        $lc = strtolower($object->class->name);

        return VmDom::CLASS_IMPLEMENTATION === $lc
            || VmDom::CLASS_XPATH === $lc
            || VmDom::CLASS_TOKEN_LIST === $lc;
    }

    /**
     * php-src ext/dom/tests/DOMDocument_json_encode.phpt — DOMDocument encodes as {}.
     */
    public static function exportZendJsonWire(ObjectEntry $object): \stdClass
    {
        return new \stdClass();
    }
}
