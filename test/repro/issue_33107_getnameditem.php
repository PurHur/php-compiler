<?php

$d = new DOMDocument();
$d->loadXML('<r a="1" b="2"/>');
$n = $d->documentElement->attributes->getNamedItem('b');
echo $n ? $n->value : 'null', PHP_EOL;
