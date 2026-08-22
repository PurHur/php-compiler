<?php
// #33773 — AOT getAttributeNode miss/null must be bool(false) like Zend (ext/dom/element.c).
$d = new DOMDocument();
$e = $d->createElement('e');
$d->appendChild($e);
var_dump($e->getAttributeNode('missing'));
var_dump($e->getAttributeNode(null));
var_dump($e->getAttributeNode(''));
$e->setAttribute('k', 'v');
$a = $e->getAttributeNode('k');
echo 'present=', ($a instanceof DOMAttr) ? 'DOMAttr' : gettype($a), ' value=', $a->value, "\n";
