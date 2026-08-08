--TEST--
AOT Dom\HTMLDocument::createElementNS HTML vs foreign (#28958)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$html = Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body></body></html>');
$a = $html->createElementNS('http://www.w3.org/1999/xhtml', 'span');
echo get_class($a), ' ', $a->nodeName, "\n";
$b = $html->createElementNS('urn:foo', 'x:y');
echo get_class($b), ' ', $b->nodeName, "\n";
--EXPECT--
Dom\HTMLElement SPAN
Dom\Element x:y
