<?php

declare(strict_types=1);

/**
 * #35234 — METHODCALL argOperands omit receiver; locals to setAttribute(NS) mis-stamp.
 * php-src: ext/dom/element.c setAttribute / setAttributeNS (xmlSetProp / xmlSetNsProp).
 */
$doc = new DOMDocument();
$e = $doc->createElement('x');
$doc->appendChild($e);

$name = 'k';
$val = 'v';
$e->setAttribute($name, $val);
echo $e->getAttribute($name), "\n";
echo $doc->saveXML($e), "\n";

$ns = 'urn:x';
$q = 'p:a';
$v = '1';
$e->setAttributeNS($ns, $q, $v);
echo $e->getAttributeNS($ns, 'a'), "\n";
