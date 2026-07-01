--TEST--
stdlib DOM namespace attribute API (#14313, ext/dom/php_dom.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root xmlns:ex="http://example.com"><ex:item ex:attr="v"/></root>');
$el = $doc->documentElement->firstChild;
echo $el->getAttributeNS('http://example.com', 'attr'), "\n";
echo $el->hasAttributeNS('http://example.com', 'attr') ? "has\n" : "nohas\n";
echo $el->lookupPrefix('http://example.com'), "\n";
echo $el->lookupNamespaceURI('ex'), "\n";
$el->setAttributeNS('http://example.com', 'ex:newattr', 'new');
echo $el->getAttributeNS('http://example.com', 'newattr'), "\n";
?>
--EXPECT--
v
has
ex
http://example.com
new
