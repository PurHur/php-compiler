<?php
/**
 * #35402 — AOT DOMXPath::query('//*[@id="x"]') must report length 1 (not 0).
 * Wildcard name-test with [@attr=val] — php-src ext/dom/xpath.c / libxml.
 */
$d = new DOMDocument();
$d->loadXML('<r><a id="x"/><b id="y"/></r>');
$x = new DOMXPath($d);
$nodes = $x->query('//*[@id="x"]');
echo 'len=', $nodes->length, "\n";
echo 'name=', ($nodes->item(0)?->nodeName ?? 'null'), "\n";
echo 'miss=', $x->query('//*[@id="z"]')->length, "\n";
echo 'tag=', $x->query('//a[@id="x"]')->length, "\n";
