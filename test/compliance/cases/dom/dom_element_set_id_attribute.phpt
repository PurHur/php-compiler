--TEST--
DOM DOMElement::setIdAttribute() enables getElementById without DTD (#14493, ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><child id="target">x</child></root>');
$child = $doc->getElementsByTagName('child')->item(0);
echo null === $doc->getElementById('target') ? "before_null\n" : "before_found\n";
$child->setIdAttribute('id', true);
$found = $doc->getElementById('target');
echo null !== $found ? "after_ok\n" : "after_null\n";
--EXPECT--
before_null
after_ok
