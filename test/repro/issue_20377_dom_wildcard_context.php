<?php
/**
 * Repro #20377 — element-scoped getElementsByTagName("*") excludes context.
 * Zend: star0=3 named_self=1 doc_star=3
 */
$d = new DOMDocument();
$d->loadXML('<r><a/><b/><c/></r>');
$r = $d->documentElement;
$list = $r->getElementsByTagName('*');
echo 'star0=', $list->length, "\n";
$r->appendChild($d->createElement('d'));
echo 'star1=', $list->length, "\n";

$d3 = new DOMDocument();
$d3->loadXML('<a><a/><b/></a>');
echo 'named_self=', $d3->documentElement->getElementsByTagName('a')->length, "\n";
echo 'doc_named=', $d3->getElementsByTagName('a')->length, "\n";
echo 'doc_star=', $d3->getElementsByTagName('*')->length, "\n";
