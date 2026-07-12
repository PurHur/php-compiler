--TEST--
DOMNamedNodeMap::getNamedItemNS() namespace attribute lookup (#17515, ext/dom/namednodemap.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root xmlns:ex="http://example.com"><ex:item ex:id="1" ex:label="x"/></root>');
$el = $doc->documentElement->firstChild;
$attrs = $el->attributes;
$attr = $attrs->getNamedItemNS('http://example.com', 'id');
echo $attr->value, "\n";
$missing = $attrs->getNamedItemNS('http://example.com', 'nope');
var_dump($missing);
$label = $attrs->getNamedItemNS('http://example.com', 'label');
echo $label->value, "\n";
?>
--EXPECT--
1
NULL
x
