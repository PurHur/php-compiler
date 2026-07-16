--TEST--
AOT: DOMElement::toggleAttribute() under PHP 8.4 forward profile (#19507, ext/dom/element.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$dom = new DOMDocument();
$el = $dom->createElement('p');
$dom->appendChild($el);
echo (int) $el->toggleAttribute('hidden'), "\n";
echo (int) $el->toggleAttribute('hidden'), "\n";
echo (int) $el->toggleAttribute('hidden', true), "\n";
echo (int) $el->toggleAttribute('hidden', false), "\n";
--EXPECT--
1
0
1
0
