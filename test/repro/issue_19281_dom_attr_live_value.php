<?php
// Repro #19281 — live DOMAttr::$value must update Element::getAttribute
$d = new DOMDocument();
$e = $d->createElement('a');
$d->appendChild($e);
$e->setAttribute('x', '1');
$attr = $e->getAttributeNode('x');
$attr->value = '2';
echo 'getAttribute=', $e->getAttribute('x'), ' attr.value=', $attr->value, "\n";
