--TEST--
AOT: DOMNode::$isConnected after appendChild/removeChild under PHP 8.4 forward profile (#29375, re-#19653, #21687, ext/dom/node.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = new DOMDocument();
$el = $doc->createElement('el');
$child = $doc->createElement('child');
echo (int) ($el->isConnected === false), "\n";
$doc->appendChild($el);
$el->appendChild($child);
echo (int) ($el->isConnected === true), "\n";
echo (int) ($child->isConnected === true), "\n";
echo (int) ($doc->isConnected === true), "\n";
$el->removeChild($child);
echo (int) ($child->isConnected === false), "\n";
$doc->removeChild($el);
echo (int) ($el->isConnected === false), "\n";
--EXPECT--
1
1
1
1
1
1
