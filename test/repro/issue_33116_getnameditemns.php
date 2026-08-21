<?php

$d = new DOMDocument();
$d->loadXML('<r a="1" b="2"/>');
$n = $d->documentElement->attributes->getNamedItemNS(null, 'b');
echo $n ? $n->value : 'null', PHP_EOL;

$d2 = new DOMDocument();
$d2->loadXML('<r xmlns:p="urn:x" p:a="1" b="2"/>');
$ns = $d2->documentElement->attributes->getNamedItemNS('urn:x', 'a');
echo $ns ? $ns->value : 'null', PHP_EOL;
