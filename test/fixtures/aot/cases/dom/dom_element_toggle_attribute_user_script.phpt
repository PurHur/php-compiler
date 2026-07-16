--TEST--
AOT: DOMElement::toggleAttribute() under PHP 8.4 forward profile (#19507, ext/dom/element.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$dom = new DOMDocument();
$el = $dom->createElement('p');
$dom->appendChild($el);
echo $el->toggleAttribute('hidden') ? "1\n" : "0\n";
echo $el->toggleAttribute('hidden') ? "1\n" : "0\n";
echo $el->toggleAttribute('hidden', true) ? "1\n" : "0\n";
echo $el->toggleAttribute('hidden', false) ? "1\n" : "0\n";
--EXPECT--
1
0
1
0
