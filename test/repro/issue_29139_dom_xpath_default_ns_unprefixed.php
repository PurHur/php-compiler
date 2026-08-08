<?php
/**
 * #29139 — AOT unprefixed //elem must not match default-namespace nodes (php-src xpath.c).
 */
$d = new DOMDocument();
$d->loadXML('<r xmlns="urn:def"><c/></r>');
$xp = new DOMXPath($d);
echo 'bare=', $xp->query('//c')->length, "\n";
$xp->registerNamespace('d', 'urn:def');
echo 'prefixed=', $xp->query('//d:c')->length, "\n";
$d2 = new DOMDocument();
$d2->loadXML('<r><c/></r>');
$xp2 = new DOMXPath($d2);
echo 'nullns=', $xp2->query('//c')->length, "\n";
