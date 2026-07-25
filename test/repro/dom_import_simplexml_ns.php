<?php
// #22738 — dom_import_simplexml must preserve namespaceURI / localName / prefix
// (php-src ext/dom/php_dom.c + shared libxml node from SimpleXML).
$sxe = new SimpleXMLElement('<a:r xmlns:a="urn:a"><a:c>1</a:c></a:r>');
$node = dom_import_simplexml($sxe);
echo 'ns=', var_export($node->namespaceURI, true), "\n";
echo 'local=', var_export($node->localName, true), "\n";
echo 'prefix=', var_export($node->prefix, true), "\n";
echo 'tag=', var_export($node->tagName, true), "\n";

foreach ($sxe->children('urn:a') as $childSxe) {
    $child = dom_import_simplexml($childSxe);
    echo 'child_ns=', var_export($child->namespaceURI, true), "\n";
    echo 'child_local=', var_export($child->localName, true), "\n";
    echo 'child_prefix=', var_export($child->prefix, true), "\n";
    break;
}

$sxeDef = new SimpleXMLElement('<r xmlns="urn:def"><c>1</c></r>');
$def = dom_import_simplexml($sxeDef);
echo 'def_ns=', var_export($def->namespaceURI, true), "\n";
echo 'def_local=', var_export($def->localName, true), "\n";
echo 'def_prefix=', var_export($def->prefix, true), "\n";
$defChild = dom_import_simplexml($sxeDef->c);
echo 'def_child_ns=', var_export($defChild->namespaceURI, true), "\n";
echo 'def_child_local=', var_export($defChild->localName, true), "\n";
