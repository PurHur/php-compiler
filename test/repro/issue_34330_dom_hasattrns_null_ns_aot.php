<?php
// AOT: namespaced attr must not satisfy hasAttributeNS(null)/hasAttribute(local)
// php-src: ext/dom/element.c xmlHasNsProp / xmlHasProp
$d = new DOMDocument();
$d->loadXML('<r xmlns:p="urn:x" p:a="1"/>');
$e = $d->documentElement;
echo 'hasAttr a=', (int) $e->hasAttribute('a'), "\n";
echo 'hasAttr p:a=', (int) $e->hasAttribute('p:a'), "\n";
echo 'getAttr a=', var_export($e->getAttribute('a'), true), "\n";
echo 'getAttr p:a=', var_export($e->getAttribute('p:a'), true), "\n";
echo 'hasNS urn a=', (int) $e->hasAttributeNS('urn:x', 'a'), "\n";
echo 'hasNS null a=', (int) $e->hasAttributeNS(null, 'a'), "\n";
echo 'getNS urn a=', var_export($e->getAttributeNS('urn:x', 'a'), true), "\n";
echo 'getNS null a=', var_export($e->getAttributeNS(null, 'a'), true), "\n";

// Mixed: null-NS local must still win when both exist (VM scan).
$d2 = new DOMDocument();
$d2->loadXML('<r xmlns:p="urn:x" p:a="1" a="2"/>');
$e2 = $d2->documentElement;
echo 'mixed hasNS null a=', (int) $e2->hasAttributeNS(null, 'a'), "\n";
echo 'mixed getNS null a=', var_export($e2->getAttributeNS(null, 'a'), true), "\n";
