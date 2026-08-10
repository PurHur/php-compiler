<?php

// Non-strict: null coerces to '' → getElementById miss → null (#29942).
$d = new DOMDocument();
$d->loadXML('<r id="x"/>');
$d->documentElement->setIdAttribute('id', true);
var_export($d->getElementById(null));
echo "\n";
echo "ok\n";
