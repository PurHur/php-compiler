--TEST--
stdlib DOMDocument::loadXML prefixed attrs xml:base / xmlns (#20615, ext/dom/document.c)
--FILE--
<?php
$d = new DOMDocument();
var_export($d->loadXML('<r xml:base="http://example.com/x/" xmlns:p="urn:x" p:a="1"/>'));
echo "\n";
$el = $d->documentElement;
$base = $el->getAttributeNode('xml:base');
$pa = $el->getAttributeNode('p:a');
echo $base->namespaceURI, "\n";
echo $base->prefix, "\n";
echo $pa->namespaceURI, "\n";
echo $pa->prefix, "\n";
echo $pa->name, "\n";
echo $el->lookupNamespaceURI('xml'), "\n";
echo $el->getAttributeNS('urn:x', 'a'), "\n";
echo $el->attributes->getNamedItemNS('urn:x', 'a')->value, "\n";

$d2 = new DOMDocument();
var_export($d2->loadXML('<r xmlns:p="urn:x"><c p:b="2"/></r>'));
echo "\n";
$c = $d2->documentElement->firstChild;
$b = $c->getAttributeNode('p:b');
echo $b->namespaceURI, "\n";
echo $b->prefix, "\n";

libxml_use_internal_errors(true);
$d3 = new DOMDocument();
var_export($d3->loadXML('<r q:z="9"/>'));
echo "\n";
$z = $d3->documentElement->attributes->item(0);
echo $z->name, "\n";
var_export($z->namespaceURI);
echo "\n";
libxml_clear_errors();
?>
--EXPECT--
true
http://www.w3.org/XML/1998/namespace
xml
urn:x
p
a
http://www.w3.org/XML/1998/namespace
1
1
true
urn:x
p
true
q:z
NULL
