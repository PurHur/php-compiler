--TEST--
dom_import_simplexml() preserves namespaceURI / localName / prefix (#22738)
--FILE--
<?php
$sxe = new SimpleXMLElement('<a:r xmlns:a="urn:a"><a:c>1</a:c></a:r>');
$node = dom_import_simplexml($sxe);
echo $node->namespaceURI, '|', $node->localName, '|', $node->prefix, '|', $node->tagName, "\n";

foreach ($sxe->children('urn:a') as $childSxe) {
    $child = dom_import_simplexml($childSxe);
    echo $child->namespaceURI, '|', $child->localName, '|', $child->prefix, "\n";
    break;
}

$sxeDef = new SimpleXMLElement('<r xmlns="urn:def"><c>1</c></r>');
$def = dom_import_simplexml($sxeDef);
echo $def->namespaceURI, '|', $def->localName, '|', var_export($def->prefix, true), "\n";
$defChild = dom_import_simplexml($sxeDef->c);
echo $defChild->namespaceURI, '|', $defChild->localName, "\n";
?>
--EXPECT--
urn:a|r|a|a:r
urn:a|c|a
urn:def|r|''
urn:def|c
