<?php
// #33097 — AOT importNode($node, false) must be shallow (php-src xmlDocCopyNode deep=0)
$a = new DOMDocument();
$a->loadXML('<r/>');
$b = new DOMDocument();
$b->loadXML('<x><y>z</y></x>');
$imp = $a->importNode($b->documentElement, false);
$a->documentElement->appendChild($imp);
echo $a->documentElement->childNodes->length, ' ';
echo $imp->childNodes->length, ' ';
echo $a->saveXML($a->documentElement), "\n";

// Omitted $deep defaults to false (ext/dom/php_dom.stub.php).
$c = new DOMDocument();
$c->loadXML('<r/>');
$d = new DOMDocument();
$d->loadXML('<p><q/></p>');
$imp2 = $c->importNode($d->documentElement);
$c->documentElement->appendChild($imp2);
echo $c->saveXML($c->documentElement), "\n";

// Deep still copies children.
$e = new DOMDocument();
$e->loadXML('<r/>');
$f = new DOMDocument();
$f->loadXML('<x><y>z</y></x>');
$imp3 = $e->importNode($f->documentElement, true);
$e->documentElement->appendChild($imp3);
echo $e->saveXML($e->documentElement), "\n";
