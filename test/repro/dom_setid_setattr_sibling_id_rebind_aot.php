<?php
/** Repro #35321 — setIdAttribute + setAttribute id rebind with sibling id= (AOT vs Zend). */
$d = new DOMDocument();
$d->loadXML('<r><a id="x"/><b id="y"/></r>');
$a = $d->documentElement->firstChild;
$a->setIdAttribute('id', true);
echo 'x0=' . ($d->getElementById('x')?->nodeName ?? 'null') . "\n";
$a->setAttribute('id', 'z');
echo 'z=' . ($d->getElementById('z')?->nodeName ?? 'null') . "\n";
echo 'x1=' . ($d->getElementById('x')?->nodeName ?? 'null') . "\n";
echo 'y=' . ($d->getElementById('y')?->nodeName ?? 'null') . "\n";
$c = $d->documentElement->appendChild($d->createElement('c'));
$c->setAttribute('id', 'x');
$c->setIdAttribute('id', true);
echo 'x2=' . ($d->getElementById('x')?->nodeName ?? 'null') . "\n";
echo 'z2=' . ($d->getElementById('z')?->nodeName ?? 'null') . "\n";
