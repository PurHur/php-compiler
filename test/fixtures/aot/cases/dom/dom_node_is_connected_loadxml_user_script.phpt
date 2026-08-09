--TEST--
AOT: DOMNode::$isConnected on loadXML-materialized tree under PHP 8.4 forward profile (#29434, re-#29375, ext/dom/node.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<r><a/></r>');
$root = $doc->documentElement;
$child = $root->firstChild;
echo (int) ($root->isConnected === true), "\n";
echo (int) ($child->isConnected === true), "\n";
echo (int) ($doc->isConnected === true), "\n";
$doc->removeChild($root);
echo (int) ($root->isConnected === false), "\n";
--EXPECT--
1
1
1
1
