--TEST--
AOT: DOMNode::isEqualNode() living API under PHP 8.4 forward profile (#19507, ext/dom/node.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><a id="1"/></root>');
$a = $doc->documentElement->firstChild;
echo (int) $a->isEqualNode($a), "\n";
$doc2 = new DOMDocument();
$doc2->loadXML('<root><a id="1"/></root>');
$b = $doc2->documentElement->firstChild;
echo (int) $a->isEqualNode($b), "\n";
$doc3 = new DOMDocument();
$doc3->loadXML('<root><a id="2"/></root>');
$d = $doc3->documentElement->firstChild;
echo (int) $a->isEqualNode($d), "\n";
--EXPECT--
1
1
0
