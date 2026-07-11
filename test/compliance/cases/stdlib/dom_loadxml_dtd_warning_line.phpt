--TEST--
stdlib DOMDocument::loadXML() DTD libxml warnings cite call-site line (#15140, ext/dom/document.c)
--FILE--
<?php
declare(strict_types=1);

$doc = new DOMDocument();
$doc->validateOnParse = true;
$doc->loadXML('<!DOCTYPE root [<!ATTLIST child id ID #IMPLIED>]><root><child id="target">x</child></root>');
$found = $doc->getElementById('target');
echo null !== $found ? "dtd_ok\n" : "dtd_null\n";
--EXPECTF--
PHP Warning:  DOMDocument::loadXML(): No declaration for element child in Entity, line: 1 in %s on line %d
PHP Warning:  DOMDocument::loadXML(): No declaration for element root in Entity, line: 1 in %s on line %d
dtd_ok
