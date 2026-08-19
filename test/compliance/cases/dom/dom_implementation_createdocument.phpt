--TEST--
stdlib DOMImplementation::createDocument matches Zend xmlNewDoc (#32531, ext/dom/php_dom.c)
--FILE--
<?php
$impl = new DOMImplementation();
$doc = $impl->createDocument(null, 'root');
echo $doc->documentElement->tagName, '|';
$ns = $impl->createDocument('http://example.com/ns', 'ex:root');
echo $ns->documentElement->tagName, '|';
echo $ns->documentElement->namespaceURI, "\n";
--EXPECT--
root|ex:root|http://example.com/ns
