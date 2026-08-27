<?php
// #35321 — setAttribute id rebind must clear old getElementById when siblings have id=
$d = new DOMDocument();
$d->loadXML('<r><a id="x"/><b id="y"/></r>');
$a = $d->documentElement->firstChild;
$a->setIdAttribute('id', true);
$a->setAttribute('id', 'z');
$got = $d->getElementById('x');
echo null === $got ? 'null' : $got->nodeName, "\n";
$c = $d->createElement('c');
$c->setAttribute('id', 'x');
$d->documentElement->appendChild($c);
$c->setIdAttribute('id', true);
$got2 = $d->getElementById('x');
echo null === $got2 ? 'null' : $got2->nodeName, "\n";
