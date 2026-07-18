<?php
/**
 * #20615 — loadXML prefixed attrs (xml:base / xmlns:p / nested / undeclared).
 */
$out = [];

$d = new DOMDocument();
$ok = $d->loadXML('<r xml:base="http://example.com/x/" xml:lang="en"/>');
$out[] = 'xml_builtin_load=' . ($ok ? '1' : '0');
$el = $d->documentElement;
$base = $el->getAttributeNode('xml:base');
$lang = $el->getAttributeNode('xml:lang');
$out[] = 'base_ns=' . ($base ? (string) $base->namespaceURI : 'null');
$out[] = 'base_prefix=' . ($base ? (string) $base->prefix : 'null');
$out[] = 'lang_local=' . ($lang ? (string) $lang->localName : 'null');
$out[] = 'lookup_xml=' . (string) $el->lookupNamespaceURI('xml');
$out[] = 'child_baseURI=' . (string) $el->baseURI;

$d2 = new DOMDocument();
$ok2 = $d2->loadXML('<r xmlns:p="urn:x" p:a="1"/>');
$out[] = 'declared_load=' . ($ok2 ? '1' : '0');
$a = $d2->documentElement->getAttributeNode('p:a');
$out[] = 'pa_ns=' . ($a ? (string) $a->namespaceURI : 'null');
$out[] = 'pa_prefix=' . ($a ? (string) $a->prefix : 'null');
$out[] = 'pa_name=' . ($a ? (string) $a->name : 'null');
$map = $d2->documentElement->attributes;
$named = $map->getNamedItemNS('urn:x', 'a');
$out[] = 'getNamedItemNS=' . ($named ? (string) $named->value : 'null');

$d3 = new DOMDocument();
$ok3 = $d3->loadXML('<r xmlns:p="urn:x"><c p:a="1"/></r>');
$out[] = 'nested_load=' . ($ok3 ? '1' : '0');
$c = $d3->documentElement->firstChild;
$na = $c->attributes->item(0);
$out[] = 'nested_ns=' . ($na ? (string) $na->namespaceURI : 'null');
$out[] = 'nested_prefix=' . ($na ? (string) $na->prefix : 'null');

libxml_use_internal_errors(true);
$d4 = new DOMDocument();
$ok4 = $d4->loadXML('<r p:a="1"/>');
$out[] = 'undeclared_load=' . ($ok4 ? '1' : '0');
$ua = $d4->documentElement->attributes->item(0);
$out[] = 'undeclared_name=' . ($ua ? (string) $ua->name : 'null');
$out[] = 'undeclared_ns=' . ($ua ? var_export($ua->namespaceURI, true) : 'null');
libxml_clear_errors();

echo implode("\n", $out), "\n";
