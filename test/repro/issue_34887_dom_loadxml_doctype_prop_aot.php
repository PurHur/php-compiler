<?php
// #34887 — loadXML must expose $doc->doctype (name/publicId/systemId); no-DOCTYPE → null.
$d = new DOMDocument();
$d->loadXML('<!DOCTYPE html><html/>');
echo $d->doctype->name, "\n";

$d2 = new DOMDocument();
$d2->loadXML('<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd"><html/>');
echo $d2->doctype->name, "\n";
echo $d2->doctype->publicId, "\n";
echo $d2->doctype->systemId, "\n";

$d3 = new DOMDocument();
$d3->loadXML('<!DOCTYPE r SYSTEM "sys.dtd"><r/>');
echo $d3->doctype->name, "\n";
echo $d3->doctype->systemId, "\n";

$d4 = new DOMDocument();
$d4->loadXML('<r/>');
echo ($d4->doctype === null) ? "null\n" : "non-null\n";

// Document-wide saveXML must still keep DOCTYPE (#34877).
$d5 = new DOMDocument();
$d5->loadXML('<!DOCTYPE html><html><body>x</body></html>');
$xml = $d5->saveXML();
echo (str_contains($xml, '<!DOCTYPE html>')) ? "save_ok\n" : "save_missing\n";
