--TEST--
dom DOMElement::getElementsByTagName() element-scoped search (#15298)
--FILE--
<?php
$dom = new DOMDocument();
$dom->loadXML('<root><a><b/></a><b/></root>');
echo $dom->documentElement->firstChild->getElementsByTagName('b')->length, "\n";
echo $dom->getElementsByTagName('b')->length, "\n";
--EXPECT--
1
2
