<?php
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$r = $d->documentElement;
var_export($r->isSameNode($r));
echo '|';
var_export($r->isSameNode($r->firstChild));
echo "\n";
