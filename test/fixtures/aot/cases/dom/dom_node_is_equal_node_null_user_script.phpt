--TEST--
AOT: DOMNode::isEqualNode(null) → false under PHP 8.4 (#24462, ext/dom/node.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = new DOMDocument();
$a = $doc->createElement('a');
$doc->appendChild($a);
// Lone null literal keeps isNullConstant under thin AOT (#24462).
echo (int) $a->isEqualNode(null), "\n";
--EXPECT--
0
