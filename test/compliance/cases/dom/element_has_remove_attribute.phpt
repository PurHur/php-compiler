--TEST--
dom DOMElement::hasAttribute() / removeAttribute() local-name API (#15297)
--FILE--
<?php
$dom = new DOMDocument();
$dom->loadXML('<root foo="bar"/>');
$el = $dom->documentElement;
echo (int) $el->hasAttribute('foo'), "\n";
echo (int) $el->removeAttribute('foo'), "\n";
echo (int) $el->hasAttribute('foo'), "\n";
echo (int) $el->removeAttribute('missing'), "\n";
--EXPECT--
1
1
0
0
