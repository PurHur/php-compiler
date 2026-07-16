--TEST--
DOMDocument::createAttributeNS() + setAttributeNodeNS() emits xmlns (#19458, ext/dom/document.c)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r/>');
$a = $d->createAttributeNS('urn:x', 'p:id');
$a->value = '1';
$d->documentElement->setAttributeNodeNS($a);
echo $d->saveXML($d->documentElement);
echo $d->documentElement->lookupNamespaceURI('p') ?? 'NULL', "\n";
$d->documentElement->setAttributeNS('urn:y', 'q:name', 'v');
echo $d->saveXML($d->documentElement);
echo $d->documentElement->lookupNamespaceURI('q') ?? 'NULL', "\n";
?>
--EXPECT--
<r xmlns:p="urn:x" p:id="1"/>urn:x
<r xmlns:p="urn:x" xmlns:q="urn:y" p:id="1" q:name="v"/>urn:y
