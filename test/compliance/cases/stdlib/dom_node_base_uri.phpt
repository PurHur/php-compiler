--TEST--
stdlib DOMNode baseURI property (#14453, ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><item/></root>');
$el = $doc->documentElement->firstChild;
echo property_exists($el, 'baseURI') ? "exists\n" : "missing\n";
$doc->documentURI = 'http://example.com/doc.xml';
echo $el->baseURI, "\n";
?>
--EXPECT--
exists
http://example.com/doc.xml
