<?php
// #34330 — namespaced attr must not satisfy hasAttribute/hasAttributeNS(null, local)
$d = new DOMDocument();
$d->loadXML('<r xmlns:p="urn:x" p:a="1"/>');
$e = $d->documentElement;
echo 'hasAttribute(a)=' . ($e->hasAttribute('a') ? '1' : '0') . "\n";
echo 'hasAttributeNS(null,a)=' . ($e->hasAttributeNS(null, 'a') ? '1' : '0') . "\n";
echo 'getAttribute(a)=[' . $e->getAttribute('a') . "]\n";
echo 'getAttributeNS(null,a)=[' . $e->getAttributeNS(null, 'a') . "]\n";
echo 'hasAttribute(p:a)=' . ($e->hasAttribute('p:a') ? '1' : '0') . "\n";
echo 'hasAttributeNS(urn:x,a)=' . ($e->hasAttributeNS('urn:x', 'a') ? '1' : '0') . "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r xmlns:p="urn:x" p:a="1" a="2"/>');
$e2 = $d2->documentElement;
echo 'both:hasAttributeNS(null,a)=' . ($e2->hasAttributeNS(null, 'a') ? '1' : '0') . "\n";
echo 'both:getAttributeNS(null,a)=[' . $e2->getAttributeNS(null, 'a') . "]\n";
