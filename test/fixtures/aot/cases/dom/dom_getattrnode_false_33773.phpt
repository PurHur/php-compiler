--TEST--
AOT: DOMElement::getAttributeNode miss/null is bool(false) (#33773)
--FILE--
<?php
$d = new DOMDocument();
$e = $d->createElement('e');
$d->appendChild($e);
var_dump($e->getAttributeNode('missing'));
var_dump($e->getAttributeNode(null));
var_dump($e->getAttributeNode(''));
$e->setAttribute('k', 'v');
$a = $e->getAttributeNode('k');
echo 'present=', ($a instanceof DOMAttr) ? 'DOMAttr' : gettype($a), ' value=', $a->value, "\n";
--EXPECT--
bool(false)
bool(false)
bool(false)
present=DOMAttr value=v
