--TEST--
DOM formatOutput keeps sole text children inline (ext/dom/document.c / libxml; #21489)
--FILE--
<?php
$d = new DOMDocument('1.0', 'UTF-8');
$d->formatOutput = true;
$r = $d->appendChild($d->createElement('root'));
$r->appendChild($d->createElement('child', 'x'));
$xml = $d->saveXML();
echo (int) str_contains($xml, "<child>x</child>"), "\n";
echo (int) str_contains($xml, "<child>\n"), "\n";

$d2 = new DOMDocument('1.0', 'UTF-8');
$d2->formatOutput = true;
$r2 = $d2->appendChild($d2->createElement('root'));
$c = $r2->appendChild($d2->createElement('child'));
$c->appendChild($d2->createElement('nested'));
$xml2 = $d2->saveXML();
echo (int) str_contains($xml2, "\n    <nested/>\n"), "\n";
--EXPECT--
1
0
1
