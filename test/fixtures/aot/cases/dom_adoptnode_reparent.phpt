--TEST--
AOT: DOMDocument::adoptNode() no abort + usable return under PROFILE=8.4 (#29853)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$d1 = new DOMDocument();
$d1->loadXML('<a><b/></a>');
$d2 = new DOMDocument();
$d2->loadXML('<r/>');
$n = $d2->adoptNode($d1->documentElement->firstChild);
echo get_class($n), ':', $n->nodeName, "\n";
--EXPECT--
DOMElement:b
