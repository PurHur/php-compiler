<?php
/**
 * #29135 — DOMXPath::registerNamespace("", $uri) must return false (php-src xpath.c / xmlXPathRegisterNs).
 */
$d = new DOMDocument();
$d->loadXML('<r xmlns="urn:def"><c/></r>');
$xp = new DOMXPath($d);
echo 'empty=', $xp->registerNamespace('', 'urn:x') ? 'true' : 'false', "\n";
echo 'empty_uri=', $xp->registerNamespace('', '') ? 'true' : 'false', "\n";
echo 'ok=', $xp->registerNamespace('p', 'urn:x') ? 'true' : 'false', "\n";
echo 'empty_ns_uri=', $xp->registerNamespace('q', '') ? 'true' : 'false', "\n";
// Empty-prefix call must not enable unprefixed default-NS queries.
echo 'bare=', $xp->query('//c')->length, "\n";
$xp->registerNamespace('d', 'urn:def');
echo 'prefixed=', $xp->query('//d:c')->length, "\n";
