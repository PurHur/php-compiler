--TEST--
stdlib DOMDocument::getElementById() with DTD ID attribute (#14378, ext/dom/php_dom.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->validateOnParse = true;
$doc->loadXML('<!DOCTYPE root [<!ATTLIST child id ID #IMPLIED>]><root><child id="target">x</child></root>');
$found = $doc->getElementById('target');
echo null !== $found ? "found_ok\n" : "found_null\n";
echo null === $doc->getElementById('missing') ? "missing_ok\n" : "missing_found\n";
--EXPECT--
found_ok
missing_ok
