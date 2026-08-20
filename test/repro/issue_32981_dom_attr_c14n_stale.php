<?php
// setAttribute / removeAttribute must refresh C14N compile-time XML (#32981).
$d = new DOMDocument();
$d->loadXML('<r a="1"/>');
$d->documentElement->setAttribute('b', '2');
echo 'set=', $d->documentElement->C14N(), "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r a="1" b="2"/>');
$d2->documentElement->removeAttribute('a');
echo 'remove=', $d2->documentElement->C14N(), "\n";

$d3 = new DOMDocument();
$d3->loadXML('<r a="1"/>');
$d3->documentElement->setAttribute('a', '9');
echo 'rewrite=', $d3->documentElement->C14N(), "\n";
