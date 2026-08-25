<?php
$d = new DOMDocument();
$d->loadXML('<r><a/><c/></r>');
$r = $d->documentElement;
$a = $r->firstChild;
$c = $a->nextSibling;
$r->replaceChild($c, $a);
echo 'replaceChild=', $d->saveXML($r), "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r><a/><c/></r>');
$r2 = $d2->documentElement;
$a2 = $r2->firstChild;
$c2 = $a2->nextSibling;
$a2->replaceWith($c2);
echo 'replaceWith=', $d2->saveXML($r2), "\n";
