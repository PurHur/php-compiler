--TEST--
Stdlib: DOMDocument::loadHTML()/saveHTML() — HTML parse and serialize (ext/dom/php_dom.c, #14356)
--FILE--
<?php
$doc = new DOMDocument();
$ok = $doc->loadHTML('<p>hi</p>');
echo $ok ? "load_ok\n" : "load_fail\n";
echo $doc->documentElement->nodeName, "\n";
$html = $doc->saveHTML();
echo str_contains($html, '<p>hi</p>') ? "has_p\n" : "no_p\n";
echo str_contains($html, '<!DOCTYPE html') ? "has_doctype\n" : "no_doctype\n";
--EXPECT--
load_ok
html
has_p
has_doctype
