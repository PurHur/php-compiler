--TEST--
AOT: DOMNode::isEqualNode() structural equality under PHP 8.4 forward profile (#21377, ext/dom/node.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><a id="1"/></root>');
$a = $doc->documentElement->firstChild;
$b = $a->cloneNode(true);
echo (int) $a->isEqualNode($a), "\n";
echo (int) $a->isEqualNode($b), "\n";
echo (int) $a->isSameNode($b), "\n";
$doc2 = new DOMDocument();
$doc2->loadXML('<root><a id="2"/></root>');
$d = $doc2->documentElement->firstChild;
echo (int) $a->isEqualNode($d), "\n";
--EXPECT--
1
1
0
0
