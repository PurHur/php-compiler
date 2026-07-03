--TEST--
stdlib DOMDocument::createAttributeNS() namespaced attribute factory (#15253, ext/dom/document.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root/>');
$attr = $doc->createAttributeNS('http://example.com', 'ex:foo');
echo get_class($attr), "\n";
echo $attr->nodeName, "\n";
echo $attr->namespaceURI, "\n";
echo $attr->localName, "\n";
echo $attr->prefix, "\n";
$attr->value = 'v';
echo $attr->value, "\n";
?>
--EXPECT--
DOMAttr
ex:foo
http://example.com
foo
ex
v
