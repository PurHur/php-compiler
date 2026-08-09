--TEST--
AOT: DOMNode::replaceChildren() living API under PHP 8.4 forward profile (#19507, #29409, ext/dom/parentnode.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = new DOMDocument();
$parent = $doc->createElement('parent');
$doc->appendChild($parent);
$parent->appendChild($doc->createElement('old'));
$new = $doc->createElement('new');
$parent->replaceChildren($new);
echo $parent->childNodes->length, "\n";
echo $parent->firstChild->nodeName, "\n";
--EXPECT--
1
new
