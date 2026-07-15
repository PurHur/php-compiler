--TEST--
DOMAttr::$value write updates Element::getAttribute (live Attr; #19281)
--FILE--
<?php
$d = new DOMDocument();
$e = $d->createElement('a');
$d->appendChild($e);
$e->setAttribute('x', '1');
$attr = $e->getAttributeNode('x');
$attr->value = '2';
echo 'getAttribute=', $e->getAttribute('x'), ' attr.value=', $attr->value, "\n";
$attr->nodeValue = '3';
echo 'getAttribute=', $e->getAttribute('x'), ' attr.nodeValue=', $attr->nodeValue, "\n";
$attrs = $e->attributes;
echo 'NamedNodeMap=', $attrs->getNamedItem('x')->value, "\n";
?>
--EXPECT--
getAttribute=2 attr.value=2
getAttribute=3 attr.nodeValue=3
NamedNodeMap=3
