--TEST--
Stdlib: DOMAttr / getAttributeNode() attribute node API (#14455, ext/dom/attr.c)
--FILE--
<?php
$doc = new DOMDocument();
$el = $doc->createElement('x');
$doc->appendChild($el);
$attr = $doc->createAttribute('id');
$attr->value = '1';
$el->setAttributeNode($attr);
$got = $el->getAttributeNode('id');
echo get_class($got), "\n", $got->name, "\n", $got->value, "\n";
$el->removeAttributeNode($got);
echo $el->hasAttribute('id') ? "has\n" : "no\n";
?>
--EXPECT--
DOMAttr
id
1
no
