--TEST--
DOM: DOMDocument::saveXML() doctype internal subset + SYSTEM (ext/dom/document.c; #21326)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<!DOCTYPE r [<!ENTITY e "E">]><r/>');
echo trim($d->saveXML($d->doctype))."\n";

$d2 = new DOMDocument();
$d2->loadXML('<!DOCTYPE r SYSTEM "sys.dtd"><r/>');
echo trim($d2->saveXML($d2->doctype))."\n";

$d3 = new DOMDocument();
$d3->loadXML('<!DOCTYPE r SYSTEM "sys.dtd" [<!ENTITY e "E">]><r/>');
echo trim($d3->saveXML($d3->doctype))."\n";
?>
--EXPECT--
<!DOCTYPE r [
<!ENTITY e "E">
]>
<!DOCTYPE r SYSTEM "sys.dtd">
<!DOCTYPE r SYSTEM "sys.dtd" [
<!ENTITY e "E">
]>
