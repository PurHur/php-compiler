--TEST--
DOMElement::tagName readable after createElement (ext/dom/node.c, #3326)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$el = $doc->createElement('item');
echo $el->tagName, "\n";
--EXPECT--
item
