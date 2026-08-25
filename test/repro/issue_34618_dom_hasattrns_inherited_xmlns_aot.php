<?php
// AOT: child prefixed attr must inherit ancestor xmlns (xmlHasNsProp / #34618)
// php-src: ext/dom/element.c — hasAttributeNS / getAttributeNS
$d = new DOMDocument();
$d->loadXML('<r xmlns:p="u"><e p:a="1"/></r>');
$e = $d->documentElement->firstChild;
echo 'fc hasNS u=', (int) $e->hasAttributeNS('u', 'a'), "\n";
echo 'fc hasNS null=', (int) $e->hasAttributeNS(null, 'a'), "\n";
echo 'fc hasAttr a=', (int) $e->hasAttribute('a'), "\n";
echo 'fc hasAttr p:a=', (int) $e->hasAttribute('p:a'), "\n";
echo 'fc getNS u=', var_export($e->getAttributeNS('u', 'a'), true), "\n";
echo 'fc getNS null=', var_export($e->getAttributeNS(null, 'a'), true), "\n";

$e2 = $d->getElementsByTagName('e')->item(0);
echo 'gt hasNS u=', (int) $e2->hasAttributeNS('u', 'a'), "\n";
echo 'gt hasNS null=', (int) $e2->hasAttributeNS(null, 'a'), "\n";
echo 'gt hasAttr a=', (int) $e2->hasAttribute('a'), "\n";
