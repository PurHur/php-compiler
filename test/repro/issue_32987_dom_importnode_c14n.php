<?php
// #32987 — importNode + appendChild must refresh C14N fold for the destination document
$a = new DOMDocument();
$b = new DOMDocument();
$a->loadXML('<r><c/></r>');
$b->loadXML('<x><y/></x>');
echo $a->documentElement->C14N(), "\n";
$imp = $a->importNode($b->documentElement, true);
$a->documentElement->appendChild($imp);
echo $a->documentElement->C14N(), "\n";
