--TEST--
stdlib DOM setAttributeNS on createElementNS element (#15380, ext/dom/element.c)
--FILE--
<?php
$doc = new DOMDocument();
$el = $doc->createElementNS('http://example.com/ns', 'ex:a');
$el->setAttributeNS('http://example.com/ns', 'ex:attr', 'v');
echo $el->getAttributeNS('http://example.com/ns', 'attr'), "\n";
echo $el->hasAttributeNS('http://example.com/ns', 'attr') ? "has\n" : "nohas\n";
$el->removeAttributeNS('http://example.com/ns', 'attr');
echo $el->hasAttributeNS('http://example.com/ns', 'attr') ? "still\n" : "gone\n";
?>
--EXPECT--
v
has
gone
