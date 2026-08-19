--TEST--
AOT: DOMImplementation::createDocument documentElement (#32531, ext/dom/php_dom.c)
--FILE--
<?php
declare(strict_types=1);
$impl = new DOMImplementation();
$doc = $impl->createDocument(null, 'root');
echo $doc->documentElement->tagName, '|';
$ns = $impl->createDocument('http://example.com/ns', 'ex:root');
echo $ns->documentElement->tagName, '|';
echo $ns->documentElement->namespaceURI, "\n";
--EXPECT--
root|ex:root|http://example.com/ns
--EXPECT_EXIT--
0
