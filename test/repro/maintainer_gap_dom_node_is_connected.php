<?php
$d = new DOMDocument();
$d->loadXML("<root><a/></root>");
$a = $d->documentElement->firstChild;
var_export($a->isConnected); echo "\n";
$d->documentElement->removeChild($a);
var_export($a->isConnected); echo "\n";
var_export($d->isConnected); echo "\n";
