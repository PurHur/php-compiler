--TEST--
stdlib DOMElement::toggleAttribute() — PHP 8.3+ profile (#16824, ext/dom/element.c)
--FILE--
<?php
$dom = new DOMDocument();
$el = $dom->createElement('p');
$dom->appendChild($el);
echo (int) $el->toggleAttribute('hidden'), "\n";
echo (int) $el->hasAttribute('hidden'), "\n";
echo (int) $el->toggleAttribute('hidden'), "\n";
echo (int) $el->hasAttribute('hidden'), "\n";
echo (int) $el->toggleAttribute('hidden', true), "\n";
echo (int) $el->hasAttribute('hidden'), "\n";
echo (int) $el->toggleAttribute('hidden', false), "\n";
echo (int) $el->hasAttribute('hidden'), "\n";
?>
--EXPECT--
1
1
0
0
1
1
0
0
