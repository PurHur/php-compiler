<?php
declare(strict_types=1);

/**
 * AOT: loadXML removeAttribute / removeAttributeNS must update saveXML (#34257).
 * php-src ext/dom/element.c — removeAttribute / removeAttributeNS (xmlUnsetProp / xmlUnsetNsProp).
 */
$d = new DOMDocument();
$d->loadXML('<r xmlns:p="urn:x" p:a="1" b="2"/>');
$e = $d->documentElement;
$e->removeAttribute('b');
echo 'rm_b=', $d->saveXML($e), "\n";
$e->removeAttributeNS('urn:x', 'a');
echo 'rm_ns=', $d->saveXML($e), "\n";
echo 'has_a=', (int) $e->hasAttributeNS('urn:x', 'a'), "\n";
echo 'has_b=', (int) $e->hasAttribute('b'), "\n";
