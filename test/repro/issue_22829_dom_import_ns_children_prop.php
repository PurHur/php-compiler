<?php
/**
 * Repro #22829 / #22728 — dom_import_simplexml on namespaced children() property.
 * php-src: ext/simplexml/sxe.c + ext/dom/node.c
 */
$s = simplexml_load_string('<r xmlns:x="urn:x"><x:a>1</x:a></r>');
$c = $s->children('urn:x');
echo 'isset=', isset($c->a) ? '1' : '0';
echo ' str=', (string) $c->a;
echo ' asxml=', trim((string) $c->a->asXML()), "\n";

$n = dom_import_simplexml($c->a);
echo $n->namespaceURI, '|', $n->localName, '|', $n->prefix, "\n";
