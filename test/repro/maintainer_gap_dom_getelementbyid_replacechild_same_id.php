<?php
// #25274 — replaceChild same id + setIdAttribute: Zend getElementById null while old node lives
// (libxml keeps ID on detach; xmlAddID fails for duplicate). Fresh id still resolves.
$d = new DOMDocument();
$d->loadXML('<r><a id="x"/><b id="y"/></r>');
$a = $d->documentElement->firstChild;
$a->setIdAttribute('id', true);
$b = $a->nextSibling;
$b->setIdAttribute('id', true);
$new = $d->createElement('c');
$new->setAttribute('id', 'x');
$d->documentElement->replaceChild($new, $a);
$new->setIdAttribute('id', true);
echo ($d->getElementById('x')->nodeName ?? 'null'), "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r><a id="x"/><b id="y"/></r>');
$a2 = $d2->documentElement->firstChild;
$a2->setIdAttribute('id', true);
$new2 = $d2->createElement('c');
$new2->setAttribute('id', 'z');
$d2->documentElement->replaceChild($new2, $a2);
$new2->setIdAttribute('id', true);
echo ($d2->getElementById('z')->nodeName ?? 'null'), "\n";
