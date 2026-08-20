<?php
// #33000 — appendChild(createTextNode) must refresh C14N/saveXML like Zend
$d = new DOMDocument();
$d->loadXML('<r>1</r>');
$d->documentElement->appendChild($d->createTextNode('x'));
echo $d->documentElement->C14N(), "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r><a>1</a></r>');
$a = $d2->documentElement->firstChild;
$a->appendChild($d2->createTextNode('x'));
echo $d2->documentElement->C14N(), "\n";
echo $d2->saveXML($a), "\n";

$d3 = new DOMDocument();
$d3->loadXML('<r><a>1</a></r>');
$a3 = $d3->documentElement->firstChild;
$a3->appendChild($d3->createTextNode('a&b'));
echo $d3->documentElement->C14N(), "\n";
