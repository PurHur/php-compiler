--TEST--
AOT: namespaced attr does not satisfy hasAttributeNS(null)/hasAttribute(local) (ext/dom/element.c xmlHasNsProp)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r xmlns:p="urn:x" p:a="1"/>');
$e = $d->documentElement;
echo (int) $e->hasAttribute('a'), '|', (int) $e->hasAttribute('p:a'), "\n";
echo var_export($e->getAttribute('a'), true), '|', var_export($e->getAttribute('p:a'), true), "\n";
echo (int) $e->hasAttributeNS('urn:x', 'a'), '|', (int) $e->hasAttributeNS(null, 'a'), "\n";
echo var_export($e->getAttributeNS('urn:x', 'a'), true), '|', var_export($e->getAttributeNS(null, 'a'), true), "\n";
$d2 = new DOMDocument();
$d2->loadXML('<r xmlns:p="urn:x" p:a="1" a="2"/>');
$e2 = $d2->documentElement;
echo (int) $e2->hasAttributeNS(null, 'a'), '|', var_export($e2->getAttributeNS(null, 'a'), true), "\n";
?>
--EXPECT--
0|1
''|'1'
1|0
'1'|''
1|'2'
