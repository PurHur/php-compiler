<?php
$d = new DOMDocument();
$d->loadXML('<r/>');
$x = new DOMXPath($d);
echo "start\n";
$a = $x->registerNamespace(null, 'urn:x');
echo 'prefix=', $a ? 'true' : 'false', "\n";
$b = $x->registerNamespace('p', null);
echo 'namespace=', $b ? 'true' : 'false', "\n";
echo "done\n";
